import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import {
  buildOwnedProcessInspectionScript,
  parseOwnedProcessInspectionOutput,
  getRunningOwnedProcesses,
  normalizeRuntimePorts,
  assertRuntimePortsPreserved,
  verifyRuntimePorts,
  snapshotRuntimeKeyMaterial,
  verifyRuntimeKeyMaterial,
  ALLOWED_RUNTIME_PORTS_FIELDS,
} from './verify-stable-delta-ui.mjs';

const root = path.resolve('.');
const metaScript = path.resolve('scripts/release-metadata.mjs');
const sourceScript = path.resolve('scripts/validate-release-source.mjs');

console.log('Verifying cumulative stable delta 0.0.10 readiness...');

// ══════════════════════════════════════════════════════════════════════════════
// 1. Exercise Actual Metadata Helper Using Isolated Temp Fixtures
// ══════════════════════════════════════════════════════════════════════════════
console.log('Testing release-metadata.mjs in isolated temp environments...');

function runMetadataInFixture(versionObj, pkgObj, tagArg = null) {
  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-meta-test-'));
  try {
    fs.writeFileSync(path.join(tempDir, 'version.json'), JSON.stringify(versionObj, null, 2));
    fs.writeFileSync(path.join(tempDir, 'package.json'), JSON.stringify(pkgObj, null, 2));
    const args = [metaScript];
    if (tagArg) args.push(tagArg);
    const output = execFileSync(process.execPath, args, {
      cwd: tempDir,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      windowsHide: true,
    });
    return { ok: true, output: JSON.parse(output.trim()) };
  } catch (err) {
    return { ok: false, error: err.stderr || err.message };
  } finally {
    fs.rmSync(tempDir, { recursive: true, force: true });
  }
}

// 1.1 Accept 11 explicit delta baselines
{
  const baselines11 = [
    'v0.0.1',
    'v0.0.10-beta.1', 'v0.0.10-beta.2', 'v0.0.10-beta.3', 'v0.0.10-beta.4', 'v0.0.10-beta.5',
    'v0.0.10-beta.6', 'v0.0.10-beta.7', 'v0.0.10-beta.8', 'v0.0.10-beta.9', 'v0.0.10-beta.10',
  ];
  const res = runMetadataInFixture(
    {
      version: '0.0.10',
      application_version: '0.0.10',
      channel: 'stable',
      release_type: 'delta',
      installer_version: '0.0.1',
      delta_baseline_tags: baselines11,
    },
    { version: '0.0.1' },
    'v0.0.10'
  );
  assert.equal(res.ok, true, 'Metadata helper must accept 11 baselines: ' + res.error);
  assert.equal(res.output.mode, 'delta');
  assert.equal(res.output.channel, 'stable');
  assert.equal(res.output.tag, 'v0.0.10');
  assert.equal(res.output.version, '0.0.10');
  assert.equal(res.output.installer_version, '0.0.1');
  assert.equal(res.output.prerelease, 'false');
  assert.equal(JSON.parse(res.output.baselines).length, 11);
}

// 1.2 Accept up to cap of 16 explicit baselines
{
  const baselines16 = [
    'v0.0.1',
    ...Array.from({ length: 15 }, (_, i) => `v0.0.10-beta.${i + 1}`),
  ];
  const res = runMetadataInFixture(
    {
      version: '0.0.10',
      application_version: '0.0.10',
      channel: 'stable',
      release_type: 'delta',
      installer_version: '0.0.1',
      delta_baseline_tags: baselines16,
    },
    { version: '0.0.1' },
    'v0.0.10'
  );
  assert.equal(res.ok, true, 'Metadata helper must accept 16 baselines: ' + res.error);
  assert.equal(JSON.parse(res.output.baselines).length, 16);
}

// 1.3 Reject excessive baselines (> 16)
{
  const baselines17 = [
    'v0.0.1',
    ...Array.from({ length: 16 }, (_, i) => `v0.0.10-beta.${i + 1}`),
  ];
  const res = runMetadataInFixture(
    {
      version: '0.0.10',
      application_version: '0.0.10',
      channel: 'stable',
      release_type: 'delta',
      installer_version: '0.0.1',
      delta_baseline_tags: baselines17,
    },
    { version: '0.0.1' },
    'v0.0.10'
  );
  assert.equal(res.ok, false, 'Metadata helper must reject > 16 baselines');
  assert.match(String(res.error), /Provide 1-16 explicit delta_baseline_tags/);
}

// 1.4 Reject duplicate baselines
{
  const duplicateBaselines = ['v0.0.1', 'v0.0.1', 'v0.0.10-beta.1'];
  const res = runMetadataInFixture(
    {
      version: '0.0.10',
      application_version: '0.0.10',
      channel: 'stable',
      release_type: 'delta',
      installer_version: '0.0.1',
      delta_baseline_tags: duplicateBaselines,
    },
    { version: '0.0.1' },
    'v0.0.10'
  );
  assert.equal(res.ok, false, 'Metadata helper must reject duplicate baselines');
  assert.match(String(res.error), /Duplicate delta_baseline_tags are not allowed/);
}

// 1.5 Reject malformed baseline tags
{
  const malformedBaselines = ['v0.0.1', 'invalid_baseline_format'];
  const res = runMetadataInFixture(
    {
      version: '0.0.10',
      application_version: '0.0.10',
      channel: 'stable',
      release_type: 'delta',
      installer_version: '0.0.1',
      delta_baseline_tags: malformedBaselines,
    },
    { version: '0.0.1' },
    'v0.0.10'
  );
  assert.equal(res.ok, false, 'Metadata helper must reject malformed baseline tags');
  assert.match(String(res.error), /Provide 1-16 explicit delta_baseline_tags/);
}

// 1.6 Preserve setup mode behavior untouched
{
  const res = runMetadataInFixture(
    {
      version: '0.0.11',
      application_version: '0.0.11',
      channel: 'stable',
      release_type: 'setup',
      installer_version: '0.0.11',
    },
    { version: '0.0.11' },
    'v0.0.11'
  );
  assert.equal(res.ok, true, 'Setup mode behavior must remain intact: ' + res.error);
  assert.equal(res.output.mode, 'setup');
  assert.equal(res.output.installer_version, '0.0.11');
  assert.equal(res.output.version, '0.0.11');
}

// 1.7 Reject channel mismatch
{
  const res = runMetadataInFixture(
    {
      version: '0.0.10',
      application_version: '0.0.10',
      channel: 'beta',
      release_type: 'delta',
      installer_version: '0.0.1',
      delta_baseline_tags: ['v0.0.1'],
    },
    { version: '0.0.1' },
    'v0.0.10'
  );
  assert.equal(res.ok, false, 'Must reject channel mismatch between tag and version.json');
  assert.match(String(res.error), /version\.json channel must match tag/);
}

console.log('PASS: release-metadata.mjs isolated tests passed.');

// ══════════════════════════════════════════════════════════════════════════════
// 2. Working Tree version.json & 11 Baseline Verification
// ══════════════════════════════════════════════════════════════════════════════
console.log('Verifying working-tree version.json...');
const versionData = JSON.parse(fs.readFileSync(path.join(root, 'version.json'), 'utf8'));

assert.equal(versionData.version, '0.0.10', 'version must be 0.0.10');
assert.equal(versionData.application_version, '0.0.10', 'application_version must be 0.0.10');
assert.equal(versionData.channel, 'stable', 'channel must be stable');
assert.equal(versionData.update_channel, 'stable', 'update_channel must be stable');
assert.equal(versionData.release_type, 'delta', 'release_type must be delta');
assert.equal(versionData.installer_version, '0.0.1', 'installer_version must stay 0.0.1');
assert.equal(versionData.update_engine_version, '1.0.0', 'update_engine_version must be 1.0.0');
assert.equal(versionData.released_at, '2026-10-25', 'released_at must be 2026-10-25');

const expectedBaselines = [
  'v0.0.1',
  'v0.0.10-beta.1',
  'v0.0.10-beta.2',
  'v0.0.10-beta.3',
  'v0.0.10-beta.4',
  'v0.0.10-beta.5',
  'v0.0.10-beta.6',
  'v0.0.10-beta.7',
  'v0.0.10-beta.8',
  'v0.0.10-beta.9',
  'v0.0.10-beta.10',
];

assert.deepEqual(versionData.delta_baseline_tags, expectedBaselines, 'delta_baseline_tags must contain exactly all 11 explicit baselines');
assert.ok(Array.isArray(versionData.changelog) && versionData.changelog.length >= 4, 'Changelog must honestly cover cumulative fixes');

// Verify changelog excludes new Electron shell handoff hardening
const changelogText = versionData.changelog.join(' ').toLowerCase();
assert.ok(!changelogText.includes('electron handoff'), 'Changelog must not claim Electron handoff hardening (reserved for Setup 0.0.11)');

console.log('PASS: version.json contracts verified.');

// ══════════════════════════════════════════════════════════════════════════════
// 3. Baseline Resolution, Source Ancestry & Retained Production App Source
// ══════════════════════════════════════════════════════════════════════════════
console.log('Verifying baseline resolution, source ancestry, and retained production source trees...');

function resolveGitCommit(ref) {
  try {
    return execFileSync('git', ['rev-parse', '--verify', `${ref}^{commit}`], {
      cwd: root,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
  } catch (err) {
    throw new Error(`Git ref '${ref}' could not be resolved: ${err.message}`);
  }
}

// 3.1 Baseline resolution: ensure each baseline tag points to a valid git commit
for (const tag of expectedBaselines) {
  const commit = resolveGitCommit(tag);
  assert.match(commit, /^[0-9a-f]{40}$/i, `Baseline tag '${tag}' must resolve to valid commit`);
}
console.log('PASS: Baseline resolution: all 11 explicit delta baseline tags resolve to valid git commits.');

// 3.2 Real ancestry assertion: ensure v0.0.10-beta.10 is an ancestor of HEAD
try {
  execFileSync('git', ['merge-base', '--is-ancestor', 'v0.0.10-beta.10', 'HEAD'], {
    cwd: root,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
} catch (err) {
  throw new Error('v0.0.10-beta.10 is not an ancestor of HEAD; branch history diverged.');
}

// 3.3 Ensure published production application source is retained with zero diff against v0.0.10-beta.10
const appSourceDiff = execFileSync('git', [
  'diff',
  'v0.0.10-beta.10',
  '--exit-code',
  '--',
  'backend',
  'frontend/src',
  'frontend/public',
  'database',
], { cwd: root, encoding: 'utf8' });
assert.equal(appSourceDiff.trim(), '', 'Production application source (backend, frontend/src, frontend/public, database) must have zero diff against v0.0.10-beta.10');

assert.ok(fs.existsSync(path.join(root, 'backend/Controllers')), 'Published backend Controllers must be retained');
assert.ok(fs.existsSync(path.join(root, 'backend/Services')), 'Published backend Services must be retained');
assert.ok(fs.existsSync(path.join(root, 'backend/composer.json')), 'Published backend composer.json must be retained');
assert.ok(fs.existsSync(path.join(root, 'frontend/src')), 'Published frontend source tree must be retained');
assert.ok(fs.existsSync(path.join(root, 'frontend/public')), 'Published frontend public tree must be retained');
assert.ok(fs.existsSync(path.join(root, 'frontend/package.json')), 'Published frontend package.json must be retained');
assert.ok(fs.existsSync(path.join(root, 'database/migrations')), 'Database migrations tree must be retained');

const headCommit = resolveGitCommit('HEAD');
console.log(`PASS: Source ancestry: v0.0.10-beta.10 is confirmed ancestor of HEAD (${headCommit.slice(0, 7)}) and production app source has zero diff.`);

// ══════════════════════════════════════════════════════════════════════════════
// 4. Immutable Shell Integrity
// ══════════════════════════════════════════════════════════════════════════════
console.log('Verifying immutable shell integrity vs v0.0.10-beta.10...');

const shellDiff = execFileSync('git', [
  'diff',
  'v0.0.10-beta.10',
  '--exit-code',
  '--',
  'electron',
  'build',
  'package.json',
  'package-lock.json',
  'scripts/desktop-runtime.json',
], { cwd: root, encoding: 'utf8' });
assert.equal(shellDiff.trim(), '', 'Immutable shell files must have zero diff against v0.0.10-beta.10');

const rootPkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const frontendPkg = JSON.parse(fs.readFileSync(path.join(root, 'frontend/package.json'), 'utf8'));
assert.equal(rootPkg.version, '0.0.1', 'root package.json version must remain immutable 0.0.1');
assert.equal(frontendPkg.version, '0.0.1', 'frontend package.json version must remain 0.0.1');

console.log('PASS: Immutable desktop shell contract verified byte-for-byte.');

// ══════════════════════════════════════════════════════════════════════════════
// 5. Gate Contracts & Secret Key Confinement
// ══════════════════════════════════════════════════════════════════════════════
console.log('Verifying release automation gate contracts...');

// 5.1 build-delta-release.ps1 contracts
const buildDeltaScript = fs.readFileSync(path.join(root, 'scripts/build-delta-release.ps1'), 'utf8');
assert.ok(buildDeltaScript.includes("GITHUB_REPOSITORY -ne 'ABDO-TECK/pos-desktop-updates'"), 'build-delta-release.ps1 must enforce repository guard');
assert.ok(buildDeltaScript.includes('UPDATE_PRIVATE_KEY repository secret is required'), 'build-delta-release.ps1 must enforce UPDATE_PRIVATE_KEY presence');
assert.ok(buildDeltaScript.includes('Remove-Item env:UPDATE_PRIVATE_KEY'), 'build-delta-release.ps1 must scrub private key from child environment');
assert.ok(buildDeltaScript.includes('$Channel -eq \'stable\''), 'build-delta-release.ps1 must guard stable-specific gates');
assert.ok(buildDeltaScript.includes('scripts/verify-stable-delta-readiness.mjs'), 'build-delta-release.ps1 must invoke verify-stable-delta-readiness.mjs');
assert.ok(buildDeltaScript.includes('scripts/verify-admin-update-center.php'), 'build-delta-release.ps1 must run admin update backend regressions');
assert.ok(buildDeltaScript.includes('scripts/verify-trigger-healing.php'), 'build-delta-release.ps1 must run trigger healing regressions');
assert.ok(buildDeltaScript.includes('scripts/verify-catalog-trigger-recovery.php'), 'build-delta-release.ps1 must run catalog trigger recovery regressions');
assert.ok(buildDeltaScript.includes('scripts/verify-admin-update-center.mjs'), 'build-delta-release.ps1 must run admin update frontend DOM regressions');
assert.ok(buildDeltaScript.includes('prepare-stable-delta-ui.ps1'), 'build-delta-release.ps1 must invoke prepare-stable-delta-ui.ps1 after delta packages');
assert.ok(/try\s*\{[\s\S]*?build-release-package\.php[\s\S]*?\}\s*finally\s*\{[\s\S]*?Remove-Item\s+env:UPDATE_PRIVATE_KEY/i.test(buildDeltaScript), 'build-delta-release.ps1 must scrub UPDATE_PRIVATE_KEY inside a finally block');
assert.ok(buildDeltaScript.includes('function Sync-ReleaseCandidateTag'), 'build-delta-release.ps1 must define Sync-ReleaseCandidateTag');
assert.ok(buildDeltaScript.includes("GITHUB_EVENT_NAME -ne 'workflow_dispatch'"), 'build-delta-release.ps1 candidate tag hook must enforce workflow_dispatch event');
assert.ok(buildDeltaScript.includes('GITHUB_EVENT_PATH'), 'build-delta-release.ps1 candidate tag hook must inspect GITHUB_EVENT_PATH');
assert.ok(buildDeltaScript.includes('-is [bool] -and -not $publish'), 'build-delta-release.ps1 must explicitly require boolean false');
assert.ok(buildDeltaScript.includes("-is [string] -and $publish.Trim() -eq 'false'"), 'build-delta-release.ps1 must explicitly require string false');
assert.ok(buildDeltaScript.includes('$tagProperty.Value -ne $Tag'), 'build-delta-release.ps1 candidate tag hook must verify inputs.tag matches Tag');
assert.ok(buildDeltaScript.includes('$Tag -notmatch \'^v\\d+\\.\\d+\\.\\d+$\''), 'build-delta-release.ps1 candidate tag hook must enforce valid stable tag regex');
assert.ok(buildDeltaScript.includes('$Tag -ne "v$($Version.version)"'), 'build-delta-release.ps1 candidate tag hook must verify tag matches version.json');
assert.ok(buildDeltaScript.includes('Refusing to force or retag'), 'build-delta-release.ps1 must refuse to force or retag diverged existing tag');
assert.ok(!buildDeltaScript.includes('git tag -f') && !buildDeltaScript.includes('git tag --force'), 'build-delta-release.ps1 must never force tags');
assert.ok(!buildDeltaScript.includes('git push'), 'build-delta-release.ps1 must never push tags or branches');

// 5.2 prepare-stable-delta-ui.ps1 contracts
const prepareUiScript = fs.readFileSync(path.join(root, 'scripts/prepare-stable-delta-ui.ps1'), 'utf8');
assert.ok(prepareUiScript.includes("env:GITHUB_ACTIONS -ne 'true'"), 'prepare-stable-delta-ui.ps1 must require GITHUB_ACTIONS');
assert.ok(prepareUiScript.includes('2b77447f1fc835560df2a5dbd7a7a24f8e137b193d242f38831e46d6de535d1a'), 'prepare-stable-delta-ui.ps1 must verify original Setup SHA256');
assert.ok(prepareUiScript.includes('scripts/verify-stable-delta-ui.mjs'), 'prepare-stable-delta-ui.ps1 must invoke verify-stable-delta-ui.mjs');
assert.ok(prepareUiScript.includes('stable-delta-acceptance.json'), 'prepare-stable-delta-ui.ps1 must publish stable-delta-acceptance.json');

// 5.3 verify-stable-delta-ui.mjs contracts
const verifyUiScript = fs.readFileSync(path.join(root, 'scripts/verify-stable-delta-ui.mjs'), 'utf8');
assert.ok(verifyUiScript.includes("process.env.GITHUB_ACTIONS, 'true'"), 'verify-stable-delta-ui.mjs must enforce ephemeral CI');
assert.ok(verifyUiScript.includes('UPDATE_PRIVATE_KEY'), 'verify-stable-delta-ui.mjs must ensure UPDATE_PRIVATE_KEY is never exposed');
assert.ok(verifyUiScript.includes('targetInventory.files'), 'verify-stable-delta-ui.mjs must verify all files in deployment inventory');
assert.ok(verifyUiScript.includes('prepublication_copied_signed_packages'), 'verify-stable-delta-ui.mjs must record transport mode honestly');
assert.ok(verifyUiScript.includes('guardedCleanup'), 'verify-stable-delta-ui.mjs must use guardedCleanup');
assert.ok(verifyUiScript.includes('assertPosTreeSafe'), 'verify-stable-delta-ui.mjs must validate tree safety and reparse points');
assert.ok(verifyUiScript.includes('verifyFixtureRunMarker'), 'verify-stable-delta-ui.mjs must enforce per-run fixture marker');
assert.ok(verifyUiScript.includes('awaitOwnedProcessShutdown'), 'verify-stable-delta-ui.mjs must await owned runtime child process shutdown');
assert.ok(verifyUiScript.includes('buildOwnedProcessInspectionScript'), 'verify-stable-delta-ui.mjs must export buildOwnedProcessInspectionScript');
assert.ok(verifyUiScript.includes('parseOwnedProcessInspectionOutput'), 'verify-stable-delta-ui.mjs must export parseOwnedProcessInspectionOutput');
assert.ok(verifyUiScript.includes('-ErrorAction Stop'), 'verify-stable-delta-ui.mjs must use -ErrorAction Stop for CIM process query');
assert.ok(verifyUiScript.includes('FromBase64String'), 'verify-stable-delta-ui.mjs must use base64 path decoding in process script');
assert.ok(verifyUiScript.includes('DirectorySeparatorChar'), 'verify-stable-delta-ui.mjs must ensure trailing separator boundary in process path');
assert.ok(verifyUiScript.includes('snapshotRuntimeKeyMaterial'), 'verify-stable-delta-ui.mjs must snapshot runtime key material');
assert.ok(verifyUiScript.includes('verifyRuntimeKeyMaterial'), 'verify-stable-delta-ui.mjs must verify runtime key material');
assert.ok(verifyUiScript.includes('normalizeRuntimePorts'), 'verify-stable-delta-ui.mjs must export normalizeRuntimePorts');
assert.ok(verifyUiScript.includes('assertRuntimePortsPreserved'), 'verify-stable-delta-ui.mjs must export assertRuntimePortsPreserved');
assert.ok(verifyUiScript.includes('waitForRuntimeKeyMaterial'), 'verify-stable-delta-ui.mjs must wait for runtime key material bootstrap');
assert.ok(verifyUiScript.includes('qz_private_key'), 'verify-stable-delta-ui.mjs must require QZ private key');
assert.ok(verifyUiScript.includes('inspect-compatibility'), 'verify-stable-delta-ui.mjs must inspect normalized compatibility');
assert.ok(verifyUiScript.includes('schema_sha256'), 'verify-stable-delta-ui.mjs must strictly compare schema_sha256');
assert.ok(verifyUiScript.includes('ledger_sha256'), 'verify-stable-delta-ui.mjs must strictly compare ledger_sha256');

console.log('PASS: Gate contracts and secret key confinement verified.');

// ══════════════════════════════════════════════════════════════════════════════
// 6. Safe PowerShell AST Syntax Parsing (Without Executing Script Bodies)
// ══════════════════════════════════════════════════════════════════════════════
console.log('Parsing PowerShell scripts safely using PowerShell Language Parser...');

function parsePowerShellAst(psFile) {
  const absolutePath = path.resolve(psFile);
  assert.ok(fs.existsSync(absolutePath), `File does not exist: ${psFile}`);

  // Construct a self-contained parsing script encoded as Base64 UTF-16LE
  const psScript = `
    $file = ${JSON.stringify(absolutePath)}
    $tokens = $null
    $errors = $null
    $ast = [System.Management.Automation.Language.Parser]::ParseFile($file, [ref]$tokens, [ref]$errors)
    if ($errors -and $errors.Count -gt 0) {
      $errors | ForEach-Object { Write-Error $_.Message }
      exit 1
    }
    Write-Output "AST_PARSED_CLEAN: $file"
  `;

  const encoded = Buffer.from(psScript, 'utf16le').toString('base64');
  try {
    const output = execFileSync('powershell.exe', [
      '-NoProfile',
      '-NonInteractive',
      '-EncodedCommand',
      encoded,
    ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], windowsHide: true });
    assert.ok(output.includes('AST_PARSED_CLEAN'), `PowerShell parse failed for ${psFile}`);
  } catch (err) {
    throw new Error(`PowerShell AST parsing failed for ${psFile}: ${err.stderr || err.message}`);
  }
}

parsePowerShellAst('scripts/build-delta-release.ps1');
parsePowerShellAst('scripts/prepare-stable-delta-ui.ps1');

console.log('PASS: PowerShell AST syntax parsed cleanly without running script bodies.');

// ══════════════════════════════════════════════════════════════════════════════
// 7. Safe Dependency-Free Owned-Process Query & Path Boundary Regressions
//    (Exercises generated script, boundary invariants, and fail-closed handling
//     WITHOUT running CIM or original app processes locally)
// ══════════════════════════════════════════════════════════════════════════════
console.log('Testing owned-process inspection script builder, path boundaries, and fail-closed handling...');

// 7.1 Script builder generation & safe quoting/encoding validation
const sampleAppDir = path.resolve('C:/runner/temp/app-setup');
const generatedScript = buildOwnedProcessInspectionScript(sampleAppDir);

assert.ok(!generatedScript.includes(sampleAppDir), 'Generated script must not embed unescaped raw path string literals');
assert.ok(generatedScript.includes('[System.Convert]::FromBase64String('), 'Generated script must decode path from Base64');
assert.ok(generatedScript.includes('-ErrorAction Stop'), 'Generated script must specify -ErrorAction Stop on Get-CimInstance');
assert.ok(generatedScript.includes('DirectorySeparatorChar'), 'Generated script must normalize with trailing directory separator');
assert.ok(generatedScript.includes('ToLowerInvariant()'), 'Generated script must normalize path comparison to lower case');

// Verify that the Base64 payload inside the script round-trips to the exact target path
const base64Match = generatedScript.match(/FromBase64String\('([^']+)'\)/);
assert.ok(base64Match, 'Base64 path literal must be present in generated script');
const decodedTarget = Buffer.from(base64Match[1], 'base64').toString('utf8');
assert.equal(decodedTarget, sampleAppDir, 'Base64 payload must decode exactly to target directory');

// 7.2 Path boundary invariant validation (simulating PowerShell StartsWith boundary)
function simulatePowerShellPathCheck(targetRoot, candidateExecutable) {
  const normRoot = path.resolve(targetRoot).replace(/[\\/]+$/, '') + path.sep;
  const rootLower = normRoot.toLowerCase();
  const execLower = path.resolve(candidateExecutable).toLowerCase();
  return execLower.startsWith(rootLower);
}

const targetLab = 'C:\\runner\\temp\\app-setup';
assert.equal(simulatePowerShellPathCheck(targetLab, 'C:\\runner\\temp\\app-setup\\POS Desktop.exe'), true, 'Direct child executable must match');
assert.equal(simulatePowerShellPathCheck(targetLab, 'C:\\runner\\temp\\app-setup\\resources\\php\\php.exe'), true, 'Nested child executable must match');
assert.equal(simulatePowerShellPathCheck(targetLab, 'c:\\RUNNER\\temp\\APP-SETUP\\POS Desktop.exe'), true, 'Case-insensitive child executable must match');
assert.equal(simulatePowerShellPathCheck(targetLab, 'C:\\runner\\temp\\app-setup-other\\POS Desktop.exe'), false, 'Neighboring directory without boundary separator must NOT match');
assert.equal(simulatePowerShellPathCheck(targetLab, 'C:\\Windows\\System32\\cmd.exe'), false, 'System executable outside target directory must NOT match');

// 7.3 Fail-closed handling on injected runner failures
assert.throws(() => {
  getRunningOwnedProcesses(sampleAppDir, () => {
    throw new Error('Injected simulated CIM access denial');
  });
}, /Injected simulated CIM access denial/, 'getRunningOwnedProcesses must fail closed on runner exceptions');

assert.throws(() => {
  getRunningOwnedProcesses(sampleAppDir, () => 'PowerShell CIM provider fatal error: cannot query Win32_Process');
}, /Failed to parse process inspection JSON/, 'getRunningOwnedProcesses must fail closed on non-JSON error text');

assert.throws(() => {
  getRunningOwnedProcesses(sampleAppDir, () => JSON.stringify([{ InvalidProcessStructure: true }]));
}, /Malformed process inspection object/, 'getRunningOwnedProcesses must fail closed on malformed process payload');

// 7.4 Correct parsing of valid empty and populated responses
const emptyResult = getRunningOwnedProcesses(sampleAppDir, () => '[]');
assert.deepEqual(emptyResult, [], 'getRunningOwnedProcesses must return empty array on clean [] output');

const whitespaceEmptyResult = getRunningOwnedProcesses(sampleAppDir, () => '  \n[]\n  ');
assert.deepEqual(whitespaceEmptyResult, [], 'getRunningOwnedProcesses must return empty array on whitespace-padded [] output');

const populatedResult = getRunningOwnedProcesses(sampleAppDir, () => JSON.stringify([
  { ProcessId: 5432, ExecutablePath: 'C:\\runner\\temp\\app-setup\\POS Desktop.exe' },
]));
assert.equal(populatedResult.length, 1);
assert.equal(populatedResult[0].ProcessId, 5432);
assert.equal(populatedResult[0].ExecutablePath, 'C:\\runner\\temp\\app-setup\\POS Desktop.exe');

console.log('PASS: Owned-process inspection script builder, path boundaries, and fail-closed handling verified.');

// ══════════════════════════════════════════════════════════════════════════════
// 8. Safe Dependency-Free Candidate Release Tag Authorization & Tag Identity
//    (Exercises extracted Sync-ReleaseCandidateTag logic in isolated temp git repos
//     WITHOUT modifying actual working tree Git refs or tags)
// ══════════════════════════════════════════════════════════════════════════════
console.log('Testing candidate release tag authorization and tag identity in isolated environments...');
const initialWorkspaceTagRefs = execFileSync('git', ['show-ref', '--tags'], { cwd: root, encoding: 'utf8' }).trim();

const helperMatch = buildDeltaScript.match(/function Sync-ReleaseCandidateTag\s*\{[\s\S]*?\n\}/);
assert.ok(helperMatch, 'Sync-ReleaseCandidateTag helper must be extracted cleanly from build-delta-release.ps1');
const helperFunctionSource = helperMatch[0];

// Execute comprehensive authorization decision cases using isolated temp git fixtures in a single powershell process
const tagRunnerScript = `
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

${helperFunctionSource}

$results = [System.Collections.Generic.List[psobject]]::new()

function Run-Case($name, [scriptblock]$action) {
    $tempDir = Join-Path ([System.IO.Path]::GetTempPath()) ("pos-tagtest-" + [System.Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tempDir | Out-Null
    try {
        git -C $tempDir init -q
        git -C $tempDir config user.name "CI"
        git -C $tempDir config user.email "ci@example.com"
        git -C $tempDir commit --allow-empty -m "initial commit" -q
        $head = (git -C $tempDir rev-parse HEAD).Trim()

        $caseResult = & $action $tempDir $head
        $results.Add([pscustomobject]@{
            Name = $name
            Passed = [bool]$caseResult.Passed
            Detail = [string]$caseResult.Detail
        })
    } catch {
        $results.Add([pscustomobject]@{
            Name = $name
            Passed = $false
            Detail = $_.Exception.Message
        })
    } finally {
        Remove-Item -Recurse -Force $tempDir -ErrorAction SilentlyContinue
        Remove-Item env:GITHUB_ACTIONS -ErrorAction SilentlyContinue
        Remove-Item env:GITHUB_REPOSITORY -ErrorAction SilentlyContinue
        Remove-Item env:GITHUB_EVENT_NAME -ErrorAction SilentlyContinue
        Remove-Item env:GITHUB_EVENT_PATH -ErrorAction SilentlyContinue
    }
}

# 8.1 Authorized candidate tag creation: workflow_dispatch with explicit boolean false
Run-Case "Authorized_Boolean_False" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.10","publish":false}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    $commit = if ($tag) { (git -C $dir rev-parse "refs/tags/v0.0.10^{commit}").Trim() } else { '' }
    return @{ Passed = ($tag -eq 'v0.0.10' -and $commit -eq $head); Detail = "tag=$tag, commit=$commit" }
}

# 8.2 Authorized candidate tag creation: workflow_dispatch with string 'false'
Run-Case "Authorized_String_False" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.10","publish":"false"}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    $commit = if ($tag) { (git -C $dir rev-parse "refs/tags/v0.0.10^{commit}").Trim() } else { '' }
    return @{ Passed = ($tag -eq 'v0.0.10' -and $commit -eq $head); Detail = "tag=$tag" }
}

# 8.3 Denied on publish = true (boolean)
Run-Case "Denied_Publish_Boolean_True" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.10","publish":true}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    return @{ Passed = ($tag -eq ''); Detail = "tag='$tag' (expected empty)" }
}

# 8.4 Denied on publish = 'true' (string)
Run-Case "Denied_Publish_String_True" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.10","publish":"true"}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    return @{ Passed = ($tag -eq ''); Detail = "tag='$tag' (expected empty)" }
}

# 8.5 Denied on truthy publish variations (string '1')
Run-Case "Denied_Publish_String_1" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.10","publish":"1"}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    return @{ Passed = ($tag -eq ''); Detail = "tag='$tag' (expected empty)" }
}

# 8.6 Denied on missing publish input
Run-Case "Denied_Publish_Missing" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.10"}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    return @{ Passed = ($tag -eq ''); Detail = "tag='$tag' (expected empty)" }
}

# 8.7 Denied on non-workflow_dispatch event (push)
Run-Case "Denied_Push_Event" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.10","publish":false}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'push'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    return @{ Passed = ($tag -eq ''); Detail = "tag='$tag' (expected empty)" }
}

# 8.8 Denied on repository mismatch
Run-Case "Denied_Repository_Mismatch" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.10","publish":false}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'unauthorized/repository'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    return @{ Passed = ($tag -eq ''); Detail = "tag='$tag' (expected empty)" }
}

# 8.9 Denied on non-GitHub environment (missing GITHUB_ACTIONS)
Run-Case "Denied_Non_GitHub_Environment" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.10","publish":false}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = ''
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    return @{ Passed = ($tag -eq ''); Detail = "tag='$tag' (expected empty)" }
}

# 8.10 Denied on malformed / missing GITHUB_EVENT_PATH
Run-Case "Denied_Missing_Event_Path" {
    param($dir, $head)
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = Join-Path $dir "non-existent-event.json"

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    return @{ Passed = ($tag -eq ''); Detail = "tag='$tag' (expected empty)" }
}

# 8.11 Denied on inputs.tag mismatch vs requested Tag
Run-Case "Denied_Tag_Mismatch" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.11","publish":false}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    return @{ Passed = ($tag -eq ''); Detail = "tag='$tag' (expected empty)" }
}

# 8.12 Denied on invalid stable tag regex (beta tag format)
Run-Case "Denied_Invalid_Stable_Regex" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.10-beta.1","publish":false}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10-beta.1' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10-beta.1"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    return @{ Passed = ($tag -eq ''); Detail = "tag='$tag' (expected empty)" }
}

# 8.13 Denied on version.json mismatch
Run-Case "Denied_Version_Mismatch" {
    param($dir, $head)
    $evt = Join-Path $dir "event.json"
    '{"inputs":{"tag":"v0.0.10","publish":false}}' | Set-Content $evt
    $env:GITHUB_ACTIONS = 'true'
    $env:GITHUB_REPOSITORY = 'ABDO-TECK/pos-desktop-updates'
    $env:GITHUB_EVENT_NAME = 'workflow_dispatch'
    $env:GITHUB_EVENT_PATH = $evt

    Push-Location $dir
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.9'; channel = 'stable' })
    } finally { Pop-Location }

    $tagList = git -C $dir tag -l "v0.0.10"
    $tag = if ($tagList) { "$tagList".Trim() } else { '' }
    return @{ Passed = ($tag -eq ''); Detail = "tag='$tag' (expected empty)" }
}

# 8.14 Existing tag matches HEAD: preserved without recreation or failure
Run-Case "Existing_Tag_Matches_Head" {
    param($dir, $head)
    git -C $dir tag "v0.0.10" $head

    Push-Location $dir
    $threw = $false
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } catch {
        $threw = $true
    } finally { Pop-Location }

    $tagCommit = (git -C $dir rev-parse "refs/tags/v0.0.10^{commit}").Trim()
    return @{ Passed = (-not $threw -and $tagCommit -eq $head); Detail = "threw=$threw, tagCommit=$tagCommit" }
}

# 8.15 Existing tag diverging from HEAD fails closed (throws, no force/retag)
Run-Case "Diverged_Tag_Fails_Closed" {
    param($dir, $head)
    git -C $dir tag "v0.0.10" $head
    git -C $dir commit --allow-empty -m "diverged commit" -q
    $newHead = (git -C $dir rev-parse HEAD).Trim()

    Push-Location $dir
    $threw = $false
    $msg = ''
    try {
        Sync-ReleaseCandidateTag -Tag 'v0.0.10' -Channel 'stable' -Version ([pscustomobject]@{ version = '0.0.10'; channel = 'stable' })
    } catch {
        $threw = $true
        $msg = $_.Exception.Message
    } finally { Pop-Location }

    $tagCommit = (git -C $dir rev-parse "refs/tags/v0.0.10^{commit}").Trim()
    $passed = $threw -and ($msg -match 'already exists') -and ($tagCommit -eq $head)
    return @{ Passed = $passed; Detail = "threw=$threw, preservedOriginalCommit=$($tagCommit -eq $head)" }
}

Write-Output "---JSON_RESULTS_START---"
Write-Output ($results | ConvertTo-Json -Compress)
Write-Output "---JSON_RESULTS_END---"
`;

const tempRunnerDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-tag-runner-'));
const tempRunnerFile = path.join(tempRunnerDir, 'runner.ps1');
fs.writeFileSync(tempRunnerFile, tagRunnerScript, 'utf8');

let runnerRawOutput = '';
try {
  runnerRawOutput = execFileSync('powershell.exe', [
    '-NoProfile',
    '-NonInteractive',
    '-ExecutionPolicy',
    'Bypass',
    '-File',
    tempRunnerFile,
  ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], windowsHide: true });
} catch (err) {
  throw new Error(`PowerShell candidate tag runner failed: ${err.stderr || err.message}`);
} finally {
  fs.rmSync(tempRunnerDir, { recursive: true, force: true });
}

const jsonStartMarker = '---JSON_RESULTS_START---';
const jsonEndMarker = '---JSON_RESULTS_END---';
const startIdx = runnerRawOutput.indexOf(jsonStartMarker);
const endIdx = runnerRawOutput.indexOf(jsonEndMarker);
assert.ok(startIdx !== -1 && endIdx !== -1, 'Candidate tag runner must produce valid JSON delimiter markers');
const jsonText = runnerRawOutput.slice(startIdx + jsonStartMarker.length, endIdx).trim();
const parsedResults = JSON.parse(jsonText);
const caseResults = Array.isArray(parsedResults) ? parsedResults : [parsedResults];
assert.ok(caseResults.length >= 15, `Expected at least 15 candidate tag authorization cases, got \${caseResults.length}`);

for (const res of caseResults) {
  assert.equal(res.Passed, true, `Candidate tag test case '${res.Name}' failed: ${res.Detail}`);
}
console.log(`PASS: All ${caseResults.length} candidate tag authorization and identity regression cases passed.`);

// 8.16 Assert that real workspace Git refs remain completely untouched
const realWorkspaceTagRefs = execFileSync('git', ['show-ref', '--tags'], { cwd: root, encoding: 'utf8' }).trim();
assert.equal(realWorkspaceTagRefs, initialWorkspaceTagRefs, 'Readiness tests must preserve every existing workspace tag and create none');
console.log('PASS: Real local workspace confirmed pristine: zero local Git ref or tag changes.');

// ══════════════════════════════════════════════════════════════════════════════
// 9. Safe Pure Runtime Ports Normalization & Assertion Regressions
// ══════════════════════════════════════════════════════════════════════════════
console.log('Testing runtime_ports normalization and assertion contracts...');

// 9.1 Baseline valid ports payload
const baselinePorts = {
  mysqlPort: 3307,
  apiPort: 8000,
  apiBaseUrl: 'http://127.0.0.1:8000',
  updatedAt: '2026-10-25T10:00:00.000Z',
};

// 9.2 Exact identical payload passes
assertRuntimePortsPreserved(baselinePorts, baselinePorts);
verifyRuntimePorts(baselinePorts, baselinePorts);

// 9.3 Volatile updatedAt timestamp diff is allowed when all other fields match
{
  const upgradedPortsWithTimestampDiff = {
    mysqlPort: 3307,
    apiPort: 8000,
    apiBaseUrl: 'http://127.0.0.1:8000',
    updatedAt: '2026-10-25T10:05:32.184Z',
  };
  assertRuntimePortsPreserved(upgradedPortsWithTimestampDiff, baselinePorts);
  const normBefore = normalizeRuntimePorts(baselinePorts);
  const normAfter = normalizeRuntimePorts(upgradedPortsWithTimestampDiff);
  assert.deepEqual(normBefore, normAfter);
}

// 9.4 Reject port modifications
{
  // mysqlPort difference rejected
  assert.throws(() => {
    assertRuntimePortsPreserved({ ...baselinePorts, mysqlPort: 3308 }, baselinePorts);
  }, /Runtime ports configuration modified|values do not match/);

  // apiPort difference rejected
  assert.throws(() => {
    assertRuntimePortsPreserved(
      { ...baselinePorts, apiPort: 8080, apiBaseUrl: 'http://127.0.0.1:8080' },
      baselinePorts
    );
  }, /Runtime ports configuration modified|values do not match/);
}

// 9.5 Reject apiBaseUrl modifications
{
  assert.throws(() => {
    assertRuntimePortsPreserved(
      { ...baselinePorts, apiBaseUrl: 'http://localhost:8000' },
      baselinePorts
    );
  }, /Runtime ports configuration modified|values do not match/);
}

// 9.6 Reject apiBaseUrl inconsistent with apiPort
{
  assert.throws(() => {
    normalizeRuntimePorts({
      ...baselinePorts,
      apiPort: 8000,
      apiBaseUrl: 'http://127.0.0.1:9000',
    });
  }, /does not match apiPort/);
}

// 9.7 Reject invalid or non-http/https apiBaseUrl
{
  assert.throws(() => {
    normalizeRuntimePorts({ ...baselinePorts, apiBaseUrl: 'ftp://127.0.0.1:8000' });
  }, /must have http: or https: protocol/);

  assert.throws(() => {
    normalizeRuntimePorts({ ...baselinePorts, apiBaseUrl: 'not-a-valid-url' });
  }, /not a valid URL/);

  assert.throws(() => {
    normalizeRuntimePorts({ ...baselinePorts, apiBaseUrl: '' });
  }, /non-empty string/);
}

// 9.8 Reject invalid port types and values outside 1..65535
for (const invalidPort of [0, -1, 65536, 70000, 8000.5, '8000', null, NaN]) {
  assert.throws(() => {
    normalizeRuntimePorts({ ...baselinePorts, mysqlPort: invalidPort });
  }, /mysqlPort must be an integer between 1 and 65535/);

  assert.throws(() => {
    normalizeRuntimePorts({ ...baselinePorts, apiPort: invalidPort, apiBaseUrl: `http://127.0.0.1:${invalidPort}` });
  }, /apiPort must be an integer between 1 and 65535/);
}

// 9.9 Reject invalid updatedAt timestamps
for (const invalidTs of ['invalid-date', '', '   ', 1600000000, null, true]) {
  assert.throws(() => {
    normalizeRuntimePorts({ ...baselinePorts, updatedAt: invalidTs });
  }, /updatedAt/);
}

// 9.10 Reject missing required fields
for (const missingField of ALLOWED_RUNTIME_PORTS_FIELDS) {
  const incomplete = { ...baselinePorts };
  delete incomplete[missingField];
  assert.throws(() => {
    normalizeRuntimePorts(incomplete);
  }, new RegExp(`missing required field: ${missingField}`));
}

// 9.11 Reject arbitrary field drift / extra unexpected fields
assert.throws(() => {
  normalizeRuntimePorts({ ...baselinePorts, extraField: 'unexpected-drift' });
}, /contains unexpected\/extra field: extraField/);

assert.throws(() => {
  normalizeRuntimePorts({ ...baselinePorts, customPort: 9999 });
}, /contains unexpected\/extra field: customPort/);

// 9.12 Reject non-object JSON payloads and invalid JSON text
for (const nonObj of ['null', '[]', '[1, 2, 3]', '12345', '"string"']) {
  assert.throws(() => {
    normalizeRuntimePorts(nonObj);
  }, /runtime_ports must be a valid JSON object/);
}

assert.throws(() => {
  normalizeRuntimePorts('{ invalid: json }');
}, /Invalid JSON/);

assert.throws(() => {
  normalizeRuntimePorts(path.join(root, 'non-existent-runtime_ports.json'));
}, /runtime_ports file does not exist/);

// 9.13 Isolated filesystem fixture integration with snapshotRuntimeKeyMaterial & verifyRuntimeKeyMaterial
{
  const tempFixtureDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pos-ports-test-'));
  const tempConfigDir = path.join(tempFixtureDir, 'config');
  const tempUserDataDir = path.join(tempFixtureDir, 'userData');
  const tempQzDir = path.join(tempUserDataDir, 'qz-certs');

  try {
    fs.mkdirSync(tempConfigDir, { recursive: true });
    fs.mkdirSync(tempQzDir, { recursive: true });

    // Seed mock credential files and private keypairs
    const dbCredsFile = path.join(tempConfigDir, 'db_credentials.json');
    const portsFile = path.join(tempConfigDir, 'runtime_ports.json');
    const qzKeyFile = path.join(tempQzDir, 'private-key.pem');
    const qzCertFile = path.join(tempQzDir, 'digital-certificate.pem');

    fs.writeFileSync(dbCredsFile, JSON.stringify({ user: 'root', pass: 'secret123' }), 'utf8');
    fs.writeFileSync(portsFile, JSON.stringify(baselinePorts, null, 2), 'utf8');
    fs.writeFileSync(qzKeyFile, '-----BEGIN RSA PRIVATE KEY-----\nMIIEogIBAAKCAQEA...\n-----END RSA PRIVATE KEY-----', 'utf8');
    fs.writeFileSync(qzCertFile, '-----BEGIN CERTIFICATE-----\nMIIDXTCCAkWgAwIBAgIJ...\n-----END CERTIFICATE-----', 'utf8');

    // Snapshot pre-upgrade key and credential material
    const snapshot = snapshotRuntimeKeyMaterial(tempConfigDir, tempUserDataDir);
    assert.equal(snapshot.size, 4);

    // Simulate post-upgrade application start: php-server.js rewrites runtime_ports.json with fresh updatedAt
    const postUpgradePorts = {
      ...baselinePorts,
      updatedAt: new Date().toISOString(),
    };
    fs.writeFileSync(portsFile, JSON.stringify(postUpgradePorts, null, 2), 'utf8');

    // verifyRuntimeKeyMaterial must pass because only volatile updatedAt timestamp changed
    verifyRuntimeKeyMaterial(snapshot);

    // Tampering with runtime_ports mysqlPort must be rejected
    fs.writeFileSync(portsFile, JSON.stringify({ ...postUpgradePorts, mysqlPort: 3308 }, null, 2), 'utf8');
    assert.throws(() => {
      verifyRuntimeKeyMaterial(snapshot);
    }, /Runtime ports configuration modified/);

    // Restore runtime_ports and tamper with db_credentials.json to assert byte-exact verification
    fs.writeFileSync(portsFile, JSON.stringify(postUpgradePorts, null, 2), 'utf8');
    fs.writeFileSync(dbCredsFile, JSON.stringify({ user: 'root', pass: 'tampered-secret' }), 'utf8');
    assert.throws(() => {
      verifyRuntimeKeyMaterial(snapshot);
    }, /Runtime key\/credential file modified after upgrade: db_credentials\.json/);

  } finally {
    fs.rmSync(tempFixtureDir, { recursive: true, force: true });
  }
}

console.log('PASS: Runtime ports normalization, assertion helper, and isolated lifecycle regressions verified.');

console.log('\n========================================================================');
console.log('ALL STABLE DELTA 0.0.10 READINESS VERIFICATIONS PASSED SUCCESSFULLY.');
console.log('========================================================================');
