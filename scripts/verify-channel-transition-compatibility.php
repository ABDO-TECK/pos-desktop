<?php
declare(strict_types=1);
require_once __DIR__.'/../backend/vendor/autoload.php';
require_once __DIR__.'/verify-transition-data.php';

use App\Services\{
    BackupService,
    ChannelTransitionManifestService,
    ChannelTransitionPreviewStore,
    ChannelTransitionReleaseCatalog,
    ChannelTransitionService,
    DeltaUpdateService,
    FrontendBuildService,
    GitHubReleaseProvider,
    GitService,
    ManifestSignatureService,
    UpdateChannelPolicy,
    UpdateDataCompatibilityService,
    UpdateService
};

function compatAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

class CompatTestReleaseProvider extends GitHubReleaseProvider
{
    /** @var array<string, array> */
    public array $releasesByRepoTag = [];
    /** @var array<string, array<array>> */
    public array $channelCandidates = [];
    public ?array $latestSetup = null;
    /** @var array<string, string> */
    public array $assets = [];
    public string $packagePath = '';

    public function getReleaseByTag(string $tag, string $repo = 'pos-desktop-updates'): array
    {
        $key = $repo . ':' . $tag;
        if (isset($this->releasesByRepoTag[$key])) {
            return ['ok' => true, 'release' => $this->releasesByRepoTag[$key]];
        }
        return ['ok' => false, 'http_code' => 404, 'error' => 'Tag not found: ' . $key];
    }

    public function listChannelCandidates(string $channel, int $page = 1): array
    {
        if ($page > 1) {
            return ['ok' => true, 'releases' => [], 'next_page' => null];
        }
        return ['ok' => true, 'releases' => $this->channelCandidates[$channel] ?? [], 'next_page' => null];
    }

    public function getLatestSetupRelease(?string $currentVersion = null): array
    {
        return ['ok' => true, 'release' => $this->latestSetup];
    }

    public function fetchAssetContent(string $url): array
    {
        if (isset($this->assets[$url])) {
            return ['ok' => true, 'content' => $this->assets[$url]];
        }
        return ['ok' => false, 'error' => 'Asset not found: ' . $url];
    }

    public function downloadAssetFile(string $url, string $destPath): array
    {
        if ($this->packagePath !== '' && file_exists($this->packagePath)) {
            copy($this->packagePath, $destPath);
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'No package file available'];
    }
}

function makeCompatRelease(string $repo, string $version, array $assetNames = []): array
{
    return [
        'tag_name' => 'v' . $version,
        'draft' => false,
        'prerelease' => str_contains($version, '-'),
        'assets' => array_map(static fn(string $name): array => [
            'name' => $name,
            'browser_download_url' => 'https://github.com/ABDO-TECK/' . $repo . '/releases/download/v' . $version . '/' . $name,
        ], $assetNames),
    ];
}

$lab = sys_get_temp_dir() . '/pos-compat-test-' . bin2hex(random_bytes(6));
$root = $lab . '/resources/app.asar.unpacked';
mkdir($root . '/backend', 0700, true);
mkdir($root . '/frontend/dist', 0700, true);
mkdir($lab . '/storage', 0700, true);

file_put_contents($lab . '/POS Desktop.exe', 'fixture shell executable binary');
file_put_contents($lab . '/resources/app.asar', 'fixture shell asar archive');
file_put_contents($root . '/version.json', json_encode(['version' => '0.0.11']));
file_put_contents($root . '/backend/backend.phar', 'fixture phar 0.0.11');
file_put_contents($root . '/frontend/dist/app.js', 'console.log("0.0.11");');

putenv('APP_DEPLOY_ROOT=' . $root);

$rsaKey = openssl_pkey_new(['private_key_bits' => 2048]);
$keyDetails = openssl_pkey_get_details($rsaKey);
$publicKeyPath = $lab . '/public.pem';
file_put_contents($publicKeyPath, $keyDetails['key']);

$sign = static function (array $data) use ($rsaKey): array {
    $raw = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    openssl_sign($raw, $signature, $rsaKey, OPENSSL_ALGO_SHA256);
    return [$raw, base64_encode($signature)];
};

$shellDigest = hash('sha256', json_encode([
    'POS Desktop.exe' => hash_file('sha256', $lab . '/POS Desktop.exe'),
    'resources/app.asar' => hash_file('sha256', $lab . '/resources/app.asar'),
], JSON_UNESCAPED_SLASHES));

$installedStable11Files = [
    'version.json' => ['sha256' => hash_file('sha256', $root . '/version.json'), 'size' => filesize($root . '/version.json')],
    'backend/backend.phar' => ['sha256' => hash_file('sha256', $root . '/backend/backend.phar'), 'size' => filesize($root . '/backend/backend.phar')],
    'frontend/dist/app.js' => ['sha256' => hash_file('sha256', $root . '/frontend/dist/app.js'), 'size' => filesize($root . '/frontend/dist/app.js')],
];
$inventoryStable11 = [
    'format' => 1,
    'version' => '0.0.11',
    'shell_sha256' => $shellDigest,
    'files' => $installedStable11Files,
];
$inventoryStable11Raw = json_encode($inventoryStable11, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$inventoryStable11Sha = hash('sha256', $inventoryStable11Raw);

$targetBeta10Files = [
    'version.json' => ['sha256' => hash('sha256', 'beta10-version'), 'size' => 30],
    'backend/backend.phar' => ['sha256' => hash('sha256', 'beta10-phar'), 'size' => 100],
    'frontend/dist/app.js' => ['sha256' => hash('sha256', 'beta10-js'), 'size' => 80],
];
$inventoryBeta10 = [
    'format' => 1,
    'version' => '0.0.10-beta.10',
    'shell_sha256' => $shellDigest,
    'files' => $targetBeta10Files,
];
$inventoryBeta10Raw = json_encode($inventoryBeta10, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$inventoryBeta10Sha = hash('sha256', $inventoryBeta10Raw);

$pdo = new CompatibilityDatabase();
$compatService = new UpdateDataCompatibilityService();
$description = $compatService->describe($pdo);
$contract = [
    'format' => 1,
    'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
    'schema_sha256' => $description['schema_sha256'],
    'ledger_sha256' => $description['ledger_sha256'],
];

// Build carrier descriptor and transition package on target v0.0.10-beta.10
$stem = 'transition-0.0.11-to-beta-0.0.10-beta.10-delta';
$descriptorBeta = [
    'format' => 1,
    'operation' => 'channel_transition',
    'carrier_tag' => 'v0.0.10-beta.10',
    'from_version' => '0.0.11',
    'from_channel' => 'stable',
    'source_inventory_sha256' => $inventoryStable11Sha,
    'source_inventory' => $inventoryStable11,
    'version' => '0.0.10-beta.10',
    'channel' => 'beta',
    'target_repo' => 'pos-desktop-updates',
    'target_tag' => 'v0.0.10-beta.10',
    'target_inventory_sha256' => $inventoryBeta10Sha,
    'target_inventory' => $inventoryBeta10,
    'shell_sha256' => $shellDigest,
    'type' => 'delta',
    'files' => array_map(static fn($p) => ['path' => $p, ...$targetBeta10Files[$p]], array_keys($targetBeta10Files)),
    'deleted_files' => [],
    'migrations' => [],
    'package' => ['asset' => $stem . '.zip', 'sha256' => str_repeat('d', 64), 'size' => 256],
    'data_compatibility' => $contract,
];
[$rawDescBeta, $sigDescBeta] = $sign($descriptorBeta);

$indexEntryBeta = [
    'from_version' => '0.0.11',
    'source_inventory_sha256' => $inventoryStable11Sha,
    'version' => '0.0.10-beta.10',
    'channel' => 'beta',
    'descriptor' => $stem . '.json',
    'signature' => $stem . '.sig',
    'descriptor_sha256' => hash('sha256', $rawDescBeta),
    'type' => 'delta',
];
[$rawIndexBeta, $sigIndexBeta] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.10', 'entries' => [$indexEntryBeta]]);

$provider = new CompatTestReleaseProvider();

$setSetupRelease = static function (?array $release) use ($provider): void {
    if ($release === null) {
        unset($provider->releasesByRepoTag['pos-desktop:v0.0.1']);
        $provider->latestSetup = null;
    } else {
        $provider->releasesByRepoTag['pos-desktop:v0.0.1'] = $release;
        $provider->latestSetup = $release;
    }
};

// Source release: v0.0.11 on pos-desktop
$releaseStable11 = makeCompatRelease('pos-desktop', '0.0.11', ['deployment-inventory.json']);
$provider->releasesByRepoTag['pos-desktop:v0.0.11'] = $releaseStable11;
$provider->latestSetup = $releaseStable11;

// Target release: v0.0.10-beta.10 on pos-desktop-updates
$releaseBeta10 = makeCompatRelease('pos-desktop-updates', '0.0.10-beta.10', [
    'deployment-inventory.json',
    'channel-transitions.json',
    'channel-transitions.sig',
    $stem . '.json',
    $stem . '.sig',
    $stem . '.zip',
]);
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.10-beta.10'] = $releaseBeta10;
$provider->channelCandidates['beta'] = [$releaseBeta10];

// Assets mapping
$betaUrl = static fn(string $name): string => 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.10-beta.10/' . $name;
$provider->assets['https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.11/deployment-inventory.json'] = $inventoryStable11Raw;
$provider->assets[$betaUrl('deployment-inventory.json')] = $inventoryBeta10Raw;
$provider->assets[$betaUrl('channel-transitions.json')] = $rawIndexBeta;
$provider->assets[$betaUrl('channel-transitions.sig')] = $sigIndexBeta;
$provider->assets[$betaUrl($stem . '.json')] = $rawDescBeta;
$provider->assets[$betaUrl($stem . '.sig')] = $sigDescBeta;

$delta = new DeltaUpdateService(null, $root, $lab . '/storage');
$updates = new UpdateService(new GitService(), new FrontendBuildService(), new BackupService(), $delta, null, null, $provider);
$updates->setClientChannel('beta');
$manifestSignatures = new ManifestSignatureService($publicKeyPath);
$manifestVerifier = new ChannelTransitionManifestService($manifestSignatures);
$service = new ChannelTransitionService($updates, new BackupService(), $pdo, $manifestSignatures);

// =========================================================================
// TEST 1: Stable 0.0.11 to older Beta 0.0.10-beta.10 transition (THE USER REPORTED DEFECT)
// =========================================================================
$preview1 = $service->preview(1, 'beta');
compatAssert(
    $preview1['status'] === 'ready' &&
    $preview1['target']['version'] === '0.0.10-beta.10' &&
    $preview1['target']['channel'] === 'beta' &&
    $preview1['package_type'] === 'delta' &&
    $preview1['reason_code'] === null,
    'Scenario 1: Stable 0.0.11 to older Beta 0.0.10-beta.10 must succeed and be ready. Got: ' . json_encode($preview1)
);

// =========================================================================
// TEST 2: Same-release transition: when installed matches target version and inventory
// =========================================================================
$updates->setClientChannel('stable');
$provider->channelCandidates['stable'] = []; // Setup release 0.0.11 is queried via pos-desktop feed
$previewSame = $service->preview(1, 'stable');
compatAssert(
    $previewSame['status'] === 'same_release' &&
    $previewSame['reason_code'] === 'same_release',
    'Scenario 2: Same release must return same_release status and reason code. Got: ' . json_encode($previewSame)
);

// =========================================================================
// TEST 3: Beta 0.0.10-beta.10 to newer Stable 0.0.11
// =========================================================================
file_put_contents($root . '/version.json', json_encode(['version' => '0.0.10-beta.10']));
file_put_contents($root . '/backend/backend.phar', 'beta10-phar');
file_put_contents($root . '/frontend/dist/app.js', 'beta10-js');

$installedBeta10Files = [
    'version.json' => ['sha256' => hash_file('sha256', $root . '/version.json'), 'size' => filesize($root . '/version.json')],
    'backend/backend.phar' => ['sha256' => hash_file('sha256', $root . '/backend/backend.phar'), 'size' => filesize($root . '/backend/backend.phar')],
    'frontend/dist/app.js' => ['sha256' => hash_file('sha256', $root . '/frontend/dist/app.js'), 'size' => filesize($root . '/frontend/dist/app.js')],
];
$inventoryBeta10Current = ['format' => 1, 'version' => '0.0.10-beta.10', 'shell_sha256' => $shellDigest, 'files' => $installedBeta10Files];
$inventoryBeta10CurrentRaw = json_encode($inventoryBeta10Current, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$inventoryBeta10CurrentSha = hash('sha256', $inventoryBeta10CurrentRaw);
$provider->assets[$betaUrl('deployment-inventory.json')] = $inventoryBeta10CurrentRaw;

// Carrier on beta 10 pointing back to stable 11
$stemBetaToStable = 'transition-0.0.10-beta.10-to-stable-0.0.11-full';
$descBetaToStable = [
    'format' => 1,
    'operation' => 'channel_transition',
    'carrier_tag' => 'v0.0.10-beta.10',
    'from_version' => '0.0.10-beta.10',
    'from_channel' => 'beta',
    'source_inventory_sha256' => $inventoryBeta10CurrentSha,
    'source_inventory' => $inventoryBeta10Current,
    'version' => '0.0.11',
    'channel' => 'stable',
    'target_repo' => 'pos-desktop',
    'target_tag' => 'v0.0.11',
    'target_inventory_sha256' => $inventoryStable11Sha,
    'target_inventory' => $inventoryStable11,
    'shell_sha256' => $shellDigest,
    'type' => 'full',
    'files' => array_map(static fn($p) => ['path' => $p, ...$installedStable11Files[$p]], array_keys($installedStable11Files)),
    'deleted_files' => [],
    'migrations' => [],
    'package' => ['asset' => $stemBetaToStable . '.zip', 'sha256' => str_repeat('e', 64), 'size' => 512],
    'data_compatibility' => $contract,
];
[$rawDescBetaToStable, $sigDescBetaToStable] = $sign($descBetaToStable);
$entryBetaToStable = [
    'from_version' => '0.0.10-beta.10',
    'source_inventory_sha256' => $inventoryBeta10CurrentSha,
    'version' => '0.0.11',
    'channel' => 'stable',
    'descriptor' => $stemBetaToStable . '.json',
    'signature' => $stemBetaToStable . '.sig',
    'descriptor_sha256' => hash('sha256', $rawDescBetaToStable),
    'type' => 'full',
];
[$rawIndexBetaToStable, $sigIndexBetaToStable] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.10', 'entries' => [$entryBetaToStable]]);

// Ensure mock release assets include all referenced transition files
$releaseBeta10['assets'] = array_map(static fn(string $name): array => [
    'name' => $name,
    'browser_download_url' => 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.10-beta.10/' . $name,
], [
    'deployment-inventory.json',
    'channel-transitions.json',
    'channel-transitions.sig',
    $stemBetaToStable . '.json',
    $stemBetaToStable . '.sig',
    $stemBetaToStable . '.zip',
]);
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.10-beta.10'] = $releaseBeta10;
$provider->channelCandidates['stable'] = []; // target is pos-desktop:v0.0.11 from setup
$provider->latestSetup = $releaseStable11;

$provider->assets[$betaUrl('channel-transitions.json')] = $rawIndexBetaToStable;
$provider->assets[$betaUrl('channel-transitions.sig')] = $sigIndexBetaToStable;
$provider->assets[$betaUrl($stemBetaToStable . '.json')] = $rawDescBetaToStable;
$provider->assets[$betaUrl($stemBetaToStable . '.sig')] = $sigDescBetaToStable;

$previewBetaToStable = $service->preview(1, 'stable');
compatAssert(
    $previewBetaToStable['status'] === 'ready' &&
    $previewBetaToStable['target']['version'] === '0.0.11' &&
    $previewBetaToStable['package_type'] === 'full',
    'Scenario 3: Beta 0.0.10-beta.10 to newer Stable 0.0.11 must succeed. Got: ' . json_encode($previewBetaToStable)
);

// =========================================================================
// TEST 4: Beta 0.0.10-beta.5 to older Stable 0.0.1 (baseline return)
// =========================================================================
file_put_contents($root . '/version.json', json_encode(['version' => '0.0.10-beta.5']));
file_put_contents($root . '/backend/backend.phar', 'beta5-phar');
file_put_contents($root . '/frontend/dist/app.js', 'beta5-js');
$installedBeta5Files = [
    'version.json' => ['sha256' => hash_file('sha256', $root . '/version.json'), 'size' => filesize($root . '/version.json')],
    'backend/backend.phar' => ['sha256' => hash_file('sha256', $root . '/backend/backend.phar'), 'size' => filesize($root . '/backend/backend.phar')],
    'frontend/dist/app.js' => ['sha256' => hash_file('sha256', $root . '/frontend/dist/app.js'), 'size' => filesize($root . '/frontend/dist/app.js')],
];
$inventoryBeta5 = ['format' => 1, 'version' => '0.0.10-beta.5', 'shell_sha256' => $shellDigest, 'files' => $installedBeta5Files];
$inventoryBeta5Raw = json_encode($inventoryBeta5, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$inventoryBeta5Sha = hash('sha256', $inventoryBeta5Raw);

$releaseBeta5 = makeCompatRelease('pos-desktop-updates', '0.0.10-beta.5', [
    'deployment-inventory.json',
    'channel-transitions.json',
    'channel-transitions.sig',
    'return.json',
    'return.sig',
    'return.zip',
]);
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.10-beta.5'] = $releaseBeta5;

$targetStable001Files = [
    'version.json' => ['sha256' => hash('sha256', '0.0.1-version'), 'size' => 20],
    'backend/backend.phar' => ['sha256' => hash('sha256', '0.0.1-phar'), 'size' => 90],
];
$inventoryStable001 = ['format' => 1, 'version' => '0.0.1', 'shell_sha256' => $shellDigest, 'files' => $targetStable001Files];
$inventoryStable001Raw = json_encode($inventoryStable001, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$inventoryStable001Sha = hash('sha256', $inventoryStable001Raw);
$releaseStable001 = makeCompatRelease('pos-desktop', '0.0.1', ['deployment-inventory.json']);
$setSetupRelease($releaseStable001);
$provider->channelCandidates['stable'] = []; // pos-desktop has setup 0.0.1

$beta5Url = static fn(string $name): string => 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.10-beta.5/' . $name;
$provider->assets[$beta5Url('deployment-inventory.json')] = $inventoryBeta5Raw;
$provider->assets['https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.1/deployment-inventory.json'] = $inventoryStable001Raw;

$descBetaTo001 = [
    'format' => 1,
    'operation' => 'channel_transition',
    'carrier_tag' => 'v0.0.10-beta.5',
    'from_version' => '0.0.10-beta.5',
    'from_channel' => 'beta',
    'source_inventory_sha256' => $inventoryBeta5Sha,
    'source_inventory' => $inventoryBeta5,
    'version' => '0.0.1',
    'channel' => 'stable',
    'target_repo' => 'pos-desktop',
    'target_tag' => 'v0.0.1',
    'target_inventory_sha256' => $inventoryStable001Sha,
    'target_inventory' => $inventoryStable001,
    'shell_sha256' => $shellDigest,
    'type' => 'delta',
    'files' => array_map(static fn($p) => ['path' => $p, ...$targetStable001Files[$p]], array_keys($targetStable001Files)),
    'deleted_files' => ['frontend/dist/app.js'],
    'migrations' => [],
    'package' => ['asset' => 'return.zip', 'sha256' => str_repeat('f', 64), 'size' => 128],
    'data_compatibility' => $contract,
];
[$rawDescBetaTo001, $sigDescBetaTo001] = $sign($descBetaTo001);
$entryBetaTo001 = [
    'from_version' => '0.0.10-beta.5',
    'source_inventory_sha256' => $inventoryBeta5Sha,
    'version' => '0.0.1',
    'channel' => 'stable',
    'descriptor' => 'return.json',
    'signature' => 'return.sig',
    'descriptor_sha256' => hash('sha256', $rawDescBetaTo001),
    'type' => 'delta',
];
[$rawIndexBetaTo001, $sigIndexBetaTo001] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.5', 'entries' => [$entryBetaTo001]]);
$provider->assets[$beta5Url('channel-transitions.json')] = $rawIndexBetaTo001;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIndexBetaTo001;
$provider->assets[$beta5Url('return.json')] = $rawDescBetaTo001;
$provider->assets[$beta5Url('return.sig')] = $sigDescBetaTo001;

$previewBetaTo001 = $service->preview(1, 'stable');
compatAssert(
    $previewBetaTo001['status'] === 'ready' &&
    $previewBetaTo001['target']['version'] === '0.0.1' &&
    $previewBetaTo001['package_type'] === 'delta',
    'Scenario 4: Beta 0.0.10-beta.5 to older Stable 0.0.1 must succeed. Got: ' . json_encode($previewBetaTo001)
);

// =========================================================================
// TEST 5: Genuine Cross-Carrier Conflict (index_ambiguous in both evaluation orders)
// =========================================================================
// Carrier 1: releaseBeta5 (v0.0.10-beta.5 on pos-desktop-updates)
// Carrier 2: releaseStable001WithCarrier (v0.0.1 on pos-desktop)
// Both claim to carry 0.0.10-beta.5 -> 0.0.1:delta with identical source, target, files, runtime, type
// but conflicting canonical operation authority via different valid 64-hex data_compatibility.ledger_sha256
$setupUrl = static fn(string $name): string => 'https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.1/' . $name;

$ledgerA = $contract['ledger_sha256'];
$ledgerB = hash('sha256', 'conflicting-ledger-b-contract');
compatAssert($ledgerA !== $ledgerB, 'Conflicting ledger must differ from baseline ledger');

$contractConflictB = [
    ...$contract,
    'ledger_sha256' => $ledgerB,
];

// Order 1: Carrier 1 (Beta 5) holds Ledger A, Carrier 2 (Setup 001) holds Ledger B
$descBetaOrder1 = [
    ...$descBetaTo001,
    'carrier_tag' => 'v0.0.10-beta.5',
    'data_compatibility' => [
        ...$contract,
        'ledger_sha256' => $ledgerA,
    ],
];
[$rawDescBetaOrder1, $sigDescBetaOrder1] = $sign($descBetaOrder1);
$entryBetaOrder1 = [
    ...$entryBetaTo001,
    'descriptor_sha256' => hash('sha256', $rawDescBetaOrder1),
];
[$rawIndexBetaOrder1, $sigIndexBetaOrder1] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.5', 'entries' => [$entryBetaOrder1]]);

$descSetupConflictB = [
    ...$descBetaTo001,
    'carrier_tag' => 'v0.0.1',
    'data_compatibility' => $contractConflictB,
];
[$rawDescSetupConflictB, $sigDescSetupConflictB] = $sign($descSetupConflictB);

$entrySetupConflictB = [
    'from_version' => '0.0.10-beta.5',
    'source_inventory_sha256' => $inventoryBeta5Sha,
    'version' => '0.0.1',
    'channel' => 'stable',
    'descriptor' => 'setup-carrier.json',
    'signature' => 'setup-carrier.sig',
    'descriptor_sha256' => hash('sha256', $rawDescSetupConflictB),
    'type' => 'delta',
];
[$rawIndexSetupConflictB, $sigIndexSetupConflictB] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.1', 'entries' => [$entrySetupConflictB]]);

$releaseStable001WithCarrier = makeCompatRelease('pos-desktop', '0.0.1', [
    'deployment-inventory.json',
    'channel-transitions.json',
    'channel-transitions.sig',
    'setup-carrier.json',
    'setup-carrier.sig',
    'return.zip',
]);

// Assert BOTH valid signed descriptors pass actual ManifestService.verify() individually
$installedBeta5ForVerification = [
    'version' => '0.0.10-beta.5',
    'channel' => 'beta',
    'shell_sha256' => $shellDigest,
    'inventory_sha256' => $inventoryBeta5Sha,
    'inventory' => $inventoryBeta5,
];
$targetStable001ForVerification = [
    'version' => '0.0.1',
    'channel' => 'stable',
    'repo' => 'pos-desktop',
    'tag' => 'v0.0.1',
    'inventory_sha256' => $inventoryStable001Sha,
    'inventory' => $inventoryStable001,
];
$manifestVerifyBeta1 = $manifestVerifier->verify($rawDescBetaOrder1, $sigDescBetaOrder1, $releaseBeta5, $installedBeta5ForVerification, $targetStable001ForVerification);
compatAssert($manifestVerifyBeta1['ok'] === true, 'Order 1: Carrier 1 descriptor must pass actual ManifestService.verify()');

$manifestVerifySetup1 = $manifestVerifier->verify($rawDescSetupConflictB, $sigDescSetupConflictB, $releaseStable001WithCarrier, $installedBeta5ForVerification, $targetStable001ForVerification);
compatAssert($manifestVerifySetup1['ok'] === true, 'Order 1: Carrier 2 descriptor must pass actual ManifestService.verify()');

// Populate Order 1 assets: Beta 5 (Ledger A) first, Setup 001 (Ledger B) second
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.10-beta.5'] = $releaseBeta5;
$provider->assets[$beta5Url('channel-transitions.json')] = $rawIndexBetaOrder1;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIndexBetaOrder1;
$provider->assets[$beta5Url('return.json')] = $rawDescBetaOrder1;
$provider->assets[$beta5Url('return.sig')] = $sigDescBetaOrder1;

$setSetupRelease($releaseStable001WithCarrier);
$provider->assets[$setupUrl('channel-transitions.json')] = $rawIndexSetupConflictB;
$provider->assets[$setupUrl('channel-transitions.sig')] = $sigIndexSetupConflictB;
$provider->assets[$setupUrl('setup-carrier.json')] = $rawDescSetupConflictB;
$provider->assets[$setupUrl('setup-carrier.sig')] = $sigDescSetupConflictB;

// Order 1: Beta5 carrier evaluated first (Ledger A), Setup001 evaluated second (Ledger B)
$previewConflict1 = $service->preview(1, 'stable');
compatAssert(
    $previewConflict1['status'] === 'blocked' &&
    $previewConflict1['reason_code'] === 'index_ambiguous',
    'Scenario 5.1: Cross-carrier conflicting authority must fail with index_ambiguous in Order 1 (Ledger A first, Ledger B later). Got: ' . json_encode($previewConflict1)
);

// Order 2: Reverse semantic authority order by SWAPPING WHICH carrier holds the conflicting ledger contract!
// Source inventory and release remain fully available. Deterministic sort yields Beta 5 first, Setup 001 later.
// Now Carrier 1 (Beta 5) holds Ledger B, Carrier 2 (Setup 001) holds Ledger A.
$descBetaOrder2 = [
    ...$descBetaTo001,
    'carrier_tag' => 'v0.0.10-beta.5',
    'data_compatibility' => $contractConflictB,
];
[$rawDescBetaOrder2, $sigDescBetaOrder2] = $sign($descBetaOrder2);
$entryBetaOrder2 = [
    ...$entryBetaTo001,
    'descriptor_sha256' => hash('sha256', $rawDescBetaOrder2),
];
[$rawIndexBetaOrder2, $sigIndexBetaOrder2] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.5', 'entries' => [$entryBetaOrder2]]);

$descSetupOrder2 = [
    ...$descBetaTo001,
    'carrier_tag' => 'v0.0.1',
    'data_compatibility' => [
        ...$contract,
        'ledger_sha256' => $ledgerA,
    ],
];
[$rawDescSetupOrder2, $sigDescSetupOrder2] = $sign($descSetupOrder2);
$entrySetupOrder2 = [
    'from_version' => '0.0.10-beta.5',
    'source_inventory_sha256' => $inventoryBeta5Sha,
    'version' => '0.0.1',
    'channel' => 'stable',
    'descriptor' => 'setup-carrier.json',
    'signature' => 'setup-carrier.sig',
    'descriptor_sha256' => hash('sha256', $rawDescSetupOrder2),
    'type' => 'delta',
];
[$rawIndexSetupOrder2, $sigIndexSetupOrder2] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.1', 'entries' => [$entrySetupOrder2]]);

// Assert BOTH valid signed descriptors for Order 2 pass actual ManifestService.verify() individually
$manifestVerifyBeta2 = $manifestVerifier->verify($rawDescBetaOrder2, $sigDescBetaOrder2, $releaseBeta5, $installedBeta5ForVerification, $targetStable001ForVerification);
compatAssert($manifestVerifyBeta2['ok'] === true, 'Order 2: Carrier 1 descriptor must pass actual ManifestService.verify()');

$manifestVerifySetup2 = $manifestVerifier->verify($rawDescSetupOrder2, $sigDescSetupOrder2, $releaseStable001WithCarrier, $installedBeta5ForVerification, $targetStable001ForVerification);
compatAssert($manifestVerifySetup2['ok'] === true, 'Order 2: Carrier 2 descriptor must pass actual ManifestService.verify()');

$provider->assets[$beta5Url('channel-transitions.json')] = $rawIndexBetaOrder2;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIndexBetaOrder2;
$provider->assets[$beta5Url('return.json')] = $rawDescBetaOrder2;
$provider->assets[$beta5Url('return.sig')] = $sigDescBetaOrder2;

$provider->assets[$setupUrl('channel-transitions.json')] = $rawIndexSetupOrder2;
$provider->assets[$setupUrl('channel-transitions.sig')] = $sigIndexSetupOrder2;
$provider->assets[$setupUrl('setup-carrier.json')] = $rawDescSetupOrder2;
$provider->assets[$setupUrl('setup-carrier.sig')] = $sigDescSetupOrder2;

// Order 2: Beta5 carrier evaluated first (Ledger B), Setup001 evaluated second (Ledger A)
$previewConflict2 = $service->preview(1, 'stable');
compatAssert(
    $previewConflict2['status'] === 'blocked' &&
    $previewConflict2['reason_code'] === 'index_ambiguous',
    'Scenario 5.2: Cross-carrier conflicting authority must fail with index_ambiguous in Order 2 (Ledger B first, Ledger A later). Got: ' . json_encode($previewConflict2)
);

// Restore Beta 5 valid return descriptor
$provider->assets[$beta5Url('channel-transitions.json')] = $rawIndexBetaTo001;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIndexBetaTo001;
$provider->assets[$beta5Url('return.json')] = $rawDescBetaTo001;
$provider->assets[$beta5Url('return.sig')] = $sigDescBetaTo001;

// =========================================================================
// TEST 6: Equivalent carriers across multiple carriers do NOT conflict
// =========================================================================
// Carrier 2 has identical canonical authority (same files, deleted_files, etc.) but carrier_tag is v0.0.1
$descSetupEquivalent = [
    ...$descBetaTo001,
    'carrier_tag' => 'v0.0.1',
];
[$rawDescSetupEq, $sigDescSetupEq] = $sign($descSetupEquivalent);
$entrySetupEq = [
    'from_version' => '0.0.10-beta.5',
    'source_inventory_sha256' => $inventoryBeta5Sha,
    'version' => '0.0.1',
    'channel' => 'stable',
    'descriptor' => 'setup-carrier.json',
    'signature' => 'setup-carrier.sig',
    'descriptor_sha256' => hash('sha256', $rawDescSetupEq),
    'type' => 'delta',
];
[$rawIndexSetupEq, $sigIndexSetupEq] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.1', 'entries' => [$entrySetupEq]]);

$setSetupRelease($releaseStable001WithCarrier);
$provider->assets[$setupUrl('channel-transitions.json')] = $rawIndexSetupEq;
$provider->assets[$setupUrl('channel-transitions.sig')] = $sigIndexSetupEq;
$provider->assets[$setupUrl('setup-carrier.json')] = $rawDescSetupEq;
$provider->assets[$setupUrl('setup-carrier.sig')] = $sigDescSetupEq;

$previewEquivalent = $service->preview(1, 'stable');
compatAssert(
    $previewEquivalent['status'] === 'ready' &&
    $previewEquivalent['target']['version'] === '0.0.1',
    'Scenario 6: Equivalent authority across independent carriers must NOT conflict. Got: ' . json_encode($previewEquivalent)
);

// Reset setup carrier assets to clean state
$setSetupRelease($releaseStable001);

// =========================================================================
// TEST 7: Multi-shell runtime variants: different valid runtime on Carrier 1, matching runtime on Carrier 2
// =========================================================================
$altShellDigest = str_repeat('7', 64);
$descAltShell = [
    ...$descBetaTo001,
    'carrier_tag' => 'v0.0.10-beta.5',
    'shell_sha256' => $altShellDigest,
];
[$rawDescAltShell, $sigDescAltShell] = $sign($descAltShell);
$entryAltShell = [
    'from_version' => '0.0.10-beta.5',
    'source_inventory_sha256' => $inventoryBeta5Sha,
    'version' => '0.0.1',
    'channel' => 'stable',
    'descriptor' => 'alt-shell.json',
    'signature' => 'alt-shell.sig',
    'descriptor_sha256' => hash('sha256', $rawDescAltShell),
    'type' => 'delta',
];
[$rawIndexAltShell, $sigIndexAltShell] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.5', 'entries' => [$entryAltShell]]);

// Put alt shell on Carrier 1 (beta 5)
$releaseBeta5WithAltShell = makeCompatRelease('pos-desktop-updates', '0.0.10-beta.5', [
    'deployment-inventory.json',
    'channel-transitions.json',
    'channel-transitions.sig',
    'alt-shell.json',
    'alt-shell.sig',
    'return.zip',
]);
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.10-beta.5'] = $releaseBeta5WithAltShell;
$provider->assets[$beta5Url('channel-transitions.json')] = $rawIndexAltShell;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIndexAltShell;
$provider->assets[$beta5Url('alt-shell.json')] = $rawDescAltShell;
$provider->assets[$beta5Url('alt-shell.sig')] = $sigDescAltShell;

// Put matching shell on Carrier 2 (setup 001)
$setSetupRelease($releaseStable001WithCarrier);
$provider->assets[$setupUrl('channel-transitions.json')] = $rawIndexSetupEq;
$provider->assets[$setupUrl('channel-transitions.sig')] = $sigIndexSetupEq;
$provider->assets[$setupUrl('setup-carrier.json')] = $rawDescSetupEq;
$provider->assets[$setupUrl('setup-carrier.sig')] = $sigDescSetupEq;

$previewMultiShell = $service->preview(1, 'stable');
$resolveMethod = new ReflectionMethod(ChannelTransitionService::class, 'resolve');
$resolveMethod->setAccessible(true);
$resolvedContext71 = $resolveMethod->invoke($service, 'stable');
compatAssert(
    $previewMultiShell['status'] === 'ready' &&
    $previewMultiShell['package_type'] === 'delta' &&
    !empty($previewMultiShell['preview_id']) &&
    $resolvedContext71['descriptor']['shell_sha256'] === $shellDigest,
    'Scenario 7.1: Carrier with matching shell must be selected when another carrier has a different runtime profile (valid-other-first, exact-later). Got: ' . json_encode($previewMultiShell)
);

// Swap: Exact-first, valid-other-later
// Carrier 1 (Beta 5) holds exact matching shell, Carrier 2 (Setup 001) holds foreign shell
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.10-beta.5'] = $releaseBeta5;
$provider->assets[$beta5Url('channel-transitions.json')] = $rawIndexBetaTo001;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIndexBetaTo001;
$provider->assets[$beta5Url('return.json')] = $rawDescBetaTo001;
$provider->assets[$beta5Url('return.sig')] = $sigDescBetaTo001;

$descSetupAltShell = [
    ...$descBetaTo001,
    'carrier_tag' => 'v0.0.1',
    'shell_sha256' => $altShellDigest,
];
[$rawDescSetupAltShell, $sigDescSetupAltShell] = $sign($descSetupAltShell);
$entrySetupAltShell = [
    'from_version' => '0.0.10-beta.5',
    'source_inventory_sha256' => $inventoryBeta5Sha,
    'version' => '0.0.1',
    'channel' => 'stable',
    'descriptor' => 'setup-alt-shell.json',
    'signature' => 'setup-alt-shell.sig',
    'descriptor_sha256' => hash('sha256', $rawDescSetupAltShell),
    'type' => 'delta',
];
[$rawIndexSetupAltShell, $sigIndexSetupAltShell] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.1', 'entries' => [$entrySetupAltShell]]);
$releaseStable001WithAltCarrier = makeCompatRelease('pos-desktop', '0.0.1', [
    'deployment-inventory.json',
    'channel-transitions.json',
    'channel-transitions.sig',
    'setup-alt-shell.json',
    'setup-alt-shell.sig',
    'return.zip',
]);
$setSetupRelease($releaseStable001WithAltCarrier);
$provider->assets[$setupUrl('channel-transitions.json')] = $rawIndexSetupAltShell;
$provider->assets[$setupUrl('channel-transitions.sig')] = $sigIndexSetupAltShell;
$provider->assets[$setupUrl('setup-alt-shell.json')] = $rawDescSetupAltShell;
$provider->assets[$setupUrl('setup-alt-shell.sig')] = $sigDescSetupAltShell;

$previewExactFirst = $service->preview(1, 'stable');
$resolvedContext72 = $resolveMethod->invoke($service, 'stable');
compatAssert(
    $previewExactFirst['status'] === 'ready' &&
    $previewExactFirst['package_type'] === 'delta' &&
    !empty($previewExactFirst['preview_id']) &&
    $resolvedContext72['descriptor']['shell_sha256'] === $shellDigest,
    'Scenario 7.2: Exact profile followed by foreign profile must succeed. Got: ' . json_encode($previewExactFirst)
);

// When ALL available carriers have mismatched shell, fail with shell_mismatch
// Carrier 1 (Beta 5) has alt shell, Carrier 2 (Setup 001) has alt shell
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.10-beta.5'] = $releaseBeta5WithAltShell;
$provider->assets[$beta5Url('channel-transitions.json')] = $rawIndexAltShell;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIndexAltShell;
$provider->assets[$beta5Url('alt-shell.json')] = $rawDescAltShell;
$provider->assets[$beta5Url('alt-shell.sig')] = $sigDescAltShell;

$previewBothAltShell = $service->preview(1, 'stable');
compatAssert(
    $previewBothAltShell['status'] === 'blocked' &&
    $previewBothAltShell['reason_code'] === 'shell_mismatch',
    'Scenario 7.3a: When all available carriers have foreign runtime profiles, fail with shell_mismatch. Got: ' . json_encode($previewBothAltShell)
);

$setSetupRelease($releaseStable001); // setup has no carrier transitions
$previewOnlyAltShell = $service->preview(1, 'stable');
compatAssert(
    $previewOnlyAltShell['status'] === 'blocked' &&
    $previewOnlyAltShell['reason_code'] === 'shell_mismatch',
    'Scenario 7.3b: When sole carrier with transitions has mismatched shell, fail with shell_mismatch. Got: ' . json_encode($previewOnlyAltShell)
);

// Invalid path on other profile fails closed immediately even when valid alternate is present
$descAltShellBadPath = $descAltShell;
$descAltShellBadPath['files'][] = ['path' => 'customer.db', 'sha256' => str_repeat('0', 64), 'size' => 100];
[$rawAltBadPath, $sigAltBadPath] = $sign($descAltShellBadPath);
$entryAltBadPath = [
    ...$entryAltShell,
    'descriptor' => 'alt-bad-path.json',
    'signature' => 'alt-bad-path.sig',
    'descriptor_sha256' => hash('sha256', $rawAltBadPath),
];
[$rawIdxAltBadPath, $sigIdxAltBadPath] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.5', 'entries' => [$entryAltBadPath]]);
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.10-beta.5'] = makeCompatRelease('pos-desktop-updates', '0.0.10-beta.5', [
    'deployment-inventory.json', 'channel-transitions.json', 'channel-transitions.sig', 'alt-bad-path.json', 'alt-bad-path.sig', 'return.zip'
]);
$setSetupRelease($releaseStable001WithCarrier); // Carrier 2 has valid exact shell
$provider->assets[$setupUrl('channel-transitions.json')] = $rawIndexSetupEq;
$provider->assets[$setupUrl('channel-transitions.sig')] = $sigIndexSetupEq;
$provider->assets[$setupUrl('setup-carrier.json')] = $rawDescSetupEq;
$provider->assets[$setupUrl('setup-carrier.sig')] = $sigDescSetupEq;

$provider->assets[$beta5Url('channel-transitions.json')] = $rawIdxAltBadPath;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIdxAltBadPath;
$provider->assets[$beta5Url('alt-bad-path.json')] = $rawAltBadPath;
$provider->assets[$beta5Url('alt-bad-path.sig')] = $sigAltBadPath;

$previewAltBadPath = $service->preview(1, 'stable');
compatAssert(
    $previewAltBadPath['status'] === 'blocked' &&
    $previewAltBadPath['reason_code'] === 'path_protected',
    'Scenario 7.4: Protected path on foreign runtime profile must fail closed with path_protected even with valid alternate carrier present. Got: ' . json_encode($previewAltBadPath)
);

// Wrong target on other profile fails closed immediately even when valid alternate is present
$descAltShellWrongTarget = $descAltShell;
$descAltShellWrongTarget['target_tag'] = 'v9.9.9';
$descAltShellWrongTarget['version'] = '9.9.9';
[$rawAltWrongTarget, $sigAltWrongTarget] = $sign($descAltShellWrongTarget);
$entryAltWrongTarget = [
    ...$entryAltShell,
    'descriptor' => 'alt-wrong-target.json',
    'signature' => 'alt-wrong-target.sig',
    'descriptor_sha256' => hash('sha256', $rawAltWrongTarget),
];
[$rawIdxAltWrongTarget, $sigIdxAltWrongTarget] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.5', 'entries' => [$entryAltWrongTarget]]);
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.10-beta.5'] = makeCompatRelease('pos-desktop-updates', '0.0.10-beta.5', [
    'deployment-inventory.json', 'channel-transitions.json', 'channel-transitions.sig', 'alt-wrong-target.json', 'alt-wrong-target.sig', 'return.zip'
]);
$setSetupRelease($releaseStable001WithCarrier); // Carrier 2 has valid exact shell
$provider->assets[$beta5Url('channel-transitions.json')] = $rawIdxAltWrongTarget;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIdxAltWrongTarget;
$provider->assets[$beta5Url('alt-wrong-target.json')] = $rawAltWrongTarget;
$provider->assets[$beta5Url('alt-wrong-target.sig')] = $sigAltWrongTarget;

$previewAltWrongTarget = $service->preview(1, 'stable');
compatAssert(
    $previewAltWrongTarget['status'] === 'blocked' &&
    $previewAltWrongTarget['reason_code'] === 'target_mismatch',
    'Scenario 7.5: Wrong target on foreign runtime profile must fail closed with target_mismatch even with valid alternate carrier present. Got: ' . json_encode($previewAltWrongTarget)
);

// Wrong entry type on other profile fails closed immediately even when valid alternate is present
$entryAltWrongType = [
    ...$entryAltShell,
    'type' => 'full', // Index says full, but descriptor is delta
];
[$rawIdxAltWrongType, $sigIdxAltWrongType] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.5', 'entries' => [$entryAltWrongType]]);
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.10-beta.5'] = $releaseBeta5WithAltShell;
$setSetupRelease($releaseStable001WithCarrier); // Carrier 2 has valid exact shell
$provider->assets[$beta5Url('channel-transitions.json')] = $rawIdxAltWrongType;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIdxAltWrongType;
$provider->assets[$beta5Url('alt-shell.json')] = $rawDescAltShell;
$provider->assets[$beta5Url('alt-shell.sig')] = $sigDescAltShell;

$previewAltWrongType = $service->preview(1, 'stable');
compatAssert(
    $previewAltWrongType['status'] === 'blocked' &&
    $previewAltWrongType['reason_code'] === 'package_type_mismatch',
    'Scenario 7.6: Wrong entry type on foreign runtime profile must fail closed with package_type_mismatch even with valid alternate carrier present. Got: ' . json_encode($previewAltWrongType)
);

// Restore valid Beta 5 carrier and clean setup carrier
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.10-beta.5'] = $releaseBeta5;
$provider->assets[$beta5Url('channel-transitions.json')] = $rawIndexBetaTo001;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIndexBetaTo001;
$provider->assets[$beta5Url('return.json')] = $rawDescBetaTo001;
$provider->assets[$beta5Url('return.sig')] = $sigDescBetaTo001;
$setSetupRelease($releaseStable001);

// =========================================================================
// TEST 8: Fail-closed on invalid signature on any carrier (bad-first & valid-first)
// =========================================================================
// Order 1: Carrier 1 has bad signature, Carrier 2 has valid setup carrier (bad-first / valid-later)
$setSetupRelease($releaseStable001WithCarrier);
$provider->assets[$setupUrl('channel-transitions.json')] = $rawIndexSetupEq;
$provider->assets[$setupUrl('channel-transitions.sig')] = $sigIndexSetupEq;
$provider->assets[$setupUrl('setup-carrier.json')] = $rawDescSetupEq;
$provider->assets[$setupUrl('setup-carrier.sig')] = $sigDescSetupEq;

$provider->assets[$beta5Url('return.sig')] = base64_encode('corrupt_signature_bytes');
$previewFailClosed1 = $service->preview(1, 'stable');
compatAssert(
    $previewFailClosed1['status'] === 'blocked' &&
    $previewFailClosed1['reason_code'] === 'signature_invalid',
    'Scenario 8.1: Bad-first carrier signature must fail closed immediately with signature_invalid. Got: ' . json_encode($previewFailClosed1)
);
$provider->assets[$beta5Url('return.sig')] = $sigDescBetaTo001;

// Order 2: Carrier 1 has valid signature, Carrier 2 has bad signature (valid-first / bad-later)
$provider->assets[$setupUrl('setup-carrier.sig')] = base64_encode('corrupt_setup_carrier_signature');
$previewFailClosed2 = $service->preview(1, 'stable');
compatAssert(
    $previewFailClosed2['status'] === 'blocked' &&
    $previewFailClosed2['reason_code'] === 'signature_invalid',
    'Scenario 8.2: Valid-first / Bad-later carrier signature must fail closed with signature_invalid without ignoring second carrier. Got: ' . json_encode($previewFailClosed2)
);
$provider->assets[$setupUrl('setup-carrier.sig')] = $sigDescSetupEq;
$setSetupRelease($releaseStable001); // revert setup

// =========================================================================
// TEST 9: Incompatible Ledger rejected (data_schema_incompatible)
// =========================================================================
$contractBadLedger = [
    ...$contract,
    'ledger_sha256' => str_repeat('9', 64),
];
$descBadLedger = [
    ...$descBetaTo001,
    'data_compatibility' => $contractBadLedger,
];
[$rawDescBadLedger, $sigDescBadLedger] = $sign($descBadLedger);
$entryBadLedger = [
    ...$entryBetaTo001,
    'descriptor_sha256' => hash('sha256', $rawDescBadLedger),
];
[$rawIndexBadLedger, $sigIndexBadLedger] = $sign(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.5', 'entries' => [$entryBadLedger]]);

$provider->assets[$beta5Url('channel-transitions.json')] = $rawIndexBadLedger;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIndexBadLedger;
$provider->assets[$beta5Url('return.json')] = $rawDescBadLedger;
$provider->assets[$beta5Url('return.sig')] = $sigDescBadLedger;

$previewBadLedger = $service->preview(1, 'stable');
compatAssert(
    $previewBadLedger['status'] === 'blocked' &&
    $previewBadLedger['reason_code'] === 'data_schema_incompatible',
    'Scenario 9: Ledger incompatibility must block transition with data_schema_incompatible. Got: ' . json_encode($previewBadLedger)
);

// Restore Beta 5 valid
$provider->assets[$beta5Url('channel-transitions.json')] = $rawIndexBetaTo001;
$provider->assets[$beta5Url('channel-transitions.sig')] = $sigIndexBetaTo001;
$provider->assets[$beta5Url('return.json')] = $rawDescBetaTo001;
$provider->assets[$beta5Url('return.sig')] = $sigDescBetaTo001;

// =========================================================================
// TEST 10: Retained Shell Mismatch on single carrier (shell_mismatch)
// =========================================================================
file_put_contents($lab . '/POS Desktop.exe', 'altered executable binary');
$previewShellMismatch = $service->preview(1, 'stable');
compatAssert(
    $previewShellMismatch['status'] === 'blocked' &&
    $previewShellMismatch['reason_code'] === 'shell_mismatch',
    'Scenario 10: Tampered shell must trigger shell_mismatch. Got: ' . json_encode($previewShellMismatch)
);
file_put_contents($lab . '/POS Desktop.exe', 'fixture shell executable binary');

// =========================================================================
// TEST 11: Source file tampering rejected (source_inventory_mismatch)
// =========================================================================
$savedInstalledPhar = file_get_contents($root . '/backend/backend.phar');
file_put_contents($root . '/backend/backend.phar', 'tampered phar');
$previewSourceTamper = $service->preview(1, 'stable');
compatAssert(
    $previewSourceTamper['status'] === 'blocked' &&
    $previewSourceTamper['reason_code'] === 'source_inventory_mismatch',
    'Scenario 11: Tampered installed source file must trigger source_inventory_mismatch. Got: ' . json_encode($previewSourceTamper)
);
file_put_contents($root . '/backend/backend.phar', $savedInstalledPhar);

// =========================================================================
// TEST 12: Corrupt descriptor digest rejected (descriptor_digest_mismatch)
// =========================================================================
$provider->assets[$beta5Url('return.json')] = $rawDescBetaTo001 . "\n/* tampered */";
$previewDigestMismatch = $service->preview(1, 'stable');
compatAssert(
    $previewDigestMismatch['status'] === 'blocked' &&
    $previewDigestMismatch['reason_code'] === 'descriptor_digest_mismatch',
    'Scenario 12: Tampered descriptor must trigger descriptor_digest_mismatch. Got: ' . json_encode($previewDigestMismatch)
);
$provider->assets[$beta5Url('return.json')] = $rawDescBetaTo001;

// =========================================================================
// TEST 13: Incompatible Database Schema rejected (data_schema_incompatible)
// =========================================================================
$pdo->type = 'int'; // Alter schema type from varchar(200)
$previewDbIncompat = $service->preview(1, 'stable');
compatAssert(
    $previewDbIncompat['status'] === 'blocked' &&
    $previewDbIncompat['reason_code'] === 'data_schema_incompatible',
    'Scenario 13: Schema mismatch must block transition with data_schema_incompatible. Got: ' . json_encode($previewDbIncompat)
);
$pdo->type = 'varchar(200)';

// =========================================================================
// TEST 14: Missing Destination Inventory handled
// =========================================================================
$targetWithoutInventory = makeCompatRelease('pos-desktop', '0.0.1', []);
$setSetupRelease($targetWithoutInventory);
$provider->channelCandidates['stable'] = [];
$catalogWithoutInv = new ChannelTransitionReleaseCatalog($provider);
$resWithoutInv = $catalogWithoutInv->resolve('stable', ['version' => '0.0.10-beta.5', 'channel' => 'beta']);
compatAssert(
    $resWithoutInv['ok'] &&
    $resWithoutInv['reason_code'] === 'target_inventory_unavailable',
    'Scenario 14: Missing destination inventory must return target_inventory_unavailable. Got: ' . json_encode($resWithoutInv)
);

// =========================================================================
// TEST 15: Dual-source v0.0.12 resolution - Setup 12 variant chosen and ready
// when matching fixture carrier exists; assert source binding & raw inventory digest
// =========================================================================
$setup12VersionContent = json_encode(['version' => '0.0.12', 'build' => 'setup']);
$setup12PharContent = 'authentic-setup-12-phar-content';
$setup12JsContent = 'console.log("setup-12");';

file_put_contents($root . '/version.json', $setup12VersionContent);
file_put_contents($root . '/backend/backend.phar', $setup12PharContent);
file_put_contents($root . '/frontend/dist/app.js', $setup12JsContent);

$installedSetup12Files = [
    'version.json' => ['sha256' => hash('sha256', $setup12VersionContent), 'size' => strlen($setup12VersionContent)],
    'backend/backend.phar' => ['sha256' => hash('sha256', $setup12PharContent), 'size' => strlen($setup12PharContent)],
    'frontend/dist/app.js' => ['sha256' => hash('sha256', $setup12JsContent), 'size' => strlen($setup12JsContent)],
];
$inventorySetup12 = ['format' => 1, 'version' => '0.0.12', 'shell_sha256' => $shellDigest, 'files' => $installedSetup12Files];
$inventorySetup12Raw = json_encode($inventorySetup12, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$inventorySetup12Sha = hash('sha256', $inventorySetup12Raw);

// Delta 12 has distinct authentic inventory files for the same published version 0.0.12
$delta12VersionContent = json_encode(['version' => '0.0.12', 'build' => 'delta']);
$delta12PharContent = 'authentic-delta-12-phar-content';
$delta12JsContent = 'console.log("delta-12");';

$installedDelta12Files = [
    'version.json' => ['sha256' => hash('sha256', $delta12VersionContent), 'size' => strlen($delta12VersionContent)],
    'backend/backend.phar' => ['sha256' => hash('sha256', $delta12PharContent), 'size' => strlen($delta12PharContent)],
    'frontend/dist/app.js' => ['sha256' => hash('sha256', $delta12JsContent), 'size' => strlen($delta12JsContent)],
];
$inventoryDelta12 = ['format' => 1, 'version' => '0.0.12', 'shell_sha256' => $shellDigest, 'files' => $installedDelta12Files];
$inventoryDelta12Raw = json_encode($inventoryDelta12, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$inventoryDelta12Sha = hash('sha256', $inventoryDelta12Raw);
compatAssert($inventorySetup12Sha !== $inventoryDelta12Sha, 'Setup and Delta 12 inventories must have distinct digests');

// Target Beta 0.0.12-beta.1 release
$targetBeta12Files = [
    'version.json' => ['sha256' => hash('sha256', 'beta12-target-version'), 'size' => 33],
    'backend/backend.phar' => ['sha256' => hash('sha256', 'beta12-target-phar'), 'size' => 600],
    'frontend/dist/app.js' => ['sha256' => hash('sha256', 'beta12-target-js'), 'size' => 450],
];
$inventoryBeta12 = ['format' => 1, 'version' => '0.0.12-beta.1', 'shell_sha256' => $shellDigest, 'files' => $targetBeta12Files];
$inventoryBeta12Raw = json_encode($inventoryBeta12, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$inventoryBeta12Sha = hash('sha256', $inventoryBeta12Raw);

// Signed transition descriptor: Setup 12 -> Beta 12
$stemSetup12ToBeta = 'transition-0.0.12-setup-to-beta-0.0.12-beta.1-delta';
$descSetup12ToBeta = [
    'format' => 1,
    'operation' => 'channel_transition',
    'carrier_tag' => 'v0.0.12-beta.1',
    'from_version' => '0.0.12',
    'from_channel' => 'stable',
    'source_inventory_sha256' => $inventorySetup12Sha,
    'source_inventory' => $inventorySetup12,
    'version' => '0.0.12-beta.1',
    'channel' => 'beta',
    'target_repo' => 'pos-desktop-updates',
    'target_tag' => 'v0.0.12-beta.1',
    'target_inventory_sha256' => $inventoryBeta12Sha,
    'target_inventory' => $inventoryBeta12,
    'shell_sha256' => $shellDigest,
    'type' => 'delta',
    'files' => array_map(static fn($p) => ['path' => $p, ...$targetBeta12Files[$p]], array_keys($targetBeta12Files)),
    'deleted_files' => [],
    'migrations' => [],
    'package' => ['asset' => $stemSetup12ToBeta . '.zip', 'sha256' => str_repeat('1', 64), 'size' => 300],
    'data_compatibility' => $contract,
];
[$rawDescSetup12ToBeta, $sigDescSetup12ToBeta] = $sign($descSetup12ToBeta);
$entrySetup12ToBeta = [
    'from_version' => '0.0.12',
    'source_inventory_sha256' => $inventorySetup12Sha,
    'version' => '0.0.12-beta.1',
    'channel' => 'beta',
    'descriptor' => $stemSetup12ToBeta . '.json',
    'signature' => $stemSetup12ToBeta . '.sig',
    'descriptor_sha256' => hash('sha256', $rawDescSetup12ToBeta),
    'type' => 'delta',
];

// Signed transition descriptor: Delta 12 -> Beta 12
$stemDelta12ToBeta = 'transition-0.0.12-delta-to-beta-0.0.12-beta.1-delta';
$descDelta12ToBeta = [
    'format' => 1,
    'operation' => 'channel_transition',
    'carrier_tag' => 'v0.0.12-beta.1',
    'from_version' => '0.0.12',
    'from_channel' => 'stable',
    'source_inventory_sha256' => $inventoryDelta12Sha,
    'source_inventory' => $inventoryDelta12,
    'version' => '0.0.12-beta.1',
    'channel' => 'beta',
    'target_repo' => 'pos-desktop-updates',
    'target_tag' => 'v0.0.12-beta.1',
    'target_inventory_sha256' => $inventoryBeta12Sha,
    'target_inventory' => $inventoryBeta12,
    'shell_sha256' => $shellDigest,
    'type' => 'delta',
    'files' => array_map(static fn($p) => ['path' => $p, ...$targetBeta12Files[$p]], array_keys($targetBeta12Files)),
    'deleted_files' => [],
    'migrations' => [],
    'package' => ['asset' => $stemDelta12ToBeta . '.zip', 'sha256' => str_repeat('2', 64), 'size' => 300],
    'data_compatibility' => $contract,
];
[$rawDescDelta12ToBeta, $sigDescDelta12ToBeta] = $sign($descDelta12ToBeta);
$entryDelta12ToBeta = [
    'from_version' => '0.0.12',
    'source_inventory_sha256' => $inventoryDelta12Sha,
    'version' => '0.0.12-beta.1',
    'channel' => 'beta',
    'descriptor' => $stemDelta12ToBeta . '.json',
    'signature' => $stemDelta12ToBeta . '.sig',
    'descriptor_sha256' => hash('sha256', $rawDescDelta12ToBeta),
    'type' => 'delta',
];

// Carrier index containing both transitions
[$rawIndexBeta12Dual, $sigIndexBeta12Dual] = $sign([
    'format' => 1,
    'carrier_tag' => 'v0.0.12-beta.1',
    'entries' => [$entrySetup12ToBeta, $entryDelta12ToBeta],
]);

$releaseSourceUpdates12 = makeCompatRelease('pos-desktop-updates', '0.0.12', ['deployment-inventory.json']);
$releaseSourceSetup12 = makeCompatRelease('pos-desktop', '0.0.12', ['deployment-inventory.json']);
$releaseBeta12Carrier = makeCompatRelease('pos-desktop-updates', '0.0.12-beta.1', [
    'deployment-inventory.json',
    'channel-transitions.json',
    'channel-transitions.sig',
    $stemSetup12ToBeta . '.json',
    $stemSetup12ToBeta . '.sig',
    $stemSetup12ToBeta . '.zip',
    $stemDelta12ToBeta . '.json',
    $stemDelta12ToBeta . '.sig',
    $stemDelta12ToBeta . '.zip',
]);

$provider->releasesByRepoTag['pos-desktop-updates:v0.0.12'] = $releaseSourceUpdates12;
$provider->releasesByRepoTag['pos-desktop:v0.0.12'] = $releaseSourceSetup12;
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.12-beta.1'] = $releaseBeta12Carrier;
$provider->channelCandidates['beta'] = [$releaseBeta12Carrier];

$beta12Url = static fn(string $name): string => 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.12-beta.1/' . $name;
$provider->assets['https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.12/deployment-inventory.json'] = $inventoryDelta12Raw;
$provider->assets['https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.12/deployment-inventory.json'] = $inventorySetup12Raw;
$provider->assets[$beta12Url('deployment-inventory.json')] = $inventoryBeta12Raw;
$provider->assets[$beta12Url('channel-transitions.json')] = $rawIndexBeta12Dual;
$provider->assets[$beta12Url('channel-transitions.sig')] = $sigIndexBeta12Dual;
$provider->assets[$beta12Url($stemSetup12ToBeta . '.json')] = $rawDescSetup12ToBeta;
$provider->assets[$beta12Url($stemSetup12ToBeta . '.sig')] = $sigDescSetup12ToBeta;
$provider->assets[$beta12Url($stemDelta12ToBeta . '.json')] = $rawDescDelta12ToBeta;
$provider->assets[$beta12Url($stemDelta12ToBeta . '.sig')] = $sigDescDelta12ToBeta;

$updates->setClientChannel('beta');
$previewDualSetup = $service->preview(1, 'beta');
compatAssert(
    $previewDualSetup['status'] === 'ready' &&
    $previewDualSetup['target']['version'] === '0.0.12-beta.1' &&
    $previewDualSetup['reason_code'] === null &&
    $previewDualSetup['package_type'] === 'delta' &&
    ($previewDualSetup['installed']['source_repo'] ?? '') === 'pos-desktop' &&
    ($previewDualSetup['installed']['inventory_sha256'] ?? '') === $inventorySetup12Sha,
    'Scenario 15: Setup v0.0.12 variant must select pos-desktop and be ready with matching carrier. Got: ' . json_encode($previewDualSetup)
);

// Assert preview claim rejects wrong source inventory SHA in binding
$previewStore = new ChannelTransitionPreviewStore($lab . '/storage/channel-transition-previews');
$claimBindingSetup = [
    'installed_version' => '0.0.12',
    'source_inventory' => $inventorySetup12Sha,
    'shell' => $shellDigest,
    'channel' => 'beta',
    'target' => $previewDualSetup['target'],
    'descriptor' => hash('sha256', $rawDescSetup12ToBeta),
    'schema' => $contract['schema_sha256'],
    'ledger' => $contract['ledger_sha256'],
];
compatAssert(
    !$previewStore->claim($previewDualSetup['preview_id'], 1, [...$claimBindingSetup, 'source_inventory' => $inventoryDelta12Sha])['ok'],
    'Scenario 15.1: Claim with Delta inventory SHA on Setup-selected preview must be refused'
);
$claimSuccess = $previewStore->claim($previewDualSetup['preview_id'], 1, $claimBindingSetup);
compatAssert(
    $claimSuccess['ok'] === true,
    'Scenario 15.2: Claim with exact authentic Setup source binding must succeed on unconsumed token'
);
$claimReuse = $previewStore->claim($previewDualSetup['preview_id'], 1, $claimBindingSetup);
compatAssert(
    !$claimReuse['ok'],
    'Scenario 15.3: Claiming already consumed preview token must fail (single-use preserved)'
);

// =========================================================================
// TEST 16: Reverse variant match Delta inventory: chooses pos-desktop-updates, ready
// =========================================================================
file_put_contents($root . '/version.json', $delta12VersionContent);
file_put_contents($root . '/backend/backend.phar', $delta12PharContent);
file_put_contents($root . '/frontend/dist/app.js', $delta12JsContent);

$previewDualDelta = $service->preview(1, 'beta');
compatAssert(
    $previewDualDelta['status'] === 'ready' &&
    $previewDualDelta['target']['version'] === '0.0.12-beta.1' &&
    $previewDualDelta['reason_code'] === null &&
    $previewDualDelta['package_type'] === 'delta' &&
    ($previewDualDelta['installed']['source_repo'] ?? '') === 'pos-desktop-updates' &&
    ($previewDualDelta['installed']['inventory_sha256'] ?? '') === $inventoryDelta12Sha,
    'Scenario 16: Delta v0.0.12 variant must select pos-desktop-updates and be ready. Got: ' . json_encode($previewDualDelta)
);

// =========================================================================
// TEST 17: Changing 1 installed file: still source_inventory_mismatch, never bypass
// =========================================================================
file_put_contents($root . '/backend/backend.phar', 'tampered-backend-neither-setup-nor-delta');
$previewTampered12 = $service->preview(1, 'beta');
compatAssert(
    $previewTampered12['status'] === 'blocked' &&
    $previewTampered12['reason_code'] === 'source_inventory_mismatch',
    'Scenario 17: Tampered file with dual 12 releases present must block with source_inventory_mismatch. Got: ' . json_encode($previewTampered12)
);

// Restore Setup 12 files
file_put_contents($root . '/version.json', $setup12VersionContent);
file_put_contents($root . '/backend/backend.phar', $setup12PharContent);
file_put_contents($root . '/frontend/dist/app.js', $setup12JsContent);

// =========================================================================
// TEST 18: Candidate 404, Non-404 discovery fail-closed, Malformed/missing
// inventory, Equivalent identity, Beta direction coverage
// =========================================================================
// 18.1: Candidate pos-desktop-updates returns 404; pos-desktop matches Setup 12
unset($provider->releasesByRepoTag['pos-desktop-updates:v0.0.12']);
$previewUpdates404 = $service->preview(1, 'beta');
compatAssert(
    $previewUpdates404['status'] === 'ready' &&
    ($previewUpdates404['installed']['source_repo'] ?? '') === 'pos-desktop',
    'Scenario 18.1: When pos-desktop-updates is 404, candidate pos-desktop must be chosen. Got: ' . json_encode($previewUpdates404)
);

// 18.2: Both candidates return 404 -> source_release_unavailable
unset($provider->releasesByRepoTag['pos-desktop:v0.0.12']);
$previewBoth404 = $service->preview(1, 'beta');
compatAssert(
    $previewBoth404['status'] === 'blocked' &&
    $previewBoth404['reason_code'] === 'source_release_unavailable',
    'Scenario 18.2: When both repos 404, must block with source_release_unavailable. Got: ' . json_encode($previewBoth404)
);

// Restore releases
$provider->releasesByRepoTag['pos-desktop-updates:v0.0.12'] = $releaseSourceUpdates12;
$provider->releasesByRepoTag['pos-desktop:v0.0.12'] = $releaseSourceSetup12;

// 18.3: Non-404 discovery error (HTTP 500) must fail closed with discovery_failed
$errorProvider = new class extends CompatTestReleaseProvider {
    public function getReleaseByTag(string $tag, string $repo = 'pos-desktop-updates'): array {
        if ($repo === 'pos-desktop-updates' && $tag === 'v0.0.12') {
            return ['ok' => false, 'http_code' => 500, 'error' => 'Internal server error 500'];
        }
        return parent::getReleaseByTag($tag, $repo);
    }
};
$errorProvider->releasesByRepoTag = $provider->releasesByRepoTag;
$errorProvider->channelCandidates = $provider->channelCandidates;
$errorProvider->assets = $provider->assets;
$errorService = new ChannelTransitionService(
    new UpdateService(new GitService(), new FrontendBuildService(), new BackupService(), $delta, null, null, $errorProvider),
    new BackupService(),
    $pdo,
    $manifestSignatures
);
$preview500 = $errorService->preview(1, 'beta');
compatAssert(
    $preview500['status'] === 'blocked' &&
    $preview500['reason_code'] === 'discovery_failed',
    'Scenario 18.3: HTTP 500 discovery must fail closed with discovery_failed. Got: ' . json_encode($preview500)
);

// 18.4: Malformed deployment inventory must fail closed with source_inventory_unavailable
$savedSetupInv = $provider->assets['https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.12/deployment-inventory.json'];
$provider->assets['https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.12/deployment-inventory.json'] = '{corrupt-json';
$previewCorruptInv = $service->preview(1, 'beta');
compatAssert(
    $previewCorruptInv['status'] === 'blocked' &&
    $previewCorruptInv['reason_code'] === 'source_inventory_unavailable',
    'Scenario 18.4: Corrupt deployment inventory must fail closed with source_inventory_unavailable. Got: ' . json_encode($previewCorruptInv)
);
$provider->assets['https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.12/deployment-inventory.json'] = $savedSetupInv;

// 18.5: Missing deployment-inventory.json asset must fail closed with source_inventory_unavailable
$releaseSetupNoInv = makeCompatRelease('pos-desktop', '0.0.12', []);
$provider->releasesByRepoTag['pos-desktop:v0.0.12'] = $releaseSetupNoInv;
$previewMissingInv = $service->preview(1, 'beta');
compatAssert(
    $previewMissingInv['status'] === 'blocked' &&
    $previewMissingInv['reason_code'] === 'source_inventory_unavailable',
    'Scenario 18.5: Missing deployment inventory asset must fail closed with source_inventory_unavailable. Got: ' . json_encode($previewMissingInv)
);
$provider->releasesByRepoTag['pos-desktop:v0.0.12'] = $releaseSourceSetup12;

// 18.6: Same-version equivalent identity: both repos present with identical valid inventory
$provider->assets['https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.12/deployment-inventory.json'] = $inventorySetup12Raw;
$previewEquivalent = $service->preview(1, 'beta');
compatAssert(
    $previewEquivalent['status'] === 'ready' &&
    ($previewEquivalent['installed']['source_repo'] ?? '') === 'pos-desktop-updates',
    'Scenario 18.6: Identical equivalent candidate inventories must deterministically select pos-desktop-updates. Got: ' . json_encode($previewEquivalent)
);
$provider->assets['https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.12/deployment-inventory.json'] = $inventoryDelta12Raw;

// 18.7: Truly different matching identities with conflicting inventory SHAs -> index_ambiguous
$inventorySetupVariant = [...$inventorySetup12, 'metadata' => 'alternate_variant'];
$rawInventorySetupVariant = json_encode($inventorySetupVariant, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
compatAssert(hash('sha256', $rawInventorySetupVariant) !== $inventorySetup12Sha, 'Variant inventory must have different SHA');
$provider->assets['https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.12/deployment-inventory.json'] = $rawInventorySetupVariant;
$previewAmbiguous = $service->preview(1, 'beta');
compatAssert(
    $previewAmbiguous['status'] === 'blocked' &&
    $previewAmbiguous['reason_code'] === 'index_ambiguous',
    'Scenario 18.7: Truly different matching identities with conflicting inventory SHAs must fail closed with index_ambiguous. Got: ' . json_encode($previewAmbiguous)
);
$provider->assets['https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.12/deployment-inventory.json'] = $inventoryDelta12Raw;

// 18.8: Beta direction retains pos-desktop-updates only
$beta12InstalledVersionContent = json_encode(['version' => '0.0.12-beta.1']);
$beta12InstalledPharContent = 'beta12-phar-installed';
$beta12InstalledJsContent = 'beta12-js-installed';

file_put_contents($root . '/version.json', $beta12InstalledVersionContent);
file_put_contents($root . '/backend/backend.phar', $beta12InstalledPharContent);
file_put_contents($root . '/frontend/dist/app.js', $beta12InstalledJsContent);

$installedBeta12Files = [
    'version.json' => ['sha256' => hash('sha256', $beta12InstalledVersionContent), 'size' => strlen($beta12InstalledVersionContent)],
    'backend/backend.phar' => ['sha256' => hash('sha256', $beta12InstalledPharContent), 'size' => strlen($beta12InstalledPharContent)],
    'frontend/dist/app.js' => ['sha256' => hash('sha256', $beta12InstalledJsContent), 'size' => strlen($beta12InstalledJsContent)],
];
$inventoryBeta12Current = ['format' => 1, 'version' => '0.0.12-beta.1', 'shell_sha256' => $shellDigest, 'files' => $installedBeta12Files];
$rawInventoryBeta12Current = json_encode($inventoryBeta12Current, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$provider->assets[$beta12Url('deployment-inventory.json')] = $rawInventoryBeta12Current;

$previewBetaDirection = $service->preview(1, 'stable');
compatAssert(
    $previewBetaDirection['installed']['channel'] === 'beta' &&
    ($previewBetaDirection['installed']['source_repo'] ?? '') === 'pos-desktop-updates',
    'Scenario 18.8: Installed channel beta probes pos-desktop-updates only and retains coverage. Got: ' . json_encode($previewBetaDirection)
);

// Restore files and channel to Setup 12
file_put_contents($root . '/version.json', $setup12VersionContent);
file_put_contents($root . '/backend/backend.phar', $setup12PharContent);
file_put_contents($root . '/frontend/dist/app.js', $setup12JsContent);
$updates->setClientChannel('beta');

// =========================================================================
// TEST 19: Authentic Setup 12 matching inventory but NO carrier transition
// -> honest transition_package_unavailable (known public coverage gap)
// =========================================================================
[$rawIndexOnlyDelta, $sigIndexOnlyDelta] = $sign([
    'format' => 1,
    'carrier_tag' => 'v0.0.12-beta.1',
    'entries' => [$entryDelta12ToBeta],
]);
$provider->assets[$beta12Url('channel-transitions.json')] = $rawIndexOnlyDelta;
$provider->assets[$beta12Url('channel-transitions.sig')] = $sigIndexOnlyDelta;

$previewNoCarrier = $service->preview(1, 'beta');
compatAssert(
    $previewNoCarrier['status'] === 'blocked' &&
    $previewNoCarrier['reason_code'] === 'transition_package_unavailable',
    'Scenario 19: Authentic Setup 12 matches inventory but carrier has no Setup transition -> honest transition_package_unavailable. Got: ' . json_encode($previewNoCarrier)
);

// =========================================================================
// Cleanup fixture directory safely
// =========================================================================
$canonical = realpath($lab);
compatAssert(
    $canonical !== false && str_starts_with(strtolower(str_replace('\\', '/', $canonical)), strtolower(str_replace('\\', '/', realpath(sys_get_temp_dir()))) . '/pos-compat-test-'),
    'Unsafe fixture cleanup root: ' . $lab
);
$cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lab, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($cleanup as $file) {
    compatAssert(!$file->isLink(), 'Fixture cleanup encountered a link');
    if ($file->isDir()) {
        rmdir($file->getPathname());
    } else {
        unlink($file->getPathname());
    }
}
rmdir($lab);

echo "PASS channel transition compatibility: bidirectional discovery, stable-to-older-beta, beta-to-stable, same-release, cross-carrier conflict guards, equivalent carrier deduplication, multi-shell variant selection, ledger/schema integrity, fail-closed signatures, dual-source v0.0.12 bounded resolution, setup/delta variant matching, fail-closed tampering/discovery\n";
