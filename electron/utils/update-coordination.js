const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const LOCK_TTL_MS = 300_000;
const COORDINATION_GATE_TTL_MS = 15_000;
const COORDINATION_GATE_WAIT_MS = 2_000;
const SALE_LOCK_PREFIX = 'sale-operation-';
const ACTIVE_UPDATE_STATES = new Set([
  'backing_up',
  'downloading',
  'verifying',
  'applying',
  'migrating',
  'desktop_handoff_pending',
  'full_ready_to_install',
  'installing',
]);
const sleepBuffer = new Int32Array(new SharedArrayBuffer(4));

function getCoordinationPaths(storageDir) {
  return {
    lockPath: path.join(storageDir, 'update-operation.lock'),
    statePath: path.join(storageDir, 'update-state.json'),
    gatePath: path.join(storageDir, 'update-coordination.gate'),
  };
}

function readJson(filePath) {
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch {
    return null;
  }
}

function ownerAgeMs(owner) {
  if (Number.isFinite(Number(owner?.time))) {
    return Math.max(0, Date.now() - (Number(owner.time) * 1000));
  }
  const updatedAt = Date.parse(owner?.updated_at || '');
  return Number.isFinite(updatedAt) ? Math.max(0, Date.now() - updatedAt) : LOCK_TTL_MS + 1;
}

function fileAgeMs(filePath) {
  try {
    return Math.max(0, Date.now() - (fs.statSync(filePath).mtimeMs || 0));
  } catch {
    return LOCK_TTL_MS + 1;
  }
}

function safeOwner(owner) {
  return {
    owner_id: String(owner?.owner_id || ''),
    operation: String(owner?.operation || 'unknown'),
    pid: Number.isFinite(Number(owner?.pid)) ? Number(owner.pid) : null,
    phase: owner?.phase ? String(owner.phase) : null,
    time: Number.isFinite(Number(owner?.time)) ? Number(owner.time) : null,
    updated_at: owner?.updated_at ? String(owner.updated_at) : null,
    context: owner?.context && typeof owner.context === 'object' ? owner.context : {},
    age_seconds: Math.floor(ownerAgeMs(owner) / 1000),
  };
}

function sleepSync(milliseconds) {
  Atomics.wait(sleepBuffer, 0, 0, milliseconds);
}

function quarantine(filePath) {
  if (!fs.existsSync(filePath)) return true;
  const stalePath = `${filePath}.stale-${process.pid}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  try {
    fs.renameSync(filePath, stalePath);
    try { fs.unlinkSync(stalePath); } catch { /* best effort cleanup */ }
    return true;
  } catch (error) {
    if (error.code === 'ENOENT') return true;
    return false;
  }
}

function listSaleLeasePaths(storageDir) {
  let entries;
  try {
    entries = fs.readdirSync(storageDir);
  } catch {
    return [];
  }
  return entries
    .filter((entry) => entry.startsWith(SALE_LOCK_PREFIX) && entry.endsWith('.lock'))
    .sort()
    .map((entry) => path.join(storageDir, entry));
}

function findActiveSaleOwner(storageDir, ttlMs) {
  let recoveredFrom = null;
  for (const salePath of listSaleLeasePaths(storageDir)) {
    const owner = readJson(salePath);
    if (owner && ownerAgeMs(owner) <= ttlMs) {
      return { owner, recoveredFrom, unavailable: false };
    }
    if (!owner && fileAgeMs(salePath) <= ttlMs) {
      return { owner: null, recoveredFrom, unavailable: true };
    }
    if (!quarantine(salePath)) {
      return { owner: null, recoveredFrom, unavailable: true };
    }
    if (!recoveredFrom) recoveredFrom = owner;
  }
  return { owner: null, recoveredFrom, unavailable: false };
}

function acquireCoordinationGate(storageDir) {
  const { gatePath } = getCoordinationPaths(storageDir);
  const gateId = `${process.pid}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  const payload = JSON.stringify({
    gate_id: gateId,
    pid: process.pid,
    time: Math.floor(Date.now() / 1000),
    updated_at: new Date().toISOString(),
  });
  const deadline = Date.now() + COORDINATION_GATE_WAIT_MS;

  while (Date.now() < deadline) {
    let fd;
    try {
      fd = fs.openSync(gatePath, 'wx');
      fs.writeFileSync(fd, payload, 'utf8');
      fs.closeSync(fd);
      return { gateId, gatePath };
    } catch (error) {
      if (fd !== undefined) {
        try { fs.closeSync(fd); } catch { /* best effort */ }
      }
      if (error.code !== 'EEXIST') return null;
    }

    const gate = readJson(gatePath);
    if (
      (gate && ownerAgeMs(gate) > COORDINATION_GATE_TTL_MS)
      || (!gate && fs.existsSync(gatePath) && fileAgeMs(gatePath) > COORDINATION_GATE_TTL_MS)
    ) {
      quarantine(gatePath);
      continue;
    }
    sleepSync(10);
  }

  return null;
}

function releaseCoordinationGate(gate) {
  if (!gate?.gateId || !gate.gatePath) return false;
  const owner = readJson(gate.gatePath);
  if (!owner || owner.gate_id !== gate.gateId) return false;
  try {
    fs.unlinkSync(gate.gatePath);
    return true;
  } catch {
    return false;
  }
}

function unavailable(message) {
  return {
    acquired: false,
    reason_code: 'update_lock_unavailable',
    message,
    owner: null,
  };
}

function busy(owner) {
  return {
    acquired: false,
    reason_code: 'update_in_progress',
    message: 'Another update or sale operation is in progress.',
    owner: safeOwner(owner),
  };
}

function acquireUpdateLock(storageDir, operation, context = {}, options = {}) {
  const ttlMs = Number.isFinite(options.ttlMs) ? Math.max(1, options.ttlMs) : LOCK_TTL_MS;
  fs.mkdirSync(storageDir, { recursive: true });
  const gate = acquireCoordinationGate(storageDir);
  if (!gate) return unavailable('The update coordination gate is unavailable.');

  try {
    const { lockPath } = getCoordinationPaths(storageDir);
    let recoveredFrom = null;
    const existing = readJson(lockPath);
    if (existing && ownerAgeMs(existing) <= ttlMs) {
      return busy(existing);
    }
    if (fs.existsSync(lockPath)) {
      if (!existing && fileAgeMs(lockPath) <= ttlMs) {
        return unavailable('The shared update coordination lock is unreadable.');
      }
      if (!quarantine(lockPath)) {
        return unavailable('The stale shared update coordination lock could not be recovered.');
      }
      recoveredFrom = existing;
    }

    const saleCheck = findActiveSaleOwner(storageDir, ttlMs);
    if (saleCheck.unavailable) {
      return unavailable('An active sale coordination lease is unreadable.');
    }
    if (saleCheck.owner) {
      return busy(saleCheck.owner);
    }
    if (!recoveredFrom) recoveredFrom = saleCheck.recoveredFrom;

    const ownerId = `${process.pid}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const payload = {
      owner_id: ownerId,
      operation,
      pid: process.pid,
      time: Math.floor(Date.now() / 1000),
      started_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      context,
    };
    let fd;
    try {
      fd = fs.openSync(lockPath, 'wx');
      fs.writeFileSync(fd, `${JSON.stringify(payload)}\n`, 'utf8');
      fs.closeSync(fd);
    } catch (error) {
      if (fd !== undefined) {
        try { fs.closeSync(fd); } catch { /* best effort */ }
      }
      const latest = readJson(lockPath);
      if (latest && ownerAgeMs(latest) <= ttlMs) return busy(latest);
      return unavailable('The shared update coordination lock could not be created.');
    }

    const lease = { acquired: true, owner_id: ownerId, operation, lock_path: lockPath };
    if (recoveredFrom) lease.recovered_from = safeOwner(recoveredFrom);
    return lease;
  } finally {
    releaseCoordinationGate(gate);
  }
}

function releaseUpdateLock(lease) {
  if (!lease?.acquired || !lease.owner_id || !lease.lock_path) return false;
  const owner = readJson(lease.lock_path);
  if (!owner || owner.owner_id !== lease.owner_id) return false;
  try {
    fs.unlinkSync(lease.lock_path);
    return true;
  } catch {
    return false;
  }
}

function heartbeatUpdateLock(lease, phase, context = {}) {
  if (!lease?.acquired || !lease.owner_id || !lease.lock_path) return false;
  const owner = readJson(lease.lock_path);
  if (!owner || owner.owner_id !== lease.owner_id) return false;
  const payload = {
    ...owner,
    phase,
    context: {
      ...(owner.context && typeof owner.context === 'object' ? owner.context : {}),
      ...context,
    },
    time: Math.floor(Date.now() / 1000),
    updated_at: new Date().toISOString(),
  };
  try {
    fs.writeFileSync(lease.lock_path, `${JSON.stringify(payload)}\n`, 'utf8');
    return true;
  } catch {
    return false;
  }
}

function readUpdateState(storageDir) {
  const { statePath } = getCoordinationPaths(storageDir);
  const state = readJson(statePath);
  return state && typeof state === 'object' ? state : null;
}

function writeUpdateState(storageDir, state, context = {}) {
  const { statePath } = getCoordinationPaths(storageDir);
  fs.mkdirSync(storageDir, { recursive: true });
  const current = readUpdateState(storageDir) || {};
  const payload = {
    ...current,
    ...context,
    state,
    started_at: current.started_at || new Date().toISOString(),
    updated_at: new Date().toISOString(),
  };
  const temporary = `${statePath}.${process.pid}.${Date.now()}.tmp`;
  fs.writeFileSync(temporary, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
  try {
    fs.renameSync(temporary, statePath);
  } catch (error) {
    if (error.code !== 'EEXIST' && error.code !== 'EPERM') throw error;
    try { fs.unlinkSync(statePath); } catch { /* destination may not exist */ }
    fs.renameSync(temporary, statePath);
  }
  return payload;
}

function isActiveUpdateState(state, now = Date.now(), ttlMs = LOCK_TTL_MS) {
  if (!state || !ACTIVE_UPDATE_STATES.has(state.state)) return false;
  const updatedAt = Date.parse(state.updated_at || '');
  if (!Number.isFinite(updatedAt)) return true;
  return Math.max(0, now - updatedAt) <= ttlMs;
}

module.exports = {
  ACTIVE_UPDATE_STATES,
  LOCK_TTL_MS,
  acquireUpdateLock,
  getCoordinationPaths,
  heartbeatUpdateLock,
  isActiveUpdateState,
  readUpdateState,
  releaseUpdateLock,
  writeUpdateState,
};
