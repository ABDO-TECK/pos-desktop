<?php
declare(strict_types=1);

namespace App\Services;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * ProductInventoryService
 *
 * Implements Format 1 full PRODUCT physical inventory and strict default-deny
 * path policy for Windows packaged installations (win32-x64).
 */
final class ProductInventoryService
{
    public const PLATFORM = 'win32';
    public const ARCH = 'x64';
    public const MAX_FILES = 20000;
    public const MAX_PATH_LENGTH = 260;
    public const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * Managed Electron root files needed for normal packaged tree.
     */
    private const ELECTRON_ROOT_PATTERN = '/^(?:POS Desktop\.exe|uninstall-data\.ps1|icudtl\.dat|v8_context_snapshot\.bin|snapshot_blob\.bin|resources\.pak|chrome_100_percent\.pak|chrome_200_percent\.pak|d3dcompiler_47\.dll|ffmpeg\.dll|libEGL\.dll|libGLESv2\.dll|vk_swiftshader\.dll|vk_swiftshader_icd\.json|vulkan-1\.dll|dxil\.dll|dxcompiler\.dll|LICENSE(?:\.[A-Za-z0-9_.-]+)*\.txt|LICENSES(?:\.[A-Za-z0-9_.-]+)*\.html|version)$/D';

    /**
     * Required physical artifacts in an as-built Windows installation.
     */
    public const REQUIRED_PHYSICAL_FILES = [
        'POS Desktop.exe',
        'resources/app.asar',
        'resources/app.asar.unpacked/version.json',
    ];

    /**
     * Compute canonical retained-shell combined digest.
     *
     * Domain: sha256(JSON{"POS Desktop.exe":exeHash,"resources/app.asar":asarHash} keys ordered).
     *
     * @param string|array<string, mixed> $filesOrExe Either an array of files or the exe hash string
     * @param string|null $asarHash Optional asar hash if first parameter is exe hash string
     */
    public static function computeCanonicalShellDigest(string|array $filesOrExe, ?string $asarHash = null): string
    {
        $exe = null;
        $asar = null;

        if (is_string($filesOrExe) && is_string($asarHash)) {
            $exe = $filesOrExe;
            $asar = $asarHash;
        } elseif (is_array($filesOrExe)) {
            $files = $filesOrExe['files'] ?? $filesOrExe;
            if (is_array($files)) {
                $exeEntry = $files['POS Desktop.exe'] ?? null;
                $asarEntry = $files['resources/app.asar'] ?? null;

                $exe = is_array($exeEntry) ? ($exeEntry['sha256'] ?? null) : $exeEntry;
                $asar = is_array($asarEntry) ? ($asarEntry['sha256'] ?? null) : $asarEntry;
            }
        }

        if (!is_string($exe) || preg_match('/^[a-f0-9]{64}$/D', $exe) !== 1) {
            throw new RuntimeException('Invalid or missing POS Desktop.exe sha256 for canonical shell digest.');
        }
        if (!is_string($asar) || preg_match('/^[a-f0-9]{64}$/D', $asar) !== 1) {
            throw new RuntimeException('Invalid or missing resources/app.asar sha256 for canonical shell digest.');
        }

        $payload = json_encode([
            'POS Desktop.exe' => strtolower($exe),
            'resources/app.asar' => strtolower($asar),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    /**
     * Identify protected known user-state files and directories that must never
     * be traversed, included in inventory, or mutated.
     */
    public static function isProtectedPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', trim($path));
        $lower = strtolower($normalized);

        // Device certificates / printing certs
        if (str_contains($lower, 'digital-certificate') || str_contains($lower, 'devicecert') || str_contains($lower, 'device-cert') || str_contains($lower, 'device_cert')) {
            return true;
        }

        // Environment configuration
        if (preg_match('/(^|\/)\.env/i', $normalized)) {
            return true;
        }

        // VCS state
        if (preg_match('/(^|\/)\.git(\/|$)/i', $normalized) || preg_match('/^\.github(\/|$)/i', $normalized)) {
            return true;
        }

        // Application runtime storage, logs, caches, temp directories
        if (preg_match('/(^|\/)(?:storage|logs|cache|caches|gpucache|userdata|temp|tmp)(\/|$)/i', $normalized)) {
            return true;
        }

        // Database data directories (e.g. mysql/data, portable mysql/data)
        if (preg_match('/(^|\/)mysql\/data(\/|$)/i', $normalized)) {
            return true;
        }

        // Database and transient files
        if (preg_match('/\.(?:sqlite|sqlite3|db|db3|key|pid|dump|bak|tmp|log)$/i', $normalized)) {
            return true;
        }

        // SQL files except the schema definition in unpacked app
        if (preg_match('/\.sql$/i', $normalized) && $normalized !== 'resources/app.asar.unpacked/database/pos_schema.sql') {
            return true;
        }

        // Private keys and credentials
        if (preg_match('/(^|\/)[^\/]*private[^\/]*\.(?:pem|key)$/i', $normalized) || preg_match('/(^|\/)[^\/]*privatekey[^\/]*$/i', $normalized)) {
            return true;
        }

        return false;
    }

    /**
     * Validate canonical syntax for a relative product path.
     *
     * @return array{ok: bool, reason_code: ?string}
     */
    public static function validatePathSyntax(string $path): array
    {
        if ($path === '' || strlen($path) > self::MAX_PATH_LENGTH) {
            return ['ok' => false, 'reason_code' => 'path_invalid'];
        }

        // Backslashes are strictly forbidden; forward slashes relative only
        if (str_contains($path, '\\')) {
            return ['ok' => false, 'reason_code' => 'path_invalid'];
        }

        // No absolute paths, drive letters, UNC prefixes, or double slashes
        if (str_starts_with($path, '/') || str_contains($path, '//') || preg_match('/^[A-Za-z]:/D', $path)) {
            return ['ok' => false, 'reason_code' => 'path_invalid'];
        }

        // No control characters, colon (NTFS ADS), or percent-encoded traversal
        if (preg_match('/[\x00-\x1f\x7f]/', $path) || str_contains($path, ':') || preg_match('/%[0-9a-fA-F]{2}/', $path)) {
            return ['ok' => false, 'reason_code' => 'path_invalid'];
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return ['ok' => false, 'reason_code' => 'path_invalid'];
            }
            if (preg_match('/[. ]$/D', $segment) || preg_match('/^ /D', $segment)) {
                return ['ok' => false, 'reason_code' => 'path_invalid'];
            }
            if (preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $segment)) {
                return ['ok' => false, 'reason_code' => 'path_invalid'];
            }
            if (preg_match('/~[0-9]/', $segment)) {
                return ['ok' => false, 'reason_code' => 'path_invalid'];
            }
        }

        return ['ok' => true, 'reason_code' => null];
    }

    /**
     * Validate a single relative product path against strict default-deny policy.
     *
     * @return array{ok: bool, reason_code: ?string}
     */
    public static function validateProductPath(string $path): array
    {
        $syntax = self::validatePathSyntax($path);
        if (!$syntax['ok']) {
            return $syntax;
        }

        // Strict default-deny security sentinels (userdata, credentials, logs, caches, databases)
        if (self::isProtectedPath($path)) {
            return ['ok' => false, 'reason_code' => 'path_protected'];
        }

        // Explicit Allowlist Checks:
        // 1. Managed Electron root binaries and resources
        if (preg_match(self::ELECTRON_ROOT_PATTERN, $path)) {
            return ['ok' => true, 'reason_code' => null];
        }

        // 2. Electron locales
        if (preg_match('/^locales\/[A-Za-z0-9_.-]+\.pak$/D', $path)) {
            return ['ok' => true, 'reason_code' => null];
        }

        // 3. resources/app.asar
        if ($path === 'resources/app.asar') {
            return ['ok' => true, 'reason_code' => null];
        }

        // 4. resources/app.asar.unpacked packaged app tree
        if (preg_match('/^resources\/app\.asar\.unpacked\/(?:version\.json|package\.json|backend\/backend\.phar|backend\/certs\/cacert\.pem|backend\/certs\/update_public_key\.pem|database\/pos_schema\.sql)$/D', $path)) {
            return ['ok' => true, 'reason_code' => null];
        }
        if (preg_match('/^resources\/app\.asar\.unpacked\/frontend\/(?:dist|public)\/[A-Za-z0-9_@+.\/ -]+$/D', $path)) {
            return ['ok' => true, 'reason_code' => null];
        }
        if (preg_match('/^resources\/app\.asar\.unpacked\/node_modules\/[A-Za-z0-9_@+.\/ -]+\.node$/D', $path)) {
            return ['ok' => true, 'reason_code' => null];
        }

        // 5. resources/portable runtime tree (explicit allowlist from desktop-runtime.json / build)
        if ($path === 'resources/portable/runtime-manifest.json') {
            return ['ok' => true, 'reason_code' => null];
        }
        if (preg_match('/^resources\/portable\/php\/(?:php\.exe|php\.ini|php\.ini-production|php\.ini-development|libsqlite3\.dll|libcrypto-[A-Za-z0-9_.-]+\.dll|libssl-[A-Za-z0-9_.-]+\.dll|libssh2\.dll|nghttp2\.dll)$/D', $path)) {
            return ['ok' => true, 'reason_code' => null];
        }
        if (preg_match('/^resources\/portable\/php\/ext\/php_[a-z0-9_]+\.dll$/D', $path)) {
            return ['ok' => true, 'reason_code' => null];
        }
        if (preg_match('/^resources\/portable\/mysql\/(?:bin\/mysqld\.exe|bin\/mysql\.exe|bin\/mysqladmin\.exe|bin\/mysql_install_db\.exe|my\.ini)$/D', $path)) {
            return ['ok' => true, 'reason_code' => null];
        }
        if (preg_match('/^resources\/portable\/mysql\/share\/[A-Za-z0-9_.\/ -]+$/D', $path)) {
            return ['ok' => true, 'reason_code' => null];
        }

        return ['ok' => false, 'reason_code' => 'path_unauthorized'];
    }

    /**
     * Validate an entire set of product paths for collisions and policy compliance.
     * Enforces typed strings, canonical syntax, case collisions, and ancestor collisions
     * BEFORE checking against the allowlist.
     *
     * @param list<string> $paths
     * @return array{ok: bool, reason_code: ?string}
     */
    public static function validatePathSet(array $paths): array
    {
        $aliases = [];
        $fileMap = [];

        // 1. Enforce typed string paths + canonical syntax + case collisions globally
        foreach ($paths as $path) {
            if (!is_string($path)) {
                return ['ok' => false, 'reason_code' => 'path_invalid'];
            }

            $syntax = self::validatePathSyntax($path);
            if (!$syntax['ok']) {
                return $syntax;
            }

            $lower = strtolower($path);
            if (isset($aliases[$lower])) {
                return ['ok' => false, 'reason_code' => 'case_collision'];
            }
            $aliases[$lower] = $path;
            $fileMap[$path] = true;
        }

        // 2. Ancestor collision check globally:
        // A path cannot be both a file and a directory ancestor of another file
        // (evaluated BEFORE allowlist so colliding paths like resources/app.asar and resources/app.asar/subfile.js
        // report ancestor_collision, not path_unauthorized)
        $lowerFileMap = [];
        foreach ($fileMap as $path => $_) {
            $lowerFileMap[strtolower($path)] = true;
        }

        foreach ($fileMap as $path => $_) {
            $parts = explode('/', $path);
            $count = count($parts);
            $prefix = '';
            for ($i = 0; $i < $count - 1; $i++) {
                $prefix = ($i === 0) ? $parts[0] : $prefix . '/' . $parts[$i];
                if (isset($fileMap[$prefix]) || isset($lowerFileMap[strtolower($prefix)])) {
                    return ['ok' => false, 'reason_code' => 'ancestor_collision'];
                }
            }
        }

        // 3. Allowlist and protection policy checks
        foreach ($paths as $path) {
            $validation = self::validateProductPath($path);
            if (!$validation['ok']) {
                return $validation;
            }
        }

        return ['ok' => true, 'reason_code' => null];
    }

    /**
     * Resolve an absolute LOCAL Windows drive path including missing terminal segments
     * using the nearest existing ancestor. Rejects traversal, UNC/device paths, NTFS ADS,
     * 8.3 aliases, and reparse points/symlinks/junctions in the complete ancestor chain.
     *
     * @param string $path Absolute local Windows path (e.g. C:\foo\bar or C:/foo/bar)
     * @param bool $requireExists If true, the full path must exist; if false, missing terminal segments are permitted
     * @return string Canonical absolute path
     * @throws RuntimeException On any policy violation, reparse point, or access failure
     */
    public static function resolveAbsolutePath(string $path, bool $requireExists = true): string
    {
        if ($path === '' || strlen($path) > self::MAX_PATH_LENGTH) {
            throw new RuntimeException("Invalid path: empty or exceeds max length: '{$path}'");
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new RuntimeException("Invalid path: contains control characters: '{$path}'");
        }

        // Reject UNC paths (\\, //) and device paths (\\.\, \\?\)
        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//') || str_starts_with($path, '\\\\?\\') || str_starts_with($path, '\\\\.\\')) {
            throw new RuntimeException("Invalid path: UNC or device namespace paths are rejected: '{$path}'");
        }

        // Must be a Windows drive absolute local path: e.g. C:\ or C:/
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            throw new RuntimeException("Invalid path: must be an absolute Windows drive path (e.g. C:\\...): '{$path}'");
        }

        // NTFS Alternative Data Stream (ADS) check: colon allowed only as drive delimiter at index 1
        if (strpos($path, ':', 2) !== false) {
            throw new RuntimeException("Invalid path: NTFS alternate data streams or extra colons rejected: '{$path}'");
        }

        $drive = strtoupper(substr($path, 0, 2));
        $remainder = substr($path, 2);

        // Normalize slashes for segment decomposition
        $normalizedRemainder = str_replace('\\', '/', $remainder);
        if (str_contains($normalizedRemainder, '//')) {
            throw new RuntimeException("Invalid path: double slashes rejected: '{$path}'");
        }

        $trimmedRemainder = trim($normalizedRemainder, '/');
        $segments = $trimmedRemainder === '' ? [] : explode('/', $trimmedRemainder);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException("Invalid path: traversal segment or empty segment rejected: '{$path}'");
            }
            if (preg_match('/[. ]$/D', $segment) || preg_match('/^ /D', $segment)) {
                throw new RuntimeException("Invalid path: trailing dot/space or leading space rejected: '{$segment}'");
            }
            if (preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $segment)) {
                throw new RuntimeException("Invalid path: reserved Windows device name rejected: '{$segment}'");
            }
            if (preg_match('/~[0-9]/', $segment)) {
                throw new RuntimeException("Invalid path: 8.3 alias rejected: '{$segment}'");
            }
        }

        // Root drive inspection (e.g. C:\)
        $rootDrive = $drive . '\\';
        $rootReal = realpath($rootDrive);
        if ($rootReal === false || is_link($rootDrive)) {
            throw new RuntimeException("Cannot resolve root drive: '{$rootDrive}'");
        }

        if (empty($segments)) {
            return rtrim($rootReal, '\\') . '\\';
        }

        return self::walkPathSegments(rtrim($rootReal, '\\'), $segments, $requireExists, null);
    }

    /**
     * Walk and validate path segments from an existing canonical base directory.
     * Enforces that every existing ancestor is a directory, detects missing segments
     * ONLY through successful parent directory listing and absence, and rejects
     * junctions, symlinks, 8.3 aliases, and boundary escapes.
     *
     * @param string $baseCanonical Absolute canonical existing directory path
     * @param list<string> $segments Relative path segments
     * @param bool $requireExists Whether missing terminal segments are forbidden
     * @param string|null $confinementRoot If provided, all resolved paths must stay within this root
     * @return string Canonical resolved path
     * @throws RuntimeException On any policy violation, reparse point, unreadable directory, or missing path
     */
    private static function walkPathSegments(string $baseCanonical, array $segments, bool $requireExists, ?string $confinementRoot): string
    {
        $currentCanonical = rtrim(str_replace('/', '\\', $baseCanonical), '\\');
        $normRoot = $confinementRoot !== null ? rtrim(strtolower(str_replace('/', '\\', $confinementRoot)), '\\') : null;
        $existsEncountered = true;
        $total = count($segments);

        for ($i = 0; $i < $total; $i++) {
            $segment = $segments[$i];

            if ($existsEncountered) {
                // Parent must be an existing directory
                if (!is_dir($currentCanonical)) {
                    throw new RuntimeException("Parent path is not a directory: '{$currentCanonical}'");
                }

                // Parent must be readable and listable
                $parentEntries = @scandir($currentCanonical);
                if ($parentEntries === false) {
                    throw new RuntimeException("Cannot inspect parent directory: '{$currentCanonical}'");
                }

                // Check presence in parent using case-insensitive comparison
                $foundInParent = false;
                foreach ($parentEntries as $entry) {
                    if (strcasecmp($entry, $segment) === 0) {
                        $foundInParent = true;
                        break;
                    }
                }

                if ($foundInParent) {
                    $currentCandidate = $currentCanonical . DIRECTORY_SEPARATOR . $segment;

                    $lstat = @lstat($currentCandidate);
                    if ($lstat === false) {
                        throw new RuntimeException("Failed to inspect existing entry in parent directory: '{$currentCandidate}'");
                    }

                    if (is_link($currentCandidate) || (($lstat['mode'] & 0120000) === 0120000)) {
                        throw new RuntimeException("Reparse point or symbolic link rejected in path: '{$currentCandidate}'");
                    }

                    $segReal = realpath($currentCandidate);
                    if ($segReal === false) {
                        throw new RuntimeException("Failed to resolve realpath for segment: '{$currentCandidate}'");
                    }

                    $expectedReal = $currentCanonical . DIRECTORY_SEPARATOR . $segment;
                    if (strcasecmp(str_replace('/', '\\', $expectedReal), str_replace('/', '\\', $segReal)) !== 0) {
                        throw new RuntimeException("Reparse point, junction, or alias rejected: '{$currentCandidate}'");
                    }

                    if ($normRoot !== null) {
                        $normSeg = strtolower(str_replace('/', '\\', $segReal));
                        if ($normSeg !== $normRoot && !str_starts_with($normSeg, $normRoot . '\\')) {
                            throw new RuntimeException("Path reparse escapes root boundary: '{$currentCandidate}'");
                        }
                    }

                    // Every non-terminal existing segment MUST be a directory
                    if ($i < $total - 1 && !is_dir($segReal)) {
                        throw new RuntimeException("Non-terminal path segment is not a directory: '{$currentCandidate}'");
                    }

                    $currentCanonical = $segReal;
                } else {
                    // Truly missing from readable parent directory
                    if ($requireExists) {
                        throw new RuntimeException("Required path does not exist: '{$currentCanonical}" . DIRECTORY_SEPARATOR . "{$segment}'");
                    }
                    $existsEncountered = false;
                    $currentCanonical = $currentCanonical . DIRECTORY_SEPARATOR . $segment;
                }
            } else {
                // Ancestor was already missing
                if ($requireExists) {
                    throw new RuntimeException("Required path does not exist: '{$currentCanonical}" . DIRECTORY_SEPARATOR . "{$segment}'");
                }
                $currentCanonical = $currentCanonical . DIRECTORY_SEPARATOR . $segment;
            }
        }

        return $currentCanonical;
    }

    /**
     * Resolve a relative product path strictly confined within a product root directory.
     * Enforces safe lexical product-relative path and canonical match for every existing
     * ancestor up to the root, rejecting symlinks, junctions, reparse points, and escapes.
     *
     * @param string $root Absolute product root directory
     * @param string $relative Safe relative product path
     * @param bool $requireExists Whether the target file must exist
     * @return string Canonical absolute path
     * @throws RuntimeException On any confinement violation or reparse point
     */
    public static function resolveConfinedPath(string $root, string $relative, bool $requireExists = true): string
    {
        if ($relative === '') {
            throw new RuntimeException("Invalid relative path for path confinement check: empty string");
        }

        // Validate relative path syntax
        $syntax = self::validatePathSyntax($relative);
        if (!$syntax['ok']) {
            throw new RuntimeException("Unsafe relative path for confinement check: '{$relative}' ({$syntax['reason_code']})");
        }

        // Resolve and verify root path itself (must be local, existing directory, no reparse points)
        $canonicalRoot = self::resolveAbsolutePath($root, true);
        if (!is_dir($canonicalRoot)) {
            throw new RuntimeException("Confinement root is not a directory: '{$canonicalRoot}'");
        }

        $segments = explode('/', $relative);
        return self::walkPathSegments($canonicalRoot, $segments, $requireExists, $canonicalRoot);
    }

    /**
     * Build physical product inventory Format 1 from an as-built Windows installation root.
     *
     * @return array{
     *     format: int,
     *     platform: string,
     *     arch: string,
     *     version: string,
     *     native_version: string,
     *     native_shell: array{
     *         sha256: string,
     *         files: array<string, array{sha256: string, size: int}>
     *     },
     *     files: array<string, array{sha256: string, size: int}>
     * }
     */
    public function buildInventory(string $root, string $nativeVersion): array
    {
        // 1. Resolve and verify root path safety through resolver
        $canonicalRoot = self::resolveAbsolutePath($root, true);
        if (!is_dir($canonicalRoot)) {
            throw new RuntimeException("Product root directory not found: {$root}");
        }

        if (!self::isValidSemver($nativeVersion)) {
            throw new RuntimeException("Invalid native shell version semver: '{$nativeVersion}'.");
        }

        // 2. Check required physical artifacts through resolver BEFORE reading
        foreach (self::REQUIRED_PHYSICAL_FILES as $requiredPath) {
            $fullPath = self::resolveConfinedPath($canonicalRoot, $requiredPath, true);
            if (!is_file($fullPath) || is_link($fullPath)) {
                throw new RuntimeException("Missing required product physical artifact: {$requiredPath}");
            }
        }

        // 3. Application version must be read from deployed resources/app.asar.unpacked/version.json
        // via resolver BEFORE reading
        $versionJsonPath = self::resolveConfinedPath($canonicalRoot, 'resources/app.asar.unpacked/version.json', true);
        $versionRaw = file_get_contents($versionJsonPath);
        if ($versionRaw === false) {
            throw new RuntimeException('Cannot read deployed version.json.');
        }
        $versionData = json_decode($versionRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($versionData) || !is_string($versionData['version'] ?? null)) {
            throw new RuntimeException('Invalid deployed version.json structure.');
        }
        $appVersion = $versionData['version'];
        if (!self::isValidSemver($appVersion)) {
            throw new RuntimeException("Invalid application version semver in version.json: '{$appVersion}'.");
        }

        // 4. Recursively inspect directory tree, skipping protected user-state dirs/files without descending
        $dirIterator = new RecursiveDirectoryIterator(
            $canonicalRoot,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
        );

        $filter = new RecursiveCallbackFilterIterator($dirIterator, function ($current, $key, $iterator) use ($canonicalRoot) {
            $pathname = str_replace('\\', '/', $current->getPathname());
            $normRoot = str_replace('\\', '/', $canonicalRoot);
            $rel = ltrim(substr($pathname, strlen($normRoot)), '/');
            if ($rel === '') {
                return true;
            }
            // Skip protected user-state dirs and files without traversing, resolving, or reading
            if (ProductInventoryService::isProtectedPath($rel)) {
                return false;
            }
            // Call confinement resolver in filter for EVERY unprotected entry BEFORE iterator can recurse,
            // failing on all ancestor canonical, junction, or reparse errors
            ProductInventoryService::resolveConfinedPath($canonicalRoot, $rel, true);
            return true;
        });

        $iterator = new RecursiveIteratorIterator(
            $filter,
            RecursiveIteratorIterator::SELF_FIRST
        );

        $discoveredPaths = [];
        $canonicalRootNorm = str_replace('\\', '/', $canonicalRoot);
        $rootLen = strlen($canonicalRootNorm);

        foreach ($iterator as $item) {
            $pathname = str_replace('\\', '/', $item->getPathname());
            if ($item->isLink()) {
                throw new RuntimeException("Reparse point or symlink rejected in product tree: {$pathname}");
            }
            if ($item->isFile()) {
                $relative = ltrim(substr($pathname, $rootLen), '/');
                if (self::isProtectedPath($relative)) {
                    continue;
                }
                $discoveredPaths[] = $relative;
                if (count($discoveredPaths) > self::MAX_FILES) {
                    throw new RuntimeException('Product tree exceeds maximum allowed file count: ' . self::MAX_FILES);
                }
            }
        }

        // Validate complete path set against policy and collision constraints
        $setCheck = self::validatePathSet($discoveredPaths);
        if (!$setCheck['ok']) {
            throw new RuntimeException("Product root contains unauthorized or colliding paths: " . ($setCheck['reason_code'] ?? 'unknown'));
        }

        sort($discoveredPaths, SORT_STRING);
        $files = [];

        foreach ($discoveredPaths as $relative) {
            $absolutePath = self::resolveConfinedPath($canonicalRoot, $relative, true);
            if (!is_file($absolutePath) || is_link($absolutePath)) {
                throw new RuntimeException("Cannot access physical file: {$relative}");
            }

            $size = filesize($absolutePath);
            if ($size === false || $size < 0 || $size > self::MAX_SAFE_INTEGER) {
                throw new RuntimeException("Invalid file size for: {$relative}");
            }

            $hash = hash_file('sha256', $absolutePath);
            if ($hash === false || strlen($hash) !== 64) {
                throw new RuntimeException("Could not compute sha256 for: {$relative}");
            }

            $files[$relative] = [
                'sha256' => strtolower($hash),
                'size' => $size,
            ];
        }

        if (count($files) > self::MAX_FILES) {
            throw new RuntimeException('Product tree exceeds maximum allowed file count.');
        }

        // Retained shell proof
        $exeHash = $files['POS Desktop.exe']['sha256'];
        $asarHash = $files['resources/app.asar']['sha256'];
        $shellDigest = self::computeCanonicalShellDigest($exeHash, $asarHash);

        $nativeShell = [
            'sha256' => $shellDigest,
            'files' => [
                'POS Desktop.exe' => [
                    'sha256' => $exeHash,
                    'size' => $files['POS Desktop.exe']['size'],
                ],
                'resources/app.asar' => [
                    'sha256' => $asarHash,
                    'size' => $files['resources/app.asar']['size'],
                ],
            ],
        ];

        return [
            'format' => 1,
            'platform' => self::PLATFORM,
            'arch' => self::ARCH,
            'version' => $appVersion,
            'native_version' => $nativeVersion,
            'native_shell' => $nativeShell,
            'files' => $files,
        ];
    }

    /**
     * Validate an existing physical product inventory descriptor.
     *
     * @param array<string, mixed> $inventory
     * @return array{ok: bool, reason_code: ?string}
     */
    public function validateInventory(array $inventory, ?string $expectedAppVersion = null, ?string $expectedNativeVersion = null): array
    {
        if (($inventory['format'] ?? null) !== 1) {
            return ['ok' => false, 'reason_code' => 'inventory_format_unsupported'];
        }

        // Exact 7 top-level keys enforced: format, platform, arch, version, native_version, native_shell, files
        $topKeys = array_keys($inventory);
        sort($topKeys);
        if ($topKeys !== ['arch', 'files', 'format', 'native_shell', 'native_version', 'platform', 'version']) {
            return ['ok' => false, 'reason_code' => 'inventory_keys_invalid'];
        }

        if ($inventory['platform'] !== self::PLATFORM || $inventory['arch'] !== self::ARCH) {
            return ['ok' => false, 'reason_code' => 'inventory_platform_unsupported'];
        }

        $appVer = $inventory['version'];
        if (!is_string($appVer) || !self::isValidSemver($appVer)) {
            return ['ok' => false, 'reason_code' => 'inventory_version_invalid'];
        }
        if ($expectedAppVersion !== null && $appVer !== $expectedAppVersion) {
            return ['ok' => false, 'reason_code' => 'inventory_version_mismatch'];
        }

        $nativeVer = $inventory['native_version'];
        if (!is_string($nativeVer) || !self::isValidSemver($nativeVer)) {
            return ['ok' => false, 'reason_code' => 'inventory_native_version_invalid'];
        }
        if ($expectedNativeVersion !== null && $nativeVer !== $expectedNativeVersion) {
            return ['ok' => false, 'reason_code' => 'inventory_native_version_mismatch'];
        }

        // Validate native_shell proof
        $nativeShell = $inventory['native_shell'];
        if (!is_array($nativeShell)) {
            return ['ok' => false, 'reason_code' => 'native_shell_invalid'];
        }
        $shellTopKeys = array_keys($nativeShell);
        sort($shellTopKeys);
        if ($shellTopKeys !== ['files', 'sha256']) {
            return ['ok' => false, 'reason_code' => 'native_shell_invalid'];
        }
        if (!is_string($nativeShell['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $nativeShell['sha256']) !== 1) {
            return ['ok' => false, 'reason_code' => 'native_shell_invalid'];
        }

        $shellFiles = $nativeShell['files'];
        if (!is_array($shellFiles)) {
            return ['ok' => false, 'reason_code' => 'native_shell_invalid'];
        }
        $shellFileKeys = array_keys($shellFiles);
        sort($shellFileKeys);
        if ($shellFileKeys !== ['POS Desktop.exe', 'resources/app.asar']) {
            return ['ok' => false, 'reason_code' => 'native_shell_invalid'];
        }

        foreach (['POS Desktop.exe', 'resources/app.asar'] as $fname) {
            $fmeta = $shellFiles[$fname];
            if (!is_array($fmeta)) {
                return ['ok' => false, 'reason_code' => 'native_shell_invalid'];
            }
            $metaKeys = array_keys($fmeta);
            sort($metaKeys);
            if ($metaKeys !== ['sha256', 'size']) {
                return ['ok' => false, 'reason_code' => 'native_shell_invalid'];
            }
            if (!is_string($fmeta['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $fmeta['sha256']) !== 1) {
                return ['ok' => false, 'reason_code' => 'native_shell_invalid'];
            }
            if (!is_int($fmeta['size']) || $fmeta['size'] < 0 || $fmeta['size'] > self::MAX_SAFE_INTEGER) {
                return ['ok' => false, 'reason_code' => 'native_shell_invalid'];
            }
        }

        try {
            $recomputedDigest = self::computeCanonicalShellDigest($shellFiles);
            if ($nativeShell['sha256'] !== $recomputedDigest) {
                return ['ok' => false, 'reason_code' => 'native_shell_digest_mismatch'];
            }
        } catch (Throwable) {
            return ['ok' => false, 'reason_code' => 'native_shell_invalid'];
        }

        // Validate files map
        $files = $inventory['files'];
        if (!is_array($files) || empty($files) || count($files) > self::MAX_FILES) {
            return ['ok' => false, 'reason_code' => 'inventory_files_invalid'];
        }

        // Required physical files must be present
        foreach (self::REQUIRED_PHYSICAL_FILES as $req) {
            if (!isset($files[$req])) {
                return ['ok' => false, 'reason_code' => 'inventory_missing_required_file'];
            }
        }

        // Retained shell entries must strictly equal inventory files entries
        foreach (['POS Desktop.exe', 'resources/app.asar'] as $req) {
            $fEntry = $files[$req];
            if (!is_array($fEntry)) {
                return ['ok' => false, 'reason_code' => 'native_shell_file_mismatch'];
            }
            $fKeys = array_keys($fEntry);
            sort($fKeys);
            if ($fKeys !== ['sha256', 'size']) {
                return ['ok' => false, 'reason_code' => 'native_shell_file_mismatch'];
            }
            if ($fEntry['sha256'] !== $shellFiles[$req]['sha256'] ||
                $fEntry['size'] !== $shellFiles[$req]['size']) {
                return ['ok' => false, 'reason_code' => 'native_shell_file_mismatch'];
            }
        }

        // Validate all paths and entries
        $pathKeys = array_keys($files);
        $setCheck = self::validatePathSet($pathKeys);
        if (!$setCheck['ok']) {
            return $setCheck;
        }

        foreach ($files as $p => $entry) {
            if (!is_string($p)) {
                return ['ok' => false, 'reason_code' => 'inventory_files_invalid'];
            }
            if (!is_array($entry)) {
                return ['ok' => false, 'reason_code' => 'file_invalid'];
            }
            $entryKeys = array_keys($entry);
            sort($entryKeys);
            if ($entryKeys !== ['sha256', 'size']) {
                return ['ok' => false, 'reason_code' => 'file_invalid'];
            }
            if (!is_string($entry['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) !== 1) {
                return ['ok' => false, 'reason_code' => 'file_invalid'];
            }
            if (!is_int($entry['size']) || $entry['size'] < 0 || $entry['size'] > self::MAX_SAFE_INTEGER) {
                return ['ok' => false, 'reason_code' => 'file_invalid'];
            }
        }

        return ['ok' => true, 'reason_code' => null];
    }

    /**
     * Fail closed if path is a reparse point or cannot be resolved safely.
     */
    public static function assertNoReparse(string $path): void
    {
        self::resolveAbsolutePath($path, true);
    }

    /**
     * Strict semantic version check matching SemVer 2.0.
     * Enforces non-empty identifiers, and numeric prerelease identifiers cannot have leading zeroes.
     */
    public static function isValidSemver(string $version): bool
    {
        return preg_match(
            '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*)?(?:\+[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*)?$/D',
            $version
        ) === 1;
    }
}
