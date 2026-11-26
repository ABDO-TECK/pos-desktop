<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use RuntimeException;
use Throwable;

/** Discovers signed shell release assets and hands the verified bytes to Electron. */
final class ShellDeltaDeliveryService
{
    public function __construct(
        private GitHubReleaseProvider $provider,
        private ShellDeltaManifestService $manifests,
        private DeltaUpdateService $updates,
        private ?ManifestSignatureService $signatures = null,
    ) {}

    public static function isVersionSelector(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 64
            && ProductInventoryService::isValidSemver($value)
            && in_array(UpdateChannelPolicy::installedChannel($value), UpdateChannelPolicy::active(), true);
    }

    public function verifyInstalledSource(string $root, array $descriptor): ?string
    {
        $source = $this->manifests->verifySource($root, $descriptor);
        if (!$source['ok']) {
            return $source['reason_code'];
        }
        try {
            $sourceData = (new UpdateDataCompatibilityService())->assess(Database::getInstance(), $descriptor['source_data_compatibility'], false);
            return $sourceData['reason_code'];
        } catch (Throwable) {
            return 'data_compatibility_unverifiable';
        }
    }

    public static function installedNativeVersion(): ?string
    {
        $version = getenv('POS_SHELL_DELTA_NATIVE_VERSION');
        return PHP_OS_FAMILY === 'Windows' && \Phar::running(false) !== ''
            && getenv('POS_SHELL_DELTA_HANDOFF') === '1' && self::isVersionSelector($version)
            ? $version
            : null;
    }

    /** @return array{reason_code: ?string, descriptor?: array, manifest?: string, signature?: string, url?: string, binary_assets?: array} */
    public function inspect(array $release, string $source, string $channel, ?string $nativeVersion): array
    {
        $target = $release['version'] ?? null;
        $tag = $release['tag_name'] ?? null;
        if (!self::isVersionSelector($source) || !self::isVersionSelector($target)
            || !in_array($tag, ['v' . $target, $target], true)
            || !ReleaseVersionPolicy::assess($source, $target)['compatible']
            || !version_compare($target, $source, '>')
            || UpdateChannelPolicy::installedChannel($target) !== $channel
            || ($release['channel'] ?? null) !== $channel) {
            return ['reason_code' => 'shell_release_mismatch'];
        }

        $owner = $this->provider->getOwner();
        $repo = $this->provider->getRepo();
        $prefix = "https://github.com/{$owner}/{$repo}/releases/download/{$tag}/";
        $assets = $release['shell_assets'] ?? [];
        foreach ([ShellDeltaManifestService::MANIFEST_ASSET, ShellDeltaManifestService::SIGNATURE_ASSET, ShellDeltaManifestService::PACKAGE_ASSET] as $name) {
            if (($assets[$name] ?? null) !== $prefix . $name) {
                return ['reason_code' => 'shell_release_assets_invalid'];
            }
        }

        $manifest = $this->provider->fetchBoundedAssetContent($assets[ShellDeltaManifestService::MANIFEST_ASSET], ShellDeltaManifestService::MAX_MANIFEST_SIZE);
        $signature = $this->provider->fetchBoundedAssetContent($assets[ShellDeltaManifestService::SIGNATURE_ASSET], ShellDeltaManifestService::MAX_SIGNATURE_SIZE);
        if ($manifest === null || $signature === null) {
            return ['reason_code' => 'shell_release_assets_unreachable'];
        }

        $context = ['version' => $source, 'channel' => $channel];
        if ($nativeVersion !== null) {
            $context['native_version'] = $nativeVersion;
        }
        $verified = $this->manifests->verify($manifest, $signature, $context);
        if (!$verified['ok']) {
            return ['reason_code' => $verified['reason_code']];
        }
        $descriptor = $verified['descriptor'];
        if ($descriptor['version'] !== $target || $descriptor['channel'] !== $channel || $descriptor['purpose'] !== 'regular') {
            return ['reason_code' => 'shell_release_mismatch'];
        }
        try {
            ShellDeltaFileTransactionService::enforceCorePolicyConstraints($descriptor);
        } catch (RuntimeException) {
            return ['reason_code' => 'shell_transaction_policy_rejected'];
        }

        $binaryAssets = $release['shell_binary_assets'] ?? [];
        foreach (ShellBinaryTransportService::ASSETS as $name) {
            if (($binaryAssets[$name] ?? null) !== $prefix . $name) {
                $binaryAssets = [];
                break;
            }
        }

        return [
            'reason_code' => null,
            'descriptor' => $descriptor,
            'binary_assets' => $binaryAssets,
            'manifest' => $manifest,
            'signature' => $signature,
            'url' => $assets[ShellDeltaManifestService::PACKAGE_ASSET],
        ];
    }

    /** Run under the existing shared update lease. The final directory publishes as one unit. */
    public function stage(array $verified, array $stateContext, ?string $installRoot = null, ?array &$transport = null): ?string
    {
        $transport = [];
        $descriptor = $verified['descriptor'];
        $manifestName = ShellDeltaManifestService::MANIFEST_ASSET;
        $signatureName = ShellDeltaManifestService::SIGNATURE_ASSET;
        $packageName = ShellDeltaManifestService::PACKAGE_ASSET;
        $temporary = null;
        $previous = null;
        try {
            $parent = $this->updates->getStagingDir($descriptor['version']);
            ProductInventoryService::resolveAbsolutePath($parent, false);
            if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
                return 'shell_stage_unavailable';
            }
            $parent = ProductInventoryService::resolveAbsolutePath($parent);
            $final = ProductInventoryService::resolveConfinedPath($parent, 'shell-delta', false);
            if (is_dir($final)
                && $this->sameSmallFile($final, $manifestName, $verified['manifest'])
                && $this->sameSmallFile($final, $signatureName, $verified['signature'])
                && $this->manifests->validatePackageZip($final . '/' . $packageName, $descriptor)['ok']) {
                $transport = ['shell_transport' => 'staged', 'shell_transport_bytes' => 0];
                return null;
            }

            $size = $descriptor['package']['size'];
            $freeBytes = @disk_free_space($parent);
            if ($freeBytes === false || $freeBytes < $size) {
                return 'shell_stage_insufficient_space';
            }

            $temporary = $parent . '/.shell-delta-' . bin2hex(random_bytes(12));
            if (!@mkdir($temporary, 0700)) {
                return 'shell_stage_unavailable';
            }
            $temporary = ProductInventoryService::resolveAbsolutePath($temporary);
            $packagePath = $temporary . '/' . $packageName;
            $binaryBytes = $installRoot !== null
                ? $this->tryBinary($verified, $installRoot, $temporary, $packagePath, $freeBytes, $stateContext)
                : null;
            if ($binaryBytes === null && !$this->download($verified['url'], $packagePath, $size, $stateContext)) {
                return 'shell_package_download_failed';
            }

            $this->updates->setUpdateState('verifying', $stateContext);
            $package = $this->manifests->validatePackageZip($packagePath, $descriptor);
            if (!$package['ok']) {
                return $package['reason_code'];
            }

            $this->writeNewFile($temporary . '/' . $manifestName, $verified['manifest']);
            $this->writeNewFile($temporary . '/' . $signatureName, $verified['signature']);
            if (scandir($temporary) !== ['.', '..', $packageName, $manifestName, $signatureName]) {
                throw new RuntimeException('Shell update staging contains unexpected or partial assets.');
            }
            clearstatcache(true);
            $final = ProductInventoryService::resolveConfinedPath($parent, 'shell-delta', false);
            if (file_exists($final)) {
                $previous = $parent . '/.shell-delta-previous-' . bin2hex(random_bytes(12));
                if (!is_dir($final) || !@rename($final, $previous)) {
                    return 'shell_stage_unavailable';
                }
            }
            if (!@rename($temporary, $final)) {
                if ($previous !== null) {
                    @rename($previous, $final);
                }
                return 'shell_stage_unavailable';
            }
            $temporary = null;
            $transport = [
                'shell_transport' => $binaryBytes === null ? 'whole_file' : 'binary_delta',
                'shell_transport_bytes' => $binaryBytes ?? $size,
            ];
            if ($previous !== null) {
                $this->discard($previous);
            }
            return null;
        } catch (Throwable) {
            return 'shell_stage_unavailable';
        } finally {
            if ($temporary !== null) {
                $this->discard($temporary);
            }
        }
    }

    private function tryBinary(
        array $verified,
        string $installRoot,
        string $temporary,
        string $packagePath,
        float $freeBytes,
        array $stateContext
    ): ?int {
        $assets = $verified['binary_assets'] ?? [];
        if ($assets === [] || $this->signatures === null) {
            return null;
        }

        $binary = new ShellBinaryTransportService();
        $descriptor = $verified['descriptor'];
        $payloadPath = $temporary . '/' . ShellBinaryTransportService::PAYLOAD;
        try {
            $metadata = $this->provider->fetchBoundedAssetContent(
                $assets[ShellBinaryTransportService::METADATA],
                ShellBinaryTransportService::MAX_METADATA,
            );
            $signature = $this->provider->fetchBoundedAssetContent(
                $assets[ShellBinaryTransportService::SIGNATURE],
                ShellDeltaManifestService::MAX_SIGNATURE_SIZE,
            );
            if ($metadata === null || $signature === null) {
                return null;
            }
            $pointer = $binary->authorize($metadata, $signature, $verified['manifest'], $descriptor['package'], $this->signatures);
            if ($pointer === null || $freeBytes < $pointer['size'] + $descriptor['package']['size'] + $descriptor['package']['uncompressed_size']) {
                return null;
            }
            if (!$this->download($assets[ShellBinaryTransportService::PAYLOAD], $payloadPath, $pointer['size'], $stateContext)) {
                return null;
            }
            $this->updates->setUpdateState('verifying', $stateContext);
            $binary->rebuild($installRoot, $payloadPath, $packagePath, $descriptor, $pointer);
            return $pointer['size'] + strlen($metadata) + strlen($signature);
        } catch (Throwable) {
            return null;
        } finally {
            // The owned staging directory may contain a partial download after provider failure.
            $payload = ProductInventoryService::resolveConfinedPath($temporary, ShellBinaryTransportService::PAYLOAD, false);
            if (is_file($payload) && !@unlink($payload)) {
                throw new RuntimeException('Cannot remove the binary transport download.');
            }
        }
    }

    private function download(string $url, string $path, int $size, array $stateContext): bool
    {
        $this->updates->setUpdateState('downloading', $stateContext + ['download_bytes' => 0, 'total_bytes' => $size]);
        $download = $this->provider->downloadBoundedAssetFile(
            $url,
            $path,
            $size,
            function (int $bytes, int $_total, int $attempt) use ($size, $stateContext): void {
                $this->updates->setUpdateState('downloading', $stateContext + [
                    'download_bytes' => min($bytes, $size), 'total_bytes' => $size, 'download_attempt' => $attempt,
                ]);
            },
        );
        return $download['ok'];
    }

    private function sameSmallFile(string $dir, string $name, string $bytes): bool
    {
        $file = ProductInventoryService::resolveConfinedPath($dir, $name, false);
        return is_file($file) && filesize($file) === strlen($bytes)
            && hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $file));
    }

    private function writeNewFile(string $path, string $bytes): void
    {
        $file = @fopen($path, 'xb');
        if ($file === false) {
            throw new RuntimeException('Cannot create shell metadata file.');
        }
        try {
            if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file)) {
                throw new RuntimeException('Could not persist shell metadata.');
            }
        } finally {
            fclose($file);
        }
    }

    private function discard(string $dir): void
    {
        try {
            $dir = ProductInventoryService::resolveAbsolutePath($dir);
            foreach ([ShellDeltaManifestService::MANIFEST_ASSET, ShellDeltaManifestService::SIGNATURE_ASSET,
                ShellDeltaManifestService::PACKAGE_ASSET, ShellBinaryTransportService::PAYLOAD] as $name) {
                $file = ProductInventoryService::resolveConfinedPath($dir, $name, false);
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        } catch (RuntimeException) {
            // Preserve ambiguous entries for inspection rather than following them.
        }
    }
}
