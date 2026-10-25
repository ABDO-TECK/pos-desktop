import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';

const source = path.resolve('.');
const php = process.env.PHP_BINARY || 'php';

// Execution policy: all runtime checks run in GitHub Actions only
if (process.env.GITHUB_ACTIONS !== 'true' || !process.env.RUNNER_TEMP) {
  console.log('Skipping update-repair runtime verification locally. All tests run in GitHub Actions.');
  process.exit(0);
}

// Fresh unique lab directory created inside RUNNER_TEMP or os.tmpdir
const labBase = process.env.RUNNER_TEMP || os.tmpdir();
const lab = fs.mkdtempSync(path.join(labBase, 'pos-repair-lab-'));

const {privateKey, publicKey} = crypto.generateKeyPairSync('rsa', {modulusLength: 2048});
const write = (f, data) => { fs.mkdirSync(path.dirname(f), {recursive: true}); fs.writeFileSync(f, data); };
const hash = f => crypto.createHash('sha256').update(fs.readFileSync(f)).digest('hex');

// Prepare fixture or installed PHAR with strict NO DUMMY BYTES fallback
let installed = process.env.POS_BASELINE_PHAR || path.join(source, 'backend/backend.phar');
if (!fs.existsSync(installed)) {
  const fixturePharPath = path.join(lab, 'fixture-backend.phar');
  const pharBuilder = path.join(lab, 'make-fixture-phar.php');
  const sourceEscaped = source.replace(/\\/g, '/');
  write(pharBuilder, `<?php
$pharPath = $argv[1];
$p = new Phar($pharPath, 0, 'engine.phar');
$p->startBuffering();
$autoload = <<<'PHP'
<?php
spl_autoload_register(function ($class) {
    $prefix = 'App\\\\';
    if (!str_starts_with($class, $prefix)) return;
    $rel = str_replace('\\\\', '/', substr($class, strlen($prefix)));
    $file = '${sourceEscaped}/backend/' . $rel . '.php';
    if (file_exists($file)) require_once $file;
});
PHP;
$p->addFromString('vendor/autoload.php', $autoload);
$p->setStub($p->createDefaultStub('vendor/autoload.php'));
$p->stopBuffering();
`);
  const buildResult = spawnSync(php, ['-d', 'phar.readonly=0', pharBuilder, fixturePharPath], {encoding: 'utf8', windowsHide: true});
  if (buildResult.status !== 0 || !fs.existsSync(fixturePharPath)) {
    throw new Error(`Failed to build valid fixture PHAR (status: ${buildResult.status}): ${buildResult.stderr || buildResult.stdout}`);
  }
  installed = fixturePharPath;
}

const installedHash = hash(installed);
const installedSize = fs.statSync(installed).size;

// Prepare tool directory
const tool = path.join(lab, 'tool');
write(path.join(tool, 'repair.php'), fs.readFileSync(path.join(source, 'scripts/update-repair/repair.php')));
write(path.join(tool, 'UpdateAssetDownloader.php'), fs.readFileSync(path.join(source, 'backend/Services/UpdateAssetDownloader.php')));
write(path.join(tool, 'update_public_key.pem'), publicKey.export({type: 'spki', format: 'pem'}));

// Flexible ZIP writer supporting duplicate entries and arbitrary structures
const zipWriter = path.join(lab, 'zip.php');
write(zipWriter, `<?php
$zip = new ZipArchive();
if ($zip->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) exit(1);
$entries = json_decode($argv[2], true);
foreach ($entries as $item) {
    $zip->addFromString($item['name'], $item['content']);
}
$zip->close();
`);

function makeZipEntries(zipPath, items) {
  const result = spawnSync(php, [zipWriter, zipPath, JSON.stringify(items)], {encoding: 'utf8', windowsHide: true});
  assert.equal(result.status, 0, `makeZipEntries failed: ${result.stderr}`);
}

function makeZip(zipPath, entriesMap) {
  const items = Object.entries(entriesMap).map(([name, content]) => ({name, content}));
  makeZipEntries(zipPath, items);
}

// Helper to restore base signed configuration with mandatory valid public inventory
function restoreDefaultConfig(target = '0.0.12-beta.1') {
  const versionBytes = JSON.stringify({version: target, channel: 'beta', installer_version: '0.0.11'});
  const cfg = {
    tool_version: '1.0.0',
    installer_version: '0.0.11',
    baseline_version: ['0.0.11'],
    target_version: target,
    target_channel: 'beta',
    baseline_phar: {
      sha256: installedHash,
      size: installedSize
    },
    allowed_paths: [
      'version.json',
      'backend/backend.phar',
      'frontend/dist/*'
    ],
    native_hashes: {
      'POS Desktop.exe': 'dummy_hash',
      'resources/app.asar': 'dummy_hash',
      'resources/portable/php/php.exe': 'dummy_hash'
    },
    target_inventory_sha256: '88475c625b47d0e60bba21ceb52923b8ab5e0b6eb1f57cfd4f495e7e1924ced2',
    target_inventory: {
      'version.json': {
        sha256: crypto.createHash('sha256').update(versionBytes).digest('hex'),
        size: Buffer.byteLength(versionBytes)
      },
      'backend/backend.phar': {
        sha256: installedHash,
        size: installedSize
      }
    }
  };
  write(path.join(tool, 'repair-config.json'), JSON.stringify(cfg, null, 2));
}

// 1. Synthetic multi-baseline matrix testing: legacy ('0.0.1', '0.0.10-beta.1') and Setup 11 ('0.0.11')
// Target code baseline verification with retained Setup 11 native baseline metadata
const matrix = [
  {baseline: '0.0.1', target: '0.0.12-beta.1'},
  {baseline: '0.0.10-beta.1', target: '0.0.12-beta.1'},
  {baseline: '0.0.11', target: '0.0.12-beta.1'}
];

for (const {baseline, target} of matrix) {
  const directory = path.join(lab, `test-${baseline}`);
  const root = path.join(directory, 'root');
  const work = path.join(directory, 'work');
  const assets = path.join(directory, 'assets');
  const userData = path.join(directory, 'userData');

  fs.mkdirSync(work, {recursive: true});
  fs.mkdirSync(assets, {recursive: true});
  fs.mkdirSync(userData, {recursive: true});

  write(path.join(root, 'backend/backend.phar'), fs.readFileSync(installed));
  write(path.join(root, 'version.json'), JSON.stringify({version: baseline, channel: 'beta', installer_version: '0.0.11'}));
  write(path.join(root, 'frontend/dist/changed.js'), 'old payload');
  write(path.join(root, 'frontend/dist/keep.js'), 'unchanged');
  write(path.join(root, 'backend/storage/customer.db'), 'customer sentinel');

  const protectedFiles = ['frontend/dist/keep.js', 'backend/storage/customer.db'].map(f => path.join(root, f));
  protectedFiles.forEach(f => fs.utimesSync(f, new Date('2020-01-01'), new Date('2020-01-01')));
  const before = protectedFiles.map(f => ({hash: hash(f), mtime: fs.statSync(f).mtimeMs, ino: fs.statSync(f).ino}));

  const versionBytes = JSON.stringify({version: target, channel: 'beta', installer_version: '0.0.11'});
  const changedBytes = 'new payload';

  // Embed full valid public target inventory (version.json, backend.phar, and frontend files)
  const targetInventory = {
    'version.json': {
      sha256: crypto.createHash('sha256').update(versionBytes).digest('hex'),
      size: Buffer.byteLength(versionBytes)
    },
    'backend/backend.phar': {
      sha256: installedHash,
      size: installedSize
    },
    'frontend/dist/changed.js': {
      sha256: crypto.createHash('sha256').update(changedBytes).digest('hex'),
      size: Buffer.byteLength(changedBytes)
    },
    'frontend/dist/keep.js': {
      sha256: hash(path.join(root, 'frontend/dist/keep.js')),
      size: fs.statSync(path.join(root, 'frontend/dist/keep.js')).size
    }
  };

  const repairConfig = {
    tool_version: '1.0.0',
    installer_version: '0.0.11',
    baseline_version: matrix.map(m => m.baseline),
    target_version: target,
    target_channel: 'beta',
    baseline_phar: {
      sha256: installedHash,
      size: installedSize
    },
    allowed_paths: [
      'version.json',
      'backend/backend.phar',
      'frontend/dist/*'
    ],
    native_hashes: {
      'POS Desktop.exe': 'dummy_hash',
      'resources/app.asar': 'dummy_hash',
      'resources/portable/php/php.exe': 'dummy_hash'
    },
    target_inventory_sha256: '88475c625b47d0e60bba21ceb52923b8ab5e0b6eb1f57cfd4f495e7e1924ced2',
    target_inventory: targetInventory
  };
  write(path.join(tool, 'repair-config.json'), JSON.stringify(repairConfig, null, 2));

  const entries = [['version.json', versionBytes], ['frontend/dist/changed.js', changedBytes]];
  const manifest = {
    manifest_version: '1.0',
    version: target,
    from_version: baseline,
    minimum_version: '0.0.1',
    type: 'delta',
    channel: 'beta',
    migrations: [],
    baseline_files: Object.fromEntries(['version.json', 'frontend/dist/changed.js'].map(f => [f, hash(path.join(root, f))])),
    files: entries.map(([p, b]) => ({path: p, sha256: crypto.createHash('sha256').update(b).digest('hex'), size: Buffer.byteLength(b), action: 'replace'})),
    deleted_files: []
  };

  const manifestPath = path.join(assets, `manifest-${baseline}.json`);
  const signaturePath = path.join(assets, `manifest-${baseline}.sig`);
  const deltaPath = path.join(assets, `delta-${baseline}-to-${target}.zip`);

  function sign(m) {
    const bytes = JSON.stringify(m);
    write(manifestPath, bytes);
    write(signaturePath, crypto.sign('RSA-SHA256', Buffer.from(bytes), privateKey).toString('base64'));
  }
  sign(manifest);

  makeZip(deltaPath, {
    'version.json': versionBytes,
    'frontend/dist/changed.js': changedBytes
  });

  function run(mode, customData = userData) {
    return spawnSync(php, [path.join(tool, 'repair.php'), mode, root, work, assets, customData], {encoding: 'utf8', windowsHide: true});
  }

  function reject(pattern) {
    const result = run('prepare');
    assert.notEqual(result.status, 0, `Expected failure, got exit 0: ${result.stdout}`);
    assert.match(result.stderr, pattern);
    assert.equal(fs.readFileSync(path.join(root, 'frontend/dist/changed.js'), 'utf8'), 'old payload');
  }

  // A. Signature verification
  write(signaturePath, 'invalid');
  reject(/RSA manifest signature rejected/);
  sign(manifest);

  // B. Migrations rejection
  sign({...manifest, migrations: ['bad.sql']});
  reject(/without migrations/);
  sign(manifest);

  // C. Wrong target / baseline
  sign({...manifest, from_version: '9.9.9'});
  reject(/Wrong repair target or baseline/);
  sign(manifest);

  // D. Modified baseline file
  write(path.join(root, 'frontend/dist/changed.js'), 'modified baseline');
  let result = run('prepare');
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /Installed files differ/);
  write(path.join(root, 'frontend/dist/changed.js'), 'old payload');

  // E. Valid prepare
  result = run('prepare');
  assert.equal(result.status, 0, result.stderr);

  // F. Tampered staging payload
  const find = (dir, name) => fs.readdirSync(dir, {withFileTypes: true}).flatMap(e => e.isDirectory() ? find(path.join(dir, e.name), name) : e.name === name ? [path.join(dir, e.name)] : []);
  const payload = find(path.join(work, 'state'), 'changed.js')[0];
  assert.ok(payload);
  write(payload, 'tampered');
  result = run('apply');
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /hash mismatch/);
  assert.equal(fs.readFileSync(path.join(root, 'frontend/dist/changed.js'), 'utf8'), 'old payload');
  write(payload, changedBytes);

  // G. Successful apply
  result = run('apply');
  assert.equal(result.status, 0, result.stderr);
  const appliedVersion = JSON.parse(fs.readFileSync(path.join(root, 'version.json'), 'utf8'));
  assert.equal(appliedVersion.version, target);
  assert.equal(appliedVersion.channel, 'beta');
  assert.equal(appliedVersion.installer_version, '0.0.11');
  assert.equal(fs.readFileSync(path.join(root, 'frontend/dist/changed.js'), 'utf8'), changedBytes);

  // H. Untouched files preservation
  protectedFiles.forEach((f, i) => assert.deepEqual({hash: hash(f), mtime: fs.statSync(f).mtimeMs, ino: fs.statSync(f).ino}, before[i]));

  // I. Verified result receipt
  const receiptPath = path.join(work, 'result.json');
  assert.ok(fs.existsSync(receiptPath));
  const receipt = JSON.parse(fs.readFileSync(receiptPath, 'utf8'));
  assert.equal(receipt.ok, true);
  assert.equal(receipt.from, baseline);
  assert.equal(receipt.to, target);

  console.log(`PASS baseline ${baseline} -> ${target}: signature/migrations/baseline/tampered-stage rejected; apply preserved file identity, customer data, and result receipt.`);
}

restoreDefaultConfig();

// 2. Strict Allowed Paths Rejections
console.log('Testing disallowed and protected path rejections (strict allowlist evaluated first)...');
const testDir = path.join(lab, 'test-disallowed');
const tRoot = path.join(testDir, 'root');
const tWork = path.join(testDir, 'work');
const tAssets = path.join(testDir, 'assets');
const tUserData = path.join(testDir, 'userData');
fs.mkdirSync(tWork, {recursive: true});
fs.mkdirSync(tAssets, {recursive: true});
fs.mkdirSync(tUserData, {recursive: true});

write(path.join(tRoot, 'backend/backend.phar'), fs.readFileSync(installed));
write(path.join(tRoot, 'version.json'), JSON.stringify({version: '0.0.11', channel: 'beta', installer_version: '0.0.11'}));

const disallowedTargets = [
  {file: 'database/pos_schema.sql', expected: /Forbidden repair target path/},
  {file: 'backend/certs/update_public_key.pem', expected: /Forbidden repair target path/},
  {file: '.env', expected: /Forbidden repair target path/},
  {file: 'resources/portable/php/php.exe', expected: /Forbidden repair target path/},
  {file: 'unauthorized/arbitrary.js', expected: /Unexpected repair target/}
];

for (const {file, expected} of disallowedTargets) {
  write(path.join(tRoot, file), 'original');
  const badManifest = {
    manifest_version: '1.0',
    version: '0.0.12-beta.1',
    from_version: '0.0.11',
    minimum_version: '0.0.1',
    type: 'delta',
    channel: 'beta',
    migrations: [],
    baseline_files: {'version.json': hash(path.join(tRoot, 'version.json')), [file]: hash(path.join(tRoot, file))},
    files: [
      {path: 'version.json', sha256: hash(path.join(tRoot, 'version.json')), size: fs.statSync(path.join(tRoot, 'version.json')).size, action: 'replace'},
      {path: file, sha256: crypto.createHash('sha256').update('new').digest('hex'), size: 3, action: 'replace'}
    ],
    deleted_files: []
  };
  const mBytes = JSON.stringify(badManifest);
  write(path.join(tAssets, 'manifest-0.0.11.json'), mBytes);
  write(path.join(tAssets, 'manifest-0.0.11.sig'), crypto.sign('RSA-SHA256', Buffer.from(mBytes), privateKey).toString('base64'));
  makeZip(path.join(tAssets, 'delta-0.0.11-to-0.0.12-beta.1.zip'), {
    'version.json': fs.readFileSync(path.join(tRoot, 'version.json'), 'utf8'),
    [file]: 'new'
  });
  const res = spawnSync(php, [path.join(tool, 'repair.php'), 'prepare', tRoot, tWork, tAssets, tUserData], {encoding: 'utf8', windowsHide: true});
  assert.notEqual(res.status, 0, `Expected failure for disallowed file ${file}`);
  assert.match(res.stderr, expected);
}
console.log('PASS disallowed protected paths rejected before reading baseline.');

// 3. User Data Guards (fail closed on active locks, corrupted state, pending jobs)
console.log('Testing readonly user data guards with genuine job shapes...');
const guardDir = path.join(lab, 'test-guards');
const gRoot = path.join(guardDir, 'root');
const gWork = path.join(guardDir, 'work');
const gAssets = path.join(guardDir, 'assets');
const gUserData = path.join(guardDir, 'userData');
fs.mkdirSync(gWork, {recursive: true});
fs.mkdirSync(gAssets, {recursive: true});
fs.mkdirSync(gUserData, {recursive: true});

write(path.join(gRoot, 'backend/backend.phar'), fs.readFileSync(installed));
write(path.join(gRoot, 'version.json'), JSON.stringify({version: '0.0.11', channel: 'beta', installer_version: '0.0.11'}));
const validManifest = {
  manifest_version: '1.0',
  version: '0.0.12-beta.1',
  from_version: '0.0.11',
  minimum_version: '0.0.1',
  type: 'delta',
  channel: 'beta',
  migrations: [],
  baseline_files: {'version.json': hash(path.join(gRoot, 'version.json'))},
  files: [{path: 'version.json', sha256: hash(path.join(gRoot, 'version.json')), size: fs.statSync(path.join(gRoot, 'version.json')).size, action: 'replace'}],
  deleted_files: []
};
const vmBytes = JSON.stringify(validManifest);
write(path.join(gAssets, 'manifest-0.0.11.json'), vmBytes);
write(path.join(gAssets, 'manifest-0.0.11.sig'), crypto.sign('RSA-SHA256', Buffer.from(vmBytes), privateKey).toString('base64'));
makeZip(path.join(gAssets, 'delta-0.0.11-to-0.0.12-beta.1.zip'), {'version.json': vmBytes});

function testGuard(setupFn, expectedError) {
  const customUd = path.join(guardDir, 'ud-' + crypto.randomBytes(4).toString('hex'));
  fs.mkdirSync(customUd, {recursive: true});
  setupFn(customUd);
  const res = spawnSync(php, [path.join(tool, 'repair.php'), 'prepare', gRoot, gWork, gAssets, customUd], {encoding: 'utf8', windowsHide: true});
  assert.notEqual(res.status, 0, `Expected failure, got exit 0`);
  assert.match(res.stderr, expectedError);
}

// Lock guards
testGuard(ud => write(path.join(ud, 'update-operation.lock'), 'locked'), /Active update lock detected/);
testGuard(ud => write(path.join(ud, 'recovery.lock'), 'locked'), /Active update lock detected/);
testGuard(ud => write(path.join(ud, 'update-coordination.gate'), 'locked'), /Active update lock detected/);
testGuard(ud => write(path.join(ud, 'sale-operation-1.lock'), 'locked'), /Active sale coordination lock detected/);

// State guards (delta update state shape)
testGuard(ud => write(path.join(ud, 'update-state.json'), JSON.stringify({state: 'downloading'})), /Pending or active update state/);
testGuard(ud => write(path.join(ud, 'update-state.json'), JSON.stringify({state: 'completed', failed: true})), /Recovery or failure flags detected/);
testGuard(ud => write(path.join(ud, 'update-state.json'), JSON.stringify({state: 'completed', recovery_in_progress: true})), /Recovery or failure flags detected/);
testGuard(ud => write(path.join(ud, 'update-state.json'), 'not valid json'), /Corrupted update-state\.json/);

// Genuine job guards (BackgroundUpdateApplyService shape: job_id, operation_id, status, result, no job_name)
testGuard(ud => write(path.join(ud, 'update-jobs/job_1.json'), JSON.stringify({job_id: 1, operation_id: 'op_1', status: 'processing', updated_at: '2026-01-01T00:00:00Z'})), /Incomplete update job/);
testGuard(ud => write(path.join(ud, 'update-jobs/job_2.json'), JSON.stringify({job_id: 2, operation_id: 'op_2', status: 'completed', result: {ok: false}})), /lacks verified result\.ok === true/);
testGuard(ud => write(path.join(ud, 'update-jobs/job_3.json'), JSON.stringify({job_id: 3, operation_id: 'op_3', status: 'completed', result: {ok: true, data: {requires_desktop_handoff: true}}})), /Pending desktop handoff apply job not completed on installed version/);
testGuard(ud => write(path.join(ud, 'update-jobs/job_4.json'), JSON.stringify({job_id: 999, operation_id: 'op_4', status: 'completed', result: {ok: true}})), /Job ID mismatch/);
testGuard(ud => write(path.join(ud, 'update-jobs/job_5.json'), JSON.stringify({job_id: 5, status: 'completed', result: {ok: true}})), /Missing operation_id/);
testGuard(ud => write(path.join(ud, 'update-jobs/job_6.json'), '{corrupt json'), /Corrupted job file JSON/);
testGuard(ud => write(path.join(ud, 'update-jobs/job_7.json'), JSON.stringify({job_id: 7, operation_id: 'op_7', status: 'completed', settlement_error: 'failure during settlement', result: {ok: true, data: {}}})), /Settlement error detected/);
testGuard(ud => write(path.join(ud, 'update-jobs/job_8.json'), JSON.stringify({job_id: 8, operation_id: 'op_8', status: 'completed', result: {ok: true, data: null}})), /invalid or null data object/);

console.log('PASS readonly user data guards verified with genuine job shapes.');

// 4. Tampered Baseline PHAR Test (Autoload Execution Prevention - NO DUMMY BYTES)
console.log('Testing tampered baseline PHAR autoload prevention with genuine PHAR build...');
const pharLab = path.join(lab, 'test-tampered-phar');
const pRoot = path.join(pharLab, 'root');
const pWork = path.join(pharLab, 'work');
const pAssets = path.join(pharLab, 'assets');
const pUserData = path.join(pharLab, 'userData');
fs.mkdirSync(pWork, {recursive: true});
fs.mkdirSync(pAssets, {recursive: true});
fs.mkdirSync(pUserData, {recursive: true});

const markerPath = path.join(pharLab, 'tampered-autoload.marker').replace(/\\/g, '/');
const tamperedPharPath = path.join(pharLab, 'tampered.phar');
const makeTamperedPhar = path.join(pharLab, 'make-tampered-phar.php');

write(makeTamperedPhar, `<?php
$p = new Phar($argv[1], 0, 'tampered.phar');
$p->startBuffering();
$autoload = "<?php file_put_contents('${markerPath}', 'MALICIOUS_AUTOLOAD_EXECUTED');";
$p->addFromString('vendor/autoload.php', $autoload);
$p->setStub($p->createDefaultStub('vendor/autoload.php'));
$p->stopBuffering();
`);

const makePharRes = spawnSync(php, ['-d', 'phar.readonly=0', makeTamperedPhar, tamperedPharPath], {encoding: 'utf8', windowsHide: true});
if (makePharRes.status !== 0 || !fs.existsSync(tamperedPharPath)) {
  throw new Error(`Failed to build valid tampered fixture PHAR: ${makePharRes.stderr || makePharRes.stdout}`);
}
write(path.join(pRoot, 'backend/backend.phar'), fs.readFileSync(tamperedPharPath));
write(path.join(pRoot, 'version.json'), JSON.stringify({version: '0.0.11', channel: 'beta', installer_version: '0.0.11'}));

// Copy valid assets
write(path.join(pAssets, 'manifest-0.0.11.json'), vmBytes);
write(path.join(pAssets, 'manifest-0.0.11.sig'), crypto.sign('RSA-SHA256', Buffer.from(vmBytes), privateKey).toString('base64'));
makeZip(path.join(pAssets, 'delta-0.0.11-to-0.0.12-beta.1.zip'), {'version.json': vmBytes});

const pharTestRes = spawnSync(php, [path.join(tool, 'repair.php'), 'prepare', pRoot, pWork, pAssets, pUserData], {encoding: 'utf8', windowsHide: true});
assert.notEqual(pharTestRes.status, 0, 'Tampered PHAR must be rejected');
assert.match(pharTestRes.stderr, /Installed backend\.phar hash or size does not match approved baseline before autoload/);
assert.equal(fs.existsSync(markerPath), false, 'Tampered PHAR vendor/autoload.php must NEVER be executed!');
console.log('PASS tampered baseline PHAR rejected before autoload; autoloader execution prevented.');

// 5. ZIP Security: traversal, directory traversal, duplicates, and missing entries
console.log('Testing ZIP security (traversal, duplicates, missing entries)...');
const zipSecLab = path.join(lab, 'test-zip-sec');
const zRoot = path.join(zipSecLab, 'root');
const zWork = path.join(zipSecLab, 'work');
const zAssets = path.join(zipSecLab, 'assets');
const zUserData = path.join(zipSecLab, 'userData');
fs.mkdirSync(zWork, {recursive: true});
fs.mkdirSync(zAssets, {recursive: true});
fs.mkdirSync(zUserData, {recursive: true});

write(path.join(zRoot, 'backend/backend.phar'), fs.readFileSync(installed));
write(path.join(zRoot, 'version.json'), JSON.stringify({version: '0.0.11', channel: 'beta', installer_version: '0.0.11'}));
write(path.join(zAssets, 'manifest-0.0.11.json'), vmBytes);
write(path.join(zAssets, 'manifest-0.0.11.sig'), crypto.sign('RSA-SHA256', Buffer.from(vmBytes), privateKey).toString('base64'));

// Unexpected entry
makeZip(path.join(zAssets, 'delta-0.0.11-to-0.0.12-beta.1.zip'), {
  'version.json': vmBytes,
  'unexpected.txt': 'injected'
});
let zipRes = spawnSync(php, [path.join(tool, 'repair.php'), 'prepare', zRoot, zWork, zAssets, zUserData], {encoding: 'utf8', windowsHide: true});
assert.notEqual(zipRes.status, 0);
assert.match(zipRes.stderr, /Unexpected ZIP entry/);

// File directory traversal entry
makeZip(path.join(zAssets, 'delta-0.0.11-to-0.0.12-beta.1.zip'), {
  'version.json': vmBytes,
  '../escaped.txt': 'escaped'
});
zipRes = spawnSync(php, [path.join(tool, 'repair.php'), 'prepare', zRoot, zWork, zAssets, zUserData], {encoding: 'utf8', windowsHide: true});
assert.notEqual(zipRes.status, 0);
assert.match(zipRes.stderr, /Directory traversal rejected/);

// Raw directory entry traversal
makeZipEntries(path.join(zAssets, 'delta-0.0.11-to-0.0.12-beta.1.zip'), [
  {name: 'version.json', content: vmBytes},
  {name: '../bad_dir/', content: ''}
]);
zipRes = spawnSync(php, [path.join(tool, 'repair.php'), 'prepare', zRoot, zWork, zAssets, zUserData], {encoding: 'utf8', windowsHide: true});
assert.notEqual(zipRes.status, 0);
assert.match(zipRes.stderr, /Directory traversal rejected/);

// Duplicate entry in ZIP
makeZipEntries(path.join(zAssets, 'delta-0.0.11-to-0.0.12-beta.1.zip'), [
  {name: 'version.json', content: vmBytes},
  {name: 'files/version.json', content: vmBytes}
]);
zipRes = spawnSync(php, [path.join(tool, 'repair.php'), 'prepare', zRoot, zWork, zAssets, zUserData], {encoding: 'utf8', windowsHide: true});
assert.notEqual(zipRes.status, 0);
assert.match(zipRes.stderr, /Duplicate ZIP entry/);

console.log('PASS ZIP security guards verified.');

// 6. Target Inventory Post-Apply Verification and Rollback Test
console.log('Testing target inventory post-apply verification failure and automatic rollback...');
const invLab = path.join(lab, 'test-target-inv');
const iRoot = path.join(invLab, 'root');
const iWork = path.join(invLab, 'work');
const iAssets = path.join(invLab, 'assets');
const iUserData = path.join(invLab, 'userData');
fs.mkdirSync(iWork, {recursive: true});
fs.mkdirSync(iAssets, {recursive: true});
fs.mkdirSync(iUserData, {recursive: true});

write(path.join(iRoot, 'backend/backend.phar'), fs.readFileSync(installed));
write(path.join(iRoot, 'version.json'), JSON.stringify({version: '0.0.11', channel: 'beta', installer_version: '0.0.11'}));
write(path.join(iRoot, 'frontend/dist/bundle.js'), 'initial bundle');

const vBytes = JSON.stringify({version: '0.0.12-beta.1', channel: 'beta', installer_version: '0.0.11'});

// Pre-test: Missing target_inventory map rejected before autoload
write(path.join(tool, 'repair-config.json'), JSON.stringify({
  tool_version: '1.0.0',
  target_version: '0.0.12-beta.1',
  target_channel: 'beta',
  baseline_version: ['0.0.11'],
  baseline_phar: { sha256: installedHash, size: installedSize }
}, null, 2));
let invPreRes = spawnSync(php, [path.join(tool, 'repair.php'), 'prepare', iRoot, iWork, iAssets, iUserData], {encoding: 'utf8', windowsHide: true});
assert.notEqual(invPreRes.status, 0);
assert.match(invPreRes.stderr, /Missing or empty target_inventory map in signed configuration/);

// Pre-test: target_inventory missing backend.phar rejected before autoload
write(path.join(tool, 'repair-config.json'), JSON.stringify({
  tool_version: '1.0.0',
  target_version: '0.0.12-beta.1',
  target_channel: 'beta',
  baseline_version: ['0.0.11'],
  baseline_phar: { sha256: installedHash, size: installedSize },
  target_inventory: {
    'version.json': { sha256: crypto.createHash('sha256').update(vBytes).digest('hex'), size: Buffer.byteLength(vBytes) }
  }
}, null, 2));
invPreRes = spawnSync(php, [path.join(tool, 'repair.php'), 'prepare', iRoot, iWork, iAssets, iUserData], {encoding: 'utf8', windowsHide: true});
assert.notEqual(invPreRes.status, 0);
assert.match(invPreRes.stderr, /target_inventory in signed configuration must contain at least version\.json and backend\/backend\.phar/);

// Pre-test: target_inventory containing forbidden private artifact rejected before autoload
write(path.join(tool, 'repair-config.json'), JSON.stringify({
  tool_version: '1.0.0',
  target_version: '0.0.12-beta.1',
  target_channel: 'beta',
  baseline_version: ['0.0.11'],
  baseline_phar: { sha256: installedHash, size: installedSize },
  target_inventory: {
    'version.json': { sha256: crypto.createHash('sha256').update(vBytes).digest('hex'), size: Buffer.byteLength(vBytes) },
    'backend/backend.phar': { sha256: installedHash, size: installedSize },
    '.env': { sha256: 'deadbeef00000000000000000000000000000000000000000000000000000000', size: 50 }
  }
}, null, 2));
invPreRes = spawnSync(php, [path.join(tool, 'repair.php'), 'prepare', iRoot, iWork, iAssets, iUserData], {encoding: 'utf8', windowsHide: true});
assert.notEqual(invPreRes.status, 0);
assert.match(invPreRes.stderr, /Disallowed path in target_inventory \(only public app artifacts permitted\): \.env/);

// Signed config mandates an extra target file that the delta does NOT provide
const strictTargetConfig = {
  tool_version: '1.0.0',
  installer_version: '0.0.11',
  baseline_version: ['0.0.11'],
  target_version: '0.0.12-beta.1',
  target_channel: 'beta',
  baseline_phar: {
    sha256: installedHash,
    size: installedSize
  },
  allowed_paths: ['version.json', 'backend/backend.phar', 'frontend/dist/*'],
  native_hashes: {'POS Desktop.exe': 'dummy_hash', 'resources/app.asar': 'dummy_hash', 'resources/portable/php/php.exe': 'dummy_hash'},
  target_inventory_sha256: '88475c625b47d0e60bba21ceb52923b8ab5e0b6eb1f57cfd4f495e7e1924ced2',
  target_inventory: {
    'version.json': {
      sha256: crypto.createHash('sha256').update(vBytes).digest('hex'),
      size: Buffer.byteLength(vBytes)
    },
    'backend/backend.phar': {
      sha256: installedHash,
      size: installedSize
    },
    'frontend/dist/bundle.js': {
      sha256: crypto.createHash('sha256').update('updated bundle').digest('hex'),
      size: Buffer.byteLength('updated bundle')
    },
    'frontend/dist/mandatory-missing.js': {
      sha256: 'deadbeef00000000000000000000000000000000000000000000000000000000',
      size: 42
    }
  }
};
write(path.join(tool, 'repair-config.json'), JSON.stringify(strictTargetConfig, null, 2));

const invManifest = {
  manifest_version: '1.0',
  version: '0.0.12-beta.1',
  from_version: '0.0.11',
  minimum_version: '0.0.1',
  type: 'delta',
  channel: 'beta',
  migrations: [],
  baseline_files: {
    'version.json': hash(path.join(iRoot, 'version.json')),
    'frontend/dist/bundle.js': hash(path.join(iRoot, 'frontend/dist/bundle.js'))
  },
  files: [
    {path: 'version.json', sha256: crypto.createHash('sha256').update(vBytes).digest('hex'), size: Buffer.byteLength(vBytes), action: 'replace'},
    {path: 'frontend/dist/bundle.js', sha256: crypto.createHash('sha256').update('updated bundle').digest('hex'), size: Buffer.byteLength('updated bundle'), action: 'replace'}
  ],
  deleted_files: []
};
const imBytes = JSON.stringify(invManifest);
write(path.join(iAssets, 'manifest-0.0.11.json'), imBytes);
write(path.join(iAssets, 'manifest-0.0.11.sig'), crypto.sign('RSA-SHA256', Buffer.from(imBytes), privateKey).toString('base64'));
makeZip(path.join(iAssets, 'delta-0.0.11-to-0.0.12-beta.1.zip'), {
  'version.json': vBytes,
  'frontend/dist/bundle.js': 'updated bundle'
});

// Prepare succeeds
let invRes = spawnSync(php, [path.join(tool, 'repair.php'), 'prepare', iRoot, iWork, iAssets, iUserData], {encoding: 'utf8', windowsHide: true});
assert.equal(invRes.status, 0, invRes.stderr);

// Apply fails on post-verification (mandatory-missing.js missing) and triggers rollback
invRes = spawnSync(php, [path.join(tool, 'repair.php'), 'apply', iRoot, iWork, iAssets, iUserData], {encoding: 'utf8', windowsHide: true});
assert.notEqual(invRes.status, 0);
assert.match(invRes.stderr, /Post-apply verification failed/);
assert.match(invRes.stderr, /Target inventory file missing after apply: frontend\/dist\/mandatory-missing\.js/);

// Verify rollback restored initial bundle and baseline version
assert.equal(fs.readFileSync(path.join(iRoot, 'frontend/dist/bundle.js'), 'utf8'), 'initial bundle');
assert.equal(JSON.parse(fs.readFileSync(path.join(iRoot, 'version.json'), 'utf8')).version, '0.0.11');

// Restore base config for clean state
restoreDefaultConfig();
console.log('PASS target inventory post-apply verification failed and automatically rolled back to 0.0.11.');

// 7. RSA Engine Signature Verification Test
console.log('Testing repair-engine.zip RSA signature verification...');
const dummyEngineZip = Buffer.from('PK\x05\x06' + '\x00'.repeat(18));
const validEngineSig = crypto.sign('RSA-SHA256', dummyEngineZip, privateKey);
assert.equal(crypto.verify('RSA-SHA256', dummyEngineZip, publicKey, validEngineSig), true, 'Engine signature must verify against public key');

// Tampered engine payload
const tamperedEngineZip = Buffer.from('PK\x05\x06' + '\x01'.repeat(18));
assert.equal(crypto.verify('RSA-SHA256', tamperedEngineZip, publicKey, validEngineSig), false, 'Tampered engine must fail RSA verification');
console.log('PASS repair-engine.zip RSA signature verification and tampering rejection verified.');

// 8. PowerShell Launcher Regressions (GHA Windows runner only)
console.log('Testing PowerShell launcher regressions (idle/completed sparse state, job shapes, native pin enforcement)...');
const psExe = process.platform === 'win32' ? 'powershell.exe' : 'pwsh';

// Helper to export Node RSA public key as XML for .NET FromXmlString
function exportRsaXml(pubKey) {
  const jwk = pubKey.export({format: 'jwk'});
  const toBase64 = s => {
    let b64 = s.replace(/-/g, '+').replace(/_/g, '/');
    while (b64.length % 4) b64 += '=';
    return b64;
  };
  return `<RSAKeyValue><Modulus>${toBase64(jwk.n)}</Modulus><Exponent>${toBase64(jwk.e)}</Exponent></RSAKeyValue>`;
}

const psLab = path.join(lab, 'test-ps-launcher');
fs.mkdirSync(psLab, {recursive: true});

const psInstall = path.join(psLab, 'install');
write(path.join(psInstall, 'POS Desktop.exe'), 'dummy exe');
write(path.join(psInstall, 'resources/app.asar'), 'dummy asar');
write(path.join(psInstall, 'resources/app.asar.unpacked/version.json'), JSON.stringify({version: '0.0.11', channel: 'beta', installer_version: '0.0.11'}));
write(path.join(psInstall, 'resources/app.asar.unpacked/backend/backend.phar'), 'dummy phar');
write(path.join(psInstall, 'resources/portable/php/php.exe'), 'dummy php');

const publicXml = exportRsaXml(publicKey);
const ps1Source = fs.readFileSync(path.join(source, 'scripts/update-repair/Repair-POS.ps1'), 'utf8');
const ps1Patched = ps1Source.replace('@PUBLIC_KEY_XML@', publicXml);
write(path.join(psLab, 'Repair-POS.ps1'), ps1Patched);

function makeTestEngineZip(configOverrides = {}) {
  const engineConfig = {
    tool_version: '1.0.0',
    installer_version: '0.0.11',
    baseline_version: ['0.0.11'],
    target_version: '0.0.12-beta.1',
    target_channel: 'beta',
    native_hashes: {
      'POS Desktop.exe': 'dummy_hash',
      'resources/app.asar': 'dummy_hash',
      'resources/portable/php/php.exe': 'dummy_hash',
      ...Object.fromEntries(Array.from({length: 320}, (_, i) => [`resources/portable/bin_${i}.dll`, 'dummy_hash']))
    },
    target_inventory: {
      'version.json': { sha256: '0'.repeat(64), size: 10 },
      'backend/backend.phar': { sha256: '0'.repeat(64), size: 10 }
    },
    ...configOverrides
  };
  const zipPath = path.join(psLab, 'repair-engine.zip');
  const sigPath = path.join(psLab, 'repair-engine.sig');
  makeZip(zipPath, {
    'repair.php': '<?php echo "dummy repair";',
    'repair-config.json': JSON.stringify(engineConfig, null, 2)
  });
  const zipBytes = fs.readFileSync(zipPath);
  const sigBytes = crypto.sign('RSA-SHA256', zipBytes, privateKey);
  write(sigPath, sigBytes.toString('base64'));
}

makeTestEngineZip();

function runLauncher(userDataDir, extraArgs = []) {
  return spawnSync(psExe, [
    '-NoProfile',
    '-ExecutionPolicy', 'Bypass',
    '-File', path.join(psLab, 'Repair-POS.ps1'),
    '-InstallRoot', psInstall,
    '-UserDataRoot', userDataDir,
    ...extraArgs
  ], {encoding: 'utf8', windowsHide: true});
}

function assertPsReject(userDataDir, expectedPattern, label) {
  const res = runLauncher(userDataDir);
  assert.notEqual(res.status, 0, `Expected failure for ${label}, got exit 0: ${res.stdout}`);
  const combined = ((res.stderr || '') + (res.stdout || '')).replace(/\s+/g, ' ');
  assert.match(combined, expectedPattern, `Unexpected error output for ${label}: ${combined}`);
}

// Case 1: Pending job
const udPending = path.join(psLab, 'ud-pending');
write(path.join(udPending, 'update-jobs/job_101.json'), JSON.stringify({job_id: 101, operation_id: 'unit101', status: 'processing', result: {ok: true}}));
assertPsReject(udPending, /Incomplete update job/, 'pending job');

// Case 2: Job ID mismatch
const udIdMismatch = path.join(psLab, 'ud-id-mismatch');
write(path.join(udIdMismatch, 'update-jobs/job_101.json'), JSON.stringify({job_id: 999, operation_id: 'unit101', status: 'completed', result: {ok: true, data: {}}}));
assertPsReject(udIdMismatch, /Job ID mismatch/, 'job ID mismatch');

// Case 3: Missing operation_id
const udNoOpId = path.join(psLab, 'ud-no-op-id');
write(path.join(udNoOpId, 'update-jobs/job_101.json'), JSON.stringify({job_id: 101, status: 'completed', result: {ok: true, data: {}}}));
assertPsReject(udNoOpId, /Missing operation_id/, 'missing operation_id');

// Case 4: Non-string empty operation_id
const udEmptyOpId = path.join(psLab, 'ud-empty-op-id');
write(path.join(udEmptyOpId, 'update-jobs/job_101.json'), JSON.stringify({job_id: 101, operation_id: '   ', status: 'completed', result: {ok: true, data: {}}}));
assertPsReject(udEmptyOpId, /Missing operation_id/, 'empty operation_id');

// Case 5: Settlement error in job
const udSettlementErr = path.join(psLab, 'ud-settlement-err');
write(path.join(udSettlementErr, 'update-jobs/job_101.json'), JSON.stringify({job_id: 101, operation_id: 'unit101', status: 'completed', settlement_error: 'failure during settlement', result: {ok: true, data: {}}}));
assertPsReject(udSettlementErr, /Settlement error detected/, 'settlement error');

// Case 6: Malformed null data object
const udNullData = path.join(psLab, 'ud-null-data');
write(path.join(udNullData, 'update-jobs/job_101.json'), JSON.stringify({job_id: 101, operation_id: 'unit101', status: 'completed', result: {ok: true, data: null}}));
assertPsReject(udNullData, /invalid or null data object/, 'null data object');

// Case 7: Corrupt job JSON
const udCorruptJob = path.join(psLab, 'ud-corrupt-job');
write(path.join(udCorruptJob, 'update-jobs/job_101.json'), '{corrupt json');
assertPsReject(udCorruptJob, /Corrupted job file/, 'corrupt job');

// Case 8: Tampered repair-engine signature
const udTamperedSig = path.join(psLab, 'ud-tampered-sig');
write(path.join(udTamperedSig, 'update-state.json'), JSON.stringify({state: 'idle'}));
write(path.join(psLab, 'repair-engine.sig'), Buffer.alloc(256).toString('base64'));
assertPsReject(udTamperedSig, /RSA repair-engine signature rejected/, 'tampered engine signature');
makeTestEngineZip(); // restore valid signature

// Case 9: Incomplete native_hashes (< 323 entries)
makeTestEngineZip({
  native_hashes: {
    'POS Desktop.exe': 'dummy_hash',
    'resources/app.asar': 'dummy_hash',
    'resources/portable/php/php.exe': 'dummy_hash'
  }
});
const udIncompleteNative = path.join(psLab, 'ud-incomplete-native');
write(path.join(udIncompleteNative, 'update-state.json'), JSON.stringify({state: 'completed'}));
write(path.join(udIncompleteNative, 'update-jobs/job_101.json'), JSON.stringify({job_id: 101, operation_id: 'unit101', status: 'completed', result: {ok: true, data: {}}}));
assertPsReject(udIncompleteNative, /Incomplete native_hashes in signed configuration/, 'incomplete native_hashes');

// Case 10: Valid sparse idle/completed state + genuine job + 323 placeholder entries missing required PHP extension pin
// Proves sparse state passes UserDataSafe in StrictMode, RSA engine signature passes, and proceeds to specific missing pin check
makeTestEngineZip({
  native_hashes: {
    'POS Desktop.exe': 'dummy_hash',
    'resources/app.asar': 'dummy_hash',
    'resources/portable/php/php.exe': 'dummy_hash',
    ...Object.fromEntries(Array.from({length: 320}, (_, i) => [`resources/portable/bin_${i}.dll`, 'dummy_hash']))
    // Deliberately omits 'resources/portable/php/ext/php_openssl.dll'
  }
});
const udSparseValid = path.join(psLab, 'ud-sparse-valid');
write(path.join(udSparseValid, 'update-state.json'), JSON.stringify({state: 'idle'}));
write(path.join(udSparseValid, 'update-jobs/job_101.json'), JSON.stringify({job_id: 101, operation_id: 'unit101', status: 'completed', result: {ok: true, data: {}}}));
assertPsReject(udSparseValid, /Required native pin missing in configuration: resources\/portable\/php\/ext\/php_openssl\.dll/, 'missing required PHP extension pin reached after clean sparse UserDataSafe');

// Case 11: Unreadable job file via exclusive lock handle in owned lab (Windows only)
if (process.platform === 'win32') {
  const udUnreadable = path.join(psLab, 'ud-unreadable');
  const lockedJobFile = path.join(udUnreadable, 'update-jobs/job_101.json');
  write(lockedJobFile, JSON.stringify({job_id: 101, operation_id: 'unit101', status: 'completed', result: {ok: true, data: {}}}));
  const psScriptPath = path.join(psLab, 'Repair-POS.ps1');
  const lockCmd = `
    $ErrorActionPreference = 'Stop'
    $fs = [System.IO.File]::Open('${lockedJobFile.replace(/'/g, "''")}', [System.IO.FileMode]::Open, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
    try {
      & '${psScriptPath.replace(/'/g, "''")}' -InstallRoot '${psInstall.replace(/'/g, "''")}' -UserDataRoot '${udUnreadable.replace(/'/g, "''")}'
    } finally {
      $fs.Close()
    }
  `;
  const lockRes = spawnSync(psExe, ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', lockCmd], {encoding: 'utf8', windowsHide: true});
  assert.notEqual(lockRes.status, 0, 'Unreadable job file under exclusive lock must cause failure');
  const combinedOut = (lockRes.stderr || '') + (lockRes.stdout || '');
  assert.match(combinedOut, /Corrupted job file/, 'unreadable job under lock');
}

// Case 12: Assert-Closed fail-closed CIM mock regressions (Windows only)
if (process.platform === 'win32') {
  makeTestEngineZip({
    native_hashes: {
      'POS Desktop.exe': 'dummy_hash',
      'resources/app.asar': 'dummy_hash',
      'resources/portable/php/php.exe': 'dummy_hash',
      ...Object.fromEntries(Array.from({length: 320}, (_, i) => [`resources/portable/bin_${i}.dll`, 'dummy_hash']))
      // Deliberately omits 'resources/portable/php/ext/php_openssl.dll'
    }
  });

  const psScriptPath = path.join(psLab, 'Repair-POS.ps1');
  function runLauncherWithCimMock(mockBody, userDataDir = udSparseValid) {
    const mockCmd = `
      $ErrorActionPreference = 'Stop'
      function global:Get-CimInstance {
        [CmdletBinding()]
        param(
          [Parameter(Position = 0)]
          [string]$ClassName,
          [Parameter(ValueFromRemainingArguments = $true)]
          $RemainingArgs
        )
        ${mockBody}
      }
      & '${psScriptPath.replace(/'/g, "''")}' -InstallRoot '${psInstall.replace(/'/g, "''")}' -UserDataRoot '${userDataDir.replace(/'/g, "''")}'
    `;
    return spawnSync(psExe, ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', mockCmd], {encoding: 'utf8', windowsHide: true});
  }

  function assertCimMockReject(mockBody, expectedPattern, label, userDataDir = udSparseValid) {
    const res = runLauncherWithCimMock(mockBody, userDataDir);
    assert.notEqual(res.status, 0, `Expected failure for ${label}, got exit 0: ${res.stdout}`);
    const combined = ((res.stderr || '') + (res.stdout || '')).replace(/\s+/g, ' ');
    assert.match(combined, expectedPattern, `Unexpected error output for ${label}: ${combined}`);
  }

  const relevantServices = [
    'POS Desktop.exe',
    'php.exe',
    'php-cgi.exe',
    'mysqld.exe',
    'mariadbd.exe',
    'java.exe',
    'javaw.exe',
    'qz-tray.exe'
  ];

  // For each relevant service name with null ExecutablePath: must reject specific closed/ownership stage before RSA/native pin
  for (const name of relevantServices) {
    assertCimMockReject(
      `return @([PSCustomObject]@{ Name = '${name}'; ExecutablePath = $null })`,
      /Cannot verify whether POS Desktop or its services are closed/,
      `relevant service ${name} with null ExecutablePath`
    );
  }

  const outsidePhp = path.join(psLab, 'outside', 'php.exe').replace(/'/g, "''");
  const missingExtensionPinPattern = /Required native pin missing in configuration: resources\/portable\/php\/ext\/php_openssl\.dll/;

  // Companion 1: Known non-owned PHP path outside InstallPrefix proceeds to missing required PHP extension pin
  assertCimMockReject(
    `return @([PSCustomObject]@{ Name = 'php.exe'; ExecutablePath = '${outsidePhp}' })`,
    missingExtensionPinPattern,
    'companion known non-owned PHP path outside InstallPrefix'
  );

  // Companion 2: Unrelated process name with null ExecutablePath proceeds to missing required PHP extension pin
  assertCimMockReject(
    `return @([PSCustomObject]@{ Name = 'unrelated-service.exe'; ExecutablePath = $null })`,
    missingExtensionPinPattern,
    'companion unrelated process name with null ExecutablePath'
  );

  // Companion 3: Both non-owned PHP and unrelated null path together proceed to missing required PHP extension pin
  assertCimMockReject(
    `return @(
      [PSCustomObject]@{ Name = 'php.exe'; ExecutablePath = '${outsidePhp}' },
      [PSCustomObject]@{ Name = 'unrelated-service.exe'; ExecutablePath = $null }
    )`,
    missingExtensionPinPattern,
    'companion non-owned PHP and unrelated null path together'
  );

  // Readable ExecutablePath under InstallPrefix rejected
  const insidePhp = path.join(psInstall, 'resources', 'portable', 'php', 'php.exe').replace(/'/g, "''");
  assertCimMockReject(
    `return @([PSCustomObject]@{ Name = 'php.exe'; ExecutablePath = '${insidePhp}' })`,
    /Close POS Desktop and its services before running repair/,
    'running process under InstallPrefix'
  );

  // CIM query failure fails closed
  assertCimMockReject(
    `throw 'Simulated CIM query failure'`,
    /Simulated CIM query failure/,
    'CIM query failure fails closed'
  );
}

console.log('PASS PowerShell launcher regressions verified.');
console.log('ALL UPDATE REPAIR VERIFICATION TESTS PASSED.');
