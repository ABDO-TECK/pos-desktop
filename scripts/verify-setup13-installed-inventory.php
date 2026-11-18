<?php

declare(strict_types=1);

if (PHP_OS_FAMILY !== 'Windows' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted') {
    fwrite(STDERR, "Verify installed inventory only on GitHub-hosted Windows Actions.\n");
    exit(1);
}

require_once __DIR__ . '/deployment-inventory.php';
require_once __DIR__ . '/../backend/Services/ProductInventoryService.php';

use App\Services\ProductInventoryService;

$lab = getenv('RUNNER_TEMP') . '/setup13-shell';
$published = json_decode(
    (string) file_get_contents($lab . '/deployment-inventory.json'),
    true,
    64,
    JSON_THROW_ON_ERROR
);

if (($published['format'] ?? null) !== 1 || ($published['version'] ?? null) !== '0.0.13'
    || !is_array($published['files'] ?? null) || $published['files'] === []) {
    throw new RuntimeException('Expected published deployment inventory Format 1 for 0.0.13.');
}

$expectedFiles = $published['files'];
ksort($expectedFiles, SORT_STRING);
$verified = [];
foreach (['source', 'installed'] as $layout) {
    $actual = deploymentFiles($lab . '/' . $layout . '/resources/app.asar.unpacked');
    if ($actual !== $expectedFiles) {
        throw new RuntimeException("The complete publishable deployment inventory differs in {$layout}.");
    }
    $verified[$layout] = count($actual);
}

$evidence = [
    'version' => '0.0.13',
    'published_sha256' => hash_file('sha256', $lab . '/deployment-inventory.json'),
    'matched_managed_files' => $verified,
];
$encoded = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($lab . '/evidence/installed-deployment-inventory.json', $encoded) !== strlen($encoded)) {
    throw new RuntimeException('Could not record installed deployment inventory evidence.');
}
echo "All {$verified['installed']} publishable files matched both physical layouts.\n";

$uninstaller = 'Uninstall POS Desktop.exe';
foreach ([$uninstaller, strtolower($uninstaller), 'resources/' . $uninstaller] as $installerPath) {
    if (ProductInventoryService::validatePathSet([$installerPath])['ok']) {
        throw new RuntimeException("Installer-owned path unexpectedly eligible for a signed manifest: {$installerPath}");
    }
}

$service = new ProductInventoryService();
$archive = $service->buildInventory($lab . '/source', '0.0.13');
$installed = $service->buildInventory($lab . '/installed', '0.0.13');
if (isset($installed['files'][$uninstaller]) || $archive !== $installed) {
    throw new RuntimeException('The installed and extracted product inventories differ.');
}

$footprint = json_decode(
    (string) file_get_contents($lab . '/evidence/extracted-footprint.json'),
    true,
    64,
    JSON_THROW_ON_ERROR
);
$extractedFiles = $footprint['files'];
ksort($extractedFiles, SORT_STRING);
if ($archive['files'] !== $extractedFiles) {
    throw new RuntimeException('The product inventory must cover every file in the extracted Setup archive.');
}

$forbidden = $installed;
$forbidden['files'][$uninstaller] = [
    'sha256' => hash_file('sha256', $lab . '/installed/' . $uninstaller),
    'size' => filesize($lab . '/installed/' . $uninstaller),
];
if ($service->validateInventory($forbidden)['ok'] || !$service->validateInventory($installed)['ok']) {
    throw new RuntimeException('The inventory validator must accept the product map and reject installer-owned files.');
}

$evidence = [
    'version' => $installed['version'],
    'native_shell_version' => $installed['native_version'],
    'native_shell_sha256' => $installed['native_shell']['sha256'],
    'matching_product_files' => count($installed['files']),
    'installer_owned_file_ineligible_for_manifest' => true,
];
$encoded = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($lab . '/evidence/installed-product-inventory.json', $encoded) !== strlen($encoded)) {
    throw new RuntimeException('Could not record installed product inventory evidence.');
}
echo "All {$evidence['matching_product_files']} managed product files matched both physical inventories.\n";
