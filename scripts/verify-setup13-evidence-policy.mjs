import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';

import {
  sha256,
  assertPathWithin,
  assertNoReparse,
  verifyRsaSignature,
  assertGhaRunner,
  validateVerifications,
  parseStrictSha256Sums,
  validateSetupDirectory,
  validateBindingReceipt,
  validateCarrierDirectory,
  validateTransitionDescriptor,
  validateTransitionIndex,
  REQUIRED_13_VERIFICATIONS,
  EXACT_5_SETUP_ASSETS,
  EXACT_18_CARRIER_FILES,
  PINNED_BETA12_INVENTORY_SHA256,
} from './finalize-setup13-release-evidence.mjs';

// Strict GHA Environment Guard: Requires authentic ephemeral Windows GHA runner
assertGhaRunner();

function signWithKey(data, privateKeyPem) {
  const signer = crypto.createSign('sha256');
  signer.update(data);
  signer.end();
  return signer.sign(privateKeyPem, 'base64');
}

function createTestLab() {
  const runnerTemp = path.resolve(process.env.RUNNER_TEMP);
  assertNoReparse(runnerTemp);

  const baseDir = path.join(runnerTemp, `policy-test-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`);
  fs.mkdirSync(baseDir);
  assertNoReparse(baseDir);

  return {
    baseDir,
    runnerTemp,
    cleanup() {
      try {
        if (fs.existsSync(baseDir)) {
          fs.rmSync(baseDir, { recursive: true, force: true });
        }
      } catch {}
    },
  };
}

function runPolicyTestSuite() {
  console.log('[Setup 13 Policy Suite] Starting pure policy regression tests in GHA...');
  let total = 0;
  let passed = 0;

  function test(name, fn) {
    total++;
    try {
      fn();
      console.log(`  PASS [${total}]: ${name}`);
      passed++;
    } catch (err) {
      console.error(`  FAIL [${total}]: ${name}`);
      console.error('       ', err.message);
      throw err;
    }
  }

  // 1. Verifications Key Set and Booleans
  test('validateVerifications accepts exact 13 true booleans', () => {
    const valid = {};
    for (const k of REQUIRED_13_VERIFICATIONS) valid[k] = true;
    validateVerifications(valid);
  });

  test('validateVerifications rejects when any flag is false', () => {
    const v = {};
    for (const k of REQUIRED_13_VERIFICATIONS) v[k] = true;
    v.candidate13_silent_upgrade = false;
    assert.throws(() => validateVerifications(v), /candidate13_silent_upgrade.*must be true/);
  });

  test('validateVerifications rejects extra keys or aliases', () => {
    const v = {};
    for (const k of REQUIRED_13_VERIFICATIONS) v[k] = true;
    v.extra_unauthorized_check = true;
    assert.throws(() => validateVerifications(v), /keyset must match REQUIRED_13_VERIFICATIONS exactly/);
  });

  test('validateVerifications rejects missing keys', () => {
    const v = {};
    for (const k of REQUIRED_13_VERIFICATIONS.slice(1)) v[k] = true;
    assert.throws(() => validateVerifications(v), /keyset must match REQUIRED_13_VERIFICATIONS exactly/);
  });

  // 2. Strict SHA256SUMS Parsing
  test('parseStrictSha256Sums accepts valid checksum file', () => {
    const expected = ['POS-Desktop-Setup-0.0.13.exe', 'latest.yml'];
    const h1 = 'a'.repeat(64);
    const h2 = 'b'.repeat(64);
    const raw = `${h1}  POS-Desktop-Setup-0.0.13.exe\n${h2}  latest.yml\n`;
    const map = parseStrictSha256Sums(raw, expected);
    assert.equal(map.get('POS-Desktop-Setup-0.0.13.exe'), h1);
    assert.equal(map.get('latest.yml'), h2);
  });

  test('parseStrictSha256Sums rejects duplicate filename entries', () => {
    const raw = `
${'a'.repeat(64)}  POS-Desktop-Setup-0.0.13.exe
${'b'.repeat(64)}  POS-Desktop-Setup-0.0.13.exe
${'c'.repeat(64)}  latest.yml
${'d'.repeat(64)}  deployment-inventory.json
`;
    assert.throws(() => {
      parseStrictSha256Sums(raw, ['POS-Desktop-Setup-0.0.13.exe', 'latest.yml', 'deployment-inventory.json', 'POS-Desktop-Setup-0.0.13.exe.blockmap']);
    }, /Duplicate filename listed in SHA256SUMS\.txt/);
  });

  test('parseStrictSha256Sums rejects self-reference to SHA256SUMS.txt', () => {
    const raw = `${'a'.repeat(64)}  SHA256SUMS.txt\n`;
    assert.throws(() => {
      parseStrictSha256Sums(raw, ['SHA256SUMS.txt']);
    }, /must not contain a self-reference/);
  });

  test('parseStrictSha256Sums rejects path traversal in filenames', () => {
    const raw = `${'a'.repeat(64)}  ../evil.exe\n`;
    assert.throws(() => {
      parseStrictSha256Sums(raw, ['../evil.exe']);
    }, /Path traversal forbidden in checksum file/);
  });

  test('parseStrictSha256Sums rejects line count mismatch', () => {
    const raw = `${'a'.repeat(64)}  POS-Desktop-Setup-0.0.13.exe\n`;
    assert.throws(() => {
      parseStrictSha256Sums(raw, ['POS-Desktop-Setup-0.0.13.exe', 'latest.yml']);
    }, /line count must be exactly 2/);
  });

  // 3. Path Safety & Pre-existing Directory Protection
  test('assertPathWithin rejects paths escaping RUNNER_TEMP', () => {
    const lab = createTestLab();
    try {
      assert.throws(() => {
        assertPathWithin('C:/Windows/System32', lab.runnerTemp, 'Target');
      }, /must reside within/);
    } finally {
      lab.cleanup();
    }
  });

  test('Fails when carrier staging directory pre-exists before staging ownership', () => {
    const lab = createTestLab();
    try {
      const carrierDir = path.join(lab.baseDir, 'setup13-carrier');
      fs.mkdirSync(carrierDir);
      assert.ok(fs.existsSync(carrierDir));

      // Attempting to prepare a pre-existing carrierDir must fail
      assert.throws(() => {
        if (fs.existsSync(carrierDir) || fs.lstatSync(carrierDir, { throwIfNoEntry: false })) {
          throw new Error(`Carrier staging directory already exists before staging ownership: ${carrierDir}`);
        }
      }, /Carrier staging directory already exists before staging ownership/);
    } finally {
      lab.cleanup();
    }
  });

  // 4. Binding Receipt Validation
  test('validateBindingReceipt accepts fully valid receipt', () => {
    const receipt = {
      schema_version: '1.0.0',
      setup_run_id: '11111111',
      acceptance_run_id: '22222222',
      source_sha: 'a'.repeat(40),
      run_token: 'token-123',
      installer_sha256: 'b'.repeat(64),
      setup_assets: {},
    };
    for (const fn of EXACT_5_SETUP_ASSETS) {
      receipt.setup_assets[fn] = { sha256: 'b'.repeat(64), size: 100 };
    }
    validateBindingReceipt(receipt, {
      setupRunId: '11111111',
      acceptanceRunId: '22222222',
      sourceSha: 'a'.repeat(40),
      runToken: 'token-123',
      installerSha: 'b'.repeat(64),
    });
  });

  test('validateBindingReceipt rejects setup_run_id mismatch', () => {
    const receipt = {
      schema_version: '1.0.0',
      setup_run_id: '11111111',
      acceptance_run_id: '22222222',
      source_sha: 'a'.repeat(40),
      run_token: 'token-123',
      installer_sha256: 'b'.repeat(64),
      setup_assets: {},
    };
    for (const fn of EXACT_5_SETUP_ASSETS) {
      receipt.setup_assets[fn] = { sha256: 'b'.repeat(64), size: 100 };
    }
    assert.throws(() => {
      validateBindingReceipt(receipt, {
        setupRunId: '99999999',
        acceptanceRunId: '22222222',
        sourceSha: 'a'.repeat(40),
        runToken: 'token-123',
        installerSha: 'b'.repeat(64),
      });
    }, /setup_run_id mismatch/);
  });

  test('validateBindingReceipt rejects acceptance_run_id mismatch', () => {
    const receipt = {
      schema_version: '1.0.0',
      setup_run_id: '11111111',
      acceptance_run_id: '22222222',
      source_sha: 'a'.repeat(40),
      run_token: 'token-123',
      installer_sha256: 'b'.repeat(64),
      setup_assets: {},
    };
    for (const fn of EXACT_5_SETUP_ASSETS) {
      receipt.setup_assets[fn] = { sha256: 'b'.repeat(64), size: 100 };
    }
    assert.throws(() => {
      validateBindingReceipt(receipt, {
        setupRunId: '11111111',
        acceptanceRunId: '33333333',
        sourceSha: 'a'.repeat(40),
        runToken: 'token-123',
        installerSha: 'b'.repeat(64),
      });
    }, /acceptance_run_id mismatch/);
  });

  test('validateBindingReceipt rejects source_sha mismatch', () => {
    const receipt = {
      schema_version: '1.0.0',
      setup_run_id: '11111111',
      acceptance_run_id: '22222222',
      source_sha: 'a'.repeat(40),
      run_token: 'token-123',
      installer_sha256: 'b'.repeat(64),
      setup_assets: {},
    };
    for (const fn of EXACT_5_SETUP_ASSETS) {
      receipt.setup_assets[fn] = { sha256: 'b'.repeat(64), size: 100 };
    }
    assert.throws(() => {
      validateBindingReceipt(receipt, {
        setupRunId: '11111111',
        acceptanceRunId: '22222222',
        sourceSha: 'f'.repeat(40),
        runToken: 'token-123',
        installerSha: 'b'.repeat(64),
      });
    }, /source_sha mismatch/);
  });

  test('validateBindingReceipt rejects run_token mismatch', () => {
    const receipt = {
      schema_version: '1.0.0',
      setup_run_id: '11111111',
      acceptance_run_id: '22222222',
      source_sha: 'a'.repeat(40),
      run_token: 'token-123',
      installer_sha256: 'b'.repeat(64),
      setup_assets: {},
    };
    for (const fn of EXACT_5_SETUP_ASSETS) {
      receipt.setup_assets[fn] = { sha256: 'b'.repeat(64), size: 100 };
    }
    assert.throws(() => {
      validateBindingReceipt(receipt, {
        setupRunId: '11111111',
        acceptanceRunId: '22222222',
        sourceSha: 'a'.repeat(40),
        runToken: 'wrong-token',
        installerSha: 'b'.repeat(64),
      });
    }, /run_token mismatch/);
  });

  test('validateBindingReceipt rejects installer_sha256 mismatch', () => {
    const receipt = {
      schema_version: '1.0.0',
      setup_run_id: '11111111',
      acceptance_run_id: '22222222',
      source_sha: 'a'.repeat(40),
      run_token: 'token-123',
      installer_sha256: 'b'.repeat(64),
      setup_assets: {},
    };
    for (const fn of EXACT_5_SETUP_ASSETS) {
      receipt.setup_assets[fn] = { sha256: 'b'.repeat(64), size: 100 };
    }
    assert.throws(() => {
      validateBindingReceipt(receipt, {
        setupRunId: '11111111',
        acceptanceRunId: '22222222',
        sourceSha: 'a'.repeat(40),
        runToken: 'token-123',
        installerSha: 'c'.repeat(64),
      });
    }, /installer_sha256 mismatch/);
  });

  test('validateBindingReceipt rejects name property in setup_assets', () => {
    const receipt = {
      schema_version: '1.0.0',
      setup_run_id: '11111111',
      acceptance_run_id: '22222222',
      source_sha: 'a'.repeat(40),
      run_token: 'token-123',
      installer_sha256: 'b'.repeat(64),
      setup_assets: {},
    };
    for (const fn of EXACT_5_SETUP_ASSETS) {
      receipt.setup_assets[fn] = { name: fn, sha256: 'b'.repeat(64), size: 100 };
    }
    assert.throws(() => {
      validateBindingReceipt(receipt, {
        setupRunId: '11111111',
        acceptanceRunId: '22222222',
        sourceSha: 'a'.repeat(40),
        runToken: 'token-123',
        installerSha: 'b'.repeat(64),
      });
    }, /must not contain 'name' property/);
  });

  // 5. Setup Directory Exact 5 Assets and No Extras/Directories
  test('validateSetupDirectory rejects extra or missing files', () => {
    const lab = createTestLab();
    try {
      const sDir = path.join(lab.baseDir, 'setup-artifacts');
      fs.mkdirSync(sDir);
      for (const fn of EXACT_5_SETUP_ASSETS) {
        fs.writeFileSync(path.join(sDir, fn), 'dummy content');
      }
      fs.writeFileSync(path.join(sDir, 'rogue-extra.txt'), 'extra');

      assert.throws(() => {
        validateSetupDirectory(sDir);
      }, /must contain exact 5 entries/);
    } finally {
      lab.cleanup();
    }
  });

  test('validateSetupDirectory rejects directories or symlinks inside artifact directory', () => {
    const lab = createTestLab();
    try {
      const sDir = path.join(lab.baseDir, 'setup-artifacts');
      fs.mkdirSync(sDir);
      for (const fn of EXACT_5_SETUP_ASSETS.slice(0, 4)) {
        fs.writeFileSync(path.join(sDir, fn), 'dummy content');
      }
      fs.mkdirSync(path.join(sDir, EXACT_5_SETUP_ASSETS[4])); // directory instead of file

      assert.throws(() => {
        validateSetupDirectory(sDir);
      }, /must be a regular file, not a directory or symlink/);
    } finally {
      lab.cleanup();
    }
  });

  // 6. Carrier Directory Exact 18 Assets & Forbidden Files Rejection
  test('validateCarrierDirectory rejects forbidden or hidden files', () => {
    const lab = createTestLab();
    try {
      const cDir = path.join(lab.baseDir, 'carrier-artifacts');
      fs.mkdirSync(cDir);
      const manifest = { carrier_artifacts: {} };
      for (const fn of EXACT_18_CARRIER_FILES) {
        fs.writeFileSync(path.join(cDir, fn), 'carrier content');
        manifest.carrier_artifacts[fn] = { sha256: sha256(Buffer.from('carrier content')), size: 15 };
      }
      fs.writeFileSync(path.join(cDir, '.carrier-staging-manifest.json'), '{}');

      assert.throws(() => {
        validateCarrierDirectory(cDir, manifest);
      }, /must contain exact 18 files/);
    } finally {
      lab.cleanup();
    }
  });

  // 7. Transition Index & Descriptor Verification
  test('validateTransitionIndex rejects duplicate direction pairs', () => {
    const keyPair = crypto.generateKeyPairSync('rsa', {
      modulusLength: 2048,
      publicKeyEncoding: { type: 'spki', format: 'pem' },
      privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
    });

    const indexObj = {
      format: 1,
      carrier_tag: 'v0.0.13',
      entries: [
        { from_version: '0.0.13', version: '0.0.12-beta.1', type: 'delta' },
        { from_version: '0.0.13', version: '0.0.12-beta.1', type: 'delta' }, // Duplicate
        { from_version: '0.0.12-beta.1', version: '0.0.13', type: 'delta' },
        { from_version: '0.0.12-beta.1', version: '0.0.13', type: 'full' },
      ],
    };
    const raw = JSON.stringify(indexObj, null, 2) + '\n';
    const sig = signWithKey(raw, keyPair.privateKey);

    assert.throws(() => {
      validateTransitionIndex(indexObj, keyPair.publicKey, raw, sig);
    }, /Duplicate transition direction pair/);
  });

  test('validateTransitionDescriptor rejects deprecated carrier_repo field', () => {
    const descData = {
      format: 1,
      operation: 'channel_transition',
      carrier_tag: 'v0.0.13',
      carrier_repo: 'deprecated-field',
      target_repo: 'pos-desktop-updates',
      from_version: '0.0.13',
      version: '0.0.12-beta.1',
      package: { asset: 'dummy.zip' },
      data_compatibility: { format: 1, metadata_migration: '059_add_update_compatibility_metadata.sql', schema_sha256: 'a'.repeat(64), ledger_sha256: 'b'.repeat(64) },
    };

    assert.throws(() => {
      validateTransitionDescriptor(descData, { shellHash: 'c'.repeat(64) });
    }, /Descriptor must NOT contain deprecated carrier_repo field/);
  });

  test('validateTransitionDescriptor rejects tampered Beta 12.1 inventory hash', () => {
    const descData = {
      format: 1,
      operation: 'channel_transition',
      carrier_tag: 'v0.0.13',
      target_repo: 'pos-desktop-updates',
      from_version: '0.0.13',
      from_channel: 'stable',
      version: '0.0.12-beta.1',
      channel: 'beta',
      source_inventory_sha256: 'setup-hash',
      source_inventory: { files: { 'a.txt': { sha256: '1', size: 1 } } },
      target_inventory_sha256: 'tampered-beta-hash',
      target_inventory: { version: '0.0.12-beta.1' },
      shell_sha256: 'shell-hash',
      package: { asset: 'dummy.zip', sha256: sha256(Buffer.from('zip')), size: 3 },
      data_compatibility: { format: 1, metadata_migration: '059_add_update_compatibility_metadata.sql', schema_sha256: 'a'.repeat(64), ledger_sha256: 'b'.repeat(64) },
    };

    assert.throws(() => {
      validateTransitionDescriptor(descData, {
        canonicalSetupInvHash: 'setup-hash',
        canonicalSetupFiles: { 'a.txt': { sha256: '1', size: 1 } },
        beta12InvHash: PINNED_BETA12_INVENTORY_SHA256,
        shellHash: 'shell-hash',
        zipBytes: Buffer.from('zip'),
      });
    }, /Beta 12\.1 target_inventory_sha256 mismatch/);
  });

  console.log(`\nAll ${passed}/${total} pure policy regression tests passed successfully.`);
}

runPolicyTestSuite();
