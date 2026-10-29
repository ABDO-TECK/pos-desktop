#!/usr/bin/env node
/**
 * scripts/publish-setup13-carrier.mjs
 *
 * Secure GHA-ONLY Setup 0.0.13 Channel Carrier stage/publish orchestrator for pos-desktop-updates.
 *
 * Enforces:
 *   1. Restricted strictly to ephemeral GitHub Actions under ABDO-TECK/pos-desktop-updates.
 *   2. Validates actual GITHUB_SHA (40 hex lowercase) == checked out TOOLING HEAD commit.
 *   3. Validates release-source checkout HEAD == ee5bf691c4acf6ffcd7730db1d7d0ed24f274787 (Tag v0.0.13).
 *   4. Validates guard-tooling checkout HEAD == 43cebef67292fa39badfd645149292d2371c0da2.
 *   5. Validates all original downloaded bytes BEFORE any write:
 *      - 5 setup assets via promoteModule.validateSetupDirectory
 *      - release provenance and acceptance receipt via promoteModule.validateEvidenceDirectory
 *      - 18 carrier files via promoteModule.validateCarrierDirectory
 *      - RSA signatures on transition index and 4 transition descriptors via update_public_key.pem
 *      - precheck runs and refs + verify frozen old assets
 *   6. Frozen refs validation:
 *      - Source main: a00dd84dda56ef50d0bd9880cd0eb24802c88ed7
 *      - Source v0.0.12 tag object: d0f8a2968c817ce77c238c623b7c8111c52d1e5c
 *      - Updates main: f67c564a8ec24dbe2fe522d622dad13437c376a3
 *      - Updates v0.0.12 tag: 916e21077188914dfe57aa3dbf5c4d1d51fda3bc
 *      - Updates v0.0.12-beta.1 tag: a254ba52b4a48cf8df65daed0b528191fadb4671
 *   7. STAGE mode:
 *      - Ensures Updates tag v0.0.13 exists at f67c564a8ec24dbe2fe522d622dad13437c376a3 (creates if absent).
 *      - Creates or reuses owned draft release with immutable ownership marker HTML comment.
 *      - Uploads 18 accepted carrier files (skips only if exact name, state 'uploaded', size, and sha256 server digest match).
 *      - Re-verifies all 18 server digests via bounded poll; fails closed if any mismatch or pending timeout.
 *      - Draft remains draft: true, prerelease: false, make_latest: false.
 *   8. PUBLISH mode:
 *      - Requires explicit positive numeric release_id.
 *      - Verifies ownership marker matches current tooling SHA, source SHA, run IDs, and tag commit.
 *      - Redoes all byte, evidence, and RSA signature checks.
 *      - Reads and validates actual Updates tag v0.0.13 points to f67c564a8ec24dbe2fe522d622dad13437c376a3 before any PATCH.
 *      - Verifies Source Setup 0.0.13 release is ALREADY PUBLIC (draft: false, prerelease: false, mandatory sha256 digests).
 *      - Downloads public 5 raw Setup assets from github.com and proves byte-equality against evidence.
 *      - Pre-mutation guard assertCarrierPublishAllowed verifies mode, release, and all checks before PATCH.
 *      - Patches carrier release draft: false, prerelease: false, make_latest: true.
 *      - Re-verifies public carrier release 18 server digests, frozen refs, and frozen old assets.
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const SOURCE_REPO = 'ABDO-TECK/pos-desktop';
export const UPDATES_REPO = 'ABDO-TECK/pos-desktop-updates';
export const EXPECTED_VERSION = '0.0.13';
export const EXPECTED_TAG = 'v0.0.13';

export const SETUP_RUN_ID = '40939314357';
export const ACCEPTANCE_RUN_ID = '40943059736';
export const EXPECTED_SOURCE_SHA = 'ee5bf691c4acf6ffcd7730db1d7d0ed24f274787';
export const EXPECTED_GUARD_SHA = '43cebef67292fa39badfd645149292d2371c0da2';

export const FROZEN_UPDATES_MAIN_SHA = 'f67c564a8ec24dbe2fe522d622dad13437c376a3';
export const FROZEN_SOURCE_MAIN_SHA = 'a00dd84dda56ef50d0bd9880cd0eb24802c88ed7';
export const FROZEN_SOURCE_V12_SHA = 'd0f8a2968c817ce77c238c623b7c8111c52d1e5c';
export const FROZEN_UPDATES_V12_SHA = '916e21077188914dfe57aa3dbf5c4d1d51fda3bc';
export const FROZEN_UPDATES_BETA12_1_SHA = 'a254ba52b4a48cf8df65daed0b528191fadb4671';

export const SETUP_EXE_SHA256 = '442f110ee7f188f2cb9d1a9b0b1859074e7b953bfb2230cb86449a64ee09c62e';
export const SETUP_EXE_SIZE = 322568909;
export const EXPECTED_INVENTORY_SHA256 = '4f3b41f28a6bf4ede11487880c46d6bd9048bc1bc1d4c511426cb9e7e08e1fb4';
export const PHYSICAL_RETAINED_SHELL_SHA256 = '21de7ba387ccb9e87fdd71e464ba112a95f7aa23996a4c54f3b965ee22d01a5b';

export const EXACT_5_SETUP_ASSETS = Object.freeze([
  'POS-Desktop-Setup-0.0.13.exe',
  'POS-Desktop-Setup-0.0.13.exe.blockmap',
  'latest.yml',
  'deployment-inventory.json',
  'SHA256SUMS.txt'
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

export const OWNERSHIP_MARKER_PREFIX = '<!-- setup13-carrier-draft: ';
export const OWNERSHIP_MARKER_SUFFIX = ' -->';

export const ALLOWED_STAGING_PREFIXES = Object.freeze([
  'setup13-carrier-',
  'setup13-public-source-'
]);

export const ALLOWED_API_PATH_PREFIXES = Object.freeze([
  `/repos/${SOURCE_REPO}/`,
  `/repos/${UPDATES_REPO}/`
]);

const registeredStagingDirs = new Set();

export function assertNoReparse(targetPath) {
  let cur = path.resolve(targetPath);
  while (cur && path.dirname(cur) !== cur) {
    if (fs.existsSync(cur) && fs.lstatSync(cur).isSymbolicLink()) {
      throw new Error(`Symlink or reparse point rejected: ${cur}`);
    }
    const parent = path.dirname(cur);
    if (parent === cur) break;
    cur = parent;
  }
}

export function assertStrictLowerHex40(str, name) {
  assert.equal(typeof str, 'string', `${name} must be a string`);
  assert.ok(
    /^[a-f0-9]{40}$/.test(str),
    `${name} must be exactly 40 lowercase hex characters (got: '${str}')`
  );
}

export function assertStrictLowerHex64(str, name) {
  assert.equal(typeof str, 'string', `${name} must be a string`);
  assert.ok(
    /^[a-f0-9]{64}$/.test(str),
    `${name} must be exactly 64 lowercase hex characters (got: '${str}')`
  );
}

export function assertPositiveInteger(value, name) {
  assert.ok(
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0,
    `${name} must be a safe positive integer (got: ${value})`
  );
}

export function assertNumericPositiveId(id, name) {
  assert.equal(typeof id, 'string', `${name} must be a string`);
  assert.ok(
    /^[1-9]\d*$/.test(id),
    `${name} must be a valid positive numeric ID string (got: '${id}')`
  );
}

export function parseCliArgs(argv = process.argv.slice(2), env = process.env) {
  const args = {};
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--mode' && i + 1 < argv.length) args.mode = argv[++i];
    else if (arg === '--release-id' && i + 1 < argv.length) args.releaseId = argv[++i];
    else if (arg === '--tooling-dir' && i + 1 < argv.length) args.toolingDir = argv[++i];
    else if (arg === '--release-source-dir' && i + 1 < argv.length) args.releaseSourceDir = argv[++i];
    else if (arg === '--guard-tooling-dir' && i + 1 < argv.length) args.guardToolingDir = argv[++i];
    else if (arg === '--setup-dir' && i + 1 < argv.length) args.setupDir = argv[++i];
    else if (arg === '--evidence-dir' && i + 1 < argv.length) args.evidenceDir = argv[++i];
    else if (arg === '--carrier-dir' && i + 1 < argv.length) args.carrierDir = argv[++i];
  }

  const mode = (args.mode || env.CARRIER_MODE || 'stage').trim().toLowerCase();
  assert.ok(mode === 'stage' || mode === 'publish', `Invalid mode: ${mode}. Must be 'stage' or 'publish'`);

  const releaseId = (args.releaseId || env.RELEASE_ID || '').trim();
  if (mode === 'publish') {
    assert.ok(
      releaseId.length > 0 && /^[1-9]\d*$/.test(releaseId),
      `release_id is required and must be a positive integer in publish mode (got: '${releaseId}')`
    );
  } else if (releaseId.length > 0) {
    assert.ok(
      /^[1-9]\d*$/.test(releaseId),
      `release_id if provided in stage mode must be a positive integer (got: '${releaseId}')`
    );
  }

  const toolingDir = path.resolve(args.toolingDir || env.TOOLING_DIR || '.');
  const releaseSourceDir = path.resolve(args.releaseSourceDir || env.RELEASE_SOURCE_DIR || 'release-source');
  const guardToolingDir = path.resolve(args.guardToolingDir || env.GUARD_TOOLING_DIR || 'guard-tooling');
  const setupDir = path.resolve(args.setupDir || env.SETUP_DIR || path.join(env.RUNNER_TEMP || '', 'windows-setup'));
  const evidenceDir = path.resolve(args.evidenceDir || env.EVIDENCE_DIR || path.join(env.RUNNER_TEMP || '', 'evidence'));
  const carrierDir = path.resolve(args.carrierDir || env.CARRIER_DIR || path.join(env.RUNNER_TEMP || '', 'carrier'));

  return {
    mode,
    releaseId: releaseId || null,
    toolingDir,
    releaseSourceDir,
    guardToolingDir,
    setupDir,
    evidenceDir,
    carrierDir
  };
}

export function validateEnvironment(env = process.env) {
  assert.equal(
    env.GITHUB_ACTIONS,
    'true',
    'publish-setup13-carrier.mjs is strictly restricted to ephemeral GitHub Actions runners'
  );

  const runnerTemp = env.RUNNER_TEMP;
  assert.ok(runnerTemp && fs.existsSync(runnerTemp), 'RUNNER_TEMP directory is required and must exist');
  assertNoReparse(runnerTemp);

  assert.equal(
    env.GITHUB_REPOSITORY,
    UPDATES_REPO,
    `GITHUB_REPOSITORY must be ${UPDATES_REPO} (got: ${env.GITHUB_REPOSITORY})`
  );

  const token = (env.GH_TOKEN || env.GITHUB_TOKEN || '').trim();
  assert.ok(token.length > 0, 'GH_TOKEN or GITHUB_TOKEN environment variable is required');

  const rawGithubSha = env.GITHUB_SHA;
  assert.equal(typeof rawGithubSha, 'string', 'GITHUB_SHA environment variable must be a string');
  assertStrictLowerHex40(rawGithubSha, 'GITHUB_SHA');
  const githubSha = rawGithubSha;

  return { runnerTemp, repository: UPDATES_REPO, token, githubSha };
}

export function validateGitHead(dir, expectedSha, label) {
  assert.ok(fs.existsSync(dir), `Directory ${label} missing: ${dir}`);
  assertNoReparse(dir);
  const head = execFileSync('git', ['-C', dir, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim().toLowerCase();
  assertStrictLowerHex40(head, `${label} HEAD`);
  assert.equal(
    head,
    expectedSha.toLowerCase(),
    `${label} checked out HEAD commit (${head}) does not match expected SHA (${expectedSha})`
  );
  return head;
}

export function validateApiEndpoint(endpoint) {
  assert.equal(typeof endpoint, 'string', 'API endpoint must be a string');
  let parsed;
  if (endpoint.startsWith('https://')) {
    parsed = new URL(endpoint);
  } else {
    assert.ok(endpoint.startsWith('/'), `Relative API endpoint must start with '/': ${endpoint}`);
    parsed = new URL(`https://api.github.com${endpoint}`);
  }

  assert.equal(parsed.protocol, 'https:', 'API endpoint must use HTTPS');
  assert.equal(parsed.hostname, 'api.github.com', `API hostname must be 'api.github.com', got: ${parsed.hostname}`);
  assert.equal(parsed.username, '', 'API URL must not contain username credentials');
  assert.equal(parsed.password, '', 'API URL must not contain password credentials');
  assert.equal(parsed.port, '', 'API URL must not specify custom port');

  const pathname = parsed.pathname;
  const isAllowedPath = ALLOWED_API_PATH_PREFIXES.some((prefix) => pathname.startsWith(prefix));
  assert.ok(
    isAllowedPath,
    `API endpoint path '${pathname}' is not under authorized repositories (${SOURCE_REPO}, ${UPDATES_REPO})`
  );

  return parsed.toString();
}

export function createOwnershipMarker(opts) {
  const { sourceSha, setupRunId, acceptanceRunId, toolingSha, tagTarget } = opts;
  assertStrictLowerHex40(sourceSha, 'sourceSha');
  assert.equal(typeof setupRunId, 'string', 'setupRunId must be a string');
  assertNumericPositiveId(setupRunId, 'setupRunId');
  assert.equal(typeof acceptanceRunId, 'string', 'acceptanceRunId must be a string');
  assertNumericPositiveId(acceptanceRunId, 'acceptanceRunId');
  assertStrictLowerHex40(toolingSha, 'toolingSha');
  assertStrictLowerHex40(tagTarget, 'tagTarget');

  const markerObj = {
    schema_version: '1.0.0',
    type: 'carrier_draft',
    source_repo: SOURCE_REPO,
    source_sha: sourceSha,
    setup_run_id: setupRunId,
    acceptance_run_id: acceptanceRunId,
    tooling_sha: toolingSha,
    tag_target: tagTarget
  };
  return `${OWNERSHIP_MARKER_PREFIX}${JSON.stringify(markerObj)}${OWNERSHIP_MARKER_SUFFIX}`;
}

export function validateReleaseOwnershipMarker(body, expected) {
  assert.ok(typeof body === 'string' && body.length > 0, 'Release body must be a non-empty string');

  const firstIdx = body.indexOf(OWNERSHIP_MARKER_PREFIX);
  assert.ok(firstIdx !== -1, 'Release body missing required carrier ownership marker');

  const secondIdx = body.indexOf(OWNERSHIP_MARKER_PREFIX, firstIdx + OWNERSHIP_MARKER_PREFIX.length);
  assert.equal(secondIdx, -1, 'Duplicate carrier ownership marker in release body rejected');

  const endIdx = body.indexOf(OWNERSHIP_MARKER_SUFFIX, firstIdx + OWNERSHIP_MARKER_PREFIX.length);
  assert.ok(endIdx !== -1, 'Malformed carrier ownership marker: missing suffix');

  const jsonStr = body.slice(firstIdx + OWNERSHIP_MARKER_PREFIX.length, endIdx).trim();
  let marker;
  try {
    marker = JSON.parse(jsonStr);
  } catch (err) {
    throw new Error(`Invalid JSON in carrier ownership marker: ${err.message}`);
  }

  assert.ok(marker && typeof marker === 'object' && !Array.isArray(marker), 'Ownership marker must be an object');

  const EXPECTED_MARKER_KEYS = [
    'acceptance_run_id',
    'schema_version',
    'setup_run_id',
    'source_repo',
    'source_sha',
    'tag_target',
    'tooling_sha',
    'type'
  ];
  const actualKeys = Object.keys(marker).sort();
  assert.deepEqual(actualKeys, EXPECTED_MARKER_KEYS, 'Ownership marker has extra or missing keys');

  assert.equal(marker.schema_version, '1.0.0', 'Ownership marker schema_version must be 1.0.0');
  assert.equal(marker.type, 'carrier_draft', 'Ownership marker type must be carrier_draft');
  assert.equal(marker.source_repo, SOURCE_REPO, `Ownership marker source_repo mismatch: ${marker.source_repo}`);

  assertStrictLowerHex40(marker.source_sha, 'marker.source_sha');
  assert.equal(marker.source_sha, expected.sourceSha, `Ownership marker source_sha mismatch: ${marker.source_sha}`);

  assert.equal(typeof marker.setup_run_id, 'string', 'marker.setup_run_id must be a string (numeric IDs rejected)');
  assertNumericPositiveId(marker.setup_run_id, 'marker.setup_run_id');
  assert.equal(marker.setup_run_id, expected.setupRunId, 'Ownership marker setup_run_id mismatch');

  assert.equal(typeof marker.acceptance_run_id, 'string', 'marker.acceptance_run_id must be a string (numeric IDs rejected)');
  assertNumericPositiveId(marker.acceptance_run_id, 'marker.acceptance_run_id');
  assert.equal(marker.acceptance_run_id, expected.acceptanceRunId, 'Ownership marker acceptance_run_id mismatch');

  assertStrictLowerHex40(marker.tooling_sha, 'marker.tooling_sha');
  assert.equal(marker.tooling_sha, expected.toolingSha, `Ownership marker tooling_sha mismatch: ${marker.tooling_sha}`);

  assertStrictLowerHex40(marker.tag_target, 'marker.tag_target');
  assert.equal(marker.tag_target, expected.tagTarget, `Ownership marker tag_target mismatch: ${marker.tag_target}`);

  return marker;
}

export function validateCarrierAssetsMap(serverAssets, expectedArtifacts) {
  assert.ok(Array.isArray(serverAssets), 'serverAssets must be an array');
  assert.ok(expectedArtifacts && typeof expectedArtifacts === 'object' && !Array.isArray(expectedArtifacts), 'expectedArtifacts map required');

  const expectedNames = Object.keys(expectedArtifacts).sort();
  assert.deepEqual(expectedNames, [...EXACT_18_CARRIER_FILES].sort(), 'expectedArtifacts keys must match EXACT_18_CARRIER_FILES exactly');

  for (const [name, meta] of Object.entries(expectedArtifacts)) {
    assert.ok(meta && typeof meta === 'object' && !Array.isArray(meta), `Metadata for ${name} must be an object`);
    assert.ok(typeof meta.size === 'number' && Number.isSafeInteger(meta.size) && meta.size > 0, `meta.size for ${name} must be positive safe integer`);
    assertStrictLowerHex64(meta.sha256, `meta.sha256 for ${name}`);
  }

  assert.equal(serverAssets.length, 18, `serverAssets must contain exactly 18 assets, got ${serverAssets.length}`);

  const actualNames = serverAssets.map((a) => a.name).sort();
  const uniqueNames = new Set(serverAssets.map((a) => a.name));
  assert.equal(uniqueNames.size, 18, 'serverAssets contains duplicate asset names');
  assert.deepEqual(actualNames, expectedNames, `Release assets do not match expected 18 carrier files: found [${actualNames.join(', ')}]`);

  for (const asset of serverAssets) {
    const expected = expectedArtifacts[asset.name];
    assert.ok(expected, `Unknown asset ${asset.name}`);
    assert.equal(asset.state, 'uploaded', `Asset ${asset.name} state is not uploaded: ${asset.state}`);
    assert.equal(typeof asset.size, 'number', `Asset ${asset.name} size must be a number`);
    assert.ok(Number.isSafeInteger(asset.size) && asset.size > 0, `Asset ${asset.name} size must be a positive safe integer`);
    assert.equal(asset.size, expected.size, `Asset ${asset.name} size mismatch: got ${asset.size}, expected ${expected.size}`);

    assert.equal(typeof asset.digest, 'string', `Asset ${asset.name} digest must be a string`);
    assert.match(asset.digest, /^sha256:[a-f0-9]{64}$/, `Asset ${asset.name} server digest format invalid (strict lowercase required): ${asset.digest}`);

    const expectedDigest = `sha256:${expected.sha256}`;
    assert.equal(asset.digest, expectedDigest, `Asset ${asset.name} server digest mismatch: got ${asset.digest}, expected ${expectedDigest}`);
  }
}

export function validateSourceSetupPublicRelease(release, expectedSetupAssets) {
  assert.ok(release && typeof release === 'object' && !Array.isArray(release), 'Source release object missing');
  assert.equal(typeof release.draft, 'boolean', 'Source Setup release draft must be boolean');
  assert.equal(release.draft, false, 'Source Setup release must not be draft');
  assert.equal(typeof release.prerelease, 'boolean', 'Source Setup release prerelease must be boolean');
  assert.equal(release.prerelease, false, 'Source Setup release must not be prerelease');
  assert.equal(release.tag_name, EXPECTED_TAG, `Source Setup release tag mismatch: ${release.tag_name}`);

  const expectedNames = Object.keys(expectedSetupAssets).sort();
  assert.deepEqual(expectedNames, [...EXACT_5_SETUP_ASSETS].sort(), 'expectedSetupAssets must match EXACT_5_SETUP_ASSETS exactly');

  for (const [name, meta] of Object.entries(expectedSetupAssets)) {
    assert.ok(meta && typeof meta === 'object' && !Array.isArray(meta), `Metadata for ${name} must be an object`);
    assert.ok(typeof meta.size === 'number' && Number.isSafeInteger(meta.size) && meta.size > 0, `meta.size for ${name} must be positive safe integer`);
    assertStrictLowerHex64(meta.sha256, `meta.sha256 for ${name}`);
  }

  const assets = release.assets;
  assert.ok(Array.isArray(assets), 'Source release assets must be an array');
  assert.equal(assets.length, 5, `Source Setup release must contain exactly 5 assets, got ${assets.length}`);

  const actualNames = assets.map((a) => a.name).sort();
  const uniqueNames = new Set(assets.map((a) => a.name));
  assert.equal(uniqueNames.size, 5, 'Source Setup release contains duplicate asset names');
  assert.deepEqual(actualNames, expectedNames, `Source Setup assets do not match expected 5 assets: [${actualNames.join(', ')}]`);

  for (const a of assets) {
    const exp = expectedSetupAssets[a.name];
    assert.ok(exp, `Unexpected setup asset: ${a.name}`);
    assert.equal(a.state, 'uploaded', `Source setup asset ${a.name} state is not uploaded: ${a.state}`);
    assert.equal(typeof a.size, 'number', `Source setup asset ${a.name} size must be a number`);
    assert.ok(Number.isSafeInteger(a.size) && a.size > 0, `Source setup asset ${a.name} size must be positive safe integer`);
    assert.equal(a.size, exp.size, `Source setup asset ${a.name} size mismatch: got ${a.size}, expected ${exp.size}`);

    assert.equal(typeof a.digest, 'string', `Source setup asset ${a.name} server digest missing or not string`);
    assert.match(a.digest, /^sha256:[a-f0-9]{64}$/, `Source setup asset ${a.name} server digest format invalid: ${a.digest}`);
    assert.equal(a.digest, `sha256:${exp.sha256}`, `Source setup asset ${a.name} digest mismatch`);
  }
}

export function createOwnedStaging(runnerTemp, prefix) {
  assert.ok(typeof runnerTemp === 'string' && runnerTemp.length > 0, 'runnerTemp is required');
  assertNoReparse(runnerTemp);
  assert.ok(
    ALLOWED_STAGING_PREFIXES.includes(prefix),
    `Prefix '${prefix}' is not in ALLOWED_STAGING_PREFIXES: [${ALLOWED_STAGING_PREFIXES.join(', ')}]`
  );

  const createdDir = fs.mkdtempSync(path.join(runnerTemp, prefix));
  assertNoReparse(createdDir);
  const resolved = path.resolve(createdDir);
  registeredStagingDirs.add(resolved);
  return resolved;
}

export function cleanupOwnedStaging(dir, runnerTemp) {
  if (!dir) return;
  assert.ok(typeof runnerTemp === 'string' && runnerTemp.length > 0, 'runnerTemp is required');
  assertNoReparse(runnerTemp);

  const resolved = path.resolve(dir);
  assert.ok(
    registeredStagingDirs.has(resolved),
    `Refusing to cleanup unregistered directory: ${resolved}`
  );

  const rel = path.relative(runnerTemp, resolved);
  assert.ok(!rel.startsWith('..') && !path.isAbsolute(rel) && rel.length > 0, `Staging outside RUNNER_TEMP: ${resolved}`);

  const base = path.basename(resolved);
  const matchesPrefix = ALLOWED_STAGING_PREFIXES.some((p) => base.startsWith(p));
  assert.ok(matchesPrefix, `Refusing to cleanup directory with unauthorized prefix: ${base}`);

  assertNoReparse(resolved);
  const st = fs.lstatSync(resolved);
  assert.ok(st.isDirectory() && !st.isSymbolicLink(), `Not directory or is symlink: ${resolved}`);

  fs.rmSync(resolved, { recursive: true, force: true });
  registeredStagingDirs.delete(resolved);
}

export function assertCarrierPublishAllowed(opts) {
  assert.ok(opts && typeof opts === 'object', 'Publish transition options required');
  const {
    mode,
    release,
    isOwnershipVerified,
    isSourcePublicVerified,
    areCarrierAssetsVerified,
    areFrozenRefsVerified
  } = opts;

  assert.equal(mode, 'publish', `Operation mode must be 'publish' to perform publication, got '${mode}'`);
  assert.ok(release && typeof release === 'object', 'Release object required');
  assert.equal(typeof release.draft, 'boolean', 'Release draft property must be boolean');
  assert.equal(typeof release.prerelease, 'boolean', 'Release prerelease property must be boolean');
  assert.equal(release.prerelease, false, 'Refusing to publish release with prerelease: true');
  assert.equal(release.tag_name, EXPECTED_TAG, `Release tag mismatch: expected ${EXPECTED_TAG}, got ${release.tag_name}`);
  assert.equal(
    release.author?.login,
    'github-actions[bot]',
    `Release author must be 'github-actions[bot]', got '${release.author?.login}'`
  );

  assert.equal(isOwnershipVerified, true, 'Ownership marker must be verified before publication');
  assert.equal(areCarrierAssetsVerified, true, 'Carrier 18 assets must be verified on server before publication');
  assert.equal(isSourcePublicVerified, true, 'Source Setup release must be public and verified before publication');
  assert.equal(areFrozenRefsVerified, true, 'Frozen refs and assets must be verified before publication');

  return true;
}

export async function downloadAndHash(downloadUrl, savePath = null) {
  const u = new URL(downloadUrl);
  assert.equal(u.protocol, 'https:', 'Download URL must be HTTPS');
  assert.ok(
    u.hostname === 'github.com' || u.hostname.endsWith('.githubusercontent.com') || u.hostname === 'objects.githubusercontent.com',
    `Untrusted host for download: ${u.hostname}`
  );

  const res = await fetch(downloadUrl, { redirect: 'follow' });
  if (res.status !== 200) {
    throw new Error(`Download failed with HTTP ${res.status} from ${downloadUrl}`);
  }

  const hash = crypto.createHash('sha256');
  let size = 0;
  let outStream = savePath ? fs.createWriteStream(savePath) : null;

  for await (const chunk of res.body) {
    hash.update(chunk);
    size += chunk.length;
    if (outStream) {
      outStream.write(chunk);
    }
  }

  if (outStream) {
    await new Promise((resolve, reject) => {
      outStream.end(resolve);
      outStream.on('error', reject);
    });
  }

  return { sha256: hash.digest('hex').toLowerCase(), size };
}

export async function downloadSourcePublicSetupAssets(stagingDir, expectedSetupAssets) {
  assert.ok(fs.existsSync(stagingDir), `Staging dir missing: ${stagingDir}`);
  assertNoReparse(stagingDir);

  const downloadedHashes = new Map();
  const downloadedSizes = new Map();

  for (const filename of EXACT_5_SETUP_ASSETS) {
    const rawUrl = `https://github.com/${SOURCE_REPO}/releases/download/${EXPECTED_TAG}/${encodeURIComponent(filename)}`;
    const dest = path.join(stagingDir, filename);
    const { sha256, size } = await downloadAndHash(rawUrl, dest);

    const exp = expectedSetupAssets[filename];
    assert.equal(sha256, exp.sha256, `Public downloaded asset ${filename} hash mismatch: got ${sha256}, expected ${exp.sha256}`);
    assert.equal(size, exp.size, `Public downloaded asset ${filename} size mismatch: got ${size}, expected ${exp.size}`);

    downloadedHashes.set(filename, sha256);
    downloadedSizes.set(filename, size);
  }

  return { downloadedHashes, downloadedSizes };
}

export async function githubApi(endpoint, token, options = {}) {
  const method = options.method || 'GET';
  const fullUrl = validateApiEndpoint(endpoint);
  const headers = {
    'User-Agent': 'Setup13CarrierPublisher',
    'Accept': 'application/vnd.github+json',
    ...(options.headers || {})
  };
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  let body = undefined;
  if (options.body !== undefined) {
    if (typeof options.body === 'object' && !(options.body instanceof Uint8Array) && !Buffer.isBuffer(options.body)) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.body);
    } else {
      body = options.body;
    }
  }

  const res = await fetch(fullUrl, {
    method,
    headers,
    body,
    redirect: 'manual'
  });

  if (res.status === 301 || res.status === 302 || res.status === 307 || res.status === 308) {
    throw new Error(`GitHub API fail-closed redirect HTTP ${res.status} on ${method} ${fullUrl}`);
  }

  if (res.status === 404 && options.allow404) {
    return null;
  }

  if (res.status < 200 || res.status >= 300) {
    const errorText = await res.text().catch(() => '');
    const err = new Error(`GitHub API fail-closed HTTP ${res.status} on ${method} ${fullUrl}: ${errorText}`);
    err.status = res.status;
    err.errorText = errorText;
    throw err;
  }

  if (res.status === 204) {
    return null;
  }

  return res.json();
}

export async function uploadReleaseAsset(releaseId, filename, filePath, token) {
  const fileBytes = fs.readFileSync(filePath);
  const uploadUrl = `https://uploads.github.com/repos/${UPDATES_REPO}/releases/${releaseId}/assets?name=${encodeURIComponent(filename)}`;

  const res = await fetch(uploadUrl, {
    method: 'POST',
    headers: {
      'User-Agent': 'Setup13CarrierPublisher',
      'Authorization': `Bearer ${token}`,
      'Accept': 'application/vnd.github+json',
      'Content-Type': 'application/octet-stream',
      'Content-Length': String(fileBytes.length)
    },
    body: fileBytes,
    duplex: 'half'
  });

  if (res.status < 200 || res.status >= 300) {
    const errorText = await res.text().catch(() => '');
    throw new Error(`Upload asset ${filename} failed HTTP ${res.status}: ${errorText}`);
  }

  return res.json();
}

export async function pollAndVerifyReleaseAssets(releaseId, token, expectedArtifacts) {
  const MAX_POLL_ATTEMPTS = 15;
  const POLL_INTERVAL_MS = 2000;

  for (let attempt = 1; attempt <= MAX_POLL_ATTEMPTS; attempt++) {
    const release = await githubApi(`/repos/${UPDATES_REPO}/releases/${releaseId}`, token);
    const assets = release.assets || [];

    if (assets.length !== 18) {
      if (attempt < MAX_POLL_ATTEMPTS) {
        await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
        continue;
      }
      throw new Error(`Expected exact 18 assets on release ${releaseId}, but found ${assets.length}`);
    }

    let allReady = true;
    for (const a of assets) {
      const exp = expectedArtifacts[a.name];
      if (!exp) {
        throw new Error(`Unknown extra asset on release: ${a.name}`);
      }
      if (a.state !== 'uploaded' || a.size !== exp.size) {
        allReady = false;
        break;
      }
      const digestStr = a.digest;
      if (typeof digestStr !== 'string' || !/^sha256:[a-f0-9]{64}$/.test(digestStr)) {
        allReady = false;
        break;
      }
      if (digestStr !== `sha256:${exp.sha256}`) {
        throw new Error(`Asset ${a.name} server digest mismatch: got ${digestStr}, expected sha256:${exp.sha256}`);
      }
    }

    if (allReady) {
      validateCarrierAssetsMap(assets, expectedArtifacts);
      return assets;
    }

    if (attempt < MAX_POLL_ATTEMPTS) {
      await new Promise((r) => setTimeout(r, POLL_INTERVAL_MS));
    }
  }

  throw new Error(`Bounded poll timed out waiting for all 18 asset server digests on release ${releaseId}`);
}

export async function verifyAllFrozenRefs(token) {
  const [srcMain, srcTag12, updMain, updTag12, updTagBeta] = await Promise.all([
    githubApi(`/repos/${SOURCE_REPO}/git/ref/heads/main`, token),
    githubApi(`/repos/${SOURCE_REPO}/git/ref/tags/v0.0.12`, token),
    githubApi(`/repos/${UPDATES_REPO}/git/ref/heads/main`, token),
    githubApi(`/repos/${UPDATES_REPO}/git/ref/tags/v0.0.12`, token),
    githubApi(`/repos/${UPDATES_REPO}/git/ref/tags/v0.0.12-beta.1`, token)
  ]);

  assert.equal(srcMain.object?.sha?.toLowerCase(), FROZEN_SOURCE_MAIN_SHA, 'Frozen Source main ref mutated');
  assert.equal(srcTag12.object?.sha?.toLowerCase(), FROZEN_SOURCE_V12_SHA, 'Frozen Source v0.0.12 tag ref mutated');
  assert.equal(updMain.object?.sha?.toLowerCase(), FROZEN_UPDATES_MAIN_SHA, 'Frozen Updates main ref mutated');
  assert.equal(updTag12.object?.sha?.toLowerCase(), FROZEN_UPDATES_V12_SHA, 'Frozen Updates v0.0.12 tag ref mutated');
  assert.equal(updTagBeta.object?.sha?.toLowerCase(), FROZEN_UPDATES_BETA12_1_SHA, 'Frozen Updates v0.0.12-beta.1 tag ref mutated');
}

export async function getUpdatesTagV13TargetCommit(token) {
  const existingTagRef = await githubApi(`/repos/${UPDATES_REPO}/git/ref/tags/${EXPECTED_TAG}`, token, { allow404: true });
  if (!existingTagRef) return null;
  let targetSha = existingTagRef.object?.sha?.toLowerCase();
  if (existingTagRef.object?.type === 'tag') {
    const tagObj = await githubApi(`/repos/${UPDATES_REPO}/git/tags/${targetSha}`, token);
    targetSha = tagObj?.object?.sha?.toLowerCase();
  }
  return targetSha;
}

export async function verifyUpdatesTagV13Target(token) {
  const targetSha = await getUpdatesTagV13TargetCommit(token);
  assert.ok(targetSha, `Tag ${EXPECTED_TAG} on ${UPDATES_REPO} must exist`);
  assert.equal(
    targetSha,
    FROZEN_UPDATES_MAIN_SHA,
    `Existing tag ${EXPECTED_TAG} on ${UPDATES_REPO} points to ${targetSha}, must point to ${FROZEN_UPDATES_MAIN_SHA}`
  );
  console.log(`[VERIFY] Verified tag ${EXPECTED_TAG} points to ${FROZEN_UPDATES_MAIN_SHA}`);
  return targetSha;
}

export async function ensureUpdatesTagV13(token) {
  const existingTagRef = await githubApi(`/repos/${UPDATES_REPO}/git/ref/tags/${EXPECTED_TAG}`, token, { allow404: true });
  if (!existingTagRef) {
    console.log(`[STAGE] Tag ${EXPECTED_TAG} absent on ${UPDATES_REPO}. Creating ref at commit ${FROZEN_UPDATES_MAIN_SHA}...`);
    await githubApi(`/repos/${UPDATES_REPO}/git/refs`, token, {
      method: 'POST',
      body: {
        ref: `refs/tags/${EXPECTED_TAG}`,
        sha: FROZEN_UPDATES_MAIN_SHA
      }
    });
    console.log(`[STAGE] Created tag ${EXPECTED_TAG} -> ${FROZEN_UPDATES_MAIN_SHA}`);
  } else {
    await verifyUpdatesTagV13Target(token);
  }
}

async function getOrCreateDraftRelease(token, toolingSha, releaseNotesBody) {
  const releases = await githubApi(`/repos/${UPDATES_REPO}/releases?per_page=30`, token);
  const matchingReleases = (releases || []).filter((r) => r.tag_name === EXPECTED_TAG);

  if (matchingReleases.length > 1) {
    throw new Error(`Multiple releases found for tag ${EXPECTED_TAG} on ${UPDATES_REPO}`);
  }

  const ownershipMarker = createOwnershipMarker({
    sourceSha: EXPECTED_SOURCE_SHA,
    setupRunId: SETUP_RUN_ID,
    acceptanceRunId: ACCEPTANCE_RUN_ID,
    toolingSha,
    tagTarget: FROZEN_UPDATES_MAIN_SHA
  });

  const fullBody = `${releaseNotesBody.trim()}\n\n${ownershipMarker}\n`;

  if (matchingReleases.length === 1) {
    const existing = matchingReleases[0];
    assert.equal(typeof existing.draft, 'boolean', 'Existing release draft property must be boolean');
    assert.equal(existing.draft, true, `Release for tag ${EXPECTED_TAG} already exists and is not draft (ID: ${existing.id})`);
    assert.equal(existing.prerelease, false, `Existing release for tag ${EXPECTED_TAG} must not be prerelease`);

    const authorLogin = existing.author?.login || '';
    assert.equal(
      authorLogin,
      'github-actions[bot]',
      `Existing release author must be exactly 'github-actions[bot]', got: '${authorLogin}'`
    );

    validateReleaseOwnershipMarker(existing.body, {
      sourceSha: EXPECTED_SOURCE_SHA,
      setupRunId: SETUP_RUN_ID,
      acceptanceRunId: ACCEPTANCE_RUN_ID,
      toolingSha,
      tagTarget: FROZEN_UPDATES_MAIN_SHA
    });

    assert.ok(
      existing.body.startsWith(releaseNotesBody.trim()),
      'Existing release body does not contain accepted release notes unchanged'
    );

    return { release: existing, isNew: false };
  }

  const newRelease = await githubApi(`/repos/${UPDATES_REPO}/releases`, token, {
    method: 'POST',
    body: {
      tag_name: EXPECTED_TAG,
      target_commitish: FROZEN_UPDATES_MAIN_SHA,
      name: 'POS Desktop 0.0.13 Channel Carrier (Baseline Setup 13)',
      body: fullBody,
      draft: true,
      prerelease: false
    }
  });

  return { release: newRelease, isNew: true };
}

async function stageReleaseAssets(release, carrierDir, token, carrierArtifacts) {
  const currentAssets = release.assets || [];
  const existingMap = new Map();

  for (const a of currentAssets) {
    assert.ok(
      EXACT_18_CARRIER_FILES.includes(a.name),
      `Unknown extra asset '${a.name}' found on release ${release.id}`
    );
    const exp = carrierArtifacts[a.name];
    assert.ok(exp, `Unexpected asset '${a.name}' in carrierArtifacts`);
    assert.equal(a.state, 'uploaded', `Existing asset '${a.name}' state is not 'uploaded': ${a.state}`);
    assert.equal(typeof a.size, 'number', `Existing asset '${a.name}' size must be number`);
    assert.equal(a.size, exp.size, `Existing asset '${a.name}' size mismatch: got ${a.size}, expected ${exp.size}`);

    assert.equal(typeof a.digest, 'string', `Existing asset '${a.name}' server digest is missing or not a string`);
    assert.match(
      a.digest,
      /^sha256:[a-f0-9]{64}$/,
      `Existing asset '${a.name}' server digest format invalid or pending: '${a.digest}'`
    );
    const expectedDigest = `sha256:${exp.sha256}`;
    assert.equal(
      a.digest,
      expectedDigest,
      `Existing asset '${a.name}' server digest mismatch: got ${a.digest}, expected ${expectedDigest}`
    );

    existingMap.set(a.name, a);
  }

  for (const filename of EXACT_18_CARRIER_FILES) {
    if (existingMap.has(filename)) {
      console.log(`[STAGE] Asset ${filename} already exists with strictly verified size and server digest; skipping upload.`);
      continue;
    }
    const filePath = path.join(carrierDir, filename);
    assert.ok(fs.existsSync(filePath), `Carrier file missing from artifact directory: ${filename}`);
    console.log(`[STAGE] Uploading carrier asset: ${filename}...`);
    await uploadReleaseAsset(release.id, filename, filePath, token);
  }

  console.log(`[STAGE] Verifying all 18 server digests via bounded poll...`);
  const finalAssets = await pollAndVerifyReleaseAssets(release.id, token, carrierArtifacts);
  return finalAssets;
}

async function executePublishMode(opts) {
  const { releaseId, toolingSha, token, runnerTemp, evidenceDir, carrierDir, provenance, promoteModule } = opts;
  assert.ok(releaseId, 'releaseId is required in publish mode');
  assertNumericPositiveId(releaseId, 'releaseId');

  const release = await githubApi(`/repos/${UPDATES_REPO}/releases/${releaseId}`, token);
  assert.equal(release.tag_name, EXPECTED_TAG, `Release tag mismatch: expected ${EXPECTED_TAG}, got ${release.tag_name}`);
  assert.equal(
    release.author?.login,
    'github-actions[bot]',
    `Carrier release author must be exactly 'github-actions[bot]', got: '${release.author?.login}'`
  );
  assert.equal(typeof release.draft, 'boolean', 'Carrier release draft must be boolean');
  assert.equal(release.prerelease, false, 'Carrier release prerelease must be false (refusing to publish prerelease)');

  validateReleaseOwnershipMarker(release.body, {
    sourceSha: EXPECTED_SOURCE_SHA,
    setupRunId: SETUP_RUN_ID,
    acceptanceRunId: ACCEPTANCE_RUN_ID,
    toolingSha,
    tagTarget: FROZEN_UPDATES_MAIN_SHA
  });

  const releaseNotesPath = path.join(carrierDir, 'release-notes.md');
  assert.ok(fs.existsSync(releaseNotesPath), 'release-notes.md missing');
  const releaseNotesBody = fs.readFileSync(releaseNotesPath, 'utf8');
  assert.ok(
    release.body.startsWith(releaseNotesBody.trim()),
    'Release body does not contain accepted release notes unchanged'
  );

  console.log(`[PUBLISH] Reading and verifying actual Updates tag ${EXPECTED_TAG} target commit...`);
  await verifyUpdatesTagV13Target(token);

  console.log(`[PUBLISH] Verifying 18 existing carrier asset digests on release ${releaseId}...`);
  await pollAndVerifyReleaseAssets(releaseId, token, provenance.carrier_artifacts);

  console.log(`[PUBLISH] Verifying Source Setup release is public and unmutated...`);
  const sourceRelease = await githubApi(`/repos/${SOURCE_REPO}/releases/tags/${EXPECTED_TAG}`, token);
  validateSourceSetupPublicRelease(sourceRelease, provenance.setup_assets);

  console.log(`[PUBLISH] Downloading public Source Setup assets to verify byte equality...`);
  const publicSetupDir = createOwnedStaging(runnerTemp, 'setup13-public-source-');
  try {
    const { downloadedHashes, downloadedSizes } = await downloadSourcePublicSetupAssets(publicSetupDir, provenance.setup_assets);

    const publicSetupResult = promoteModule.validateSetupDirectory(publicSetupDir);
    for (const [fn, h] of downloadedHashes.entries()) {
      assert.equal(publicSetupResult.setupHashes.get(fn), h, `Hash mismatch in public setup validation: ${fn}`);
      assert.equal(publicSetupResult.setupSizes.get(fn), downloadedSizes.get(fn), `Size mismatch in public setup validation: ${fn}`);
    }

    promoteModule.validateEvidenceDirectory(evidenceDir, {
      expectedSetupRunId: SETUP_RUN_ID,
      expectedAcceptanceRunId: ACCEPTANCE_RUN_ID,
      expectedSha: EXPECTED_SOURCE_SHA,
      setupHashes: publicSetupResult.setupHashes,
      setupSizes: publicSetupResult.setupSizes
    });
  } finally {
    cleanupOwnedStaging(publicSetupDir, runnerTemp);
  }

  assertCarrierPublishAllowed({
    mode: 'publish',
    release,
    isOwnershipVerified: true,
    isSourcePublicVerified: true,
    areCarrierAssetsVerified: true,
    areFrozenRefsVerified: true
  });

  let isAlreadyPublic = false;
  if (release.draft === false) {
    console.log(`[PUBLISH] Release ${releaseId} is ALREADY public; verification passed, skipping mutation.`);
    isAlreadyPublic = true;
  } else {
    console.log(`[PUBLISH] Publishing carrier release ${releaseId} (draft: false, prerelease: false, make_latest: true)...`);
    await githubApi(`/repos/${UPDATES_REPO}/releases/${releaseId}`, token, {
      method: 'PATCH',
      body: {
        draft: false,
        prerelease: false,
        make_latest: 'true'
      }
    });
  }

  const publishedRelease = await githubApi(`/repos/${UPDATES_REPO}/releases/${releaseId}`, token);
  assert.equal(publishedRelease.draft, false, 'Carrier release draft must be false after publication');
  assert.equal(publishedRelease.prerelease, false, 'Carrier release prerelease must be false after publication');

  await pollAndVerifyReleaseAssets(releaseId, token, provenance.carrier_artifacts);

  return { publishedRelease, isAlreadyPublic };
}

export async function main() {
  const envInfo = validateEnvironment(process.env);
  const parsedArgs = parseCliArgs(process.argv.slice(2), process.env);

  console.log(`[INIT] Setup 13 Carrier Orchestrator running in mode '${parsedArgs.mode}'`);

  try {
    const { toolingDir, releaseSourceDir, guardToolingDir, setupDir, evidenceDir, carrierDir } = parsedArgs;

    validateGitHead(toolingDir, envInfo.githubSha, 'TOOLING');
    validateGitHead(releaseSourceDir, EXPECTED_SOURCE_SHA, 'RELEASE_SOURCE');
    validateGitHead(guardToolingDir, EXPECTED_GUARD_SHA, 'GUARD_TOOLING');

    const promotePath = path.resolve(releaseSourceDir, 'scripts/promote-setup13.mjs');
    const finalizePath = path.resolve(releaseSourceDir, 'scripts/finalize-setup13-release-evidence.mjs');
    const guardVerifyPath = path.resolve(guardToolingDir, 'scripts/verify-setup13-public.mjs');

    assert.ok(fs.existsSync(promotePath), `Missing promote-setup13.mjs at ${promotePath}`);
    assert.ok(fs.existsSync(finalizePath), `Missing finalize-setup13-release-evidence.mjs at ${finalizePath}`);
    assert.ok(fs.existsSync(guardVerifyPath), `Missing verify-setup13-public.mjs at ${guardVerifyPath}`);

    const promoteModule = await import(pathToFileURL(promotePath).href);
    const finalizeModule = await import(pathToFileURL(finalizePath).href);
    const guardModule = await import(pathToFileURL(guardVerifyPath).href);

    console.log('[VALIDATION] Validating setup artifact directory...');
    const { setupHashes, setupSizes, inventoryInfo } = promoteModule.validateSetupDirectory(setupDir);
    assert.equal(setupHashes.get('POS-Desktop-Setup-0.0.13.exe'), SETUP_EXE_SHA256, 'Setup EXE SHA256 mismatch');
    assert.equal(setupSizes.get('POS-Desktop-Setup-0.0.13.exe'), SETUP_EXE_SIZE, 'Setup EXE size mismatch');
    assert.equal(inventoryInfo.sha256, EXPECTED_INVENTORY_SHA256, 'Setup inventory SHA256 mismatch');

    console.log('[VALIDATION] Validating release evidence and binding receipt...');
    const provenance = promoteModule.validateEvidenceDirectory(evidenceDir, {
      expectedSetupRunId: SETUP_RUN_ID,
      expectedAcceptanceRunId: ACCEPTANCE_RUN_ID,
      expectedSha: EXPECTED_SOURCE_SHA,
      setupHashes,
      setupSizes
    });

    const receiptPath = path.join(evidenceDir, 'setup13-binding-receipt.json');
    assert.ok(fs.existsSync(receiptPath), 'setup13-binding-receipt.json missing');
    const receipt = JSON.parse(fs.readFileSync(receiptPath, 'utf8'));
    assert.equal(receipt.installer_sha256, SETUP_EXE_SHA256, 'receipt installer_sha256 mismatch');
    assert.equal(receipt.setup_run_id, SETUP_RUN_ID, 'receipt setup_run_id mismatch');
    assert.equal(receipt.acceptance_run_id, ACCEPTANCE_RUN_ID, 'receipt acceptance_run_id mismatch');
    assert.equal(receipt.source_sha, EXPECTED_SOURCE_SHA, 'receipt source_sha mismatch');
    assert.equal(receipt.schema_version, '1.0.0', 'receipt schema_version mismatch');
    assert.equal(receipt.run_token, provenance.run_token, 'receipt run_token mismatch');

    assert.ok(receipt.retained_shell && typeof receipt.retained_shell === 'object' && !Array.isArray(receipt.retained_shell), 'receipt.retained_shell must be object');
    assert.ok(provenance.retained_shell && typeof provenance.retained_shell === 'object' && !Array.isArray(provenance.retained_shell), 'provenance.retained_shell must be object');
    assert.deepEqual(receipt.retained_shell, provenance.retained_shell, 'retained_shell deep equality mismatch');

    assert.equal(
      provenance.retained_shell.sha256,
      PHYSICAL_RETAINED_SHELL_SHA256,
      `provenance.retained_shell.sha256 mismatch: got ${provenance.retained_shell?.sha256}, expected ${PHYSICAL_RETAINED_SHELL_SHA256}`
    );
    assert.equal(
      receipt.retained_shell.sha256,
      PHYSICAL_RETAINED_SHELL_SHA256,
      `receipt.retained_shell.sha256 mismatch: got ${receipt.retained_shell?.sha256}, expected ${PHYSICAL_RETAINED_SHELL_SHA256}`
    );

    console.log('[VALIDATION] Validating carrier directory and artifacts...');
    promoteModule.validateCarrierDirectory(carrierDir, provenance, inventoryInfo);

    console.log('[VALIDATION] Verifying RSA signatures...');
    const pubKeyPath = path.join(releaseSourceDir, 'backend/certs/update_public_key.pem');
    assert.ok(fs.existsSync(pubKeyPath), `Public key missing: ${pubKeyPath}`);
    const pubKeyPem = fs.readFileSync(pubKeyPath, 'utf8');

    const rawIndex = fs.readFileSync(path.join(carrierDir, 'channel-transitions.json'), 'utf8');
    const rawIndexSig = fs.readFileSync(path.join(carrierDir, 'channel-transitions.sig'), 'utf8');
    finalizeModule.validateTransitionIndex(JSON.parse(rawIndex), pubKeyPem, rawIndex, rawIndexSig);

    for (const t of promoteModule.EXPECTED_TRANSITIONS) {
      const rawDesc = fs.readFileSync(path.join(carrierDir, `${t.stem}.json`), 'utf8');
      const rawDescSig = fs.readFileSync(path.join(carrierDir, `${t.stem}.sig`), 'utf8');
      assert.ok(
        finalizeModule.verifyRsaSignature(rawDesc, rawDescSig, pubKeyPem),
        `RSA signature failed for ${t.stem}.json`
      );
    }

    console.log('[VALIDATION] Prechecking source workflow runs and tag...');
    await guardModule.precheckRunsAndRefs({
      sourceSha: EXPECTED_SOURCE_SHA,
      setupRunId: SETUP_RUN_ID,
      acceptanceRunId: ACCEPTANCE_RUN_ID,
      token: envInfo.token,
      releaseSourceDir
    });

    console.log('[VALIDATION] Verifying all frozen refs...');
    await verifyAllFrozenRefs(envInfo.token);

    console.log('[VALIDATION] Verifying frozen old assets...');
    await guardModule.verifyFrozenOldAssets(envInfo.token);

    if (parsedArgs.mode === 'stage') {
      await ensureUpdatesTagV13(envInfo.token);

      const releaseNotesPath = path.join(carrierDir, 'release-notes.md');
      assert.ok(fs.existsSync(releaseNotesPath), 'release-notes.md missing');
      const releaseNotesBody = fs.readFileSync(releaseNotesPath, 'utf8');

      const { release, isNew } = await getOrCreateDraftRelease(envInfo.token, envInfo.githubSha, releaseNotesBody);
      console.log(`[STAGE] Draft release ID ${release.id} (${isNew ? 'newly created' : 'reused existing'}).`);

      await stageReleaseAssets(release, carrierDir, envInfo.token, provenance.carrier_artifacts);

      console.log('[VALIDATION] Re-verifying frozen refs and frozen old assets post-stage...');
      await verifyAllFrozenRefs(envInfo.token);
      await guardModule.verifyFrozenOldAssets(envInfo.token);

      const report = {
        report_name: 'setup13-carrier-publish-report',
        mode: 'stage',
        status: 'STAGED',
        public: false,
        release_id: release.id,
        release_url: release.html_url,
        tooling_sha: envInfo.githubSha,
        source_sha: EXPECTED_SOURCE_SHA,
        setup_run_id: SETUP_RUN_ID,
        acceptance_run_id: ACCEPTANCE_RUN_ID,
        carrier_tag: EXPECTED_TAG,
        tag_target_commit: FROZEN_UPDATES_MAIN_SHA,
        verified_carrier_assets_count: 18,
        carrier_assets_verified: true,
        timestamp: new Date().toISOString()
      };

      fs.writeFileSync('carrier-publish-report.json', JSON.stringify(report, null, 2) + '\n', 'utf8');
      console.log(`[PASS] Setup 13 carrier draft staged successfully. Release ID: ${release.id}`);
    } else {
      const { publishedRelease, isAlreadyPublic } = await executePublishMode({
        releaseId: parsedArgs.releaseId,
        toolingSha: envInfo.githubSha,
        token: envInfo.token,
        runnerTemp: envInfo.runnerTemp,
        evidenceDir,
        carrierDir,
        provenance,
        promoteModule,
        finalizeModule,
        releaseSourceDir
      });

      console.log('[VALIDATION] Re-verifying frozen refs and frozen old assets post-publish...');
      await verifyAllFrozenRefs(envInfo.token);
      await guardModule.verifyFrozenOldAssets(envInfo.token);

      const report = {
        report_name: 'setup13-carrier-publish-report',
        mode: 'publish',
        status: isAlreadyPublic ? 'ALREADY_PUBLISHED' : 'PUBLISHED',
        public: true,
        release_id: publishedRelease.id,
        release_url: publishedRelease.html_url,
        tooling_sha: envInfo.githubSha,
        source_sha: EXPECTED_SOURCE_SHA,
        setup_run_id: SETUP_RUN_ID,
        acceptance_run_id: ACCEPTANCE_RUN_ID,
        carrier_tag: EXPECTED_TAG,
        tag_target_commit: FROZEN_UPDATES_MAIN_SHA,
        verified_carrier_assets_count: 18,
        source_setup_public_verified: true,
        carrier_assets_verified: true,
        timestamp: new Date().toISOString()
      };

      fs.writeFileSync('carrier-publish-report.json', JSON.stringify(report, null, 2) + '\n', 'utf8');
      console.log(`[PASS] Setup 13 carrier published successfully (${report.status}). Release ID: ${publishedRelease.id}`);
    }
  } catch (err) {
    const failureReport = {
      report_name: 'setup13-carrier-publish-report',
      mode: parsedArgs?.mode || 'unknown',
      status: 'FAILED',
      error: err.message,
      timestamp: new Date().toISOString()
    };
    try {
      fs.writeFileSync('carrier-publish-report.json', JSON.stringify(failureReport, null, 2) + '\n', 'utf8');
    } catch {
      // Ignore secondary write error
    }
    console.error(`[CRITICAL FAIL] Carrier execution failed: ${err.message}`);
    process.exit(1);
  }
}

const isMain = process.argv[1] && path.resolve(process.argv[1]) === path.resolve(fileURLToPath(import.meta.url));
if (isMain) {
  main();
}
