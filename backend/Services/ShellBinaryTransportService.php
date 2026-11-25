<?php
declare(strict_types=1);

namespace App\Services;

use OpenSSLAsymmetricKey;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Optional delivery encoding for the exact signed Format 2 shell archive. */
final class ShellBinaryTransportService
{
    public const METADATA = 'shell-binary.json';
    public const SIGNATURE = 'shell-binary.signature';
    public const PAYLOAD = 'shell-binary.bin';
    public const ASSETS = [self::METADATA, self::SIGNATURE, self::PAYLOAD];
    public const MAX_METADATA = 4096;

    private const FORMAT = 1;
    private const MIN_PATCH_SOURCE = 16384;

    private ShellBinaryPatchService $patches;

    public function __construct()
    {
        $this->patches = new ShellBinaryPatchService();
    }

    public function authorize(string $raw, string $signature, string $manifest, array $package, ManifestSignatureService $signatures): ?array
    {
        if (strlen($raw) > self::MAX_METADATA || strlen($signature) > ShellDeltaManifestService::MAX_SIGNATURE_SIZE
            || !$signatures->verifySignature($raw, $signature)) {
            return null;
        }
        $metadata = json_decode($raw, true, 8);
        if (!is_array($metadata) || count($metadata) !== 4
            || ($metadata['format'] ?? null) !== self::FORMAT
            || ($metadata['manifest_sha256'] ?? null) !== hash('sha256', $manifest)
            || !is_string($metadata['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256']) !== 1
            || !is_int($metadata['size'] ?? null) || $metadata['size'] < 1
            || $metadata['size'] + strlen($raw) + strlen($signature) >= $package['size']) {
            return null;
        }
        return $metadata;
    }

    /** Give every canonical changed-file ZIP entry identical metadata on the builder and the client. */
    public static function addCanonicalFile(ZipArchive $zip, string $file, string $name): void
    {
        if (!$zip->addFile($file, $name)
            || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0100644 << 16))) {
            throw new RuntimeException('Cannot write canonical shell ZIP entry.');
        }
    }

    /** Normalize DOS timestamps in a newly created, bounded, single-disk ZIP. */
    public static function normalizeCanonicalTimes(string $path): void
    {
        $path = ProductInventoryService::resolveAbsolutePath($path);
        $file = @fopen($path, 'r+b');
        if ($file === false) {
            throw new RuntimeException('Cannot normalize the shell ZIP timestamps.');
        }
        try {
            $size = fstat($file)['size'] ?? 0;
            $endOffset = $size - 22;
            if ($endOffset < 0 || $size > ShellDeltaManifestService::MAX_PACKAGE_SIZE
                || fseek($file, $endOffset) !== 0) {
                throw new RuntimeException('Cannot read the canonical ZIP end record.');
            }
            $end = unpack('Vsignature/vdisk/vdirectoryDisk/vdiskEntries/ventries/VdirectorySize/VdirectoryOffset/vcomment',
                ShellBinaryPatchService::readExact($file, 22));
            if ($end['signature'] !== 0x06054b50 || $end['disk'] !== 0 || $end['directoryDisk'] !== 0
                || $end['diskEntries'] !== $end['entries'] || $end['entries'] > ShellDeltaManifestService::MAX_FILES
                || $end['comment'] !== 0 || $end['directoryOffset'] + $end['directorySize'] !== $endOffset) {
                throw new RuntimeException('Unexpected canonical ZIP directory layout.');
            }
            $directoryStart = $end['directoryOffset'];
            $entryOffset = $directoryStart;
            $lastLocalEnd = 0;
            // DOS date 1980-01-01 and time 00:00:00, written directly to avoid libzip time zone differences.
            $fixed = pack('vv', 0, 33);
            for ($index = 0; $index < $end['entries']; $index++) {
                if ($entryOffset > $endOffset - 46 || fseek($file, $entryOffset) !== 0) {
                    throw new RuntimeException('Cannot read the canonical ZIP entry header.');
                }
                $header = unpack('Vsignature/x16/Vcompressed/x4/vname/vextra/vcomment/x8/Vlocal',
                    ShellBinaryPatchService::readExact($file, 46));
                $local = $header['local'];
                if ($header['signature'] !== 0x02014b50 || $local < $lastLocalEnd || $local > $directoryStart - 30
                    || fseek($file, $local) !== 0) {
                    throw new RuntimeException('Unexpected canonical ZIP entry layout.');
                }
                $localHeader = unpack('Vsignature/x22/vname/vextra', ShellBinaryPatchService::readExact($file, 30));
                $lastLocalEnd = $local + 30 + $localHeader['name'] + $localHeader['extra'] + $header['compressed'];
                if ($localHeader['signature'] !== 0x04034b50 || $lastLocalEnd > $directoryStart
                    || fseek($file, $local + 10) !== 0) {
                    throw new RuntimeException('Cannot normalize the canonical ZIP local timestamp.');
                }
                ShellBinaryPatchService::write($file, $fixed);
                if (fseek($file, $entryOffset + 12) !== 0) {
                    throw new RuntimeException('Cannot normalize the canonical ZIP directory timestamp.');
                }
                ShellBinaryPatchService::write($file, $fixed);
                $entryOffset += 46 + $header['name'] + $header['extra'] + $header['comment'];
            }
            if ($entryOffset !== $endOffset || !fflush($file)) {
                throw new RuntimeException('Canonical ZIP timestamp normalization failed.');
            }
        } finally {
            fclose($file);
        }
    }

    /** Called after the canonical archive and its descriptor pass existing Format 2 validation. */
    public function build(
        string $sourceRoot,
        string $targetRoot,
        string $stage,
        array $descriptor,
        string $manifest,
        OpenSSLAsymmetricKey $key
    ): bool {
        $package = $descriptor['package'];
        $canonical = $stage . '/' . ShellDeltaManifestService::PACKAGE_ASSET;
        $payload = $stage . '/' . self::PAYLOAD;
        $whole = $this->openZip($canonical, ZipArchive::RDONLY);
        $thin = null;
        $scratch = [];
        $created = [];
        $published = false;
        $roundTrip = $stage . '/.binary-check-' . bin2hex(random_bytes(8));
        try {
            $thin = $this->openZip($payload, ZipArchive::CREATE | ZipArchive::EXCL);
            $created[] = $payload;
            $prefix = $stage . '/.binary-patch-' . bin2hex(random_bytes(8)) . '-';
            $patchCount = 0;
            foreach ($descriptor['files'] as $index => $entry) {
                $name = $entry['path'];
                $target = ProductInventoryService::resolveConfinedPath($targetRoot, $name);
                $stat = $whole->statName($name);
                if ($stat === false) {
                    throw new RuntimeException('Canonical entry is missing from the shell ZIP.');
                }
                $sourceMeta = $descriptor['source_inventory']['files'][$name] ?? null;
                if ($sourceMeta !== null && $sourceMeta['size'] >= self::MIN_PATCH_SOURCE
                    && $sourceMeta['size'] <= ShellDeltaManifestService::MAX_SINGLE_FILE_SIZE
                    && $entry['size'] >= self::MIN_PATCH_SOURCE && $stat['comp_size'] > 0) {
                    $source = ProductInventoryService::resolveConfinedPath($sourceRoot, $name);
                    $patch = $prefix . $index;
                    $output = $this->openFile($patch, 'xb');
                    $scratch[] = $patch;
                    $base = null;
                    $updated = null;
                    try {
                        $base = $this->openFile($source, 'rb');
                        $updated = $this->openFile($target, 'rb');
                        $worthwhile = $this->patches->create($base, $updated, $output, $sourceMeta['size'], $entry['size'], $stat['comp_size']);
                    } finally {
                        if (is_resource($base)) {
                            fclose($base);
                        }
                        if (is_resource($updated)) {
                            fclose($updated);
                        }
                        fclose($output);
                    }
                    if ($worthwhile) {
                        if (!$thin->addFile($patch, 'p/' . $index)) {
                            throw new RuntimeException('Cannot add a binary patch to the transport.');
                        }
                        $patchCount++;
                        continue;
                    }
                    if (!@unlink($patch)) {
                        throw new RuntimeException('Cannot remove an unused binary patch.');
                    }
                    array_pop($scratch);
                }
                if (!$thin->addFile($target, 'f/' . $index)) {
                    throw new RuntimeException('Cannot add a whole file to the binary transport.');
                }
            }
            if ($patchCount === 0) {
                return false;
            }
            if (!$thin->close()) {
                throw new RuntimeException('Cannot finalize the binary transport.');
            }
            $thin = null;
            clearstatcache(true, $payload);
            $size = filesize($payload);
            if (!is_int($size) || $size < 1 || $size >= $package['size']) {
                return false;
            }
            $sha256 = hash_file('sha256', $payload);
            if ($sha256 === false) {
                throw new RuntimeException('Cannot hash the binary transport.');
            }
            $metadata = [
                'format' => self::FORMAT,
                'manifest_sha256' => hash('sha256', $manifest),
                'sha256' => $sha256,
                'size' => $size,
            ];
            $raw = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            if (!openssl_sign($raw, $signed, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Cannot sign the binary transport metadata.');
            }
            $signature = base64_encode($signed);
            if (strlen($signature) > ShellDeltaManifestService::MAX_SIGNATURE_SIZE
                || $size + strlen($raw) + strlen($signature) >= $package['size']) {
                return false;
            }
            $this->rebuild($sourceRoot, $payload, $roundTrip, $descriptor, $metadata);
            if (!@unlink($roundTrip)) {
                throw new RuntimeException('Cannot remove the verified binary transport round trip.');
            }

            foreach ([self::METADATA => $raw, self::SIGNATURE => $signature] as $name => $bytes) {
                $path = $stage . '/' . $name;
                $file = $this->openFile($path, 'xb');
                $created[] = $path;
                try {
                    ShellBinaryPatchService::write($file, $bytes);
                    if (!fflush($file)) {
                        throw new RuntimeException('Cannot persist signed binary transport metadata.');
                    }
                } finally {
                    fclose($file);
                }
            }
            $published = true;
            return true;
        } finally {
            $this->closeOnFailure($thin);
            $whole->close();
            $this->removeFiles($stage, $scratch);
            if (!$published) {
                $this->removeFiles($stage, [...$created, $roundTrip]);
            }
        }
    }

    /** Build a canonical ZIP from signed inputs entirely in an owned staging directory. */
    public function rebuild(string $sourceRoot, string $payload, string $output, array $descriptor, array $metadata): void
    {
        $stage = ProductInventoryService::resolveAbsolutePath(dirname($output));
        $payload = ProductInventoryService::resolveAbsolutePath($payload);
        $this->assertBytes($payload, $metadata);
        $sourceRoot = ProductInventoryService::resolveAbsolutePath($sourceRoot);
        $thin = $this->openZip($payload, ZipArchive::RDONLY | ZipArchive::CHECKCONS);
        $package = null;
        $completed = false;
        $ownsOutput = false;
        $scratch = [];
        try {
            $files = $descriptor['files'];
            if (count($files) < 1 || count($files) > ShellDeltaManifestService::MAX_FILES || $thin->numFiles !== count($files)) {
                throw new RuntimeException('Binary transport has an unexpected entry count.');
            }
            $package = $this->openZip($output, ZipArchive::CREATE | ZipArchive::EXCL);
            $ownsOutput = true;
            $prefix = $stage . '/.binary-target-' . bin2hex(random_bytes(8)) . '-';
            $patchCount = 0;
            $total = 0;
            foreach ($files as $index => $entry) {
                $name = $entry['path'];
                if (!ProductInventoryService::validateProductPath($name)['ok']) {
                    throw new RuntimeException('Binary transport references a disallowed product path.');
                }
                $total += $entry['size'];
                if ($entry['size'] < 0 || $entry['size'] > ShellDeltaManifestService::MAX_SINGLE_FILE_SIZE
                    || $total > ShellDeltaManifestService::MAX_UNCOMPRESSED_SIZE) {
                    throw new RuntimeException('Binary transport output exceeds signed package limits.');
                }
                $stat = $thin->statIndex($index);
                if ($stat === false || !in_array($stat['name'], ['p/' . $index, 'f/' . $index], true)
                    || !in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)
                    || ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                    throw new RuntimeException('Binary transport contains an invalid entry.');
                }
                $isPatch = $stat['name'] === 'p/' . $index;
                if ($stat['size'] < 0 || $stat['size'] > ShellDeltaManifestService::MAX_SINGLE_FILE_SIZE
                    || (!$isPatch && $stat['size'] !== $entry['size'])) {
                    throw new RuntimeException('Binary transport entry exceeds its allowed size.');
                }
                $target = $prefix . $index;
                $destination = $this->openFile($target, 'xb');
                $scratch[] = $target;
                $stream = null;
                $base = null;
                try {
                    $stream = $thin->getStream($stat['name']);
                    if ($stream === false) {
                        throw new RuntimeException('Cannot read the signed binary transport entry.');
                    }
                    if ($isPatch) {
                        $sourceMeta = $descriptor['source_inventory']['files'][$name] ?? null;
                        if ($sourceMeta === null) {
                            throw new RuntimeException('Binary patch has no signed source entry.');
                        }
                        $sourcePath = ProductInventoryService::resolveConfinedPath($sourceRoot, $name);
                        $base = $this->openFile($sourcePath, 'rb');
                        // Re-resolve after opening before using any base bytes.
                        clearstatcache(true);
                        if (ProductInventoryService::resolveConfinedPath($sourceRoot, $name) !== $sourcePath
                            || !is_file($sourcePath)) {
                            throw new RuntimeException('Binary patch source changed while opening it.');
                        }
                        $this->patches->apply($base, $stream, $stat['size'], $destination, $sourceMeta, $entry);
                        $patchCount++;
                    } elseif (stream_copy_to_stream($stream, $destination, $entry['size'] + 1) !== $entry['size']) {
                        throw new RuntimeException('Signed binary transport whole-file entry is truncated or oversized.');
                    }
                    if (!fflush($destination)) {
                        throw new RuntimeException('Cannot persist the reconstructed shell entry.');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    if (is_resource($base)) {
                        fclose($base);
                    }
                    fclose($destination);
                }
                $this->assertBytes($target, $entry);
                self::addCanonicalFile($package, $target, $name);
            }
            if ($patchCount === 0 || $total !== $descriptor['package']['uncompressed_size']) {
                throw new RuntimeException('Binary transport does not match the signed change set.');
            }
            if (!$package->close()) {
                throw new RuntimeException('Cannot finalize the reconstructed shell ZIP.');
            }
            $package = null;
            self::normalizeCanonicalTimes($output);
            $this->assertBytes($output, $descriptor['package']);
            $completed = true;
        } finally {
            $this->closeOnFailure($package);
            $thin->close();
            if (!$completed && $ownsOutput) {
                $scratch[] = $output;
            }
            $this->removeFiles($stage, $scratch);
        }
    }

    private function assertBytes(string $path, array $meta): void
    {
        clearstatcache(true, $path);
        if (filesize($path) !== $meta['size'] || hash_file('sha256', $path) !== $meta['sha256']) {
            throw new RuntimeException('Binary transport bytes differ from their signed digest or size.');
        }
    }

    private function openZip(string $path, int $flags): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path, $flags) !== true) {
            throw new RuntimeException('Cannot open the shell transport archive.');
        }
        return $zip;
    }

    private function openFile(string $path, string $mode)
    {
        $file = @fopen($path, $mode);
        if ($file === false) {
            throw new RuntimeException('Cannot open the shell transport file.');
        }
        return $file;
    }

    private function closeOnFailure(?ZipArchive $zip): void
    {
        if ($zip !== null) {
            try {
                @$zip->unchangeAll();
                @$zip->close();
            } catch (Throwable) {
                // Remove any partial archive below.
            }
        }
    }

    private function removeFiles(string $stage, array $paths): void
    {
        foreach ($paths as $path) {
            $file = ProductInventoryService::resolveConfinedPath($stage, basename($path), false);
            if (is_file($file) && !@unlink($file)) {
                throw new RuntimeException('Cannot clean up the shell transport workspace.');
            }
        }
    }
}
