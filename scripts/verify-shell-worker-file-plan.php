<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// PRE-FLIGHT ENVIRONMENT GUARDS
// Must execute BEFORE any autoload, filesystem operations, crypto, or subprocesses.
// -----------------------------------------------------------------------------

// Guard 1: Must execute exclusively via PHP CLI
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Verification suite runs strictly via PHP CLI.\n");
    exit(1);
}

// Guard 2: Must execute exclusively in GitHub Actions
if (getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "Verification suite runs strictly in GitHub Actions environment (GITHUB_ACTIONS=true).\n");
    exit(1);
}

// Guard 3: Must execute on Windows runner
if (getenv('RUNNER_OS') !== 'Windows') {
    fwrite(STDERR, "Verification suite runs strictly on Windows runner (RUNNER_OS=Windows).\n");
    exit(1);
}

// Guard 4: Must execute on Windows platform
if (PHP_OS_FAMILY !== 'Windows' && stripos(PHP_OS, 'WIN') !== 0) {
    fwrite(STDERR, "Verification suite runs strictly on Windows platforms.\n");
    exit(1);
}

// Autoloading
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

// -----------------------------------------------------------------------------
// ASSERTION UTILITIES
// -----------------------------------------------------------------------------

$totalAssertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $totalAssertions;
    $totalAssertions++;
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
}

function assertFalse(bool $condition, string $message): void
{
    global $totalAssertions;
    $totalAssertions++;
    if ($condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
}

function assertEqual(mixed $actual, mixed $expected, string $message): void
{
    global $totalAssertions;
    $totalAssertions++;
    if ($actual !== $expected) {
        $actStr = is_scalar($actual) ? (string) $actual : json_encode($actual);
        $expStr = is_scalar($expected) ? (string) $expected : json_encode($expected);
        throw new RuntimeException("Assertion failed: {$message} (expected: {$expStr}, got: {$actStr})");
    }
}

function assertThrows(callable $fn, string $expectedMessageSubstr, string $message): void
{
    global $totalAssertions;
    $totalAssertions++;
    if ($expectedMessageSubstr === '') {
        throw new RuntimeException("Test setup error: assertThrows requires a non-empty expectedMessageSubstr for '{$message}'.");
    }
    try {
        $fn();
    } catch (RuntimeException $e) {
        $eMsg = strtolower($e->getMessage());
        $expectedSub = strtolower($expectedMessageSubstr);
        if (!str_contains($eMsg, $expectedSub)) {
            throw new RuntimeException("Assertion failed: {$message}. Expected message substring '{$expectedMessageSubstr}', got: '{$e->getMessage()}'");
        }
        return;
    } catch (Throwable $e) {
        throw new RuntimeException("Assertion failed: {$message}. Expected RuntimeException, but caught " . get_class($e) . ": '{$e->getMessage()}'");
    }
    throw new RuntimeException("Assertion failed: {$message}. Expected RuntimeException was not thrown.");
}

// -----------------------------------------------------------------------------
// IN-MEMORY CRYPTOGRAPHIC & INVENTORY FIXTURE BUILDERS
// -----------------------------------------------------------------------------

function signManifest(array $descriptor, $privateKey): array
{
    $raw = json_encode($descriptor, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $binarySig = '';
    if (!openssl_sign($raw, $binarySig, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException("Failed to generate RSA signature.");
    }
    $signature = base64_encode($binarySig);
    return [$raw, $signature];
}

function createFakeInventory(
    string $appVersion,
    string $nativeVersion,
    array $extraFiles = [],
    ?string $posExeOverrideHash = null,
    ?string $asarOverrideHash = null
): array {
    $posExeHash = $posExeOverrideHash ?? hash('sha256', 'POS_DESKTOP_EXE_' . $nativeVersion);
    $appAsarHash = $asarOverrideHash ?? hash('sha256', 'APP_ASAR_' . $nativeVersion);
    $versionJsonHash = hash('sha256', 'VERSION_JSON_' . $appVersion);

    $shellFiles = [
        'POS Desktop.exe' => [
            'sha256' => $posExeHash,
            'size' => 102400,
        ],
        'resources/app.asar' => [
            'sha256' => $appAsarHash,
            'size' => 204800,
        ],
    ];

    $shellSha256 = ProductInventoryService::computeCanonicalShellDigest($shellFiles);

    $files = [
        'POS Desktop.exe' => [
            'sha256' => $posExeHash,
            'size' => 102400,
        ],
        'resources/app.asar' => [
            'sha256' => $appAsarHash,
            'size' => 204800,
        ],
        'resources/app.asar.unpacked/version.json' => [
            'sha256' => $versionJsonHash,
            'size' => 512,
        ],
    ];

    foreach ($extraFiles as $p => $fmeta) {
        $files[(string) $p] = [
            'sha256' => (string) $fmeta['sha256'],
            'size' => (int) $fmeta['size'],
        ];
    }
    ksort($files, SORT_STRING);

    return [
        'format' => 1,
        'platform' => 'win32',
        'arch' => 'x64',
        'version' => $appVersion,
        'native_version' => $nativeVersion,
        'native_shell' => [
            'files' => $shellFiles,
            'sha256' => $shellSha256,
        ],
        'files' => $files,
    ];
}

function createDescriptor(
    array $srcInv,
    array $tgtInv,
    string $channel,
    array $compatContract,
    string $purpose = 'regular',
    array $migrations = []
): array {
    $srcFiles = $srcInv['files'];
    $tgtFiles = $tgtInv['files'];

    $changedFiles = [];
    $totalUncompressed = 0;
    foreach ($tgtFiles as $p => $entry) {
        if (!isset($srcFiles[$p]) ||
            $srcFiles[$p]['sha256'] !== $entry['sha256'] ||
            $srcFiles[$p]['size'] !== $entry['size']) {
            $changedFiles[] = [
                'path' => (string) $p,
                'sha256' => (string) $entry['sha256'],
                'size' => (int) $entry['size'],
            ];
            $totalUncompressed += $entry['size'];
        }
    }
    usort($changedFiles, static fn($a, $b) => strcmp($a['path'], $b['path']));

    $deletedFiles = array_values(array_diff(array_keys($srcFiles), array_keys($tgtFiles)));
    sort($deletedFiles, SORT_STRING);

    $srcInvSha256 = ShellDeltaManifestService::canonicalInventoryDigest($srcInv);
    $tgtInvSha256 = ShellDeltaManifestService::canonicalInventoryDigest($tgtInv);

    return [
        'format' => 2,
        'operation' => 'shell_delta',
        'minimum_updater_protocol' => 2,
        'platform' => 'win32',
        'arch' => 'x64',
        'purpose' => $purpose,
        'from_version' => $srcInv['version'],
        'from_channel' => $channel,
        'from_native_version' => $srcInv['native_version'],
        'version' => $tgtInv['version'],
        'channel' => $channel,
        'prerelease' => ($channel === 'beta'),
        'native_version' => $tgtInv['native_version'],
        'source_inventory_sha256' => $srcInvSha256,
        'source_inventory' => $srcInv,
        'target_inventory_sha256' => $tgtInvSha256,
        'target_inventory' => $tgtInv,
        'source_native_shell' => $srcInv['native_shell'],
        'target_native_shell' => $tgtInv['native_shell'],
        'files' => $changedFiles,
        'deleted_files' => $deletedFiles,
        'package' => [
            'asset' => 'shell-delta.zip',
            'sha256' => hash('sha256', 'dummy_package_zip_content'),
            'size' => 1024,
            'uncompressed_size' => $totalUncompressed,
        ],
        'data_compatibility' => $compatContract,
        'source_data_compatibility' => $compatContract,
        'migrations' => $migrations,
    ];
}

function createContext(array $inv, string $channel, array $compatContract): array
{
    return [
        'version' => (string) $inv['version'],
        'native_version' => (string) $inv['native_version'],
        'channel' => $channel,
        'inventory_sha256' => ShellDeltaManifestService::canonicalInventoryDigest($inv),
        'native_shell_sha256' => (string) $inv['native_shell']['sha256'],
        'data_compatibility' => $compatContract,
    ];
}

// -----------------------------------------------------------------------------
// MAIN TEST RUNNER
// -----------------------------------------------------------------------------

echo "=== Running Signed Shell Worker File Plan Verification Suite ===\n\n";

// Generate Ephemeral RSA 2048 Key in MEMORY (zero ambient files / logging)
$ephemeralKey = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
if ($ephemeralKey === false) {
    fwrite(STDERR, "Failed to generate ephemeral RSA 2048 key.\n");
    exit(1);
}

$details = openssl_pkey_get_details($ephemeralKey);
if ($details === false || !isset($details['key'])) {
    fwrite(STDERR, "Failed to extract ephemeral public key.\n");
    exit(1);
}
$pubKeyPem = $details['key'];

$defaultDataCompat = [
    'format' => 1,
    'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
    'schema_sha256' => hash('sha256', 'SCHEMA_V1'),
    'ledger_sha256' => hash('sha256', 'LEDGER_V1'),
];

$sigService = new ManifestSignatureService(null, [$pubKeyPem], false, false);
$manifestService = new ShellDeltaManifestService($sigService);
$filePlanService = new ShellDeltaWorkerFilePlanService($manifestService);

try {
    // =========================================================================
    // SUITE 1: Stable and Beta Valid Mixed Action Plans (Create/Replace/Delete/Retain)
    // =========================================================================
    echo "Suite 1: Valid Stable and Beta Plans with Exact Action Projections...";

    // 1A. Stable Channel (App 12.1.0 -> 12.2.0, Native 13.0.0 -> 13.1.0)
    // Shared common POS Desktop.exe (retained)
    $sharedExeHash = hash('sha256', 'SHARED_POS_DESKTOP_EXE');
    $stableExtraSrc = [
        'resources/app/common.dat' => ['sha256' => hash('sha256', 'COMMON'), 'size' => 100],
        'resources/app/code.js' => ['sha256' => hash('sha256', 'CODE_OLD'), 'size' => 500],
        'resources/app/padding.bin' => ['sha256' => hash('sha256', 'PADDING_SHARED'), 'size' => 200],
        'resources/app/old_module.js' => ['sha256' => hash('sha256', 'OLD'), 'size' => 300],
        'resources/portable/mysql/share/mysql_system_tables.sql' => ['sha256' => hash('sha256', 'SQL_STATIC'), 'size' => 400],
    ];
    $stableExtraTgt = [
        'resources/app/common.dat' => ['sha256' => hash('sha256', 'COMMON'), 'size' => 100], // retain
        'resources/app/code.js' => ['sha256' => hash('sha256', 'CODE_NEW'), 'size' => 500],   // replace: different hash, same size
        'resources/app/padding.bin' => ['sha256' => hash('sha256', 'PADDING_SHARED'), 'size' => 250], // replace: SAME hash, DIFFERENT size
        'resources/app/new_module.js' => ['sha256' => hash('sha256', 'NEW'), 'size' => 600], // create
        'resources/portable/mysql/share/mysql_system_tables.sql' => ['sha256' => hash('sha256', 'SQL_STATIC'), 'size' => 400], // retain engine/sql
    ];

    $srcInvStable = createFakeInventory('12.1.0', '13.0.0', $stableExtraSrc, $sharedExeHash);
    $tgtInvStable = createFakeInventory('12.2.0', '13.1.0', $stableExtraTgt, $sharedExeHash);

    $descStable = createDescriptor($srcInvStable, $tgtInvStable, 'stable', $defaultDataCompat);
    [$rawStable, $sigStable] = signManifest($descStable, $ephemeralKey);

    $srcCtxStable = createContext($srcInvStable, 'stable', $defaultDataCompat);
    $tgtCtxStable = createContext($tgtInvStable, 'stable', $defaultDataCompat);
    $rawStableSha256 = hash('sha256', $rawStable);

    $planResultStable = $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $srcCtxStable, $tgtCtxStable);

    // Verify envelope shape: ONLY 3 keys
    assertEqual(array_keys($planResultStable), ['can_apply', 'plan_json', 'plan_sha256'], "Envelope must contain exact 3 keys");
    assertFalse($planResultStable['can_apply'], "can_apply must be false");
    assertEqual($planResultStable['plan_sha256'], hash('sha256', $planResultStable['plan_json']), "plan_sha256 must match plan_json digest");

    $decodedPlan = json_decode($planResultStable['plan_json'], true, 512, JSON_THROW_ON_ERROR);
    assertEqual($decodedPlan['format'], 1, "Plan format must be 1");
    assertEqual($decodedPlan['operation'], 'plan_signed_files', "Plan operation must be plan_signed_files");
    assertFalse($decodedPlan['can_apply'], "Plan body can_apply must be false");
    assertEqual($decodedPlan['manifest_sha256'], $rawStableSha256, "manifest_sha256 must match exact raw manifest SHA");
    assertEqual($decodedPlan['migrations'], [], "migrations must be empty");

    // Verify source and target identity 6 keys
    $expectedIdKeys = ['channel', 'data_compatibility', 'inventory_sha256', 'native_shell_sha256', 'native_version', 'version'];
    $actualSrcIdKeys = array_keys($decodedPlan['source']);
    sort($actualSrcIdKeys);
    assertEqual($actualSrcIdKeys, $expectedIdKeys, "source identity keys must match");
    $actualTgtIdKeys = array_keys($decodedPlan['target']);
    sort($actualTgtIdKeys);
    assertEqual($actualTgtIdKeys, $expectedIdKeys, "target identity keys must match");

    // Verify file tables counts
    assertEqual(count($decodedPlan['source_files']), count($srcInvStable['files']), "source_files count must match source inventory");
    assertEqual(count($decodedPlan['target_files']), count($tgtInvStable['files']), "target_files count must match target inventory");

    // Verify actions list
    $actionsByPath = [];
    foreach ($decodedPlan['actions'] as $act) {
        assertEqual(array_keys($act), ['path', 'kind', 'source', 'target'], "Action row must have exact 4 keys");
        $actionsByPath[$act['path']] = $act;
    }

    // Check specific action rows
    assertEqual($actionsByPath['POS Desktop.exe']['kind'], 'retain', "Unchanged POS Desktop.exe must be retained");
    assertTrue($actionsByPath['POS Desktop.exe']['source'] !== null && $actionsByPath['POS Desktop.exe']['target'] !== null, "Retain has source and target");

    assertEqual($actionsByPath['resources/app/common.dat']['kind'], 'retain', "common.dat must be retain");
    assertEqual($actionsByPath['resources/app/code.js']['kind'], 'replace', "code.js must be replace (different hash)");

    // CRITICAL: Same hash, different size MUST be replace
    assertEqual($actionsByPath['resources/app/padding.bin']['kind'], 'replace', "padding.bin must be replace (same hash different size)");
    assertEqual($actionsByPath['resources/app/padding.bin']['source']['sha256'], $actionsByPath['resources/app/padding.bin']['target']['sha256'], "padding.bin hashes equal");
    assertTrue($actionsByPath['resources/app/padding.bin']['source']['size'] !== $actionsByPath['resources/app/padding.bin']['target']['size'], "padding.bin sizes different");

    assertEqual($actionsByPath['resources/app/new_module.js']['kind'], 'create', "new_module.js must be create");
    assertEqual($actionsByPath['resources/app/new_module.js']['source'], null, "create action source must be null");
    assertTrue($actionsByPath['resources/app/new_module.js']['target'] !== null, "create action target must be present");

    assertEqual($actionsByPath['resources/app/old_module.js']['kind'], 'delete', "old_module.js must be delete");
    assertTrue($actionsByPath['resources/app/old_module.js']['source'] !== null, "delete action source must be present");
    assertEqual($actionsByPath['resources/app/old_module.js']['target'], null, "delete action target must be null");

    assertEqual($actionsByPath['resources/portable/mysql/share/mysql_system_tables.sql']['kind'], 'retain', "mysql_system_tables.sql must be retain");

    // 1B. Beta Channel (Native version advancement not required: native 13.0.0 -> 13.0.0)
    $srcInvBeta = createFakeInventory('12.1.0-beta.1', '13.0.0', ['resources/app/data.bin' => ['sha256' => hash('sha256', 'BETA_1'), 'size' => 100]]);
    $tgtInvBeta = createFakeInventory('12.2.0-beta.2', '13.0.0', ['resources/app/data.bin' => ['sha256' => hash('sha256', 'BETA_2'), 'size' => 100]]);

    $descBeta = createDescriptor($srcInvBeta, $tgtInvBeta, 'beta', $defaultDataCompat);
    [$rawBeta, $sigBeta] = signManifest($descBeta, $ephemeralKey);

    $srcCtxBeta = createContext($srcInvBeta, 'beta', $defaultDataCompat);
    $tgtCtxBeta = createContext($tgtInvBeta, 'beta', $defaultDataCompat);
    $rawBetaSha256 = hash('sha256', $rawBeta);

    $planResultBeta = $filePlanService->build($rawBeta, $sigBeta, $rawBetaSha256, $srcCtxBeta, $tgtCtxBeta);
    assertFalse($planResultBeta['can_apply'], "Beta can_apply must be false");
    assertEqual($planResultBeta['plan_sha256'], hash('sha256', $planResultBeta['plan_json']), "Beta plan_sha256 must match");

    echo " PASS\n";

    // =========================================================================
    // SUITE 2: Deterministic Ordering and Raw Byte Identity
    // =========================================================================
    echo "Suite 2: Deterministic Table Ordering and Byte Identity...";

    // Reorder context keys and data_compatibility keys
    $shuffledSrcCtx = [
        'native_shell_sha256' => $srcCtxStable['native_shell_sha256'],
        'channel' => $srcCtxStable['channel'],
        'data_compatibility' => [
            'schema_sha256' => $defaultDataCompat['schema_sha256'],
            'ledger_sha256' => $defaultDataCompat['ledger_sha256'],
            'metadata_migration' => $defaultDataCompat['metadata_migration'],
            'format' => $defaultDataCompat['format'],
        ],
        'version' => $srcCtxStable['version'],
        'inventory_sha256' => $srcCtxStable['inventory_sha256'],
        'native_version' => $srcCtxStable['native_version'],
    ];
    $shuffledTgtCtx = [
        'data_compatibility' => [
            'ledger_sha256' => $defaultDataCompat['ledger_sha256'],
            'format' => $defaultDataCompat['format'],
            'schema_sha256' => $defaultDataCompat['schema_sha256'],
            'metadata_migration' => $defaultDataCompat['metadata_migration'],
        ],
        'inventory_sha256' => $tgtCtxStable['inventory_sha256'],
        'channel' => $tgtCtxStable['channel'],
        'native_version' => $tgtCtxStable['native_version'],
        'native_shell_sha256' => $tgtCtxStable['native_shell_sha256'],
        'version' => $tgtCtxStable['version'],
    ];

    // Same raw manifest + semantically identical context with different key order MUST produce byte-identical plan
    $planResultShuffled = $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $shuffledSrcCtx, $shuffledTgtCtx);
    assertEqual($planResultShuffled['plan_json'], $planResultStable['plan_json'], "Plan JSON must be byte-for-byte identical");
    assertEqual($planResultShuffled['plan_sha256'], $planResultStable['plan_sha256'], "Plan SHA must be identical");

    // Different raw JSON order => different raw SHA => different plan SHA EXPECTED, compare projections
    $reversedDescKeys = array_reverse($descStable, true);
    [$rawReversed, $sigReversed] = signManifest($reversedDescKeys, $ephemeralKey);
    $rawReversedSha256 = hash('sha256', $rawReversed);

    assertTrue($rawReversedSha256 !== $rawStableSha256, "Reordered JSON has different raw SHA-256");

    $planResultReversed = $filePlanService->build($rawReversed, $sigReversed, $rawReversedSha256, $srcCtxStable, $tgtCtxStable);
    assertTrue($planResultReversed['plan_sha256'] !== $planResultStable['plan_sha256'], "Different raw SHA implies different plan SHA");

    $decodedReversed = json_decode($planResultReversed['plan_json'], true, 512, JSON_THROW_ON_ERROR);
    assertEqual($decodedReversed['actions'], $decodedPlan['actions'], "Actions projection must match despite raw reordering");
    assertEqual($decodedReversed['source_files'], $decodedPlan['source_files'], "Source files projection must match");
    assertEqual($decodedReversed['target_files'], $decodedPlan['target_files'], "Target files projection must match");

    echo " PASS\n";

    // =========================================================================
    // SUITE 3: Caller Pin, Cryptographic Signature, and Context Validation
    // =========================================================================
    echo "Suite 3: Caller Pin, Cryptographic Signature, and Context Validation...";

    // 3.1 Pin wrong
    $wrongPin = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $wrongPin, $srcCtxStable, $tgtCtxStable),
        'caller pin',
        'Wrong pin must be rejected before signature verification'
    );

    // 3.2 Bad signature
    $tamperedSig = base64_encode(str_repeat('X', 256));
    assertThrows(
        fn() => $filePlanService->build($rawStable, $tamperedSig, $rawStableSha256, $srcCtxStable, $tgtCtxStable),
        'signature',
        'Corrupted signature must be rejected'
    );

    // 3.3 Truncated signed raw
    $truncatedRaw = substr($rawStable, 0, (int) (strlen($rawStable) / 2));
    $truncatedPin = hash('sha256', $truncatedRaw);
    assertThrows(
        fn() => $filePlanService->build($truncatedRaw, $sigStable, $truncatedPin, $srcCtxStable, $tgtCtxStable),
        'signature',
        'Truncated raw manifest must fail signature verification before JSON parser'
    );

    // 3.4 Context with 'root' or 'paths'
    $ctxWithRoot = $srcCtxStable;
    $ctxWithRoot['root'] = 'C:\\pos';
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $ctxWithRoot, $tgtCtxStable),
        'root',
        'Context root key must be strictly rejected'
    );

    $ctxWithPaths = $srcCtxStable;
    $ctxWithPaths['paths'] = ['resources/app.asar'];
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $ctxWithPaths, $tgtCtxStable),
        'paths',
        'Context paths key must be strictly rejected'
    );

    // 3.5 Context with unknown extra key
    $ctxWithExtra = $srcCtxStable;
    $ctxWithExtra['unknown_extra'] = 'value';
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $ctxWithExtra, $tgtCtxStable),
        'exact 6 keys',
        'Context with unknown extra key must be rejected'
    );

    // 3.6 Context with missing key
    $ctxMissing = $srcCtxStable;
    unset($ctxMissing['native_shell_sha256']);
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $ctxMissing, $tgtCtxStable),
        'exact 6 keys',
        'Context with missing key must be rejected'
    );

    // 3.7 Invalid channel / classification mismatch
    $ctxBadChannel = $srcCtxStable;
    $ctxBadChannel['channel'] = 'retired';
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $ctxBadChannel, $tgtCtxStable),
        'channel',
        'Inactive channel must be rejected'
    );

    $ctxChannelMismatch = $srcCtxStable;
    $ctxChannelMismatch['version'] = '12.1.0-beta.1'; // beta version on stable channel
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $ctxChannelMismatch, $tgtCtxStable),
        'classification',
        'Beta version on stable channel must be rejected'
    );

    // 3.8 Cross-channel mismatch
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $srcCtxStable, $tgtCtxBeta),
        'channel',
        'Cross-channel transition must be rejected'
    );

    // 3.9 Monotonicity rejection (target <= source)
    $ctxDowngrade = $srcCtxStable;
    $ctxDowngrade['version'] = '12.0.0';
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $srcCtxStable, $ctxDowngrade),
        'strictly newer',
        'Downgrade or same-version target must be rejected'
    );

    // 3.10 Target identity mismatch with descriptor
    $ctxMismatchedTgtVer = $tgtCtxStable;
    $ctxMismatchedTgtVer['version'] = '12.3.0';
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $srcCtxStable, $ctxMismatchedTgtVer),
        'target version mismatch',
        'Target context version mismatch with descriptor must be rejected'
    );

    $ctxMismatchedTgtNative = $tgtCtxStable;
    $ctxMismatchedTgtNative['native_version'] = '14.0.0';
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $srcCtxStable, $ctxMismatchedTgtNative),
        'target native_version mismatch',
        'Target context native_version mismatch with descriptor must be rejected'
    );

    // 3.11 Version and native_version > 128 ASCII chars bounds
    $ctxLongVer = $srcCtxStable;
    $ctxLongVer['version'] = '1.0.' . str_repeat('0', 125); // 129 chars
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $ctxLongVer, $tgtCtxStable),
        'between 1 and 128',
        'Version exceeding 128 characters must be rejected with bounded length error'
    );

    $ctxLongNativeVer = $srcCtxStable;
    $ctxLongNativeVer['native_version'] = '1.0.' . str_repeat('0', 125); // 129 chars
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $ctxLongNativeVer, $tgtCtxStable),
        'between 1 and 128',
        'Native version exceeding 128 characters must be rejected with bounded length error'
    );

    // 3.12 Architectural Ceiling Notice: MAX_CONTEXT_JSON_BYTES (4096 bytes)
    // NOTICE: The 4096-byte context cap serves as an architectural ceiling defense-in-depth limit.
    // Because strict scalar validators run first and enforce version length <= 128, channels,
    // and exact 64-hex digests, a valid context with all fields at maximum length is ~550 bytes.
    // Hence, the 4096 cap is verified via constant assertion; legitimate fixtures cannot and
    // should not inflate beyond bounded primitives.
    assertEqual(ShellDeltaWorkerFilePlanService::MAX_CONTEXT_JSON_BYTES, 4096, "MAX_CONTEXT_JSON_BYTES constant must be 4096");

    // 3.13 In-memory JsonSerializable probe tests
    // Ensure that arbitrary JsonSerializable objects passed into context scalar or nested data
    // fields are rejected by primitive type checks before json_encode, ensuring zero user code execution.
    $probe = new class implements \JsonSerializable {
        public int $callCount = 0;
        public function jsonSerialize(): mixed {
            $this->callCount++;
            throw new RuntimeException("Side effect in jsonSerialize should NEVER be reached!");
        }
    };

    // Source scalar variants
    $probeCtxChannel = $srcCtxStable;
    $probeCtxChannel['channel'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $probeCtxChannel, $tgtCtxStable),
        'channel',
        'Probe in source channel must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on channel");

    $probeCtxVersion = $srcCtxStable;
    $probeCtxVersion['version'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $probeCtxVersion, $tgtCtxStable),
        'version',
        'Probe in source version must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on version");

    $probeCtxNativeVersion = $srcCtxStable;
    $probeCtxNativeVersion['native_version'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $probeCtxNativeVersion, $tgtCtxStable),
        'native_version',
        'Probe in source native_version must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on native_version");

    $probeCtxInvSha = $srcCtxStable;
    $probeCtxInvSha['inventory_sha256'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $probeCtxInvSha, $tgtCtxStable),
        'inventory_sha256',
        'Probe in source inventory_sha256 must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on inventory_sha256");

    $probeCtxShellSha = $srcCtxStable;
    $probeCtxShellSha['native_shell_sha256'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $probeCtxShellSha, $tgtCtxStable),
        'native_shell_sha256',
        'Probe in source native_shell_sha256 must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on native_shell_sha256");

    // Source nested data_compatibility variants
    $probeCtxDataCompat = $srcCtxStable;
    $probeCtxDataCompat['data_compatibility'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $probeCtxDataCompat, $tgtCtxStable),
        'data_compatibility',
        'Probe in source data_compatibility array must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on data_compatibility");

    $probeCtxDataFormat = $srcCtxStable;
    $probeCtxDataFormat['data_compatibility']['format'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $probeCtxDataFormat, $tgtCtxStable),
        'format',
        'Probe in source data_compatibility.format must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on format");

    $probeCtxDataMig = $srcCtxStable;
    $probeCtxDataMig['data_compatibility']['metadata_migration'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $probeCtxDataMig, $tgtCtxStable),
        'metadata_migration',
        'Probe in source data_compatibility.metadata_migration must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on metadata_migration");

    $probeCtxDataSchema = $srcCtxStable;
    $probeCtxDataSchema['data_compatibility']['schema_sha256'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $probeCtxDataSchema, $tgtCtxStable),
        'schema_sha256',
        'Probe in source data_compatibility.schema_sha256 must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on schema_sha256");

    $probeCtxDataLedger = $srcCtxStable;
    $probeCtxDataLedger['data_compatibility']['ledger_sha256'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $probeCtxDataLedger, $tgtCtxStable),
        'ledger_sha256',
        'Probe in source data_compatibility.ledger_sha256 must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on ledger_sha256");

    // Target variants
    $probeTgtChannel = $tgtCtxStable;
    $probeTgtChannel['channel'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $srcCtxStable, $probeTgtChannel),
        'channel',
        'Probe in target channel must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on target channel");

    $probeTgtVersion = $tgtCtxStable;
    $probeTgtVersion['version'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $srcCtxStable, $probeTgtVersion),
        'version',
        'Probe in target version must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on target version");

    $probeTgtSchema = $tgtCtxStable;
    $probeTgtSchema['data_compatibility']['schema_sha256'] = $probe;
    assertThrows(
        fn() => $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $srcCtxStable, $probeTgtSchema),
        'schema_sha256',
        'Probe in target data_compatibility.schema_sha256 must be rejected'
    );
    assertEqual($probe->callCount, 0, "Probe callback must not be invoked on target schema_sha256");

    echo " PASS\n";

    // =========================================================================
    // SUITE 4: Full Validator Enforcement (Paths, Collisions, Protected Data)
    // =========================================================================
    echo "Suite 4: Full Validator Enforcement (Paths, Collisions, Protected Data)...";

    // 4.1 Recomputed target inventory SHA tampered
    $descTamperedInvHash = $descStable;
    $descTamperedInvHash['target_inventory_sha256'] = hash('sha256', 'TAMPERED_INVENTORY');
    [$rawTamperedHash, $sigTamperedHash] = signManifest($descTamperedInvHash, $ephemeralKey);
    $tamperedHashCtx = $tgtCtxStable;
    $tamperedHashCtx['inventory_sha256'] = $descTamperedInvHash['target_inventory_sha256'];
    assertThrows(
        fn() => $filePlanService->build($rawTamperedHash, $sigTamperedHash, hash('sha256', $rawTamperedHash), $srcCtxStable, $tamperedHashCtx),
        'target_inventory_hash_mismatch',
        'Tampered inventory digest must be rejected by validator'
    );

    // 4.2 Traversal / Invalid path in inventory
    $invInvalidPath = $srcInvStable;
    $invInvalidPath['files']['resources/app/../escaped.txt'] = ['sha256' => hash('sha256', 'BAD'), 'size' => 100];
    $descInvalidPath = createDescriptor($invInvalidPath, $tgtInvStable, 'stable', $defaultDataCompat);
    [$rawInvalidPath, $sigInvalidPath] = signManifest($descInvalidPath, $ephemeralKey);
    $ctxInvalidPath = createContext($invInvalidPath, 'stable', $defaultDataCompat);
    assertThrows(
        fn() => $filePlanService->build($rawInvalidPath, $sigInvalidPath, hash('sha256', $rawInvalidPath), $ctxInvalidPath, $tgtCtxStable),
        'source_inventory_invalid',
        'Path with directory traversal in inventory must be rejected'
    );

    // 4.3 Cross-inventory case collision
    $invCaseSrc = $srcInvStable;
    $invCaseSrc['files']['resources/app/sample.txt'] = ['sha256' => hash('sha256', 'SAMPLE1'), 'size' => 100];
    $invCaseTgt = $tgtInvStable;
    $invCaseTgt['files']['resources/app/SAMPLE.TXT'] = ['sha256' => hash('sha256', 'SAMPLE2'), 'size' => 100];
    $descCase = createDescriptor($invCaseSrc, $invCaseTgt, 'stable', $defaultDataCompat);
    [$rawCase, $sigCase] = signManifest($descCase, $ephemeralKey);
    $ctxCaseSrc = createContext($invCaseSrc, 'stable', $defaultDataCompat);
    $ctxCaseTgt = createContext($invCaseTgt, 'stable', $defaultDataCompat);
    assertThrows(
        fn() => $filePlanService->build($rawCase, $sigCase, hash('sha256', $rawCase), $ctxCaseSrc, $ctxCaseTgt),
        'path_case_collision',
        'Case collisions across inventories must be rejected'
    );

    // 4.4 Protected user-state data path (.env)
    $invProtected = $srcInvStable;
    $invProtected['files']['.env'] = ['sha256' => hash('sha256', 'SECRET'), 'size' => 50];
    $descProtected = createDescriptor($invProtected, $tgtInvStable, 'stable', $defaultDataCompat);
    [$rawProtected, $sigProtected] = signManifest($descProtected, $ephemeralKey);
    $ctxProtected = createContext($invProtected, 'stable', $defaultDataCompat);
    assertThrows(
        fn() => $filePlanService->build($rawProtected, $sigProtected, hash('sha256', $rawProtected), $ctxProtected, $tgtCtxStable),
        'source_inventory_invalid',
        'Protected user-state file (.env) must be rejected'
    );

    // 4.5 Mismatched files payload diff
    $descBadDiff = $descStable;
    $descBadDiff['files'][] = ['path' => 'resources/app/unaccounted.js', 'sha256' => hash('sha256', 'UN'), 'size' => 50];
    $descBadDiff['package']['uncompressed_size'] += 50;
    [$rawBadDiff, $sigBadDiff] = signManifest($descBadDiff, $ephemeralKey);
    assertThrows(
        fn() => $filePlanService->build($rawBadDiff, $sigBadDiff, hash('sha256', $rawBadDiff), $srcCtxStable, $tgtCtxStable),
        'payload_mismatch',
        'Mismatched files diff in descriptor must be rejected'
    );

    // 4.6 Package bounds exceeded
    $descHugePkg = $descStable;
    $descHugePkg['package']['uncompressed_size'] = ShellDeltaManifestService::MAX_UNCOMPRESSED_SIZE + 1;
    [$rawHugePkg, $sigHugePkg] = signManifest($descHugePkg, $ephemeralKey);
    assertThrows(
        fn() => $filePlanService->build($rawHugePkg, $sigHugePkg, hash('sha256', $rawHugePkg), $srcCtxStable, $tgtCtxStable),
        'package_invalid',
        'Package exceeding uncompressed size limits must be rejected'
    );

    echo " PASS\n";

    // =========================================================================
    // SUITE 5: Data Compatibility, Schema/Ledger, Fixed 59, Migrations, Bootstrap
    // =========================================================================
    echo "Suite 5: Data Compatibility, Migrations, and Bootstrap Policy...";

    // 5.1 Data compatibility format !== 1
    $badCompatFormat = $defaultDataCompat;
    $badCompatFormat['format'] = 2;
    $descBadCompatFormat = createDescriptor($srcInvStable, $tgtInvStable, 'stable', $badCompatFormat);
    [$rawBadCompatFormat, $sigBadCompatFormat] = signManifest($descBadCompatFormat, $ephemeralKey);
    $ctxBadCompatFormat = createContext($srcInvStable, 'stable', $badCompatFormat);
    assertThrows(
        fn() => $filePlanService->build($rawBadCompatFormat, $sigBadCompatFormat, hash('sha256', $rawBadCompatFormat), $ctxBadCompatFormat, $ctxBadCompatFormat),
        'format',
        'Unsupported data compatibility format must be rejected'
    );

    // 5.2 Metadata migration != 059
    $badCompatMigration = $defaultDataCompat;
    $badCompatMigration['metadata_migration'] = '060_unsupported.sql';
    $descBadMigration = createDescriptor($srcInvStable, $tgtInvStable, 'stable', $badCompatMigration);
    [$rawBadMigration, $sigBadMigration] = signManifest($descBadMigration, $ephemeralKey);
    $ctxBadMigration = createContext($srcInvStable, 'stable', $badCompatMigration);
    assertThrows(
        fn() => $filePlanService->build($rawBadMigration, $sigBadMigration, hash('sha256', $rawBadMigration), $ctxBadMigration, $ctxBadMigration),
        'metadata_migration',
        'Invalid metadata_migration constant must be rejected'
    );

    // 5.3 Source and target data compatibility mismatch
    $descCompatMismatch = $descStable;
    $descCompatMismatch['data_compatibility']['schema_sha256'] = hash('sha256', 'SCHEMA_V2');
    [$rawCompatMismatch, $sigCompatMismatch] = signManifest($descCompatMismatch, $ephemeralKey);
    $tgtCtxMismatch = $tgtCtxStable;
    $tgtCtxMismatch['data_compatibility']['schema_sha256'] = $descCompatMismatch['data_compatibility']['schema_sha256'];
    assertThrows(
        fn() => $filePlanService->build($rawCompatMismatch, $sigCompatMismatch, hash('sha256', $rawCompatMismatch), $srcCtxStable, $tgtCtxMismatch),
        'data compatibility',
        'Source and target data compatibility mismatch must be rejected'
    );

    // 5.4 Non-empty migrations in descriptor
    $descWithMigrations = createDescriptor($srcInvStable, $tgtInvStable, 'stable', $defaultDataCompat, 'regular', ['059_add_update_compatibility_metadata.sql']);
    [$rawWithMigrations, $sigWithMigrations] = signManifest($descWithMigrations, $ephemeralKey);
    assertThrows(
        fn() => $filePlanService->build($rawWithMigrations, $sigWithMigrations, hash('sha256', $rawWithMigrations), $srcCtxStable, $tgtCtxStable),
        'migrations must be empty',
        'Non-empty migrations array in file plan descriptor must be rejected'
    );

    // 5.5 Purpose 'bootstrap_completion' fails closed
    $descBootstrap = createDescriptor($srcInvStable, $tgtInvStable, 'stable', $defaultDataCompat, 'bootstrap_completion');
    $descBootstrap['bootstrap_link'] = hash('sha256', 'LINK');
    [$rawBootstrap, $sigBootstrap] = signManifest($descBootstrap, $ephemeralKey);
    assertThrows(
        fn() => $filePlanService->build($rawBootstrap, $sigBootstrap, hash('sha256', $rawBootstrap), $srcCtxStable, $tgtCtxStable),
        'bootstrap_completion',
        'Purpose bootstrap_completion must be rejected'
    );

    echo " PASS\n";

    // =========================================================================
    // SUITE 6: Database Asset and SQL Policy (Disallow Mutation, Allow Retain)
    // =========================================================================
    echo "Suite 6: Database Asset and SQL Mutation Policy...";

    // 6.1 Disallow CREATE to resources/portable/mysql/**
    $tgtWithMysqlCreate = $tgtInvStable;
    $tgtWithMysqlCreate['files']['resources/portable/mysql/bin/mariadbd.exe'] = ['sha256' => hash('sha256', 'ENGINE'), 'size' => 1000];
    $descMysqlCreate = createDescriptor($srcInvStable, $tgtWithMysqlCreate, 'stable', $defaultDataCompat);
    [$rawMysqlCreate, $sigMysqlCreate] = signManifest($descMysqlCreate, $ephemeralKey);
    $ctxMysqlCreate = createContext($tgtWithMysqlCreate, 'stable', $defaultDataCompat);
    assertThrows(
        fn() => $filePlanService->build($rawMysqlCreate, $sigMysqlCreate, hash('sha256', $rawMysqlCreate), $srcCtxStable, $ctxMysqlCreate),
        'disallowed mutation to database asset',
        'Creation of files under resources/portable/mysql/ must be rejected'
    );

    // 6.2 Disallow REPLACE to SQL schema script
    $tgtWithSqlReplace = $tgtInvStable;
    $tgtWithSqlReplace['files']['resources/portable/mysql/share/mysql_system_tables.sql'] = ['sha256' => hash('sha256', 'SQL_MODIFIED'), 'size' => 450];
    $descSqlReplace = createDescriptor($srcInvStable, $tgtWithSqlReplace, 'stable', $defaultDataCompat);
    [$rawSqlReplace, $sigSqlReplace] = signManifest($descSqlReplace, $ephemeralKey);
    $ctxSqlReplace = createContext($tgtWithSqlReplace, 'stable', $defaultDataCompat);
    assertThrows(
        fn() => $filePlanService->build($rawSqlReplace, $sigSqlReplace, hash('sha256', $rawSqlReplace), $srcCtxStable, $ctxSqlReplace),
        'disallowed mutation to database asset',
        'Modification of SQL schema scripts must be rejected'
    );

    // 6.3 Disallow CREATE to any *.sql file
    $tgtWithNewSql = $tgtInvStable;
    $tgtWithNewSql['files']['resources/app/migrations.sql'] = ['sha256' => hash('sha256', 'NEW_SQL'), 'size' => 100];
    $descNewSql = createDescriptor($srcInvStable, $tgtWithNewSql, 'stable', $defaultDataCompat);
    [$rawNewSql, $sigNewSql] = signManifest($descNewSql, $ephemeralKey);
    $ctxNewSql = createContext($tgtWithNewSql, 'stable', $defaultDataCompat);
    assertThrows(
        fn() => $filePlanService->build($rawNewSql, $sigNewSql, hash('sha256', $rawNewSql), $srcCtxStable, $ctxNewSql),
        'disallowed mutation to database asset',
        'Creation of *.sql files must be rejected'
    );

    // 6.4 Disallow DELETE of any *.sql file
    $srcWithDeletableSql = $srcInvStable;
    $srcWithDeletableSql['files']['resources/portable/mysql/share/maria_add_gis_sp.sql'] = ['sha256' => hash('sha256', 'GIS'), 'size' => 100];
    $descDeleteSql = createDescriptor($srcWithDeletableSql, $tgtInvStable, 'stable', $defaultDataCompat);
    [$rawDeleteSql, $sigDeleteSql] = signManifest($descDeleteSql, $ephemeralKey);
    $ctxDeleteSqlSrc = createContext($srcWithDeletableSql, 'stable', $defaultDataCompat);
    assertThrows(
        fn() => $filePlanService->build($rawDeleteSql, $sigDeleteSql, hash('sha256', $rawDeleteSql), $ctxDeleteSqlSrc, $tgtCtxStable),
        'disallowed mutation to database asset',
        'Deletion of SQL schema files must be rejected'
    );

    // 6.5 Allowed: RETAINED MySQL SQL scripts and engine assets remain in file tables
    $sqlRetainAction = $actionsByPath['resources/portable/mysql/share/mysql_system_tables.sql'] ?? null;
    assertTrue($sqlRetainAction !== null, "mysql_system_tables.sql must be in actions table");
    assertEqual($sqlRetainAction['kind'], 'retain', "mysql_system_tables.sql kind must be retain");

    echo " PASS\n";

    // =========================================================================
    // SUITE 7: Safe Integer Boundary & 20,000 + 20,000 In-Memory Stress Projection
    // =========================================================================
    echo "Suite 7: Numeric Safe Integer Boundary & 20k+20k In-Memory Projection...";

    // 7.1 True boundary MAX_SAFE_INTEGER (9007199254740991 = 2^53 - 1)
    // Retained non-shell file in BOTH source and target inventories
    $hugePayloadHash = hash('sha256', 'MAX_SAFE_INTEGER_PAYLOAD');
    $srcExtraMaxSafe = [
        'resources/app/retained_max_safe.bin' => [
            'sha256' => $hugePayloadHash,
            'size' => 9007199254740991,
        ],
    ];
    $tgtExtraMaxSafe = [
        'resources/app/retained_max_safe.bin' => [
            'sha256' => $hugePayloadHash,
            'size' => 9007199254740991,
        ],
    ];
    $srcInvMaxSafe = createFakeInventory('12.1.0', '13.0.0', $srcExtraMaxSafe);
    $tgtInvMaxSafe = createFakeInventory('12.2.0', '13.0.0', $tgtExtraMaxSafe);

    $descMaxSafe = createDescriptor($srcInvMaxSafe, $tgtInvMaxSafe, 'stable', $defaultDataCompat);
    [$rawMaxSafe, $sigMaxSafe] = signManifest($descMaxSafe, $ephemeralKey);

    $srcCtxMaxSafe = createContext($srcInvMaxSafe, 'stable', $defaultDataCompat);
    $tgtCtxMaxSafe = createContext($tgtInvMaxSafe, 'stable', $defaultDataCompat);
    $rawMaxSafeSha256 = hash('sha256', $rawMaxSafe);

    $resMaxSafe = $filePlanService->build($rawMaxSafe, $sigMaxSafe, $rawMaxSafeSha256, $srcCtxMaxSafe, $tgtCtxMaxSafe);
    assertFalse($resMaxSafe['can_apply'], "Max safe integer inventory builds plan with can_apply false");

    $decodedMaxSafe = json_decode($resMaxSafe['plan_json'], true, 512, JSON_THROW_ON_ERROR);

    // Confirm plan retains EXACT integer MAX (9007199254740991) and hash in source_files, target_files, and actions
    $srcFileEntry = null;
    foreach ($decodedMaxSafe['source_files'] as $f) {
        if ($f['path'] === 'resources/app/retained_max_safe.bin') {
            $srcFileEntry = $f;
            break;
        }
    }
    assertTrue($srcFileEntry !== null, "retained_max_safe.bin must be in source_files");
    assertEqual($srcFileEntry['size'], 9007199254740991, "source_files size must be exact MAX_SAFE_INTEGER (9007199254740991)");
    assertEqual($srcFileEntry['sha256'], $hugePayloadHash, "source_files hash must match");

    $tgtFileEntry = null;
    foreach ($decodedMaxSafe['target_files'] as $f) {
        if ($f['path'] === 'resources/app/retained_max_safe.bin') {
            $tgtFileEntry = $f;
            break;
        }
    }
    assertTrue($tgtFileEntry !== null, "retained_max_safe.bin must be in target_files");
    assertEqual($tgtFileEntry['size'], 9007199254740991, "target_files size must be exact MAX_SAFE_INTEGER (9007199254740991)");
    assertEqual($tgtFileEntry['sha256'], $hugePayloadHash, "target_files hash must match");

    $actionEntry = null;
    foreach ($decodedMaxSafe['actions'] as $act) {
        if ($act['path'] === 'resources/app/retained_max_safe.bin') {
            $actionEntry = $act;
            break;
        }
    }
    assertTrue($actionEntry !== null, "retained_max_safe.bin must be in actions");
    assertEqual($actionEntry['kind'], 'retain', "retained_max_safe.bin action kind must be retain");
    assertEqual($actionEntry['source']['size'], 9007199254740991, "action source size must be exact MAX_SAFE_INTEGER");
    assertEqual($actionEntry['target']['size'], 9007199254740991, "action target size must be exact MAX_SAFE_INTEGER");
    assertEqual($actionEntry['source']['sha256'], $hugePayloadHash, "action source hash must match");
    assertEqual($actionEntry['target']['sha256'], $hugePayloadHash, "action target hash must match");

    // 7.2 Invalid inventory file sizes: 9007199254740992, -1, 10.5, "1000"
    // Mutate ALREADY valid descriptor AFTER build helper; sign raw + update pin.
    // Do NOT call canonicalInventoryDigest on invalid metadata outside assert (that fails test setup).
    // Require expected full validator reason substrings.

    // 7.2.1 MAX_SAFE_INTEGER + 1 (9007199254740992) in source inventory
    $descExceedsSafeInt = $descStable;
    $descExceedsSafeInt['source_inventory']['files']['resources/app/common.dat']['size'] = 9007199254740992;
    [$rawExceeds, $sigExceeds] = signManifest($descExceedsSafeInt, $ephemeralKey);
    assertThrows(
        fn() => $filePlanService->build($rawExceeds, $sigExceeds, hash('sha256', $rawExceeds), $srcCtxStable, $tgtCtxStable),
        'source_inventory_invalid',
        'Source inventory size > MAX_SAFE_INTEGER (9007199254740992) must be rejected with source_inventory_invalid'
    );

    // 7.2.2 Negative file size (-1) in source inventory
    $descNegSize = $descStable;
    $descNegSize['source_inventory']['files']['resources/app/common.dat']['size'] = -1;
    [$rawNeg, $sigNeg] = signManifest($descNegSize, $ephemeralKey);
    assertThrows(
        fn() => $filePlanService->build($rawNeg, $sigNeg, hash('sha256', $rawNeg), $srcCtxStable, $tgtCtxStable),
        'source_inventory_invalid',
        'Source inventory with negative size (-1) must be rejected with source_inventory_invalid'
    );

    // 7.2.3 Fractional file size (10.5) in source inventory
    $descFracSize = $descStable;
    $descFracSize['source_inventory']['files']['resources/app/common.dat']['size'] = 10.5;
    [$rawFrac, $sigFrac] = signManifest($descFracSize, $ephemeralKey);
    assertThrows(
        fn() => $filePlanService->build($rawFrac, $sigFrac, hash('sha256', $rawFrac), $srcCtxStable, $tgtCtxStable),
        'source_inventory_invalid',
        'Source inventory with fractional size (10.5) must be rejected with source_inventory_invalid'
    );

    // 7.2.4 String file size ("1000") in source inventory
    $descStrSize = $descStable;
    $descStrSize['source_inventory']['files']['resources/app/common.dat']['size'] = "1000";
    [$rawStr, $sigStr] = signManifest($descStrSize, $ephemeralKey);
    assertThrows(
        fn() => $filePlanService->build($rawStr, $sigStr, hash('sha256', $rawStr), $srcCtxStable, $tgtCtxStable),
        'source_inventory_invalid',
        'Source inventory with string size must be rejected with source_inventory_invalid'
    );

    // 7.2.5 Target inventory invalid size (9007199254740992)
    $descTgtExceeds = $descStable;
    $descTgtExceeds['target_inventory']['files']['resources/app/common.dat']['size'] = 9007199254740992;
    [$rawTgtExceeds, $sigTgtExceeds] = signManifest($descTgtExceeds, $ephemeralKey);
    assertThrows(
        fn() => $filePlanService->build($rawTgtExceeds, $sigTgtExceeds, hash('sha256', $rawTgtExceeds), $srcCtxStable, $tgtCtxStable),
        'target_inventory_invalid',
        'Target inventory with size > MAX_SAFE_INTEGER must be rejected with target_inventory_invalid'
    );

    // 7.3 Changed file exceeding 250 MiB (MAX_SINGLE_FILE_SIZE = 262144000)
    // Real negative signed file diff case using synthetic metadata (NO physical huge file on disk).
    $srcInvSingleFile = createFakeInventory('12.1.0', '13.0.0');
    $tgtInvSingleFile = createFakeInventory('12.2.0', '13.0.0', [
        'resources/app/changed_250mb.bin' => [
            'sha256' => hash('sha256', 'OVERSIZED_CHANGED_FILE'),
            'size' => 262144001, // MAX_SINGLE_FILE_SIZE (262144000) + 1
        ],
    ]);
    $descSingleFile = createDescriptor($srcInvSingleFile, $tgtInvSingleFile, 'stable', $defaultDataCompat);
    [$rawSingleFile, $sigSingleFile] = signManifest($descSingleFile, $ephemeralKey);
    $srcCtxSingleFile = createContext($srcInvSingleFile, 'stable', $defaultDataCompat);
    $tgtCtxSingleFile = createContext($tgtInvSingleFile, 'stable', $defaultDataCompat);
    $pinSingleFile = hash('sha256', $rawSingleFile);

    assertThrows(
        fn() => $filePlanService->build($rawSingleFile, $sigSingleFile, $pinSingleFile, $srcCtxSingleFile, $tgtCtxSingleFile),
        'files_invalid',
        'Changed file exceeding MAX_SINGLE_FILE_SIZE (250 MiB) must be rejected with files_invalid'
    );

    // 7.4 Architectural Ceiling Notice: MAX_PLAN_JSON_BYTES (64 MiB)
    // NOTICE: The 64 MiB (67108864 bytes) plan cap serves as an architectural ceiling defense-in-depth limit.
    // The short-path 20k+20k fixture below measures one valid projection, not the largest possible encoding.
    // Longer or escaped Unicode paths may produce larger plans.
    // The constant and the fixture's measured length are checked; the over-64-MiB rejection branch
    // is not exercised here, and no claim of its runtime coverage is made.
    assertEqual(ShellDeltaWorkerFilePlanService::MAX_PLAN_JSON_BYTES, 67108864, "MAX_PLAN_JSON_BYTES must be 64 MiB");

    // 7.5 Real signed in-memory 20k source + 20k target stress test
    // 3 common retained entries + 19,997 source-only + 19,997 target-only => 39,997 actions projection
    $commonExeHash = hash('sha256', 'COMMON_EXE');
    $commonAsarHash = hash('sha256', 'COMMON_ASAR');

    $hugeSrcExtra = [];
    $hugeTgtExtra = [];

    // Use short relative paths: 's/00001' .. 's/19997' and 't/00001' .. 't/19997'
    for ($i = 1; $i <= 19997; $i++) {
        $idxStr = str_pad((string) $i, 5, '0', STR_PAD_LEFT);
        $hugeSrcExtra["s/{$idxStr}"] = ['sha256' => hash('sha256', "src_{$i}"), 'size' => 0];
        $hugeTgtExtra["t/{$idxStr}"] = ['sha256' => hash('sha256', "tgt_{$i}"), 'size' => 0];
    }

    $hugeSrcInv = createFakeInventory('12.1.0', '13.0.0', $hugeSrcExtra, $commonExeHash, $commonAsarHash);
    $hugeTgtInv = createFakeInventory('12.2.0', '13.0.0', $hugeTgtExtra, $commonExeHash, $commonAsarHash);

    // Keep version.json retained across huge inventories to have exactly 3 retained entries
    $hugeTgtInv['files']['resources/app.asar.unpacked/version.json'] = $hugeSrcInv['files']['resources/app.asar.unpacked/version.json'];

    assertEqual(count($hugeSrcInv['files']), 20000, "Source inventory has exactly 20,000 files");
    assertEqual(count($hugeTgtInv['files']), 20000, "Target inventory has exactly 20,000 files");

    $hugeDesc = createDescriptor($hugeSrcInv, $hugeTgtInv, 'stable', $defaultDataCompat);
    [$hugeRaw, $hugeSig] = signManifest($hugeDesc, $ephemeralKey);

    $hugeRawLen = strlen($hugeRaw);
    assertTrue($hugeRawLen > 0 && $hugeRawLen <= ShellDeltaManifestService::MAX_MANIFEST_SIZE, "Huge raw manifest fits within 16 MiB");

    $hugeSrcCtx = createContext($hugeSrcInv, 'stable', $defaultDataCompat);
    $hugeTgtCtx = createContext($hugeTgtInv, 'stable', $defaultDataCompat);
    $hugeRawSha256 = hash('sha256', $hugeRaw);

    $hugePlanResult = $filePlanService->build($hugeRaw, $hugeSig, $hugeRawSha256, $hugeSrcCtx, $hugeTgtCtx);

    assertFalse($hugePlanResult['can_apply'], "Huge plan can_apply is false");
    assertEqual($hugePlanResult['plan_sha256'], hash('sha256', $hugePlanResult['plan_json']), "Huge plan self-hash matches");

    $decodedHuge = json_decode($hugePlanResult['plan_json'], true, 512, JSON_THROW_ON_ERROR);
    assertEqual(count($decodedHuge['source_files']), 20000, "Huge source_files has 20,000 items");
    assertEqual(count($decodedHuge['target_files']), 20000, "Huge target_files has 20,000 items");
    assertEqual(count($decodedHuge['actions']), 39997, "Huge actions has 39,997 items (3 retained + 19,997 created + 19,997 deleted)");

    // Count action kinds
    $retains = 0;
    $creates = 0;
    $deletes = 0;
    foreach ($decodedHuge['actions'] as $act) {
        if ($act['kind'] === 'retain') $retains++;
        elseif ($act['kind'] === 'create') $creates++;
        elseif ($act['kind'] === 'delete') $deletes++;
    }
    assertEqual($retains, 3, "Exactly 3 retained actions");
    assertEqual($creates, 19997, "Exactly 19,997 created actions");
    assertEqual($deletes, 19997, "Exactly 19,997 deleted actions");

    $hugePlanBytes = strlen($hugePlanResult['plan_json']);
    assertTrue($hugePlanBytes <= ShellDeltaWorkerFilePlanService::MAX_PLAN_JSON_BYTES, "Huge plan JSON fits within 64 MiB limit");

    // Clean up large fixtures from memory
    unset($hugeSrcExtra, $hugeTgtExtra, $hugeSrcInv, $hugeTgtInv, $hugeDesc, $hugeRaw, $hugeSig, $decodedHuge, $hugePlanResult);
    gc_collect_cycles();

    echo " PASS\n";

    // =========================================================================
    // SUITE 8: Plan Frame Security and Isolation
    // =========================================================================
    echo "Suite 8: Plan Frame Security, Integrity, and Isolation...";

    // 8.1 Plan frame self-hash verification
    assertEqual(hash('sha256', $planResultStable['plan_json']), $planResultStable['plan_sha256'], "Plan self-hash SHA-256 matches exact bytes");

    // 8.2 Envelope immutability & input mutation safety
    $mutSrcCtx = $srcCtxStable;
    $mutTgtCtx = $tgtCtxStable;
    $resBefore = $filePlanService->build($rawStable, $sigStable, $rawStableSha256, $mutSrcCtx, $mutTgtCtx);

    // Mutate caller input arrays
    $mutSrcCtx['channel'] = 'tampered';
    $mutSrcCtx['data_compatibility']['format'] = 999;
    $mutTgtCtx['version'] = '99.99.99';

    assertEqual($resBefore['plan_json'], $planResultStable['plan_json'], "Mutating input arrays after call does not affect previous result");

    // 8.3 Verify no private key, raw signature, or absolute root leaked into plan JSON
    $planJson = $planResultStable['plan_json'];
    assertFalse(str_contains($planJson, 'PRIVATE KEY'), "Plan JSON must not contain private key");
    assertFalse(str_contains($planJson, $sigStable), "Plan JSON must not contain raw base64 signature");
    assertFalse(str_contains($planJson, 'C:\\'), "Plan JSON must not contain Windows absolute drive path");
    assertFalse(str_contains($planJson, 'c:\\'), "Plan JSON must not contain lowercase Windows drive path");

    echo " PASS\n";

    // =========================================================================
    // FINAL CLEANUP AND SUMMARY
    // =========================================================================
    unset($ephemeralKey);
    gc_collect_cycles();

    echo "\n=== All 8 Verification Suites PASSED Successfully ({$totalAssertions} assertions verified) ===\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "\nFATAL TEST FAILURE in {$e->getFile()}:{$e->getLine()}: {$e->getMessage()}\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
