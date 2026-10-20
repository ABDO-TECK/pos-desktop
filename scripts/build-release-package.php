<?php
declare(strict_types=1);

/**
 * Universal Cross-Platform Release Package Builder for GitHub Actions & Local CLI
 * 
 * Supports both:
 *  1. Bootstrap Releases (e.g. v1.1.47-bootstrap -> full-package.zip)
 *  2. Delta Releases (e.g. v1.1.48 -> delta-1.1.47-to-1.1.48.zip)
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';
require_once __DIR__ . '/deployment-inventory.php';

use App\Services\ManifestSignatureService;
use App\Services\UpdateManifestService;

$rootDir = realpath(__DIR__ . '/..');

/**
 * Resolve a release baseline through Git without allowing the ref to become
 * shell syntax. Release automation must use an explicit immutable ref.
 */
function resolveGitCommit(string $rootDir, string $ref, string $label): string
{
    $command = 'git -C ' . escapeshellarg($rootDir) . ' rev-parse --verify ' . escapeshellarg($ref . '^{commit}') . ' 2>&1';
    $output = [];
    exec($command, $output, $exitCode);
    $commit = trim(implode("\n", $output));
    if ($exitCode !== 0 || !preg_match('/^[0-9a-f]{40}$/i', $commit)) {
        throw new RuntimeException("Could not resolve {$label} '{$ref}'.");
    }
    return strtolower($commit);
}

/**
 * Read a tracked file from an already-resolved commit.
 */
function readGitFile(string $rootDir, string $commit, string $relativePath): string
{
    $object = $commit . ':' . ltrim($relativePath, '/');
    $command = 'git -C ' . escapeshellarg($rootDir) . ' show ' . escapeshellarg($object) . ' 2>&1';
    $output = [];
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("Git baseline {$commit} does not contain {$relativePath}.");
    }
    return implode("\n", $output);
}

// Parse CLI options
$options = getopt('', ['tag:', 'from-tag:', 'from-ref:', 'from-version:', 'private-key:', 'output-dir:', 'previous-dist:', 'baseline-inventory:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/build-release-package.php --tag=<tag> [--from-tag=<from_tag>] [--from-ref=<from_ref>] [--from-version=<from_version>] [--private-key=<path>] [--output-dir=<dir>]\n";
    exit(0);
}

$tag = $options['tag'] ?? getenv('RELEASE_TAG') ?: null;
if (!$tag) {
    // Try to get current git tag
    $tag = trim((string) @shell_exec('git describe --tags --exact-match 2>NUL || git describe --tags --exact-match 2>/dev/null'));
}

if (!$tag) {
    fwrite(STDERR, "Error: Release tag not specified. Use --tag=vX.Y.Z or set RELEASE_TAG environment variable.\n");
    exit(1);
}

// 1. Determine version and release type
$isBootstrap = str_contains(strtolower($tag), 'bootstrap');
$cleanVersion = preg_replace('/^v/', '', $tag);
$baseVersion = preg_replace('/-bootstrap$/', '', $cleanVersion);
if (!preg_match('/^\d+\.\d+\.\d+(?:-(?:beta|rc)\.\d+)?$/', $baseVersion)) {
    throw new RuntimeException('Use vX.Y.Z, vX.Y.Z-beta.N or vX.Y.Z-rc.N.');
}
$releaseChannel = str_contains($baseVersion, '-beta.') ? 'beta' : (str_contains($baseVersion, '-rc.') ? 'rc' : 'stable');

echo "════════════════════════════════════════════════════════════════════════════════\n";
echo "🚀 POS Update Engine Release Builder\n";
echo "Tag: {$tag} | Version: {$baseVersion} | Type: " . ($isBootstrap ? 'BOOTSTRAP (Full)' : 'DELTA (Incremental)') . "\n";
echo "════════════════════════════════════════════════════════════════════════════════\n\n";

// 2. Validate version.json match
$versionJsonPath = $rootDir . '/version.json';
if (!file_exists($versionJsonPath)) {
    fwrite(STDERR, "Error: version.json not found at {$versionJsonPath}\n");
    exit(1);
}

$versionData = json_decode((string) file_get_contents($versionJsonPath), true);
$localAppVersion = $versionData['version'] ?? $versionData['application_version'] ?? '';

if ($localAppVersion !== $baseVersion) {
    fwrite(STDERR, "❌ Version Mismatch Error: Git tag version '{$baseVersion}' does not match version.json version '{$localAppVersion}'!\n");
    exit(1);
}
echo "✔ Version match validated: {$baseVersion}\n";

// 3. Resolve Private Key
$privateKeyPem = null;
$tempKeyFile = null;

if (isset($options['private-key'])) {
    if (file_exists($options['private-key'])) {
        $privateKeyPem = file_get_contents($options['private-key']);
    } else {
        fwrite(STDERR, "❌ Error: Specified private key file does not exist: {$options['private-key']}\n");
        exit(1);
    }
} elseif (getenv('UPDATE_PRIVATE_KEY') && trim((string)getenv('UPDATE_PRIVATE_KEY')) !== '') {
    $privateKeyPem = getenv('UPDATE_PRIVATE_KEY');
} elseif (file_exists($rootDir . '/release/private_key.pem')) {
    $privateKeyPem = file_get_contents($rootDir . '/release/private_key.pem');
}


if (!$privateKeyPem || trim($privateKeyPem) === '') {
    fwrite(STDERR, "❌ Error: UPDATE_PRIVATE_KEY secret or private key file is missing.\n");
    exit(1);
}

// Write to ephemeral temp key file with strict permissions
$tempKeyFile = $rootDir . '/backend/storage/.temp_signing_key_' . bin2hex(random_bytes(8)) . '.pem';
@mkdir(dirname($tempKeyFile), 0755, true);
file_put_contents($tempKeyFile, trim($privateKeyPem) . "\n");

// Register shutdown cleanup for private key
register_shutdown_function(static function () use (&$tempKeyFile): void {
    if ($tempKeyFile && file_exists($tempKeyFile)) {
        @unlink($tempKeyFile);
    }
});

// 4. Output directory
$outputDir = $options['output-dir'] ?? ($rootDir . "/release/{$cleanVersion}");
if (!is_dir($outputDir)) {
    @mkdir($outputDir, 0755, true);
}

$sigService = new ManifestSignatureService();
$manifestService = new UpdateManifestService();

if ($isBootstrap) {
    // ══════════════════════════════════════════════════════════════
    // BOOTSTRAP RELEASE GENERATION
    // ══════════════════════════════════════════════════════════════
    echo "📦 Packaging Full Bootstrap Release...\n";

    $excludedPrefixes = [
        '.git/', '.github/', '.env', 'release/', 'storage/', 'backend/storage/',
        'backend/logs/', 'node_modules/', 'dist-electron/', 'backend/vendor/',
        'frontend/node_modules/', 'backend/tests/', 'frontend/src/',
        'backend/.phpunit.result.cache',
    ];

    $includedFolders = [
        'backend/Controllers', 'backend/Helpers', 'backend/Middleware',
        'backend/Models', 'backend/Services', 'backend/certs',
        'backend/config', 'backend/database', 'backend/routes',
        'backend/bootstrap.php', 'backend/server.php',
        'frontend/dist', 'scripts', 'docs', 'version.json', 'package.json',
    ];

    $filesToPack = [];
    $manifestFiles = [];

    foreach ($includedFolders as $item) {
        $fullPath = $rootDir . '/' . $item;
        if (is_file($fullPath)) {
            $rel = $item;
            $filesToPack[] = $rel;
            $manifestFiles[] = [
                'path' => $rel,
                'action' => 'replace',
                'sha256' => hash_file('sha256', $fullPath),
                'size' => filesize($fullPath),
            ];
        } elseif (is_dir($fullPath)) {
            $dirIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($dirIterator as $fileInfo) {
                if ($fileInfo->isDir()) continue;
                $filePath = $fileInfo->getRealPath();
                $rel = str_replace('\\', '/', substr($filePath, strlen($rootDir) + 1));

                $skip = false;
                foreach ($excludedPrefixes as $exc) {
                    if (str_starts_with($rel, $exc)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;

                $filesToPack[] = $rel;
                $manifestFiles[] = [
                    'path' => $rel,
                    'action' => 'replace',
                    'sha256' => hash_file('sha256', $filePath),
                    'size' => filesize($filePath),
                ];
            }
        }
    }

    $zipPath = $outputDir . '/full-package.zip';
    if (file_exists($zipPath)) @unlink($zipPath);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Failed to create ZIP package: {$zipPath}");
    }
    foreach ($filesToPack as $relPath) {
        $zip->addFile($rootDir . '/' . $relPath, $relPath);
    }
    $zip->close();

    $changelog = $versionData['changelog'] ?? [
        "POS Desktop Bootstrap Migration Release v{$baseVersion}",
    ];

    $manifestData = [
        'manifest_version' => '1.0',
        'version' => $baseVersion,
        'type' => 'full',
        'migration_release' => true,
        'minimum_version' => $versionData['minimum_supported_version'] ?? $baseVersion,
        'update_engine_version' => $versionData['update_engine_version'] ?? '1.0.0',
        'channel' => $versionData['channel'] ?? 'stable',
        'released_at' => $versionData['released_at'] ?? date('Y-m-d'),
        'changelog' => $changelog,
        'files' => $manifestFiles,
        'deleted_files' => [],
    ];

} else {
    // ══════════════════════════════════════════════════════════════
    // DELTA RELEASE GENERATION
    // ══════════════════════════════════════════════════════════════
    echo "📦 Packaging Incremental Delta Release...\n";

    $fromTag = isset($options['from-tag']) ? trim((string) $options['from-tag']) : null;
    $fromRef = isset($options['from-ref']) ? trim((string) $options['from-ref']) : ($fromTag ?: null);
    $fromVersionOption = isset($options['from-version']) ? trim((string) $options['from-version']) : '';
    if (!$fromRef || !$fromVersionOption) {
        throw new RuntimeException('Delta release requires an explicit baseline: provide --from-ref (or --from-tag) and --from-version.');
    }

    $fromVersion = $fromVersionOption;
    if (!preg_match('/^\d+\.\d+\.\d+(?:-(?:beta|rc)\.\d+)?$/', $fromVersion)) {
        throw new RuntimeException("Invalid explicit baseline version '{$fromVersionOption}'.");
    }

    $baselineCommit = resolveGitCommit($rootDir, $fromRef, 'baseline ref');
    $baselineVersionData = json_decode(readGitFile($rootDir, $baselineCommit, 'version.json'), true);
    if (!is_array($baselineVersionData)) {
        throw new RuntimeException("Baseline ref '{$fromRef}' contains invalid version.json.");
    }
    $baselineDeclaredVersion = (string) ($baselineVersionData['version'] ?? $baselineVersionData['application_version'] ?? '');
    if ($baselineDeclaredVersion !== $fromVersion) {
        throw new RuntimeException("Baseline ref '{$fromRef}' declares version '{$baselineDeclaredVersion}', not '{$fromVersion}'.");
    }

    echo "Delta comparison: {$fromRef} ({$fromVersion}) -> {$tag} ({$baseVersion})\n";

    if (version_compare($baseVersion, $fromVersion, '<=')) {
        throw new RuntimeException('Delta target must be newer than its baseline.');
    }
    if (($versionData['channel'] ?? $releaseChannel) !== $releaseChannel) {
        throw new RuntimeException('version.json channel does not match its release tag.');
    }
    $baselinePath = $options['baseline-inventory'] ?? '';
    if (!is_file($baselinePath)) throw new RuntimeException('Delta requires --baseline-inventory from the published baseline release.');
    $previous = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
    $inventory = createDeploymentInventory($rootDir, $rootDir);
    if (($previous['format'] ?? null) !== 1 || ($previous['version'] ?? null) !== $fromVersion || !is_array($previous['files'] ?? null)) {
        throw new RuntimeException('Baseline deployment inventory has invalid format/version.');
    }
    if (($previous['shell_sha256'] ?? '') !== $inventory['shell_sha256']) {
        throw new RuntimeException('Electron, packaging or runtime inputs changed: publish a full Setup release.');
    }
    $modifiedFiles = [];
    $deletedFiles = [];
    foreach ($inventory['files'] as $relative => $entry) {
        if (($previous['files'][$relative]['sha256'] ?? null) !== $entry['sha256']) $modifiedFiles[] = $relative;
    }
    foreach ($previous['files'] as $relative => $entry) {
        if (!isset($inventory['files'][$relative])) $deletedFiles[] = $relative;
    }
    $pathManifest = ['files' => array_map(static fn (string $p): array => ['path' => $p], $modifiedFiles), 'deleted_files' => $deletedFiles];
    if (!$manifestService->validateManifestPaths($pathManifest, $rootDir)['ok']) throw new RuntimeException('Unsafe baseline artifact paths.');
    if (empty($modifiedFiles) && empty($deletedFiles)) throw new RuntimeException('No deployed artifact bytes changed.');
    if (!in_array('version.json', $modifiedFiles, true)) throw new RuntimeException('Delta must deploy the target version.json.');
    $migrationCommand = 'git -C ' . escapeshellarg($rootDir) . ' diff --no-renames --name-only --diff-filter=AM ' . escapeshellarg($baselineCommit) . ' HEAD -- database/migrations';
    $migrationOutput = [];
    exec($migrationCommand, $migrationOutput, $migrationExit);
    if ($migrationExit !== 0) throw new RuntimeException('Could not inspect migration changes.');
    $migrationFiles = array_map('basename', array_filter($migrationOutput, static fn (string $p): bool => str_ends_with($p, '.sql')));
    file_put_contents($outputDir . '/deployment-inventory.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    echo "Detected " . count($modifiedFiles) . " modified file(s) and " . count($deletedFiles) . " deleted file(s).\n";

    $manifestFiles = [];
    $zipPath = $outputDir . "/delta-{$fromVersion}-to-{$baseVersion}.zip";
    $genericZipPath = $outputDir . '/delta.zip';

    if (file_exists($zipPath)) @unlink($zipPath);
    if (file_exists($genericZipPath)) @unlink($genericZipPath);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Failed to create ZIP package: {$zipPath}");
    }

    foreach ($modifiedFiles as $relPath) {
        $fullPath = $rootDir . '/' . $relPath;
        $zip->addFile($fullPath, $relPath);
        $manifestFiles[] = [
            'path' => $relPath,
            'action' => 'replace',
            'sha256' => hash_file('sha256', $fullPath),
            'size' => filesize($fullPath),
        ];
    }
    $zip->close();

    // Copy to generic delta.zip
    @copy($zipPath, $genericZipPath);

    // Collect git commits for changelog
    $nullDev = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
    $logRef = $fromTag ?: $baselineCommit;
    $gitLog = (string) @shell_exec('git -C ' . escapeshellarg($rootDir) . ' log ' . escapeshellarg($logRef . '..HEAD') . ' --pretty=format:%s 2>' . $nullDev);
    $commitEntries = array_values(array_filter(array_map('trim', explode("\n", (string)$gitLog))));


    $changelog = !empty($versionData['changelog']) ? $versionData['changelog'] : $commitEntries;
    if (empty($changelog)) {
        $changelog = ["Incremental performance improvements and updates for v{$baseVersion}"];
    }

    $manifestData = [
        'manifest_version' => '1.0',
        'version' => $baseVersion,
        'type' => 'delta',
        'minimum_version' => $fromVersion,
        'from_version' => $fromVersion,
        'baseline_files' => array_map(static fn (array $file): string => $file['sha256'], $previous['files']),
        'update_engine_version' => '1.0.0',
        'channel' => $releaseChannel,
        'released_at' => date('Y-m-d'),
        'changelog' => $changelog,
        'files' => $manifestFiles,
        'deleted_files' => $deletedFiles,
        'migrations' => array_values(array_unique($migrationFiles)),
    ];
}

// 5. Write and Sign Manifest
if (!$isBootstrap) {
    $validation = $manifestService->validateManifest($manifestData);
    if (!$validation['valid']) throw new RuntimeException('Invalid generated manifest: ' . implode('; ', $validation['errors']));
    if (!$manifestService->validateManifestPaths($manifestData, $rootDir)['ok']) throw new RuntimeException('Unsafe generated manifest paths.');
}
$manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$manifestPath = $outputDir . '/manifest.json';
file_put_contents($manifestPath, $manifestJson);

$signature = $sigService->signData($manifestJson, $tempKeyFile);
if (!$signature) {
    throw new RuntimeException("Failed to generate RSA-2048 digital signature.");
}
$sigPath = $outputDir . '/manifest.sig';
file_put_contents($sigPath, $signature);

// Verify signature against public key immediately
$pubKeyPath = $rootDir . '/backend/certs/update_public_key.pem';
if (file_exists($pubKeyPath)) {
    $verified = $sigService->verifySignature($manifestJson, $signature, $pubKeyPath);
    if (!$verified) {
        throw new RuntimeException("CRITICAL: Generated manifest signature failed verification against update_public_key.pem!");
    }
    echo "✔ Cryptographic RSA signature verified against public key.\n";
}

// 6. Generate Customer-Safe release-notes.md
$changelogMd = "";
foreach ($manifestData['changelog'] as $entry) {
    if (is_string($entry)) {
        $changelogMd .= "- {$entry}\n";
    }
}

$releaseNotes = <<<MD
# POS Desktop v{$baseVersion}

### 🌟 Overview
Release **v{$baseVersion}** for POS Desktop.

---

### 🚀 Changelog:
{$changelogMd}

---

### 🔒 Cryptographic Verification:
- **Manifest**: `manifest.json` (RSA-2048 Signed via `manifest.sig`)
- **Type**: {$manifestData['type']}
MD;

file_put_contents($outputDir . '/release-notes.md', $releaseNotes);

// Clean up temporary key
if ($tempKeyFile && file_exists($tempKeyFile)) {
    @unlink($tempKeyFile);
    $tempKeyFile = null;
}

echo "\n🎉 RELEASE PACKAGE READY AT: {$outputDir}\n";
echo "Files created:\n";
foreach (scandir($outputDir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $size = round(filesize($outputDir . '/' . $f) / 1024, 2);
    echo " - {$f} ({$size} KB)\n";
}
echo "\n";
