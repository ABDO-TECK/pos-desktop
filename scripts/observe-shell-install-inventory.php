<?php
declare(strict_types=1);

use App\Services\ProductInventoryService;
use App\Services\ShellDeltaManifestService;

if (PHP_OS_FAMILY !== 'Windows' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted' || $argc !== 4) {
    fwrite(STDERR, "Inspect the disposable install only on GitHub-hosted Windows Actions.\n");
    exit(1);
}

require_once __DIR__ . '/../backend/vendor/autoload.php';

$binary = getenv('POS_SHELL_ACCEPTANCE_PROFILE') === 'binary';
$lab = ProductInventoryService::resolveAbsolutePath((string)getenv('RUNNER_TEMP') . ($binary ? '/pos-binary' : '/shell-inapp'));
$scenarios = $binary ? ['rollback', 'commit', 'corrupt', 'format', 'missing', 'older'] : ['rollback', 'commit'];
if (!in_array($argv[3], $scenarios, true)) {
    throw new InvalidArgumentException('Unknown installed fixture scenario.');
}
$scenario = $argv[3];
$root = ProductInventoryService::resolveAbsolutePath($lab . '/installed-' . $scenario);
$release = match ($argv[1]) {
    'target', 'failing' => $argv[1],
    default => throw new InvalidArgumentException('Unknown fixture release.'),
};
$role = match ($argv[2]) {
    'source', 'target' => $argv[2],
    default => throw new InvalidArgumentException('Unknown signed inventory role.'),
};

$manifestFile = $lab . '/' . $release . '/release/shell-manifest.json';
$manifest = json_decode((string)file_get_contents($manifestFile), true, 64, JSON_THROW_ON_ERROR);
$expected = $manifest[$role . '_inventory'];
$product = new ProductInventoryService();
$report = ['expected_files' => count($expected['files']), 'digest_matches' => false];
try {
    $actual = $product->buildInventory($root, $expected['native_version']);
    $report['actual_files'] = count($actual['files']);
    $report['digest_matches'] = ShellDeltaManifestService::canonicalInventoryDigest($actual) === $manifest[$role . '_inventory_sha256'];
    $report['missing_managed_paths'] = array_slice(array_keys(array_diff_key($expected['files'], $actual['files'])), 0, 64);
    $report['changed_managed_paths'] = array_slice(array_keys(array_filter(
        array_intersect_key($expected['files'], $actual['files']),
        static fn (array $file, string $path): bool => $file !== $actual['files'][$path],
        ARRAY_FILTER_USE_BOTH
    )), 0, 64);
} catch (Throwable $error) {
    $report['policy_error'] = substr(str_replace($root, '[installed]', $error->getMessage()), 0, 500);
}

$report['unexpected_paths'] = [];
$rootLength = strlen($root);
$tree = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filtered = new RecursiveCallbackFilterIterator($tree, static function (SplFileInfo $entry) use ($rootLength): bool {
    $relative = ltrim(str_replace('\\', '/', substr($entry->getPathname(), $rootLength)), '/');
    if ($relative === 'Uninstall POS Desktop.exe' || ProductInventoryService::isProtectedPath($relative)) {
        return false;
    }
    if ($entry->isDir()) {
        try {
            ProductInventoryService::assertNoReparse($entry->getPathname());
        } catch (Throwable) {
            return false;
        }
    }
    return true;
});
$walk = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::LEAVES_ONLY);
foreach ($walk as $entry) {
    $relative = ltrim(str_replace('\\', '/', substr($entry->getPathname(), $rootLength)), '/');
    if (!isset($expected['files'][$relative])) {
        $classification = ProductInventoryService::validateProductPath($relative);
        $report['unexpected_paths'][$relative] = $classification['reason_code'] ?? 'managed_unsigned';
        if (count($report['unexpected_paths']) === 64) {
            break;
        }
    }
}

$report['qz_device_witnesses'] = [];
foreach (['override.crt', 'qz-tray.properties'] as $name) {
    $relative = 'resources/portable/qz-tray/' . $name;
    $probe = $root . '/' . $relative;
    if (is_file($probe)) {
        $file = ProductInventoryService::resolveConfinedPath($root, $relative, true);
        $report['qz_device_witnesses'][$relative] = [
            'sha256' => hash_file('sha256', $file),
            'size' => filesize($file),
            'classification' => ProductInventoryService::validateProductPath($relative)['reason_code'],
        ];
    }
}
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
