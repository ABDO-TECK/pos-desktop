<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * ShellDeltaManifestService
 *
 * Implements Format 2 shell_delta manifest verification, signature validation,
 * source verification, and package zip integrity checks.
 */
final class ShellDeltaManifestService
{
    public const FORMAT = 2;
    public const OPERATION = 'shell_delta';
    public const MINIMUM_UPDATER_PROTOCOL = 2;
    public const PLATFORM = 'win32';
    public const ARCH = 'x64';

    public const MANIFEST_ASSET = 'shell-manifest.json';
    public const SIGNATURE_ASSET = 'shell-manifest.sig';
    public const PACKAGE_ASSET = 'shell-delta.zip';

    public const MAX_MANIFEST_SIZE = 16777216;      // 16 MiB
    public const MAX_SIGNATURE_SIZE = 16384;         // 16 KiB
    public const MAX_FILES = 20000;
    public const MAX_PACKAGE_SIZE = 524288000;       // 500 MiB
    public const MAX_UNCOMPRESSED_SIZE = 1073741824; // 1 GiB
    public const MAX_SINGLE_FILE_SIZE = 262144000;   // 250 MiB

    private ManifestSignatureService $signatures;
    private ProductInventoryService $inventoryService;

    public function __construct(
        ?ManifestSignatureService $signatures = null,
        ?ProductInventoryService $inventoryService = null
    ) {
        $this->signatures = $signatures ?? new ManifestSignatureService();
        $this->inventoryService = $inventoryService ?? new ProductInventoryService();
    }

    /**
     * Validate a single relative product path against strict default-deny policy.
     *
     * @return array{ok: bool, reason_code: ?string}
     */
    public function validateProductPath(string $path): array
    {
        return ProductInventoryService::validateProductPath($path);
    }

    /**
     * Build physical product inventory Format 1 from an as-built Windows installation root.
     *
     * @return array<string, mixed>
     */
    public function buildInventory(string $root, string $nativeVersion): array
    {
        return $this->inventoryService->buildInventory($root, $nativeVersion);
    }

    /**
     * Compute the canonical Format 1 full product inventory digest for Format 2 manifests.
     *
     * Validates Format 1 structure and reconstructs strictly ordered canonical JSON.
     *
     * @param array<string, mixed> $inventory
     * @return string SHA-256 hex digest
     * @throws RuntimeException If inventory violates Format 1 specification
     */
    public static function canonicalInventoryDigest(array $inventory): string
    {
        $validator = new ProductInventoryService();
        $validation = $validator->validateInventory($inventory);
        if (!$validation['ok']) {
            throw new RuntimeException('Cannot compute canonical inventory digest: ' . ($validation['reason_code'] ?? 'inventory_invalid'));
        }

        $shellFiles = [
            'POS Desktop.exe' => [
                'sha256' => (string) $inventory['native_shell']['files']['POS Desktop.exe']['sha256'],
                'size' => (int) $inventory['native_shell']['files']['POS Desktop.exe']['size'],
            ],
            'resources/app.asar' => [
                'sha256' => (string) $inventory['native_shell']['files']['resources/app.asar']['sha256'],
                'size' => (int) $inventory['native_shell']['files']['resources/app.asar']['size'],
            ],
        ];

        $nativeShell = [
            'sha256' => (string) $inventory['native_shell']['sha256'],
            'files' => $shellFiles,
        ];

        $canonicalFiles = [];
        $rawFiles = $inventory['files'];
        ksort($rawFiles, SORT_STRING);
        foreach ($rawFiles as $path => $entry) {
            $canonicalFiles[(string) $path] = [
                'sha256' => (string) $entry['sha256'],
                'size' => (int) $entry['size'],
            ];
        }

        $canonical = [
            'format' => (int) $inventory['format'],
            'platform' => (string) $inventory['platform'],
            'arch' => (string) $inventory['arch'],
            'version' => (string) $inventory['version'],
            'native_version' => (string) $inventory['native_version'],
            'native_shell' => $nativeShell,
            'files' => $canonicalFiles,
        ];

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    /**
     * Validate the structural integrity, schema, diff, and constraints of a Format 2 manifest descriptor.
     *
     * @param array<string, mixed> $descriptor
     * @return array{ok: bool, reason_code: ?string}
     */
    public function validateStructural(array $descriptor): array
    {
        // 1. Core envelope identification
        if (($descriptor['format'] ?? null) !== self::FORMAT) {
            return ['ok' => false, 'reason_code' => 'format_unsupported'];
        }
        if (($descriptor['operation'] ?? null) !== self::OPERATION) {
            return ['ok' => false, 'reason_code' => 'operation_invalid'];
        }
        if (($descriptor['minimum_updater_protocol'] ?? null) !== self::MINIMUM_UPDATER_PROTOCOL) {
            return ['ok' => false, 'reason_code' => 'protocol_unsupported'];
        }
        if (($descriptor['platform'] ?? null) !== self::PLATFORM) {
            return ['ok' => false, 'reason_code' => 'platform_unsupported'];
        }
        if (($descriptor['arch'] ?? null) !== self::ARCH) {
            return ['ok' => false, 'reason_code' => 'arch_unsupported'];
        }

        // 2. Purpose identification
        $purpose = $descriptor['purpose'] ?? null;
        if (!is_string($purpose) || !in_array($purpose, ['regular', 'bootstrap_completion'], true)) {
            return ['ok' => false, 'reason_code' => 'purpose_invalid'];
        }

        // 3. Exact allowed descriptor fields
        $knownKeys = [
            'format', 'operation', 'minimum_updater_protocol', 'platform', 'arch',
            'from_channel', 'channel', 'from_version', 'version',
            'from_native_version', 'native_version', 'prerelease', 'purpose',
            'source_inventory_sha256', 'source_inventory',
            'target_inventory_sha256', 'target_inventory',
            'source_native_shell', 'target_native_shell',
            'files', 'deleted_files', 'package',
            'data_compatibility', 'source_data_compatibility', 'migrations',
        ];
        if ($purpose === 'bootstrap_completion') {
            $knownKeys[] = 'bootstrap_link';
        }

        foreach (array_keys($descriptor) as $k) {
            if ($k === 'bootstrap_link' && $purpose === 'regular') {
                return ['ok' => false, 'reason_code' => 'bootstrap_link_unexpected'];
            }
            if (!in_array($k, $knownKeys, true)) {
                return ['ok' => false, 'reason_code' => 'descriptor_invalid'];
            }
        }

        // 4. Channels and Prerelease classification
        $fromChannel = $descriptor['from_channel'] ?? null;
        $toChannel = $descriptor['channel'] ?? null;
        $activeChannels = UpdateChannelPolicy::active();

        if (!is_string($fromChannel) || !in_array($fromChannel, $activeChannels, true)) {
            return ['ok' => false, 'reason_code' => 'channel_invalid'];
        }
        if (!is_string($toChannel) || !in_array($toChannel, $activeChannels, true)) {
            return ['ok' => false, 'reason_code' => 'channel_invalid'];
        }
        if ($fromChannel !== $toChannel) {
            return ['ok' => false, 'reason_code' => 'channel_mismatch'];
        }

        $fromVersion = $descriptor['from_version'] ?? null;
        $toVersion = $descriptor['version'] ?? null;
        $fromNativeVer = $descriptor['from_native_version'] ?? null;
        $toNativeVer = $descriptor['native_version'] ?? null;

        if (!is_string($fromVersion) || !ProductInventoryService::isValidSemver($fromVersion) ||
            !is_string($toVersion) || !ProductInventoryService::isValidSemver($toVersion) ||
            !is_string($fromNativeVer) || !ProductInventoryService::isValidSemver($fromNativeVer) ||
            !is_string($toNativeVer) || !ProductInventoryService::isValidSemver($toNativeVer)) {
            return ['ok' => false, 'reason_code' => 'semver_invalid'];
        }

        if (UpdateChannelPolicy::installedChannel($fromVersion) !== $fromChannel) {
            return ['ok' => false, 'reason_code' => 'channel_invalid'];
        }
        if (UpdateChannelPolicy::installedChannel($toVersion) !== $toChannel) {
            return ['ok' => false, 'reason_code' => 'channel_invalid'];
        }

        $expectedPrerelease = ($toChannel === 'beta');
        if (($descriptor['prerelease'] ?? null) !== $expectedPrerelease) {
            return ['ok' => false, 'reason_code' => 'prerelease_mismatch'];
        }

        // 5. Version progression & Purpose rules
        $srcInv = $descriptor['source_inventory'] ?? null;
        $tgtInv = $descriptor['target_inventory'] ?? null;
        if (!is_array($srcInv) || !is_array($tgtInv)) {
            return ['ok' => false, 'reason_code' => 'inventory_invalid'];
        }

        if ($purpose === 'regular') {
            if (version_compare($toVersion, $fromVersion, '<=')) {
                return ['ok' => false, 'reason_code' => 'downgrade_rejected'];
            }
        } elseif ($purpose === 'bootstrap_completion') {
            if (version_compare($toVersion, $fromVersion, '<')) {
                return ['ok' => false, 'reason_code' => 'downgrade_rejected'];
            }
            $link = $descriptor['bootstrap_link'] ?? null;
            if (!is_string($link) || preg_match('/^[a-f0-9]{64}$/D', $link) !== 1) {
                return ['ok' => false, 'reason_code' => 'bootstrap_link_invalid'];
            }
            $srcShellHash = $srcInv['native_shell']['sha256'] ?? '';
            $tgtShellHash = $tgtInv['native_shell']['sha256'] ?? '';
            if ($srcShellHash === '' || $srcShellHash === $tgtShellHash) {
                return ['ok' => false, 'reason_code' => 'bootstrap_shell_unchanged'];
            }
        }

        // 6. Source & Target Product Inventories Validation
        $srcValidation = $this->inventoryService->validateInventory($srcInv, $fromVersion, $fromNativeVer);
        if (!$srcValidation['ok']) {
            return ['ok' => false, 'reason_code' => 'source_inventory_invalid'];
        }

        $srcInvHash = $descriptor['source_inventory_sha256'] ?? null;
        if (!is_string($srcInvHash) || preg_match('/^[a-f0-9]{64}$/D', $srcInvHash) !== 1) {
            return ['ok' => false, 'reason_code' => 'source_inventory_hash_invalid'];
        }
        try {
            $expectedSrcHash = self::canonicalInventoryDigest($srcInv);
        } catch (Throwable) {
            return ['ok' => false, 'reason_code' => 'source_inventory_invalid'];
        }
        if ($srcInvHash !== $expectedSrcHash) {
            return ['ok' => false, 'reason_code' => 'source_inventory_hash_mismatch'];
        }

        $tgtValidation = $this->inventoryService->validateInventory($tgtInv, $toVersion, $toNativeVer);
        if (!$tgtValidation['ok']) {
            return ['ok' => false, 'reason_code' => 'target_inventory_invalid'];
        }

        $tgtInvHash = $descriptor['target_inventory_sha256'] ?? null;
        if (!is_string($tgtInvHash) || preg_match('/^[a-f0-9]{64}$/D', $tgtInvHash) !== 1) {
            return ['ok' => false, 'reason_code' => 'target_inventory_hash_invalid'];
        }
        try {
            $expectedTgtHash = self::canonicalInventoryDigest($tgtInv);
        } catch (Throwable) {
            return ['ok' => false, 'reason_code' => 'target_inventory_invalid'];
        }
        if ($tgtInvHash !== $expectedTgtHash) {
            return ['ok' => false, 'reason_code' => 'target_inventory_hash_mismatch'];
        }

        // Exact physical shell proofs must match embedded inventories semantically independent of order
        $matchNativeShell = static function (mixed $a, mixed $b): bool {
            if (!is_array($a) || !is_array($b)) {
                return false;
            }
            if (($a['sha256'] ?? null) !== ($b['sha256'] ?? null)) {
                return false;
            }
            $aFiles = $a['files'] ?? null;
            $bFiles = $b['files'] ?? null;
            if (!is_array($aFiles) || !is_array($bFiles)) {
                return false;
            }
            $reqFiles = ['POS Desktop.exe', 'resources/app.asar'];
            $aTopKeys = array_keys($aFiles);
            $bTopKeys = array_keys($bFiles);
            sort($aTopKeys);
            sort($bTopKeys);
            if ($aTopKeys !== $reqFiles || $bTopKeys !== $reqFiles) {
                return false;
            }
            foreach ($reqFiles as $shellFile) {
                if (!is_array($aFiles[$shellFile]) || !is_array($bFiles[$shellFile])) {
                    return false;
                }
                if (($aFiles[$shellFile]['sha256'] ?? null) !== ($bFiles[$shellFile]['sha256'] ?? null) ||
                    ($aFiles[$shellFile]['size'] ?? null) !== ($bFiles[$shellFile]['size'] ?? null)) {
                    return false;
                }
                $aKeys = array_keys($aFiles[$shellFile]);
                $bKeys = array_keys($bFiles[$shellFile]);
                sort($aKeys);
                sort($bKeys);
                if ($aKeys !== ['sha256', 'size'] || $bKeys !== ['sha256', 'size']) {
                    return false;
                }
            }
            $aRootKeys = array_keys($a);
            $bRootKeys = array_keys($b);
            sort($aRootKeys);
            sort($bRootKeys);
            return ($aRootKeys === ['files', 'sha256'] && $bRootKeys === ['files', 'sha256']);
        };

        if (!$matchNativeShell($descriptor['source_native_shell'] ?? null, $srcInv['native_shell']) ||
            !$matchNativeShell($descriptor['target_native_shell'] ?? null, $tgtInv['native_shell'])) {
            return ['ok' => false, 'reason_code' => 'shell_proof_mismatch'];
        }

        // 7. Cross-inventory case collision & ancestor collision checks
        $sourceFiles = $srcInv['files'];
        $targetFiles = $tgtInv['files'];
        $unionPaths = array_values(array_unique(array_merge(array_keys($sourceFiles), array_keys($targetFiles))));
        $unionCheck = ProductInventoryService::validatePathSet($unionPaths);
        if (!$unionCheck['ok']) {
            return ['ok' => false, 'reason_code' => $unionCheck['reason_code']];
        }

        // 8. Data Compatibility Contract Validation (both REQUIRED, exact four keys)
        $validateDataCompat = static function (mixed $contract, string $reasonCode): ?array {
            if (!is_array($contract)) {
                return ['ok' => false, 'reason_code' => $reasonCode];
            }
            $cKeys = array_keys($contract);
            sort($cKeys);
            if ($cKeys !== ['format', 'ledger_sha256', 'metadata_migration', 'schema_sha256']) {
                return ['ok' => false, 'reason_code' => $reasonCode];
            }
            if ($contract['format'] !== 1 ||
                $contract['metadata_migration'] !== UpdateDataCompatibilityService::METADATA_MIGRATION ||
                !is_string($contract['schema_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $contract['schema_sha256']) !== 1 ||
                !is_string($contract['ledger_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $contract['ledger_sha256']) !== 1) {
                return ['ok' => false, 'reason_code' => $reasonCode];
            }
            return null;
        };

        $targetCompatCheck = $validateDataCompat($descriptor['data_compatibility'] ?? null, 'data_contract_invalid');
        if ($targetCompatCheck !== null) {
            return $targetCompatCheck;
        }

        $sourceCompatCheck = $validateDataCompat($descriptor['source_data_compatibility'] ?? null, 'source_data_contract_invalid');
        if ($sourceCompatCheck !== null) {
            return $sourceCompatCheck;
        }

        // 9. Migrations array: bounded unique actual *.sql filenames, NEVER SQL commands
        $migrations = $descriptor['migrations'] ?? null;
        if (!is_array($migrations) || !array_is_list($migrations) || count($migrations) > self::MAX_FILES) {
            return ['ok' => false, 'reason_code' => 'migrations_invalid'];
        }
        foreach ($migrations as $migration) {
            if (!is_string($migration) || preg_match('/^[0-9]{3}_[a-zA-Z0-9_.-]+\.sql$/D', $migration) !== 1) {
                return ['ok' => false, 'reason_code' => 'migrations_invalid'];
            }
        }
        if (count($migrations) !== count(array_unique($migrations))) {
            return ['ok' => false, 'reason_code' => 'migrations_invalid'];
        }

        // 10. Package descriptor validation
        $package = $descriptor['package'] ?? null;
        if (!is_array($package)) {
            return ['ok' => false, 'reason_code' => 'package_invalid'];
        }
        $pkgKeys = array_keys($package);
        sort($pkgKeys);
        if ($pkgKeys !== ['asset', 'sha256', 'size', 'uncompressed_size']) {
            return ['ok' => false, 'reason_code' => 'package_invalid'];
        }
        if ($package['asset'] !== self::PACKAGE_ASSET) {
            return ['ok' => false, 'reason_code' => 'package_invalid'];
        }
        if (!is_string($package['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $package['sha256']) !== 1) {
            return ['ok' => false, 'reason_code' => 'package_invalid'];
        }
        if (!is_int($package['size']) || $package['size'] <= 0 || $package['size'] > self::MAX_PACKAGE_SIZE) {
            return ['ok' => false, 'reason_code' => 'package_invalid'];
        }
        if (!is_int($package['uncompressed_size']) || $package['uncompressed_size'] < 0 || $package['uncompressed_size'] > self::MAX_UNCOMPRESSED_SIZE) {
            return ['ok' => false, 'reason_code' => 'package_invalid'];
        }

        // 11. Exact diff validation (files vs deleted_files)
        $expectedFiles = [];
        foreach ($targetFiles as $path => $entry) {
            if (!isset($sourceFiles[$path]) ||
                $sourceFiles[$path]['sha256'] !== $entry['sha256'] ||
                $sourceFiles[$path]['size'] !== $entry['size']) {
                $expectedFiles[$path] = $entry;
            }
        }
        ksort($expectedFiles);

        $expectedDeleted = array_keys(array_diff_key($sourceFiles, $targetFiles));
        sort($expectedDeleted);

        $files = $descriptor['files'] ?? null;
        if (!is_array($files) || !array_is_list($files) || count($files) > self::MAX_FILES) {
            return ['ok' => false, 'reason_code' => 'files_invalid'];
        }

        $actualFiles = [];
        $sumFilesSize = 0;
        foreach ($files as $entry) {
            if (!is_array($entry)) {
                return ['ok' => false, 'reason_code' => 'files_invalid'];
            }
            $entryKeys = array_keys($entry);
            sort($entryKeys);
            if ($entryKeys !== ['path', 'sha256', 'size']) {
                return ['ok' => false, 'reason_code' => 'files_invalid'];
            }
            $p = $entry['path'];
            if (!is_string($p)) {
                return ['ok' => false, 'reason_code' => 'files_invalid'];
            }
            $pathSyntax = ProductInventoryService::validateProductPath($p);
            if (!$pathSyntax['ok']) {
                return ['ok' => false, 'reason_code' => 'files_invalid'];
            }
            if (isset($actualFiles[$p])) {
                return ['ok' => false, 'reason_code' => 'files_duplicate'];
            }
            if (!is_string($entry['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) !== 1 ||
                !is_int($entry['size']) || $entry['size'] < 0 || $entry['size'] > self::MAX_SINGLE_FILE_SIZE) {
                return ['ok' => false, 'reason_code' => 'files_invalid'];
            }
            $sumFilesSize += $entry['size'];
            $actualFiles[$p] = [
                'sha256' => $entry['sha256'],
                'size' => $entry['size'],
            ];
        }
        ksort($actualFiles);

        if ($sumFilesSize > self::MAX_UNCOMPRESSED_SIZE) {
            return ['ok' => false, 'reason_code' => 'package_invalid'];
        }
        if ($sumFilesSize !== $package['uncompressed_size']) {
            return ['ok' => false, 'reason_code' => 'package_uncompressed_mismatch'];
        }

        if ($actualFiles !== $expectedFiles) {
            return ['ok' => false, 'reason_code' => 'payload_mismatch'];
        }

        $deleted = $descriptor['deleted_files'] ?? null;
        if (!is_array($deleted) || !array_is_list($deleted) || count($deleted) > self::MAX_FILES) {
            return ['ok' => false, 'reason_code' => 'deletions_invalid'];
        }
        $seenDeleted = [];
        foreach ($deleted as $delPath) {
            if (!is_string($delPath) || !ProductInventoryService::validateProductPath($delPath)['ok']) {
                return ['ok' => false, 'reason_code' => 'deletions_invalid'];
            }
            if (isset($seenDeleted[$delPath])) {
                return ['ok' => false, 'reason_code' => 'deletions_invalid'];
            }
            $seenDeleted[$delPath] = true;
        }
        $actualDeleted = $deleted;
        sort($actualDeleted);

        if ($actualDeleted !== $expectedDeleted) {
            return ['ok' => false, 'reason_code' => 'deletions_mismatch'];
        }

        return ['ok' => true, 'reason_code' => null];
    }

    /**
     * Verify a raw manifest payload and RSA signature against trusted public key and optional installed context.
     *
     * @param string $raw Raw manifest UTF-8 JSON
     * @param string $signature Base64 RSA PKCS#1 SHA-256 signature
     * @param array<string, mixed> $installedContext Optional verification context from installed client
     * @return array{ok: bool, descriptor: ?array<string, mixed>, reason_code: ?string}
     */
    public function verify(string $raw, string $signature, array $installedContext = []): array
    {
        try {
            if (strlen($raw) === 0 || strlen($raw) > self::MAX_MANIFEST_SIZE) {
                return ['ok' => false, 'descriptor' => null, 'reason_code' => 'manifest_too_large'];
            }

            if (strlen($signature) > self::MAX_SIGNATURE_SIZE) {
                return ['ok' => false, 'descriptor' => null, 'reason_code' => 'signature_invalid'];
            }

            // Canonical base64 ONLY outer whitespace/newline allowed; reject valid raw signature fallback
            $trimmedSig = trim($signature, " \t\r\n");
            if ($trimmedSig === '' || strlen($trimmedSig) % 4 !== 0 || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $trimmedSig) !== 1) {
                return ['ok' => false, 'descriptor' => null, 'reason_code' => 'signature_invalid'];
            }

            $decodedSig = base64_decode($trimmedSig, true);
            if ($decodedSig === false || base64_encode($decodedSig) !== $trimmedSig) {
                return ['ok' => false, 'descriptor' => null, 'reason_code' => 'signature_invalid'];
            }

            // Cryptographic verification of exact UTF-8 bytes BEFORE parsing or trusting content
            if (!$this->signatures->verifySignature($raw, $trimmedSig)) {
                return ['ok' => false, 'descriptor' => null, 'reason_code' => 'signature_invalid'];
            }

            $descriptor = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($descriptor)) {
                return ['ok' => false, 'descriptor' => null, 'reason_code' => 'descriptor_invalid'];
            }

            $structural = $this->validateStructural($descriptor);
            if (!$structural['ok']) {
                return ['ok' => false, 'descriptor' => null, 'reason_code' => $structural['reason_code']];
            }

            // Strict installed context validation if provided
            if ($installedContext !== []) {
                $allowedContextKeys = [
                    'version',
                    'native_version',
                    'channel',
                    'inventory_sha256',
                    'native_shell_sha256',
                    'root',
                    'data_compatibility',
                ];

                foreach ($installedContext as $ctxKey => $ctxVal) {
                    if (!in_array($ctxKey, $allowedContextKeys, true)) {
                        return ['ok' => false, 'descriptor' => null, 'reason_code' => 'installed_context_invalid'];
                    }
                    if ($ctxKey === 'data_compatibility') {
                        if (!is_array($ctxVal)) {
                            return ['ok' => false, 'descriptor' => null, 'reason_code' => 'installed_context_invalid'];
                        }
                    } elseif (!is_string($ctxVal)) {
                        return ['ok' => false, 'descriptor' => null, 'reason_code' => 'installed_context_invalid'];
                    }
                }

                if (isset($installedContext['version']) && $installedContext['version'] !== $descriptor['from_version']) {
                    return ['ok' => false, 'descriptor' => null, 'reason_code' => 'source_version_mismatch'];
                }
                if (isset($installedContext['native_version']) && $installedContext['native_version'] !== $descriptor['from_native_version']) {
                    return ['ok' => false, 'descriptor' => null, 'reason_code' => 'source_native_version_mismatch'];
                }
                if (isset($installedContext['channel']) && $installedContext['channel'] !== $descriptor['from_channel']) {
                    return ['ok' => false, 'descriptor' => null, 'reason_code' => 'source_channel_mismatch'];
                }
                if (isset($installedContext['inventory_sha256']) && $installedContext['inventory_sha256'] !== $descriptor['source_inventory_sha256']) {
                    return ['ok' => false, 'descriptor' => null, 'reason_code' => 'source_inventory_mismatch'];
                }
                if (isset($installedContext['native_shell_sha256']) && $installedContext['native_shell_sha256'] !== ($descriptor['source_inventory']['native_shell']['sha256'] ?? '')) {
                    return ['ok' => false, 'descriptor' => null, 'reason_code' => 'source_shell_mismatch'];
                }
                if (isset($installedContext['data_compatibility'])) {
                    $ctxCompat = $installedContext['data_compatibility'];
                    $srcCompat = $descriptor['source_data_compatibility'] ?? null;
                    if (!is_array($ctxCompat) || !is_array($srcCompat)) {
                        return ['ok' => false, 'descriptor' => null, 'reason_code' => 'source_data_contract_mismatch'];
                    }
                    $ctxKeys = array_keys($ctxCompat);
                    sort($ctxKeys);
                    if ($ctxKeys !== ['format', 'ledger_sha256', 'metadata_migration', 'schema_sha256']) {
                        return ['ok' => false, 'descriptor' => null, 'reason_code' => 'source_data_contract_mismatch'];
                    }
                    if ($ctxCompat['format'] !== $srcCompat['format'] ||
                        $ctxCompat['metadata_migration'] !== $srcCompat['metadata_migration'] ||
                        $ctxCompat['schema_sha256'] !== $srcCompat['schema_sha256'] ||
                        $ctxCompat['ledger_sha256'] !== $srcCompat['ledger_sha256']) {
                        return ['ok' => false, 'descriptor' => null, 'reason_code' => 'source_data_contract_mismatch'];
                    }
                }
                if (isset($installedContext['root'])) {
                    $srcCheck = $this->verifySource($installedContext['root'], $descriptor);
                    if (!$srcCheck['ok']) {
                        return ['ok' => false, 'descriptor' => null, 'reason_code' => $srcCheck['reason_code']];
                    }
                }
            }

            return ['ok' => true, 'descriptor' => $descriptor, 'reason_code' => null];
        } catch (Throwable) {
            return ['ok' => false, 'descriptor' => null, 'reason_code' => 'descriptor_invalid'];
        }
    }

    /**
     * Verify that an on-disk installation root strictly matches the descriptor's source inventory.
     *
     * @param string $root Absolute root path to product installation
     * @param array<string, mixed> $descriptor Verified manifest descriptor
     * @return array{ok: bool, reason_code: ?string}
     */
    public function verifySource(string $root, array $descriptor): array
    {
        // 1. Structural validation first
        $structural = $this->validateStructural($descriptor);
        if (!$structural['ok']) {
            return ['ok' => false, 'reason_code' => $structural['reason_code']];
        }

        // 2. Safe root resolution via ProductInventoryService
        try {
            $canonicalRoot = ProductInventoryService::resolveAbsolutePath($root, true);
        } catch (Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'reparse') || str_contains(strtolower($e->getMessage()), 'symlink')) {
                return ['ok' => false, 'reason_code' => 'reparse_point_rejected'];
            }
            return ['ok' => false, 'reason_code' => 'source_directory_missing'];
        }

        if (!is_dir($canonicalRoot)) {
            return ['ok' => false, 'reason_code' => 'source_directory_missing'];
        }

        try {
            ProductInventoryService::assertNoReparse($canonicalRoot);
        } catch (Throwable) {
            return ['ok' => false, 'reason_code' => 'reparse_point_rejected'];
        }

        // 3. Deployed version.json validation matching signed source version
        $sourceFiles = $descriptor['source_inventory']['files'] ?? null;
        if (!is_array($sourceFiles)) {
            return ['ok' => false, 'reason_code' => 'source_inventory_invalid'];
        }

        $versionRelPath = 'resources/app.asar.unpacked/version.json';
        $versionSignedMeta = $sourceFiles[$versionRelPath] ?? null;
        if (!is_array($versionSignedMeta) ||
            !isset($versionSignedMeta['size'], $versionSignedMeta['sha256']) ||
            !is_int($versionSignedMeta['size']) ||
            $versionSignedMeta['size'] < 0 ||
            $versionSignedMeta['size'] > 65536) { // 64 KiB metadata cap
            return ['ok' => false, 'reason_code' => 'source_version_mismatch'];
        }

        try {
            $versionPath = ProductInventoryService::resolveConfinedPath($canonicalRoot, $versionRelPath, true);
        } catch (Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'reparse') || str_contains(strtolower($e->getMessage()), 'symlink')) {
                return ['ok' => false, 'reason_code' => 'reparse_point_rejected'];
            }
            return ['ok' => false, 'reason_code' => 'source_version_mismatch'];
        }

        if (!is_file($versionPath) || is_link($versionPath)) {
            return ['ok' => false, 'reason_code' => 'source_version_mismatch'];
        }

        $vActualSize = filesize($versionPath);
        if ($vActualSize !== $versionSignedMeta['size']) {
            return ['ok' => false, 'reason_code' => 'source_version_mismatch'];
        }

        $vActualHash = hash_file('sha256', $versionPath);
        if ($vActualHash === false || strtolower($vActualHash) !== strtolower($versionSignedMeta['sha256'])) {
            return ['ok' => false, 'reason_code' => 'source_version_mismatch'];
        }

        // Read bounded exact bytes
        $vFp = fopen($versionPath, 'rb');
        if ($vFp === false) {
            return ['ok' => false, 'reason_code' => 'source_version_mismatch'];
        }
        $versionRaw = ($versionSignedMeta['size'] === 0) ? '' : fread($vFp, $versionSignedMeta['size']);
        fclose($vFp);

        if ($versionRaw === false || strlen($versionRaw) !== $versionSignedMeta['size']) {
            return ['ok' => false, 'reason_code' => 'source_version_mismatch'];
        }

        try {
            $versionData = json_decode($versionRaw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($versionData) || ($versionData['version'] ?? null) !== $descriptor['from_version']) {
                return ['ok' => false, 'reason_code' => 'source_version_mismatch'];
            }
        } catch (Throwable) {
            return ['ok' => false, 'reason_code' => 'source_version_mismatch'];
        }

        // 4. Source inventory files verification
        foreach ($sourceFiles as $path => $meta) {
            if (!is_string($path) || ProductInventoryService::isProtectedPath($path)) {
                return ['ok' => false, 'reason_code' => 'source_file_mismatch'];
            }

            try {
                $fullPath = ProductInventoryService::resolveConfinedPath($canonicalRoot, $path, true);
            } catch (Throwable $e) {
                if (str_contains(strtolower($e->getMessage()), 'reparse') || str_contains(strtolower($e->getMessage()), 'symlink')) {
                    return ['ok' => false, 'reason_code' => 'reparse_point_rejected'];
                }
                return ['ok' => false, 'reason_code' => 'source_file_mismatch'];
            }

            if (!is_file($fullPath) || is_link($fullPath)) {
                return ['ok' => false, 'reason_code' => 'source_file_mismatch'];
            }
            if (filesize($fullPath) !== ($meta['size'] ?? -1)) {
                return ['ok' => false, 'reason_code' => 'source_file_mismatch'];
            }
            $hash = hash_file('sha256', $fullPath);
            if ($hash === false || strtolower($hash) !== ($meta['sha256'] ?? '')) {
                return ['ok' => false, 'reason_code' => 'source_file_mismatch'];
            }
        }

        return ['ok' => true, 'reason_code' => null];
    }

    /**
     * Validate the physical zip package against descriptor metadata and contents.
     *
     * @param string $zipPath Absolute or relative path to shell-delta.zip
     * @param array<string, mixed> $descriptor Verified manifest descriptor
     * @return array{ok: bool, reason_code: ?string}
     */
    public function validatePackageZip(string $zipPath, array $descriptor): array
    {
        // 1. Structural validation first
        $structural = $this->validateStructural($descriptor);
        if (!$structural['ok']) {
            return ['ok' => false, 'reason_code' => $structural['reason_code']];
        }

        // 2. Safe absolute path
        if (!is_string($zipPath) || $zipPath === '') {
            return ['ok' => false, 'reason_code' => 'package_missing'];
        }

        try {
            $canonicalZip = ProductInventoryService::resolveAbsolutePath($zipPath, true);
        } catch (Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'reparse') || str_contains(strtolower($e->getMessage()), 'symlink')) {
                return ['ok' => false, 'reason_code' => 'package_reparse_rejected'];
            }
            return ['ok' => false, 'reason_code' => 'package_missing'];
        }

        if (!is_file($canonicalZip) || is_link($canonicalZip)) {
            return ['ok' => false, 'reason_code' => 'package_missing'];
        }

        try {
            ProductInventoryService::assertNoReparse($canonicalZip);
        } catch (Throwable) {
            return ['ok' => false, 'reason_code' => 'package_reparse_rejected'];
        }

        // 3. Size and hash BEFORE open
        $packageMeta = $descriptor['package'] ?? [];
        $expectedSize = $packageMeta['size'] ?? -1;
        $expectedHash = $packageMeta['sha256'] ?? '';
        $expectedUncompressed = $packageMeta['uncompressed_size'] ?? -1;

        $actualSize = filesize($canonicalZip);
        if ($actualSize !== $expectedSize || $actualSize > self::MAX_PACKAGE_SIZE || $actualSize <= 0) {
            return ['ok' => false, 'reason_code' => 'package_size_mismatch'];
        }

        $actualHash = hash_file('sha256', $canonicalZip);
        if ($actualHash === false || strtolower($actualHash) !== strtolower($expectedHash)) {
            return ['ok' => false, 'reason_code' => 'package_hash_mismatch'];
        }

        // 4. Collect manifest files map
        $manifestFiles = [];
        foreach (($descriptor['files'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['path']) && is_string($entry['path'])) {
                $manifestFiles[$entry['path']] = $entry;
            }
        }

        // 5. Open ZIP
        $zip = new ZipArchive();
        if ($zip->open($canonicalZip, ZipArchive::RDONLY) !== true) {
            return ['ok' => false, 'reason_code' => 'package_corrupt'];
        }

        try {
            $numFiles = $zip->numFiles;
            if ($numFiles !== count($manifestFiles)) {
                return ['ok' => false, 'reason_code' => 'package_entry_count_mismatch'];
            }
            if ($numFiles > self::MAX_FILES) {
                return ['ok' => false, 'reason_code' => 'package_too_many_files'];
            }

            $totalUncompressed = 0;
            $seenZipPaths = [];
            $seenLowerPaths = [];

            for ($i = 0; $i < $numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || !isset($stat['name']) || !is_string($stat['name'])) {
                    return ['ok' => false, 'reason_code' => 'package_entry_corrupt'];
                }

                $name = $stat['name'];

                if (str_ends_with($name, '/') || str_ends_with($name, '\\')) {
                    return ['ok' => false, 'reason_code' => 'package_entry_directory'];
                }

                $pathCheck = ProductInventoryService::validateProductPath($name);
                if (!$pathCheck['ok']) {
                    return ['ok' => false, 'reason_code' => 'package_path_invalid'];
                }

                if (isset($seenZipPaths[$name])) {
                    return ['ok' => false, 'reason_code' => 'package_duplicate_entry'];
                }
                $seenZipPaths[$name] = true;

                $lowerName = strtolower($name);
                if (isset($seenLowerPaths[$lowerName])) {
                    return ['ok' => false, 'reason_code' => 'package_case_collision'];
                }
                $seenLowerPaths[$lowerName] = true;

                if (!isset($manifestFiles[$name])) {
                    return ['ok' => false, 'reason_code' => 'package_unexpected_entry'];
                }

                if (isset($stat['encryption_method']) && $stat['encryption_method'] !== 0) {
                    return ['ok' => false, 'reason_code' => 'package_entry_encrypted'];
                }

                $opsys = 0;
                $attr = 0;
                if (!$zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                    return ['ok' => false, 'reason_code' => 'package_entry_attributes_invalid'];
                }

                // Supported operating system types: DOS (0), UNIX (3), NTFS (10)
                if ($opsys !== ZipArchive::OPSYS_DOS && $opsys !== ZipArchive::OPSYS_UNIX && $opsys !== 10) {
                    return ['ok' => false, 'reason_code' => 'package_entry_attributes_invalid'];
                }

                if ($opsys === ZipArchive::OPSYS_UNIX) {
                    $unixMode = ($attr >> 16) & 0xFFFF;
                    if ($unixMode === 0) {
                        return ['ok' => false, 'reason_code' => 'package_entry_attributes_invalid'];
                    }
                    $fileType = $unixMode & 0xF000;
                    if ($fileType === 0x4000) {
                        return ['ok' => false, 'reason_code' => 'package_entry_directory'];
                    }
                    if ($fileType === 0xA000) {
                        return ['ok' => false, 'reason_code' => 'package_entry_symlink'];
                    }
                    if ($fileType !== 0x8000) {
                        return ['ok' => false, 'reason_code' => 'package_entry_non_regular'];
                    }

                    $dosAttr = $attr & 0xFFFF;
                    if (($dosAttr & 0x0010) !== 0) {
                        return ['ok' => false, 'reason_code' => 'package_entry_directory'];
                    }
                    if (($dosAttr & 0x0400) !== 0) {
                        return ['ok' => false, 'reason_code' => 'package_entry_reparse'];
                    }
                } else {
                    // DOS (0) or NTFS (10)
                    $dosAttr = $attr & 0xFFFF;
                    if (($dosAttr & 0x0010) !== 0) {
                        return ['ok' => false, 'reason_code' => 'package_entry_directory'];
                    }
                    if (($dosAttr & 0x0400) !== 0) {
                        return ['ok' => false, 'reason_code' => 'package_entry_reparse'];
                    }
                    if (($dosAttr & 0x0008) !== 0) {
                        return ['ok' => false, 'reason_code' => 'package_entry_non_regular'];
                    }

                    $unixMode = ($attr >> 16) & 0xFFFF;
                    if ($unixMode !== 0) {
                        $fileType = $unixMode & 0xF000;
                        if ($fileType === 0x4000) {
                            return ['ok' => false, 'reason_code' => 'package_entry_directory'];
                        }
                        if ($fileType === 0xA000) {
                            return ['ok' => false, 'reason_code' => 'package_entry_symlink'];
                        }
                        if ($fileType !== 0x8000) {
                            return ['ok' => false, 'reason_code' => 'package_entry_non_regular'];
                        }
                    }
                }

                $signedSize = $manifestFiles[$name]['size'];
                $statSize = $stat['size'] ?? -1;

                if ($statSize !== $signedSize) {
                    return ['ok' => false, 'reason_code' => 'package_entry_size_mismatch'];
                }

                if ($signedSize < 0 || $signedSize > self::MAX_SINGLE_FILE_SIZE) {
                    return ['ok' => false, 'reason_code' => 'package_entry_too_large'];
                }

                $remainingTotal = self::MAX_UNCOMPRESSED_SIZE - $totalUncompressed;
                if ($signedSize > $remainingTotal) {
                    return ['ok' => false, 'reason_code' => 'package_uncompressed_overflow'];
                }

                $hardStop = min($signedSize, $statSize, self::MAX_SINGLE_FILE_SIZE, $remainingTotal);

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    return ['ok' => false, 'reason_code' => 'package_stream_error'];
                }

                $ctx = hash_init('sha256');
                $streamBytes = 0;
                $chunkSize = 65536;

                while (!feof($stream)) {
                    $bytesToRead = min($chunkSize, ($hardStop - $streamBytes) + 1);
                    if ($bytesToRead <= 0) {
                        fclose($stream);
                        return ['ok' => false, 'reason_code' => 'package_entry_too_large'];
                    }

                    $chunk = fread($stream, $bytesToRead);
                    if ($chunk === false) {
                        fclose($stream);
                        return ['ok' => false, 'reason_code' => 'package_stream_error'];
                    }
                    if ($chunk === '') {
                        break;
                    }

                    $streamBytes += strlen($chunk);
                    if ($streamBytes > $hardStop) {
                        fclose($stream);
                        return ['ok' => false, 'reason_code' => 'package_entry_too_large'];
                    }

                    hash_update($ctx, $chunk);
                }
                fclose($stream);

                $entryHash = hash_final($ctx);
                if ($streamBytes !== $signedSize || strtolower($entryHash) !== strtolower($manifestFiles[$name]['sha256'])) {
                    return ['ok' => false, 'reason_code' => 'package_content_mismatch'];
                }

                $totalUncompressed += $streamBytes;
            }

            if ($totalUncompressed !== $expectedUncompressed) {
                return ['ok' => false, 'reason_code' => 'package_uncompressed_mismatch'];
            }

            return ['ok' => true, 'reason_code' => null];
        } finally {
            $zip->close();
        }
    }
}
