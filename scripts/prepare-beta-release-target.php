<?php
declare(strict_types=1);

// Strict CI guards before any actions
if (getenv('GITHUB_ACTIONS') !== 'true' || !getenv('RUNNER_TEMP')) {
    throw new RuntimeException('Ephemeral Windows GitHub Actions runner required.');
}

if (getenv('GITHUB_REPOSITORY') !== 'ABDO-TECK/pos-desktop-updates') {
    throw new RuntimeException("Invalid repository: '" . (string)getenv('GITHUB_REPOSITORY') . "'. Must be exactly 'ABDO-TECK/pos-desktop-updates'.");
}

foreach (['UPDATE_PRIVATE_KEY', 'SOURCE_PRIVATE_SIGNING_KEY', 'PRIVATE_KEY'] as $varName) {
    if (getenv($varName)) {
        throw new RuntimeException("Security violation: {$varName} must not exist during prepare-beta-release-target.");
    }
}

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\ManifestSignatureService;

function failTarget(string $message): never
{
    fwrite(STDERR, "Target preparation error: {$message}\n");
    exit(1);
}

function normalizeRelativePath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function assertSafeAppPath(string $path): void
{
    $normalized = normalizeRelativePath($path);
    $segments = explode('/', $normalized);
    if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, ':') || in_array('..', $segments, true) || in_array('.', $segments, true) || in_array('', $segments, true)) {
        failTarget("Unsafe path rejected: {$path}");
    }
    if (preg_match('/(^|\/)(?:\.env|private[_-]?key|credentials?|secrets?)(?:\.|\/|$)/i', $normalized)) {
        failTarget("Credential-like path rejected: {$path}");
    }
}

function assertNoReparse(string $path): void
{
    $ancestor = $path;
    while ($ancestor !== '' && $ancestor !== '.' && $ancestor !== DIRECTORY_SEPARATOR) {
        if (file_exists($ancestor) && is_link($ancestor)) {
            failTarget("Reparse point or symlink rejected: {$ancestor}");
        }
        $parent = dirname($ancestor);
        if ($parent === $ancestor) {
            break;
        }
        $ancestor = $parent;
    }
}

// Parse command line arguments
$options = getopt('', ['lab-dir:', 'assets-dir:']);
$labDir = $options['lab-dir'] ?? null;
$assetsDir = $options['assets-dir'] ?? null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--lab-dir=')) {
        $labDir = substr($arg, strlen('--lab-dir='));
    } elseif (str_starts_with($arg, '--assets-dir=')) {
        $assetsDir = substr($arg, strlen('--assets-dir='));
    }
}

if (!$labDir || !is_dir($labDir)) {
    failTarget('--lab-dir must identify an existing directory.');
}
if (!$assetsDir || !is_dir($assetsDir)) {
    failTarget('--assets-dir must identify an existing directory.');
}

assertNoReparse($labDir);
assertNoReparse($assetsDir);

$labDir = realpath($labDir);
$assetsDir = realpath($assetsDir);
if ($labDir === false || $assetsDir === false) {
    failTarget('Could not resolve lab or assets directory path.');
}

// Bind to canonical runner temp path
$canonicalLab = realpath((string)getenv('RUNNER_TEMP') . DIRECTORY_SEPARATOR . 'setup11-beta-release-lab');
if ($canonicalLab === false || $labDir !== $canonicalLab) {
    failTarget("Lab directory must bind strictly to canonical path: {$canonicalLab}");
}

// 1. Verify baseline Setup 11 inventory and unpacked source files
$setup11InvPath = $labDir . DIRECTORY_SEPARATOR . 'setup11-inventory.json';
if (!is_file($setup11InvPath)) {
    failTarget("Setup 11 baseline inventory missing: {$setup11InvPath}");
}
$setup11InvHash = strtolower((string)hash_file('sha256', $setup11InvPath));
$expected11InvHash = '88475c625b47d0e60bba21ceb52923b8ab5e0b6eb1f57cfd4f495e7e1924ced2';
if ($setup11InvHash !== $expected11InvHash) {
    failTarget("Setup 11 baseline inventory SHA-256 mismatch. Expected: {$expected11InvHash}, got: {$setup11InvHash}");
}

$setup11Inventory = json_decode((string)file_get_contents($setup11InvPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($setup11Inventory) || ($setup11Inventory['version'] ?? '') !== '0.0.11' || empty($setup11Inventory['files'])) {
    failTarget('Invalid baseline inventory content for Setup 0.0.11.');
}

$baselineAppRoot = $labDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'app.asar.unpacked';
if (!is_dir($baselineAppRoot)) {
    failTarget("Installed Setup 0.0.11 unpacked application root missing: {$baselineAppRoot}");
}

// 2. Locate and verify signed release package in assetsDir
$manifestPath = $assetsDir . DIRECTORY_SEPARATOR . 'manifest.json';
$manifestSigPath = $assetsDir . DIRECTORY_SEPARATOR . 'manifest.sig';
$deploymentInvPath = $assetsDir . DIRECTORY_SEPARATOR . 'deployment-inventory.json';

if (!is_file($manifestPath)) failTarget("manifest.json missing in {$assetsDir}");
if (!is_file($manifestSigPath)) failTarget("manifest.sig missing in {$assetsDir}");
if (!is_file($deploymentInvPath)) failTarget("deployment-inventory.json missing in {$assetsDir}");

$deltaZip = $assetsDir . DIRECTORY_SEPARATOR . 'delta-0.0.11-to-0.0.12-beta.1.zip';
if (!is_file($deltaZip)) {
    $deltaZip = $assetsDir . DIRECTORY_SEPARATOR . 'delta.zip';
}
if (!is_file($deltaZip)) {
    failTarget("Delta ZIP package missing in {$assetsDir}");
}

// 3. Verify manifest contents and RSA code signature against pinned updater public key
$manifestJson = (string)file_get_contents($manifestPath);
$manifestSig = (string)file_get_contents($manifestSigPath);
$manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($manifest)) {
    failTarget('manifest.json could not be parsed as JSON.');
}
if (($manifest['version'] ?? '') !== '0.0.12-beta.1') {
    failTarget("Manifest target version must be 0.0.12-beta.1, got: " . ($manifest['version'] ?? ''));
}
if (($manifest['minimum_version'] ?? '') !== '0.0.11') {
    failTarget("Manifest baseline version must be 0.0.11, got: " . ($manifest['minimum_version'] ?? ''));
}
if (($manifest['type'] ?? '') !== 'delta') {
    failTarget("Manifest type must be delta, got: " . ($manifest['type'] ?? ''));
}

$publicKeyPath = __DIR__ . '/../backend/certs/update_public_key.pem';
if (!is_file($publicKeyPath)) {
    failTarget("Pinned public key missing at: {$publicKeyPath}");
}

$signatureService = new ManifestSignatureService();
if (!$signatureService->verifySignature($manifestJson, $manifestSig, $publicKeyPath)) {
    failTarget('Manifest RSA signature verification failed against pinned updater key.');
}

// 4. Read expected target deployment inventory
$deploymentInventory = json_decode((string)file_get_contents($deploymentInvPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($deploymentInventory) || ($deploymentInventory['version'] ?? '') !== '0.0.12-beta.1' || empty($deploymentInventory['files'])) {
    failTarget('Invalid deployment-inventory.json for Candidate 0.0.12-beta.1.');
}

// 5. Initialize candidate-app directory cleanly (fail fresh if already exists)
$candidateApp = $labDir . DIRECTORY_SEPARATOR . 'candidate-app';
if (file_exists($candidateApp)) {
    failTarget("Pre-existing candidate-app directory blocks fresh reconstruction: {$candidateApp}");
}
if (!mkdir($candidateApp, 0755, true) && !is_dir($candidateApp)) {
    failTarget("Could not create candidate-app directory: {$candidateApp}");
}

// 6. Copy baseline files from Setup 11 application
echo "Copying baseline Setup 0.0.11 files into candidate application...\n";
foreach ($setup11Inventory['files'] as $relPath => $baselineInfo) {
    assertSafeAppPath((string)$relPath);
    $sourceFile = $baselineAppRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$relPath);
    if (!is_file($sourceFile)) {
        failTarget("Baseline source file missing: {$sourceFile}");
    }
    $actualHash = hash_file('sha256', $sourceFile);
    if (!hash_equals(strtolower((string)$baselineInfo['sha256']), strtolower((string)$actualHash))) {
        failTarget("Baseline source file SHA-256 mismatch for {$relPath}.");
    }
    if ((int)$baselineInfo['size'] !== filesize($sourceFile)) {
        failTarget("Baseline source file size mismatch for {$relPath}.");
    }

    $destFile = $candidateApp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$relPath);
    $destDir = dirname($destFile);
    assertNoReparse($destDir);
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    if (!copy($sourceFile, $destFile)) {
        failTarget("Failed copying baseline file to candidate: {$destFile}");
    }
}

// 7. Apply verified signed Delta package files
echo "Applying verified signed Delta payload onto candidate application...\n";
$manifestFiles = [];
foreach ($manifest['files'] as $fileEntry) {
    if (!is_array($fileEntry) || !isset($fileEntry['path'], $fileEntry['sha256'], $fileEntry['size'])) {
        failTarget('Malformed file entry in manifest.json.');
    }
    $path = normalizeRelativePath((string)$fileEntry['path']);
    assertSafeAppPath($path);
    $manifestFiles[$path] = $fileEntry;
}

$zip = new ZipArchive();
$openResult = $zip->open($deltaZip);
if ($openResult !== true) {
    failTarget("Could not open Delta ZIP archive {$deltaZip} (code {$openResult}).");
}

$seenZipEntries = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entryName = $zip->getNameIndex($i);
    if ($entryName === false || str_ends_with($entryName, '/')) {
        continue;
    }
    $normEntry = normalizeRelativePath($entryName);
    assertSafeAppPath($normEntry);

    if (isset($seenZipEntries[$normEntry])) {
        $zip->close();
        failTarget("Duplicate entry in Delta ZIP package: {$normEntry}");
    }
    $seenZipEntries[$normEntry] = true;

    if (!isset($manifestFiles[$normEntry])) {
        $zip->close();
        failTarget("Delta ZIP contains file not listed in signed manifest: {$normEntry}");
    }
    $expected = $manifestFiles[$normEntry];
    $content = $zip->getFromIndex($i);
    if ($content === false) {
        $zip->close();
        failTarget("Could not extract file from Delta ZIP: {$normEntry}");
    }

    $actualHash = hash('sha256', $content);
    if (!hash_equals(strtolower((string)$expected['sha256']), strtolower($actualHash))) {
        $zip->close();
        failTarget("Delta payload SHA-256 mismatch for {$normEntry}.");
    }
    if ((int)$expected['size'] !== strlen($content)) {
        $zip->close();
        failTarget("Delta payload size mismatch for {$normEntry}.");
    }

    $destFile = $candidateApp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normEntry);
    $destDir = dirname($destFile);
    assertNoReparse($destDir);
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    if (file_put_contents($destFile, $content) === false) {
        $zip->close();
        failTarget("Could not write candidate file: {$destFile}");
    }
}
$zip->close();

// Ensure all manifest files were provided by the delta ZIP
foreach (array_keys($manifestFiles) as $expectedManifestPath) {
    if (!isset($seenZipEntries[$expectedManifestPath])) {
        failTarget("Manifest declared file missing from Delta ZIP package: {$expectedManifestPath}");
    }
}

// Handle manifest deleted_files if present
if (!empty($manifest['deleted_files']) && is_array($manifest['deleted_files'])) {
    foreach ($manifest['deleted_files'] as $deletedRel) {
        $normDel = normalizeRelativePath((string)$deletedRel);
        assertSafeAppPath($normDel);
        $delPath = $candidateApp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normDel);
        if (is_file($delPath)) {
            unlink($delPath);
        }
    }
}

// 8. Validate reconstructed candidate application against deployment-inventory.json
echo "Validating reconstructed candidate application files against deployment-inventory.json...\n";
foreach ($deploymentInventory['files'] as $relPath => $expectedInfo) {
    assertSafeAppPath((string)$relPath);
    $candidateFile = $candidateApp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$relPath);
    if (!is_file($candidateFile)) {
        failTarget("Deployment inventory file missing in reconstructed candidate: {$relPath}");
    }
    $candHash = hash_file('sha256', $candidateFile);
    if (!hash_equals(strtolower((string)$expectedInfo['sha256']), strtolower((string)$candHash))) {
        failTarget("Reconstructed candidate SHA-256 mismatch for {$relPath}. Expected: {$expectedInfo['sha256']}, got: {$candHash}");
    }
    if ((int)$expectedInfo['size'] !== filesize($candidateFile)) {
        failTarget("Reconstructed candidate size mismatch for {$relPath}. Expected: {$expectedInfo['size']}, got: " . filesize($candidateFile));
    }
}

// Verify no extra untracked files in candidate-app
$candidateAppReal = realpath($candidateApp);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($candidateApp, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $item) {
    if (!$item->isFile()) continue;
    $rel = normalizeRelativePath(substr((string)$item->getRealPath(), strlen($candidateAppReal) + 1));
    if (!isset($deploymentInventory['files'][$rel])) {
        failTarget("Extra unmanaged file detected in candidate application: {$rel}");
    }
}

// Verify version.json in candidate-app
$versionJsonPath = $candidateApp . DIRECTORY_SEPARATOR . 'version.json';
if (!is_file($versionJsonPath)) {
    failTarget("version.json missing in reconstructed candidate: {$versionJsonPath}");
}
$versionData = json_decode((string)file_get_contents($versionJsonPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($versionData) || ($versionData['version'] ?? '') !== '0.0.12-beta.1') {
    failTarget("Reconstructed candidate version.json version mismatch: " . ($versionData['version'] ?? ''));
}
if (($versionData['channel'] ?? '') !== 'beta') {
    failTarget("Reconstructed candidate version.json channel mismatch: " . ($versionData['channel'] ?? ''));
}

// Write deployment inventory to lab marker ONLY (not inside candidate-app to keep candidate unpolluted)
copy($deploymentInvPath, $labDir . DIRECTORY_SEPARATOR . 'candidate-inventory.json');

echo "SUCCESS: Candidate 0.0.12-beta.1 target application reconstructed and verified (without running target code).\n";
exit(0);
