import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { classifyVerifiedPublicStagedCa } from './protected-certificate-classification.mjs';

export const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const [phase, appArg, labArg, evidenceArg, assetsArg] = process.argv.slice(2);
if (!phase || !appArg || !labArg || !evidenceArg) {
  console.error('Usage: node scripts/verify-setup12-regressions.mjs <contract|acceptance> <application> <lab> <evidence> [assets]');
  process.exit(1);
}

const application = path.resolve(appArg);
const lab = path.resolve(labArg);
const evidence = path.resolve(evidenceArg);
const assets = assetsArg ? path.resolve(assetsArg) : path.join(lab, 'assets');
const configDir = path.join(process.env.APPDATA, 'POS System');
const storageDir = path.join(process.env.PROGRAMDATA, 'POS System');
const root = path.join(application, 'resources/app.asar.unpacked');
const SENTINEL = 'codex_setup12_regression_sentinel';
const MUTATED_SENTINEL = 'codex_mutated_row_sentinel';

const posTargets = [
  path.join(process.env.PROGRAMDATA, 'POS System'),
  path.join(process.env.APPDATA, 'POS System'),
  path.join(process.env.APPDATA, 'POS Desktop'),
  path.join(process.env.APPDATA, 'pos-desktop'),
  path.join(process.env.LOCALAPPDATA, 'POS System'),
  path.join(process.env.LOCALAPPDATA, 'POS Desktop'),
  path.join(process.env.LOCALAPPDATA, 'pos-desktop'),
  path.join(process.env.LOCALAPPDATA, 'pos-desktop-updater'),
].map((p) => path.resolve(p));

const exactRegistryKeys = [
  'HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\com.abdoteck.pos',
  'HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\com.abdoteck.pos',
  'HKCU:\\Software\\com.abdoteck.pos',
  'HKLM:\\Software\\com.abdoteck.pos',
];

function assertNoSymlinks(targetPath) {
  let curr = path.resolve(targetPath);
  while (curr) {
    try {
      const stat = fs.lstatSync(curr);
      assert.equal(stat.isSymbolicLink(), false, `Reparse point or symlink rejected: ${curr}`);
    } catch (e) {
      if (e.code !== 'ENOENT') throw e;
    }
    const parent = path.dirname(curr);
    if (parent === curr) break;
    curr = parent;
  }
}

function assertOwnedRun() {
  assert.ok(process.env.RUNNER_TEMP, 'RUNNER_TEMP environment variable is required');
  assert.ok(process.env.GITHUB_RUN_ID, 'GITHUB_RUN_ID environment variable is required');
  assert.ok(process.env.GITHUB_RUN_ATTEMPT, 'GITHUB_RUN_ATTEMPT environment variable is required');

  const runnerTemp = path.resolve(process.env.RUNNER_TEMP);
  const norm = (p) => path.resolve(p).toLowerCase();

  const canonicalApp = path.join(runnerTemp, 'setup12-regression-lab', 'app');
  const canonicalLab = path.join(runnerTemp, 'setup12-regression-lab');
  const canonicalEvidence = path.join(runnerTemp, 'setup12-regression-evidence');
  const canonicalCandidateSource = path.join(runnerTemp, 'candidate-source');

  assert.equal(norm(application), norm(canonicalApp), `application must match canonical path: ${canonicalApp}`);
  assert.equal(norm(lab), norm(canonicalLab), `lab must match canonical path: ${canonicalLab}`);
  assert.equal(norm(evidence), norm(canonicalEvidence), `evidence must match canonical path: ${canonicalEvidence}`);

  const earlyReceiptPath = path.join(runnerTemp, 'setup12-ownership-receipt.json');
  assert.ok(fs.existsSync(earlyReceiptPath), `Early ownership receipt missing: ${earlyReceiptPath}`);
  const lateMarkerPath = path.join(lab, 'owned-lab.json');
  assert.ok(fs.existsSync(lateMarkerPath), `Late owned lab marker missing: ${lateMarkerPath}`);

  // Enforce ancestor/leaf reparse and symlink rejection before reading JSON
  assertNoSymlinks(earlyReceiptPath);
  assertNoSymlinks(lateMarkerPath);
  assertNoSymlinks(application);
  assertNoSymlinks(lab);
  assertNoSymlinks(evidence);
  assertNoSymlinks(canonicalCandidateSource);

  const early = JSON.parse(fs.readFileSync(earlyReceiptPath, 'utf8'));
  const late = JSON.parse(fs.readFileSync(lateMarkerPath, 'utf8'));

  assert.match(early.run_token || '', /^[0-9a-f]{32}$/, 'Early receipt run_token must be 32 hex chars');
  assert.equal(early.run_token, late.run_token, 'run_token mismatch between early receipt and late marker');

  assert.equal(String(early.github_run_id), String(process.env.GITHUB_RUN_ID), 'github_run_id mismatch in early receipt');
  assert.equal(String(late.github_run_id), String(process.env.GITHUB_RUN_ID), 'github_run_id mismatch in late marker');
  assert.equal(String(early.github_run_attempt), String(process.env.GITHUB_RUN_ATTEMPT), 'github_run_attempt mismatch in early receipt');
  assert.equal(String(late.github_run_attempt), String(process.env.GITHUB_RUN_ATTEMPT), 'github_run_attempt mismatch in late marker');

  assert.equal(early.fresh_prechecks_passed, true, 'Early receipt fresh_prechecks_passed must be true');
  assert.ok(!isNaN(Date.parse(early.created_at_utc || early.created_at || '')), 'Early receipt created_at_utc invalid');

  assert.equal(norm(early.application), norm(canonicalApp), 'Early receipt application mismatch');
  assert.equal(norm(late.application), norm(canonicalApp), 'Late marker application mismatch');
  assert.equal(norm(early.lab), norm(canonicalLab), 'Early receipt lab mismatch');
  assert.equal(norm(late.lab), norm(canonicalLab), 'Late marker lab mismatch');
  assert.equal(norm(early.evidence), norm(canonicalEvidence), 'Early receipt evidence mismatch');
  assert.equal(norm(late.evidence), norm(canonicalEvidence), 'Late marker evidence mismatch');

  assert.ok(typeof early.candidate_source === 'string' && early.candidate_source.length > 0, 'Early receipt candidate_source missing');
  assert.equal(norm(early.candidate_source), norm(canonicalCandidateSource), 'Early receipt candidate_source mismatch against canonical path');
  assertNoSymlinks(early.candidate_source);

  if (late.candidate_source) {
    assert.equal(norm(late.candidate_source), norm(canonicalCandidateSource), 'Late marker candidate_source mismatch');
    assertNoSymlinks(late.candidate_source);
  }

  if (late.ownership_receipt_path) {
    assert.equal(norm(late.ownership_receipt_path), norm(earlyReceiptPath), 'Arbitrary ownership_receipt_path rejected');
    assertNoSymlinks(late.ownership_receipt_path);
  }
  if (late.early_receipt_path) {
    assert.equal(norm(late.early_receipt_path), norm(earlyReceiptPath), 'early_receipt_path mismatch');
    assertNoSymlinks(late.early_receipt_path);
  }

  const sortedEarlyTargets = (early.pos_targets || []).map(norm).sort();
  const sortedCanonicalTargets = posTargets.map(norm).sort();
  assert.deepEqual(sortedEarlyTargets, sortedCanonicalTargets, 'pos_targets mismatch against canonical targets');

  const sortedEarlyKeys = (early.registry_keys || []).map((k) => k.toLowerCase()).sort();
  const sortedExpectedKeys = exactRegistryKeys.map((k) => k.toLowerCase()).sort();
  assert.deepEqual(sortedEarlyKeys, sortedExpectedKeys, 'registry_keys mismatch against exact registry keys');

  return { early, owned: late };
}

function hasher(filePath) {
  return crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
}

function findCarrierDescriptor(labDir, { fromVersion, targetVersion, channel, sourceInventorySha256, targetInventorySha256 }) {
  const searchDirs = [
    path.join(labDir, 'assets', 'pos-desktop'),
    path.join(labDir, 'assets', 'pos-desktop-updates'),
    path.join(labDir, 'assets'),
    labDir,
  ];
  for (const dir of searchDirs) {
    if (!fs.existsSync(dir)) continue;
    for (const file of fs.readdirSync(dir)) {
      if (file.startsWith('transition-') && file.endsWith('.json')) {
        try {
          const desc = JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'));
          if (desc.operation === 'channel_transition') {
            const matchesVersion = !targetVersion || desc.version === targetVersion;
            const matchesFromVersion = !fromVersion || desc.from_version === fromVersion;
            const matchesChannel = !channel || desc.channel === channel;
            const matchesSourceSha = !sourceInventorySha256 || desc.source_inventory_sha256 === sourceInventorySha256;
            const matchesTargetSha = !targetInventorySha256 || desc.target_inventory_sha256 === targetInventorySha256;
            if (matchesVersion && matchesFromVersion && matchesChannel && matchesSourceSha && matchesTargetSha) {
              return desc;
            }
          }
        } catch {}
      }
    }
  }
  return null;
}

async function observeMigrationSafetySnapshot(safetyDir, preExistingFiles, timeoutMs = 30000) {
  const deadline = Date.now() + timeoutMs;
  let sqlPath = null;
  let jsonPath = null;

  while (Date.now() < deadline) {
    if (fs.existsSync(safetyDir)) {
      const files = fs.readdirSync(safetyDir);
      for (const file of files) {
        if (preExistingFiles.has(file)) continue;
        if (file.endsWith('.sql.json')) {
          jsonPath = path.join(safetyDir, file);
        } else if (file.endsWith('.sql')) {
          sqlPath = path.join(safetyDir, file);
        }
      }
    }
    if (sqlPath && jsonPath) break;
    await delay(250);
  }

  assert.ok(sqlPath && fs.existsSync(sqlPath), 'Safety snapshot .sql must be created');
  assert.ok(jsonPath && fs.existsSync(jsonPath), 'Safety snapshot .sql.json must be created');

  const sqlFilename = path.basename(sqlPath);
  const jsonFilename = path.basename(jsonPath);

  assert.equal(jsonFilename, `${sqlFilename}.json`, 'Metadata filename must match <backup>.sql.json');

  const sqlMatch = sqlFilename.match(/^migration-safety-(.+)\.sql$/);
  assert.ok(sqlMatch, `SQL snapshot filename invalid: ${sqlFilename}`);
  const recoveryIdFromFilename = sqlMatch[1];

  const metadata = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
  assert.equal(metadata.recovery_id, recoveryIdFromFilename, 'Metadata recovery_id must match filename recovery_id');
  assert.equal(metadata.from_version, 'current', 'Snapshot from_version must be current');
  assert.equal(metadata.to_version, 'restore', 'Snapshot to_version must be restore');

  const createdAt = Date.parse(metadata.created_at);
  assert.ok(!isNaN(createdAt), 'Snapshot created_at must be a valid date');
  assert.ok(Date.now() - createdAt < 300000 && createdAt <= Date.now() + 5000, 'Snapshot timestamp must be fresh');

  const norm = (p) => path.resolve(p).toLowerCase();
  assert.equal(norm(metadata.backup_path), norm(sqlPath), 'Snapshot backup_path must match canonical SQL file path');

  const sqlBytes = fs.readFileSync(sqlPath);
  const calculatedSha = crypto.createHash('sha256').update(sqlBytes).digest('hex');
  assert.equal(metadata.sha256, calculatedSha, 'Snapshot metadata sha256 must match raw SQL bytes');
  assert.equal(metadata.size, sqlBytes.length, 'Snapshot metadata size must match raw SQL byte length');

  return { sqlPath, jsonPath, recoveryId: recoveryIdFromFilename, metadata };
}

function sanitizeReason(str) {
  if (typeof str !== 'string') return '';
  return str
    .replace(/(["']?(?:password|token|secret|key|private_key|auth|api_key)["']?\s*[:=]\s*["'])([^"'\n]+)(["'])/gi, '$1[REDACTED]$3')
    .replace(/-----BEGIN (?:RSA )?PRIVATE KEY-----[\s\S]+?-----END (?:RSA )?PRIVATE KEY-----/g, '[REDACTED PRIVATE KEY]')
    .replace(/bearer\s+[A-Za-z0-9_\-\.]+/gi, 'Bearer [REDACTED]')
    .replace(/p(?:assword)?=[^;&\s]+/gi, 'password=[REDACTED]');
}

function runPhpFixture(action, options = {}) {
  const php = process.env.PHP_BINARY || 'php';
  const fixtureScript = path.resolve('scripts/fixtures/setup12-regressions.php');
  assert.ok(fs.existsSync(fixtureScript), `Fixture script missing: ${fixtureScript}`);
  const optArgs = [`--action=${action}`];
  for (const [key, val] of Object.entries(options)) {
    if (val !== undefined && val !== null) {
      optArgs.push(`--${key}=${val}`);
    }
  }
  const stdout = execFileSync(php, [fixtureScript, ...optArgs], {
    encoding: 'utf8',
    windowsHide: true,
  });
  return JSON.parse(stdout);
}

function verifyInventoryFiles(targetInventory, baseInventory = null) {
  const files = targetInventory.files || {};
  assert.ok(Object.keys(files).length > 0, 'Target inventory files must not be empty');

  for (const [relPath, entry] of Object.entries(files)) {
    const full = path.join(root, relPath);
    assert.ok(fs.existsSync(full), `Expected inventory file missing: ${relPath}`);
    const actualHash = hasher(full);
    assert.equal(actualHash, entry.sha256, `Inventory SHA256 mismatch for ${relPath}`);
  }

  if (baseInventory && baseInventory.files) {
    const deleted = Object.keys(baseInventory.files).filter((k) => !files[k]);
    for (const relPath of deleted) {
      const full = path.join(root, relPath);
      assert.ok(!fs.existsSync(full), `Deleted inventory file must not exist on disk: ${relPath}`);
    }
  }
}

async function waitForQZReadiness(desktopLabUi) {
  const startTime = Date.now();
  const deadline = startTime + 180000;
  let triggeredIpc = false;

  while (true) {
    let foundQzDir = null;
    for (const target of posTargets) {
      const dir = path.join(target, 'qz-certs');
      const key = path.join(dir, 'private-key.pem');
      const cert = path.join(dir, 'digital-certificate.pem');
      if (fs.existsSync(key) && fs.existsSync(cert)) {
        try {
          if (fs.statSync(key).size > 0 && fs.statSync(cert).size > 0) {
            foundQzDir = dir;
            break;
          }
        } catch {}
      }
    }

    if (!foundQzDir && desktopLabUi && !triggeredIpc) {
      console.log('QZ cert pair not yet on disk; triggering printing IPC initialization...');
      triggeredIpc = true;
      await desktopLabUi.evaluate('window.electronAPI?.getQZCert ? window.electronAPI.getQZCert() : null').catch(() => null);
    }

    let flagStatus = 'missing';
    const flagSearchDirs = foundQzDir
      ? [foundQzDir, ...posTargets.map((t) => path.join(t, 'qz-certs'))]
      : posTargets.map((t) => path.join(t, 'qz-certs'));

    const checkedFlagPaths = new Set();
    for (const d of flagSearchDirs) {
      const f = path.join(d, 'ssl-installed.flag');
      if (checkedFlagPaths.has(f)) continue;
      checkedFlagPaths.add(f);
      if (fs.existsSync(f)) {
        let content = '';
        try {
          content = fs.readFileSync(f, 'utf8').trim();
        } catch {}
        if (content === 'skipped') {
          throw new Error('QZ silent SSL installation was skipped (flag: skipped)');
        }
        if (content === 'installed') {
          flagStatus = 'installed';
        } else if (flagStatus !== 'installed') {
          flagStatus = content ? 'unknown' : 'pending';
        }
      }
    }

    const rawReceipts = [];
    let validReceiptCount = 0;
    for (const target of posTargets) {
      const p = path.join(target, 'qz-shared-install.json');
      if (fs.existsSync(p)) {
        let isFile = false;
        try {
          isFile = fs.statSync(p).isFile();
        } catch {}
        if (isFile) {
          rawReceipts.push(p);
          try {
            const stat = fs.statSync(p);
            if (stat.size > 0) {
              const raw = fs.readFileSync(p, 'utf8');
              const parsed = JSON.parse(raw);
              if (parsed && typeof parsed === 'object' && typeof parsed.rootCertificateBase64 === 'string' && parsed.rootCertificateBase64.length > 0) {
                validReceiptCount++;
              }
            }
          } catch {
            // Transient JSON write in progress; do not count as valid yet
          }
        }
      }
    }

    if (rawReceipts.length > 1) {
      throw new Error(`Duplicate QZ ownership receipts detected across POS data targets (count=${rawReceipts.length})`);
    }

    if (foundQzDir && flagStatus === 'installed' && rawReceipts.length === 1 && validReceiptCount === 1) {
      return foundQzDir;
    }

    if (Date.now() >= deadline) {
      throw new Error(
        `Timed out waiting for QZ readiness (pairFound=${Boolean(foundQzDir)}, receiptCount=${rawReceipts.length}, validReceipts=${validReceiptCount}, flagStatus=${flagStatus}, elapsedMs=${Date.now() - startTime})`
      );
    }

    await delay(500);
  }
}

async function getSystemProtectedState(desktopLabUi) {
  // 1. Mandatory credentials: look across the 8 POS targets
  const credFiles = [];
  for (const target of posTargets) {
    const p = path.join(target, 'db_credentials.json');
    if (fs.existsSync(p) && fs.statSync(p).isFile() && fs.statSync(p).size > 0) {
      credFiles.push(p);
    }
  }
  assert.ok(credFiles.length > 0, 'Mandatory credentials file missing or empty in POS data targets');

  // 2. Environment files (.env): look across the 8 POS targets
  const envFiles = [];
  for (const target of posTargets) {
    const p = path.join(target, '.env');
    if (fs.existsSync(p) && fs.statSync(p).isFile() && fs.statSync(p).size > 0) {
      envFiles.push(p);
    }
  }

  // 3. Mandatory runtime QZ certificate pair and production printing trust readiness
  const foundQzDir = await waitForQZReadiness(desktopLabUi);
  assert.ok(foundQzDir, 'Mandatory runtime QZ private-key.pem and digital-certificate.pem pair missing after printing initialization');
  const qzKeyPath = path.join(foundQzDir, 'private-key.pem');
  const qzCertPath = path.join(foundQzDir, 'digital-certificate.pem');

  // Discover all device key/cert files across the 8 targets
  const deviceKeyMap = {
    [qzKeyPath]: hasher(qzKeyPath),
    [qzCertPath]: hasher(qzCertPath),
  };

  const activeTrustedInventories = [
    JSON.parse(fs.readFileSync(path.join(lab, 'candidate13-setup-inventory.json'), 'utf8')),
    JSON.parse(fs.readFileSync(path.join(lab, 'candidate13-delta-inventory.json'), 'utf8')),
    JSON.parse(fs.readFileSync(path.join(lab, 'beta12-inventory.json'), 'utf8')),
  ];

  for (const target of posTargets) {
    if (!fs.existsSync(target)) continue;
    function scanKeys(dir) {
      for (const item of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, item.name);
        if (item.isDirectory()) {
          scanKeys(full);
        } else if (item.isFile()) {
          const ext = path.extname(item.name).toLowerCase();
          if (ext === '.key' || ext === '.crt' || ext === '.pem') {
            const resolved = path.resolve(full);
            if (!deviceKeyMap[resolved] && fs.statSync(resolved).size > 0) {
              const publicClassification = (target === posTargets[0])
                ? classifyVerifiedPublicStagedCa(resolved, posTargets[0], activeTrustedInventories)
                : null;
              if (!publicClassification) {
                deviceKeyMap[resolved] = hasher(resolved);
              }
            }
          }
        }
      }
    }
    scanKeys(target);
  }
  assert.ok(Object.keys(deviceKeyMap).length >= 2, 'At least 2 device key/certificate files must be discovered');

  // 4. Snapshot printing ownership receipt (discovered across the 8 POS targets)
  const receipts = [];
  for (const target of posTargets) {
    const p = path.join(target, 'qz-shared-install.json');
    if (fs.existsSync(p) && fs.statSync(p).isFile()) {
      assert.ok(fs.statSync(p).size > 0, 'QZ ownership receipt file is empty: ' + p);
      receipts.push(p);
    }
  }
  assert.equal(receipts.length, 1, `Original baseline did not record ownership of QZ printing trust in POS data targets (found ${receipts.length})`);
  const receiptPath = path.resolve(receipts[0]);
  const receiptDigest = hasher(receiptPath);
  const ownership = JSON.parse(fs.readFileSync(receiptPath, 'utf8'));

  assert.ok(typeof ownership.rootCertificateBase64 === 'string' && ownership.rootCertificateBase64.length > 0, 'Ownership receipt missing rootCertificateBase64');
  const certDer = Buffer.from(ownership.rootCertificateBase64, 'base64');
  assert.ok(certDer.length > 0, 'Root certificate base64 decoded to zero bytes');
  const printingThumbprint = crypto.createHash('sha1').update(certDer).digest('hex').toUpperCase();
  assert.equal(printingThumbprint.length, 40, 'Computed certificate thumbprint must be 40 hex characters');

  // Verify printing root certificate is installed in Enterprise store
  const enterpriseKeyPath = `Registry::HKEY_LOCAL_MACHINE\\SOFTWARE\\Microsoft\\EnterpriseCertificates\\Root\\Certificates\\${printingThumbprint}`;
  const enterpriseCheck = execFileSync('powershell.exe', [
    '-NoProfile', '-NonInteractive', '-Command',
    `if (Test-Path -LiteralPath '${enterpriseKeyPath}') { 'True' } else { 'False' }`,
  ], { encoding: 'utf8', windowsHide: true }).trim();
  assert.equal(enterpriseCheck, 'True', `Printing root certificate was not installed in Enterprise trust store: ${enterpriseKeyPath}`);

  // 5. Optional LAN SSL keypair capture: look across the 8 POS targets
  let lanSslEnabled = false;
  const sslFiles = [];
  for (const target of posTargets) {
    const key = path.join(target, 'ssl', 'server.key');
    const cert = path.join(target, 'ssl', 'server.crt');
    if (fs.existsSync(key) && fs.existsSync(cert) && fs.statSync(key).size > 0 && fs.statSync(cert).size > 0) {
      lanSslEnabled = true;
      sslFiles.push(key, cert);
      break;
    }
  }

  // Keep all secret hashes in-memory only (never exported to evidence or logs)
  return {
    credentials: Object.fromEntries(credFiles.map((f) => [f, hasher(f)])),
    env: envFiles.length > 0 ? Object.fromEntries(envFiles.map((f) => [f, hasher(f)])) : 'not_exercised',
    deviceKeys: deviceKeyMap,
    qzReceipt: {
      [receiptPath]: receiptDigest,
    },
    sslCerts: sslFiles.length > 0 ? Object.fromEntries(sslFiles.map((f) => [f, hasher(f)])) : 'not_exercised',
    lanSslEnabled,
    printingThumbprint,
  };
}

function assertProtectedStatePreserved(before, after, stageName) {
  for (const [credPath, credHash] of Object.entries(before.credentials)) {
    assert.ok(fs.existsSync(credPath), `Protected credentials missing after ${stageName}: ${credPath}`);
    assert.equal(after.credentials[credPath], credHash, `Protected credentials altered during ${stageName}: ${credPath}`);
  }
  if (before.env === 'not_exercised') {
    assert.equal(after.env, 'not_exercised', `Protected .env was not exercised before ${stageName}`);
  } else {
    for (const [envPath, envHash] of Object.entries(before.env)) {
      assert.ok(fs.existsSync(envPath), `Protected .env missing after ${stageName}: ${envPath}`);
      assert.equal(after.env[envPath], envHash, `Protected .env altered during ${stageName}: ${envPath}`);
    }
  }
  for (const [keyPath, keyHash] of Object.entries(before.deviceKeys)) {
    assert.ok(fs.existsSync(keyPath), `Protected device key/cert missing after ${stageName}: ${keyPath}`);
    assert.equal(after.deviceKeys[keyPath], keyHash, `Protected device key/cert altered during ${stageName}: ${keyPath}`);
  }
  for (const [qzReceipt, qzHash] of Object.entries(before.qzReceipt)) {
    assert.ok(fs.existsSync(qzReceipt), `Protected QZ ownership receipt missing after ${stageName}: ${qzReceipt}`);
    assert.equal(after.qzReceipt[qzReceipt], qzHash, `Protected QZ ownership receipt altered during ${stageName}: ${qzReceipt}`);
  }
  if (before.sslCerts === 'not_exercised') {
    assert.equal(after.sslCerts, 'not_exercised', `Protected LAN SSL cert was not exercised before ${stageName}`);
  } else if (before.lanSslEnabled) {
    for (const [sslPath, sslHash] of Object.entries(before.sslCerts)) {
      assert.ok(fs.existsSync(sslPath), `Protected LAN SSL cert missing after ${stageName}: ${sslPath}`);
      assert.equal(after.sslCerts[sslPath], sslHash, `Protected LAN SSL cert altered during ${stageName}: ${sslPath}`);
    }
  }
  assert.equal(after.printingThumbprint, before.printingThumbprint, `QZ printing thumbprint altered during ${stageName}`);
}

async function pendingSale(ui, action) {
  return ui.evaluate(`(async () => {
    let ownerUserId = 1, branchId = 1;
    try {
      const auth = JSON.parse(localStorage.getItem('pos_auth') || '{}');
      if (auth?.state?.user?.id) ownerUserId = Number(auth.state.user.id);
      if (auth?.state?.user?.branch_id) branchId = Number(auth.state.user.branch_id);
    } catch {}
    if (!ownerUserId || ownerUserId === 9999999) {
      try {
        const me = await (await fetch('/api/v1/auth/me', { credentials: 'include' })).json();
        if (me?.data?.id) ownerUserId = Number(me.data.id);
        if (me?.data?.branch_id) branchId = Number(me.data.branch_id);
      } catch {}
    }
    return new Promise((resolve, reject) => {
      const request = indexedDB.open('pos_offline');
      request.onerror = () => reject(new Error('Offline DB unavailable'));
      request.onsuccess = () => {
        const db = request.result;
        if (!db.objectStoreNames.contains('pending_sales')) {
          db.close();
          resolve(null);
          return;
        }
        const tx = db.transaction('pending_sales', ${JSON.stringify(action === 'put' ? 'readwrite' : 'readonly')});
        const store = tx.objectStore('pending_sales');
        const op = ${action === 'put' ? `store.put({localId: 'codex-transition-pending', ownerUserId, branchId, savedAt: '2026-10-21T00:00:00Z', syncStatus: 'review', fixture: true})` : `store.get('codex-transition-pending')`};
        op.onsuccess = () => {
          if (${JSON.stringify(action)} === 'put') {
            try { window.dispatchEvent(new Event('offline-sales-updated')); } catch {}
            resolve(true);
          } else {
            resolve(op.result);
          }
        };
        op.onerror = () => reject(new Error('Offline sale fixture failed'));
        tx.oncomplete = () => db.close();
      };
    });
  })()`);
}

function createRegressionLab(appDir, evidenceDir, extraEnv = {}) {
  assert.equal(process.env.GITHUB_ACTIONS, 'true', 'Desktop lab requires ephemeral CI');
  const temporary = fs.realpathSync(process.env.RUNNER_TEMP).toLowerCase() + path.sep;
  assert.ok(fs.realpathSync(appDir).toLowerCase().startsWith(temporary), 'Application must be isolated');
  fs.mkdirSync(evidenceDir, { recursive: true });

  let child;
  let rendererWs;
  let mainWs;
  let rendererSeq = 0;
  let mainSeq = 0;
  const rendererPending = new Map();
  const mainPending = new Map();
  const listeners = new Set();

  async function callRenderer(method, params = {}) {
    const id = ++rendererSeq;
    let timer;
    const response = new Promise((resolve, reject) => {
      timer = setTimeout(() => {
        rendererPending.delete(id);
        reject(new Error('UI command timed out: ' + method));
      }, 30000);
      rendererPending.set(id, resolve);
    });
    rendererWs.send(JSON.stringify({ id, method, params }));
    try {
      const reply = await response;
      assert.ok(!reply.error && !reply.result?.exceptionDetails, 'UI command failed: ' + JSON.stringify(reply.error || reply.result?.exceptionDetails || method));
      return reply.result;
    } finally {
      clearTimeout(timer);
    }
  }

  async function evaluate(expression) {
    return (await callRenderer('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result?.value;
  }

  async function until(expression, label, timeout = 90000) {
    const deadline = Date.now() + timeout;
    do {
      try {
        if (await evaluate(expression)) return;
      } catch (err) {
        if (!/Execution context was destroyed|Cannot find context|Inspected target navigated/i.test(err?.message || '')) {
          throw err;
        }
      }
      await delay(250);
    } while (Date.now() < deadline);
    throw new Error('Timed out waiting for: ' + label);
  }

  async function evaluateMain(expression) {
    assert.ok(mainWs && mainWs.readyState === WebSocket.OPEN, 'Main inspector not connected');
    const id = ++mainSeq;
    let timer;
    const response = new Promise((resolve, reject) => {
      timer = setTimeout(() => {
        mainPending.delete(id);
        reject(new Error('Main inspector evaluate timed out: ' + expression));
      }, 30000);
      mainPending.set(id, resolve);
    });
    mainWs.send(JSON.stringify({
      id,
      method: 'Runtime.evaluate',
      params: { expression, awaitPromise: true, returnByValue: true },
    }));
    try {
      const reply = await response;
      if (reply.error || reply.result?.exceptionDetails) {
        throw new Error('Main inspector command failed: ' + JSON.stringify(reply.error || reply.result?.exceptionDetails));
      }
      return reply.result?.result?.value;
    } finally {
      clearTimeout(timer);
    }
  }

  async function mockOpenDialog(filePath) {
    return await evaluateMain(`(() => {
      const electron = (typeof require !== 'undefined' ? require('electron') : (process.mainModule ? process.mainModule.require('electron') : null));
      if (!electron) throw new Error('Cannot acquire electron module in main process');
      global.__mockRestoreFilePath = ${JSON.stringify(filePath)};
      if (!global.__dialogMockInstalled) {
        global.__dialogMockInstalled = true;
        electron.dialog.showOpenDialog = async (win, opts) => {
          if (global.__mockRestoreFilePath) {
            return { canceled: false, filePaths: [global.__mockRestoreFilePath] };
          }
          return { canceled: true, filePaths: [] };
        };
      }
      return true;
    })()`);
  }

  async function start(options = {}) {
    const { enableMainInspector = true } = options;
    const env = { ...process.env, ...extraEnv };
    for (const key of ['GH_TOKEN', 'GITHUB_TOKEN', 'UPDATE_PRIVATE_KEY']) {
      delete env[key];
    }
    const output = fs.openSync(path.join(evidenceDir, 'desktop.log'), 'a');
    const spawnArgs = ['--remote-debugging-port=19231'];
    if (enableMainInspector) {
      spawnArgs.push('--inspect=19232');
    }
    child = spawn(path.join(appDir, 'POS Desktop.exe'), spawnArgs, {
      cwd: appDir,
      env,
      windowsHide: true,
      stdio: ['ignore', output, output],
    });
    fs.closeSync(output);

    // 1. Connect renderer WebSocket
    const deadline = Date.now() + 240000;
    let target;
    do {
      if (child.exitCode !== null) throw new Error('Desktop exited prematurely during startup');
      try {
        const res = await fetch('http://127.0.0.1:19231/json/list');
        if (res.ok) {
          const list = await res.json();
          target = list.find((t) => t.type === 'page' && t.url.startsWith('app://pos-app/'));
        }
      } catch {}
      if (target) break;
      await delay(500);
    } while (Date.now() < deadline);

    assert.ok(target, 'Isolated desktop renderer page missing on port 19231');
    rendererWs = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {
      rendererWs.onopen = resolve;
      rendererWs.onerror = reject;
    });

    rendererWs.onmessage = (event) => {
      const reply = JSON.parse(event.data);
      const resolve = rendererPending.get(reply.id);
      if (resolve) {
        rendererPending.delete(reply.id);
        resolve(reply);
      }
      if (reply.method) {
        for (const listener of listeners) listener(reply.method, reply.params);
      }
    };

    listeners.add((method, params) => {
      if (method === 'Runtime.consoleAPICalled') {
        const text = (params.args || []).map((a) => a.value ?? a.description ?? JSON.stringify(a)).join(' ');
        console.log(`[Renderer Console ${params.type}] ${text}`);
      } else if (method === 'Runtime.exceptionThrown') {
        const desc = params.exceptionDetails?.exception?.description || params.exceptionDetails?.text || 'unknown';
        console.error(`[Renderer Exception] ${desc}`);
      }
    });

    await callRenderer('Runtime.enable');
    await callRenderer('Console.enable').catch(() => {});

    // 2. Connect main process inspector if enabled (Hard requirement: MUST fail if unavailable)
    if (enableMainInspector) {
      const mainDeadline = Date.now() + 45000;
      let mainTarget;
      do {
        try {
          const res = await fetch('http://127.0.0.1:19232/json/list');
          if (res.ok) {
            const list = await res.json();
            mainTarget = list.find((t) => t.type === 'node') || list[0];
          }
        } catch {}
        if (mainTarget?.webSocketDebuggerUrl) break;
        await delay(300);
      } while (Date.now() < mainDeadline);

      if (!mainTarget?.webSocketDebuggerUrl) {
        throw new Error('Port 19232 connection timed out; main process inspector is strictly required.');
      }

      mainWs = new WebSocket(mainTarget.webSocketDebuggerUrl);
      await new Promise((resolve, reject) => {
        mainWs.onopen = resolve;
        mainWs.onerror = reject;
      });
      mainWs.onmessage = (event) => {
        const reply = JSON.parse(event.data);
        const resolve = mainPending.get(reply.id);
        if (resolve) {
          mainPending.delete(reply.id);
          resolve(reply);
        }
      };
      console.log('[Main Inspector] Connected successfully to port 19232.');
    }

    await until('Boolean(window.electronAPI && document.querySelector("form, a[href=\'/settings\']"))', 'initial page', 240000);
  }

  async function stop() {
    if (rendererWs?.readyState === WebSocket.OPEN) {
      rendererWs.send(JSON.stringify({ id: ++rendererSeq, method: 'Browser.close' }));
      rendererWs.close();
    }
    if (mainWs?.readyState === WebSocket.OPEN) {
      mainWs.close();
    }
    if (child && child.exitCode === null) {
      await Promise.race([new Promise((resolve) => child.once('exit', resolve)), delay(35000)]);
    }
    if (child && child.exitCode === null) {
      child.kill('SIGKILL');
      await delay(2000);
    }
    assert.ok(!child || child.exitCode !== null, 'Isolated desktop did not stop cleanly');
    await delay(2000);
  }

  async function click(text) {
    await until(`[...document.querySelectorAll('button')].some(b=>b.textContent.trim()===${JSON.stringify(text)})`, 'button ' + text);
    await evaluate(`(() => {const b=[...document.querySelectorAll('button')].find(b=>b.textContent.trim()===${JSON.stringify(text)});b.scrollIntoView({block:'center'});b.click();})()`);
  }

  async function fill(selector, value) {
    assert.equal(await evaluate(`(() => {const e=document.querySelector(${JSON.stringify(selector)});if(!e)return false;Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set.call(e,${JSON.stringify(value)});e.dispatchEvent(new Event('input',{bubbles:true}));return true;})()`), true);
    await delay(100);
  }

  async function login(email, password) {
    await until('Boolean(document.querySelector("input[type=email]"))', 'login form');
    await fill('input[type=email]', email);
    await fill('input[type=password]', password);
    await evaluate('document.querySelector("form button[type=submit]").click()');
  }

  async function freshLogin() {
    const initial = await evaluate('window.electronAPI.setup.getInitialAdmin()');
    assert.ok(initial?.password, 'Fresh temporary admin credentials missing');
    await until('Boolean(document.querySelector("[aria-labelledby=initial-admin-title]"))', 'temporary password notice');
    await click('فهمت');
    await login(initial.email, initial.password);
    const password = 'Lab-' + crypto.randomBytes(22).toString('base64url');
    await until('Boolean(document.querySelector("#forced-current-password"))', 'forced password change');
    await fill('#forced-current-password', initial.password);
    await fill('#forced-new-password', password);
    await fill('#forced-confirm-password', password);
    await evaluate('document.querySelector("#forced-current-password").closest("form").querySelector("button[type=submit]").click()');
    await until('!document.querySelector("#forced-current-password") && Boolean(document.querySelector("input[type=email]"))', 'reauthentication');
    await login(initial.email, password);
    await until('Boolean(document.querySelector("a[href=\'/settings\']"))', 'authenticated navigation');
    return { email: initial.email, password };
  }

  async function system() {
    await until('Boolean(document.querySelector("a[href=\'/settings\']"))', 'settings link');
    await evaluate('document.querySelector("a[href=\'/settings\']").click()');
    await until('location.pathname==="/settings"', 'settings route');
    await click('النظام والصيانة');
  }

  async function screenshot(name) {
    await evaluate('document.querySelector("[data-testid=channel-transition]")?.scrollIntoView({block:"center"})');
    const capture = await callRenderer('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(path.join(evidenceDir, name + '.png'), Buffer.from(capture.data, 'base64'));
  }

  return {
    start,
    stop,
    evaluate,
    evaluateMain,
    mockOpenDialog,
    until,
    click,
    fill,
    login,
    freshLogin,
    system,
    screenshot,
    callRenderer,
    onEvent: (listener) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
  };
}

async function chooseChannel(ui, channel) {
  await ui.until('Boolean(document.querySelector("select option[value=beta]"))', 'channel selector', 30000);
  await ui.evaluate(`(() => {
    const select = [...document.querySelectorAll('select')].find(s => s.querySelector('option[value=beta]'));
    if (!select) throw new Error('Channel selector select element not found');
    Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set.call(select, ${JSON.stringify(channel)});
    select.dispatchEvent(new Event('change', {bubbles: true}));
  })()`);
  await delay(1500);
}

async function preview(ui) {
  await ui.until('Boolean(document.querySelector("[data-testid=preview-channel-transition]:not(:disabled)"))', 'enabled transition preview', 30000);
  await ui.evaluate('document.querySelector("[data-testid=preview-channel-transition]").click()');
  await delay(500);
  await ui.until(
    'document.querySelector("[data-testid=channel-transition]")?.getAttribute("aria-busy")==="false" && Boolean(document.querySelector("[data-testid=apply-channel-transition], [data-testid=channel-transition] [role=alert], p[style*=\'color: var(--text-muted)\']"))',
    'transition preview settlement',
    60000
  );
}

const applyHistory = {
  transition_apply: [],
};

async function waitForApplySlot(limiter = 'transition_apply') {
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

async function versionIs(ui, auth, expectedVersion, startedAt = 0, expectedRunId = null) {
  const deadline = Date.now() + 180000;
  do {
    try {
      const vFile = path.join(root, 'version.json');
      if (fs.existsSync(vFile)) {
        const cur = JSON.parse(fs.readFileSync(vFile, 'utf8')).version;
        if (cur === expectedVersion) {
          if (expectedRunId) {
            await ui.until(
              `window.__pageRunId !== ${JSON.stringify(expectedRunId)} && Boolean(document.querySelector("form, a[href='/settings']"))`,
              'restarted frontend after update',
              120000
            );
          } else {
            await delay(5000);
            await ui.until("Boolean(document.querySelector(\"form, a[href='/settings']\"))", 'restarted frontend', 120000);
          }
          if (await ui.evaluate('Boolean(document.querySelector("input[type=email]"))')) {
            await ui.login(auth.email, auth.password);
          }
          await ui.until("Boolean(document.querySelector(\"a[href='/settings']\"))", 'authenticated after update restart', 60000);
          return;
        }
      }
    } catch {}

    if (startedAt > 0) {
      try {
        const stateFile = path.join(storageDir, 'update-state.json');
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

    const toast = await ui.evaluate(`(() => {
      const nodes = [...document.querySelectorAll('[role="alert"], [role="status"], .toast-error, div[style*="animation"]')];
      for (const n of nodes) {
        const text = n.innerText?.trim() || n.textContent?.trim() || '';
        if (!text) continue;
        if (/فشل|خطأ|error|rejected|update_in_progress|in progress|مسبق/i.test(text) && !/تم استكمال التحديث|إعادة تشغيل الخدمات|التحقق من التحديثات|جاري/i.test(text)) {
          return text;
        }
      }
      return null;
    })()`).catch(() => null);
    if (toast) throw new Error('Frontend reported error during update: ' + toast);

    await delay(1000);
  } while (Date.now() < deadline);
  throw new Error(`Timed out waiting for version ${expectedVersion}`);
}

// ═══════════════════════════════════════════════════════════════════════════
// PHASE: CONTRACT
// ═══════════════════════════════════════════════════════════════════════════
if (phase === 'contract') {
  console.log('=== Executing Contract Phase on Published Setup 12 ===');
  assertOwnedRun();
  const ui = createRegressionLab(application, evidence);
  try {
    await ui.start({ enableMainInspector: false });
    const auth = await ui.freshLogin();
    fs.writeFileSync(path.join(lab, 'fixture-auth.json'), JSON.stringify(auth), { mode: 0o600 });
    console.log('[Contract] Authenticated and saved fixture credentials.');

    // Seed database sentinel
    console.log(`[Contract] Seeding customer and setting sentinel '${SENTINEL}'...`);
    const seedRes = runPhpFixture('seed-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
    });
    assert.equal(seedRes.ok, true, 'seed-db failed');
    assert.ok(seedRes.customer_id > 0, 'Customer ID must be positive');
    console.log(`[Contract] Seeded customer #${seedRes.customer_id} with sentinel.`);

    // Capture SQL compatibility contract
    console.log('[Contract] Capturing MariaDB schema and ledger compatibility contract...');
    const contractPath = path.join(lab, 'data-contract.json');
    const contractRes = runPhpFixture('capture-contract', {
      'config-dir': configDir,
      output: contractPath,
    });
    assert.equal(contractRes.ok, true, 'capture-contract failed');
    assert.ok(fs.existsSync(contractPath), 'Contract file missing after capture');
    console.log(`[Contract] Captured contract successfully. Schema SHA256: ${contractRes.contract.schema_sha256}`);

    // Capture baseline database inspection
    const baselineDb = runPhpFixture('inspect-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
    });
    fs.writeFileSync(path.join(lab, 'baseline-db.json'), JSON.stringify(baselineDb, null, 2), 'utf8');

    // Capture baseline system protected state
    const protectedState = await getSystemProtectedState(ui);
    fs.writeFileSync(path.join(lab, 'baseline-system-protected.json'), JSON.stringify(protectedState, null, 2), 'utf8');
  } finally {
    await ui.stop();
  }
  console.log('=== Contract Phase Completed Successfully ===');
  process.exit(0);
}

// ═══════════════════════════════════════════════════════════════════════════
// PHASE: ACCEPTANCE
// ═══════════════════════════════════════════════════════════════════════════
assert.equal(phase, 'acceptance', `Unknown phase: ${phase}`);
console.log('=== Executing Acceptance Phase for Setup 12 Regressions ===');

// Security assertion: UPDATE_PRIVATE_KEY must NOT exist during acceptance phase
assert.ok(!process.env.UPDATE_PRIVATE_KEY, 'Security violation: UPDATE_PRIVATE_KEY must not exist during acceptance phase');

const { owned } = assertOwnedRun();

const contractPath = path.join(lab, 'data-contract.json');
assert.ok(fs.existsSync(contractPath), `Data contract missing: ${contractPath}`);
const contract = JSON.parse(fs.readFileSync(contractPath, 'utf8'));

const baselineDbPath = path.join(lab, 'baseline-db.json');
assert.ok(fs.existsSync(baselineDbPath), `Baseline DB missing: ${baselineDbPath}`);
const baselineDb = JSON.parse(fs.readFileSync(baselineDbPath, 'utf8'));

const baselineProtectedPath = path.join(lab, 'baseline-system-protected.json');
assert.ok(fs.existsSync(baselineProtectedPath), `Baseline protected state missing: ${baselineProtectedPath}`);
const baselineProtected = JSON.parse(fs.readFileSync(baselineProtectedPath, 'utf8'));

const authPath = path.join(lab, 'fixture-auth.json');
assert.ok(fs.existsSync(authPath), `Fixture auth missing: ${authPath}`);
const auth = JSON.parse(fs.readFileSync(authPath, 'utf8'));

const setup12Inv = JSON.parse(fs.readFileSync(path.join(lab, 'setup12-inventory.json'), 'utf8'));
const delta12Inv = JSON.parse(fs.readFileSync(path.join(lab, 'delta12-inventory.json'), 'utf8'));
const beta12Inv = JSON.parse(fs.readFileSync(path.join(lab, 'beta12-inventory.json'), 'utf8'));
const candidate13SetupInv = JSON.parse(fs.readFileSync(path.join(lab, 'candidate13-setup-inventory.json'), 'utf8'));
const candidate13DeltaInv = JSON.parse(fs.readFileSync(path.join(lab, 'candidate13-delta-inventory.json'), 'utf8'));

const candidate13SetupInvHash = hasher(path.join(lab, 'candidate13-setup-inventory.json'));
const candidate13DeltaInvHash = hasher(path.join(lab, 'candidate13-delta-inventory.json'));
const beta12InvHash = hasher(path.join(lab, 'beta12-inventory.json'));

// Prepare PHP INI scan dir for mock transport router
const phpIniDir = path.join(lab, 'php-ini');
fs.mkdirSync(phpIniDir, { recursive: true });
const fixtureTransportPhp = path.resolve('scripts/fixtures/channel-transition-transport.php').replace(/\\/g, '/');
assert.ok(fs.existsSync(fixtureTransportPhp), `Fixture transport script missing: ${fixtureTransportPhp}`);
fs.writeFileSync(path.join(phpIniDir, 'fixture-transport.ini'), `auto_prepend_file = "${fixtureTransportPhp}"\n`, 'utf8');

// Candidate results structure for runner verification (written to candidate-results.json ONLY)
const results = {
  run_token: owned.run_token,
  timestamp: new Date().toISOString(),
  verifications: {
    bug1_setup12_source_inventory_mismatch_reproduced: false,
    candidate13_silent_upgrade: false,
    candidate13_shell_root_preserved: false,
    bug1_candidate13_stable_to_beta_transition: false,
    bug1_candidate13_beta_to_stable_transition: false,
    repeated_channel_roundtrip_verified: false,
    bug2_desktop_sql_restore_success: false,
    bug2_desktop_sql_restore_rollback_and_error_classification: false,
    database_integrity_verified: false,
    session_preservation_on_error_verified: false,
    indexeddb_offline_preserved: false,
    protected_state_preserved: false,
  },
  details: {},
};

let offlineBefore = null;

try {
  // ── TEST 1: Reproduce Bug 1 on Published Setup 12 ──────────────────────────
  console.log('\n[Test 1] Reproducing Bug 1 (source_inventory_mismatch) on published Setup 12 baseline...');
  const reproFixturePath = path.join(lab, 'provider-repro.json');
  runPhpFixture('generate-provider-fixture', {
    mode: 'reproduce-setup12',
    'lab-dir': lab,
    'evidence-dir': evidence,
    output: reproFixturePath,
  });
  assert.ok(fs.existsSync(reproFixturePath), 'Reproduction provider fixture missing');

  const reproLab = createRegressionLab(application, evidence, {
    PHP_INI_SCAN_DIR: `${phpIniDir};${process.env.PHP_INI_SCAN_DIR || ''}`,
    POS_TRANSITION_FIXTURE: reproFixturePath,
    APP_DEPLOY_ROOT: root,
  });

  try {
    await reproLab.start({ enableMainInspector: true });
    if (await reproLab.evaluate('Boolean(document.querySelector("input[type=email]"))')) {
      await reproLab.login(auth.email, auth.password);
    }

    // Baseline Setup 12 SQL restore behavior test
    console.log('[Test 1 Baseline Restore] Testing Setup 12 baseline backup & restore behavior...');
    const setup12Sql = await reproLab.evaluate(`(async () => {
      const base = (await window.posRuntime?.getApiBaseUrl?.()) || '';
      const res = await fetch(base + '/api/v1/backup', { credentials: 'include' });
      if (!res.ok) throw new Error('Backup download failed: ' + res.status);
      return await res.text();
    })()`);
    assert.ok(setup12Sql.length > 50, 'Setup 12 backup too small');
    const setup12BackupPath = path.join(lab, 'setup12-backup-baseline.sql');
    fs.writeFileSync(setup12BackupPath, setup12Sql, 'utf8');

    // Mutate sentinel to test Setup 12 restore attempt
    const mutateRes = runPhpFixture('mutate-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
      'mutated-name': MUTATED_SENTINEL,
    });
    assert.equal(mutateRes.ok, true, 'mutate-db failed for Test 1');
    assert.equal(mutateRes.affected, 1, 'mutate-db must affect exactly 1 customer row');
    const dbMutated = runPhpFixture('inspect-db', { 'config-dir': configDir, sentinel: MUTATED_SENTINEL });
    assert.equal(dbMutated.customer?.name, MUTATED_SENTINEL, 'Mutated sentinel must exist before restore attempt');

    await reproLab.mockOpenDialog(setup12BackupPath);
    let setup12ContextLost = false;
    let setup12RestoreOutcome;
    try {
      setup12RestoreOutcome = await reproLab.evaluate('window.electronAPI.backup.restore()');
    } catch (err) {
      setup12ContextLost = /destroyed|cannot find context|navigated|target closed|session closed|protocol error/i.test(err?.message || '');
      setup12RestoreOutcome = { error: true };
    }

    const dbAfterRestore = runPhpFixture('inspect-db', { 'config-dir': configDir, sentinel: SENTINEL });
    const dbSuccess = dbAfterRestore.customer?.name === SENTINEL;
    const outcomeClass = dbSuccess ? 'SUCCESS' : (setup12ContextLost ? 'UNKNOWN_CONTEXT_LOST' : 'FAILURE');
    results.details.setup12_baseline_restore = {
      outcome: outcomeClass,
      db_restored: dbSuccess,
      failed: !dbSuccess,
    };
    console.log('[Test 1 Baseline Restore] Setup 12 restore attempt recorded:', results.details.setup12_baseline_restore.outcome);

    if (!dbSuccess) {
      const dbMutCheck = runPhpFixture('inspect-db', { 'config-dir': configDir, sentinel: MUTATED_SENTINEL });
      assert.equal(dbMutCheck.customer?.name, MUTATED_SENTINEL, 'Mutated sentinel must be present if restore failed');
      const revertRes = runPhpFixture('mutate-db', {
        'config-dir': configDir,
        sentinel: MUTATED_SENTINEL,
        'mutated-name': SENTINEL,
      });
      assert.equal(revertRes.ok, true, 'Revert to sentinel failed');
      assert.equal(revertRes.affected, 1, 'Revert must affect exactly 1 row');
      const dbReverted = runPhpFixture('inspect-db', { 'config-dir': configDir, sentinel: SENTINEL });
      assert.equal(dbReverted.customer?.name, SENTINEL, 'Database must be restored to baseline sentinel before Test 1 preview');
    }

    if (await reproLab.evaluate('Boolean(document.querySelector("input[type=email]"))')) {
      await reproLab.login(auth.email, auth.password);
    }

    // Seed actual actor-scoped review IndexedDB record AFTER baseline Setup 12 restore/reauth and BEFORE candidate 13 NSIS
    console.log('[Test 1 IndexedDB] Seeding actor-scoped offline sale fixture into IndexedDB pos_offline...');
    let stored = false;
    for (let i = 0; i < 20 && !stored; i++) {
      stored = await pendingSale(reproLab, 'put');
      if (!stored) await delay(500);
    }
    assert.equal(stored, true, 'Offline fixture store unavailable');
    offlineBefore = await pendingSale(reproLab, 'get');
    assert.ok(offlineBefore?.localId === 'codex-transition-pending', 'Seeded offline sale fixture record missing');
    console.log('[Test 1 IndexedDB] Seeded offline pending sale fixture successfully.');

    await reproLab.system();
    await chooseChannel(reproLab, 'beta');
    await preview(reproLab);

    // Check preview result from the API directly
    const previewData = await reproLab.evaluate(`(async () => {
      const base = (await window.posRuntime?.getApiBaseUrl?.()) || '';
      const csrfRes = await (await fetch(base + '/api/v1/csrf-cookie', { credentials: 'include' })).json();
      const token = csrfRes?.data?.csrf_token;
      const res = await fetch(base + '/api/v1/updates/transition/preview', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token || '' }
      });
      return await res.json();
    })()`);

    console.log('[Test 1] Setup 12 Preview Status:', previewData?.data?.status, 'Reason:', previewData?.data?.reason_code);
    const previewResult = previewData?.data || {};

    // Strict separate assertions: API reason_code AND UI error message independently asserted
    assert.equal(previewResult.status, 'blocked', 'Setup 12 preview must be blocked');
    assert.equal(previewResult.reason_code, 'source_inventory_mismatch', 'Setup 12 must fail with source_inventory_mismatch');

    const uiErrorText = await reproLab.evaluate('document.querySelector("[data-testid=channel-transition] [role=alert], [data-testid=transition-preview-result]")?.innerText || ""');
    assert.ok(uiErrorText.length > 0, 'UI error container must not be empty');
    assert.ok(
      uiErrorText.includes('ملفات الإصدار المثبّت تختلف عن النسخة المعتمدة'),
      'UI must display Arabic mismatch notice: ملفات الإصدار المثبّت تختلف عن النسخة المعتمدة'
    );
    await reproLab.screenshot('bug1-setup12-source-inventory-mismatch');

    results.verifications.bug1_setup12_source_inventory_mismatch_reproduced = true;
    results.details.bug1_reproduction = {
      installed_version: '0.0.12',
      target_channel: 'beta',
      preview_status: previewResult.status,
      reason_code: previewResult.reason_code,
      ui_error_displayed: true,
    };
    console.log('PASS [Test 1] Bug 1 reproduced: Setup 12 correctly blocked with source_inventory_mismatch.');
  } finally {
    await reproLab.stop();
  }

  // ── TEST 2: Silent Upgrade to Candidate 13 ─────────────────────────────────
  console.log('\n[Test 2] Silently upgrading published Setup 12 to Candidate 13...');
  const candidateInstaller = owned.candidate_installer;
  assert.ok(fs.existsSync(candidateInstaller), `Candidate installer missing: ${candidateInstaller}`);

  const upgradeProc = spawn(candidateInstaller, ['/S', '/currentuser', `/D=${application}`], {
    windowsHide: true,
    stdio: 'ignore',
  });
  await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Silent candidate 13 upgrade timed out after 180s')), 180000);
    upgradeProc.on('exit', (code) => {
      clearTimeout(timer);
      if (code === 0) resolve();
      else reject(new Error(`Candidate 13 silent upgrade failed with exit code: ${code}`));
    });
  });

  // Verify Candidate 13 version and files
  const candidateVersion = JSON.parse(fs.readFileSync(path.join(root, 'version.json'), 'utf8')).version;
  assert.equal(candidateVersion, '0.0.13', 'Version after upgrade must be 0.0.13');
  verifyInventoryFiles(candidate13SetupInv, setup12Inv);

  // Shell Root Binding Assertion: verify installed shell executable and ASAR match candidate build
  const installedExePath = path.join(application, 'POS Desktop.exe');
  const installedAsarPath = path.join(application, 'resources/app.asar');
  assert.equal(hasher(installedExePath), owned.candidate_exe_sha256, 'Installed POS Desktop.exe does not match candidate shell root');
  assert.equal(hasher(installedAsarPath), owned.candidate_asar_sha256, 'Installed app.asar does not match candidate shell root');

  const roundtripFixturePath = path.join(lab, 'provider-roundtrip.json');
  runPhpFixture('generate-provider-fixture', {
    mode: 'candidate13-roundtrip',
    'lab-dir': lab,
    'evidence-dir': evidence,
    output: roundtripFixturePath,
  });
  assert.ok(fs.existsSync(roundtripFixturePath), 'Roundtrip provider fixture missing');

  const transitionLab = createRegressionLab(application, evidence, {
    PHP_INI_SCAN_DIR: `${phpIniDir};${process.env.PHP_INI_SCAN_DIR || ''}`,
    POS_TRANSITION_FIXTURE: roundtripFixturePath,
    APP_DEPLOY_ROOT: root,
  });

  try {
    await transitionLab.start({ enableMainInspector: true });
    if (await transitionLab.evaluate('Boolean(document.querySelector("input[type=email]"))')) {
      await transitionLab.login(auth.email, auth.password);
    }
    await transitionLab.until('Boolean(document.querySelector("a[href=\'/settings\']"))', 'authenticated navigation', 60000);

    const runningAppVersion = await transitionLab.evaluate('window.electronAPI?.getVersion ? window.electronAPI.getVersion() : null');
    assert.equal(runningAppVersion, '0.0.13', 'Running native Candidate 13 application must report version 0.0.13');

    // Verify database sentinel row and admin digest preserved
    const dbAfterUpgrade = runPhpFixture('inspect-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
    });
    assert.equal(dbAfterUpgrade.ok, true, 'inspect-db after upgrade failed');
    assert.equal(dbAfterUpgrade.customer?.name, SENTINEL, 'Sentinel customer record lost during upgrade');
    assert.equal(dbAfterUpgrade.setting?.value, baselineDb.setting?.value, 'Sentinel setting lost during upgrade');
    assert.equal(dbAfterUpgrade.admin_digest, baselineDb.admin_digest, 'Admin credentials altered during upgrade');
    assert.deepEqual(dbAfterUpgrade.ledger_rows, baselineDb.ledger_rows, 'Full raw schema_versions rows altered during silent upgrade');

    // Verify system protected state
    const protectedAfterUpgrade = await getSystemProtectedState(transitionLab);
    assertProtectedStatePreserved(baselineProtected, protectedAfterUpgrade, 'Candidate 13 Upgrade');

    // Verify IndexedDB pending sale preserved across NSIS silent upgrade
    assert.deepEqual(await pendingSale(transitionLab, 'get'), offlineBefore, 'Offline sale preserved after Candidate 13 silent upgrade');

    results.verifications.candidate13_silent_upgrade = true;
    results.verifications.candidate13_shell_root_preserved = true;
    results.details.silent_upgrade = {
      from_version: '0.0.12',
      to_version: '0.0.13',
      schema_matches_contract: dbAfterUpgrade.schema_sha256 === contract.schema_sha256,
      ledger_count: dbAfterUpgrade.ledger_count,
    };
    console.log('PASS [Test 2] Candidate 13 silent upgrade verified. Files, shell root, and database preserved.');

    // ── TEST 3: Bidirectional Channel Transition on Candidate 13 ───────────────
    console.log('\n[Test 3] Testing Bidirectional Channel Transitions on Candidate 13 (Bug 1 Fix)...');
    await transitionLab.system();

    async function executeTransition({
      stepName,
      channel,
      expectedFromVersion,
      expectedTargetVersion,
      expectedFromChannel,
      expectedSourceInvHash,
      expectedTargetInvHash,
      targetInv,
      baseInv,
      screenshotName,
    }) {
      if (await transitionLab.evaluate('Boolean(document.querySelector("input[type=email]"))')) {
        await transitionLab.login(auth.email, auth.password);
      }
      await transitionLab.system();
      await chooseChannel(transitionLab, channel);
      await preview(transitionLab);

      const previewData = await transitionLab.evaluate(`(async () => {
        const base = (await window.posRuntime?.getApiBaseUrl?.()) || '';
        const csrfRes = await (await fetch(base + '/api/v1/csrf-cookie', { credentials: 'include' })).json();
        const token = csrfRes?.data?.csrf_token;
        const res = await fetch(base + '/api/v1/updates/transition/preview', {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token || '' }
        });
        return await res.json();
      })()`);
      console.log(`[${stepName}] Preview Status:`, previewData?.data?.status, 'Reason:', previewData?.data?.reason_code);
      assert.equal(previewData?.data?.status, 'ready', `[${stepName}] Transition preview must be ready`);
      if (expectedFromVersion) {
        assert.equal(previewData?.data?.installed?.version, expectedFromVersion, `[${stepName}] Installed version mismatch in preview`);
      }
      if (expectedFromChannel) {
        assert.equal(previewData?.data?.installed?.channel, expectedFromChannel, `[${stepName}] Installed channel mismatch in preview`);
      }
      assert.equal(previewData?.data?.target?.version, expectedTargetVersion, `[${stepName}] Target version mismatch in preview`);
      assert.equal(previewData?.data?.target?.channel, channel, `[${stepName}] Target channel mismatch in preview`);
      if (expectedSourceInvHash) {
        if (expectedFromVersion === '0.0.13') {
          assert.equal(previewData?.data?.installed?.inventory_sha256, expectedSourceInvHash, `[${stepName}] Source inventory hash mismatch in preview`);
        } else if (previewData?.data?.installed?.inventory_sha256 !== undefined) {
          assert.equal(previewData?.data?.installed?.inventory_sha256, expectedSourceInvHash, `[${stepName}] Source inventory hash mismatch in preview`);
        }
      }
      if (expectedTargetInvHash) {
        assert.equal(previewData?.data?.target?.inventory_sha256, expectedTargetInvHash, `[${stepName}] Target inventory hash mismatch in preview`);
      }

      // Verify carrier descriptor against expectations and raw inventory files
      const carrierDesc = findCarrierDescriptor(lab, {
        fromVersion: expectedFromVersion,
        targetVersion: expectedTargetVersion,
        channel,
        sourceInventorySha256: expectedSourceInvHash,
        targetInventorySha256: expectedTargetInvHash,
      });
      assert.ok(carrierDesc, `[${stepName}] Carrier descriptor must exist for transition to ${expectedTargetVersion}`);
      assert.equal(carrierDesc.version, expectedTargetVersion, `[${stepName}] Carrier descriptor version mismatch`);
      if (expectedFromVersion) {
        assert.equal(carrierDesc.from_version, expectedFromVersion, `[${stepName}] Carrier descriptor from_version mismatch`);
      }
      if (channel) {
        assert.equal(carrierDesc.channel, channel, `[${stepName}] Carrier descriptor channel mismatch`);
      }
      if (expectedSourceInvHash) {
        assert.equal(carrierDesc.source_inventory_sha256, expectedSourceInvHash, `[${stepName}] Carrier source_inventory_sha256 mismatch`);
      }
      if (expectedTargetInvHash) {
        assert.equal(carrierDesc.target_inventory_sha256, expectedTargetInvHash, `[${stepName}] Carrier target_inventory_sha256 mismatch`);
      }
      if (baseInv) {
        assert.deepEqual(carrierDesc.source_inventory?.files, baseInv.files, `[${stepName}] Carrier source_inventory.files mismatch against baseInv.files`);
      }
      if (targetInv) {
        assert.deepEqual(carrierDesc.target_inventory?.files, targetInv.files, `[${stepName}] Carrier target_inventory.files mismatch against targetInv.files`);
      }

      // Verify raw inventory files
      if (baseInv && expectedFromVersion) {
        assert.equal(baseInv.version, expectedFromVersion, `[${stepName}] Base inventory version mismatch`);
      }
      if (targetInv) {
        assert.equal(targetInv.version, expectedTargetVersion, `[${stepName}] Target inventory version mismatch`);
      }

      const receiptsDir = path.join(storageDir, 'channel-transition-receipts');
      fs.mkdirSync(receiptsDir, { recursive: true });
      const preExistingReceipts = new Set(
        fs.readdirSync(receiptsDir).filter((f) => f.endsWith('.json') && !f.endsWith('.plan.json')).map((f) => f.slice(0, -5))
      );

      const updateJobsDir = path.join(storageDir, 'update-jobs');
      fs.mkdirSync(updateJobsDir, { recursive: true });
      const preExistingJobs = new Set(fs.readdirSync(updateJobsDir));

      await waitForApplySlot('transition_apply');
      const transitionAt = Date.now();
      const transitionRunId = 'trans_' + transitionAt;
      await transitionLab.evaluate(`window.__pageRunId = ${JSON.stringify(transitionRunId)}`);
      await transitionLab.until('Boolean(document.querySelector("[data-testid=apply-channel-transition]"))', 'apply button visible');
      await transitionLab.evaluate('document.querySelector("[data-testid=apply-channel-transition]").click()');
      await transitionLab.click('تأكيد');
      await versionIs(transitionLab, auth, expectedTargetVersion, transitionAt, transitionRunId);

      const receiptDeadline = Date.now() + 30000;
      let receipt;
      let boundReceiptId;
      do {
        const state = JSON.parse(fs.readFileSync(path.join(storageDir, 'update-state.json'), 'utf8'));
        if (state.transition_receipt && !preExistingReceipts.has(state.transition_receipt)) {
          boundReceiptId = state.transition_receipt;
          try {
            receipt = JSON.parse(fs.readFileSync(path.join(receiptsDir, state.transition_receipt + '.json'), 'utf8'));
          } catch {}
          if (receipt?.state === 'completed') break;
          if (receipt?.state === 'failed') throw new Error(`[${stepName}] Transition receipt failed: ${receipt?.reason_code || 'unknown'}`);
        }
        await delay(250);
      } while (Date.now() < receiptDeadline);

      assert.ok(boundReceiptId && !preExistingReceipts.has(boundReceiptId), `[${stepName}] Transition must produce a new receipt ID`);
      assert.equal(receipt?.state, 'completed', `[${stepName}] Transition receipt never completed`);
      assert.equal(receipt.receipt_id, boundReceiptId, `[${stepName}] Receipt ID mismatch`);
      assert.equal(receipt.target_inventory_matches, true, `[${stepName}] Receipt must confirm target_inventory_matches`);
      assert.equal(receipt.data_compatible, true, `[${stepName}] Receipt must confirm data_compatible`);

      const receiptTs = Date.parse(receipt.updated_at || receipt.created_at || '');
      assert.ok(!isNaN(receiptTs) && receiptTs >= transitionAt - 10000, `[${stepName}] Receipt timestamp must be fresh`);

      const planPath = path.join(receiptsDir, receipt.receipt_id + '.plan.json');
      assert.ok(fs.existsSync(planPath), `[${stepName}] Transition plan file must exist alongside receipt`);
      const plan = JSON.parse(fs.readFileSync(planPath, 'utf8'));
      assert.equal(plan.receipt_id, receipt.receipt_id, `[${stepName}] Plan receipt_id mismatch`);
      assert.equal(plan.target_version, expectedTargetVersion, `[${stepName}] Plan target_version mismatch`);
      assert.equal(plan.source_inventory?.version, expectedFromVersion, `[${stepName}] Plan source_inventory.version mismatch`);
      assert.equal(plan.target_inventory?.version, expectedTargetVersion, `[${stepName}] Plan target_inventory.version mismatch`);
      assert.ok(typeof plan.source_sha256 === 'string' && /^[0-9a-f]{64}$/.test(plan.source_sha256), `[${stepName}] Plan source_sha256 must be 64-char hex`);
      assert.ok(fs.existsSync(plan.source_phar), `[${stepName}] Plan source_phar must exist`);
      assert.equal(hasher(plan.source_phar), plan.source_sha256, `[${stepName}] Plan source_phar hash must match source_sha256`);
      if (baseInv) {
        assert.deepEqual(plan.source_inventory?.files, baseInv.files, `[${stepName}] Plan source_inventory.files mismatch against baseInv.files`);
      }
      if (targetInv) {
        assert.deepEqual(plan.target_inventory?.files, targetInv.files, `[${stepName}] Plan target_inventory.files mismatch against targetInv.files`);
      }

      // Native update job terminal verification: find exact new job_<ID>.json matching receipt_id, operation_id, and job_id
      const jobDeadline = Date.now() + 30000;
      let boundJob = null;
      let boundJobId = null;

      do {
        if (fs.existsSync(updateJobsDir)) {
          for (const jf of fs.readdirSync(updateJobsDir)) {
            if (preExistingJobs.has(jf)) continue;
            const match = jf.match(/^job_(\d+)\.json$/);
            if (!match) continue;
            try {
              const jobData = JSON.parse(fs.readFileSync(path.join(updateJobsDir, jf), 'utf8'));
              const rcId = jobData.result?.receipt_id ?? jobData.result?.data?.receipt_id;
              if (rcId === boundReceiptId) {
                boundJob = jobData;
                boundJobId = match[1];
                if (boundJob.status === 'completed') break;
              }
            } catch {}
          }
        }
        if (boundJob && boundJob.status === 'completed') break;
        await delay(250);
      } while (Date.now() < jobDeadline);

      assert.ok(boundJob, `[${stepName}] Exact new update job for receipt ${boundReceiptId} must exist`);
      assert.equal(String(boundJob.job_id), String(boundJobId), `[${stepName}] Job job_id must match filename ID`);
      assert.ok(typeof boundJob.operation_id === 'string' && boundJob.operation_id.length > 0, `[${stepName}] Job operation_id must be present`);

      const currentUpdateState = JSON.parse(fs.readFileSync(path.join(storageDir, 'update-state.json'), 'utf8'));
      assert.equal(currentUpdateState.operation_id, boundJob.operation_id, `[${stepName}] update-state.operation_id must match boundJob.operation_id`);
      assert.equal(currentUpdateState.transition_receipt, boundReceiptId, `[${stepName}] update-state.transition_receipt must match boundReceiptId`);

      assert.equal(boundJob.status, 'completed', `[${stepName}] Job status must be completed`);
      assert.equal(boundJob.result?.ok, true, `[${stepName}] Job result.ok must be true`);
      assert.equal(boundJob.result?.desktop_handoff_completed, true, `[${stepName}] Job result.desktop_handoff_completed must be true`);
      assert.equal(boundJob.result?.requires_desktop_handoff, false, `[${stepName}] Job result.requires_desktop_handoff must be false`);

      await transitionLab.system();
      await transitionLab.until(
        `sessionStorage.getItem('pos.active_update_job_id') === null && sessionStorage.getItem('pos.update_handoff_' + ${JSON.stringify(boundJobId)}) === 'done' && Boolean(document.querySelector("a[href='/settings']"))`,
        'UI settlement, active_update_job_id cleared, and handoff done in sessionStorage'
      );
      assert.equal(
        await transitionLab.evaluate(`sessionStorage.getItem('pos.update_handoff_' + ${JSON.stringify(boundJobId)})`),
        'done',
        `[${stepName}] pos.update_handoff_${boundJobId} must be 'done' in sessionStorage`
      );
      assert.equal(
        await transitionLab.evaluate(`sessionStorage.getItem('pos.active_update_job_id')`),
        null,
        `[${stepName}] pos.active_update_job_id must be null after settlement`
      );

      verifyInventoryFiles(targetInv, baseInv);
      assert.equal(hasher(installedExePath), owned.candidate_exe_sha256, `[${stepName}] POS Desktop.exe altered`);
      assert.equal(hasher(installedAsarPath), owned.candidate_asar_sha256, `[${stepName}] app.asar altered`);

      const dbAfter = runPhpFixture('inspect-db', { 'config-dir': configDir, sentinel: SENTINEL });
      assert.equal(dbAfter.customer?.name, SENTINEL, `[${stepName}] Sentinel lost`);
      assert.equal(dbAfter.setting?.value, baselineDb.setting?.value, `[${stepName}] Setting lost`);
      assert.equal(dbAfter.admin_digest, baselineDb.admin_digest, `[${stepName}] Admin credentials altered`);
      assert.deepEqual(dbAfter.ledger_rows, baselineDb.ledger_rows, `[${stepName}] Schema versions rows altered`);

      assert.deepEqual(await pendingSale(transitionLab, 'get'), offlineBefore, `[${stepName}] Offline sale preserved`);

      const protectedAfter = await getSystemProtectedState(transitionLab);
      assertProtectedStatePreserved(baselineProtected, protectedAfter, stepName);
      if (screenshotName) {
        await transitionLab.screenshot(screenshotName);
      }
    }

    // 3A: Candidate 13 Stable (Setup) -> Public Beta 12.1
    console.log('[Test 3A] Transitioning Candidate 13 Stable (Setup) -> Beta 12.1...');
    const verBeforePreview = JSON.parse(fs.readFileSync(path.join(root, 'version.json'), 'utf8')).version;
    assert.equal(verBeforePreview, '0.0.13', 'Selecting channel must not modify installed version');

    await executeTransition({
      stepName: 'Test 3A (Setup 13 -> Beta 12.1)',
      channel: 'beta',
      expectedFromVersion: '0.0.13',
      expectedTargetVersion: '0.0.12-beta.1',
      expectedFromChannel: 'stable',
      expectedSourceInvHash: candidate13SetupInvHash,
      expectedTargetInvHash: beta12InvHash,
      targetInv: beta12Inv,
      baseInv: candidate13SetupInv,
      screenshotName: 'transition-to-beta12',
    });
    results.verifications.bug1_candidate13_stable_to_beta_transition = true;

    // 3B: Public Beta 12.1 -> Candidate 13 Stable (Delta)
    console.log('[Test 3B] Transitioning Beta 12.1 -> Candidate 13 Stable (Delta)...');
    await executeTransition({
      stepName: 'Test 3B (Beta 12.1 -> Delta 13)',
      channel: 'stable',
      expectedFromVersion: '0.0.12-beta.1',
      expectedTargetVersion: '0.0.13',
      expectedFromChannel: 'beta',
      expectedSourceInvHash: beta12InvHash,
      expectedTargetInvHash: candidate13DeltaInvHash,
      targetInv: candidate13DeltaInv,
      baseInv: beta12Inv,
      screenshotName: 'transition-return-to-stable13',
    });
    results.verifications.bug1_candidate13_beta_to_stable_transition = true;

    // 3C: Repeated Transition 1: Candidate 13 Stable (Delta) -> Public Beta 12.1
    console.log('[Test 3C] Repeating Transition: Candidate 13 Delta -> Beta 12.1...');
    await executeTransition({
      stepName: 'Test 3C (Delta 13 -> Beta 12.1)',
      channel: 'beta',
      expectedFromVersion: '0.0.13',
      expectedTargetVersion: '0.0.12-beta.1',
      expectedFromChannel: 'stable',
      expectedSourceInvHash: candidate13DeltaInvHash,
      expectedTargetInvHash: beta12InvHash,
      targetInv: beta12Inv,
      baseInv: candidate13DeltaInv,
    });

    // 3D: Repeated Transition 2: Public Beta 12.1 -> Candidate 13 Stable (Delta)
    console.log('[Test 3D] Repeating Transition: Beta 12.1 -> Candidate 13 Delta...');
    await executeTransition({
      stepName: 'Test 3D (Beta 12.1 -> Delta 13)',
      channel: 'stable',
      expectedFromVersion: '0.0.12-beta.1',
      expectedTargetVersion: '0.0.13',
      expectedFromChannel: 'beta',
      expectedSourceInvHash: beta12InvHash,
      expectedTargetInvHash: candidate13DeltaInvHash,
      targetInv: candidate13DeltaInv,
      baseInv: beta12Inv,
    });

    results.verifications.repeated_channel_roundtrip_verified = true;
    results.verifications.indexeddb_offline_preserved = true;
    console.log('PASS [Test 3] Both channel transition roundtrips verified.');

    // ── TEST 4: Bug 2 Verification - Desktop SQL Restore Integration ─────────
    console.log('\n[Test 4] Verifying Desktop SQL Restore on Candidate 13 (Bug 2 Fix)...');

    // 4A: Fetch genuine application SQL backup via renderer endpoint
    console.log('[Test 4A] Downloading real production SQL backup from /api/v1/backup...');
    const baselineSql = await transitionLab.evaluate(`(async () => {
      const base = (await window.posRuntime?.getApiBaseUrl?.()) || '';
      const res = await fetch(base + '/api/v1/backup', { credentials: 'include' });
      if (!res.ok) throw new Error('Backup download failed: ' + res.status);
      return await res.text();
    })()`);

    assert.ok(baselineSql.length > 50, 'Downloaded SQL backup is too small');
    assert.ok(
      baselineSql.includes(SENTINEL),
      `Downloaded SQL backup does not contain sentinel '${SENTINEL}'`
    );
    const baselineBackupPath = path.join(lab, 'backup-baseline.sql');
    fs.writeFileSync(baselineBackupPath, baselineSql, 'utf8');
    console.log(`[Test 4A] Baseline SQL backup saved (${baselineSql.length} bytes) to ${baselineBackupPath}`);

    // 4B: Mutate sentinel row in database
    console.log(`[Test 4B] Mutating sentinel '${SENTINEL}' to '${MUTATED_SENTINEL}'...`);
    const mutateRes = runPhpFixture('mutate-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
      'mutated-name': MUTATED_SENTINEL,
    });
    assert.equal(mutateRes.ok, true, 'mutate-db failed');
    assert.equal(mutateRes.affected, 1, 'Exactly 1 sentinel row should be mutated');

    // Verify mutation took effect
    const dbMutated = runPhpFixture('inspect-db', { 'config-dir': configDir, sentinel: SENTINEL });
    assert.equal(dbMutated.customer, null, 'Original sentinel should not match after mutation');
    assert.ok(
      dbMutated.all_customers.some((c) => c.name === MUTATED_SENTINEL),
      'Mutated sentinel row must exist in customers table'
    );
    console.log('[Test 4B] Mutation verified: database now has mutated row.');

    // 4C: Happy-Path Restore via window.electronAPI.backup.restore()
    console.log('[Test 4C] Testing Happy-Path Desktop Restore with baseline SQL backup...');
    await transitionLab.mockOpenDialog(baselineBackupPath);

    const safetyDir = path.join(storageDir, 'update-backups', 'migration-safety');
    fs.mkdirSync(safetyDir, { recursive: true });
    const preExistingSafety = new Set(fs.readdirSync(safetyDir));

    const pageRunIdBefore = 'restore_' + Date.now();
    await transitionLab.evaluate(`window.__pageRunId = ${JSON.stringify(pageRunIdBefore)}`);

    // Immediate settled wrapper catching all transport rejections without unhandled rejection
    let restoreContextLost = false;
    let restoreSettled = null;
    const restorePromise = transitionLab.evaluate('window.electronAPI.backup.restore()')
      .then((res) => { restoreSettled = res; return res; })
      .catch((err) => {
        restoreContextLost = /destroyed|cannot find context|navigated|target closed|session closed|protocol error/i.test(err?.message || '');
        restoreSettled = { context_lost: restoreContextLost, error: true, message: err?.message };
        return restoreSettled;
      });

    // Observe creation of safety snapshot pair (.sql and .sql.json)
    const snapshot4C = await observeMigrationSafetySnapshot(safetyDir, preExistingSafety);

    // Successful restore reloads window and clears session
    await transitionLab.until('Boolean(document.querySelector("input[type=email]"))', 'login form after restore reload', 90000);
    await restorePromise;
    console.log('[Test 4C] Restore promise settled; context lost:', restoreContextLost);

    if (restoreSettled?.context_lost) {
      assert.ok(restoreContextLost, 'Context loss must match expected navigation pattern');
    } else {
      assert.equal(restoreSettled?.success, true, `Restore settlement must return success: true, got: ${JSON.stringify(restoreSettled)}`);
    }

    // Document reload proof: window.__pageRunId set before restore must not equal pageRunIdBefore
    const pageRunIdAfter = await transitionLab.evaluate('window.__pageRunId');
    assert.notEqual(pageRunIdAfter, pageRunIdBefore, 'window.__pageRunId must change post-reload to prove document reload');

    // Verify restore cleared actor offline IndexedDB rows via read-only lookup on the unauthenticated login page
    const offlineAfter = await pendingSale(transitionLab, 'get');
    assert.ok(offlineAfter === undefined || offlineAfter === null, 'Restore must clear actor offline IndexedDB pending sales rows');

    // Verify safety snapshots were deleted after complete success
    assert.equal(fs.existsSync(snapshot4C.sqlPath), false, 'Safety snapshot .sql must be deleted after successful restore');
    assert.equal(fs.existsSync(snapshot4C.jsonPath), false, 'Safety snapshot .sql.json must be deleted after successful restore');

    // Preexisting snapshots preserved in memory
    for (const f of preExistingSafety) {
      assert.ok(fs.existsSync(path.join(safetyDir, f)), `Preexisting safety snapshot ${f} must be preserved`);
    }

    // Inspect database to verify original sentinel customer row was restored
    const dbRestored = runPhpFixture('inspect-db', { 'config-dir': configDir, sentinel: SENTINEL });
    assert.equal(dbRestored.customer?.name, SENTINEL, 'Original sentinel customer row must be restored');
    assert.equal(dbRestored.schema_sha256, contract.schema_sha256, 'Restored schema SHA256 must match contract');
    assert.equal(dbRestored.ledger_sha256, contract.ledger_sha256, 'Restored ledger SHA256 must match contract');
    assert.equal(dbRestored.setting?.value, baselineDb.setting?.value, 'Restored setting must match baseline');
    assert.equal(dbRestored.admin_digest, baselineDb.admin_digest, 'Restored admin credentials must match baseline');
    assert.deepEqual(dbRestored.ledger_rows, baselineDb.ledger_rows, 'Full raw schema_versions rows altered after restore');

    // Log in again to continue testing in authenticated session
    await transitionLab.login(auth.email, auth.password);
    await transitionLab.until("Boolean(document.querySelector(\"a[href='/settings']\"))", 'authenticated navigation after restore');
    await transitionLab.screenshot('restore-happy-path-success');

    results.verifications.bug2_desktop_sql_restore_success = true;
    console.log('PASS [Test 4C] Desktop SQL restore happy path verified: database reverted to baseline.');

    // 4D: Error-Path Restore with SQL altering data before failing (Triggering Rollback & Error Classification)
    console.log('\n[Test 4D] Testing Error-Path Desktop Restore with SQL altering data before failing (Rollback & Classification)...');
    const failingSql = baselineSql + '\nINSERT INTO `__absent_fixture_table__` (`id`, `val`) VALUES (1, "fail");\n';
    const failingBackupPath = path.join(lab, 'backup-failing-import.sql');
    fs.writeFileSync(failingBackupPath, failingSql, 'utf8');

    // Mutate sentinel row in database BEFORE failed restore attempt to verify rollback restores to pre-attempt state
    console.log(`[Test 4D] Mutating sentinel '${SENTINEL}' to '${MUTATED_SENTINEL}' before failed restore...`);
    const mutateRes4D = runPhpFixture('mutate-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
      'mutated-name': MUTATED_SENTINEL,
    });
    assert.equal(mutateRes4D.ok, true, 'mutate-db failed for Test 4D');
    assert.equal(mutateRes4D.affected, 1, 'Exactly 1 sentinel row should be mutated for Test 4D');
    const dbBeforeFailed = runPhpFixture('inspect-db', { 'config-dir': configDir, sentinel: MUTATED_SENTINEL });
    assert.equal(dbBeforeFailed.customer?.name, MUTATED_SENTINEL, 'Mutated sentinel must exist prior to failed restore');
    assert.ok(dbBeforeFailed.schema_sha256, 'Pre-failure schema hash missing');
    assert.ok(dbBeforeFailed.ledger_rows?.length > 0, 'Pre-failure ledger rows missing');
    assert.ok(dbBeforeFailed.setting?.value, 'Pre-failure setting missing');
    assert.ok(dbBeforeFailed.admin_digest, 'Pre-failure admin digest missing');

    const preExistingSafety4D = new Set(fs.existsSync(safetyDir) ? fs.readdirSync(safetyDir) : []);

    // Mock dialog to select the failing backup file
    await transitionLab.mockOpenDialog(failingBackupPath);

    // Invoke restore through renderer IPC and observe creation of safety snapshot pair
    const failurePromise = transitionLab.evaluate('window.electronAPI.backup.restore()')
      .catch((err) => ({ success: false, code: 'IPC_TRANSPORT_UNKNOWN', error: err?.message || '' }));
    const snapshot4D = await observeMigrationSafetySnapshot(safetyDir, preExistingSafety4D);
    const failureResult = await failurePromise;
    console.log('[Test 4D] Failure code:', failureResult?.code, 'stage:', failureResult?.stage, 'recovered:', failureResult?.recovered);

    assert.equal(failureResult?.success, false, 'Malformed restore must return success: false');
    assert.equal(failureResult?.code, 'DATABASE_RESTORE_FAILED_RECOVERED', 'Error code must be DATABASE_RESTORE_FAILED_RECOVERED');
    assert.equal(failureResult?.stage, 'restore_import', 'Failure stage must be restore_import');
    assert.equal(failureResult?.recovered, true, 'Recovered flag must be true');
    assert.ok(typeof failureResult?.reference === 'string' && /^[a-f0-9]{16}$/i.test(failureResult.reference), 'Reference must be 16 hex chars');
    assert.ok(failureResult?.error.includes(failureResult.reference), 'Error message must include reference');
    assert.ok(failureResult?.error.includes('restore_import'), 'Error message must indicate stage');
    assert.ok(failureResult?.error.includes('recovered successfully'), 'Error message must confirm database was recovered');
    assert.ok(!failureResult?.error.includes('\\') && !failureResult?.error.includes('/'), 'Error message must not leak file paths');

    // Assert retention of safety snapshot per RestoreManager.js failed import with successful rollback policy
    assert.equal(fs.existsSync(snapshot4D.sqlPath), true, 'Safety snapshot .sql must be retained after failed import with successful rollback');
    assert.equal(fs.existsSync(snapshot4D.jsonPath), true, 'Safety snapshot .sql.json must be retained after failed import with successful rollback');

    const retainedSqlBytes = fs.readFileSync(snapshot4D.sqlPath);
    const retainedSqlSha = crypto.createHash('sha256').update(retainedSqlBytes).digest('hex');
    assert.equal(retainedSqlSha, snapshot4D.metadata.sha256, 'Retained SQL file sha256 must match metadata');
    assert.equal(retainedSqlBytes.length, snapshot4D.metadata.size, 'Retained SQL file size must match metadata');

    // Preexisting snapshots preserved in memory
    for (const f of preExistingSafety4D) {
      assert.ok(fs.existsSync(path.join(safetyDir, f)), `Preexisting safety snapshot ${f} must be preserved`);
    }

    // Verify desktop session was NOT cleared (user remains authenticated)
    const stillAuthenticated = await transitionLab.evaluate("Boolean(document.querySelector(\"a[href='/settings']\"))");
    assert.equal(stillAuthenticated, true, 'User session must be preserved when restore fails and rolls back');

    // Verify database integrity: original state retained via safety snapshot rollback (restored to mutated pre-attempt state)
    const dbAfterRollback = runPhpFixture('inspect-db', { 'config-dir': configDir, sentinel: MUTATED_SENTINEL });
    assert.deepEqual(dbAfterRollback.customer, dbBeforeFailed.customer, 'Customer row must be restored to pre-failure state');
    assert.equal(dbAfterRollback.customer?.name, MUTATED_SENTINEL, 'Database customer row must retain MUTATED_SENTINEL after rollback');
    assert.equal(dbAfterRollback.schema_sha256, dbBeforeFailed.schema_sha256, 'Database schema must match pre-failure state');
    assert.equal(dbAfterRollback.setting?.value, dbBeforeFailed.setting?.value, 'Database setting must match pre-failure state');
    assert.equal(dbAfterRollback.admin_digest, dbBeforeFailed.admin_digest, 'Database admin digest must match pre-failure state');
    assert.deepEqual(dbAfterRollback.ledger_rows, dbBeforeFailed.ledger_rows, 'Full raw schema_versions rows altered after rollback');

    // Revert database mutation back to baseline sentinel
    const revertRes4D = runPhpFixture('mutate-db', {
      'config-dir': configDir,
      sentinel: MUTATED_SENTINEL,
      'mutated-name': SENTINEL,
    });
    assert.equal(revertRes4D.ok, true, 'Revert to sentinel failed');
    assert.equal(revertRes4D.affected, 1, 'Revert must affect exactly 1 row');
    const dbFinalClean = runPhpFixture('inspect-db', { 'config-dir': configDir, sentinel: SENTINEL });
    assert.equal(dbFinalClean.customer?.name, SENTINEL, 'Database customer row must be restored to baseline sentinel');

    await transitionLab.screenshot('restore-rollback-error-classified');

    results.verifications.bug2_desktop_sql_restore_rollback_and_error_classification = true;
    results.verifications.database_integrity_verified = true;
    results.verifications.session_preservation_on_error_verified = true;
    results.verifications.protected_state_preserved = true;
    results.details.bug2_restore = {
      happy_path: {
        customer_restored: dbRestored.customer?.name === SENTINEL,
        schema_matches_contract: dbRestored.schema_sha256 === contract.schema_sha256,
        ledger_count: dbRestored.ledger_count,
      },
      error_path: {
        code: failureResult.code,
        stage: failureResult.stage,
        recovered: failureResult.recovered,
        reference: failureResult.reference,
      },
    };
    console.log('PASS [Test 4D] Desktop SQL restore error classification, reference propagation, and rollback verified.');
  } finally {
    await transitionLab.stop();
  }

  // Acceptance ordering: write candidate-results.json to lab and evidence (acceptance.json written ONLY by cleanup finalizer)
  const candidateResultsLab = path.join(lab, 'candidate-results.json');
  fs.writeFileSync(candidateResultsLab, JSON.stringify(results, null, 2), 'utf8');
  const candidateResultsEvidence = path.join(evidence, 'candidate-results.json');
  fs.writeFileSync(candidateResultsEvidence, JSON.stringify(results, null, 2), 'utf8');

  console.log(`\nCandidate verification results written to: ${candidateResultsLab}`);
  console.log('All Setup 12 Regression and Restore Verifications PASSED!');
  process.exit(0);
} catch (error) {
  console.error('\nFAILURE during acceptance verification:', error.message);
  try {
    // Capture sanitized diagnostic files with strict field allowlist
    const stateFile = path.join(storageDir, 'update-state.json');
    if (fs.existsSync(stateFile)) {
      try {
        const st = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
        const diagState = {
          state: typeof st.state === 'string' && /^[a-zA-Z0-9_\-]+$/.test(st.state) ? st.state : null,
          operation: typeof st.operation === 'string' && /^[a-zA-Z0-9_\-]+$/.test(st.operation) ? st.operation : null,
          from_version: typeof st.from_version === 'string' && /^[0-9a-zA-Z\.\-]+$/.test(st.from_version) ? st.from_version : null,
          to_version: typeof st.to_version === 'string' && /^[0-9a-zA-Z\.\-]+$/.test(st.to_version) ? st.to_version : null,
          updated_at: typeof st.updated_at === 'string' && /^[0-9T:\-\.Z]+$/.test(st.updated_at) ? st.updated_at : null,
          has_error: Boolean(st.error),
          transition_receipt: typeof st.transition_receipt === 'string' && /^[a-zA-Z0-9_\-]+$/.test(st.transition_receipt) ? st.transition_receipt : null,
          transition_verified: typeof st.transition_verified === 'boolean' ? st.transition_verified : null,
          requires_recovery: typeof st.requires_recovery === 'boolean' ? st.requires_recovery : null,
        };
        fs.writeFileSync(path.join(evidence, 'diagnostic-update-state.json'), JSON.stringify(diagState, null, 2), 'utf8');
      } catch {}
    }

    const lockFile = path.join(storageDir, 'update-operation.lock');
    if (fs.existsSync(lockFile)) {
      try {
        const lk = JSON.parse(fs.readFileSync(lockFile, 'utf8'));
        const diagLock = {
          operation: typeof lk.operation === 'string' && /^[a-zA-Z0-9_\-]+$/.test(lk.operation) ? lk.operation : null,
          acquired_at: typeof lk.acquired_at === 'string' && /^[0-9T:\-\.Z]+$/.test(lk.acquired_at) ? lk.acquired_at : null,
        };
        fs.writeFileSync(path.join(evidence, 'diagnostic-lock.json'), JSON.stringify(diagLock, null, 2), 'utf8');
      } catch {}
    }

    const receiptsDir = path.join(storageDir, 'channel-transition-receipts');
    if (fs.existsSync(receiptsDir)) {
      try {
        const safeReceipts = [];
        for (const f of fs.readdirSync(receiptsDir)) {
          if (f.endsWith('.json') && !f.endsWith('.plan.json')) {
            const raw = JSON.parse(fs.readFileSync(path.join(receiptsDir, f), 'utf8'));
            safeReceipts.push({
              receipt_id: typeof raw.receipt_id === 'string' && /^[a-zA-Z0-9_\-]+$/.test(raw.receipt_id) ? raw.receipt_id : null,
              state: typeof raw.state === 'string' && /^[a-zA-Z0-9_\-]+$/.test(raw.state) ? raw.state : null,
              from_version: typeof raw.from_version === 'string' && /^[0-9a-zA-Z\.\-]+$/.test(raw.from_version) ? raw.from_version : null,
              to_version: typeof raw.to_version === 'string' && /^[0-9a-zA-Z\.\-]+$/.test(raw.to_version) ? raw.to_version : null,
              target_inventory_matches: typeof raw.target_inventory_matches === 'boolean' ? raw.target_inventory_matches : null,
              data_compatible: typeof raw.data_compatible === 'boolean' ? raw.data_compatible : null,
            });
          }
        }
        fs.writeFileSync(path.join(evidence, 'diagnostic-receipts.json'), JSON.stringify(safeReceipts, null, 2), 'utf8');
      } catch {}
    }
  } catch (diagErr) {
    console.error('Failed to capture diagnostics:', diagErr.message);
  }
  throw error;
}
