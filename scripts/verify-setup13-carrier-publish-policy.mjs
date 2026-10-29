#!/usr/bin/env node
/**
 * scripts/verify-setup13-carrier-publish-policy.mjs
 *
 * GHA-only regression test suite for Setup 0.0.13 Carrier stage and publish policy guards.
 * Pure unit tests with mocked fixtures: zero network calls, zero live publications, zero credential use.
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';

// Strict GHA-only execution gate before any fixtures or imports
assert.equal(
  process.env.GITHUB_ACTIONS,
  'true',
  'verify-setup13-carrier-publish-policy.mjs is strictly restricted to ephemeral GitHub Actions runners'
);
const runnerTemp = process.env.RUNNER_TEMP;
assert.ok(runnerTemp && fs.existsSync(runnerTemp), 'RUNNER_TEMP directory is required and must exist');

import {
  SOURCE_REPO,
  UPDATES_REPO,
  EXPECTED_VERSION,
  EXPECTED_TAG,
  SETUP_RUN_ID,
  ACCEPTANCE_RUN_ID,
  EXPECTED_SOURCE_SHA,
  EXPECTED_GUARD_SHA,
  FROZEN_UPDATES_MAIN_SHA,
  FROZEN_SOURCE_MAIN_SHA,
  FROZEN_SOURCE_V12_SHA,
  FROZEN_UPDATES_V12_SHA,
  FROZEN_UPDATES_BETA12_1_SHA,
  SETUP_EXE_SHA256,
  SETUP_EXE_SIZE,
  EXPECTED_INVENTORY_SHA256,
  PHYSICAL_RETAINED_SHELL_SHA256,
  EXACT_5_SETUP_ASSETS,
  EXACT_18_CARRIER_FILES,
  ALLOWED_STAGING_PREFIXES,
  ALLOWED_API_PATH_PREFIXES,
  assertNoReparse,
  assertStrictLowerHex40,
  assertStrictLowerHex64,
  assertPositiveInteger,
  assertNumericPositiveId,
  parseCliArgs,
  validateEnvironment,
  validateApiEndpoint,
  createOwnershipMarker,
  validateReleaseOwnershipMarker,
  validateCarrierAssetsMap,
  validateSourceSetupPublicRelease,
  createOwnedStaging,
  cleanupOwnedStaging,
  assertCarrierPublishAllowed
} from './publish-setup13-carrier.mjs';

assertNoReparse(runnerTemp);

console.log('--- Setup 0.0.13 Carrier Publish Policy Suite (GHA Mocked) ---');

let totalTests = 0;
let passedTests = 0;

function runTest(name, fn) {
  totalTests++;
  try {
    fn();
    passedTests++;
    console.log(`  [PASS] ${name}`);
  } catch (err) {
    console.error(`  [FAIL] ${name}: ${err.message}`);
    throw err;
  }
}

function createMockCarrierArtifacts() {
  const artifacts = {};
  for (let i = 0; i < EXACT_18_CARRIER_FILES.length; i++) {
    const fn = EXACT_18_CARRIER_FILES[i];
    const sha = crypto.createHash('sha256').update(`carrier-payload-${fn}`).digest('hex');
    artifacts[fn] = {
      sha256: sha,
      size: 5000 + i * 100
    };
  }
  return artifacts;
}

function createMockServerAssets(carrierArtifacts, overrides = {}) {
  return Object.entries(carrierArtifacts).map(([name, meta]) => {
    const asset = {
      id: 200000 + Math.floor(Math.random() * 10000),
      name,
      size: meta.size,
      state: 'uploaded',
      digest: `sha256:${meta.sha256}`
    };
    if (overrides[name]) {
      Object.assign(asset, overrides[name]);
    }
    return asset;
  });
}

function createMockSetupAssets() {
  const assets = {};
  for (let i = 0; i < EXACT_5_SETUP_ASSETS.length; i++) {
    const fn = EXACT_5_SETUP_ASSETS[i];
    const sha = fn === 'POS-Desktop-Setup-0.0.13.exe'
      ? SETUP_EXE_SHA256
      : crypto.createHash('sha256').update(`setup-payload-${fn}`).digest('hex');
    const size = fn === 'POS-Desktop-Setup-0.0.13.exe' ? SETUP_EXE_SIZE : 2000 + i * 200;
    assets[fn] = { sha256: sha, size };
  }
  return assets;
}

// ==========================================
// Suite 1: Workflow Shell Injection Safety
// ==========================================
runTest('Workflow policy: workflow must not embed ${{ inputs.* }} directly into shell run commands', () => {
  const workflowPath = path.resolve('.github/workflows/setup13-carrier-publish.yml');
  assert.ok(fs.existsSync(workflowPath), `Workflow file missing at ${workflowPath}`);
  const content = fs.readFileSync(workflowPath, 'utf8');

  const lines = content.split('\n');
  let inRunBlock = false;
  let runIndent = 0;

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const runMatch = line.match(/^(\s*)run:\s*(\|.*)?$/);
    if (runMatch) {
      inRunBlock = true;
      runIndent = runMatch[1].length;
      continue;
    }

    if (inRunBlock) {
      const lineIndentMatch = line.match(/^(\s*)\S/);
      if (lineIndentMatch) {
        const indent = lineIndentMatch[1].length;
        if (indent <= runIndent) {
          inRunBlock = false;
        } else {
          assert.ok(
            !line.includes('${{ inputs.'),
            `Shell run block at line ${i + 1} dangerously interpolates inputs: ${line.trim()}`
          );
        }
      }
    }
  }
});

// ==========================================
// Suite 2: Boundary & Environment Validation
// ==========================================
runTest('validateEnvironment accepts valid GHA environment under UPDATES_REPO', () => {
  const env = {
    GITHUB_ACTIONS: 'true',
    RUNNER_TEMP: runnerTemp,
    GITHUB_REPOSITORY: UPDATES_REPO,
    GITHUB_SHA: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
    GH_TOKEN: 'ghp_validToken123456789'
  };
  const res = validateEnvironment(env);
  assert.equal(res.repository, UPDATES_REPO);
  assert.equal(res.githubSha, 'ef0948813d0049f5deb4431e6571edc861fe96e4');
  assert.equal(res.token, 'ghp_validToken123456789');
});

runTest('validateEnvironment rejects missing or false GITHUB_ACTIONS', () => {
  assert.throws(
    () => validateEnvironment({ GITHUB_ACTIONS: 'false', RUNNER_TEMP: runnerTemp, GITHUB_REPOSITORY: UPDATES_REPO }),
    /publish-setup13-carrier\.mjs is strictly restricted to ephemeral GitHub Actions runners/
  );
  assert.throws(
    () => validateEnvironment({ RUNNER_TEMP: runnerTemp, GITHUB_REPOSITORY: UPDATES_REPO }),
    /publish-setup13-carrier\.mjs is strictly restricted to ephemeral GitHub Actions runners/
  );
});

runTest('validateEnvironment rejects untrusted repository', () => {
  assert.throws(
    () => validateEnvironment({
      GITHUB_ACTIONS: 'true',
      RUNNER_TEMP: runnerTemp,
      GITHUB_REPOSITORY: SOURCE_REPO,
      GITHUB_SHA: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
      GH_TOKEN: 'valid'
    }),
    /GITHUB_REPOSITORY must be ABDO-TECK\/pos-desktop-updates/
  );

  assert.throws(
    () => validateEnvironment({
      GITHUB_ACTIONS: 'true',
      RUNNER_TEMP: runnerTemp,
      GITHUB_REPOSITORY: 'evil-attacker/pos-desktop-updates',
      GITHUB_SHA: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
      GH_TOKEN: 'valid'
    }),
    /GITHUB_REPOSITORY must be ABDO-TECK\/pos-desktop-updates/
  );
});

runTest('validateEnvironment rejects missing or empty token', () => {
  assert.throws(
    () => validateEnvironment({
      GITHUB_ACTIONS: 'true',
      RUNNER_TEMP: runnerTemp,
      GITHUB_REPOSITORY: UPDATES_REPO,
      GITHUB_SHA: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
      GH_TOKEN: '   '
    }),
    /GH_TOKEN or GITHUB_TOKEN environment variable is required/
  );
});

runTest('validateEnvironment rejects invalid or uppercase GITHUB_SHA without normalization', () => {
  assert.throws(
    () => validateEnvironment({
      GITHUB_ACTIONS: 'true',
      RUNNER_TEMP: runnerTemp,
      GITHUB_REPOSITORY: UPDATES_REPO,
      GITHUB_SHA: 'not-a-sha',
      GH_TOKEN: 'valid'
    }),
    /GITHUB_SHA must be exactly 40 lowercase hex characters/
  );

  assert.throws(
    () => validateEnvironment({
      GITHUB_ACTIONS: 'true',
      RUNNER_TEMP: runnerTemp,
      GITHUB_REPOSITORY: UPDATES_REPO,
      GITHUB_SHA: 'EF0948813D0049F5DEB4431E6571EDC861FE96E4',
      GH_TOKEN: 'valid'
    }),
    /GITHUB_SHA must be exactly 40 lowercase hex characters/
  );
});

runTest('Positive validation: distinct SOURCE_SHA vs TOOLING_SHA', () => {
  const sourceSha = EXPECTED_SOURCE_SHA;
  const toolingSha = 'ef0948813d0049f5deb4431e6571edc861fe96e4';
  assertStrictLowerHex40(sourceSha, 'sourceSha');
  assertStrictLowerHex40(toolingSha, 'toolingSha');
  assert.notEqual(sourceSha, toolingSha, 'Source SHA and Tooling SHA must remain distinct');
  assert.equal(sourceSha, 'ee5bf691c4acf6ffcd7730db1d7d0ed24f274787');
});

// ==========================================
// Suite 3: Mode & CLI Argument Parsing
// ==========================================
runTest('parseCliArgs accepts stage mode with default or empty release_id', () => {
  const res1 = parseCliArgs(['--mode', 'stage'], {});
  assert.equal(res1.mode, 'stage');
  assert.equal(res1.releaseId, null);

  const res2 = parseCliArgs([], { CARRIER_MODE: 'stage' });
  assert.equal(res2.mode, 'stage');
  assert.equal(res2.releaseId, null);
});

runTest('parseCliArgs accepts publish mode with positive integer release_id', () => {
  const res = parseCliArgs(['--mode', 'publish', '--release-id', '1234567'], {});
  assert.equal(res.mode, 'publish');
  assert.equal(res.releaseId, '1234567');
});

runTest('parseCliArgs rejects invalid mode string', () => {
  assert.throws(
    () => parseCliArgs(['--mode', 'promote'], {}),
    /Invalid mode: promote\. Must be 'stage' or 'publish'/
  );
  assert.throws(
    () => parseCliArgs(['--mode', 'destroy'], {}),
    /Invalid mode: destroy\. Must be 'stage' or 'publish'/
  );
});

runTest('parseCliArgs rejects publish mode when release_id is missing', () => {
  assert.throws(
    () => parseCliArgs(['--mode', 'publish'], {}),
    /release_id is required and must be a positive integer in publish mode/
  );
  assert.throws(
    () => parseCliArgs(['--mode', 'publish', '--release-id', ''], {}),
    /release_id is required and must be a positive integer in publish mode/
  );
});

runTest('parseCliArgs rejects publish mode with non-numeric, zero, or negative release_id', () => {
  assert.throws(
    () => parseCliArgs(['--mode', 'publish', '--release-id', '0'], {}),
    /release_id is required and must be a positive integer in publish mode/
  );
  assert.throws(
    () => parseCliArgs(['--mode', 'publish', '--release-id', '-99'], {}),
    /release_id is required and must be a positive integer in publish mode/
  );
  assert.throws(
    () => parseCliArgs(['--mode', 'publish', '--release-id', 'abc123'], {}),
    /release_id is required and must be a positive integer in publish mode/
  );
});

runTest('parseCliArgs rejects stage mode with negative or zero release_id if provided', () => {
  assert.throws(
    () => parseCliArgs(['--mode', 'stage', '--release-id', '0'], {}),
    /release_id if provided in stage mode must be a positive integer/
  );
  assert.throws(
    () => parseCliArgs(['--mode', 'stage', '--release-id', '-5'], {}),
    /release_id if provided in stage mode must be a positive integer/
  );
});

// ==========================================
// Suite 4: Ownership Marker Validation
// ==========================================
runTest('createOwnershipMarker and validateReleaseOwnershipMarker roundtrip successfully', () => {
  const opts = {
    sourceSha: EXPECTED_SOURCE_SHA,
    setupRunId: SETUP_RUN_ID,
    acceptanceRunId: ACCEPTANCE_RUN_ID,
    toolingSha: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
    tagTarget: FROZEN_UPDATES_MAIN_SHA
  };
  const marker = createOwnershipMarker(opts);
  const releaseBody = `# Release Notes\n\nSome notes here.\n\n${marker}\n`;

  const validated = validateReleaseOwnershipMarker(releaseBody, opts);
  assert.equal(validated.source_sha, EXPECTED_SOURCE_SHA);
  assert.equal(validated.setup_run_id, SETUP_RUN_ID);
  assert.equal(validated.acceptance_run_id, ACCEPTANCE_RUN_ID);
  assert.equal(validated.tooling_sha, 'ef0948813d0049f5deb4431e6571edc861fe96e4');
  assert.equal(validated.tag_target, FROZEN_UPDATES_MAIN_SHA);
});

runTest('validateReleaseOwnershipMarker rejects release body missing ownership marker', () => {
  assert.throws(
    () => validateReleaseOwnershipMarker('# Release Notes\n\nNo marker here', {
      sourceSha: EXPECTED_SOURCE_SHA,
      setupRunId: SETUP_RUN_ID,
      acceptanceRunId: ACCEPTANCE_RUN_ID,
      toolingSha: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
      tagTarget: FROZEN_UPDATES_MAIN_SHA
    }),
    /Release body missing required carrier ownership marker/
  );
});

runTest('validateReleaseOwnershipMarker rejects duplicate ownership markers', () => {
  const opts = {
    sourceSha: EXPECTED_SOURCE_SHA,
    setupRunId: SETUP_RUN_ID,
    acceptanceRunId: ACCEPTANCE_RUN_ID,
    toolingSha: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
    tagTarget: FROZEN_UPDATES_MAIN_SHA
  };
  const marker = createOwnershipMarker(opts);
  const duplicateBody = `# Release Notes\n\n${marker}\n\nMiddle text\n\n${marker}\n`;
  assert.throws(
    () => validateReleaseOwnershipMarker(duplicateBody, opts),
    /Duplicate carrier ownership marker in release body rejected/
  );
});

runTest('validateReleaseOwnershipMarker rejects marker with extra or missing schema keys', () => {
  const extraObj = {
    schema_version: '1.0.0',
    type: 'carrier_draft',
    source_repo: SOURCE_REPO,
    source_sha: EXPECTED_SOURCE_SHA,
    setup_run_id: SETUP_RUN_ID,
    acceptance_run_id: ACCEPTANCE_RUN_ID,
    tooling_sha: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
    tag_target: FROZEN_UPDATES_MAIN_SHA,
    malicious_extra_field: 'attack'
  };
  const extraMarker = `<!-- setup13-carrier-draft: ${JSON.stringify(extraObj)} -->`;
  assert.throws(
    () => validateReleaseOwnershipMarker(`Notes\n\n${extraMarker}`, {
      sourceSha: EXPECTED_SOURCE_SHA,
      setupRunId: SETUP_RUN_ID,
      acceptanceRunId: ACCEPTANCE_RUN_ID,
      toolingSha: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
      tagTarget: FROZEN_UPDATES_MAIN_SHA
    }),
    /Ownership marker has extra or missing keys/
  );
});

runTest('validateReleaseOwnershipMarker rejects marker with numeric rather than string IDs', () => {
  const numericObj = {
    schema_version: '1.0.0',
    type: 'carrier_draft',
    source_repo: SOURCE_REPO,
    source_sha: EXPECTED_SOURCE_SHA,
    setup_run_id: 40939314357, // Numeric type rejected
    acceptance_run_id: 40943059736,
    tooling_sha: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
    tag_target: FROZEN_UPDATES_MAIN_SHA
  };
  const numMarker = `<!-- setup13-carrier-draft: ${JSON.stringify(numericObj)} -->`;
  assert.throws(
    () => validateReleaseOwnershipMarker(`Notes\n\n${numMarker}`, {
      sourceSha: EXPECTED_SOURCE_SHA,
      setupRunId: SETUP_RUN_ID,
      acceptanceRunId: ACCEPTANCE_RUN_ID,
      toolingSha: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
      tagTarget: FROZEN_UPDATES_MAIN_SHA
    }),
    /marker\.setup_run_id must be a string \(numeric IDs rejected\)/
  );
});

runTest('validateReleaseOwnershipMarker rejects marker with mismatched source_sha', () => {
  const marker = createOwnershipMarker({
    sourceSha: '0000000000000000000000000000000000000000',
    setupRunId: SETUP_RUN_ID,
    acceptanceRunId: ACCEPTANCE_RUN_ID,
    toolingSha: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
    tagTarget: FROZEN_UPDATES_MAIN_SHA
  });
  assert.throws(
    () => validateReleaseOwnershipMarker(`Notes\n\n${marker}`, {
      sourceSha: EXPECTED_SOURCE_SHA,
      setupRunId: SETUP_RUN_ID,
      acceptanceRunId: ACCEPTANCE_RUN_ID,
      toolingSha: 'ef0948813d0049f5deb4431e6571edc861fe96e4',
      tagTarget: FROZEN_UPDATES_MAIN_SHA
    }),
    /Ownership marker source_sha mismatch/
  );
});

// ==========================================
// Suite 5: API Endpoint Security Guard
// ==========================================
runTest('validateApiEndpoint accepts valid relative and absolute HTTPS API paths under authorized repos', () => {
  assert.equal(
    validateApiEndpoint(`/repos/${SOURCE_REPO}/releases`),
    `https://api.github.com/repos/${SOURCE_REPO}/releases`
  );
  assert.equal(
    validateApiEndpoint(`https://api.github.com/repos/${UPDATES_REPO}/releases`),
    `https://api.github.com/repos/${UPDATES_REPO}/releases`
  );
});

runTest('validateApiEndpoint rejects non-HTTPS, untrusted domains, credentials, or unauthorized repos', () => {
  assert.throws(
    () => validateApiEndpoint(`http://api.github.com/repos/${SOURCE_REPO}/releases`),
    /API endpoint must use HTTPS/
  );
  assert.throws(
    () => validateApiEndpoint(`https://evil.attacker.com/repos/${SOURCE_REPO}/releases`),
    /API hostname must be 'api.github.com'/
  );
  assert.throws(
    () => validateApiEndpoint(`https://user:pass@api.github.com/repos/${SOURCE_REPO}/releases`),
    /API URL must not contain username credentials/
  );
  assert.throws(
    () => validateApiEndpoint(`/repos/other-unauthorized-org/repo/releases`),
    /API endpoint path '.*' is not under authorized repositories/
  );
});

// ==========================================
// Suite 6: Carrier Assets Map & Digest Verification
// ==========================================
runTest('validateCarrierAssetsMap accepts exact 18 carrier assets with valid sha256 digests', () => {
  const artifacts = createMockCarrierArtifacts();
  const serverAssets = createMockServerAssets(artifacts);
  assert.doesNotThrow(() => validateCarrierAssetsMap(serverAssets, artifacts));
});

runTest('validateCarrierAssetsMap rejects missing asset (fewer than 18)', () => {
  const artifacts = createMockCarrierArtifacts();
  const serverAssets = createMockServerAssets(artifacts).slice(1);
  assert.throws(
    () => validateCarrierAssetsMap(serverAssets, artifacts),
    /serverAssets must contain exactly 18 assets, got 17/
  );
});

runTest('validateCarrierAssetsMap rejects duplicate asset names', () => {
  const artifacts = createMockCarrierArtifacts();
  const serverAssets = createMockServerAssets(artifacts);
  serverAssets[1].name = serverAssets[0].name;
  assert.throws(
    () => validateCarrierAssetsMap(serverAssets, artifacts),
    /serverAssets contains duplicate asset names/
  );
});

runTest('validateCarrierAssetsMap rejects asset size string or non-positive integer', () => {
  const artifacts = createMockCarrierArtifacts();
  const serverAssets1 = createMockServerAssets(artifacts, {
    'channel-transitions.json': { size: '5000' }
  });
  assert.throws(
    () => validateCarrierAssetsMap(serverAssets1, artifacts),
    /size must be a number/
  );

  const serverAssets2 = createMockServerAssets(artifacts, {
    'channel-transitions.json': { size: 0 }
  });
  assert.throws(
    () => validateCarrierAssetsMap(serverAssets2, artifacts),
    /size must be a positive safe integer/
  );
});

runTest('validateCarrierAssetsMap rejects uppercase server digest (strict lowercase required)', () => {
  const artifacts = createMockCarrierArtifacts();
  const serverAssets = createMockServerAssets(artifacts);
  serverAssets[0].digest = serverAssets[0].digest.toUpperCase();
  assert.throws(
    () => validateCarrierAssetsMap(serverAssets, artifacts),
    /server digest format invalid \(strict lowercase required\)/
  );
});

runTest('validateCarrierAssetsMap rejects expectedArtifacts map with extra or missing keys', () => {
  const artifacts = createMockCarrierArtifacts();
  delete artifacts['channel-transitions.json'];
  const serverAssets = createMockServerAssets(createMockCarrierArtifacts());
  assert.throws(
    () => validateCarrierAssetsMap(serverAssets, artifacts),
    /expectedArtifacts keys must match EXACT_18_CARRIER_FILES exactly/
  );
});

// ==========================================
// Suite 7: Source Setup Public Release Verification
// ==========================================
runTest('validateSourceSetupPublicRelease accepts valid public release with 5 assets', () => {
  const setupAssets = createMockSetupAssets();
  const publicRelease = {
    draft: false,
    prerelease: false,
    tag_name: EXPECTED_TAG,
    assets: Object.entries(setupAssets).map(([name, meta]) => ({
      name,
      size: meta.size,
      state: 'uploaded',
      digest: `sha256:${meta.sha256}`
    }))
  };
  assert.doesNotThrow(() => validateSourceSetupPublicRelease(publicRelease, setupAssets));
});

runTest('validateSourceSetupPublicRelease rejects release if draft is true', () => {
  const setupAssets = createMockSetupAssets();
  const draftRelease = {
    draft: true,
    prerelease: false,
    tag_name: EXPECTED_TAG,
    assets: Object.entries(setupAssets).map(([name, meta]) => ({
      name,
      size: meta.size,
      state: 'uploaded',
      digest: `sha256:${meta.sha256}`
    }))
  };
  assert.throws(
    () => validateSourceSetupPublicRelease(draftRelease, setupAssets),
    /Source Setup release must not be draft/
  );
});

runTest('validateSourceSetupPublicRelease rejects release if prerelease is true', () => {
  const setupAssets = createMockSetupAssets();
  const prerelease = {
    draft: false,
    prerelease: true,
    tag_name: EXPECTED_TAG,
    assets: Object.entries(setupAssets).map(([name, meta]) => ({
      name,
      size: meta.size,
      state: 'uploaded',
      digest: `sha256:${meta.sha256}`
    }))
  };
  assert.throws(
    () => validateSourceSetupPublicRelease(prerelease, setupAssets),
    /Source Setup release must not be prerelease/
  );
});

runTest('validateSourceSetupPublicRelease rejects release if server digest is missing or invalid', () => {
  const setupAssets = createMockSetupAssets();
  const invalidRelease = {
    draft: false,
    prerelease: false,
    tag_name: EXPECTED_TAG,
    assets: Object.entries(setupAssets).map(([name, meta]) => ({
      name,
      size: meta.size,
      state: 'uploaded'
      // digest missing
    }))
  };
  assert.throws(
    () => validateSourceSetupPublicRelease(invalidRelease, setupAssets),
    /Source setup asset .* server digest missing or not string/
  );
});

runTest('validateSourceSetupPublicRelease rejects duplicate asset names', () => {
  const setupAssets = createMockSetupAssets();
  const releaseAssets = Object.entries(setupAssets).map(([name, meta]) => ({
    name,
    size: meta.size,
    state: 'uploaded',
    digest: `sha256:${meta.sha256}`
  }));
  releaseAssets[1].name = releaseAssets[0].name;
  const duplicateRelease = {
    draft: false,
    prerelease: false,
    tag_name: EXPECTED_TAG,
    assets: releaseAssets
  };
  assert.throws(
    () => validateSourceSetupPublicRelease(duplicateRelease, setupAssets),
    /Source Setup release contains duplicate asset names/
  );
});

// ==========================================
// Suite 8: Owned Staging Directory & Cleanup
// ==========================================
runTest('createOwnedStaging registers directory and cleanupOwnedStaging safely cleans it', () => {
  const testStagingDir = createOwnedStaging(runnerTemp, 'setup13-carrier-');
  assert.ok(fs.existsSync(testStagingDir));
  cleanupOwnedStaging(testStagingDir, runnerTemp);
  assert.equal(fs.existsSync(testStagingDir), false);
});

runTest('cleanupOwnedStaging refuses unregistered foreign directory with matching prefix', () => {
  const unregisteredDir = fs.mkdtempSync(path.join(runnerTemp, 'setup13-carrier-foreign-'));
  try {
    assert.throws(
      () => cleanupOwnedStaging(unregisteredDir, runnerTemp),
      /Refusing to cleanup unregistered directory/
    );
  } finally {
    fs.rmSync(unregisteredDir, { recursive: true, force: true });
  }
});

runTest('createOwnedStaging refuses unauthorized prefix', () => {
  assert.throws(
    () => createOwnedStaging(runnerTemp, 'unauthorized-prefix-'),
    /Prefix 'unauthorized-prefix-' is not in ALLOWED_STAGING_PREFIXES/
  );
});

// ==========================================
// Suite 9: Real Publish Transition & Write Guard
// ==========================================
runTest('assertCarrierPublishAllowed allows valid publish transition and executes mock mutation', () => {
  const validOpts = {
    mode: 'publish',
    release: {
      draft: true,
      prerelease: false,
      tag_name: EXPECTED_TAG,
      author: { login: 'github-actions[bot]' }
    },
    isOwnershipVerified: true,
    isSourcePublicVerified: true,
    areCarrierAssetsVerified: true,
    areFrozenRefsVerified: true
  };

  let mockMutationExecuted = false;
  assert.doesNotThrow(() => {
    assertCarrierPublishAllowed(validOpts);
    mockMutationExecuted = true;
  });
  assert.equal(mockMutationExecuted, true, 'Mutation callback should execute when all publication guards pass');
});

runTest('assertCarrierPublishAllowed blocks mutation when mode is stage', () => {
  const stageOpts = {
    mode: 'stage',
    release: {
      draft: true,
      prerelease: false,
      tag_name: EXPECTED_TAG,
      author: { login: 'github-actions[bot]' }
    },
    isOwnershipVerified: true,
    isSourcePublicVerified: true,
    areCarrierAssetsVerified: true,
    areFrozenRefsVerified: true
  };

  let mockMutationExecuted = false;
  assert.throws(
    () => {
      assertCarrierPublishAllowed(stageOpts);
      mockMutationExecuted = true;
    },
    /Operation mode must be 'publish' to perform publication, got 'stage'/
  );
  assert.equal(mockMutationExecuted, false, 'Mutation must not execute in stage mode');
});

runTest('assertCarrierPublishAllowed blocks mutation when Source Setup release is not verified public', () => {
  const unverifiedSourceOpts = {
    mode: 'publish',
    release: {
      draft: true,
      prerelease: false,
      tag_name: EXPECTED_TAG,
      author: { login: 'github-actions[bot]' }
    },
    isOwnershipVerified: true,
    isSourcePublicVerified: false,
    areCarrierAssetsVerified: true,
    areFrozenRefsVerified: true
  };

  let mockMutationExecuted = false;
  assert.throws(
    () => {
      assertCarrierPublishAllowed(unverifiedSourceOpts);
      mockMutationExecuted = true;
    },
    /Source Setup release must be public and verified before publication/
  );
  assert.equal(mockMutationExecuted, false, 'Mutation must not execute when source setup release unverified');
});

runTest('assertCarrierPublishAllowed blocks mutation if release prerelease is true', () => {
  const prereleaseOpts = {
    mode: 'publish',
    release: {
      draft: true,
      prerelease: true,
      tag_name: EXPECTED_TAG,
      author: { login: 'github-actions[bot]' }
    },
    isOwnershipVerified: true,
    isSourcePublicVerified: true,
    areCarrierAssetsVerified: true,
    areFrozenRefsVerified: true
  };

  let mockMutationExecuted = false;
  assert.throws(
    () => {
      assertCarrierPublishAllowed(prereleaseOpts);
      mockMutationExecuted = true;
    },
    /Refusing to publish release with prerelease: true/
  );
  assert.equal(mockMutationExecuted, false, 'Mutation must not execute if prerelease is true');
});

runTest('assertCarrierPublishAllowed blocks mutation if release author is not github-actions[bot]', () => {
  const foreignAuthorOpts = {
    mode: 'publish',
    release: {
      draft: true,
      prerelease: false,
      tag_name: EXPECTED_TAG,
      author: { login: 'external-user' }
    },
    isOwnershipVerified: true,
    isSourcePublicVerified: true,
    areCarrierAssetsVerified: true,
    areFrozenRefsVerified: true
  };

  let mockMutationExecuted = false;
  assert.throws(
    () => {
      assertCarrierPublishAllowed(foreignAuthorOpts);
      mockMutationExecuted = true;
    },
    /Release author must be 'github-actions\[bot\]'/
  );
  assert.equal(mockMutationExecuted, false, 'Mutation must not execute if author is foreign');
});

console.log(`\n[PASS] All ${passedTests} policy tests passed successfully (total: ${totalTests}).`);
