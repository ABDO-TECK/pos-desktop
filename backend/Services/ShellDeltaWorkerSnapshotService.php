<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * ShellDeltaWorkerSnapshotService
 *
 * INTERNAL authenticated SOURCE-runtime FILE snapshot slice (NOT launch/activation).
 *
 * CRITICAL ARCHITECTURAL CONSTRAINTS AND SAFETY BOUNDARIES:
 * - INTERNAL FILESYSTEM ONLY: Creates and verifies an isolated, authenticated,
 *   immutable source-runtime file snapshot in private state storage.
 * - NOT LAUNCH OR ACTIVATION: This service does NOT validate PHP CLI entrypoint
 *   or protocol capability, does NOT implement process quiescence or self-updater
 *   activation, and does NOT execute or verify worker CLI readiness. The actual
 *   source 13 PHAR may lack worker CLI capability entirely.
 * - NO INI EXECUTION OR PARSING: Any php.ini file present in the signed source
 *   inventory is copied strictly as opaque SIGNED BYTES without parsing,
 *   interpreting, or sanitizing directives. Future launchers MUST execute using
 *   isolated '-n', private extension directories, and MUST NOT inherit source
 *   system INI or ambient PATH configurations.
 * - READ-ONLY SOURCE INTEGRITY: Never mutates the product installation root.
 *   Performs NO customer state, configuration, or database I/O, and executes NO
 *   SQL queries. Static authorized code assets (including schema definitions)
 *   are hashed strictly as static files by full inventory verification.
 * - INDEPENDENT VERIFICATION: Snapshot verification is strictly independent of
 *   product root file contents; the product root may already be mutated, missing,
 *   or updated at verification time without invalidating the snapshot.
 * - PROCESS-CRASH AND FAIL-RETAIN: On any failure or exception during creation,
 *   partial snapshot directories, temporary files, and metadata are RETAINED for
 *   audit; no automatic recursive cleanup or error-path unlink is performed.
 * - RESIDUAL DURABILITY LIMITS & TOCTOU: Standard PHP file I/O operations on Windows
 *   cannot guarantee kernel-level directory metadata durability or atomic directory
 *   sync under catastrophic hardware power loss. Residual OS-level filesystem races
 *   (TOCTOU) cannot be totally eliminated by userspace code.
 */
final class ShellDeltaWorkerSnapshotService
{
    public const PHASE_FILES_VERIFIED = 'snapshot_files_verified';

    public const SNAPSHOTS_DIR = 'worker_snapshots';
    public const SEALED_DIR = 'sealed';
    public const PAYLOAD_DIR = 'payload';

    public const OWNER_FILENAME = '.pos_worker_snapshot_owner';
    public const COMPLETION_FILENAME = 'snapshot.json';

    public const REQUIRED_PHP_EXE = 'resources/portable/php/php.exe';
    public const REQUIRED_BACKEND_PHAR = 'resources/app.asar.unpacked/backend/backend.phar';
    public const REQUIRED_UPDATE_KEY = 'resources/app.asar.unpacked/backend/certs/update_public_key.pem';
    public const OPTIONAL_CACERT = 'resources/app.asar.unpacked/backend/certs/cacert.pem';
    public const PHP_PREFIX = 'resources/portable/php/';

    public const MAX_METADATA_SIZE = 8192; // 8 KiB

    private ShellDeltaManifestService $manifests;
    /** @var (callable(string, array<string, mixed>): void)|null */
    private $checkpointCallback;

    /**
     * @param ShellDeltaManifestService|null $manifests Manifest service dependency
     * @param (callable(string, array<string, mixed>): void)|null $checkpointCallback Test checkpoint hook (GHA only)
     */
    public function __construct(
        ?ShellDeltaManifestService $manifests = null,
        ?callable $checkpointCallback = null
    ) {
        $this->manifests = $manifests ?? new ShellDeltaManifestService();
        $this->checkpointCallback = $checkpointCallback;
    }

    /**
     * Reject drive root paths purely lexically before any filesystem resolution or I/O.
     */
    public static function assertNotDriveRoot(string $path, string $paramName): void
    {
        $trimmed = trim($path);
        if (preg_match('/^[a-zA-Z]:[\\\\\/]*$/D', $trimmed) === 1) {
            throw new RuntimeException("Drive root is not permitted as {$paramName}: '{$path}'");
        }
    }

    /**
     * Create a new immutable source-runtime snapshot from verified signed source inventory.
     * Product files are left completely unmodified.
     *
     * @param string $productRoot Absolute Windows product root directory
     * @param string $privateStateBase Absolute Windows private state base directory
     * @param string $rawManifest Raw UTF-8 manifest JSON
     * @param string $signature Base64 RSA PKCS#1 SHA-256 signature
     * @param array<string, mixed> $installedContext Required source context (root key forbidden)
     * @return array{
     *     snapshot_id: string,
     *     snapshot_root: string,
     *     snapshot_sha256: string,
     *     source_inventory_sha256: string,
     *     manifest_sha256: string,
     *     php_path: string,
     *     phar_path: string,
     *     phase: string
     * }
     */
    public function create(
        string $productRoot,
        string $privateStateBase,
        string $rawManifest,
        string $signature,
        array $installedContext
    ): array {
        // 1. Strict installed context validation (context root key rejected to prevent hidden reads)
        $this->validateInstalledContext($installedContext);

        // 2. Pure lexical drive-root rejection BEFORE any filesystem resolution, inventory, or allocation
        self::assertNotDriveRoot($productRoot, 'productRoot');
        self::assertNotDriveRoot($privateStateBase, 'privateStateBase');

        // 3. Validate canonical directories (productRoot must exist, privateStateBase must exist, mutually disjoint)
        [$canonicalRoot, $canonicalStateBase] = $this->validateDirectories($productRoot, $privateStateBase, true);

        // 4. Pre-flight authenticate raw manifest and signature before any directory allocation or file copies
        if (strlen($rawManifest) === 0 || strlen($rawManifest) > ShellDeltaManifestService::MAX_MANIFEST_SIZE) {
            throw new RuntimeException("Manifest size exceeds allowed limits.");
        }
        if (strlen($signature) === 0 || strlen($signature) > ShellDeltaManifestService::MAX_SIGNATURE_SIZE) {
            throw new RuntimeException("Signature size exceeds allowed limits.");
        }

        $preCheck = $this->manifests->verify($rawManifest, $signature, $installedContext);
        if (!$preCheck['ok'] || !is_array($preCheck['descriptor'])) {
            throw new RuntimeException("Manifest verification failed pre-flight: " . ($preCheck['reason_code'] ?? 'verification_failed'));
        }
        $descriptor = $preCheck['descriptor'];

        // 5. Derive exact expected payload set derived ONLY from signed source_inventory.files
        $selectedMap = $this->deriveSelectedPayloadMap($descriptor);

        // 6. Fresh FULL build inventory check BEFORE any payload copy
        $this->assertRootExactSource($canonicalRoot, $descriptor);

        // 7. Allocate fresh nonce snapshot leaf under privateStateBase/worker_snapshots/rt_<32lowerhex>
        $snapshotsDir = $this->ensureDirectoryComponent($canonicalStateBase, self::SNAPSHOTS_DIR);
        $snapshotNonce = bin2hex(random_bytes(16));
        $snapshotId = 'rt_' . $snapshotNonce;
        $snapshotRoot = $snapshotsDir . DIRECTORY_SEPARATOR . $snapshotId;

        // Establish absence via parent listing and guard raw parent immediately before mkdir
        ProductInventoryService::assertNoReparse($snapshotsDir);
        $existingSnapshots = scandir($snapshotsDir);
        if ($existingSnapshots === false) {
            throw new RuntimeException("Failed to scan snapshots directory: {$snapshotsDir}");
        }
        if (in_array($snapshotId, $existingSnapshots, true)) {
            throw new RuntimeException("Snapshot leaf already exists: {$snapshotId}. Collisions must never overwrite or reuse.");
        }

        ProductInventoryService::assertNoReparse($snapshotsDir);
        if (!@mkdir($snapshotRoot, 0700, false)) {
            throw new RuntimeException("Failed to create snapshot directory: {$snapshotRoot}");
        }

        ProductInventoryService::assertNoReparse($snapshotRoot);
        $canonicalSnapshotRoot = ProductInventoryService::resolveAbsolutePath($snapshotRoot, true);
        ProductInventoryService::assertNoReparse($canonicalSnapshotRoot);

        // Verify snapshotRoot is strictly confined within snapshotsDir
        $normSnapshotsDir = rtrim(strtolower(str_replace('/', '\\', $snapshotsDir)), '\\');
        $normSnapshotRoot = rtrim(strtolower(str_replace('/', '\\', $canonicalSnapshotRoot)), '\\');
        if (!str_starts_with($normSnapshotRoot, $normSnapshotsDir . '\\')) {
            throw new RuntimeException("Snapshot path escape detected: {$canonicalSnapshotRoot}");
        }

        // 8. Write owner record FIRST before any payload or sealed writes (exclusive create)
        $ownerRecord = [
            'format' => 1,
            'snapshot_id' => $snapshotId,
            'root' => $canonicalRoot,
            'source_inventory_sha256' => $descriptor['source_inventory_sha256'],
            'manifest_sha256' => hash('sha256', $rawManifest),
            'signature_sha256' => hash('sha256', $signature),
            'created_at' => time(),
        ];
        $ownerFilePath = $canonicalSnapshotRoot . DIRECTORY_SEPARATOR . self::OWNER_FILENAME;
        $this->writeExclusiveJsonFile($ownerFilePath, $ownerRecord, 'owner record');
        $ownerRawBytes = $this->readBoundedFile($ownerFilePath, self::MAX_METADATA_SIZE);
        $ownerSha256 = hash('sha256', $ownerRawBytes);

        // 9. Create sealed and payload subdirectories
        $sealedDir = $this->ensureDirectoryComponent($canonicalSnapshotRoot, self::SEALED_DIR);
        $payloadDir = $this->ensureDirectoryComponent($canonicalSnapshotRoot, self::PAYLOAD_DIR);

        // 10. Write sealed assets (exact raw manifest and signature bytes)
        $sealedManifestPath = $sealedDir . DIRECTORY_SEPARATOR . ShellDeltaManifestService::MANIFEST_ASSET;
        $sealedSigPath = $sealedDir . DIRECTORY_SEPARATOR . ShellDeltaManifestService::SIGNATURE_ASSET;
        $this->writeExclusiveRawFile($sealedManifestPath, $rawManifest);
        $this->writeExclusiveRawFile($sealedSigPath, $signature);

        // 11. Copy each selected payload file using bounded streams and exclusive creation
        foreach ($selectedMap as $relPath => $fileMeta) {
            // Surgical GHA checkpoint before opening source file
            $this->triggerCheckpoint('before_source_copy', ['path' => $relPath]);

            // Re-validate roots before new I/O after callback
            ProductInventoryService::assertNoReparse($canonicalRoot);
            ProductInventoryService::assertNoReparse($canonicalSnapshotRoot);

            // Re-resolve source file segment by segment to prevent traversal, ADS, or reparse
            $sourceAbsPath = $this->readonlyProductWalk($canonicalRoot, $relPath);
            ProductInventoryService::assertNoReparse($sourceAbsPath);

            // Ensure destination directory components exist inside payload
            $relDir = dirname($relPath);
            $destParentDir = ($relDir === '.' || $relDir === '')
                ? $payloadDir
                : $this->ensureRelativeDirectoryTree($payloadDir, $relDir);

            ProductInventoryService::assertNoReparse($destParentDir);

            $fileName = basename($relPath);
            // Missing dest leaf confinement validation via parent listing
            $parentEntries = scandir($destParentDir);
            if ($parentEntries === false) {
                throw new RuntimeException("Failed to scan destination directory: {$destParentDir}");
            }
            if (in_array($fileName, $parentEntries, true)) {
                throw new RuntimeException("Destination payload file already exists in parent listing: {$fileName}");
            }

            $destFilePath = $destParentDir . DIRECTORY_SEPARATOR . $fileName;

            // Copy file exclusively with verified size, sha256, and fsync
            $this->copyFileExclusive($sourceAbsPath, $destFilePath, $fileMeta, $relPath);

            // Surgical GHA checkpoint after payload close
            $this->triggerCheckpoint('after_payload_close', [
                'path' => $relPath,
                'snapshot_path' => $destFilePath,
            ]);

            // Re-validate roots and re-verify actual destination file post-close
            ProductInventoryService::assertNoReparse($canonicalSnapshotRoot);
            ProductInventoryService::assertNoReparse($destFilePath);
            clearstatcache(true, $destFilePath);
            if (!is_file($destFilePath) || is_link($destFilePath)) {
                throw new RuntimeException("Destination payload file is missing or not a regular file after close: {$relPath}");
            }
            $destSize = filesize($destFilePath);
            if ($destSize !== $fileMeta['size']) {
                throw new RuntimeException("Destination payload file size mismatch after close: {$relPath}");
            }
            $destHash = hash_file('sha256', $destFilePath);
            if ($destHash !== $fileMeta['sha256']) {
                throw new RuntimeException("Destination payload file digest mismatch after close: {$relPath}");
            }
        }

        // Derive canonical ordered selected map (path => {sha256, size}, fixed key order) -> scalar 64 hex digest
        $filesSha256Digest = $this->computeCanonicalFilesSha256($selectedMap);

        // 12. Surgical GHA checkpoint before writing completion record
        $this->triggerCheckpoint('before_completion', ['snapshot_root' => $canonicalSnapshotRoot]);

        // 13. Fresh FULL source proof AGAIN before writing completion record
        $this->assertRootExactSource($canonicalRoot, $descriptor);

        // 14. Fresh FULL snapshot state verification BEFORE writing completion record
        $completionFilePath = $canonicalSnapshotRoot . DIRECTORY_SEPARATOR . self::COMPLETION_FILENAME;
        clearstatcache(true, $completionFilePath);
        if (file_exists($completionFilePath) || is_link($completionFilePath)) {
            throw new RuntimeException("Foreign completion record already present in snapshot before completion: {$completionFilePath}");
        }

        // Verify owner record exact bytes and digest
        $currentOwnerRaw = $this->readBoundedFile($ownerFilePath, self::MAX_METADATA_SIZE);
        if (!hash_equals($ownerSha256, hash('sha256', $currentOwnerRaw))) {
            throw new RuntimeException("Owner record was modified or tampered before completion.");
        }

        // Verify sealed manifest and signature exact bytes
        $currentSealedManifest = $this->readBoundedFile($sealedManifestPath, ShellDeltaManifestService::MAX_MANIFEST_SIZE);
        if ($currentSealedManifest !== $rawManifest) {
            throw new RuntimeException("Sealed manifest was modified or tampered before completion.");
        }
        $currentSealedSig = $this->readBoundedFile($sealedSigPath, ShellDeltaManifestService::MAX_SIGNATURE_SIZE);
        if ($currentSealedSig !== $signature) {
            throw new RuntimeException("Sealed signature was modified or tampered before completion.");
        }

        // Verify closed snapshot tree (expecting owner, sealed, payload, NO completion)
        $this->verifyClosedSnapshotTree($canonicalSnapshotRoot, $selectedMap, false);

        // Re-verify every actual payload file size and SHA-256 digest
        foreach ($selectedMap as $relPath => $fileMeta) {
            $actualPayloadPath = $payloadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            ProductInventoryService::assertNoReparse($actualPayloadPath);
            clearstatcache(true, $actualPayloadPath);
            if (!is_file($actualPayloadPath) || is_link($actualPayloadPath)) {
                throw new RuntimeException("Payload file missing or non-regular before completion: {$relPath}");
            }
            if (filesize($actualPayloadPath) !== $fileMeta['size']) {
                throw new RuntimeException("Payload file size mismatch before completion: {$relPath}");
            }
            if (hash_file('sha256', $actualPayloadPath) !== $fileMeta['sha256']) {
                throw new RuntimeException("Payload file digest mismatch before completion: {$relPath}");
            }
        }

        // 15. Write completion record LAST only after all payload verified + fresh source proof + snapshot closed tree verified
        $completionRecord = [
            'format' => 1,
            'snapshot_id' => $snapshotId,
            'root' => $canonicalRoot,
            'source_inventory_sha256' => $descriptor['source_inventory_sha256'],
            'manifest_sha256' => hash('sha256', $rawManifest),
            'signature_sha256' => hash('sha256', $signature),
            'files_sha256' => $filesSha256Digest,
            'owner_sha256' => $ownerSha256,
            'created_at' => time(),
        ];
        $this->writeExclusiveJsonFile($completionFilePath, $completionRecord, 'completion record');

        $completionRawBytes = $this->readBoundedFile($completionFilePath, self::MAX_METADATA_SIZE);
        $snapshotSha256 = hash('sha256', $completionRawBytes);

        // 16. Perform final self-verification with pinned expected completion hash
        return $this->verify(
            $canonicalRoot,
            $canonicalStateBase,
            $snapshotId,
            $snapshotSha256,
            $rawManifest,
            $signature,
            $installedContext
        );
    }

    /**
     * Verify an existing snapshot without reading or depending on product root file contents.
     * Completely read-only: performs zero writes to disk and zero deletions.
     *
     * @param string $productRoot Product root path for metadata binding (contents NOT read)
     * @param string $privateStateBase Absolute private state base directory
     * @param string $snapshotId Snapshot identifier (rt_<32lowerhex>)
     * @param string $expectedSnapshotSha256 Expected opaque snapshot.json SHA-256 digest
     * @param string $rawManifest Raw UTF-8 manifest JSON
     * @param string $signature Base64 RSA PKCS#1 SHA-256 signature
     * @param array<string, mixed> $installedContext Required source context (root key forbidden)
     * @return array{
     *     snapshot_id: string,
     *     snapshot_root: string,
     *     snapshot_sha256: string,
     *     source_inventory_sha256: string,
     *     manifest_sha256: string,
     *     php_path: string,
     *     phar_path: string,
     *     phase: string
     * }
     */
    public function verify(
        string $productRoot,
        string $privateStateBase,
        string $snapshotId,
        string $expectedSnapshotSha256,
        string $rawManifest,
        string $signature,
        array $installedContext
    ): array {
        // 1. Strict installed context validation (context root key rejected)
        $this->validateInstalledContext($installedContext);

        // 2. Pure lexical drive-root rejection BEFORE any filesystem resolution or I/O
        self::assertNotDriveRoot($productRoot, 'productRoot');
        self::assertNotDriveRoot($privateStateBase, 'privateStateBase');

        // 3. Validate snapshotId syntax
        if (!is_string($snapshotId) || preg_match('/^rt_[a-f0-9]{32}$/D', $snapshotId) !== 1) {
            throw new RuntimeException("Invalid snapshot ID format: {$snapshotId}");
        }

        // 4. Validate expectedSnapshotSha256 syntax
        if (!is_string($expectedSnapshotSha256) || preg_match('/^[a-f0-9]{64}$/D', $expectedSnapshotSha256) !== 1) {
            throw new RuntimeException("Invalid expected snapshot digest format.");
        }

        // 5. Validate productRoot (metadata canonicalization ONLY, product may be missing or mutated)
        [$canonicalRoot, $canonicalStateBase] = $this->validateDirectories($productRoot, $privateStateBase, false);

        // 6. Re-authenticate CALLER raw manifest and signature (without context root)
        if (strlen($rawManifest) === 0 || strlen($rawManifest) > ShellDeltaManifestService::MAX_MANIFEST_SIZE) {
            throw new RuntimeException("Manifest size exceeds allowed limits.");
        }
        if (strlen($signature) === 0 || strlen($signature) > ShellDeltaManifestService::MAX_SIGNATURE_SIZE) {
            throw new RuntimeException("Signature size exceeds allowed limits.");
        }

        $preCheck = $this->manifests->verify($rawManifest, $signature, $installedContext);
        if (!$preCheck['ok'] || !is_array($preCheck['descriptor'])) {
            throw new RuntimeException("Caller manifest verification failed: " . ($preCheck['reason_code'] ?? 'verification_failed'));
        }
        $descriptor = $preCheck['descriptor'];

        // 7. Locate snapshot root and enforce containment inside privateStateBase/worker_snapshots
        $snapshotsDir = $canonicalStateBase . DIRECTORY_SEPARATOR . self::SNAPSHOTS_DIR;
        $snapshotRoot = $snapshotsDir . DIRECTORY_SEPARATOR . $snapshotId;

        clearstatcache(true, $snapshotRoot);
        if (!file_exists($snapshotRoot) || is_link($snapshotRoot) || !is_dir($snapshotRoot)) {
            throw new RuntimeException("Snapshot directory does not exist or is not a directory: {$snapshotId}");
        }

        ProductInventoryService::assertNoReparse($snapshotRoot);
        $canonicalSnapshotRoot = ProductInventoryService::resolveAbsolutePath($snapshotRoot, true);
        ProductInventoryService::assertNoReparse($canonicalSnapshotRoot);

        $normSnapshotsDir = rtrim(strtolower(str_replace('/', '\\', $snapshotsDir)), '\\');
        $normSnapshotRoot = rtrim(strtolower(str_replace('/', '\\', $canonicalSnapshotRoot)), '\\');
        if (!str_starts_with($normSnapshotRoot, $normSnapshotsDir . '\\')) {
            throw new RuntimeException("Snapshot path escape detected: {$canonicalSnapshotRoot}");
        }

        // 8. Read completion record bytes and verify expected SHA-256 BEFORE trusting JSON schema
        $completionPath = $canonicalSnapshotRoot . DIRECTORY_SEPARATOR . self::COMPLETION_FILENAME;
        $rawCompletionBytes = $this->readBoundedFile($completionPath, self::MAX_METADATA_SIZE);
        $actualCompletionSha256 = hash('sha256', $rawCompletionBytes);
        if (!hash_equals($expectedSnapshotSha256, $actualCompletionSha256)) {
            throw new RuntimeException("Snapshot completion digest mismatch: expected {$expectedSnapshotSha256}, got {$actualCompletionSha256}");
        }

        // 9. Strict schema verification for completion record
        $completion = json_decode($rawCompletionBytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($completion)) {
            throw new RuntimeException("Malformed completion record JSON.");
        }
        $expectedCompletionKeys = [
            'format',
            'snapshot_id',
            'root',
            'source_inventory_sha256',
            'manifest_sha256',
            'signature_sha256',
            'files_sha256',
            'owner_sha256',
            'created_at',
        ];
        $actualCompletionKeys = array_keys($completion);
        sort($expectedCompletionKeys);
        sort($actualCompletionKeys);
        if ($actualCompletionKeys !== $expectedCompletionKeys) {
            throw new RuntimeException("Completion record schema contains unexpected or missing keys.");
        }

        if ($completion['format'] !== 1 ||
            $completion['snapshot_id'] !== $snapshotId ||
            $completion['root'] !== $canonicalRoot ||
            $completion['source_inventory_sha256'] !== $descriptor['source_inventory_sha256'] ||
            $completion['manifest_sha256'] !== hash('sha256', $rawManifest) ||
            $completion['signature_sha256'] !== hash('sha256', $signature) ||
            !is_string($completion['files_sha256']) ||
            preg_match('/^[a-f0-9]{64}$/D', $completion['files_sha256']) !== 1 ||
            !is_string($completion['owner_sha256']) ||
            preg_match('/^[a-f0-9]{64}$/D', $completion['owner_sha256']) !== 1 ||
            !is_int($completion['created_at']) ||
            $completion['created_at'] <= 0) {
            throw new RuntimeException("Completion record field validation failed.");
        }

        // 10. Read owner record and verify hash against completion record
        $ownerPath = $canonicalSnapshotRoot . DIRECTORY_SEPARATOR . self::OWNER_FILENAME;
        $rawOwnerBytes = $this->readBoundedFile($ownerPath, self::MAX_METADATA_SIZE);
        $actualOwnerSha256 = hash('sha256', $rawOwnerBytes);
        if (!hash_equals($completion['owner_sha256'], $actualOwnerSha256)) {
            throw new RuntimeException("Owner record digest does not match completion record.");
        }

        $owner = json_decode($rawOwnerBytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($owner)) {
            throw new RuntimeException("Malformed owner record JSON.");
        }
        $expectedOwnerKeys = [
            'format',
            'snapshot_id',
            'root',
            'source_inventory_sha256',
            'manifest_sha256',
            'signature_sha256',
            'created_at',
        ];
        $actualOwnerKeys = array_keys($owner);
        sort($expectedOwnerKeys);
        sort($actualOwnerKeys);
        if ($actualOwnerKeys !== $expectedOwnerKeys) {
            throw new RuntimeException("Owner record schema contains unexpected or missing keys.");
        }

        if ($owner['format'] !== 1 ||
            $owner['snapshot_id'] !== $snapshotId ||
            $owner['root'] !== $canonicalRoot ||
            $owner['source_inventory_sha256'] !== $descriptor['source_inventory_sha256'] ||
            $owner['manifest_sha256'] !== hash('sha256', $rawManifest) ||
            $owner['signature_sha256'] !== hash('sha256', $signature) ||
            !is_int($owner['created_at']) ||
            $owner['created_at'] <= 0) {
            throw new RuntimeException("Owner record field validation failed.");
        }

        // 11. Verify sealed manifest and signature exact bytes match caller
        $sealedManifestPath = $canonicalSnapshotRoot . DIRECTORY_SEPARATOR . self::SEALED_DIR . DIRECTORY_SEPARATOR . ShellDeltaManifestService::MANIFEST_ASSET;
        $sealedSigPath = $canonicalSnapshotRoot . DIRECTORY_SEPARATOR . self::SEALED_DIR . DIRECTORY_SEPARATOR . ShellDeltaManifestService::SIGNATURE_ASSET;

        $sealedManifestBytes = $this->readBoundedFile($sealedManifestPath, ShellDeltaManifestService::MAX_MANIFEST_SIZE);
        $sealedSigBytes = $this->readBoundedFile($sealedSigPath, ShellDeltaManifestService::MAX_SIGNATURE_SIZE);

        if ($sealedManifestBytes !== $rawManifest) {
            throw new RuntimeException("Sealed manifest does not match caller manifest.");
        }
        if ($sealedSigBytes !== $signature) {
            throw new RuntimeException("Sealed signature does not match caller signature.");
        }

        // 12. Freshly derive selected payload set from signed source inventory
        $derivedSelectedMap = $this->deriveSelectedPayloadMap($descriptor);
        $derivedFilesSha256 = $this->computeCanonicalFilesSha256($derivedSelectedMap);

        if (!hash_equals($derivedFilesSha256, $completion['files_sha256'])) {
            throw new RuntimeException("Completion files_sha256 map does not match signed source inventory derivation.");
        }

        // 13. Verify EXACT CLOSED private tree set (no extra, no missing, no reparse, no foreign entries)
        $this->verifyClosedSnapshotTree($canonicalSnapshotRoot, $derivedSelectedMap, true);

        // 14. Reverify size and SHA-256 digest of every actual copied file
        $payloadDir = $canonicalSnapshotRoot . DIRECTORY_SEPARATOR . self::PAYLOAD_DIR;
        foreach ($derivedSelectedMap as $relPath => $fileMeta) {
            $actualFilePath = $payloadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            ProductInventoryService::assertNoReparse($actualFilePath);
            clearstatcache(true, $actualFilePath);
            if (!is_file($actualFilePath) || is_link($actualFilePath)) {
                throw new RuntimeException("Snapshot payload file missing or non-regular: {$relPath}");
            }
            if (filesize($actualFilePath) !== $fileMeta['size']) {
                throw new RuntimeException("Snapshot payload file size mismatch: {$relPath}");
            }
            if (hash_file('sha256', $actualFilePath) !== $fileMeta['sha256']) {
                throw new RuntimeException("Snapshot payload file digest mismatch: {$relPath}");
            }
        }

        $phpPath = $payloadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::REQUIRED_PHP_EXE);
        $pharPath = $payloadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::REQUIRED_BACKEND_PHAR);

        return [
            'snapshot_id' => $snapshotId,
            'snapshot_root' => $canonicalSnapshotRoot,
            'snapshot_sha256' => $actualCompletionSha256,
            'source_inventory_sha256' => $descriptor['source_inventory_sha256'],
            'manifest_sha256' => hash('sha256', $rawManifest),
            'php_path' => $phpPath,
            'phar_path' => $pharPath,
            'phase' => self::PHASE_FILES_VERIFIED,
        ];
    }

    /**
     * Compute canonical scalar SHA-256 digest over selected files map.
     * Fixed structure: canonical ordered selected map (path => {sha256, size}, fixed key order),
     * encoded with JSON_UNESCAPED_SLASHES, returning a 64-character lowercase hex string.
     *
     * @param array<string, array{size: int, sha256: string}> $selectedMap
     */
    private function computeCanonicalFilesSha256(array $selectedMap): string
    {
        $canonicalMap = [];
        foreach ($selectedMap as $path => $meta) {
            $canonicalMap[$path] = [
                'sha256' => $meta['sha256'],
                'size' => $meta['size'],
            ];
        }
        ksort($canonicalMap, SORT_STRING);
        $json = json_encode($canonicalMap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json);
    }

    /**
     * Validate installed context format and keys.
     * Rejects 'root' key to prevent hidden filesystem reads, rejects unknown keys,
     * and validates scalar strict types.
     *
     * @param array<string, mixed> $context
     */
    private function validateInstalledContext(array $context): void
    {
        if (array_key_exists('root', $context)) {
            throw new RuntimeException("Context root key is strictly rejected to prevent hidden filesystem reads.");
        }

        $allowedKeys = [
            'version',
            'native_version',
            'channel',
            'data_compatibility',
            'inventory_sha256',
            'native_shell_sha256',
        ];

        foreach (array_keys($context) as $key) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                throw new RuntimeException("Unknown installedContext key: {$key}");
            }
        }

        $requiredKeys = ['version', 'native_version', 'channel', 'data_compatibility'];
        foreach ($requiredKeys as $req) {
            if (!array_key_exists($req, $context)) {
                throw new RuntimeException("Missing required installedContext key: {$req}");
            }
        }

        if (!is_string($context['version']) || !ProductInventoryService::isValidSemver($context['version'])) {
            throw new RuntimeException("Invalid installedContext version semver.");
        }
        if (!is_string($context['native_version']) || !ProductInventoryService::isValidSemver($context['native_version'])) {
            throw new RuntimeException("Invalid installedContext native_version semver.");
        }
        if (!is_string($context['channel']) || !in_array($context['channel'], UpdateChannelPolicy::active(), true)) {
            throw new RuntimeException("Invalid installedContext channel.");
        }

        if (!is_array($context['data_compatibility'])) {
            throw new RuntimeException("Invalid installedContext data_compatibility contract.");
        }
        $compat = $context['data_compatibility'];
        $compatKeys = array_keys($compat);
        sort($compatKeys);
        if ($compatKeys !== ['format', 'ledger_sha256', 'metadata_migration', 'schema_sha256']) {
            throw new RuntimeException("Invalid installedContext data_compatibility keys.");
        }
        if ($compat['format'] !== 1 ||
            !is_string($compat['metadata_migration']) ||
            $compat['metadata_migration'] === '' ||
            !is_string($compat['schema_sha256']) ||
            preg_match('/^[a-f0-9]{64}$/D', $compat['schema_sha256']) !== 1 ||
            !is_string($compat['ledger_sha256']) ||
            preg_match('/^[a-f0-9]{64}$/D', $compat['ledger_sha256']) !== 1) {
            throw new RuntimeException("Invalid installedContext data_compatibility values.");
        }

        if (array_key_exists('inventory_sha256', $context)) {
            if (!is_string($context['inventory_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $context['inventory_sha256']) !== 1) {
                throw new RuntimeException("Invalid installedContext inventory_sha256 format.");
            }
        }
        if (array_key_exists('native_shell_sha256', $context)) {
            if (!is_string($context['native_shell_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $context['native_shell_sha256']) !== 1) {
                throw new RuntimeException("Invalid installedContext native_shell_sha256 format.");
            }
        }
    }

    /**
     * Validate canonical directories and verify mutual disjointness.
     *
     * @return array{0: string, 1: string}
     */
    private function validateDirectories(string $root, string $privateStateBase, bool $rootMustExist = true): array
    {
        self::assertNotDriveRoot($root, 'productRoot');
        self::assertNotDriveRoot($privateStateBase, 'privateStateBase');

        $canonicalRoot = ProductInventoryService::resolveAbsolutePath($root, $rootMustExist);
        if ($rootMustExist) {
            ProductInventoryService::assertNoReparse($canonicalRoot);
            if (!is_dir($canonicalRoot)) {
                throw new RuntimeException("Product root is not a directory: {$root}");
            }
        }

        $canonicalStateBase = ProductInventoryService::resolveAbsolutePath($privateStateBase, true);
        ProductInventoryService::assertNoReparse($canonicalStateBase);
        if (!is_dir($canonicalStateBase)) {
            throw new RuntimeException("Private state base is not a directory: {$privateStateBase}");
        }

        // Must be mutually disjoint in BOTH directions (case-insensitive Windows canonical)
        $normRoot = rtrim(strtolower(str_replace('/', '\\', $canonicalRoot)), '\\');
        $normState = rtrim(strtolower(str_replace('/', '\\', $canonicalStateBase)), '\\');

        if ($normRoot === $normState) {
            throw new RuntimeException("Product root and private state base cannot be the same directory.");
        }
        if (str_starts_with($normState, $normRoot . '\\')) {
            throw new RuntimeException("Private state base cannot reside inside the product root.");
        }
        if (str_starts_with($normRoot, $normState . '\\')) {
            throw new RuntimeException("Product root cannot reside inside the private state base.");
        }

        return [$canonicalRoot, $canonicalStateBase];
    }

    /**
     * Check that the product root physically matches the exact signed source inventory.
     *
     * @param array<string, mixed> $descriptor
     */
    private function assertRootExactSource(string $canonicalRoot, array $descriptor): void
    {
        try {
            $actualInventory = $this->manifests->buildInventory($canonicalRoot, $descriptor['from_native_version']);
            if ($actualInventory['version'] !== $descriptor['from_version']) {
                throw new RuntimeException("Source root version mismatch: expected {$descriptor['from_version']}, found {$actualInventory['version']}");
            }
            $actualDigest = ShellDeltaManifestService::canonicalInventoryDigest($actualInventory);
            if (!hash_equals($descriptor['source_inventory_sha256'], $actualDigest)) {
                throw new RuntimeException("Source root inventory digest mismatch: source files have drifted or do not match signed inventory.");
            }
        } catch (Throwable $e) {
            throw new RuntimeException("Source root inventory verification failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Derive exact expected payload set derived ONLY from signed source_inventory.files.
     *
     * @param array<string, mixed> $descriptor
     * @return array<string, array{size: int, sha256: string}>
     */
    private function deriveSelectedPayloadMap(array $descriptor): array
    {
        $sourceFiles = $descriptor['source_inventory']['files'] ?? null;
        if (!is_array($sourceFiles)) {
            throw new RuntimeException("Signed manifest descriptor missing valid source_inventory.files map.");
        }

        // Require php.exe, backend.phar, and update_public_key.pem in signed source set
        if (!isset($sourceFiles[self::REQUIRED_PHP_EXE])) {
            throw new RuntimeException("Signed source inventory missing required payload file: " . self::REQUIRED_PHP_EXE);
        }
        if (!isset($sourceFiles[self::REQUIRED_BACKEND_PHAR])) {
            throw new RuntimeException("Signed source inventory missing required payload file: " . self::REQUIRED_BACKEND_PHAR);
        }
        if (!isset($sourceFiles[self::REQUIRED_UPDATE_KEY])) {
            throw new RuntimeException("Signed source inventory missing required payload file: " . self::REQUIRED_UPDATE_KEY);
        }

        $selected = [];
        $totalBytes = 0;

        foreach ($sourceFiles as $relPath => $fileMeta) {
            if (!is_string($relPath) || !is_array($fileMeta)) {
                throw new RuntimeException("Malformed file entry in source inventory.");
            }

            $isSelected = false;
            if ($relPath === self::REQUIRED_BACKEND_PHAR || $relPath === self::REQUIRED_UPDATE_KEY) {
                $isSelected = true;
            } elseif ($relPath === self::OPTIONAL_CACERT) {
                $isSelected = true;
            } elseif (str_starts_with($relPath, self::PHP_PREFIX)) {
                $isSelected = true;
            }

            if (!$isSelected) {
                continue;
            }

            // Bounded size and sha256 check
            $size = $fileMeta['size'] ?? null;
            $sha256 = $fileMeta['sha256'] ?? null;
            if (!is_int($size) || $size < 0 || $size > ShellDeltaManifestService::MAX_SINGLE_FILE_SIZE) {
                throw new RuntimeException("Selected file size invalid or exceeds 250 MiB limit: {$relPath}");
            }
            if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
                throw new RuntimeException("Selected file SHA-256 digest invalid: {$relPath}");
            }

            $totalBytes += $size;
            $selected[$relPath] = [
                'size' => $size,
                'sha256' => $sha256,
            ];
        }

        if (count($selected) > ShellDeltaManifestService::MAX_FILES) {
            throw new RuntimeException("Selected payload file count exceeds limit (" . ShellDeltaManifestService::MAX_FILES . ").");
        }
        if ($totalBytes > ShellDeltaManifestService::MAX_UNCOMPRESSED_SIZE) {
            throw new RuntimeException("Selected payload total size exceeds limit (" . ShellDeltaManifestService::MAX_UNCOMPRESSED_SIZE . " bytes).");
        }

        // Validate complete path set in O(N*depth) avoiding O(N^2) loops:
        // checks syntax, case collisions, ancestor collisions, allowlist and protection policy
        $pathSetCheck = ProductInventoryService::validatePathSet(array_keys($selected));
        if (!$pathSetCheck['ok']) {
            throw new RuntimeException("Selected payload path set validation failed: " . ($pathSetCheck['reason_code'] ?? 'invalid'));
        }

        ksort($selected, SORT_STRING);
        return $selected;
    }

    /**
     * Walk down relative path within canonical root segment by segment.
     */
    private function readonlyProductWalk(string $canonicalRoot, string $relPath): string
    {
        $syntax = ProductInventoryService::validatePathSyntax($relPath);
        if (!$syntax['ok']) {
            throw new RuntimeException("Invalid path syntax: {$relPath}");
        }

        $segments = explode('/', $relPath);
        $current = $canonicalRoot;

        foreach ($segments as $seg) {
            $next = $current . DIRECTORY_SEPARATOR . $seg;
            clearstatcache(true, $next);

            if (!file_exists($next) && !is_link($next)) {
                throw new RuntimeException("Source path segment does not exist: {$next}");
            }

            ProductInventoryService::assertNoReparse($next);
            $canonicalNext = ProductInventoryService::resolveAbsolutePath($next, true);
            ProductInventoryService::assertNoReparse($canonicalNext);

            $normRoot = rtrim(strtolower(str_replace('/', '\\', $canonicalRoot)), '\\');
            $normNext = rtrim(strtolower(str_replace('/', '\\', $canonicalNext)), '\\');
            if ($normNext !== $normRoot && !str_starts_with($normNext, $normRoot . '\\')) {
                throw new RuntimeException("Source path escapes product root boundary: {$relPath}");
            }

            $current = $canonicalNext;
        }

        if (!is_file($current) || is_link($current)) {
            throw new RuntimeException("Source path is not a regular file: {$relPath}");
        }

        return $current;
    }

    /**
     * Ensure a single directory component exists under a canonical parent.
     * Guards raw parent no-reparse BEFORE checking or creating directory.
     */
    private function ensureDirectoryComponent(string $canonicalBase, string $component): string
    {
        if ($component === '' || $component === '.' || $component === '..' ||
            str_contains($component, '/') || str_contains($component, '\\') ||
            str_contains($component, ':')) {
            throw new RuntimeException("Invalid directory component: {$component}");
        }

        ProductInventoryService::assertNoReparse($canonicalBase);
        $entries = scandir($canonicalBase);
        if ($entries === false) {
            throw new RuntimeException("Failed to scan directory: {$canonicalBase}");
        }

        $target = $canonicalBase . DIRECTORY_SEPARATOR . $component;

        if (in_array($component, $entries, true)) {
            ProductInventoryService::assertNoReparse($target);
            $canonical = ProductInventoryService::resolveAbsolutePath($target, true);
            ProductInventoryService::assertNoReparse($canonical);
            if (!is_dir($canonical) || is_link($canonical)) {
                throw new RuntimeException("Path component is not a regular directory: {$target}");
            }
            return $canonical;
        }

        // Fresh raw parent guard immediately before mkdir
        ProductInventoryService::assertNoReparse($canonicalBase);
        if (!@mkdir($target, 0700, false) && !is_dir($target)) {
            throw new RuntimeException("Failed to create directory component: {$target}");
        }

        ProductInventoryService::assertNoReparse($target);
        $canonical = ProductInventoryService::resolveAbsolutePath($target, true);
        ProductInventoryService::assertNoReparse($canonical);
        if (!is_dir($canonical) || is_link($canonical)) {
            throw new RuntimeException("Created directory failed validation: {$target}");
        }

        return $canonical;
    }

    /**
     * Ensure a relative directory tree exists component by component.
     */
    private function ensureRelativeDirectoryTree(string $canonicalBase, string $relDir): string
    {
        $normalized = str_replace('\\', '/', trim($relDir, '/'));
        if ($normalized === '' || $normalized === '.') {
            return $canonicalBase;
        }

        $segments = explode('/', $normalized);
        $current = $canonicalBase;
        foreach ($segments as $seg) {
            $current = $this->ensureDirectoryComponent($current, $seg);
        }
        return $current;
    }

    /**
     * Copy a source file to destination using exclusive creation, chunked streaming,
     * pre-write overflow detection, SHA-256 digest verification, and fsync.
     * All handles closed in finally with verified close status.
     *
     * @param array{size: int, sha256: string} $expectedMeta
     */
    private function copyFileExclusive(string $srcPath, string $destPath, array $expectedMeta, string $relPath): void
    {
        $destParentDir = dirname($destPath);
        ProductInventoryService::assertNoReparse($destParentDir);

        clearstatcache(true, $destPath);
        if (file_exists($destPath) || is_link($destPath)) {
            throw new RuntimeException("Destination payload file already exists: {$destPath}");
        }

        $srcFp = @fopen($srcPath, 'rb');
        if ($srcFp === false) {
            throw new RuntimeException("Failed to open source file for reading: {$relPath}");
        }

        $destFp = null;
        $bytesCopied = 0;
        $hashCtx = hash_init('sha256');
        $expectedSize = $expectedMeta['size'];
        $primaryException = null;

        try {
            $destFp = @fopen($destPath, 'xb');
            if ($destFp === false) {
                throw new RuntimeException("Failed to open destination file for exclusive creation: {$destPath}");
            }

            while (!feof($srcFp)) {
                $remaining = $expectedSize - $bytesCopied;
                // Bound source READ to remaining expected + 1 (max 64 KiB), detect extra BEFORE write
                $readLen = max(1, min(65536, $remaining + 1));
                $chunk = fread($srcFp, $readLen);
                if ($chunk === false) {
                    throw new RuntimeException("Failed to read from source file during copy: {$relPath}");
                }
                if ($chunk === '') {
                    // Handle fread '' without feof as error not EOF success
                    if (!feof($srcFp)) {
                        throw new RuntimeException("Source file fread returned empty string before EOF: {$relPath}");
                    }
                    break;
                }

                $chunkLen = strlen($chunk);
                // Detect overflow BEFORE writing overflow bytes
                if ($bytesCopied + $chunkLen > $expectedSize) {
                    throw new RuntimeException("Source file exceeded expected size during copy: {$relPath}");
                }

                hash_update($hashCtx, $chunk);

                $written = 0;
                while ($written < $chunkLen) {
                    $n = fwrite($destFp, substr($chunk, $written));
                    if ($n === false || $n <= 0) {
                        throw new RuntimeException("Failed to write to destination payload file: {$destPath}");
                    }
                    $written += $n;
                }

                $bytesCopied += $chunkLen;
            }

            // Check for trailing bytes beyond expected EOF; raw close/fread(false) must not be accepted EOF evidence
            $extraByte = fread($srcFp, 1);
            if ($extraByte === false) {
                throw new RuntimeException("Failed to verify EOF on source file (fread returned false): {$relPath}");
            }
            if ($extraByte !== '') {
                throw new RuntimeException("Source file exceeded expected size (trailing bytes): {$relPath}");
            }

            if ($bytesCopied !== $expectedSize) {
                throw new RuntimeException("Copied file size does not match signed source metadata: {$relPath} (copied {$bytesCopied}, expected {$expectedSize})");
            }

            if (!fflush($destFp)) {
                throw new RuntimeException("fflush failed on destination payload file: {$destPath}");
            }
            if (!fsync($destFp)) {
                throw new RuntimeException("fsync failed on destination payload file: {$destPath}");
            }
        } catch (Throwable $e) {
            $primaryException = $e;
            throw $e;
        } finally {
            $closeErrors = [];
            // Use is_resource for finally handles: all open handles must get close attempt even when another close fails
            if (is_resource($destFp)) {
                if (!@fclose($destFp)) {
                    $closeErrors[] = "fclose failed on destination payload file: {$destPath}";
                }
            }
            if (is_resource($srcFp)) {
                if (!@fclose($srcFp)) {
                    $closeErrors[] = "fclose failed on source file: {$relPath}";
                }
            }

            if (!empty($closeErrors)) {
                $closeSummary = implode('; ', $closeErrors);
                if ($primaryException !== null) {
                    // Preserve primary exception and include close issues as context without masking it
                    throw new RuntimeException($primaryException->getMessage() . " (Additional close errors: {$closeSummary})", 0, $primaryException);
                }
                // If no primary and close fails => specific close diagnostic/no completion
                throw new RuntimeException("Resource cleanup failure during file copy: {$closeSummary}");
            }
        }

        // Post-close verification
        ProductInventoryService::assertNoReparse($destPath);
        clearstatcache(true, $destPath);
        if (!is_file($destPath) || is_link($destPath)) {
            throw new RuntimeException("Destination payload file missing or non-regular after close: {$relPath}");
        }
        if (filesize($destPath) !== $expectedSize) {
            throw new RuntimeException("Destination payload file size mismatch after close: {$relPath}");
        }
        $actualSha256 = hash_final($hashCtx);
        if (!hash_equals($expectedMeta['sha256'], $actualSha256)) {
            throw new RuntimeException("Copied file digest does not match signed source metadata: {$relPath}");
        }
        if (hash_file('sha256', $destPath) !== $expectedMeta['sha256']) {
            throw new RuntimeException("Destination file hash check failed after close: {$relPath}");
        }
    }

    /**
     * Write raw file content exclusively ('xb') and verify.
     * Guards raw parent no-reparse before open, checks all I/O return values,
     * closes in finally, and validates post-close size and actual hash == input hash.
     */
    private function writeExclusiveRawFile(string $destPath, string $content): void
    {
        $parentDir = dirname($destPath);
        ProductInventoryService::assertNoReparse($parentDir);

        clearstatcache(true, $destPath);
        if (file_exists($destPath) || is_link($destPath)) {
            throw new RuntimeException("File already exists: {$destPath}");
        }

        $fp = @fopen($destPath, 'xb');
        if ($fp === false) {
            throw new RuntimeException("Failed to open file for exclusive creation: {$destPath}");
        }

        $len = strlen($content);
        $written = 0;
        $primaryException = null;

        try {
            while ($written < $len) {
                $n = fwrite($fp, substr($content, $written));
                if ($n === false || $n <= 0) {
                    throw new RuntimeException("Failed to write to file: {$destPath}");
                }
                $written += $n;
            }

            if (!fflush($fp)) {
                throw new RuntimeException("fflush failed on {$destPath}");
            }
            if (!fsync($fp)) {
                throw new RuntimeException("fsync failed on {$destPath}");
            }
        } catch (Throwable $e) {
            $primaryException = $e;
            throw $e;
        } finally {
            $closeError = null;
            if (is_resource($fp)) {
                if (!@fclose($fp)) {
                    $closeError = "fclose failed on {$destPath}";
                }
            }

            if ($closeError !== null) {
                if ($primaryException !== null) {
                    throw new RuntimeException($primaryException->getMessage() . " (Additional close error: {$closeError})", 0, $primaryException);
                }
                throw new RuntimeException($closeError);
            }
        }

        clearstatcache(true, $destPath);
        ProductInventoryService::assertNoReparse($destPath);
        if (!is_file($destPath) || is_link($destPath) || filesize($destPath) !== $len) {
            throw new RuntimeException("Written file failed size or regular verification: {$destPath}");
        }
        if (hash_file('sha256', $destPath) !== hash('sha256', $content)) {
            throw new RuntimeException("Written file actual hash mismatch with input content: {$destPath}");
        }
    }

    /**
     * Write JSON metadata file exclusively ('xb') bounded to MAX_METADATA_SIZE.
     *
     * @param array<string, mixed> $data
     */
    private function writeExclusiveJsonFile(string $destPath, array $data, string $purpose): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_METADATA_SIZE) {
            throw new RuntimeException("{$purpose} size exceeds allowed limit of " . self::MAX_METADATA_SIZE . " bytes.");
        }
        $this->writeExclusiveRawFile($destPath, $json);
    }

    /**
     * Read a file with bounded size, exact EOF proof, and checked close.
     * Guards raw path and parent no-reparse before open.
     */
    private function readBoundedFile(string $filePath, int $maxBytes): string
    {
        $parentDir = dirname($filePath);
        ProductInventoryService::assertNoReparse($parentDir);
        ProductInventoryService::assertNoReparse($filePath);

        clearstatcache(true, $filePath);
        if (!is_file($filePath) || is_link($filePath)) {
            throw new RuntimeException("Path is not a regular file: {$filePath}");
        }

        $fp = @fopen($filePath, 'rb');
        if ($fp === false) {
            throw new RuntimeException("Failed to open file for reading: {$filePath}");
        }

        $buffer = '';
        $primaryException = null;

        try {
            while (!feof($fp)) {
                $chunk = fread($fp, 8192);
                if ($chunk === false) {
                    throw new RuntimeException("fread failed while reading: {$filePath}");
                }
                if ($chunk === '') {
                    if (!feof($fp)) {
                        throw new RuntimeException("fread returned empty string before EOF: {$filePath}");
                    }
                    break;
                }
                $buffer .= $chunk;
                if (strlen($buffer) > $maxBytes) {
                    throw new RuntimeException("File size exceeds allowed limit of {$maxBytes} bytes: {$filePath}");
                }
            }

            // Prove EOF
            $extra = fread($fp, 1);
            if ($extra === false) {
                throw new RuntimeException("fread failed when verifying EOF: {$filePath}");
            }
            if ($extra !== '') {
                throw new RuntimeException("File size exceeds allowed limit (extra trailing bytes): {$filePath}");
            }
        } catch (Throwable $e) {
            $primaryException = $e;
            throw $e;
        } finally {
            $closeError = null;
            if (is_resource($fp)) {
                if (!@fclose($fp)) {
                    $closeError = "fclose failed while reading: {$filePath}";
                }
            }

            if ($closeError !== null) {
                if ($primaryException !== null) {
                    throw new RuntimeException($primaryException->getMessage() . " (Additional close error: {$closeError})", 0, $primaryException);
                }
                throw new RuntimeException($closeError);
            }
        }

        return $buffer;
    }

    /**
     * Verify that the physical snapshot directory tree matches the EXACT closed set of
     * expected files and directories. Any foreign file, foreign directory, missing file,
     * or reparse point causes immediate closed failure BEFORE reading unknown payload.
     *
     * @param array<string, array{size: int, sha256: string}> $derivedSelectedMap
     */
    private function verifyClosedSnapshotTree(string $canonicalSnapshotRoot, array $derivedSelectedMap, bool $allowCompletion = true): void
    {
        // 1. Build exact expected files map (case-insensitive keys, canonical relative paths)
        $expectedFiles = [
            strtolower(self::OWNER_FILENAME) => self::OWNER_FILENAME,
            strtolower(self::SEALED_DIR . '/' . ShellDeltaManifestService::MANIFEST_ASSET) => self::SEALED_DIR . '/' . ShellDeltaManifestService::MANIFEST_ASSET,
            strtolower(self::SEALED_DIR . '/' . ShellDeltaManifestService::SIGNATURE_ASSET) => self::SEALED_DIR . '/' . ShellDeltaManifestService::SIGNATURE_ASSET,
        ];

        if ($allowCompletion) {
            $expectedFiles[strtolower(self::COMPLETION_FILENAME)] = self::COMPLETION_FILENAME;
        }

        // 2. Build exact expected directories map
        $expectedDirs = [
            strtolower(self::SEALED_DIR) => self::SEALED_DIR,
            strtolower(self::PAYLOAD_DIR) => self::PAYLOAD_DIR,
        ];

        foreach (array_keys($derivedSelectedMap) as $relPath) {
            $payloadRel = self::PAYLOAD_DIR . '/' . $relPath;
            $expectedFiles[strtolower($payloadRel)] = $payloadRel;

            // Register all parent directory segments under payload
            $dirPart = dirname($payloadRel);
            while ($dirPart !== '.' && $dirPart !== '' && $dirPart !== self::PAYLOAD_DIR) {
                $dirNorm = str_replace('\\', '/', $dirPart);
                $expectedDirs[strtolower($dirNorm)] = $dirNorm;
                $dirPart = dirname($dirPart);
            }
        }

        // 3. Scan physical tree component by component using BFS queue
        $queue = [$canonicalSnapshotRoot];
        $visitedFiles = [];
        $visitedDirs = [];

        $rootLen = strlen($canonicalSnapshotRoot);

        while (!empty($queue)) {
            $currentDir = array_shift($queue);
            ProductInventoryService::assertNoReparse($currentDir);

            $entries = scandir($currentDir);
            if ($entries === false) {
                throw new RuntimeException("Failed to read snapshot directory: {$currentDir}");
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $fullEntryPath = $currentDir . DIRECTORY_SEPARATOR . $entry;
                clearstatcache(true, $fullEntryPath);

                if (is_link($fullEntryPath)) {
                    throw new RuntimeException("Reparse point or symlink detected inside snapshot: {$fullEntryPath}");
                }
                ProductInventoryService::assertNoReparse($fullEntryPath);

                $relFromRoot = ltrim(str_replace('\\', '/', substr($fullEntryPath, $rootLen)), '/');
                $normRel = strtolower($relFromRoot);

                if (is_dir($fullEntryPath)) {
                    if (!isset($expectedDirs[$normRel])) {
                        throw new RuntimeException("Unexpected foreign directory found in snapshot tree: {$relFromRoot}");
                    }
                    if (isset($visitedDirs[$normRel])) {
                        throw new RuntimeException("Duplicate directory visit in snapshot tree: {$relFromRoot}");
                    }
                    $visitedDirs[$normRel] = true;
                    $queue[] = $fullEntryPath;
                } elseif (is_file($fullEntryPath)) {
                    if (!isset($expectedFiles[$normRel])) {
                        throw new RuntimeException("Unexpected foreign file found in snapshot tree: {$relFromRoot}");
                    }
                    if (isset($visitedFiles[$normRel])) {
                        throw new RuntimeException("Duplicate file visit in snapshot tree: {$relFromRoot}");
                    }
                    $visitedFiles[$normRel] = true;
                } else {
                    throw new RuntimeException("Non-regular item found in snapshot tree: {$relFromRoot}");
                }
            }
        }

        // 4. Verify no missing expected files
        foreach ($expectedFiles as $normKey => $origRel) {
            if (!isset($visitedFiles[$normKey])) {
                throw new RuntimeException("Missing expected file in snapshot tree: {$origRel}");
            }
        }

        // 5. Verify no missing expected directories
        foreach ($expectedDirs as $normKey => $origRel) {
            if (!isset($visitedDirs[$normKey])) {
                throw new RuntimeException("Missing expected directory in snapshot tree: {$origRel}");
            }
        }
    }

    /**
     * Trigger test checkpoint hook if running under GitHub Actions.
     *
     * @param array<string, mixed> $context
     */
    private function triggerCheckpoint(string $checkpoint, array $context = []): void
    {
        if ($this->checkpointCallback !== null && getenv('GITHUB_ACTIONS') === 'true') {
            ($this->checkpointCallback)($checkpoint, $context);
        }
    }
}
