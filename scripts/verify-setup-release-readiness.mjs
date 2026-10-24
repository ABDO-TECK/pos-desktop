#!/usr/bin/env node
/**
 * scripts/verify-setup-release-readiness.mjs
 *
 * Standalone, dependency-free release readiness verifier for Setup 0.0.11.
 * Validates:
 *   1. Consistent version metadata (0.0.11 / stable / setup / 2026-10-25) across all package files
 *   2. Strict byte/hash equality against pinned approved hashes for 3 production Electron files;
 *      optional comparison against local reference if available (no absolute devtree dependency in CI).
 *   3. Git ancestry (HEAD contains published beta10 96f6a9c)
 *   4. Exact backend and frontend source retention vs published beta10 (96f6a9c) with dependency metadata-only diffs
 *   5. Workflow safety contracts (publish=false default, ephemeral runner guards, true upgrade phase, readiness gate)
 *   6. Static PowerShell syntax parsing (using safe parser API, no execution)
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

function sha256(content) {
  return crypto.createHash('sha256').update(content).digest('hex');
}

function readJson(relPath) {
  const full = path.join(repoRoot, relPath);
  assert.ok(fs.existsSync(full), `Required file missing: ${relPath}`);
  return JSON.parse(fs.readFileSync(full, 'utf8'));
}

console.log('--- POS Desktop Setup 0.0.11 Release Readiness Verification ---');

// 1. Consistent Version Metadata across all manifests
console.log('[1/6] Verifying consistent version metadata (0.0.11 / stable / setup)...');
const targetVersion = '0.0.11';
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
assert.equal(versionJson.released_at, '2026-10-25', 'version.json released_at must be 2026-10-25');
assert.equal(versionJson.minimum_supported_version, '0.0.1', 'version.json minimum_supported_version must be 0.0.1');

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
console.log('      PASS: Metadata 0.0.11 strictly verified across 5 manifest files.');

// 2. Strict Approved Hashes for 3 Production Electron Files + Portable Reference Check
console.log('[2/6] Verifying pinned approved hashes for 3 production Electron files...');
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

// 3. Git Ancestry & Beta10 Basis Verification
console.log('[3/6] Verifying git ancestry and commit heritage...');
try {
  execFileSync('git', ['merge-base', '--is-ancestor', '96f6a9c', 'HEAD'], {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe']
  });
  console.log('      PASS: HEAD has published beta10 (96f6a9c) in its ancestry.');
} catch (e) {
  throw new Error('Git ancestry check failed: HEAD must include published beta10 (96f6a9c)');
}

// 4. Exact Backend and Frontend Source Retention vs Published Beta10 (96f6a9c)
console.log('[4/6] Verifying exact backend/frontend source retention vs published beta10 (96f6a9c)...');

// A. Backend source tree must have 0 diff against beta10
const backendDiff = execFileSync('git', ['diff', '96f6a9c', '--stat', 'backend/'], {
  cwd: repoRoot,
  encoding: 'utf8',
  stdio: ['ignore', 'pipe', 'pipe']
}).trim();
assert.equal(backendDiff, '', `backend/ must have zero diff against beta10 (96f6a9c), found: ${backendDiff}`);

// B. Frontend src tree must have 0 diff against beta10
const frontendSrcDiff = execFileSync('git', ['diff', '96f6a9c', '--stat', 'frontend/src/'], {
  cwd: repoRoot,
  encoding: 'utf8',
  stdio: ['ignore', 'pipe', 'pipe']
}).trim();
assert.equal(frontendSrcDiff, '', `frontend/src/ must have zero diff against beta10 (96f6a9c), found: ${frontendSrcDiff}`);

// C. Frontend metadata-only diffs (only package.json and package-lock.json version bump)
const frontendOtherDiff = execFileSync('git', ['diff', '96f6a9c', '--name-only', 'frontend/'], {
  cwd: repoRoot,
  encoding: 'utf8',
  stdio: ['ignore', 'pipe', 'pipe']
}).trim().split(/\r?\n/).filter(Boolean);

const expectedFrontendDiffFiles = ['frontend/package.json', 'frontend/package-lock.json'];
assert.deepEqual(
  frontendOtherDiff.sort(),
  expectedFrontendDiffFiles.sort(),
  `frontend/ changes against beta10 must be metadata-only, found: ${frontendOtherDiff.join(', ')}`
);

console.log('      PASS: Exact backend and frontend/src source retention vs beta10 verified (0 source drift).');

// 5. Workflow Gate & Upgrade Safety Contracts
console.log('[5/6] Verifying GitHub Actions workflow gates and upgrade contracts...');
const workflowPath = path.join(repoRoot, '.github/workflows/windows-setup.yml');
assert.ok(fs.existsSync(workflowPath), '.github/workflows/windows-setup.yml missing');
const workflowContent = fs.readFileSync(workflowPath, 'utf8');

assert.ok(/publish:\s*\n\s*description:[^\n]+\n\s*type:\s*boolean\n\s*default:\s*false/.test(workflowContent), 'windows-setup.yml publish input must default to false');
assert.ok(/publish:\s*\n\s*if:\s*inputs\.publish/.test(workflowContent), 'publish job must be gated by inputs.publish');
assert.ok(workflowContent.includes('scripts/verify-setup-release-readiness.mjs'), 'windows-setup.yml must run verify-setup-release-readiness.mjs');
assert.ok(workflowContent.includes('scripts/verify-original-setup-upgrade.ps1'), 'windows-setup.yml must run verify-original-setup-upgrade.ps1');
assert.ok(workflowContent.includes('scripts/verify-setup-recovery.mjs'), 'windows-setup.yml must run verify-setup-recovery.mjs');
assert.ok(workflowContent.includes('scripts/verify-delta-handoff-recovery.mjs'), 'windows-setup.yml must run verify-delta-handoff-recovery.mjs');
assert.ok(workflowContent.includes('scripts/verify-delta-handoff-windows.ps1'), 'windows-setup.yml must run verify-delta-handoff-windows.ps1');
assert.ok(workflowContent.includes('scripts/verify-catalog-trigger-recovery.php'), 'windows-setup.yml must run verify-catalog-trigger-recovery.php');
assert.ok(workflowContent.includes('scripts/verify-admin-update-center.php'), 'windows-setup.yml must run verify-admin-update-center.php');
assert.ok(workflowContent.includes('pdo_sqlite'), 'windows-setup.yml must include pdo_sqlite in setup-php extensions');

// Verify upgrade script contracts
const upgradeScriptPath = path.join(repoRoot, 'scripts/verify-original-setup-upgrade.ps1');
assert.ok(fs.existsSync(upgradeScriptPath), 'scripts/verify-original-setup-upgrade.ps1 missing');
const upgradeScriptContent = fs.readFileSync(upgradeScriptPath, 'utf8');

assert.ok(upgradeScriptContent.includes('$env:GITHUB_ACTIONS -ne \'true\' -or -not $env:RUNNER_TEMP'), 'upgrade script must enforce GITHUB_ACTIONS and RUNNER_TEMP guard');
assert.ok(upgradeScriptContent.includes('2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a'), 'upgrade script must require original 0.0.1 SHA256');
assert.ok(upgradeScriptContent.includes('Get-PosCleanupTargets'), 'upgrade script must check cleanup targets before running');
assert.ok(upgradeScriptContent.includes('Uninstall-Pos'), 'upgrade script must clean up owned fixture via uninstallation');
assert.ok(upgradeScriptContent.includes('channel-transition-capability.php'), 'upgrade script must use channel-transition-capability.php fixture');
assert.ok(upgradeScriptContent.includes('prepare-compatibility'), 'upgrade script must prepare compatibility contract');
assert.ok(upgradeScriptContent.includes('admin_digest'), 'upgrade script must assert admin digest');
assert.ok(upgradeScriptContent.includes('qz-certs'), 'upgrade script must assert qz-certs pair');
assert.ok(upgradeScriptContent.includes('schema_sha256'), 'upgrade script must assert schema_sha256');
assert.ok(upgradeScriptContent.includes('ledger_sha256'), 'upgrade script must assert ledger_sha256');

// Verify lifecycle script guard
const lifecycleScriptContent = fs.readFileSync(path.join(repoRoot, 'scripts/verify-installer-lifecycle.ps1'), 'utf8');
assert.ok(lifecycleScriptContent.includes('$env:GITHUB_ACTIONS -ne \'true\' -or -not $env:RUNNER_TEMP'), 'lifecycle script must enforce GITHUB_ACTIONS and RUNNER_TEMP guard');
console.log('      PASS: CI workflow gates, readiness gate, and upgrade safety contracts verified.');

// 6. Safe Static PowerShell Syntax Parsing (No execution)
console.log('[6/6] Parsing PowerShell scripts statically via PowerShell Parser API (no execution)...');
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

console.log('\n===============================================================');
console.log(' ALL SETUP 0.0.11 RELEASE READINESS CHECKS PASSED SUCCESSFULLY!');
console.log('===============================================================');
