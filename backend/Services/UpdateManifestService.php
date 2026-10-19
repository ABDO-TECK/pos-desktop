<?php

namespace App\Services;

use App\Helpers\Logger;

class UpdateManifestService
{
    /**
     * Development trees contain source files, but packaged desktops execute
     * only the artifacts below. Keep the production allow-list deliberately
     * small so a signed manifest cannot claim arbitrary app.asar contents.
     */
    private const PACKAGED_ALLOWED_PATH_PREFIXES = [
        'backend/backend.phar',
        'backend/certs/',
        'database/pos_schema.sql',
        'frontend/dist/',
        'frontend/public/',
        'version.json',
    ];

    private const ALLOWED_PATH_PREFIXES = [
        'backend/',
        'frontend/',
        'database/',
        'scripts/',
        'docs/',
        'version.json',
        'package.json',
    ];

    private const BLOCKED_PATH_PATTERNS = [
        '#(^|/)\.env#i',
        '#(^|/)\.git(/|$)#i',
        '#^\.github(/|$)#i',
        '#^backend/storage/#i',
        '#^backend/logs/#i',
        '#^storage/#i',
        '#^node_modules/#i',
        '#(^|/)[^/]*private[^/]*\.pem$#i',
        '#\.(key|crt|cert|lock|log|sqlite|db)$#i',
        '#^backend/Config/database\.sqlite#i',
    ];



    /**
     * Validate manifest structure and return errors or decoded data.
     *
     * @param string|array $manifestContent Raw JSON string or decoded array
     * @return array{valid: bool, errors: list<string>, manifest: array|null}
     */
    public function validateManifest(string|array $manifestContent): array
    {
        $manifest = is_array($manifestContent)
            ? $manifestContent
            : json_decode($manifestContent, true);

        if (!is_array($manifest)) {
            return [
                'valid' => false,
                'errors' => ['Manifest is not valid JSON.'],
                'manifest' => null,
            ];
        }

        $errors = [];
        $seenPaths = [];

        // Required version string
        if (empty($manifest['version']) || !is_string($manifest['version'])) {
            $errors[] = 'Manifest is missing a valid "version" string.';
        } elseif (!$this->isValidSemver($manifest['version'])) {
            $errors[] = "Manifest version '{$manifest['version']}' is not a valid semantic version.";
        }

        // Required minimum_version string (if present or enforced)
        if (isset($manifest['minimum_version'])) {
            if (!is_string($manifest['minimum_version']) || !$this->isValidSemver($manifest['minimum_version'])) {
                $errors[] = 'Manifest "minimum_version" is not a valid semantic version.';
            }
        }

        // Validate update_engine_version string if present
        if (isset($manifest['update_engine_version'])) {
            if (!is_string($manifest['update_engine_version']) || !$this->isValidSemver($manifest['update_engine_version'])) {
                $errors[] = 'Manifest "update_engine_version" is not a valid semantic version.';
            }
        }

        // Validate channel string if present
        if (isset($manifest['channel'])) {
            if (!is_string($manifest['channel']) || !in_array($manifest['channel'], ['stable', 'beta', 'rc'], true)) {
                $errors[] = 'Manifest "channel" must be one of: stable, beta, rc.';
            }
        }

        // Validate rollout_percentage integer if present
        if (isset($manifest['rollout_percentage'])) {
            if (!is_int($manifest['rollout_percentage']) || $manifest['rollout_percentage'] < 1 || $manifest['rollout_percentage'] > 100) {
                $errors[] = 'Manifest "rollout_percentage" must be an integer between 1 and 100.';
            }
        }

        // Validate files array (mandatory for delta updates, optional for full packages)
        $isFullPackage = ($manifest['type'] ?? '') === 'full';
        if (!isset($manifest['files']) || !is_array($manifest['files'])) {
            if (!$isFullPackage) {
                $errors[] = 'Manifest is missing a "files" array.';
            }
        } else {
            foreach ($manifest['files'] as $index => $fileEntry) {
                if (!is_array($fileEntry)) {
                    $errors[] = "File entry at index {$index} must be an object.";
                    continue;
                }

                $filePath = $fileEntry['path'] ?? null;
                if (!is_string($filePath) || trim($filePath) === '') {
                    $errors[] = "File entry at index {$index} is missing a valid 'path'.";
                } else {
                    $normPath = str_replace('\\', '/', trim($filePath));
                    if (isset($seenPaths[$normPath])) {
                        $errors[] = "Duplicate file path detected in manifest: {$normPath}";
                    }
                    $seenPaths[$normPath] = 'file';
                }

                $sha256 = $fileEntry['sha256'] ?? null;
                if (!is_string($sha256) || !$this->isValidSha256($sha256)) {
                    $errors[] = "File '{$filePath}' has an invalid or missing SHA-256 checksum.";
                }

                $action = $fileEntry['action'] ?? 'replace';
                if (!in_array($action, ['replace', 'add', 'delete'], true)) {
                    $errors[] = "File '{$filePath}' has an invalid action '{$action}'. Allowed: replace, add, delete.";
                }

                if (isset($fileEntry['size']) && (!is_int($fileEntry['size']) || $fileEntry['size'] < 0)) {
                    $errors[] = "File '{$filePath}' has an invalid size.";
                }
            }
        }

        // Validate deleted_files array if present
        if (isset($manifest['deleted_files'])) {
            if (!is_array($manifest['deleted_files'])) {
                $errors[] = 'Manifest "deleted_files" must be an array of paths.';
            } else {
                foreach ($manifest['deleted_files'] as $index => $deletedPath) {
                    if (!is_string($deletedPath) || trim($deletedPath) === '') {
                        $errors[] = "Deleted file at index {$index} is invalid.";
                    } else {
                        $normPath = str_replace('\\', '/', trim($deletedPath));
                        if (isset($seenPaths[$normPath])) {
                            $errors[] = "File path '{$normPath}' cannot be present in both 'files' and 'deleted_files'.";
                        }
                        $seenPaths[$normPath] = 'deleted';
                    }
                }
            }
        }

        if (isset($manifest['migrations']) && (!is_array($manifest['migrations']) || array_filter($manifest['migrations'], static fn ($migration): bool => !is_string($migration) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\\.sql$/', $migration)))) {
            $errors[] = 'Manifest "migrations" must contain canonical SQL migration filenames only.';
        }


        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'manifest' => empty($errors) ? $manifest : null,
        ];
    }

    /**
     * Check if client update engine is compatible with the release manifest.
     *
     * @param string|null $clientEngineVersion Current engine version installed on client (null for legacy clients)
     * @param array $manifest Manifest data
     * @return array{compatible: bool, reason: string|null, requires_bootstrap: bool}
     */
    public function checkEngineCompatibility(?string $clientEngineVersion, array $manifest): array
    {
        $requiredEngineVersion = $manifest['update_engine_version'] ?? null;
        $isMigrationRelease = !empty($manifest['migration_release']) || ($manifest['type'] ?? '') === 'full';

        // If manifest does not specify an engine constraint
        if ($requiredEngineVersion === null) {
            if ($isMigrationRelease) {
                return [
                    'compatible' => true,
                    'reason' => 'Release is a full/migration package.',
                    'requires_bootstrap' => false,
                ];
            }
            return [
                'compatible' => true,
                'reason' => null,
                'requires_bootstrap' => false,
            ];
        }

        // Legacy client without update engine installed (e.g. v1.1.46 and earlier)
        if ($clientEngineVersion === null || trim($clientEngineVersion) === '') {
            if ($isMigrationRelease) {
                return [
                    'compatible' => true,
                    'reason' => 'Client is legacy; full bootstrap release is available.',
                    'requires_bootstrap' => true,
                ];
            }

            return [
                'compatible' => false,
                'reason' => 'Client lacks delta update engine. Full bootstrap update is required.',
                'requires_bootstrap' => true,
            ];
        }

        if ($isMigrationRelease) {
            return [
                'compatible' => true,
                'reason' => 'Full bootstrap/migration release is compatible across all engine versions.',
                'requires_bootstrap' => false,
            ];
        }

        if (version_compare($clientEngineVersion, $requiredEngineVersion, '<')) {
            return [
                'compatible' => false,
                'reason' => "Client update engine v{$clientEngineVersion} is below required engine v{$requiredEngineVersion}. Bootstrap update required.",
                'requires_bootstrap' => true,
            ];
        }

        return [
            'compatible' => true,
            'reason' => null,
            'requires_bootstrap' => false,
        ];

    }

    /**
     * Check if release channel matches client channel preference.
     *
     * Rules:
     * - 'stable' client receives ONLY 'stable' releases.
     * - 'rc' client receives 'rc' and 'stable' releases.
     * - 'beta' client receives 'beta', 'rc', and 'stable' releases.
     *
     * @param string $clientChannel Client update channel ('stable', 'beta', 'rc')
     * @param string|null $releaseChannel Channel specified in release manifest (defaults to 'stable')
     * @return array{compatible: bool, reason: string|null}
     */
    public function checkChannelCompatibility(string $clientChannel, ?string $releaseChannel = 'stable'): array
    {
        $relChannel = strtolower(trim($releaseChannel ?: 'stable'));
        $cliChannel = strtolower(trim($clientChannel ?: 'stable'));

        if (!in_array($cliChannel, ['stable', 'beta', 'rc'], true)) {
            $cliChannel = 'stable';
        }
        if (!in_array($relChannel, ['stable', 'beta', 'rc'], true)) {
            $relChannel = 'stable';
        }

        // Stable clients only accept stable releases
        if ($cliChannel === 'stable') {
            if ($relChannel !== 'stable') {
                return [
                    'compatible' => false,
                    'reason' => "Release is on '{$relChannel}' channel, but client is configured for 'stable' updates only.",
                ];
            }
            return ['compatible' => true, 'reason' => null];
        }

        // RC clients accept stable and rc
        if ($cliChannel === 'rc') {
            if ($relChannel === 'beta') {
                return [
                    'compatible' => false,
                    'reason' => "Release is on 'beta' channel, but client is configured for 'rc' updates.",
                ];
            }
            return ['compatible' => true, 'reason' => null];
        }

        // Beta clients accept all channels (beta, rc, stable)
        return ['compatible' => true, 'reason' => null];
    }

    /**
     * Calculate deterministic gradual rollout bucket for a device and version.
     *
     * @param string $deviceId Unique stable device identifier
     * @param string $targetVersion Target release version
     * @param int $rolloutPercentage Percentage of devices to receive update (1-100)
     * @return array{eligible: bool, bucket: int, rollout_percentage: int, reason: string|null}
     */
    public function checkRolloutEligibility(string $deviceId, string $targetVersion, int $rolloutPercentage = 100): array
    {
        $rollout = max(1, min(100, $rolloutPercentage));
        
        if ($rollout >= 100) {
            return [
                'eligible' => true,
                'bucket' => 1,
                'rollout_percentage' => 100,
                'reason' => null,
            ];
        }

        // Deterministic hash bucket: 1 to 100
        $hashHex = substr(hash('sha256', "pos:rollout:{$deviceId}:{$targetVersion}"), 0, 8);
        $bucket = (int) ((hexdec($hashHex) % 100) + 1);

        $eligible = ($bucket <= $rollout);

        return [
            'eligible' => $eligible,
            'bucket' => $bucket,
            'rollout_percentage' => $rollout,
            'reason' => $eligible ? null : "Device bucket #{$bucket} is outside the current {$rollout}% gradual rollout group.",
        ];
    }




    /**
     * Check if a manifest is compatible with the currently installed version.
     *
     * @param string $currentVersion Current app version
     * @param array $manifest Validated manifest array
     * @param bool $allowDowngrade Whether to allow reinstalling same/lower version
     * @return array{compatible: bool, reason: string|null}
     */
    public function checkVersionCompatibility(
        string $currentVersion,
        array $manifest,
        bool $allowDowngrade = false
    ): array {
        $targetVersion = $manifest['version'] ?? '0.0.0';
        $minimumVersion = $manifest['minimum_version'] ?? null;

        if (!$allowDowngrade && version_compare($targetVersion, $currentVersion, '<=')) {
            return [
                'compatible' => false,
                'reason' => "Target version v{$targetVersion} is not newer than current version v{$currentVersion}.",
            ];
        }

        if ($minimumVersion !== null && version_compare($currentVersion, $minimumVersion, '<')) {
            return [
                'compatible' => false,
                'reason' => "Current version v{$currentVersion} is below the minimum required version v{$minimumVersion} for this update. A full update is required.",
            ];
        }

        return [
            'compatible' => true,
            'reason' => null,
        ];
    }

    /**
     * Validate that a relative path is safe and within the project boundaries.
     *
     * @param string $relativePath Relative file path from application root
     * @param string $rootDir Absolute application root directory
     * @return bool True if safe, false otherwise
     */
    public function isPathSafe(string $relativePath, string $rootDir): bool
    {
        // Reject null bytes, carriage returns, or control characters
        if (preg_match('/[\x00-\x1F\x7F]/', $relativePath)) {
            return false;
        }

        // Standardize directory separators
        $normalized = str_replace('\\', '/', trim($relativePath));

        // Reject absolute paths, drive letters, stream wrappers
        if (
            str_starts_with($normalized, '/') ||
            preg_match('/^[a-zA-Z]:/', $normalized) ||
            preg_match('#^[a-zA-Z0-9\.\-\+]+://#', $normalized)
        ) {
            return false;
        }

        // Reject directory traversal segments
        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }

        // Check against blocked patterns (e.g. .env, .git, storage, secrets)
        foreach (self::BLOCKED_PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return false;
            }
        }

        // Packaged customers only have deployable artifacts under
        // app.asar.unpacked. Source paths are accepted solely for development
        // installations, where the PHAR artifact is absent.
        $allowedPrefixes = $this->isPackagedDeploymentRoot($rootDir)
            ? self::PACKAGED_ALLOWED_PATH_PREFIXES
            : self::ALLOWED_PATH_PREFIXES;

        // Must match an allowed path prefix
        $isAllowedPrefix = false;
        foreach ($allowedPrefixes as $prefix) {
            if ($normalized === $prefix || str_starts_with($normalized, $prefix)) {
                $isAllowedPrefix = true;
                break;
            }
        }
        if (!$isAllowedPrefix) {
            return false;
        }

        // Canonical root directory check
        $realRoot = realpath($rootDir) ?: $rootDir;
        $realRootNormalized = rtrim(str_replace('\\', '/', $realRoot), '/');
        $targetCandidate = $realRootNormalized . '/' . $normalized;

        // Ensure the path does not escape the root
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $targetCandidate)) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        $resolved = (str_starts_with($targetCandidate, '/') ? '/' : '') . implode('/', $parts);

        return str_starts_with($resolved, $realRootNormalized . '/');
    }

    private function isPackagedDeploymentRoot(string $rootDir): bool
    {
        return is_file(rtrim(str_replace('\\', '/', $rootDir), '/') . '/backend/backend.phar');
    }

    /**
     * Validate all paths in a manifest against the given root directory.
     *
     * @param array $manifest Validated manifest
     * @param string $rootDir Absolute application root directory
     * @return array{ok: bool, unsafe_paths: list<string>}
     */
    public function validateManifestPaths(array $manifest, string $rootDir): array
    {
        $unsafePaths = [];

        foreach ($manifest['files'] ?? [] as $file) {
            $path = $file['path'] ?? '';
            if (!$this->isPathSafe($path, $rootDir)) {
                $unsafePaths[] = $path;
            }
        }

        foreach ($manifest['deleted_files'] ?? [] as $path) {
            if (!$this->isPathSafe($path, $rootDir)) {
                $unsafePaths[] = $path;
            }
        }

        return [
            'ok' => empty($unsafePaths),
            'unsafe_paths' => $unsafePaths,
        ];
    }

    /**
     * Verify the SHA-256 hash of a file.
     *
     * @param string $filePath Full filesystem path
     * @param string $expectedHash Expected 64-char hex SHA-256 hash
     * @return bool
     */
    public function verifyFileHash(string $filePath, string $expectedHash): bool
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return false;
        }

        $calculated = hash_file('sha256', $filePath);
        if ($calculated === false) {
            return false;
        }

        return hash_equals(strtolower($expectedHash), strtolower($calculated));
    }

    /**
     * Verify all staged files against the manifest definitions.
     *
     * @param string $stagingDir Staging directory containing downloaded files
     * @param array $files Files array from manifest
     * @return array{ok: bool, failed_files: list<array{path: string, reason: string}>, verified_count: int}
     */
    public function verifyStagedFiles(string $stagingDir, array $files): array
    {
        $stagingDirNormalized = rtrim(str_replace('\\', '/', $stagingDir), '/');
        $failedFiles = [];
        $verifiedCount = 0;

        foreach ($files as $fileEntry) {
            $relativePath = str_replace('\\', '/', $fileEntry['path'] ?? '');
            $action = $fileEntry['action'] ?? 'replace';

            if ($action === 'delete') {
                continue;
            }

            $stagedPath = $stagingDirNormalized . '/' . $relativePath;

            if (!is_file($stagedPath)) {
                $failedFiles[] = [
                    'path' => $relativePath,
                    'reason' => 'Staged file is missing from staging directory.',
                ];
                continue;
            }

            $expectedHash = $fileEntry['sha256'] ?? '';
            if (!$this->verifyFileHash($stagedPath, $expectedHash)) {
                $actualHash = hash_file('sha256', $stagedPath) ?: 'unreadable';
                $failedFiles[] = [
                    'path' => $relativePath,
                    'reason' => "SHA-256 mismatch. Expected: {$expectedHash}, Actual: {$actualHash}",
                ];
                continue;
            }

            if (isset($fileEntry['size'])) {
                $actualSize = filesize($stagedPath);
                if ($actualSize !== (int) $fileEntry['size']) {
                    $failedFiles[] = [
                        'path' => $relativePath,
                        'reason' => "File size mismatch. Expected: {$fileEntry['size']} bytes, Actual: {$actualSize} bytes.",
                    ];
                    continue;
                }
            }

            $verifiedCount++;
        }

        return [
            'ok' => empty($failedFiles),
            'failed_files' => $failedFiles,
            'verified_count' => $verifiedCount,
        ];
    }

    private function isValidSemver(string $version): bool
    {
        return (bool) preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z\.-]+)?$/', trim($version));
    }

    private function isValidSha256(string $hash): bool
    {
        return (bool) preg_match('/^[0-9a-fA-F]{64}$/', trim($hash));
    }
}
