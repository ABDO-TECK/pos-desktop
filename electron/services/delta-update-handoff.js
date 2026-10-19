const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const ALLOWED_PREFIXES = [
  'backend/backend.phar', 'backend/certs/', 'database/pos_schema.sql',
  'frontend/dist/', 'frontend/public/', 'version.json',
];

function isAllowed(relativePath) {
  const normalized = String(relativePath || '').replace(/\\/g, '/').trim();
  return normalized && !normalized.includes('..') && !path.isAbsolute(normalized)
    && !/^[A-Za-z]:/.test(normalized)
    && ALLOWED_PREFIXES.some((prefix) => normalized === prefix || normalized.startsWith(prefix));
}

function hashMatches(filePath, expected) {
  const actual = crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
  return actual.toLowerCase() === String(expected || '').toLowerCase();
}

function replaceFile(source, target) {
  fs.mkdirSync(path.dirname(target), { recursive: true });
  const temporary = path.join(path.dirname(target), `.${path.basename(target)}.delta-${process.pid}.tmp`);
  fs.copyFileSync(source, temporary);
  try {
    fs.renameSync(temporary, target);
  } catch {
    fs.copyFileSync(temporary, target);
    fs.unlinkSync(temporary);
  }
}

function rollback(plan) {
  const metadataPath = path.join(plan.snapshot_path, 'metadata.json');
  if (!fs.existsSync(metadataPath)) return;
  const metadata = JSON.parse(fs.readFileSync(metadataPath, 'utf8'));
  const filesDir = path.join(plan.snapshot_path, 'files');
  for (const entry of [...(metadata.files || []), ...(metadata.deleted_files || [])]) {
    if (!isAllowed(entry.path)) continue;
    const backup = path.join(filesDir, entry.path);
    if (fs.existsSync(backup)) replaceFile(backup, path.join(plan.root_dir, entry.path));
  }
  for (const relativePath of metadata.new_files || []) {
    if (!isAllowed(relativePath)) continue;
    const target = path.join(plan.root_dir, relativePath);
    if (fs.existsSync(target)) fs.unlinkSync(target);
  }
  if (typeof metadata.version_json_backup === 'string') {
    fs.writeFileSync(path.join(plan.root_dir, 'version.json'), metadata.version_json_backup);
  }
}

function setHandoffState(plan, state, context = {}) {
  const statePath = path.join(plan.storage_dir, 'update-state.json');
  let current = {};
  try { current = JSON.parse(fs.readFileSync(statePath, 'utf8')); } catch { /* first write */ }
  fs.writeFileSync(statePath, `${JSON.stringify({
    ...current,
    ...context,
    state,
    updated_at: new Date().toISOString(),
  }, null, 2)}\n`);
}

function applyPendingDelta(storageDir, version, expectedRoot) {
  const safeVersion = String(version || '').replace(/[^A-Za-z0-9._-]/g, '_');
  const planPath = path.join(storageDir, 'update_staging', safeVersion, 'desktop-handoff.json');
  const plan = JSON.parse(fs.readFileSync(planPath, 'utf8'));
  if (path.resolve(plan.storage_dir) !== path.resolve(storageDir) || path.resolve(plan.root_dir) !== path.resolve(expectedRoot)) {
    throw new Error('Desktop delta hand-off plan targets an unexpected runtime location.');
  }
  const files = plan.manifest?.files || [];
  const deleted = plan.manifest?.deleted_files || [];

  try {
    for (const file of files) {
      if ((file.action || 'replace') === 'delete') continue;
      if (!isAllowed(file.path)) throw new Error(`Unsafe delta path: ${file.path}`);
      const staged = path.join(plan.staging_dir, file.path);
      if (!fs.existsSync(staged) || !hashMatches(staged, file.sha256)) {
        throw new Error(`Staged artifact integrity check failed: ${file.path}`);
      }
      replaceFile(staged, path.join(plan.root_dir, file.path));
    }
    for (const relativePath of deleted) {
      if (!isAllowed(relativePath)) throw new Error(`Unsafe deleted artifact path: ${relativePath}`);
      const target = path.join(plan.root_dir, relativePath);
      if (fs.existsSync(target)) fs.unlinkSync(target);
    }
    // A signed Delta normally carries version.json itself. Do not serialize it
    // again after replacement: formatting or non-version metadata changes
    // would make the installed artifact differ from its verified hash.
    const deploysVersionFile = files.some((file) => file.path === 'version.json');
    if (!deploysVersionFile) {
      const versionFile = path.join(plan.root_dir, 'version.json');
      const current = fs.existsSync(versionFile) ? JSON.parse(fs.readFileSync(versionFile, 'utf8')) : {};
      fs.writeFileSync(versionFile, `${JSON.stringify({ ...current, version: plan.version }, null, 2)}\n`);
    }
    return { ok: true, plan };
  } catch (error) {
    rollback(plan);
    return { ok: false, error: error.message, plan };
  }
}

module.exports = { applyPendingDelta, rollback, setHandoffState };
