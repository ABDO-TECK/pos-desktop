#!/usr/bin/env node
/**
 * scripts/verify-setup13-promotion-policy.mjs
 *
 * GHA-only regression test suite for Setup 0.0.13 promotion policy guards.
 * Validates with mocked fixtures (zero network calls, zero live publication):
 *   1. GHA environment and repository boundary enforcement.
 *   2. Strict GITHUB_SHA validation (40 hex lower, matches HEAD, matches both runs, matches remote tag).
 *   3. Positive numeric run IDs and workflow run identity/status/conclusion guards.
 *   4. Fail-closed pre-existing release refusal (status 200 refuses; 403/500 not treated as 404).
 *   5. Strict setup assets (exact 5 files, no arrays, no string values, no hash aliases, exact sha256 + size).
 *   6. Strict carrier artifacts (exact 18 files, 4 transitions JSON/SIG/ZIP + common 6, deep files equality).
 *   7. Shared proof contract (release-provenance.json + acceptance.json: exact 13 flags, no case aliases, cleanup verified).
 *   8. Reparse point and symlink rejection across all boundaries.
 *   9. Owned staging under RUNNER_TEMP and safe cleanup (no deterministic deletion).
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';

import {
  REQUIRED_REPOSITORY,
  EXPECTED_VERSION,
  EXPECTED_TAG,
  EXPECTED_SETUP_WORKFLOW,
  EXPECTED_ACCEPTANCE_WORKFLOW,
  EXACT_5_SETUP_ASSETS,
  EXPECTED_SETUP_PAYLOAD_FILES,
  REQUIRED_13_VERIFICATIONS,
  EXPECTED_TRANSITIONS,
  EXACT_18_CARRIER_FILES,
  EXPECTED_CARRIER_COUNT,
  sha256,
  assertNoReparse,
  assertPositiveInteger,
  assertStrictLowerHex64,
  assertStrictLowerHex40,
  assertNumericPositiveId,
  validateEnvironment,
  validateHeadCommit,
  validateWorkflowRun,
  validateRemoteTag,
  validateNoExistingRelease,
  validateSetupDirectory,
  validateEvidenceDirectory,
  validateCarrierDirectory,
  cleanupOwnedStaging
} from './promote-setup13.mjs';

// Enforce GHA-only execution before any test execution
assert.equal(
  process.env.GITHUB_ACTIONS,
  'true',
  'verify-setup13-promotion-policy.mjs is strictly restricted to ephemeral GitHub Actions runners'
);
const runnerTemp = process.env.RUNNER_TEMP;
assert.ok(runnerTemp && fs.existsSync(runnerTemp), 'RUNNER_TEMP directory is required and must exist');
assertNoReparse(runnerTemp);

assert.equal(
  process.env.GITHUB_REPOSITORY,
  REQUIRED_REPOSITORY,
  `GITHUB_REPOSITORY must be ${REQUIRED_REPOSITORY} (got: ${process.env.GITHUB_REPOSITORY})`
);
if (process.env.REPOSITORY) {
  assert.equal(
    process.env.REPOSITORY,
    REQUIRED_REPOSITORY,
    `REPOSITORY override must match ${REQUIRED_REPOSITORY} (got: ${process.env.REPOSITORY})`
  );
}

const ghaRunId = process.env.GITHUB_RUN_ID;
assert.ok(ghaRunId && /^[1-9]\d*$/.test(ghaRunId), 'GITHUB_RUN_ID is required and must be a positive integer');

console.log('--- Setup 0.0.13 Promotion Policy Regression Suite (GHA Mocked) ---');

let totalTests = 0;
let passedTests = 0;
let skippedTests = 0;

function runTest(name, fn) {
  totalTests++;
  try {
    const outcome = fn();
    if (outcome && outcome.skipped) {
      skippedTests++;
      console.log(`  [SKIP] ${name}${outcome.reason ? `: ${outcome.reason}` : ''}`);
      return;
    }
    passedTests++;
    console.log(`  [PASS] ${name}`);
  } catch (err) {
    console.error(`  [FAIL] ${name}: ${err.message}`);
    throw err;
  }
}

// Create isolated fixture sandbox inside RUNNER_TEMP
const testTemp = fs.mkdtempSync(path.join(runnerTemp, 'setup13-policy-test-'));
assertNoReparse(testTemp);

try {
  const dummySha = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
  const setupDir = path.join(testTemp, 'setup-assets');
  const carrierDir = path.join(testTemp, 'setup13-channel-carrier');
  const evidenceDir = path.join(testTemp, 'setup13-release-evidence');

  fs.mkdirSync(setupDir, { recursive: true });
  fs.mkdirSync(carrierDir, { recursive: true });
  fs.mkdirSync(evidenceDir, { recursive: true });

  // 1. Populate Valid Baseline Setup Assets (exact 5 files)
  fs.writeFileSync(path.join(setupDir, 'POS-Desktop-Setup-0.0.13.exe'), Buffer.from('mock exe payload binary content'));
  fs.writeFileSync(path.join(setupDir, 'POS-Desktop-Setup-0.0.13.exe.blockmap'), Buffer.from('mock blockmap payload content'));
  fs.writeFileSync(
    path.join(setupDir, 'latest.yml'),
    'version: 0.0.13\npath: POS-Desktop-Setup-0.0.13.exe\nsha512: dGVzdA==\nreleaseDate: "2026-10-29T00:00:00.000Z"\n'
  );

  const canonicalFiles = {
    'version.json': {
      sha256: 'b1c2d3e4f5061728394a5b6c7d8e9f0123456789abcdef0123456789abcdef01',
      size: 123
    },
    'resources/app.asar': {
      sha256: 'c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f7',
      size: 45678
    }
  };
  const setupInventoryObj = {
    version: '0.0.13',
    format: 1,
    shell_sha256: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    files: canonicalFiles
  };
  const setupInventoryBytes = Buffer.from(JSON.stringify(setupInventoryObj, null, 2) + '\n');
  fs.writeFileSync(path.join(setupDir, 'deployment-inventory.json'), setupInventoryBytes);

  const setupSums = EXPECTED_SETUP_PAYLOAD_FILES.map((fn) => {
    const h = sha256(fs.readFileSync(path.join(setupDir, fn)));
    return `${h}  ${fn}`;
  }).join('\n') + '\n';
  fs.writeFileSync(path.join(setupDir, 'SHA256SUMS.txt'), setupSums);

  // Validate setup assets to get canonical hashes, sizes, and inventoryInfo
  const { setupHashes, setupSizes, inventoryInfo } = validateSetupDirectory(setupDir);

  // 2. Populate Valid Baseline Carrier Assets (exact 18 files)
  const transitionEntries = [];
  for (const t of EXPECTED_TRANSITIONS) {
    const isReturn = t.direction === 'return_stable';
    const zipName = `${t.stem}.zip`;
    const descName = `${t.stem}.json`;
    const sigName = `${t.stem}.sig`;

    const zipBytes = Buffer.from(`mock carrier package binary content for ${t.stem}`);
    fs.writeFileSync(path.join(carrierDir, zipName), zipBytes);
    fs.writeFileSync(path.join(carrierDir, sigName), `mock-sig-for-${t.stem}\n`);

    const descObj = {
      format: 1,
      operation: 'channel_transition',
      carrier_tag: EXPECTED_TAG,
      from_version: t.from_version,
      from_channel: t.from_channel,
      version: t.version,
      channel: t.channel,
      type: t.type,
      target_repo: isReturn ? 'pos-desktop' : 'pos-desktop-updates',
      target_tag: isReturn ? EXPECTED_TAG : 'v0.0.12-beta.1',
      shell_sha256: inventoryInfo.parsed.shell_sha256,
      package: {
        asset: zipName,
        sha256: sha256(zipBytes),
        size: zipBytes.length
      },
      files: [],
      deleted_files: [],
      migrations: [],
      data_compatibility: {
        format: 1,
        metadata_migration: 'channel_transition_v1',
        schema_sha256: 'a1b2c3d4e5f60718293a4b5c6d7e8f90123456789abcdef0123456789abcdef0',
        ledger_sha256: 'b1c2d3e4f5061728394a5b6c7d8e9f0123456789abcdef0123456789abcdef1'
      }
    };

    if (isReturn) {
      descObj.target_inventory_sha256 = inventoryInfo.sha256;
      descObj.target_inventory = {
        version: '0.0.13',
        format: 1,
        shell_sha256: inventoryInfo.parsed.shell_sha256,
        files: inventoryInfo.parsed.files
      };
    } else {
      descObj.source_inventory_sha256 = inventoryInfo.sha256;
      descObj.source_inventory = {
        version: '0.0.13',
        format: 1,
        shell_sha256: inventoryInfo.parsed.shell_sha256,
        files: inventoryInfo.parsed.files
      };
    }

    const descRaw = JSON.stringify(descObj, null, 2) + '\n';
    fs.writeFileSync(path.join(carrierDir, descName), descRaw);

    transitionEntries.push({
      from_version: t.from_version,
      from_channel: t.from_channel,
      version: t.version,
      channel: t.channel,
      descriptor: descName,
      signature: sigName,
      descriptor_sha256: sha256(Buffer.from(descRaw)),
      type: t.type
    });
  }

  // 6 Common carrier files
  const carrierIndex = {
    format: 1,
    carrier_tag: EXPECTED_TAG,
    entries: transitionEntries
  };
  fs.writeFileSync(path.join(carrierDir, 'channel-transitions.json'), JSON.stringify(carrierIndex, null, 2) + '\n');
  fs.writeFileSync(path.join(carrierDir, 'channel-transitions.sig'), 'mock-channel-transitions-sig\n');
  // deployment-inventory.json MUST raw-byte match Setup inventory
  fs.writeFileSync(path.join(carrierDir, 'deployment-inventory.json'), setupInventoryBytes);
  fs.writeFileSync(path.join(carrierDir, 'source-provenance.json'), JSON.stringify({ tag: EXPECTED_TAG }, null, 2) + '\n');
  fs.writeFileSync(path.join(carrierDir, 'release-notes.md'), '# Setup 0.0.13 Release Notes\n');

  const carrierNonSums = EXACT_18_CARRIER_FILES.filter((f) => f !== 'SHA256SUMS.txt');
  const carrierSumsContent = carrierNonSums.map((fn) => {
    const h = sha256(fs.readFileSync(path.join(carrierDir, fn)));
    return `${h}  ${fn}`;
  }).join('\n') + '\n';
  fs.writeFileSync(path.join(carrierDir, 'SHA256SUMS.txt'), carrierSumsContent);

  // 3. Populate Valid Baseline Evidence (release-provenance.json + acceptance.json)
  const provSetupAssets = {};
  for (const fn of EXACT_5_SETUP_ASSETS) {
    provSetupAssets[fn] = {
      sha256: setupHashes.get(fn),
      size: setupSizes.get(fn)
    };
  }

  const provCarrierArtifacts = {};
  for (const fn of EXACT_18_CARRIER_FILES) {
    const b = fs.readFileSync(path.join(carrierDir, fn));
    provCarrierArtifacts[fn] = {
      sha256: sha256(b),
      size: b.length
    };
  }

  const verifications13 = {};
  for (const k of REQUIRED_13_VERIFICATIONS) {
    verifications13[k] = true;
  }

  const validProvenance = {
    schema_version: '1.0.0',
    tag: EXPECTED_TAG,
    setup_run_id: '12345',
    acceptance_run_id: '67890',
    source_sha: dummySha,
    verdict: 'PASSED',
    cleanup_verified: true,
    artifact_binding_verified: true,
    verifications: verifications13,
    setup_assets: provSetupAssets,
    carrier_artifacts: provCarrierArtifacts
  };
  fs.writeFileSync(path.join(evidenceDir, 'release-provenance.json'), JSON.stringify(validProvenance, null, 2) + '\n');

  const validAcceptance = {
    verdict: 'PASSED',
    cleanup_verified: true,
    run_id: '67890',
    setup_run_id: '12345',
    verifications: verifications13
  };
  fs.writeFileSync(path.join(evidenceDir, 'acceptance.json'), JSON.stringify(validAcceptance, null, 2) + '\n');

  // --- POSITIVE BASELINE TEST ---
  runTest('Positive baseline fixtures satisfy all validators', () => {
    const { setupHashes: sHashes, setupSizes: sSizes, inventoryInfo: invInfo } = validateSetupDirectory(setupDir);
    const prov = validateEvidenceDirectory(evidenceDir, {
      expectedSetupRunId: '12345',
      expectedAcceptanceRunId: '67890',
      expectedSha: dummySha,
      setupHashes: sHashes,
      setupSizes: sSizes
    });
    validateCarrierDirectory(carrierDir, prov, invInfo);
  });

  // --- POLICY TEST GROUP 1: WRONG COMMIT SHA & REPO BOUNDARY GUARDS ---
  runTest('Policy: Reject GITHUB_ACTIONS !== "true"', () => {
    assert.throws(
      () => validateEnvironment({
        GITHUB_ACTIONS: 'false',
        RUNNER_TEMP: runnerTemp,
        GITHUB_REPOSITORY: REQUIRED_REPOSITORY,
        GH_TOKEN: 'token',
        GITHUB_SHA: dummySha
      }),
      /strictly restricted to ephemeral GitHub Actions runners/
    );
  });

  runTest('Policy: Reject GITHUB_REPOSITORY mismatch', () => {
    assert.throws(
      () => validateEnvironment({
        GITHUB_ACTIONS: 'true',
        RUNNER_TEMP: runnerTemp,
        GITHUB_REPOSITORY: 'evil-org/pos-desktop',
        GH_TOKEN: 'token',
        GITHUB_SHA: dummySha
      }),
      /GITHUB_REPOSITORY must be ABDO-TECK\/pos-desktop/
    );
  });

  runTest('Policy: Reject REPOSITORY override mismatch', () => {
    assert.throws(
      () => validateEnvironment({
        GITHUB_ACTIONS: 'true',
        RUNNER_TEMP: runnerTemp,
        GITHUB_REPOSITORY: REQUIRED_REPOSITORY,
        REPOSITORY: 'evil-org/pos-desktop',
        GH_TOKEN: 'token',
        GITHUB_SHA: dummySha
      }),
      /REPOSITORY override must match ABDO-TECK\/pos-desktop/
    );
  });

  runTest('Policy: Reject missing GH_TOKEN or GITHUB_TOKEN', () => {
    assert.throws(
      () => validateEnvironment({
        GITHUB_ACTIONS: 'true',
        RUNNER_TEMP: runnerTemp,
        GITHUB_REPOSITORY: REQUIRED_REPOSITORY,
        GITHUB_SHA: dummySha
      }),
      /GH_TOKEN or GITHUB_TOKEN environment variable is required/
    );
  });

  runTest('Policy: Reject missing GITHUB_SHA', () => {
    assert.throws(
      () => validateEnvironment({
        GITHUB_ACTIONS: 'true',
        RUNNER_TEMP: runnerTemp,
        GITHUB_REPOSITORY: REQUIRED_REPOSITORY,
        GH_TOKEN: 'token'
      }),
      /GITHUB_SHA environment variable is required/
    );
  });

  runTest('Policy: Reject GITHUB_SHA with invalid length (<40)', () => {
    assert.throws(
      () => validateEnvironment({
        GITHUB_ACTIONS: 'true',
        RUNNER_TEMP: runnerTemp,
        GITHUB_REPOSITORY: REQUIRED_REPOSITORY,
        GH_TOKEN: 'token',
        GITHUB_SHA: 'a1b2c3'
      }),
      /40 lowercase hex characters/
    );
  });

  runTest('Policy: Reject GITHUB_SHA with uppercase or non-hex characters', () => {
    assert.throws(
      () => validateEnvironment({
        GITHUB_ACTIONS: 'true',
        RUNNER_TEMP: runnerTemp,
        GITHUB_REPOSITORY: REQUIRED_REPOSITORY,
        GH_TOKEN: 'token',
        GITHUB_SHA: 'A1B2C3D4E5F60718293A4B5C6D7E8F9012345678'
      }),
      /40 lowercase hex characters/
    );
  });

  runTest('Policy: Reject SOURCE_SHA mismatch against GITHUB_SHA', () => {
    assert.throws(
      () => validateEnvironment({
        GITHUB_ACTIONS: 'true',
        RUNNER_TEMP: runnerTemp,
        GITHUB_REPOSITORY: REQUIRED_REPOSITORY,
        GH_TOKEN: 'token',
        GITHUB_SHA: dummySha,
        SOURCE_SHA: 'ffffffffffffffffffffffffffffffffffffffff'
      }),
      /SOURCE_SHA .* must match GITHUB_SHA/
    );
  });

  runTest('Policy: validateHeadCommit rejects HEAD commit mismatch against expected GITHUB_SHA', () => {
    assert.throws(
      () => validateHeadCommit(process.cwd(), '0000000000000000000000000000000000000000'),
      /does not match expected GITHUB_SHA/
    );
  });

  runTest('Policy: Reject setup run head_sha mismatch against GITHUB_SHA', () => {
    assert.throws(
      () => validateWorkflowRun(
        {
          id: '12345',
          status: 'completed',
          conclusion: 'success',
          path: EXPECTED_SETUP_WORKFLOW,
          repository: { full_name: REQUIRED_REPOSITORY },
          head_repository: { full_name: REQUIRED_REPOSITORY },
          head_sha: '0000000000000000000000000000000000000000'
        },
        '12345',
        EXPECTED_SETUP_WORKFLOW,
        dummySha,
        'Setup run'
      ),
      /does not match expected GITHUB_SHA/
    );
  });

  runTest('Policy: Reject acceptance run head_sha mismatch against GITHUB_SHA', () => {
    assert.throws(
      () => validateWorkflowRun(
        {
          id: '67890',
          status: 'completed',
          conclusion: 'success',
          path: EXPECTED_ACCEPTANCE_WORKFLOW,
          repository: { full_name: REQUIRED_REPOSITORY },
          head_repository: { full_name: REQUIRED_REPOSITORY },
          head_sha: '1111111111111111111111111111111111111111'
        },
        '67890',
        EXPECTED_ACCEPTANCE_WORKFLOW,
        dummySha,
        'Acceptance run'
      ),
      /does not match expected GITHUB_SHA/
    );
  });

  runTest('Policy: Reject remote tag pointing to mismatched commit SHA', () => {
    assert.throws(
      () => validateRemoteTag(
        { object: { type: 'commit', sha: '9999999999999999999999999999999999999999' } },
        null,
        dummySha
      ),
      /points to commit .*, but expected commit is/
    );
  });

  runTest('Policy: Reject remote tag missing object', () => {
    assert.throws(
      () => validateRemoteTag(null, null, dummySha),
      /Remote tag v0\.0\.13 ref data missing/
    );
  });

  runTest('Policy: Reject peeled annotated tag resolving to mismatched commit SHA', () => {
    const annotatedTagSha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    const wrongTargetSha = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    assert.throws(
      () => validateRemoteTag(
        { object: { type: 'tag', sha: annotatedTagSha } },
        (tagSha) => {
          assert.equal(tagSha, annotatedTagSha);
          return { object: { sha: wrongTargetSha } };
        },
        dummySha
      ),
      /points to commit .*, but expected commit is/
    );
  });

  // --- POLICY TEST GROUP 2: NUMERIC RUN IDs & RUN IDENTITY GUARDS ---
  runTest('Policy: Reject non-numeric setup_run_id', () => {
    assert.throws(() => assertNumericPositiveId('12345abc', 'setup_run_id'), /positive numeric ID/);
  });

  runTest('Policy: Reject non-positive numeric run ID (zero or negative)', () => {
    assert.throws(() => assertNumericPositiveId('0', 'setup_run_id'), /positive numeric ID/);
    assert.throws(() => assertNumericPositiveId('-123', 'acceptance_run_id'), /positive numeric ID/);
  });

  runTest('Policy: Reject non-string run ID type', () => {
    assert.throws(() => assertNumericPositiveId(12345, 'setup_run_id'), /must be a string/);
  });

  runTest('Policy: Reject setup run ID mismatch', () => {
    assert.throws(
      () => validateWorkflowRun(
        {
          id: '99999',
          status: 'completed',
          conclusion: 'success',
          path: EXPECTED_SETUP_WORKFLOW,
          repository: { full_name: REQUIRED_REPOSITORY },
          head_repository: { full_name: REQUIRED_REPOSITORY },
          head_sha: dummySha
        },
        '12345',
        EXPECTED_SETUP_WORKFLOW,
        dummySha,
        'Setup run'
      ),
      /Setup run ID mismatch/
    );
  });

  runTest('Policy: Reject setup run with status not completed', () => {
    assert.throws(
      () => validateWorkflowRun(
        {
          id: '12345',
          status: 'in_progress',
          conclusion: null,
          path: EXPECTED_SETUP_WORKFLOW,
          repository: { full_name: REQUIRED_REPOSITORY },
          head_repository: { full_name: REQUIRED_REPOSITORY },
          head_sha: dummySha
        },
        '12345',
        EXPECTED_SETUP_WORKFLOW,
        dummySha,
        'Setup run'
      ),
      /status is not completed/
    );
  });

  runTest('Policy: Reject setup run with non-success conclusion', () => {
    assert.throws(
      () => validateWorkflowRun(
        {
          id: '12345',
          status: 'completed',
          conclusion: 'failure',
          path: EXPECTED_SETUP_WORKFLOW,
          repository: { full_name: REQUIRED_REPOSITORY },
          head_repository: { full_name: REQUIRED_REPOSITORY },
          head_sha: dummySha
        },
        '12345',
        EXPECTED_SETUP_WORKFLOW,
        dummySha,
        'Setup run'
      ),
      /conclusion is not success/
    );
  });

  runTest('Policy: Reject workflow run with unexpected workflow path', () => {
    assert.throws(
      () => validateWorkflowRun(
        {
          id: '12345',
          status: 'completed',
          conclusion: 'success',
          path: '.github/workflows/untrusted-builder.yml',
          repository: { full_name: REQUIRED_REPOSITORY },
          head_repository: { full_name: REQUIRED_REPOSITORY },
          head_sha: dummySha
        },
        '12345',
        EXPECTED_SETUP_WORKFLOW,
        dummySha,
        'Setup run'
      ),
      /unexpected workflow path/
    );
  });

  runTest('Policy: Reject setup run from external fork or untrusted repository', () => {
    assert.throws(
      () => validateWorkflowRun(
        {
          id: '12345',
          status: 'completed',
          conclusion: 'success',
          path: EXPECTED_SETUP_WORKFLOW,
          repository: { full_name: 'attacker/pos-desktop' },
          head_repository: { full_name: REQUIRED_REPOSITORY },
          head_sha: dummySha
        },
        '12345',
        EXPECTED_SETUP_WORKFLOW,
        dummySha,
        'Setup run'
      ),
      /repository mismatch/
    );
  });

  runTest('Policy: Reject acceptance run from untrusted head_repository', () => {
    assert.throws(
      () => validateWorkflowRun(
        {
          id: '67890',
          status: 'completed',
          conclusion: 'success',
          path: EXPECTED_ACCEPTANCE_WORKFLOW,
          repository: { full_name: REQUIRED_REPOSITORY },
          head_repository: { full_name: 'attacker/pos-desktop' },
          head_sha: dummySha
        },
        '67890',
        EXPECTED_ACCEPTANCE_WORKFLOW,
        dummySha,
        'Acceptance run'
      ),
      /head_repository mismatch/
    );
  });

  runTest('Policy: Reject provenance setup_run_id mismatch', () => {
    assert.throws(
      () => validateEvidenceDirectory(evidenceDir, {
        expectedSetupRunId: '99999',
        expectedAcceptanceRunId: '67890',
        expectedSha: dummySha,
        setupHashes,
        setupSizes
      }),
      /release-provenance setup_run_id mismatch/
    );
  });

  runTest('Policy: Reject provenance acceptance_run_id mismatch', () => {
    assert.throws(
      () => validateEvidenceDirectory(evidenceDir, {
        expectedSetupRunId: '12345',
        expectedAcceptanceRunId: '99999',
        expectedSha: dummySha,
        setupHashes,
        setupSizes
      }),
      /release-provenance acceptance_run_id mismatch/
    );
  });

  runTest('Policy: Reject provenance source_sha mismatch', () => {
    assert.throws(
      () => validateEvidenceDirectory(evidenceDir, {
        expectedSetupRunId: '12345',
        expectedAcceptanceRunId: '67890',
        expectedSha: '0000000000000000000000000000000000000000',
        setupHashes,
        setupSizes
      }),
      /release-provenance source_sha .* must match expectedSha/
    );
  });

  runTest('Policy: Reject acceptance.json with missing run_id', () => {
    const badAcc = { ...validAcceptance };
    delete badAcc.run_id;
    const badAccPath = path.join(evidenceDir, 'acceptance.json');
    fs.writeFileSync(badAccPath, JSON.stringify(badAcc, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /acceptance\.json run_id must be a required string/
      );
    } finally {
      fs.writeFileSync(badAccPath, JSON.stringify(validAcceptance, null, 2));
    }
  });

  runTest('Policy: Reject acceptance.json run_id mismatch', () => {
    const badAcc = { ...validAcceptance, run_id: '99999' };
    const badAccPath = path.join(evidenceDir, 'acceptance.json');
    fs.writeFileSync(badAccPath, JSON.stringify(badAcc, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /acceptance\.json run_id mismatch/
      );
    } finally {
      fs.writeFileSync(badAccPath, JSON.stringify(validAcceptance, null, 2));
    }
  });

  // --- POLICY TEST GROUP 3: FAIL-CLOSED PRE-EXISTING RELEASE GUARDS ---
  runTest('Policy: Reject if release for tag v0.0.13 already exists (status 200)', () => {
    assert.throws(
      () => validateNoExistingRelease({ status: 200, data: { id: 999, draft: false } }),
      /Release for tag v0\.0\.13 already exists/
    );
  });

  runTest('Policy: Fail closed on HTTP 403 Forbidden checking release', () => {
    assert.throws(
      () => validateNoExistingRelease({ status: 403, data: { message: 'Forbidden' } }),
      /Unexpected HTTP status 403 .* must not be treated as 404/
    );
  });

  runTest('Policy: Fail closed on HTTP 500 Server Error checking release', () => {
    assert.throws(
      () => validateNoExistingRelease({ status: 500, data: { message: 'Internal Server Error' } }),
      /Unexpected HTTP status 500 .* must not be treated as 404/
    );
  });

  // --- POLICY TEST GROUP 4: PROOF CONTRACT & 13 NATIVE FLAGS GUARDS ---
  runTest('Policy: Reject release-provenance with missing verification flag', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    delete badProv.verifications.protected_state_preserved;
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /verifications keys mismatch/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject release-provenance with verification flag false', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    badProv.verifications.candidate13_silent_upgrade = false;
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /candidate13_silent_upgrade must be strictly true/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject alternate case alias (indexedDB_offline_preserved) in proof contract', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    delete badProv.verifications.indexeddb_offline_preserved;
    badProv.verifications.indexedDB_offline_preserved = true;
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /verifications keys mismatch/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject extra unapproved verification flags in proof contract', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    badProv.verifications.unapproved_extra_flag = true;
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /verifications keys mismatch/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject acceptance.json with missing verification flag', () => {
    const badAcc = JSON.parse(JSON.stringify(validAcceptance));
    delete badAcc.verifications.database_integrity_verified;
    const badAccPath = path.join(evidenceDir, 'acceptance.json');
    fs.writeFileSync(badAccPath, JSON.stringify(badAcc, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /acceptance\.json verifications keys mismatch/
      );
    } finally {
      fs.writeFileSync(badAccPath, JSON.stringify(validAcceptance, null, 2));
    }
  });

  runTest('Policy: Reject acceptance.json with verification flag false', () => {
    const badAcc = JSON.parse(JSON.stringify(validAcceptance));
    badAcc.verifications.database_integrity_verified = false;
    const badAccPath = path.join(evidenceDir, 'acceptance.json');
    fs.writeFileSync(badAccPath, JSON.stringify(badAcc, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /database_integrity_verified must be strictly true in acceptance\.json/
      );
    } finally {
      fs.writeFileSync(badAccPath, JSON.stringify(validAcceptance, null, 2));
    }
  });

  runTest('Policy: Reject release-provenance verdict !== "PASSED"', () => {
    const badProv = { ...validProvenance, verdict: 'FAILED' };
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /release-provenance verdict must be 'PASSED'/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject release-provenance cleanup_verified !== true', () => {
    const badProv = { ...validProvenance, cleanup_verified: false };
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /release-provenance cleanup_verified must be true/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject release-provenance artifact_binding_verified !== true', () => {
    const badProv = { ...validProvenance, artifact_binding_verified: false };
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /release-provenance artifact_binding_verified must be true/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  // --- POLICY TEST GROUP 5: SETUP ASSETS CONTRACT GUARDS ---
  runTest('Policy: Reject setup_assets formatted as array instead of strict map', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    badProv.setup_assets = Object.values(badProv.setup_assets);
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /setup_assets must be a strict object map/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject setup_assets with missing asset key', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    delete badProv.setup_assets['SHA256SUMS.txt'];
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /setup_assets keys mismatch/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject setup_assets with extra asset key', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    badProv.setup_assets['unexpected-extra.exe'] = {
      sha256: '0000000000000000000000000000000000000000000000000000000000000000',
      size: 100
    };
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /setup_assets keys mismatch/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject setup_assets entry with hash alias or extra property', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    badProv.setup_assets['latest.yml'] = {
      sha256: setupHashes.get('latest.yml'),
      size: setupSizes.get('latest.yml'),
      extra_prop: 'rejected'
    };
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /must contain exact properties \{sha256, size\}/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject setup_assets entry with uppercase sha256', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    badProv.setup_assets['latest.yml'].sha256 = setupHashes.get('latest.yml').toUpperCase();
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /64 lowercase hex characters/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject setup_assets entry with non-positive or unsafe size', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    badProv.setup_assets['latest.yml'].size = -5;
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /must be a safe positive integer/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject setup_assets entry with byte hash mismatch against disk', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    badProv.setup_assets['latest.yml'].sha256 = '0000000000000000000000000000000000000000000000000000000000000000';
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /hash mismatch against verified downloaded asset/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject setup_assets entry with size mismatch against disk', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    badProv.setup_assets['latest.yml'].size = setupSizes.get('latest.yml') + 10;
    const badProvPath = path.join(evidenceDir, 'release-provenance.json');
    fs.writeFileSync(badProvPath, JSON.stringify(badProv, null, 2));
    try {
      assert.throws(
        () => validateEvidenceDirectory(evidenceDir, {
          expectedSetupRunId: '12345',
          expectedAcceptanceRunId: '67890',
          expectedSha: dummySha,
          setupHashes,
          setupSizes
        }),
        /size mismatch against verified downloaded asset/
      );
    } finally {
      fs.writeFileSync(badProvPath, JSON.stringify(validProvenance, null, 2));
    }
  });

  runTest('Policy: Reject setup directory containing unexpected extra files', () => {
    const extraFile = path.join(setupDir, 'unexpected-payload.exe');
    fs.writeFileSync(extraFile, 'unauthorized binary');
    try {
      assert.throws(
        () => validateSetupDirectory(setupDir),
        /Setup directory must contain exact 5 assets/
      );
    } finally {
      fs.unlinkSync(extraFile);
    }
  });

  runTest('Policy: Reject setup directory missing required asset', () => {
    const missingFile = path.join(setupDir, 'latest.yml');
    const content = fs.readFileSync(missingFile);
    fs.unlinkSync(missingFile);
    try {
      assert.throws(
        () => validateSetupDirectory(setupDir),
        /Setup directory must contain exact 5 assets/
      );
    } finally {
      fs.writeFileSync(missingFile, content);
    }
  });

  runTest('Policy: Reject setup SHA256SUMS.txt with checksum mismatch', () => {
    const sumsFile = path.join(setupDir, 'SHA256SUMS.txt');
    const origSums = fs.readFileSync(sumsFile, 'utf8');
    const badSums = origSums.replace(/^[a-f0-9]{64}/m, '0000000000000000000000000000000000000000000000000000000000000000');
    fs.writeFileSync(sumsFile, badSums);
    try {
      assert.throws(
        () => validateSetupDirectory(setupDir),
        /SHA256 mismatch for .* against SHA256SUMS\.txt/
      );
    } finally {
      fs.writeFileSync(sumsFile, origSums);
    }
  });

  runTest('Policy: Reject latest.yml with version mismatch', () => {
    const ymlFile = path.join(setupDir, 'latest.yml');
    const sumsFile = path.join(setupDir, 'SHA256SUMS.txt');
    const origYml = fs.readFileSync(ymlFile, 'utf8');
    const origSums = fs.readFileSync(sumsFile, 'utf8');

    const tamperedYml = origYml.replace('0.0.13', '0.0.12');
    const origHash = sha256(Buffer.from(origYml));
    const tamperedHash = sha256(Buffer.from(tamperedYml));

    fs.writeFileSync(ymlFile, tamperedYml);
    fs.writeFileSync(sumsFile, origSums.replace(origHash, tamperedHash));
    try {
      assert.throws(
        () => validateSetupDirectory(setupDir),
        /latest\.yml version must be 0\.0\.13/
      );
    } finally {
      fs.writeFileSync(ymlFile, origYml);
      fs.writeFileSync(sumsFile, origSums);
    }
  });

  // --- POLICY TEST GROUP 6: CARRIER ARTIFACTS GUARDS ---
  runTest('Policy: Reject carrier directory with missing file (17 files)', () => {
    const carrierFile = path.join(carrierDir, 'release-notes.md');
    const orig = fs.readFileSync(carrierFile);
    fs.unlinkSync(carrierFile);
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Carrier directory must match exact 18 allowlisted files/
      );
    } finally {
      fs.writeFileSync(carrierFile, orig);
    }
  });

  runTest('Policy: Reject carrier directory with extra file (19 files)', () => {
    const extraCarrier = path.join(carrierDir, 'extra-unapproved.txt');
    fs.writeFileSync(extraCarrier, 'unapproved extra file');
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Carrier directory must match exact 18 allowlisted files/
      );
    } finally {
      fs.unlinkSync(extraCarrier);
    }
  });

  runTest('Policy: Reject carrier directory containing prohibited delta.zip', () => {
    const badDelta = path.join(carrierDir, 'delta.zip');
    fs.writeFileSync(badDelta, 'ordinary delta rejected');
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Carrier directory must match exact 18 allowlisted files/
      );
    } finally {
      fs.unlinkSync(badDelta);
    }
  });

  runTest('Policy: Reject carrier directory containing private key or .pem file', () => {
    const badKey = path.join(carrierDir, 'update_private_key.key');
    fs.writeFileSync(badKey, 'private key leaked');
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Carrier directory must match exact 18 allowlisted files/
      );
    } finally {
      fs.unlinkSync(badKey);
    }
  });

  runTest('Policy: Reject carrier directory containing negative test fixture file', () => {
    const badFixture = path.join(carrierDir, 'corrupt-fixture.json');
    fs.writeFileSync(badFixture, '{}');
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Carrier directory must match exact 18 allowlisted files/
      );
    } finally {
      fs.unlinkSync(badFixture);
    }
  });

  runTest('Policy: Reject carrier deployment-inventory.json byte mismatch against Setup inventory', () => {
    const carrierInvPath = path.join(carrierDir, 'deployment-inventory.json');
    const orig = fs.readFileSync(carrierInvPath);
    // Write altered deployment inventory with different shell_sha256
    const altered = {
      ...setupInventoryObj,
      shell_sha256: '0000000000000000000000000000000000000000000000000000000000000000'
    };
    fs.writeFileSync(carrierInvPath, JSON.stringify(altered, null, 2) + '\n');
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Carrier deployment-inventory\.json SHA256 mismatch against Setup deployment-inventory\.json/
      );
    } finally {
      fs.writeFileSync(carrierInvPath, orig);
    }
  });

  runTest('Policy: Reject duplicate transition tuple in channel-transitions.json', () => {
    const indexPath = path.join(carrierDir, 'channel-transitions.json');
    const origIndex = fs.readFileSync(indexPath, 'utf8');
    const parsed = JSON.parse(origIndex);
    // Duplicate first entry in place of fourth entry
    parsed.entries[3] = { ...parsed.entries[0] };
    fs.writeFileSync(indexPath, JSON.stringify(parsed, null, 2) + '\n');
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Duplicate transition tuple in channel-transitions\.json/
      );
    } finally {
      fs.writeFileSync(indexPath, origIndex);
    }
  });

  runTest('Policy: Reject descriptor hash mismatch against channel-transitions.json descriptor_sha256', () => {
    const descStem = EXPECTED_TRANSITIONS[0].stem;
    const descPath = path.join(carrierDir, `${descStem}.json`);
    const origDesc = fs.readFileSync(descPath, 'utf8');
    fs.writeFileSync(descPath, origDesc + '   \n');
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Descriptor hash mismatch/
      );
    } finally {
      fs.writeFileSync(descPath, origDesc);
    }
  });

  runTest('Policy: Reject transition descriptor with wrong package.asset ZIP reference', () => {
    const descStem = EXPECTED_TRANSITIONS[0].stem;
    const descPath = path.join(carrierDir, `${descStem}.json`);
    const indexPath = path.join(carrierDir, 'channel-transitions.json');
    const origDesc = fs.readFileSync(descPath, 'utf8');
    const origIndex = fs.readFileSync(indexPath, 'utf8');

    const parsedDesc = JSON.parse(origDesc);
    parsedDesc.package.asset = 'wrong-package.zip';
    const tamperedRaw = JSON.stringify(parsedDesc, null, 2) + '\n';
    fs.writeFileSync(descPath, tamperedRaw);

    const parsedIndex = JSON.parse(origIndex);
    parsedIndex.entries[0].descriptor_sha256 = sha256(Buffer.from(tamperedRaw));
    fs.writeFileSync(indexPath, JSON.stringify(parsedIndex, null, 2) + '\n');
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /package\.asset must be/
      );
    } finally {
      fs.writeFileSync(descPath, origDesc);
      fs.writeFileSync(indexPath, origIndex);
    }
  });

  runTest('Policy: Reject transition descriptor with package.sha256 mismatch against ZIP on disk', () => {
    const descStem = EXPECTED_TRANSITIONS[0].stem;
    const descPath = path.join(carrierDir, `${descStem}.json`);
    const indexPath = path.join(carrierDir, 'channel-transitions.json');
    const origDesc = fs.readFileSync(descPath, 'utf8');
    const origIndex = fs.readFileSync(indexPath, 'utf8');

    const parsedDesc = JSON.parse(origDesc);
    parsedDesc.package.sha256 = '0000000000000000000000000000000000000000000000000000000000000000';
    const tamperedRaw = JSON.stringify(parsedDesc, null, 2) + '\n';
    fs.writeFileSync(descPath, tamperedRaw);

    const parsedIndex = JSON.parse(origIndex);
    parsedIndex.entries[0].descriptor_sha256 = sha256(Buffer.from(tamperedRaw));
    fs.writeFileSync(indexPath, JSON.stringify(parsedIndex, null, 2) + '\n');
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Package archive hash mismatch/
      );
    } finally {
      fs.writeFileSync(descPath, origDesc);
      fs.writeFileSync(indexPath, origIndex);
    }
  });

  runTest('Policy: Reject transition descriptor with package.size mismatch against ZIP on disk', () => {
    const descStem = EXPECTED_TRANSITIONS[0].stem;
    const descPath = path.join(carrierDir, `${descStem}.json`);
    const indexPath = path.join(carrierDir, 'channel-transitions.json');
    const origDesc = fs.readFileSync(descPath, 'utf8');
    const origIndex = fs.readFileSync(indexPath, 'utf8');

    const parsedDesc = JSON.parse(origDesc);
    parsedDesc.package.size = 99999999;
    const tamperedRaw = JSON.stringify(parsedDesc, null, 2) + '\n';
    fs.writeFileSync(descPath, tamperedRaw);

    const parsedIndex = JSON.parse(origIndex);
    parsedIndex.entries[0].descriptor_sha256 = sha256(Buffer.from(tamperedRaw));
    fs.writeFileSync(indexPath, JSON.stringify(parsedIndex, null, 2) + '\n');
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Package archive size mismatch/
      );
    } finally {
      fs.writeFileSync(descPath, origDesc);
      fs.writeFileSync(indexPath, origIndex);
    }
  });

  runTest('Policy: Reject carrier_artifacts in provenance with missing key', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    delete badProv.carrier_artifacts['release-notes.md'];
    assert.throws(
      () => validateCarrierDirectory(carrierDir, badProv, inventoryInfo),
      /carrier_artifacts keys do not match exact 18 allowlisted carrier files/
    );
  });

  runTest('Policy: Reject carrier_artifacts hash mismatch against disk', () => {
    const badProv = JSON.parse(JSON.stringify(validProvenance));
    badProv.carrier_artifacts['release-notes.md'].sha256 = '0000000000000000000000000000000000000000000000000000000000000000';
    assert.throws(
      () => validateCarrierDirectory(carrierDir, badProv, inventoryInfo),
      /carrier_artifacts\[release-notes\.md\] hash mismatch/
    );
  });

  runTest('Policy: Reject Carrier SHA256SUMS.txt with hash mismatch against disk', () => {
    const sumsPath = path.join(carrierDir, 'SHA256SUMS.txt');
    const origSums = fs.readFileSync(sumsPath, 'utf8');
    const badSums = origSums.replace(/^[a-f0-9]{64}/m, '0000000000000000000000000000000000000000000000000000000000000000');
    fs.writeFileSync(sumsPath, badSums);
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Carrier SHA256SUMS\.txt hash mismatch/
      );
    } finally {
      fs.writeFileSync(sumsPath, origSums);
    }
  });

  runTest('Policy: Reject Carrier SHA256SUMS.txt with path traversal', () => {
    const sumsPath = path.join(carrierDir, 'SHA256SUMS.txt');
    const origSums = fs.readFileSync(sumsPath, 'utf8');
    const badSums = origSums + '0000000000000000000000000000000000000000000000000000000000000000  ../evil.txt\n';
    fs.writeFileSync(sumsPath, badSums);
    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /Carrier SHA256SUMS\.txt must cover exactly 17 files/
      );
    } finally {
      fs.writeFileSync(sumsPath, origSums);
    }
  });

  // --- POLICY TEST GROUP 7: PRODUCER GAP FIXTURES (DEEP FILES EQUALITY) ---
  runTest('Producer Gap 1: Reject return transition descriptor with tampered target_inventory files', () => {
    const descStem = 'transition-0.0.12-beta.1-to-stable-0.0.13-delta';
    const returnDescPath = path.join(carrierDir, `${descStem}.json`);
    const indexPath = path.join(carrierDir, 'channel-transitions.json');
    const origReturnDesc = fs.readFileSync(returnDescPath, 'utf8');
    const origIndex = fs.readFileSync(indexPath, 'utf8');

    const parsedDesc = JSON.parse(origReturnDesc);
    // Tamper embedded target_inventory.files (e.g. faked version.json SHA)
    parsedDesc.target_inventory.files = {
      ...parsedDesc.target_inventory.files,
      'version.json': {
        sha256: '0000000000000000000000000000000000000000000000000000000000000000',
        size: 123
      }
    };
    const tamperedDescRaw = JSON.stringify(parsedDesc, null, 2) + '\n';
    fs.writeFileSync(returnDescPath, tamperedDescRaw);

    // Update channel-transitions.json descriptor_sha256 to isolate embedded files check
    const parsedIndex = JSON.parse(origIndex);
    const entry = parsedIndex.entries.find((e) => e.descriptor === `${descStem}.json`);
    entry.descriptor_sha256 = sha256(Buffer.from(tamperedDescRaw));
    fs.writeFileSync(indexPath, JSON.stringify(parsedIndex, null, 2) + '\n');

    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /target_inventory files mismatch against Setup 13/
      );
    } finally {
      fs.writeFileSync(returnDescPath, origReturnDesc);
      fs.writeFileSync(indexPath, origIndex);
    }
  });

  runTest('Producer Gap 2: Reject forward transition descriptor with tampered source_inventory files', () => {
    const descStem = 'transition-0.0.13-to-beta-0.0.12-beta.1-delta';
    const forwardDescPath = path.join(carrierDir, `${descStem}.json`);
    const indexPath = path.join(carrierDir, 'channel-transitions.json');
    const origForwardDesc = fs.readFileSync(forwardDescPath, 'utf8');
    const origIndex = fs.readFileSync(indexPath, 'utf8');

    const parsedDesc = JSON.parse(origForwardDesc);
    // Tamper embedded source_inventory.files (e.g. faked version.json SHA)
    parsedDesc.source_inventory.files = {
      ...parsedDesc.source_inventory.files,
      'version.json': {
        sha256: 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        size: 123
      }
    };
    const tamperedDescRaw = JSON.stringify(parsedDesc, null, 2) + '\n';
    fs.writeFileSync(forwardDescPath, tamperedDescRaw);

    // Update channel-transitions.json descriptor_sha256 to isolate embedded files check
    const parsedIndex = JSON.parse(origIndex);
    const entry = parsedIndex.entries.find((e) => e.descriptor === `${descStem}.json`);
    entry.descriptor_sha256 = sha256(Buffer.from(tamperedDescRaw));
    fs.writeFileSync(indexPath, JSON.stringify(parsedIndex, null, 2) + '\n');

    try {
      assert.throws(
        () => validateCarrierDirectory(carrierDir, validProvenance, inventoryInfo),
        /source_inventory files mismatch against Setup 13/
      );
    } finally {
      fs.writeFileSync(forwardDescPath, origForwardDesc);
      fs.writeFileSync(indexPath, origIndex);
    }
  });

  // --- POLICY TEST GROUP 8: BOUNDARY & REPARSE CHECKS & STAGING CLEANUP ---
  runTest('Policy: assertNoReparse rejects symlinks in path tree', () => {
    const linkPath = path.join(testTemp, 'test-symlink');
    let symlinkCreated = false;
    try {
      fs.symlinkSync(setupDir, linkPath, 'dir');
      symlinkCreated = true;
    } catch (createErr) {
      if (createErr.code === 'EPERM' || createErr.code === 'EACCES') {
        return { skipped: true, reason: 'Symlink creation unsupported/unprivileged on current host' };
      }
      throw createErr;
    }

    if (symlinkCreated) {
      try {
        assert.throws(() => assertNoReparse(linkPath), /Reparse point or symlink rejected/);
      } finally {
        fs.rmSync(linkPath, { recursive: true, force: true });
      }
    }
  });

  runTest('Policy: cleanupOwnedStaging cleans up genuine owned staging directory', () => {
    const ownedStaging = fs.mkdtempSync(path.join(runnerTemp, 'setup13-promote-staging-'));
    assert.ok(fs.existsSync(ownedStaging), 'owned staging should exist before cleanup');
    cleanupOwnedStaging(ownedStaging, runnerTemp);
    assert.ok(!fs.existsSync(ownedStaging), 'owned staging should be removed after cleanup');
  });

  runTest('Policy: cleanupOwnedStaging rejects staging path outside RUNNER_TEMP', () => {
    const outsidePath = path.resolve(runnerTemp, '..', 'setup13-promote-staging-evil');
    assert.throws(
      () => cleanupOwnedStaging(outsidePath, runnerTemp),
      /stagingDir must be strictly an owned descendant of RUNNER_TEMP/
    );
  });

  runTest('Policy: cleanupOwnedStaging rejects RUNNER_TEMP directory itself', () => {
    assert.throws(
      () => cleanupOwnedStaging(runnerTemp, runnerTemp),
      /stagingDir must be strictly an owned descendant of RUNNER_TEMP/
    );
  });

  runTest('Policy: cleanupOwnedStaging rejects unowned or deterministic directory name', () => {
    const unownedStaging = path.join(runnerTemp, 'unowned-staging-dir');
    assert.throws(
      () => cleanupOwnedStaging(unownedStaging, runnerTemp),
      /Refusing to delete unowned directory name/
    );
  });

  runTest('Policy: cleanupOwnedStaging rejects non-directory file even with owned prefix', () => {
    const filePath = path.join(runnerTemp, 'setup13-promote-staging-fakefile.tmp');
    fs.writeFileSync(filePath, 'not a directory');
    try {
      assert.throws(
        () => cleanupOwnedStaging(filePath, runnerTemp),
        /stagingDir must be a genuine directory/
      );
    } finally {
      fs.rmSync(filePath, { force: true });
    }
  });

  console.log(`\n===============================================================`);
  if (skippedTests > 0) {
    console.log(` PROMOTION POLICY REGRESSION TESTS: ${passedTests} PASSED, ${skippedTests} SKIPPED (${totalTests} TOTAL)`);
  } else {
    console.log(` ALL ${passedTests}/${totalTests} PROMOTION POLICY REGRESSION TESTS PASSED!`);
  }
  console.log(`===============================================================\n`);
} finally {
  fs.rmSync(testTemp, { recursive: true, force: true });
}
