#!/usr/bin/env node
/**
 * scripts/promote-setup13.mjs
 *
 * Hardened GitHub Actions promoter for Setup 0.0.13.
 *
 * Enforces:
 *   1. Restricted strictly to ephemeral GitHub Actions under ABDO-TECK/pos-desktop.
 *   2. Validates actual GITHUB_SHA (40 hex lowercase) == checked out HEAD == both API runs == remote peeled tag v0.0.13.
 *      No overriding or spoofing GITHUB_SHA; SOURCE_SHA if present must match GITHUB_SHA.
 *   3. Verifies both parent runs via GitHub Actions REST API:
 *      - setup_run_id (.github/workflows/windows-setup.yml) completed with success
 *      - acceptance_run_id (.github/workflows/setup13-release-acceptance.yml) completed with success
 *      - Both runs from same repository and head_repository: ABDO-TECK/pos-desktop
 *      - Positive numeric IDs only.
 *   4. Remote tag v0.0.13 MUST pre-exist on GitHub and peel to exact expected commit SHA.
 *   5. Pre-check: Target release v0.0.13 MUST NOT already exist (fail-closed, 0 delete/clobber paths).
 *      Network, authentication, or server errors are NOT treated as 404.
 *   6. Strictly consumes named download directories via workflow actions:
 *      - --setup-dir (exact 5 assets: .exe, .exe.blockmap, latest.yml, deployment-inventory.json, SHA256SUMS.txt)
 *      - --evidence-dir (exact root proof files: release-provenance.json and acceptance.json)
 *      - --carrier-dir (exact 18 carrier files: 4 transitions JSON/SIG/ZIP = 12, signed channel-transitions.json/.sig,
 *                       deployment-inventory.json, source-provenance.json, release-notes.md, SHA256SUMS.txt)
 *      Fails if any required file is missing; NO fallback unzip, NO API artifact download, NO recursive first match.
 *   7. Validates shared proof contract release-provenance.json:
 *      - schema_version === '1.0.0', tag === 'v0.0.13', verdict === 'PASSED', cleanup_verified === true, artifact_binding_verified === true
 *      - EXACT 13 verification flags all strictly true (no alternate case aliases)
 *      - setup_assets: exact 5-key map with exact properties {sha256, size}, matching downloaded files byte-for-byte
 *      - carrier_artifacts: nonempty strict map covering all 18 allowlisted carrier files matching byte-for-byte
 *      - Independently checks acceptance.json: run_id is a REQUIRED string matching expectedAcceptanceRunId,
 *        same 13 flags, PASSED verdict, cleanup_verified.
 *   8. Stages verified assets into isolated mkdtemp under RUNNER_TEMP with descendant and reparse checks BEFORE mutation.
 *      Never deletes pre-existing deterministic path; cleans up owned temp staging only with reasserted boundaries in try/finally.
 *   9. Invokes scripts/publish-setup-release.mjs with verified unmutated assets and verified inherited GITHUB_SHA.
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export const REQUIRED_REPOSITORY = 'ABDO-TECK/pos-desktop';
export const EXPECTED_VERSION = '0.0.13';
export const EXPECTED_TAG = 'v0.0.13';
export const EXPECTED_SETUP_WORKFLOW = '.github/workflows/windows-setup.yml';
export const EXPECTED_ACCEPTANCE_WORKFLOW = '.github/workflows/setup13-release-acceptance.yml';

export const EXACT_5_SETUP_ASSETS = Object.freeze([
  'POS-Desktop-Setup-0.0.13.exe',
  'POS-Desktop-Setup-0.0.13.exe.blockmap',
  'latest.yml',
  'deployment-inventory.json',
  'SHA256SUMS.txt'
]);

export const EXPECTED_SETUP_PAYLOAD_FILES = Object.freeze([
  'POS-Desktop-Setup-0.0.13.exe',
  'POS-Desktop-Setup-0.0.13.exe.blockmap',
  'latest.yml',
  'deployment-inventory.json'
]);

export const REQUIRED_13_VERIFICATIONS = Object.freeze([
  'bug1_setup12_source_inventory_mismatch_reproduced',
  'candidate13_silent_upgrade',
  'candidate13_shell_root_preserved',
  'bug1_candidate13_stable_to_beta_transition',
  'bug1_candidate13_beta_to_stable_transition',
  'repeated_channel_roundtrip_verified',
  'bug2_desktop_sql_restore_success',
  'bug2_desktop_sql_restore_rollback_and_error_classification',
  'database_integrity_verified',
  'session_preservation_on_error_verified',
  'indexeddb_offline_preserved',
  'protected_state_preserved',
  'legacy_v025_restore_migrations_verified'
]);

export const EXPECTED_TRANSITIONS = Object.freeze([
  {
    stem: 'transition-0.0.13-to-beta-0.0.12-beta.1-delta',
    direction: 'forward_beta',
    from_version: '0.0.13',
    from_channel: 'stable',
    version: '0.0.12-beta.1',
    channel: 'beta',
    type: 'delta'
  },
  {
    stem: 'transition-0.0.13-to-beta-0.0.12-beta.1-full',
    direction: 'forward_beta',
    from_version: '0.0.13',
    from_channel: 'stable',
    version: '0.0.12-beta.1',
    channel: 'beta',
    type: 'full'
  },
  {
    stem: 'transition-0.0.12-beta.1-to-stable-0.0.13-delta',
    direction: 'return_stable',
    from_version: '0.0.12-beta.1',
    from_channel: 'beta',
    version: '0.0.13',
    channel: 'stable',
    type: 'delta'
  },
  {
    stem: 'transition-0.0.12-beta.1-to-stable-0.0.13-full',
    direction: 'return_stable',
    from_version: '0.0.12-beta.1',
    from_channel: 'beta',
    version: '0.0.13',
    channel: 'stable',
    type: 'full'
  }
]);

export const EXACT_18_CARRIER_FILES = Object.freeze([
  'channel-transitions.json',
  'channel-transitions.sig',
  'deployment-inventory.json',
  'source-provenance.json',
  'release-notes.md',
  'SHA256SUMS.txt',
  'transition-0.0.13-to-beta-0.0.12-beta.1-delta.json',
  'transition-0.0.13-to-beta-0.0.12-beta.1-delta.sig',
  'transition-0.0.13-to-beta-0.0.12-beta.1-delta.zip',
  'transition-0.0.13-to-beta-0.0.12-beta.1-full.json',
  'transition-0.0.13-to-beta-0.0.12-beta.1-full.sig',
  'transition-0.0.13-to-beta-0.0.12-beta.1-full.zip',
  'transition-0.0.12-beta.1-to-stable-0.0.13-delta.json',
  'transition-0.0.12-beta.1-to-stable-0.0.13-delta.sig',
  'transition-0.0.12-beta.1-to-stable-0.0.13-delta.zip',
  'transition-0.0.12-beta.1-to-stable-0.0.13-full.json',
  'transition-0.0.12-beta.1-to-stable-0.0.13-full.sig',
  'transition-0.0.12-beta.1-to-stable-0.0.13-full.zip'
]);

export const EXPECTED_CARRIER_COUNT = 18;

export function sha256(buffer) {
  return crypto.createHash('sha256').update(buffer).digest('hex');
}

export function assertNoReparse(targetPath) {
  let current = path.resolve(targetPath);
  while (current && path.dirname(current) !== current) {
    if (fs.existsSync(current)) {
      const stat = fs.lstatSync(current);
      if (stat.isSymbolicLink()) {
        throw new Error('Reparse point or symlink rejected: ' + current);
      }
    }
    const parent = path.dirname(current);
    if (parent === current) break;
    current = parent;
  }
}

export function assertPositiveInteger(value, name) {
  assert.ok(
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0,
    `${name} must be a safe positive integer (got: ${value})`
  );
}

export function assertStrictLowerHex64(str, name) {
  assert.equal(typeof str, 'string', `${name} must be a string`);
  assert.ok(
    /^[a-f0-9]{64}$/.test(str),
    `${name} must be exactly 64 lowercase hex characters (got: ${str})`
  );
}

export function assertStrictLowerHex40(str, name) {
  assert.equal(typeof str, 'string', `${name} must be a string`);
  assert.ok(
    /^[a-f0-9]{40}$/.test(str),
    `${name} must be exactly 40 lowercase hex characters (got: ${str})`
  );
}

export function assertNumericPositiveId(id, name) {
  assert.equal(typeof id, 'string', `${name} must be a string`);
  assert.ok(
    /^[1-9]\d*$/.test(id),
    `${name} must be a valid positive numeric ID (got: ${id})`
  );
}

export function validateEnvironment(env = process.env) {
  assert.equal(
    env.GITHUB_ACTIONS,
    'true',
    'promote-setup13.mjs is strictly restricted to ephemeral GitHub Actions runners'
  );
  const runnerTemp = env.RUNNER_TEMP;
  assert.ok(runnerTemp && fs.existsSync(runnerTemp), 'RUNNER_TEMP directory is required and must exist');
  assertNoReparse(runnerTemp);

  assert.equal(
    env.GITHUB_REPOSITORY,
    REQUIRED_REPOSITORY,
    `GITHUB_REPOSITORY must be ${REQUIRED_REPOSITORY} (got: ${env.GITHUB_REPOSITORY})`
  );
  if (env.REPOSITORY) {
    assert.equal(
      env.REPOSITORY,
      REQUIRED_REPOSITORY,
      `REPOSITORY override must match ${REQUIRED_REPOSITORY} (got: ${env.REPOSITORY})`
    );
  }

  const token = env.GH_TOKEN || env.GITHUB_TOKEN;
  assert.ok(token && token.trim().length > 0, 'GH_TOKEN or GITHUB_TOKEN environment variable is required');

  const githubSha = env.GITHUB_SHA;
  assert.ok(githubSha, 'GITHUB_SHA environment variable is required');
  assertStrictLowerHex40(githubSha, 'GITHUB_SHA');

  if (env.SOURCE_SHA) {
    assert.equal(
      env.SOURCE_SHA,
      githubSha,
      `SOURCE_SHA (${env.SOURCE_SHA}) must match GITHUB_SHA (${githubSha})`
    );
  }

  return { runnerTemp, repository: REQUIRED_REPOSITORY, token, githubSha };
}

export function validateHeadCommit(repoRoot, expectedSha) {
  const headCommit = execFileSync('git', ['rev-parse', 'HEAD'], {
    cwd: repoRoot,
    encoding: 'utf8'
  }).trim();
  assertStrictLowerHex40(headCommit, 'checked out HEAD commit');
  assert.equal(
    headCommit,
    expectedSha,
    `Checked out HEAD commit (${headCommit}) does not match expected GITHUB_SHA (${expectedSha})`
  );
}

export function validateLocalMetadata(repoRoot = process.cwd()) {
  const versionMetaPath = path.join(repoRoot, 'version.json');
  assert.ok(fs.existsSync(versionMetaPath), 'version.json missing');
  const versionMeta = JSON.parse(fs.readFileSync(versionMetaPath, 'utf8'));

  assert.equal(versionMeta.version, EXPECTED_VERSION, `version.json version must be ${EXPECTED_VERSION}`);
  assert.equal(versionMeta.application_version, EXPECTED_VERSION, `version.json application_version must be ${EXPECTED_VERSION}`);
  assert.equal(versionMeta.installer_version, EXPECTED_VERSION, `version.json installer_version must be ${EXPECTED_VERSION}`);
  assert.equal(versionMeta.channel, 'stable', 'version.json channel must be stable');
  assert.equal(versionMeta.release_type, 'setup', 'version.json release_type must be setup');

  const packageJsonPath = path.join(repoRoot, 'package.json');
  if (fs.existsSync(packageJsonPath)) {
    const rootPkg = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
    assert.equal(rootPkg.version, EXPECTED_VERSION, `package.json version must be ${EXPECTED_VERSION}`);
  }
}

export function validateWorkflowRun(run, expectedRunId, expectedWorkflowPath, expectedSha, label) {
  assert.ok(run && typeof run === 'object', `${label} data missing`);
  assert.equal(String(run.id), String(expectedRunId), `${label} ID mismatch (expected ${expectedRunId}, got ${run.id})`);
  assert.equal(run.status, 'completed', `${label} status is not completed: ${run.status}`);
  assert.equal(run.conclusion, 'success', `${label} conclusion is not success: ${run.conclusion}`);
  assert.equal(run.path, expectedWorkflowPath, `${label} unexpected workflow path: ${run.path}`);
  assert.equal(run.repository?.full_name, REQUIRED_REPOSITORY, `${label} repository mismatch: ${run.repository?.full_name}`);
  assert.equal(run.head_repository?.full_name, REQUIRED_REPOSITORY, `${label} head_repository mismatch: ${run.head_repository?.full_name}`);

  assert.ok(run.head_sha, `${label} head_sha missing`);
  assertStrictLowerHex40(run.head_sha, `${label} head_sha`);
  assert.equal(
    run.head_sha,
    expectedSha,
    `${label} head_sha (${run.head_sha}) does not match expected GITHUB_SHA (${expectedSha})`
  );
}

export function validateRemoteTag(tagRefData, tagObjResolver, expectedSha) {
  assert.ok(tagRefData && tagRefData.object, `Remote tag ${EXPECTED_TAG} ref data missing`);
  let peeledSha = tagRefData.object.sha;
  if (tagRefData.object.type === 'tag') {
    if (typeof tagObjResolver === 'function') {
      const tagObj = tagObjResolver(peeledSha);
      assert.ok(tagObj && tagObj.object && tagObj.object.sha, `Failed resolving annotated tag object ${peeledSha}`);
      peeledSha = tagObj.object.sha;
    }
  }
  assertStrictLowerHex40(peeledSha, `peeled SHA for ${EXPECTED_TAG}`);
  assert.equal(
    peeledSha,
    expectedSha,
    `Remote tag ${EXPECTED_TAG} points to commit ${peeledSha}, but expected commit is ${expectedSha}`
  );
}

export function validateNoExistingRelease(releaseCheckResult) {
  if (releaseCheckResult.status === 200) {
    throw new Error(
      `CRITICAL REFUSAL: Release for tag ${EXPECTED_TAG} already exists (ID: ${releaseCheckResult.data?.id}, draft: ${releaseCheckResult.data?.draft}). Modifying or clobbering existing releases is strictly forbidden.`
    );
  }
  if (releaseCheckResult.status !== 404) {
    throw new Error(
      `CRITICAL: Unexpected HTTP status ${releaseCheckResult.status} checking release for tag ${EXPECTED_TAG}. Network, authentication, and server errors must not be treated as 404.`
    );
  }
}

export function validateSetupDirectory(setupDir) {
  const resolved = path.resolve(setupDir);
  assert.ok(fs.existsSync(resolved) && fs.statSync(resolved).isDirectory(), `Setup directory missing or not a directory: ${setupDir}`);
  assertNoReparse(resolved);

  const entries = fs.readdirSync(resolved);
  for (const entry of entries) {
    const full = path.join(resolved, entry);
    assertNoReparse(full);
    const stat = fs.statSync(full);
    assert.ok(stat.isFile(), `Setup directory contains non-file entry: ${entry}`);
    assert.ok(!entry.startsWith('.'), `Setup directory contains hidden file: ${entry}`);
  }

  const lowerSet = new Set(entries.map((e) => e.toLowerCase()));
  assert.equal(lowerSet.size, entries.length, 'Setup directory contains case collision entries');

  const sortedActual = entries.slice().sort();
  const sortedExpected = EXACT_5_SETUP_ASSETS.slice().sort();
  assert.deepEqual(
    sortedActual,
    sortedExpected,
    `Setup directory must contain exact 5 assets (${sortedExpected.join(', ')}), found: ${sortedActual.join(', ')}`
  );

  const setupHashes = new Map();
  const setupSizes = new Map();
  for (const file of EXACT_5_SETUP_ASSETS) {
    const filePath = path.join(resolved, file);
    const bytes = fs.readFileSync(filePath);
    assert.ok(bytes.length > 0, `Setup release asset cannot be empty: ${file}`);
    const hash = sha256(bytes);
    assertStrictLowerHex64(hash, `computed hash for ${file}`);
    setupHashes.set(file, hash);
    setupSizes.set(file, bytes.length);
  }

  // Validate SHA256SUMS.txt
  const checksumsContent = fs.readFileSync(path.join(resolved, 'SHA256SUMS.txt'), 'utf8');
  const lines = checksumsContent.trim().split(/\r?\n/).filter((l) => l.trim().length > 0);
  assert.equal(lines.length, 4, `Setup SHA256SUMS.txt must contain exactly 4 entries, found: ${lines.length}`);

  const covered = new Set();
  for (const line of lines) {
    const match = line.match(/^([a-f0-9]{64})\s+(\S+)$/);
    assert.ok(match, `Malformed line in Setup SHA256SUMS.txt: '${line}'`);
    const [, expectedHash, filename] = match;
    assert.ok(!filename.includes('/') && !filename.includes('\\'), `Path traversal in SHA256SUMS.txt: ${filename}`);
    assert.notEqual(filename, 'SHA256SUMS.txt', 'SHA256SUMS.txt must not list itself');
    assert.ok(!covered.has(filename), `Duplicate entry in SHA256SUMS.txt: ${filename}`);
    covered.add(filename);

    assert.ok(EXPECTED_SETUP_PAYLOAD_FILES.includes(filename), `Unexpected file listed in SHA256SUMS.txt: ${filename}`);
    assert.equal(setupHashes.get(filename), expectedHash, `SHA256 mismatch for ${filename} against SHA256SUMS.txt`);
  }
  assert.deepEqual([...covered].sort(), EXPECTED_SETUP_PAYLOAD_FILES.slice().sort(), 'Setup SHA256SUMS.txt coverage mismatch');

  // Validate latest.yml & deployment-inventory.json
  const latestYml = fs.readFileSync(path.join(resolved, 'latest.yml'), 'utf8');
  assert.match(latestYml, /^version:\s*0\.0\.13\s*$/m, 'latest.yml version must be 0.0.13');
  assert.match(latestYml, /POS-Desktop-Setup-0\.0\.13\.exe/, 'latest.yml must reference POS-Desktop-Setup-0.0.13.exe');

  const invBytes = fs.readFileSync(path.join(resolved, 'deployment-inventory.json'));
  const inv = JSON.parse(invBytes.toString('utf8'));
  assert.equal(inv.version, '0.0.13', 'deployment-inventory.json version must be 0.0.13');
  assert.equal(inv.format, 1, 'deployment-inventory.json format must be 1');
  assertStrictLowerHex64(inv.shell_sha256, 'deployment-inventory.json shell_sha256');
  assert.ok(inv.files && typeof inv.files === 'object' && !Array.isArray(inv.files), 'deployment-inventory.json files map is required');

  const inventoryInfo = {
    rawBytes: invBytes,
    parsed: inv,
    sha256: setupHashes.get('deployment-inventory.json'),
    size: setupSizes.get('deployment-inventory.json')
  };

  return { setupHashes, setupSizes, inventoryInfo };
}

export function validateEvidenceDirectory(evidenceDir, options) {
  const { expectedSetupRunId, expectedAcceptanceRunId, expectedSha, setupHashes, setupSizes } = options;
  const resolved = path.resolve(evidenceDir);
  assert.ok(fs.existsSync(resolved) && fs.statSync(resolved).isDirectory(), `Evidence directory missing or not a directory: ${evidenceDir}`);
  assertNoReparse(resolved);

  // Exact root proof names: NO recursive search. Diagnostics next to 2 root proofs tolerated if not loaded.
  const provenancePath = path.join(resolved, 'release-provenance.json');
  assert.ok(fs.existsSync(provenancePath) && fs.statSync(provenancePath).isFile(), 'release-provenance.json missing from evidence directory root');
  assertNoReparse(provenancePath);

  const acceptancePath = path.join(resolved, 'acceptance.json');
  assert.ok(fs.existsSync(acceptancePath) && fs.statSync(acceptancePath).isFile(), 'acceptance.json missing from evidence directory root');
  assertNoReparse(acceptancePath);

  // 1. Validate release-provenance.json
  const provenance = JSON.parse(fs.readFileSync(provenancePath, 'utf8'));
  assert.equal(provenance.schema_version, '1.0.0', `release-provenance schema_version must be '1.0.0', got: ${provenance.schema_version}`);
  assert.equal(provenance.tag, EXPECTED_TAG, `release-provenance tag must be '${EXPECTED_TAG}', got: ${provenance.tag}`);

  assert.equal(typeof provenance.setup_run_id, 'string', 'release-provenance setup_run_id must be a string');
  assertNumericPositiveId(provenance.setup_run_id, 'release-provenance setup_run_id');
  assert.equal(provenance.setup_run_id, expectedSetupRunId, 'release-provenance setup_run_id mismatch');

  assert.equal(typeof provenance.acceptance_run_id, 'string', 'release-provenance acceptance_run_id must be a string');
  assertNumericPositiveId(provenance.acceptance_run_id, 'release-provenance acceptance_run_id');
  assert.equal(provenance.acceptance_run_id, expectedAcceptanceRunId, 'release-provenance acceptance_run_id mismatch');

  assertStrictLowerHex40(provenance.source_sha, 'release-provenance source_sha');
  assert.equal(
    provenance.source_sha,
    expectedSha,
    `release-provenance source_sha (${provenance.source_sha}) must match expectedSha (${expectedSha})`
  );

  assert.equal(provenance.verdict, 'PASSED', `release-provenance verdict must be 'PASSED', got: ${provenance.verdict}`);
  assert.equal(provenance.cleanup_verified, true, 'release-provenance cleanup_verified must be true');
  assert.equal(provenance.artifact_binding_verified, true, 'release-provenance artifact_binding_verified must be true');

  // Verify all 13 native regression verifications strictly (no alternate case aliases, exact keys)
  assert.ok(
    provenance.verifications && typeof provenance.verifications === 'object' && !Array.isArray(provenance.verifications),
    'release-provenance verifications must be an object map'
  );
  const provKeys = Object.keys(provenance.verifications).sort();
  const expectedKeys = REQUIRED_13_VERIFICATIONS.slice().sort();
  assert.deepEqual(
    provKeys,
    expectedKeys,
    `release-provenance verifications keys mismatch. Expected exact 13 keys, got: ${provKeys.join(', ')}`
  );
  for (const key of REQUIRED_13_VERIFICATIONS) {
    assert.equal(
      provenance.verifications[key],
      true,
      `Verification flag ${key} must be strictly true in release-provenance.json`
    );
  }

  // Verify setup_assets: EXACT 5-key map with exact properties { sha256, size } (no extra keys, no aliases)
  assert.ok(
    provenance.setup_assets && typeof provenance.setup_assets === 'object' && !Array.isArray(provenance.setup_assets),
    'release-provenance setup_assets must be a strict object map (not array or scalar)'
  );
  const assetKeys = Object.keys(provenance.setup_assets).sort();
  assert.deepEqual(
    assetKeys,
    EXACT_5_SETUP_ASSETS.slice().sort(),
    `release-provenance setup_assets keys mismatch. Expected exact 5 assets, got: ${assetKeys.join(', ')}`
  );

  for (const filename of EXACT_5_SETUP_ASSETS) {
    const entry = provenance.setup_assets[filename];
    assert.ok(
      entry && typeof entry === 'object' && !Array.isArray(entry),
      `setup_assets[${filename}] must be a map object with sha256 and size`
    );
    const entryKeys = Object.keys(entry).sort();
    assert.deepEqual(
      entryKeys,
      ['sha256', 'size'],
      `setup_assets[${filename}] must contain exact properties {sha256, size}; aliases or extra properties rejected (got: ${entryKeys.join(', ')})`
    );

    assertStrictLowerHex64(entry.sha256, `setup_assets[${filename}].sha256`);
    assert.equal(
      entry.sha256,
      setupHashes.get(filename),
      `setup_assets[${filename}] hash mismatch against verified downloaded asset`
    );

    assertPositiveInteger(entry.size, `setup_assets[${filename}].size`);
    assert.equal(
      entry.size,
      setupSizes.get(filename),
      `setup_assets[${filename}] size mismatch against verified downloaded asset`
    );
  }

  // Verify carrier_artifacts is a non-empty object map
  assert.ok(
    provenance.carrier_artifacts && typeof provenance.carrier_artifacts === 'object' && !Array.isArray(provenance.carrier_artifacts),
    'release-provenance carrier_artifacts must be a strict non-empty object map'
  );
  assert.ok(
    Object.keys(provenance.carrier_artifacts).length > 0,
    'release-provenance carrier_artifacts map must not be empty'
  );

  // 2. Independently check acceptance.json: run_id is a REQUIRED string
  const acceptance = JSON.parse(fs.readFileSync(acceptancePath, 'utf8'));
  assert.equal(acceptance.verdict, 'PASSED', `acceptance.json verdict must be 'PASSED', got: ${acceptance.verdict}`);
  assert.equal(acceptance.cleanup_verified, true, 'acceptance.json cleanup_verified must be true');

  assert.equal(typeof acceptance.run_id, 'string', 'acceptance.json run_id must be a required string');
  assertNumericPositiveId(acceptance.run_id, 'acceptance.json run_id');
  assert.equal(acceptance.run_id, expectedAcceptanceRunId, 'acceptance.json run_id mismatch');

  if (acceptance.acceptance_run_id !== undefined) {
    assert.equal(typeof acceptance.acceptance_run_id, 'string', 'acceptance.json acceptance_run_id must be a string');
    assert.equal(acceptance.acceptance_run_id, expectedAcceptanceRunId, 'acceptance.json acceptance_run_id mismatch');
  }
  if (acceptance.setup_run_id !== undefined) {
    assert.equal(typeof acceptance.setup_run_id, 'string', 'acceptance.json setup_run_id must be a string');
    assert.equal(acceptance.setup_run_id, expectedSetupRunId, 'acceptance.json setup_run_id mismatch');
  }

  assert.ok(
    acceptance.verifications && typeof acceptance.verifications === 'object' && !Array.isArray(acceptance.verifications),
    'acceptance.json verifications must be an object map'
  );
  const accKeys = Object.keys(acceptance.verifications).sort();
  assert.deepEqual(
    accKeys,
    expectedKeys,
    `acceptance.json verifications keys mismatch. Expected exact 13 keys, got: ${accKeys.join(', ')}`
  );
  for (const key of REQUIRED_13_VERIFICATIONS) {
    assert.equal(
      acceptance.verifications[key],
      true,
      `Verification flag ${key} must be strictly true in acceptance.json`
    );
  }

  return provenance;
}

export function validateCarrierDirectory(carrierDir, provenance, setupInventoryInfo) {
  const resolved = path.resolve(carrierDir);
  assert.ok(fs.existsSync(resolved) && fs.statSync(resolved).isDirectory(), `Carrier directory missing or not a directory: ${carrierDir}`);
  assertNoReparse(resolved);
  assert.ok(setupInventoryInfo && setupInventoryInfo.rawBytes && setupInventoryInfo.parsed && setupInventoryInfo.sha256, 'setupInventoryInfo is required');

  const entries = fs.readdirSync(resolved);
  for (const entry of entries) {
    const full = path.join(resolved, entry);
    assertNoReparse(full);
    const stat = fs.statSync(full);
    assert.ok(stat.isFile(), `Carrier directory contains non-file entry: ${entry}`);
    assert.ok(!entry.startsWith('.'), `Carrier directory contains hidden file: ${entry}`);
  }

  const lowerSet = new Set(entries.map((e) => e.toLowerCase()));
  assert.equal(lowerSet.size, entries.length, 'Carrier directory contains case collision entries');

  // Enforce EXACT 18 allowlisted carrier files
  const sortedActual = entries.slice().sort();
  const sortedExpected = EXACT_18_CARRIER_FILES.slice().sort();
  assert.deepEqual(
    sortedActual,
    sortedExpected,
    `Carrier directory must match exact 18 allowlisted files (${sortedExpected.join(', ')}), found: ${sortedActual.join(', ')}`
  );

  // 1. Carrier deployment-inventory.json MUST byte-match Setup deployment-inventory.json
  const carrierInvPath = path.join(resolved, 'deployment-inventory.json');
  const carrierInvBytes = fs.readFileSync(carrierInvPath);
  const carrierInvHash = sha256(carrierInvBytes);
  assert.equal(
    carrierInvHash,
    setupInventoryInfo.sha256,
    `Carrier deployment-inventory.json SHA256 mismatch against Setup deployment-inventory.json (${carrierInvHash} vs ${setupInventoryInfo.sha256})`
  );
  assert.equal(
    carrierInvBytes.length,
    setupInventoryInfo.size,
    `Carrier deployment-inventory.json size mismatch against Setup deployment-inventory.json`
  );
  assert.deepEqual(
    carrierInvBytes,
    setupInventoryInfo.rawBytes,
    'Carrier deployment-inventory.json must byte-match Setup deployment-inventory.json identically'
  );

  // Compute hashes and sizes for all 18 files
  const carrierHashes = new Map();
  const carrierSizes = new Map();
  for (const file of entries) {
    const bytes = fs.readFileSync(path.join(resolved, file));
    assert.ok(bytes.length > 0, `Carrier file cannot be empty: ${file}`);
    const hash = sha256(bytes);
    assertStrictLowerHex64(hash, `carrier hash for ${file}`);
    carrierHashes.set(file, hash);
    carrierSizes.set(file, bytes.length);
  }

  // 2. Validate channel-transitions.json
  const indexRaw = fs.readFileSync(path.join(resolved, 'channel-transitions.json'), 'utf8');
  const indexData = JSON.parse(indexRaw);
  assert.equal(indexData.format, 1, 'channel-transitions.json format must be 1');
  assert.equal(indexData.carrier_tag, EXPECTED_TAG, `channel-transitions.json carrier_tag must be ${EXPECTED_TAG}`);
  assert.ok(Array.isArray(indexData.entries), 'channel-transitions.json entries must be an array');
  assert.equal(indexData.entries.length, 4, `channel-transitions.json must contain exactly 4 transition entries, got: ${indexData.entries.length}`);

  // Enforce unique exact 4 direction/type tuples and matching stem names
  const seenTuples = new Set();
  const seenStems = new Set();

  for (const entry of indexData.entries) {
    const tupleKey = `${entry.from_version}:${entry.from_channel || (entry.from_version === '0.0.13' ? 'stable' : 'beta')}->${entry.version}:${entry.channel}:${entry.type}`;
    assert.ok(!seenTuples.has(tupleKey), `Duplicate transition tuple in channel-transitions.json: ${tupleKey}`);
    seenTuples.add(tupleKey);

    const matchingExpected = EXPECTED_TRANSITIONS.find((t) =>
      t.from_version === entry.from_version &&
      t.version === entry.version &&
      t.channel === entry.channel &&
      t.type === entry.type
    );
    assert.ok(matchingExpected, `Unknown transition entry in channel-transitions.json: from ${entry.from_version} to ${entry.version} (${entry.channel}, ${entry.type})`);

    const expectedStem = matchingExpected.stem;
    assert.equal(entry.descriptor, `${expectedStem}.json`, `Descriptor name mismatch for ${tupleKey}`);
    assert.equal(entry.signature, `${expectedStem}.sig`, `Signature name mismatch for ${tupleKey}`);
    seenStems.add(expectedStem);

    assertStrictLowerHex64(entry.descriptor_sha256, `descriptor_sha256 for ${entry.descriptor}`);
    assert.equal(
      carrierHashes.get(entry.descriptor),
      entry.descriptor_sha256,
      `Descriptor hash mismatch for ${entry.descriptor}`
    );

    // Validate descriptor file content
    const descRaw = fs.readFileSync(path.join(resolved, entry.descriptor), 'utf8');
    const descData = JSON.parse(descRaw);
    assert.equal(descData.format, 1, `${entry.descriptor} format must be 1`);
    assert.equal(descData.operation, 'channel_transition', `${entry.descriptor} operation must be channel_transition`);
    assert.equal(descData.carrier_tag, EXPECTED_TAG, `${entry.descriptor} carrier_tag must be ${EXPECTED_TAG}`);
    assert.equal(descData.carrier_repo, undefined, `${entry.descriptor} must not contain invented carrier_repo field`);

    assert.equal(descData.from_version, matchingExpected.from_version, `${entry.descriptor} from_version mismatch`);
    assert.equal(descData.from_channel, matchingExpected.from_channel, `${entry.descriptor} from_channel mismatch`);
    assert.equal(descData.version, matchingExpected.version, `${entry.descriptor} version mismatch`);
    assert.equal(descData.channel, matchingExpected.channel, `${entry.descriptor} channel mismatch`);
    assert.equal(descData.type, matchingExpected.type, `${entry.descriptor} type mismatch`);

    // Validate package archive binding
    assert.equal(descData.package?.asset, `${expectedStem}.zip`, `${entry.descriptor} package.asset must be ${expectedStem}.zip`);
    assertStrictLowerHex64(descData.package.sha256, `package sha256 in ${entry.descriptor}`);
    assert.equal(
      carrierHashes.get(`${expectedStem}.zip`),
      descData.package.sha256,
      `Package archive hash mismatch for ${expectedStem}.zip`
    );
    assertPositiveInteger(descData.package.size, `package size in ${entry.descriptor}`);
    assert.equal(
      carrierSizes.get(`${expectedStem}.zip`),
      descData.package.size,
      `Package archive size mismatch for ${expectedStem}.zip`
    );

    // Cross-binding against Setup 13 canonical deployment-inventory
    if (matchingExpected.direction === 'return_stable') {
      // Return transition: target is 0.0.13 stable
      assert.ok(
        descData.target_repo === 'ABDO-TECK/pos-desktop' || descData.target_repo === 'ABDO-TECK/pos-desktop-updates' ||
        descData.target_repo === 'pos-desktop' || descData.target_repo === 'pos-desktop-updates',
        `Unexpected target_repo in ${entry.descriptor}: ${descData.target_repo}`
      );
      assert.equal(descData.target_tag, EXPECTED_TAG, `${entry.descriptor} target_tag must be ${EXPECTED_TAG}`);
      assert.equal(descData.target_inventory_sha256, setupInventoryInfo.sha256, `${entry.descriptor} target_inventory_sha256 must match Setup 13 inventory`);
      assert.ok(descData.target_inventory && typeof descData.target_inventory === 'object', `${entry.descriptor} target_inventory missing`);
      assert.equal(descData.target_inventory.version, '0.0.13', `${entry.descriptor} target_inventory version must be 0.0.13`);
      assert.equal(descData.target_inventory.format, 1, `${entry.descriptor} target_inventory format must be 1`);
      assert.equal(
        descData.target_inventory.shell_sha256,
        setupInventoryInfo.parsed.shell_sha256,
        `${entry.descriptor} target_inventory shell_sha256 mismatch against Setup 13`
      );
      assert.deepEqual(
        descData.target_inventory.files,
        setupInventoryInfo.parsed.files,
        `${entry.descriptor} target_inventory files mismatch against Setup 13`
      );
      assert.equal(
        descData.shell_sha256,
        setupInventoryInfo.parsed.shell_sha256,
        `${entry.descriptor} shell_sha256 mismatch against Setup 13`
      );
    } else {
      // Forward transition: source is 0.0.13 stable, target is 0.0.12-beta.1
      assert.ok(
        descData.target_repo === 'ABDO-TECK/pos-desktop-updates' || descData.target_repo === 'pos-desktop-updates',
        `Unexpected target_repo in ${entry.descriptor}: ${descData.target_repo}`
      );
      assert.equal(descData.target_tag, 'v0.0.12-beta.1', `${entry.descriptor} target_tag must be v0.0.12-beta.1`);
      assert.equal(descData.source_inventory_sha256, setupInventoryInfo.sha256, `${entry.descriptor} source_inventory_sha256 must match Setup 13 inventory`);
      assert.ok(descData.source_inventory && typeof descData.source_inventory === 'object', `${entry.descriptor} source_inventory missing`);
      assert.equal(descData.source_inventory.version, '0.0.13', `${entry.descriptor} source_inventory version must be 0.0.13`);
      assert.equal(descData.source_inventory.format, 1, `${entry.descriptor} source_inventory format must be 1`);
      assert.equal(
        descData.source_inventory.shell_sha256,
        setupInventoryInfo.parsed.shell_sha256,
        `${entry.descriptor} source_inventory shell_sha256 mismatch against Setup 13`
      );
      assert.deepEqual(
        descData.source_inventory.files,
        setupInventoryInfo.parsed.files,
        `${entry.descriptor} source_inventory files mismatch against Setup 13`
      );
      assert.equal(
        descData.shell_sha256,
        setupInventoryInfo.parsed.shell_sha256,
        `${entry.descriptor} shell_sha256 mismatch against Setup 13`
      );
    }
  }

  assert.equal(seenStems.size, 4, 'channel-transitions.json must cover all 4 canonical transition stems');

  // 3. Validate Carrier SHA256SUMS.txt
  const carrierSumsRaw = fs.readFileSync(path.join(resolved, 'SHA256SUMS.txt'), 'utf8');
  const carrierSumLines = carrierSumsRaw.trim().split(/\r?\n/).filter((l) => l.trim().length > 0);
  assert.equal(
    carrierSumLines.length,
    EXPECTED_CARRIER_COUNT - 1,
    `Carrier SHA256SUMS.txt must cover exactly ${EXPECTED_CARRIER_COUNT - 1} files, found: ${carrierSumLines.length}`
  );

  const carrierCovered = new Set();
  for (const line of carrierSumLines) {
    const match = line.match(/^([a-f0-9]{64})\s+\*?(\S+)$/);
    assert.ok(match, `Malformed line in Carrier SHA256SUMS.txt: '${line}'`);
    const [, expectedHash, fn] = match;
    assert.ok(!fn.includes('/') && !fn.includes('\\'), `Path traversal in Carrier SHA256SUMS.txt: ${fn}`);
    assert.notEqual(fn, 'SHA256SUMS.txt', 'Carrier SHA256SUMS.txt must not list itself');
    assert.ok(!carrierCovered.has(fn), `Duplicate entry in Carrier SHA256SUMS.txt: ${fn}`);
    carrierCovered.add(fn);

    assert.ok(entries.includes(fn), `Carrier SHA256SUMS.txt lists untracked file: ${fn}`);
    assert.equal(carrierHashes.get(fn), expectedHash, `Carrier SHA256SUMS.txt hash mismatch for ${fn}`);
  }

  // 4. Validate carrier_artifacts cryptographic binding against provenance
  const provCarrier = provenance.carrier_artifacts;
  const provCarrierKeys = Object.keys(provCarrier).sort();
  assert.deepEqual(
    provCarrierKeys,
    EXACT_18_CARRIER_FILES.slice().sort(),
    'release-provenance carrier_artifacts keys do not match exact 18 allowlisted carrier files'
  );

  for (const fn of entries) {
    const meta = provCarrier[fn];
    assert.ok(meta && typeof meta === 'object' && !Array.isArray(meta), `carrier_artifacts[${fn}] must be an object`);
    const metaKeys = Object.keys(meta).sort();
    assert.deepEqual(
      metaKeys,
      ['sha256', 'size'],
      `carrier_artifacts[${fn}] must contain exact properties {sha256, size}; aliases or extra properties rejected (got: ${metaKeys.join(', ')})`
    );

    assertStrictLowerHex64(meta.sha256, `carrier_artifacts[${fn}].sha256`);
    assert.equal(meta.sha256, carrierHashes.get(fn), `carrier_artifacts[${fn}] hash mismatch`);
    assertPositiveInteger(meta.size, `carrier_artifacts[${fn}].size`);
    assert.equal(meta.size, carrierSizes.get(fn), `carrier_artifacts[${fn}] size mismatch`);
  }

  return { carrierHashes, carrierSizes };
}

export function cleanupOwnedStaging(stagingDir, runnerTemp) {
  assert.ok(typeof stagingDir === 'string' && stagingDir.length > 0, 'stagingDir is required');
  assert.ok(typeof runnerTemp === 'string' && runnerTemp.length > 0, 'runnerTemp is required');

  assertNoReparse(runnerTemp);

  const rel = path.relative(runnerTemp, stagingDir);
  assert.ok(
    !rel.startsWith('..') && !path.isAbsolute(rel) && rel.length > 0,
    `stagingDir must be strictly an owned descendant of RUNNER_TEMP (got: ${stagingDir})`
  );

  const basename = path.basename(stagingDir);
  assert.ok(
    basename.startsWith('setup13-promote-staging-'),
    `Refusing to delete unowned directory name: ${basename}`
  );

  if (fs.existsSync(stagingDir)) {
    assertNoReparse(stagingDir);
    const stat = fs.lstatSync(stagingDir);
    assert.ok(
      stat.isDirectory() && !stat.isSymbolicLink(),
      `stagingDir must be a genuine directory and not a symlink or reparse point: ${stagingDir}`
    );
    fs.rmSync(stagingDir, { recursive: true, force: true });
  }
}

export function stageAndPromote(options) {
  const { setupDir, runnerTemp, repoRoot, actualSha, token, publisherScriptPath } = options;
  assertNoReparse(runnerTemp);

  const inheritedSha = process.env.GITHUB_SHA;
  assert.ok(inheritedSha, 'process.env.GITHUB_SHA is required');
  assert.equal(
    actualSha,
    inheritedSha,
    `actualSha (${actualSha}) must strictly match inherited process.env.GITHUB_SHA (${inheritedSha})`
  );
  if (process.env.SOURCE_SHA) {
    assert.equal(
      process.env.SOURCE_SHA,
      inheritedSha,
      `process.env.SOURCE_SHA must match inherited process.env.GITHUB_SHA (${inheritedSha})`
    );
  }

  const stagingDir = fs.mkdtempSync(path.join(runnerTemp, 'setup13-promote-staging-'));
  const rel = path.relative(runnerTemp, stagingDir);
  assert.ok(
    !rel.startsWith('..') && !path.isAbsolute(rel) && rel.length > 0,
    `Staging directory must be strictly a descendant of RUNNER_TEMP (got: ${stagingDir})`
  );
  assertNoReparse(stagingDir);

  try {
    for (const file of EXACT_5_SETUP_ASSETS) {
      const src = path.join(setupDir, file);
      const dst = path.join(stagingDir, file);
      fs.copyFileSync(src, dst);
    }
    assertNoReparse(stagingDir);

    const publisherScript = publisherScriptPath || path.resolve(repoRoot, 'scripts/publish-setup-release.mjs');
    assert.ok(fs.existsSync(publisherScript), `publish-setup-release.mjs missing at ${publisherScript}`);

    execFileSync(process.execPath, [publisherScript, stagingDir], {
      cwd: repoRoot,
      stdio: 'inherit',
      env: {
        ...process.env,
        GITHUB_ACTIONS: 'true',
        RUNNER_TEMP: runnerTemp,
        GITHUB_REPOSITORY: REQUIRED_REPOSITORY,
        GH_TOKEN: token,
        GITHUB_TOKEN: token,
        RELEASE_TAG: EXPECTED_TAG,
        SOURCE_SHA: inheritedSha,
        PRERELEASE: 'false'
      }
    });
  } finally {
    cleanupOwnedStaging(stagingDir, runnerTemp);
  }
}

export async function defaultApiRequest(endpoint, token, options = {}) {
  const url = endpoint.startsWith('http') ? endpoint : `https://api.github.com${endpoint}`;
  const reqHeaders = {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/vnd.github+json',
    'User-Agent': 'pos-desktop-promoter-setup13',
    'X-GitHub-Api-Version': '2022-11-28',
    ...(options.headers || {})
  };
  const res = await fetch(url, { ...options, headers: reqHeaders });
  const text = await res.text();
  let data = null;
  try {
    data = JSON.parse(text);
  } catch {
    data = text;
  }
  return { status: res.status, ok: res.ok, data };
}

export async function promoteSetup13(options = {}) {
  console.log('--- Setup 0.0.13 Artifact Promotion Guard ---');

  const repoRoot = options.repoRoot || process.cwd();
  const env = options.env || process.env;
  const apiRequest = options.apiRequest || ((ep, opt) => defaultApiRequest(ep, token, opt));

  // 1. Validate Environment & GITHUB_SHA
  const { runnerTemp, repository, token, githubSha } = validateEnvironment(env);
  validateHeadCommit(repoRoot, githubSha);
  validateLocalMetadata(repoRoot);

  // 2. Parse CLI Arguments
  const argv = options.argv || process.argv.slice(2);
  let setupRunId = options.setupRunId || env.SETUP_RUN_ID;
  let acceptanceRunId = options.acceptanceRunId || env.ACCEPTANCE_RUN_ID;
  let setupDir = options.setupDir || env.SETUP_DIR || 'setup-assets';
  let evidenceDir = options.evidenceDir || env.EVIDENCE_DIR || 'setup13-release-evidence';
  let carrierDir = options.carrierDir || env.CARRIER_DIR || 'setup13-channel-carrier';

  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--setup-run-id' && argv[i + 1]) {
      setupRunId = argv[++i];
    } else if (argv[i] === '--acceptance-run-id' && argv[i + 1]) {
      acceptanceRunId = argv[++i];
    } else if (argv[i] === '--setup-dir' && argv[i + 1]) {
      setupDir = argv[++i];
    } else if (argv[i] === '--evidence-dir' && argv[i + 1]) {
      evidenceDir = argv[++i];
    } else if (argv[i] === '--carrier-dir' && argv[i + 1]) {
      carrierDir = argv[++i];
    }
  }

  assertNumericPositiveId(setupRunId, 'setup_run_id');
  assertNumericPositiveId(acceptanceRunId, 'acceptance_run_id');

  console.log(`Setup build run ID:      ${setupRunId}`);
  console.log(`Acceptance proof run ID: ${acceptanceRunId}`);

  // 3. Query & Verify Both Workflow Runs via GitHub REST API
  console.log('[1/6] Verifying setup and acceptance workflow runs via GitHub Actions REST API...');
  const setupRunRes = await apiRequest(`/repos/${repository}/actions/runs/${setupRunId}`);
  assert.ok(setupRunRes.ok, `Failed to query setup run ${setupRunId}: HTTP ${setupRunRes.status}`);
  validateWorkflowRun(setupRunRes.data, setupRunId, EXPECTED_SETUP_WORKFLOW, githubSha, 'Setup run');

  const acceptanceRunRes = await apiRequest(`/repos/${repository}/actions/runs/${acceptanceRunId}`);
  assert.ok(acceptanceRunRes.ok, `Failed to query acceptance run ${acceptanceRunId}: HTTP ${acceptanceRunRes.status}`);
  validateWorkflowRun(acceptanceRunRes.data, acceptanceRunId, EXPECTED_ACCEPTANCE_WORKFLOW, githubSha, 'Acceptance run');

  // 4. Verify Remote Tag & Ensure Release Does NOT Already Exist
  console.log(`[2/6] Verifying remote tag ${EXPECTED_TAG} and ensuring release does not exist...`);
  const tagRefRes = await apiRequest(`/repos/${repository}/git/ref/tags/${EXPECTED_TAG}`);
  if (tagRefRes.status === 404) {
    throw new Error(`CRITICAL REFUSAL: Remote tag ${EXPECTED_TAG} does not exist on remote (HTTP 404). Tag must pre-exist and peel to ${githubSha}.`);
  }
  assert.ok(tagRefRes.ok, `Failed to query remote tag ${EXPECTED_TAG}: HTTP ${tagRefRes.status}`);

  let peeledCommit = tagRefRes.data.object.sha;
  if (tagRefRes.data.object.type === 'tag') {
    const tagObjRes = await apiRequest(`/repos/${repository}/git/tags/${peeledCommit}`);
    assert.ok(tagObjRes.ok, `Failed resolving annotated tag object ${peeledCommit}: HTTP ${tagObjRes.status}`);
    peeledCommit = tagObjRes.data.object.sha;
  }
  validateRemoteTag(
    { object: { type: 'commit', sha: peeledCommit } },
    null,
    githubSha
  );

  const releaseCheckRes = await apiRequest(`/repos/${repository}/releases/tags/${EXPECTED_TAG}`);
  validateNoExistingRelease(releaseCheckRes);
  console.log('      PASS: Remote tag and non-existence of release strictly verified.');

  // 5. Strictly Validate Setup Build Assets Directory
  console.log('[3/6] Validating downloaded Windows Setup build assets (--setup-dir)...');
  const setupDirResolved = path.resolve(repoRoot, setupDir);
  const { setupHashes, setupSizes, inventoryInfo } = validateSetupDirectory(setupDirResolved);
  console.log('      PASS: Exact 5 setup assets verified.');

  // 6. Strictly Validate Evidence Directory (release-provenance.json + acceptance.json)
  console.log('[4/6] Validating release evidence proof contract (--evidence-dir)...');
  const provenance = validateEvidenceDirectory(path.resolve(repoRoot, evidenceDir), {
    expectedSetupRunId: setupRunId,
    expectedAcceptanceRunId: acceptanceRunId,
    expectedSha: githubSha,
    setupHashes,
    setupSizes
  });
  console.log('      PASS: Provenance proof contract and acceptance.json verified.');

  // 7. Strictly Validate Carrier Directory
  console.log('[5/6] Validating Channel Transition Carrier assets (--carrier-dir)...');
  validateCarrierDirectory(path.resolve(repoRoot, carrierDir), provenance, inventoryInfo);
  console.log('      PASS: Exact 18 carrier files verified.');

  // 8. Stage Verified Assets & Invoke Hardened Publisher
  console.log('[6/6] Staging verified assets into owned RUNNER_TEMP view and promoting...');
  stageAndPromote({
    setupDir: setupDirResolved,
    runnerTemp,
    repoRoot,
    actualSha: githubSha,
    token
  });

  console.log('\n================================================================================');
  console.log(` SETUP 0.0.13 PROMOTED SUCCESSFULLY!`);
  console.log(` Provenance verified: Setup run ${setupRunId}, Acceptance run ${acceptanceRunId}, commit ${githubSha}`);
  console.log('================================================================================\n');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  promoteSetup13().catch((err) => {
    console.error(`\nCRITICAL PROMOTION FAILURE: ${err.message}`);
    process.exit(1);
  });
}
