<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require __DIR__ . '/../backend/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

use App\Services\GitHubReleaseProvider;
use App\Services\DeltaUpdateService;
use App\Services\ManifestSignatureService;
use App\Services\ShellDeltaDeliveryService;
use App\Services\ShellDeltaManifestService;
use App\Services\UpdateDataCompatibilityService;

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
$binary = array_map($asset, ['shell-binary.json', 'shell-binary.signature', 'shell-binary.bin']);
$legacy = array_map($asset, ['manifest.json', 'manifest.sig', 'delta.zip', 'full-package.zip']);
$release = ['tag_name' => 'v0.0.14', 'draft' => false, 'prerelease' => false];
$mapped = $provider->mapReleaseData($release + ['assets' => [...$legacy, ...$shell, ...$binary]], '0.0.13');
$require(($mapped['signature_url'] ?? null) === $prefix . 'manifest.sig', 'Shell signature replaced the legacy signature.');
$require(($mapped['full_package_url'] ?? null) === $prefix . 'full-package.zip', 'Shell archive replaced the legacy full package.');
$require(($mapped['shell_assets']['shell-delta.zip'] ?? null) === $prefix . 'shell-delta.zip', 'Canonical shell archive was not catalogued.');

$require(count($mapped['shell_binary_assets'] ?? []) === 3, 'Canonical binary asset triplet was not catalogued.');
$malformed = $binary;
$malformed[0]['browser_download_url'] .= '?unexpected';
foreach ([[$binary[0]], $malformed, [...$binary, $binary[1]]] as $assets) {
    $mapped = $provider->mapReleaseData($release + ['assets' => [...$shell, ...$assets]], '0.0.13');
    $require(count($mapped['shell_assets']) === 3 && $mapped['shell_binary_assets'] === [], 'Invalid optional assets affected whole-file discovery.');
}

$mapped = $provider->mapReleaseData($release + ['assets' => $shell], '0.0.13');
$require(($mapped['signature_url'] ?? null) === null && ($mapped['full_package_url'] ?? null) === null, 'Shell-only release advertised legacy assets.');

$mismatched = $shell;
$mismatched[2]['browser_download_url'] = str_replace('/v0.0.14/', '/v0.0.15/', $mismatched[2]['browser_download_url']);
foreach ([[$shell[0], $shell[1]], $mismatched, [...$shell, $shell[0]]] as $assets) {
    $mapped = $provider->mapReleaseData($release + ['assets' => $assets], '0.0.13');
    $require(($mapped['shell_assets'] ?? null) === [], 'Malformed shell asset triplet was not rejected.');
}

echo "Shell release catalogue preserves legacy delivery and rejects mismatched shell assets.\n";

// Exercise the production builder, pinned signature verifier and on-disk staging.
// Only the external GitHub transfer is replaced in this focused test.
$lab = realpath((string) getenv('RUNNER_TEMP')) . '/pos-shell-delivery-' . bin2hex(random_bytes(5));
$source = $lab . '/source';
$target = $lab . '/target';
$output = $lab . '/assets';
$storage = $lab . '/data';
mkdir($storage, 0700, true);
$binaryFixture = random_bytes(2 * 1024 * 1024);
foreach ([$source => '0.0.13', $target => '0.0.14'] as $root => $version) {
    mkdir($root . '/resources/app.asar.unpacked', 0700, true);
    file_put_contents($root . '/POS Desktop.exe', $version . $binaryFixture);
    file_put_contents($root . '/resources/app.asar', 'test Electron application ' . $version);
    file_put_contents($root . '/resources/app.asar.unpacked/version.json', json_encode(['version' => $version], JSON_THROW_ON_ERROR));
}

$pair = ManifestSignatureService::generateKeyPair();
file_put_contents($lab . '/private.pem', $pair['private_key']);
file_put_contents($lab . '/public.pem', $pair['public_key']);
$profile = $lab . '/contract.json';
file_put_contents($profile, json_encode([
    'format' => 1, 'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
    'schema_sha256' => hash('sha256', 'schema'),'ledger_sha256' => hash('sha256', 'ledger'),
], JSON_THROW_ON_ERROR));
$command = [PHP_BINARY, __DIR__ . '/build-shell-delta.php', '--source-root', $source, '--target-root', $target,
    '--source-native-version', '0.0.13', '--target-native-version', '0.0.14', '--source-data-contract', $profile,
    '--target-data-contract', $profile, '--private-key', $lab . '/private.pem', '--output-dir', $output];
$build = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes);
$require(is_resource($build) && proc_close($build) === 0, 'Signed shell fixture could not be built.');
unlink($lab . '/private.pem');

$transport = new class ($output) extends GitHubReleaseProvider {
    public bool $corruptZip = false;
    public int $downloads = 0;
    public array $downloadNames = [];
    public ?string $corruptAsset = null;

    public function __construct(private string $assets)
    {
        parent::__construct('ABDO-TECK', 'pos-desktop-updates', '');
    }

    public function fetchBoundedAssetContent(string $url, int $maxBytes): ?string
    {
        $content = file_get_contents($this->assets . '/' . basename($url));
        return $content !== false && strlen($content) <= $maxBytes ? $content : null;
    }

    public function downloadBoundedAssetFile(string $url, string $destPath, int $maxBytes, Closure $progress): array
    {
        $this->downloads++;
        $this->downloadNames[] = basename($url);
        $content = file_get_contents($this->assets . '/' . basename($url));
        if ($content === false || strlen($content) > $maxBytes) {
            return ['ok' => false];
        }
        $progress(strlen($content), strlen($content), 1);
        return ['ok' => file_put_contents($destPath, ($this->corruptZip || $this->corruptAsset === basename($url)) ? str_repeat('x', strlen($content)) : $content) !== false];
    }
};
$preservedSource = hash_file('sha256', $source . '/POS Desktop.exe');
$delta = new DeltaUpdateService(null, $source, $storage);
$signatures = new ManifestSignatureService($lab . '/public.pem', [], false, false);
$delivery = new ShellDeltaDeliveryService($transport, new ShellDeltaManifestService($signatures), $delta, $signatures);
$catalogue = $transport->mapReleaseData($release + ['assets' => $shell], '0.0.13');
$remote = $catalogue + ['version' => '0.0.14'];
$verified = $delivery->inspect($remote, '0.0.13', 'stable', '0.0.13');
$require(($verified['reason_code'] ?? null) === null && isset($verified['descriptor']), 'Valid signed release was rejected.');
$require($delivery->inspect($remote, '0.0.13', 'stable', '0.0.12')['reason_code'] === 'source_native_version_mismatch', 'Wrong native baseline passed discovery.');
$require($delivery->inspect($remote, '0.0.13', 'beta', '0.0.13')['reason_code'] === 'shell_release_mismatch', 'Wrong channel passed discovery.');

$context = ['operation' => 'shell_delta', 'to_version' => '0.0.14'];
$stage = $delta->getStagingDir('0.0.14') . '/shell-delta';
$transport->corruptZip = true;
$require($delivery->stage($verified, $context) === 'package_hash_mismatch' && !is_dir($stage), 'Corrupt ZIP was published.');
$transport->corruptZip = false;
$require($delivery->stage($verified, $context) === null && is_file($stage . '/shell-manifest.sig'), 'Valid ZIP did not publish a complete stage.');
$require($delivery->stage($verified, $context) === null && $transport->downloads === 2, 'Identical valid stage was not reused.');
file_put_contents($stage . '/shell-delta.zip', 'short corrupt prior stage');
$require($delivery->stage($verified, $context) === null && $transport->downloads === 3, 'A corrupt prior stage could not be replaced by a verified retry.');
$require(hash_file('sha256', $source . '/POS Desktop.exe') === $preservedSource, 'Staging modified the installed source.');
$binaryCatalogue = $transport->mapReleaseData($release + ['assets' => [...$shell, ...$binary]], '0.0.13');
$binaryVerified = $delivery->inspect($binaryCatalogue + ['version' => '0.0.14'], '0.0.13', 'stable', '0.0.13');
$binaryState = [];
file_put_contents($stage . '/shell-delta.zip', 'replace with binary transport');
$transport->downloadNames = [];
$require($delivery->stage($binaryVerified, $context, $source, $binaryState) === null, 'Binary staging failed.');
$require($transport->downloadNames === ['shell-binary.bin'] && ($binaryState['shell_transport'] ?? null) === 'binary_delta',
    'Binary staging downloaded the whole-file ZIP.');
$require(hash_file('sha256', $stage . '/shell-delta.zip') === hash_file('sha256', $output . '/shell-delta.zip'),
    'Binary staging did not reconstruct the exact signed ZIP.');
$require($binaryState['shell_transport_bytes'] < filesize($output . '/shell-delta.zip'),
    'Binary staging used a larger transport.');
$require(count(glob($stage . '/*')) === 3, 'Optional assets leaked into the worker staging directory.');

file_put_contents($stage . '/shell-delta.zip', 'replace with full fallback');
$transport->downloadNames = [];
$transport->corruptAsset = 'shell-binary.bin';
$require($delivery->stage($binaryVerified, $context, $source, $binaryState) === null, 'Corrupt binary transport could not fall back.');
$require($transport->downloadNames === ['shell-binary.bin', 'shell-delta.zip'] && $binaryState['shell_transport'] === 'whole_file',
    'Corrupt binary transport did not use the separately verified whole-file ZIP.');
$transport->corruptAsset = null;

file_put_contents($stage . '/shell-delta.zip', 'replace with full fallback');
file_put_contents($output . '/shell-binary.signature', base64_encode('wrong signature'));
$transport->downloadNames = [];
$require($delivery->stage($binaryVerified, $context, $source, $binaryState) === null && $transport->downloadNames === ['shell-delta.zip'],
    'Untrusted metadata authorized the binary download.');
$require(hash_file('sha256', $source . '/POS Desktop.exe') === $preservedSource, 'Binary staging modified the installed source.');

file_put_contents($output . '/shell-manifest.sig', base64_encode('wrong signature'));
$require($delivery->inspect($remote, '0.0.13', 'stable', '0.0.13')['reason_code'] === 'signature_invalid', 'Invalid signature passed discovery.');

echo "Signed delivery enforces the exact source and channel, verifies ZIP bytes and publishes a retryable stage without writing the product.\n";
