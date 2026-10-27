#!/usr/bin/env node
/**
 * scripts/verify-setup12-delta-upgrade.mjs
 *
 * GHA-ONLY smoke test validating future update (to 0.0.14) on packaged Setup 12.
 *
 * Requirements:
 *   1. Restricted strictly to ephemeral GitHub Actions under ABDO-TECK/pos-desktop.
 *   2. Strict pre-existing 8 canonical POS data targets and reparse checks before install.
 *   3. Silently installs Setup 12 into isolated application directory under RUNNER_TEMP.
 *   4. Builds REAL changed backend.phar + frontend/version fixture 14 in FRESH RUNNER_TEMP source copy:
 *      - Uses existing frontend/PHAR build interfaces.
 *      - Unique valid PHP fixture under backend/Services (DeltaFixtureMarker0014.php).
 *      - Authoritative token check: exact PHP Phar entry content check.
 *      - Zero production Setup 12 / source / public key patch.
 *      - Safe source copy leaves no stale builds or generated credentials/keys.
 *      - Root version.json 14 feeds build app version; keep root/frontend package and locks 12.
 *      - RSA-2048 signed with production UPDATE_PRIVATE_KEY via ManifestSignatureService.
 *      - Verified against backend/certs/update_public_key.pem.
 *   5. Windows build invocation:
 *      - Uses supported cmd.exe /d /s /c interface with inherited stdio on Windows.
 *   6. Safe owned cleanup:
 *      - Correct literal path quoting (no backslash doubling in single-quoted PS).
 *      - Separator-bound rooted matching.
 *      - Stop/wait/assert no surviving owned processes without swallowing failures.
 *      - Encloses install and build failures under cleanup.
 *      - Validates ownership receipt file paths cannot escape qzRoot, rejecting traversal/absolute/reparse targets.
 *      - No global process-name kills, no deleting customer data, canonical preexisting 8-target guard stays before setup.
 *      - Final uninstallation only lab-owned app; fails honestly if partial install leaves unsafe cleanup.
 *      - Sensitive hashes stay memory-only; no sensitive paths/secrets leaked in evidence.
 *   7. Real full inventory:
 *      - Accurately classifies add/replace operations against Setup 12 baseline inventory.
 *      - Identifies and lists obsolete built frontend artifacts under deleted_files.
 *      - Builds expected full target inventory (baseline + new/changed - deletions) following deployment-inventory.php schema.
 *      - Proves exact deployment file set + hashes + sizes post-update, preserving runtime digital-certificate.txt.
 *      - Separately snapshots and verifies entire immutable native/portable file set (all shell binaries, DLLs, portable runtimes).
 *      - Asserts unchanged schema (pos_schema.sql), CA (cacert.pem), and mandatory update public key.
 *   8. True bound completion:
 *      - Captures same positive jobId and plan before native apply from staging.
 *      - Captures storage/update-jobs/job_{id}.json before handoff, binds operation_id, and compares post-handoff.
 *      - Binds root_dir == unpackedRoot, storage_dir == canonical storage, staging_dir, from_version 12, target 14, staged paths.
 *      - Binds parsed manifest equal to actual signed manifest bytes.
 *      - Distinguishes renderer reload from full EXE relaunch (never fake restarts or use ui.start() as reconnect).
 *      - After native completion, resumes and re-authenticates transport, then queries requestTransport.readJobStatus(jobId).
 *      - Asserts status completed, result.ok === true, desktop_handoff_completed === true, requires_desktop_handoff === false.
 *      - Reconciles returned jobId and backendSource === 'installed_application'.
 *      - Inspects actual backend job file (job_{id}.json) and native context file (update-state.json) on disk.
 *      - Genuinely measures and reports rendererReloadVerified and desktopHandoffCompletedVerified instead of unsupported claims.
 *   9. Preserves existing SQL business_rows/settings/admin/schema/ledger, IndexedDB offline record, LAN/QZ trust, and sentinels.
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createDesktopLab, delay } from './desktop-transition-lab.mjs';
import { attachTransitionTransport } from './channel-transition-ui-transport.mjs';

function sha256(content) {
  return crypto.createHash('sha256').update(content).digest('hex');
}

function sha256File(filePath) {
  return crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
}

function assertNoReparse(targetPath) {
  let current = path.resolve(targetPath);
  while (current && path.dirname(current) !== current) {
    try {
      const stat = fs.lstatSync(current);
      if (stat.isSymbolicLink()) {
        throw new Error('Reparse point or symlink rejected: ' + current);
      }
    } catch (err) {
      if (err.code !== 'ENOENT') throw err;
    }
    const parent = path.dirname(current);
    if (parent === current) break;
    current = parent;
  }
}

// 1. Guard: Ephemeral CI environment and secrets required
assert.equal(
  process.env.GITHUB_ACTIONS,
  'true',
  'verify-setup12-delta-upgrade.mjs is strictly restricted to ephemeral GitHub Actions'
);
assert.equal(
  process.env.GITHUB_REPOSITORY,
  'ABDO-TECK/pos-desktop',
  'verify-setup12-delta-upgrade.mjs is strictly restricted to ABDO-TECK/pos-desktop'
);

const runnerTemp = process.env.RUNNER_TEMP;
assert.ok(
  runnerTemp && path.isAbsolute(runnerTemp),
  'RUNNER_TEMP must be a non-empty absolute path'
);
assertNoReparse(runnerTemp);
assert.ok(
  fs.existsSync(runnerTemp) && fs.statSync(runnerTemp).isDirectory(),
  'RUNNER_TEMP must exist and be a directory: ' + runnerTemp
);

const updatePrivateKey = process.env.UPDATE_PRIVATE_KEY;
assert.ok(
  updatePrivateKey && updatePrivateKey.trim().length > 0,
  'UPDATE_PRIVATE_KEY is required for authentic manifest signing'
);

const repoRoot = process.cwd();
const setup12Version = '0.0.12';
const installerPath = path.join(repoRoot, 'dist-electron', `POS-Desktop-Setup-${setup12Version}.exe`);
assert.ok(fs.existsSync(installerPath), `Setup 12 installer missing at ${installerPath}`);

const inventoryPath = path.join(repoRoot, 'dist-electron/deployment-inventory.json');
assert.ok(fs.existsSync(inventoryPath), `Setup 12 deployment inventory missing: ${inventoryPath}`);
const setup12Inventory = JSON.parse(fs.readFileSync(inventoryPath, 'utf8'));
assert.equal(setup12Inventory.version, setup12Version, 'Setup 12 inventory version must be 0.0.12');

// Canonical 8 pre-existing POS data targets
const posTargets = [
  path.join(process.env.PROGRAMDATA || 'C:\\ProgramData', 'POS System'),
  path.join(process.env.APPDATA || '', 'POS System'),
  path.join(process.env.APPDATA || '', 'POS Desktop'),
  path.join(process.env.APPDATA || '', 'pos-desktop'),
  path.join(process.env.LOCALAPPDATA || '', 'POS System'),
  path.join(process.env.LOCALAPPDATA || '', 'POS Desktop'),
  path.join(process.env.LOCALAPPDATA || '', 'pos-desktop'),
  path.join(process.env.LOCALAPPDATA || '', 'pos-desktop-updater'),
].map(p => path.resolve(p));

// 2. Strict Pre-existing Data & Reparse Point Assertions
console.log('--- Setup 12 Future Delta Upgrade (0.0.12 -> 0.0.14) Smoke Test ---');
console.log('[1/7] Asserting canonical POS data targets are clean before install...');

for (const target of posTargets) {
  assertNoReparse(target);
  if (fs.existsSync(target)) {
    const entries = fs.readdirSync(target);
    if (entries.length > 0) {
      throw new Error(`Runner contains pre-existing POS data before install: ${target} (${entries.join(', ')})`);
    }
  }
}

const lab = path.join(runnerTemp, 'setup12-delta-upgrade-lab');
const freshSourceDir = path.join(runnerTemp, 'setup14-fixture-source');

const resolvedRunnerTemp = path.resolve(runnerTemp);
const resolvedLab = path.resolve(lab);
const resolvedFreshSource = path.resolve(freshSourceDir);
const resolvedRepo = path.resolve(repoRoot);

const runnerTempPrefix = resolvedRunnerTemp.toLowerCase().replace(/[\\/]+$/, '') + path.sep;
const repoPrefix = resolvedRepo.toLowerCase().replace(/[\\/]+$/, '') + path.sep;

assert.ok(
  resolvedLab.toLowerCase().startsWith(runnerTempPrefix),
  `lab directory must be a strict child of RUNNER_TEMP (${runnerTemp}): ${lab}`
);
assert.ok(
  resolvedFreshSource.toLowerCase().startsWith(runnerTempPrefix),
  `freshSourceDir must be a strict child of RUNNER_TEMP (${runnerTemp}): ${freshSourceDir}`
);
assert.ok(
  !resolvedLab.toLowerCase().startsWith(repoPrefix) && resolvedLab.toLowerCase() !== resolvedRepo.toLowerCase(),
  `lab directory must not be inside repoRoot: ${lab}`
);
assert.ok(
  !resolvedFreshSource.toLowerCase().startsWith(repoPrefix) && resolvedFreshSource.toLowerCase() !== resolvedRepo.toLowerCase(),
  `freshSourceDir must not be inside repoRoot: ${freshSourceDir}`
);

// Assert no reparse on lab and freshSourceDir BEFORE ANY readdir/mkdir
assertNoReparse(lab);
assertNoReparse(freshSourceDir);

// Refuse pre-existing non-empty lab or fixture source unless uniquely created by this run
if (fs.existsSync(lab)) {
  const labEntries = fs.readdirSync(lab);
  if (labEntries.length > 0) {
    throw new Error(`Pre-existing non-empty lab directory detected: ${lab}`);
  }
}
if (fs.existsSync(freshSourceDir)) {
  const sourceEntries = fs.readdirSync(freshSourceDir);
  if (sourceEntries.length > 0) {
    throw new Error(`Pre-existing non-empty fixture source directory detected: ${freshSourceDir}`);
  }
}

const application = path.join(lab, 'app');
const evidence = path.join(lab, 'evidence');
const fixtureAssetsDir = path.join(lab, 'fixture-assets');
const phpIniDir = path.join(lab, 'php-ini');
const storage = path.join(process.env.PROGRAMDATA || 'C:\\ProgramData', 'POS System');
const unpackedRoot = path.join(application, 'resources/app.asar.unpacked');
const installedExe = path.join(application, 'POS Desktop.exe');
const installedVersionJson = path.join(unpackedRoot, 'version.json');
const targetVersion = '0.0.14';

fs.mkdirSync(application, { recursive: true });
fs.mkdirSync(evidence, { recursive: true });
fs.mkdirSync(fixtureAssetsDir, { recursive: true });
fs.mkdirSync(phpIniDir, { recursive: true });
fs.mkdirSync(freshSourceDir, { recursive: true });

// Ownership markers
const runToken = crypto.randomUUID();
fs.writeFileSync(path.join(lab, '.lab-owner.txt'), runToken, 'utf8');
fs.writeFileSync(path.join(freshSourceDir, '.source-owner.txt'), runToken, 'utf8');

function safeDeleteInFreshSource(relPath) {
  const markerPath = path.join(freshSourceDir, '.source-owner.txt');
  if (!fs.existsSync(markerPath) || fs.readFileSync(markerPath, 'utf8').trim() !== runToken) {
    throw new Error('Safe delete refused: .source-owner.txt marker missing or token mismatch in freshSourceDir');
  }

  const target = path.resolve(freshSourceDir, relPath);
  const normalizedSource = path.resolve(freshSourceDir).toLowerCase() + path.sep;
  if (!target.toLowerCase().startsWith(normalizedSource)) {
    throw new Error(`Attempted path traversal deletion outside freshSourceDir: ${relPath}`);
  }

  if (fs.existsSync(target)) {
    const stat = fs.lstatSync(target);
    if (stat.isSymbolicLink()) {
      fs.unlinkSync(target);
    } else {
      assertNoReparse(target);
      if (stat.isDirectory()) {
        const verifyNoSymlinks = (dir) => {
          for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
            const p = path.join(dir, ent.name);
            if (ent.isSymbolicLink() || fs.lstatSync(p).isSymbolicLink()) {
              throw new Error('Reparse point or symlink rejected in nested directory during safe delete: ' + p);
            }
            if (ent.isDirectory()) {
              verifyNoSymlinks(p);
            }
          }
        };
        verifyNoSymlinks(target);
      }
      fs.rmSync(target, { recursive: true, force: true });
    }
  }
}

function stopOwnedLabProcesses(appDir) {
  const normalizedApp = path.resolve(appDir).toLowerCase() + path.sep;
  // Literal PowerShell single quoting: only ' is doubled to '', backslashes stay single
  const psAppRoot = normalizedApp.replace(/'/g, "''");
  const stopScript = `
    $ErrorActionPreference = 'Stop'
    $targetRoot = '${psAppRoot}'
    $deadline = (Get-Date).AddSeconds(20)
    do {
      $matching = @(Get-Process -ErrorAction SilentlyContinue | Where-Object {
        try {
          $p = $_.Path
          $p -and $p.ToLower().StartsWith($targetRoot.ToLower())
        } catch { $false }
      })
      if ($matching.Count -eq 0) { break }
      foreach ($proc in $matching) {
        try {
          if (-not $proc.HasExited) {
            $proc.Kill()
            $null = $proc.WaitForExit(5000)
          }
        } catch [System.InvalidOperationException] {
          # Process exited concurrently
        }
      }
      Start-Sleep -Milliseconds 250
    } while ((Get-Date) -lt $deadline)

    $surviving = @(Get-Process -ErrorAction SilentlyContinue | Where-Object {
      try {
        $p = $_.Path
        $p -and $p.ToLower().StartsWith($targetRoot.ToLower())
      } catch { $false }
    })
    if ($surviving.Count -gt 0) {
      $ids = ($surviving | ForEach-Object { "$($_.Id):$($_.ProcessName)" }) -join ', '
      throw "Surviving owned lab processes detected: $ids"
    }
  `;
  execFileSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', stopScript], {
    stdio: 'inherit'
  });
}

function snapshotImmutableNative(appDir) {
  const snapshot = new Map();
  function walk(currentDir) {
    for (const ent of fs.readdirSync(currentDir, { withFileTypes: true })) {
      const full = path.join(currentDir, ent.name);
      if (ent.isSymbolicLink() || fs.lstatSync(full).isSymbolicLink()) {
        throw new Error('Reparse point / symlink rejected in native tree: ' + full);
      }
      const rel = path.relative(appDir, full).replace(/\\/g, '/');
      if (rel === 'resources/app.asar.unpacked' || rel.startsWith('resources/app.asar.unpacked/')) {
        continue;
      }
      if (ent.isDirectory()) {
        walk(full);
      } else if (ent.isFile()) {
        snapshot.set(rel, { sha256: sha256File(full), size: fs.statSync(full).size });
      }
    }
  }
  walk(appDir);
  return snapshot;
}

function getActualDeploymentFiles(root) {
  const fixed = ['backend/backend.phar', 'backend/certs/cacert.pem', 'database/pos_schema.sql', 'version.json'];
  for (const rel of fixed) {
    assertNoReparse(path.join(root, rel));
  }
  const dist = path.join(root, 'frontend/dist');
  assert.ok(fs.existsSync(dist), 'frontend/dist must exist in unpackedRoot');
  assertNoReparse(dist);
  const paths = [...fixed];
  function scan(dir) {
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, ent.name);
      if (ent.isSymbolicLink() || fs.lstatSync(full).isSymbolicLink()) {
        throw new Error('Reparse point / symlink rejected in deployment dist tree: ' + full);
      }
      if (ent.isDirectory()) {
        scan(full);
      } else if (ent.isFile() && ent.name !== 'digital-certificate.txt') {
        const rel = path.relative(root, full).replace(/\\/g, '/');
        paths.push(rel);
      }
    }
  }
  scan(dist);
  paths.sort();
  const result = {};
  for (const rel of paths) {
    const full = path.join(root, rel);
    assert.ok(fs.existsSync(full), `Deployment file missing: ${rel}`);
    result[rel] = { sha256: sha256File(full), size: fs.statSync(full).size };
  }
  return result;
}

function findLanSslPair(targets = posTargets) {
  const pairs = [];
  for (const target of targets) {
    const key = path.join(target, 'ssl', 'server.key');
    const cert = path.join(target, 'ssl', 'server.crt');
    if (fs.existsSync(key) && fs.existsSync(cert)) {
      const keyStat = fs.statSync(key);
      const certStat = fs.statSync(cert);
      if (keyStat.isFile() && certStat.isFile() && keyStat.size > 0 && certStat.size > 0) {
        assertNoReparse(key);
        assertNoReparse(cert);
        const resolvedKey = path.resolve(key);
        const resolvedCert = path.resolve(cert);
        assert.ok(path.isAbsolute(resolvedKey), 'LAN SSL server.key must be an absolute path: ' + resolvedKey);
        assert.ok(path.isAbsolute(resolvedCert), 'LAN SSL server.crt must be an absolute path: ' + resolvedCert);
        pairs.push({ key: resolvedKey, cert: resolvedCert });
      }
    }
  }
  if (pairs.length === 0) {
    return null;
  }
  assert.equal(pairs.length, 1, `Ambiguous LAN SSL keypair discovery across targets: found ${pairs.length} pairs`);
  return pairs[0];
}

async function getSystemProtectedState(desktopLabUi) {
  const credFiles = [];
  for (const target of posTargets) {
    const p = path.join(target, 'db_credentials.json');
    if (fs.existsSync(p) && fs.statSync(p).isFile() && fs.statSync(p).size > 0) {
      assertNoReparse(p);
      credFiles.push(p);
    }
  }
  assert.ok(credFiles.length > 0, 'Mandatory credentials file missing or empty in POS data targets');

  const envFiles = [];
  for (const target of posTargets) {
    const p = path.join(target, '.env');
    if (fs.existsSync(p) && fs.statSync(p).isFile() && fs.statSync(p).size > 0) {
      assertNoReparse(p);
      envFiles.push(p);
    }
  }

  let foundQzDir = null;
  for (const target of posTargets) {
    const dir = path.join(target, 'qz-certs');
    const key = path.join(dir, 'private-key.pem');
    const cert = path.join(dir, 'digital-certificate.pem');
    if (fs.existsSync(key) && fs.existsSync(cert) && fs.statSync(key).size > 0 && fs.statSync(cert).size > 0) {
      assertNoReparse(key);
      assertNoReparse(cert);
      foundQzDir = dir;
      break;
    }
  }
  assert.ok(foundQzDir, 'Mandatory runtime QZ private-key.pem and digital-certificate.pem pair missing or empty');
  const qzKeyPath = path.join(foundQzDir, 'private-key.pem');
  const qzCertPath = path.join(foundQzDir, 'digital-certificate.pem');

  const deviceKeyMap = {
    [qzKeyPath]: desktopLabUi.hash(qzKeyPath),
    [qzCertPath]: desktopLabUi.hash(qzCertPath)
  };

  const receipts = [];
  for (const target of posTargets) {
    const p = path.join(target, 'qz-shared-install.json');
    if (fs.existsSync(p) && fs.statSync(p).isFile() && fs.statSync(p).size > 0) {
      assertNoReparse(p);
      receipts.push(p);
    }
  }
  assert.equal(receipts.length, 1, 'Exactly one non-empty QZ ownership receipt must exist');
  const receiptPath = path.resolve(receipts[0]);
  const receiptDigest = desktopLabUi.hash(receiptPath);
  const ownership = JSON.parse(fs.readFileSync(receiptPath, 'utf8'));

  assert.ok(typeof ownership.rootCertificateBase64 === 'string' && ownership.rootCertificateBase64.length > 0);
  const certDer = Buffer.from(ownership.rootCertificateBase64, 'base64');
  const printingThumbprint = crypto.createHash('sha1').update(certDer).digest('hex').toUpperCase();

  const enterpriseKeyPath = `Registry::HKEY_LOCAL_MACHINE\\SOFTWARE\\Microsoft\\EnterpriseCertificates\\Root\\Certificates\\${printingThumbprint}`;
  const enterpriseCheck = execFileSync('powershell.exe', [
    '-NoProfile', '-NonInteractive', '-Command',
    `if (Test-Path -LiteralPath '${enterpriseKeyPath}') { 'True' } else { 'False' }`
  ], { encoding: 'utf8', windowsHide: true }).trim();
  assert.equal(enterpriseCheck, 'True', `Printing root certificate missing in Enterprise trust store: ${enterpriseKeyPath}`);

  const qzRoot = path.resolve(process.env.PROGRAMDATA || 'C:\\ProgramData', 'qz');
  assertNoReparse(qzRoot);
  const resolvedQzRoot = qzRoot.toLowerCase() + path.sep;
  const qzTrustFiles = {};
  for (const file of ownership.files) {
    if (!file || typeof file.path !== 'string' || file.path.trim().length === 0) {
      throw new Error('Malformed or empty file path in QZ ownership receipt');
    }
    const rawPath = file.path.trim();
    if (path.isAbsolute(rawPath) || /^[a-zA-Z]:/.test(rawPath) || rawPath.startsWith('/') || rawPath.startsWith('\\')) {
      throw new Error('Absolute path rejected in QZ ownership receipt: ' + rawPath);
    }
    const normalized = path.normalize(rawPath).replace(/^[/\\]+/, '');
    if (normalized.startsWith('..' + path.sep) || normalized === '..' || normalized.includes(path.sep + '..' + path.sep)) {
      throw new Error('Directory traversal rejected in QZ ownership receipt: ' + rawPath);
    }
    const absPath = path.resolve(qzRoot, normalized);
    if (!absPath.toLowerCase().startsWith(resolvedQzRoot)) {
      throw new Error(`File in QZ ownership receipt escapes qzRoot: ${rawPath} -> ${absPath}`);
    }
    assertNoReparse(absPath);
    assert.ok(fs.existsSync(absPath) && fs.statSync(absPath).isFile(), 'Owned printing file missing: ' + absPath);
    qzTrustFiles[normalized] = desktopLabUi.hash(absPath).toLowerCase();
  }
  assert.ok(Object.keys(qzTrustFiles).length >= 4, 'Non-empty QZ trust files required (>= 4)');

  const sslPair = findLanSslPair(posTargets);
  assert.ok(sslPair && sslPair.key && sslPair.cert, 'Mandatory non-empty LAN SSL server.key and server.crt pair missing');
  if (boundSslPair) {
    assert.equal(sslPair.key, boundSslPair.key, 'LAN SSL server.key path must remain stable and bound');
    assert.equal(sslPair.cert, boundSslPair.cert, 'LAN SSL server.crt path must remain stable and bound');
  }
  const sslFiles = [sslPair.key, sslPair.cert];
  assert.equal(sslFiles.length, 2, 'Mandatory non-empty LAN SSL server.key and server.crt pair missing');

  const secrets = {
    credentials: Object.fromEntries(credFiles.map(f => [f, desktopLabUi.hash(f)])),
    env: Object.fromEntries(envFiles.map(f => [f, desktopLabUi.hash(f)])),
    deviceKeys: deviceKeyMap,
    qzReceipt: { [receiptPath]: receiptDigest },
    qzTrust: qzTrustFiles,
    sslCerts: Object.fromEntries(sslFiles.map(f => [f, desktopLabUi.hash(f)]))
  };

  const publicData = {
    lanSslEnabled: true,
    printingThumbprint,
    printingTrustInstalled: true,
    ownedPrintingFilesCount: Object.keys(qzTrustFiles).length,
    deviceKeyFilesCount: Object.keys(deviceKeyMap).length,
    envFilesCount: envFiles.length
  };

  return { secrets, public: publicData };
}

function assertProtectedStatePreserved(initial, current, stageName) {
  for (const [category, initialFiles] of Object.entries(initial.secrets)) {
    const currentFiles = current.secrets[category] || {};
    const initKeys = Object.keys(initialFiles);
    const currKeys = Object.keys(currentFiles);
    if (initKeys.length !== currKeys.length) {
      throw new Error(`Protected file count mismatch in '${category}' during ${stageName}: initial=${initKeys.length}, current=${currKeys.length}`);
    }
    for (const [filePath, expectedHash] of Object.entries(initialFiles)) {
      const currentHash = currentFiles[filePath];
      if (typeof currentHash !== 'string' || currentHash !== expectedHash) {
        throw new Error(`Protected file modified in '${category}': ${path.basename(filePath)} during ${stageName}`);
      }
    }
  }

  assert.equal(current.public.lanSslEnabled, true, `LAN SSL status changed during ${stageName}`);
  assert.equal(current.public.printingThumbprint, initial.public.printingThumbprint, `Printing thumbprint changed during ${stageName}`);
  assert.equal(current.public.printingTrustInstalled, true, `Printing trust not installed during ${stageName}`);
  assert.equal(current.public.ownedPrintingFilesCount, initial.public.ownedPrintingFilesCount, `Owned printing files count changed during ${stageName}`);
}

function assertSqlPreserved(expected, actual, stageName) {
  assert.equal(actual.schema_sha256, expected.schema_sha256, `Database schema SHA256 modified during ${stageName}`);
  assert.deepEqual(actual.ledger, expected.ledger, `Database migration ledger modified during ${stageName}`);
  assert.equal(actual.admin_digest, expected.admin_digest, `Admin identity digest modified during ${stageName}`);
  assert.deepEqual(actual.business_rows, expected.business_rows, `Business customer rows modified during ${stageName}`);
  assert.deepEqual(actual.settings, expected.settings, `Settings modified during ${stageName}`);
  assert.deepEqual(actual, expected, `SQL business state modified during ${stageName}`);
}

const applyHistory = {
  update_apply: [],
  transition_apply: []
};

async function waitForApplySlot(limiter = 'update_apply') {
  const history = applyHistory[limiter];
  if (history.length >= 2) {
    const earliestInWindow = history[history.length - 2];
    const waitUntil = earliestInWindow + 310000;
    while (Date.now() < waitUntil) {
      const remainingSec = Math.ceil((waitUntil - Date.now()) / 1000);
      console.log(`Waiting ${remainingSec}s for ${limiter} rate-limit window (2 per 300s)...`);
      await delay(Math.min(15000, waitUntil - Date.now()));
    }
  }
  history.push(Date.now());
}

async function versionIs(ui, auth, requestTransport, expected, startedAt = 0, expectedRunId = null) {
  const deadline = Date.now() + 180000;
  do {
    try {
      if (fs.existsSync(installedVersionJson)) {
        const currentVersion = JSON.parse(fs.readFileSync(installedVersionJson, 'utf8')).version;
        if (currentVersion === expected) {
          if (expectedRunId) {
            await ui.until(
              `window.__pageRunId !== ${JSON.stringify(expectedRunId)} && Boolean(document.querySelector("form, a[href='/settings']"))`,
              'reloaded frontend after update',
              120000
            );
          } else {
            await delay(5000);
            await ui.until("Boolean(document.querySelector(\"form, a[href='/settings']\"))", 'reloaded frontend', 120000);
          }
          if (await ui.evaluate('Boolean(document.querySelector("input[type=email]"))')) {
            await ui.login(auth.email, auth.password);
          }
          await ui.until("Boolean(document.querySelector(\"a[href='/settings']\"))", 'authenticated after update reload', 120000);
          await delay(2000);
          requestTransport.resume();
          return;
        }
      }
    } catch (err) {
      if (!/Execution context was destroyed|Cannot find context|Inspected target navigated/i.test(err?.message || '')) {
        // Unexpected navigation evaluation error; continue polling until deadline
      }
    }
    if (startedAt > 0) {
      try {
        const stateFile = path.join(storage, 'update-state.json');
        if (fs.existsSync(stateFile)) {
          const st = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
          const stateTime = Date.parse(st.updated_at || st.started_at || '');
          if (Number.isFinite(stateTime) && stateTime >= startedAt && (st.state === 'failed' || st.state === 'rolled_back')) {
            throw new Error('Update transaction failed on disk: ' + (st.error || st.state));
          }
        }
      } catch (e) {
        if (e.message?.startsWith('Update transaction failed on disk:')) throw e;
      }
    }
    await delay(500);
  } while (Date.now() < deadline);
  throw new Error('Version/reload failed waiting for ' + expected);
}

async function pendingSale(ui, action, targetLocalId = 'setup12-delta-pending') {
  return ui.evaluate(`new Promise((resolve, reject) => {
    const request = indexedDB.open('pos_offline');
    request.onerror = () => reject(Error('Offline DB unavailable: ' + request.error?.message));
    request.onsuccess = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains('pending_sales')) {
        db.close();
        return resolve({ count: 0, record: null, error: 'Store pending_sales missing' });
      }
      const isSeed = ${JSON.stringify(action === 'seed')};
      if (isSeed) {
        let authData;
        try {
          authData = JSON.parse(localStorage.getItem('pos_auth') || '{}');
        } catch (e) {
          db.close();
          return reject(Error('pos_auth parse failure: ' + e.message));
        }
        const user = authData?.state?.user;
        const ownerUserId = Number(user?.id);
        const branchId = Number(user?.branch_id);
        if (!Number.isInteger(ownerUserId) || ownerUserId <= 0) {
          db.close();
          return reject(Error('Missing or invalid authenticated ownerUserId from pos_auth: ' + JSON.stringify(user?.id)));
        }
        if (!Number.isInteger(branchId) || branchId <= 0) {
          db.close();
          return reject(Error('Missing or invalid authenticated branchId from pos_auth: ' + JSON.stringify(user?.branch_id)));
        }

        const saleRecord = {
          localId: ${JSON.stringify(targetLocalId)},
          ownerUserId,
          branchId,
          idempotencyKey: 'offline_idem_' + Date.now(),
          savedAt: new Date().toISOString(),
          syncStatus: 'review',
          payload: {
            items: [{ product_id: 1, name: 'Offline Fixture Product', quantity: 1, price: 149.99 }],
            total: 149.99
          }
        };

        const tx = db.transaction('pending_sales', 'readwrite');
        const store = tx.objectStore('pending_sales');
        const putReq = store.put(saleRecord);
        putReq.onerror = () => { db.close(); reject(Error('Failed to put pending sale: ' + putReq.error?.message)); };

        tx.oncomplete = () => {
          const readTx = db.transaction('pending_sales', 'readonly');
          const readStore = readTx.objectStore('pending_sales');
          const getReq = readStore.get(${JSON.stringify(targetLocalId)});
          getReq.onsuccess = () => {
            const countReq = readStore.count();
            countReq.onsuccess = () => {
              const res = { count: countReq.result, record: getReq.result };
              db.close();
              resolve(res);
            };
            countReq.onerror = () => { db.close(); reject(Error('Count failed: ' + countReq.error?.message)); };
          };
          getReq.onerror = () => { db.close(); reject(Error('Get after put failed: ' + getReq.error?.message)); };
        };
        tx.onerror = () => { db.close(); reject(Error('Put tx failed: ' + tx.error?.message)); };
      } else {
        const tx = db.transaction('pending_sales', 'readonly');
        const store = tx.objectStore('pending_sales');
        const getReq = store.get(${JSON.stringify(targetLocalId)});
        getReq.onsuccess = () => {
          const countReq = store.count();
          countReq.onsuccess = () => {
            const res = { count: countReq.result, record: getReq.result };
            db.close();
            resolve(res);
          };
          countReq.onerror = () => { db.close(); reject(Error('Count failed: ' + countReq.error?.message)); };
        };
        getReq.onerror = () => { db.close(); reject(Error('Get failed: ' + getReq.error?.message)); };
      }
    };
  })`);
}

// Enclose entire installation, build, and test lifecycle in try/finally for safe owned cleanup
let ui = null;
let requestTransport = null;
let observedJob = null;
let observedTerminalOk = false;
let observedRequiresHandoff = false;
let observedHandoffVersion = null;
let handoffPlanSnapshot = null;
let boundJobId = null;
let preHandoffOperationId = null;
let boundSslPair = null;
let primaryError = null;
let acceptanceEvidence = null;

try {
  // 3. Silently install Setup 12 into isolated application directory
  console.log(`[2/7] Installing Setup 12 silently into ${application}...`);
  const installScript = `
    $ErrorActionPreference = 'Stop'
    $proc = Start-Process -FilePath '${installerPath.replace(/'/g, "''")}' -ArgumentList @('/S', '/currentuser', '/D=${application.replace(/'/g, "''")}') -WindowStyle Hidden -PassThru
    if (-not $proc.WaitForExit(180000)) { throw 'Installer timed out after 180s' }
    if ($proc.ExitCode -ne 0) { throw "Installer failed with exit code $($proc.ExitCode)" }
  `;
  execFileSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', installScript], { stdio: 'inherit' });

  assert.ok(fs.existsSync(installedExe), `Installed executable missing at ${installedExe}`);
  assert.ok(fs.existsSync(installedVersionJson), `Installed version.json missing at ${installedVersionJson}`);
  const initialInstalledMeta = JSON.parse(fs.readFileSync(installedVersionJson, 'utf8'));
  assert.equal(initialInstalledMeta.version, setup12Version, 'Initial installed version must be 0.0.12');

  const initialExeHash = sha256File(installedExe);
  const initialAsarHash = sha256File(path.join(application, 'resources/app.asar'));
  const setup12PharHash = sha256File(path.join(unpackedRoot, 'backend/backend.phar'));

  // Mandatory initial public update key assertion
  const updatePubKeyPath = path.join(unpackedRoot, 'backend/certs/update_public_key.pem');
  assert.ok(
    fs.existsSync(updatePubKeyPath) && fs.statSync(updatePubKeyPath).size > 0,
    `Mandatory initial update_public_key.pem missing: ${updatePubKeyPath}`
  );
  assertNoReparse(updatePubKeyPath);
  const initialUpdatePubKeyHash = sha256File(updatePubKeyPath);

  const php = path.join(application, 'resources/portable/php/php.exe');
  assert.ok(fs.existsSync(php), `Embedded PHP missing at ${php}`);

  // 4. Build REAL changed backend.phar + frontend/version fixture 14 in FRESH RUNNER_TEMP source copy
  console.log('[3/7] Building genuine future 0.0.14 fixture in fresh RUNNER_TEMP source copy...');

  // Copy source backend, frontend, build-phar.php, package.json, package-lock.json with strict exclusion filter
  // Allowlist approved public certificates required by PHAR builder and verification
  const approvedPublicCerts = new Set([
    'backend/certs/cacert.pem',
    'backend/certs/update_public_key.pem'
  ]);

  const sourceFilter = (src) => {
    const relFromRepo = path.relative(repoRoot, src).replace(/\\/g, '/');
    const base = path.basename(src);
    if (base === '.git' || base === 'node_modules' || base === 'vendor' || base === 'storage' || base === 'dist') return false;
    if (base === '.env' || base.startsWith('.env.')) return false;
    // Allow approved public certificates
    if (approvedPublicCerts.has(relFromRepo)) {
      return true;
    }
    // Exclude private/runtime keys, certificates, secrets
    if (
      base.endsWith('.pem') ||
      base.endsWith('.key') ||
      base.endsWith('.crt') ||
      base.endsWith('.p12') ||
      base.endsWith('.pfx')
    ) {
      return false;
    }
    return true;
  };

  fs.cpSync(path.join(repoRoot, 'backend'), path.join(freshSourceDir, 'backend'), { recursive: true, filter: sourceFilter });
  fs.cpSync(path.join(repoRoot, 'frontend'), path.join(freshSourceDir, 'frontend'), { recursive: true, filter: sourceFilter });
  fs.copyFileSync(path.join(repoRoot, 'build-phar.php'), path.join(freshSourceDir, 'build-phar.php'));
  fs.copyFileSync(path.join(repoRoot, 'package.json'), path.join(freshSourceDir, 'package.json'));
  if (fs.existsSync(path.join(repoRoot, 'package-lock.json'))) {
    fs.copyFileSync(path.join(repoRoot, 'package-lock.json'), path.join(freshSourceDir, 'package-lock.json'));
  }
  if (fs.existsSync(path.join(repoRoot, 'frontend/package-lock.json'))) {
    fs.copyFileSync(path.join(repoRoot, 'frontend/package-lock.json'), path.join(freshSourceDir, 'frontend/package-lock.json'));
  }

  // Assert copied approved public certs retain exact source hash
  assert.equal(
    sha256File(path.join(freshSourceDir, 'backend/certs/cacert.pem')),
    sha256File(path.join(repoRoot, 'backend/certs/cacert.pem')),
    'backend/certs/cacert.pem in fixture source must retain exact source hash'
  );
  assert.equal(
    sha256File(path.join(freshSourceDir, 'backend/certs/update_public_key.pem')),
    sha256File(path.join(repoRoot, 'backend/certs/update_public_key.pem')),
    'backend/certs/update_public_key.pem in fixture source must retain exact source hash'
  );

  // Copy approved tracked database/migrations and database/seeders into freshSourceDir before PHAR compile
  console.log('      Copying approved tracked database/migrations and database/seeders into fixture source...');
  safeDeleteInFreshSource('database');

  const trackedDbOutput = execFileSync('git', ['ls-files', '-z', '--', 'database/migrations', 'database/seeders'], {
    cwd: repoRoot,
    encoding: 'utf8'
  });
  const trackedDbFiles = trackedDbOutput
    .split('\0')
    .map(f => f.trim().replace(/\\/g, '/'))
    .filter(Boolean);

  assert.ok(trackedDbFiles.length > 0, 'No tracked files found under database/migrations or database/seeders');

  const freshDatabaseDir = path.join(freshSourceDir, 'database');
  const resolvedFreshDb = path.resolve(freshDatabaseDir);
  const freshDbPrefix = resolvedFreshDb.toLowerCase() + path.sep;

  let copiedMigrationCount = 0;
  let copiedRollbackCount = 0;
  let copiedSeederCount = 0;

  for (const rel of trackedDbFiles) {
    assert.ok(
      rel.startsWith('database/migrations/') || rel.startsWith('database/seeders/'),
      `Tracked database file outside approved trees: ${rel}`
    );
    assert.ok(rel.endsWith('.sql'), `Tracked file in database tree must end with .sql: ${rel}`);
    assert.ok(!rel.includes('..'), `Path traversal rejected: ${rel}`);

    const srcFile = path.resolve(repoRoot, rel);
    assert.ok(
      srcFile.toLowerCase().startsWith(repoPrefix),
      `Source file resolves outside repoRoot: ${srcFile}`
    );
    assert.ok(fs.existsSync(srcFile), `Source file missing: ${srcFile}`);
    assertNoReparse(srcFile);
    assert.ok(fs.statSync(srcFile).isFile(), `Source path must be a file: ${srcFile}`);

    const destFile = path.resolve(freshSourceDir, rel);
    assert.ok(
      destFile.toLowerCase().startsWith(freshDbPrefix),
      `Destination file resolves outside freshSourceDir/database: ${destFile}`
    );

    const markerPath = path.join(freshSourceDir, '.source-owner.txt');
    assert.ok(
      fs.existsSync(markerPath) && fs.readFileSync(markerPath, 'utf8').trim() === runToken,
      'Ownership token mismatch in freshSourceDir'
    );

    fs.mkdirSync(path.dirname(destFile), { recursive: true });
    fs.copyFileSync(srcFile, destFile);
    assertNoReparse(destFile);

    const srcBytes = fs.readFileSync(srcFile);
    const destBytes = fs.readFileSync(destFile);
    assert.equal(destBytes.length, srcBytes.length, `Size mismatch for copied file: ${rel}`);
    assert.equal(sha256(destBytes), sha256(srcBytes), `SHA256 mismatch for copied file: ${rel}`);

    if (rel.startsWith('database/migrations/')) {
      copiedMigrationCount++;
      if (rel.startsWith('database/migrations/rollback/')) {
        copiedRollbackCount++;
      }
    } else if (rel.startsWith('database/seeders/')) {
      copiedSeederCount++;
    }
  }

  assert.ok(copiedMigrationCount > 0, 'database/migrations tree is mandatory and must be non-empty');
  assert.ok(copiedRollbackCount > 0, 'database/migrations/rollback tree is mandatory and must be non-empty');
  assert.ok(copiedSeederCount > 0, 'database/seeders tree is mandatory and must be non-empty');

  // Strict sourcewalk check on freshSourceDir/database: reject symlinks/reparse, outside copy, extra files
  const approvedDbSet = new Set(trackedDbFiles);
  const scannedFreshDbFiles = [];
  function walkFreshDb(dir) {
    assertNoReparse(dir);
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, ent.name);
      assertNoReparse(full);
      if (ent.isSymbolicLink()) {
        throw new Error(`Symbolic link rejected in freshSourceDir/database: ${full}`);
      }
      if (ent.isDirectory()) {
        walkFreshDb(full);
      } else if (ent.isFile()) {
        const rel = path.relative(freshSourceDir, full).replace(/\\/g, '/');
        if (!approvedDbSet.has(rel)) {
          throw new Error(`Unapproved or unexpected file in freshSourceDir/database: ${rel}`);
        }
        if (!rel.endsWith('.sql')) {
          throw new Error(`Non-SQL file detected in freshSourceDir/database: ${rel}`);
        }
        scannedFreshDbFiles.push(rel);
      } else {
        throw new Error(`Unsupported entry type in freshSourceDir/database: ${full}`);
      }
    }
  }
  walkFreshDb(freshDatabaseDir);
  assert.equal(
    scannedFreshDbFiles.length,
    trackedDbFiles.length,
    `Scanned DB files count (${scannedFreshDbFiles.length}) must equal tracked DB files count (${trackedDbFiles.length})`
  );

  // Junction vendor and node_modules from repoRoot to save time/space
  const repoVendor = path.join(repoRoot, 'backend/vendor');
  if (fs.existsSync(repoVendor) && !fs.existsSync(path.join(freshSourceDir, 'backend/vendor'))) {
    fs.symlinkSync(repoVendor, path.join(freshSourceDir, 'backend/vendor'), 'junction');
  }
  const repoNodeModules = path.join(repoRoot, 'frontend/node_modules');
  if (fs.existsSync(repoNodeModules) && !fs.existsSync(path.join(freshSourceDir, 'frontend/node_modules'))) {
    fs.symlinkSync(repoNodeModules, path.join(freshSourceDir, 'frontend/node_modules'), 'junction');
  }

  // Safe delete helper verifies path is strict child of freshSourceDir
  safeDeleteInFreshSource('backend/backend.phar');
  safeDeleteInFreshSource('backend_temp.phar');
  safeDeleteInFreshSource('backend/.env');
  safeDeleteInFreshSource('backend/storage');
  safeDeleteInFreshSource('frontend/dist');

  // Verify static package and package-lock versions retain 0.0.12 (never mutate to 0.0.14)
  const rootPkg = JSON.parse(fs.readFileSync(path.join(freshSourceDir, 'package.json'), 'utf8'));
  assert.equal(rootPkg.version, setup12Version, 'Root package.json version in fixture source must retain 0.0.12');
  const fPkg = JSON.parse(fs.readFileSync(path.join(freshSourceDir, 'frontend/package.json'), 'utf8'));
  assert.equal(fPkg.version, setup12Version, 'frontend/package.json version in fixture source must retain 0.0.12');
  if (fs.existsSync(path.join(freshSourceDir, 'package-lock.json'))) {
    const rootPkgLock = JSON.parse(fs.readFileSync(path.join(freshSourceDir, 'package-lock.json'), 'utf8'));
    assert.equal(rootPkgLock.version, setup12Version, 'package-lock.json version in fixture source must retain 0.0.12');
  }
  if (fs.existsSync(path.join(freshSourceDir, 'frontend/package-lock.json'))) {
    const fPkgLock = JSON.parse(fs.readFileSync(path.join(freshSourceDir, 'frontend/package-lock.json'), 'utf8'));
    assert.equal(fPkgLock.version, setup12Version, 'frontend/package-lock.json version in fixture source must retain 0.0.12');
  }

  // Root version.json 14 feeds build app version metadata
  const targetVersionJsonContent = JSON.stringify({
    version: targetVersion,
    application_version: targetVersion,
    update_engine_version: '1.0.0',
    channel: 'stable',
    minimum_supported_version: '0.0.1',
    release_series: 'v0',
    release_generation: 3,
    released_at: '2026-10-28',
    changelog: [
      'Automated future delta smoke test validating Setup 12 native runtime handoff',
    ],
    requires_npm_install: false,
    update_channel: 'stable',
    release_type: 'delta',
    installer_version: setup12Version
  }, null, 2) + '\n';
  fs.writeFileSync(path.join(freshSourceDir, 'version.json'), targetVersionJsonContent, 'utf8');

  // Write unique valid PHP fixture under backend/Services in isolated source
  const fixtureMarkerToken = 'FIXTURE_0_0_14_DELTA_SMOKE_' + crypto.randomUUID().replace(/-/g, '');
  const backendMarkerPhp = `<?php
declare(strict_types=1);

namespace App\\Services;

final class DeltaFixtureMarker0014
{
    public const MARKER = '${fixtureMarkerToken}';

    public static function getMarker(): string
    {
        return self::MARKER;
    }
}
`;
  const backendMarkerFile = path.join(freshSourceDir, 'backend/Services/DeltaFixtureMarker0014.php');
  fs.writeFileSync(backendMarkerFile, backendMarkerPhp, 'utf8');

  // Compile genuine backend.phar inside freshSourceDir using existing build-phar.php
  console.log('      Compiling genuine v14 backend.phar...');
  execFileSync(php, ['-d', 'phar.readonly=0', path.join(freshSourceDir, 'build-phar.php')], {
    cwd: freshSourceDir,
    stdio: 'inherit'
  });
  const v14PharPath = path.join(freshSourceDir, 'backend/backend.phar');
  assert.ok(fs.existsSync(v14PharPath), 'Compiled v14 backend.phar missing');
  const v14PharHash = sha256File(v14PharPath);
  assert.notEqual(v14PharHash, setup12PharHash, 'v14 backend.phar must have a genuine changed hash');

  // Authoritative token check: exact PHP Phar entry content check + non-empty migrations & seeders trees
  const expectedDbMap = {};
  for (const rel of trackedDbFiles) {
    const srcFile = path.resolve(repoRoot, rel);
    const buf = fs.readFileSync(srcFile);
    expectedDbMap[rel] = {
      sha256: crypto.createHash('sha256').update(buf).digest('hex'),
      size: buf.length
    };
  }
  const expectedDbMapFile = path.join(freshSourceDir, 'expected-phar-db-entries.json');
  fs.writeFileSync(expectedDbMapFile, JSON.stringify(expectedDbMap, null, 2), 'utf8');
  assertNoReparse(expectedDbMapFile);

  const verifyPharEntryPhp = `
    $pharPath = '${v14PharPath.replace(/\\/g, '/')}';
    $phar = new Phar($pharPath);

    // 1. Authoritative exact marker token verification
    $entry = 'Services/DeltaFixtureMarker0014.php';
    if (!isset($phar[$entry])) {
      fwrite(STDERR, "Entry {$entry} missing inside rebuilt PHAR\\n");
      exit(1);
    }
    $content = $phar[$entry]->getContent();
    if (!str_contains($content, '${fixtureMarkerToken}')) {
      fwrite(STDERR, "Exact marker token missing inside PHAR entry {$entry}\\n");
      exit(1);
    }

    // 2. Resolve and validate isolated metadata file argument inside freshSourceDir
    $metadataFile = '';
    for ($i = 1; $i < count($argv); $i++) {
      if ($argv[$i] !== '--' && is_string($argv[$i]) && strlen($argv[$i]) > 0) {
        $metadataFile = $argv[$i];
        break;
      }
    }
    if ($metadataFile === '') {
      $metadataFile = '${expectedDbMapFile.replace(/\\/g, '/')}';
    }

    $freshSourceDirReal = realpath('${freshSourceDir.replace(/\\/g, '/')}');
    if ($freshSourceDirReal === false) {
      fwrite(STDERR, "freshSourceDir cannot be resolved\\n");
      exit(1);
    }
    $realMetadataFile = realpath($metadataFile);
    if ($realMetadataFile === false || !is_file($realMetadataFile)) {
      fwrite(STDERR, "Metadata file missing or cannot be resolved: {$metadataFile}\\n");
      exit(1);
    }
    $sourceDirPrefix = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $freshSourceDirReal), '/') . '/';
    $normalizedMetadata = str_replace(DIRECTORY_SEPARATOR, '/', $realMetadataFile);
    if (!str_starts_with(strtolower($normalizedMetadata), strtolower($sourceDirPrefix))) {
      fwrite(STDERR, "Metadata file {$realMetadataFile} is outside freshSourceDir {$freshSourceDirReal}\\n");
      exit(1);
    }

    $metadataRaw = file_get_contents($realMetadataFile);
    if ($metadataRaw === false) {
      fwrite(STDERR, "Failed to read metadata file: {$realMetadataFile}\\n");
      exit(1);
    }
    $expectedMap = json_decode($metadataRaw, true);
    if (!is_array($expectedMap)) {
      fwrite(STDERR, "Metadata file is not valid JSON object\\n");
      exit(1);
    }

    $expectedMigrations = [];
    $expectedSeeders = [];
    foreach ($expectedMap as $relPath => $info) {
      if (!is_string($relPath) || !is_array($info) || !isset($info['sha256'], $info['size'])) {
        fwrite(STDERR, "Invalid metadata entry for: {$relPath}\\n");
        exit(1);
      }
      $cleanRel = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relPath), '/');
      if (str_starts_with($cleanRel, 'database/migrations/')) {
        $expectedMigrations[$cleanRel] = $info;
      } elseif (str_starts_with($cleanRel, 'database/seeders/')) {
        $expectedSeeders[$cleanRel] = $info;
      } else {
        fwrite(STDERR, "Metadata entry outside approved scopes: {$cleanRel}\\n");
        exit(1);
      }
    }

    if (count($expectedMigrations) === 0) {
      fwrite(STDERR, "Mandatory database/migrations tree is empty in expected metadata\\n");
      exit(1);
    }
    if (count($expectedSeeders) === 0) {
      fwrite(STDERR, "Mandatory database/seeders tree is empty in expected metadata\\n");
      exit(1);
    }

    // 3. Scan generated PHAR for all entries in database/migrations and database/seeders
    $actualMigrations = [];
    $actualSeeders = [];
    $iterator = new RecursiveIteratorIterator($phar);
    foreach ($iterator as $item) {
      if ($item->isDir()) {
        continue;
      }
      $rawPath = $item->getPathname();
      $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $rawPath);
      $subRel = '';
      $pharMarkerPos = stripos($normalized, '.phar/');
      if ($pharMarkerPos !== false) {
        $subRel = substr($normalized, $pharMarkerPos + strlen('.phar/'));
      } else {
        $subRel = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
      }
      $subRel = ltrim($subRel, '/');

      if (str_starts_with($subRel, 'database/migrations/')) {
        $actualMigrations[$subRel] = true;
      } elseif (str_starts_with($subRel, 'database/seeders/')) {
        $actualSeeders[$subRel] = true;
      }
    }

    // 4. Verify ZERO MISSING in both scopes
    $missingMigrations = [];
    foreach (array_keys($expectedMigrations) as $expMig) {
      if (!isset($actualMigrations[$expMig])) {
        $missingMigrations[] = $expMig;
      }
    }
    if (count($missingMigrations) > 0) {
      fwrite(STDERR, "Missing migration entries inside PHAR (" . count($missingMigrations) . "): " . implode(', ', $missingMigrations) . "\\n");
      exit(1);
    }

    $missingSeeders = [];
    foreach (array_keys($expectedSeeders) as $expSeed) {
      if (!isset($actualSeeders[$expSeed])) {
        $missingSeeders[] = $expSeed;
      }
    }
    if (count($missingSeeders) > 0) {
      fwrite(STDERR, "Missing seeder entries inside PHAR (" . count($missingSeeders) . "): " . implode(', ', $missingSeeders) . "\\n");
      exit(1);
    }

    // 5. Verify ZERO EXTRA in both scopes
    $extraMigrations = [];
    foreach (array_keys($actualMigrations) as $actMig) {
      if (!isset($expectedMigrations[$actMig])) {
        $extraMigrations[] = $actMig;
      }
    }
    if (count($extraMigrations) > 0) {
      fwrite(STDERR, "Extra migration entries inside PHAR (" . count($extraMigrations) . "): " . implode(', ', $extraMigrations) . "\\n");
      exit(1);
    }

    $extraSeeders = [];
    foreach (array_keys($actualSeeders) as $actSeed) {
      if (!isset($expectedSeeders[$actSeed])) {
        $extraSeeders[] = $actSeed;
      }
    }
    if (count($extraSeeders) > 0) {
      fwrite(STDERR, "Extra seeder entries inside PHAR (" . count($extraSeeders) . "): " . implode(', ', $extraSeeders) . "\\n");
      exit(1);
    }

    // 6. Verify exact byte/hash contents of each entry inside PHAR
    foreach ($expectedMigrations as $expMig => $meta) {
      if (!isset($phar[$expMig])) {
        fwrite(STDERR, "Entry {$expMig} not accessible via Phar ArrayAccess\\n");
        exit(1);
      }
      $bytes = $phar[$expMig]->getContent();
      if (strlen($bytes) !== (int)$meta['size']) {
        fwrite(STDERR, "Byte size mismatch for PHAR entry {$expMig}\\n");
        exit(1);
      }
      $entrySha = hash('sha256', $bytes);
      if (!hash_equals($meta['sha256'], $entrySha)) {
        fwrite(STDERR, "Hash mismatch for PHAR entry {$expMig}\\n");
        exit(1);
      }
    }

    foreach ($expectedSeeders as $expSeed => $meta) {
      if (!isset($phar[$expSeed])) {
        fwrite(STDERR, "Entry {$expSeed} not accessible via Phar ArrayAccess\\n");
        exit(1);
      }
      $bytes = $phar[$expSeed]->getContent();
      if (strlen($bytes) !== (int)$meta['size']) {
        fwrite(STDERR, "Byte size mismatch for PHAR entry {$expSeed}\\n");
        exit(1);
      }
      $entrySha = hash('sha256', $bytes);
      if (!hash_equals($meta['sha256'], $entrySha)) {
        fwrite(STDERR, "Hash mismatch for PHAR entry {$expSeed}\\n");
        exit(1);
      }
    }

    // 7. Verify rollback files specifically present
    $rollbackFiles = array_filter(array_keys($expectedMigrations), static function ($k) {
      return str_starts_with($k, 'database/migrations/rollback/');
    });
    if (count($rollbackFiles) === 0) {
      fwrite(STDERR, "Expected migrations rollback directory must contain files\\n");
      exit(1);
    }
    foreach ($rollbackFiles as $rollbackEntry) {
      if (!isset($actualMigrations[$rollbackEntry])) {
        fwrite(STDERR, "Missing rollback migration entry inside PHAR: {$rollbackEntry}\\n");
        exit(1);
      }
    }

    // 8. Report source-only counts (not private hashes)
    $migCount = count($expectedMigrations);
    $rollbackCount = count($rollbackFiles);
    $seedCount = count($expectedSeeders);
    echo "✔ Authoritative check passed: verified exact marker token, {$migCount} migration entries (including {$rollbackCount} rollback), and {$seedCount} seeder entries inside PHAR (zero missing, zero extra).\\n";
  `;
  execFileSync(php, ['-d', 'phar.readonly=0', '-r', verifyPharEntryPhp, '--', expectedDbMapFile], { stdio: 'inherit' });
  console.log(`      PASS: v14 backend.phar built (${copiedMigrationCount} migrations incl ${copiedRollbackCount} rollback, ${copiedSeederCount} seeders, marker verified).`);

  // Build genuine v14 frontend payload using supported cmd.exe interface on Windows
  console.log('      Building genuine v14 frontend payload...');
  const buildExecutable = process.platform === 'win32' ? (process.env.ComSpec || 'cmd.exe') : 'npm';
  const buildArgs = process.platform === 'win32' ? ['/d', '/s', '/c', 'npm', 'run', 'build'] : ['run', 'build'];
  execFileSync(buildExecutable, buildArgs, {
    cwd: path.join(freshSourceDir, 'frontend'),
    stdio: 'inherit',
    env: process.env
  });
  const v14DistDir = path.join(freshSourceDir, 'frontend/dist');
  assert.ok(fs.existsSync(path.join(v14DistDir, 'index.html')), 'v14 frontend/dist/index.html missing');

  // Stage delta files to be packaged into delta ZIP
  const filesToPackage = [
    { rel: 'version.json', full: path.join(freshSourceDir, 'version.json') },
    { rel: 'backend/backend.phar', full: v14PharPath },
  ];

  function scanDist(dir, baseRel) {
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, ent.name);
      const rel = `${baseRel}/${ent.name}`;
      if (ent.isDirectory()) {
        scanDist(full, rel);
      } else if (ent.isFile()) {
        if (ent.name !== 'digital-certificate.txt') {
          filesToPackage.push({ rel, full });
        }
      }
    }
  }
  scanDist(v14DistDir, 'frontend/dist');

  // Create delta zip using PHP ZipArchive
  const deltaZipPath = path.join(fixtureAssetsDir, `delta-0.0.12-to-${targetVersion}.zip`);
  const genericDeltaZipPath = path.join(fixtureAssetsDir, 'delta.zip');

  const zipScriptEntries = filesToPackage.map(
    f => `$zip->addFile('${f.full.replace(/\\/g, '/')}', '${f.rel.replace(/\\/g, '/')}');`
  ).join('\n');

  const createZipPhp = `
    $zip = new ZipArchive();
    if ($zip->open('${deltaZipPath.replace(/\\/g, '/')}', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      fwrite(STDERR, "Failed to create ZIP\\n");
      exit(1);
    }
    ${zipScriptEntries}
    $zip->close();
  `;
  execFileSync(php, ['-r', createZipPhp], { stdio: 'inherit' });
  assert.ok(fs.existsSync(deltaZipPath), 'Delta zip creation failed');
  fs.copyFileSync(deltaZipPath, genericDeltaZipPath);

  // Construct baseline files map
  const baselineFilesMap = {};
  for (const [rel, entry] of Object.entries(setup12Inventory.files)) {
    baselineFilesMap[rel] = entry.sha256;
  }

  // Classify add/replace accurately based on Setup 12 baseline inventory
  const manifestFiles = filesToPackage.map(f => {
    const bytes = fs.readFileSync(f.full);
    const relPath = f.rel.replace(/\\/g, '/');
    const action = Object.prototype.hasOwnProperty.call(setup12Inventory.files, relPath) ? 'replace' : 'add';
    return {
      path: relPath,
      action,
      sha256: sha256(bytes),
      size: bytes.length
    };
  });

  // Identify obsolete built frontend artifacts to remove
  const targetRelPaths = new Set(manifestFiles.map(f => f.path));
  const deletedFiles = [];
  for (const baselineRel of Object.keys(setup12Inventory.files)) {
    if (baselineRel.startsWith('frontend/dist/')) {
      const baseName = path.basename(baselineRel);
      if (baseName !== 'digital-certificate.txt' && !targetRelPaths.has(baselineRel)) {
        deletedFiles.push(baselineRel);
      }
    }
  }
  deletedFiles.sort();

  const manifestData = {
    manifest_version: '1.0',
    version: targetVersion,
    type: 'delta',
    minimum_version: setup12Version,
    from_version: setup12Version,
    baseline_files: baselineFilesMap,
    update_engine_version: '1.0.0',
    channel: 'stable',
    released_at: '2026-10-28',
    changelog: [
      'Automated future delta smoke test validating Setup 12 native runtime handoff',
    ],
    files: manifestFiles,
    deleted_files: deletedFiles,
    migrations: []
  };

  // Build expected full target inventory from Setup baseline + new/changed files - deletions
  const expectedTargetFiles = {};
  for (const [rel, entry] of Object.entries(setup12Inventory.files)) {
    expectedTargetFiles[rel] = { sha256: entry.sha256, size: entry.size };
  }
  for (const f of manifestFiles) {
    expectedTargetFiles[f.path] = { sha256: f.sha256, size: f.size };
  }
  for (const d of deletedFiles) {
    delete expectedTargetFiles[d];
  }

  const expectedTargetInventory = {
    format: 1,
    version: targetVersion,
    shell_sha256: setup12Inventory.shell_sha256,
    files: expectedTargetFiles
  };

  const manifestJson = JSON.stringify(manifestData, null, 2) + '\n';
  const canonicalManifestPath = path.join(fixtureAssetsDir, `manifest-${setup12Version}.json`);
  const genericManifestPath = path.join(fixtureAssetsDir, 'manifest.json');
  fs.writeFileSync(canonicalManifestPath, manifestJson, 'utf8');
  fs.writeFileSync(genericManifestPath, manifestJson, 'utf8');

  // Sign manifest using production ManifestSignatureService and UPDATE_PRIVATE_KEY
  const signingPhpScript = `
    require_once '${path.join(repoRoot, 'backend/vendor/autoload.php').replace(/\\/g, '/')}';
    $sigService = new App\\Services\\ManifestSignatureService();
    $privateKey = (string)getenv('UPDATE_PRIVATE_KEY');
    $manifestData = file_get_contents('${canonicalManifestPath.replace(/\\/g, '/')}');
    $sig = $sigService->signData($manifestData, $privateKey);
    file_put_contents('${path.join(fixtureAssetsDir, `manifest-${setup12Version}.sig`).replace(/\\/g, '/')}', $sig);
    file_put_contents('${path.join(fixtureAssetsDir, 'manifest.sig').replace(/\\/g, '/')}', $sig);

    $pubKeyPath = '${path.join(repoRoot, 'backend/certs/update_public_key.pem').replace(/\\/g, '/')}';
    $verified = $sigService->verifySignature($manifestData, $sig, $pubKeyPath);
    if (!$verified) {
      fwrite(STDERR, "CRITICAL: Manifest signature verification failed against update_public_key.pem!\\n");
      exit(1);
    }
    echo "✔ Cryptographic RSA signature generated and verified successfully.\\n";
  `;

  execFileSync(php, ['-r', signingPhpScript], {
    env: { ...process.env, UPDATE_PRIVATE_KEY: updatePrivateKey },
    stdio: 'inherit'
  });

  const sigPath = path.join(fixtureAssetsDir, 'manifest.sig');
  assert.ok(fs.existsSync(sigPath) && fs.statSync(sigPath).size > 0, 'Signature file missing or empty');
  console.log('      PASS: Target 0.0.14 delta payload and verified RSA signature created.');

  // 5. Setup mock transport router and PHP INI with auto_prepend_file hook
  console.log('[4/7] Preparing PHP INI and HTTP transport mock responses...');

  const fixtureTransportPhp = path.resolve('scripts/fixtures/channel-transition-transport.php').replace(/\\/g, '/');
  assert.ok(fs.existsSync(fixtureTransportPhp), `Fixture transport script missing: ${fixtureTransportPhp}`);
  fs.writeFileSync(path.join(phpIniDir, 'fixture-transport.ini'), `auto_prepend_file = "${fixtureTransportPhp}"\n`, 'utf8');

  const v14Release = {
    tag_name: 'v0.0.14',
    draft: false,
    prerelease: false,
    name: 'POS Future Delta Test v0.0.14',
    published_at: '2026-10-28T00:00:00Z',
    body: 'Future delta test release',
    assets: [
      {
        name: `manifest-${setup12Version}.json`,
        browser_download_url: `https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/manifest-${setup12Version}.json`,
        size: fs.statSync(canonicalManifestPath).size
      },
      {
        name: `manifest-${setup12Version}.sig`,
        browser_download_url: `https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/manifest-${setup12Version}.sig`,
        size: fs.statSync(sigPath).size
      },
      {
        name: `delta-${setup12Version}-to-${targetVersion}.zip`,
        browser_download_url: `https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/delta-${setup12Version}-to-${targetVersion}.zip`,
        size: fs.statSync(deltaZipPath).size
      },
      {
        name: 'manifest.json',
        browser_download_url: 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/manifest.json',
        size: fs.statSync(genericManifestPath).size
      },
      {
        name: 'manifest.sig',
        browser_download_url: 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/manifest.sig',
        size: fs.statSync(sigPath).size
      },
      {
        name: 'delta.zip',
        browser_download_url: 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/delta.zip',
        size: fs.statSync(genericDeltaZipPath).size
      }
    ]
  };

  const setup12Release = {
    tag_name: 'v0.0.12',
    draft: false,
    prerelease: false,
    name: 'POS Desktop Setup v0.0.12',
    published_at: '2026-10-27T00:00:00Z',
    body: 'Published setup release',
    assets: [{
      name: 'deployment-inventory.json',
      browser_download_url: 'https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.12/deployment-inventory.json',
      size: fs.statSync(inventoryPath).size
    }]
  };

  const notFoundSentinel = {
    http_code: 404,
    body: JSON.stringify({ message: 'Not Found', documentation_url: 'https://docs.github.com/rest' })
  };

  const transportPath = path.join(lab, 'transport.json');
  const transportData = {
    mode: 'delta',
    responses: {
      'https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases?per_page=100&page=1': [v14Release],
      'https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/tags/v0.0.14': v14Release,
      'https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/latest': v14Release,
      'https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/tags/v0.0.12': notFoundSentinel,

      'https://api.github.com/repos/ABDO-TECK/pos-desktop/releases?per_page=100&page=1': [setup12Release],
      'https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/tags/v0.0.12': setup12Release,
      'https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/tags/v0.0.14': notFoundSentinel
    },
    assets: {
      [`https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/manifest-${setup12Version}.json`]: canonicalManifestPath,
      [`https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/manifest-${setup12Version}.sig`]: sigPath,
      [`https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/delta-${setup12Version}-to-${targetVersion}.zip`]: deltaZipPath,
      'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/manifest.json': genericManifestPath,
      'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/manifest.sig': sigPath,
      'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/delta.zip': genericDeltaZipPath,
      'https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.12/deployment-inventory.json': inventoryPath
    },
    trace: path.join(evidence, 'transport-trace.jsonl')
  };
  fs.writeFileSync(transportPath, JSON.stringify(transportData, null, 2), 'utf8');

  const existingScan = process.env.PHP_INI_SCAN_DIR ? `;${process.env.PHP_INI_SCAN_DIR}` : '';
  const phpIniScanDir = `${phpIniDir}${existingScan}`;

  const extraEnv = {
    PHP_INI_SCAN_DIR: phpIniScanDir,
    POS_TRANSITION_FIXTURE: transportPath,
    APP_DEPLOY_ROOT: unpackedRoot
  };

  // SQL helper via channel-transition-capability.php (returns {schema_sha256, ledger, admin_digest, business_rows, settings})
  const capabilityFixture = path.resolve('scripts/fixtures/channel-transition-capability.php');
  const sql = mode => JSON.parse(execFileSync(php, [capabilityFixture, mode], { encoding: 'utf8', windowsHide: true }));

  // 6. Launch packaged Setup 12 desktop app via CDP
  console.log('[5/7] Launching packaged Setup 12 application...');
  ui = createDesktopLab(application, evidence, extraEnv);
  await ui.start();

  console.log('      Executing fresh administrator onboarding...');
  const auth = await ui.freshLogin();
  assert.ok(auth.email && auth.password, 'Fresh admin credentials must be established');

  // Mandatory bounded poll for LAN SSL key/cert generation
  console.log('      Waiting for LAN SSL server.key/crt generation across canonical targets...');
  const sslDeadline = Date.now() + 60000;
  while (Date.now() < sslDeadline) {
    const pair = findLanSslPair(posTargets);
    if (pair) {
      boundSslPair = pair;
      break;
    }
    await delay(1000);
  }
  assert.ok(boundSslPair, 'Mandatory LAN SSL server.key/crt generation timed out after 60s');
  assert.ok(path.isAbsolute(boundSslPair.key), 'LAN SSL server.key must be an absolute path: ' + boundSslPair.key);
  assert.ok(path.isAbsolute(boundSslPair.cert), 'LAN SSL server.crt must be an absolute path: ' + boundSslPair.cert);
  console.log(`      PASS: LAN SSL keypair detected at ${path.dirname(boundSslPair.key)}`);

  // Pure native snapshot taken AFTER onboarding
  const initialNativeSnapshot = snapshotImmutableNative(application);
  assert.ok(initialNativeSnapshot.size > 0, 'Initial immutable native snapshot must not be empty');
  console.log(`      PASS: Setup 12 onboarded (${initialNativeSnapshot.size} immutable native files snapshotted).`);

  // Seed business data before update
  console.log('      Seeding business data (SQL business_rows, settings, admin, ledger, schema)...');
  const sqlBefore = sql('seed');
  assert.ok(Array.isArray(sqlBefore.business_rows) && sqlBefore.business_rows.length > 0, 'Seeded business_rows must be non-empty');
  assert.ok(Array.isArray(sqlBefore.settings) && sqlBefore.settings.length > 0, 'Seeded settings must be non-empty');
  assert.ok(sqlBefore.business_rows.some(r => r.name === 'codex-transition-sentinel'), 'Business sentinel customer missing in business_rows');
  assert.ok(sqlBefore.settings.some(s => s.key === 'codex_transition_sentinel'), 'Business sentinel setting missing in settings');
  assert.ok(typeof sqlBefore.admin_digest === 'string' && sqlBefore.admin_digest.length > 0, 'Admin digest must be a non-empty string');
  assert.ok(Array.isArray(sqlBefore.ledger) && sqlBefore.ledger.length > 0, 'Schema migration ledger must be a non-empty array');
  assert.ok(typeof sqlBefore.schema_sha256 === 'string' && sqlBefore.schema_sha256.length > 0, 'Schema sha256 must be a non-empty string');

  // Seed IndexedDB offline sale record
  console.log('      Seeding IndexedDB offline sale record with logged-in actor...');
  const idbSeed = await pendingSale(ui, 'seed');
  assert.equal(idbSeed.count, 1, 'IndexedDB offline sale seeding count mismatch');
  assert.ok(idbSeed.record, 'IndexedDB offline sale record missing after seeding');
  assert.equal(idbSeed.record.localId, 'setup12-delta-pending');
  assert.equal(idbSeed.record.syncStatus, 'review', 'Seeded record must have syncStatus review');
  assert.ok(
    Number.isInteger(idbSeed.record.ownerUserId) && idbSeed.record.ownerUserId > 0,
    'Seeded record must have genuine positive integer ownerUserId'
  );
  assert.ok(
    Number.isInteger(idbSeed.record.branchId) && idbSeed.record.branchId > 0,
    'Seeded record must have genuine positive integer branchId'
  );
  const canonicalPreRecord = idbSeed.record;

  // Business sentinel file in storage
  const sentinelPath = path.join(storage, 'business_sentinel.txt');
  const sentinelData = 'SENTINEL_SETUP12_' + crypto.randomUUID();
  fs.writeFileSync(sentinelPath, sentinelData, 'utf8');

  // Capture protected system state baseline before delta update
  const systemStateBefore = await getSystemProtectedState(ui);
  assert.ok(systemStateBefore.public.ownedPrintingFilesCount >= 4, 'Owned printing trust files missing');
  console.log('      PASS: Private business state and protected system baselines captured.');

  // Attach transport router
  requestTransport = await attachTransitionTransport({
    ui,
    application,
    storage,
    evidence,
    fixture: transportPath,
    auth
  });

  // Track background jobs and handoff preparation
  requestTransport.onJobObservation(obs => {
    const id = Number(obs?.jobId || obs?.polledJobId);
    if (Number.isInteger(id) && id > 0) {
      if (boundJobId === null) {
        boundJobId = id;
      } else {
        assert.equal(id, boundJobId, 'Observed job ID must remain immutable and consistent across observations');
      }
    }
    if (obs?.status === 'completed' && obs?.result) {
      observedJob = obs;
      if (obs.result.ok === true) observedTerminalOk = true;
      if (obs.result.requires_desktop_handoff === true) {
        observedRequiresHandoff = true;
        observedHandoffVersion = obs.result.handoff_version;
      }
    }
  });

  requestTransport.beforeApply(async payload => {
    assert.ok(
      Number.isInteger(boundJobId) && boundJobId > 0,
      `boundJobId must be a positive integer before apply, got: ${boundJobId}`
    );
    const data = payload?.data || payload;
    const hookId = Number(data?.id || data?.job_id);
    assert.ok(
      Number.isInteger(hookId) && hookId > 0,
      `beforeApply hook job ID must be a positive integer, got: ${hookId}`
    );
    assert.equal(hookId, boundJobId, 'beforeApply hook job ID must match boundJobId');

    // Capture actual storage/update-jobs/job_{id}.json before native handoff
    const preJobFilePath = path.join(storage, 'update-jobs', `job_${boundJobId}.json`);
    assert.ok(fs.existsSync(preJobFilePath), `Pre-handoff job file missing on disk: ${preJobFilePath}`);
    const preDiskJob = JSON.parse(fs.readFileSync(preJobFilePath, 'utf8'));
    assert.equal(Number(preDiskJob.job_id || preDiskJob.id), boundJobId, 'Pre-handoff job file ID mismatch');
    assert.ok(typeof preDiskJob.operation_id === 'string' && preDiskJob.operation_id.length > 0, 'Pre-handoff job operation_id must be non-empty');
    assert.equal(preDiskJob.result?.ok, true, 'Pre-handoff job result.ok must be true');
    preHandoffOperationId = preDiskJob.operation_id;

    const stagingDir = path.join(storage, 'update_staging', targetVersion);
    const planPath = path.join(stagingDir, 'desktop-handoff.json');
    assert.ok(fs.existsSync(planPath), `Desktop handoff plan missing in staging: ${planPath}`);
    handoffPlanSnapshot = JSON.parse(fs.readFileSync(planPath, 'utf8'));

    // Bindings
    assert.equal(
      path.resolve(handoffPlanSnapshot.root_dir).toLowerCase(),
      path.resolve(unpackedRoot).toLowerCase(),
      'Plan root_dir must match unpackedRoot'
    );
    assert.equal(
      path.resolve(handoffPlanSnapshot.storage_dir).toLowerCase(),
      path.resolve(storage).toLowerCase(),
      'Plan storage_dir must match canonical storage'
    );
    assert.equal(
      path.resolve(handoffPlanSnapshot.staging_dir).toLowerCase(),
      path.resolve(stagingDir).toLowerCase(),
      'Plan staging_dir must match expected staging dir'
    );
    assert.equal(handoffPlanSnapshot.manifest?.from_version, setup12Version, 'Plan from_version must be 0.0.12');
    assert.equal(handoffPlanSnapshot.version, targetVersion, 'Plan version must be 0.0.14');
    assert.equal(handoffPlanSnapshot.manifest?.version, targetVersion, 'Plan manifest version must be 0.0.14');

    // Staged paths
    for (const f of handoffPlanSnapshot.manifest.files) {
      if ((f.action || 'replace') !== 'delete') {
        const stagedPath = path.join(handoffPlanSnapshot.staging_dir, f.path);
        assert.ok(fs.existsSync(stagedPath), `Staged file missing before apply: ${f.path}`);
        assert.equal(sha256File(stagedPath), f.sha256, `Staged file hash mismatch: ${f.path}`);
      }
    }

    // Parsed manifest equal to actual signed manifest bytes
    const signedManifestRaw = fs.readFileSync(canonicalManifestPath, 'utf8');
    const signedManifestParsed = JSON.parse(signedManifestRaw);
    assert.deepEqual(handoffPlanSnapshot.manifest, signedManifestParsed, 'Plan manifest must deeply equal parsed signed manifest');
  });

  // 7. Execute delta upgrade via Settings UI
  console.log('[6/7] Triggering delta upgrade to 0.0.14 via Settings UI...');
  await waitForApplySlot('update_apply');
  await ui.system();

  // Wait for update check button or automatic check completion
  await ui.until(
    'Boolean(document.querySelector("button") && [...document.querySelectorAll("button")].some(b => b.textContent.includes("التحقق من التحديثات") || b.textContent.includes("تثبيت التحديث")))',
    'update buttons rendered',
    60000
  );

  const hasInstallButton = await ui.evaluate(`[...document.querySelectorAll('button')].some(b => b.textContent.includes('تثبيت التحديث'))`);
  if (!hasInstallButton) {
    console.log('      Clicking check for updates button...');
    await ui.evaluate(`(() => {
      const b = [...document.querySelectorAll('button')].find(btn => btn.textContent.includes('التحقق من التحديثات'));
      if (b) b.click();
    })()`);
  }

  console.log('      Waiting for install update button...');
  await ui.until(
    `[...document.querySelectorAll('button')].some(b => b.textContent.includes('تثبيت التحديث'))`,
    'install update button visible',
    60000
  );

  const tStart = Date.now();
  const initialPageRunId = 'setup12_smoke_' + tStart;
  await ui.evaluate(`window.__pageRunId = ${JSON.stringify(initialPageRunId)}`);

  console.log('      Clicking install update button...');
  await ui.evaluate(`(() => {
    const b = [...document.querySelectorAll('button')].find(btn => btn.textContent.includes('تثبيت التحديث'));
    if (b) b.click();
  })()`);

  await delay(1000);
  const hasConfirm = await ui.evaluate(`[...document.querySelectorAll('button')].some(b => b.textContent.trim() === 'تأكيد')`);
  if (hasConfirm) {
    console.log('      Confirming update modal...');
    await ui.click('تأكيد');
  }

  // 8. Wait for background job settlement, native handoff reload, and version 0.0.14
  console.log('      Waiting for background job settlement, handoff reload, and version 0.0.14...');
  await versionIs(ui, auth, requestTransport, targetVersion, tStart, initialPageRunId);

  // Assert actual renderer reload occurred by verifying changed run ID
  const postRunId = await ui.evaluate('window.__pageRunId');
  const rendererReloadVerified = Boolean(postRunId !== initialPageRunId);
  assert.equal(rendererReloadVerified, true, 'Frontend renderer reload was not observed');
  console.log(`      PASS: Deployed version updated to ${targetVersion} and renderer reload genuinely verified.`);

  // After native completion: resume and reauth installed transport
  requestTransport.resume();
  await requestTransport.reauth();

  // Assert positive boundJobId was captured before apply
  assert.ok(boundJobId && boundJobId > 0, `Valid positive job ID must be captured before apply, got: ${boundJobId}`);

  // Query and reconcile real installed application job status via existing readJobStatus(jobId)
  console.log(`      Reading post-handoff job status for job ${boundJobId} from installed application...`);
  const postJobStatus = await requestTransport.readJobStatus(boundJobId);
  assert.equal(postJobStatus.backendSource, 'installed_application', 'Job status must be verified against installed application backend');
  const postJobData = postJobStatus.data || postJobStatus;
  assert.equal(Number(postJobData.id), boundJobId, 'Returned job ID must match bound job ID');
  assert.equal(postJobData.status, 'completed', 'Job status must be completed');
  assert.equal(postJobData.result?.ok, true, 'Job result.ok must be true');
  assert.equal(postJobData.result?.desktop_handoff_completed, true, 'Job result.desktop_handoff_completed must be true');
  assert.equal(postJobData.result?.requires_desktop_handoff, false, 'Job result.requires_desktop_handoff must be false');

  // Inspect actual backend job file on disk and compare operation_id
  const jobFilePath = path.join(storage, 'update-jobs', `job_${boundJobId}.json`);
  assert.ok(fs.existsSync(jobFilePath), `Backend job file missing on disk: ${jobFilePath}`);
  const postDiskJobData = JSON.parse(fs.readFileSync(jobFilePath, 'utf8'));
  assert.equal(Number(postDiskJobData.job_id || postDiskJobData.id), boundJobId, 'Disk job file ID mismatch');
  assert.equal(postDiskJobData.result?.ok, true, 'Disk job result.ok must be true');
  assert.equal(postDiskJobData.operation_id, preHandoffOperationId, 'Disk job operation_id must match pre-handoff operation_id');

  // Inspect actual native context file on disk
  const updateStatePath = path.join(storage, 'update-state.json');
  assert.ok(fs.existsSync(updateStatePath), `update-state.json missing on disk: ${updateStatePath}`);
  const diskUpdateState = JSON.parse(fs.readFileSync(updateStatePath, 'utf8'));
  assert.equal(diskUpdateState.state, 'completed', 'update-state.json state must be completed');
  assert.equal(diskUpdateState.operation, 'electron_delta_install', 'update-state.json operation mismatch');
  assert.equal(diskUpdateState.phase, 'completed', 'update-state.json phase mismatch');
  assert.equal(diskUpdateState.to_version, targetVersion, 'update-state.json to_version mismatch');
  assert.ok(
    typeof preHandoffOperationId === 'string' && preHandoffOperationId.length > 0,
    'preHandoffOperationId must be a non-empty string'
  );
  assert.equal(
    diskUpdateState.operation_id,
    preHandoffOperationId,
    'update-state.json operation_id must match pre-handoff operation_id'
  );

  // 9. Strict Preservation, Inventory, and Native Integrity Assertions
  console.log('[7/7] Verifying complete preservation and native integrity...');

  // A. Production HTTP background job settlement and handoff contract
  assert.ok(observedJob, 'Background job was not observed during update apply');
  assert.equal(observedTerminalOk, true, 'Observed background job must have terminal result.ok === true');
  assert.equal(observedRequiresHandoff, true, 'Observed background job must have requires_desktop_handoff === true');
  assert.equal(observedHandoffVersion, targetVersion, `Handoff version mismatch: expected ${targetVersion}, got ${observedHandoffVersion}`);

  // B. Bound desktop-handoff.json plan
  assert.ok(handoffPlanSnapshot, 'desktop-handoff.json plan was not captured before apply');
  assert.equal(handoffPlanSnapshot.version, targetVersion, 'Plan target version mismatch');
  assert.equal(handoffPlanSnapshot.manifest?.version, targetVersion, 'Plan manifest version mismatch');

  // C. Retained native shell 0.0.12
  const postExeHash = sha256File(installedExe);
  const postAsarHash = sha256File(path.join(application, 'resources/app.asar'));
  assert.equal(postExeHash, initialExeHash, 'POS Desktop.exe hash must not change during delta update');
  assert.equal(postAsarHash, initialAsarHash, 'resources/app.asar hash must not change during delta update');
  console.log('      PASS: Native shell 0.0.12 strictly retained (EXE and ASAR hashes unchanged).');

  // D. Genuine updated backend.phar
  const postPharHash = sha256File(path.join(unpackedRoot, 'backend/backend.phar'));
  assert.equal(postPharHash, v14PharHash, 'backend/backend.phar must match the newly built v14 PHAR');
  assert.notEqual(postPharHash, setup12PharHash, 'backend/backend.phar must be genuinely different from Setup 12');
  console.log('      PASS: backend/backend.phar genuinely updated to newly built v14 PHAR.');

  // E. Deployed version.json
  const postVersionMeta = JSON.parse(fs.readFileSync(installedVersionJson, 'utf8'));
  assert.equal(postVersionMeta.version, targetVersion, `version.json must be ${targetVersion}`);
  assert.equal(
    postVersionMeta.application_version,
    targetVersion,
    `version.json application_version must be ${targetVersion}`
  );
  assert.equal(
    postVersionMeta.installer_version,
    setup12Version,
    `version.json installer_version must remain ${setup12Version}`
  );

  // F. Full target deployment inventory verified against deployment-inventory.php rules
  const actualDeploymentFiles = getActualDeploymentFiles(unpackedRoot);
  const actualKeys = Object.keys(actualDeploymentFiles).sort();
  const expectedKeys = Object.keys(expectedTargetInventory.files).sort();
  assert.deepEqual(actualKeys, expectedKeys, 'Actual deployed file list must match expected deployment inventory keys exactly');

  for (const [relPath, expectedEntry] of Object.entries(expectedTargetInventory.files)) {
    const actualEntry = actualDeploymentFiles[relPath];
    assert.ok(actualEntry, `Deployment file missing: ${relPath}`);
    assert.equal(actualEntry.sha256, expectedEntry.sha256, `SHA256 mismatch for deployed file: ${relPath}`);
    assert.equal(actualEntry.size, expectedEntry.size, `Size mismatch for deployed file: ${relPath}`);
  }

  // Verify deleted obsolete frontend files are genuinely absent
  for (const deletedRel of deletedFiles) {
    const deletedFullPath = path.join(unpackedRoot, deletedRel);
    assert.equal(fs.existsSync(deletedFullPath), false, `Obsolete file should have been deleted: ${deletedRel}`);
  }

  // Verify unchanged schema and CA
  const schemaFile = path.join(unpackedRoot, 'database/pos_schema.sql');
  assert.ok(fs.existsSync(schemaFile), 'database/pos_schema.sql missing');
  assert.equal(sha256File(schemaFile), setup12Inventory.files['database/pos_schema.sql'].sha256, 'database/pos_schema.sql must be unchanged');

  const caCertFile = path.join(unpackedRoot, 'backend/certs/cacert.pem');
  assert.ok(fs.existsSync(caCertFile), 'backend/certs/cacert.pem missing');
  assert.equal(sha256File(caCertFile), setup12Inventory.files['backend/certs/cacert.pem'].sha256, 'backend/certs/cacert.pem must be unchanged');

  // Mandatory post-update public update key assertion
  assert.ok(
    fs.existsSync(updatePubKeyPath) && fs.statSync(updatePubKeyPath).size > 0,
    'Mandatory post-update update_public_key.pem missing'
  );
  assert.equal(sha256File(updatePubKeyPath), initialUpdatePubKeyHash, 'Mandatory update_public_key.pem hash modified');

  // Verify entire immutable native/portable file set
  const postNativeSnapshot = snapshotImmutableNative(application);
  assert.equal(postNativeSnapshot.size, initialNativeSnapshot.size, 'Immutable native file count mismatch');
  for (const [rel, initialEntry] of initialNativeSnapshot.entries()) {
    assert.ok(postNativeSnapshot.has(rel), `Immutable native file missing after update: ${rel}`);
    const postEntry = postNativeSnapshot.get(rel);
    assert.equal(postEntry.sha256, initialEntry.sha256, `Immutable native file modified: ${rel}`);
    assert.equal(postEntry.size, initialEntry.size, `Immutable native file size changed: ${rel}`);
  }
  console.log(`      PASS: Full target inventory verified (${expectedKeys.length} deployment files + ${initialNativeSnapshot.size} immutable native files).`);

  // G. SQL Business Data Preservation
  const sqlAfter = sql('inspect');
  assertSqlPreserved(sqlBefore, sqlAfter, 'post-delta');
  console.log('      PASS: SQL business_rows, settings, admin, ledger, and schema preserved.');

  // H. IndexedDB Offline Data Preservation
  const idbAfter = await pendingSale(ui, 'read', canonicalPreRecord.localId);
  assert.equal(idbAfter.count, 1, 'IndexedDB offline sale count mismatch after update');
  assert.deepEqual(idbAfter.record, canonicalPreRecord, 'IndexedDB offline sale record modified during update');
  console.log('      PASS: IndexedDB offline records preserved.');

  // I. Protected System State & Sentinel Preservation
  const postDeltaSslPair = findLanSslPair(posTargets);
  assert.ok(postDeltaSslPair, 'LAN SSL server.key/crt pair missing after delta update');
  assert.equal(postDeltaSslPair.key, boundSslPair.key, 'LAN SSL server.key path modified during delta update');
  assert.equal(postDeltaSslPair.cert, boundSslPair.cert, 'LAN SSL server.crt path modified during delta update');
  assert.ok(fs.statSync(postDeltaSslPair.key).size > 0, 'LAN SSL server.key must be non-empty after delta update');
  assert.ok(fs.statSync(postDeltaSslPair.cert).size > 0, 'LAN SSL server.crt must be non-empty after delta update');
  assertNoReparse(postDeltaSslPair.key);
  assertNoReparse(postDeltaSslPair.cert);

  const systemStateAfter = await getSystemProtectedState(ui);
  assertProtectedStatePreserved(systemStateBefore, systemStateAfter, 'post-delta');
  assert.ok(fs.existsSync(sentinelPath), 'Business sentinel file deleted during update');
  assert.equal(fs.readFileSync(sentinelPath, 'utf8'), sentinelData, 'Business sentinel file content modified');
  console.log('      PASS: Protected system state, credentials, LAN SSL, QZ trust, and sentinel preserved.');

  // J. Clean Update State on Disk
  const finalState = JSON.parse(fs.readFileSync(path.join(storage, 'update-state.json'), 'utf8'));
  assert.equal(finalState.state, 'completed', 'Final update state must be completed');
  assert.equal(finalState.operation, 'electron_delta_install', 'Final update operation must be electron_delta_install');
  assert.equal(finalState.to_version, targetVersion, `Final update to_version must be ${targetVersion}`);

  // 10. Prepare in-memory acceptance evidence object ONLY after all corresponding assertions succeed
  acceptanceEvidence = {
    sourceVersion: setup12Version,
    targetVersion,
    updateType: 'delta',
    nativeShellRetained: true,
    backendPharUpdated: true,
    fullTargetInventoryVerified: true,
    backgroundJobOkVerified: true,
    requiresDesktopHandoffVerified: true,
    desktopHandoffCompletedVerified: true,
    rendererReloadVerified: rendererReloadVerified,
    nativeServiceRestartMeasured: false,
    fullExeRelaunchMeasured: false,
    businessSqlRowsPreserved: true,
    settingsPreserved: true,
    schemaPreserved: true,
    ledgerPreserved: true,
    adminIdentityPreserved: true,
    credentialsPreserved: true,
    envPreserved: systemStateBefore.public.envFilesCount > 0 ? true : 'not_exercised',
    lanSslPreserved: true,
    qzTrustPreserved: true,
    deviceKeysPreserved: true,
    sentinelPreserved: true,
    offlineDbPreserved: true,
    timestamp: new Date().toISOString()
  };
  console.log('      PASS: In-memory acceptance evidence prepared (persisted after clean teardown).');

} catch (err) {
  primaryError = err;
  throw err;
} finally {
  console.log('Cleaning up desktop lab and test fixtures...');
  const cleanupErrors = [];
  try {
    if (requestTransport) await requestTransport.close();
  } catch (e) {
    cleanupErrors.push(e);
  }
  try {
    if (ui) await ui.stop();
  } catch (e) {
    cleanupErrors.push(e);
  }

  try {
    stopOwnedLabProcesses(application);
  } catch (e) {
    cleanupErrors.push(e);
  }

  const uninstaller = path.join(application, 'Uninstall POS Desktop.exe');
  const hasApp = fs.existsSync(path.join(application, 'POS Desktop.exe'));
  if (fs.existsSync(uninstaller)) {
    console.log('Uninstalling lab POS Desktop instance...');
    try {
      const uninstallScript = `
        $ErrorActionPreference = 'Stop'
        $proc = Start-Process -FilePath '${uninstaller.replace(/'/g, "''")}' -ArgumentList @('/S', '/currentuser', '_?=${application.replace(/'/g, "''")}') -WindowStyle Hidden -PassThru
        if (-not $proc.WaitForExit(60000)) { throw 'Uninstallation timed out after 60s' }
        if ($proc.ExitCode -ne 0) { throw "Uninstaller failed with exit code $($proc.ExitCode)" }
      `;
      execFileSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', uninstallScript], { stdio: 'inherit' });
    } catch (e) {
      cleanupErrors.push(e);
    }
  } else if (hasApp) {
    cleanupErrors.push(new Error(`Uninstaller missing at ${uninstaller}; cleanup cannot safely proceed.`));
  }

  try {
    stopOwnedLabProcesses(application);
  } catch (e) {
    cleanupErrors.push(e);
  }

  // Assert canonical 8 targets have no residual files after uninstallation
  const residualErrors = [];
  for (const target of posTargets) {
    if (fs.existsSync(target)) {
      const files = fs.readdirSync(target);
      if (files.length > 0) {
        residualErrors.push(`${target} (${files.join(', ')})`);
      }
    }
  }
  if (residualErrors.length > 0) {
    cleanupErrors.push(new Error(`Residual POS data detected in canonical targets after uninstallation: ${residualErrors.join('; ')}`));
  }

  if (cleanupErrors.length > 0) {
    console.error(`Cleanup encountered ${cleanupErrors.length} error(s):`, cleanupErrors.map(e => e?.message || String(e)));
    if (!primaryError) {
      throw new Error(`Cleanup failed: ${cleanupErrors.map(e => e?.message || String(e)).join('; ')}`);
    }
  } else if (!primaryError && acceptanceEvidence) {
    // Withhold/move final acceptance write until all assertions AND owned cleanup succeed
    acceptanceEvidence.cleanupSucceeded = true;
    fs.writeFileSync(
      path.join(evidence, 'setup12-delta-acceptance.json'),
      JSON.stringify(acceptanceEvidence, null, 2) + '\n',
      'utf8'
    );
    console.log('      PASS: Final acceptance evidence recorded after verified clean teardown.');
  }
}

console.log('\n===============================================================');
console.log(' SETUP 12 FUTURE DELTA UPGRADE SMOKE TEST PASSED SUCCESSFULLY!');
console.log('===============================================================');
