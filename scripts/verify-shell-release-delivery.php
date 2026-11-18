<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require __DIR__ . '/../backend/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

use App\Services\GitHubReleaseProvider;

$provider = new GitHubReleaseProvider('ABDO-TECK', 'pos-desktop-updates', '');
$prefix = 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.14/';
$asset = static fn (string $name): array => ['name' => $name, 'browser_download_url' => $prefix . $name];
$require = static function (bool $valid, string $failure): void {
    if (!$valid) {
        fwrite(STDERR, $failure . PHP_EOL);
        exit(1);
    }
};

$shell = array_map($asset, ['shell-manifest.json', 'shell-manifest.sig', 'shell-delta.zip']);
$legacy = array_map($asset, ['manifest.json', 'manifest.sig', 'delta.zip', 'full-package.zip']);
$release = ['tag_name' => 'v0.0.14', 'draft' => false, 'prerelease' => false];
$mapped = $provider->mapReleaseData($release + ['assets' => [...$legacy, ...$shell]], '0.0.13');
$require(($mapped['signature_url'] ?? null) === $prefix . 'manifest.sig', 'Shell signature replaced the legacy signature.');
$require(($mapped['full_package_url'] ?? null) === $prefix . 'full-package.zip', 'Shell archive replaced the legacy full package.');
$require(($mapped['shell_assets']['shell-delta.zip'] ?? null) === $prefix . 'shell-delta.zip', 'Canonical shell archive was not catalogued.');

$mapped = $provider->mapReleaseData($release + ['assets' => $shell], '0.0.13');
$require(($mapped['signature_url'] ?? null) === null && ($mapped['full_package_url'] ?? null) === null, 'Shell-only release advertised legacy assets.');

$mismatched = $shell;
$mismatched[2]['browser_download_url'] = str_replace('/v0.0.14/', '/v0.0.15/', $mismatched[2]['browser_download_url']);
foreach ([[$shell[0], $shell[1]], $mismatched, [...$shell, $shell[0]]] as $assets) {
    $mapped = $provider->mapReleaseData($release + ['assets' => $assets], '0.0.13');
    $require(($mapped['shell_assets'] ?? null) === [], 'Malformed shell asset triplet was not rejected.');
}

echo "Shell release catalogue preserves legacy delivery and rejects mismatched shell assets.\n";
