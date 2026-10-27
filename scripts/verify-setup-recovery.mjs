import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { listPackage, extractFile } from '@electron/asar';

const repoRoot = process.cwd();
const distElectron = path.join(repoRoot, 'dist-electron');
const winUnpacked = path.join(distElectron, 'win-unpacked');
const resources = path.join(winUnpacked, 'resources');
const asarPath = path.join(resources, 'app.asar');
const unpacked = path.join(resources, 'app.asar.unpacked');
const portable = path.join(resources, 'portable');

// 1. Enforce Release and Installer Identity
const pkg = JSON.parse(fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));
const ver = JSON.parse(fs.readFileSync(path.join(repoRoot, 'version.json'), 'utf8'));

const targetVersion = '0.0.12';
assert.equal(pkg.version, targetVersion, `package.json version must be ${targetVersion}`);
assert.equal(ver.version, targetVersion, `version.json version must be ${targetVersion}`);
assert.equal(ver.application_version, targetVersion, `version.json application_version must be ${targetVersion}`);
assert.equal(ver.installer_version, targetVersion, `version.json installer_version must be ${targetVersion}`);
assert.equal(ver.channel, 'stable', 'version.json channel must be stable');
assert.equal(ver.update_channel, 'stable', 'version.json update_channel must be stable');
assert.equal(ver.release_type, 'setup', 'version.json release_type must be setup');

if (!fs.existsSync(distElectron)) {
  throw new Error(`dist-electron directory does not exist: ${distElectron}`);
}

const installerName = `POS-Desktop-Setup-${targetVersion}.exe`;
const installerPath = path.join(distElectron, installerName);
assert.ok(fs.existsSync(installerPath), `Installer executable missing: ${installerPath}`);

assert.ok(fs.existsSync(asarPath), `app.asar missing: ${asarPath}`);
const unpackedVerPath = path.join(unpacked, 'version.json');
assert.ok(fs.existsSync(unpackedVerPath), `app.asar.unpacked/version.json missing: ${unpackedVerPath}`);
const unpackedVer = JSON.parse(fs.readFileSync(unpackedVerPath, 'utf8'));
assert.equal(unpackedVer.version, targetVersion, 'unpacked version.json version must match');

const asarVerBuf = extractFile(asarPath, 'version.json');
const asarVer = JSON.parse(asarVerBuf.toString('utf8'));
assert.equal(asarVer.version, targetVersion, 'internal asar version.json version must match');

// 2. Key Recovery Source Byte Equality
const recoveryFiles = [
  'electron/main.js',
  'electron/services/delta-update-handoff.js',
  'electron/services/php-server.js'
];

for (const rel of recoveryFiles) {
  const repoFile = path.join(repoRoot, rel);
  assert.ok(fs.existsSync(repoFile), `Repository source file missing: ${repoFile}`);
  const repoBuf = fs.readFileSync(repoFile);
  const asarBuf = extractFile(asarPath, path.normalize(rel));

  const repoHash = crypto.createHash('sha256').update(repoBuf).digest('hex');
  const asarHash = crypto.createHash('sha256').update(asarBuf).digest('hex');
  assert.equal(asarHash, repoHash, `Byte equality failure for shipped ${rel}: asar=${asarHash} repo=${repoHash}`);
}

// 3. Built PHAR Content Comparison via Bundled PHP
const shippedPhar = path.join(unpacked, 'backend', 'backend.phar');
assert.ok(fs.existsSync(shippedPhar), `Shipped backend.phar missing: ${shippedPhar}`);

const phpCandidates = [
  process.env.PHP_BINARY,
  path.join(repoRoot, 'portable', 'php', 'php.exe')
].filter(Boolean);

const phpExe = phpCandidates.find(p => fs.existsSync(p));
if (!phpExe) {
  throw new Error('Bundled PHP executable not found at portable/php/php.exe or in PHP_BINARY');
}

const verifyPhpScript = `
$pharPath = $argv[1];
$repoRoot = $argv[2];

try {
    $phar = new Phar($pharPath);
} catch (Throwable $e) {
    fwrite(STDERR, "Unable to open PHAR: " . $e->getMessage() . "\\n");
    exit(1);
}

$files = [
    // Catalog trigger DEFINER healing
    'Services/MigrationService.php' => 'backend/Services/MigrationService.php',
    'Services/ProductService.php' => 'backend/Services/ProductService.php',
    'cli/migrate.php' => 'backend/cli/migrate.php',
    // Beta10 Admin Update Center and update engine
    'Services/BackgroundUpdateApplyService.php' => 'backend/Services/BackgroundUpdateApplyService.php',
    'Controllers/UpdateController.php' => 'backend/Controllers/UpdateController.php',
    'Services/DeltaUpdateService.php' => 'backend/Services/DeltaUpdateService.php',
    'Services/UpdateAssetDownloader.php' => 'backend/Services/UpdateAssetDownloader.php',
    'Services/UpdateService.php' => 'backend/Services/UpdateService.php',
    'Helpers/JobQueue.php' => 'backend/Helpers/JobQueue.php',
    'Services/ChannelTransitionService.php' => 'backend/Services/ChannelTransitionService.php',
];

$normalizedPhar = str_replace('\\\\', '/', realpath($pharPath));

foreach ($files as $pharRel => $repoRel) {
    if (!isset($phar[$pharRel])) {
        fwrite(STDERR, "PHAR entry missing: {$pharRel}\\n");
        exit(2);
    }
    $pharContent = file_get_contents("phar://{$normalizedPhar}/{$pharRel}");
    $repoFile = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $repoRel);
    if (!is_file($repoFile)) {
        fwrite(STDERR, "Repo file missing: {$repoFile}\\n");
        exit(3);
    }
    $repoContent = file_get_contents($repoFile);
    if (hash('sha256', $pharContent) !== hash('sha256', $repoContent)) {
        fwrite(STDERR, "Content hash mismatch in PHAR for: {$pharRel} vs {$repoRel}\\n");
        exit(4);
    }
}

$sig = $phar->getSignature();
echo json_encode([
    'valid' => true,
    'hash_type' => $sig['hash_type'] ?? 'unknown',
    'hash' => $sig['hash'] ?? ''
]);
exit(0);
`;

const phpResult = spawnSync(phpExe, ['-r', verifyPhpScript, shippedPhar, repoRoot], {
  encoding: 'utf8',
  windowsHide: true
});

if (phpResult.status !== 0) {
  throw new Error(`PHAR verification via bundled PHP failed (exit code ${phpResult.status}): ${phpResult.stderr || phpResult.stdout}`);
}

const pharInfo = JSON.parse(phpResult.stdout.trim());
assert.ok(pharInfo.valid, 'PHAR verification must return valid: true');

// 4. Secret & Runtime Data Exclusions in ASAR and Portable Tree
const asarEntries = listPackage(asarPath).map(p => p.replaceAll('\\', '/').replace(/^\//, ''));
const forbiddenAsarPatterns = [
  /^(?:release|certs|docs|scripts|\.github|\.git)(?:\/|$)/i,
  /(?:^|\/)(?:\.env|db_credentials\.json|cookies\.json|private[-_]key\.pem)(?:\/|$)/i,
  /\.(?:pfx|p12|key)$/i,
  /(?:^|\/)(?:mysql_data|storage\/|logs\/)/i
];

const forbiddenAsarMatches = asarEntries.filter(entry =>
  forbiddenAsarPatterns.some(pattern => pattern.test(entry))
);
assert.equal(
  forbiddenAsarMatches.length,
  0,
  `Forbidden private/runtime entries detected in ASAR: ${forbiddenAsarMatches.join(', ')}`
);

// Scan portable directory in resources
function scanDir(dir) {
  const files = [];
  if (!fs.existsSync(dir)) return files;
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      files.push(...scanDir(full));
    } else {
      files.push(full);
    }
  }
  return files;
}

const portableFiles = scanDir(portable).map(f => path.relative(portable, f).replaceAll('\\', '/'));

// Legitimate vendor runtime configurations shipped with pinned Java / QZ distributions
const allowedVendorProperties = new Set([
  'java/conf/logging.properties',
  'java/conf/management/management.properties',
  'java/conf/net.properties',
  'java/conf/sound.properties',
  'java/lib/psfontj2d.properties',
  'qz-tray/qz-tray.properties'
]);

const forbiddenPortable = portableFiles.filter(rel => {
  // Never package runtime database, storage, or temporary data directories
  if (/(?:^|\/)data\//i.test(rel) || /(?:^|\/)mysql_data\//i.test(rel) || /(?:^|\/)storage\//i.test(rel)) return true;
  // Ban private keys, key stores, and private certificate archives
  if (/\.(?:p12|pfx|key)$/i.test(rel)) return true;
  // Ban private/user-generated override certificates
  if (/override\.crt$/i.test(rel)) return true;
  // Strictly scope properties files: only legitimate pinned vendor configs allowed
  if (/\.properties$/i.test(rel)) {
    return !allowedVendorProperties.has(rel);
  }
  // Public vendor CA certs in PHP/Java are allowed; reject arbitrary private/user pem files
  if (/\.pem$/i.test(rel)) {
    return !/^php\/(?:extras\/ssl\/)?cacert\.pem$/i.test(rel);
  }
  return false;
});

assert.equal(
  forbiddenPortable.length,
  0,
  `Forbidden files found in packaged portable tree: ${forbiddenPortable.join(', ')}`
);

// 5. Safe Upgrade Cleanup Guard & Unchanged AppId
const nshPath = path.join(repoRoot, 'build', 'installer.nsh');
assert.ok(fs.existsSync(nshPath), `installer.nsh missing: ${nshPath}`);
const nshContent = fs.readFileSync(nshPath, 'utf8');
assert.ok(
  nshContent.includes('${IfNot} ${isUpdated}'),
  'installer.nsh must contain ${IfNot} ${isUpdated} guard'
);
assert.ok(
  nshContent.includes('uninstall-data.ps1'),
  'installer.nsh must delegate to uninstall-data.ps1'
);

assert.equal(pkg.build.appId, 'com.abdoteck.pos', 'build.appId must remain unchanged: com.abdoteck.pos');
assert.equal(pkg.build.productName, 'POS Desktop', 'build.productName must remain unchanged: POS Desktop');
assert.equal(pkg.build.directories.output, 'dist-electron', 'build.directories.output must remain dist-electron');

const uninstallHelper = path.join(resources, 'uninstall-data.ps1');
assert.ok(fs.existsSync(uninstallHelper), `uninstall-data.ps1 missing in packaged resources: ${uninstallHelper}`);

console.log(JSON.stringify({
  setupRecoveryVerified: true,
  identity: {
    version: targetVersion,
    channel: ver.channel,
    releaseType: ver.release_type,
    installerAsset: installerName
  },
  keyRecoverySourcesMatched: recoveryFiles,
  pharHealingServicesMatched: [
    'Services/MigrationService.php',
    'Services/ProductService.php',
    'cli/migrate.php',
    'Services/BackgroundUpdateApplyService.php',
    'Controllers/UpdateController.php',
    'Services/DeltaUpdateService.php',
    'Services/UpdateAssetDownloader.php',
    'Services/UpdateService.php',
    'Helpers/JobQueue.php',
    'Services/ChannelTransitionService.php'
  ],
  pharSignature: pharInfo.hash_type,
  secretExclusionsVerified: true,
  portableFiltersVerified: true,
  appId: pkg.build.appId,
  isUpdatedGuardVerified: true
}, null, 2));
