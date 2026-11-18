<?php
declare(strict_types=1);

/**
 * scripts/create-shell-native-worker-image-set-fixture.php
 *
 * GHA-ONLY NATIVE WORKER IMAGE SET FIXTURE GENERATOR.
 * Used exclusively by verify-shell-native-worker-image-set.ps1 in GitHub Actions.
 *
 * ARCHITECTURAL TRUST & EXECUTION BOUNDARIES:
 * 1. This is a TEST FIXTURE GENERATOR, NOT a production release tool or launcher.
 * 2. Process identity is NOT code, image, or payload authentication.
 * 3. CanApply and CanLaunch are unconditionally false across all results.
 * 4. Ephemeral RSA key pair is generated strictly in memory and destroyed at exit.
 *    The private key is NEVER written to disk, logged, or exported in fixture JSON.
 * 5. Prepares a real copied PHP directory structure and compiled PHAR under a freshly
 *    generated private nonce payload root with exact physical file sizes and SHA-256
 *    hashes matching the signed source inventory.
 * 6. Runs exclusively in GitHub Actions under CLI on 64-bit Windows.
 */

// Section 1: Strict Execution Environment Guards (Fail early, independent checks)
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "GUARD: CLI SAPI required.\n");
    exit(1);
}
if (getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "GUARD: GITHUB_ACTIONS=true required.\n");
    exit(1);
}
if (PHP_OS_FAMILY !== 'Windows') {
    fwrite(STDERR, "GUARD: Windows OS required.\n");
    exit(1);
}
if (getenv('RUNNER_OS') !== 'Windows') {
    fwrite(STDERR, "GUARD: RUNNER_OS=Windows required.\n");
    exit(1);
}
if (PHP_INT_SIZE !== 8) {
    fwrite(STDERR, "GUARD: 64-bit PHP runtime required.\n");
    exit(1);
}

// Section 2: Autoloading (Vendor or explicit SPL fallback for App\Services)
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

use App\Services\ManifestSignatureService;
use App\Services\ProductInventoryService;
use App\Services\ShellDeltaManifestService;
use App\Services\ShellDeltaWorkerFilePlanService;
use App\Services\UpdateChannelPolicy;
use App\Services\UpdateDataCompatibilityService;

// Section 3: CLI Argument Parsing
$options = getopt('', [
    'output:',
    'backend-phar:',
    'php-dir:',
]);

$outputPath = $options['output'] ?? null;
$backendPharPath = $options['backend-phar'] ?? null;
$phpDirPath = $options['php-dir'] ?? null;

if (!is_string($outputPath) || trim($outputPath) === '') {
    fwrite(STDERR, "ERROR: --output <filepath> is required.\n");
    exit(1);
}
if (!is_string($backendPharPath) || trim($backendPharPath) === '') {
    fwrite(STDERR, "ERROR: --backend-phar <filepath> is required.\n");
    exit(1);
}
if (!is_string($phpDirPath) || trim($phpDirPath) === '') {
    fwrite(STDERR, "ERROR: --php-dir <directory> is required.\n");
    exit(1);
}

// Helper: Assert path safety
$assertSafePath = static function (string $p, string $label): string {
    if (preg_match('/[\x00-\x1f\x7f]/', $p)) {
        fwrite(STDERR, "ERROR: {$label} contains control characters: '{$p}'\n");
        exit(1);
    }
    if (str_starts_with($p, '\\\\') || str_starts_with($p, '//')) {
        fwrite(STDERR, "ERROR: {$label} cannot be a UNC path: '{$p}'\n");
        exit(1);
    }
    if (str_contains($p, '..')) {
        fwrite(STDERR, "ERROR: {$label} contains traversal segments: '{$p}'\n");
        exit(1);
    }
    if (str_contains($p, '~')) {
        fwrite(STDERR, "ERROR: {$label} contains 8.3 alias tilde: '{$p}'\n");
        exit(1);
    }
    return $p;
};

$outputPath = $assertSafePath($outputPath, '--output');
$backendPharPath = $assertSafePath($backendPharPath, '--backend-phar');
$phpDirPath = $assertSafePath($phpDirPath, '--php-dir');

if (!is_file($backendPharPath) || is_link($backendPharPath)) {
    fwrite(STDERR, "ERROR: --backend-phar must be an existing regular file: '{$backendPharPath}'\n");
    exit(1);
}
if (!is_dir($phpDirPath) || is_link($phpDirPath)) {
    fwrite(STDERR, "ERROR: --php-dir must be an existing directory: '{$phpDirPath}'\n");
    exit(1);
}

$phpExeSource = rtrim($phpDirPath, '\\/') . DIRECTORY_SEPARATOR . 'php.exe';
if (!is_file($phpExeSource) || is_link($phpExeSource)) {
    fwrite(STDERR, "ERROR: php.exe not found in --php-dir: '{$phpExeSource}'\n");
    exit(1);
}
$phpExtDirSource = rtrim($phpDirPath, '\\/') . DIRECTORY_SEPARATOR . 'ext';
if (!is_dir($phpExtDirSource) || is_link($phpExtDirSource)) {
    fwrite(STDERR, "ERROR: ext directory not found in --php-dir: '{$phpExtDirSource}'\n");
    exit(1);
}

// Section 4: RUNNER_TEMP Fresh Nonce LAB Generation
$runnerTemp = getenv('RUNNER_TEMP');
if (!is_string($runnerTemp) || trim($runnerTemp) === '') {
    fwrite(STDERR, "ERROR: RUNNER_TEMP environment variable is required.\n");
    exit(1);
}
$runnerTemp = $assertSafePath($runnerTemp, 'RUNNER_TEMP');
$canonicalRunnerTemp = realpath($runnerTemp);
if ($canonicalRunnerTemp === false || !is_dir($canonicalRunnerTemp)) {
    fwrite(STDERR, "ERROR: RUNNER_TEMP directory does not exist: '{$runnerTemp}'\n");
    exit(1);
}

// Generate fresh unique nonce and payload directory
$nonce = bin2hex(random_bytes(16));
$payloadRootPath = $canonicalRunnerTemp . DIRECTORY_SEPARATOR . 'snw-payload-' . $nonce;
if (file_exists($payloadRootPath)) {
    fwrite(STDERR, "ERROR: Refusing to reuse existing payload directory: '{$payloadRootPath}'\n");
    exit(1);
}
if (!mkdir($payloadRootPath, 0755, false)) {
    fwrite(STDERR, "ERROR: Failed to create fresh payload root: '{$payloadRootPath}'\n");
    exit(1);
}
$canonicalPayloadRoot = realpath($payloadRootPath);
if ($canonicalPayloadRoot === false) {
    fwrite(STDERR, "ERROR: realpath failed on freshly created payload root: '{$payloadRootPath}'\n");
    exit(1);
}

// Write owner marker
$ownerMarkerName = '.is-pay-marker';
$ownerMarkerPath = $canonicalPayloadRoot . DIRECTORY_SEPARATOR . $ownerMarkerName;
if (file_put_contents($ownerMarkerPath, $nonce) === false) {
    fwrite(STDERR, "ERROR: Failed to write owner marker: '{$ownerMarkerPath}'\n");
    exit(1);
}

// Section 5: Ephemeral RSA 2048 Key Generation (Strictly In-Memory)
$ephemeralKey = null;
$rawModulus = '';
$rawExponent = '';

do {
    if ($ephemeralKey !== null) {
        unset($ephemeralKey);
    }
    $ephemeralKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($ephemeralKey === false) {
        fwrite(STDERR, "ERROR: Failed to generate ephemeral RSA 2048 key.\n");
        exit(1);
    }
    $details = openssl_pkey_get_details($ephemeralKey);
    if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
        fwrite(STDERR, "ERROR: Failed to extract RSA modulus and exponent.\n");
        exit(1);
    }
    $rawModulus = $details['rsa']['n'];
    $rawExponent = $details['rsa']['e'];
} while (
    strlen($rawModulus) !== 256 ||
    (ord($rawModulus[0]) & 0x80) === 0 ||
    (ord($rawModulus[255]) & 1) === 0
);

$keyId = 'ephemeral_gha_fixture_trusted_key_not_production_trust_bootstrap';

// Export PEM public key for certs/update_public_key.pem member
$ephemeralPubKeyPem = $details['key'];
if (!is_string($ephemeralPubKeyPem) || !str_contains($ephemeralPubKeyPem, 'BEGIN PUBLIC KEY')) {
    fwrite(STDERR, "ERROR: Failed to export ephemeral public key PEM.\n");
    exit(1);
}

// Helper: Pure recursive canonical sort for associative arrays
function recursive_canonical_ksort(array &$arr): void
{
    ksort($arr, SORT_STRING);
    foreach ($arr as &$value) {
        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                recursive_canonical_ksort($value);
            }
        }
    }
    unset($value);
}

function canonical_wire_json_encode(array $data): string
{
    $clone = $data;
    recursive_canonical_ksort($clone);
    return json_encode($clone, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

// Signing helpers
$signRaw = static function (string $rawJson, $pkey): string {
    $sigBytes = '';
    if (!openssl_sign($rawJson, $sigBytes, $pkey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('openssl_sign failed.');
    }
    return base64_encode($sigBytes);
};

$encodeAndSign = static function (array $descriptor, $pkey) use ($signRaw): array {
    $raw = json_encode($descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $sig = $signRaw($raw, $pkey);
    $sha = hash('sha256', $raw);
    return [$raw, $sig, $sha];
};

// Section 6: Construct Copied Real PHP Tree and Private Payload Root
$dirsToCreate = [
    $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources',
    $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'portable',
    $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'portable' . DIRECTORY_SEPARATOR . 'php',
    $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'portable' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'ext',
    $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'app.asar.unpacked',
    $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'app.asar.unpacked' . DIRECTORY_SEPARATOR . 'backend',
    $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'app.asar.unpacked' . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'certs',
];
foreach ($dirsToCreate as $d) {
    if (!is_dir($d) && !mkdir($d, 0755, true)) {
        fwrite(STDERR, "ERROR: Failed to create directory: '{$d}'\n");
        exit(1);
    }
}

// 1. Copy php.exe
$targetPhpExe = $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources\portable\php\php.exe';
if (!copy($phpExeSource, $targetPhpExe)) {
    fwrite(STDERR, "ERROR: Failed to copy php.exe to payload root.\n");
    exit(1);
}

// 2. Copy PHP core DLL (php8.dll or php8ts.dll) and supporting runtime DLLs
$phpSourceFiles = scandir($phpDirPath);
if ($phpSourceFiles === false) {
    fwrite(STDERR, "ERROR: scandir failed on --php-dir: '{$phpDirPath}'\n");
    exit(1);
}

$copiedPhpDlls = [];
foreach ($phpSourceFiles as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $srcFile = rtrim($phpDirPath, '\\/') . DIRECTORY_SEPARATOR . $entry;
    if (is_file($srcFile) && !is_link($srcFile)) {
        $lower = strtolower($entry);
        if (str_ends_with($lower, '.dll')) {
            $destName = ($lower === 'php8.dll' || $lower === 'php8ts.dll') ? $lower : $entry;
            $destFile = $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources\portable\php\\' . $destName;
            if (!copy($srcFile, $destFile)) {
                fwrite(STDERR, "ERROR: Failed to copy DLL '{$srcFile}' to '{$destFile}'.\n");
                exit(1);
            }
            $copiedPhpDlls[] = 'resources/portable/php/' . $destName;
        }
    }
}

// Verify at least php8.dll or php8ts.dll is present
$hasCoreDll = false;
foreach ($copiedPhpDlls as $rel) {
    if ($rel === 'resources/portable/php/php8.dll' || $rel === 'resources/portable/php/php8ts.dll') {
        $hasCoreDll = true;
        break;
    }
}
if (!$hasCoreDll) {
    fwrite(STDERR, "ERROR: Neither php8.dll nor php8ts.dll was found in the PHP runtime.\n");
    exit(1);
}

// 3. Copy required extensions from ext directory
$requiredExts = [
    'php_openssl.dll',
    'php_zip.dll',
    'php_mbstring.dll',
];
foreach ($requiredExts as $extName) {
    $srcExt = rtrim($phpExtDirSource, '\\/') . DIRECTORY_SEPARATOR . $extName;
    if (!is_file($srcExt)) {
        $altExt = rtrim($phpExtDirSource, '\\/') . DIRECTORY_SEPARATOR . substr($extName, 4);
        if (is_file($altExt)) {
            $srcExt = $altExt;
        }
    }
    if (!is_file($srcExt)) {
        fwrite(STDERR, "ERROR: Required PHP extension '{$extName}' not found in '{$phpExtDirSource}'.\n");
        exit(1);
    }
    $destExt = $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources\portable\php\ext\\' . $extName;
    if (!copy($srcExt, $destExt)) {
        fwrite(STDERR, "ERROR: Failed to copy extension '{$extName}' to '{$destExt}'.\n");
        exit(1);
    }
}

// 4. Copy backend.phar and inject ephemeral public key with readback verification
$pharReadOnly = ini_get('phar.readonly');
if ($pharReadOnly === '1') {
    fwrite(STDERR, "ERROR: phar.readonly must be 0 for fixture generation.\n");
    exit(1);
}

$targetBackendPhar = $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources\app.asar.unpacked\backend\backend.phar';
if (!copy($backendPharPath, $targetBackendPhar)) {
    fwrite(STDERR, "ERROR: Failed to copy backend.phar to payload root.\n");
    exit(1);
}

try {
    $phar = new Phar($targetBackendPhar);
    $phar->startBuffering();
    $phar->addFromString('certs/update_public_key.pem', $ephemeralPubKeyPem);
    $phar->setSignatureAlgorithm(Phar::SHA512);
    $phar->stopBuffering();
    unset($phar);

    // Read back member and verify exact match
    $readPhar = new Phar($targetBackendPhar);
    if (!isset($readPhar['certs/update_public_key.pem'])) {
        throw new RuntimeException("backend.phar missing certs/update_public_key.pem after patch.");
    }
    $readPub = $readPhar['certs/update_public_key.pem']->getContent();
    if ($readPub !== $ephemeralPubKeyPem) {
        throw new RuntimeException("backend.phar certs/update_public_key.pem content mismatch after patch.");
    }
    $sig = $readPhar->getSignature();
    if ($sig['hash_type'] !== 'SHA-512') {
        throw new RuntimeException("backend.phar signature algorithm is not SHA-512: " . $sig['hash_type']);
    }
    unset($readPhar);
} catch (Throwable $pe) {
    fwrite(STDERR, "ERROR: Failed to patch and verify backend.phar: " . $pe->getMessage() . "\n");
    exit(1);
}

// 5. Write external update_public_key.pem in payload root
$targetCertFile = $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources\app.asar.unpacked\backend\certs\update_public_key.pem';
if (file_put_contents($targetCertFile, $ephemeralPubKeyPem) === false) {
    fwrite(STDERR, "ERROR: Failed to write update_public_key.pem to payload root.\n");
    exit(1);
}

// 6. Optional cacert.pem
$repoCacert = __DIR__ . '/../backend/certs/cacert.pem';
$targetCacert = $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources\app.asar.unpacked\backend\certs\cacert.pem';
if (is_file($repoCacert)) {
    if (!copy($repoCacert, $targetCacert)) {
        fwrite(STDERR, "ERROR: Failed to copy cacert.pem to payload root.\n");
        exit(1);
    }
} else {
    if (file_put_contents($targetCacert, "-----BEGIN CERTIFICATE-----\nMOCK_CACERT\n-----END CERTIFICATE-----\n") === false) {
        fwrite(STDERR, "ERROR: Failed to write mock cacert.pem to payload root.\n");
        exit(1);
    }
}

// 7. Write mandatory physical shell & product files
$targetExe = $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'POS Desktop.exe';
$targetAsar = $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources\app.asar';
$targetVersionJson = $canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources\app.asar.unpacked\version.json';

if (!is_file($targetExe)) {
    $exeStub = "MZ" . str_repeat("\x90", 510);
    if (file_put_contents($targetExe, $exeStub) === false) {
        fwrite(STDERR, "ERROR: Failed to write POS Desktop.exe stub.\n");
        exit(1);
    }
}
if (!is_file($targetAsar)) {
    $asarStub = "ASAR" . str_repeat("\x00", 1020);
    if (file_put_contents($targetAsar, $asarStub) === false) {
        fwrite(STDERR, "ERROR: Failed to write app.asar stub.\n");
        exit(1);
    }
}
if (file_put_contents($targetVersionJson, json_encode(['version' => '1.0.0'], JSON_UNESCAPED_SLASHES) . "\n") === false) {
    fwrite(STDERR, "ERROR: Failed to write version.json stub.\n");
    exit(1);
}

// Section 7: Compute Exact Physical Hashes and Construct Source Inventory
$hashFile = static function (string $absPath): array {
    $size = filesize($absPath);
    if ($size === false) {
        throw new RuntimeException("filesize failed on: {$absPath}");
    }
    $sha256 = hash_file('sha256', $absPath);
    if ($sha256 === false) {
        throw new RuntimeException("hash_file failed on: {$absPath}");
    }
    return ['size' => (int)$size, 'sha256' => strtolower($sha256)];
};

$sourceFiles = [];

// Hash shell files
$sourceFiles['POS Desktop.exe'] = $hashFile($targetExe);
$sourceFiles['resources/app.asar'] = $hashFile($targetAsar);
$sourceFiles['resources/app.asar.unpacked/version.json'] = $hashFile($targetVersionJson);

// Hash required worker image files
$sourceFiles['resources/portable/php/php.exe'] = $hashFile($targetPhpExe);
$sourceFiles['resources/portable/php/ext/php_openssl.dll'] = $hashFile($canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources\portable\php\ext\php_openssl.dll');
$sourceFiles['resources/portable/php/ext/php_zip.dll'] = $hashFile($canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources\portable\php\ext\php_zip.dll');
$sourceFiles['resources/portable/php/ext/php_mbstring.dll'] = $hashFile($canonicalPayloadRoot . DIRECTORY_SEPARATOR . 'resources\portable\php\ext\php_mbstring.dll');
$sourceFiles['resources/app.asar.unpacked/backend/backend.phar'] = $hashFile($targetBackendPhar);
$sourceFiles['resources/app.asar.unpacked/backend/certs/update_public_key.pem'] = $hashFile($targetCertFile);
$sourceFiles['resources/app.asar.unpacked/backend/certs/cacert.pem'] = $hashFile($targetCacert);

// Hash all copied PHP DLLs
foreach ($copiedPhpDlls as $relDll) {
    $absDll = $canonicalPayloadRoot . DIRECTORY_SEPARATOR . str_replace('/', '\\', $relDll);
    if (is_file($absDll)) {
        $sourceFiles[$relDll] = $hashFile($absDll);
    }
}

ksort($sourceFiles, SORT_STRING);

$shellFiles = [
    'POS Desktop.exe' => $sourceFiles['POS Desktop.exe'],
    'resources/app.asar' => $sourceFiles['resources/app.asar'],
];
$canonicalShellDigest = ProductInventoryService::computeCanonicalShellDigest($shellFiles);

// Distinct app version (1.0.0) and native version (0.0.13)
$sourceInventory = [
    'arch' => 'x64',
    'files' => $sourceFiles,
    'format' => 1,
    'native_shell' => [
        'files' => $shellFiles,
        'sha256' => $canonicalShellDigest,
    ],
    'native_version' => '0.0.13',
    'platform' => 'win32',
    'version' => '1.0.0',
];
recursive_canonical_ksort($sourceInventory);
$sourceInventorySha256 = ShellDeltaManifestService::canonicalInventoryDigest($sourceInventory);

// Section 8: Construct Target Inventory (Simulated 1.0.1 / native 0.0.14 target)
$targetFiles = $sourceFiles;
$targetVersionJsonData = ['version' => '1.0.1'];
$targetVersionJsonRaw = json_encode($targetVersionJsonData, JSON_UNESCAPED_SLASHES) . "\n";
$targetFiles['resources/app.asar.unpacked/version.json'] = [
    'sha256' => hash('sha256', $targetVersionJsonRaw),
    'size' => strlen($targetVersionJsonRaw),
];
ksort($targetFiles, SORT_STRING);

$targetInventory = [
    'arch' => 'x64',
    'files' => $targetFiles,
    'format' => 1,
    'native_shell' => [
        'files' => $shellFiles,
        'sha256' => $canonicalShellDigest,
    ],
    'native_version' => '0.0.14',
    'platform' => 'win32',
    'version' => '1.0.1',
];
recursive_canonical_ksort($targetInventory);
$targetInventorySha256 = ShellDeltaManifestService::canonicalInventoryDigest($targetInventory);

// Baseline Data Contract (Format 1) - Sorted keys
$dataContract = [
    'format' => 1,
    'ledger_sha256' => hash('sha256', 'mock_mariadb_ledger_normalized_v1'),
    'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
    'schema_sha256' => hash('sha256', 'mock_mariadb_schema_normalized_v1'),
];
recursive_canonical_ksort($dataContract);

// Section 9: Build Baseline Format 2 Descriptor
$diffFiles = [
    [
        'path' => 'resources/app.asar.unpacked/version.json',
        'sha256' => $targetFiles['resources/app.asar.unpacked/version.json']['sha256'],
        'size' => $targetFiles['resources/app.asar.unpacked/version.json']['size'],
    ],
];

$descBaseline = [
    'arch' => 'x64',
    'channel' => 'stable',
    'data_compatibility' => $dataContract,
    'deleted_files' => [],
    'files' => $diffFiles,
    'format' => 2,
    'from_channel' => 'stable',
    'from_native_version' => '0.0.13',
    'from_version' => '1.0.0',
    'migrations' => [],
    'minimum_updater_protocol' => 2,
    'native_version' => '0.0.14',
    'operation' => 'shell_delta',
    'package' => [
        'asset' => 'shell-delta.zip',
        'sha256' => hash('sha256', 'mock_synthetic_package_zip_content_v101'),
        'size' => 4096,
        'uncompressed_size' => $targetFiles['resources/app.asar.unpacked/version.json']['size'],
    ],
    'platform' => 'win32',
    'prerelease' => false,
    'purpose' => 'regular',
    'source_data_compatibility' => $dataContract,
    'source_inventory' => $sourceInventory,
    'source_inventory_sha256' => $sourceInventorySha256,
    'source_native_shell' => $sourceInventory['native_shell'],
    'target_inventory' => $targetInventory,
    'target_inventory_sha256' => $targetInventorySha256,
    'target_native_shell' => $targetInventory['native_shell'],
    'version' => '1.0.1',
];
recursive_canonical_ksort($descBaseline);

list($rawBaseline, $sigBaseline, $shaBaseline) = $encodeAndSign($descBaseline, $ephemeralKey);

// Section 10: Build Canonical RPC Contexts and Request Wire JSON
$sourceContext = [
    'channel' => 'stable',
    'data_compatibility' => $dataContract,
    'inventory_sha256' => $sourceInventorySha256,
    'native_shell_sha256' => $canonicalShellDigest,
    'native_version' => '0.0.13',
    'version' => '1.0.0',
];
recursive_canonical_ksort($sourceContext);

$targetContext = [
    'channel' => 'stable',
    'data_compatibility' => $dataContract,
    'inventory_sha256' => $targetInventorySha256,
    'native_shell_sha256' => $canonicalShellDigest,
    'native_version' => '0.0.14',
    'version' => '1.0.1',
];
recursive_canonical_ksort($targetContext);

// Execute real production PHP pipeline validation before saving fixture
try {
    $sigService = new ManifestSignatureService(null, [$targetCertFile], false, false);
    $manifestService = new ShellDeltaManifestService($sigService);
    $planService = new ShellDeltaWorkerFilePlanService($manifestService);
    $planResult = $planService->build($rawBaseline, $sigBaseline, $shaBaseline, $sourceContext, $targetContext);

    if ($planResult['can_apply'] !== false) {
        fwrite(STDERR, "ERROR: Pre-flight planService->build returned can_apply !== false.\n");
        exit(1);
    }
    $expectedPlanJson = $planResult['plan_json'];
    $expectedPlanSha256 = $planResult['plan_sha256'];
} catch (Throwable $pe) {
    fwrite(STDERR, "ERROR: Pre-flight FilePlanService verification failed: " . $pe->getMessage() . "\n");
    exit(1);
}

$requestWireObj = [
    'expected_manifest_sha256' => $shaBaseline,
    'raw_manifest_base64' => base64_encode($rawBaseline),
    'signature' => $sigBaseline,
    'source_context' => $sourceContext,
    'target_context' => $targetContext,
];
$requestWireJson = canonical_wire_json_encode($requestWireObj);

// Section 11: Assemble Cases (Positive Baseline + Negative Variants with Canonical Wire Encoding)
$cases = [];

// Case 1: Positive Baseline
$cases[] = [
    'description' => 'Valid signed Format 2 delta transition matching real copied PHP and PHAR',
    'expected_can_apply' => false,
    'expected_manifest_sha256' => $shaBaseline,
    'id' => 'positive_stable_transition',
    'is_negative' => false,
    'raw_manifest_base64' => base64_encode($rawBaseline),
    'request_wire_json' => $requestWireJson,
    'selected_files_count' => count($sourceFiles),
    'signature_base64' => $sigBaseline,
    'source_context' => $sourceContext,
    'target_context' => $targetContext,
];

// Case 2: Negative Signature Tamper (Canonical Wire Format)
$tamperedSigBytes = base64_decode($sigBaseline);
$tamperedSigBytes[10] = chr(ord($tamperedSigBytes[10]) ^ 0xFF);
$tamperedSigBase64 = base64_encode($tamperedSigBytes);
$tamperedSigWire = canonical_wire_json_encode([
    'expected_manifest_sha256' => $shaBaseline,
    'raw_manifest_base64' => base64_encode($rawBaseline),
    'signature' => $tamperedSigBase64,
    'source_context' => $sourceContext,
    'target_context' => $targetContext,
]);
$cases[] = [
    'description' => 'Cryptographic verification fails due to tampered signature bytes',
    'expected_can_apply' => false,
    'expected_error_token' => 'signature',
    'expected_manifest_sha256' => $shaBaseline,
    'id' => 'negative_tampered_signature',
    'is_negative' => true,
    'raw_manifest_base64' => base64_encode($rawBaseline),
    'request_wire_json' => $tamperedSigWire,
    'signature_base64' => $tamperedSigBase64,
    'source_context' => $sourceContext,
    'target_context' => $targetContext,
];

// Case 3: Negative Manifest SHA-256 Pin Mismatch (Canonical Wire Format)
$mismatchedSha = hash('sha256', 'mismatched_manifest_digest_control_pin');
$mismatchedShaWire = canonical_wire_json_encode([
    'expected_manifest_sha256' => $mismatchedSha,
    'raw_manifest_base64' => base64_encode($rawBaseline),
    'signature' => $sigBaseline,
    'source_context' => $sourceContext,
    'target_context' => $targetContext,
]);
$cases[] = [
    'description' => 'Host expected_manifest_sha256 pin does not match actual raw manifest hash',
    'expected_can_apply' => false,
    'expected_error_token' => 'caller pin',
    'expected_manifest_sha256' => $mismatchedSha,
    'id' => 'negative_manifest_sha_mismatch',
    'is_negative' => true,
    'raw_manifest_base64' => base64_encode($rawBaseline),
    'request_wire_json' => $mismatchedShaWire,
    'signature_base64' => $sigBaseline,
    'source_context' => $sourceContext,
    'target_context' => $targetContext,
];

// Case 4: Negative Source Context Channel Mismatch (Canonical Wire Format)
$badContextChannel = $sourceContext;
$badContextChannel['channel'] = 'beta';
$badContextChannel['version'] = '1.0.0-beta.1';
recursive_canonical_ksort($badContextChannel);
$badChannelWire = canonical_wire_json_encode([
    'expected_manifest_sha256' => $shaBaseline,
    'raw_manifest_base64' => base64_encode($rawBaseline),
    'signature' => $sigBaseline,
    'source_context' => $badContextChannel,
    'target_context' => $targetContext,
]);
$cases[] = [
    'description' => 'Context channel does not match signed descriptor channel',
    'expected_can_apply' => false,
    'expected_error_token' => 'channel',
    'expected_manifest_sha256' => $shaBaseline,
    'id' => 'negative_context_channel_mismatch',
    'is_negative' => true,
    'raw_manifest_base64' => base64_encode($rawBaseline),
    'request_wire_json' => $badChannelWire,
    'signature_base64' => $sigBaseline,
    'source_context' => $badContextChannel,
    'target_context' => $targetContext,
];

// Case 5: Negative Request Wire Extra Key (Non-canonical Wire Format)
$badWireExtra = $requestWireObj;
$badWireExtra['unexpected_ambient_flag'] = true;
ksort($badWireExtra, SORT_STRING);
$cases[] = [
    'description' => 'Request wire JSON contains forbidden unexpected key',
    'expected_can_apply' => false,
    'expected_error_token' => 'exact 5 keys',
    'id' => 'negative_wire_extra_key',
    'is_negative' => true,
    'request_wire_json' => json_encode($badWireExtra, JSON_UNESCAPED_SLASHES),
];

// A duplicate must fail even when JSON decoding preserves the expected values.
$duplicateRequestWire = '{"expected_manifest_sha256":"' . $shaBaseline . '",' . substr($requestWireJson, 1);
$cases[] = [
    'description' => 'Request wire JSON repeats the manifest SHA-256 key',
    'expected_can_apply' => false,
    'expected_error_token' => 'duplicate keys',
    'id' => 'negative_wire_duplicate_key',
    'is_negative' => true,
    'request_wire_json' => $duplicateRequestWire,
];

// Section 12: Negative Map Descriptors (For testing NativeBrokerWorkerImageMap.FromBoundResult)
$negativeMapCases = [];

// NegMap 1: Missing php.exe
$descNoPhp = $descBaseline;
unset($descNoPhp['source_inventory']['files']['resources/portable/php/php.exe']);
recursive_canonical_ksort($descNoPhp);
list($rawNoPhp, $sigNoPhp, $shaNoPhp) = $encodeAndSign($descNoPhp, $ephemeralKey);
$negativeMapCases[] = [
    'description' => 'Signed source inventory missing required php.exe',
    'expected_exception' => 'FormatException',
    'expected_message_substring' => 'resources/portable/php/php.exe',
    'id' => 'neg_map_missing_php_exe',
    'raw_manifest_base64' => base64_encode($rawNoPhp),
    'signature_base64' => $sigNoPhp,
    'expected_manifest_sha256' => $shaNoPhp,
];

// NegMap 2: Missing backend.phar
$descNoPhar = $descBaseline;
unset($descNoPhar['source_inventory']['files']['resources/app.asar.unpacked/backend/backend.phar']);
recursive_canonical_ksort($descNoPhar);
list($rawNoPhar, $sigNoPhar, $shaNoPhar) = $encodeAndSign($descNoPhar, $ephemeralKey);
$negativeMapCases[] = [
    'description' => 'Signed source inventory missing required backend.phar',
    'expected_exception' => 'FormatException',
    'expected_message_substring' => 'backend.phar',
    'id' => 'neg_map_missing_backend_phar',
    'raw_manifest_base64' => base64_encode($rawNoPhar),
    'signature_base64' => $sigNoPhar,
    'expected_manifest_sha256' => $shaNoPhar,
];

// NegMap 3: Missing php_openssl.dll
$descNoOpenssl = $descBaseline;
unset($descNoOpenssl['source_inventory']['files']['resources/portable/php/ext/php_openssl.dll']);
recursive_canonical_ksort($descNoOpenssl);
list($rawNoOpenssl, $sigNoOpenssl, $shaNoOpenssl) = $encodeAndSign($descNoOpenssl, $ephemeralKey);
$negativeMapCases[] = [
    'description' => 'Signed source inventory missing required php_openssl.dll',
    'expected_exception' => 'FormatException',
    'expected_message_substring' => 'php_openssl.dll',
    'id' => 'neg_map_missing_php_openssl',
    'raw_manifest_base64' => base64_encode($rawNoOpenssl),
    'signature_base64' => $sigNoOpenssl,
    'expected_manifest_sha256' => $shaNoOpenssl,
];

// NegMap 4: Missing update_public_key.pem
$descNoKey = $descBaseline;
unset($descNoKey['source_inventory']['files']['resources/app.asar.unpacked/backend/certs/update_public_key.pem']);
recursive_canonical_ksort($descNoKey);
list($rawNoKey, $sigNoKey, $shaNoKey) = $encodeAndSign($descNoKey, $ephemeralKey);
$negativeMapCases[] = [
    'description' => 'Signed source inventory missing required update_public_key.pem',
    'expected_exception' => 'FormatException',
    'expected_message_substring' => 'update_public_key.pem',
    'id' => 'neg_map_missing_update_public_key',
    'raw_manifest_base64' => base64_encode($rawNoKey),
    'signature_base64' => $sigNoKey,
    'expected_manifest_sha256' => $shaNoKey,
];

// NegMap 5: Zero-size required file
$descZeroSize = $descBaseline;
$descZeroSize['source_inventory']['files']['resources/portable/php/php.exe']['size'] = 0;
recursive_canonical_ksort($descZeroSize);
list($rawZeroSize, $sigZeroSize, $shaZeroSize) = $encodeAndSign($descZeroSize, $ephemeralKey);
$negativeMapCases[] = [
    'description' => 'Required payload file has invalid size of 0',
    'expected_exception' => 'FormatException',
    'expected_message_substring' => 'must not be empty',
    'id' => 'neg_map_zero_size_file',
    'raw_manifest_base64' => base64_encode($rawZeroSize),
    'signature_base64' => $sigZeroSize,
    'expected_manifest_sha256' => $shaZeroSize,
];

// NegMap 6: Ancestor directory collision (file named resources/portable/php)
$descAncestorCollision = $descBaseline;
$descAncestorCollision['source_inventory']['files']['resources/portable/php'] = [
    'sha256' => hash('sha256', 'dummy_collision'),
    'size' => 128,
];
recursive_canonical_ksort($descAncestorCollision);
list($rawAncestor, $sigAncestor, $shaAncestor) = $encodeAndSign($descAncestorCollision, $ephemeralKey);
$negativeMapCases[] = [
    'description' => 'File path collides with an ancestor directory of another selected file',
    'expected_exception' => 'FormatException',
    'expected_message_substring' => 'aliases an ancestor directory',
    'id' => 'neg_map_ancestor_collision',
    'raw_manifest_base64' => base64_encode($rawAncestor),
    'signature_base64' => $sigAncestor,
    'expected_manifest_sha256' => $shaAncestor,
];

// NegMap 7: Oversized single file (> 250 MB)
$descOversized = $descBaseline;
$descOversized['source_inventory']['files']['resources/portable/php/php.exe']['size'] = 262144001;
recursive_canonical_ksort($descOversized);
list($rawOversized, $sigOversized, $shaOversized) = $encodeAndSign($descOversized, $ephemeralKey);
$negativeMapCases[] = [
    'description' => 'Single selected file exceeds 250 MiB limit',
    'expected_exception' => 'FormatException',
    'expected_message_substring' => 'exceeds 250 MiB limit',
    'id' => 'neg_map_oversized_file',
    'raw_manifest_base64' => base64_encode($rawOversized),
    'signature_base64' => $sigOversized,
    'expected_manifest_sha256' => $shaOversized,
];

// Section 13: Assemble Final Fixture Document
$fixtureDoc = [
    'canonical_shell_digest' => $canonicalShellDigest,
    'cases' => $cases,
    'expected_plan_json' => $expectedPlanJson,
    'expected_plan_sha256' => $expectedPlanSha256,
    'negative_map_cases' => $negativeMapCases,
    'nonce' => $nonce,
    'owner_marker' => $ownerMarkerName,
    'payload_root' => $canonicalPayloadRoot,
    'pinned_files' => array_keys($sourceFiles),
    'public_key' => [
        'exponent_base64' => base64_encode($rawExponent),
        'key_id' => $keyId,
        'modulus_base64' => base64_encode($rawModulus),
        'public_key_pem' => $ephemeralPubKeyPem,
    ],
    'source_inventory_sha256' => $sourceInventorySha256,
    'target_inventory_sha256' => $targetInventorySha256,
];
recursive_canonical_ksort($fixtureDoc);

// Section 14: Checked Exclusive Stream Write & Close
$targetDir = dirname($outputPath);
if (!is_dir($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) {
        fwrite(STDERR, "ERROR: Target directory cannot be created: {$targetDir}\n");
        exit(1);
    }
}

$fixtureJson = json_encode($fixtureDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

$fp = @fopen($outputPath, 'xb');
if ($fp === false) {
    fwrite(STDERR, "ERROR: Cannot open output file exclusively (file already exists or permission denied): {$outputPath}\n");
    exit(1);
}

$writePrimary = null;
$closeCleanup = null;
try {
    $length = strlen($fixtureJson);
    $written = 0;
    while ($written < $length) {
        $chunk = substr($fixtureJson, $written);
        $n = fwrite($fp, $chunk);
        if ($n === false || $n === 0) {
            throw new RuntimeException("fwrite failed after writing {$written} of {$length} bytes.");
        }
        $written += $n;
    }
    if (!fflush($fp)) {
        throw new RuntimeException("fflush failed.");
    }
} catch (Throwable $e) {
    $writePrimary = $e;
} finally {
    try {
        if (!fclose($fp)) {
            throw new RuntimeException("fclose returned false.");
        }
    } catch (Throwable $ce) {
        $closeCleanup = $ce;
    }
}

if ($writePrimary !== null && $closeCleanup !== null) {
    fwrite(STDERR, "ERROR: Write failed: " . $writePrimary->getMessage() . " AND close failed: " . $closeCleanup->getMessage() . "\n");
    exit(1);
}
if ($writePrimary !== null) {
    fwrite(STDERR, "ERROR: Write failed: " . $writePrimary->getMessage() . "\n");
    exit(1);
}
if ($closeCleanup !== null) {
    fwrite(STDERR, "ERROR: Close failed: " . $closeCleanup->getMessage() . "\n");
    exit(1);
}

// Destroy private key handle immediately
unset($ephemeralKey);

echo "Successfully wrote worker image set fixture with " . count($cases) . " cases and " . count($negativeMapCases) . " negative map cases to {$outputPath} (" . strlen($fixtureJson) . " bytes).\n";
echo "Private payload root initialized at: {$canonicalPayloadRoot}\n";
exit(0);
