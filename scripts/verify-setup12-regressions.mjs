import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { classifyVerifiedPublicStagedCa } from './protected-certificate-classification.mjs';
import { attachTransitionTransport } from './channel-transition-ui-transport.mjs';

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

const BOUNDED_BUCKETS = ['tables', 'columns', 'indexes', 'keys', 'references', 'checks', 'objects'];
const BOUNDED_FIELDS = {
  tables: ['name', 'type', 'engine', 'collation'],
  columns: ['table_name', 'name', 'position', 'type', 'nullable', 'default_value', 'extra', 'charset_name', 'collation'],
  indexes: ['table_name', 'name', 'non_unique', 'position', 'column_name', 'collation', 'sub_part', 'type'],
  keys: ['table_name', 'name', 'column_name', 'position', 'referenced_table', 'referenced_column'],
  references: ['table_name', 'name', 'referenced_table', 'update_rule', 'delete_rule', 'match_option'],
  checks: ['table_name', 'name', 'clause_text'],
  objects: ['object_type', 'object_name', 'definition', 'context', 'table_name'],
};

function compareDbStages(stageName, sourceDb, targetDb) {
  const schemaEqual = Boolean(
    sourceDb?.schema_sha256 && targetDb?.schema_sha256 && sourceDb.schema_sha256 === targetDb.schema_sha256
  );
  const ledgerEqual = Boolean(
    sourceDb?.ledger_sha256 && targetDb?.ledger_sha256 && sourceDb.ledger_sha256 === targetDb.ledger_sha256
  );
  const rawSchemaEqual = Boolean(
    sourceDb?.raw_schema_sha256 && targetDb?.raw_schema_sha256 && sourceDb.raw_schema_sha256 === targetDb.raw_schema_sha256
  );

  const changedBuckets = [];
  const changedFields = {};
  const rowCountsDeltas = {};

  const srcBuckets = sourceDb?.canonical_components?.buckets || {};
  const tgtBuckets = targetDb?.canonical_components?.buckets || {};

  for (const bucket of BOUNDED_BUCKETS) {
    const srcB = srcBuckets[bucket] || {};
    const tgtB = tgtBuckets[bucket] || {};
    const srcCount = Number.isInteger(srcB.count) ? srcB.count : 0;
    const tgtCount = Number.isInteger(tgtB.count) ? tgtB.count : 0;
    rowCountsDeltas[bucket] = tgtCount - srcCount;

    const countChanged = srcCount !== tgtCount;
    const shaChanged = Boolean(srcB.sha256 && tgtB.sha256 && srcB.sha256 !== tgtB.sha256);

    const bucketChangedFields = [];
    const fieldsList = BOUNDED_FIELDS[bucket] || [];
    for (const field of fieldsList) {
      const srcF = srcB.fields?.[field];
      const tgtF = tgtB.fields?.[field];
      if (srcF && tgtF && srcF !== tgtF) {
        bucketChangedFields.push(field);
      }
    }

    if (countChanged || shaChanged || bucketChangedFields.length > 0) {
      changedBuckets.push(bucket);
      if (bucketChangedFields.length > 0) {
        changedFields[bucket] = bucketChangedFields;
      }
    }
  }

  const srcLedgerCount = sourceDb?.canonical_components?.ledger?.count ?? (Array.isArray(sourceDb?.ledger) ? sourceDb.ledger.length : 0);
  const tgtLedgerCount = targetDb?.canonical_components?.ledger?.count ?? (Array.isArray(targetDb?.ledger) ? targetDb.ledger.length : 0);
  rowCountsDeltas.ledger = tgtLedgerCount - srcLedgerCount;

  return {
    stage: stageName,
    schema_equal: schemaEqual,
    ledger_equal: ledgerEqual,
    raw_schema_equal: rawSchemaEqual,
    buckets_changed_count: changedBuckets.length,
    changed_buckets: changedBuckets,
    changed_fields: changedFields,
    row_counts_deltas: rowCountsDeltas,
  };
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

async function seedOfflinePendingSale(ui) {
  let stored = false;
  for (let i = 0; i < 20 && !stored; i++) {
    stored = await pendingSale(ui, 'put');
    if (!stored) await delay(500);
  }
  assert.equal(stored, true, 'Offline fixture store unavailable');
  const record = await pendingSale(ui, 'get');
  assert.ok(record?.localId === 'codex-transition-pending', 'Seeded offline sale fixture record missing');
  return record;
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
    call: callRenderer,
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
  console.log('[Contract] Verifying restore SQL byte preservation logic in BackupService...');
  const preservationRes = runPhpFixture('verify-restore-sql-preservation', { 'lab-dir': lab });
  assert.equal(preservationRes.ok, true, 'verify-restore-sql-preservation failed');
  assert.equal(preservationRes.unsigned_ddl_preserved, true, 'unsigned_ddl_preserved must be true');
  assert.equal(preservationRes.literals_comments_preserved, true, 'literals_comments_preserved must be true');
  assert.equal(preservationRes.bom_removed_cleanly, true, 'bom_removed_cleanly must be true');
  assert.equal(preservationRes.dangerous_commands_rejected, true, 'dangerous_commands_rejected must be true');
  assert.equal(preservationRes.benign_literals_accepted, true, 'benign_literals_accepted must be true');
  console.log(`[Contract] Verified restore SQL preservation logic successfully (${preservationRes.cases_tested} cases tested).`);

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
assert.ok(typeof baselineDb?.setting?.key === 'string' && baselineDb.setting.key.length > 0, 'Baseline setting key must be a non-empty string');
assert.ok(typeof baselineDb?.setting?.value === 'string' && baselineDb.setting.value.length > 0, 'Baseline setting value must be a non-empty string');

const schemaDiagnostics = {
  format: 1,
  contract_matches_baseline: Boolean(
    contract?.schema_sha256 && baselineDb?.schema_sha256 &&
    contract.schema_sha256 === baselineDb.schema_sha256 &&
    contract?.ledger_sha256 && baselineDb?.ledger_sha256 &&
    contract.ledger_sha256 === baselineDb.ledger_sha256
  ),
  actual_matches_contract: null,
  actual_matches_carrier: null,
  carrier_matches_contract: null,
  earliest_drift_stage: 'none',
  candidate_restore_recovered_contract: null,
  stages: [],
};

if (!schemaDiagnostics.contract_matches_baseline) {
  schemaDiagnostics.earliest_drift_stage = 'contract';
}

function persistSchemaDiagnostics() {
  try {
    const summaryFile = path.join(evidence, 'diagnostic-schema-stage-summary.json');
    fs.writeFileSync(summaryFile, JSON.stringify(schemaDiagnostics, null, 2), 'utf8');
  } catch {
    console.error('Failed to persist schema stage diagnostics: write_failed');
  }
}
persistSchemaDiagnostics();

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
    legacy_v025_restore_migrations_verified: false,
  },
  details: {},
};

let offlineBefore = null;
let dbAfterRestore = null;
let dbAfterUpgrade = null;
let dbPreTransition = null;
let setup12BackupSha256 = null;

const ALLOWED_LEGACY_PHASES = new Set([
  'prepare',
  'nativeLegacy',
  'legacyVerify',
  'baselineBack',
  'finalVerify',
  'completed',
]);

const ALLOWED_LEGACY_RESULTS = new Set([
  'pass',
  'fail',
  'in_progress',
]);

const ALLOWED_RESTORE_CODES = new Set(['DATABASE_RESTORE_VALIDATION_FAILED', 'DATABASE_RESTORE_SNAPSHOT_FAILED', 'DATABASE_RESTORE_FAILED_RECOVERED', 'DATABASE_RESTORE_RECOVERY_FAILED', 'DATABASE_RESTORE_RESTART_FAILED', 'DATABASE_RESTORE_FINALIZE_FAILED', 'DATABASE_RESTORE_FAILED']);

const ALLOWED_RESTORE_STAGES = new Set([
  'restore_validate',
  'restore_snapshot',
  'restore_import',
  'restore_migrations',
  'restore_verify',
  'restore_rollback',
  'restore_restart',
  'restore_finalize',
]);

const ALLOWED_LEGACY_STAGES = new Set([
  ...ALLOWED_RESTORE_STAGES,
  'completed',
  'prepare',
  'nativeLegacy',
  'legacyVerify',
  'baselineBack',
  'finalVerify',
]);

const repoMigrations = path.join(process.cwd(), 'database/migrations');
const KNOWN_MIGRATION_FILENAMES = new Set([
  ...(fs.existsSync(path.join(root, 'database/migrations'))
    ? fs.readdirSync(path.join(root, 'database/migrations')).filter((f) => f.endsWith('.sql'))
    : []),
  ...(fs.existsSync(repoMigrations)
    ? fs.readdirSync(repoMigrations).filter((f) => f.endsWith('.sql'))
    : []),
]);

function has16HexReference(val) {
  if (typeof val === 'string') {
    if (/^[a-f0-9]{16}$/i.test(val.trim())) return true;
    if (/\bReference:\s*([a-f0-9]{16})\b/i.test(val)) return true;
  }
  return false;
}

let setup12Diagnostic = null;

function persistSetup12Diagnostic(patch = {}) {
  if (!setup12Diagnostic) return;
  Object.assign(setup12Diagnostic, patch);

  const safeCode = typeof setup12Diagnostic.code === 'string' && ALLOWED_RESTORE_CODES.has(setup12Diagnostic.code)
    ? setup12Diagnostic.code
    : null;
  const safeStage = typeof setup12Diagnostic.stage === 'string' && ALLOWED_RESTORE_STAGES.has(setup12Diagnostic.stage)
    ? setup12Diagnostic.stage
    : null;
  const safeRecovered = typeof setup12Diagnostic.recovered === 'boolean'
    ? setup12Diagnostic.recovered
    : null;
  const safeReferencePresent = typeof setup12Diagnostic.reference_present === 'boolean'
    ? setup12Diagnostic.reference_present
    : null;

  const safeDriverErrno = typeof setup12Diagnostic.driver_errno === 'number' && Number.isInteger(setup12Diagnostic.driver_errno) && setup12Diagnostic.driver_errno >= 1 && setup12Diagnostic.driver_errno <= 99999
    ? setup12Diagnostic.driver_errno
    : null;
  const safeMigrationDriverErrno = typeof setup12Diagnostic.migration_driver_errno === 'number' && Number.isInteger(setup12Diagnostic.migration_driver_errno) && setup12Diagnostic.migration_driver_errno >= 1 && setup12Diagnostic.migration_driver_errno <= 99999
    ? setup12Diagnostic.migration_driver_errno
    : null;

  const safeFailedMigration = typeof setup12Diagnostic.failed_migration === 'string' && KNOWN_MIGRATION_FILENAMES.has(setup12Diagnostic.failed_migration)
    ? setup12Diagnostic.failed_migration
    : null;

  const safeLedgerCount = typeof setup12Diagnostic.ledger_count === 'number' && Number.isInteger(setup12Diagnostic.ledger_count) && setup12Diagnostic.ledger_count >= 0
    ? setup12Diagnostic.ledger_count
    : null;

  const safeLatestLedgerVersion = typeof setup12Diagnostic.latest_ledger_version === 'string' && KNOWN_MIGRATION_FILENAMES.has(setup12Diagnostic.latest_ledger_version)
    ? setup12Diagnostic.latest_ledger_version
    : null;

  const safe = {
    format: 1,
    restore_outcome: typeof setup12Diagnostic.restore_outcome === 'string' ? setup12Diagnostic.restore_outcome : null,
    restore_success_flag: typeof setup12Diagnostic.success_flag === 'boolean' ? setup12Diagnostic.success_flag : null,
    restore_code: safeCode,
    restore_stage: safeStage,
    restore_recovered: safeRecovered,
    restore_has_reference: safeReferencePresent,
    context_lost: typeof setup12Diagnostic.context_lost === 'boolean' ? setup12Diagnostic.context_lost : false,
    ui_reload_ready: typeof setup12Diagnostic.ui_reload_ready === 'boolean' ? setup12Diagnostic.ui_reload_ready : null,
    document_marker_changed: typeof setup12Diagnostic.document_marker_changed === 'boolean' ? setup12Diagnostic.document_marker_changed : null,
    metadata_object_exists: typeof setup12Diagnostic.metadata_object_exists === 'boolean' ? setup12Diagnostic.metadata_object_exists : null,
    metadata_object_type: typeof setup12Diagnostic.metadata_object_type === 'string' ? setup12Diagnostic.metadata_object_type : null,
    metadata_query_ok: typeof setup12Diagnostic.metadata_query_ok === 'boolean' ? setup12Diagnostic.metadata_query_ok : null,
    view_exists: typeof setup12Diagnostic.metadata_object_exists === 'boolean' ? setup12Diagnostic.metadata_object_exists : null,
    view_type: typeof setup12Diagnostic.metadata_object_type === 'string' ? setup12Diagnostic.metadata_object_type : null,
    driver_errno: safeDriverErrno,
    ledger_count: safeLedgerCount,
    latest_ledger_version: safeLatestLedgerVersion,
    canonical_58_match: typeof setup12Diagnostic.canonical_58_match === 'boolean' ? setup12Diagnostic.canonical_58_match : null,
    sentinel_customer_present: typeof setup12Diagnostic.sentinel_customer_present === 'boolean' ? setup12Diagnostic.sentinel_customer_present : null,
    admin_user_present: typeof setup12Diagnostic.admin_user_present === 'boolean' ? setup12Diagnostic.admin_user_present : null,
    sentinel_setting_present: typeof setup12Diagnostic.sentinel_setting_present === 'boolean' ? setup12Diagnostic.sentinel_setting_present : null,
    failed_migration: safeFailedMigration,
    migration_driver_errno: safeMigrationDriverErrno,
  };

  fs.writeFileSync(path.join(evidence, 'diagnostic-setup12-restore-summary.json'), JSON.stringify(safe, null, 2), 'utf8');
}

let legacyDiagnostic = null;

function persistLegacyDiagnostic(patch = {}) {
  if (!legacyDiagnostic) return;
  Object.assign(legacyDiagnostic, patch);

  let safeCode = null;
  if (legacyDiagnostic.code === 0 || legacyDiagnostic.code === 1) {
    safeCode = legacyDiagnostic.code;
  } else if (legacyDiagnostic.result === 'pass') {
    safeCode = 0;
  } else if (legacyDiagnostic.result === 'fail') {
    safeCode = 1;
  }

  const safe = {
    phase: typeof legacyDiagnostic.phase === 'string' && ALLOWED_LEGACY_PHASES.has(legacyDiagnostic.phase) ? legacyDiagnostic.phase : null,
    result: typeof legacyDiagnostic.result === 'string' && ALLOWED_LEGACY_RESULTS.has(legacyDiagnostic.result) ? legacyDiagnostic.result : null,
    code: safeCode,
    stage: typeof legacyDiagnostic.stage === 'string' && ALLOWED_LEGACY_STAGES.has(legacyDiagnostic.stage) ? legacyDiagnostic.stage : null,
    has_reference: Boolean(legacyDiagnostic.has_reference),
    recovered: typeof legacyDiagnostic.recovered === 'boolean' ? legacyDiagnostic.recovered : null,
    legacy_migrations_count: typeof legacyDiagnostic.legacy_migrations_count === 'number' && Number.isInteger(legacyDiagnostic.legacy_migrations_count) && legacyDiagnostic.legacy_migrations_count >= 0 && legacyDiagnostic.legacy_migrations_count <= 100 ? legacyDiagnostic.legacy_migrations_count : null,
    completed_migrations_count: typeof legacyDiagnostic.completed_migrations_count === 'number' && Number.isInteger(legacyDiagnostic.completed_migrations_count) && legacyDiagnostic.completed_migrations_count >= 0 && legacyDiagnostic.completed_migrations_count <= 100 ? legacyDiagnostic.completed_migrations_count : null,
    latest_migration_present: typeof legacyDiagnostic.latest_migration_present === 'boolean' ? legacyDiagnostic.latest_migration_present : null,
    protected_preserved: typeof legacyDiagnostic.protected_preserved === 'boolean' ? legacyDiagnostic.protected_preserved : null,
    failing_migration: typeof legacyDiagnostic.failing_migration === 'string' && KNOWN_MIGRATION_FILENAMES.has(legacyDiagnostic.failing_migration) ? legacyDiagnostic.failing_migration : null,
    driver_errno: typeof legacyDiagnostic.driver_errno === 'number' && Number.isInteger(legacyDiagnostic.driver_errno) && legacyDiagnostic.driver_errno > 0 && legacyDiagnostic.driver_errno < 100000 ? legacyDiagnostic.driver_errno : null,
  };
  fs.writeFileSync(path.join(evidence, 'diagnostic-legacy-v025-summary.json'), JSON.stringify(safe, null, 2), 'utf8');
}

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
  let reproTransport = null;

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
    setup12BackupSha256 = hasher(setup12BackupPath);

    // Mutate sentinel to test Setup 12 restore attempt
    const mutateRes = runPhpFixture('mutate-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
      'mutated-name': MUTATED_SENTINEL,
    });
    assert.equal(mutateRes.ok, true, 'mutate-db failed for Test 1');
    assert.equal(mutateRes.affected, 1, 'mutate-db must affect exactly 1 customer row');
    const dbMutated = runPhpFixture('inspect-db', {
      'config-dir': configDir,
      sentinel: MUTATED_SENTINEL,
      'setting-key': baselineDb.setting.key,
    });
    assert.equal(dbMutated.customer?.name, MUTATED_SENTINEL, 'Mutated sentinel must exist before restore attempt');

    // Goal 2: mark pre-restore log offsets and timestamps
    const restoreStartTime = Date.now();
    const preRestoreLogs = {};
    const logsDir = path.join(storageDir, 'logs');
    assertNoSymlinks(logsDir);
    if (fs.existsSync(logsDir)) {
      try {
        for (const file of fs.readdirSync(logsDir)) {
          if (file.endsWith('.log')) {
            const fullPath = path.join(logsDir, file);
            assertNoSymlinks(fullPath);
            try {
              const stat = fs.statSync(fullPath);
              preRestoreLogs[file] = { size: stat.size, mtimeMs: stat.mtimeMs, birthtimeMs: stat.birthtimeMs };
            } catch {}
          }
        }
      } catch {}
    }

    // Goal 1: fresh document marker before restore
    const pageRunIdBefore1 = 'setup12_restore_' + Date.now();
    await reproLab.evaluate(`window.__pageRunId = ${JSON.stringify(pageRunIdBefore1)}`);

    await reproLab.mockOpenDialog(setup12BackupPath);

    // Goal 2: Persist diagnostic-setup12-restore-summary.json immediately before native IPC start (with initial unknown nulls)
    setup12Diagnostic = {
      restore_outcome: 'INITIALIZING',
      code: null,
      stage: null,
      recovered: null,
      success_flag: null,
      reference_present: null,
      context_lost: false,
      ui_reload_ready: null,
      document_marker_changed: null,
      metadata_object_exists: null,
      metadata_object_type: null,
      metadata_query_ok: null,
      driver_errno: null,
      ledger_count: null,
      latest_ledger_version: null,
      canonical_58_match: null,
      sentinel_customer_present: null,
      admin_user_present: null,
      sentinel_setting_present: null,
      failed_migration: null,
      migration_driver_errno: null,
    };
    persistSetup12Diagnostic();

    // Goal 1: Actual native Setup 12 restore outcome MUST be recorded before any DB-describe/throw
    let setup12ContextLost = false;
    let setup12UnexpectedError = null;
    let setup12RawOutcome = null;

    const restore1Promise = reproLab.evaluate('window.electronAPI.backup.restore()')
      .then((res) => {
        setup12RawOutcome = res;
        const successFlag = typeof res?.success === 'boolean' ? res.success : null;
        const safeCode = typeof res?.code === 'string' && ALLOWED_RESTORE_CODES.has(res.code) ? res.code : null;
        const safeStage = typeof res?.stage === 'string' && ALLOWED_RESTORE_STAGES.has(res.stage) ? res.stage : null;
        const safeRecovered = typeof res?.recovered === 'boolean' ? res.recovered : null;
        const refPresent = has16HexReference(res?.reference) || has16HexReference(res?.error);

        let outcomeClass = 'UNKNOWN';
        if (successFlag === true) {
          outcomeClass = 'SUCCESS';
        } else if (successFlag === false) {
          outcomeClass = 'FAILURE';
        }

        persistSetup12Diagnostic({
          restore_outcome: outcomeClass,
          code: safeCode,
          stage: safeStage,
          recovered: safeRecovered,
          success_flag: successFlag,
          reference_present: refPresent,
          context_lost: false,
        });
        return res;
      })
      .catch((err) => {
        const isKnownNav = /destroyed|cannot find context|navigated|target closed|session closed|protocol error/i.test(err?.message || '');
        if (isKnownNav) {
          setup12ContextLost = true;
          persistSetup12Diagnostic({
            restore_outcome: 'UNKNOWN_CONTEXT_LOST',
            context_lost: true,
            success_flag: null,
          });
        } else {
          setup12UnexpectedError = err;
          persistSetup12Diagnostic({
            restore_outcome: 'UNEXPECTED_ERROR',
            context_lost: false,
            success_flag: null,
          });
        }
        return null;
      });

    await restore1Promise;

    // Goal 1 & 2: Check reload and login readiness; if native false, skip login wait
    let uiReloadReady = false;
    let documentMarkerChanged = false;

    const reloadExpected = setup12ContextLost || setup12RawOutcome?.success === true;

    if (reloadExpected) {
      try {
        await reproLab.until('Boolean(document.querySelector("input[type=email]"))', 'login form after Setup 12 restore reload', 90000);
        uiReloadReady = true;
      } catch (waitErr) {
        uiReloadReady = false;
        console.warn('[Test 1 Baseline Restore] UI reload wait timed out or login form not reached');
      }

      if (uiReloadReady) {
        try {
          const pageRunIdAfter1 = await reproLab.evaluate('window.__pageRunId');
          documentMarkerChanged = pageRunIdAfter1 !== pageRunIdBefore1;
        } catch {
          documentMarkerChanged = false;
        }
      }

      persistSetup12Diagnostic({
        ui_reload_ready: uiReloadReady,
        document_marker_changed: documentMarkerChanged,
      });

      if (!uiReloadReady || !documentMarkerChanged) {
        results.details.setup12_baseline_restore = {
          outcome: 'RELOAD_FAILED',
          success_flag: typeof setup12RawOutcome?.success === 'boolean' ? setup12RawOutcome.success : null,
          code: typeof setup12RawOutcome?.code === 'string' && ALLOWED_RESTORE_CODES.has(setup12RawOutcome.code) ? setup12RawOutcome.code : null,
          stage: typeof setup12RawOutcome?.stage === 'string' && ALLOWED_RESTORE_STAGES.has(setup12RawOutcome.stage) ? setup12RawOutcome.stage : null,
          recovered: typeof setup12RawOutcome?.recovered === 'boolean' ? setup12RawOutcome.recovered : null,
          reference_present: has16HexReference(setup12RawOutcome?.reference) || has16HexReference(setup12RawOutcome?.error),
          context_lost: setup12ContextLost,
          ui_reload_ready: uiReloadReady,
          document_marker_changed: documentMarkerChanged,
          db_restored: false,
          failed: true,
        };
        throw new Error(`Setup 12 expected reload failed: uiReloadReady=${uiReloadReady}, documentMarkerChanged=${documentMarkerChanged}`);
      }
    } else {
      // Native false or unexpected error: skip login wait
      persistSetup12Diagnostic({
        ui_reload_ready: false,
        document_marker_changed: false,
      });
    }

    if (setup12UnexpectedError) {
      throw setup12UnexpectedError;
    }

    // Goal 4: Parse structured logs for failed migration and driver errno
    let logFailedMigration = null;
    let logMigrationDriverErrno = null;

    assertNoSymlinks(logsDir);
    if (fs.existsSync(logsDir)) {
      try {
        for (const file of fs.readdirSync(logsDir)) {
          if (!file.endsWith('.log')) continue;
          const fullPath = path.join(logsDir, file);
          assertNoSymlinks(fullPath);
          try {
            const currentStat = fs.statSync(fullPath);
            let startOffset = 0;
            let bytesToRead = 0;

            if (Object.prototype.hasOwnProperty.call(preRestoreLogs, file)) {
              const prev = preRestoreLogs[file];
              if (currentStat.size > prev.size) {
                startOffset = prev.size;
                bytesToRead = Math.min(currentStat.size - prev.size, 65536);
              } else if (currentStat.size < prev.size) {
                continue;
              } else {
                continue;
              }
            } else {
              const isNew = currentStat.mtimeMs >= restoreStartTime || (currentStat.birthtimeMs && currentStat.birthtimeMs >= restoreStartTime);
              if (isNew && currentStat.size > 0) {
                startOffset = 0;
                bytesToRead = Math.min(currentStat.size, 65536);
              } else {
                continue;
              }
            }

            if (bytesToRead > 0) {
              const fd = fs.openSync(fullPath, 'r');
              const buf = Buffer.alloc(bytesToRead);
              fs.readSync(fd, buf, 0, bytesToRead, startOffset);
              fs.closeSync(fd);
              const text = buf.toString('utf8');
              const lines = text.split(/\r?\n/);
              for (const line of lines) {
                const trimmed = line.trim();
                if (!trimmed) continue;
                try {
                  const entry = JSON.parse(trimmed);
                  if (entry && entry.level === 'ERROR' && typeof entry.message === 'string') {
                    const match = entry.message.match(/^Migration failed:\s*(.+)$/);
                    if (match) {
                      const fname = match[1].trim();
                      if (KNOWN_MIGRATION_FILENAMES.has(fname)) {
                        logFailedMigration = fname;
                        const dc = entry.context?.driver_code;
                        if (typeof dc === 'number' && Number.isInteger(dc) && dc >= 1 && dc <= 99999) {
                          logMigrationDriverErrno = dc;
                        } else if (typeof dc === 'string' && /^\d+$/.test(dc)) {
                          const parsedDc = parseInt(dc, 10);
                          if (parsedDc >= 1 && parsedDc <= 99999) {
                            logMigrationDriverErrno = parsedDc;
                          } else {
                            logMigrationDriverErrno = null;
                          }
                        } else {
                          logMigrationDriverErrno = null;
                        }
                      }
                    }
                  }
                } catch {}
              }
            }
          } catch {}
        }
      } catch {}
    }

    // Goal 3: Read-only minimal probe of owned ephemeral DB BEFORE describe
    const probeResult = runPhpFixture('probe-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
      'setting-key': baselineDb.setting.key,
    });
    console.log(
      `[Test 1 DB Probe] Probe result: metadata_object_exists: ${probeResult.metadata_object_exists} | metadata_object_type: ${probeResult.metadata_object_type} | driver_errno: ${probeResult.driver_errno} | ledger_count: ${probeResult.ledger_count} | latest_ledger: ${probeResult.latest_ledger_version} | sentinel_customer: ${probeResult.sentinel_customer_present}`
    );

    // Goal 2 & 3: Update diagnostic after probe
    persistSetup12Diagnostic({
      metadata_object_exists: probeResult.metadata_object_exists,
      metadata_object_type: probeResult.metadata_object_type,
      metadata_query_ok: probeResult.metadata_query_ok,
      driver_errno: typeof probeResult.driver_errno === 'number' && probeResult.driver_errno >= 1 && probeResult.driver_errno <= 99999 ? probeResult.driver_errno : null,
      ledger_count: probeResult.ledger_count,
      latest_ledger_version: probeResult.latest_ledger_version,
      canonical_58_match: probeResult.canonical_58_match,
      sentinel_customer_present: probeResult.sentinel_customer_present,
      admin_user_present: probeResult.admin_user_present,
      sentinel_setting_present: probeResult.sentinel_setting_present,
      failed_migration: logFailedMigration,
      migration_driver_errno: logMigrationDriverErrno,
    });
    console.log('[Test 1 Diagnostic] Updated diagnostic-setup12-restore-summary.json after probe and log parsing');

    // Extract safe allowlisted native outcome properties for results.details
    const safeCode = typeof setup12RawOutcome?.code === 'string' && ALLOWED_RESTORE_CODES.has(setup12RawOutcome.code)
      ? setup12RawOutcome.code
      : null;
    const safeStage = typeof setup12RawOutcome?.stage === 'string' && ALLOWED_RESTORE_STAGES.has(setup12RawOutcome.stage)
      ? setup12RawOutcome.stage
      : null;
    const safeRecovered = typeof setup12RawOutcome?.recovered === 'boolean'
      ? setup12RawOutcome.recovered
      : null;
    const refPresent = has16HexReference(setup12RawOutcome?.reference) || has16HexReference(setup12RawOutcome?.error);
    const successFlag = typeof setup12RawOutcome?.success === 'boolean'
      ? setup12RawOutcome.success
      : null;

    let initialOutcomeClass = 'UNKNOWN';
    if (successFlag === true) {
      initialOutcomeClass = 'SUCCESS';
    } else if (setup12ContextLost) {
      initialOutcomeClass = 'UNKNOWN_CONTEXT_LOST';
    } else if (successFlag === false) {
      initialOutcomeClass = 'FAILURE';
    }

    results.details.setup12_baseline_restore = {
      outcome: initialOutcomeClass,
      success_flag: successFlag,
      code: safeCode,
      stage: safeStage,
      recovered: safeRecovered,
      reference_present: refPresent,
      context_lost: setup12ContextLost,
      ui_reload_ready: uiReloadReady,
      document_marker_changed: documentMarkerChanged,
      db_restored: false,
      failed: successFlag === false,
    };
    console.log('[Test 1 Baseline Restore] Setup 12 native restore result recorded:', initialOutcomeClass, 'context_lost:', setup12ContextLost, 'reload_ready:', uiReloadReady);

    // Goal 1: Assert fail-closed after probe + safe artifact if metadata_object_exists !== true or metadata_object_type !== 'VIEW' before describe
    assert.equal(
      probeResult.metadata_object_exists,
      true,
      `Setup 12 baseline restore failed closed: metadata object product_catalog_trigger_metadata does not exist (probe errno: ${probeResult.driver_errno}, failed migration: ${logFailedMigration})`
    );
    assert.equal(
      probeResult.metadata_object_type,
      'VIEW',
      `Setup 12 baseline restore failed closed: metadata object product_catalog_trigger_metadata exists but is type ${probeResult.metadata_object_type}, expected VIEW`
    );

    // If metadata view exists and is valid VIEW, proceed to normal inspect-db
    dbAfterRestore = runPhpFixture('inspect-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
    });
    const dbSuccess = dbAfterRestore.customer?.name === SENTINEL;

    const finalOutcomeClass = dbSuccess
      ? 'SUCCESS'
      : (setup12ContextLost ? 'UNKNOWN_CONTEXT_LOST' : 'FAILURE');

    results.details.setup12_baseline_restore.outcome = finalOutcomeClass;
    results.details.setup12_baseline_restore.db_restored = dbSuccess;
    results.details.setup12_baseline_restore.failed = !dbSuccess;
    console.log('[Test 1 Baseline Restore] Setup 12 restore attempt recorded:', results.details.setup12_baseline_restore.outcome);

    const restoreDiff = compareDbStages('baseline_to_restore', baselineDb, dbAfterRestore);
    schemaDiagnostics.stages.push(restoreDiff);
    if ((!restoreDiff.schema_equal || !restoreDiff.ledger_equal) && schemaDiagnostics.earliest_drift_stage === 'none') {
      schemaDiagnostics.earliest_drift_stage = 'restore';
    }
    persistSchemaDiagnostics();
    console.log(
      `[Schema Drift Diagnostic] Stage: baseline_to_restore | schema_equal: ${restoreDiff.schema_equal} | ledger_equal: ${restoreDiff.ledger_equal} | raw_schema_equal: ${restoreDiff.raw_schema_equal} | changed_buckets: [${restoreDiff.changed_buckets.join(', ')}] | earliest_drift: ${schemaDiagnostics.earliest_drift_stage}`
    );

    if (!dbSuccess) {
      const dbMutCheck = runPhpFixture('inspect-db', {
        'config-dir': configDir,
        sentinel: MUTATED_SENTINEL,
        'setting-key': baselineDb.setting.key,
      });
      assert.equal(dbMutCheck.customer?.name, MUTATED_SENTINEL, 'Mutated sentinel must be present if restore failed');
      const revertRes = runPhpFixture('mutate-db', {
        'config-dir': configDir,
        sentinel: MUTATED_SENTINEL,
        'mutated-name': SENTINEL,
      });
      assert.equal(revertRes.ok, true, 'Revert to sentinel failed');
      assert.equal(revertRes.affected, 1, 'Revert must affect exactly 1 row');
      const dbReverted = runPhpFixture('inspect-db', {
        'config-dir': configDir,
        sentinel: SENTINEL,
      });
      assert.equal(dbReverted.customer?.name, SENTINEL, 'Database must be restored to baseline sentinel before Test 1 preview');
    }

    if (await reproLab.evaluate('Boolean(document.querySelector("input[type=email]"))')) {
      await reproLab.login(auth.email, auth.password);
    }

    const genuinePhp12 = path.join(application, 'resources/portable/php/php.exe');
    assert.ok(fs.existsSync(genuinePhp12), `Genuine portable PHP executable missing in Setup 12: ${genuinePhp12}`);
    assert.ok(fs.existsSync(root), `Genuine unpacked root missing in Setup 12: ${root}`);

    reproTransport = await attachTransitionTransport({
      ui: reproLab,
      application,
      storage: storageDir,
      evidence,
      fixture: reproFixturePath,
      auth,
    });

    // Seed actual actor-scoped review IndexedDB record AFTER baseline Setup 12 restore/reauth and BEFORE candidate 13 NSIS
    console.log('[Test 1 IndexedDB] Seeding actor-scoped offline sale fixture into IndexedDB pos_offline...');
    offlineBefore = await seedOfflinePendingSale(reproLab);
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
    if (reproTransport) {
      await reproTransport.close().catch(() => {});
      reproTransport = null;
    }
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
  let transitionTransport = null;

  try {
    await transitionLab.start({ enableMainInspector: true });
    if (await transitionLab.evaluate('Boolean(document.querySelector("input[type=email]"))')) {
      await transitionLab.login(auth.email, auth.password);
    }
    await transitionLab.until('Boolean(document.querySelector("a[href=\'/settings\']"))', 'authenticated navigation', 60000);

    const runningAppVersion = await transitionLab.evaluate('window.electronAPI?.getVersion ? window.electronAPI.getVersion() : null');
    assert.equal(runningAppVersion, '0.0.13', 'Running native Candidate 13 application must report version 0.0.13');

    // Verify database sentinel row and admin digest preserved
    dbAfterUpgrade = runPhpFixture('inspect-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
    });
    assert.equal(dbAfterUpgrade.ok, true, 'inspect-db after upgrade failed');
    assert.equal(dbAfterUpgrade.customer?.name, SENTINEL, 'Sentinel customer record lost during upgrade');
    assert.equal(dbAfterUpgrade.setting?.value, baselineDb.setting?.value, 'Sentinel setting lost during upgrade');
    assert.equal(dbAfterUpgrade.admin_digest, baselineDb.admin_digest, 'Admin credentials altered during upgrade');
    assert.deepEqual(dbAfterUpgrade.ledger_rows, baselineDb.ledger_rows, 'Full raw schema_versions rows altered during silent upgrade');

    if (dbAfterRestore) {
      const upgradeFromRestoreDiff = compareDbStages('restore_to_upgrade', dbAfterRestore, dbAfterUpgrade);
      schemaDiagnostics.stages.push(upgradeFromRestoreDiff);
      if ((!upgradeFromRestoreDiff.schema_equal || !upgradeFromRestoreDiff.ledger_equal) && schemaDiagnostics.earliest_drift_stage === 'none') {
        schemaDiagnostics.earliest_drift_stage = 'upgrade';
      }
    }
    const upgradeCumulativeDiff = compareDbStages('baseline_to_upgrade', baselineDb, dbAfterUpgrade);
    schemaDiagnostics.stages.push(upgradeCumulativeDiff);
    if ((!upgradeCumulativeDiff.schema_equal || !upgradeCumulativeDiff.ledger_equal) && schemaDiagnostics.earliest_drift_stage === 'none') {
      schemaDiagnostics.earliest_drift_stage = 'upgrade';
    }
    schemaDiagnostics.actual_matches_contract = Boolean(
      dbAfterUpgrade.schema_sha256 === contract.schema_sha256 &&
      dbAfterUpgrade.ledger_sha256 === contract.ledger_sha256
    );
    persistSchemaDiagnostics();
    console.log(
      `[Schema Drift Diagnostic] Stage: restore_to_upgrade | schema_equal: ${schemaDiagnostics.stages.find((s) => s.stage === 'restore_to_upgrade')?.schema_equal ?? null} | cumulative_schema_equal: ${upgradeCumulativeDiff.schema_equal} | actual_matches_contract: ${schemaDiagnostics.actual_matches_contract} | earliest_drift: ${schemaDiagnostics.earliest_drift_stage}`
    );

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

    // ── TEST 2B: Candidate 13 Restore Recovery (Revert Test 1 Baseline Schema Drift) ────
    console.log('\n[Test 2B] Executing Candidate 13 native restore recovery using original Setup 12 baseline SQL backup...');
    const recoveryBackupPath = path.join(lab, 'setup12-backup-baseline.sql');
    assert.ok(fs.existsSync(recoveryBackupPath), 'Baseline Setup 12 SQL backup missing for Test 2B');
    assert.equal(hasher(recoveryBackupPath), setup12BackupSha256, 'Setup 12 baseline backup SQL file must be unmodified');
    const recoverySql = fs.readFileSync(recoveryBackupPath, 'utf8');
    assert.ok(recoverySql.length > 50, 'Baseline Setup 12 backup SQL file empty');

    await transitionLab.mockOpenDialog(recoveryBackupPath);

    const safetyDir2B = path.join(storageDir, 'update-backups', 'migration-safety');
    fs.mkdirSync(safetyDir2B, { recursive: true });
    const preExistingSafety2B = new Set(fs.readdirSync(safetyDir2B));

    const pageRunIdBefore2B = 'recovery_' + Date.now();
    await transitionLab.evaluate(`window.__pageRunId = ${JSON.stringify(pageRunIdBefore2B)}`);

    // Immediate settled wrapper catching all transport rejections without unhandled rejection
    let restore2BContextLost = false;
    let restore2BSettled = null;
    const restore2BPromise = transitionLab.evaluate('window.electronAPI.backup.restore()')
      .then((res) => { restore2BSettled = res; return res; })
      .catch((err) => {
        restore2BContextLost = /destroyed|cannot find context|navigated|target closed|session closed|protocol error/i.test(err?.message || '');
        restore2BSettled = { context_lost: restore2BContextLost, error: true };
        return restore2BSettled;
      });

    // Observe creation of safety snapshot pair (.sql and .sql.json)
    const snapshot2B = await observeMigrationSafetySnapshot(safetyDir2B, preExistingSafety2B);

    // Successful restore reloads window and clears session
    await transitionLab.until('Boolean(document.querySelector("input[type=email]"))', 'login form after recovery restore reload', 90000);
    await restore2BPromise;
    console.log('[Test 2B] Recovery restore promise settled; context lost:', restore2BContextLost);

    if (restore2BSettled?.context_lost) {
      assert.ok(restore2BContextLost, 'Context loss must match expected navigation pattern');
    } else {
      assert.equal(restore2BSettled?.success, true, 'Restore settlement must return success: true');
    }

    // Document reload proof: window.__pageRunId set before restore must not equal pageRunIdBefore2B
    const pageRunIdAfter2B = await transitionLab.evaluate('window.__pageRunId');
    assert.notEqual(pageRunIdAfter2B, pageRunIdBefore2B, 'window.__pageRunId must change post-reload to prove document reload');

    // Verify restore cleared actor offline IndexedDB rows via read-only lookup on the unauthenticated login page
    const offlineAfter2B = await pendingSale(transitionLab, 'get');
    assert.ok(offlineAfter2B === undefined || offlineAfter2B === null, 'Restore must clear actor offline IndexedDB pending sales rows');

    // Verify safety snapshots were deleted after complete success
    assert.equal(fs.existsSync(snapshot2B.sqlPath), false, 'Safety snapshot .sql must be deleted after successful recovery restore');
    assert.equal(fs.existsSync(snapshot2B.jsonPath), false, 'Safety snapshot .sql.json must be deleted after successful recovery restore');

    // Preexisting snapshots preserved in memory
    for (const f of preExistingSafety2B) {
      assert.ok(fs.existsSync(path.join(safetyDir2B, f)), 'Preexisting safety snapshot must be preserved');
    }

    // Inspect current database after recovery restore
    dbPreTransition = runPhpFixture('inspect-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
    });
    assert.equal(dbPreTransition.ok, true, 'inspect-db after candidate 13 recovery failed');

    // Safe diagnostic stage comparison: baseline_to_candidate_restore
    const candidateRestoreDiff = compareDbStages('baseline_to_candidate_restore', baselineDb, dbPreTransition);
    schemaDiagnostics.stages.push(candidateRestoreDiff);
    schemaDiagnostics.candidate_restore_recovered_contract = Boolean(
      dbPreTransition.schema_sha256 === contract.schema_sha256 &&
      dbPreTransition.ledger_sha256 === contract.ledger_sha256
    );
    schemaDiagnostics.actual_matches_contract = schemaDiagnostics.candidate_restore_recovered_contract;
    persistSchemaDiagnostics();
    console.log(
      `[Schema Drift Diagnostic] Stage: baseline_to_candidate_restore | schema_equal: ${candidateRestoreDiff.schema_equal} | ledger_equal: ${candidateRestoreDiff.ledger_equal} | raw_schema_equal: ${candidateRestoreDiff.raw_schema_equal} | candidate_restore_recovered_contract: ${schemaDiagnostics.candidate_restore_recovered_contract} | earliest_drift: ${schemaDiagnostics.earliest_drift_stage}`
    );

    assert.equal(dbPreTransition.customer?.name, SENTINEL, 'Original sentinel customer row must be restored');
    assert.equal(dbPreTransition.setting?.value, baselineDb.setting?.value, 'Original sentinel setting must be restored');
    assert.equal(dbPreTransition.admin_digest, baselineDb.admin_digest, 'Original admin credentials must be restored');
    assert.deepEqual(dbPreTransition.ledger_rows, baselineDb.ledger_rows, 'Full raw schema_versions rows altered after recovery restore');
    assert.equal(dbPreTransition.schema_sha256, contract.schema_sha256, 'Restored schema SHA256 must match original signed contract');
    assert.equal(dbPreTransition.ledger_sha256, contract.ledger_sha256, 'Restored ledger SHA256 must match original signed contract');
    assert.equal(dbPreTransition.schema_sha256, baselineDb.schema_sha256, 'Restored canonical schema SHA256 must match original baseline');
    assert.equal(dbPreTransition.raw_schema_sha256, baselineDb.raw_schema_sha256, 'Restored RAW schema SHA256 must match original baseline');

    // Verify system protected state preserved across recovery restore
    const protectedAfterRecovery = await getSystemProtectedState(transitionLab);
    assertProtectedStatePreserved(baselineProtected, protectedAfterRecovery, 'Candidate 13 Recovery Restore');

    // Require actual new 13 recovery contract match BEFORE Test 3
    assert.equal(schemaDiagnostics.candidate_restore_recovered_contract, true, 'Candidate 13 restore recovery must match original contract before Test 3');
    assert.equal(schemaDiagnostics.actual_matches_contract, true, 'Current database must match contract before Test 3');

    // Fresh login after restore reload
    await transitionLab.login(auth.email, auth.password);
    await transitionLab.until("Boolean(document.querySelector(\"a[href='/settings']\"))", 'authenticated navigation after recovery', 60000);

    // Re-seed SAME actor-scoped syncStatus:'review' offline fixture after native 13 recovery for TRANSITIONS
    console.log('[Test 2B IndexedDB] Re-seeding actor-scoped offline sale fixture into IndexedDB pos_offline...');
    offlineBefore = await seedOfflinePendingSale(transitionLab);
    console.log('[Test 2B IndexedDB] Re-seeded offline pending sale fixture successfully.');

    results.details.candidate13_restore_recovery = {
      outcome: 'SUCCESS',
      schema_matches_contract: true,
      raw_schema_matches_baseline: true,
    };
    console.log('PASS [Test 2B] Candidate 13 restore recovery verified. Schema restored to baseline and contract.');

    // ── TEST 3: Bidirectional Channel Transition on Candidate 13 ───────────────
    console.log('\n[Test 3] Testing Bidirectional Channel Transitions on Candidate 13 (Bug 1 Fix)...');
    await transitionLab.system();

    const genuinePhp13 = path.join(application, 'resources/portable/php/php.exe');
    assert.ok(fs.existsSync(genuinePhp13), `Genuine portable PHP executable missing in Candidate 13: ${genuinePhp13}`);
    assert.ok(fs.existsSync(root), `Genuine unpacked root missing in Candidate 13: ${root}`);

    const tracePath = path.join(evidence, 'provider-trace.jsonl');
    const baselineTraceCount = fs.existsSync(tracePath)
      ? fs.readFileSync(tracePath, 'utf8').trim().split('\n').filter(Boolean).length
      : 0;
    let v13TraceVerified = false;

    transitionTransport = await attachTransitionTransport({
      ui: transitionLab,
      application,
      storage: storageDir,
      evidence,
      fixture: roundtripFixturePath,
      auth,
    });

    const witnessedReceiptIds = new Set();
    const witnessedNativeOwnerIds = new Set();

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
      if (schemaDiagnostics.carrier_matches_contract === null) {
        const sampleDesc = findCarrierDescriptor(lab, {
          fromVersion: expectedFromVersion,
          targetVersion: expectedTargetVersion,
          channel,
          sourceInventorySha256: expectedSourceInvHash,
          targetInventorySha256: expectedTargetInvHash,
        });
        if (sampleDesc?.data_compatibility && contract) {
          schemaDiagnostics.carrier_matches_contract = Boolean(
            sampleDesc.data_compatibility.schema_sha256 === contract.schema_sha256 &&
            sampleDesc.data_compatibility.ledger_sha256 === contract.ledger_sha256
          );
          if (dbPreTransition) {
            schemaDiagnostics.actual_matches_carrier = Boolean(
              sampleDesc.data_compatibility.schema_sha256 === dbPreTransition.schema_sha256 &&
              sampleDesc.data_compatibility.ledger_sha256 === dbPreTransition.ledger_sha256
            );
          }
          if (!schemaDiagnostics.carrier_matches_contract && schemaDiagnostics.earliest_drift_stage === 'none') {
            schemaDiagnostics.earliest_drift_stage = 'carrier_descriptor';
          }
        }
      }

      if (schemaDiagnostics.contract_matches_baseline && schemaDiagnostics.earliest_drift_stage === 'restore') {
        console.log('[Schema Drift Diagnostic] Captured contract matches baseline; schema drift detected post-Setup-12 restore (earliest drift stage: restore).');
      }

      persistSchemaDiagnostics();

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
      console.log(`[${stepName} Diagnostic] reason_code: ${previewData?.data?.reason_code || 'none'}, earliest_drift: ${schemaDiagnostics.earliest_drift_stage}`);
      persistSchemaDiagnostics();
      if (!v13TraceVerified && expectedFromVersion === '0.0.13') {
        assert.ok(fs.existsSync(tracePath), `[${stepName}] Provider trace file missing: ${tracePath}`);
        const traceLines = fs.readFileSync(tracePath, 'utf8').trim().split('\n').filter(Boolean);
        const newTraceEntries = traceLines.slice(baselineTraceCount).map((l) => {
          try { return JSON.parse(l); } catch { return null; }
        }).filter(Boolean);

        const expectedDesktopUrl = 'https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/tags/v0.0.13';
        const expectedUpdatesUrl = 'https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/tags/v0.0.13';
        let hasDesktop = false;
        let hasUpdates = false;
        for (const entry of newTraceEntries) {
          const mUrl = entry?.metadata_url;
          if (mUrl === expectedDesktopUrl) {
            assert.ok(Number.isInteger(entry.pid) && entry.pid > 0, `[${stepName}] Matched pos-desktop trace entry PID must be positive integer`);
            hasDesktop = true;
          } else if (mUrl === expectedUpdatesUrl) {
            assert.ok(Number.isInteger(entry.pid) && entry.pid > 0, `[${stepName}] Matched pos-desktop-updates trace entry PID must be positive integer`);
            hasUpdates = true;
          }
        }

        assert.ok(
          hasDesktop && hasUpdates,
          `[${stepName}] Both v0.0.13 canonical source URLs must be queried in provider trace (pos-desktop=${hasDesktop}, pos-desktop-updates=${hasUpdates})`
        );
        console.log(`[${stepName}] Verified canonical v0.0.13 source queries in provider trace (pos-desktop: ${hasDesktop}, pos-desktop-updates: ${hasUpdates}, new_traces: ${newTraceEntries.length}).`);
        v13TraceVerified = true;
      }
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

      await waitForApplySlot('transition_apply');

      let beforeApplyResolved = false;
      let capturedPrepareReceiptId = null;
      let capturedPrepOwnerId = null;
      let capturedSnapshotPath = null;
      let capturedElectronPid = null;

      const beforeApplyPromise = new Promise((resolve, reject) => {
        transitionTransport.beforeApply(async (payload) => {
          try {
            const data = payload?.data ?? payload;
            assert.equal(data.ok, true, `[${stepName}] Prepare response data.ok must be true`);
            assert.equal(data.code, 200, `[${stepName}] Prepare response data.code must be 200`);
            assert.equal(data.requires_desktop_handoff, true, `[${stepName}] Prepare response requires_desktop_handoff must be true`);
            assert.equal(data.handoff_version, expectedTargetVersion, `[${stepName}] Prepare response handoff_version mismatch`);
            assert.ok(
              typeof data.receipt_id === 'string' && /^[0-9a-f]{64}$/.test(data.receipt_id),
              `[${stepName}] Prepare receipt_id must be 64-char hex string`
            );
            assert.ok(!preExistingReceipts.has(data.receipt_id), `[${stepName}] Prepare receipt_id must not be pre-existing`);
            assert.ok(!witnessedReceiptIds.has(data.receipt_id), `[${stepName}] Prepare receipt_id must be unique across transitions`);
            assert.equal(data.job_id, undefined, `[${stepName}] Prepare response job_id must be undefined`);

            capturedPrepareReceiptId = data.receipt_id;

            const statePath = path.join(storageDir, 'update-state.json');
            assert.ok(fs.existsSync(statePath), `[${stepName}] update-state.json must exist during prepare interception`);
            assertNoSymlinks(statePath);
            const prepState = JSON.parse(fs.readFileSync(statePath, 'utf8'));

            assert.equal(prepState.transition_receipt, data.receipt_id, `[${stepName}] prepState.transition_receipt must match prepared receipt_id`);
            assert.ok(typeof prepState.handoff_plan === 'string' && prepState.handoff_plan.length > 0, `[${stepName}] prepState.handoff_plan must be string`);
            assert.ok(typeof prepState.owner_id === 'string' && prepState.owner_id.length > 0, `[${stepName}] prepState.owner_id must be present`);
            capturedPrepOwnerId = prepState.owner_id;

            const realStorage = fs.realpathSync(storageDir);

            const planFilePath = path.resolve(prepState.handoff_plan);
            assert.ok(fs.existsSync(planFilePath), `[${stepName}] Handoff plan file missing`);
            assertNoSymlinks(planFilePath);
            const realPlanFile = fs.realpathSync(planFilePath);
            assert.ok(
              realPlanFile.toLowerCase().startsWith((realStorage + path.sep).toLowerCase()),
              `[${stepName}] Handoff plan must reside within storage directory`
            );

            const handoffPlan = JSON.parse(fs.readFileSync(realPlanFile, 'utf8'));
            assert.equal(handoffPlan.version, expectedTargetVersion, `[${stepName}] handoffPlan.version mismatch`);
            assert.equal(path.resolve(handoffPlan.root_dir).toLowerCase(), path.resolve(root).toLowerCase(), `[${stepName}] handoffPlan.root_dir mismatch`);
            assert.equal(path.resolve(handoffPlan.storage_dir).toLowerCase(), path.resolve(storageDir).toLowerCase(), `[${stepName}] handoffPlan.storage_dir mismatch`);
            assert.equal(handoffPlan.db_recovery, null, `[${stepName}] handoffPlan.db_recovery must be null`);

            assert.ok(typeof handoffPlan.staging_dir === 'string' && handoffPlan.staging_dir.length > 0, `[${stepName}] handoffPlan.staging_dir must be string`);
            assertNoSymlinks(handoffPlan.staging_dir);
            const realStagingDir = fs.realpathSync(handoffPlan.staging_dir);
            assert.ok(
              realStagingDir.toLowerCase().startsWith((realStorage + path.sep).toLowerCase()),
              `[${stepName}] Staging directory must reside within storage directory`
            );
            assert.equal(
              path.dirname(realPlanFile).toLowerCase(),
              realStagingDir.toLowerCase(),
              `[${stepName}] Handoff plan directory must match staging directory`
            );

            assert.ok(typeof handoffPlan.snapshot_path === 'string' && handoffPlan.snapshot_path.length > 0, `[${stepName}] handoffPlan.snapshot_path must be string`);
            assertNoSymlinks(handoffPlan.snapshot_path);
            const backupsDir = path.join(storageDir, 'update-backups');
            assert.ok(fs.existsSync(backupsDir), `[${stepName}] update-backups directory missing`);
            assertNoSymlinks(backupsDir);
            const realBackups = fs.realpathSync(backupsDir);
            const realSnapshot = fs.realpathSync(handoffPlan.snapshot_path);
            assert.ok(
              realSnapshot.toLowerCase().startsWith((realBackups + path.sep).toLowerCase()),
              `[${stepName}] Snapshot path must reside within update-backups directory`
            );
            capturedSnapshotPath = handoffPlan.snapshot_path;

            const manifest = handoffPlan.manifest;
            assert.ok(manifest && typeof manifest === 'object', `[${stepName}] handoffPlan.manifest missing`);
            assert.equal(manifest.version, expectedTargetVersion, `[${stepName}] manifest.version mismatch`);
            if (channel) {
              assert.equal(manifest.channel, channel, `[${stepName}] manifest.channel mismatch`);
            }
            assert.ok(Array.isArray(manifest.migrations) && manifest.migrations.length === 0, `[${stepName}] manifest.migrations must be empty array`);

            if (baseInv && baseInv.files) {
              const expectedBaselineFiles = {};
              for (const [k, v] of Object.entries(baseInv.files)) {
                expectedBaselineFiles[k] = v.sha256;
              }
              assert.deepEqual(manifest.baseline_files, expectedBaselineFiles, `[${stepName}] manifest.baseline_files mismatch`);
            }

            if (carrierDesc && Array.isArray(carrierDesc.files)) {
              const expectedChangedFiles = carrierDesc.files.filter((file) => {
                const baseEntry = baseInv?.files?.[file.path];
                return !baseEntry || baseEntry.sha256 !== file.sha256 || baseEntry.size !== file.size;
              });
              assert.deepEqual(manifest.files, expectedChangedFiles, `[${stepName}] manifest.files mismatch`);
            }

            if (carrierDesc) {
              assert.deepEqual(manifest.deleted_files, carrierDesc.deleted_files || [], `[${stepName}] manifest.deleted_files mismatch`);
            }

            capturedElectronPid = await transitionLab.evaluateMain('process.pid');
            assert.ok(Number.isInteger(capturedElectronPid) && capturedElectronPid > 0, `[${stepName}] Electron main PID must be positive integer`);

            beforeApplyResolved = true;
            resolve();
          } catch (err) {
            reject(err);
            throw err;
          }
        });
      });
      beforeApplyPromise.catch(() => {});

      const transitionAt = Date.now();
      const transitionRunId = 'trans_' + transitionAt;
      await transitionLab.evaluate(`window.__pageRunId = ${JSON.stringify(transitionRunId)}`);
      await transitionLab.until('Boolean(document.querySelector("[data-testid=apply-channel-transition]"))', 'apply button visible');
      await transitionLab.evaluate('document.querySelector("[data-testid=apply-channel-transition]").click()');
      await transitionLab.click('تأكيد');

      let timeoutId;
      const timeoutPromise = new Promise((_, reject) => {
        timeoutId = setTimeout(() => {
          if (!beforeApplyResolved) {
            reject(new Error(`[${stepName}] beforeApply hook was not called within 60s`));
          }
        }, 60000);
      });

      try {
        await Promise.race([beforeApplyPromise, timeoutPromise]);
      } finally {
        if (timeoutId) clearTimeout(timeoutId);
      }
      assert.ok(capturedPrepareReceiptId, `[${stepName}] Prepare receipt ID must have been captured`);

      await versionIs(transitionLab, auth, expectedTargetVersion, transitionAt, transitionRunId);

      assert.ok(fs.existsSync(genuinePhp13), `Genuine portable PHP executable missing after transition: ${genuinePhp13}`);
      assert.ok(fs.existsSync(root), `Genuine unpacked root missing after transition: ${root}`);
      assert.ok(fs.existsSync(path.join(root, 'backend/backend.phar')), `Genuine backend PHAR missing after transition: ${path.join(root, 'backend/backend.phar')}`);

      transitionTransport.resume();
      await transitionTransport.reauth();

      const receiptDeadline = Date.now() + 30000;
      let receipt;
      let boundReceiptId;
      do {
        try {
          const state = JSON.parse(fs.readFileSync(path.join(storageDir, 'update-state.json'), 'utf8'));
          if (state.transition_receipt && !preExistingReceipts.has(state.transition_receipt)) {
            boundReceiptId = state.transition_receipt;
            try {
              receipt = JSON.parse(fs.readFileSync(path.join(receiptsDir, state.transition_receipt + '.json'), 'utf8'));
            } catch {}
            if (receipt?.state === 'completed' && state.state === 'completed' && state.transition_verified === true) break;
            if (receipt?.state === 'failed') throw new Error(`[${stepName}] Transition receipt failed: ${receipt?.reason_code || 'unknown'}`);
          }
        } catch (e) {
          if (e.message?.startsWith(`[${stepName}] Transition receipt failed:`)) throw e;
        }
        await delay(250);
      } while (Date.now() < receiptDeadline);

      assert.ok(boundReceiptId && !preExistingReceipts.has(boundReceiptId), `[${stepName}] Transition must produce a new receipt ID`);
      assert.equal(boundReceiptId, capturedPrepareReceiptId, `[${stepName}] Bound receipt ID must match captured prepare receipt ID`);
      assert.equal(receipt?.state, 'completed', `[${stepName}] Transition receipt never completed`);
      assert.equal(receipt.receipt_id, boundReceiptId, `[${stepName}] Receipt ID mismatch`);
      assert.equal(receipt.target_inventory_matches, true, `[${stepName}] Receipt must confirm target_inventory_matches`);
      assert.equal(receipt.data_compatible, true, `[${stepName}] Receipt must confirm data_compatible`);

      const receiptTs = Date.parse(receipt.updated_at || receipt.created_at || '');
      assert.ok(!isNaN(receiptTs) && receiptTs >= transitionAt - 10000, `[${stepName}] Receipt timestamp must be fresh`);

      const planPath = path.join(receiptsDir, receipt.receipt_id + '.plan.json');
      assert.ok(fs.existsSync(planPath), `[${stepName}] Transition plan file must exist alongside receipt`);
      assertNoSymlinks(planPath);
      const plan = JSON.parse(fs.readFileSync(planPath, 'utf8'));
      assert.equal(plan.receipt_id, receipt.receipt_id, `[${stepName}] Plan receipt_id mismatch`);
      assert.equal(plan.target_version, expectedTargetVersion, `[${stepName}] Plan target_version mismatch`);
      if (expectedFromVersion) {
        assert.equal(plan.source_inventory?.version, expectedFromVersion, `[${stepName}] Plan source_inventory.version mismatch`);
      }
      assert.equal(plan.target_inventory?.version, expectedTargetVersion, `[${stepName}] Plan target_inventory.version mismatch`);
      assert.ok(typeof plan.source_sha256 === 'string' && /^[0-9a-f]{64}$/.test(plan.source_sha256), `[${stepName}] Plan source_sha256 must be 64-char hex`);
      assert.ok(fs.existsSync(plan.source_phar), `[${stepName}] Plan source_phar must exist`);
      assert.equal(hasher(plan.source_phar), plan.source_sha256, `[${stepName}] Plan source_phar hash must match source_sha256`);
      assert.equal(plan.snapshot_path, capturedSnapshotPath, `[${stepName}] Plan snapshot_path must match captured handoff plan snapshot_path`);
      assert.equal(path.resolve(plan.root).toLowerCase(), path.resolve(root).toLowerCase(), `[${stepName}] Plan root mismatch`);
      assert.equal(path.resolve(plan.storage).toLowerCase(), path.resolve(storageDir).toLowerCase(), `[${stepName}] Plan storage mismatch`);

      const expectedPharSha = baseInv?.files?.['backend/backend.phar']?.sha256;
      assert.ok(
        typeof expectedPharSha === 'string' && /^[0-9a-f]{64}$/.test(expectedPharSha),
        `[${stepName}] baseInv.files['backend/backend.phar'].sha256 must be valid 64-char hex`
      );
      assert.equal(
        plan.source_sha256,
        expectedPharSha,
        `[${stepName}] Plan source_sha256 must match signed source inventory backend/backend.phar SHA`
      );
      if (baseInv) {
        assert.deepEqual(plan.source_inventory?.files, baseInv.files, `[${stepName}] Plan source_inventory.files mismatch against baseInv.files`);
      }
      if (targetInv) {
        assert.deepEqual(plan.target_inventory?.files, targetInv.files, `[${stepName}] Plan target_inventory.files mismatch against targetInv.files`);
      }

      const terminalStatePath = path.join(storageDir, 'update-state.json');
      assert.ok(fs.existsSync(terminalStatePath), `[${stepName}] Terminal update-state.json must exist`);
      assertNoSymlinks(terminalStatePath);
      const terminalState = JSON.parse(fs.readFileSync(terminalStatePath, 'utf8'));

      assert.equal(terminalState.state, 'completed', `[${stepName}] Terminal state must be completed`);
      assert.equal(terminalState.operation, 'channel_transition', `[${stepName}] Terminal operation must be channel_transition`);
      assert.equal(terminalState.transition_verified, true, `[${stepName}] Terminal transition_verified must be true`);
      assert.equal(terminalState.transition_receipt, capturedPrepareReceiptId, `[${stepName}] Terminal transition_receipt must match captured prepare receipt ID`);
      assert.equal(terminalState.phase, 'completed', `[${stepName}] Terminal phase must be completed`);
      assert.equal(terminalState.target_version, expectedTargetVersion, `[${stepName}] Terminal target_version mismatch`);
      assert.equal(terminalState.to_version, expectedTargetVersion, `[${stepName}] Terminal to_version mismatch`);
      assert.equal(terminalState.backup_snapshot, capturedSnapshotPath, `[${stepName}] Terminal backup_snapshot mismatch`);

      assert.ok(!terminalState.error, `[${stepName}] Terminal state must not have error`);
      assert.ok(!terminalState.primary_error, `[${stepName}] Terminal state must not have primary_error`);
      if (terminalState.secondary_errors !== undefined && terminalState.secondary_errors !== null) {
        if (Array.isArray(terminalState.secondary_errors)) {
          assert.equal(terminalState.secondary_errors.length, 0, `[${stepName}] Terminal secondary_errors array must be empty`);
        } else if (typeof terminalState.secondary_errors === 'object') {
          assert.equal(Object.keys(terminalState.secondary_errors).length, 0, `[${stepName}] Terminal secondary_errors object must be empty`);
        } else {
          assert.fail(`[${stepName}] Terminal secondary_errors must be empty or absent`);
        }
      }
      assert.equal(terminalState.requires_recovery, false, `[${stepName}] Terminal state requires_recovery must be false`);
      assert.ok(!terminalState.recovery_required, `[${stepName}] Terminal state must not have recovery_required`);
      assert.ok(!terminalState.recovery_in_progress, `[${stepName}] Terminal state must not have recovery_in_progress`);
      assert.ok(!terminalState.recovery_attempted, `[${stepName}] Terminal state must not have recovery_attempted`);
      assert.ok(!terminalState.recovery_failed, `[${stepName}] Terminal state must not have recovery_failed`);
      assert.ok(!terminalState.rollback, `[${stepName}] Terminal state must not have rollback`);
      assert.ok(!terminalState.rolled_back, `[${stepName}] Terminal state must not have rolled_back`);
      assert.ok(!terminalState.rollback_in_progress, `[${stepName}] Terminal state must not have rollback_in_progress`);
      assert.ok(!terminalState.rollback_attempted, `[${stepName}] Terminal state must not have rollback_attempted`);
      assert.ok(!terminalState.rollback_failed, `[${stepName}] Terminal state must not have rollback_failed`);
      assert.ok(!terminalState.db_restore_error, `[${stepName}] Terminal state must not have db_restore_error`);
      assert.ok(!terminalState.db_restored_from, `[${stepName}] Terminal state must not have db_restored_from`);
      assert.ok(!terminalState.failed, `[${stepName}] Terminal state must not have failed`);
      assert.ok(!terminalState.reason_code, `[${stepName}] Terminal state must not have reason_code`);

      assert.ok(typeof terminalState.owner_id === 'string' && terminalState.owner_id.length > 0, `[${stepName}] Terminal owner_id must be non-empty string`);
      const ownerMatch = terminalState.owner_id.match(/^(\d+)-(\d+)-([0-9a-f]+)$/);
      assert.ok(ownerMatch, `[${stepName}] Terminal owner_id must match Electron PID-timestamp-random pattern`);

      const ownerPid = Number(ownerMatch[1]);
      const ownerTs = Number(ownerMatch[2]);
      assert.ok(Number.isSafeInteger(ownerPid) && ownerPid > 0, `[${stepName}] ownerPid must be a positive safe integer`);
      assert.equal(ownerPid, capturedElectronPid, `[${stepName}] Terminal owner_id PID must match captured Electron main PID`);
      assert.ok(Number.isSafeInteger(ownerTs), `[${stepName}] Terminal owner_id timestamp must be a safe integer`);
      assert.ok(ownerTs >= transitionAt, `[${stepName}] Terminal owner_id timestamp must not be stale (>= transitionAt)`);
      assert.ok(ownerTs <= Date.now(), `[${stepName}] Terminal owner_id timestamp must not be in the future (<= Date.now())`);
      assert.notEqual(terminalState.owner_id, capturedPrepOwnerId, `[${stepName}] Terminal owner_id must be distinct from PHP preparer owner_id`);
      assert.ok(!witnessedNativeOwnerIds.has(terminalState.owner_id), `[${stepName}] Terminal owner_id must be unique across all transitions`);

      witnessedReceiptIds.add(capturedPrepareReceiptId);
      witnessedNativeOwnerIds.add(terminalState.owner_id);

      await transitionLab.system();
      await transitionLab.until(
        `document.querySelector('[data-testid="channel-transition"]')?.getAttribute('aria-busy') === 'false'`,
        'channel panel not busy after settlement'
      );
      assert.equal(
        await transitionLab.evaluate(`document.querySelector('[data-testid="channel-transition"]')?.getAttribute('aria-busy')`),
        'false',
        `[${stepName}] channel-transition panel aria-busy must be false after settlement`
      );

      verifyInventoryFiles(targetInv, baseInv);
      assert.equal(hasher(installedExePath), owned.candidate_exe_sha256, `[${stepName}] POS Desktop.exe altered`);
      assert.equal(hasher(installedAsarPath), owned.candidate_asar_sha256, `[${stepName}] app.asar altered`);

      const dbAfter = runPhpFixture('inspect-db', { 'config-dir': configDir, sentinel: SENTINEL });
      assert.equal(dbAfter.customer?.name, SENTINEL, `[${stepName}] Sentinel lost`);
      assert.equal(dbAfter.setting?.value, baselineDb.setting?.value, `[${stepName}] Setting lost`);
      assert.equal(dbAfter.admin_digest, baselineDb.admin_digest, `[${stepName}] Admin credentials altered`);
      assert.deepEqual(dbAfter.ledger_rows, baselineDb.ledger_rows, `[${stepName}] Schema versions rows altered`);
      assert.equal(dbAfter.schema_sha256, baselineDb.schema_sha256, `[${stepName}] Schema altered`);
      assert.equal(dbAfter.raw_schema_sha256, baselineDb.raw_schema_sha256, `[${stepName}] Raw schema altered`);

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

    assert.equal(witnessedReceiptIds.size, 4, 'All 4 transitions must have produced unique receipt IDs');
    assert.equal(witnessedNativeOwnerIds.size, 4, 'All 4 transitions must have used distinct native lease owner IDs');

    results.verifications.repeated_channel_roundtrip_verified = true;
    results.verifications.indexeddb_offline_preserved = true;
    console.log('PASS [Test 3] Both channel transition roundtrips verified.');

    if (transitionTransport) {
      console.log('Closing channel transition transport before Test 4 desktop SQL restore...');
      await transitionTransport.close();
      transitionTransport = null;
    }

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
    const dbBeforeFailed = runPhpFixture('inspect-db', {
      'config-dir': configDir,
      sentinel: MUTATED_SENTINEL,
      'setting-key': baselineDb.setting.key,
    });
    assert.equal(dbBeforeFailed.customer?.name, MUTATED_SENTINEL, 'Mutated sentinel must exist prior to failed restore');
    assert.ok(dbBeforeFailed.schema_sha256, 'Pre-failure schema hash missing');
    assert.ok(dbBeforeFailed.raw_schema_sha256, 'Pre-failure raw schema hash missing');
    assert.ok(dbBeforeFailed.ledger_sha256, 'Pre-failure ledger hash missing');
    assert.ok(dbBeforeFailed.ledger_rows?.length > 0, 'Pre-failure ledger rows missing');
    assert.ok(dbBeforeFailed.setting?.value, 'Pre-failure setting missing');
    assert.equal(dbBeforeFailed.setting?.key, baselineDb.setting.key, 'Pre-failure setting key must match baseline setting key');
    assert.equal(dbBeforeFailed.setting?.value, baselineDb.setting.value, 'Pre-failure setting value must match baseline setting value');
    assert.ok(dbBeforeFailed.admin_digest, 'Pre-failure admin digest missing');

    console.log('[Test 4D IndexedDB] Seeding actor-owned review offline fixture into IndexedDB pos_offline...');
    const offlineBefore4D = await seedOfflinePendingSale(transitionLab);
    assert.ok(offlineBefore4D && typeof offlineBefore4D === 'object', 'Test 4D offline sale fixture missing');
    assert.equal(offlineBefore4D.localId, 'codex-transition-pending', 'Test 4D offline sale localId mismatch');
    assert.equal(offlineBefore4D.syncStatus, 'review', 'Test 4D offline sale syncStatus must be review');

    console.log('[Test 4D Protected State] Capturing system protected state before failed restore...');
    const protectedBefore4D = await getSystemProtectedState(transitionLab);

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

    // Verify offline sale fixture in IndexedDB was preserved across failed restore and rollback
    const offlineAfter4D = await pendingSale(transitionLab, 'get');
    assert.ok(offlineAfter4D && typeof offlineAfter4D === 'object', 'Test 4D offline sale fixture missing after rollback');
    assert.deepEqual(offlineAfter4D, offlineBefore4D, 'Offline sale fixture in IndexedDB must be preserved after failed restore and rollback');

    // Verify system protected state was preserved across failed restore and rollback
    const protectedAfter4D = await getSystemProtectedState(transitionLab);
    assertProtectedStatePreserved(protectedBefore4D, protectedAfter4D, 'Test 4D Error-Path Restore Rollback');

    // Verify database integrity: original state retained via safety snapshot rollback (restored to mutated pre-attempt state)
    const dbAfterRollback = runPhpFixture('inspect-db', {
      'config-dir': configDir,
      sentinel: MUTATED_SENTINEL,
      'setting-key': baselineDb.setting.key,
    });
    assert.deepEqual(dbAfterRollback.customer, dbBeforeFailed.customer, 'Customer row must be restored to pre-failure state');
    assert.equal(dbAfterRollback.customer?.name, MUTATED_SENTINEL, 'Database customer row must retain MUTATED_SENTINEL after rollback');
    assert.equal(dbAfterRollback.schema_sha256, dbBeforeFailed.schema_sha256, 'Database schema must match pre-failure state');
    assert.equal(dbAfterRollback.raw_schema_sha256, dbBeforeFailed.raw_schema_sha256, 'Database raw schema must match pre-failure state');
    assert.equal(dbAfterRollback.ledger_sha256, dbBeforeFailed.ledger_sha256, 'Database ledger SHA256 must match pre-failure state');
    assert.equal(dbAfterRollback.setting?.key, dbBeforeFailed.setting?.key, 'Database setting key must match pre-failure state');
    assert.equal(dbAfterRollback.setting?.value, dbBeforeFailed.setting?.value, 'Database setting value must match pre-failure state');
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

    // ── TEST 5: Legacy v0.25 Database Backup Restore & Full Migration Upgrade ───
    console.log('\n[Test 5] Testing native desktop restore of legacy v0.25 backup and full migration upgrade...');

    legacyDiagnostic = {
      phase: 'prepare',
      result: 'in_progress',
      code: null,
      stage: null,
      has_reference: false,
      recovered: null,
      legacy_migrations_count: null,
      completed_migrations_count: null,
      latest_migration_present: null,
      protected_preserved: null,
      failing_migration: null,
      driver_errno: null,
    };
    persistLegacyDiagnostic({ phase: 'prepare' });

    // Close mock transport if open to ensure pure native desktop restore flow
    if (transitionTransport) {
      await transitionTransport.close().catch(() => {});
      transitionTransport = null;
    }

    // Capture fresh production backup endpoint SQL of exact pre-Test5 state for baseline reversion
    console.log('[Test 5 Baseline Capture] Capturing fresh production backup of pre-Test5 database state...');
    const pre5Sql = await transitionLab.evaluate(`(async () => {
      const base = (await window.posRuntime?.getApiBaseUrl?.()) || '';
      const res = await fetch(base + '/api/v1/backup', { credentials: 'include' });
      if (!res.ok) throw new Error('Backup download failed: ' + res.status);
      return await res.text();
    })()`);
    assert.ok(pre5Sql.length > 50, 'Pre-Test5 backup SQL too small');
    const pre5BackupPath = path.join(lab, 'pre-legacy-v025-baseline.sql');
    fs.writeFileSync(pre5BackupPath, pre5Sql, 'utf8');

    // Capture exact pre-Test5 database, protected, binary, and inventory state
    const pre5Db = runPhpFixture('inspect-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
      'setting-key': baselineDb.setting?.key,
    });
    assert.equal(pre5Db.ok, true, 'Pre-Test5 inspect-db failed');
    const pre5Protected = await getSystemProtectedState(transitionLab);
    const installedExePath = path.join(application, 'POS Desktop.exe');
    const installedAsarPath = path.join(application, 'resources/app.asar');
    assert.equal(hasher(installedExePath), owned.candidate_exe_sha256, 'Pre-Test5 POS Desktop.exe altered');
    assert.equal(hasher(installedAsarPath), owned.candidate_asar_sha256, 'Pre-Test5 app.asar altered');
    verifyInventoryFiles(candidate13DeltaInv);

    // Prepare legacy backup template with ephemeral fixture auth credentials
    const legacyBackupPath = path.join(lab, 'legacy-v025-backup.sql');
    const prepRes = runPhpFixture('prepare-legacy-v025-backup', {
      'auth-path': authPath,
      'output-path': legacyBackupPath,
    });
    assert.equal(prepRes.ok, true, 'prepare-legacy-v025-backup failed');
    assert.equal(prepRes.table_count, 27, 'Legacy template must have 27 tables');
    assert.equal(prepRes.version_count, 25, 'Legacy template must have 25 seed versions');
    assert.ok(fs.existsSync(legacyBackupPath), 'legacy-v025-backup.sql missing');
    persistLegacyDiagnostic({ legacy_migrations_count: prepRes.version_count });

    const safetyDir5 = path.join(storageDir, 'update-backups', 'migration-safety');
    fs.mkdirSync(safetyDir5, { recursive: true });

    async function executeTest5NativeRestore(label, backupPath, isLegacy = false) {
      console.log(`[Test 5 Native Restore] Seeding offline sale and setting pageRunId for ${label}...`);
      await seedOfflinePendingSale(transitionLab);

      const preExistingSafety = new Set(fs.readdirSync(safetyDir5));
      const pageRunIdBefore = `${label}_${Date.now()}`;
      await transitionLab.evaluate(`window.__pageRunId = ${JSON.stringify(pageRunIdBefore)}`);

      await transitionLab.mockOpenDialog(backupPath);

      let restoreContextLost = false;
      let restoreSettled = null;
      let failureSignalReject = null;
      const failureSignal = new Promise((_, reject) => {
        failureSignalReject = reject;
      });

      const restorePromise = transitionLab.evaluate('window.electronAPI.backup.restore()')
        .then((res) => {
          restoreSettled = res;
          if (res && res.success !== true) {
            const rawStage = typeof res.stage === 'string' ? res.stage : null;
            const boundedStage = ALLOWED_RESTORE_STAGES.has(rawStage) ? rawStage : null;
            const hasRef = Boolean(typeof res.reference === 'string' && /^[0-9a-f]{16}$/i.test(res.reference));
            const recovered = typeof res.recovered === 'boolean' ? res.recovered : null;
            persistLegacyDiagnostic({
              result: 'fail',
              code: 1,
              stage: boundedStage,
              has_reference: hasRef,
              recovered: recovered,
            });
            const errStage = boundedStage || 'unknown_stage';
            const rawCode = typeof res.code === 'string' && /^[A-Z0-9_\-]+$/.test(res.code) ? res.code : 'RESTORE_FAILED';
            const failErr = new Error(`${label} restore failed at stage [${errStage}] with code [${rawCode}]`);
            failErr.restoreResult = { code: rawCode, stage: errStage, has_reference: hasRef, recovered };
            if (failureSignalReject) failureSignalReject(failErr);
            return res;
          }
          return res;
        })
        .catch((err) => {
          restoreContextLost = /destroyed|cannot find context|navigated|target closed|session closed|protocol error/i.test(err?.message || '');
          if (!restoreContextLost) {
            if (failureSignalReject) failureSignalReject(err);
            throw err;
          }
          restoreSettled = { context_lost: true, error: true };
          return restoreSettled;
        });

      restorePromise.catch(() => {});

      const snapshot = await Promise.race([
        observeMigrationSafetySnapshot(safetyDir5, preExistingSafety),
        failureSignal,
      ]);

      await Promise.race([
        transitionLab.until('Boolean(document.querySelector("input[type=email]"))', `login form after ${label} reload`, 90000),
        failureSignal,
      ]);

      await restorePromise;

      if (restoreSettled && !restoreSettled.context_lost && restoreSettled.success !== true) {
        const rawStage = typeof restoreSettled.stage === 'string' ? restoreSettled.stage : null;
        const boundedStage = ALLOWED_RESTORE_STAGES.has(rawStage) ? rawStage : 'unknown_stage';
        const rawCode = typeof restoreSettled.code === 'string' && /^[A-Z0-9_\-]+$/.test(restoreSettled.code) ? restoreSettled.code : 'RESTORE_FAILED';
        assert.fail(`${label} restore failed at stage [${boundedStage}] with code [${rawCode}]`);
      }

      const pageRunIdAfter = await transitionLab.evaluate('window.__pageRunId');
      assert.notEqual(pageRunIdAfter, pageRunIdBefore, `window.__pageRunId must change post-reload after ${label}`);

      const emailInputPresent = await transitionLab.evaluate('Boolean(document.querySelector("input[type=email]"))');
      assert.equal(emailInputPresent, true, `Login email input must be present after ${label}`);
      const settingsLinkPresent = await transitionLab.evaluate("Boolean(document.querySelector(\"a[href='/settings']\"))");
      assert.equal(settingsLinkPresent, false, `Authenticated settings link must not be present after ${label}`);

      const offlineAfter = await pendingSale(transitionLab, 'get');
      assert.ok(offlineAfter === undefined || offlineAfter === null, `Restore must clear actor offline IndexedDB pending sales after ${label}`);

      assert.equal(fs.existsSync(snapshot.sqlPath), false, `Safety snapshot .sql must be deleted after successful ${label}`);
      assert.equal(fs.existsSync(snapshot.jsonPath), false, `Safety snapshot .sql.json must be deleted after successful ${label}`);

      for (const f of preExistingSafety) {
        assert.ok(fs.existsSync(path.join(safetyDir5, f)), `Preexisting safety snapshot ${f} must be preserved across ${label}`);
      }

      return { snapshot, contextLost: restoreContextLost, settled: restoreSettled };
    }

    // Execute legacy restore
    persistLegacyDiagnostic({ phase: 'nativeLegacy' });
    const legacyRestoreResult = await executeTest5NativeRestore('legacy_v025', legacyBackupPath, true);
    console.log('[Test 5] Legacy restore completed; context lost:', legacyRestoreResult.contextLost);

    // Verify protected state, binaries, and inventory unchanged across legacy restore
    const protectedAfterLegacy = await getSystemProtectedState(transitionLab);
    assertProtectedStatePreserved(pre5Protected, protectedAfterLegacy, 'Legacy v0.25 Restore');
    persistLegacyDiagnostic({ protected_preserved: true });
    assert.equal(hasher(installedExePath), owned.candidate_exe_sha256, 'POS Desktop.exe altered during legacy restore');
    assert.equal(hasher(installedAsarPath), owned.candidate_asar_sha256, 'app.asar altered during legacy restore');
    verifyInventoryFiles(candidate13DeltaInv);

    // Verify upgraded database state and migration reconciliation via PHP helper
    persistLegacyDiagnostic({ phase: 'legacyVerify' });
    console.log('[Test 5] Verifying upgraded database state and migration reconciliation...');
    const upgradeRes = runPhpFixture('verify-legacy-v025-upgrade', {
      'config-dir': configDir,
      'auth-path': authPath,
      'contract-path': path.join(lab, 'data-contract.json'),
    });
    assert.equal(upgradeRes.ok, true, 'verify-legacy-v025-upgrade failed');

    // Record probe counts and latest migration present before subsequent assertions may fail
    persistLegacyDiagnostic({
      completed_migrations_count: typeof upgradeRes.ledger_count === 'number' ? upgradeRes.ledger_count : null,
      latest_migration_present: upgradeRes.latest_migration_present === true || upgradeRes.exact_all_58_match === true,
    });

    assert.equal(upgradeRes.ledger_count, 58, 'Upgraded ledger count must be 58');
    assert.equal(upgradeRes.legacy_retained_count, 24, 'Legacy retained versions count must be 24');
    assert.equal(upgradeRes.pending_applied_count, 34, 'Pending applied migrations count must be 34');
    assert.equal(upgradeRes.exact_all_58_match, true, 'All 58 migration filenames must match database/migrations/*.sql');
    assert.equal(upgradeRes.latest_migration_present, true, 'Latest migration 059 must be present in ledger');
    assert.equal(upgradeRes.legacy_timestamps_preserved, true, 'Legacy 24 versions must retain 2000-01-01 timestamps');
    assert.equal(upgradeRes.pending_timestamps_new, true, 'Pending 34 versions must have new timestamps');
    assert.equal(upgradeRes.duplicate_016_gone, true, 'Duplicate 016_add_refresh_tokens must be reconciled');
    assert.equal(upgradeRes.legacy_migrations_table_dropped, true, 'Legacy migrations table must be dropped');
    assert.equal(upgradeRes.catalog_view_present, true, 'product_catalog_trigger_metadata view must be present');
    assert.equal(upgradeRes.catalog_triggers_present, true, 'All 3 required catalog triggers must be present');
    assert.equal(upgradeRes.customer_branch_inferred, true, 'Customer branch_id must be inferred');
    assert.equal(upgradeRes.supplier_branch_inferred, true, 'Supplier branch_id must be inferred');
    assert.equal(upgradeRes.products_unit_type_applied, true, 'Product unit_type must be applied');
    assert.equal(upgradeRes.inventory_fractional_applied, true, 'Inventory events fractional decimal must be applied');
    assert.equal(upgradeRes.catalog_changes_present, true, 'Product catalog changes table must be present');
    assert.equal(upgradeRes.admin_verified, true, 'Admin user credentials and password must be verified against fixture-auth.json');
    assert.equal(upgradeRes.data_preserved, true, 'Representative business data and relations must be preserved');
    assert.equal(upgradeRes.schema_matches_contract, true, 'Upgraded schema SHA256 must match contract');
    assert.equal(upgradeRes.ledger_matches_contract, true, 'Upgraded ledger SHA256 must match contract');

    // Login with auth credentials on upgraded database
    console.log('[Test 5] Logging into desktop app after legacy restore and upgrade...');
    await transitionLab.login(auth.email, auth.password);
    await transitionLab.until("Boolean(document.querySelector(\"a[href='/settings']\"))", 'authenticated navigation after legacy restore', 60000);
    await transitionLab.screenshot('legacy-v025-upgraded');

    // Clean up: Restore fresh baseline backup using same strict patterns to revert database
    persistLegacyDiagnostic({ phase: 'baselineBack' });
    console.log('[Test 5 Baseline Reversion] Restoring fresh pre-Test5 baseline backup to revert database...');
    const baselineRestoreResult = await executeTest5NativeRestore('baseline_cleanup', pre5BackupPath, false);
    console.log('[Test 5 Baseline Reversion] Baseline restore completed; context lost:', baselineRestoreResult.contextLost);

    persistLegacyDiagnostic({ phase: 'finalVerify' });
    await transitionLab.login(auth.email, auth.password);
    await transitionLab.until("Boolean(document.querySelector(\"a[href='/settings']\"))", 'authenticated navigation after baseline cleanup', 60000);

    const dbPostTest5 = runPhpFixture('inspect-db', {
      'config-dir': configDir,
      sentinel: SENTINEL,
      'setting-key': baselineDb.setting?.key,
    });
    assert.equal(dbPostTest5.ok, true, 'inspect-db after baseline recovery failed');
    assert.equal(dbPostTest5.customer?.name, SENTINEL, 'Baseline sentinel customer row must match pre5');
    assert.equal(dbPostTest5.setting?.value, pre5Db.setting?.value, 'Baseline setting value must match pre5');
    assert.equal(dbPostTest5.admin_digest, pre5Db.admin_digest, 'Admin digest must match pre5');
    assert.deepEqual(dbPostTest5.ledger_rows, pre5Db.ledger_rows, 'Raw ledger rows must match pre5');
    assert.equal(dbPostTest5.schema_sha256, pre5Db.schema_sha256, 'Canonical schema SHA256 must match pre5');
    assert.equal(dbPostTest5.raw_schema_sha256, pre5Db.raw_schema_sha256, 'Raw schema SHA256 must match pre5');
    assert.equal(dbPostTest5.ledger_sha256, pre5Db.ledger_sha256, 'Ledger SHA256 must match pre5');

    const protectedPostTest5 = await getSystemProtectedState(transitionLab);
    assertProtectedStatePreserved(pre5Protected, protectedPostTest5, 'Post-Test5 Baseline Recovery');
    assert.equal(hasher(installedExePath), owned.candidate_exe_sha256, 'POS Desktop.exe altered during baseline recovery');
    assert.equal(hasher(installedAsarPath), owned.candidate_asar_sha256, 'app.asar altered during baseline recovery');
    verifyInventoryFiles(candidate13DeltaInv);

    persistLegacyDiagnostic({
      phase: 'completed',
      result: 'pass',
      code: 0,
      stage: 'completed',
      recovered: null,
      has_reference: false,
    });

    results.verifications.legacy_v025_restore_migrations_verified = true;
    results.details.legacy_v025_restore = {
      outcome: 'SUCCESS',
      legacy_retained_count: upgradeRes.legacy_retained_count,
      pending_applied_count: upgradeRes.pending_applied_count,
      total_ledger_count: upgradeRes.ledger_count,
      exact_all_58_match: upgradeRes.exact_all_58_match,
      latest_migration_present: upgradeRes.latest_migration_present,
      duplicate_reconciled: upgradeRes.duplicate_reconciled,
      migrations_table_dropped: upgradeRes.legacy_migrations_table_dropped,
      schema_matches_contract: upgradeRes.schema_matches_contract,
      ledger_matches_contract: upgradeRes.ledger_matches_contract,
    };
    console.log('PASS [Test 5] Legacy v0.25 database backup restore and full migration upgrade verified.');
  } finally {
    if (transitionTransport) {
      await transitionTransport.close().catch(() => {});
      transitionTransport = null;
    }
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
    persistSchemaDiagnostics();
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

    if (legacyDiagnostic && legacyDiagnostic.result !== 'pass') {
      let failMig = legacyDiagnostic.failing_migration;
      let failErrno = legacyDiagnostic.driver_errno;
      if (!failMig && typeof error?.message === 'string') {
        const migMatch = error.message.match(/0[0-9]{2}_[a-zA-Z0-9_\-]+\.sql/);
        if (migMatch && KNOWN_MIGRATION_FILENAMES.has(migMatch[0])) {
          failMig = migMatch[0];
        }
      }
      if (!failErrno && typeof error?.message === 'string') {
        const errMatch = error.message.match(/(?:errno|error):\s*([0-9]{4,5})/i) ||
                         error.message.match(/SQLSTATE\[[A-Z0-9]+\]:\s*(?:General error:\s*)?([0-9]{4,5})/i);
        if (errMatch) {
          const parsed = parseInt(errMatch[1], 10);
          if (parsed > 0 && parsed < 100000) failErrno = parsed;
        }
      }
      const failStage = ALLOWED_LEGACY_STAGES.has(legacyDiagnostic.stage)
        ? legacyDiagnostic.stage
        : (ALLOWED_LEGACY_PHASES.has(legacyDiagnostic.phase) ? legacyDiagnostic.phase : null);
      persistLegacyDiagnostic({
        result: 'fail',
        code: 1,
        stage: failStage,
        failing_migration: failMig,
        driver_errno: failErrno,
      });
    }
  } catch (diagErr) {
    console.error('Failed to capture diagnostics:', diagErr.message);
  }
  throw error;
}
