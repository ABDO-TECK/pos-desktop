<?php
declare(strict_types=1);

// Dynamic verification suite runs strictly in GitHub Actions environment.
if (getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('Verification suite runs only in GitHub Actions environment (GITHUB_ACTIONS=true).');
}

if (is_file(__DIR__ . '/../backend/vendor/autoload.php')) {
    require_once __DIR__ . '/../backend/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = __DIR__ . '/../backend/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });
}

use App\Services\{
    ManifestSignatureService,
    ProductInventoryService,
    ShellDeltaManifestService,
    UpdateChannelPolicy,
    UpdateDataCompatibilityService
};

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
}

function assertEqual(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException("Assertion failed: {$message}. Expected " . json_encode($expected) . ", got " . json_encode($actual));
    }
}

/**
 * Lexical filesystem cleaner: never calls getRealPath on symlinks/junctions to avoid unlinking outside targets.
 */
$createdJunctions = [];
$trackedVictims = [];

function createTrackedJunction(string $link, string $target): void
{
    global $createdJunctions;
    $output = [];
    $exitCode = 0;
    exec('cmd /c mklink /J ' . escapeshellarg(str_replace('/', '\\', $link)) . ' ' . escapeshellarg(str_replace('/', '\\', $target)), $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("Failed to create junction from '{$link}' to '{$target}'");
    }
    $createdJunctions[] = $link;
}

function removeTrackedJunction(string $link): void
{
    global $createdJunctions;
    if (file_exists($link) || is_link($link)) {
        $output = [];
        $exitCode = 0;
        exec('cmd /c rmdir ' . escapeshellarg(str_replace('/', '\\', $link)), $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException("Failed to remove junction: '{$link}'");
        }
    }
    $idx = array_search($link, $createdJunctions, true);
    if ($idx !== false) {
        unset($createdJunctions[$idx]);
    }
}

function safeLexicalSubdirCleanup(string $dir): void
{
    $items = scandir($dir);
    if ($items === false) {
        throw new RuntimeException("Cannot list directory for cleanup: '{$dir}'");
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        $lstat = @lstat($path);
        if ($lstat !== false && (is_link($path) || (($lstat['mode'] & 0120000) === 0120000))) {
            if (is_dir($path)) {
                $output = [];
                $exitCode = 0;
                exec('cmd /c rmdir ' . escapeshellarg($path), $output, $exitCode);
                if ($exitCode !== 0) {
                    throw new RuntimeException("Failed to remove link during cleanup: '{$path}'");
                }
            } else {
                if (!unlink($path)) {
                    throw new RuntimeException("Failed to unlink: '{$path}'");
                }
            }
        } elseif (is_dir($path)) {
            safeLexicalSubdirCleanup($path);
            if (!rmdir($path)) {
                throw new RuntimeException("Failed to rmdir: '{$path}'");
            }
        } else {
            if (!unlink($path)) {
                throw new RuntimeException("Failed to unlink: '{$path}'");
            }
        }
    }
}

function safeLexicalCleanup(string $dir, string $expectedMarker): void
{
    if (!file_exists($dir) && !is_link($dir)) {
        return;
    }
    $markerFile = $dir . DIRECTORY_SEPARATOR . '.pos_owned_marker';
    if (!file_exists($markerFile) || trim((string)@file_get_contents($markerFile)) !== $expectedMarker) {
        throw new RuntimeException("Refusing to clean up directory without valid ownership marker: '{$dir}'");
    }

    $items = scandir($dir);
    if ($items === false) {
        throw new RuntimeException("Cannot list directory for cleanup: '{$dir}'");
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        $lstat = @lstat($path);
        if ($lstat !== false && (is_link($path) || (($lstat['mode'] & 0120000) === 0120000))) {
            if (is_dir($path)) {
                $output = [];
                $exitCode = 0;
                exec('cmd /c rmdir ' . escapeshellarg($path), $output, $exitCode);
                if ($exitCode !== 0) {
                    throw new RuntimeException("Failed to remove link during cleanup: '{$path}'");
                }
            } else {
                if (!unlink($path)) {
                    throw new RuntimeException("Failed to unlink: '{$path}'");
                }
            }
        } elseif (is_dir($path)) {
            safeLexicalSubdirCleanup($path);
            if (!rmdir($path)) {
                throw new RuntimeException("Failed to rmdir: '{$path}'");
            }
        } else {
            if (!unlink($path)) {
                throw new RuntimeException("Failed to unlink: '{$path}'");
            }
        }
    }
    if (!rmdir($dir)) {
        throw new RuntimeException("Failed to rmdir root: '{$dir}'");
    }
}

function populateTree(string $root, array $files): void
{
    foreach ($files as $path => $content) {
        $full = $root . '/' . $path;
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($full, $content);
    }
}

function buildDummyPackageZip(string $zipPath, array $filesMap, array $options = []): void
{
    $zip = new ZipArchive();
    $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        throw new RuntimeException("Cannot create test zip: {$zipPath}, code: {$opened}");
    }
    foreach ($filesMap as $path => $content) {
        if (!$zip->addFromString($path, $content)) {
            throw new RuntimeException("addFromString failed for {$path}");
        }
    }
    if (isset($options['extra_files']) && is_array($options['extra_files'])) {
        foreach ($options['extra_files'] as $path => $content) {
            if (!$zip->addFromString($path, $content)) {
                throw new RuntimeException("addFromString failed for extra {$path}");
            }
        }
    }
    if (!empty($options['encrypt_file'])) {
        $encFile = $options['encrypt_file'];
        if (!$zip->setEncryptionName($encFile, ZipArchive::EM_AES_128, 'testpass')) {
            throw new RuntimeException("setEncryptionName failed for {$encFile}");
        }
    }
    if (!empty($options['symlink_file'])) {
        $symFile = $options['symlink_file'];
        if (!$zip->setExternalAttributesName($symFile, ZipArchive::OPSYS_UNIX, (0120777 << 16))) {
            throw new RuntimeException("setExternalAttributesName symlink failed for {$symFile}");
        }
    }
    if (!empty($options['dir_attr_file'])) {
        $dirFile = $options['dir_attr_file'];
        if (!$zip->setExternalAttributesName($dirFile, ZipArchive::OPSYS_DOS, 0x10)) {
            throw new RuntimeException("setExternalAttributesName dir failed for {$dirFile}");
        }
    }
    if (!empty($options['reparse_attr_file'])) {
        $repFile = $options['reparse_attr_file'];
        if (!$zip->setExternalAttributesName($repFile, ZipArchive::OPSYS_DOS, 0x0400)) {
            throw new RuntimeException("setExternalAttributesName reparse failed for {$repFile}");
        }
    }
    if (!empty($options['unknown_attr_file'])) {
        $unkFile = $options['unknown_attr_file'];
        if (!$zip->setExternalAttributesName($unkFile, 99, 0)) {
            throw new RuntimeException("setExternalAttributesName unknown opsys failed for {$unkFile}");
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException("Failed to close zip: {$zipPath}");
    }
}

/**
 * Recompute inventory hashes and shell proofs on modified descriptors to avoid stale digest masking.
 */
function refreshDescriptor(array $descriptor): array
{
    if (isset($descriptor['source_inventory']) && is_array($descriptor['source_inventory'])) {
        $descriptor['source_inventory_sha256'] = ShellDeltaManifestService::canonicalInventoryDigest($descriptor['source_inventory']);
        $descriptor['source_native_shell'] = $descriptor['source_inventory']['native_shell'];
    }
    if (isset($descriptor['target_inventory']) && is_array($descriptor['target_inventory'])) {
        $descriptor['target_inventory_sha256'] = ShellDeltaManifestService::canonicalInventoryDigest($descriptor['target_inventory']);
        $descriptor['target_native_shell'] = $descriptor['target_inventory']['native_shell'];
    }
    return $descriptor;
}

// Owned lab in RUNNER_TEMP with random ownership marker
$runnerTemp = getenv('RUNNER_TEMP');
$tempBase = (is_string($runnerTemp) && $runnerTemp !== '' && is_dir($runnerTemp))
    ? $runnerTemp
    : sys_get_temp_dir();
$lab = rtrim(str_replace('\\', '/', $tempBase), '/') . '/shell-delta-contract-' . bin2hex(random_bytes(8));
if (!mkdir($lab, 0700, true)) {
    throw new RuntimeException("Failed to create owned lab directory: {$lab}");
}

$expectedMarker = 'OWNED_SHELL_DELTA_' . bin2hex(random_bytes(16));
$markerFile = $lab . DIRECTORY_SEPARATOR . '.pos_owned_marker';
if (file_put_contents($markerFile, $expectedMarker) === false) {
    throw new RuntimeException("Failed to write owned lab marker: {$markerFile}");
}

try {
    echo "=== Running Signed Native-Shell Delta Contract Verification Suite ===\n\n";

    // 1. Generate Ephemeral RSA Keys for GHA Test Environment
    $ephemeralKeyA = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($ephemeralKeyA === false) {
        throw new RuntimeException('Failed to generate ephemeral RSA key pair A.');
    }
    $pubKeyDetailsA = openssl_pkey_get_details($ephemeralKeyA);
    $pubKeyPemA = $pubKeyDetailsA['key'];

    $ephemeralKeyB = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($ephemeralKeyB === false) {
        throw new RuntimeException('Failed to generate ephemeral RSA key pair B.');
    }
    $pubKeyDetailsB = openssl_pkey_get_details($ephemeralKeyB);
    $pubKeyPemB = $pubKeyDetailsB['key'];

    $signManifest = static function (array $descriptor, $key): array {
        $raw = json_encode($descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $sigBytes = '';
        openssl_sign($raw, $sigBytes, $key, OPENSSL_ALGO_SHA256);
        return [$raw, base64_encode($sigBytes)];
    };

    // Standard format 1 data compatibility profile fixture (exact four keys)
    $compatContract = [
        'format' => 1,
        'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
        'schema_sha256' => hash('sha256', 'mock_schema_normalized_v1'),
        'ledger_sha256' => hash('sha256', 'mock_ledger_normalized_v1'),
    ];

    // -------------------------------------------------------------
    // TEST 1: Canonical Retained-Shell Combined Digest Formula
    // -------------------------------------------------------------
    echo "[Test 1] Canonical Retained-Shell Combined Digest Formula...\n";
    $testExeHash = hash('sha256', 'mock_exe_bytes');
    $testAsarHash = hash('sha256', 'mock_asar_bytes');

    $digest1 = ProductInventoryService::computeCanonicalShellDigest($testExeHash, $testAsarHash);
    $digest2 = ProductInventoryService::computeCanonicalShellDigest([
        'POS Desktop.exe' => ['sha256' => $testExeHash, 'size' => 1024],
        'resources/app.asar' => ['sha256' => $testAsarHash, 'size' => 2048],
    ]);
    assertEqual($digest1, $digest2, 'computeCanonicalShellDigest string vs map form must match');

    // Expected exact canonical JSON payload domain
    $expectedCanonicalJson = '{"POS Desktop.exe":"' . $testExeHash . '","resources/app.asar":"' . $testAsarHash . '"}';
    assertEqual($digest1, hash('sha256', $expectedCanonicalJson), 'Combined digest must match JSON ordered keys domain');

    // Rejection of invalid hashes
    $threw = false;
    try {
        ProductInventoryService::computeCanonicalShellDigest('invalid_hash', $testAsarHash);
    } catch (Throwable) {
        $threw = true;
    }
    assertTrue($threw, 'Invalid hex for exeHash must be rejected');

    echo "  PASS: Canonical digest matches existing domain.\n\n";

    // -------------------------------------------------------------
    // TEST 1b: Full Product Inventory Canonical Digest Formula
    // -------------------------------------------------------------
    echo "[Test 1b] Full Product Inventory Canonical Digest Formula...\n";
    $dummyInv = [
        'format' => 1,
        'platform' => 'win32',
        'arch' => 'x64',
        'version' => '0.0.12',
        'native_version' => '0.0.12',
        'native_shell' => [
            'sha256' => ProductInventoryService::computeCanonicalShellDigest($testExeHash, $testAsarHash),
            'files' => [
                'POS Desktop.exe' => ['sha256' => $testExeHash, 'size' => 1024],
                'resources/app.asar' => ['sha256' => $testAsarHash, 'size' => 2048],
            ],
        ],
        'files' => [
            'resources/app.asar' => ['sha256' => $testAsarHash, 'size' => 2048],
            'POS Desktop.exe' => ['sha256' => $testExeHash, 'size' => 1024],
            'resources/app.asar.unpacked/version.json' => ['sha256' => hash('sha256', 'v'), 'size' => 20],
        ],
    ];

    $invDigestOrig = ShellDeltaManifestService::canonicalInventoryDigest($dummyInv);
    assertTrue(strlen($invDigestOrig) === 64, 'Canonical inventory digest must be 64-char sha256');

    // Reordered keys in inventory produce identical canonical digest
    $reorderedInv = [
        'files' => $dummyInv['files'],
        'native_shell' => [
            'files' => [
                'resources/app.asar' => $dummyInv['native_shell']['files']['resources/app.asar'],
                'POS Desktop.exe' => $dummyInv['native_shell']['files']['POS Desktop.exe'],
            ],
            'sha256' => $dummyInv['native_shell']['sha256'],
        ],
        'native_version' => $dummyInv['native_version'],
        'version' => $dummyInv['version'],
        'arch' => $dummyInv['arch'],
        'platform' => $dummyInv['platform'],
        'format' => $dummyInv['format'],
    ];
    $invDigestReordered = ShellDeltaManifestService::canonicalInventoryDigest($reorderedInv);
    assertEqual($invDigestOrig, $invDigestReordered, 'Reordered inventory keys must produce identical canonical digest');

    // Reversed entry key order (size before sha256) in ONLY one of files vs native_shell.files
    $revEntryOnlyFilesInv = $dummyInv;
    $revEntryOnlyFilesInv['files']['POS Desktop.exe'] = ['size' => 1024, 'sha256' => $testExeHash];
    $valOnlyFilesRes = (new ProductInventoryService())->validateInventory($revEntryOnlyFilesInv);
    assertTrue($valOnlyFilesRes['ok'], 'Inventory with reversed entry keys in only files must validate: ' . ($valOnlyFilesRes['reason_code'] ?? ''));
    $digestRevEntryOnlyFiles = ShellDeltaManifestService::canonicalInventoryDigest($revEntryOnlyFilesInv);
    assertEqual($invDigestOrig, $digestRevEntryOnlyFiles, 'Reversed entry keys in only files must yield identical canonical digest');

    // Fully reversed entry keys in both files and native_shell
    $revAllEntriesInv = $reorderedInv;
    $revAllEntriesInv['files']['POS Desktop.exe'] = ['size' => 1024, 'sha256' => $testExeHash];
    $revAllEntriesInv['files']['resources/app.asar'] = ['size' => 2048, 'sha256' => $testAsarHash];
    $revAllEntriesInv['native_shell']['files']['POS Desktop.exe'] = ['size' => 1024, 'sha256' => $testExeHash];
    $revAllEntriesInv['native_shell']['files']['resources/app.asar'] = ['size' => 2048, 'sha256' => $testAsarHash];
    $valAllRevRes = (new ProductInventoryService())->validateInventory($revAllEntriesInv);
    assertTrue($valAllRevRes['ok'], 'Inventory with all reversed entry keys must validate');
    $digestAllRev = ShellDeltaManifestService::canonicalInventoryDigest($revAllEntriesInv);
    assertEqual($invDigestOrig, $digestAllRev, 'Fully reversed root/native/files/entry must produce identical canonical digest');

    echo "  PASS: Full product inventory canonical digest verified.\n\n";

    // -------------------------------------------------------------
    // TEST 2: Positive Beta Update (native 13 / 12.1-beta.1 -> native 14 / 14-beta.1)
    // -------------------------------------------------------------
    echo "[Test 2] Positive Beta Update (native 13 / 12.1-beta.1 -> native 14 / 14-beta.1)...\n";
    $betaSrcDir = $lab . '/beta-src';
    $betaTgtDir = $lab . '/beta-tgt';

    $baseProductFiles = [
        'POS Desktop.exe' => "EXE_SHELL_V13_CONTENT_" . str_repeat('A', 500),
        'resources/app.asar' => "ASAR_SHELL_V13_CONTENT_" . str_repeat('B', 1000),
        'resources/app.asar.unpacked/version.json' => json_encode(['version' => '0.0.12-beta.1']),
        'resources/app.asar.unpacked/package.json' => json_encode(['name' => 'pos-desktop', 'version' => '0.0.12-beta.1']),
        'resources/app.asar.unpacked/backend/backend.phar' => "PHAR_V12_CONTENT",
        'resources/app.asar.unpacked/backend/certs/cacert.pem' => "MOCK_CACERT",
        'resources/app.asar.unpacked/backend/certs/update_public_key.pem' => $pubKeyPemA,
        'resources/app.asar.unpacked/database/pos_schema.sql' => "-- schema v12",
        'resources/app.asar.unpacked/frontend/dist/app.js' => "console.log('v12');",
        'resources/app.asar.unpacked/frontend/dist/legacy.js' => "console.log('old');",
        'resources/portable/runtime-manifest.json' => json_encode(['schemaVersion' => 1]),
        'resources/portable/php/php.exe' => "MOCK_PHP_EXE",
        'resources/portable/mysql/bin/mysqld.exe' => "MOCK_MYSQL_EXE",
        'locales/en-US.pak' => "PAK_EN_US_UNCHANGED",
        'icudtl.dat' => "ICU_DATA_UNCHANGED",
    ];

    populateTree($betaSrcDir, $baseProductFiles);

    // Target has updated Electron native shell (V14), updated version.json, updated app.js, new v14.js, deleted legacy.js
    $targetProductFiles = $baseProductFiles;
    $targetProductFiles['POS Desktop.exe'] = "EXE_SHELL_V14_CONTENT_" . str_repeat('Z', 600);
    $targetProductFiles['resources/app.asar'] = "ASAR_SHELL_V14_CONTENT_" . str_repeat('Y', 1200);
    $targetProductFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.14-beta.1']);
    $targetProductFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.14-beta.1']);
    $targetProductFiles['resources/app.asar.unpacked/backend/backend.phar'] = "PHAR_V14_CONTENT";
    $targetProductFiles['resources/app.asar.unpacked/frontend/dist/app.js'] = "console.log('v14');";
    $targetProductFiles['resources/app.asar.unpacked/frontend/dist/app-v14.js'] = "console.log('new feature');";
    unset($targetProductFiles['resources/app.asar.unpacked/frontend/dist/legacy.js']);

    populateTree($betaTgtDir, $targetProductFiles);

    $invService = new ProductInventoryService();
    $srcInvBeta = $invService->buildInventory($betaSrcDir, '0.0.13');
    $tgtInvBeta = $invService->buildInventory($betaTgtDir, '0.0.14');

    assertEqual($srcInvBeta['version'], '0.0.12-beta.1', 'Source app version');
    assertEqual($srcInvBeta['native_version'], '0.0.13', 'Source native version');
    assertEqual($tgtInvBeta['version'], '0.0.14-beta.1', 'Target app version');
    assertEqual($tgtInvBeta['native_version'], '0.0.14', 'Target native version');
    assertTrue($srcInvBeta['native_shell']['sha256'] !== $tgtInvBeta['native_shell']['sha256'], 'Native shell physical hashes must differ');

    // Build diff
    $changedBetaFiles = [];
    $rawZipFilesBeta = [];
    $uncompressedBetaTotal = 0;
    foreach ($tgtInvBeta['files'] as $p => $meta) {
        if (!isset($srcInvBeta['files'][$p]) ||
            $srcInvBeta['files'][$p]['sha256'] !== $meta['sha256'] ||
            $srcInvBeta['files'][$p]['size'] !== $meta['size']) {
            $changedBetaFiles[] = ['path' => $p, 'sha256' => $meta['sha256'], 'size' => $meta['size']];
            $rawZipFilesBeta[$p] = file_get_contents($betaTgtDir . '/' . $p);
            $uncompressedBetaTotal += $meta['size'];
        }
    }
    $deletedBetaFiles = ['resources/app.asar.unpacked/frontend/dist/legacy.js'];

    // Verify unchanged files are excluded
    $changedBetaPaths = array_column($changedBetaFiles, 'path');
    assertTrue(!in_array('locales/en-US.pak', $changedBetaPaths, true), 'Unchanged locales/en-US.pak must not be in payload');
    assertTrue(!in_array('icudtl.dat', $changedBetaPaths, true), 'Unchanged icudtl.dat must not be in payload');

    $betaZipPath = $lab . '/beta-delta.zip';
    buildDummyPackageZip($betaZipPath, $rawZipFilesBeta);

    $betaDescriptor = [
        'format' => 2,
        'operation' => 'shell_delta',
        'minimum_updater_protocol' => 2,
        'platform' => 'win32',
        'arch' => 'x64',
        'purpose' => 'regular',
        'from_version' => '0.0.12-beta.1',
        'from_channel' => 'beta',
        'from_native_version' => '0.0.13',
        'version' => '0.0.14-beta.1',
        'channel' => 'beta',
        'prerelease' => true,
        'native_version' => '0.0.14',
        'source_inventory_sha256' => ShellDeltaManifestService::canonicalInventoryDigest($srcInvBeta),
        'source_inventory' => $srcInvBeta,
        'target_inventory_sha256' => ShellDeltaManifestService::canonicalInventoryDigest($tgtInvBeta),
        'target_inventory' => $tgtInvBeta,
        'source_native_shell' => $srcInvBeta['native_shell'],
        'target_native_shell' => $tgtInvBeta['native_shell'],
        'files' => $changedBetaFiles,
        'deleted_files' => $deletedBetaFiles,
        'package' => [
            'asset' => 'shell-delta.zip',
            'sha256' => hash_file('sha256', $betaZipPath),
            'size' => filesize($betaZipPath),
            'uncompressed_size' => $uncompressedBetaTotal,
        ],
        'data_compatibility' => $compatContract,
        'source_data_compatibility' => $compatContract,
        'migrations' => [],
    ];

    [$rawBetaManifest, $sigBetaManifest] = $signManifest($betaDescriptor, $ephemeralKeyA);

    $sigServiceA = new ManifestSignatureService(null, [$pubKeyPemA]);
    $manifestService = new ShellDeltaManifestService($sigServiceA, $invService);

    // Context profile with reversed key order
    $revCtxCompat = [
        'schema_sha256' => $compatContract['schema_sha256'],
        'format' => $compatContract['format'],
        'ledger_sha256' => $compatContract['ledger_sha256'],
        'metadata_migration' => $compatContract['metadata_migration'],
    ];

    $verBeta = $manifestService->verify($rawBetaManifest, $sigBetaManifest, [
        'version' => '0.0.12-beta.1',
        'native_version' => '0.0.13',
        'channel' => 'beta',
        'root' => $betaSrcDir,
        'data_compatibility' => $revCtxCompat,
    ]);
    assertTrue($verBeta['ok'], 'Positive Beta manifest verification must succeed: ' . ($verBeta['reason_code'] ?? ''));

    // Reordered manifest descriptor with reversed source_native_shell keys & entry keys
    $revNativeShellDesc = $betaDescriptor;
    $revNativeShellDesc['source_native_shell'] = [
        'files' => [
            'resources/app.asar' => [
                'size' => $srcInvBeta['native_shell']['files']['resources/app.asar']['size'],
                'sha256' => $srcInvBeta['native_shell']['files']['resources/app.asar']['sha256'],
            ],
            'POS Desktop.exe' => [
                'size' => $srcInvBeta['native_shell']['files']['POS Desktop.exe']['size'],
                'sha256' => $srcInvBeta['native_shell']['files']['POS Desktop.exe']['sha256'],
            ],
        ],
        'sha256' => $srcInvBeta['native_shell']['sha256'],
    ];
    // Reverse root keys of descriptor
    $revRootDesc = array_reverse($revNativeShellDesc, true);
    [$rawRevManifest, $sigRevManifest] = $signManifest($revRootDesc, $ephemeralKeyA);
    $verRevDesc = $manifestService->verify($rawRevManifest, $sigRevManifest);
    assertTrue($verRevDesc['ok'], 'Descriptor with reversed root keys and reversed source_native_shell must verify: ' . ($verRevDesc['reason_code'] ?? ''));

    $zipVerBeta = $manifestService->validatePackageZip($betaZipPath, $betaDescriptor);
    assertTrue($zipVerBeta['ok'], 'Positive Beta zip package validation must succeed: ' . ($zipVerBeta['reason_code'] ?? ''));

    $srcVerBeta = $manifestService->verifySource($betaSrcDir, $betaDescriptor);
    assertTrue($srcVerBeta['ok'], 'Positive Beta source verification must succeed');

    echo "  PASS: Positive Beta update verified.\n\n";

    // -------------------------------------------------------------
    // TEST 3: Positive Stable Update (stable -> stable)
    // -------------------------------------------------------------
    echo "[Test 3] Positive Stable Update (0.0.12 -> 0.0.13)...\n";
    $stableSrcDir = $lab . '/stable-src';
    $stableTgtDir = $lab . '/stable-tgt';

    $stableFiles = $baseProductFiles;
    $stableFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.12']);
    $stableFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.12']);
    populateTree($stableSrcDir, $stableFiles);

    $stableTgtFiles = $stableFiles;
    $stableTgtFiles['POS Desktop.exe'] = "EXE_SHELL_STABLE_13_" . str_repeat('X', 500);
    $stableTgtFiles['resources/app.asar'] = "ASAR_SHELL_STABLE_13_" . str_repeat('W', 1000);
    $stableTgtFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.13']);
    $stableTgtFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.13']);
    populateTree($stableTgtDir, $stableTgtFiles);

    $srcInvStable = $invService->buildInventory($stableSrcDir, '0.0.12');
    $tgtInvStable = $invService->buildInventory($stableTgtDir, '0.0.13');

    $changedStable = [];
    $rawZipStable = [];
    $uncompressedStableTotal = 0;
    foreach ($tgtInvStable['files'] as $p => $m) {
        if (!isset($srcInvStable['files'][$p]) || $srcInvStable['files'][$p]['sha256'] !== $m['sha256']) {
            $changedStable[] = ['path' => $p, 'sha256' => $m['sha256'], 'size' => $m['size']];
            $rawZipStable[$p] = file_get_contents($stableTgtDir . '/' . $p);
            $uncompressedStableTotal += $m['size'];
        }
    }
    $stableZipPath = $lab . '/stable-delta.zip';
    buildDummyPackageZip($stableZipPath, $rawZipStable);

    $stableDescriptor = [
        'format' => 2,
        'operation' => 'shell_delta',
        'minimum_updater_protocol' => 2,
        'platform' => 'win32',
        'arch' => 'x64',
        'purpose' => 'regular',
        'from_version' => '0.0.12',
        'from_channel' => 'stable',
        'from_native_version' => '0.0.12',
        'version' => '0.0.13',
        'channel' => 'stable',
        'prerelease' => false,
        'native_version' => '0.0.13',
        'source_inventory_sha256' => ShellDeltaManifestService::canonicalInventoryDigest($srcInvStable),
        'source_inventory' => $srcInvStable,
        'target_inventory_sha256' => ShellDeltaManifestService::canonicalInventoryDigest($tgtInvStable),
        'target_inventory' => $tgtInvStable,
        'source_native_shell' => $srcInvStable['native_shell'],
        'target_native_shell' => $tgtInvStable['native_shell'],
        'files' => $changedStable,
        'deleted_files' => [],
        'package' => [
            'asset' => 'shell-delta.zip',
            'sha256' => hash_file('sha256', $stableZipPath),
            'size' => filesize($stableZipPath),
            'uncompressed_size' => $uncompressedStableTotal,
        ],
        'data_compatibility' => $compatContract,
        'source_data_compatibility' => $compatContract,
        'migrations' => [],
    ];

    [$rawStableManifest, $sigStableManifest] = $signManifest($stableDescriptor, $ephemeralKeyA);
    $verStable = $manifestService->verify($rawStableManifest, $sigStableManifest, [
        'version' => '0.0.12',
        'channel' => 'stable',
    ]);
    assertTrue($verStable['ok'], 'Positive Stable manifest verification must succeed');

    echo "  PASS: Positive Stable update verified.\n\n";

    // -------------------------------------------------------------
    // TEST 4: Positive Bootstrap Completion (equal app version, native shell changed)
    // -------------------------------------------------------------
    echo "[Test 4] Positive Bootstrap Completion (equal app version, native shell changed)...\n";
    $bootstrapTgtDir = $lab . '/bootstrap-tgt';
    $bootstrapFiles = $baseProductFiles; // Same app version 0.0.12-beta.1
    $bootstrapFiles['POS Desktop.exe'] = "EXE_SHELL_NEW_NATIVE_" . str_repeat('M', 550);
    $bootstrapFiles['resources/app.asar'] = "ASAR_SHELL_NEW_NATIVE_" . str_repeat('N', 1100);
    populateTree($bootstrapTgtDir, $bootstrapFiles);

    // Target native version 0.0.14 (source native version is 0.0.13 from srcInvBeta)
    $tgtInvBootstrap = $invService->buildInventory($bootstrapTgtDir, '0.0.14');

    $changedBootstrap = [];
    $rawZipBootstrap = [];
    $uncompressedBootstrap = 0;
    foreach ($tgtInvBootstrap['files'] as $p => $m) {
        if (!isset($srcInvBeta['files'][$p]) || $srcInvBeta['files'][$p]['sha256'] !== $m['sha256']) {
            $changedBootstrap[] = ['path' => $p, 'sha256' => $m['sha256'], 'size' => $m['size']];
            $rawZipBootstrap[$p] = file_get_contents($bootstrapTgtDir . '/' . $p);
            $uncompressedBootstrap += $m['size'];
        }
    }
    $bootstrapZipPath = $lab . '/bootstrap-delta.zip';
    buildDummyPackageZip($bootstrapZipPath, $rawZipBootstrap);

    $mockBootstrapLink = hash('sha256', 'mock_bootstrap_record_digest');
    $bootstrapDescriptor = [
        'format' => 2,
        'operation' => 'shell_delta',
        'minimum_updater_protocol' => 2,
        'platform' => 'win32',
        'arch' => 'x64',
        'purpose' => 'bootstrap_completion',
        'bootstrap_link' => $mockBootstrapLink,
        'from_version' => '0.0.12-beta.1',
        'from_channel' => 'beta',
        'from_native_version' => '0.0.13', // Matches srcInvBeta native version 0.0.13
        'version' => '0.0.12-beta.1',
        'channel' => 'beta',
        'prerelease' => true,
        'native_version' => '0.0.14',
        'source_inventory_sha256' => ShellDeltaManifestService::canonicalInventoryDigest($srcInvBeta),
        'source_inventory' => $srcInvBeta,
        'target_inventory_sha256' => ShellDeltaManifestService::canonicalInventoryDigest($tgtInvBootstrap),
        'target_inventory' => $tgtInvBootstrap,
        'source_native_shell' => $srcInvBeta['native_shell'],
        'target_native_shell' => $tgtInvBootstrap['native_shell'],
        'files' => $changedBootstrap,
        'deleted_files' => [],
        'package' => [
            'asset' => 'shell-delta.zip',
            'sha256' => hash_file('sha256', $bootstrapZipPath),
            'size' => filesize($bootstrapZipPath),
            'uncompressed_size' => $uncompressedBootstrap,
        ],
        'data_compatibility' => $compatContract,
        'source_data_compatibility' => $compatContract,
        'migrations' => [],
    ];

    [$rawBootManifest, $sigBootManifest] = $signManifest($bootstrapDescriptor, $ephemeralKeyA);
    $verBoot = $manifestService->verify($rawBootManifest, $sigBootManifest);
    assertTrue($verBoot['ok'], 'Bootstrap completion manifest must succeed: ' . ($verBoot['reason_code'] ?? ''));

    echo "  PASS: Bootstrap completion verified.\n\n";

    // -------------------------------------------------------------
    // TEST 5: Metadata Independence
    // -------------------------------------------------------------
    echo "[Test 5] Metadata Independence...\n";
    assertEqual($srcInvBeta['version'], '0.0.12-beta.1', 'Source app version');
    assertEqual($srcInvBeta['native_version'], '0.0.13', 'Source native version');
    assertEqual($tgtInvBeta['version'], '0.0.14-beta.1', 'Target app version');
    assertEqual($tgtInvBeta['native_version'], '0.0.14', 'Target native version');
    echo "  PASS: App version and Native version vary independently.\n\n";

    // -------------------------------------------------------------
    // TEST 6: Exact Diff & Coverage Sets
    // -------------------------------------------------------------
    echo "[Test 6] Exact Diff & Coverage Sets...\n";
    $reconFiles = $srcInvBeta['files'];
    foreach ($betaDescriptor['deleted_files'] as $del) {
        unset($reconFiles[$del]);
    }
    foreach ($betaDescriptor['files'] as $mod) {
        $reconFiles[$mod['path']] = ['sha256' => $mod['sha256'], 'size' => $mod['size']];
    }
    ksort($reconFiles);
    $tgtExpectedFiles = $tgtInvBeta['files'];
    ksort($tgtExpectedFiles);
    assertEqual($reconFiles, $tgtExpectedFiles, 'Reconstructed files must strictly equal target inventory files');
    echo "  PASS: Diff reconstruction invariant holds.\n\n";

    // -------------------------------------------------------------
    // TEST 7: Negative Policy Matrix
    // -------------------------------------------------------------
    echo "[Test 7] Negative Policy Matrix...\n";

    // 7.1 Raw JSON tampering
    $tamperedRaw = substr($rawBetaManifest, 0, -2) . " \n";
    $tamperedRes = $manifestService->verify($tamperedRaw, $sigBetaManifest);
    assertEqual($tamperedRes['reason_code'], 'signature_invalid', 'Tampered raw JSON must fail signature');

    // 7.2 Corrupt signature
    $corruptRes = $manifestService->verify($rawBetaManifest, 'bm90LWEtdmFsaWQtc2lnbmF0dXJl');
    assertEqual($corruptRes['reason_code'], 'signature_invalid', 'Corrupt signature must fail');

    // 7.3 Raw valid signature vs canonical base64 (reject raw valid signature fallback)
    $rawSigBytes = base64_decode($sigBetaManifest, true);
    assertTrue($rawSigBytes !== false, 'Decode ephemeral signature');
    $rawSigRes = $manifestService->verify($rawBetaManifest, $rawSigBytes);
    assertEqual($rawSigRes['reason_code'], 'signature_invalid', 'Raw binary signature must be rejected');

    // 7.4 Wrong public key (Key B)
    $sigServiceB = new ManifestSignatureService(null, [$pubKeyPemB]);
    $manifestServiceB = new ShellDeltaManifestService($sigServiceB, $invService);
    $wrongKeyRes = $manifestServiceB->verify($rawBetaManifest, $sigBetaManifest);
    assertEqual($wrongKeyRes['reason_code'], 'signature_invalid', 'Verification with wrong public key must fail');

    // 7.5 Unknown fields, invalid types, missing data compatibility profile
    // 7.5a Unknown root field
    $unkDesc = $betaDescriptor;
    $unkDesc['unknown_field'] = 'forbidden';
    [$unkRaw, $unkSig] = $signManifest($unkDesc, $ephemeralKeyA);
    $unkRes = $manifestService->verify($unkRaw, $unkSig);
    assertEqual($unkRes['reason_code'], 'descriptor_invalid', 'Unknown root field must be rejected');

    // 7.5b Invalid field type
    $invTypeDesc = $betaDescriptor;
    $invTypeDesc['version'] = 1400;
    [$invTypeRaw, $invTypeSig] = $signManifest($invTypeDesc, $ephemeralKeyA);
    $invTypeRes = $manifestService->verify($invTypeRaw, $invTypeSig);
    assertEqual($invTypeRes['reason_code'], 'semver_invalid', 'Invalid type for version must be rejected');

    // 7.5c Missing source_data_compatibility
    $noSrcCompatDesc = $betaDescriptor;
    unset($noSrcCompatDesc['source_data_compatibility']);
    [$noSrcRaw, $noSrcSig] = $signManifest($noSrcCompatDesc, $ephemeralKeyA);
    $noSrcRes = $manifestService->verify($noSrcRaw, $noSrcSig);
    assertEqual($noSrcRes['reason_code'], 'source_data_contract_invalid', 'Missing source_data_compatibility must be rejected');

    // 7.5d Missing data_compatibility
    $noTgtCompatDesc = $betaDescriptor;
    unset($noTgtCompatDesc['data_compatibility']);
    [$noTgtRaw, $noTgtSig] = $signManifest($noTgtCompatDesc, $ephemeralKeyA);
    $noTgtRes = $manifestService->verify($noTgtRaw, $noTgtSig);
    assertEqual($noTgtRes['reason_code'], 'data_contract_invalid', 'Missing data_compatibility must be rejected');

    // 7.5e Extra unknown key in data_compatibility
    $extraCompatDesc = $betaDescriptor;
    $extraCompatDesc['data_compatibility']['extra_key'] = 1;
    [$extraCompatRaw, $extraCompatSig] = $signManifest($extraCompatDesc, $ephemeralKeyA);
    $extraCompatRes = $manifestService->verify($extraCompatRaw, $extraCompatSig);
    assertEqual($extraCompatRes['reason_code'], 'data_contract_invalid', 'Extra field in data_compatibility must be rejected');

    // 7.6 Cross-channel rejection (Beta -> Stable) with refreshed hashes
    $crossChannelDesc = $betaDescriptor;
    $crossChannelDesc['from_channel'] = 'beta';
    $crossChannelDesc['channel'] = 'stable';
    $crossChannelDesc['version'] = '0.0.14';
    $crossChannelDesc['prerelease'] = false;
    $crossTgtInv = $tgtInvBeta;
    $crossTgtInv['version'] = '0.0.14';
    $crossChannelDesc['target_inventory'] = $crossTgtInv;
    $crossChannelDesc = refreshDescriptor($crossChannelDesc);
    [$crossRaw, $crossSig] = $signManifest($crossChannelDesc, $ephemeralKeyA);
    $crossRes = $manifestService->verify($crossRaw, $crossSig);
    assertEqual($crossRes['reason_code'], 'channel_mismatch', 'Cross-channel update must be rejected');

    // 7.7 Retired RC channel with refreshed hashes
    $rcDesc = $betaDescriptor;
    $rcDesc['version'] = '0.0.14-rc.1';
    $rcDesc['channel'] = 'rc';
    $rcTgtInv = $tgtInvBeta;
    $rcTgtInv['version'] = '0.0.14-rc.1';
    $rcDesc['target_inventory'] = $rcTgtInv;
    $rcDesc = refreshDescriptor($rcDesc);
    [$rcRaw, $rcSig] = $signManifest($rcDesc, $ephemeralKeyA);
    $rcRes = $manifestService->verify($rcRaw, $rcSig);
    assertEqual($rcRes['reason_code'], 'channel_invalid', 'Retired RC channel must be rejected');

    // 7.8 Unsupported protocol version
    $protoDesc = $betaDescriptor;
    $protoDesc['minimum_updater_protocol'] = 3;
    [$protoRaw, $protoSig] = $signManifest($protoDesc, $ephemeralKeyA);
    $protoRes = $manifestService->verify($protoRaw, $protoSig);
    assertEqual($protoRes['reason_code'], 'protocol_unsupported', 'Unsupported protocol version must be rejected');

    // 7.9 Unsupported format version
    $fmtDesc = $betaDescriptor;
    $fmtDesc['format'] = 1;
    [$fmtRaw, $fmtSig] = $signManifest($fmtDesc, $ephemeralKeyA);
    $fmtRes = $manifestService->verify($fmtRaw, $fmtSig);
    assertEqual($fmtRes['reason_code'], 'format_unsupported', 'Unsupported format must be rejected');

    // 7.10 Caps & Sum validation
    // 7.10a Single file size exceeds 250 MiB
    $capDesc = $betaDescriptor;
    $capDesc['files'][0]['size'] = 262144001; // 250 MiB + 1
    $capDesc['package']['uncompressed_size'] = 262144001;
    [$capRaw, $capSig] = $signManifest($capDesc, $ephemeralKeyA);
    $capRes = $manifestService->verify($capRaw, $capSig);
    assertEqual($capRes['reason_code'], 'files_invalid', 'Single file > 250 MiB must be rejected');

    // 7.10b Sum of files size mismatch against package.uncompressed_size
    $sumDesc = $betaDescriptor;
    $sumDesc['package']['uncompressed_size'] = $sumDesc['package']['uncompressed_size'] + 50;
    [$sumRaw, $sumSig] = $signManifest($sumDesc, $ephemeralKeyA);
    $sumRes = $manifestService->verify($sumRaw, $sumSig);
    assertEqual($sumRes['reason_code'], 'package_uncompressed_mismatch', 'Sum of files mismatch with uncompressed size must be rejected');

    // 7.10c Package size exceeds 500 MiB
    $pkgCapDesc = $betaDescriptor;
    $pkgCapDesc['package']['size'] = 524288001;
    [$pkgCapRaw, $pkgCapSig] = $signManifest($pkgCapDesc, $ephemeralKeyA);
    $pkgCapRes = $manifestService->verify($pkgCapRaw, $pkgCapSig);
    assertEqual($pkgCapRes['reason_code'], 'package_invalid', 'Package size > 500 MiB must be rejected');

    // 7.11 Path Traversal in manifest files
    $travDesc = $betaDescriptor;
    $travDesc['files'][] = ['path' => '../secret.txt', 'sha256' => hash('sha256', 'x'), 'size' => 1];
    [$travRaw, $travSig] = $signManifest($travDesc, $ephemeralKeyA);
    $travRes = $manifestService->verify($travRaw, $travSig);
    assertEqual($travRes['reason_code'], 'files_invalid', 'Path traversal in files must be rejected');

    // 7.12 Cross-inventory case collision & ancestor collision
    // 7.12a Cross-inventory case collision
    $caseInvTgt = $tgtInvBeta;
    $caseInvTgt['files']['locales/en-us.pak'] = $caseInvTgt['files']['locales/en-US.pak'] ?? ['sha256' => hash('sha256', 'pak'), 'size' => 10];
    unset($caseInvTgt['files']['locales/en-US.pak']);
    $caseDesc = $betaDescriptor;
    $caseDesc['target_inventory'] = $caseInvTgt;
    $caseDesc = refreshDescriptor($caseDesc);
    [$caseRaw, $caseSig] = $signManifest($caseDesc, $ephemeralKeyA);
    $caseRes = $manifestService->verify($caseRaw, $caseSig);
    assertEqual($caseRes['reason_code'], 'case_collision', 'Cross-inventory case collision must be rejected');

    // 7.12b Cross-inventory ancestor collision: valid separate inventories
    $ancInvSrc = $srcInvBeta;
    $ancInvSrc['files']['resources/app.asar.unpacked/frontend/dist/pivot.js'] = ['sha256' => hash('sha256', 'pivot'), 'size' => 10];
    ksort($ancInvSrc['files']);

    $ancInvTgt = $tgtInvBeta;
    unset($ancInvTgt['files']['resources/app.asar.unpacked/frontend/dist/pivot.js']);
    $ancInvTgt['files']['resources/app.asar.unpacked/frontend/dist/pivot.js/child.js'] = ['sha256' => hash('sha256', 'child'), 'size' => 10];
    ksort($ancInvTgt['files']);

    $ancDesc = $betaDescriptor;
    $ancDesc['source_inventory'] = $ancInvSrc;
    $ancDesc['target_inventory'] = $ancInvTgt;
    $ancDesc = refreshDescriptor($ancDesc);
    [$ancRaw, $ancSig] = $signManifest($ancDesc, $ephemeralKeyA);
    $ancRes = $manifestService->verify($ancRaw, $ancSig);
    assertEqual($ancRes['reason_code'], 'ancestor_collision', 'Cross-inventory ancestor collision must be rejected');

    // 7.13 Replay / Downgrade rejection with refreshed target inventory
    $downDesc = $betaDescriptor;
    $downDesc['version'] = '0.0.10-beta.1';
    $downDesc['from_version'] = '0.0.12-beta.1';
    $downTgtInv = $tgtInvBeta;
    $downTgtInv['version'] = '0.0.10-beta.1';
    $downDesc['target_inventory'] = $downTgtInv;
    $downDesc = refreshDescriptor($downDesc);
    [$downRaw, $downSig] = $signManifest($downDesc, $ephemeralKeyA);
    $downRes = $manifestService->verify($downRaw, $downSig);
    assertEqual($downRes['reason_code'], 'downgrade_rejected', 'Downgrade must be rejected');

    // 7.14 Ordinary equal version without bootstrap_completion
    $equalDesc = $betaDescriptor;
    $equalDesc['version'] = '0.0.12-beta.1';
    $equalDesc['from_version'] = '0.0.12-beta.1';
    $equalDesc['purpose'] = 'regular';
    $equalTgtInv = $tgtInvBeta;
    $equalTgtInv['version'] = '0.0.12-beta.1';
    $equalDesc['target_inventory'] = $equalTgtInv;
    $equalDesc = refreshDescriptor($equalDesc);
    [$equalRaw, $equalSig] = $signManifest($equalDesc, $ephemeralKeyA);
    $equalRes = $manifestService->verify($equalRaw, $equalSig);
    assertEqual($equalRes['reason_code'], 'downgrade_rejected', 'Equal version without bootstrap_completion must be rejected');

    // 7.15 Bootstrap completion without native shell change
    $badBootDesc = $bootstrapDescriptor;
    $badBootTgtInv = $badBootDesc['target_inventory'];
    $badBootTgtInv['native_shell'] = $badBootDesc['source_inventory']['native_shell'];
    $badBootTgtInv['files']['POS Desktop.exe'] = $badBootDesc['source_inventory']['files']['POS Desktop.exe'];
    $badBootTgtInv['files']['resources/app.asar'] = $badBootDesc['source_inventory']['files']['resources/app.asar'];
    $badBootDesc['target_inventory'] = $badBootTgtInv;
    $badBootDesc = refreshDescriptor($badBootDesc);
    [$badBootRaw, $badBootSig] = $signManifest($badBootDesc, $ephemeralKeyA);
    $badBootRes = $manifestService->verify($badBootRaw, $badBootSig);
    assertEqual($badBootRes['reason_code'], 'bootstrap_shell_unchanged', 'Bootstrap without physical shell change must be rejected');

    // 7.16 Bootstrap link unexpected in regular update
    $unexpBootDesc = $betaDescriptor;
    $unexpBootDesc['bootstrap_link'] = hash('sha256', 'mock_link');
    [$unexpBootRaw, $unexpBootSig] = $signManifest($unexpBootDesc, $ephemeralKeyA);
    $unexpBootRes = $manifestService->verify($unexpBootRaw, $unexpBootSig);
    assertEqual($unexpBootRes['reason_code'], 'bootstrap_link_unexpected', 'Bootstrap link unexpected in regular update');

    // 7.17 Business sentinels protection
    foreach (['.env', 'storage/app.db', 'mysql/data/ibdata1', 'frontend/dist/digital-certificate.txt', 'backend/certs/my_private.pem'] as $sentinel) {
        $sentinelCheck = ProductInventoryService::validateProductPath($sentinel);
        assertTrue(!$sentinelCheck['ok'] && $sentinelCheck['reason_code'] === 'path_protected', "Business sentinel must be protected: {$sentinel}");
    }

    // 7.18 Actual present protected user data unchanged
    $envFile = $betaSrcDir . '/.env';
    $dbFile = $betaSrcDir . '/storage/app.db';
    if (!is_dir(dirname($dbFile))) {
        mkdir(dirname($dbFile), 0755, true);
    }
    file_put_contents($envFile, 'DB_PASSWORD=secret');
    file_put_contents($dbFile, 'SQLITE_DATA');
    $srcProtectedRes = $manifestService->verifySource($betaSrcDir, $betaDescriptor);
    assertTrue($srcProtectedRes['ok'], 'verifySource must succeed when protected user data is present');
    assertEqual(file_get_contents($envFile), 'DB_PASSWORD=secret', 'Protected .env file must be completely untouched');
    assertEqual(file_get_contents($dbFile), 'SQLITE_DATA', 'Protected app.db file must be completely untouched');
    @unlink($envFile);
    @unlink($dbFile);

    // 7.19 Source verification failures (source version drift and hash drift)
    $versionJsonFile = $betaSrcDir . '/resources/app.asar.unpacked/version.json';
    $origVersionRaw = file_get_contents($versionJsonFile);
    file_put_contents($versionJsonFile, json_encode(['version' => '0.0.99']));
    $verMisRes = $manifestService->verifySource($betaSrcDir, $betaDescriptor);
    assertEqual($verMisRes['reason_code'], 'source_version_mismatch', 'Source version mismatch on disk must be detected');
    file_put_contents($versionJsonFile, $origVersionRaw);

    $driftFile = $betaSrcDir . '/locales/en-US.pak';
    $origDriftBytes = file_get_contents($driftFile);
    file_put_contents($driftFile, 'TAMPERED_SOURCE_BYTES');
    $driftRes = $manifestService->verifySource($betaSrcDir, $betaDescriptor);
    assertEqual($driftRes['reason_code'], 'source_file_mismatch', 'Source file hash drift must be detected');
    file_put_contents($driftFile, $origDriftBytes);

    // 7.19b Intermediate source directory junction test
    $outsideDistVictim = $lab . '/outside-dist-victim';
    mkdir($outsideDistVictim, 0755, true);
    $victimFiles = [
        'app.js' => "console.log('v12');",
        'legacy.js' => "console.log('old');",
    ];
    populateTree($outsideDistVictim, $victimFiles);
    $trackedVictims[$outsideDistVictim] = $victimFiles;

    $juncSourceDir = $lab . '/junc-source';
    populateTree($juncSourceDir, $baseProductFiles);

    $targetFrontendDist = $juncSourceDir . '/resources/app.asar.unpacked/frontend/dist';
    safeLexicalSubdirCleanup($targetFrontendDist);
    if (!rmdir($targetFrontendDist)) {
        throw new RuntimeException("Failed to remove directory before junction creation: {$targetFrontendDist}");
    }

    $frontendJunc = $targetFrontendDist;
    createTrackedJunction($frontendJunc, $outsideDistVictim);

    $juncSourceRes = $manifestService->verifySource($juncSourceDir, $betaDescriptor);
    assertEqual($juncSourceRes['reason_code'], 'reparse_point_rejected', 'verifySource must reject intermediate directory junction');

    // Verify victim directory is completely intact
    foreach ($victimFiles as $vRel => $vExpectedContent) {
        $vPath = $outsideDistVictim . '/' . $vRel;
        assertTrue(is_file($vPath), "Victim file {$vRel} must still exist");
        assertEqual(file_get_contents($vPath), $vExpectedContent, "Victim file {$vRel} must be intact");
    }

    removeTrackedJunction($frontendJunc);

    // 7.20 ZIP package validations
    // 7.20a Encrypted ZIP entry
    $encZipPath = $lab . '/enc-delta.zip';
    buildDummyPackageZip($encZipPath, $rawZipFilesBeta, ['encrypt_file' => 'POS Desktop.exe']);
    $encDesc = $betaDescriptor;
    $encDesc['package']['sha256'] = hash_file('sha256', $encZipPath);
    $encDesc['package']['size'] = filesize($encZipPath);
    $encRes = $manifestService->validatePackageZip($encZipPath, $encDesc);
    assertEqual($encRes['reason_code'], 'package_entry_encrypted', 'Encrypted zip entry must be rejected');

    // 7.20b Duplicate entry in ZIP via binary header patch of equal-length names
    $dupZipPath = $lab . '/dup-delta.zip';
    $nameA = 'resources/app.asar.unpacked/package.json';
    $nameB = 'resources/app.asar.unpacked/version.json';
    assertEqual(strlen($nameA), strlen($nameB), 'Names A and B must have identical byte length');

    // In rawZipFilesBeta, make content of nameB identical to nameA so first stream matches manifestA
    $dupMap = $rawZipFilesBeta;
    $dupMap[$nameB] = $dupMap[$nameA];
    buildDummyPackageZip($dupZipPath, $dupMap);

    $rawZipBytes = file_get_contents($dupZipPath);
    assertTrue($rawZipBytes !== false, 'Read raw test zip bytes');
    $replaceCount = 0;
    $patchedZipBytes = str_replace($nameB, $nameA, $rawZipBytes, $replaceCount);
    assertEqual($replaceCount, 2, 'Must binary patch exactly 2 header occurrences (local + central)');
    file_put_contents($dupZipPath, $patchedZipBytes);

    // Inspect patched ZIP with ZipArchive to verify actual duplicate entry exists
    $inspectZip = new ZipArchive();
    $openedInspect = $inspectZip->open($dupZipPath, ZipArchive::RDONLY);
    assertEqual($openedInspect, true, 'Patched duplicate zip must be openable');
    assertEqual($inspectZip->numFiles, count($betaDescriptor['files']), 'numFiles must still match manifest count');
    $seenNames = [];
    for ($zi = 0; $zi < $inspectZip->numFiles; $zi++) {
        $stat = $inspectZip->statIndex($zi);
        assertTrue($stat !== false, "statIndex({$zi})");
        $seenNames[] = $stat['name'];
    }
    $inspectZip->close();
    assertEqual(count($seenNames) - count(array_unique($seenNames)), 1, 'Inspected zip must contain actual duplicate entry');

    // Update package size and hash only (manifest files list remains completely unique and valid)
    $dupDesc = $betaDescriptor;
    $dupDesc['package']['size'] = filesize($dupZipPath);
    $dupDesc['package']['sha256'] = hash_file('sha256', $dupZipPath);
    $dupRes = $manifestService->validatePackageZip($dupZipPath, $dupDesc);
    assertEqual($dupRes['reason_code'], 'package_duplicate_entry', 'Duplicate entry in zip must be rejected as package_duplicate_entry');

    // 7.20c Unexpected extra entry in ZIP
    $extraZipPath = $lab . '/extra-delta.zip';
    buildDummyPackageZip($extraZipPath, $rawZipFilesBeta, ['extra_files' => ['unexpected.dll' => 'EXTRA']]);
    $extraDesc = $betaDescriptor;
    $extraDesc['package']['sha256'] = hash_file('sha256', $extraZipPath);
    $extraDesc['package']['size'] = filesize($extraZipPath);
    $extraRes = $manifestService->validatePackageZip($extraZipPath, $extraDesc);
    assertTrue(in_array($extraRes['reason_code'], ['package_entry_count_mismatch', 'package_unexpected_entry'], true), 'Extra entry in zip must be rejected');

    // 7.20d Symlink external attribute in ZIP
    $symZipPath = $lab . '/sym-delta.zip';
    buildDummyPackageZip($symZipPath, $rawZipFilesBeta, ['symlink_file' => 'POS Desktop.exe']);
    $symDesc = $betaDescriptor;
    $symDesc['package']['sha256'] = hash_file('sha256', $symZipPath);
    $symDesc['package']['size'] = filesize($symZipPath);
    $symRes = $manifestService->validatePackageZip($symZipPath, $symDesc);
    assertEqual($symRes['reason_code'], 'package_entry_symlink', 'Symlink external attribute in zip must be rejected');

    // 7.20e Directory external attribute in ZIP
    $dirZipPath = $lab . '/dir-delta.zip';
    buildDummyPackageZip($dirZipPath, $rawZipFilesBeta, ['dir_attr_file' => 'POS Desktop.exe']);
    $dirDesc = $betaDescriptor;
    $dirDesc['package']['sha256'] = hash_file('sha256', $dirZipPath);
    $dirDesc['package']['size'] = filesize($dirZipPath);
    $dirRes = $manifestService->validatePackageZip($dirZipPath, $dirDesc);
    assertEqual($dirRes['reason_code'], 'package_entry_directory', 'Directory external attribute in zip must be rejected');

    // 7.20f Reparse external attribute in ZIP
    $repZipPath = $lab . '/reparse-delta.zip';
    buildDummyPackageZip($repZipPath, $rawZipFilesBeta, ['reparse_attr_file' => 'POS Desktop.exe']);
    $repDesc = $betaDescriptor;
    $repDesc['package']['sha256'] = hash_file('sha256', $repZipPath);
    $repDesc['package']['size'] = filesize($repZipPath);
    $repRes = $manifestService->validatePackageZip($repZipPath, $repDesc);
    assertEqual($repRes['reason_code'], 'package_entry_reparse', 'Reparse attribute in zip must be rejected');

    // 7.20g Unsupported opsys in ZIP (opsys 99)
    $unkZipPath = $lab . '/unk-delta.zip';
    buildDummyPackageZip($unkZipPath, $rawZipFilesBeta, ['unknown_attr_file' => 'POS Desktop.exe']);
    $unkDesc = $betaDescriptor;
    $unkDesc['package']['sha256'] = hash_file('sha256', $unkZipPath);
    $unkDesc['package']['size'] = filesize($unkZipPath);
    $unkRes = $manifestService->validatePackageZip($unkZipPath, $unkDesc);
    assertEqual($unkRes['reason_code'], 'package_entry_attributes_invalid', 'Unsupported opsys in zip must be rejected');

    // 7.20h Package size mismatch
    $badSizeDesc = $betaDescriptor;
    $badSizeDesc['package']['size'] = $badSizeDesc['package']['size'] + 10;
    $badSizeRes = $manifestService->validatePackageZip($betaZipPath, $badSizeDesc);
    assertEqual($badSizeRes['reason_code'], 'package_size_mismatch', 'Package size mismatch must be rejected');

    // 7.20i Package hash mismatch
    $badHashDesc = $betaDescriptor;
    $badHashDesc['package']['sha256'] = hash('sha256', 'corrupt_package');
    $badHashRes = $manifestService->validatePackageZip($betaZipPath, $badHashDesc);
    assertEqual($badHashRes['reason_code'], 'package_hash_mismatch', 'Package hash mismatch must be rejected');

    echo "  PASS: Negative policy matrix satisfied.\n\n";

    echo "=== ALL SIGNED NATIVE-SHELL DELTA ENGINE TESTS PASSED ===\n";

} finally {
    // 1. Explicitly remove tracked junctions first
    foreach ($createdJunctions as $junc) {
        removeTrackedJunction($junc);
    }
    // 2. Verify outside victim bytes intact AFTER product cleanup before victim cleanup
    foreach ($trackedVictims as $victimDir => $expectedVictimFiles) {
        if (is_dir($victimDir)) {
            foreach ($expectedVictimFiles as $vRel => $vContent) {
                $vPath = $victimDir . '/' . $vRel;
                if (!is_file($vPath) || file_get_contents($vPath) !== $vContent) {
                    throw new RuntimeException("Cleanup invariant failure: victim {$vPath} was modified or deleted!");
                }
            }
        }
    }
    // 3. Exact lab validation and lexical safe owned cleanup
    safeLexicalCleanup($lab, $expectedMarker);
}
