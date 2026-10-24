const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const ALLOWED_EXACT_FILES = [
  'backend/backend.phar',
  'database/pos_schema.sql',
  'version.json',
];

const ALLOWED_DIR_PREFIXES = [
  'backend/certs/',
  'frontend/dist/',
  'frontend/public/',
];

function isAllowed(relativePath) {
  const normalized = String(relativePath || '').replace(/\\/g, '/').trim();
  if (
    !normalized ||
    normalized.includes('..') ||
    path.isAbsolute(normalized) ||
    /^[A-Za-z]:/.test(normalized) ||
    normalized.startsWith('//')
  ) {
    return false;
  }

  const matchesExact = ALLOWED_EXACT_FILES.includes(normalized);
  const matchesPrefix = ALLOWED_DIR_PREFIXES.some((prefix) => normalized.startsWith(prefix));
  if (!matchesExact && !matchesPrefix) return false;

  // Reject paths that target user runtime databases, storage, .env or certificates
  const lower = normalized.toLowerCase();
  if (
    lower.includes('.env') ||
    lower.includes('/storage/') ||
    lower.endsWith('.db') ||
    lower.endsWith('.sqlite') ||
    lower.includes('digital-certificate')
  ) {
    return false;
  }

  return true;
}

function resolveSafeConfinedPath(rootPath, childPath, options = {}) {
  const { requireExists = false, allowRoot = false } = options;
  if (typeof rootPath !== 'string' || !rootPath) {
    throw new Error('Invalid rootPath for path confinement check');
  }
  if (typeof childPath !== 'string' || !childPath) {
    throw new Error('Invalid childPath for path confinement check');
  }

  const normalizedChild = childPath.replace(/\\/g, '/').trim();
  if (
    normalizedChild.includes('..') ||
    path.isAbsolute(normalizedChild) ||
    /^[A-Za-z]:/.test(normalizedChild) ||
    normalizedChild.startsWith('//')
  ) {
    throw new Error(`Path escapes root boundary: ${childPath}`);
  }

  const resolvedRoot = path.resolve(rootPath);
  const resolvedTarget = path.resolve(resolvedRoot, childPath);
  const rel = path.relative(resolvedRoot, resolvedTarget);
  if (rel.startsWith('..') || path.isAbsolute(rel) || (!allowRoot && !rel)) {
    throw new Error(`Path escapes root boundary: ${childPath}`);
  }

  const getReal = (p) => (fs.realpathSync.native ? fs.realpathSync.native(p) : fs.realpathSync(p));

  // Inspect root itself
  let rootStat;
  try {
    rootStat = fs.lstatSync(resolvedRoot);
  } catch (err) {
    throw new Error(`Cannot inspect root path ${resolvedRoot}: ${err.message}`);
  }
  if (rootStat.isSymbolicLink()) {
    throw new Error(`Root directory is a symbolic link: ${resolvedRoot}`);
  }
  let realRoot;
  try {
    realRoot = getReal(resolvedRoot);
  } catch (err) {
    throw new Error(`Cannot resolve real path of root ${resolvedRoot}: ${err.message}`);
  }

  // Decompose path into ancestors from root down to target
  let current = resolvedTarget;
  const segments = [];
  while (current && current.length >= resolvedRoot.length) {
    segments.push(current);
    const parent = path.dirname(current);
    if (parent === current) break;
    current = parent;
  }
  segments.reverse();

  for (const seg of segments) {
    let stat;
    try {
      stat = fs.lstatSync(seg);
    } catch (err) {
      if (err.code === 'ENOENT') {
        if (requireExists) {
          throw new Error(`Required path does not exist: ${childPath}`);
        }
        // Segment does not exist; stop checking deeper segments
        break;
      }
      // ANY other error (EACCES, EPERM, etc.) must fail closed
      throw new Error(`Failed to inspect path segment ${seg}: ${err.message}`);
    }

    if (stat.isSymbolicLink()) {
      throw new Error(`Reparse point or symbolic link rejected in path: ${childPath}`);
    }

    let segReal;
    try {
      segReal = getReal(seg);
    } catch (err) {
      throw new Error(`Failed to resolve realpath for ${seg}: ${err.message}`);
    }

    if (seg !== resolvedRoot) {
      let parentReal;
      try {
        parentReal = getReal(path.dirname(seg));
      } catch (err) {
        throw new Error(`Failed to resolve realpath for parent of ${seg}: ${err.message}`);
      }
      const expectedReal = path.join(parentReal, path.basename(seg));
      if (expectedReal.toLowerCase() !== segReal.toLowerCase()) {
        throw new Error(`Reparse point or junction rejected in path: ${childPath}`);
      }
    }

    const realRel = path.relative(realRoot, segReal);
    if (realRel.startsWith('..') || path.isAbsolute(realRel)) {
      throw new Error(`Path reparse escapes root boundary: ${childPath}`);
    }
  }

  return resolvedTarget;
}

function resolveSafeTargetPath(rootDir, relativePath) {
  if (!isAllowed(relativePath)) {
    throw new Error(`Unsafe delta path rejected: ${relativePath}`);
  }
  return resolveSafeConfinedPath(rootDir, relativePath);
}

function hashMatches(filePath, expected) {
  try {
    const actual = crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
    return actual.toLowerCase() === String(expected || '').toLowerCase();
  } catch {
    return false;
  }
}

function hashOf(filePath) {
  return crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
}

// Transaction-local expectations must not be reconstructed from mutable backup metadata.
const rollbackExpectations = new WeakMap();

function captureRollbackExpectation(plan, metadata, captureInstalled = false) {
  const baseline = plan.manifest?.baseline_files || {};
  if (!baseline || typeof baseline !== 'object' || Array.isArray(baseline)) {
    throw new Error('Invalid rollback baseline_files');
  }
  const expected = { ...baseline };
  const classified = new Map();
  const groups = ['files', 'deleted_files', 'new_files'];
  for (const group of groups) {
    const entries = metadata[group] === undefined && group !== 'files' ? [] : metadata[group];
    if (!Array.isArray(entries)) throw new Error(`Snapshot metadata.${group} must be an array`);
    for (const entry of entries) {
      const relativePath = typeof entry === 'string' ? entry : entry?.path;
      if (!isAllowed(relativePath) || classified.has(relativePath)) {
        throw new Error(`Invalid or conflicting rollback metadata path: ${relativePath}`);
      }
      classified.set(relativePath, group);
      if (group === 'new_files') {
        if (Object.hasOwn(baseline, relativePath)) {
          throw new Error(`Rollback new file conflicts with baseline: ${relativePath}`);
        }
        expected[relativePath] = null;
      } else {
        const sha = (typeof entry === 'object' && entry.sha256) || baseline[relativePath];
        if (typeof sha !== 'string' || !/^[a-f0-9]{64}$/i.test(sha) ||
            (baseline[relativePath] && sha.toLowerCase() !== String(baseline[relativePath]).toLowerCase())) {
          throw new Error(`Rollback metadata sha256 does not match baseline: ${relativePath}`);
        }
        expected[relativePath] = sha;
      }
    }
  }
  for (const file of plan.manifest?.files || []) {
    if (!['files', 'new_files'].includes(classified.get(file.path))) {
      throw new Error(`Incomplete rollback metadata coverage: ${file.path}`);
    }
  }
  for (const relativePath of plan.manifest?.deleted_files || []) {
    if (classified.get(relativePath) !== 'deleted_files') {
      throw new Error(`Incomplete rollback deleted_files coverage: ${relativePath}`);
    }
  }
  if (typeof metadata.version_json_backup !== 'string' || !metadata.version_json_backup) {
    throw new Error('Missing rollback version_json_backup');
  }
  const versionSha = crypto.createHash('sha256').update(metadata.version_json_backup).digest('hex');
  if (expected['version.json'] && versionSha !== String(expected['version.json']).toLowerCase()) {
    throw new Error('Rollback version_json_backup does not match baseline');
  }
  expected['version.json'] = versionSha;
  for (const [relativePath, sha] of Object.entries(expected)) {
    if (!isAllowed(relativePath) || (sha !== null && (typeof sha !== 'string' || !/^[a-f0-9]{64}$/i.test(sha)))) {
      throw new Error(`Invalid rollback baseline entry: ${relativePath}`);
    }
  }
  const expectation = Object.freeze({
    files: Object.freeze(expected),
    metadata: JSON.stringify(metadata),
  });
  if (captureInstalled) {
    const errors = verifyRollbackBaseline(plan, expectation);
    if (errors.length) throw new Error(errors.map((entry) => entry.error).join('; '));
  }
  return expectation;
}

function verifyRollbackBaseline(plan, expectation, includeVersion = true) {
  const errors = [];
  for (const [relativePath, sha] of Object.entries(expectation.files)) {
    if (!includeVersion && relativePath === 'version.json') continue;
    try {
      const target = resolveSafeTargetPath(plan.root_dir, relativePath);
      if (sha === null) {
        // lstat distinguishes true absence from access errors and dangling links.
        try {
          fs.lstatSync(target);
          throw new Error(`Rollback new file still exists: ${relativePath}`);
        } catch (err) {
          if (err.code !== 'ENOENT') throw err;
        }
      } else if (!hashMatches(target, sha)) {
        throw new Error(`Rollback baseline hash mismatch: ${relativePath}`);
      }
    } catch (err) {
      errors.push({ path: relativePath, error: err.message });
    }
  }
  return errors;
}

function generateTempPath(targetPath) {
  const dir = path.dirname(targetPath);
  const base = path.basename(targetPath);
  const uniqueSuffix = `${process.pid}-${Date.now()}-${crypto.randomBytes(6).toString('hex')}`;
  return path.join(dir, `.${base}.delta-${uniqueSuffix}.tmp`);
}

function isReadOnly(filePath) {
  try {
    const stat = fs.statSync(filePath);
    return (stat.mode & 0o200) === 0;
  } catch {
    return false;
  }
}

function clearReadOnlyIfSet(filePath) {
  try {
    const stat = fs.statSync(filePath);
    if ((stat.mode & 0o200) === 0) {
      fs.chmodSync(filePath, stat.mode | 0o600);
      return true;
    }
  } catch {}
  return false;
}

function restoreReadOnly(filePath) {
  try {
    const stat = fs.statSync(filePath);
    fs.chmodSync(filePath, stat.mode & ~0o222);
    return true;
  } catch {}
  return false;
}

async function replaceFile(source, target, options = {}) {
  const targetDir = path.dirname(target);
  fs.mkdirSync(targetDir, { recursive: true });

  const temporary = generateTempPath(target);
  let targetWasReadOnly = false;
  let renameError = null;
  let copyError = null;
  let renamed = false;
  const secondaryErrors = [];

  try {
    try {
      fs.copyFileSync(source, temporary, fs.constants.COPYFILE_EXCL);
    } catch (err) {
      copyError = err;
      throw err;
    }

    // On Windows, copyFileSync copies ReadOnly attribute from source.
    // Clear ReadOnly attribute ONLY on our owned temporary file.
    clearReadOnlyIfSet(temporary);

    // If target exists and has ReadOnly set, clear it on the managed target so rename can succeed
    if (fs.existsSync(target)) {
      targetWasReadOnly = clearReadOnlyIfSet(target);
    }

    // Rename with bounded transient retry (handles transient AV / indexing locks)
    const maxRetries = options.retries || 5;
    const retryDelayMs = options.retryDelayMs || 50;

    for (let attempt = 1; attempt <= maxRetries; attempt += 1) {
      try {
        fs.renameSync(temporary, target);
        renamed = true;
        break;
      } catch (err) {
        renameError = err;
        if (
          attempt === maxRetries ||
          (err.code !== 'EPERM' && err.code !== 'EBUSY' && err.code !== 'EACCES')
        ) {
          break;
        }
        await new Promise((resolve) => setTimeout(resolve, retryDelayMs));
      }
    }

    if (!renamed) {
      if (targetWasReadOnly && fs.existsSync(target)) {
        if (!restoreReadOnly(target)) {
          secondaryErrors.push(`Failed to restore target ReadOnly attribute: ${target}`);
        }
      }
      const thrown = renameError || new Error(`Failed to rename '${temporary}' to '${target}'`);
      thrown.secondaryErrors = secondaryErrors.length > 0 ? secondaryErrors : null;
      throw thrown;
    }

    if (options.preserveTargetReadOnly && fs.existsSync(target)) {
      if (!restoreReadOnly(target)) {
        secondaryErrors.push(`Failed to set ReadOnly on target: ${target}`);
      }
    }

    return {
      ok: true,
      wasReadOnly: targetWasReadOnly,
      secondaryErrors: secondaryErrors.length > 0 ? secondaryErrors : null,
    };
  } finally {
    if (temporary && fs.existsSync(temporary)) {
      try {
        clearReadOnlyIfSet(temporary);
        fs.unlinkSync(temporary);
      } catch (unlinkErr) {
        secondaryErrors.push(`Failed to clean up temporary file '${temporary}': ${unlinkErr.message}`);
      }
    }

    const primaryError = copyError || renameError;
    if (primaryError) {
      primaryError.secondaryErrors = secondaryErrors.length > 0 ? secondaryErrors : null;
      primaryError.cleanupError = secondaryErrors.find((s) => s.includes('clean up temporary')) || null;
    } else if (secondaryErrors.length > 0) {
      throw new Error(`File replaced, but cleanup errors occurred: ${secondaryErrors.join('; ')}`);
    }
  }
}

function preflightPendingDelta(storageDir, version, expectedRoot) {
  if (typeof storageDir !== 'string' || !storageDir) {
    return { ok: false, phase: 'preflight', error: 'Invalid storageDir provided to preflight.' };
  }
  const semverRegex = /^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$/;
  if (typeof version !== 'string' || !semverRegex.test(version.trim())) {
    return { ok: false, phase: 'preflight', error: `Invalid version provided to preflight: ${version}` };
  }
  if (typeof expectedRoot !== 'string' || !expectedRoot) {
    return { ok: false, phase: 'preflight', error: 'Invalid expectedRoot provided to preflight.' };
  }

  const resolvedStorage = path.resolve(storageDir);
  const resolvedExpectedRoot = path.resolve(expectedRoot);

  const expectedStagingDir = path.join(resolvedStorage, 'update_staging', version);
  const planPath = path.join(expectedStagingDir, 'desktop-handoff.json');
  if (!fs.existsSync(planPath)) {
    return {
      ok: false,
      phase: 'preflight',
      error: `Desktop handoff plan not found: ${planPath}`,
    };
  }

  let plan;
  try {
    plan = JSON.parse(fs.readFileSync(planPath, 'utf8'));
  } catch (err) {
    return {
      ok: false,
      phase: 'preflight',
      error: `Invalid desktop handoff plan JSON: ${err.message}`,
    };
  }

  if (!plan || typeof plan !== 'object' || Array.isArray(plan)) {
    return { ok: false, phase: 'preflight', error: 'Malformed desktop handoff plan: not a valid object.' };
  }

  if (plan.version !== version) {
    return {
      ok: false,
      phase: 'preflight',
      error: `Plan version mismatch: plan.version '${plan.version}' !== requested '${version}'`,
      plan,
    };
  }

  if (!plan.manifest || typeof plan.manifest !== 'object' || Array.isArray(plan.manifest)) {
    return { ok: false, phase: 'preflight', error: 'Malformed desktop handoff plan: missing manifest object.', plan };
  }

  if (plan.manifest.version !== version) {
    return {
      ok: false,
      phase: 'preflight',
      error: `Manifest version mismatch: manifest.version '${plan.manifest.version}' !== requested '${version}'`,
      plan,
    };
  }

  if (!plan.storage_dir || typeof plan.storage_dir !== 'string') {
    return { ok: false, phase: 'preflight', error: 'Malformed desktop handoff plan: missing storage_dir.', plan };
  }
  if (!plan.root_dir || typeof plan.root_dir !== 'string') {
    return { ok: false, phase: 'preflight', error: 'Malformed desktop handoff plan: missing root_dir.', plan };
  }
  if (!plan.staging_dir || typeof plan.staging_dir !== 'string') {
    return { ok: false, phase: 'preflight', error: 'Malformed desktop handoff plan: missing staging_dir.', plan };
  }
  if (!plan.snapshot_path || typeof plan.snapshot_path !== 'string') {
    return { ok: false, phase: 'preflight', error: 'Malformed desktop handoff plan: missing snapshot_path.', plan };
  }

  if (path.resolve(plan.storage_dir) !== resolvedStorage) {
    return {
      ok: false,
      phase: 'preflight',
      error: 'Desktop delta hand-off plan targets an unexpected storage location.',
      plan,
    };
  }
  if (path.resolve(plan.root_dir) !== resolvedExpectedRoot) {
    return {
      ok: false,
      phase: 'preflight',
      error: 'Desktop delta hand-off plan targets an unexpected root location.',
      plan,
    };
  }
  if (path.resolve(plan.staging_dir) !== path.resolve(expectedStagingDir)) {
    return {
      ok: false,
      phase: 'preflight',
      error: 'Desktop delta hand-off plan targets an unexpected staging directory.',
      plan,
    };
  }

  // Physical validation of staging_dir
  try {
    resolveSafeConfinedPath(resolvedStorage, path.relative(resolvedStorage, plan.staging_dir), { allowRoot: false });
  } catch (err) {
    return { ok: false, phase: 'preflight', error: `Staging directory physical validation failed: ${err.message}`, plan };
  }

  // Snapshot path must be a strict child of storage/update-backups
  const expectedBackupsDir = path.join(resolvedStorage, 'update-backups');
  const relSnapshot = path.relative(expectedBackupsDir, path.resolve(plan.snapshot_path));
  if (relSnapshot.startsWith('..') || path.isAbsolute(relSnapshot) || !relSnapshot) {
    return { ok: false, phase: 'preflight', error: 'Snapshot directory is not a strict child of storage/update-backups.', plan };
  }
  try {
    resolveSafeConfinedPath(resolvedStorage, path.relative(resolvedStorage, plan.snapshot_path), { allowRoot: false });
  } catch (err) {
    return { ok: false, phase: 'preflight', error: `Snapshot directory physical validation failed: ${err.message}`, plan };
  }

  const files = plan.manifest.files;
  const deleted = plan.manifest.deleted_files || [];
  const baseline = plan.manifest.baseline_files || {};

  if (!Array.isArray(files)) {
    return { ok: false, phase: 'preflight', error: 'Malformed delta plan: manifest.files must be an array.', plan };
  }
  if (!Array.isArray(deleted)) {
    return { ok: false, phase: 'preflight', error: 'Malformed delta plan: manifest.deleted_files must be an array.', plan };
  }

  // Enforce signed version.json entry presence in manifest.files
  const versionEntry = files.find((f) => f && f.path === 'version.json');
  if (!versionEntry || typeof versionEntry.sha256 !== 'string' || !versionEntry.sha256) {
    return {
      ok: false,
      phase: 'preflight',
      error: 'Delta manifest must contain a signed version.json entry with valid sha256.',
      plan,
    };
  }

  // 1. Verify manifest file paths & staged file hashes upfront
  for (const file of files) {
    if (!file || typeof file !== 'object') {
      return { ok: false, phase: 'preflight', error: 'Malformed entry in manifest.files.', plan };
    }
    if (!isAllowed(file.path)) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Unsafe target path in manifest: ${file.path}`,
        plan,
      };
    }
    if ((file.action || 'replace') !== 'delete') {
      let stagedFile;
      try {
        stagedFile = resolveSafeConfinedPath(plan.staging_dir, file.path, { requireExists: true });
      } catch (err) {
        return {
          ok: false,
          phase: 'preflight',
          error: `Staged artifact missing: ${file.path} (${err.message})`,
          plan,
        };
      }
      if (!hashMatches(stagedFile, file.sha256)) {
        return {
          ok: false,
          phase: 'preflight',
          error: `Staged artifact hash mismatch: ${file.path}`,
          plan,
        };
      }
    }
  }

  // 2. Verify deleted files list
  for (const relativePath of deleted) {
    if (!isAllowed(relativePath)) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Unsafe deleted path in manifest: ${relativePath}`,
        plan,
      };
    }
  }

  // 3. Verify baseline files against installed target directory
  for (const [relativePath, expectedSha] of Object.entries(baseline)) {
    if (!isAllowed(relativePath)) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Unsafe baseline path rejected: ${relativePath}`,
        plan,
      };
    }
    let targetPath;
    try {
      targetPath = resolveSafeTargetPath(plan.root_dir, relativePath);
    } catch (err) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Baseline target path validation failed: ${err.message}`,
        plan,
      };
    }
    if (!fs.existsSync(targetPath)) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Installed file missing from baseline: ${relativePath}`,
        plan,
      };
    }
    if (!hashMatches(targetPath, expectedSha)) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Baseline file hash mismatch: ${relativePath}`,
        plan,
      };
    }
  }

  // 4. Validate snapshot metadata structure and cross-check backup coverage
  const metadataPath = path.join(plan.snapshot_path, 'metadata.json');
  try {
    resolveSafeConfinedPath(plan.snapshot_path, 'metadata.json', { requireExists: true });
  } catch (err) {
    return {
      ok: false,
      phase: 'preflight',
      error: `Snapshot metadata.json validation failed: ${err.message}`,
      plan,
    };
  }

  let metadata;
  try {
    metadata = JSON.parse(fs.readFileSync(metadataPath, 'utf8'));
  } catch (err) {
    return {
      ok: false,
      phase: 'preflight',
      error: `Invalid backup snapshot metadata JSON: ${err.message}`,
      plan,
    };
  }

  if (!metadata || typeof metadata !== 'object' || Array.isArray(metadata)) {
    return {
      ok: false,
      phase: 'preflight',
      error: 'Snapshot metadata must be a non-null object.',
      plan,
    };
  }

  if (!Array.isArray(metadata.files)) {
    return {
      ok: false,
      phase: 'preflight',
      error: 'Snapshot metadata.files must be an array.',
      plan,
    };
  }

  const metaDeleted = metadata.deleted_files || [];
  if (!Array.isArray(metaDeleted)) {
    return {
      ok: false,
      phase: 'preflight',
      error: 'Snapshot metadata.deleted_files must be an array.',
      plan,
    };
  }

  const metaNew = metadata.new_files || [];
  if (!Array.isArray(metaNew)) {
    return {
      ok: false,
      phase: 'preflight',
      error: 'Snapshot metadata.new_files must be an array.',
      plan,
    };
  }

  // Check duplicate entries in metadata
  const metaFilePaths = metadata.files.map((e) => (typeof e === 'string' ? e : e?.path));
  const metaDeletedPaths = metaDeleted.map((e) => (typeof e === 'string' ? e : e?.path));
  const metaNewPaths = metaNew.map((e) => (typeof e === 'string' ? e : e?.path));

  const hasDups = (arr) => new Set(arr).size !== arr.length;
  if (hasDups(metaFilePaths) || hasDups(metaDeletedPaths) || hasDups(metaNewPaths)) {
    return {
      ok: false,
      phase: 'preflight',
      error: 'Snapshot metadata contains duplicate file entries.',
      plan,
    };
  }

  // Check conflicts between metadata lists
  for (const p of metaNewPaths) {
    if (metaFilePaths.includes(p) || metaDeletedPaths.includes(p)) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Snapshot metadata conflict: '${p}' declared in new_files cannot also appear in files or deleted_files.`,
        plan,
      };
    }
  }
  for (const p of metaDeletedPaths) {
    if (metaFilePaths.includes(p)) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Snapshot metadata conflict: '${p}' declared in deleted_files cannot also appear in files.`,
        plan,
      };
    }
  }

  // Validate metaNew classification against actual target absence
  for (const newRel of metaNewPaths) {
    if (baseline[newRel]) {
      return {
        ok: false,
        phase: 'preflight',
        error: `File '${newRel}' declared as new_files in metadata but exists in signed baseline.`,
        plan,
      };
    }
    let targetFile;
    try {
      targetFile = resolveSafeTargetPath(plan.root_dir, newRel);
    } catch (err) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Target path validation failed for new_files entry '${newRel}': ${err.message}`,
        plan,
      };
    }
    if (fs.existsSync(targetFile)) {
      return {
        ok: false,
        phase: 'preflight',
        error: `File '${newRel}' declared in new_files but already exists on disk in target. Existing untracked file cannot be classified as new.`,
        plan,
      };
    }
  }

  // Cross-check every mutated manifest file is covered in metadata
  for (const fileEntry of files) {
    const p = fileEntry.path;
    if (metaNewPaths.includes(p)) {
      if (baseline[p]) {
        return {
          ok: false,
          phase: 'preflight',
          error: `File '${p}' declared as new_files in metadata but exists in signed baseline.`,
          plan,
        };
      }
      let targetFile;
      try {
        targetFile = resolveSafeTargetPath(plan.root_dir, p);
      } catch (err) {
        return {
          ok: false,
          phase: 'preflight',
          error: `Target path validation failed for new file '${p}': ${err.message}`,
          plan,
        };
      }
      if (fs.existsSync(targetFile)) {
        return {
          ok: false,
          phase: 'preflight',
          error: `File '${p}' declared in new_files but already exists on disk in target. Existing untracked file cannot be classified as new.`,
          plan,
        };
      }
    } else {
      const metaEntry = metadata.files.find((e) => (typeof e === 'string' ? e : e?.path) === p);
      if (!metaEntry) {
        return {
          ok: false,
          phase: 'preflight',
          error: `Mutated file '${p}' is not covered by snapshot metadata (neither files nor new_files).`,
          plan,
        };
      }
      const expectedSha = typeof metaEntry === 'object' && metaEntry.sha256 ? metaEntry.sha256 : baseline[p];
      if (!expectedSha) {
        return {
          ok: false,
          phase: 'preflight',
          error: `Backup metadata entry for '${p}' is missing required sha256.`,
          plan,
        };
      }
      if (baseline[p] && expectedSha !== baseline[p]) {
        return {
          ok: false,
          phase: 'preflight',
          error: `Backup metadata sha256 for '${p}' does not match signed baseline.`,
          plan,
        };
      }
      let backupFile;
      try {
        backupFile = resolveSafeConfinedPath(plan.snapshot_path, path.join('files', p), { requireExists: true });
      } catch (err) {
        return {
          ok: false,
          phase: 'preflight',
          error: `Backup snapshot artifact missing for '${p}': ${err.message}`,
          plan,
        };
      }
      if (!hashMatches(backupFile, expectedSha)) {
        return {
          ok: false,
          phase: 'preflight',
          error: `Backup snapshot artifact integrity check failed for '${p}'.`,
          plan,
        };
      }
    }
  }

  // Cross-check every deleted file is covered in metadata and backup
  for (const delPath of deleted) {
    if (!metaDeletedPaths.includes(delPath)) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Deleted file '${delPath}' is missing from snapshot metadata deleted_files.`,
        plan,
      };
    }
    const metaEntry = metaDeleted.find((e) => (typeof e === 'string' ? e : e?.path) === delPath);
    const expectedSha = typeof metaEntry === 'object' && metaEntry.sha256 ? metaEntry.sha256 : baseline[delPath];
    if (!expectedSha) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Backup metadata entry for deleted file '${delPath}' missing required sha256.`,
        plan,
      };
    }
    if (baseline[delPath] && expectedSha !== baseline[delPath]) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Backup metadata sha256 for deleted file '${delPath}' does not match signed baseline.`,
        plan,
      };
    }
    let backupFile;
    try {
      backupFile = resolveSafeConfinedPath(plan.snapshot_path, path.join('files', delPath), { requireExists: true });
    } catch (err) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Backup artifact missing for deleted file '${delPath}': ${err.message}`,
        plan,
      };
    }
    if (!hashMatches(backupFile, expectedSha)) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Backup artifact integrity check failed for deleted file '${delPath}'.`,
        plan,
      };
    }
  }

  // Validate version_json_backup
  if (typeof metadata.version_json_backup !== 'string' || !metadata.version_json_backup) {
    return {
      ok: false,
      phase: 'preflight',
      error: 'Backup snapshot metadata missing valid version_json_backup.',
      plan,
    };
  }
  const versionBackupSha = crypto.createHash('sha256').update(metadata.version_json_backup).digest('hex');
  if (baseline['version.json'] && versionBackupSha !== baseline['version.json']) {
    return {
      ok: false,
      phase: 'preflight',
      error: 'Backup version_json_backup hash does not match signed baseline version.json.',
      plan,
    };
  }

  // 5. Validate declared database migration recovery safety
  const hasMigrations = Array.isArray(plan.manifest.migrations) && plan.manifest.migrations.length > 0;
  if (hasMigrations || plan.db_recovery) {
    const dbRec = plan.db_recovery;
    if (!dbRec || typeof dbRec !== 'object') {
      return {
        ok: false,
        phase: 'preflight',
        error: 'Manifest declares database migrations, but db_recovery object is missing from plan.',
        plan,
      };
    }
    if (!dbRec.backup_path || !dbRec.recovery_id) {
      return {
        ok: false,
        phase: 'preflight',
        error: 'Malformed db_recovery: missing backup_path or recovery_id.',
        plan,
      };
    }
    try {
      resolveSafeConfinedPath(resolvedStorage, path.relative(resolvedStorage, dbRec.backup_path), { requireExists: true });
    } catch (err) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Database recovery backup file validation failed: ${err.message}`,
        plan,
      };
    }
    if (dbRec.sha256 && !hashMatches(dbRec.backup_path, dbRec.sha256)) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Database recovery backup file hash mismatch: ${dbRec.backup_path}`,
        plan,
      };
    }
  }

  // 6. Parent directory permission probes
  const targetDirs = new Set();
  const allCandidatePaths = [
    ...files.map((f) => f.path),
    ...deleted,
    'version.json',
  ];
  for (const rel of allCandidatePaths) {
    let resolved;
    try {
      resolved = resolveSafeTargetPath(plan.root_dir, rel);
    } catch (err) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Target path validation failed for '${rel}': ${err.message}`,
        plan,
      };
    }
    // Probe nearest EXISTING confined ancestor; DO NOT mkdir real new deployment dirs during preflight!
    let existingAncestor = path.dirname(resolved);
    const resolvedRoot = path.resolve(plan.root_dir);
    while (existingAncestor && existingAncestor.length >= resolvedRoot.length) {
      if (fs.existsSync(existingAncestor)) {
        break;
      }
      const parent = path.dirname(existingAncestor);
      if (parent === existingAncestor) {
        existingAncestor = resolvedRoot;
        break;
      }
      existingAncestor = parent;
    }
    if (!existingAncestor || existingAncestor.length < resolvedRoot.length || !fs.existsSync(existingAncestor)) {
      existingAncestor = resolvedRoot;
    }
    targetDirs.add(existingAncestor);
  }

  for (const checkDir of targetDirs) {
    const probeSuffix = `${process.pid}-${Date.now()}-${crypto.randomBytes(4).toString('hex')}`;
    const probeFile = path.join(checkDir, `.probe-delta-${probeSuffix}.tmp`);
    const probeRenamed = path.join(checkDir, `.probe-renamed-${probeSuffix}.tmp`);
    const probeCleanupErrors = [];
    let primaryProbeError = null;
    let isPermissionError = false;

    try {
      fs.writeFileSync(probeFile, 'probe');
      fs.renameSync(probeFile, probeRenamed);
      fs.unlinkSync(probeRenamed);
    } catch (err) {
      primaryProbeError = err;
      if (err.code === 'EACCES' || err.code === 'EPERM') {
        isPermissionError = true;
      }
    } finally {
      try {
        if (fs.existsSync(probeFile)) {
          clearReadOnlyIfSet(probeFile);
          fs.unlinkSync(probeFile);
        }
      } catch (cleanErr) {
        probeCleanupErrors.push(`Failed to remove probe file ${probeFile}: ${cleanErr.message}`);
      }
      try {
        if (fs.existsSync(probeRenamed)) {
          clearReadOnlyIfSet(probeRenamed);
          fs.unlinkSync(probeRenamed);
        }
      } catch (cleanErr) {
        probeCleanupErrors.push(`Failed to remove probe file ${probeRenamed}: ${cleanErr.message}`);
      }
    }

    if (primaryProbeError) {
      if (isPermissionError) {
        const guidanceAr =
          'يتطلب تثبيت التحديث صلاحيات المسؤول للكتابة في مجلد البرنامج. يرجى تثبيت التحديث باستخدام برنامج التثبيت الكامل (Setup) أو التواصل مع الدعم الفني.';
        const technicalMsg =
          'Installing this update requires administrator permissions to write to the application folder. Please run the full POS Desktop Setup installer to upgrade, or contact technical support.';
        return {
          ok: false,
          phase: 'preflight',
          reason_code: 'install_permission_required',
          error: guidanceAr,
          message_ar: guidanceAr,
          technical_details: technicalMsg,
          probe_cleanup_errors: probeCleanupErrors.length > 0 ? probeCleanupErrors : null,
          plan,
        };
      }
      return {
        ok: false,
        phase: 'preflight',
        error: `Target directory probe failed in ${checkDir}: ${primaryProbeError.message}`,
        probe_cleanup_errors: probeCleanupErrors.length > 0 ? probeCleanupErrors : null,
        plan,
      };
    }

    if (probeCleanupErrors.length > 0) {
      return {
        ok: false,
        phase: 'preflight',
        error: `Target directory probe succeeded but probe files could not be cleaned up: ${probeCleanupErrors.join('; ')}`,
        probe_cleanup_errors: probeCleanupErrors,
        plan,
      };
    }
  }

  try {
    rollbackExpectations.set(plan, captureRollbackExpectation(plan, metadata, true));
  } catch (err) {
    return { ok: false, phase: 'preflight', error: err.message, plan };
  }
  return { ok: true, phase: 'preflight', plan };
}

function getUpdateStateFilePath(storageDir) {
  return path.join(storageDir, 'update-state.json');
}

function writeUpdateState(storageDir, state, context = {}) {
  const filePath = getUpdateStateFilePath(storageDir);
  let current = {};
  if (fs.existsSync(filePath)) {
    try {
      current = JSON.parse(fs.readFileSync(filePath, 'utf8'));
    } catch {}
  }
  const payload = {
    ...current,
    ...context,
    state,
    started_at: current.started_at || new Date().toISOString(),
    updated_at: new Date().toISOString(),
  };
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
  return payload;
}

function safeWriteUpdateState(storageDir, state, context = {}) {
  try {
    const payload = writeUpdateState(storageDir, state, context);
    return { ok: true, payload };
  } catch (err) {
    return { ok: false, error: err };
  }
}

function setHandoffState(plan, state, context = {}) {
  if (!plan?.storage_dir) return null;
  return safeWriteUpdateState(plan.storage_dir, state, {
    to_version: plan.version,
    backup_snapshot: plan.snapshot_path,
    db_recovery: plan.db_recovery || null,
    ...context,
  });
}

async function rollback(plan, options = {}) {
  if (!plan?.snapshot_path || !plan?.root_dir) {
    return {
      ok: false,
      restored_count: 0,
      errors: [{ error: 'Invalid plan provided to rollback' }],
      verified: false,
      retained_backup: plan?.snapshot_path || null,
      retained_staging: plan?.staging_dir || null,
    };
  }

  let metadataPath;
  try {
    metadataPath = resolveSafeConfinedPath(plan.snapshot_path, 'metadata.json', { requireExists: true });
  } catch (err) {
    return {
      ok: false,
      restored_count: 0,
      errors: [{ error: `Snapshot metadata resolution failed: ${err.message}` }],
      verified: false,
      retained_backup: plan.snapshot_path,
      retained_staging: plan.staging_dir,
    };
  }

  let metadata;
  try {
    metadata = JSON.parse(fs.readFileSync(metadataPath, 'utf8'));
  } catch (err) {
    return {
      ok: false,
      restored_count: 0,
      errors: [{ error: `Invalid snapshot metadata JSON: ${err.message}` }],
      verified: false,
      retained_backup: plan.snapshot_path,
      retained_staging: plan.staging_dir,
    };
  }

  if (!metadata || typeof metadata !== 'object' || Array.isArray(metadata)) {
    return {
      ok: false,
      restored_count: 0,
      errors: [{ error: 'Snapshot metadata must be a non-null object' }],
      verified: false,
      retained_backup: plan.snapshot_path,
      retained_staging: plan.staging_dir,
    };
  }

  if (metadata.files !== undefined && !Array.isArray(metadata.files)) {
    return {
      ok: false,
      restored_count: 0,
      errors: [{ error: 'Snapshot metadata.files must be an array' }],
      verified: false,
      retained_backup: plan.snapshot_path,
      retained_staging: plan.staging_dir,
    };
  }

  if (metadata.deleted_files !== undefined && !Array.isArray(metadata.deleted_files)) {
    return {
      ok: false,
      restored_count: 0,
      errors: [{ error: 'Snapshot metadata.deleted_files must be an array' }],
      verified: false,
      retained_backup: plan.snapshot_path,
      retained_staging: plan.staging_dir,
    };
  }

  if (metadata.new_files !== undefined && !Array.isArray(metadata.new_files)) {
    return {
      ok: false,
      restored_count: 0,
      errors: [{ error: 'Snapshot metadata.new_files must be an array' }],
      verified: false,
      retained_backup: plan.snapshot_path,
      retained_staging: plan.staging_dir,
    };
  }

  let expectation;
  try {
    expectation = rollbackExpectations.get(plan) || captureRollbackExpectation(plan, metadata);
    if (JSON.stringify(metadata) !== expectation.metadata) {
      throw new Error('Rollback snapshot metadata changed after preflight');
    }
  } catch (err) {
    return {
      ok: false,
      restored_count: 0,
      errors: [{ error: err.message }],
      verified: false,
      retained_backup: plan.snapshot_path,
      retained_staging: plan.staging_dir,
    };
  }

  const errors = [];
  let restoredCount = 0;
  const originalAttributes = options.originalAttributes || {};

  // 1. Restore regular backed-up files, EXCLUDING version.json
  for (const entry of metadata.files || []) {
    if (!entry || (typeof entry !== 'string' && typeof entry !== 'object')) {
      errors.push({ error: 'Malformed entry in snapshot metadata.files: must be string or object' });
      continue;
    }
    const relativePath = typeof entry === 'string' ? entry : entry.path;
    if (typeof relativePath !== 'string' || !relativePath.trim()) {
      errors.push({ error: 'Malformed entry in snapshot metadata.files: path must be a non-empty string' });
      continue;
    }
    if (relativePath === 'version.json') {
      // Excluded from ordinary loop, restored strictly last
      continue;
    }

    const expectedSha =
      (typeof entry === 'object' && typeof entry.sha256 === 'string' && entry.sha256)
        ? entry.sha256
        : (plan.manifest?.baseline_files?.[relativePath] || null);

    if (!expectedSha) {
      errors.push({ path: relativePath, error: `Missing required expected sha256 for rollback file '${relativePath}'` });
      continue;
    }

    if (!isAllowed(relativePath)) {
      errors.push({ path: relativePath, error: `Unsafe rollback path rejected: ${relativePath}` });
      continue;
    }

    let target;
    try {
      target = resolveSafeTargetPath(plan.root_dir, relativePath);
    } catch (err) {
      errors.push({ path: relativePath, error: `Target path resolution failed: ${err.message}` });
      continue;
    }

    let backup;
    try {
      backup = resolveSafeConfinedPath(plan.snapshot_path, path.join('files', relativePath), { requireExists: true });
    } catch (err) {
      errors.push({ path: relativePath, error: `Backup artifact resolution failed: ${err.message}` });
      continue;
    }

    // Verify source hash BEFORE copying!
    if (!hashMatches(backup, expectedSha)) {
      errors.push({ path: relativePath, error: `Backup source hash mismatch before copy for '${relativePath}'` });
      continue;
    }

    try {
      const preserveTargetReadOnly = Boolean(originalAttributes[relativePath]);
      const repRes = await replaceFile(backup, target, { preserveTargetReadOnly });
      if (repRes.secondaryErrors) {
        for (const se of repRes.secondaryErrors) {
          errors.push({ path: relativePath, error: se, secondary: true });
        }
      }
      if (!hashMatches(target, expectedSha)) {
        errors.push({ path: relativePath, error: `Restored target file hash mismatch against metadata: ${relativePath}` });
      } else {
        restoredCount += 1;
      }
    } catch (err) {
      errors.push({ path: relativePath, error: err.message });
      if (err.secondaryErrors) {
        for (const se of err.secondaryErrors) {
          errors.push({ path: relativePath, error: se, secondary: true });
        }
      }
    }
  }

  // 2. Restore deleted files
  for (const entry of metadata.deleted_files || []) {
    if (!entry || (typeof entry !== 'string' && typeof entry !== 'object')) {
      errors.push({ error: 'Malformed entry in snapshot metadata.deleted_files: must be string or object' });
      continue;
    }
    const relativePath = typeof entry === 'string' ? entry : entry.path;
    if (typeof relativePath !== 'string' || !relativePath.trim()) {
      errors.push({ error: 'Malformed entry in snapshot metadata.deleted_files: path must be a non-empty string' });
      continue;
    }

    const expectedSha =
      (typeof entry === 'object' && typeof entry.sha256 === 'string' && entry.sha256)
        ? entry.sha256
        : (plan.manifest?.baseline_files?.[relativePath] || null);

    if (!expectedSha) {
      errors.push({ path: relativePath, error: `Missing required expected sha256 for deleted file restoration '${relativePath}'` });
      continue;
    }

    if (!isAllowed(relativePath)) {
      errors.push({ path: relativePath, error: `Unsafe rollback path rejected for deleted file: ${relativePath}` });
      continue;
    }

    let target;
    try {
      target = resolveSafeTargetPath(plan.root_dir, relativePath);
    } catch (err) {
      errors.push({ path: relativePath, error: `Target path resolution failed: ${err.message}` });
      continue;
    }

    let backup;
    try {
      backup = resolveSafeConfinedPath(plan.snapshot_path, path.join('files', relativePath), { requireExists: true });
    } catch (err) {
      errors.push({ path: relativePath, error: `Backup artifact resolution failed for deleted file: ${err.message}` });
      continue;
    }

    if (!hashMatches(backup, expectedSha)) {
      errors.push({ path: relativePath, error: `Backup source hash mismatch before restore for deleted file '${relativePath}'` });
      continue;
    }

    try {
      const preserveTargetReadOnly = Boolean(originalAttributes[relativePath]);
      const repRes = await replaceFile(backup, target, { preserveTargetReadOnly });
      if (repRes.secondaryErrors) {
        for (const se of repRes.secondaryErrors) {
          errors.push({ path: relativePath, error: se, secondary: true });
        }
      }
      if (!hashMatches(target, expectedSha)) {
        errors.push({ path: relativePath, error: `Restored deleted file hash mismatch: ${relativePath}` });
      } else {
        restoredCount += 1;
      }
    } catch (err) {
      errors.push({ path: relativePath, error: err.message });
      if (err.secondaryErrors) {
        for (const se of err.secondaryErrors) {
          errors.push({ path: relativePath, error: se, secondary: true });
        }
      }
    }
  }

  // 3. Remove newly added files
  for (const entry of metadata.new_files || []) {
    const relativePath = typeof entry === 'string' ? entry : entry?.path;
    if (typeof relativePath !== 'string' || !relativePath.trim()) {
      errors.push({ error: 'Malformed entry in snapshot metadata.new_files' });
      continue;
    }
    if (!isAllowed(relativePath)) {
      errors.push({ path: relativePath, error: `Unsafe new_file path rejected: ${relativePath}` });
      continue;
    }
    let target;
    try {
      target = resolveSafeTargetPath(plan.root_dir, relativePath);
    } catch (err) {
      errors.push({ path: relativePath, error: `Target path resolution failed: ${err.message}` });
      continue;
    }
    if (fs.existsSync(target)) {
      try {
        clearReadOnlyIfSet(target);
        fs.unlinkSync(target);
        if (fs.existsSync(target)) {
          errors.push({ path: relativePath, error: `Failed to remove new file during rollback: ${relativePath}` });
        } else {
          restoredCount += 1;
        }
      } catch (err) {
        errors.push({ path: relativePath, error: err.message });
      }
    }
  }

  // Verify the original complete baseline, not just entries left in metadata.
  errors.push(...verifyRollbackBaseline(plan, expectation, false));

  // Never restore version.json while file restoration has errors!
  if (errors.length === 0) {
    if (typeof metadata.version_json_backup === 'string') {
      let versionTarget;
      try {
        versionTarget = resolveSafeTargetPath(plan.root_dir, 'version.json');
        const tempVersion = generateTempPath(versionTarget);
        try {
          fs.writeFileSync(tempVersion, metadata.version_json_backup, 'utf8');
          clearReadOnlyIfSet(tempVersion);
          const preserveVersionReadOnly = Boolean(originalAttributes['version.json']);
          const repRes = await replaceFile(tempVersion, versionTarget, {
            preserveTargetReadOnly: preserveVersionReadOnly,
          });
          if (repRes.secondaryErrors) {
            for (const se of repRes.secondaryErrors) {
              errors.push({ path: 'version.json', error: se, secondary: true });
            }
          }
          const restoredContent = fs.readFileSync(versionTarget, 'utf8');
          if (restoredContent !== metadata.version_json_backup) {
            errors.push({ path: 'version.json', error: 'Restored version.json does not match backup content' });
          } else {
            restoredCount += 1;
          }
        } finally {
          try {
            if (fs.existsSync(tempVersion)) {
              clearReadOnlyIfSet(tempVersion);
              fs.unlinkSync(tempVersion);
            }
          } catch (cleanErr) {
            errors.push({ path: 'version.json', error: `Failed to clean temp version file: ${cleanErr.message}` });
          }
        }
      } catch (err) {
        errors.push({ path: 'version.json', error: err.message });
        if (err.secondaryErrors) {
          for (const se of err.secondaryErrors) {
            errors.push({ path: 'version.json', error: se, secondary: true });
          }
        }
      }
    } else {
      errors.push({ path: 'version.json', error: 'version_json_backup missing in snapshot metadata' });
    }
  }

  // Recheck every artifact after the last awaited replacement, including version.
  errors.push(...verifyRollbackBaseline(plan, expectation));
  const ok = errors.length === 0;
  return {
    ok,
    restored_count: restoredCount,
    errors: errors.length > 0 ? errors : null,
    verified: ok,
    retained_backup: plan.snapshot_path,
    retained_staging: plan.staging_dir,
  };
}

async function applyPendingDelta(storageDir, version, expectedRoot) {
  const preflight = preflightPendingDelta(storageDir, version, expectedRoot);
  if (!preflight.ok) {
    return {
      ...preflight,
      mutations_started: false,
    };
  }
  const { plan } = preflight;
  const files = plan.manifest?.files || [];
  const deleted = plan.manifest?.deleted_files || [];

  // Partition files so version.json is applied strictly LAST
  const regularFiles = [];
  let versionFileEntry = null;
  for (const file of files) {
    if ((file.action || 'replace') === 'delete') continue;
    if (file.path === 'version.json') {
      versionFileEntry = file;
    } else {
      regularFiles.push(file);
    }
  }

  const originalAttributes = {};
  for (const file of files) {
    try {
      const target = resolveSafeTargetPath(plan.root_dir, file.path);
      if (fs.existsSync(target)) {
        originalAttributes[file.path] = isReadOnly(target);
      }
    } catch {}
  }
  for (const relPath of deleted) {
    try {
      const target = resolveSafeTargetPath(plan.root_dir, relPath);
      if (fs.existsSync(target)) {
        originalAttributes[relPath] = isReadOnly(target);
      }
    } catch {}
  }
  try {
    const target = resolveSafeTargetPath(plan.root_dir, 'version.json');
    if (fs.existsSync(target)) {
      originalAttributes['version.json'] = isReadOnly(target);
    }
  } catch {}

  let mutationsStarted = false;
  const applySecondaryErrors = [];

  try {
    // 1. Apply regular files
    for (const file of regularFiles) {
      const staged = path.join(plan.staging_dir, file.path);
      const target = resolveSafeTargetPath(plan.root_dir, file.path);
      if (fs.existsSync(target) && hashMatches(target, file.sha256)) continue;

      mutationsStarted = true;
      const repRes = await replaceFile(staged, target);
      if (repRes?.secondaryErrors) {
        applySecondaryErrors.push(...repRes.secondaryErrors);
      }
      if (!hashMatches(target, file.sha256)) {
        throw new Error(`Target file corrupted after replacement: ${file.path}`);
      }
    }

    // 2. Delete obsolete files
    for (const relativePath of deleted) {
      const target = resolveSafeTargetPath(plan.root_dir, relativePath);
      if (fs.existsSync(target)) {
        mutationsStarted = true;
        clearReadOnlyIfSet(target);
        fs.unlinkSync(target);
      }
    }

    // 3. Apply version.json strictly LAST
    if (versionFileEntry) {
      const staged = path.join(plan.staging_dir, versionFileEntry.path);
      const target = resolveSafeTargetPath(plan.root_dir, versionFileEntry.path);
      mutationsStarted = true;
      const repRes = await replaceFile(staged, target);
      if (repRes?.secondaryErrors) {
        applySecondaryErrors.push(...repRes.secondaryErrors);
      }
      if (!hashMatches(target, versionFileEntry.sha256)) {
        throw new Error('version.json corrupted after replacement');
      }
    } else {
      throw new Error('Delta manifest missing signed version.json entry');
    }

    return {
      ok: true,
      phase: 'apply',
      plan,
      mutations_started: mutationsStarted,
      original_attributes: originalAttributes,
      secondary_errors: applySecondaryErrors.length > 0 ? applySecondaryErrors : null,
    };
  } catch (error) {
    let rollbackResult = null;
    if (mutationsStarted) {
      try {
        rollbackResult = await rollback(plan, { originalAttributes });
      } catch (rbErr) {
        rollbackResult = { ok: false, error: rbErr.message, errors: [{ error: rbErr.message }] };
      }
    }

    const combinedSecondary = [];
    if (applySecondaryErrors.length > 0) {
      combinedSecondary.push(...applySecondaryErrors);
    }
    if (error.secondaryErrors && Array.isArray(error.secondaryErrors)) {
      combinedSecondary.push(...error.secondaryErrors);
    }

    return {
      ok: false,
      phase: 'apply',
      error: error.message,
      primary_error: error.message,
      mutations_started: mutationsStarted,
      original_attributes: originalAttributes,
      secondary_errors: combinedSecondary.length > 0 ? combinedSecondary : null,
      rollback: rollbackResult,
      rolled_back: rollbackResult?.ok === true,
      reason_code: rollbackResult?.ok ? undefined : 'recovery_required',
      plan,
    };
  }
}

async function orchestrateDeltaInstall({
  storageDir,
  version,
  expectedRoot,
  lease,
  hooks = {},
}) {
  const {
    stopJobWorker = async () => {},
    stopPHP = async () => {},
    runDatabaseMigrations = async () => {},
    restoreDatabaseSafety = async () => {},
    restartPhpAndWorker = async () => {},
    onSuccess = async () => {},
  } = hooks;

  // Step 1: Preflight check before touching runtime or mutating any files
  const preflight = preflightPendingDelta(storageDir, version, expectedRoot);
  if (!preflight.ok) {
    safeWriteUpdateState(storageDir, 'failed', {
      operation: 'electron_delta_install',
      owner_id: lease?.owner_id,
      target_version: version,
      to_version: version,
      backup_snapshot: preflight.plan?.snapshot_path || null,
      db_recovery: preflight.plan?.db_recovery || null,
      phase: 'preflight',
      error: preflight.error,
      reason_code: preflight.reason_code,
    });
    return {
      ok: false,
      phase: 'preflight',
      error: preflight.error,
      primary_error: preflight.error,
      reason_code: preflight.reason_code,
      message_ar: preflight.message_ar,
      plan: preflight.plan,
    };
  }

  const { plan } = preflight;

  // Step 2: Initial state write before stopping runtime
  const initialWrite = safeWriteUpdateState(storageDir, 'applying', {
    operation: 'electron_delta_install',
    owner_id: lease?.owner_id,
    target_version: version,
    to_version: version,
    backup_snapshot: plan.snapshot_path,
    db_recovery: plan.db_recovery || null,
    phase: 'stopping_runtime',
  });
  if (!initialWrite.ok) {
    return {
      ok: false,
      phase: 'state_write',
      error: `Failed to initialize update state before mutations: ${initialWrite.error.message}`,
      primary_error: initialWrite.error.message,
      plan,
    };
  }

  // Tracking transaction state for single recovery handler
  let currentPhase = 'stopping_runtime';
  let filesMutated = false;
  let migrationsAttempted = false;
  let runtimeRestartAttempted = false;
  let runtimeStopped = false;
  let applyResult = null;
  let appliedAttributes = {};

  try {
    let workerStopped = false;
    let phpStopped = false;

    // Step 3a: Stop Job Worker
    currentPhase = 'stop_worker';
    try {
      await stopJobWorker();
      workerStopped = true;
    } catch (workerErr) {
      const err = new Error(`Failed to stop background job worker before file replacement: ${workerErr.message}`);
      err.originalError = workerErr;
      throw err;
    }

    // Step 3b: Stop PHP Server (bounded awaitable exit)
    currentPhase = 'stop_php';
    try {
      await stopPHP();
      phpStopped = true;
    } catch (phpErr) {
      const err = new Error(`Failed to stop PHP server before file replacement: ${phpErr.message}`);
      err.originalError = phpErr;
      throw err;
    }

    runtimeStopped = workerStopped && phpStopped;

    // Step 4: Apply Files
    currentPhase = 'apply';
    applyResult = await applyPendingDelta(storageDir, version, expectedRoot);
    if (applyResult?.original_attributes) {
      appliedAttributes = applyResult.original_attributes;
    }
    if (!applyResult.ok) {
      if (applyResult.mutations_started) {
        filesMutated = true;
      }
      const err = new Error(applyResult.error || 'Delta file application failed');
      err.applyResult = applyResult;
      throw err;
    }
    filesMutated = true;

    // Step 5: Database Migrations (if declared)
    if (Array.isArray(plan.manifest?.migrations) && plan.manifest.migrations.length > 0) {
      currentPhase = 'migration';
      const migrateState = safeWriteUpdateState(storageDir, 'migrating', {
        operation: 'electron_delta_install',
        owner_id: lease?.owner_id,
        target_version: version,
        to_version: version,
        backup_snapshot: plan.snapshot_path,
        db_recovery: plan.db_recovery || null,
        migration_started: true,
      });
      if (!migrateState.ok) {
        const err = new Error(`Failed to record migrating state: ${migrateState.error.message}`);
        err.stateError = migrateState.error;
        throw err;
      }

      migrationsAttempted = true;
      await runDatabaseMigrations(plan.manifest.migrations);
    }

    // Verify unchanged baseline artifacts as well as all installed replacements/deletions.
    // Migration hooks must not leave HTTP starting over a mixed installation either.
    currentPhase = 'restart';
    const installedFiles = { ...rollbackExpectations.get(plan).files };
    for (const file of plan.manifest.files) {
      installedFiles[file.path] = (file.action || 'replace') === 'delete' ? null : file.sha256;
    }
    for (const relativePath of plan.manifest.deleted_files || []) installedFiles[relativePath] = null;
    const installedErrors = verifyRollbackBaseline(plan, { files: installedFiles });
    if (installedErrors.length) {
      throw new Error(`Installed artifact verification failed before restart: ${installedErrors.map((entry) => entry.error).join('; ')}`);
    }

    // Step 6: Restart Runtime post-install
    runtimeRestartAttempted = true;
    await restartPhpAndWorker({ startupMigrations: [] });

    // Step 7: Completed State Write
    currentPhase = 'completed';
    const completedState = safeWriteUpdateState(storageDir, 'completed', {
      operation: 'electron_delta_install',
      owner_id: lease?.owner_id,
      target_version: version,
      to_version: version,
      backup_snapshot: plan.snapshot_path,
      db_recovery: plan.db_recovery || null,
      phase: 'completed',
      migration_completed: migrationsAttempted,
    });
    if (!completedState.ok) {
      const err = new Error(`Failed to persist completed update state: ${completedState.error.message}`);
      err.stateError = completedState.error;
      throw err;
    }

    try {
      await onSuccess();
    } catch (onSuccessErr) {
      console.warn('[Delta] Warning in onSuccess hook:', onSuccessErr.message);
    }

    return { ok: true, version, plan };
  } catch (primaryErr) {
    // CENTRAL RECOVERY HANDLER
    const primaryErrorMessage = primaryErr.message || String(primaryErr);
    const secondaryErrors = [];
    if (primaryErr.stateError) {
      secondaryErrors.push(`State persistence error: ${primaryErr.stateError.message}`);
    }
    if (primaryErr.applyResult?.secondary_errors) {
      secondaryErrors.push(...primaryErr.applyResult.secondary_errors);
    } else if (applyResult?.secondary_errors) {
      secondaryErrors.push(...applyResult.secondary_errors);
    }

    const recordRecoveryState = (st, ctx) => {
      const writeRes = safeWriteUpdateState(storageDir, st, {
        operation: 'electron_delta_install',
        owner_id: lease?.owner_id,
        target_version: version,
        to_version: version,
        backup_snapshot: plan?.snapshot_path || null,
        db_recovery: plan?.db_recovery || null,
        primary_error: primaryErrorMessage,
        ...ctx,
      });
      if (!writeRes.ok) {
        secondaryErrors.push(`Failed to persist state '${st}': ${writeRes.error.message}`);
      }
    };

    // If zero file mutations occurred:
    if (!filesMutated) {
      let restartOldError = null;
      const baselineErrors = verifyRollbackBaseline(plan, rollbackExpectations.get(plan));
      for (const entry of baselineErrors) secondaryErrors.push(`Baseline verification error: ${entry.error}`);
      if (baselineErrors.length) restartOldError = new Error('Original baseline could not be verified');
      if (runtimeStopped && !restartOldError) {
        try {
          await restartPhpAndWorker({ startupMigrations: [] });
        } catch (rErr) {
          restartOldError = rErr;
          secondaryErrors.push(`Failed to restart old runtime after pre-mutation failure: ${rErr.message}`);
        }
      }

      recordRecoveryState(baselineErrors.length ? 'recovery_required' : 'failed', {
        phase: currentPhase,
        error: primaryErrorMessage,
        restarted_old_runtime: runtimeStopped && !restartOldError,
        secondary_errors: secondaryErrors,
      });

      return {
        ok: false,
        phase: currentPhase,
        error: primaryErrorMessage,
        primary_error: primaryErr.originalError?.message || primaryErrorMessage,
        secondary_errors: secondaryErrors.length > 0 ? secondaryErrors : null,
        mutations_started: false,
        reason_code: baselineErrors.length ? 'recovery_required' : undefined,
        restarted_old_runtime: runtimeStopped && !restartOldError,
        plan,
      };
    }

    // Files WERE mutated (or restart/completed state write failed).
    // If runtime was restarted or attempted, stop live processes before file/DB recovery
    if (runtimeRestartAttempted) {
      const failedStops = [];
      try {
        await stopJobWorker();
      } catch (wsErr) {
        failedStops.push(`worker: ${wsErr.message}`);
        secondaryErrors.push(`Failed to stop job worker during recovery: ${wsErr.message}`);
      }
      try {
        await stopPHP();
      } catch (psErr) {
        failedStops.push(`php: ${psErr.message}`);
        secondaryErrors.push(`Failed to stop PHP server during recovery: ${psErr.message}`);
      }

      if (failedStops.length > 0) {
        recordRecoveryState('recovery_required', {
          phase: currentPhase,
          failed_stops: failedStops,
          recovery_stop_failed: true,
          secondary_errors: secondaryErrors,
        });
        return {
          ok: false,
          phase: 'recovery_stop',
          reason_code: 'recovery_required',
          error: `Failed to stop runtime during recovery (${failedStops.join(', ')}): ${primaryErrorMessage}`,
          primary_error: primaryErrorMessage,
          secondary_errors: secondaryErrors.length > 0 ? secondaryErrors : null,
          db_restored: false,
          rolled_back: false,
          plan,
        };
      }
    }

    // Recover Database Safety FIRST if migrations were attempted
    let dbRestored = false;
    let dbRestoreError = null;
    if (migrationsAttempted) {
      if (plan.db_recovery?.backup_path && plan.db_recovery?.recovery_id) {
        try {
          await restoreDatabaseSafety(plan.db_recovery.backup_path, plan.db_recovery.recovery_id);
          dbRestored = true;
        } catch (dbErr) {
          dbRestoreError = dbErr;
          secondaryErrors.push(`Database recovery error: ${dbErr.message}`);
        }
      } else {
        secondaryErrors.push('Database recovery backup was not available');
      }

      if (!dbRestored) {
        recordRecoveryState('database_recovery_failed', {
          phase: currentPhase,
          db_restored: false,
          db_restore_error: dbRestoreError?.message || 'Database recovery backup unavailable.',
          secondary_errors: secondaryErrors,
        });
        return {
          ok: false,
          phase: 'database_recovery',
          reason_code: 'recovery_required',
          error: `Database recovery could not be completed after error in ${currentPhase}: ${primaryErrorMessage}`,
          primary_error: primaryErrorMessage,
          secondary_errors: secondaryErrors.length > 0 ? secondaryErrors : null,
          db_restored: false,
          rolled_back: false,
          plan,
        };
      }
    }

    // Rollback Files
    let rollbackResult = primaryErr.applyResult?.rollback || null;
    if (!rollbackResult) {
      try {
        rollbackResult = await rollback(plan, { originalAttributes: appliedAttributes });
      } catch (rbErr) {
        rollbackResult = { ok: false, error: rbErr.message, errors: [{ error: rbErr.message }] };
      }
    }

    if (rollbackResult?.ok) {
      const baselineErrors = verifyRollbackBaseline(plan, rollbackExpectations.get(plan));
      if (baselineErrors.length) {
        rollbackResult = { ...rollbackResult, ok: false, verified: false, errors: baselineErrors };
      }
    }

    if (rollbackResult?.errors && Array.isArray(rollbackResult.errors)) {
      for (const rbErr of rollbackResult.errors) {
        secondaryErrors.push(`Rollback error: ${rbErr.error || rbErr.message || rbErr}`);
      }
    }

    if (rollbackResult?.ok) {
      recordRecoveryState('rolled_back', {
        phase: currentPhase,
        db_restored: migrationsAttempted ? true : null,
        secondary_errors: secondaryErrors,
      });

      let restartAfterRollbackError = null;
      try {
        await restartPhpAndWorker({ startupMigrations: [] });
      } catch (rErr) {
        restartAfterRollbackError = rErr;
        secondaryErrors.push(`Runtime restart after rollback failed: ${rErr.message}`);
      }

      return {
        ok: false,
        phase: currentPhase,
        error: primaryErrorMessage,
        primary_error: primaryErrorMessage,
        secondary_errors: secondaryErrors.length > 0 ? secondaryErrors : null,
        db_restored: migrationsAttempted ? true : null,
        rolled_back: true,
        rollback: rollbackResult,
        restart_failed: Boolean(restartAfterRollbackError),
        plan,
      };
    }

    // Rollback failed: recovery required. DO NOT RESTART RUNTIME ON MIXED ARTIFACTS
    recordRecoveryState('recovery_required', {
      phase: currentPhase,
      db_restored: migrationsAttempted ? dbRestored : null,
      rollback_errors: rollbackResult?.errors,
      secondary_errors: secondaryErrors,
    });

    return {
      ok: false,
      phase: currentPhase,
      reason_code: 'recovery_required',
      error: `Failure in ${currentPhase} and rollback encountered errors: ${primaryErrorMessage}`,
      primary_error: primaryErrorMessage,
      secondary_errors: secondaryErrors.length > 0 ? secondaryErrors : null,
      db_restored: migrationsAttempted ? dbRestored : null,
      rolled_back: false,
      rollback: rollbackResult,
      rollback_errors: rollbackResult?.errors || null,
      plan,
    };
  }
}

module.exports = {
  isAllowed,
  resolveSafeTargetPath,
  resolveSafeConfinedPath,
  hashMatches,
  hashOf,
  generateTempPath,
  isReadOnly,
  clearReadOnlyIfSet,
  restoreReadOnly,
  replaceFile,
  preflightPendingDelta,
  writeUpdateState,
  safeWriteUpdateState,
  setHandoffState,
  rollback,
  applyPendingDelta,
  orchestrateDeltaInstall,
};
