<?php
declare(strict_types=1);

// Dynamic verification suite runs strictly in GitHub Actions environment.
if (getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "Verification suite runs only in GitHub Actions environment (GITHUB_ACTIONS=true).\n");
    exit(1);
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

// -------------------------------------------------------------
// Tracked Junction and Lexical Cleanup Management
// -------------------------------------------------------------
$createdJunctions = [];

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

function removeAllTrackedJunctions(): void
{
    global $createdJunctions;
    while (!empty($createdJunctions)) {
        $j = array_pop($createdJunctions);
        if (file_exists($j) || is_link($j)) {
            $output = [];
            $exitCode = 0;
            exec('cmd /c rmdir ' . escapeshellarg(str_replace('/', '\\', $j)), $output, $exitCode);
            if ($exitCode !== 0) {
                throw new RuntimeException("Failed to remove junction during teardown: '{$j}'");
            }
        }
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
            if (!mkdir($dir, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }
        if (file_put_contents($full, $content) === false) {
            throw new RuntimeException("Failed to write fixture file: {$full}");
        }
    }
}

function snapshotTree(string $dir): array
{
    $hashes = [];
    if (!is_dir($dir)) {
        return $hashes;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        if ($item->isFile()) {
            $rel = ltrim(substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $dir))), '/');
            $h = hash_file('sha256', $item->getPathname());
            if ($h === false) {
                throw new RuntimeException("Failed to hash file during snapshot: " . $item->getPathname());
            }
            $hashes[$rel] = $h;
        }
    }
    ksort($hashes);
    return $hashes;
}

function runBuilderCli(array $args, array $env = []): array
{
    $builderScript = realpath(__DIR__ . '/build-shell-delta.php');
    if ($builderScript === false) {
        $builderScript = __DIR__ . '/build-shell-delta.php';
    }

    $cmd = array_merge([PHP_BINARY, $builderScript], $args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    // Preserve complete environment via getenv() to avoid losing SystemRoot/PATH/GITHUB_ACTIONS
    $processEnv = getenv();
    if (!is_array($processEnv)) {
        $processEnv = [];
    }
    foreach ($env as $k => $v) {
        $processEnv[$k] = $v;
    }
    if (getenv('GITHUB_ACTIONS') === 'true') {
        $processEnv['GITHUB_ACTIONS'] = 'true';
    }

    $proc = proc_open($cmd, $descriptors, $pipes, null, $processEnv);
    if (!is_resource($proc)) {
        throw new RuntimeException("Failed to spawn builder CLI process via proc_open.");
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    return [
        'exitCode' => $exitCode,
        'stdout' => $stdout ?: '',
        'stderr' => $stderr ?: '',
    ];
}

function assertNoStageOrLeaseLeft(string $parentDir): void
{
    $entries = scandir($parentDir);
    if ($entries === false) {
        throw new RuntimeException("Cannot list directory for leftover check: '{$parentDir}'");
    }
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        assertTrue(!str_starts_with($e, '.stage-'), "Leftover staging directory found in parent: {$e}");
        assertTrue(!str_ends_with($e, '.publease'), "Leftover publication lease found in parent: {$e}");
        assertTrue(!str_ends_with($e, '.owner'), "Leftover sibling owner marker found in parent: {$e}");
    }
}

function assertKeySecretAbsent(string $keyPem, array $outputsAndLogs, ?string $zipPath = null): void
{
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($keyPem)));
    $bodyLines = array_values(array_filter($lines, static fn (string $l): bool => !str_starts_with($l, '-----')));
    $snippet = substr(implode('', $bodyLines), 0, 64);

    foreach ($outputsAndLogs as $label => $content) {
        assertTrue(!str_contains($content, '-----BEGIN RSA PRIVATE KEY-----'), "Private key header leaked in {$label}");
        assertTrue(!str_contains($content, '-----BEGIN PRIVATE KEY-----'), "Private key header leaked in {$label}");
        if ($snippet !== '') {
            assertTrue(!str_contains($content, $snippet), "Private key snippet leaked in {$label}");
        }
    }

    if ($zipPath !== null && is_file($zipPath)) {
        $zip = new ZipArchive();
        $res = $zip->open($zipPath, ZipArchive::RDONLY);
        assertTrue($res === true, "Failed to open zip to scan decompressed entries for key leak: {$zipPath}");
        try {
            $numFiles = $zip->numFiles;
            for ($i = 0; $i < $numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $content = $zip->getFromIndex($i);
                assertTrue($content !== false, "Cannot read zip entry {$name} for key leak check");
                assertTrue(!str_contains($content, '-----BEGIN RSA PRIVATE KEY-----'), "Private key header leaked in zip entry {$name}");
                assertTrue(!str_contains($content, '-----BEGIN PRIVATE KEY-----'), "Private key header leaked in zip entry {$name}");
                if ($snippet !== '') {
                    assertTrue(!str_contains($content, $snippet), "Private key snippet leaked in zip entry {$name}");
                }
            }
        } finally {
            $zip->close();
        }
    }
}

function verifyZipAttributes(string $zipPath): void
{
    $zip = new ZipArchive();
    $res = $zip->open($zipPath, ZipArchive::RDONLY);
    assertTrue($res === true, "Failed to open package zip archive: {$zipPath}");
    try {
        $numFiles = $zip->numFiles;
        assertTrue($numFiles > 0, "Package zip must contain at least 1 file");
        for ($i = 0; $i < $numFiles; $i++) {
            $opsys = 0;
            $attr = 0;
            assertTrue($zip->getExternalAttributesIndex($i, $opsys, $attr), "Failed to read zip entry attributes at index {$i}");
            assertEqual($opsys, ZipArchive::OPSYS_UNIX, "Zip entry opsys must be UNIX (3)");
            $unixMode = ($attr >> 16) & 0xFFFF;
            assertEqual($unixMode, 0100644, "Zip entry mode must be regular Unix file 0100644");
        }
    } finally {
        $zip->close();
    }
}

function verifyPublishedAssets(string $outputDir): array
{
    $expectedAssets = [
        ShellDeltaManifestService::MANIFEST_ASSET,
        ShellDeltaManifestService::SIGNATURE_ASSET,
        ShellDeltaManifestService::PACKAGE_ASSET,
        'source-inventory.json',
        'target-inventory.json',
    ];
    sort($expectedAssets);

    $items = scandir($outputDir);
    if ($items === false) {
        throw new RuntimeException("Cannot list published output directory: {$outputDir}");
    }
    $actual = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $actual[] = $item;
    }
    sort($actual);
    assertEqual($actual, $expectedAssets, "Published output directory must contain exactly 5 assets");

    $manifestPath = $outputDir . '/' . ShellDeltaManifestService::MANIFEST_ASSET;
    $sigPath = $outputDir . '/' . ShellDeltaManifestService::SIGNATURE_ASSET;
    $zipPath = $outputDir . '/' . ShellDeltaManifestService::PACKAGE_ASSET;
    $srcInvPath = $outputDir . '/source-inventory.json';
    $tgtInvPath = $outputDir . '/target-inventory.json';

    $rawManifest = file_get_contents($manifestPath);
    assertTrue($rawManifest !== false, "Cannot read {$manifestPath}");
    $sigBase64 = trim((string)file_get_contents($sigPath));
    assertTrue($sigBase64 !== '', "Cannot read {$sigPath}");
    $descriptor = json_decode($rawManifest, true, 64, JSON_THROW_ON_ERROR);

    $rawSrcInv = file_get_contents($srcInvPath);
    assertTrue($rawSrcInv !== false, "Cannot read {$srcInvPath}");
    $srcInv = json_decode($rawSrcInv, true, 512, JSON_THROW_ON_ERROR);

    $rawTgtInv = file_get_contents($tgtInvPath);
    assertTrue($rawTgtInv !== false, "Cannot read {$tgtInvPath}");
    $tgtInv = json_decode($rawTgtInv, true, 512, JSON_THROW_ON_ERROR);

    return [
        'manifestPath' => $manifestPath,
        'sigPath' => $sigPath,
        'zipPath' => $zipPath,
        'rawManifest' => $rawManifest,
        'sigBase64' => $sigBase64,
        'descriptor' => $descriptor,
        'srcInv' => $srcInv,
        'tgtInv' => $tgtInv,
    ];
}

function verifyReconstruction(array $srcFiles, array $tgtFiles, array $descriptor, string $zipPath): void
{
    $zip = new ZipArchive();
    $res = $zip->open($zipPath, ZipArchive::RDONLY);
    assertTrue($res === true, "Failed to open zip archive for delta reconstruction test");
    try {
        $reconstructed = $srcFiles;
        foreach ($descriptor['deleted_files'] as $del) {
            unset($reconstructed[$del]);
        }
        foreach ($descriptor['files'] as $entry) {
            $p = $entry['path'];
            $stream = $zip->getStream($p);
            assertTrue($stream !== false, "Cannot stream entry {$p} from zip");
            $content = stream_get_contents($stream);
            fclose($stream);
            $reconstructed[$p] = [
                'sha256' => hash('sha256', $content),
                'size' => strlen($content),
            ];
        }
        ksort($reconstructed);
        ksort($tgtFiles);
        assertEqual($reconstructed, $tgtFiles, "Reconstructed target files must exactly match target inventory files");
    } finally {
        $zip->close();
    }
}

// -------------------------------------------------------------
// Setup Owned Lab and Protected Outside Victim
// -------------------------------------------------------------
$runnerTemp = getenv('RUNNER_TEMP');
$tempBase = (is_string($runnerTemp) && $runnerTemp !== '' && is_dir($runnerTemp))
    ? $runnerTemp
    : sys_get_temp_dir();

$lab = rtrim(str_replace('\\', '/', $tempBase), '/') . '/shell-delta-builder-lab-' . bin2hex(random_bytes(8));
if (!mkdir($lab, 0700, true)) {
    throw new RuntimeException("Failed to create owned lab directory: {$lab}");
}
$expectedLabMarker = 'OWNED_SHELL_DELTA_BUILDER_' . bin2hex(random_bytes(16));
file_put_contents($lab . '/.pos_owned_marker', $expectedLabMarker);

$victimDir = rtrim(str_replace('\\', '/', $tempBase), '/') . '/shell-delta-builder-victim-' . bin2hex(random_bytes(8));
if (!mkdir($victimDir, 0700, true)) {
    throw new RuntimeException("Failed to create victim directory: {$victimDir}");
}
$expectedVictimMarker = 'OWNED_VICTIM_' . bin2hex(random_bytes(16));
file_put_contents($victimDir . '/.pos_owned_marker', $expectedVictimMarker);
$victimSentinelFile = $victimDir . '/victim-sentinel.txt';
$victimSentinelContent = 'VICTIM_CONTENT_MUST_NOT_BE_MUTATED_OR_DELETED_' . bin2hex(random_bytes(16));
file_put_contents($victimSentinelFile, $victimSentinelContent);

try {
    echo "=== Running Shell Delta Builder Real-CLI Verification Suite ===\n\n";

    // Generate Ephemeral RSA 2048 Key Pair
    $ephemeralKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($ephemeralKey === false) {
        throw new RuntimeException("Failed to generate ephemeral RSA key pair.");
    }
    $privKeyDetails = openssl_pkey_get_details($ephemeralKey);
    $pubKeyPem = $privKeyDetails['key'];
    $exportRes = openssl_pkey_export($ephemeralKey, $privKeyPem);
    assertTrue($exportRes, "Failed to export ephemeral private key PEM");

    $keyFile = $lab . '/builder-private-key.pem';
    file_put_contents($keyFile, $privKeyPem);

    // Standard Format 1 Data Compatibility Contracts (exact 4 fields)
    $sourceContractData = [
        'format' => 1,
        'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
        'schema_sha256' => hash('sha256', 'mock_schema_source_v1'),
        'ledger_sha256' => hash('sha256', 'mock_ledger_source_v1'),
    ];
    $targetContractData = [
        'format' => 1,
        'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
        'schema_sha256' => hash('sha256', 'mock_schema_target_v2'),
        'ledger_sha256' => hash('sha256', 'mock_ledger_target_v2'),
    ];

    $sourceContractFile = $lab . '/source-data-contract.json';
    $targetContractFile = $lab . '/target-data-contract.json';
    file_put_contents($sourceContractFile, json_encode($sourceContractData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($targetContractFile, json_encode($targetContractData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Synthetic Win32 Base Product Files
    $baseProductFiles = [
        'POS Desktop.exe' => "EXE_SHELL_V13_CONTENT_" . str_repeat('A', 500),
        'resources/app.asar' => "ASAR_SHELL_V13_CONTENT_" . str_repeat('B', 1000),
        'resources/app.asar.unpacked/version.json' => json_encode(['version' => '0.0.12-beta.1']),
        'resources/app.asar.unpacked/package.json' => json_encode(['name' => 'pos-desktop', 'version' => '0.0.12-beta.1']),
        'resources/app.asar.unpacked/backend/backend.phar' => "PHAR_V12_CONTENT",
        'resources/app.asar.unpacked/backend/certs/cacert.pem' => "MOCK_CACERT",
        'resources/app.asar.unpacked/backend/certs/update_public_key.pem' => $pubKeyPem,
        'resources/app.asar.unpacked/database/pos_schema.sql' => "-- schema v12",
        'resources/app.asar.unpacked/frontend/dist/app.js' => "console.log('v12');",
        'resources/app.asar.unpacked/frontend/dist/legacy.js' => "console.log('old');",
        'resources/portable/runtime-manifest.json' => json_encode(['schemaVersion' => 1]),
        'resources/portable/php/php.exe' => "MOCK_PHP_EXE",
        'resources/portable/mysql/bin/mysqld.exe' => "MOCK_MYSQL_EXE",
        'locales/en-US.pak' => "PAK_EN_US_UNCHANGED",
        'icudtl.dat' => "ICU_DATA_UNCHANGED",
    ];

    // -------------------------------------------------------------
    // TEST 1: Positive Beta Update (native 13 / 0.0.12-beta.1 -> native 14 / 0.0.14-beta.1)
    // -------------------------------------------------------------
    echo "[Test 1] Positive Beta Update...\n";
    $betaSrcDir = $lab . '/beta-src';
    $betaTgtDir = $lab . '/beta-tgt';
    $betaOutDir = $lab . '/beta-out';

    populateTree($betaSrcDir, $baseProductFiles);

    $targetBetaFiles = $baseProductFiles;
    $targetBetaFiles['POS Desktop.exe'] = "EXE_SHELL_V14_CONTENT_" . str_repeat('Z', 600);
    $targetBetaFiles['resources/app.asar'] = "ASAR_SHELL_V14_CONTENT_" . str_repeat('Y', 1200);
    $targetBetaFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.14-beta.1']);
    $targetBetaFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.14-beta.1']);
    $targetBetaFiles['resources/app.asar.unpacked/backend/backend.phar'] = "PHAR_V14_CONTENT";
    $targetBetaFiles['resources/app.asar.unpacked/frontend/dist/app.js'] = "console.log('v14');";
    $targetBetaFiles['resources/app.asar.unpacked/frontend/dist/app-v14.js'] = "console.log('new feature');";
    unset($targetBetaFiles['resources/app.asar.unpacked/frontend/dist/legacy.js']);

    populateTree($betaTgtDir, $targetBetaFiles);

    $beforeSrcSnap = snapshotTree($betaSrcDir);
    $beforeTgtSnap = snapshotTree($betaTgtDir);
    $beforeKeyHash = hash_file('sha256', $keyFile);
    $beforeContractHash = hash_file('sha256', $sourceContractFile);

    $cliArgs = [
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $betaOutDir,
    ];

    $res = runBuilderCli($cliArgs);
    assertEqual($res['exitCode'], 0, "Beta update builder CLI must exit with status 0. Stderr: {$res['stderr']}");
    assertTrue(is_dir($betaOutDir), "Published output directory must exist: {$betaOutDir}");

    assertNoStageOrLeaseLeft($lab);

    $assets = verifyPublishedAssets($betaOutDir);
    verifyZipAttributes($assets['zipPath']);

    $sigService = new ManifestSignatureService(null, [$pubKeyPem]);
    $manifestService = new ShellDeltaManifestService($sigService, new ProductInventoryService());

    $verRes = $manifestService->verify($assets['rawManifest'], $assets['sigBase64']);
    assertTrue($verRes['ok'], "Manifest verify failed: " . ($verRes['reason_code'] ?? ''));

    $srcVerRes = $manifestService->verifySource($betaSrcDir, $assets['descriptor']);
    assertTrue($srcVerRes['ok'], "verifySource failed: " . ($srcVerRes['reason_code'] ?? ''));

    $zipVerRes = $manifestService->validatePackageZip($assets['zipPath'], $assets['descriptor']);
    assertTrue($zipVerRes['ok'], "validatePackageZip failed: " . ($zipVerRes['reason_code'] ?? ''));

    assertEqual($assets['descriptor']['source_inventory_sha256'], ShellDeltaManifestService::canonicalInventoryDigest($assets['srcInv']), "Source canonical inventory digest mismatch");
    assertEqual($assets['descriptor']['target_inventory_sha256'], ShellDeltaManifestService::canonicalInventoryDigest($assets['tgtInv']), "Target canonical inventory digest mismatch");

    verifyReconstruction($assets['srcInv']['files'], $assets['tgtInv']['files'], $assets['descriptor'], $assets['zipPath']);

    // Check sentinels: source, target, key, contracts byte-identical before and after
    assertEqual(snapshotTree($betaSrcDir), $beforeSrcSnap, "Source files must remain byte-identical");
    assertEqual(snapshotTree($betaTgtDir), $beforeTgtSnap, "Target files must remain byte-identical");
    assertEqual(hash_file('sha256', $keyFile), $beforeKeyHash, "Private key file must remain byte-identical");
    assertEqual(hash_file('sha256', $sourceContractFile), $beforeContractHash, "Contract file must remain byte-identical");

    // Check private key absent from all outputs, logs, AND decompressed zip entries
    assertKeySecretAbsent($privKeyPem, [
        'cli_stdout' => $res['stdout'],
        'cli_stderr' => $res['stderr'],
        'manifest' => $assets['rawManifest'],
        'sig' => $assets['sigBase64'],
        'source_inventory' => json_encode($assets['srcInv']),
        'target_inventory' => json_encode($assets['tgtInv']),
    ], $assets['zipPath']);

    echo "  PASS: Beta update verified successfully.\n\n";

    // -------------------------------------------------------------
    // TEST 2: Positive Stable Update with Inline JSON Contract
    // -------------------------------------------------------------
    echo "[Test 2] Positive Stable Update with Inline JSON Contract...\n";
    $stableSrcDir = $lab . '/stable-src';
    $stableTgtDir = $lab . '/stable-tgt';
    $stableOutDir = $lab . '/stable-out';

    $stableBase = $baseProductFiles;
    $stableBase['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.12']);
    $stableBase['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.12']);
    populateTree($stableSrcDir, $stableBase);

    $stableTarget = $targetBetaFiles;
    $stableTarget['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.14']);
    $stableTarget['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.14']);
    populateTree($stableTgtDir, $stableTarget);

    $inlineSourceContract = json_encode($sourceContractData, JSON_UNESCAPED_SLASHES);
    $inlineTargetContract = json_encode($targetContractData, JSON_UNESCAPED_SLASHES);

    $cliArgsStable = [
        '--source-root', $stableSrcDir,
        '--target-root', $stableTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $inlineSourceContract,
        '--target-data-contract', $inlineTargetContract,
        '--private-key', $keyFile,
        '--output-dir', $stableOutDir,
    ];

    $resStable = runBuilderCli($cliArgsStable);
    assertEqual($resStable['exitCode'], 0, "Stable update CLI must exit 0. Stderr: {$resStable['stderr']}");
    assertTrue(is_dir($stableOutDir), "Stable output directory must exist");
    assertNoStageOrLeaseLeft($lab);

    $assetsStable = verifyPublishedAssets($stableOutDir);
    assertEqual($assetsStable['descriptor']['channel'], 'stable', "Stable channel expected");
    assertEqual($assetsStable['descriptor']['prerelease'], false, "prerelease must be false for stable");

    $verResStable = $manifestService->verify($assetsStable['rawManifest'], $assetsStable['sigBase64']);
    assertTrue($verResStable['ok'], "Stable manifest verify failed: " . ($verResStable['reason_code'] ?? ''));

    echo "  PASS: Stable update with inline contract verified successfully.\n\n";

    // -------------------------------------------------------------
    // TEST 3: Positive Same Native Shell / Different App Version
    // -------------------------------------------------------------
    echo "[Test 3] Positive Same Native Shell / Different App Version...\n";
    $sameNativeSrcDir = $lab . '/same-native-src';
    $sameNativeTgtDir = $lab . '/same-native-tgt';
    $sameNativeOutDir = $lab . '/same-native-out';

    $sameNativeBase = $stableTarget; // Native 0.0.14, app 0.0.14
    populateTree($sameNativeSrcDir, $sameNativeBase);

    $sameNativeTarget = $sameNativeBase;
    $sameNativeTarget['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.15']);
    $sameNativeTarget['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.15']);
    $sameNativeTarget['resources/app.asar.unpacked/frontend/dist/app.js'] = "console.log('v15');";
    populateTree($sameNativeTgtDir, $sameNativeTarget);

    $cliArgsSameNative = [
        '--source-root', $sameNativeSrcDir,
        '--target-root', $sameNativeTgtDir,
        '--source-native-version', '0.0.14',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $sameNativeOutDir,
        '--purpose', 'regular',
    ];

    $resSameNative = runBuilderCli($cliArgsSameNative);
    assertEqual($resSameNative['exitCode'], 0, "Same native shell CLI must exit 0. Stderr: {$resSameNative['stderr']}");
    assertTrue(is_dir($sameNativeOutDir), "Same native output dir must exist");

    $assetsSameNative = verifyPublishedAssets($sameNativeOutDir);
    assertEqual($assetsSameNative['descriptor']['source_native_shell']['sha256'], $assetsSameNative['descriptor']['target_native_shell']['sha256'], "Native shell digests must match");
    assertTrue(count($assetsSameNative['descriptor']['files']) > 0, "Changed files must be packaged");

    echo "  PASS: Same native shell / app diff verified successfully.\n\n";

    // -------------------------------------------------------------
    // TEST 4: Positive Bootstrap Completion (Equal App, Different Native, Spaced Options)
    // -------------------------------------------------------------
    echo "[Test 4] Positive Bootstrap Completion with Spaced Purpose and Link...\n";
    $bootSrcDir = $lab . '/boot-src';
    $bootTgtDir = $lab . '/boot-tgt';
    $bootOutDir = $lab . '/boot-out';

    $bootBase = $baseProductFiles;
    $bootBase['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.14']);
    $bootBase['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.14']);
    populateTree($bootSrcDir, $bootBase);

    $bootTarget = $bootBase;
    $bootTarget['POS Desktop.exe'] = "EXE_SHELL_V14_NEW_BINARY_" . str_repeat('K', 800);
    $bootTarget['resources/app.asar'] = "ASAR_SHELL_V14_NEW_CONTAINER_" . str_repeat('L', 1600);
    populateTree($bootTgtDir, $bootTarget);

    $bootLink = str_repeat('d', 64);

    $cliArgsBoot = [
        '--source-root', $bootSrcDir,
        '--target-root', $bootTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $bootOutDir,
        '--purpose', 'bootstrap_completion',
        '--bootstrap-link', $bootLink,
    ];

    $resBoot = runBuilderCli($cliArgsBoot);
    assertEqual($resBoot['exitCode'], 0, "Bootstrap completion CLI must exit 0. Stderr: {$resBoot['stderr']}");
    assertTrue(is_dir($bootOutDir), "Bootstrap output dir must exist");

    $assetsBoot = verifyPublishedAssets($bootOutDir);
    assertEqual($assetsBoot['descriptor']['purpose'], 'bootstrap_completion', "Purpose must be bootstrap_completion");
    assertEqual($assetsBoot['descriptor']['bootstrap_link'], $bootLink, "Bootstrap link must match");
    assertTrue($assetsBoot['descriptor']['source_native_shell']['sha256'] !== $assetsBoot['descriptor']['target_native_shell']['sha256'], "Native shell must differ");

    echo "  PASS: Bootstrap completion verified successfully.\n\n";

    // -------------------------------------------------------------
    // TEST 5: Rejection of Missing Nested Parent in Output Dir
    // -------------------------------------------------------------
    echo "[Test 5] Rejection of Missing Nested Parent in Output Dir...\n";
    $missingParentOut = $lab . '/missing_dir_level1/level2/out';
    $resMissingParent = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $missingParentOut,
    ]);
    assertTrue($resMissingParent['exitCode'] !== 0, "Missing nested parent must exit non-zero");
    assertTrue(str_contains($resMissingParent['stderr'], 'Required path does not exist:'), "Expected missing parent rejection, got: {$resMissingParent['stderr']}");
    assertTrue(!file_exists($missingParentOut), "Missing parent output dir must not exist");
    assertNoStageOrLeaseLeft($lab);
    echo "  PASS: Missing nested parent rejected.\n\n";

    // -------------------------------------------------------------
    // TEST 6: Rejection of Existing Empty and Non-Empty Output Dirs
    // -------------------------------------------------------------
    echo "[Test 6] Rejection of Existing Empty and Non-Empty Output Dirs...\n";
    $existingEmpty = $lab . '/existing-empty-out';
    assertTrue(mkdir($existingEmpty, 0700), "Failed to mkdir existing empty dir");
    $resExistEmpty = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $existingEmpty,
    ]);
    assertTrue($resExistEmpty['exitCode'] !== 0, "Existing empty output dir must exit non-zero");
    assertTrue(str_contains($resExistEmpty['stderr'], 'Output directory already exists'), "Expected 'Output directory already exists', got: {$resExistEmpty['stderr']}");
    assertEqual(scandir($existingEmpty), ['.', '..'], "Existing empty dir must remain unmodified");

    $existingNonEmpty = $lab . '/existing-full-out';
    assertTrue(mkdir($existingNonEmpty, 0700), "Failed to mkdir existing full dir");
    file_put_contents($existingNonEmpty . '/sentinel.txt', 'SENTINEL_CONTENT');
    $resExistFull = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $existingNonEmpty,
    ]);
    assertTrue($resExistFull['exitCode'] !== 0, "Existing non-empty output dir must exit non-zero");
    assertTrue(str_contains($resExistFull['stderr'], 'Output directory already exists'), "Expected 'Output directory already exists', got: {$resExistFull['stderr']}");
    assertEqual(file_get_contents($existingNonEmpty . '/sentinel.txt'), 'SENTINEL_CONTENT', "Existing non-empty dir contents must remain untouched");
    echo "  PASS: Existing output directories rejected without overwrite.\n\n";

    // -------------------------------------------------------------
    // TEST 7: Ancestor & Equality Overlap Checks (Both Directions)
    // -------------------------------------------------------------
    echo "[Test 7] Ancestor & Equality Overlap Checks (Both Directions)...\n";
    // 7.1 Source == Target
    $resEqualSrcTgt = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaSrcDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-test-eq',
    ]);
    assertTrue($resEqualSrcTgt['exitCode'] !== 0, "Source == Target must exit non-zero");
    assertTrue(str_contains($resEqualSrcTgt['stderr'], 'Source root and target root must be distinct directories'), "Expected distinct error, got: {$resEqualSrcTgt['stderr']}");

    // 7.2 Source inside Target
    $resSrcInTgt = runBuilderCli([
        '--source-root', $betaTgtDir . '/resources',
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-test-src-in-tgt',
    ]);
    assertTrue($resSrcInTgt['exitCode'] !== 0, "Source inside Target must exit non-zero");
    assertTrue(str_contains($resSrcInTgt['stderr'], 'Source root and target root must not contain or overlap one another'), "Expected overlap error, got: {$resSrcInTgt['stderr']}");

    // 7.3 Target inside Source
    $resTgtInSrc = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaSrcDir . '/resources',
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-test-tgt-in-src',
    ]);
    assertTrue($resTgtInSrc['exitCode'] !== 0, "Target inside Source must exit non-zero");
    assertTrue(str_contains($resTgtInSrc['stderr'], 'Source root and target root must not contain or overlap one another'), "Expected overlap error, got: {$resTgtInSrc['stderr']}");

    // 7.4 Missing Output inside Source
    $resOutInSrc = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $betaSrcDir . '/sub-out-missing',
    ]);
    assertTrue($resOutInSrc['exitCode'] !== 0, "Missing output inside source must exit non-zero");
    assertTrue(str_contains($resOutInSrc['stderr'], 'Output directory must not be inside source root'), "Expected output inside source error, got: {$resOutInSrc['stderr']}");

    // 7.5 Missing Output inside Target
    $resOutInTgt = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $betaTgtDir . '/sub-out-missing',
    ]);
    assertTrue($resOutInTgt['exitCode'] !== 0, "Missing output inside target must exit non-zero");
    assertTrue(str_contains($resOutInTgt['stderr'], 'Output directory must not be inside target root'), "Expected output inside target error, got: {$resOutInTgt['stderr']}");

    // 7.6 Output is Ancestor of Source (e.g. output is $lab, source is $lab/beta-src)
    $resOutAncestorSrc = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab,
    ]);
    assertTrue($resOutAncestorSrc['exitCode'] !== 0, "Output as ancestor of source must exit non-zero");
    assertTrue(str_contains($resOutAncestorSrc['stderr'], 'Output directory already exists') || str_contains($resOutAncestorSrc['stderr'], 'Output directory must not be an ancestor of source root'), "Expected ancestor or existing error, got: {$resOutAncestorSrc['stderr']}");

    echo "  PASS: Overlap and ancestor constraints enforced in both directions.\n\n";

    // -------------------------------------------------------------
    // TEST 8: Private Key Boundaries and Strength
    // -------------------------------------------------------------
    echo "[Test 8] Private Key Boundaries and Strength...\n";
    // 8.1 Key inside Source
    $keyInSrc = $betaSrcDir . '/key.pem';
    file_put_contents($keyInSrc, $privKeyPem);
    $resKeyInSrc = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyInSrc,
        '--output-dir', $lab . '/out-key-insrc',
    ]);
    assertTrue($resKeyInSrc['exitCode'] !== 0, "Key inside source must exit non-zero");
    assertTrue(str_contains($resKeyInSrc['stderr'], 'Private key must be outside source root'), "Expected outside source error, got: {$resKeyInSrc['stderr']}");
    unlink($keyInSrc);

    // 8.2 Corrupted Key PEM
    $corruptKeyFile = $lab . '/corrupt-key.pem';
    file_put_contents($corruptKeyFile, "NOT_A_VALID_PEM_CONTENT\n");
    $resCorruptKey = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $corruptKeyFile,
        '--output-dir', $lab . '/out-corrupt-key',
    ]);
    assertTrue($resCorruptKey['exitCode'] !== 0, "Corrupted key must exit non-zero");
    assertTrue(str_contains($resCorruptKey['stderr'], 'Failed to parse private key PEM'), "Expected corrupt PEM error, got: {$resCorruptKey['stderr']}");

    // 8.3 RSA 1024-bit Key Rejected
    $key1024 = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $pem1024 = '';
    openssl_pkey_export($key1024, $pem1024);
    $key1024File = $lab . '/key-1024.pem';
    file_put_contents($key1024File, $pem1024);
    $resKey1024 = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $key1024File,
        '--output-dir', $lab . '/out-key1024',
    ]);
    assertTrue($resKey1024['exitCode'] !== 0, "1024-bit RSA key must exit non-zero");
    assertTrue(str_contains($resKey1024['stderr'], 'Private key must be an RSA key of at least 2048 bits'), "Expected 2048+ bits error, got: {$resKey1024['stderr']}");

    echo "  PASS: Private key boundaries and RSA 2048+ verified.\n\n";

    // -------------------------------------------------------------
    // TEST 9: Data Compatibility Contract Strictness (No Silent Strip)
    // -------------------------------------------------------------
    echo "[Test 9] Data Compatibility Contract Strictness...\n";
    // Extra field in profile must be rejected (not silently stripped)
    $extraFieldContract = $sourceContractData;
    $extraFieldContract['unknown_field'] = 'should_not_be_ignored';
    $extraContractJson = json_encode($extraFieldContract);
    $resExtraField = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $extraContractJson,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-extra-contract',
    ]);
    assertTrue($resExtraField['exitCode'] !== 0, "Contract with extra unknown fields must exit non-zero");
    assertTrue(str_contains($resExtraField['stderr'], 'profile does not satisfy exact format 1 UpdateDataCompatibilityService specification'), "Expected exact format specification error, got: {$resExtraField['stderr']}");

    // Wrong metadata_migration
    $badMigrationContract = $sourceContractData;
    $badMigrationContract['metadata_migration'] = 'invalid_migration_tag';
    $resBadMigration = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', json_encode($badMigrationContract),
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-bad-migration',
    ]);
    assertTrue($resBadMigration['exitCode'] !== 0, "Contract with wrong metadata_migration must exit non-zero");
    assertTrue(str_contains($resBadMigration['stderr'], 'metadata_migration must be'), "Expected metadata_migration error, got: {$resBadMigration['stderr']}");

    echo "  PASS: Contract profile exact four fields verified.\n\n";

    // -------------------------------------------------------------
    // TEST 10: Malformed Flags, Unknown Arguments, Duplicates, Trailing Tokens
    // -------------------------------------------------------------
    echo "[Test 10] Malformed Flags, Unknown Arguments, Duplicates, Trailing Tokens...\n";
    // 10.1 Unknown argument
    $resUnknownArg = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-unknown-arg',
        '--unknown-flag', 'value',
    ]);
    assertTrue($resUnknownArg['exitCode'] !== 0, "Unknown argument must exit non-zero");
    assertTrue(str_contains($resUnknownArg['stderr'], "Unknown argument '--unknown-flag'"), "Expected unknown arg error, got: {$resUnknownArg['stderr']}");

    // 10.2 Duplicate argument
    $resDuplicateArg = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-dup-arg',
        '--purpose', 'regular',
        '--purpose', 'regular',
    ]);
    assertTrue($resDuplicateArg['exitCode'] !== 0, "Duplicate argument must exit non-zero");
    assertTrue(str_contains($resDuplicateArg['stderr'], "Duplicate argument '--purpose'"), "Expected duplicate arg error, got: {$resDuplicateArg['stderr']}");

    // 10.3 Invalid trailing token
    $resTrailingToken = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-trailing',
        'unrecognized_trailing_positional_token',
    ]);
    assertTrue($resTrailingToken['exitCode'] !== 0, "Trailing token must exit non-zero");
    assertTrue(str_contains($resTrailingToken['stderr'], 'Unexpected or invalid argument'), "Expected trailing token error, got: {$resTrailingToken['stderr']}");

    // 10.4 Bootstrap link with regular purpose
    $resLinkWithRegular = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-link-regular',
        '--purpose', 'regular',
        '--bootstrap-link', str_repeat('a', 64),
    ]);
    assertTrue($resLinkWithRegular['exitCode'] !== 0, "Bootstrap link with purpose regular must exit non-zero");
    assertTrue(str_contains($resLinkWithRegular['stderr'], 'unexpected and rejected for purpose \'regular\''), "Expected link with regular error, got: {$resLinkWithRegular['stderr']}");

    // 10.5 Missing bootstrap link for bootstrap_completion
    $resMissingBootLink = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-missing-bootlink',
        '--purpose', 'bootstrap_completion',
    ]);
    assertTrue($resMissingBootLink['exitCode'] !== 0, "Missing bootstrap link for bootstrap_completion must exit non-zero");
    assertTrue(str_contains($resMissingBootLink['stderr'], 'Option --bootstrap-link with 64-hex SHA-256 is required'), "Expected missing link error, got: {$resMissingBootLink['stderr']}");

    echo "  PASS: CLI argument syntax and validation verified.\n\n";

    // -------------------------------------------------------------
    // TEST 11: Business Logic Violations (Cross-Channel, Downgrade, No Changes)
    // -------------------------------------------------------------
    echo "[Test 11] Business Logic Violations...\n";
    // 11.1 Cross-channel (Beta source -> Stable target)
    $resCrossChannel = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $stableTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-cross-channel',
    ]);
    assertTrue($resCrossChannel['exitCode'] !== 0, "Cross-channel delta must exit non-zero");
    assertTrue(str_contains($resCrossChannel['stderr'], 'Cross-channel operation rejected'), "Expected cross-channel error, got: {$resCrossChannel['stderr']}");

    // 11.2 Downgrade (0.0.14 -> 0.0.12)
    $resDowngrade = runBuilderCli([
        '--source-root', $betaTgtDir,
        '--target-root', $betaSrcDir,
        '--source-native-version', '0.0.14',
        '--target-native-version', '0.0.13',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-downgrade',
    ]);
    assertTrue($resDowngrade['exitCode'] !== 0, "Downgrade delta must exit non-zero");
    assertTrue(str_contains($resDowngrade['stderr'], 'must be newer than source'), "Expected downgrade error, got: {$resDowngrade['stderr']}");

    // 11.3 Unchanged application version / identical files
    $sameNativeIdenticalTgt = $lab . '/same-native-identical-tgt';
    $noChangesOut = $lab . '/out-no-changes';
    populateTree($sameNativeIdenticalTgt, $sameNativeBase);
    $resNoChanges = runBuilderCli([
        '--source-root', $sameNativeSrcDir,
        '--target-root', $sameNativeIdenticalTgt,
        '--source-native-version', '0.0.14',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $noChangesOut,
    ]);
    assertTrue($resNoChanges['exitCode'] !== 0, "Unchanged app version must exit non-zero");
    assertTrue(str_contains($resNoChanges['stderr'], 'must be newer than source'), "Expected unchanged app version error, got: {$resNoChanges['stderr']}");
    assertTrue(!file_exists($noChangesOut), "No-changes output dir must not exist");
    assertNoStageOrLeaseLeft($lab);

    echo "  PASS: Business logic rejections verified.\n\n";

    // -------------------------------------------------------------
    // TEST 12: Reparse Points and Actual Windows Junctions
    // -------------------------------------------------------------
    echo "[Test 12] Reparse Points and Actual Windows Junctions...\n";
    // 12.1 Source is a junction
    $jSrc = $lab . '/junction-src';
    createTrackedJunction($jSrc, $betaSrcDir);
    $resJSrc = runBuilderCli([
        '--source-root', $jSrc,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-jsrc',
    ]);
    removeTrackedJunction($jSrc);
    assertTrue($resJSrc['exitCode'] !== 0, "Junction source root must exit non-zero");
    assertTrue(str_contains($resJSrc['stderr'], 'Reparse point'), "Expected reparse point error, got: {$resJSrc['stderr']}");

    // 12.2 Target is a junction
    $jTgt = $lab . '/junction-tgt';
    createTrackedJunction($jTgt, $betaTgtDir);
    $resJTgt = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $jTgt,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $lab . '/out-jtgt',
    ]);
    removeTrackedJunction($jTgt);
    assertTrue($resJTgt['exitCode'] !== 0, "Junction target root must exit non-zero");
    assertTrue(str_contains($resJTgt['stderr'], 'Reparse point'), "Expected reparse point error, got: {$resJTgt['stderr']}");

    // 12.3 Output parent is a junction
    $realParent = $lab . '/real-parent-dir';
    assertTrue(mkdir($realParent, 0700), "Failed to mkdir real parent dir");
    $jParent = $lab . '/junction-parent-dir';
    createTrackedJunction($jParent, $realParent);
    $resJParent = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $jParent . '/out-dir',
    ]);
    removeTrackedJunction($jParent);
    assertTrue(rmdir($realParent), "Failed to rmdir real parent dir");
    assertTrue($resJParent['exitCode'] !== 0, "Junction output parent must exit non-zero");
    assertTrue(str_contains($resJParent['stderr'], 'Reparse point'), "Expected reparse point error, got: {$resJParent['stderr']}");

    echo "  PASS: Junction and reparse point protections verified.\n\n";

    // -------------------------------------------------------------
    // TEST 13: Synthetic Fault Injection & Clean Stage Cleanup
    // -------------------------------------------------------------
    echo "[Test 13] Synthetic Fault Injection & Clean Stage Cleanup...\n";
    $faultOutDir = $lab . '/fault-out';

    // 13.1 Fault: before_publish
    $resFaultBeforePub = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $faultOutDir,
    ], ['SHELL_DELTA_BUILDER_TEST_FAULT' => 'before_publish']);

    assertTrue($resFaultBeforePub['exitCode'] !== 0, "Fault injection before_publish must exit non-zero");
    assertTrue(!file_exists($faultOutDir), "Output directory must NOT exist after fault injection");
    assertTrue(str_contains($resFaultBeforePub['stderr'], 'Synthetic fault simulation triggered: before_publish'), "Stderr must contain synthetic simulation message");
    assertTrue(!str_contains($resFaultBeforePub['stderr'], 'ValueError'), "Stderr must not contain uncaught ValueError");
    assertTrue(!str_contains($resFaultBeforePub['stderr'], 'Invalid or uninitialized Zip object'), "Stderr must not contain uninitialized Zip error");
    assertNoStageOrLeaseLeft($lab);

    // 13.2 Fault: after_zip
    $resFaultAfterZip = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $faultOutDir,
    ], ['SHELL_DELTA_BUILDER_TEST_FAULT' => 'after_zip']);

    assertTrue($resFaultAfterZip['exitCode'] !== 0, "Fault injection after_zip must exit non-zero");
    assertTrue(!file_exists($faultOutDir), "Output directory must NOT exist after fault injection");
    assertTrue(str_contains($resFaultAfterZip['stderr'], 'Synthetic fault simulation triggered: after_zip'), "Stderr must contain synthetic simulation message");
    assertTrue(!str_contains($resFaultAfterZip['stderr'], 'ValueError'), "Stderr must not contain uncaught ValueError");
    assertTrue(!str_contains($resFaultAfterZip['stderr'], 'Invalid or uninitialized Zip object'), "Stderr must not contain uninitialized Zip error");
    assertNoStageOrLeaseLeft($lab);

    // 13.3 Fault: short_lease_write (partial lease write before full token)
    $resShortLease = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $faultOutDir,
    ], ['SHELL_DELTA_BUILDER_TEST_FAULT' => 'short_lease_write']);

    assertTrue($resShortLease['exitCode'] !== 0, "short_lease_write must exit non-zero");
    assertTrue(!file_exists($faultOutDir), "Output directory must NOT exist after short_lease_write");
    assertTrue(str_contains($resShortLease['stderr'], 'Synthetic fault simulation triggered: short_lease_write'), "Stderr must contain short_lease_write message");
    assertTrue(!str_contains($resShortLease['stderr'], 'ValueError'), "Stderr must not contain uncaught ValueError");
    assertNoStageOrLeaseLeft($lab);

    // 13.4 Fault: short_owner_write (partial owner marker write before stage mkdir)
    $resShortOwner = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $faultOutDir,
    ], ['SHELL_DELTA_BUILDER_TEST_FAULT' => 'short_owner_write']);

    assertTrue($resShortOwner['exitCode'] !== 0, "short_owner_write must exit non-zero");
    assertTrue(!file_exists($faultOutDir), "Output directory must NOT exist after short_owner_write");
    assertTrue(str_contains($resShortOwner['stderr'], 'Synthetic fault simulation triggered: short_owner_write'), "Stderr must contain short_owner_write message");
    assertTrue(!str_contains($resShortOwner['stderr'], 'ValueError'), "Stderr must not contain uncaught ValueError");
    assertNoStageOrLeaseLeft($lab);

    // 13.5 Fault: short_asset_write (partial stage asset write)
    $resShortAsset = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $faultOutDir,
    ], ['SHELL_DELTA_BUILDER_TEST_FAULT' => 'short_asset_write']);

    assertTrue($resShortAsset['exitCode'] !== 0, "short_asset_write must exit non-zero");
    assertTrue(!file_exists($faultOutDir), "Output directory must NOT exist after short_asset_write");
    assertTrue(str_contains($resShortAsset['stderr'], 'Synthetic fault simulation triggered: short_asset_write'), "Stderr must contain short_asset_write message");
    assertTrue(!str_contains($resShortAsset['stderr'], 'ValueError'), "Stderr must not contain uncaught ValueError");
    assertNoStageOrLeaseLeft($lab);

    // 13.6 Fault: pre_rename (failure immediately prior to rename)
    $resPreRename = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $faultOutDir,
    ], ['SHELL_DELTA_BUILDER_TEST_FAULT' => 'pre_rename']);

    assertTrue($resPreRename['exitCode'] !== 0, "pre_rename must exit non-zero");
    assertTrue(!file_exists($faultOutDir), "Output directory must NOT exist after pre_rename failure");
    assertTrue(str_contains($resPreRename['stderr'], 'Synthetic fault simulation triggered: pre_rename'), "Stderr must contain pre_rename message");
    assertTrue(!str_contains($resPreRename['stderr'], 'ValueError'), "Stderr must not contain uncaught ValueError");
    assertTrue(!str_contains($resPreRename['stderr'], 'Invalid or uninitialized Zip object'), "Stderr must not contain uninitialized Zip error");
    assertNoStageOrLeaseLeft($lab);

    // 13.7 Fault: foreign_marker_same_prefix (foreign full marker with same prefix, different token while owned stage exists)
    $foreignMarkerOutDir = $lab . '/foreign-marker-out';

    // Place an unrelated foreign publication lease in $lab with same prefix 'LEASE_TOKEN_' but different token
    $foreignLeaseFile = $lab . '/.foreign-dummy.publease';
    $foreignLeaseContent = "LEASE_TOKEN_FOREIGN_DIFF_" . bin2hex(random_bytes(16)) . "\n";
    file_put_contents($foreignLeaseFile, $foreignLeaseContent);

    $resForeignMarker = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $foreignMarkerOutDir,
    ], ['SHELL_DELTA_BUILDER_TEST_FAULT' => 'foreign_marker_same_prefix']);

    assertTrue($resForeignMarker['exitCode'] !== 0, "foreign_marker_same_prefix must exit non-zero");
    assertTrue(!file_exists($foreignMarkerOutDir), "Output directory must NOT exist after foreign_marker_same_prefix failure");
    assertTrue(str_contains($resForeignMarker['stderr'], 'Synthetic fault simulation triggered: foreign_marker_same_prefix'), "Stderr must contain foreign_marker_same_prefix message");
    assertTrue(str_contains($resForeignMarker['stderr'], 'NEEDS ATTENTION'), "Stderr must report NEEDS ATTENTION on ownership proof failure");
    assertTrue(!str_contains($resForeignMarker['stderr'], 'ValueError'), "Stderr must not contain uncaught ValueError");

    // Another foreign LEASE with same prefix and different token must NOT be unlinked!
    assertTrue(file_exists($foreignLeaseFile), "Foreign publication lease with same prefix must NOT be unlinked");
    assertEqual(file_get_contents($foreignLeaseFile), $foreignLeaseContent, "Foreign lease content must remain untouched");

    // The owned stage directory and foreign marker MUST BE PRESERVED (intentional retention)!
    $labEntries = scandir($lab);
    $retainedStages = array_values(array_filter($labEntries, static fn($f) => str_starts_with($f, '.stage-foreign-marker-out-')));
    $retainedMarkers = array_values(array_filter($labEntries, static fn($f) => str_starts_with($f, '..stage-foreign-marker-out-') && str_ends_with($f, '.owner')));

    assertTrue(count($retainedStages) === 1, "Exactly one stage directory must be retained on foreign marker mismatch");
    assertTrue(count($retainedMarkers) === 1, "Exactly one foreign marker file must be retained on foreign marker mismatch");

    $retainedStagePath = $lab . '/' . $retainedStages[0];
    $retainedMarkerPath = $lab . '/' . $retainedMarkers[0];

    // Stage contents must be preserved (e.g. package zip exists)
    assertTrue(file_exists($retainedStagePath . '/' . ShellDeltaManifestService::PACKAGE_ASSET), "Retained stage must preserve its package asset");
    assertTrue(str_starts_with(file_get_contents($retainedMarkerPath), 'STAGE_OWNER_foreign_'), "Retained marker must preserve foreign token");

    // Test owns whole Master Lab and cleans fixture ONLY AFTER preservation asserts
    unlink($foreignLeaseFile);
    unlink($retainedMarkerPath);
    safeLexicalSubdirCleanup($retainedStagePath);
    rmdir($retainedStagePath);

    assertNoStageOrLeaseLeft($lab);

    assertEqual(file_get_contents($victimSentinelFile), $victimSentinelContent, "Victim sentinel must remain untouched during fault injection tests");

    echo "  PASS: Fault injection after_zip, before_publish, short_*, pre_rename, and foreign_marker_same_prefix verified.\n\n";

    // -------------------------------------------------------------
    // TEST 14: Input Drift Rejection (Source & Target Drift After ZIP)
    // -------------------------------------------------------------
    echo "[Test 14] Input Drift Rejection...\n";
    $driftOutDir = $lab . '/drift-out';

    $resTargetDrift = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $driftOutDir,
    ], ['SHELL_DELTA_BUILDER_TEST_FAULT' => 'simulate_target_drift']);

    assertTrue($resTargetDrift['exitCode'] !== 0, "Target drift must exit non-zero");
    assertTrue(str_contains($resTargetDrift['stderr'], 'Target installation drifted during delta build'), "Expected target drift error, got: {$resTargetDrift['stderr']}");
    assertTrue(!file_exists($driftOutDir), "Output directory must NOT exist after drift rejection");
    assertNoStageOrLeaseLeft($lab);

    // Restore target version.json
    file_put_contents($betaTgtDir . '/resources/app.asar.unpacked/version.json', json_encode(['version' => '0.0.14-beta.1']));

    $resSourceDrift = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $driftOutDir,
    ], ['SHELL_DELTA_BUILDER_TEST_FAULT' => 'simulate_source_drift']);

    assertTrue($resSourceDrift['exitCode'] !== 0, "Source drift must exit non-zero");
    assertTrue(str_contains($resSourceDrift['stderr'], 'Source installation drifted during delta build'), "Expected source drift error, got: {$resSourceDrift['stderr']}");
    assertTrue(!file_exists($driftOutDir), "Output directory must NOT exist after drift rejection");
    assertNoStageOrLeaseLeft($lab);

    // Restore source version.json
    file_put_contents($betaSrcDir . '/resources/app.asar.unpacked/version.json', json_encode(['version' => '0.0.12-beta.1']));

    echo "  PASS: Input drift rejection verified.\n\n";

    // -------------------------------------------------------------
    // TEST 15: Windows Destination Collision & Immutability Prior to Rename
    // -------------------------------------------------------------
    echo "[Test 15] Windows Destination Collision & Immutability Prior to Rename...\n";

    // 15.0 GHA Empirical probe on owned separate fixtures for PHP rename() primitive behavior
    echo "  Probing PHP rename() primitive on owned separate fixtures...\n";
    $probeSrc = $lab . '/probe-rename-src';
    mkdir($probeSrc, 0700);
    file_put_contents($probeSrc . '/probe-src.txt', 'probe_source_data');

    $probeDstEmpty = $lab . '/probe-rename-dst-empty';
    mkdir($probeDstEmpty, 0700);
    $renameEmptyRes = @rename($probeSrc, $probeDstEmpty);
    assertTrue($renameEmptyRes === false, "rename() over existing empty directory must return false on Windows");
    assertTrue(is_file($probeSrc . '/probe-src.txt'), "Source file must remain untouched after failed rename over empty dir");
    assertEqual(scandir($probeDstEmpty), ['.', '..'], "Empty destination must remain empty after failed rename");
    rmdir($probeDstEmpty);

    $probeDstNonEmpty = $lab . '/probe-rename-dst-nonempty';
    mkdir($probeDstNonEmpty, 0700);
    file_put_contents($probeDstNonEmpty . '/probe-dst.txt', 'probe_dest_sentinel');
    $renameNonEmptyRes = @rename($probeSrc, $probeDstNonEmpty);
    assertTrue($renameNonEmptyRes === false, "rename() over existing non-empty directory must return false on Windows");
    assertTrue(is_file($probeSrc . '/probe-src.txt'), "Source file must remain untouched after failed rename over non-empty dir");
    assertEqual(file_get_contents($probeDstNonEmpty . '/probe-dst.txt'), 'probe_dest_sentinel', "Destination sentinel must remain untouched after failed rename");
    unlink($probeDstNonEmpty . '/probe-dst.txt');
    rmdir($probeDstNonEmpty);

    unlink($probeSrc . '/probe-src.txt');
    rmdir($probeSrc);

    // 15.1 Foreign empty destination created right before rename
    $preRenameEmptyOut = $lab . '/pre-rename-empty-out';
    $resPreRenameEmpty = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $preRenameEmptyOut,
    ], ['SHELL_DELTA_BUILDER_TEST_FAULT' => 'pre_rename_create_empty_dest']);

    assertTrue($resPreRenameEmpty['exitCode'] !== 0, "Pre-rename empty dest creation must fail builder");
    assertTrue(str_contains($resPreRenameEmpty['stderr'], 'Output directory exists immediately prior to publication rename'), "Expected pre-rename output exists error, got: {$resPreRenameEmpty['stderr']}");
    assertTrue(is_dir($preRenameEmptyOut), "Empty destination directory must remain existing (not deleted)");
    assertEqual(scandir($preRenameEmptyOut), ['.', '..'], "Empty destination directory must remain unmodified");
    assertNoStageOrLeaseLeft($lab);
    rmdir($preRenameEmptyOut);

    // 15.2 Foreign non-empty destination created right before rename
    $preRenameFullOut = $lab . '/pre-rename-full-out';
    $resPreRenameFull = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $preRenameFullOut,
    ], ['SHELL_DELTA_BUILDER_TEST_FAULT' => 'pre_rename_create_nonempty_dest']);

    assertTrue($resPreRenameFull['exitCode'] !== 0, "Pre-rename nonempty dest creation must fail builder");
    assertTrue(str_contains($resPreRenameFull['stderr'], 'Output directory exists immediately prior to publication rename'), "Expected pre-rename output exists error, got: {$resPreRenameFull['stderr']}");
    assertTrue(is_file($preRenameFullOut . '/foreign.txt'), "Foreign file in destination must remain untouched");
    assertEqual(file_get_contents($preRenameFullOut . '/foreign.txt'), 'foreign_data', "Foreign file content must remain unchanged");
    assertNoStageOrLeaseLeft($lab);
    unlink($preRenameFullOut . '/foreign.txt');
    rmdir($preRenameFullOut);

    echo "  PASS: Windows destination immutability prior to rename verified.\n\n";

    // -------------------------------------------------------------
    // TEST 16: Two Concurrent CLI Processes Output Publication Lease Competition
    // -------------------------------------------------------------
    echo "[Test 16] Two Concurrent CLI Processes Publication Lease Competition...\n";
    $compOutDir = $lab . '/competition-out';
    $builderScript = realpath(__DIR__ . '/build-shell-delta.php');
    if ($builderScript === false) {
        $builderScript = __DIR__ . '/build-shell-delta.php';
    }

    $compArgs = [
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $sourceContractFile,
        '--target-data-contract', $targetContractFile,
        '--private-key', $keyFile,
        '--output-dir', $compOutDir,
    ];

    $cmdComp = array_merge([PHP_BINARY, $builderScript], $compArgs);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $procEnv = getenv();
    if (!is_array($procEnv)) {
        $procEnv = [];
    }
    if (getenv('GITHUB_ACTIONS') === 'true') {
        $procEnv['GITHUB_ACTIONS'] = 'true';
    }

    $p1 = proc_open($cmdComp, $descriptors, $pipes1, null, $procEnv);
    $p2 = proc_open($cmdComp, $descriptors, $pipes2, null, $procEnv);
    assertTrue(is_resource($p1) && is_resource($p2), "Failed to spawn concurrent builder processes");

    fclose($pipes1[0]);
    fclose($pipes2[0]);

    $out1 = stream_get_contents($pipes1[1]);
    $err1 = stream_get_contents($pipes1[2]);
    fclose($pipes1[1]);
    fclose($pipes1[2]);
    $code1 = proc_close($p1);

    $out2 = stream_get_contents($pipes2[1]);
    $err2 = stream_get_contents($pipes2[2]);
    fclose($pipes2[1]);
    fclose($pipes2[2]);
    $code2 = proc_close($p2);

    $successCount = ($code1 === 0 ? 1 : 0) + ($code2 === 0 ? 1 : 0);
    assertTrue($successCount <= 1, "At most one concurrent builder process may succeed (successCount: {$successCount})");

    if ($successCount === 1) {
        assertTrue(is_dir($compOutDir), "Winning builder process must produce output directory");
        verifyPublishedAssets($compOutDir);
        $losingErr = ($code1 !== 0 ? $err1 : $err2);
        assertTrue(str_contains($losingErr, 'Cannot acquire publication lease') || str_contains($losingErr, 'Output directory already exists'), "Losing process must report lease or existing output error: {$losingErr}");
    } else {
        assertTrue(!is_dir($compOutDir), "If both processes backed off, output directory must not exist");
    }

    assertNoStageOrLeaseLeft($lab);

    echo "  PASS: Concurrent CLI processes lease competition verified.\n\n";

    echo "=== All Shell Delta Builder Verification Tests Passed Successfully ===\n";

} finally {
    removeAllTrackedJunctions();
    safeLexicalCleanup($lab, $expectedLabMarker);

    // Verify victim sentinel AFTER lab product cleanup and BEFORE victim cleanup
    assertTrue(is_dir($victimDir), "Victim directory must still exist outside lab before victim cleanup");
    assertTrue(file_exists($victimSentinelFile), "Victim sentinel file must exist before victim cleanup");
    assertEqual(file_get_contents($victimSentinelFile), $victimSentinelContent, "Victim sentinel content must be completely untouched before victim cleanup");

    safeLexicalCleanup($victimDir, $expectedVictimMarker);
}
