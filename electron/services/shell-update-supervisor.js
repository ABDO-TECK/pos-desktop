'use strict';

const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const http = require('node:http');
const { execFile } = require('node:child_process');
const {
  EXE, WORKER, VALID_OPERATION, VALID_VERSION, canonical, ensureDirectory, operationPaths, stateLocation,
  assertScratchOwnership, assertOperationPathBudget,
  readActive, clearActive, writeRecord, durableFile, plainFile, fileHash, powershell,
  phpArgs, workerEnv, secret, hash, copyChecked, bytesInTree, requireSpace, jsonLine, send,
  spawnCandidateBroker, waitForSourceShutdown, sharedUpdateContext,
} = require('./shell-update-files');
const {
  heartbeatUpdateLock, releaseUpdateLock, writeUpdateState,
} = require('./update-coordination');

const processDeadline = 20_000;
const transactionDeadline = 180_000;
const healthDeadline = 90_000;
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let job;
let stateRoot;
let paths;
let ownership;
let leaseTimer;
let candidate;

function loadIdentity() {
  if (process.argv.length !== 5 || !['begin', 'recover'].includes(process.argv[2]) ||
      !VALID_OPERATION.test(process.argv[4])) {
    throw new Error('Invalid private supervisor entry point.');
  }
  const installRoot = canonical(process.argv[3]);
  const localProfile = canonical(process.env.LOCALAPPDATA);
  stateRoot = canonical(stateLocation(installRoot, localProfile));
  paths = operationPaths(stateRoot, process.argv[4]);
  assertOperationPathBudget(paths);
  assertScratchOwnership(paths, stateRoot, process.argv[4]);
  if (canonical(__filename).toLowerCase() !== path.join(paths.worker, WORKER).toLowerCase() ||
      canonical(process.execPath).toLowerCase() !== path.join(paths.runtime, EXE).toLowerCase()) {
    throw new Error('The independent supervisor was launched from the wrong location.');
  }
  return { installRoot, id: process.argv[4] };
}

function listen(server, options) {
  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(options, () => {
      server.removeListener('error', reject);
      resolve();
    });
  });
}

async function obtainOwnership() {
  ownership = net.createServer((socket) => socket.destroy());
  // The OS releases the exclusive listener if the worker dies. There is no
  // age-based takeover that could admit a second live updater.
  const endpoint = '\\\\.\\pipe\\pos-shell-supervisor-' + hash(stateRoot.toLowerCase());
  await listen(ownership, { path: endpoint, exclusive: true, readableAll: false, writableAll: false });
}

function save(phase, extra = {}) {
  job = { ...job, ...extra, phase, updated_at: new Date().toISOString() };
  writeRecord(path.join(stateRoot, 'active.json'), job);
}

function updateState(state, extra = {}) {
  writeUpdateState(job.dataDir, state, {
    ...sharedUpdateContext(job),
    phase: state,
    from_version: job.context.version,
    shell_transaction_id: job.id,
    ...extra,
  });
}

async function transaction(command, extra = {}, env = {}) {
  const php = plainFile(path.join(paths.php, 'php.exe'));
  plainFile(paths.backend);
  const child = execFile(php, phpArgs(paths), {
    cwd: paths.php, maxBuffer: 512 * 1024, timeout: transactionDeadline, windowsHide: true,
    env: { ...workerEnv(), ...env },
  });
  const [result] = await Promise.all([
    new Promise((resolve, reject) => {
      let stdout = '';
      let stderr = '';
      child.stdout.setEncoding('utf8').on('data', (chunk) => { stdout += chunk; });
      child.stderr.setEncoding('utf8').on('data', (chunk) => { stderr += chunk; });
      child.once('error', reject);
      child.once('close', (code) => {
        if (code === 0) {
          try { resolve(JSON.parse(stdout)); } catch (error) { reject(error); }
        } else {
          reject(new Error('Shell transaction ' + command + ' failed: ' + stderr.trim().slice(0, 1800)));
        }
      });
    }),
    new Promise((resolve, reject) => {
      child.stdin.once('error', reject);
      child.stdin.end(JSON.stringify({
        command, root: job.installRoot, state: paths.transaction,
        operation: job.id, context: job.context, target: job.target, target_native: job.nativeTarget, ...extra,
      }), resolve);
    }),
  ]);
  return result;
}

async function productProcesses() {
  const output = await powershell([
    '[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)',
    '$items = @(Get-CimInstance Win32_Process | Where-Object { $_.Name -in @("POS Desktop.exe", "php.exe", "php-cgi.exe", "mysqld.exe", "mariadbd.exe", "java.exe", "javaw.exe") } | Select-Object ProcessId, Name, ExecutablePath, CommandLine)',
    'ConvertTo-Json -InputObject $items -Compress',
  ].join('\n'));
  const prefix = job.installRoot.toLowerCase() + path.sep;
  const database = path.join(job.dataDir, 'mysql_data').toLowerCase();
  return JSON.parse(output).filter((entry) => {
    if (Number(entry.ProcessId) === process.pid) return false;
    const executable = typeof entry.ExecutablePath === 'string' ? entry.ExecutablePath.toLowerCase() : '';
    const command = typeof entry.CommandLine === 'string' ? entry.CommandLine.toLowerCase() : '';
    if (!executable && /^(pos desktop|php|php-cgi|mariadbd|mysqld)\.exe$/i.test(entry.Name)) return true;
    return executable.startsWith(prefix) || command.includes(database) ||
      (command.includes(prefix) && /qz-tray\.jar/i.test(command));
  });
}

async function waitForQuiescence() {
  const deadline = Date.now() + processDeadline;
  do {
    const processes = await productProcesses();
    if (processes.length === 0) return;
    if (Date.now() >= deadline) {
      throw new Error('Could not prove all installed POS and database processes had exited.');
    }
    await delay(500);
  } while (true);
}

async function databaseTree(root, destination = null, current = root, entries = {}) {
  const sorted = (await fs.promises.readdir(canonical(current), { withFileTypes: true }))
    .sort((a, b) => a.name.localeCompare(b.name, 'en'));
  for (const entry of sorted) {
    const source = path.join(current, entry.name);
    const relative = path.relative(root, source);
    const target = destination ? path.join(destination, relative) : null;
    if (entry.isDirectory() && !entry.isSymbolicLink()) {
      entries[relative] = 'directory';
      if (target) ensureDirectory(target);
      await databaseTree(root, destination, source, entries);
    } else if (entry.isFile()) {
      const stat = fs.lstatSync(plainFile(source));
      const digest = await fileHash(source);
      entries[relative] = digest;
      if (target) {
        await copyChecked(source, target, { sha256: digest, size: stat.size });
      }
    } else {
      throw new Error('Database directory contains a redirected or special entry.');
    }
  }
  return entries;
}

function signatureOf(entries) {
  return hash(JSON.stringify(Object.entries(entries).sort(([a], [b]) => a.localeCompare(b, 'en'))));
}

async function coldBackup() {
  const data = canonical(path.join(job.dataDir, 'mysql_data'));
  plainFile(path.join(data, 'ibdata1'));
  const dataSize = bytesInTree(data);
  const sameVolume = path.parse(data).root.toLowerCase() === path.parse(paths.backup).root.toLowerCase();
  requireSpace(paths.operation, sameVolume ? 2n * dataSize : dataSize);
  if (!sameVolume) requireSpace(data, dataSize);
  ensureDirectory(paths.backup);
  const copied = signatureOf(await databaseTree(data, paths.backup));
  const physical = signatureOf(await databaseTree(data));
  if (physical !== copied || signatureOf(await databaseTree(paths.backup)) !== copied) {
    throw new Error('Database cold backup verification failed.');
  }
  writeRecord(paths.hashes, { digest: copied });
  save('backup_ready', { databaseBackupDigest: copied });
}

async function restoreDatabase() {
  const { databaseBackupDigest } = job;
  const data = path.join(job.dataDir, 'mysql_data');
  const restored = path.join(job.dataDir, '.shell-restore-' + job.id);
  const prior = path.join(job.dataDir, '.shell-quarantine-' + job.id);
  if (!/^[0-9a-f]{64}$/.test(databaseBackupDigest) ||
      signatureOf(await databaseTree(paths.backup)) !== databaseBackupDigest) {
    throw new Error('The closed database backup is missing or changed.');
  }
  if (!fs.existsSync(restored) && fs.existsSync(prior)) {
    if (signatureOf(await databaseTree(data)) !== databaseBackupDigest) {
      throw new Error('The previously restored database bytes have changed.');
    }
    return;
  }
  if (fs.existsSync(restored) && signatureOf(await databaseTree(restored)) !== databaseBackupDigest) {
    if (fs.existsSync(prior)) throw new Error('The staged database is incomplete after the original was moved.');
    fs.renameSync(canonical(restored), restored + '-partial-' + secret().slice(0, 12));
  }
  if (!fs.existsSync(restored)) {
    ensureDirectory(restored);
    await databaseTree(paths.backup, restored);
  }
  if (signatureOf(await databaseTree(restored)) !== databaseBackupDigest) {
    throw new Error('The staged cold database restore is incomplete.');
  }
  if (!fs.existsSync(prior)) fs.renameSync(canonical(data), prior);
  if (fs.existsSync(data)) throw new Error('The database path was recreated during rollback.');
  fs.renameSync(canonical(restored), data);
  if (signatureOf(await databaseTree(data)) !== databaseBackupDigest) {
    throw new Error('Cold database rollback did not restore the verified bytes.');
  }
}

function reportReceipt(outcome, detail = {}) {
  writeRecord(paths.receipt, {
    operation_id: job.id, outcome, phase: job.phase,
    source_version: job.context.version, target_version: job.target,
    source_pid: job.sourcePid, candidate_pid: job.candidatePid ?? null,
    restored_pid: job.restoredPid ?? null, candidate_exit_code: job.candidateExit ?? null,
    database_backup_sha256: job.databaseBackupDigest ?? null,
    transaction_digest: job.digest ?? null,
    candidate_health: job.candidateHealth ?? null, restored_health: job.restoredHealth ?? null,
    candidate_failure: job.candidateFailure ?? null, restored_failure: job.restoredFailure ?? null,
    candidate_broker_phase: job.candidateBrokerPhase ?? null, restored_broker_phase: job.restoredBrokerPhase ?? null,
    ...detail,
  });
}

function finish(state, detail = {}) {
  save(state, detail);
  updateState(state, {
    error: detail.error ?? null,
    requires_recovery: state === 'recovery_required',
    source_runtime_recovered: job.restoredHealth?.database === true,
    source_reopened_without_mutation: detail.source_reopened_without_mutation === true,
  });
  reportReceipt(state, detail);
  if (state !== 'recovery_required') {
    releaseUpdateLock(job.lease);
    clearActive(stateRoot, job.id);
  }
}

async function stopCandidate(child) {
  if (!child) return;
  child.socket?.end();
  child.server.close();
  if (child.control && !child.control.stdin.writableEnded) child.control.stdin.end('stop\n');
  if (child.control && !child.released) {
    await bounded(child.brokerExit, processDeadline, 'The candidate Job Object broker did not exit.');
    if (!child.stopped) {
      throw new Error('The candidate Job Object did not prove the runtime stopped.');
    }
  }
}

function bounded(signal, timeout, error) {
  let timer;
  return Promise.race([
    signal,
    new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(error)), timeout); }),
  ]).finally(() => clearTimeout(timer));
}

function matchesDatabaseContract(database) {
  const expected = job.context.data_compatibility;
  return database && ['format', 'metadata_migration', 'schema_sha256', 'ledger_sha256']
    .every((key) => database[key] === expected[key]);
}

function checkHealthFrame(frame, version, nativeVersion) {
  return frame.version === version && frame.nativeVersion === nativeVersion && frame.ui === 'sign-in' && frame.php === true &&
    Number.isInteger(frame.phpPort) && frame.phpPort >= 1 && frame.phpPort <= 65535 &&
    matchesDatabaseContract(frame.data_compatibility);
}

function provePhp(port, token, version) {
  return new Promise((resolve, reject) => {
    const request = http.get({
      hostname: '127.0.0.1', port, path: '/api/health',
      headers: { 'X-POS-Shell-Health': token },
    }, (response) => {
      let body = '';
      response.setEncoding('utf8');
      response.on('data', (chunk) => {
        body += chunk;
        if (body.length > 16384) request.destroy(new Error('PHP health response exceeded the allowed size.'));
      });
      response.once('error', reject);
      response.once('end', () => {
        try {
          const data = JSON.parse(body);
          if (response.statusCode !== 200 || data.version !== version || data.critical_failed !== false ||
              !matchesDatabaseContract(data.data_compatibility)) {
            throw new Error('The restarted PHP and MariaDB health probe did not match the signed target.');
          }
          resolve({ ui: 'sign-in', php: true, version, database: true });
        } catch (error) {
          reject(error);
        }
      });
    });
    request.setTimeout(5000, () => request.destroy(new Error('The restarted PHP health probe timed out.')));
    request.once('error', reject);
  });
}

async function startProbation(version, nativeVersion, restored) {
  const token = secret();
  const server = net.createServer({ pauseOnConnect: true });
  const pending = [];
  const deferConnection = (socket) => pending.push(socket);
  server.on('connection', deferConnection);
  await listen(server, { host: '127.0.0.1', port: 0, exclusive: true });
  const endpoint = server.address().port;
  const phaseField = restored ? 'restoredBrokerPhase' : 'candidateBrokerPhase';
  save(restored ? 'launching_restored' : 'launching_target', { [phaseField]: 'spawning' });
  let control;
  try {
    control = spawnCandidateBroker(paths.worker, job.installRoot, { token, endpoint, id: job.id });
  } catch (error) {
    server.close();
    throw error;
  }
  const session = { control, server, token, socket: null, stopped: false, released: false, exitObserved: false };
  candidate = session;
  let started;
  let exited;
  let failed;
  const startedSignal = new Promise((resolve, reject) => { started = resolve; failed = reject; });
  const exitedSignal = new Promise((resolve) => { exited = resolve; });
  session.brokerExit = new Promise((resolve) => {
    control.once('error', (error) => { failed(error); resolve(-1); });
    control.once('close', (code) => { failed(new Error('The candidate broker exited before launch.')); resolve(code); });
  });
  control.stdin.on('error', failed);
  jsonLine(control.stdout, (frame) => {
    if (frame.event === 'phase' && ['initializing', 'creating_job'].includes(frame.phase)) {
      save(job.phase, { [phaseField]: frame.phase });
    } else if (frame.event === 'started') {
      save(job.phase, { [phaseField]: 'running' });
      started(frame.pid);
    }
    else if (frame.event === 'exited') {
      session.exitObserved = true;
      exited(frame.code);
    }
    else if (frame.event === 'error') failed(new Error('Native broker ' + frame.phase + ': ' + frame.error));
    else if (frame.event === 'stopped') session.stopped = true;
    else if (frame.event === 'released') session.released = true;
  }, failed);
  let pid;
  try {
    pid = await bounded(startedSignal, processDeadline, 'The candidate Job Object failed to launch.');
    if (!Number.isInteger(pid) || pid < 1) throw new Error('The native broker returned an invalid candidate PID.');
  } catch (error) {
    throw new Error(error.message + ' Last native broker phase: ' + job[phaseField] + '.');
  }
  save(restored ? 'probing_restored' : 'probing_target', {
    [restored ? 'restoredPid' : 'candidatePid']: pid,
  });
  const healthy = new Promise((resolve, reject) => {
    const receive = (socket) => {
      socket.setTimeout(healthDeadline, () => socket.destroy());
      socket.on('error', () => {});
      jsonLine(socket, (frame) => {
        if (frame.token !== token || frame.pid !== pid || frame.id !== job.id) {
          socket.destroy();
          return;
        }
        if (frame.event === 'starting' && !session.socket) {
          session.socket = socket;
          send(socket, { command: 'probe', nativeVersion });
          return;
        }
        if (socket !== session.socket) return;
        if (frame.event === 'failed') {
          reject(new Error('The restarted POS runtime reported startup failure: ' + String(frame.error).slice(0, 400)));
        } else if (frame.event === 'health' && checkHealthFrame(frame, version, nativeVersion)) {
          provePhp(frame.phpPort, token, version).then((proof) => resolve({ ...proof, native_version: nativeVersion }), reject);
        } else {
          reject(new Error('The restarted POS runtime returned an invalid health proof.'));
        }
      }, () => socket.destroy());
      socket.resume();
    };
    server.removeListener('connection', deferConnection);
    server.on('connection', receive);
    pending.forEach(receive);
  });
  const outcome = await bounded(Promise.race([
    healthy.then((proof) => ({ proof })),
    exitedSignal.then((code) => ({ code })),
    session.brokerExit.then(() => ({ brokerFailed: true })),
  ]), healthDeadline, 'Restarted POS health timed out.');
  if (!outcome.proof) {
    if (!restored) save(job.phase, { candidateExit: outcome.code ?? null });
    throw new Error('The restarted POS process exited before health proof: ' + (outcome.code ?? 'ownership lost'));
  }
  save(job.phase, { [restored ? 'restoredHealth' : 'candidateHealth']: outcome.proof });
  return session;
}

async function admit(session, state, detail = {}) {
  if (!session?.socket || session.socket.destroyed || session.exitObserved) throw new Error('Healthy POS lost supervisor control before admission.');
  session.control.stdin.end('release\n');
  const result = await bounded(session.brokerExit, processDeadline, 'The healthy POS Job Object could not be released.');
  if (result !== 0 || !session.released) throw new Error('The healthy POS could not be admitted.');
  durableFile(paths.gate, session.token);
  finish(state, detail);
  send(session.socket, { command: 'admit' });
  session.socket.end();
  session.server.close();
  candidate = null;
}

async function rollback(error) {
  const reason = error.message;
  await stopCandidate(candidate);
  candidate = null;
  await waitForQuiescence();
  const current = (await transaction('phase')).phase;
  if (current === 'committed') {
    // A durable commit must never be reverted, including if the PHP reply or
    // supervisor was interrupted after the journal changed.
    if (!job.digest) throw new Error('Committed target digest is missing from the supervisor journal.');
    await transaction('commit', { digest: job.digest });
    const healthyTarget = await startProbation(job.target, job.nativeTarget, false);
    await admit(healthyTarget, 'completed');
    return;
  }
  if (current !== 'none') await transaction('recover');
  if (job.databaseMayHaveChanged && !job.databaseRestored) {
    save('restoring_database', { error: reason });
    await restoreDatabase();
    save('source_restored', { databaseRestored: true });
  }
  try {
    const restored = await startProbation(job.context.version, job.context.native_version, true);
    const reopenAfterDrainFailure = job.reopenAfterDrainFailure === true;
    await admit(restored, reopenAfterDrainFailure ? 'failed' : 'rolled_back', {
      error: reason,
      ...(reopenAfterDrainFailure ? { source_reopened_without_mutation: true } : {}),
    });
  } catch (restoreError) {
    save(job.phase, { restoredFailure: restoreError.message });
    throw restoreError;
  }
}

async function begin(first, nextFrame) {
  if (!first || first.event !== 'begin' || first.job.id !== job.id || first.job.installRoot !== job.installRoot ||
      !VALID_VERSION.test(first.job.target) || readActive(stateRoot)) {
    throw new Error('Invalid or conflicting shell update handoff.');
  }
  job = first.job;
  save('preparing');
  leaseTimer = setInterval(() => heartbeatUpdateLock(job.lease, job.phase), 10000);
  let shutdownEvent;
  try {
    await transaction('prepare', { package: first.package, images: first.images });
    save('prepared');
    const shutdown = waitForSourceShutdown(nextFrame);
    send(process.stdout, { event: 'prepared' });
    shutdownEvent = await shutdown;
    if (shutdownEvent === 'stopped') {
      save('source_stopped');
    } else {
      save('source_exiting_without_apply', { reopenAfterDrainFailure: true });
    }
  } catch (error) {
    const phase = (await transaction('phase')).phase;
    if (phase === 'prepared') await transaction('recover');
    else if (phase !== 'none') throw error;
    finish('failed', { error: error.message });
    send(process.stdout, { event: 'rejected', error: error.message });
    return;
  }

  if (shutdownEvent === 'drain-failed-source-exiting') {
    await rollback(new Error('The source did not drain cleanly. The shell update was canceled.'));
    return;
  }

  try {
    updateState('applying');
    await waitForQuiescence();
    await coldBackup();
    await waitForQuiescence();
    const applied = await transaction('apply');
    save('files_applied', {
      digest: applied.target_inventory_sha256,
      databaseMayHaveChanged: true,
    });
    const healthy = await startProbation(job.target, job.nativeTarget, false);
    if (healthy.exitObserved || healthy.socket.destroyed) {
      throw new Error('The candidate exited before its health decision was durable.');
    }
    await transaction('commit', { digest: job.digest });
    await admit(healthy, 'completed');
  } catch (error) {
    save(job.phase, { candidateFailure: error.message });
    await rollback(error);
  }
}

async function main() {
  job = loadIdentity();
  await obtainOwnership();
  if (process.argv[2] === 'recover') {
    const record = readActive(stateRoot);
    if (!record || record.id !== job.id || record.installRoot !== job.installRoot) return;
    job = record;
    if (job.phase === 'recovery_required') return;
    leaseTimer = setInterval(() => heartbeatUpdateLock(job.lease, job.phase), 10000);
    await rollback(new Error('The shell update supervisor was interrupted.'));
    return;
  }
  let nextFrame;
  jsonLine(process.stdin, (frame) => nextFrame?.(frame), (error) => send(process.stdout, { event: 'rejected', error: error.message }));
  const first = new Promise((resolve) => { nextFrame = resolve; });
  await begin(await first, (listener) => { nextFrame = listener; });
}

main().catch(async (error) => {
  if (job?.context) {
    try {
      await stopCandidate(candidate);
      finish('recovery_required', { error: error.message });
    } catch (inner) {
      console.error(inner.message);
    }
  }
  try { send(process.stdout, { event: 'rejected', error: error.message }); } catch { /* source exited */ }
  process.exitCode = 1;
}).finally(() => {
  clearInterval(leaseTimer);
  ownership?.close();
  process.stdin.destroy();
});
process.stdout.on('error', () => {});

