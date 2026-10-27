#!/usr/bin/env node
/**
 * scripts/verify-setup12-release-readiness.mjs
 *
 * Standalone, dependency-free release readiness verifier for Setup 0.0.12.
 * Validates:
 *   1. Consistent version metadata (0.0.12 / stable / setup / 2026-10-27) across all 5 package files
 *   2. Strict byte/hash equality against pinned approved hashes for 3 production Electron files;
 *      optional comparison against local reference if available (no absolute devtree dependency in CI).
 *   3. Git ancestry (HEAD contains published Beta 12 9124ce7e2a338662611c11d903c12b99d6168999)
 *   4. Exact backend and frontend source retention vs published Beta 12 (9124ce7) with package metadata-only diffs
 *   5. Workflow safety contracts (publish=false default, ephemeral runner guards, dual baseline upgrade phases, delta upgrade smoke, readiness gate)
 *   6. Static PowerShell syntax parsing (using safe parser API, no execution)
 *   7. Windows ACL fixture SDDL normalization and cleanup comparison regression (pure fixtures, zero local ACL changes)
 *
 * Requires NO node_modules, no built artifacts, no database connection, and executes no installers.
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

const repoRoot = process.cwd();
const localReferenceRoot = 'C:/xampp/htdocs/pos';
const baseCommit = '9124ce7e2a338662611c11d903c12b99d6168999';

function sha256(content) {
  return crypto.createHash('sha256').update(content).digest('hex');
}

function readJson(relPath) {
  const full = path.join(repoRoot, relPath);
  assert.ok(fs.existsSync(full), `Required file missing: ${relPath}`);
  return JSON.parse(fs.readFileSync(full, 'utf8'));
}

console.log('--- POS Desktop Setup 0.0.12 Release Readiness Verification ---');

// 1. Consistent Version Metadata across all manifests
console.log('[1/7] Verifying consistent version metadata (0.0.12 / stable / setup)...');
const targetVersion = '0.0.12';
const versionJson = readJson('version.json');
const rootPkg = readJson('package.json');
const rootLock = readJson('package-lock.json');
const frontendPkg = readJson('frontend/package.json');
const frontendLock = readJson('frontend/package-lock.json');

assert.equal(versionJson.version, targetVersion, 'version.json version mismatch');
assert.equal(versionJson.application_version, targetVersion, 'version.json application_version mismatch');
assert.equal(versionJson.installer_version, targetVersion, 'version.json installer_version mismatch');
assert.equal(versionJson.channel, 'stable', 'version.json channel must be stable');
assert.equal(versionJson.update_channel, 'stable', 'version.json update_channel must be stable');
assert.equal(versionJson.release_type, 'setup', 'version.json release_type must be setup');
assert.equal(versionJson.update_engine_version, '1.0.0', 'version.json update_engine_version must be 1.0.0');
assert.equal(versionJson.released_at, '2026-10-27', 'version.json released_at must be 2026-10-27');
assert.equal(versionJson.minimum_supported_version, '0.0.1', 'version.json minimum_supported_version must be 0.0.1');
assert.equal(versionJson.requires_npm_install, false, 'version.json requires_npm_install must be false');
assert.equal(versionJson.delta_baseline_tags, undefined, 'version.json must not include delta_baseline_tags in setup release');

assert.equal(rootPkg.version, targetVersion, 'package.json version mismatch');
assert.equal(rootPkg.build?.appId, 'com.abdoteck.pos', 'package.json build.appId must not change');
assert.equal(rootPkg.build?.productName, 'POS Desktop', 'package.json build.productName must not change');
assert.equal(rootPkg.build?.directories?.output, 'dist-electron', 'package.json build.directories.output must not change');

// Exclusions in build.files
const buildFiles = rootPkg.build?.files || [];
assert.ok(buildFiles.includes('!electron/assets/ssl/**'), 'package.json build.files must exclude ssl assets');
assert.ok(buildFiles.includes('!**/*.key'), 'package.json build.files must exclude .key files');

assert.equal(rootLock.version, targetVersion, 'package-lock.json root version mismatch');
assert.equal(rootLock.packages?.['']?.version, targetVersion, 'package-lock.json packages[""].version mismatch');

assert.equal(frontendPkg.version, targetVersion, 'frontend/package.json version mismatch');
assert.equal(frontendLock.version, targetVersion, 'frontend/package-lock.json root version mismatch');
assert.equal(frontendLock.packages?.['']?.version, targetVersion, 'frontend/package-lock.json packages[""].version mismatch');
console.log('      PASS: Metadata 0.0.12 strictly verified across 5 manifest files.');

// 2. Strict Approved Hashes for 3 Production Electron Files + Portable Reference Check
console.log('[2/7] Verifying pinned approved hashes for 3 production Electron files...');
const PINNED_APPROVED_ELECTRON_HASHES = {
  'electron/main.js': '56306d1a88c60cab4ce8d996907007a58eeb6661025975e2c3db1b53ace83aa0',
  'electron/services/delta-update-handoff.js': '48edddd8b214a025c5ad230f3c33c7a2db5577eaeddff5289eec8c3618ae8c71',
  'electron/services/php-server.js': '98f68d87976702b3e4689acaa593e64c304b39a18be4d311378383f9f719a6b3',
};

for (const [rel, expectedHash] of Object.entries(PINNED_APPROVED_ELECTRON_HASHES)) {
  const localFile = path.join(repoRoot, rel);
  assert.ok(fs.existsSync(localFile), `Production file missing: ${rel}`);
  const actualHash = sha256(fs.readFileSync(localFile));
  assert.equal(actualHash, expectedHash, `Hash mismatch for production file ${rel}: expected ${expectedHash}, got ${actualHash}`);
}

// Check accompanying test and doc files
const recoveryTestFile = path.join(repoRoot, 'scripts/verify-delta-handoff-recovery.mjs');
assert.ok(fs.existsSync(recoveryTestFile), 'verify-delta-handoff-recovery.mjs missing');

const windowsLabScript = path.join(repoRoot, 'scripts/verify-delta-handoff-windows.ps1');
assert.ok(fs.existsSync(windowsLabScript), 'verify-delta-handoff-windows.ps1 missing');
const labContent = fs.readFileSync(windowsLabScript, 'utf8');
assert.ok(
  labContent.includes('$env:GITHUB_ACTIONS -ne \'true\' -or -not $env:RUNNER_TEMP'),
  'verify-delta-handoff-windows.ps1 must contain CI-only execution guard'
);

const recoveryDoc = path.join(repoRoot, 'docs/windows-delta-recovery.md');
assert.ok(fs.existsSync(recoveryDoc), 'docs/windows-delta-recovery.md missing');

// Optional local reference comparison if running on workstation with C:/xampp/htdocs/pos available
if (fs.existsSync(localReferenceRoot)) {
  for (const [rel, expectedHash] of Object.entries(PINNED_APPROVED_ELECTRON_HASHES)) {
    const refFile = path.join(localReferenceRoot, rel);
    if (fs.existsSync(refFile)) {
      const refHash = sha256(fs.readFileSync(refFile));
      assert.equal(refHash, expectedHash, `Local reference ${refFile} does not match approved hash ${expectedHash}`);
    }
  }
  console.log('      PASS: 3 production Electron files match pinned approved hashes and local reference bytes exactly.');
} else {
  console.log('      PASS: 3 production Electron files match pinned approved hashes strictly (portable CI mode).');
}

// 3. Git Ancestry & Published Beta 12 Basis Verification
console.log(`[3/7] Verifying git ancestry and commit heritage vs Beta 12 (${baseCommit.slice(0, 7)})...`);
try {
  execFileSync('git', ['merge-base', '--is-ancestor', baseCommit, 'HEAD'], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe']
  });
  console.log(`      PASS: HEAD has published Beta 12 (${baseCommit.slice(0, 7)}) in its ancestry.`);
} catch (e) {
  throw new Error(`Git ancestry check failed: HEAD must include published Beta 12 (${baseCommit})`);
}

// 4. Exact Native, Runtime, Backend and Frontend Source & Dependency Retention vs Published Beta 12
console.log(`[4/7] Verifying exact native, runtime, backend and frontend source & dependency retention vs Beta 12 (${baseCommit.slice(0, 7)})...`);

// A. Native shell and build input trees must have 0 diff against published Beta 12
for (const relPath of ['electron/', 'build/', 'backend/', 'frontend/src/', 'scripts/desktop-runtime.json']) {
  const diff = execFileSync('git', ['diff', baseCommit, '--stat', relPath], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe']
  }).trim();
  assert.equal(diff, '', `${relPath} must have zero diff against Beta 12 (${baseCommit}), found: ${diff}`);
}
console.log('      PASS: electron/, build/, backend/, frontend/src/, scripts/desktop-runtime.json have 0 source drift.');

// B. Structured Package and Lock JSON deep equality vs Beta 12 (normalized only on approved version fields)
function getBaseJson(rel) {
  return JSON.parse(execFileSync('git', ['show', `${baseCommit}:${rel}`], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe']
  }));
}

// 1. package.json: only version differs
const curPkg = readJson('package.json');
const basePkg = getBaseJson('package.json');
assert.equal(curPkg.version, targetVersion, 'package.json version mismatch');
const curPkgNorm = JSON.parse(JSON.stringify(curPkg));
const basePkgNorm = JSON.parse(JSON.stringify(basePkg));
curPkgNorm.version = 'NORMALIZED';
basePkgNorm.version = 'NORMALIZED';
assert.deepEqual(curPkgNorm, basePkgNorm, 'package.json has unexpected changes beyond version bump vs Beta 12');

// 2. package-lock.json: only root and packages[""] version differ
const curLock = readJson('package-lock.json');
const baseLock = getBaseJson('package-lock.json');
assert.equal(curLock.version, targetVersion, 'package-lock.json version mismatch');
assert.equal(curLock.packages?.['']?.version, targetVersion, 'package-lock.json packages[""].version mismatch');
const curLockNorm = JSON.parse(JSON.stringify(curLock));
const baseLockNorm = JSON.parse(JSON.stringify(baseLock));
curLockNorm.version = 'NORMALIZED';
curLockNorm.packages[''].version = 'NORMALIZED';
baseLockNorm.version = 'NORMALIZED';
baseLockNorm.packages[''].version = 'NORMALIZED';
assert.deepEqual(curLockNorm, baseLockNorm, 'package-lock.json has unexpected dependency or integrity changes vs Beta 12');

// 3. frontend/package.json: only version differs
const curFPkg = readJson('frontend/package.json');
const baseFPkg = getBaseJson('frontend/package.json');
assert.equal(curFPkg.version, targetVersion, 'frontend/package.json version mismatch');
const curFPkgNorm = JSON.parse(JSON.stringify(curFPkg));
const baseFPkgNorm = JSON.parse(JSON.stringify(baseFPkg));
curFPkgNorm.version = 'NORMALIZED';
baseFPkgNorm.version = 'NORMALIZED';
assert.deepEqual(curFPkgNorm, baseFPkgNorm, 'frontend/package.json has unexpected changes beyond version bump vs Beta 12');

// 4. frontend/package-lock.json: only root and packages[""] version differ
const curFLock = readJson('frontend/package-lock.json');
const baseFLock = getBaseJson('frontend/package-lock.json');
assert.equal(curFLock.version, targetVersion, 'frontend/package-lock.json version mismatch');
assert.equal(curFLock.packages?.['']?.version, targetVersion, 'frontend/package-lock.json packages[""].version mismatch');
const curFLockNorm = JSON.parse(JSON.stringify(curFLock));
const baseFLockNorm = JSON.parse(JSON.stringify(baseFLock));
curFLockNorm.version = 'NORMALIZED';
curFLockNorm.packages[''].version = 'NORMALIZED';
baseFLockNorm.version = 'NORMALIZED';
baseFLockNorm.packages[''].version = 'NORMALIZED';
assert.deepEqual(curFLockNorm, baseFLockNorm, 'frontend/package-lock.json has unexpected dependency or integrity changes vs Beta 12');

console.log('      PASS: Structured package.json and lockfile dependencies byte-for-byte retained (0 dependency drift).');

// 5. Workflow Gate & Upgrade Safety Contracts
console.log('[5/7] Verifying GitHub Actions workflow gates and upgrade contracts...');
const workflowPath = path.join(repoRoot, '.github/workflows/windows-setup.yml');
assert.ok(fs.existsSync(workflowPath), '.github/workflows/windows-setup.yml missing');
const workflowContent = fs.readFileSync(workflowPath, 'utf8');

assert.ok(/publish:\s*\n\s*description:[^\n]+\n\s*type:\s*boolean\n\s*default:\s*false/.test(workflowContent), 'windows-setup.yml publish input must default to false');
assert.ok(/publish:\s*\n\s*if:\s*inputs\.publish/.test(workflowContent), 'publish job must be gated by inputs.publish');
assert.ok(workflowContent.includes('scripts/verify-setup12-release-readiness.mjs'), 'windows-setup.yml must run verify-setup12-release-readiness.mjs');
assert.ok(workflowContent.includes('scripts/verify-original-setup-upgrade.ps1'), 'windows-setup.yml must run verify-original-setup-upgrade.ps1');
assert.ok(workflowContent.includes('-BaselineVersion 0.0.1'), 'windows-setup.yml must run baseline 0.0.1 upgrade check');
assert.ok(workflowContent.includes('-BaselineVersion 0.0.11'), 'windows-setup.yml must run baseline 0.0.11 upgrade check');
assert.ok(workflowContent.includes('scripts/verify-setup12-delta-upgrade.mjs'), 'windows-setup.yml must run verify-setup12-delta-upgrade.mjs');
assert.ok(workflowContent.includes('scripts/verify-setup-recovery.mjs'), 'windows-setup.yml must run verify-setup-recovery.mjs');
assert.ok(workflowContent.includes('scripts/verify-delta-handoff-recovery.mjs'), 'windows-setup.yml must run verify-delta-handoff-recovery.mjs');
assert.ok(workflowContent.includes('scripts/verify-delta-handoff-windows.ps1'), 'windows-setup.yml must run verify-delta-handoff-windows.ps1');
assert.ok(workflowContent.includes('scripts/verify-catalog-trigger-recovery.php'), 'windows-setup.yml must run verify-catalog-trigger-recovery.php');
assert.ok(workflowContent.includes('scripts/verify-admin-update-center.php'), 'windows-setup.yml must run verify-admin-update-center.php');
assert.ok(workflowContent.includes('pdo_sqlite'), 'windows-setup.yml must include pdo_sqlite in setup-php extensions');

// CI step ordering regression: Prepare portable runtime must precede isolated tests, and tests must precede Build Setup
const cacheStepIndex = workflowContent.indexOf('Cache pinned vendor archives');
const prepareStepIndex = workflowContent.indexOf('Prepare portable PHP, MariaDB, Java and QZ Tray');
const isolatedTestsStepIndex = workflowContent.indexOf('Verify stable and beta delta flows in isolated deployments');
const buildSetupStepIndex = workflowContent.indexOf('Build Windows x64 Setup');

assert.ok(cacheStepIndex !== -1, 'windows-setup.yml must contain Cache pinned vendor archives step');
assert.ok(prepareStepIndex !== -1, 'windows-setup.yml must contain Prepare portable step');
assert.ok(isolatedTestsStepIndex !== -1, 'windows-setup.yml must contain isolated deployment tests step');
assert.ok(buildSetupStepIndex !== -1, 'windows-setup.yml must contain Build Setup step');

assert.ok(cacheStepIndex < prepareStepIndex, 'windows-setup.yml: Cache pinned vendor archives must precede Prepare portable runtime');
assert.ok(prepareStepIndex < isolatedTestsStepIndex, 'windows-setup.yml: Prepare portable runtime must precede isolated deployment tests');
assert.ok(isolatedTestsStepIndex < buildSetupStepIndex, 'windows-setup.yml: Isolated deployment tests must precede Build Windows x64 Setup');

// Verify upgrade script contracts
const upgradeScriptPath = path.join(repoRoot, 'scripts/verify-original-setup-upgrade.ps1');
assert.ok(fs.existsSync(upgradeScriptPath), 'scripts/verify-original-setup-upgrade.ps1 missing');
const upgradeScriptContent = fs.readFileSync(upgradeScriptPath, 'utf8');

assert.ok(upgradeScriptContent.includes('$env:GITHUB_ACTIONS -ne \'true\' -or -not $env:RUNNER_TEMP'), 'upgrade script must enforce GITHUB_ACTIONS and RUNNER_TEMP guard');
assert.ok(upgradeScriptContent.includes('2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a'), 'upgrade script must require original 0.0.1 SHA256');
assert.ok(upgradeScriptContent.includes('4abfbd98898bf314d1531834166ec8f682710b90386920e7d71924e7fc92357d'), 'upgrade script must require original 0.0.11 SHA256');
assert.ok(upgradeScriptContent.includes('Get-PosCleanupTargets'), 'upgrade script must check cleanup targets before running');
assert.ok(upgradeScriptContent.includes('Uninstall-Pos'), 'upgrade script must clean up owned fixture via uninstallation');
assert.ok(upgradeScriptContent.includes('channel-transition-capability.php'), 'upgrade script must use channel-transition-capability.php fixture');
assert.ok(upgradeScriptContent.includes('prepare-compatibility'), 'upgrade script must prepare compatibility contract');
assert.ok(upgradeScriptContent.includes('admin_digest'), 'upgrade script must assert admin digest');
assert.ok(upgradeScriptContent.includes('qz-certs'), 'upgrade script must assert qz-certs pair');
assert.ok(upgradeScriptContent.includes('schema_sha256'), 'upgrade script must assert schema_sha256');
assert.ok(upgradeScriptContent.includes('ledger_sha256'), 'upgrade script must assert ledger_sha256');

// Verify delta upgrade smoke script exists
const deltaSmokeScriptPath = path.join(repoRoot, 'scripts/verify-setup12-delta-upgrade.mjs');
assert.ok(fs.existsSync(deltaSmokeScriptPath), 'scripts/verify-setup12-delta-upgrade.mjs missing');
const deltaSmokeScriptContent = fs.readFileSync(deltaSmokeScriptPath, 'utf8');
assert.ok(deltaSmokeScriptContent.includes('GITHUB_ACTIONS'), 'delta smoke script must enforce GITHUB_ACTIONS guard');
assert.ok(deltaSmokeScriptContent.includes('UPDATE_PRIVATE_KEY'), 'delta smoke script must enforce UPDATE_PRIVATE_KEY secret requirement');

// Verify publisher script exists and contains hardened contracts
const publisherScriptPath = path.join(repoRoot, 'scripts/publish-setup-release.mjs');
assert.ok(fs.existsSync(publisherScriptPath), 'scripts/publish-setup-release.mjs missing');
const publisherContent = fs.readFileSync(publisherScriptPath, 'utf8');
assert.ok(publisherContent.includes('target_commitish'), 'publisher must enforce target_commitish');
assert.ok(publisherContent.includes('ABDO-TECK/pos-desktop'), 'publisher must enforce exact repository guard');
assert.ok(publisherContent.includes('RUNNER_TEMP'), 'publisher must enforce RUNNER_TEMP boundary guard');
assert.ok(!publisherContent.includes("method: 'DELETE'"), 'publisher must contain no DELETE release or asset paths');

// Verify workflow runs hardened publisher and finalizes SHA256SUMS covering all 4 assets
assert.ok(workflowContent.includes('scripts/publish-setup-release.mjs'), 'windows-setup.yml publish job must execute publish-setup-release.mjs');
assert.ok(workflowContent.includes('Finalize SHA256SUMS.txt covering all release assets'), 'windows-setup.yml must finalize SHA256SUMS covering all 4 release assets before upload');

// Verify lifecycle script guard
const lifecycleScriptContent = fs.readFileSync(path.join(repoRoot, 'scripts/verify-installer-lifecycle.ps1'), 'utf8');
assert.ok(lifecycleScriptContent.includes('$env:GITHUB_ACTIONS -ne \'true\' -or -not $env:RUNNER_TEMP'), 'lifecycle script must enforce GITHUB_ACTIONS and RUNNER_TEMP guard');
console.log('      PASS: CI workflow gates, readiness gate, dual upgrades, delta smoke, and hardened publisher contracts verified.');

// 6. Safe Static PowerShell Syntax Parsing (No execution)
console.log('[6/7] Parsing PowerShell scripts statically via PowerShell Parser API (no execution)...');
const psScripts = [
  'scripts/verify-original-setup-upgrade.ps1',
  'scripts/verify-installer-lifecycle.ps1',
  'scripts/verify-delta-handoff-windows.ps1',
  'scripts/verify-desktop-release.ps1',
  'scripts/verify-uninstall-data.ps1',
  'build/uninstall-data.ps1',
];

const psCommand = process.platform === 'win32' ? 'powershell.exe' : 'pwsh';

for (const rel of psScripts) {
  const fullPath = path.join(repoRoot, rel);
  assert.ok(fs.existsSync(fullPath), `PowerShell file missing: ${rel}`);

  const parseScript = `
    $errors = $null
    [System.Management.Automation.Language.Parser]::ParseFile('${fullPath.replace(/'/g, "''")}', [ref]$null, [ref]$errors) | Out-Null
    if ($errors -and $errors.Count -gt 0) {
      $errors | ForEach-Object { Write-Error $_.Message }
      exit 1
    }
    exit 0
  `;

  try {
    execFileSync(psCommand, ['-NoProfile', '-NonInteractive', '-Command', parseScript], {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe']
    });
  } catch (err) {
    throw new Error(`PowerShell static parse error in ${rel}: ${err.stderr || err.stdout || err.message}`);
  }
}
console.log(`      PASS: All ${psScripts.length} PowerShell scripts parsed cleanly with 0 syntax errors.`);

// 7. Windows ACL Fixture SDDL Normalization & Semantic Equivalence Regression (Pure in-memory fixtures, no local ACL changes)
console.log('[7/7] Verifying Windows ACL fixture SDDL normalization and semantic comparison regression (pure fixtures)...');

function normalizeSddlDescriptorDaclAi(sddl) {
  if (!sddl || typeof sddl !== 'string') return sddl;
  const match = sddl.match(/^(.*?D:)([^()S]*?)(\(.*|S:.*|$)$/);
  if (match) {
    const prefix = match[1];
    const flags = match[2].replace(/AI/g, '');
    const rest = match[3];
    return `${prefix}${flags}${rest}`;
  }
  return sddl;
}

// Fixtures observed on real Windows GitHub Actions runner (run 40141680363)
const FIXTURE_ORIGINAL_SDDL = 'O:BAG:S-1-5-21-3776380004-1005456608-3524014338-513D:(A;OICIID;FA;;;SY)(A;OICIID;FA;;;BA)(A;OICIID;FA;;;LA)';
const FIXTURE_RESTORED_SDDL_AI = 'O:BAG:S-1-5-21-3776380004-1005456608-3524014338-513D:AI(A;OICIID;FA;;;SY)(A;OICIID;FA;;;BA)(A;OICIID;FA;;;LA)';

// 7.1 Verify raw fixture strings differ only by the recomputed auto-inherited AI flag in the DACL control header
assert.notEqual(FIXTURE_ORIGINAL_SDDL, FIXTURE_RESTORED_SDDL_AI, 'Raw fixture strings must differ before normalization');
const normalizedOriginal = normalizeSddlDescriptorDaclAi(FIXTURE_ORIGINAL_SDDL);
const normalizedRestored = normalizeSddlDescriptorDaclAi(FIXTURE_RESTORED_SDDL_AI);
assert.equal(normalizedRestored, normalizedOriginal, 'Normalized restored SDDL must equal normalized original SDDL');

// 7.2 Verify normalization preserves protection (P) and auto-inherit-required (AR) descriptor flags
assert.equal(
  normalizeSddlDescriptorDaclAi('O:BAG:BAD:PAI(A;;FA;;;SY)'),
  'O:BAG:BAD:P(A;;FA;;;SY)',
  'DACL protection flag (P) must be strictly preserved'
);
assert.equal(
  normalizeSddlDescriptorDaclAi('O:BAG:BAD:ARAI(A;;FA;;;SY)'),
  'O:BAG:BAD:AR(A;;FA;;;SY)',
  'DACL auto-inherit-required flag (AR) must be strictly preserved'
);

// 7.3 Verify ACE inheritance flags inside parentheses remain untouched
assert.ok(normalizedRestored.includes('(A;OICIID;FA;;;SY)'), 'ACE flags inside parens must remain intact');

// 7.4 Verify detection of genuine security drifts (must never match normalized original)
const genuineDrifts = [
  'O:BAG:S-1-5-21-3776380004-1005456608-3524014338-513D:AI(D;OICI;FA;;;LA)(A;OICIID;FA;;;SY)(A;OICIID;FA;;;BA)(A;OICIID;FA;;;LA)',
  'O:BAG:S-1-5-21-3776380004-1005456608-3524014338-513D:PAI(A;OICIID;FA;;;SY)(A;OICIID;FA;;;BA)(A;OICIID;FA;;;LA)',
  'O:BAG:S-1-5-21-3776380004-1005456608-3524014338-513D:AI(A;OICIID;FR;;;SY)(A;OICIID;FA;;;BA)(A;OICIID;FA;;;LA)',
  'O:BAG:S-1-5-21-3776380004-1005456608-3524014338-513D:AI(A;OICIID;FA;;;WD)(A;OICIID;FA;;;BA)(A;OICIID;FA;;;LA)',
  'O:SYG:S-1-5-21-3776380004-1005456608-3524014338-513D:AI(A;OICIID;FA;;;SY)(A;OICIID;FA;;;BA)(A;OICIID;FA;;;LA)',
  'O:BAG:BAD:AI(A;OICIID;FA;;;SY)(A;OICIID;FA;;;BA)(A;OICIID;FA;;;LA)',
];

for (const drift of genuineDrifts) {
  assert.notEqual(
    normalizeSddlDescriptorDaclAi(drift),
    normalizedOriginal,
    `Normalization must NOT mask genuine security drift: ${drift}`
  );
}

// 7.5 Pure in-memory .NET DirectorySecurity test via PowerShell (no disk files or actual Windows ACL changes)
const dotNetTestScript = `
  $origFixture = '${FIXTURE_ORIGINAL_SDDL}'
  $restFixture = '${FIXTURE_RESTORED_SDDL_AI}'
  $denyFixture = 'O:BAG:S-1-5-21-3776380004-1005456608-3524014338-513D:AI(D;OICI;FA;;;LA)(A;OICIID;FA;;;SY)(A;OICIID;FA;;;BA)(A;OICIID;FA;;;LA)'

  $dsOrig = New-Object System.Security.AccessControl.DirectorySecurity
  $dsOrig.SetSecurityDescriptorSddlForm($origFixture)

  $dsRest = New-Object System.Security.AccessControl.DirectorySecurity
  $dsRest.SetSecurityDescriptorSddlForm($restFixture)

  $dsDeny = New-Object System.Security.AccessControl.DirectorySecurity
  $dsDeny.SetSecurityDescriptorSddlForm($denyFixture)

  if ($dsOrig.GetOwner([System.Security.Principal.SecurityIdentifier]).Value -ne $dsRest.GetOwner([System.Security.Principal.SecurityIdentifier]).Value) {
    throw 'Owner mismatch in in-memory test'
  }
  if ($dsOrig.GetGroup([System.Security.Principal.SecurityIdentifier]).Value -ne $dsRest.GetGroup([System.Security.Principal.SecurityIdentifier]).Value) {
    throw 'Group mismatch in in-memory test'
  }
  if ($dsOrig.AreAccessRulesProtected -ne $dsRest.AreAccessRulesProtected) {
    throw 'Protection mismatch in in-memory test'
  }

  $denyCount = @($dsDeny.Access | Where-Object { $_.AccessControlType -eq [System.Security.AccessControl.AccessControlType]::Deny }).Count
  if ($denyCount -ne 1) {
    throw "Expected 1 deny ACE in test fixture, got $denyCount"
  }
  $restDenyCount = @($dsRest.Access | Where-Object { $_.AccessControlType -eq [System.Security.AccessControl.AccessControlType]::Deny }).Count
  if ($restDenyCount -ne 0) {
    throw "Expected 0 deny ACEs in restored fixture, got $restDenyCount"
  }

  exit 0
`;

try {
  execFileSync(psCommand, ['-NoProfile', '-NonInteractive', '-Command', dotNetTestScript], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe']
  });
} catch (err) {
  throw new Error(`In-memory pure .NET DirectorySecurity test failed: ${err.stderr || err.stdout || err.message}`);
}

// 7.6 Script contract checks on scripts/verify-delta-handoff-windows.ps1
const windowsLabContent = fs.readFileSync(windowsLabScript, 'utf8');
assert.ok(windowsLabContent.includes('Normalize-SddlDescriptorDaclAi'), 'verify-delta-handoff-windows.ps1 must define Normalize-SddlDescriptorDaclAi');
assert.ok(windowsLabContent.includes('Assert-RestoredAclMatchesOriginal'), 'verify-delta-handoff-windows.ps1 must define Assert-RestoredAclMatchesOriginal');
assert.ok(windowsLabContent.includes('Format-CanonicalAceRule'), 'verify-delta-handoff-windows.ps1 must define Format-CanonicalAceRule');
assert.ok(windowsLabContent.includes('Set-Acl -LiteralPath $protectedDir -AclObject $originalAcl'), 'verify-delta-handoff-windows.ps1 must preserve original Set-Acl call');
assert.ok(windowsLabContent.includes('AssertOwnedLabPath $protectedDir'), 'verify-delta-handoff-windows.ps1 must preserve AssertOwnedLabPath guard');
assert.ok(windowsLabContent.includes('AreAccessRulesProtected'), 'verify-delta-handoff-windows.ps1 must verify AreAccessRulesProtected');
assert.ok(windowsLabContent.includes('test-probe-plan'), 'verify-delta-handoff-windows.ps1 must verify writable preflight probe after restore');

console.log('      PASS: Pure fixture SDDL normalization, in-memory .NET semantics, drift detection, and script contracts verified.');

console.log('\n===============================================================');
console.log(' ALL SETUP 0.0.12 RELEASE READINESS CHECKS PASSED SUCCESSFULLY!');
console.log('===============================================================');
