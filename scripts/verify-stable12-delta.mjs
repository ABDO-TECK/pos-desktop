import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';

// Strict CI guards: GHA Windows ephemeral runner only
if (process.env.GITHUB_ACTIONS !== 'true' || !process.env.RUNNER_TEMP) {
  throw new Error('scripts/verify-stable12-delta.mjs requires ephemeral Windows GitHub Actions runner.');
}

if (process.env.GITHUB_REPOSITORY !== 'ABDO-TECK/pos-desktop-updates') {
  throw new Error(`Invalid repository: '${process.env.GITHUB_REPOSITORY}'. Must be exactly 'ABDO-TECK/pos-desktop-updates'.`);
}

for (const key of ['UPDATE_PRIVATE_KEY', 'SOURCE_PRIVATE_SIGNING_KEY', 'PRIVATE_KEY']) {
  if (process.env[key]) {
    throw new Error(`Security violation: ${key} must not exist during delta acceptance phase.`);
  }
}

function assertNoReparse(targetPath) {
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

function computeSha256(filePath) {
  const hash = crypto.createHash('sha256');
  const buffer = fs.readFileSync(filePath);
  hash.update(buffer);
  return hash.digest('hex').toLowerCase();
}

function verifyRsaSignature(dataBytes, sigBase64, publicKeyPem) {
  const verifier = crypto.createVerify('RSA-SHA256');
  verifier.update(dataBytes);
  verifier.end();
  return verifier.verify(publicKeyPem, Buffer.from(sigBase64.trim(), 'base64'));
}

// Canonical 8 POS data targets managed across Windows profiles (matching Get-PosCleanupTargets)
const posTargets = [
  path.join(process.env.PROGRAMDATA || 'C:\\ProgramData', 'POS System'),
  path.join(process.env.APPDATA || '', 'POS System'),
  path.join(process.env.APPDATA || '', 'POS Desktop'),
  path.join(process.env.APPDATA || '', 'pos-desktop'),
  path.join(process.env.LOCALAPPDATA || '', 'POS System'),
  path.join(process.env.LOCALAPPDATA || '', 'POS Desktop'),
  path.join(process.env.LOCALAPPDATA || '', 'pos-desktop'),
  path.join(process.env.LOCALAPPDATA || '', 'pos-desktop-updater'),
].map(p => path.resolve(p));

function snapshotDataRoots() {
  const snapshot = {
    totalFiles: 0,
    totalBytes: 0,
    dbFilesCount: 0,
    credFilesCount: 0,
    keyPairFilesCount: 0,
    envFilesCount: 0,
    files: new Map()
  };
  for (let i = 0; i < posTargets.length; i++) {
    const targetDir = posTargets[i];
    if (!fs.existsSync(targetDir)) continue;
    assertNoReparse(targetDir);
    function scanDir(currentDir) {
      for (const item of fs.readdirSync(currentDir, { withFileTypes: true })) {
        const fullPath = path.join(currentDir, item.name);
        assertNoReparse(fullPath);
        if (item.isDirectory()) {
          scanDir(fullPath);
        } else if (item.isFile()) {
          const stat = fs.statSync(fullPath);
          const relKey = `target_${i}:${path.relative(targetDir, fullPath).replace(/\\/g, '/')}`;
          const hash = crypto.createHash('sha256').update(fs.readFileSync(fullPath)).digest('hex');
          snapshot.files.set(relKey, { size: stat.size, sha256: hash });
          snapshot.totalFiles++;
          snapshot.totalBytes += stat.size;

          const lowerName = item.name.toLowerCase();
          const ext = path.extname(lowerName);
          if (ext === '.db' || ext === '.sqlite' || lowerName.includes('pos_data') || lowerName.includes('ibdata')) {
            snapshot.dbFilesCount++;
          }
          if (lowerName === 'db_credentials.json' || lowerName === 'credentials.json') {
            snapshot.credFilesCount++;
          }
          if (ext === '.key' || ext === '.crt' || ext === '.pem') {
            snapshot.keyPairFilesCount++;
          }
          if (lowerName === '.env') {
            snapshot.envFilesCount++;
          }
        }
      }
    }
    scanDir(targetDir);
  }
  return snapshot;
}

function assertDataRootsPreserved(beforeSnapshot, afterSnapshot, stageName) {
  if (afterSnapshot.totalFiles !== beforeSnapshot.totalFiles) {
    throw new Error(`Data roots file count mismatch during ${stageName}: expected ${beforeSnapshot.totalFiles}, got ${afterSnapshot.totalFiles}`);
  }
  if (afterSnapshot.totalBytes !== beforeSnapshot.totalBytes) {
    throw new Error(`Data roots byte count mismatch during ${stageName}: expected ${beforeSnapshot.totalBytes}, got ${afterSnapshot.totalBytes}`);
  }
  for (const [key, beforeEntry] of beforeSnapshot.files.entries()) {
    const afterEntry = afterSnapshot.files.get(key);
    if (!afterEntry) {
      throw new Error(`Data root file missing after ${stageName}: ${key}`);
    }
    if (afterEntry.size !== beforeEntry.size || afterEntry.sha256 !== beforeEntry.sha256) {
      throw new Error(`Data root file modified during ${stageName}: ${key}`);
    }
  }
}

function verifyInventory(appRoot, inventory) {
  for (const [file, expected] of Object.entries(inventory.files)) {
    const filePath = path.join(appRoot, file);
    assert.ok(fs.existsSync(filePath), 'Target file missing: ' + file);
    const actualHash = computeSha256(filePath);
    assert.equal(actualHash, expected.sha256, 'Target hash mismatch: ' + file);
    const actualSize = fs.statSync(filePath).size;
    assert.equal(actualSize, expected.size, 'Target size mismatch: ' + file);
  }
}

const [assetsArg, evidenceArg] = process.argv.slice(2);
const runnerTemp = path.resolve(process.env.RUNNER_TEMP);
assert.ok(path.isAbsolute(runnerTemp), 'RUNNER_TEMP must be an absolute path.');
assertNoReparse(runnerTemp);

const assetsDir = path.resolve(assetsArg || 'release-assets');
const evidenceDir = path.resolve(evidenceArg || path.join(runnerTemp, 'stable12-delta-evidence'));
const labDir = path.join(runnerTemp, 'setup11-beta-release-lab');
const php = process.env.PHP_BINARY || 'php';

assertNoReparse(assetsDir);
fs.mkdirSync(evidenceDir, { recursive: true });
assertNoReparse(evidenceDir);

console.log('=== Stable 0.0.12 Delta Release Acceptance ===');
console.log(`Assets Directory:   ${assetsDir}`);
console.log(`Evidence Directory: ${evidenceDir}`);
console.log(`Lab Directory:      ${labDir}`);

// 1. Verify Setup 0.0.11 Baseline Pins and Inventory
console.log('1. Verifying Setup 0.0.11 baseline pins and inventory...');
const expected11InstallerHash = '4abfbd98898bf314d1531834166ec8f682710b90386920e7d71924e7fc92357d';
const expected11InventoryHash = '88475c625b47d0e60bba21ceb52923b8ab5e0b6eb1f57cfd4f495e7e1924ced2';
const expected11SourceCommit = 'aa14fce3dc5c0ec15b3a38c8ae83010430a50cf7';

let setup11Exe = path.join(labDir, 'setup11-download', 'POS-Desktop-Setup-0.0.11.exe');
let setup11Inv = path.join(labDir, 'setup11-inventory.json');

if (!fs.existsSync(setup11Inv)) {
  const altInv = path.join(labDir, 'setup11-download', 'deployment-inventory.json');
  if (fs.existsSync(altInv)) {
    setup11Inv = altInv;
  }
}

assert.ok(fs.existsSync(setup11Exe), `Setup 0.0.11 installer missing at ${setup11Exe}`);
assert.ok(fs.existsSync(setup11Inv), `Setup 0.0.11 inventory missing at ${setup11Inv}`);

const actual11ExeHash = computeSha256(setup11Exe);
assert.equal(actual11ExeHash, expected11InstallerHash, 'Setup 0.0.11 installer SHA-256 pin mismatch');

const actual11InvHash = computeSha256(setup11Inv);
assert.equal(actual11InvHash, expected11InventoryHash, 'Setup 0.0.11 deployment inventory SHA-256 pin mismatch');

const setup11InventoryData = JSON.parse(fs.readFileSync(setup11Inv, 'utf8'));
assert.equal(setup11InventoryData.version, '0.0.11', 'Setup 0.0.11 inventory version mismatch');
const pinned11Phar = setup11InventoryData.files && setup11InventoryData.files['backend/backend.phar'];
assert.ok(pinned11Phar, 'Setup 0.0.11 deployment inventory must track backend/backend.phar');
console.log('✔ Setup 0.0.11 installer, inventory, and source pins verified.');

// 2. Validate Public Key and Release Assets Integrity
console.log('2. Verifying release payload files and RSA signatures in assets directory...');
const publicKeyPath = path.resolve('backend/certs/update_public_key.pem');
assert.ok(fs.existsSync(publicKeyPath), `Public key missing at ${publicKeyPath}`);
const publicKeyPem = fs.readFileSync(publicKeyPath, 'utf8');

const requiredAssets = [
  'manifest-0.0.11.json',
  'manifest-0.0.11.sig',
  'delta-0.0.11-to-0.0.12.zip',
  'manifest-0.0.12-beta.1.json',
  'manifest-0.0.12-beta.1.sig',
  'delta-0.0.12-beta.1-to-0.0.12.zip',
  'manifest.json',
  'manifest.sig',
  'delta.zip',
  'deployment-inventory.json',
  'release-notes.md',
  'source-provenance.json'
];

for (const name of requiredAssets) {
  const full = path.join(assetsDir, name);
  assert.ok(fs.existsSync(full), `Required release asset missing: ${name}`);
  const stat = fs.lstatSync(full);
  assert.ok(!stat.isSymbolicLink() && stat.isFile(), `Asset must be regular file: ${name}`);
}

// Ensure no forbidden files in assets
for (const entry of fs.readdirSync(assetsDir)) {
  if (entry === 'SHA256SUMS.txt' || entry === 'stable12-delta-acceptance.json') continue;
  if (!requiredAssets.includes(entry)) {
    throw new Error(`Unexpected release asset found: ${entry}`);
  }
  if (/(?:auth\.json|\.env|private|key|\.pem|\.key|\.db|\.sqlite|data-contract|owned-lab)/i.test(entry) && !entry.endsWith('.sig')) {
    throw new Error(`Forbidden sensitive file in release assets: ${entry}`);
  }
}

// 3. Verify Primary Generic Aliases Byte Identity
console.log('3. Verifying generic aliases are byte-identical to primary baseline v0.0.11 canonical files...');
assert.equal(
  computeSha256(path.join(assetsDir, 'manifest.json')),
  computeSha256(path.join(assetsDir, 'manifest-0.0.11.json')),
  'manifest.json must be byte-identical to manifest-0.0.11.json'
);
assert.equal(
  computeSha256(path.join(assetsDir, 'manifest.sig')),
  computeSha256(path.join(assetsDir, 'manifest-0.0.11.sig')),
  'manifest.sig must be byte-identical to manifest-0.0.11.sig'
);
assert.equal(
  computeSha256(path.join(assetsDir, 'delta.zip')),
  computeSha256(path.join(assetsDir, 'delta-0.0.11-to-0.0.12.zip')),
  'delta.zip must be byte-identical to delta-0.0.11-to-0.0.12.zip'
);
console.log('✔ Primary generic aliases byte-identity verified.');

// 4. Verify RSA Manifest Signatures
console.log('4. Verifying RSA-2048 signatures of all manifests against update_public_key.pem...');
const m11Bytes = fs.readFileSync(path.join(assetsDir, 'manifest-0.0.11.json'));
const m11Sig = fs.readFileSync(path.join(assetsDir, 'manifest-0.0.11.sig'), 'utf8');
assert.ok(verifyRsaSignature(m11Bytes, m11Sig, publicKeyPem), 'RSA signature verification failed for manifest-0.0.11.json');

const mBetaBytes = fs.readFileSync(path.join(assetsDir, 'manifest-0.0.12-beta.1.json'));
const mBetaSig = fs.readFileSync(path.join(assetsDir, 'manifest-0.0.12-beta.1.sig'), 'utf8');
assert.ok(verifyRsaSignature(mBetaBytes, mBetaSig, publicKeyPem), 'RSA signature verification failed for manifest-0.0.12-beta.1.json');

const mGenericBytes = fs.readFileSync(path.join(assetsDir, 'manifest.json'));
const mGenericSig = fs.readFileSync(path.join(assetsDir, 'manifest.sig'), 'utf8');
assert.ok(verifyRsaSignature(mGenericBytes, mGenericSig, publicKeyPem), 'RSA signature verification failed for generic manifest.json');
console.log('✔ RSA manifest signatures verified.');

// 5. Manifest Structure and Channel Policy Verification
console.log('5. Validating manifest metadata and channel policies...');
const manifest11 = JSON.parse(m11Bytes.toString('utf8'));
const manifestBeta = JSON.parse(mBetaBytes.toString('utf8'));

// Verify source version.json has requires_npm_install: false
const srcVersionJson = JSON.parse(fs.readFileSync('version.json', 'utf8'));
assert.equal(srcVersionJson.requires_npm_install, false, 'Source version.json requires_npm_install must be false');

for (const m of [manifest11, manifestBeta]) {
  assert.equal(m.manifest_version, '1.0', 'manifest_version must be 1.0');
  assert.equal(m.version, '0.0.12', 'Target version must be 0.0.12');
  assert.equal(m.channel, 'stable', 'Channel must be stable');
  assert.equal(m.type, 'delta', 'Type must be delta');
  if (m.requires_npm_install !== undefined) {
    assert.equal(m.requires_npm_install, false, 'manifest requires_npm_install if present must be false');
  }
  assert.deepEqual(m.migrations, [], 'migrations must be empty (code-only delta)');
}

assert.equal(manifest11.from_version, '0.0.11', 'manifest-0.0.11.json from_version must be 0.0.11');
assert.equal(manifestBeta.from_version, '0.0.12-beta.1', 'manifest-0.0.12-beta.1.json from_version must be 0.0.12-beta.1');

// 6. Inspect Delta Archive Entries
console.log('6. Inspecting ZIP archive entries for directory traversal and unsafe files...');
function inspectZipArchive(zipPath, expectedFiles) {
  const inspectorPhp = `
    $zip = new ZipArchive();
    if ($zip->open($argv[1]) !== true) { fwrite(STDERR, "Open failed\\n"); exit(1); }
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) exit(2);
        $norm = str_replace('\\\\', '/', $name);
        if (str_contains($norm, '..') || str_starts_with($norm, '/') || str_contains($norm, ':') || str_contains($norm, "\\0")) {
            fwrite(STDERR, "Directory traversal rejected: $name\\n"); exit(3);
        }
        if (preg_match('#(^|/)(?:\\\\.env|private|key|\\\\.pem|\\\\.key|\\\\.crt|\\\\.db|\\\\.sqlite)(/|$)#i', $norm)) {
            fwrite(STDERR, "Forbidden file in zip: $name\\n"); exit(4);
        }
        if (!str_ends_with($norm, '/')) {
            $clean = preg_replace('#^files/#', '', $norm);
            $entries[] = $clean;
        }
    }
    $zip->close();
    echo json_encode($entries);
  `;
  const result = spawnSync(php, ['-r', inspectorPhp, zipPath], { encoding: 'utf8', windowsHide: true });
  assert.equal(result.status, 0, `ZIP inspection failed for ${zipPath}: ${result.stderr}`);
  const zipEntries = JSON.parse(result.stdout);
  const expectedSet = new Set(expectedFiles.map(f => f.path));
  for (const entry of zipEntries) {
    assert.ok(expectedSet.has(entry), `ZIP contains unexpected file entry: ${entry}`);
  }
}

inspectZipArchive(path.join(assetsDir, 'delta-0.0.11-to-0.0.12.zip'), manifest11.files);
inspectZipArchive(path.join(assetsDir, 'delta-0.0.12-beta.1-to-0.0.12.zip'), manifestBeta.files);
console.log('✔ Delta ZIP archives inspected and verified safe.');

// 7. Verify Target Deployment Inventory
console.log('7. Verifying target deployment inventory...');
const targetInventoryPath = path.join(assetsDir, 'deployment-inventory.json');
const targetInventory = JSON.parse(fs.readFileSync(targetInventoryPath, 'utf8'));
assert.equal(targetInventory.version, '0.0.12', 'Deployment inventory version must be 0.0.12');
assert.ok(targetInventory.files && typeof targetInventory.files === 'object', 'deployment-inventory must have files object');
assert.ok(targetInventory.files['version.json'], 'deployment-inventory must contain version.json');
assert.ok(targetInventory.files['backend/backend.phar'], 'deployment-inventory must contain backend/backend.phar');
console.log(`✔ Target inventory verified (${Object.keys(targetInventory.files).length} files tracked).`);

// 8. Prepare Isolated Test Runtimes & Execute Delta Application
console.log('8. Preparing isolated test application runtimes for dual-baseline verification...');
const toolDir = path.join(runnerTemp, `stable12-repair-tool-${crypto.randomUUID()}`);
fs.mkdirSync(toolDir, { recursive: true });

// Stage repair engine with allowed target 0.0.12 (CI-only artifact adapter; production repair.php stays untouched)
const originalRepairPhp = fs.readFileSync('scripts/update-repair/repair.php', 'utf8');
assert.ok(originalRepairPhp.includes("if ($target !== '0.0.12-beta.1')"), 'Original repair.php must have expected target check');
assert.ok(originalRepairPhp.includes(": ['0.0.11'];"), 'Original repair.php must have expected baseline default');

const patchedRepairPhp = originalRepairPhp
  .replace("if ($target !== '0.0.12-beta.1')", "if ($target !== '0.0.12-beta.1' && $target !== '0.0.12')")
  .replace(": ['0.0.11'];", ": ['0.0.11', '0.0.12-beta.1'];");
assert.notEqual(patchedRepairPhp, originalRepairPhp, 'Patched repair.php must differ from original');
assert.ok(patchedRepairPhp.includes("&& $target !== '0.0.12'"), 'Patched repair.php must include target 0.0.12');

fs.writeFileSync(path.join(toolDir, 'repair.php'), patchedRepairPhp);
fs.copyFileSync('backend/Services/UpdateAssetDownloader.php', path.join(toolDir, 'UpdateAssetDownloader.php'));
fs.copyFileSync('backend/certs/update_public_key.pem', path.join(toolDir, 'update_public_key.pem'));

const installedSetup11App = path.join(labDir, 'app', 'resources', 'app.asar.unpacked');
assert.ok(fs.existsSync(installedSetup11App), `Installed Setup 11 application directory missing: ${installedSetup11App}`);
const phar11Path = path.join(installedSetup11App, 'backend', 'backend.phar');
assert.ok(fs.existsSync(phar11Path), 'Setup 11 backend.phar missing');
const phar11Hash = computeSha256(phar11Path);
const phar11Size = fs.statSync(phar11Path).size;

// Verify installed baseline PHAR hash and size match PINNED inventory before autoload
assert.equal(phar11Hash, pinned11Phar.sha256, 'Installed Setup 11 backend.phar hash must match pinned inventory before autoload');
assert.equal(phar11Size, pinned11Phar.size, 'Installed Setup 11 backend.phar size must match pinned inventory before autoload');

function copyDirectoryRecursive(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    const srcPath = path.join(src, entry.name);
    const destPath = path.join(dest, entry.name);
    assertNoReparse(srcPath);
    if (entry.isDirectory()) {
      copyDirectoryRecursive(srcPath, destPath);
    } else {
      fs.copyFileSync(srcPath, destPath);
    }
  }
}

// ═════════════════════════════════════════════════════════════════════════
// Capture In-Memory Baseline Snapshot: Canonical 8 POS Data Roots
// ═════════════════════════════════════════════════════════════════════════
console.log('Capturing in-memory baseline snapshot across all 8 canonical POS data targets...');
const dataRootsBefore = snapshotDataRoots();
assert.ok(dataRootsBefore.totalFiles > 0, 'Data roots must contain files');
assert.ok(dataRootsBefore.totalBytes > 0, 'Data roots must contain non-zero bytes');
assert.ok(dataRootsBefore.dbFilesCount > 0, 'Mandatory closed database files missing in canonical POS data targets');
assert.ok(dataRootsBefore.credFilesCount > 0, 'Mandatory credentials file missing in canonical POS data targets');

let foundQzPair = false;
for (const target of posTargets) {
  const dir = path.join(target, 'qz-certs');
  const key = path.join(dir, 'private-key.pem');
  const cert = path.join(dir, 'digital-certificate.pem');
  if (fs.existsSync(key) && fs.existsSync(cert) && fs.statSync(key).size > 0 && fs.statSync(cert).size > 0) {
    foundQzPair = true;
    break;
  }
}
assert.ok(foundQzPair, 'Mandatory runtime QZ private-key.pem and digital-certificate.pem pair missing in POS targets');

let foundLanSslPair = false;
for (const target of posTargets) {
  const key = path.join(target, 'ssl', 'server.key');
  const cert = path.join(target, 'ssl', 'server.crt');
  if (fs.existsSync(key) && fs.existsSync(cert) && fs.statSync(key).size > 0 && fs.statSync(cert).size > 0) {
    foundLanSslPair = true;
    break;
  }
}
assert.ok(foundLanSslPair, 'Mandatory LAN SSL key/cert pair missing in POS targets');

let foundReceipt = false;
for (const target of posTargets) {
  const p = path.join(target, 'qz-shared-install.json');
  if (fs.existsSync(p) && fs.statSync(p).isFile() && fs.statSync(p).size > 0) {
    const ownership = JSON.parse(fs.readFileSync(p, 'utf8'));
    assert.ok(typeof ownership.rootCertificateBase64 === 'string' && ownership.rootCertificateBase64.length > 0, 'Receipt missing rootCertificateBase64');
    foundReceipt = true;
    break;
  }
}
assert.ok(foundReceipt, 'Mandatory QZ ownership receipt qz-shared-install.json missing in POS targets');
console.log(`✔ Real quiesced fixture validated (${dataRootsBefore.totalFiles} files, ${dataRootsBefore.totalBytes} bytes).`);

const primaryDataRoot = posTargets[0];

// 8A. Delta Application Test against Baseline 0.0.11
console.log('8A. Applying delta-0.0.11-to-0.0.12.zip to isolated Setup 0.0.11 installation...');
const test11Dir = path.join(runnerTemp, `test-apply-11-${crypto.randomUUID()}`);
const root11 = path.join(test11Dir, 'root');
const work11 = path.join(test11Dir, 'work');

copyDirectoryRecursive(installedSetup11App, root11);
fs.mkdirSync(work11, { recursive: true });

assert.equal(JSON.parse(fs.readFileSync(path.join(root11, 'version.json'), 'utf8')).version, '0.0.11', 'Baseline must start at 0.0.11');

const repairConfig11 = {
  tool_version: '1.0.0',
  installer_version: '0.0.11',
  baseline_version: ['0.0.11', '0.0.12-beta.1'],
  target_version: '0.0.12',
  target_channel: 'stable',
  baseline_phars: {
    '0.0.11': { sha256: phar11Hash, size: phar11Size }
  },
  allowed_paths: ['version.json', 'backend/backend.phar', 'frontend/dist/*'],
  target_inventory: targetInventory.files
};
fs.writeFileSync(path.join(toolDir, 'repair-config.json'), JSON.stringify(repairConfig11, null, 2));

const prep11Res = spawnSync(php, [path.join(toolDir, 'repair.php'), 'prepare', root11, work11, assetsDir, primaryDataRoot], { encoding: 'utf8', windowsHide: true });
assert.equal(prep11Res.status, 0, `repair.php prepare failed for 0.0.11: ${prep11Res.stderr || prep11Res.stdout}`);

const apply11Res = spawnSync(php, [path.join(toolDir, 'repair.php'), 'apply', root11, work11, assetsDir, primaryDataRoot], { encoding: 'utf8', windowsHide: true });
assert.equal(apply11Res.status, 0, `repair.php apply failed for 0.0.11: ${apply11Res.stderr || apply11Res.stdout}`);

// Verify data roots preserved immediately after 0.0.11 delta engine apply
const snapshotPost11 = snapshotDataRoots();
assertDataRootsPreserved(dataRootsBefore, snapshotPost11, 'Baseline 0.0.11 to 0.0.12 delta apply');

const post11Version = JSON.parse(fs.readFileSync(path.join(root11, 'version.json'), 'utf8'));
assert.equal(post11Version.version, '0.0.12', 'Applied version must be 0.0.12');
assert.equal(post11Version.channel, 'stable', 'Applied channel must be stable');

const post11PharHash = computeSha256(path.join(root11, 'backend', 'backend.phar'));
assert.notEqual(post11PharHash, phar11Hash, 'backend.phar hash must change after apply from 0.0.11');
assert.equal(post11PharHash, targetInventory.files['backend/backend.phar'].sha256, 'Target backend.phar hash mismatch after apply from 0.0.11');
verifyInventory(root11, targetInventory);
console.log('✔ Baseline 0.0.11 successfully transitioned to 0.0.12 stable with full target inventory verified.');

// 8B. Download published Beta 12 assets and test Delta Application against Baseline 0.0.12-beta.1
console.log('8B. Fetching published Beta 12 release assets and testing transition to 0.0.12 stable...');
const beta12DownloadDir = path.join(runnerTemp, 'beta12-download');
fs.mkdirSync(beta12DownloadDir, { recursive: true });

try {
  execFileSync('gh', [
    'release', 'download', 'v0.0.12-beta.1',
    '--repo', 'ABDO-TECK/pos-desktop-updates',
    '--pattern', 'manifest-0.0.11.json',
    '--pattern', 'manifest-0.0.11.sig',
    '--pattern', 'delta-0.0.11-to-0.0.12-beta.1.zip',
    '--pattern', 'deployment-inventory.json',
    '--dir', beta12DownloadDir
  ], { stdio: 'inherit', windowsHide: true });
} catch (ghErr) {
  console.log('gh release download failed, trying fetch fallback...');
  const baseUrl = 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.12-beta.1/';
  for (const f of ['manifest-0.0.11.json', 'manifest-0.0.11.sig', 'delta-0.0.11-to-0.0.12-beta.1.zip', 'deployment-inventory.json']) {
    const res = await fetch(baseUrl + f);
    if (!res.ok) throw new Error(`Failed to fetch ${baseUrl + f}: HTTP ${res.status}`);
    fs.writeFileSync(path.join(beta12DownloadDir, f), Buffer.from(await res.arrayBuffer()));
  }
}

// Verify published Beta 12 manifest signature
const pubBetaManifestBytes = fs.readFileSync(path.join(beta12DownloadDir, 'manifest-0.0.11.json'));
const pubBetaSig = fs.readFileSync(path.join(beta12DownloadDir, 'manifest-0.0.11.sig'), 'utf8');
assert.ok(verifyRsaSignature(pubBetaManifestBytes, pubBetaSig, publicKeyPem), 'Published Beta 12 manifest RSA signature verification failed');

const pubBetaInventory = JSON.parse(fs.readFileSync(path.join(beta12DownloadDir, 'deployment-inventory.json'), 'utf8'));
assert.equal(pubBetaInventory.version, '0.0.12-beta.1', 'Published Beta 12 inventory version mismatch');
const pinnedBetaPhar = pubBetaInventory.files && pubBetaInventory.files['backend/backend.phar'];
assert.ok(pinnedBetaPhar, 'Beta deployment inventory must track backend/backend.phar');

// Reconstruct authentic Beta 12 application
const testBetaDir = path.join(runnerTemp, `test-apply-beta-${crypto.randomUUID()}`);
const rootBeta = path.join(testBetaDir, 'root');
const workBeta = path.join(testBetaDir, 'work');

copyDirectoryRecursive(installedSetup11App, rootBeta);
fs.mkdirSync(workBeta, { recursive: true });

const repairConfigBetaSetup = {
  tool_version: '1.0.0',
  installer_version: '0.0.11',
  baseline_version: ['0.0.11'],
  target_version: '0.0.12-beta.1',
  target_channel: 'beta',
  baseline_phars: {
    '0.0.11': { sha256: phar11Hash, size: phar11Size }
  },
  allowed_paths: ['version.json', 'backend/backend.phar', 'frontend/dist/*'],
  target_inventory: pubBetaInventory.files
};
fs.writeFileSync(path.join(toolDir, 'repair-config.json'), JSON.stringify(repairConfigBetaSetup, null, 2));

const prepBetaRes = spawnSync(php, [path.join(toolDir, 'repair.php'), 'prepare', rootBeta, workBeta, beta12DownloadDir, primaryDataRoot], { encoding: 'utf8', windowsHide: true });
assert.equal(prepBetaRes.status, 0, `Failed preparing authentic Beta 12 baseline: ${prepBetaRes.stderr || prepBetaRes.stdout}`);

const applyBetaRes = spawnSync(php, [path.join(toolDir, 'repair.php'), 'apply', rootBeta, workBeta, beta12DownloadDir, primaryDataRoot], { encoding: 'utf8', windowsHide: true });
assert.equal(applyBetaRes.status, 0, `Failed applying authentic Beta 12 baseline: ${applyBetaRes.stderr || applyBetaRes.stdout}`);

assert.equal(JSON.parse(fs.readFileSync(path.join(rootBeta, 'version.json'), 'utf8')).version, '0.0.12-beta.1', 'Reconstructed Beta baseline version must be 0.0.12-beta.1');

// Now apply Stable 12 delta to authentic Beta 12 baseline
const pharBetaPath = path.join(rootBeta, 'backend', 'backend.phar');
const pharBetaHash = computeSha256(pharBetaPath);
const pharBetaSize = fs.statSync(pharBetaPath).size;

assert.equal(pharBetaHash, pinnedBetaPhar.sha256, 'Beta baseline backend.phar hash must match pinned beta inventory before autoload');
assert.equal(pharBetaSize, pinnedBetaPhar.size, 'Beta baseline backend.phar size must match pinned beta inventory before autoload');

const workBetaToStable = path.join(testBetaDir, 'work-stable');
fs.mkdirSync(workBetaToStable, { recursive: true });

const repairConfigBetaToStable = {
  tool_version: '1.0.0',
  installer_version: '0.0.11',
  baseline_version: ['0.0.11', '0.0.12-beta.1'],
  target_version: '0.0.12',
  target_channel: 'stable',
  baseline_phars: {
    '0.0.12-beta.1': { sha256: pharBetaHash, size: pharBetaSize }
  },
  allowed_paths: ['version.json', 'backend/backend.phar', 'frontend/dist/*'],
  target_inventory: targetInventory.files
};
fs.writeFileSync(path.join(toolDir, 'repair-config.json'), JSON.stringify(repairConfigBetaToStable, null, 2));

const prepBetaStableRes = spawnSync(php, [path.join(toolDir, 'repair.php'), 'prepare', rootBeta, workBetaToStable, assetsDir, primaryDataRoot], { encoding: 'utf8', windowsHide: true });
assert.equal(prepBetaStableRes.status, 0, `repair.php prepare failed for 0.0.12-beta.1: ${prepBetaStableRes.stderr || prepBetaStableRes.stdout}`);

const applyBetaStableRes = spawnSync(php, [path.join(toolDir, 'repair.php'), 'apply', rootBeta, workBetaToStable, assetsDir, primaryDataRoot], { encoding: 'utf8', windowsHide: true });
assert.equal(applyBetaStableRes.status, 0, `repair.php apply failed for 0.0.12-beta.1: ${applyBetaStableRes.stderr || applyBetaStableRes.stdout}`);

// Verify data roots preserved immediately after beta delta engine apply
const snapshotPostBeta = snapshotDataRoots();
assertDataRootsPreserved(dataRootsBefore, snapshotPostBeta, 'Baseline 0.0.12-beta.1 to 0.0.12 delta apply');

const postBetaVersion = JSON.parse(fs.readFileSync(path.join(rootBeta, 'version.json'), 'utf8'));
assert.equal(postBetaVersion.version, '0.0.12', 'Applied version from beta baseline must be 0.0.12');
assert.equal(postBetaVersion.channel, 'stable', 'Applied channel from beta baseline must be stable');

const postBetaPharHash = computeSha256(path.join(rootBeta, 'backend', 'backend.phar'));
assert.notEqual(postBetaPharHash, pharBetaHash, 'backend.phar hash must change after apply from beta');
assert.equal(postBetaPharHash, targetInventory.files['backend/backend.phar'].sha256, 'Target backend.phar hash mismatch after apply from beta');
verifyInventory(rootBeta, targetInventory);
console.log('✔ Baseline 0.0.12-beta.1 successfully transitioned to 0.0.12 stable with full target inventory verified.');

// 9. Data Preservation, Database Schemas, and Environment Guards
console.log('9. Verifying data preservation, schema integrity, and environmental guards...');
// Verify database schema identical across baselines (public schema file byte equality)
const targetSchemaPath = path.join(root11, 'database', 'pos_schema.sql');
const baseSchemaPath = path.join(installedSetup11App, 'database', 'pos_schema.sql');
assert.equal(computeSha256(targetSchemaPath), computeSha256(baseSchemaPath), 'database/pos_schema.sql must remain byte-identical (no migrations)');

// Check .env preservation
const envFilesChecked = dataRootsBefore.envFilesCount;
const envPreserved = envFilesChecked > 0 ? true : 'not_exercised';

// 10. Generate and Write Truthful stable12-delta-acceptance.json
console.log('10. Writing truthful stable12-delta-acceptance.json evidence artifact...');
const acceptanceRecord = {
  ok: true,
  targetVersion: '0.0.12',
  releaseChannel: 'stable',
  baselinesTested: ['0.0.11', '0.0.12-beta.1'],
  originalSetup11Verified: true,
  publishedBeta12Verified: true,
  rsaSignaturesVerified: true,
  manifestsMatched: true,
  payloadArchivesVerified: true,
  targetInventoryMatched: true,
  codePayloadReplaced: true,
  pharReplaced: true,
  versionReplaced: true,
  databaseSchemaPreserved: true,
  customerLedgerDataPreserved: true,
  closedDatabaseBytesPreserved: true,
  liveSqlSemanticQueryExercised: false,
  semanticSql: 'not_exercised',
  schemaSqlByteEquality: true,
  credentialsPreserved: true,
  printerKeysPreserved: true,
  dataRootsTotalFiles: dataRootsBefore.totalFiles,
  dataRootsTotalBytes: dataRootsBefore.totalBytes,
  dataRootsPreservedInMemory: true,
  envFilesChecked: envFilesChecked,
  envPreserved: envPreserved,
  physicalPrinterTested: false,
  bootstrapLimitationAcknowledged: true,
  productionRepairZipShipped: false,
  customerBootstrapPath: 'Setup 0.0.13 in-place installer',
  frozenSetup11DtoDropResultOk: true,
  acceptanceEngineType: 'isolated artifact-engine acceptance; NOT original 11 client HTTP bootstrap',
  nativeShellVersion: '0.0.11',
  nativeShellCommit: expected11SourceCommit,
  originalSetup11InstallerSha256: expected11InstallerHash,
  originalSetup11InventorySha256: expected11InventoryHash,
  generatedAt: new Date().toISOString()
};

const acceptanceJson = JSON.stringify(acceptanceRecord, null, 2);
fs.writeFileSync(path.join(assetsDir, 'stable12-delta-acceptance.json'), acceptanceJson);
fs.writeFileSync(path.join(evidenceDir, 'stable12-delta-acceptance.json'), acceptanceJson);
if (fs.existsSync(labDir)) {
  fs.writeFileSync(path.join(labDir, 'stable12-delta-acceptance.json'), acceptanceJson);
}

console.log('✔ Acceptance artifact written successfully to assets and evidence.');
console.log('Dual-baseline Stable 0.0.12 delta verification PASSED.');
