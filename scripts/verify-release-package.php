<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\ManifestSignatureService;

function verifyFail(string $message): never
{
    throw new RuntimeException($message);
}

function verifySemver(string $version, string $label): void
{
    if (!preg_match('/^\d+\.\d+\.\d+(?:-(?:beta|rc)\.\d+)?$/', $version)) {
        verifyFail("{$label} must be a semantic version in X.Y.Z form.");
    }
}

function normalizePackagePath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function assertSafePackagePath(string $path): void
{
    $normalized = normalizePackagePath($path);
    $segments = explode('/', $normalized);
    if ($normalized === '' || str_starts_with($normalized, '/') || str_contains($normalized, ':') || in_array('..', $segments, true) || in_array('.', $segments, true)) {
        verifyFail("Unsafe manifest path: {$path}");
    }
    if (preg_match('/(^|\/)(?:\.env|private[_-]?key|credentials?|secrets?)(?:\.|\/|$)/i', $normalized)) {
        verifyFail("Credential-like manifest path: {$path}");
    }
}

function readRequiredFile(string $path, string $label): string
{
    if (!is_file($path)) {
        verifyFail("Missing {$label}: {$path}");
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        verifyFail("Could not read {$label}: {$path}");
    }
    return $contents;
}

function verifyZip(string $zipPath, array $manifestFiles): void
{
    $zip = new ZipArchive();
    $result = $zip->open($zipPath);
    if ($result !== true) {
        verifyFail("Could not open ZIP {$zipPath} (code {$result}).");
    }

    $manifestByPath = [];
    foreach ($manifestFiles as $entry) {
        if (!is_array($entry) || !isset($entry['path'], $entry['sha256'], $entry['size'])) {
            $zip->close();
            verifyFail('Manifest contains an incomplete file entry.');
        }
        $path = normalizePackagePath((string) $entry['path']);
        assertSafePackagePath($path);
        if (!preg_match('/^[0-9a-f]{64}$/i', (string) $entry['sha256'])) {
            $zip->close();
            verifyFail("Invalid SHA-256 in manifest for {$path}.");
        }
        $manifestByPath[$path] = $entry;
    }

    $zipPaths = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $zipEntry = $zip->getNameIndex($index);
        if ($zipEntry === false || str_ends_with($zipEntry, '/')) {
            $zip->close();
            verifyFail('ZIP contains an invalid directory or unreadable entry.');
        }
        $path = normalizePackagePath($zipEntry);
        assertSafePackagePath($path);
        if (!array_key_exists($path, $manifestByPath)) {
            $zip->close();
            verifyFail("ZIP entry is absent from the signed manifest: {$path}");
        }
        $zipPaths[$path] = true;
        $contents = $zip->getFromName($zipEntry);
        if ($contents === false) {
            $zip->close();
            verifyFail("Could not read ZIP entry: {$path}");
        }
        $entry = $manifestByPath[$path];
        $actualHash = hash('sha256', $contents);
        if (!hash_equals(strtolower((string) $entry['sha256']), strtolower($actualHash))) {
            $zip->close();
            verifyFail("SHA-256 mismatch for ZIP entry: {$path}");
        }
        if ((int) $entry['size'] !== strlen($contents)) {
            $zip->close();
            verifyFail("Size mismatch for ZIP entry: {$path}");
        }
    }

    $zip->close();
    if (count($zipPaths) !== count($manifestByPath)) {
        verifyFail('Signed manifest and ZIP entry sets differ.');
    }
}

try {
    $options = getopt('', ['release-dir:', 'target-version:', 'minimum-version:', 'public-key::']);
    $releaseDir = isset($options['release-dir']) ? realpath((string) $options['release-dir']) : false;
    $targetVersion = (string) ($options['target-version'] ?? '');
    $minimumVersion = (string) ($options['minimum-version'] ?? '');
    $publicKey = (string) ($options['public-key'] ?? (__DIR__ . '/../backend/certs/update_public_key.pem'));

    if ($releaseDir === false || !is_dir($releaseDir)) {
        verifyFail('--release-dir must identify an existing directory.');
    }
    verifySemver($targetVersion, 'target version');
    verifySemver($minimumVersion, 'minimum version');
    if (version_compare($targetVersion, $minimumVersion, '<=')) {
        verifyFail("target version {$targetVersion} must be greater than minimum version {$minimumVersion}.");
    }

    $manifestPath = $releaseDir . DIRECTORY_SEPARATOR . 'manifest.json';
    $signaturePath = $releaseDir . DIRECTORY_SEPARATOR . 'manifest.sig';
    $manifestJson = readRequiredFile($manifestPath, 'manifest');
    $signature = readRequiredFile($signaturePath, 'manifest signature');
    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest)) {
        verifyFail('manifest.json is not valid JSON.');
    }
    if (($manifest['version'] ?? null) !== $targetVersion) {
        verifyFail('Manifest version does not match the requested target version.');
    }
    if (($manifest['minimum_version'] ?? null) !== $minimumVersion) {
        verifyFail('Manifest minimum_version does not match the requested baseline.');
    }
    if (($manifest['type'] ?? null) !== 'delta') {
        verifyFail('The v0.0.4 test package must be a Delta manifest.');
    }
    if (!isset($manifest['files']) || !is_array($manifest['files']) || count($manifest['files']) === 0) {
        verifyFail('Manifest must contain at least one deployable file.');
    }

    $signatureService = new ManifestSignatureService();
    if (!$signatureService->verifySignature($manifestJson, $signature, $publicKey)) {
        verifyFail('RSA manifest signature verification failed.');
    }

    $expectedDelta = $releaseDir . DIRECTORY_SEPARATOR . "delta-{$minimumVersion}-to-{$targetVersion}.zip";
    $genericDelta = $releaseDir . DIRECTORY_SEPARATOR . 'delta.zip';
    readRequiredFile($expectedDelta, 'versioned Delta ZIP');
    readRequiredFile($genericDelta, 'generic Delta ZIP');
    readRequiredFile($releaseDir . DIRECTORY_SEPARATOR . 'release-notes.md', 'release notes');

    if (hash_file('sha256', $expectedDelta) !== hash_file('sha256', $genericDelta)) {
        verifyFail('delta.zip does not match the versioned Delta ZIP.');
    }
    verifyZip($expectedDelta, $manifest['files']);

    $allowedAssets = [
        basename($expectedDelta),
        'delta.zip',
        'manifest.json',
        'manifest.sig',
        'release-notes.md',
        'deployment-inventory.json',
        'update_public_key.pem',
    ];
    foreach (scandir($releaseDir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!in_array($name, $allowedAssets, true)) {
            verifyFail("Unexpected or credential-like release asset is present: {$name}");
        }
        if ($name === 'update_public_key.pem') {
            $expectedPublicKey = __DIR__ . '/../backend/certs/update_public_key.pem';
            if (!is_file($expectedPublicKey) || hash_file('sha256', $releaseDir . DIRECTORY_SEPARATOR . $name) !== hash_file('sha256', $expectedPublicKey)) {
                verifyFail('Bundled update_public_key.pem does not match the pinned public key.');
            }
        }
    }

    echo "Release package valid: v{$targetVersion}; RSA signature, manifest hashes, ZIP contents, and asset set verified.\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Release package verification failed: {$error->getMessage()}\n");
    exit(1);
}
