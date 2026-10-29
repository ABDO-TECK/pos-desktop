import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export const PINNED_BETA12_INVENTORY_SHA256 = '104cc1b55d9ebae69aac8d56bbb024afa178e4208fd73a41a1d4d89d504b1fb3';

export const REQUIRED_13_VERIFICATIONS = [
  'bug1_candidate13_beta_to_stable_transition',
  'bug1_candidate13_stable_to_beta_transition',
  'bug1_setup12_source_inventory_mismatch_reproduced',
  'bug2_desktop_sql_restore_rollback_and_error_classification',
  'bug2_desktop_sql_restore_success',
  'candidate13_shell_root_preserved',
  'candidate13_silent_upgrade',
  'database_integrity_verified',
  'indexeddb_offline_preserved',
  'legacy_v025_restore_migrations_verified',
  'protected_state_preserved',
  'repeated_channel_roundtrip_verified',
  'session_preservation_on_error_verified',
];

export const EXACT_5_SETUP_ASSETS = [
  'POS-Desktop-Setup-0.0.13.exe',
  'POS-Desktop-Setup-0.0.13.exe.blockmap',
  'latest.yml',
  'deployment-inventory.json',
  'SHA256SUMS.txt',
];

export const REQUIRED_POSITIVE_CARRIER_FILES = [
  'channel-transitions.json',
  'channel-transitions.sig',
  'transition-0.0.13-to-beta-0.0.12-beta.1-delta.zip',
  'transition-0.0.13-to-beta-0.0.12-beta.1-delta.json',
  'transition-0.0.13-to-beta-0.0.12-beta.1-delta.sig',
  'transition-0.0.13-to-beta-0.0.12-beta.1-full.zip',
  'transition-0.0.13-to-beta-0.0.12-beta.1-full.json',
  'transition-0.0.13-to-beta-0.0.12-beta.1-full.sig',
  'transition-0.0.12-beta.1-to-stable-0.0.13-delta.zip',
  'transition-0.0.12-beta.1-to-stable-0.0.13-delta.json',
  'transition-0.0.12-beta.1-to-stable-0.0.13-delta.sig',
  'transition-0.0.12-beta.1-to-stable-0.0.13-full.zip',
  'transition-0.0.12-beta.1-to-stable-0.0.13-full.json',
  'transition-0.0.12-beta.1-to-stable-0.0.13-full.sig',
];

export const EXACT_18_CARRIER_FILES = [
  'channel-transitions.json',
  'channel-transitions.sig',
  'deployment-inventory.json',
  'release-notes.md',
  'SHA256SUMS.txt',
  'source-provenance.json',
  'transition-0.0.12-beta.1-to-stable-0.0.13-delta.json',
  'transition-0.0.12-beta.1-to-stable-0.0.13-delta.sig',
  'transition-0.0.12-beta.1-to-stable-0.0.13-delta.zip',
  'transition-0.0.12-beta.1-to-stable-0.0.13-full.json',
  'transition-0.0.12-beta.1-to-stable-0.0.13-full.sig',
  'transition-0.0.12-beta.1-to-stable-0.0.13-full.zip',
  'transition-0.0.13-to-beta-0.0.12-beta.1-delta.json',
  'transition-0.0.13-to-beta-0.0.12-beta.1-delta.sig',
  'transition-0.0.13-to-beta-0.0.12-beta.1-delta.zip',
  'transition-0.0.13-to-beta-0.0.12-beta.1-full.json',
  'transition-0.0.13-to-beta-0.0.12-beta.1-full.sig',
  'transition-0.0.13-to-beta-0.0.12-beta.1-full.zip',
];

export function sha256(buffer) {
  return crypto.createHash('sha256').update(buffer).digest('hex').toLowerCase();
}

export function normPath(p) {
  return path.resolve(p).replace(/\\/g, '/').toLowerCase();
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

export function assertPathWithin(childPath, parentPath, label = 'Path') {
  const normChild = normPath(childPath);
  const normParent = normPath(parentPath).replace(/\/+$/, '');
  if (!normChild.startsWith(normParent + '/') && normChild !== normParent) {
    throw new Error(`${label} must reside within ${parentPath}, got: ${childPath}`);
  }
}

export function computeDesktopShellFingerprint(sourceRoot) {
  const output = execFileSync('git', [
    '-C', sourceRoot,
    'ls-files', '-z', '--',
    'electron', 'build', 'package.json', 'package-lock.json', 'scripts/desktop-runtime.json',
  ], { encoding: 'utf8' });

  const paths = output.split('\0').map((p) => p.trim().replace(/\\/g, '/')).filter(Boolean).sort();
  if (paths.length === 0) {
    throw new Error('Tracked desktop shell inputs are missing.');
  }

  const hashes = {};
  for (const rel of paths) {
    const full = path.join(sourceRoot, rel);
    hashes[rel] = sha256(fs.readFileSync(full));
  }

  const jsonStr = JSON.stringify(hashes);
  return sha256(Buffer.from(jsonStr, 'utf8'));
}

export function computeCanonicalShellDigest(filesMapOrExe, maybeAsar) {
  let exeHash;
  let asarHash;
  if (typeof filesMapOrExe === 'string' && typeof maybeAsar === 'string') {
    exeHash = filesMapOrExe;
    asarHash = maybeAsar;
  } else if (filesMapOrExe && typeof filesMapOrExe === 'object') {
    const files = filesMapOrExe.files || filesMapOrExe;
    exeHash = files['POS Desktop.exe']?.sha256 || files['POS Desktop.exe'];
    asarHash = files['resources/app.asar']?.sha256 || files['resources/app.asar'];
  }
  assert.ok(typeof exeHash === 'string' && /^[a-f0-9]{64}$/.test(exeHash), 'Invalid or missing POS Desktop.exe sha256 for canonical shell digest');
  assert.ok(typeof asarHash === 'string' && /^[a-f0-9]{64}$/.test(asarHash), 'Invalid or missing resources/app.asar sha256 for canonical shell digest');

  const canonicalJson = JSON.stringify({
    'POS Desktop.exe': exeHash,
    'resources/app.asar': asarHash,
  });
  return sha256(Buffer.from(canonicalJson, 'utf8'));
}

export function validateRetainedShellProof(retainedShell, label = 'retained_shell') {
  assert.ok(retainedShell && typeof retainedShell === 'object' && !Array.isArray(retainedShell), `${label} must be a non-null object`);
  assert.ok(typeof retainedShell.sha256 === 'string' && /^[a-f0-9]{64}$/.test(retainedShell.sha256), `${label}.sha256 must be a 64-hex lowercase string`);

  const topKeys = Object.keys(retainedShell).sort();
  assert.deepEqual(topKeys, ['files', 'sha256'], `${label} must contain exact keys {files, sha256}, got: ${topKeys.join(', ')}`);

  assert.ok(retainedShell.files && typeof retainedShell.files === 'object' && !Array.isArray(retainedShell.files), `${label}.files must be an object map`);
  const fileKeys = Object.keys(retainedShell.files).sort();
  assert.deepEqual(fileKeys, ['POS Desktop.exe', 'resources/app.asar'].sort(), `${label}.files must contain exact keys {'POS Desktop.exe', 'resources/app.asar'}`);

  for (const fname of ['POS Desktop.exe', 'resources/app.asar']) {
    const meta = retainedShell.files[fname];
    assert.ok(meta && typeof meta === 'object' && !Array.isArray(meta), `${label}.files['${fname}'] must be an object`);
    const metaKeys = Object.keys(meta).sort();
    assert.deepEqual(metaKeys, ['sha256', 'size'], `${label}.files['${fname}'] must contain exact keys {sha256, size}`);
    assert.ok(typeof meta.sha256 === 'string' && /^[a-f0-9]{64}$/.test(meta.sha256), `${label}.files['${fname}'].sha256 must be a 64-hex lowercase string`);
    assert.ok(typeof meta.size === 'number' && Number.isSafeInteger(meta.size) && meta.size > 0, `${label}.files['${fname}'].size must be a positive safe integer`);
  }

  const recomputed = computeCanonicalShellDigest(retainedShell.files);
  assert.equal(retainedShell.sha256, recomputed, `${label}.sha256 must match recomputed canonical 2-hash combined digest`);
}

export function verifyRsaSignature(data, signatureBase64, publicKeyPem) {
  try {
    const rawSig = Buffer.from(signatureBase64.trim(), 'base64');
    return crypto.verify('sha256', Buffer.from(data, 'utf8'), publicKeyPem, rawSig);
  } catch {
    return false;
  }
}

export function assertGhaRunner() {
  assert.equal(
    process.env.GITHUB_ACTIONS,
    'true',
    'Execution is strictly restricted to ephemeral Windows GitHub Actions runner.'
  );
  assert.equal(
    process.platform,
    'win32',
    'Execution is strictly restricted to Windows runner platform.'
  );
  assert.equal(
    process.env.GITHUB_REPOSITORY,
    'ABDO-TECK/pos-desktop',
    'Execution is strictly restricted to repository ABDO-TECK/pos-desktop.'
  );
  assert.ok(
    process.env.GITHUB_RUN_ID && /^\d+$/.test(process.env.GITHUB_RUN_ID),
    `Valid numeric positive GITHUB_RUN_ID is required, got: ${process.env.GITHUB_RUN_ID}`
  );
  assert.ok(
    process.env.GITHUB_SHA && /^[a-f0-9]{40}$/.test(process.env.GITHUB_SHA),
    `Valid 40-hex GITHUB_SHA is required, got: ${process.env.GITHUB_SHA}`
  );
  assert.ok(process.env.RUNNER_TEMP, 'RUNNER_TEMP environment variable is required.');
  assert.ok(fs.existsSync(process.env.RUNNER_TEMP), 'RUNNER_TEMP directory must exist.');
  assertNoReparse(process.env.RUNNER_TEMP);
}

export function validateVerifications(verifications) {
  assert.ok(verifications && typeof verifications === 'object', 'verifications must be an object');
  const actualKeys = Object.keys(verifications).sort();
  const expectedKeys = REQUIRED_13_VERIFICATIONS.slice().sort();
  assert.deepEqual(actualKeys, expectedKeys, 'verifications keyset must match REQUIRED_13_VERIFICATIONS exactly (no extra keys or aliases)');
  for (const k of expectedKeys) {
    assert.equal(verifications[k], true, `Verification boolean '${k}' must be true`);
  }
}

export function parseStrictSha256Sums(content, expectedFiles) {
  assert.ok(typeof content === 'string', 'SHA256SUMS content must be a string');
  assert.ok(Array.isArray(expectedFiles), 'expectedFiles must be an array');
  const lines = content.trim().split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  assert.equal(
    lines.length,
    expectedFiles.length,
    `SHA256SUMS.txt line count must be exactly ${expectedFiles.length}, got: ${lines.length}`
  );

  const checksumMap = new Map();
  const lowerExpected = new Map(expectedFiles.map((f) => [f.toLowerCase(), f]));

  for (const line of lines) {
    const match = line.match(/^([a-fA-F0-9]{64})\s+\*?([^\r\n]+)$/);
    assert.ok(match, `Malformed line in SHA256SUMS.txt: '${line}'`);
    const hash = match[1].toLowerCase();
    const fname = match[2].trim();

    assert.ok(!fname.includes('/') && !fname.includes('\\') && !fname.includes('..'), `Path traversal forbidden in checksum file: '${fname}'`);
    assert.notEqual(fname, 'SHA256SUMS.txt', 'SHA256SUMS.txt must not contain a self-reference');
    assert.ok(lowerExpected.has(fname.toLowerCase()), `Unexpected file listed in SHA256SUMS.txt: '${fname}'`);
    assert.ok(!checksumMap.has(fname), `Duplicate filename listed in SHA256SUMS.txt: '${fname}'`);
    checksumMap.set(fname, hash);
  }

  assert.equal(checksumMap.size, expectedFiles.length, `SHA256SUMS.txt must uniquely cover all ${expectedFiles.length} files`);
  return checksumMap;
}

export function validateSetupDirectory(setupArtifactDir) {
  assert.ok(fs.existsSync(setupArtifactDir), `Setup artifact directory missing: ${setupArtifactDir}`);
  assertNoReparse(setupArtifactDir);

  const rawEntries = fs.readdirSync(setupArtifactDir);
  assert.equal(rawEntries.length, 5, `Setup artifact directory must contain exact 5 entries, got ${rawEntries.length}: ${rawEntries.join(', ')}`);

  const lowerSet = new Set();
  for (const entry of rawEntries) {
    const fullPath = path.join(setupArtifactDir, entry);
    const st = fs.lstatSync(fullPath);
    assert.ok(st.isFile() && !st.isSymbolicLink(), `Setup entry '${entry}' must be a regular file, not a directory or symlink`);
    const lower = entry.toLowerCase();
    assert.ok(!lowerSet.has(lower), `Duplicate casefold entry detected in setup artifact directory: '${entry}'`);
    lowerSet.add(lower);
  }

  assert.deepEqual(rawEntries.slice().sort(), EXACT_5_SETUP_ASSETS.slice().sort(), 'Setup directory files list mismatch against EXACT_5_SETUP_ASSETS');

  const checksumsPath = path.join(setupArtifactDir, 'SHA256SUMS.txt');
  const checksumsRaw = fs.readFileSync(checksumsPath, 'utf8');
  const expectedSumFiles = EXACT_5_SETUP_ASSETS.filter((f) => f !== 'SHA256SUMS.txt');
  const checksumMap = parseStrictSha256Sums(checksumsRaw, expectedSumFiles);

  const setupAssets = {};
  for (const fname of EXACT_5_SETUP_ASSETS) {
    const fpath = path.join(setupArtifactDir, fname);
    const bytes = fs.readFileSync(fpath);
    assert.ok(bytes.length > 0, `Setup artifact file ${fname} cannot be empty`);
    const fileHash = sha256(bytes);

    if (fname !== 'SHA256SUMS.txt') {
      assert.ok(checksumMap.has(fname), `Setup SHA256SUMS.txt missing entry for ${fname}`);
      assert.equal(fileHash, checksumMap.get(fname), `Setup SHA256 mismatch for ${fname}`);
    }

    setupAssets[fname] = {
      sha256: fileHash,
      size: bytes.length,
    };
  }

  // Validate latest.yml content
  const latestYmlContent = fs.readFileSync(path.join(setupArtifactDir, 'latest.yml'), 'utf8');
  assert.match(latestYmlContent, /version:\s*0\.0\.13/, 'latest.yml must declare version 0.0.13');
  assert.match(latestYmlContent, /path:\s*POS-Desktop-Setup-0\.0\.13\.exe/, 'latest.yml must reference POS-Desktop-Setup-0.0.13.exe');

  // Validate deployment-inventory.json content
  const invRaw = fs.readFileSync(path.join(setupArtifactDir, 'deployment-inventory.json'), 'utf8');
  const invContent = JSON.parse(invRaw);
  assert.equal(invContent.version, '0.0.13', 'deployment-inventory.json version must be 0.0.13');
  assert.equal(invContent.format, 1, 'deployment-inventory.json format must be 1');

  return { setupAssets, invContent, invRaw };
}

export function validateBindingReceipt(receipt, expected) {
  assert.ok(receipt && typeof receipt === 'object', 'Binding receipt must be an object');
  assert.equal(receipt.schema_version, '1.0.0', 'Binding receipt schema_version must be 1.0.0');
  assert.equal(typeof receipt.setup_run_id, 'string', 'Binding receipt setup_run_id must be a string');
  assert.equal(receipt.setup_run_id, String(expected.setupRunId), `Binding receipt setup_run_id mismatch (${receipt.setup_run_id} vs ${expected.setupRunId})`);
  assert.equal(typeof receipt.acceptance_run_id, 'string', 'Binding receipt acceptance_run_id must be a string');
  assert.equal(receipt.acceptance_run_id, String(expected.acceptanceRunId), `Binding receipt acceptance_run_id mismatch (${receipt.acceptance_run_id} vs ${expected.acceptanceRunId})`);
  assert.ok(typeof receipt.source_sha === 'string' && /^[a-f0-9]{40}$/.test(receipt.source_sha), 'Binding receipt source_sha must be 40-hex lowercase');
  assert.equal(receipt.source_sha, expected.sourceSha, `Binding receipt source_sha mismatch (${receipt.source_sha} vs ${expected.sourceSha})`);
  assert.ok(typeof receipt.run_token === 'string' && /^[a-f0-9]{32}$/.test(receipt.run_token), 'Binding receipt run_token must be a 32-hex lowercase string');
  assert.equal(receipt.run_token, expected.runToken, `Binding receipt run_token mismatch (${receipt.run_token} vs ${expected.runToken})`);
  assert.ok(typeof receipt.installer_sha256 === 'string' && /^[a-f0-9]{64}$/.test(receipt.installer_sha256), 'Binding receipt installer_sha256 must be 64-hex lowercase');
  assert.equal(receipt.installer_sha256, expected.installerSha, `Binding receipt installer_sha256 mismatch (${receipt.installer_sha256} vs ${expected.installerSha})`);

  assert.ok(receipt.setup_assets && typeof receipt.setup_assets === 'object', 'Binding receipt missing setup_assets');
  assert.deepEqual(Object.keys(receipt.setup_assets).sort(), EXACT_5_SETUP_ASSETS.slice().sort(), 'Binding receipt setup_assets keys mismatch');

  for (const [fn, meta] of Object.entries(receipt.setup_assets)) {
    assert.equal('name' in meta, false, `setup_assets[${fn}] must not contain 'name' property`);
    assert.ok(meta.sha256 && meta.size !== undefined, `setup_assets[${fn}] missing sha256 or size`);
    if (expected.setupAssetsMap && expected.setupAssetsMap[fn]) {
      assert.equal(meta.sha256, expected.setupAssetsMap[fn].sha256, `setup_assets[${fn}] hash mismatch against expected`);
      assert.equal(meta.size, expected.setupAssetsMap[fn].size, `setup_assets[${fn}] size mismatch against expected`);
    }
  }

  // Validate retained_shell proof in binding receipt
  assert.ok(receipt.retained_shell, 'Binding receipt missing retained_shell proof object');
  validateRetainedShellProof(receipt.retained_shell, 'Binding receipt retained_shell');
  if (expected.retainedShell) {
    assert.deepEqual(receipt.retained_shell, expected.retainedShell, 'Binding receipt retained_shell mismatch against expected');
  }
}

export function validateCarrierDirectory(carrierDir, stagingManifest) {
  assert.ok(fs.existsSync(carrierDir), `Carrier directory missing: ${carrierDir}`);
  assertNoReparse(carrierDir);

  const rawEntries = fs.readdirSync(carrierDir);
  assert.equal(
    rawEntries.length,
    18,
    `Carrier directory must contain exact 18 files, got ${rawEntries.length}: ${rawEntries.join(', ')}`
  );

  const lowerSet = new Set();
  const forbiddenPattern = /(auth\.json|\.env|private|key|\.pem|\.key$|\.db$|\.sqlite$|owned-lab|\.carrier-staging-manifest)/i;
  for (const entry of rawEntries) {
    if (forbiddenPattern.test(entry) && !entry.endsWith('.sig')) {
      throw new Error(`Forbidden sensitive or hidden file detected in carrier directory: ${entry}`);
    }
    const fullPath = path.join(carrierDir, entry);
    const st = fs.lstatSync(fullPath);
    assert.ok(st.isFile() && !st.isSymbolicLink(), `Carrier entry '${entry}' must be a regular file, not a directory or symlink`);
    const lower = entry.toLowerCase();
    assert.ok(!lowerSet.has(lower), `Duplicate casefold entry detected in carrier directory: '${entry}'`);
    lowerSet.add(lower);
  }

  assert.deepEqual(rawEntries.slice().sort(), EXACT_18_CARRIER_FILES.slice().sort(), 'Carrier directory files list mismatch against EXACT_18_CARRIER_FILES');

  const carrierArtifacts = {};
  for (const fname of EXACT_18_CARRIER_FILES) {
    const meta = stagingManifest.carrier_artifacts?.[fname];
    assert.ok(meta, `Staging manifest missing entry for carrier file: ${fname}`);

    const fpath = path.join(carrierDir, fname);
    const bytes = fs.readFileSync(fpath);
    assert.equal(sha256(bytes), meta.sha256, `Byte hash mismatch for staged carrier file: ${fname}`);
    assert.equal(bytes.length, meta.size, `Byte size mismatch for staged carrier file: ${fname}`);

    carrierArtifacts[fname] = {
      sha256: meta.sha256,
      size: meta.size,
    };
  }

  // Parse carrier SHA256SUMS.txt
  const carrierSumsPath = path.join(carrierDir, 'SHA256SUMS.txt');
  const carrierSumsRaw = fs.readFileSync(carrierSumsPath, 'utf8');
  const expectedCarrierSumFiles = EXACT_18_CARRIER_FILES.filter((f) => f !== 'SHA256SUMS.txt');
  const carrierSumMap = parseStrictSha256Sums(carrierSumsRaw, expectedCarrierSumFiles);

  for (const fname of expectedCarrierSumFiles) {
    assert.equal(carrierArtifacts[fname].sha256, carrierSumMap.get(fname), `Carrier SHA256SUMS.txt hash mismatch for ${fname}`);
  }

  return carrierArtifacts;
}

export function validateTransitionDescriptor(descData, expected) {
  assert.equal(descData.format, 1, 'Descriptor format must be 1');
  assert.equal(descData.operation, 'channel_transition', 'Descriptor operation must be channel_transition');
  assert.equal(descData.carrier_tag, 'v0.0.13', 'Descriptor carrier_tag must be v0.0.13');
  assert.equal(descData.target_repo, 'pos-desktop-updates', 'Descriptor target_repo must be pos-desktop-updates');
  assert.ok(!('carrier_repo' in descData), 'Descriptor must NOT contain deprecated carrier_repo field');

  // Verify package
  assert.ok(descData.package && descData.package.asset, 'Descriptor missing package.asset');
  if (expected.zipName) {
    assert.equal(descData.package.asset, expected.zipName, 'Package asset name mismatch');
  }
  if (expected.zipBytes) {
    assert.equal(descData.package.sha256, sha256(expected.zipBytes), 'Package sha256 mismatch against zip archive bytes');
    assert.equal(descData.package.size, expected.zipBytes.length, 'Package size mismatch against zip archive bytes');
  }

  // Verify expected shell hashes requirements
  assert.ok(
    typeof expected.shellHash === 'string' && /^[a-f0-9]{64}$/.test(expected.shellHash),
    'expected.shellHash must be a 64-hex lowercase string'
  );
  assert.ok(
    typeof expected.sourceShellFingerprint === 'string' && /^[a-f0-9]{64}$/.test(expected.sourceShellFingerprint),
    'expected.sourceShellFingerprint must be a 64-hex lowercase string'
  );

  // Verify shell fingerprint / physical digest
  assert.equal(descData.shell_sha256, expected.shellHash, 'Descriptor shell_sha256 mismatch against physical retained shell digest');

  // Verify direction and inventory deep equality
  if (descData.from_version === '0.0.13' && descData.version === '0.0.12-beta.1') {
    assert.equal(descData.from_channel, 'stable');
    assert.equal(descData.channel, 'beta');
    assert.equal(descData.source_inventory_sha256, expected.canonicalSetupInvHash, 'Setup 13 source_inventory_sha256 mismatch');
    assert.deepEqual(descData.source_inventory?.files, expected.canonicalSetupFiles, 'Setup 13 source_inventory.files deep equality mismatch');
    assert.equal(descData.source_inventory?.shell_sha256, expected.sourceShellFingerprint, 'Setup 13 source_inventory.shell_sha256 mismatch against source tree fingerprint');
    assert.equal(descData.target_inventory_sha256, expected.beta12InvHash, 'Beta 12.1 target_inventory_sha256 mismatch');
    assert.equal(descData.target_inventory?.version, '0.0.12-beta.1');
  } else if (descData.from_version === '0.0.12-beta.1' && descData.version === '0.0.13') {
    assert.equal(descData.from_channel, 'beta');
    assert.equal(descData.channel, 'stable');
    assert.equal(descData.source_inventory_sha256, expected.beta12InvHash, 'Beta 12.1 source_inventory_sha256 mismatch');
    assert.equal(descData.source_inventory?.version, '0.0.12-beta.1');
    assert.equal(descData.target_inventory_sha256, expected.canonicalSetupInvHash, 'Setup 13 target_inventory_sha256 mismatch');
    assert.deepEqual(descData.target_inventory?.files, expected.canonicalSetupFiles, 'Setup 13 target_inventory.files deep equality mismatch');
    assert.equal(descData.target_inventory?.shell_sha256, expected.sourceShellFingerprint, 'Setup 13 target_inventory.shell_sha256 mismatch against source tree fingerprint');
  } else {
    throw new Error(`Unexpected transition direction: ${descData.from_version} -> ${descData.version}`);
  }

  // Verify data_compatibility contract
  const dc = descData.data_compatibility;
  assert.ok(dc && typeof dc === 'object', 'Descriptor missing data_compatibility object');
  assert.equal(dc.format, 1, 'data_compatibility format must be 1');
  assert.equal(dc.metadata_migration, '059_add_update_compatibility_metadata.sql', 'data_compatibility metadata_migration must match the production contract');
  assert.match(dc.schema_sha256, /^[a-f0-9]{64}$/, 'data_compatibility schema_sha256 must be 64-hex');
  assert.match(dc.ledger_sha256, /^[a-f0-9]{64}$/, 'data_compatibility ledger_sha256 must be 64-hex');
}

export function validateTransitionIndex(indexData, publicKeyPem, rawIndex, rawSig) {
  assert.ok(
    verifyRsaSignature(rawIndex, rawSig, publicKeyPem),
    'RSA signature verification failed for channel-transitions.json'
  );

  assert.equal(indexData.format, 1, 'channel-transitions.json format must be 1');
  assert.equal(indexData.carrier_tag, 'v0.0.13', 'channel-transitions.json carrier_tag must be v0.0.13');
  assert.ok(Array.isArray(indexData.entries), 'channel-transitions.json entries must be an array');
  assert.equal(indexData.entries.length, 4, `channel-transitions.json must contain exactly 4 transition entries, got: ${indexData.entries.length}`);

  const EXPECTED_PAIRS = new Set([
    '0.0.13->0.0.12-beta.1:delta',
    '0.0.13->0.0.12-beta.1:full',
    '0.0.12-beta.1->0.0.13:delta',
    '0.0.12-beta.1->0.0.13:full',
  ]);

  const seenPairs = new Set();
  for (const entry of indexData.entries) {
    const pairKey = `${entry.from_version}->${entry.version}:${entry.type}`;
    assert.ok(EXPECTED_PAIRS.has(pairKey), `Unexpected transition direction pair: ${pairKey}`);
    assert.ok(!seenPairs.has(pairKey), `Duplicate transition direction pair: ${pairKey}`);
    seenPairs.add(pairKey);
  }
  assert.equal(seenPairs.size, 4, 'channel-transitions.json must cover all 4 unique direction type pairs');
}

export function stageCarrierAssets(options = {}) {
  console.log('[Evidence Finalizer] Stage Mode: Staging tested production channel carrier...');
  assertGhaRunner();

  const runnerTemp = path.resolve(process.env.RUNNER_TEMP);
  const lab = options.lab || path.join(runnerTemp, 'setup12-regression-lab');
  const evidence = options.evidence || path.join(runnerTemp, 'setup12-regression-evidence');
  const assets = options.assets || path.join(lab, 'assets');
  const setupArtifactDir = options.setupArtifactDir || path.join(runnerTemp, 'setup13-artifact');
  const carrierDir = options.carrierDir || path.join(runnerTemp, 'setup13-channel-carrier');
  const repoRoot = options.repoRoot || process.cwd();
  const setupRunId = options.setupRunId || process.env.SETUP_RUN_ID;
  const acceptanceRunId = options.acceptanceRunId || process.env.GITHUB_RUN_ID;

  assert.ok(setupRunId && /^\d+$/.test(setupRunId), `Valid numeric setup_run_id required, got: ${setupRunId}`);
  assert.ok(acceptanceRunId && /^\d+$/.test(acceptanceRunId), `Valid numeric acceptance_run_id required, got: ${acceptanceRunId}`);
  assert.equal(String(acceptanceRunId), String(process.env.GITHUB_RUN_ID), 'acceptance_run_id must match GITHUB_RUN_ID');

  // 1. Validate real git HEAD against actual GITHUB_SHA
  const gitHead = execFileSync('git', ['-C', repoRoot, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim().toLowerCase();
  assert.equal(
    gitHead,
    process.env.GITHUB_SHA.toLowerCase(),
    `git HEAD (${gitHead}) does not match GITHUB_SHA (${process.env.GITHUB_SHA})`
  );
  const sourceSha = gitHead;

  // 2. Validate candidate-results.json in lab
  const candidateResultsPath = path.join(lab, 'candidate-results.json');
  assert.ok(fs.existsSync(candidateResultsPath), `candidate-results.json missing at: ${candidateResultsPath}`);
  assertNoReparse(candidateResultsPath);

  const cRes = JSON.parse(fs.readFileSync(candidateResultsPath, 'utf8'));
  assert.ok(cRes.run_token, 'candidate-results.json missing run_token');
  validateVerifications(cRes.verifications);
  console.log('      PASS: All 13 acceptance regression verifications verified true in candidate-results.json');

  // 3. Validate setup13-binding-receipt.json in evidence
  const bindingReceiptPath = path.join(evidence, 'setup13-binding-receipt.json');
  assert.ok(fs.existsSync(bindingReceiptPath), `setup13-binding-receipt.json is required at: ${bindingReceiptPath}`);
  assertNoReparse(bindingReceiptPath);

  const receiptRaw = fs.readFileSync(bindingReceiptPath, 'utf8');
  const receipt = JSON.parse(receiptRaw);
  validateBindingReceipt(receipt, {
    setupRunId,
    acceptanceRunId,
    sourceSha,
    runToken: cRes.run_token,
    installerSha: receipt.installer_sha256,
  });
  const stagedReceiptHash = sha256(Buffer.from(receiptRaw, 'utf8'));
  console.log('      PASS: setup13-binding-receipt.json validated and hashed before staging.');

  // 3b. Verify candidate unpacked physical shell bytes match receipt.retained_shell before cleanup
  const candidateUnpacked = options.candidateShellDir
    ? path.resolve(options.candidateShellDir)
    : path.join(lab, 'candidate-unpacked');
  assert.ok(fs.existsSync(candidateUnpacked), `candidate-unpacked directory missing at: ${candidateUnpacked}`);
  assertNoReparse(candidateUnpacked);

  const candidateExe = path.join(candidateUnpacked, 'POS Desktop.exe');
  const candidateAsar = path.join(candidateUnpacked, 'resources/app.asar');
  assert.ok(fs.existsSync(candidateExe), `Candidate POS Desktop.exe missing at: ${candidateExe}`);
  assert.ok(fs.existsSync(candidateAsar), `Candidate resources/app.asar missing at: ${candidateAsar}`);
  assertNoReparse(candidateExe);
  assertNoReparse(candidateAsar);

  const exeBytes = fs.readFileSync(candidateExe);
  const asarBytes = fs.readFileSync(candidateAsar);
  const exeHash = sha256(exeBytes);
  const asarHash = sha256(asarBytes);

  assert.equal(exeHash, receipt.retained_shell.files['POS Desktop.exe'].sha256, 'Candidate POS Desktop.exe sha256 mismatch against receipt');
  assert.equal(exeBytes.length, receipt.retained_shell.files['POS Desktop.exe'].size, 'Candidate POS Desktop.exe size mismatch against receipt');
  assert.equal(asarHash, receipt.retained_shell.files['resources/app.asar'].sha256, 'Candidate resources/app.asar sha256 mismatch against receipt');
  assert.equal(asarBytes.length, receipt.retained_shell.files['resources/app.asar'].size, 'Candidate resources/app.asar size mismatch against receipt');

  const stagedPhysicalDigest = computeCanonicalShellDigest(exeHash, asarHash);
  assert.equal(receipt.retained_shell.sha256, stagedPhysicalDigest, 'receipt.retained_shell.sha256 mismatch against candidate shell bytes');
  console.log('      PASS: Verified candidate unpacked physical shell bytes against binding receipt before staging.');

  // 4. Validate carrier staging directory strictly within RUNNER_TEMP and FAIL if pre-existing
  assertNoReparse(runnerTemp);
  assertPathWithin(carrierDir, runnerTemp, 'Carrier staging directory');
  assertNoReparse(path.dirname(carrierDir));

  if (fs.existsSync(carrierDir) || fs.lstatSync(carrierDir, { throwIfNoEntry: false })) {
    throw new Error(`Carrier staging directory already exists before staging ownership: ${carrierDir}`);
  }
  fs.mkdirSync(carrierDir);
  assertNoReparse(carrierDir);

  // 5. Stage verified positive carrier files from assets/pos-desktop-updates
  const updatesSourceDir = path.join(assets, 'pos-desktop-updates');
  assert.ok(fs.existsSync(updatesSourceDir), `pos-desktop-updates assets directory missing: ${updatesSourceDir}`);
  assertNoReparse(updatesSourceDir);

  const carrierArtifactsMap = {};

  for (const fname of REQUIRED_POSITIVE_CARRIER_FILES) {
    const srcFile = path.join(updatesSourceDir, fname);
    assert.ok(fs.existsSync(srcFile), `Required tested carrier file missing in assets: ${fname}`);
    assertNoReparse(srcFile);

    const srcBytes = fs.readFileSync(srcFile);
    assert.ok(srcBytes.length > 0, `Carrier asset ${fname} cannot be empty`);
    const srcHash = sha256(srcBytes);

    const dstFile = path.join(carrierDir, fname);
    fs.copyFileSync(srcFile, dstFile);

    const dstBytes = fs.readFileSync(dstFile);
    assert.equal(sha256(dstBytes), srcHash, `Hash mismatch after staging carrier file: ${fname}`);
    assert.equal(dstBytes.length, srcBytes.length, `Size mismatch after staging carrier file: ${fname}`);

    carrierArtifactsMap[fname] = {
      sha256: srcHash,
      size: dstBytes.length,
    };
  }
  console.log(`      PASS: Staged ${REQUIRED_POSITIVE_CARRIER_FILES.length} verified transition files with identical bytes.`);

  // 6. Stage deployment-inventory.json from setup-artifact-dir
  const setupInvSrc = path.join(setupArtifactDir, 'deployment-inventory.json');
  assert.ok(fs.existsSync(setupInvSrc), `Setup deployment-inventory.json missing at: ${setupInvSrc}`);
  assertNoReparse(setupInvSrc);

  const setupInvBytes = fs.readFileSync(setupInvSrc);
  const setupInv = JSON.parse(setupInvBytes.toString('utf8'));
  assert.equal(setupInv.version, '0.0.13', 'deployment-inventory.json version must be 0.0.13');
  assert.equal(setupInv.format, 1, 'deployment-inventory.json format must be 1');

  const dstInvPath = path.join(carrierDir, 'deployment-inventory.json');
  fs.copyFileSync(setupInvSrc, dstInvPath);
  const dstInvBytes = fs.readFileSync(dstInvPath);
  assert.equal(sha256(dstInvBytes), sha256(setupInvBytes), 'deployment-inventory.json copy hash mismatch');
  assert.equal(dstInvBytes.length, setupInvBytes.length, 'deployment-inventory.json copy size mismatch');

  carrierArtifactsMap['deployment-inventory.json'] = {
    sha256: sha256(dstInvBytes),
    size: dstInvBytes.length,
  };
  console.log('      PASS: Staged baseline Setup 0.0.13 deployment-inventory.json.');

  // 7. Generate source-provenance.json
  const sourceProvenance = {
    schema_version: '1.0.0',
    release_tag: 'v0.0.13',
    target_version: '0.0.13',
    channel: 'stable',
    carrier_repository: 'ABDO-TECK/pos-desktop-updates',
    source_repository: 'ABDO-TECK/pos-desktop',
    source_commit: sourceSha,
    setup_run_id: String(setupRunId),
    acceptance_run_id: String(acceptanceRunId),
    supported_baselines: ['v0.0.12-beta.1'],
    carrier_type: 'channel-transition',
    retained_shell: receipt.retained_shell,
    built_at: new Date().toISOString(),
  };

  const provJsonPath = path.join(carrierDir, 'source-provenance.json');
  fs.writeFileSync(provJsonPath, JSON.stringify(sourceProvenance, null, 2) + '\n', 'utf8');
  const provBytes = fs.readFileSync(provJsonPath);
  carrierArtifactsMap['source-provenance.json'] = {
    sha256: sha256(provBytes),
    size: provBytes.length,
  };
  console.log('      PASS: Generated source-provenance.json for carrier.');

  // 8. Generate release-notes.md
  const releaseNotesContent = `# POS Desktop 0.0.13 Release Notes & Transition Carrier

## Native Setup 0.0.13 Upgrade
Setup 0.0.13 introduces updated native Electron shell runtime components. Upgrades from earlier versions (such as Setup 0.0.12) require installing the full native setup package (\`POS-Desktop-Setup-0.0.13.exe\`). This update is **not** an ordinary delta upgrade and cannot be delivered as an application-only delta archive due to native shell binary and runtime requirements.

## Signed Channel Transition Carrier
This release asset carrier on \`ABDO-TECK/pos-desktop-updates\` provides cryptographically signed channel transition packages between Stable 0.0.13 and Beta 0.0.12-beta.1. These transition carriers enable verified bi-directional channel switching directly within POS Desktop:
- Stable 0.0.13 -> Beta 0.0.12-beta.1 (Delta & Full transition bundles)
- Beta 0.0.12-beta.1 -> Stable 0.0.13 (Delta & Full transition bundles)

All transition descriptors, manifests, and index signatures are signed with the project release private key and validated against \`backend/certs/update_public_key.pem\`.
`;

  const notesPath = path.join(carrierDir, 'release-notes.md');
  fs.writeFileSync(notesPath, releaseNotesContent, 'utf8');
  const notesBytes = fs.readFileSync(notesPath);
  carrierArtifactsMap['release-notes.md'] = {
    sha256: sha256(notesBytes),
    size: notesBytes.length,
  };
  console.log('      PASS: Generated release-notes.md.');

  // 9. Generate SHA256SUMS.txt covering all 17 staged carrier assets
  const filesToHash = Object.keys(carrierArtifactsMap).sort();
  assert.equal(filesToHash.length, 17, `Staging manifest must contain exact 17 files prior to checksum file generation, got: ${filesToHash.length}`);
  const checksumLines = filesToHash.map((fn) => `${carrierArtifactsMap[fn].sha256}  ${fn}`);
  const checksumsPath = path.join(carrierDir, 'SHA256SUMS.txt');
  fs.writeFileSync(checksumsPath, checksumLines.join('\n') + '\n', 'utf8');
  const checksumsBytes = fs.readFileSync(checksumsPath);
  carrierArtifactsMap['SHA256SUMS.txt'] = {
    sha256: sha256(checksumsBytes),
    size: checksumsBytes.length,
  };
  console.log(`      PASS: Generated SHA256SUMS.txt covering ${filesToHash.length} staged carrier files.`);

  // 10. Assert exact 18 files in carrier directory and NO hidden or extra files
  const actualEntries = fs.readdirSync(carrierDir).sort();
  assert.equal(actualEntries.length, 18, `Carrier directory must contain exact 18 files, got ${actualEntries.length}`);
  assert.deepEqual(actualEntries, EXACT_18_CARRIER_FILES.slice().sort(), 'Carrier directory contents mismatch against EXACT_18_CARRIER_FILES');

  // 11. Write staging receipt in evidence directory ONLY (never inside carrierDir payload)
  const stagingManifest = {
    schema_version: '1.0.0',
    setup_run_id: String(setupRunId),
    acceptance_run_id: String(acceptanceRunId),
    source_sha: sourceSha,
    run_token: cRes.run_token,
    binding_receipt_sha256: stagedReceiptHash,
    retained_shell: receipt.retained_shell,
    carrier_artifacts: carrierArtifactsMap,
    staged_at: new Date().toISOString(),
  };

  if (!fs.existsSync(evidence)) {
    fs.mkdirSync(evidence, { recursive: true });
  }
  fs.writeFileSync(
    path.join(evidence, 'carrier-staging-manifest.json'),
    JSON.stringify(stagingManifest, null, 2) + '\n',
    'utf8'
  );

  console.log(`[Evidence Finalizer] Stage Mode complete. ${Object.keys(carrierArtifactsMap).length} carrier files staged safely before cleanup.`);
}

export function finalizeReleaseEvidence(options = {}) {
  console.log('[Evidence Finalizer] Finalize Mode: Verifying acceptance, cleanup, and provenance...');
  assertGhaRunner();

  const runnerTemp = path.resolve(process.env.RUNNER_TEMP);
  const evidence = options.evidence || path.join(runnerTemp, 'setup12-regression-evidence');
  const setupArtifactDir = options.setupArtifactDir || path.join(runnerTemp, 'setup13-artifact');
  const carrierDir = options.carrierDir || path.join(runnerTemp, 'setup13-channel-carrier');
  const repoRoot = options.repoRoot || process.cwd();
  const setupRunId = options.setupRunId || process.env.SETUP_RUN_ID;
  const acceptanceRunId = options.acceptanceRunId || process.env.GITHUB_RUN_ID;

  assert.ok(setupRunId && /^\d+$/.test(setupRunId), `Valid numeric setup_run_id required, got: ${setupRunId}`);
  assert.ok(acceptanceRunId && /^\d+$/.test(acceptanceRunId), `Valid numeric acceptance_run_id required, got: ${acceptanceRunId}`);
  assert.equal(String(acceptanceRunId), String(process.env.GITHUB_RUN_ID), 'acceptance_run_id must match GITHUB_RUN_ID');

  // 1. Validate real git HEAD against actual GITHUB_SHA
  const gitHead = execFileSync('git', ['-C', repoRoot, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim().toLowerCase();
  assert.equal(
    gitHead,
    process.env.GITHUB_SHA.toLowerCase(),
    `git HEAD (${gitHead}) does not match GITHUB_SHA (${process.env.GITHUB_SHA})`
  );
  const sourceSha = gitHead;

  // 2. Read and validate carrier-staging-manifest.json from evidence
  const evidenceManifestPath = path.join(evidence, 'carrier-staging-manifest.json');
  assert.ok(fs.existsSync(evidenceManifestPath), `Carrier staging manifest missing in evidence directory: ${evidenceManifestPath}`);
  assertNoReparse(evidenceManifestPath);
  const stagingManifest = JSON.parse(fs.readFileSync(evidenceManifestPath, 'utf8'));

  assert.equal(stagingManifest.schema_version, '1.0.0');
  assert.equal(String(stagingManifest.setup_run_id), String(setupRunId));
  assert.equal(String(stagingManifest.acceptance_run_id), String(acceptanceRunId));
  assert.equal(stagingManifest.source_sha?.toLowerCase(), sourceSha);
  assert.ok(stagingManifest.retained_shell, 'stagingManifest missing retained_shell proof object');
  validateRetainedShellProof(stagingManifest.retained_shell, 'stagingManifest.retained_shell');

  // 3. Read and validate acceptance.json
  const acceptanceJsonPath = path.join(evidence, 'acceptance.json');
  assert.ok(fs.existsSync(acceptanceJsonPath), `acceptance.json missing at: ${acceptanceJsonPath}`);
  assertNoReparse(acceptanceJsonPath);

  const acceptance = JSON.parse(fs.readFileSync(acceptanceJsonPath, 'utf8'));
  assert.equal(acceptance.verdict, 'PASSED', `acceptance.json verdict must be PASSED, got: ${acceptance.verdict}`);
  assert.equal(acceptance.cleanup_verified, true, 'acceptance.json cleanup_verified must be true');
  assert.equal(String(acceptance.run_id), String(acceptanceRunId), `acceptance.json run_id mismatch (${acceptance.run_id} vs ${acceptanceRunId})`);
  assert.equal(acceptance.run_token, stagingManifest.run_token, `acceptance.json run_token mismatch against staging manifest (${acceptance.run_token} vs ${stagingManifest.run_token})`);
  validateVerifications(acceptance.verifications);
  console.log('      PASS: acceptance.json verified with verdict PASSED, cleanup verified, and all 13 checks TRUE.');

  // 4. Validate setup13-binding-receipt.json in evidence
  const bindingReceiptPath = path.join(evidence, 'setup13-binding-receipt.json');
  assert.ok(fs.existsSync(bindingReceiptPath), `setup13-binding-receipt.json is required at: ${bindingReceiptPath}`);
  assertNoReparse(bindingReceiptPath);

  const receiptRaw = fs.readFileSync(bindingReceiptPath, 'utf8');
  assert.equal(
    sha256(Buffer.from(receiptRaw, 'utf8')),
    stagingManifest.binding_receipt_sha256,
    'setup13-binding-receipt.json was modified after carrier staging phase'
  );
  const receipt = JSON.parse(receiptRaw);
  validateBindingReceipt(receipt, {
    setupRunId,
    acceptanceRunId,
    sourceSha,
    runToken: stagingManifest.run_token,
    installerSha: receipt.installer_sha256,
    retainedShell: stagingManifest.retained_shell,
  });
  validateRetainedShellProof(receipt.retained_shell, 'receipt.retained_shell');
  assert.deepEqual(receipt.retained_shell, stagingManifest.retained_shell, 'receipt.retained_shell mismatch against staging manifest retained_shell');

  const recomputedPhysicalDigest = computeCanonicalShellDigest(receipt.retained_shell.files);
  assert.equal(receipt.retained_shell.sha256, recomputedPhysicalDigest, 'receipt.retained_shell.sha256 mismatch against recomputed canonical digest');
  console.log('      PASS: Verified setup13-binding-receipt.json immutable evidence.');

  // 5. Validate Setup Artifacts in setup-artifact-dir
  const { setupAssets, invContent, invRaw } = validateSetupDirectory(setupArtifactDir);
  assert.equal(
    setupAssets['POS-Desktop-Setup-0.0.13.exe'].sha256,
    receipt.installer_sha256,
    'Setup installer SHA mismatch against binding receipt installer_sha256'
  );
  validateBindingReceipt(receipt, {
    setupRunId,
    acceptanceRunId,
    sourceSha,
    runToken: stagingManifest.run_token,
    installerSha: receipt.installer_sha256,
    setupAssetsMap: setupAssets,
    retainedShell: stagingManifest.retained_shell,
  });

  const canonicalSetupInvHash = sha256(Buffer.from(invRaw, 'utf8'));
  const canonicalSetupFiles = invContent.files;
  const expectedShellHash = computeDesktopShellFingerprint(repoRoot);
  assert.equal(
    invContent.shell_sha256,
    expectedShellHash,
    `deployment-inventory.json shell_sha256 mismatch against source tree (${invContent.shell_sha256} vs ${expectedShellHash})`
  );
  console.log('      PASS: Setup 5-asset cryptographic binding and shell fingerprint verified.');

  // 6. Validate Carrier Artifacts in carrierDir
  const carrierArtifacts = validateCarrierDirectory(carrierDir, stagingManifest);

  const carrierSourceProv = JSON.parse(fs.readFileSync(path.join(carrierDir, 'source-provenance.json'), 'utf8'));
  validateRetainedShellProof(carrierSourceProv.retained_shell, 'carrier source-provenance.json retained_shell');
  assert.deepEqual(carrierSourceProv.retained_shell, receipt.retained_shell, 'carrier source-provenance.json retained_shell mismatch against binding receipt');

  // 7. Verify RSA signatures and transition descriptors
  const publicKeyPath = path.join(repoRoot, 'backend/certs/update_public_key.pem');
  assert.ok(fs.existsSync(publicKeyPath), `Update public key missing: ${publicKeyPath}`);
  const publicKeyPem = fs.readFileSync(publicKeyPath, 'utf8');

  const indexJsonRaw = fs.readFileSync(path.join(carrierDir, 'channel-transitions.json'), 'utf8');
  const indexSigRaw = fs.readFileSync(path.join(carrierDir, 'channel-transitions.sig'), 'utf8');
  const indexData = JSON.parse(indexJsonRaw);

  validateTransitionIndex(indexData, publicKeyPem, indexJsonRaw, indexSigRaw);

  for (const entry of indexData.entries) {
    const descFname = entry.descriptor;
    const sigFname = entry.signature;
    assert.ok(carrierArtifacts[descFname], `Transition descriptor ${descFname} missing in carrier artifacts`);
    assert.ok(carrierArtifacts[sigFname], `Transition signature ${sigFname} missing in carrier artifacts`);

    const descRaw = fs.readFileSync(path.join(carrierDir, descFname), 'utf8');
    const sigRaw = fs.readFileSync(path.join(carrierDir, sigFname), 'utf8');
    assert.equal(sha256(Buffer.from(descRaw, 'utf8')), entry.descriptor_sha256, `descriptor_sha256 mismatch for ${descFname}`);
    assert.ok(verifyRsaSignature(descRaw, sigRaw, publicKeyPem), `RSA signature verification failed for ${descFname}`);

    const descData = JSON.parse(descRaw);
    const zipName = descData.package?.asset;
    assert.ok(zipName, `Descriptor ${descFname} missing package.asset`);
    assert.ok(carrierArtifacts[zipName], `Carrier package archive ${zipName} missing in carrier artifacts`);
    const zipBytes = fs.readFileSync(path.join(carrierDir, zipName));

    validateTransitionDescriptor(descData, {
      canonicalSetupInvHash,
      canonicalSetupFiles,
      beta12InvHash: PINNED_BETA12_INVENTORY_SHA256,
      shellHash: recomputedPhysicalDigest,
      sourceShellFingerprint: expectedShellHash,
      zipName,
      zipBytes,
    });
  }
  console.log('      PASS: All transition descriptors, manifests, and RSA signatures verified against public key.');

  // 8. Output release-provenance.json
  const provenance = {
    schema_version: '1.0.0',
    tag: 'v0.0.13',
    release_tag: 'v0.0.13',
    setup_run_id: String(setupRunId),
    acceptance_run_id: String(acceptanceRunId),
    source_sha: sourceSha,
    run_token: stagingManifest.run_token,
    verdict: 'PASSED',
    cleanup_verified: true,
    artifact_binding_verified: true,
    verifications: acceptance.verifications,
    setup_assets: setupAssets,
    carrier_artifacts: carrierArtifacts,
    retained_shell: receipt.retained_shell,
    finalized_at: new Date().toISOString(),
  };

  const provenancePath = path.join(evidence, 'release-provenance.json');
  fs.writeFileSync(provenancePath, JSON.stringify(provenance, null, 2) + '\n', 'utf8');
  console.log(`      PASS: Generated release-provenance.json at: ${provenancePath}`);

  // 9. Print Proof
  console.log('\n======================================================================');
  console.log('PROVENANCE AND ACCEPTANCE PROOF: PASSED');
  console.log('Release Tag:                   v0.0.13');
  console.log(`Setup Run ID:                  ${setupRunId}`);
  console.log(`Acceptance Run ID:             ${acceptanceRunId}`);
  console.log(`Source SHA:                    ${sourceSha}`);
  console.log('All 13 Acceptance Checks:      TRUE');
  console.log('Cleanup Verified:              TRUE');
  console.log('Artifact Binding Verified:     TRUE');
  console.log(`Carrier Artifacts Count:       ${Object.keys(carrierArtifacts).length}`);
  console.log('======================================================================\n');
}

function parseCliArgs() {
  const args = process.argv.slice(2);
  const options = {
    mode: null,
    lab: null,
    evidence: null,
    assets: null,
    setupArtifactDir: null,
    carrierDir: null,
    setupRunId: process.env.SETUP_RUN_ID || null,
    acceptanceRunId: process.env.ACCEPTANCE_RUN_ID || process.env.GITHUB_RUN_ID || null,
    repoRoot: process.cwd(),
  };

  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--stage') {
      options.mode = 'stage';
    } else if (arg === '--finalize') {
      options.mode = 'finalize';
    } else if (arg === '--lab' && args[i + 1]) {
      options.lab = path.resolve(args[++i]);
    } else if (arg === '--evidence' && args[i + 1]) {
      options.evidence = path.resolve(args[++i]);
    } else if (arg === '--assets' && args[i + 1]) {
      options.assets = path.resolve(args[++i]);
    } else if (arg === '--setup-artifact-dir' && args[i + 1]) {
      options.setupArtifactDir = path.resolve(args[++i]);
    } else if (arg === '--carrier-dir' && args[i + 1]) {
      options.carrierDir = path.resolve(args[++i]);
    } else if (arg === '--setup-run-id' && args[i + 1]) {
      options.setupRunId = args[++i];
    } else if (arg === '--acceptance-run-id' && args[i + 1]) {
      options.acceptanceRunId = args[++i];
    } else if (arg === '--repo-root' && args[i + 1]) {
      options.repoRoot = path.resolve(args[++i]);
    }
  }

  return options;
}

export function main() {
  const options = parseCliArgs();
  if (options.mode === 'stage') {
    stageCarrierAssets(options);
  } else if (options.mode === 'finalize') {
    finalizeReleaseEvidence(options);
  } else {
    console.error('Usage: node scripts/finalize-setup13-release-evidence.mjs <--stage|--finalize> [options]');
    process.exit(1);
  }
}

const isDirectRun = Boolean(
  process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])
);
if (isDirectRun) {
  main();
}
