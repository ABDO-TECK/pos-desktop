<?php
declare(strict_types=1);

/**
 * scripts/create-shell-native-broker-binding-fixtures.php
 *
 * Generates synthetic cross-language test fixtures for native broker context binding.
 * Generates an ephemeral in-memory RSA 2048 key, builds signed descriptors using
 * real PHP canonical shell and inventory digests, and writes the complete test suite
 * to the specified output JSON file for consumption by verify-shell-native-broker-binding.ps1.
 * Ephemeral GHA fixture trusted key, not production trust bootstrap.
 *
 * STRICT BOUNDARIES:
 * - Runs exclusively in GitHub Actions on Windows under CLI
 * - Pure in-memory synthetic baseline (no database, customer, or product filesystem reads)
 * - Ephemeral private key is never saved to disk or logged
 * - Checked write/close to single output file, no recursive cleanup
 */

// Strict Environment Guards: Must run under CLI in GitHub Actions on Windows
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "GUARD: CLI required.\n");
    exit(1);
}
if (getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "GUARD: GITHUB_ACTIONS=true required.\n");
    exit(1);
}
if (PHP_OS_FAMILY !== 'Windows') {
    fwrite(STDERR, "GUARD: Windows runner required.\n");
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

use App\Services\ManifestSignatureService;
use App\Services\ProductInventoryService;
use App\Services\ShellDeltaManifestService;
use App\Services\UpdateChannelPolicy;
use App\Services\UpdateDataCompatibilityService;

$options = getopt('', ['output:']);
$outputPath = $options['output'] ?? null;
if (!is_string($outputPath) || trim($outputPath) === '') {
    fwrite(STDERR, "ERROR: --output <filepath> is required.\n");
    exit(1);
}

// 1. Generate Ephemeral RSA 2048 Key in Memory
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

// Assert RSA key shape matches NativeBrokerTrustedKey requirements (no leading 0, odd integer)
if ((ord($rawModulus[0]) & 0x80) === 0 || (ord($rawModulus[strlen($rawModulus) - 1]) & 1) === 0) {
    fwrite(STDERR, "ERROR: Ephemeral RSA modulus must have high bit set and be odd.\n");
    exit(1);
}

// Ephemeral GHA fixture trusted key, not production trust bootstrap
$keyId = 'ephemeral_gha_fixture_trusted_key_not_production_trust_bootstrap';

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

// 2. Baseline Data Contract (Format 1)
$baseDataContract = [
    'format' => 1,
    'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
    'schema_sha256' => hash('sha256', 'mock_mariadb_schema_normalized_v1'),
    'ledger_sha256' => hash('sha256', 'mock_migration_ledger_normalized_v1'),
];

// Helper to build synthetic Format 1 inventory
$makeSyntheticInventory = static function (
    string $appVersion,
    string $nativeVersion,
    string $exeHash,
    int $exeSize,
    string $asarHash,
    int $asarSize,
    ?array $additionalFiles = null
): array {
    $shellFiles = [
        'POS Desktop.exe' => [
            'sha256' => strtolower($exeHash),
            'size' => $exeSize,
        ],
        'resources/app.asar' => [
            'sha256' => strtolower($asarHash),
            'size' => $asarSize,
        ],
    ];

    $canonicalShellDigest = ProductInventoryService::computeCanonicalShellDigest($shellFiles);

    $files = [
        'POS Desktop.exe' => [
            'sha256' => strtolower($exeHash),
            'size' => $exeSize,
        ],
        'resources/app.asar' => [
            'sha256' => strtolower($asarHash),
            'size' => $asarSize,
        ],
        'resources/app.asar.unpacked/version.json' => [
            'sha256' => hash('sha256', '{"version":"' . $appVersion . '"}'),
            'size' => strlen('{"version":"' . $appVersion . '"}'),
        ],
    ];

    if ($additionalFiles !== null) {
        foreach ($additionalFiles as $path => $meta) {
            $files[$path] = $meta;
        }
    }

    return [
        'format' => 1,
        'platform' => 'win32',
        'arch' => 'x64',
        'version' => $appVersion,
        'native_version' => $nativeVersion,
        'native_shell' => [
            'files' => $shellFiles,
            'sha256' => $canonicalShellDigest,
        ],
        'files' => $files,
    ];
};

// Helper to compute diff between inventories and assemble full descriptor
$makeDescriptor = static function (
    array $srcInv,
    array $tgtInv,
    string $fromChannel,
    string $toChannel,
    string $fromVersion,
    string $toVersion,
    string $fromNativeVer,
    string $toNativeVer,
    array $dataContract
): array {
    $expectedFiles = [];
    $totalUncompressed = 0;
    foreach ($tgtInv['files'] as $path => $entry) {
        if (!isset($srcInv['files'][$path]) ||
            $srcInv['files'][$path]['sha256'] !== $entry['sha256'] ||
            $srcInv['files'][$path]['size'] !== $entry['size']) {
            $expectedFiles[$path] = $entry;
            $totalUncompressed += $entry['size'];
        }
    }
    ksort($expectedFiles);

    $diffFiles = [];
    foreach ($expectedFiles as $p => $meta) {
        $diffFiles[] = [
            'path' => $p,
            'sha256' => $meta['sha256'],
            'size' => $meta['size'],
        ];
    }

    $deletedFiles = array_values(array_diff(array_keys($srcInv['files']), array_keys($tgtInv['files'])));
    sort($deletedFiles);

    $srcInvSha = ShellDeltaManifestService::canonicalInventoryDigest($srcInv);
    $tgtInvSha = ShellDeltaManifestService::canonicalInventoryDigest($tgtInv);

    return [
        'format' => 2,
        'operation' => 'shell_delta',
        'minimum_updater_protocol' => 2,
        'platform' => 'win32',
        'arch' => 'x64',
        'from_channel' => $fromChannel,
        'channel' => $toChannel,
        'from_version' => $fromVersion,
        'version' => $toVersion,
        'from_native_version' => $fromNativeVer,
        'native_version' => $toNativeVer,
        'prerelease' => ($toChannel === 'beta'),
        'purpose' => 'regular',
        'source_inventory_sha256' => $srcInvSha,
        'source_inventory' => $srcInv,
        'target_inventory_sha256' => $tgtInvSha,
        'target_inventory' => $tgtInv,
        'source_native_shell' => $srcInv['native_shell'],
        'target_native_shell' => $tgtInv['native_shell'],
        'files' => $diffFiles,
        'deleted_files' => $deletedFiles,
        'package' => [
            'asset' => 'shell-delta.zip',
            'sha256' => hash('sha256', 'mock_synthetic_package_zip_content'),
            'size' => 2048,
            'uncompressed_size' => $totalUncompressed,
        ],
        'data_compatibility' => $dataContract,
        'source_data_compatibility' => $dataContract,
        'migrations' => [],
    ];
};

// Instantiate signature, inventory, and manifest services avoiding default key discovery and logging
$sigService = new ManifestSignatureService(null, [$details['key']], false, false);
$invService = new ProductInventoryService();
$manifestService = new ShellDeltaManifestService($sigService, $invService);

// Compute canonical shell golden vector
$shellTestExeSha = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$shellTestAsarSha = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';
$canonicalShellDigestExpected = ProductInventoryService::computeCanonicalShellDigest([
    'POS Desktop.exe' => ['sha256' => $shellTestExeSha, 'size' => 12345],
    'resources/app.asar' => ['sha256' => $shellTestAsarSha, 'size' => 67890],
]);
$canonicalShellGolden = [
    'exe_sha' => $shellTestExeSha,
    'asar_sha' => $shellTestAsarSha,
    'expected_digest' => $canonicalShellDigestExpected,
];

$cases = [];

// =========================================================================
// SECTION 1: POSITIVE VALID CASES
// =========================================================================

// Case 1: Valid Stable Progression 1.0.0 -> 1.0.1
$srcInvStable = $makeSyntheticInventory('1.0.0', '1.0.0', hash('sha256', 'src_stable_exe'), 10240, hash('sha256', 'src_stable_asar'), 20480);
$tgtInvStable = $makeSyntheticInventory('1.0.1', '1.0.1', hash('sha256', 'tgt_stable_exe'), 10300, hash('sha256', 'tgt_stable_asar'), 20500);
$descStable = $makeDescriptor($srcInvStable, $tgtInvStable, 'stable', 'stable', '1.0.0', '1.0.1', '1.0.0', '1.0.1', $baseDataContract);
$valCheck = $manifestService->validateStructural($descStable);
if (!$valCheck['ok']) {
    throw new RuntimeException('Baseline stable descriptor failed validateStructural: ' . ($valCheck['reason_code'] ?? 'unknown'));
}
[$rawStable, $sigStable, $shaStable] = $encodeAndSign($descStable, $ephemeralKey);

$cases[] = [
    'id' => 'valid_stable',
    'group' => 'positive_valid',
    'description' => 'Valid stable release progression 1.0.0 -> 1.0.1',
    'raw_manifest_base64' => base64_encode($rawStable),
    'signature_base64' => $sigStable,
    'expected_ok' => true,
    'expected_reason_code' => null,
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaStable,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Case 2: Valid Beta Progression 0.0.12-beta.1 -> 0.0.14-beta.1
$srcInvBeta = $makeSyntheticInventory('0.0.12-beta.1', '0.0.13', hash('sha256', 'src_beta_exe'), 11000, hash('sha256', 'src_beta_asar'), 21000);
$tgtInvBeta = $makeSyntheticInventory('0.0.14-beta.1', '0.0.14', hash('sha256', 'tgt_beta_exe'), 11500, hash('sha256', 'tgt_beta_asar'), 21500);
$descBeta = $makeDescriptor($srcInvBeta, $tgtInvBeta, 'beta', 'beta', '0.0.12-beta.1', '0.0.14-beta.1', '0.0.13', '0.0.14', $baseDataContract);
$valCheck = $manifestService->validateStructural($descBeta);
if (!$valCheck['ok']) {
    throw new RuntimeException('Baseline beta descriptor failed validateStructural: ' . ($valCheck['reason_code'] ?? 'unknown'));
}
[$rawBeta, $sigBeta, $shaBeta] = $encodeAndSign($descBeta, $ephemeralKey);

$cases[] = [
    'id' => 'valid_beta',
    'group' => 'positive_valid',
    'description' => 'Valid beta release progression 0.0.12-beta.1 -> 0.0.14-beta.1',
    'raw_manifest_base64' => base64_encode($rawBeta),
    'signature_base64' => $sigBeta,
    'expected_ok' => true,
    'expected_reason_code' => null,
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '0.0.12-beta.1',
            'channel' => 'beta',
            'native_version' => '0.0.13',
            'inventory_sha256' => $descBeta['source_inventory_sha256'],
            'native_shell_sha256' => $descBeta['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '0.0.14-beta.1',
            'channel' => 'beta',
            'native_version' => '0.0.14',
            'inventory_sha256' => $descBeta['target_inventory_sha256'],
            'native_shell_sha256' => $descBeta['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBeta,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Case 3: Valid Beta Progression from 9 to 10 (0.0.9-beta.9 -> 0.0.9-beta.10)
$srcInvBeta9 = $makeSyntheticInventory('0.0.9-beta.9', '0.0.9', hash('sha256', 'src_b9_exe'), 10000, hash('sha256', 'src_b9_asar'), 20000);
$tgtInvBeta10 = $makeSyntheticInventory('0.0.9-beta.10', '0.0.10', hash('sha256', 'tgt_b10_exe'), 10100, hash('sha256', 'tgt_b10_asar'), 20100);
$descBeta9to10 = $makeDescriptor($srcInvBeta9, $tgtInvBeta10, 'beta', 'beta', '0.0.9-beta.9', '0.0.9-beta.10', '0.0.9', '0.0.10', $baseDataContract);
$valCheck = $manifestService->validateStructural($descBeta9to10);
if (!$valCheck['ok']) {
    throw new RuntimeException('Beta 9 to 10 descriptor failed validateStructural: ' . ($valCheck['reason_code'] ?? 'unknown'));
}
[$rawBeta9to10, $sigBeta9to10, $shaBeta9to10] = $encodeAndSign($descBeta9to10, $ephemeralKey);

$cases[] = [
    'id' => 'valid_beta_progression_9_to_10',
    'group' => 'positive_valid',
    'description' => 'Valid beta progression 0.0.9-beta.9 -> 0.0.9-beta.10 with length+ordinal numeric comparison',
    'raw_manifest_base64' => base64_encode($rawBeta9to10),
    'signature_base64' => $sigBeta9to10,
    'expected_ok' => true,
    'expected_reason_code' => null,
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '0.0.9-beta.9',
            'channel' => 'beta',
            'native_version' => '0.0.9',
            'inventory_sha256' => $descBeta9to10['source_inventory_sha256'],
            'native_shell_sha256' => $descBeta9to10['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '0.0.9-beta.10',
            'channel' => 'beta',
            'native_version' => '0.0.10',
            'inventory_sha256' => $descBeta9to10['target_inventory_sha256'],
            'native_shell_sha256' => $descBeta9to10['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBeta9to10,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Case 4: Valid Core Minor Progression from 9 to 10 (1.9.0 -> 1.10.0)
$srcInvCore9 = $makeSyntheticInventory('1.9.0', '1.9.0', hash('sha256', 'src_c9_exe'), 10000, hash('sha256', 'src_c9_asar'), 20000);
$tgtInvCore10 = $makeSyntheticInventory('1.10.0', '1.10.0', hash('sha256', 'tgt_c10_exe'), 10100, hash('sha256', 'tgt_c10_asar'), 20100);
$descCore9to10 = $makeDescriptor($srcInvCore9, $tgtInvCore10, 'stable', 'stable', '1.9.0', '1.10.0', '1.9.0', '1.10.0', $baseDataContract);
$valCheck = $manifestService->validateStructural($descCore9to10);
if (!$valCheck['ok']) {
    throw new RuntimeException('Core 9 to 10 descriptor failed validateStructural: ' . ($valCheck['reason_code'] ?? 'unknown'));
}
[$rawCore9to10, $sigCore9to10, $shaCore9to10] = $encodeAndSign($descCore9to10, $ephemeralKey);

$cases[] = [
    'id' => 'valid_core_progression_9_to_10',
    'group' => 'positive_valid',
    'description' => 'Valid core minor progression 1.9.0 -> 1.10.0 with length+ordinal numeric comparison',
    'raw_manifest_base64' => base64_encode($rawCore9to10),
    'signature_base64' => $sigCore9to10,
    'expected_ok' => true,
    'expected_reason_code' => null,
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.9.0',
            'channel' => 'stable',
            'native_version' => '1.9.0',
            'inventory_sha256' => $descCore9to10['source_inventory_sha256'],
            'native_shell_sha256' => $descCore9to10['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.10.0',
            'channel' => 'stable',
            'native_version' => '1.10.0',
            'inventory_sha256' => $descCore9to10['target_inventory_sha256'],
            'native_shell_sha256' => $descCore9to10['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaCore9to10,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Case 5: Valid Full PHP Wide Component Progression (Shared Huge Major, Small Patch 0->1)
$wideMajorSrc = '999999999999999999999999999999.0.0';
$wideMajorTgt = '999999999999999999999999999999.0.1';
$srcInvWide = $makeSyntheticInventory($wideMajorSrc, '1.0.0', hash('sha256', 'src_wide_exe'), 10000, hash('sha256', 'src_wide_asar'), 20000);
$tgtInvWide = $makeSyntheticInventory($wideMajorTgt, '1.0.0', hash('sha256', 'tgt_wide_exe'), 10100, hash('sha256', 'tgt_wide_asar'), 20100);
$descWide = $makeDescriptor($srcInvWide, $tgtInvWide, 'stable', 'stable', $wideMajorSrc, $wideMajorTgt, '1.0.0', '1.0.0', $baseDataContract);
$valCheck = $manifestService->validateStructural($descWide);
if (!$valCheck['ok']) {
    throw new RuntimeException('Wide descriptor failed validateStructural: ' . ($valCheck['reason_code'] ?? 'unknown'));
}
[$rawWide, $sigWide, $shaWide] = $encodeAndSign($descWide, $ephemeralKey);

$cases[] = [
    'id' => 'valid_wide_component_progression',
    'group' => 'positive_valid',
    'description' => 'Valid full PHP wide component progression with shared 30-digit major component',
    'raw_manifest_base64' => base64_encode($rawWide),
    'signature_base64' => $sigWide,
    'expected_ok' => true,
    'expected_reason_code' => null,
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => $wideMajorSrc,
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descWide['source_inventory_sha256'],
            'native_shell_sha256' => $descWide['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => $wideMajorTgt,
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descWide['target_inventory_sha256'],
            'native_shell_sha256' => $descWide['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaWide,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Case 6: Same Native Shell Allowed in Regular (backend/runtime-only change)
$sharedExeHash = hash('sha256', 'shared_unchanged_shell_exe');
$sharedAsarHash = hash('sha256', 'shared_unchanged_shell_asar');
$srcInvSameShell = $makeSyntheticInventory('1.0.0', '1.0.0', $sharedExeHash, 10000, $sharedAsarHash, 20000);
$tgtInvSameShell = $makeSyntheticInventory('1.0.1', '1.0.0', $sharedExeHash, 10000, $sharedAsarHash, 20000);
$descSameShell = $makeDescriptor($srcInvSameShell, $tgtInvSameShell, 'stable', 'stable', '1.0.0', '1.0.1', '1.0.0', '1.0.0', $baseDataContract);
$valCheck = $manifestService->validateStructural($descSameShell);
if (!$valCheck['ok']) {
    throw new RuntimeException('Same shell descriptor failed validateStructural: ' . ($valCheck['reason_code'] ?? 'unknown'));
}
[$rawSameShell, $sigSameShell, $shaSameShell] = $encodeAndSign($descSameShell, $ephemeralKey);

$cases[] = [
    'id' => 'valid_same_native_shell_regular',
    'group' => 'positive_valid',
    'description' => 'Valid regular update where app version advances but native shell is identical',
    'raw_manifest_base64' => base64_encode($rawSameShell),
    'signature_base64' => $sigSameShell,
    'expected_ok' => true,
    'expected_reason_code' => null,
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descSameShell['source_inventory_sha256'],
            'native_shell_sha256' => $descSameShell['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descSameShell['target_inventory_sha256'],
            'native_shell_sha256' => $descSameShell['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaSameShell,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Case 7: Metadata Property-Order Variation (JSON insertion order swapped)
$descReordered = $descStable;
$descReordered['data_compatibility'] = [
    'ledger_sha256' => $baseDataContract['ledger_sha256'],
    'schema_sha256' => $baseDataContract['schema_sha256'],
    'metadata_migration' => $baseDataContract['metadata_migration'],
    'format' => $baseDataContract['format'],
];
$descReordered['source_data_compatibility'] = [
    'schema_sha256' => $baseDataContract['schema_sha256'],
    'format' => $baseDataContract['format'],
    'ledger_sha256' => $baseDataContract['ledger_sha256'],
    'metadata_migration' => $baseDataContract['metadata_migration'],
];
$valCheck = $manifestService->validateStructural($descReordered);
if (!$valCheck['ok']) {
    throw new RuntimeException('Reordered descriptor failed validateStructural: ' . ($valCheck['reason_code'] ?? 'unknown'));
}
[$rawReordered, $sigReordered, $shaReordered] = $encodeAndSign($descReordered, $ephemeralKey);

$cases[] = [
    'id' => 'valid_metadata_property_order_variation',
    'group' => 'positive_valid',
    'description' => 'Valid descriptor with permuted JSON property ordering in data compatibility contract',
    'raw_manifest_base64' => base64_encode($rawReordered),
    'signature_base64' => $sigReordered,
    'expected_ok' => true,
    'expected_reason_code' => null,
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descReordered['source_inventory_sha256'],
            'native_shell_sha256' => $descReordered['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descReordered['target_inventory_sha256'],
            'native_shell_sha256' => $descReordered['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaReordered,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// =========================================================================
// SECTION 2: KNOWN ARCHITECTURAL LIMIT CASES (EXPLICIT BOUNDARY PROOFS)
// =========================================================================

// Limit Case 1: Extra Non-Shell Entry in Files Array (Outside 2 shell files)
// Binding engine does NOT perform full diff validation; accepts with CanApply=false
$descExtraDiffFile = $descStable;
$descExtraDiffFile['files'][] = [
    'path' => 'locales/fake_invalid.pak',
    'sha256' => hash('sha256', 'not_in_inventory'),
    'size' => 999999,
];
[$rawExtraDiffFile, $sigExtraDiffFile, $shaExtraDiffFile] = $encodeAndSign($descExtraDiffFile, $ephemeralKey);

$cases[] = [
    'id' => 'limit_signed_other_file_entry',
    'group' => 'limit_boundary',
    'description' => 'NATIVE BINDING ONLY LIMIT: Extra non-shell file in files array accepted with CanApply=false (full diff validation not performed)',
    'raw_manifest_base64' => base64_encode($rawExtraDiffFile),
    'signature_base64' => $sigExtraDiffFile,
    'expected_ok' => true,
    'expected_reason_code' => null,
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descExtraDiffFile['source_inventory_sha256'],
            'native_shell_sha256' => $descExtraDiffFile['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descExtraDiffFile['target_inventory_sha256'],
            'native_shell_sha256' => $descExtraDiffFile['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaExtraDiffFile,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Limit Case 2: Independent Native Huge 30-Digit Component Comparison
// (1.0.999... -> 1.0.1000...: Native compares length+ordinal without int/float overflow)
$hugeSrcVer = '1.0.999999999999999999999999999999';
$hugeTgtVer = '1.0.1000000000000000000000000000000';
$srcInvHuge = $makeSyntheticInventory($hugeSrcVer, '1.0.0', hash('sha256', 'src_huge_exe'), 10000, hash('sha256', 'src_huge_asar'), 20000);
$tgtInvHuge = $makeSyntheticInventory($hugeTgtVer, '1.0.0', hash('sha256', 'tgt_huge_exe'), 10100, hash('sha256', 'tgt_huge_asar'), 20100);
$descHuge = $makeDescriptor($srcInvHuge, $tgtInvHuge, 'stable', 'stable', $hugeSrcVer, $hugeTgtVer, '1.0.0', '1.0.0', $baseDataContract);
[$rawHuge, $sigHuge, $shaHuge] = $encodeAndSign($descHuge, $ephemeralKey);

$cases[] = [
    'id' => 'limit_native_huge_bounded_numeric_progression',
    'group' => 'limit_boundary',
    'description' => 'NATIVE BINDING ONLY LIMIT case NOT valid full PHP descriptor: 30-digit component progression accepted without int/float overflow (CanApply=false)',
    'raw_manifest_base64' => base64_encode($rawHuge),
    'signature_base64' => $sigHuge,
    'expected_ok' => true,
    'expected_reason_code' => null,
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => $hugeSrcVer,
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descHuge['source_inventory_sha256'],
            'native_shell_sha256' => $descHuge['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => $hugeTgtVer,
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descHuge['target_inventory_sha256'],
            'native_shell_sha256' => $descHuge['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaHuge,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Limit Case 3: Invalid Non-Shell Inventory Entry (Extra '../escape' path with negative size)
// Proves honest layer boundary: full path/inventory validation is NOT performed; CanApply=false
$descInvalidNonShell = $descStable;
$descInvalidNonShell['target_inventory']['files']['../escape'] = [
    'sha256' => 'bogus_hash_not_hex',
    'size' => -1,
];
[$rawInvalidNonShell, $sigInvalidNonShell, $shaInvalidNonShell] = $encodeAndSign($descInvalidNonShell, $ephemeralKey);

$cases[] = [
    'id' => 'limit_invalid_non_shell_inventory_entry',
    'group' => 'limit_boundary',
    'description' => 'NATIVE BINDING ONLY LIMIT case NOT valid full PHP descriptor: Invalid non-shell inventory entry accepted by pure binding with CanApply=false (full inventory/path proof not performed)',
    'raw_manifest_base64' => base64_encode($rawInvalidNonShell),
    'signature_base64' => $sigInvalidNonShell,
    'expected_ok' => true,
    'expected_reason_code' => null,
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descInvalidNonShell['source_inventory_sha256'],
            'native_shell_sha256' => $descInvalidNonShell['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descInvalidNonShell['target_inventory_sha256'],
            'native_shell_sha256' => $descInvalidNonShell['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaInvalidNonShell,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// =========================================================================
// SECTION 3: NEGATIVE SIGNED CASES
// NOTE: Every field-negative test updates context.expected_manifest_sha256
// to the SHA-256 of the mutated raw manifest so the raw pin check PASSES,
// and the specific field validation logic and reason code are exercised!
// =========================================================================

// Negative Case 1: Wrong Raw Pin (Deliberate Pin Mismatch)
$cases[] = [
    'id' => 'negative_raw_pin_mismatch',
    'group' => 'negative_pin',
    'description' => 'Context expected ManifestSha256 does not match envelope ManifestSha256',
    'raw_manifest_base64' => base64_encode($rawStable),
    'signature_base64' => $sigStable,
    'expected_ok' => false,
    'expected_reason_code' => 'manifest_sha256_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => str_repeat('0', 64), // Deliberately wrong pin
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 2: Pin Checked Before Fields (Wrong Pin + Missing Root Key => pin reason)
$descBadPinMissingKey = $descStable;
unset($descBadPinMissingKey['package']); // Missing descriptor key after the minimum authenticated envelope gates
[$rawBadPinMissingKey, $sigBadPinMissingKey, $shaBadPinMissingKey] = $encodeAndSign($descBadPinMissingKey, $ephemeralKey);

$cases[] = [
    'id' => 'negative_pin_checked_before_fields',
    'group' => 'negative_pin',
    'description' => 'Pin check occurs strictly before field inspection: wrong pin + missing root key produces manifest_sha256_mismatch',
    'raw_manifest_base64' => base64_encode($rawBadPinMissingKey),
    'signature_base64' => $sigBadPinMissingKey,
    'expected_ok' => false,
    'expected_reason_code' => 'manifest_sha256_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => str_repeat('f', 64), // Mismatched pin
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 3: Raw Signature Tamper (Verifier rejects before binding)
$tamperedRaw = $rawStable;
$tamperedRaw[strlen($tamperedRaw) - 2] = ($tamperedRaw[strlen($tamperedRaw) - 2] === ' ') ? "\t" : ' ';
$cases[] = [
    'id' => 'negative_raw_signature_tamper',
    'group' => 'negative_signature',
    'description' => 'Raw manifest byte modified after signing; verifier fails closed before binding',
    'raw_manifest_base64' => base64_encode($tamperedRaw),
    'signature_base64' => $sigStable,
    'expected_ok' => false,
    'expected_reason_code' => 'signature_rejected',
    'expected_verifier_rejected' => true,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => hash('sha256', $tamperedRaw),
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 4: Wrong Trusted Key ID (Signature verified by valid key, but context specifies different key ID)
$cases[] = [
    'id' => 'negative_wrong_trusted_key_id',
    'group' => 'negative_key',
    'description' => 'Envelope verified with valid trusted key but context expected_key_id does not match selected key',
    'raw_manifest_base64' => base64_encode($rawStable),
    'signature_base64' => $sigStable,
    'expected_ok' => false,
    'expected_reason_code' => 'trusted_key_id_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaStable,
        'expected_key_id' => 'other_expected_key_id',
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 5: Source App Version Mismatch with Context
$descBadSrcVer = $descStable;
$descBadSrcVer['from_version'] = '1.0.5';
[$rawBadSrcVer, $sigBadSrcVer, $shaBadSrcVer] = $encodeAndSign($descBadSrcVer, $ephemeralKey);

$cases[] = [
    'id' => 'negative_source_app_version_mismatch',
    'group' => 'negative_metadata',
    'description' => 'Descriptor from_version does not match context source app_version',
    'raw_manifest_base64' => base64_encode($rawBadSrcVer),
    'signature_base64' => $sigBadSrcVer,
    'expected_ok' => false,
    'expected_reason_code' => 'source_version_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBadSrcVer,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 6: Target App Version Mismatch with Context
$descBadTgtVer = $descStable;
$descBadTgtVer['version'] = '1.0.9';
[$rawBadTgtVer, $sigBadTgtVer, $shaBadTgtVer] = $encodeAndSign($descBadTgtVer, $ephemeralKey);

$cases[] = [
    'id' => 'negative_target_app_version_mismatch',
    'group' => 'negative_metadata',
    'description' => 'Descriptor version does not match context target app_version',
    'raw_manifest_base64' => base64_encode($rawBadTgtVer),
    'signature_base64' => $sigBadTgtVer,
    'expected_ok' => false,
    'expected_reason_code' => 'target_version_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBadTgtVer,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 7: Source Channel Mismatch with Context
$descBadSrcChan = $descStable;
$descBadSrcChan['from_channel'] = 'beta';
[$rawBadSrcChan, $sigBadSrcChan, $shaBadSrcChan] = $encodeAndSign($descBadSrcChan, $ephemeralKey);

$cases[] = [
    'id' => 'negative_source_channel_mismatch',
    'group' => 'negative_channel',
    'description' => 'Descriptor from_channel does not match context source channel',
    'raw_manifest_base64' => base64_encode($rawBadSrcChan),
    'signature_base64' => $sigBadSrcChan,
    'expected_ok' => false,
    'expected_reason_code' => 'channel_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBadSrcChan,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 8: Source Native Version Mismatch
$descBadSrcNat = $descStable;
$descBadSrcNat['from_native_version'] = '1.0.9';
[$rawBadSrcNat, $sigBadSrcNat, $shaBadSrcNat] = $encodeAndSign($descBadSrcNat, $ephemeralKey);

$cases[] = [
    'id' => 'negative_source_native_version_mismatch',
    'group' => 'negative_metadata',
    'description' => 'Descriptor from_native_version does not match context source native_version',
    'raw_manifest_base64' => base64_encode($rawBadSrcNat),
    'signature_base64' => $sigBadSrcNat,
    'expected_ok' => false,
    'expected_reason_code' => 'source_native_version_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBadSrcNat,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 9: Target Native Version Mismatch
$descBadTgtNat = $descStable;
$descBadTgtNat['native_version'] = '1.0.9';
[$rawBadTgtNat, $sigBadTgtNat, $shaBadTgtNat] = $encodeAndSign($descBadTgtNat, $ephemeralKey);

$cases[] = [
    'id' => 'negative_target_native_version_mismatch',
    'group' => 'negative_metadata',
    'description' => 'Descriptor native_version does not match context target native_version',
    'raw_manifest_base64' => base64_encode($rawBadTgtNat),
    'signature_base64' => $sigBadTgtNat,
    'expected_ok' => false,
    'expected_reason_code' => 'target_native_version_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBadTgtNat,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 10: Source Inventory SHA-256 Mismatch
$descBadSrcInvSha = $descStable;
$descBadSrcInvSha['source_inventory_sha256'] = hash('sha256', 'tampered_source_inventory_digest');
[$rawBadSrcInvSha, $sigBadSrcInvSha, $shaBadSrcInvSha] = $encodeAndSign($descBadSrcInvSha, $ephemeralKey);

$cases[] = [
    'id' => 'negative_source_inventory_sha256_mismatch',
    'group' => 'negative_metadata',
    'description' => 'Descriptor source_inventory_sha256 does not match context source inventory_sha256',
    'raw_manifest_base64' => base64_encode($rawBadSrcInvSha),
    'signature_base64' => $sigBadSrcInvSha,
    'expected_ok' => false,
    'expected_reason_code' => 'source_inventory_hash_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBadSrcInvSha,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 11: Target Inventory SHA-256 Mismatch
$descBadTgtInvSha = $descStable;
$descBadTgtInvSha['target_inventory_sha256'] = hash('sha256', 'tampered_target_inventory_digest');
[$rawBadTgtInvSha, $sigBadTgtInvSha, $shaBadTgtInvSha] = $encodeAndSign($descBadTgtInvSha, $ephemeralKey);

$cases[] = [
    'id' => 'negative_target_inventory_sha256_mismatch',
    'group' => 'negative_metadata',
    'description' => 'Descriptor target_inventory_sha256 does not match context target inventory_sha256',
    'raw_manifest_base64' => base64_encode($rawBadTgtInvSha),
    'signature_base64' => $sigBadTgtInvSha,
    'expected_ok' => false,
    'expected_reason_code' => 'target_inventory_hash_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBadTgtInvSha,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 12: Context Native Shell SHA Mismatch (All raw proofs consistent, but context SHA differs)
$descContextShellMismatch = $descStable;
[$rawContextShellMismatch, $sigContextShellMismatch, $shaContextShellMismatch] = $encodeAndSign($descContextShellMismatch, $ephemeralKey);

$cases[] = [
    'id' => 'negative_wrong_context_shell_sha',
    'group' => 'negative_shell',
    'description' => 'Descriptor proofs consistent but context target native_shell_sha256 differs',
    'raw_manifest_base64' => base64_encode($rawContextShellMismatch),
    'signature_base64' => $sigContextShellMismatch,
    'expected_ok' => false,
    'expected_reason_code' => 'native_shell_digest_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descContextShellMismatch['source_inventory_sha256'],
            'native_shell_sha256' => $descContextShellMismatch['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descContextShellMismatch['target_inventory_sha256'],
            'native_shell_sha256' => hash('sha256', 'tampered_context_shell_sha'),
        ],
        'expected_manifest_sha256' => $shaContextShellMismatch,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 13: Prerelease is String Instead of Typed Boolean
$descPrereleaseString = $descStable;
$descPrereleaseString['prerelease'] = 'false';
[$rawPrereleaseString, $sigPrereleaseString, $shaPrereleaseString] = $encodeAndSign($descPrereleaseString, $ephemeralKey);

$cases[] = [
    'id' => 'negative_prerelease_string',
    'group' => 'negative_type',
    'description' => 'prerelease field is string instead of typed boolean',
    'raw_manifest_base64' => base64_encode($rawPrereleaseString),
    'signature_base64' => $sigPrereleaseString,
    'expected_ok' => false,
    'expected_reason_code' => 'prerelease_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaPrereleaseString,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 14: Prerelease Boolean Mismatch (stable channel with prerelease: true)
$descPrereleaseMismatch = $descStable;
$descPrereleaseMismatch['prerelease'] = true;
[$rawPrereleaseMismatch, $sigPrereleaseMismatch, $shaPrereleaseMismatch] = $encodeAndSign($descPrereleaseMismatch, $ephemeralKey);

$cases[] = [
    'id' => 'negative_prerelease_bool_mismatch',
    'group' => 'negative_policy',
    'description' => 'stable channel descriptor has prerelease=true',
    'raw_manifest_base64' => base64_encode($rawPrereleaseMismatch),
    'signature_base64' => $sigPrereleaseMismatch,
    'expected_ok' => false,
    'expected_reason_code' => 'prerelease_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaPrereleaseMismatch,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 15: Root vs Inventory Version Contradiction
$descRootInvContradiction = $descStable;
$descRootInvContradiction['target_inventory']['version'] = '1.0.9';
[$rawRootInvContradiction, $sigRootInvContradiction, $shaRootInvContradiction] = $encodeAndSign($descRootInvContradiction, $ephemeralKey);

$cases[] = [
    'id' => 'negative_root_vs_inventory_app_version_contradiction',
    'group' => 'negative_inventory',
    'description' => 'Root version (1.0.1) contradicts target_inventory version (1.0.9)',
    'raw_manifest_base64' => base64_encode($rawRootInvContradiction),
    'signature_base64' => $sigRootInvContradiction,
    'expected_ok' => false,
    'expected_reason_code' => 'inventory_version_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaRootInvContradiction,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 16: Root vs Inventory Native Version Contradiction
$descRootInvNatContradiction = $descStable;
$descRootInvNatContradiction['target_inventory']['native_version'] = '1.0.9';
[$rawRootInvNatContradiction, $sigRootInvNatContradiction, $shaRootInvNatContradiction] = $encodeAndSign($descRootInvNatContradiction, $ephemeralKey);

$cases[] = [
    'id' => 'negative_root_vs_inventory_native_version_contradiction',
    'group' => 'negative_inventory',
    'description' => 'Root native_version (1.0.1) contradicts target_inventory native_version (1.0.9)',
    'raw_manifest_base64' => base64_encode($rawRootInvNatContradiction),
    'signature_base64' => $sigRootInvNatContradiction,
    'expected_ok' => false,
    'expected_reason_code' => 'inventory_native_version_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaRootInvNatContradiction,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 17: Malformed Shell Entry Size (string instead of integer)
$descBadShellSize = $descStable;
$descBadShellSize['target_inventory']['native_shell']['files']['POS Desktop.exe']['size'] = '10300';
[$rawBadShellSize, $sigBadShellSize, $shaBadShellSize] = $encodeAndSign($descBadShellSize, $ephemeralKey);

$cases[] = [
    'id' => 'negative_shell_entry_size_malformed',
    'group' => 'negative_shell',
    'description' => 'Shell entry size is a string instead of an integer',
    'raw_manifest_base64' => base64_encode($rawBadShellSize),
    'signature_base64' => $sigBadShellSize,
    'expected_ok' => false,
    'expected_reason_code' => 'native_shell_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBadShellSize,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 18: Shell Entry Size Negative (-1)
$descNegShellSize = $descStable;
$descNegShellSize['target_inventory']['native_shell']['files']['POS Desktop.exe']['size'] = -1;
[$rawNegShellSize, $sigNegShellSize, $shaNegShellSize] = $encodeAndSign($descNegShellSize, $ephemeralKey);

$cases[] = [
    'id' => 'negative_shell_entry_size_negative',
    'group' => 'negative_shell',
    'description' => 'Shell entry size is negative (-1)',
    'raw_manifest_base64' => base64_encode($rawNegShellSize),
    'signature_base64' => $sigNegShellSize,
    'expected_ok' => false,
    'expected_reason_code' => 'native_shell_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaNegShellSize,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 19: Shell Entry Size Exceeds MAX_SAFE_INTEGER (9007199254740992)
$descOverflowShellSize = $descStable;
$descOverflowShellSize['target_inventory']['native_shell']['files']['POS Desktop.exe']['size'] = 9007199254740992;
[$rawOverflowShellSize, $sigOverflowShellSize, $shaOverflowShellSize] = $encodeAndSign($descOverflowShellSize, $ephemeralKey);

$cases[] = [
    'id' => 'negative_shell_entry_size_exceeds_safe_integer',
    'group' => 'negative_shell',
    'description' => 'Shell entry size exceeds MAX_SAFE_INTEGER (9007199254740991)',
    'raw_manifest_base64' => base64_encode($rawOverflowShellSize),
    'signature_base64' => $sigOverflowShellSize,
    'expected_ok' => false,
    'expected_reason_code' => 'native_shell_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaOverflowShellSize,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 20: Malformed Shell Hash (uppercase hex characters)
$descBadShellHash = $descStable;
$descBadShellHash['target_inventory']['native_shell']['files']['POS Desktop.exe']['sha256'] = strtoupper($descStable['target_inventory']['native_shell']['files']['POS Desktop.exe']['sha256']);
[$rawBadShellHash, $sigBadShellHash, $shaBadShellHash] = $encodeAndSign($descBadShellHash, $ephemeralKey);

$cases[] = [
    'id' => 'negative_shell_entry_hash_malformed',
    'group' => 'negative_shell',
    'description' => 'Shell entry sha256 contains uppercase hex characters',
    'raw_manifest_base64' => base64_encode($rawBadShellHash),
    'signature_base64' => $sigBadShellHash,
    'expected_ok' => false,
    'expected_reason_code' => 'native_shell_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBadShellHash,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 21: Shell Recomputed Digest Mismatch
$descBadShellDigest = $descStable;
$descBadShellDigest['target_inventory']['native_shell']['sha256'] = hash('sha256', 'tampered_canonical_shell_digest');
[$rawBadShellDigest, $sigBadShellDigest, $shaBadShellDigest] = $encodeAndSign($descBadShellDigest, $ephemeralKey);

$cases[] = [
    'id' => 'negative_shell_digest_recompute_mismatch',
    'group' => 'negative_shell',
    'description' => 'native_shell sha256 does not match canonical recomputed formula',
    'raw_manifest_base64' => base64_encode($rawBadShellDigest),
    'signature_base64' => $sigBadShellDigest,
    'expected_ok' => false,
    'expected_reason_code' => 'native_shell_digest_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBadShellDigest,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 22: Inventory Files vs Native Shell File Mismatch
$descShellFileMismatch = $descStable;
$descShellFileMismatch['target_inventory']['files']['POS Desktop.exe']['size'] = 99999;
[$rawShellFileMismatch, $sigShellFileMismatch, $shaShellFileMismatch] = $encodeAndSign($descShellFileMismatch, $ephemeralKey);

$cases[] = [
    'id' => 'negative_inventory_files_shell_mismatch',
    'group' => 'negative_shell',
    'description' => 'target_inventory.files POS Desktop.exe size does not match native_shell.files POS Desktop.exe size',
    'raw_manifest_base64' => base64_encode($rawShellFileMismatch),
    'signature_base64' => $sigShellFileMismatch,
    'expected_ok' => false,
    'expected_reason_code' => 'native_shell_file_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaShellFileMismatch,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 23: Extra Key in Shell File Inventory Entry
$descShellExtraKey = $descStable;
$descShellExtraKey['target_inventory']['files']['POS Desktop.exe']['unauthorized_key'] = 'malicious';
[$rawShellExtraKey, $sigShellExtraKey, $shaShellExtraKey] = $encodeAndSign($descShellExtraKey, $ephemeralKey);

$cases[] = [
    'id' => 'negative_shell_inventory_extra_meta_key',
    'group' => 'negative_shell',
    'description' => 'target_inventory.files POS Desktop.exe contains unauthorized extra metadata key',
    'raw_manifest_base64' => base64_encode($rawShellExtraKey),
    'signature_base64' => $sigShellExtraKey,
    'expected_ok' => false,
    'expected_reason_code' => 'native_shell_file_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaShellExtraKey,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 24: Root Shell Proof vs Inventory Shell Proof Mismatch
$descRootShellMismatch = $descStable;
$descRootShellMismatch['target_native_shell']['files']['POS Desktop.exe']['size'] = 99999;
[$rawRootShellMismatch, $sigRootShellMismatch, $shaRootShellMismatch] = $encodeAndSign($descRootShellMismatch, $ephemeralKey);

$cases[] = [
    'id' => 'negative_root_shell_proof_mismatch',
    'group' => 'negative_shell',
    'description' => 'Root target_native_shell does not match target_inventory.native_shell',
    'raw_manifest_base64' => base64_encode($rawRootShellMismatch),
    'signature_base64' => $sigRootShellMismatch,
    'expected_ok' => false,
    'expected_reason_code' => 'shell_proof_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaRootShellMismatch,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 25: Missing Required Physical File in Inventory
$descMissingReqFile = $descStable;
unset($descMissingReqFile['target_inventory']['files']['resources/app.asar.unpacked/version.json']);
[$rawMissingReqFile, $sigMissingReqFile, $shaMissingReqFile] = $encodeAndSign($descMissingReqFile, $ephemeralKey);

$cases[] = [
    'id' => 'negative_inventory_missing_required_file',
    'group' => 'negative_inventory',
    'description' => 'target_inventory.files is missing required physical file resources/app.asar.unpacked/version.json',
    'raw_manifest_base64' => base64_encode($rawMissingReqFile),
    'signature_base64' => $sigMissingReqFile,
    'expected_ok' => false,
    'expected_reason_code' => 'inventory_missing_required_file',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaMissingReqFile,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 26: Channel Retired ('rc' channel rejected)
$descRetiredChannel = $descStable;
$descRetiredChannel['from_channel'] = 'rc';
$descRetiredChannel['channel'] = 'rc';
[$rawRetiredChannel, $sigRetiredChannel, $shaRetiredChannel] = $encodeAndSign($descRetiredChannel, $ephemeralKey);

$cases[] = [
    'id' => 'negative_channel_retired',
    'group' => 'negative_channel',
    'description' => 'Descriptor declares retired channel rc instead of active stable/beta',
    'raw_manifest_base64' => base64_encode($rawRetiredChannel),
    'signature_base64' => $sigRetiredChannel,
    'expected_ok' => false,
    'expected_reason_code' => 'channel_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaRetiredChannel,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 27: Downgrade Rejected (from 1.0.1 to 1.0.0)
$descDowngrade = $makeDescriptor($tgtInvStable, $srcInvStable, 'stable', 'stable', '1.0.1', '1.0.0', '1.0.1', '1.0.0', $baseDataContract);
[$rawDowngrade, $sigDowngrade, $shaDowngrade] = $encodeAndSign($descDowngrade, $ephemeralKey);

$cases[] = [
    'id' => 'negative_downgrade',
    'group' => 'negative_progression',
    'description' => 'Target app version (1.0.0) is older than source app version (1.0.1); downgrade rejected',
    'raw_manifest_base64' => base64_encode($rawDowngrade),
    'signature_base64' => $sigDowngrade,
    'expected_ok' => false,
    'expected_reason_code' => 'downgrade_rejected',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descDowngrade['source_inventory_sha256'],
            'native_shell_sha256' => $descDowngrade['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descDowngrade['target_inventory_sha256'],
            'native_shell_sha256' => $descDowngrade['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaDowngrade,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 28: Equal Version Progression Rejected (1.0.0 -> 1.0.0)
$descEqualVer = $makeDescriptor($srcInvStable, $srcInvStable, 'stable', 'stable', '1.0.0', '1.0.0', '1.0.0', '1.0.0', $baseDataContract);
[$rawEqualVer, $sigEqualVer, $shaEqualVer] = $encodeAndSign($descEqualVer, $ephemeralKey);

$cases[] = [
    'id' => 'negative_equal_version',
    'group' => 'negative_progression',
    'description' => 'Target app version (1.0.0) equals source app version (1.0.0); progression rejected',
    'raw_manifest_base64' => base64_encode($rawEqualVer),
    'signature_base64' => $sigEqualVer,
    'expected_ok' => false,
    'expected_reason_code' => 'downgrade_rejected',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descEqualVer['source_inventory_sha256'],
            'native_shell_sha256' => $descEqualVer['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descEqualVer['target_inventory_sha256'],
            'native_shell_sha256' => $descEqualVer['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaEqualVer,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 29: Beta Downgrade Rejected (0.0.14-beta.2 -> 0.0.14-beta.1)
$srcInvBeta2 = $makeSyntheticInventory('0.0.14-beta.2', '0.0.14', hash('sha256', 'b2_exe'), 10000, hash('sha256', 'b2_asar'), 20000);
$tgtInvBeta1 = $makeSyntheticInventory('0.0.14-beta.1', '0.0.14', hash('sha256', 'b1_exe'), 10000, hash('sha256', 'b1_asar'), 20000);
$descBetaDowngrade = $makeDescriptor($srcInvBeta2, $tgtInvBeta1, 'beta', 'beta', '0.0.14-beta.2', '0.0.14-beta.1', '0.0.14', '0.0.14', $baseDataContract);
[$rawBetaDowngrade, $sigBetaDowngrade, $shaBetaDowngrade] = $encodeAndSign($descBetaDowngrade, $ephemeralKey);

$cases[] = [
    'id' => 'negative_beta_downgrade',
    'group' => 'negative_progression',
    'description' => 'Beta version downgrade from 0.0.14-beta.2 to 0.0.14-beta.1 rejected',
    'raw_manifest_base64' => base64_encode($rawBetaDowngrade),
    'signature_base64' => $sigBetaDowngrade,
    'expected_ok' => false,
    'expected_reason_code' => 'downgrade_rejected',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '0.0.14-beta.2',
            'channel' => 'beta',
            'native_version' => '0.0.14',
            'inventory_sha256' => $descBetaDowngrade['source_inventory_sha256'],
            'native_shell_sha256' => $descBetaDowngrade['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '0.0.14-beta.1',
            'channel' => 'beta',
            'native_version' => '0.0.14',
            'inventory_sha256' => $descBetaDowngrade['target_inventory_sha256'],
            'native_shell_sha256' => $descBetaDowngrade['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBetaDowngrade,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 30: Unsupported Purpose (bootstrap_completion without bootstrap_link rejected)
$descBootstrap = $descStable;
$descBootstrap['purpose'] = 'bootstrap_completion';
[$rawBootstrap, $sigBootstrap, $shaBootstrap] = $encodeAndSign($descBootstrap, $ephemeralKey);

$cases[] = [
    'id' => 'negative_unsupported_purpose_bootstrap',
    'group' => 'negative_policy',
    'description' => 'Purpose bootstrap_completion is rejected; only regular supported',
    'raw_manifest_base64' => base64_encode($rawBootstrap),
    'signature_base64' => $sigBootstrap,
    'expected_ok' => false,
    'expected_reason_code' => 'purpose_unsupported',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBootstrap,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 31: Bootstrap Completion WITH bootstrap_link (26 keys rejected by descriptor_keys_invalid)
$descBootstrapWithLink = $descStable;
$descBootstrapWithLink['purpose'] = 'bootstrap_completion';
$descBootstrapWithLink['bootstrap_link'] = hash('sha256', 'dummy_bootstrap_link');
[$rawBootstrapWithLink, $sigBootstrapWithLink, $shaBootstrapWithLink] = $encodeAndSign($descBootstrapWithLink, $ephemeralKey);

$cases[] = [
    'id' => 'negative_bootstrap_completion_with_bootstrap_link',
    'group' => 'negative_descriptor',
    'description' => 'Descriptor purpose bootstrap_completion with bootstrap_link contains 26 keys; rejected by descriptor_keys_invalid',
    'raw_manifest_base64' => base64_encode($rawBootstrapWithLink),
    'signature_base64' => $sigBootstrapWithLink,
    'expected_ok' => false,
    'expected_reason_code' => 'descriptor_keys_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBootstrapWithLink,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 32: Unexpected bootstrap_link key in regular descriptor
$descUnexpectedLink = $descStable;
$descUnexpectedLink['bootstrap_link'] = hash('sha256', 'unexpected_link');
[$rawUnexpectedLink, $sigUnexpectedLink, $shaUnexpectedLink] = $encodeAndSign($descUnexpectedLink, $ephemeralKey);

$cases[] = [
    'id' => 'negative_unexpected_bootstrap_link',
    'group' => 'negative_descriptor',
    'description' => 'Regular descriptor contains unexpected bootstrap_link key',
    'raw_manifest_base64' => base64_encode($rawUnexpectedLink),
    'signature_base64' => $sigUnexpectedLink,
    'expected_ok' => false,
    'expected_reason_code' => 'descriptor_keys_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaUnexpectedLink,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 33: Extra Descriptor Key
$descExtraKey = $descStable;
$descExtraKey['unauthorized_extra_field'] = 'malicious';
[$rawExtraKey, $sigExtraKey, $shaExtraKey] = $encodeAndSign($descExtraKey, $ephemeralKey);

$cases[] = [
    'id' => 'negative_extra_descriptor_key',
    'group' => 'negative_descriptor',
    'description' => 'Descriptor contains unauthorized extra key',
    'raw_manifest_base64' => base64_encode($rawExtraKey),
    'signature_base64' => $sigExtraKey,
    'expected_ok' => false,
    'expected_reason_code' => 'descriptor_keys_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaExtraKey,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 34: Missing Descriptor Key (missing migrations)
$descMissingKey = $descStable;
unset($descMissingKey['migrations']);
[$rawMissingKey, $sigMissingKey, $shaMissingKey] = $encodeAndSign($descMissingKey, $ephemeralKey);

$cases[] = [
    'id' => 'negative_missing_descriptor_key',
    'group' => 'negative_descriptor',
    'description' => 'Descriptor is missing mandatory key migrations',
    'raw_manifest_base64' => base64_encode($rawMissingKey),
    'signature_base64' => $sigMissingKey,
    'expected_ok' => false,
    'expected_reason_code' => 'descriptor_keys_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaMissingKey,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 35: Files is Object Instead of Array
$descFilesObj = $descStable;
$descFilesObj['files'] = (object)[];
[$rawFilesObj, $sigFilesObj, $shaFilesObj] = $encodeAndSign($descFilesObj, $ephemeralKey);

$cases[] = [
    'id' => 'negative_files_object_instead_of_array',
    'group' => 'negative_descriptor',
    'description' => 'Descriptor files field is an object {} instead of an array []',
    'raw_manifest_base64' => base64_encode($rawFilesObj),
    'signature_base64' => $sigFilesObj,
    'expected_ok' => false,
    'expected_reason_code' => 'files_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaFilesObj,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 36: Nonempty Migrations Array
$descNonemptyMigrations = $descStable;
$descNonemptyMigrations['migrations'] = ['060_unauthorized_migration.sql'];
[$rawNonemptyMigrations, $sigNonemptyMigrations, $shaNonemptyMigrations] = $encodeAndSign($descNonemptyMigrations, $ephemeralKey);

$cases[] = [
    'id' => 'negative_nonempty_migrations',
    'group' => 'negative_migrations',
    'description' => 'Descriptor has non-empty migrations array; runtime migrations are unsupported',
    'raw_manifest_base64' => base64_encode($rawNonemptyMigrations),
    'signature_base64' => $sigNonemptyMigrations,
    'expected_ok' => false,
    'expected_reason_code' => 'migrations_unsupported',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaNonemptyMigrations,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 37: Migrations is Object Instead of Array
$descMigrationsObj = $descStable;
$descMigrationsObj['migrations'] = (object)[];
[$rawMigrationsObj, $sigMigrationsObj, $shaMigrationsObj] = $encodeAndSign($descMigrationsObj, $ephemeralKey);

$cases[] = [
    'id' => 'negative_migrations_object_instead_of_array',
    'group' => 'negative_migrations',
    'description' => 'Descriptor migrations field is an object {} instead of array []',
    'raw_manifest_base64' => base64_encode($rawMigrationsObj),
    'signature_base64' => $sigMigrationsObj,
    'expected_ok' => false,
    'expected_reason_code' => 'migrations_unsupported',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaMigrationsObj,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 38: Mutated Data Contract (Target schema differs from Source schema)
$descDataMismatch = $descStable;
$descDataMismatch['data_compatibility']['schema_sha256'] = hash('sha256', 'mutated_target_schema');
[$rawDataMismatch, $sigDataMismatch, $shaDataMismatch] = $encodeAndSign($descDataMismatch, $ephemeralKey);

$cases[] = [
    'id' => 'negative_changed_source_target_data_contract',
    'group' => 'negative_data_contract',
    'description' => 'target data_compatibility schema does not match source_data_compatibility',
    'raw_manifest_base64' => base64_encode($rawDataMismatch),
    'signature_base64' => $sigDataMismatch,
    'expected_ok' => false,
    'expected_reason_code' => 'data_contract_mismatch',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaDataMismatch,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 39: Invalid Metadata Migration Name
$descBadMetaMigration = $descStable;
$descBadMetaMigration['data_compatibility']['metadata_migration'] = '060_unauthorized_migration.sql';
$descBadMetaMigration['source_data_compatibility']['metadata_migration'] = '060_unauthorized_migration.sql';
[$rawBadMetaMigration, $sigBadMetaMigration, $shaBadMetaMigration] = $encodeAndSign($descBadMetaMigration, $ephemeralKey);

$cases[] = [
    'id' => 'negative_changed_data_metadata_migration',
    'group' => 'negative_data_contract',
    'description' => 'data_compatibility metadata_migration is not 059_add_update_compatibility_metadata.sql',
    'raw_manifest_base64' => base64_encode($rawBadMetaMigration),
    'signature_base64' => $sigBadMetaMigration,
    'expected_ok' => false,
    'expected_reason_code' => 'data_contract_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaBadMetaMigration,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Negative Case 40: Typed Bad in source_data_compatibility (schema_sha256 is integer)
$descSrcDataTypedBad = $descStable;
$descSrcDataTypedBad['source_data_compatibility']['schema_sha256'] = 12345;
[$rawSrcDataTypedBad, $sigSrcDataTypedBad, $shaSrcDataTypedBad] = $encodeAndSign($descSrcDataTypedBad, $ephemeralKey);

$cases[] = [
    'id' => 'negative_source_data_compatibility_typed_bad',
    'group' => 'negative_data_contract',
    'description' => 'source_data_compatibility schema_sha256 is an integer instead of hex string',
    'raw_manifest_base64' => base64_encode($rawSrcDataTypedBad),
    'signature_base64' => $sigSrcDataTypedBad,
    'expected_ok' => false,
    'expected_reason_code' => 'source_data_contract_invalid',
    'expected_verifier_rejected' => false,
    'context' => [
        'source' => [
            'app_version' => '1.0.0',
            'channel' => 'stable',
            'native_version' => '1.0.0',
            'inventory_sha256' => $descStable['source_inventory_sha256'],
            'native_shell_sha256' => $descStable['source_native_shell']['sha256'],
        ],
        'target' => [
            'app_version' => '1.0.1',
            'channel' => 'stable',
            'native_version' => '1.0.1',
            'inventory_sha256' => $descStable['target_inventory_sha256'],
            'native_shell_sha256' => $descStable['target_native_shell']['sha256'],
        ],
        'expected_manifest_sha256' => $shaSrcDataTypedBad,
        'expected_key_id' => $keyId,
        'data_contract' => $baseDataContract,
    ],
];

// Additional Data Compatibility Negative Cases using compact cloning of baseline stable context
$makeNegativeDataContractCase = static function (
    string $id,
    string $description,
    string $expectedReasonCode,
    callable $mutateDescriptor
) use ($descStable, $cases, $encodeAndSign, $ephemeralKey): array {
    $desc = $descStable;
    $mutateDescriptor($desc);
    [$raw, $sig, $sha] = $encodeAndSign($desc, $ephemeralKey);
    $caseContext = $cases[0]['context']; // clone baseline stable context (copy on write)
    $caseContext['expected_manifest_sha256'] = $sha;

    return [
        'id' => $id,
        'group' => 'negative_data_contract',
        'description' => $description,
        'raw_manifest_base64' => base64_encode($raw),
        'signature_base64' => $sig,
        'expected_ok' => false,
        'expected_reason_code' => $expectedReasonCode,
        'expected_verifier_rejected' => false,
        'context' => $caseContext,
    ];
};

$dataContractNegatives = [
    [
        'id' => 'negative_source_data_compatibility_schema_different_hex',
        'description' => 'source_data_compatibility schema_sha256 is valid 64-hex but differs from context data contract schema',
        'expected_reason_code' => 'data_contract_mismatch',
        'mutator' => static function (array &$d): void {
            $d['source_data_compatibility']['schema_sha256'] = hash('sha256', 'different_valid_source_schema_sha256');
        },
    ],
    [
        'id' => 'negative_source_data_compatibility_ledger_different_hex',
        'description' => 'source_data_compatibility ledger_sha256 is valid 64-hex but differs from context data contract ledger',
        'expected_reason_code' => 'data_contract_mismatch',
        'mutator' => static function (array &$d): void {
            $d['source_data_compatibility']['ledger_sha256'] = hash('sha256', 'different_valid_source_ledger_sha256');
        },
    ],
    [
        'id' => 'negative_source_data_compatibility_metadata_migration_wrong',
        'description' => 'source_data_compatibility metadata_migration does not match required metadata migration',
        'expected_reason_code' => 'source_data_contract_invalid',
        'mutator' => static function (array &$d): void {
            $d['source_data_compatibility']['metadata_migration'] = '060_wrong_migration.sql';
        },
    ],
    [
        'id' => 'negative_target_data_compatibility_format_string',
        'description' => 'target data_compatibility format is string "1" instead of integer',
        'expected_reason_code' => 'data_contract_invalid',
        'mutator' => static function (array &$d): void {
            $d['data_compatibility']['format'] = '1';
        },
    ],
];

foreach ($dataContractNegatives as $neg) {
    $cases[] = $makeNegativeDataContractCase($neg['id'], $neg['description'], $neg['expected_reason_code'], $neg['mutator']);
}

// 4. SemVer Golden Ordering Comparison Vectors (Mathematical vs PHP version_compare)
$compareMath = static function (string $source, string $target): bool {
    $parse = static function (string $v): ?array {
        $marker = '-beta.';
        $bIdx = strpos($v, $marker);
        if ($bIdx !== false) {
            $core = substr($v, 0, $bIdx);
            $beta = substr($v, $bIdx + strlen($marker));
            $parts = explode('.', $core);
            if (count($parts) !== 3) return null;
            return ['major' => $parts[0], 'minor' => $parts[1], 'patch' => $parts[2], 'is_beta' => true, 'beta' => $beta];
        }
        if (strpos($v, '-') !== false || strpos($v, '+') !== false) return null;
        $parts = explode('.', $v);
        if (count($parts) !== 3) return null;
        return ['major' => $parts[0], 'minor' => $parts[1], 'patch' => $parts[2], 'is_beta' => false, 'beta' => null];
    };
    $cmpNum = static function (string $a, string $b): int {
        if (strlen($a) !== strlen($b)) return strlen($a) <=> strlen($b);
        return strcmp($a, $b);
    };
    $pS = $parse($source);
    $pT = $parse($target);
    if ($pS === null || $pT === null) return false;
    $c = $cmpNum($pT['major'], $pS['major']);
    if ($c !== 0) return $c > 0;
    $c = $cmpNum($pT['minor'], $pS['minor']);
    if ($c !== 0) return $c > 0;
    $c = $cmpNum($pT['patch'], $pS['patch']);
    if ($c !== 0) return $c > 0;
    if ($pS['is_beta'] && $pT['is_beta']) {
        return $cmpNum($pT['beta'], $pS['beta']) > 0;
    }
    return false;
};

$goldenPairs = [
    ['1.0.0', '1.0.1'],
    ['1.0.1', '1.0.0'],
    ['1.0.0', '1.0.0'],
    ['0.0.9-beta.9', '0.0.9-beta.10'],
    ['0.0.9-beta.10', '0.0.9-beta.9'],
    ['1.9.0', '1.10.0'],
    ['1.10.0', '1.9.0'],
    ['0.0.12-beta.1', '0.0.14-beta.1'],
    ['1.0.0-beta.5', '1.0.1-beta.1'],
    ['1.0.999999999999999999999999999999', '1.0.1000000000000000000000000000000'],
];

$semverGolden = [];
foreach ($goldenPairs as [$s, $t]) {
    $mathStrictlyNewer = $compareMath($s, $t);
    $phpStrictlyNewer = version_compare($t, $s, '>');
    $semverGolden[] = [
        'source' => $s,
        'target' => $t,
        'expected_strictly_newer' => $mathStrictlyNewer,
        'php_actual_strictly_newer' => $phpStrictlyNewer,
        'is_wide_int' => (strlen($s) > 20 || strlen($t) > 20),
    ];
}

// 5. Assemble Final Fixture Document
$fixtureDoc = [
    'public_key' => [
        'key_id' => $keyId,
        'modulus_base64' => base64_encode($rawModulus),
        'exponent_base64' => base64_encode($rawExponent),
    ],
    'canonical_shell_golden' => $canonicalShellGolden,
    'semver_golden' => $semverGolden,
    'cases' => $cases,
];

// 6. Checked Exclusive Stream Write & Close to Destination Path
$targetDir = dirname($outputPath);
if (!is_dir($targetDir)) {
    fwrite(STDERR, "ERROR: Target directory does not exist: {$targetDir}\n");
    exit(1);
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

echo "Successfully wrote " . count($cases) . " test cases to {$outputPath} (" . strlen($fixtureJson) . " bytes).\n";
exit(0);
