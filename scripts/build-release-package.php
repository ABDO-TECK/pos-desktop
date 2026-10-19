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
$options = getopt('', ['tag:', 'from-tag:', 'from-ref:', 'from-version:', 'private-key:', 'output-dir:', 'previous-dist:', 'help']);

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
$baseVersion = explode('-', $cleanVersion)[0];

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

    $fromVersion = explode('-', $fromVersionOption)[0];
    if (!preg_match('/^\d+\.\d+\.\d+$/', $fromVersion)) {
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

    // Detect changed files between tags/refs
    $diffCmd = 'git -C ' . escapeshellarg($rootDir) . ' diff --name-status ' . escapeshellarg($baselineCommit) . ' HEAD 2>&1';
    $diffOutput = (string) shell_exec($diffCmd);
    $lines = array_filter(explode("\n", trim($diffOutput)));

    $sourceChanges = [];
    $sourceDeletes = [];
    $migrationFiles = [];

    $ignoredPrefixes = [
        '.git/', '.github/', '.env', 'release/', 'storage/', 'backend/storage/',
        'backend/logs/', 'node_modules/', 'dist-electron/', 'backend/vendor/',
        'frontend/node_modules/', 'backend/tests/',
        'backend/.phpunit.result.cache', 'scratch/',
    ];

    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line), 2);
        if (count($parts) < 2) continue;
        $status = $parts[0];
        $file = str_replace('\\', '/', $parts[1]);

        $skip = false;
        foreach ($ignoredPrefixes as $ig) {
            if (str_starts_with($file, $ig)) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;

        if ($status === 'D') {
            $sourceDeletes[] = $file;
        } else {
            if (file_exists($rootDir . '/' . $file)) {
                $sourceChanges[] = $file;
            }
        }
    }

    // Map source changes to the files that actually exist in an installed
    // app.asar.unpacked tree. PHP source and canonical migrations are shipped
    // together inside backend/backend.phar; frontend source produces Vite
    // artifacts under frontend/dist. Never place source-only paths in a delta.
    $modifiedFiles = [];
    $deletedFiles = [];
    $addArtifact = static function (string $path) use (&$modifiedFiles, $rootDir): void {
        if (is_file($rootDir . '/' . $path) && !in_array($path, $modifiedFiles, true)) {
            $modifiedFiles[] = $path;
        }
    };
    $addFrontendDist = static function () use (&$modifiedFiles, $rootDir): void {
        $distDir = $rootDir . '/frontend/dist';
        if (!is_dir($distDir)) {
            throw new RuntimeException('frontend/dist is missing. Build the frontend before generating a delta release.');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($distDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                $path = 'frontend/dist/' . str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($distDir) + 1));
                if (!in_array($path, $modifiedFiles, true)) $modifiedFiles[] = $path;
            }
        }
    };

    foreach ($sourceChanges as $sourcePath) {
        if (str_starts_with($sourcePath, 'database/migrations/') && str_ends_with($sourcePath, '.sql')) {
            $migrationFiles[] = basename($sourcePath);
        }
        if (str_starts_with($sourcePath, 'backend/') || str_starts_with($sourcePath, 'database/migrations/') || $sourcePath === 'build-phar.php' || $sourcePath === 'scripts/build-phar.mjs') {
            if (str_starts_with($sourcePath, 'backend/certs/')) {
                $addArtifact($sourcePath);
            } else {
                $addArtifact('backend/backend.phar');
            }
        } elseif (str_starts_with($sourcePath, 'frontend/dist/') || str_starts_with($sourcePath, 'frontend/public/')) {
            $addArtifact($sourcePath);
        } elseif (str_starts_with($sourcePath, 'frontend/')) {
            $addFrontendDist();
        } elseif ($sourcePath === 'version.json') {
            $addArtifact('version.json');
        } elseif (str_starts_with($sourcePath, 'electron/') || $sourcePath === 'package.json') {
            throw new RuntimeException("{$sourcePath} is stored in app.asar and cannot be emitted as a PHP delta artifact. Publish a bootstrap desktop release for this change.");
        }
    }

    foreach ($sourceDeletes as $sourcePath) {
        if (str_starts_with($sourcePath, 'frontend/dist/') || str_starts_with($sourcePath, 'frontend/public/')) {
            $deletedFiles[] = $sourcePath;
        } elseif (str_starts_with($sourcePath, 'backend/') || str_starts_with($sourcePath, 'database/migrations/')) {
            $addArtifact('backend/backend.phar');
        }
    }

    // Vite build output is intentionally ignored by Git, so a source diff
    // cannot reveal obsolete hashed files. Release automation may provide the
    // previous deployed frontend tree to emit deletion metadata accurately.
    $previousDist = $options['previous-dist'] ?? null;
    if ($previousDist !== null && $previousDist !== '') {
        $previousDist = rtrim(str_replace('\\', '/', $previousDist), '/');
        if (!is_dir($previousDist)) {
            throw new RuntimeException("Previous frontend deployment directory does not exist: {$previousDist}");
        }
        $currentDist = $rootDir . '/frontend/dist';
        $previousIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($previousDist, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($previousIterator as $fileInfo) {
            if (!$fileInfo->isFile()) continue;
            $relative = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($previousDist) + 1));
            if (!is_file($currentDist . '/' . $relative)) {
                $deployedPath = 'frontend/dist/' . $relative;
                if (!in_array($deployedPath, $deletedFiles, true)) $deletedFiles[] = $deployedPath;
            }
        }
    }

    // version.json is a deployed artifact and the durable local update marker,
    // but do not turn a source-no-op into a meaningless one-file delta.
    if (!empty($modifiedFiles) || !empty($deletedFiles)) {
        $addArtifact('version.json');
    }

    if (empty($modifiedFiles) && empty($deletedFiles)) {
        throw new RuntimeException('No deployable artifacts changed; refusing to generate an empty delta release.');
    }

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
        'update_engine_version' => '1.0.0',
        'channel' => 'stable',
        'released_at' => date('Y-m-d'),
        'changelog' => $changelog,
        'files' => $manifestFiles,
        'deleted_files' => $deletedFiles,
        'migrations' => array_values(array_unique($migrationFiles)),
    ];
}

// 5. Write and Sign Manifest
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
