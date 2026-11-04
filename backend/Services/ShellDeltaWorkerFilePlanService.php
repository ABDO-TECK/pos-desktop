<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * ShellDeltaWorkerFilePlanService
 *
 * INTERNAL PURE COMPLETE SIGNED WORKER FILE-PLAN PROJECTION.
 * Preparation for future authenticated Native broker transport.
 *
 * ARCHITECTURAL TRUST BOUNDARIES & EXPLICIT LIMITS:
 * 1. PURE IN-MEMORY PROJECTION:
 *    - Performs ZERO filesystem calls, directory scans, file existence checks,
 *      or path resolution.
 *    - Performs ZERO database queries, PDO operations, or ledger transactions.
 *    - Performs ZERO process creation, CLI execution, IPC, network, or logging.
 *    - Operates purely on immutable in-memory string and array inputs.
 *
 * 2. CRITICAL CAN_APPLY = FALSE INVARIANT:
 *    - The generated file plan ALWAYS declares can_apply = false.
 *    - Projecting a file plan DOES NOT authorize or perform file application.
 *    - Proving cryptographic consistency and projection between signed inventories
 *      is NOT an execution permit, NOT a directory lease, and NOT physical proof.
 *
 * 3. NATIVE BROKER VERIFICATION & PARSING IS MANDATORY (FUTURE WIRING NOT IMPLEMENTED):
 *    - In any future integrated architecture, the native broker MUST use the Native
 *      authentication and context binding modules (C# NativeBrokerManifestVerifier
 *      and NativeBrokerContextBinding) to verify raw RSA signatures, strict JSON syntax,
 *      and context bindings BEFORE communicating with worker processes.
 *    - Wiring between the native broker and worker processes is NOT implemented here,
 *      and this service makes no capability claims regarding live broker transport.
 *    - This pure PHP service REUSES existing PHP ShellDeltaManifestService full contract,
 *      canonical inventory digests, path set, and diff validations rather than duplicating
 *      them into native code.
 *    - This PHP service DOES NOT prove the native broker gate, does NOT generate
 *      an execution receipt, and does NOT claim or invent native_verified status.
 *    - PHP json_decode has known duplicate key and {} vs [] associative-array type
 *      limitations; the strict native JSON parser remains mandatory before future brokers
 *      consume this plan.
 *
 * 4. TRANSPORT & WORKER OWNERSHIP DISCLAIMER:
 *    - The returned plan_sha256 digest is strictly a deterministic content hash of the
 *      exact generated plan JSON string.
 *    - Returning plan_sha256 does NOT authenticate transport, does NOT act as a signing
 *      key, nonce, session token, or worker ownership receipt.
 *    - Callers cannot assume a plan is authentic just because an untrusted wire hash
 *      matches it. Future native brokers MUST authenticate the owned child worker process,
 *      verify IPC framing integrity, and independently verify the raw signed manifest.
 *
 * 5. PHYSICAL EXECUTION RESPONSIBILITY (NOT IMPLEMENTED HERE):
 *    - The retain list is NOT an apply capability.
 *    - Retained files (including unchanged POS Desktop.exe and version.json) must be held
 *      by the physical executor using exclusive handles during staging.
 *    - The physical executor must hold all source handles, keep the main executable absent
 *      through partial mutations, perform atomic rename/publish last, perform cold DB backups,
 *      and verify ZIP payload file extracts against staged files before applying. None of
 *      those physical operations are implemented or authorized by this service.
 *
 * 6. VERSION COMPARISON & PHP DIVERGENCE:
 *    - Known 30-digit PHP version_compare divergences with SemVer 2.0 exist in production;
 *      this service follows the production ShellDeltaManifestService validator policy.
 *    - Native shell version and application version are strictly separate. No native version
 *      advancement is required when updating application code.
 *
 * 7. DATABASE & SQL MUTATION POLICY:
 *    - Disallows ANY mutation (create, replace, or delete) to resources/portable/mysql/**
 *      or any file with a .sql suffix.
 *    - Retained engine assets and vendor SQL scripts are allowed and preserved in complete
 *      file tables.
 */
final class ShellDeltaWorkerFilePlanService
{
    public const FORMAT = 1;
    public const OPERATION = 'plan_signed_files';

    public const MAX_PLAN_JSON_BYTES = 67108864; // 64 MiB
    public const MAX_CONTEXT_JSON_BYTES = 4096;   // 4 KiB
    public const MAX_MANIFEST_SIZE = ShellDeltaManifestService::MAX_MANIFEST_SIZE; // 16 MiB
    public const MAX_SIGNATURE_SIZE = ShellDeltaManifestService::MAX_SIGNATURE_SIZE; // 16 KiB
    public const MAX_FILES = ShellDeltaManifestService::MAX_FILES; // 20000
    public const MAX_UNION_ACTIONS = 40000;

    public const METADATA_MIGRATION = UpdateDataCompatibilityService::METADATA_MIGRATION;

    private ShellDeltaManifestService $manifestService;

    /**
     * Constructor requires explicit injection of ShellDeltaManifestService.
     * No default ambient keys, key discovery, or automatic App bootstrap.
     */
    public function __construct(ShellDeltaManifestService $manifestService)
    {
        $this->manifestService = $manifestService;
    }

    /**
     * Build an immutable signed worker file-plan projection purely in memory.
     *
     * @param string $rawManifest Exact raw UTF-8 manifest JSON string
     * @param string $signature Base64 RSA PKCS#1 SHA-256 signature
     * @param string $expectedManifestSha256 Pinned 64-character lowercase hex digest of raw manifest
     * @param array<string, mixed> $sourceContext Trusted source endpoint context (exact 6 keys)
     * @param array<string, mixed> $targetContext Trusted target endpoint context (exact 6 keys)
     * @return array{
     *     can_apply: false,
     *     plan_json: string,
     *     plan_sha256: string
     * }
     */
    public function build(
        string $rawManifest,
        string $signature,
        string $expectedManifestSha256,
        array $sourceContext,
        array $targetContext
    ): array {
        // ---------------------------------------------------------------------
        // 1. Raw Bounds & Manifest Pin Pre-flight Check
        // ---------------------------------------------------------------------
        $manifestLen = strlen($rawManifest);
        if ($manifestLen === 0 || $manifestLen > self::MAX_MANIFEST_SIZE) {
            throw new RuntimeException("Manifest size exceeds allowed limits (1..16 MiB).");
        }

        $sigLen = strlen($signature);
        if ($sigLen === 0 || $sigLen > self::MAX_SIGNATURE_SIZE) {
            throw new RuntimeException("Signature size exceeds allowed limits (1..16 KiB).");
        }

        if (strlen($expectedManifestSha256) !== 64 || preg_match('/^[a-f0-9]{64}$/D', $expectedManifestSha256) !== 1) {
            throw new RuntimeException("Expected manifest SHA-256 must be a 64-character lowercase ASCII hex string.");
        }

        // Compare caller's pinned raw SHA-256 BEFORE signature verification and BEFORE JSON parsing.
        // Preserve exact original bytes; never reserialize or normalize before digest.
        $actualManifestSha256 = hash('sha256', $rawManifest);
        if ($actualManifestSha256 !== $expectedManifestSha256) {
            throw new RuntimeException("Manifest SHA-256 does not match expected caller pin.");
        }

        // ---------------------------------------------------------------------
        // 2. Strict Context Validation (Source and Target)
        // ---------------------------------------------------------------------
        // Reject root, paths, missing, or unknown keys BEFORE invoking any verifier.
        // Trusted host observations/consented target only; never copy request as context.
        $this->validateContext($sourceContext, 'sourceContext');
        $this->validateContext($targetContext, 'targetContext');

        // Cross-context invariants:
        // Must be on the exact same channel
        if ($sourceContext['channel'] !== $targetContext['channel']) {
            throw new RuntimeException("Source and target channels must match: '{$sourceContext['channel']}' !== '{$targetContext['channel']}'.");
        }

        // Application version progression must be strictly monotonic (inherited PHP policy)
        if (version_compare((string) $targetContext['version'], (string) $sourceContext['version'], '<=')) {
            throw new RuntimeException("Target app version must be strictly newer than source app version: '{$targetContext['version']}' <= '{$sourceContext['version']}'.");
        }

        // Source and target data compatibility must be strictly identical (four keys semantic comparison)
        $this->assertDataCompatibilityStrictlyEqual(
            $sourceContext['data_compatibility'],
            $targetContext['data_compatibility'],
            "Source and target context data compatibility contracts must be strictly identical."
        );

        // ---------------------------------------------------------------------
        // 3. Manifest Cryptographic & Full Contract Verification
        // ---------------------------------------------------------------------
        // Verify signature on exact raw bytes and validate full inventory canonical
        // digests, physical shell consistency, path sets, protected paths, and package declarations.
        $verified = $this->manifestService->verify($rawManifest, $signature, $sourceContext);
        if (!$verified['ok'] || !is_array($verified['descriptor'] ?? null)) {
            $reason = (string) ($verified['reason_code'] ?? 'verification_failed');
            throw new RuntimeException("Manifest verification failed: {$reason}");
        }
        $descriptor = $verified['descriptor'];

        // ---------------------------------------------------------------------
        // 4. File-Plan Specific Policy & Identity Verification
        // ---------------------------------------------------------------------
        // Purpose: REGULAR only. Bootstrap completion is strictly rejected.
        if (($descriptor['purpose'] ?? null) !== 'regular') {
            throw new RuntimeException("Only 'regular' purpose is supported for file plan projection. 'bootstrap_completion' fails closed.");
        }

        // Empty migrations only for file plan
        if (!empty($descriptor['migrations'])) {
            throw new RuntimeException("Migrations must be empty for file plan projection.");
        }

        // Target identity and data fields in descriptor must EXACTLY equal trusted targetContext
        if ($descriptor['channel'] !== $targetContext['channel']) {
            throw new RuntimeException("Manifest target channel mismatch with trusted target context.");
        }
        if ($descriptor['version'] !== $targetContext['version']) {
            throw new RuntimeException("Manifest target version mismatch with trusted target context.");
        }
        if ($descriptor['native_version'] !== $targetContext['native_version']) {
            throw new RuntimeException("Manifest target native_version mismatch with trusted target context.");
        }
        if ($descriptor['target_inventory_sha256'] !== $targetContext['inventory_sha256']) {
            throw new RuntimeException("Manifest target inventory_sha256 mismatch with trusted target context.");
        }

        $targetShellSha = $descriptor['target_inventory']['native_shell']['sha256'] ?? '';
        if ($targetShellSha !== $targetContext['native_shell_sha256']) {
            throw new RuntimeException("Manifest target native_shell_sha256 mismatch with trusted target context.");
        }

        // Descriptor source and target data compatibility must match trusted contexts and each other
        $this->assertDataCompatibilityStrictlyEqual(
            $descriptor['data_compatibility'],
            $targetContext['data_compatibility'],
            "Manifest target data compatibility mismatch with trusted target context."
        );
        $this->assertDataCompatibilityStrictlyEqual(
            $descriptor['source_data_compatibility'],
            $descriptor['data_compatibility'],
            "Manifest source and target data compatibility contracts must be strictly identical."
        );

        // ---------------------------------------------------------------------
        // 5. Build Canonical File Tables and Actions Projection
        // ---------------------------------------------------------------------
        $sourceFilesMap = $descriptor['source_inventory']['files'] ?? [];
        $targetFilesMap = $descriptor['target_inventory']['files'] ?? [];

        if (!is_array($sourceFilesMap) || !is_array($targetFilesMap)) {
            throw new RuntimeException("Manifest inventories files must be arrays.");
        }

        if (count($sourceFilesMap) > self::MAX_FILES || count($targetFilesMap) > self::MAX_FILES) {
            throw new RuntimeException("Inventory file count exceeds maximum allowed limit of " . self::MAX_FILES);
        }

        // Build sorted source_files list
        $sourcePaths = array_keys($sourceFilesMap);
        sort($sourcePaths, SORT_STRING);
        $sourceFilesList = [];
        foreach ($sourcePaths as $rawPath) {
            $p = (string) $rawPath;
            $sourceFilesList[] = [
                'path' => $p,
                'sha256' => (string) $sourceFilesMap[$p]['sha256'],
                'size' => (int) $sourceFilesMap[$p]['size'],
            ];
        }

        // Build sorted target_files list
        $targetPaths = array_keys($targetFilesMap);
        sort($targetPaths, SORT_STRING);
        $targetFilesList = [];
        foreach ($targetPaths as $rawPath) {
            $p = (string) $rawPath;
            $targetFilesList[] = [
                'path' => $p,
                'sha256' => (string) $targetFilesMap[$p]['sha256'],
                'size' => (int) $targetFilesMap[$p]['size'],
            ];
        }

        // Build union of paths for actions projection
        $allPaths = array_values(array_unique(array_merge($sourcePaths, $targetPaths)));
        sort($allPaths, SORT_STRING);

        if (count($allPaths) > self::MAX_UNION_ACTIONS) {
            throw new RuntimeException("Union path count exceeds maximum allowed limit of " . self::MAX_UNION_ACTIONS);
        }

        $actionsList = [];
        foreach ($allPaths as $rawPath) {
            $path = (string) $rawPath;
            $inSource = isset($sourceFilesMap[$path]);
            $inTarget = isset($targetFilesMap[$path]);

            $sourceData = $inSource ? [
                'sha256' => (string) $sourceFilesMap[$path]['sha256'],
                'size' => (int) $sourceFilesMap[$path]['size'],
            ] : null;

            $targetData = $inTarget ? [
                'sha256' => (string) $targetFilesMap[$path]['sha256'],
                'size' => (int) $targetFilesMap[$path]['size'],
            ] : null;

            if ($inSource && $inTarget) {
                // retain iff BOTH hash and size are strictly equal
                if ($sourceData['sha256'] === $targetData['sha256'] && $sourceData['size'] === $targetData['size']) {
                    $kind = 'retain';
                } else {
                    $kind = 'replace';
                }
            } elseif ($inTarget) {
                $kind = 'create';
            } else {
                $kind = 'delete';
            }

            // Enforce database asset and SQL schema mutation policy:
            // Reject CREATE, REPLACE, or DELETE to ANY casefolded resources/portable/mysql/** or *.sql suffix.
            // RETAIN engine/sql files are allowed and must remain in complete file tables.
            if ($kind !== 'retain') {
                $norm = strtolower(str_replace('\\', '/', $path));
                if (str_starts_with($norm, 'resources/portable/mysql/') || str_ends_with($norm, '.sql')) {
                    throw new RuntimeException("Disallowed mutation to database asset or SQL schema: {$path}");
                }
            }

            $actionsList[] = [
                'path' => $path,
                'kind' => $kind,
                'source' => $sourceData,
                'target' => $targetData,
            ];
        }

        // ---------------------------------------------------------------------
        // 6. Assemble Canonical Plan Body Array with Deterministic Key Order
        // ---------------------------------------------------------------------
        $sourceIdentity = [
            'version' => (string) $sourceContext['version'],
            'native_version' => (string) $sourceContext['native_version'],
            'channel' => (string) $sourceContext['channel'],
            'inventory_sha256' => (string) $sourceContext['inventory_sha256'],
            'native_shell_sha256' => (string) $sourceContext['native_shell_sha256'],
            'data_compatibility' => [
                'format' => (int) $sourceContext['data_compatibility']['format'],
                'metadata_migration' => (string) $sourceContext['data_compatibility']['metadata_migration'],
                'schema_sha256' => (string) $sourceContext['data_compatibility']['schema_sha256'],
                'ledger_sha256' => (string) $sourceContext['data_compatibility']['ledger_sha256'],
            ],
        ];

        $targetIdentity = [
            'version' => (string) $targetContext['version'],
            'native_version' => (string) $targetContext['native_version'],
            'channel' => (string) $targetContext['channel'],
            'inventory_sha256' => (string) $targetContext['inventory_sha256'],
            'native_shell_sha256' => (string) $targetContext['native_shell_sha256'],
            'data_compatibility' => [
                'format' => (int) $targetContext['data_compatibility']['format'],
                'metadata_migration' => (string) $targetContext['data_compatibility']['metadata_migration'],
                'schema_sha256' => (string) $targetContext['data_compatibility']['schema_sha256'],
                'ledger_sha256' => (string) $targetContext['data_compatibility']['ledger_sha256'],
            ],
        ];

        $packageMetadata = [
            'asset' => (string) $descriptor['package']['asset'],
            'sha256' => (string) $descriptor['package']['sha256'],
            'size' => (int) $descriptor['package']['size'],
            'uncompressed_size' => (int) $descriptor['package']['uncompressed_size'],
        ];

        $dataCompatibility = [
            'format' => (int) $descriptor['data_compatibility']['format'],
            'metadata_migration' => (string) $descriptor['data_compatibility']['metadata_migration'],
            'schema_sha256' => (string) $descriptor['data_compatibility']['schema_sha256'],
            'ledger_sha256' => (string) $descriptor['data_compatibility']['ledger_sha256'],
        ];

        $plan = [
            'format' => self::FORMAT,
            'operation' => self::OPERATION,
            'can_apply' => false,
            'manifest_sha256' => $actualManifestSha256,
            'source' => $sourceIdentity,
            'target' => $targetIdentity,
            'source_files' => $sourceFilesList,
            'target_files' => $targetFilesList,
            'actions' => $actionsList,
            'package' => $packageMetadata,
            'data_compatibility' => $dataCompatibility,
            'migrations' => [],
        ];

        // ---------------------------------------------------------------------
        // 7. Canonical JSON Encoding & Max Size Enforcement
        // ---------------------------------------------------------------------
        $planJson = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($planJson) > self::MAX_PLAN_JSON_BYTES) {
            throw new RuntimeException("Plan JSON exceeds maximum size limit of " . self::MAX_PLAN_JSON_BYTES . " bytes.");
        }

        // ---------------------------------------------------------------------
        // 8. Return Exact Enclosing Envelope (NO mutable decoded plan/model)
        // ---------------------------------------------------------------------
        return [
            'can_apply' => false,
            'plan_json' => $planJson,
            'plan_sha256' => hash('sha256', $planJson),
        ];
    }

    /**
     * Validate an installation endpoint context against strict structural and scalar rules.
     *
     * @param array<string, mixed> $context
     * @param string $paramName
     */
    private function validateContext(array $context, string $paramName): void
    {
        // Reject root and paths keys explicitly to prevent hidden filesystem lookups
        if (array_key_exists('root', $context) || array_key_exists('paths', $context)) {
            throw new RuntimeException("Context 'root' and 'paths' keys are strictly rejected in {$paramName}.");
        }

        // Required exact 6 keys: version, native_version, channel, inventory_sha256, native_shell_sha256, data_compatibility
        $keys = array_keys($context);
        sort($keys, SORT_STRING);
        $expectedKeys = [
            'channel',
            'data_compatibility',
            'inventory_sha256',
            'native_shell_sha256',
            'native_version',
            'version',
        ];
        if ($keys !== $expectedKeys) {
            throw new RuntimeException("{$paramName} must contain exact 6 keys: version, native_version, channel, inventory_sha256, native_shell_sha256, data_compatibility.");
        }

        // Channel: active channel ('stable' or 'beta')
        $channel = $context['channel'];
        if (!is_string($channel) || !in_array($channel, UpdateChannelPolicy::active(), true)) {
            throw new RuntimeException("Invalid {$paramName} channel: must be an active channel ('stable' or 'beta').");
        }

        // Version: ASCII 1..128 characters, valid SemVer 2.0, matches channel classification
        $version = $context['version'];
        if (!is_string($version) || strlen($version) < 1 || strlen($version) > 128 || preg_match('/^[\x20-\x7E]+$/D', $version) !== 1) {
            throw new RuntimeException("Invalid {$paramName} version: must be ASCII between 1 and 128 characters.");
        }
        if (!ProductInventoryService::isValidSemver($version)) {
            throw new RuntimeException("Invalid {$paramName} version semver: '{$version}'.");
        }
        if (UpdateChannelPolicy::installedChannel($version) !== $channel) {
            throw new RuntimeException("Invalid {$paramName} version classification for channel '{$channel}': '{$version}'.");
        }

        // Native Version: ASCII 1..128 characters, valid SemVer 2.0
        $nativeVersion = $context['native_version'];
        if (!is_string($nativeVersion) || strlen($nativeVersion) < 1 || strlen($nativeVersion) > 128 || preg_match('/^[\x20-\x7E]+$/D', $nativeVersion) !== 1) {
            throw new RuntimeException("Invalid {$paramName} native_version: must be ASCII between 1 and 128 characters.");
        }
        if (!ProductInventoryService::isValidSemver($nativeVersion)) {
            throw new RuntimeException("Invalid {$paramName} native_version semver: '{$nativeVersion}'.");
        }

        // Inventory SHA-256: 64-character lowercase hex string
        $invSha = $context['inventory_sha256'];
        if (!is_string($invSha) || preg_match('/^[a-f0-9]{64}$/D', $invSha) !== 1) {
            throw new RuntimeException("Invalid {$paramName} inventory_sha256: must be a 64-character lowercase hex string.");
        }

        // Native Shell SHA-256: 64-character lowercase hex string
        $shellSha = $context['native_shell_sha256'];
        if (!is_string($shellSha) || preg_match('/^[a-f0-9]{64}$/D', $shellSha) !== 1) {
            throw new RuntimeException("Invalid {$paramName} native_shell_sha256: must be a 64-character lowercase hex string.");
        }

        // Data Compatibility: exact 4 keys (format=1, metadata_migration, schema_sha256, ledger_sha256)
        $dataCompat = $context['data_compatibility'];
        if (!is_array($dataCompat)) {
            throw new RuntimeException("Invalid {$paramName} data_compatibility: must be an array.");
        }
        $compatKeys = array_keys($dataCompat);
        sort($compatKeys, SORT_STRING);
        if ($compatKeys !== ['format', 'ledger_sha256', 'metadata_migration', 'schema_sha256']) {
            throw new RuntimeException("Invalid {$paramName} data_compatibility keys: must contain exact 4 keys.");
        }
        if (!is_int($dataCompat['format']) || $dataCompat['format'] !== 1) {
            throw new RuntimeException("Invalid {$paramName} data_compatibility format: must be integer 1.");
        }
        if (!is_string($dataCompat['metadata_migration']) || $dataCompat['metadata_migration'] !== self::METADATA_MIGRATION) {
            throw new RuntimeException("Invalid {$paramName} data_compatibility metadata_migration constant.");
        }
        if (!is_string($dataCompat['schema_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $dataCompat['schema_sha256']) !== 1) {
            throw new RuntimeException("Invalid {$paramName} data_compatibility schema_sha256: must be a 64-character lowercase hex string.");
        }
        if (!is_string($dataCompat['ledger_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $dataCompat['ledger_sha256']) !== 1) {
            throw new RuntimeException("Invalid {$paramName} data_compatibility ledger_sha256: must be a 64-character lowercase hex string.");
        }

        // Context size bound: JSON representation must be <= 4096 bytes
        // Executed strictly AFTER all 6 context scalars and nested data fields are validated as bounded primitives.
        // Guarantees only bounded primitive strings/ints ever reach json_encode with zero user-code invocation.
        $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > self::MAX_CONTEXT_JSON_BYTES) {
            throw new RuntimeException("{$paramName} JSON representation exceeds maximum limit of " . self::MAX_CONTEXT_JSON_BYTES . " bytes.");
        }
    }

    /**
     * Assert two data compatibility contracts are strictly identical on all 4 keys.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @param string $errorMessage
     */
    private function assertDataCompatibilityStrictlyEqual(array $a, array $b, string $errorMessage): void
    {
        $keys = ['format', 'metadata_migration', 'schema_sha256', 'ledger_sha256'];
        foreach ($keys as $k) {
            if (!isset($a[$k], $b[$k]) || $a[$k] !== $b[$k]) {
                throw new RuntimeException($errorMessage);
            }
        }
    }
}
