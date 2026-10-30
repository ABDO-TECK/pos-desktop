<?php
declare(strict_types=1);

namespace App\Services;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * ShellDeltaFileTransactionService
 *
 * INTERNAL signed shell-delta per-file transaction core with process-crash
 * rollback and recovery.
 *
 * CRITICAL ARCHITECTURAL CONSTRAINTS AND SAFETY BOUNDARIES:
 * - UNSAFE TO INTEGRATE WITHOUT CALLER-ENFORCED WINDOWS PROCESS QUIESCENCE,
 *   LAUNCH INTERLOCK, DATABASE COLD BACKUP, AND POST-RECOVERY HEALTH VERIFICATION.
 * - This core is strictly an internal library service. It is NOT wired to the product
 *   and MUST NOT advertise installed-update capability.
 * - Does NOT implement process quiescence, launch interlock, DB cold backups, or
 *   health finalization. Caller is strictly required to enforce those prerequisites.
 * - No commit or health finalization API exists in this core; healthy native commit
 *   is deferred to a future step.
 * - No database or engine upgrade functionality: any modification or deletion of
 *   files under resources/portable/mysql/** or any schema SQL scripts is strictly rejected.
 * - Purpose is restricted to 'regular'; 'bootstrap_completion' fails closed because
 *   nonce/receipt gates are not yet implemented.
 * - PROCESS-CRASH recovery only: PHP on Windows cannot guarantee atomic directory
 *   metadata durability under hardware power loss.
 * - Recovery performs rollback ONLY; apply-replay after crash is not supported.
 * - Recovery workspaces and backup bytes are retained for inspection and recovery audit;
 *   no automatic recursive cleanup API is provided.
 * - Empty directories created during an update are safely retained after rollback (they
 *   are empty and do not contain user data or affect application integrity; created_directories
 *   in the journal is diagnostic only and never pruned to avoid accidental deletion of user directories).
 */
final class ShellDeltaFileTransactionService
{
    public const PHASE_PREPARED = 'prepared';
    public const PHASE_APPLYING = 'applying';
    public const PHASE_FILES_APPLIED = 'files_applied_awaiting_health';
    public const PHASE_ROLLING_BACK = 'rolling_back';
    public const PHASE_ROLLED_BACK = 'rolled_back';

    public const OWNER_MARKER_FILENAME = '.pos_transaction_owner';
    public const JOURNAL_FILENAME = 'journal.json';

    private const VERSION_JSON_PATH = 'resources/app.asar.unpacked/version.json';

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
     * Prepare a new file transaction: validate signed package, stage files,
     * back up source files, and establish persistent lease. Product files are
     * left completely unmodified.
     *
     * @param string $root Absolute product root directory
     * @param string $privateStateBase Absolute private state base directory
     * @param string $rawManifest Raw UTF-8 manifest JSON
     * @param string $signature Base64 RSA PKCS#1 SHA-256 signature
     * @param string $zipPath Path to package ZIP
     * @param array<string, mixed> $installedContext Required source context
     * @return array{operation_id: string, workspace: string, phase: string}
     */
    public function prepare(
        string $root,
        string $privateStateBase,
        string $rawManifest,
        string $signature,
        string $zipPath,
        array $installedContext
    ): array {
        [$canonicalRoot, $canonicalStateBase] = $this->validateCanonicalDirectories($root, $privateStateBase);
        $this->validateInstalledContext($installedContext);

        if (strlen($rawManifest) === 0 || strlen($rawManifest) > ShellDeltaManifestService::MAX_MANIFEST_SIZE) {
            throw new RuntimeException("Manifest size exceeds allowed limits.");
        }
        if (strlen($signature) === 0 || strlen($signature) > ShellDeltaManifestService::MAX_SIGNATURE_SIZE) {
            throw new RuntimeException("Signature size exceeds allowed limits.");
        }

        // Pre-flight authenticate raw manifest and signature before heavy allocation or ZIP copy
        $preCheck = $this->manifests->verify($rawManifest, $signature, $installedContext);
        if (!$preCheck['ok']) {
            throw new RuntimeException("Manifest verification failed pre-flight: " . ($preCheck['reason_code'] ?? 'verification_failed'));
        }

        $rootDigest = $this->getRootDigest($canonicalRoot);
        $lockFp = $this->acquireRootLock($canonicalStateBase, $rootDigest);

        try {
            // Check active operation marker: ANY entry exists => generic busy WITHOUT reading foreign payload
            $activeMarkerFile = $this->getActiveMarkerPath($canonicalStateBase, $rootDigest);
            clearstatcache(true, $activeMarkerFile);
            if (file_exists($activeMarkerFile) || is_link($activeMarkerFile)) {
                throw new RuntimeException("Product root has an active or unfinished operation. Recovery or rollback is required before starting a new operation.");
            }

            // Allocate new nonce workspace
            $operationId = 'tx_' . bin2hex(random_bytes(16));
            $workspace = $this->allocateWorkspace($canonicalStateBase, $operationId, $canonicalRoot);

            // Copy bounded manifest, signature, and ZIP into workspace/sealed
            $sealedManifestPath = $workspace . DIRECTORY_SEPARATOR . 'sealed' . DIRECTORY_SEPARATOR . ShellDeltaManifestService::MANIFEST_ASSET;
            $sealedSigPath = $workspace . DIRECTORY_SEPARATOR . 'sealed' . DIRECTORY_SEPARATOR . ShellDeltaManifestService::SIGNATURE_ASSET;
            $sealedZipPath = $workspace . DIRECTORY_SEPARATOR . 'sealed' . DIRECTORY_SEPARATOR . ShellDeltaManifestService::PACKAGE_ASSET;

            $this->writeAtomicFile($sealedManifestPath, $rawManifest, 'journal');
            $this->writeAtomicFile($sealedSigPath, $signature, 'journal');
            $this->copyPackageZipBounded($zipPath, $sealedZipPath);

            // Cryptographically verify sealed copies
            $contextWithRoot = $installedContext;
            $contextWithRoot['root'] = $canonicalRoot;
            [$sealedRaw, $sealedSig, $descriptor] = $this->reverifySealedPackage($workspace, $contextWithRoot);

            // Enforce library policy constraints
            $this->enforceCorePolicyConstraints($descriptor);

            // Verify source root fresh against descriptor source inventory BEFORE staging/backup
            $srcCheck = $this->manifests->verifySource($canonicalRoot, $descriptor);
            if (!$srcCheck['ok']) {
                throw new RuntimeException("Source root verification failed during prepare: " . ($srcCheck['reason_code'] ?? 'source_mismatch'));
            }

            // Fresh actual full inventory check before staging/backup
            if (!$this->isRootExactSource($canonicalRoot, $descriptor)) {
                throw new RuntimeException("Source root inventory verification failed during prepare: source_inventory_mismatch");
            }

            // Stage all changed files from ZIP into workspace/staged
            $stagedFiles = $this->stageChangedFiles($sealedZipPath, $descriptor, $workspace);

            // Back up all existing modified or deleted files from root into workspace/backups
            $backupFiles = $this->backupSourceFiles($canonicalRoot, $descriptor, $workspace);

            // Write initial journal with phase 'prepared' (clean exact schema)
            $journal = [
                'operation_id' => $operationId,
                'phase' => self::PHASE_PREPARED,
                'root' => $canonicalRoot,
                'descriptor_digest' => hash('sha256', $sealedRaw),
                'created_at' => time(),
                'completed_paths' => [],
                'created_directories' => [],
            ];
            $this->writeJournal($workspace, $journal);

            // Hook before creating active marker (enables testing foreign marker replacement)
            $this->triggerCheckpoint('before_active_marker_create', [
                'operation_id' => $operationId,
                'active_marker_path' => $activeMarkerFile,
            ]);

            // Recheck right before exclusive creation
            clearstatcache(true, $activeMarkerFile);
            if (file_exists($activeMarkerFile) || is_link($activeMarkerFile)) {
                throw new RuntimeException("Product root has an active operation marker created concurrently. Aborting prepare.");
            }

            // Write persistent active operation marker binding root and operation_id EXCLUSIVELY ('xb')
            $activeMarkerData = [
                'operation_id' => $operationId,
                'root' => $canonicalRoot,
                'workspace' => $workspace,
                'created_at' => time(),
                'pid' => getmypid(),
            ];
            $markerJson = json_encode($activeMarkerData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->writeExclusiveFile($activeMarkerFile, $markerJson, 'marker');

            $this->triggerCheckpoint('prepared', ['operation_id' => $operationId]);

            return [
                'operation_id' => $operationId,
                'workspace' => $workspace,
                'phase' => self::PHASE_PREPARED,
            ];
        } finally {
            $this->releaseRootLock($lockFp);
        }
    }

    /**
     * Apply a prepared transaction: verify source freshness, atomically publish
     * files one by one with version.json published last, and verify target inventory.
     * Leaves source backups and persistent marker for health gate.
     *
     * @param string $root Absolute product root directory
     * @param string $privateStateBase Absolute private state base directory
     * @param string $operationId Operation identifier
     * @param array<string, mixed> $installedContext Required source context
     * @return array{operation_id: string, phase: string, version: string, target_inventory_sha256: string}
     */
    public function apply(
        string $root,
        string $privateStateBase,
        string $operationId,
        array $installedContext
    ): array {
        if (!is_string($operationId) || preg_match('/^tx_[a-f0-9]{32}$/D', $operationId) !== 1) {
            throw new RuntimeException("Invalid operation ID format: {$operationId}");
        }

        [$canonicalRoot, $canonicalStateBase] = $this->validateCanonicalDirectories($root, $privateStateBase);
        $this->validateInstalledContext($installedContext);

        $rootDigest = $this->getRootDigest($canonicalRoot);
        $lockFp = $this->acquireRootLock($canonicalStateBase, $rootDigest);

        try {
            $expectedWorkspace = $canonicalStateBase . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . $operationId;
            $activeMarkerFile = $this->getActiveMarkerPath($canonicalStateBase, $rootDigest);

            $marker = $this->readAndValidateActiveMarker($activeMarkerFile, $operationId, $canonicalRoot, $expectedWorkspace);
            $workspace = $expectedWorkspace;
            $this->assertWorkspaceOwnership($workspace, $operationId, $canonicalRoot, $canonicalStateBase);

            // Reverify sealed package descriptor and signature BEFORE any phase decisions
            $contextWithRoot = $installedContext;
            $contextWithRoot['root'] = $canonicalRoot;
            [$sealedRaw, $sealedSig, $descriptor] = $this->reverifySealedPackage($workspace, $contextWithRoot);

            $journal = $this->readAndValidateJournal($workspace, $operationId, $canonicalRoot);

            if (hash('sha256', $sealedRaw) !== $journal['descriptor_digest']) {
                throw new RuntimeException("Sealed descriptor digest does not match journal digest.");
            }

            // Nonprepared apply ALWAYS reject / recovery required, no replay
            if ($journal['phase'] !== self::PHASE_PREPARED) {
                throw new RuntimeException("Cannot apply operation in phase: '{$journal['phase']}'. Apply-replay after crash is not supported; recover/rollback is required.");
            }

            $this->enforceCorePolicyConstraints($descriptor);

            // Reverify physical integrity of all staged files and backup files
            $this->assertStagedAndBackupFilesIntegrity($workspace, $descriptor, $journal);

            // Pre-flight fresh source drift check right before first mutation
            $srcCheck = $this->manifests->verifySource($canonicalRoot, $descriptor);
            if (!$srcCheck['ok']) {
                throw new RuntimeException("Source root drift detected before first mutation: " . ($srcCheck['reason_code'] ?? 'source_drift'));
            }

            // Fresh actual full inventory check before any product mutation or journal applying transition
            if (!$this->isRootExactSource($canonicalRoot, $descriptor)) {
                throw new RuntimeException("Source root inventory drift detected before first mutation: source_inventory_mismatch");
            }

            // Pre-flight check for file <-> directory shape transitions
            $this->assertNoShapeTransitions($canonicalRoot, $descriptor);

            // Update journal phase to 'applying'
            $journal['phase'] = self::PHASE_APPLYING;
            $this->writeJournal($workspace, $journal);

            $this->triggerCheckpoint('sealed_backup', ['operation_id' => $operationId]);

            // Plan publication order: version.json MUST be published LAST
            $regularFiles = [];
            $versionEntry = null;
            foreach ($descriptor['files'] as $fEntry) {
                if ($fEntry['path'] === self::VERSION_JSON_PATH) {
                    $versionEntry = $fEntry;
                } else {
                    $regularFiles[] = $fEntry;
                }
            }

            $tempDir = $workspace . DIRECTORY_SEPARATOR . 'temp';
            $stagedDir = $workspace . DIRECTORY_SEPARATOR . 'staged';
            $sourceFiles = $descriptor['source_inventory']['files'];

            // Publish regular files
            foreach ($regularFiles as $fEntry) {
                $relPath = $fEntry['path'];
                $stagedPath = $stagedDir . DIRECTORY_SEPARATOR . hash('sha256', $relPath) . '.staged';
                $sourceMeta = $sourceFiles[$relPath] ?? null;

                $this->publishFile(
                    $canonicalRoot,
                    $relPath,
                    $stagedPath,
                    $fEntry['sha256'],
                    $fEntry['size'],
                    $sourceMeta,
                    $tempDir,
                    $journal,
                    $workspace
                );
            }

            // Process deleted files
            foreach ($descriptor['deleted_files'] as $delPath) {
                $delSourceMeta = $sourceFiles[$delPath] ?? null;
                if ($delSourceMeta === null) {
                    throw new RuntimeException("Deleted file not in source inventory: {$delPath}");
                }
                $this->deleteProductFile($canonicalRoot, $delPath, $delSourceMeta, $journal, $workspace);
            }

            // Publish version.json LAST
            if ($versionEntry !== null) {
                $versionStagedPath = $stagedDir . DIRECTORY_SEPARATOR . hash('sha256', self::VERSION_JSON_PATH) . '.staged';
                $versionSourceMeta = $sourceFiles[self::VERSION_JSON_PATH] ?? null;
                $this->publishFile(
                    $canonicalRoot,
                    self::VERSION_JSON_PATH,
                    $versionStagedPath,
                    $versionEntry['sha256'],
                    $versionEntry['size'],
                    $versionSourceMeta,
                    $tempDir,
                    $journal,
                    $workspace
                );
            }

            // Post-apply full target physical inventory verification
            $actualInventory = $this->manifests->buildInventory($canonicalRoot, $descriptor['native_version']);
            if ($actualInventory['version'] !== $descriptor['version']) {
                throw new RuntimeException("Applied application version mismatch: expected '{$descriptor['version']}', got '{$actualInventory['version']}'.");
            }

            $actualDigest = ShellDeltaManifestService::canonicalInventoryDigest($actualInventory);
            if ($actualDigest !== $descriptor['target_inventory_sha256']) {
                throw new RuntimeException("Applied target inventory digest mismatch: expected '{$descriptor['target_inventory_sha256']}', got '{$actualDigest}'.");
            }

            $this->triggerCheckpoint('target_verified', ['operation_id' => $operationId]);

            // Finalize journal phase as files_applied_awaiting_health
            $journal['phase'] = self::PHASE_FILES_APPLIED;
            $journal['applied_version'] = $descriptor['version'];
            $journal['applied_target_digest'] = $descriptor['target_inventory_sha256'];
            $this->writeJournal($workspace, $journal);

            return [
                'operation_id' => $operationId,
                'phase' => self::PHASE_FILES_APPLIED,
                'version' => $descriptor['version'],
                'target_inventory_sha256' => $descriptor['target_inventory_sha256'],
            ];
        } finally {
            $this->releaseRootLock($lockFp);
        }
    }

    /**
     * Roll back an operation: restore modified/deleted source files from sealed
     * backups and remove added target files. version.json is restored LAST.
     * Idempotent across repeated executions and process crashes.
     *
     * @param string $root Absolute product root directory
     * @param string $privateStateBase Absolute private state base directory
     * @param string $operationId Operation identifier
     * @param array<string, mixed> $installedContext Required source context
     * @return array{operation_id: string, phase: string, version: string, source_inventory_sha256: string}
     */
    public function rollback(
        string $root,
        string $privateStateBase,
        string $operationId,
        array $installedContext
    ): array {
        return $this->executeRollbackOrRecovery($root, $privateStateBase, $operationId, $installedContext);
    }

    /**
     * Recover an operation after process crash: executes rollback ONLY.
     * Apply-replay after crash is strictly forbidden.
     *
     * @param string $root Absolute product root directory
     * @param string $privateStateBase Absolute private state base directory
     * @param string $operationId Operation identifier
     * @param array<string, mixed> $installedContext Required source context
     * @return array{operation_id: string, phase: string, version: string, source_inventory_sha256: string}
     */
    public function recover(
        string $root,
        string $privateStateBase,
        string $operationId,
        array $installedContext
    ): array {
        return $this->executeRollbackOrRecovery($root, $privateStateBase, $operationId, $installedContext);
    }

    /**
     * Shared rollback and recovery execution core.
     *
     * @param array<string, mixed> $installedContext
     * @return array{operation_id: string, phase: string, version: string, source_inventory_sha256: string}
     */
    private function executeRollbackOrRecovery(
        string $root,
        string $privateStateBase,
        string $operationId,
        array $installedContext
    ): array {
        if (!is_string($operationId) || preg_match('/^tx_[a-f0-9]{32}$/D', $operationId) !== 1) {
            throw new RuntimeException("Invalid operation ID format: {$operationId}");
        }

        [$canonicalRoot, $canonicalStateBase] = $this->validateCanonicalDirectories($root, $privateStateBase);
        $this->validateInstalledContext($installedContext);

        $rootDigest = $this->getRootDigest($canonicalRoot);
        $lockFp = $this->acquireRootLock($canonicalStateBase, $rootDigest);

        try {
            $expectedWorkspace = $canonicalStateBase . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . $operationId;
            $activeMarkerFile = $this->getActiveMarkerPath($canonicalStateBase, $rootDigest);

            clearstatcache(true, $activeMarkerFile);
            $hasActiveMarker = file_exists($activeMarkerFile) || is_link($activeMarkerFile);
            $marker = null;

            if ($hasActiveMarker) {
                // If active marker exists, it MUST belong to this operation!
                // Foreign marker is ALWAYS refused; snapshot captured for final release comparison.
                $marker = $this->readAndValidateActiveMarker($activeMarkerFile, $operationId, $canonicalRoot, $expectedWorkspace);
            } else {
                // Active marker is absent.
                // Workspace must exist for idempotent verification.
                if (!is_dir($expectedWorkspace)) {
                    throw new RuntimeException("Operation workspace not found: {$expectedWorkspace}. Active marker is absent and workspace does not exist.");
                }
            }

            $workspace = $expectedWorkspace;
            $this->assertWorkspaceOwnership($workspace, $operationId, $canonicalRoot, $canonicalStateBase);
            $journal = $this->readAndValidateJournal($workspace, $operationId, $canonicalRoot);

            // Reverify sealed manifest and signature without root in context.
            // Note: Package ZIP is NOT needed or validated for rollback/recovery!
            $contextWithoutRoot = $installedContext;
            unset($contextWithoutRoot['root']);
            [$sealedRaw, $sealedSig, $descriptor] = $this->reverifySealedManifest($workspace, $contextWithoutRoot);

            if (hash('sha256', $sealedRaw) !== $journal['descriptor_digest']) {
                throw new RuntimeException("Sealed descriptor digest does not match journal digest.");
            }

            $this->enforceCorePolicyConstraints($descriptor);

            // Check if root already physically satisfies full source inventory
            $alreadyExactSource = $this->isRootExactSource($canonicalRoot, $descriptor);

            if (!$hasActiveMarker) {
                // Case: Active marker is ABSENT.
                // ONLY READONLY full signed SOURCE physical proof permits idempotent success!
                // If root not EXACT source FAIL NO MUTATION; never old operation rollback later healthy root!
                if (!$alreadyExactSource) {
                    throw new RuntimeException("Active marker is absent and product root does not match signed source inventory. Refusing mutation on unleased root.");
                }

                return [
                    'operation_id' => $operationId,
                    'phase' => self::PHASE_ROLLED_BACK,
                    'version' => $descriptor['from_version'],
                    'source_inventory_sha256' => $descriptor['source_inventory_sha256'],
                ];
            }

            // Case: Own active marker is PRESENT.
            // FIRST verify full Source REGARDLESS journal phase.
            // If already exact source, safe journal rolled_back + release OWN marker, even unused backup damaged (cancellation).
            if ($alreadyExactSource) {
                $journal['phase'] = self::PHASE_ROLLED_BACK;
                $this->writeJournal($workspace, $journal);
                $this->releaseActiveMarker($activeMarkerFile, $operationId, $canonicalRoot, $workspace, $marker);
                return [
                    'operation_id' => $operationId,
                    'phase' => self::PHASE_ROLLED_BACK,
                    'version' => $descriptor['from_version'],
                    'source_inventory_sha256' => $descriptor['source_inventory_sha256'],
                ];
            }

            // Product root is not currently exact source: rollback is required.
            // Update journal phase to 'rolling_back'
            $journal['phase'] = self::PHASE_ROLLING_BACK;
            $this->writeJournal($workspace, $journal);

            // Derive affected paths from the SIGNED diff (never trust mutable journal alone)
            $sourceFiles = $descriptor['source_inventory']['files'];
            $targetFiles = $descriptor['target_inventory']['files'];

            $addedFiles = [];
            $modifiedFiles = [];
            $deletedFiles = [];

            foreach ($targetFiles as $p => $tEntry) {
                if (!isset($sourceFiles[$p])) {
                    $addedFiles[$p] = $tEntry;
                } elseif ($sourceFiles[$p]['sha256'] !== $tEntry['sha256'] || $sourceFiles[$p]['size'] !== $tEntry['size']) {
                    $modifiedFiles[$p] = [
                        'source' => $sourceFiles[$p],
                        'target' => $tEntry,
                    ];
                }
            }

            foreach ($descriptor['deleted_files'] as $delPath) {
                if (isset($sourceFiles[$delPath])) {
                    $deletedFiles[$delPath] = $sourceFiles[$delPath];
                }
            }

            $backupsDir = $workspace . DIRECTORY_SEPARATOR . 'backups';
            $tempDir = $workspace . DIRECTORY_SEPARATOR . 'temp';

            // Verify backup files exist for files that ACTUALLY need restore.
            // If a modified or deleted file currently matches source, its backup is not strictly needed.
            foreach ($modifiedFiles as $p => $meta) {
                $walk = $this->readonlyProductWalk($canonicalRoot, $p);
                $needsRestore = true;
                if ($walk['exists'] && !$walk['is_dir']) {
                    $curSize = filesize($walk['resolved_path']);
                    $curHash = hash_file('sha256', $walk['resolved_path']);
                    if ($curSize === $meta['source']['size'] && strtolower((string) $curHash) === strtolower($meta['source']['sha256'])) {
                        $needsRestore = false;
                    }
                }
                if ($needsRestore) {
                    $this->assertBackupFileValid($backupsDir, $p, $meta['source']);
                }
            }
            foreach ($deletedFiles as $p => $sourceMeta) {
                $walk = $this->readonlyProductWalk($canonicalRoot, $p);
                $needsRestore = true;
                if ($walk['exists'] && !$walk['is_dir']) {
                    $curSize = filesize($walk['resolved_path']);
                    $curHash = hash_file('sha256', $walk['resolved_path']);
                    if ($curSize === $sourceMeta['size'] && strtolower((string) $curHash) === strtolower($sourceMeta['sha256'])) {
                        $needsRestore = false;
                    }
                }
                if ($needsRestore) {
                    $this->assertBackupFileValid($backupsDir, $p, $sourceMeta);
                }
            }

            // 1. Remove added files: remove ONLY if current bytes match signed target.
            // If absent, accept. If unknown bytes, FAIL CLOSED and retain.
            foreach ($addedFiles as $p => $tEntry) {
                $this->rollbackAddedFile($canonicalRoot, $p, $tEntry);
            }

            // 2. Restore modified files (excluding version.json for now)
            $restoreVersionJson = null;
            foreach ($modifiedFiles as $p => $meta) {
                if ($p === self::VERSION_JSON_PATH) {
                    $restoreVersionJson = $meta['source'];
                    continue;
                }
                $this->rollbackModifiedOrDeletedFile(
                    $canonicalRoot,
                    $p,
                    $meta['source'],
                    $meta['target'],
                    $backupsDir,
                    $tempDir
                );
            }

            // 3. Restore deleted files
            foreach ($deletedFiles as $p => $sourceMeta) {
                if ($p === self::VERSION_JSON_PATH) {
                    $restoreVersionJson = $sourceMeta;
                    continue;
                }
                $this->rollbackModifiedOrDeletedFile(
                    $canonicalRoot,
                    $p,
                    $sourceMeta,
                    null,
                    $backupsDir,
                    $tempDir
                );
            }

            // (Directory pruning removed per Fix 2: retain empty dirs safe)

            // 4. Restore version.json LAST
            if ($restoreVersionJson !== null) {
                $versionTargetMeta = $targetFiles[self::VERSION_JSON_PATH] ?? null;
                $this->rollbackModifiedOrDeletedFile(
                    $canonicalRoot,
                    self::VERSION_JSON_PATH,
                    $restoreVersionJson,
                    $versionTargetMeta,
                    $backupsDir,
                    $tempDir
                );
            }

            // Physical verification of full source inventory
            $actualInventory = $this->manifests->buildInventory($canonicalRoot, $descriptor['from_native_version']);
            if ($actualInventory['version'] !== $descriptor['from_version']) {
                throw new RuntimeException("Rollback physical source version mismatch: expected '{$descriptor['from_version']}', got '{$actualInventory['version']}'.");
            }

            $actualDigest = ShellDeltaManifestService::canonicalInventoryDigest($actualInventory);
            if ($actualDigest !== $descriptor['source_inventory_sha256']) {
                throw new RuntimeException("Rollback physical source inventory digest mismatch: expected '{$descriptor['source_inventory_sha256']}', got '{$actualDigest}'.");
            }

            // Mark journal phase as rolled_back
            $journal['phase'] = self::PHASE_ROLLED_BACK;
            $this->writeJournal($workspace, $journal);

            // Release persistent active operation marker safely with verified snapshot comparison
            $this->releaseActiveMarker($activeMarkerFile, $operationId, $canonicalRoot, $workspace, $marker);

            return [
                'operation_id' => $operationId,
                'phase' => self::PHASE_ROLLED_BACK,
                'version' => $descriptor['from_version'],
                'source_inventory_sha256' => $descriptor['source_inventory_sha256'],
            ];
        } finally {
            $this->releaseRootLock($lockFp);
        }
    }

    /**
     * Roll back an added target file.
     * Removes the file ONLY if current bytes match signed target.
     *
     * @param array{sha256: string, size: int} $targetMeta
     */
    private function rollbackAddedFile(string $canonicalRoot, string $relPath, array $targetMeta): void
    {
        $walk = $this->readonlyProductWalk($canonicalRoot, $relPath);
        if (!$walk['exists']) {
            // Missing leaf or missing ancestor: accept absence!
            return;
        }

        if ($walk['is_dir']) {
            throw new RuntimeException("Rollback safety violation: expected file, found directory: {$relPath}");
        }

        $destPath = $walk['resolved_path'];
        clearstatcache(true, $destPath);
        $size = filesize($destPath);
        $hash = hash_file('sha256', $destPath);

        if ($size !== $targetMeta['size'] || strtolower((string) $hash) !== strtolower($targetMeta['sha256'])) {
            // Unknown current bytes: MUST NOT DELETE
            throw new RuntimeException("Rollback safety violation: file '{$relPath}' contains unknown current bytes that do not match signed target. Refusing to delete.");
        }

        $this->triggerCheckpoint('before_restore_file', ['path' => $relPath, 'action' => 'delete_added']);

        // Recheck fresh parent + leaf immediately after hook and before unlink
        $destDir = dirname($destPath);
        $canonicalDestDir = ProductInventoryService::resolveAbsolutePath($destDir, true);
        ProductInventoryService::assertNoReparse($canonicalDestDir);

        clearstatcache(true, $destPath);
        if (!file_exists($destPath) && !is_link($destPath)) {
            return;
        }
        ProductInventoryService::assertNoReparse($destPath);
        if (is_dir($destPath)) {
            throw new RuntimeException("Rollback safety violation: expected file, found directory: {$relPath}");
        }
        $freshSize = filesize($destPath);
        $freshHash = hash_file('sha256', $destPath);
        if ($freshSize !== $targetMeta['size'] || strtolower((string) $freshHash) !== strtolower($targetMeta['sha256'])) {
            throw new RuntimeException("Rollback safety violation: file '{$relPath}' altered during hook; refusing to delete altered entry.");
        }

        if (!@unlink($destPath)) {
            throw new RuntimeException("Failed to remove added target file during rollback: {$destPath}");
        }
        clearstatcache(true, $destPath);
        $this->triggerCheckpoint('after_restore_file', ['path' => $relPath, 'action' => 'delete_added']);
    }

    /**
     * Roll back a modified or deleted file.
     *
     * @param array{sha256: string, size: int} $sourceMeta
     * @param array{sha256: string, size: int}|null $targetMeta
     */
    private function rollbackModifiedOrDeletedFile(
        string $canonicalRoot,
        string $relPath,
        array $sourceMeta,
        ?array $targetMeta,
        string $backupsDir,
        string $tempDir
    ): void {
        $walk = $this->readonlyProductWalk($canonicalRoot, $relPath);

        if ($walk['exists']) {
            if ($walk['is_dir']) {
                throw new RuntimeException("Rollback safety violation: expected file, found directory: {$relPath}");
            }

            $destPath = $walk['resolved_path'];
            clearstatcache(true, $destPath);
            $curSize = filesize($destPath);
            $curHash = hash_file('sha256', $destPath);

            // If current already matches source, accept
            if ($curSize === $sourceMeta['size'] && strtolower((string) $curHash) === strtolower($sourceMeta['sha256'])) {
                return;
            }

            // If current matches target, restore permitted
            $matchesTarget = ($targetMeta !== null &&
                $curSize === $targetMeta['size'] &&
                strtolower((string) $curHash) === strtolower($targetMeta['sha256']));

            if (!$matchesTarget) {
                // Current bytes match neither source nor target: UNKNOWN current bytes
                throw new RuntimeException("Rollback safety violation: file '{$relPath}' contains unknown current bytes (matches neither source nor target). Refusing to overwrite.");
            }
        } else {
            // Leaf or ancestor is absent. Compute path under canonicalRoot.
            $destPath = $canonicalRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        }

        // Recheck backup fresh when used
        $this->assertBackupFileValid($backupsDir, $relPath, $sourceMeta);
        $backupFile = $backupsDir . DIRECTORY_SEPARATOR . hash('sha256', $relPath) . '.bak';

        $this->ensureParentDirectories($canonicalRoot, $relPath);

        $this->triggerCheckpoint('before_restore_file', ['path' => $relPath, 'action' => 'restore_backup']);

        $tmpFile = $tempDir . DIRECTORY_SEPARATOR . 'rst_' . bin2hex(random_bytes(8)) . '.tmp';
        $this->copyBoundedVerifiedFile($backupFile, $tmpFile, $sourceMeta['size'], $sourceMeta['sha256'], 'restore');

        // Freshly resolve destination parent directory right before rename
        $destDir = dirname($destPath);
        $canonicalDestDir = ProductInventoryService::resolveAbsolutePath($destDir, true);
        ProductInventoryService::assertNoReparse($canonicalDestDir);

        // Recheck destination leaf SIGNED source or target AGAIN IMMEDIATELY before rename after private copy + after io hooks
        clearstatcache(true, $destPath);
        if (file_exists($destPath) || is_link($destPath)) {
            ProductInventoryService::assertNoReparse($destPath);
            if (is_dir($destPath)) {
                throw new RuntimeException("Rollback safety violation: expected file, found directory: {$relPath}");
            }
            $lateSize = filesize($destPath);
            $lateHash = hash_file('sha256', $destPath);
            $matchesSource = ($lateSize === $sourceMeta['size'] && strtolower((string) $lateHash) === strtolower($sourceMeta['sha256']));
            $matchesTarget = ($targetMeta !== null &&
                $lateSize === $targetMeta['size'] &&
                strtolower((string) $lateHash) === strtolower($targetMeta['sha256']));

            if (!$matchesSource && !$matchesTarget) {
                throw new RuntimeException("Rollback safety violation: late leaf collision on file '{$relPath}' with unknown bytes. Refusing to overwrite.");
            }
        }

        if (!@rename($tmpFile, $destPath)) {
            throw new RuntimeException("Failed to atomically restore file: {$destPath}");
        }
        clearstatcache(true, $destPath);

        $this->triggerCheckpoint('after_restore_file', ['path' => $relPath, 'action' => 'restore_backup']);
    }

    /**
     * Publish a single file atomically into the product tree.
     *
     * @param array{sha256: string, size: int}|null $sourceMeta
     * @param array<string, mixed> $journal
     */
    private function publishFile(
        string $canonicalRoot,
        string $relPath,
        string $stagedPath,
        string $expectedHash,
        int $expectedSize,
        ?array $sourceMeta,
        string $tempDir,
        array &$journal,
        string $workspace
    ): void {
        // Recheck staged file fresh before use
        clearstatcache(true, $stagedPath);
        if (!is_file($stagedPath) || filesize($stagedPath) !== $expectedSize) {
            throw new RuntimeException("Staged file missing or size mismatch before publish: {$stagedPath}");
        }
        $stagedHash = hash_file('sha256', $stagedPath);
        if ($stagedHash === false || strtolower($stagedHash) !== strtolower($expectedHash)) {
            throw new RuntimeException("Staged file checksum mismatch before publish: {$stagedPath}");
        }

        $createdDirs = $this->ensureParentDirectories($canonicalRoot, $relPath);
        if (!empty($createdDirs)) {
            $journal['created_directories'] = array_values(array_unique(array_merge(
                $journal['created_directories'] ?? [],
                $createdDirs
            )));
            $this->writeJournal($workspace, $journal);
        }

        $this->triggerCheckpoint('before_publish_file', ['path' => $relPath]);

        // IMMEDIATELY after before_publish_file hook:
        // Verify current leaf on disk is either SIGNED source (for modified) or absent (for added).
        // If foreign bytes were injected late, refuse to overwrite!
        // Acknowledge residual uncoordinated FS TOCTOU: Windows does not provide kernel conditional hash rename.
        $destPath = $canonicalRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        clearstatcache(true, $destPath);

        if (file_exists($destPath) || is_link($destPath)) {
            ProductInventoryService::assertNoReparse($destPath);
            if (is_dir($destPath)) {
                throw new RuntimeException("Cannot publish over existing directory: {$relPath}");
            }
            if ($sourceMeta === null) {
                // Was an added file: must have been absent! Injected late foreign bytes!
                throw new RuntimeException("Refusing to publish added file over injected foreign entry: {$relPath}");
            }
            // Was a modified file: must match signed source
            $curSize = filesize($destPath);
            $curHash = hash_file('sha256', $destPath);
            if ($curSize !== $sourceMeta['size'] || strtolower((string) $curHash) !== strtolower($sourceMeta['sha256'])) {
                throw new RuntimeException("Refusing to publish modified file over modified foreign entry: {$relPath}");
            }
        } else {
            if ($sourceMeta !== null) {
                // Was a modified file, but missing on disk right before publish!
                throw new RuntimeException("Source file missing before publish mutation: {$relPath}");
            }
        }

        $tmpFile = $tempDir . DIRECTORY_SEPARATOR . 'pub_' . bin2hex(random_bytes(8)) . '.tmp';
        $this->copyBoundedVerifiedFile($stagedPath, $tmpFile, $expectedSize, $expectedHash, 'publish');

        // Freshly resolve destination parent directory before rename
        $destDir = dirname($destPath);
        $canonicalDestDir = ProductInventoryService::resolveAbsolutePath($destDir, true);
        ProductInventoryService::assertNoReparse($canonicalDestDir);

        // Recheck destination leaf SIGNED source (or absent for added) AGAIN IMMEDIATELY before rename after private copy + after io hooks
        clearstatcache(true, $destPath);
        if (file_exists($destPath) || is_link($destPath)) {
            ProductInventoryService::assertNoReparse($destPath);
            if (is_dir($destPath)) {
                throw new RuntimeException("Cannot publish over existing directory: {$relPath}");
            }
            if ($sourceMeta === null) {
                // Was an added file: must have remained absent! Late foreign entry!
                throw new RuntimeException("Late leaf collision: refusing to publish added file over injected foreign entry: {$relPath}");
            }
            $lateSize = filesize($destPath);
            $lateHash = hash_file('sha256', $destPath);
            if ($lateSize !== $sourceMeta['size'] || strtolower((string) $lateHash) !== strtolower($sourceMeta['sha256'])) {
                throw new RuntimeException("Late leaf collision: refusing to publish modified file over altered foreign entry: {$relPath}");
            }
        } else {
            if ($sourceMeta !== null) {
                throw new RuntimeException("Late leaf missing: source file disappeared before publish rename: {$relPath}");
            }
        }

        if (!@rename($tmpFile, $destPath)) {
            throw new RuntimeException("Failed to atomically rename publish file to destination: {$destPath}");
        }
        clearstatcache(true, $destPath);

        // Checkpoint before phase ack: physical rename has completed, journal ack pending
        $this->triggerCheckpoint('before_phase_ack', ['path' => $relPath]);

        $journal['completed_paths'][] = $relPath;
        $this->writeJournal($workspace, $journal);

        $this->triggerCheckpoint('after_publish_file', ['path' => $relPath]);
    }

    /**
     * Delete an unneeded product file during apply.
     *
     * @param array{sha256: string, size: int} $sourceMeta
     * @param array<string, mixed> $journal
     */
    private function deleteProductFile(
        string $canonicalRoot,
        string $delPath,
        array $sourceMeta,
        array &$journal,
        string $workspace
    ): void {
        $destPath = $canonicalRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $delPath);

        $this->triggerCheckpoint('before_publish_file', ['path' => $delPath, 'action' => 'delete']);

        // Fresh parent directory + leaf checks immediately after hook and before unlink
        $destDir = dirname($destPath);
        $canonicalDestDir = ProductInventoryService::resolveAbsolutePath($destDir, true);
        ProductInventoryService::assertNoReparse($canonicalDestDir);

        clearstatcache(true, $destPath);
        if (file_exists($destPath) || is_link($destPath)) {
            ProductInventoryService::assertNoReparse($destPath);
            if (is_dir($destPath)) {
                throw new RuntimeException("Cannot delete directory as a file: {$delPath}");
            }
            $curSize = filesize($destPath);
            $curHash = hash_file('sha256', $destPath);
            if ($curSize !== $sourceMeta['size'] || strtolower((string) $curHash) !== strtolower($sourceMeta['sha256'])) {
                throw new RuntimeException("Refusing to delete file with unknown or foreign bytes: {$delPath}");
            }

            if (!@unlink($destPath)) {
                throw new RuntimeException("Failed to delete product file: {$destPath}");
            }
            clearstatcache(true, $destPath);
        }

        $this->triggerCheckpoint('before_phase_ack', ['path' => $delPath, 'action' => 'delete']);
        $journal['completed_paths'][] = $delPath;
        $this->writeJournal($workspace, $journal);
        $this->triggerCheckpoint('after_publish_file', ['path' => $delPath, 'action' => 'delete']);
    }

    /**
     * Ensure parent directory hierarchy exists one component at a time with
     * strict confinement and reparse point checks.
     *
     * @return list<string> Newly created relative directory paths
     */
    private function ensureParentDirectories(string $canonicalRoot, string $relPath): array
    {
        $syntax = ProductInventoryService::validatePathSyntax($relPath);
        if (!$syntax['ok']) {
            throw new RuntimeException("Invalid path syntax: {$relPath}");
        }

        $segments = explode('/', $relPath);
        array_pop($segments); // Remove filename

        $created = [];
        $accum = '';

        foreach ($segments as $seg) {
            $accum = $accum === '' ? $seg : $accum . '/' . $seg;
            $compPath = $canonicalRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $accum);
            clearstatcache(true, $compPath);

            if (file_exists($compPath) || is_link($compPath)) {
                ProductInventoryService::assertNoReparse($compPath);
                $canonical = ProductInventoryService::resolveAbsolutePath($compPath, true);
                if (!is_dir($canonical) || is_link($canonical)) {
                    throw new RuntimeException("Intermediate path segment is not a directory: {$accum}");
                }
            } else {
                if (!@mkdir($compPath, 0755, false) && !is_dir($compPath)) {
                    throw new RuntimeException("Failed to create intermediate directory: {$compPath}");
                }
                $canonical = ProductInventoryService::resolveAbsolutePath($compPath, true);
                ProductInventoryService::assertNoReparse($canonical);
                if (!is_dir($canonical) || is_link($canonical)) {
                    throw new RuntimeException("Created directory failed confinement check: {$accum}");
                }
                $created[] = $accum;
            }
        }

        return $created;
    }

    /**
     * Stage all changed files from the package ZIP into workspace/staged.
     *
     * @param array<string, mixed> $descriptor
     * @return array<string, array{staged_path: string, sha256: string, size: int}>
     */
    private function stageChangedFiles(string $sealedZipPath, array $descriptor, string $workspace): array
    {
        $stagedFiles = [];
        $zip = new ZipArchive();
        if ($zip->open($sealedZipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Failed to open sealed package ZIP: {$sealedZipPath}");
        }

        $stagedDir = $workspace . DIRECTORY_SEPARATOR . 'staged';
        $canonicalStagedDir = ProductInventoryService::resolveAbsolutePath($stagedDir, true);
        ProductInventoryService::assertNoReparse($canonicalStagedDir);

        try {
            foreach ($descriptor['files'] as $entry) {
                $relPath = $entry['path'];
                $expectedHash = strtolower($entry['sha256']);
                $expectedSize = $entry['size'];

                $stream = $zip->getStream($relPath);
                if ($stream === false) {
                    throw new RuntimeException("Cannot open ZIP stream for entry: {$relPath}");
                }

                $stagedPath = $canonicalStagedDir . DIRECTORY_SEPARATOR . hash('sha256', $relPath) . '.staged';
                $outFp = @fopen($stagedPath, 'xb');
                if ($outFp === false) {
                    fclose($stream);
                    throw new RuntimeException("Cannot create staged file: {$stagedPath}");
                }

                $written = 0;
                try {
                    while (!feof($stream) && $written <= $expectedSize) {
                        $toRead = min(65536, ($expectedSize + 1) - $written);
                        $chunk = fread($stream, $toRead);
                        if ($chunk === false) {
                            throw new RuntimeException("Error reading ZIP stream for: {$relPath}");
                        }
                        if ($chunk === '') {
                            break;
                        }
                        $w = fwrite($outFp, $chunk);
                        if ($w !== strlen($chunk)) {
                            throw new RuntimeException("Short write to staged file: {$stagedPath}");
                        }
                        $written += $w;
                        if ($written > $expectedSize) {
                            throw new RuntimeException("Staged entry exceeded expected size ({$expectedSize}) for: {$relPath}");
                        }
                    }
                    if (!@fclose($stream)) {
                        $stream = null;
                        throw new RuntimeException("Failed to close ZIP stream for: {$relPath}");
                    }
                    $stream = null;

                    $this->triggerCheckpoint('io_before_flush', ['purpose' => 'stage', 'path' => $stagedPath]);
                    if (@fflush($outFp) === false) {
                        throw new RuntimeException("fflush failed for staged file: {$stagedPath}");
                    }

                    $this->triggerCheckpoint('io_before_fsync', ['purpose' => 'stage', 'path' => $stagedPath]);
                    if (!@fsync($outFp)) {
                        throw new RuntimeException("fsync failed for staged file: {$stagedPath}");
                    }

                    $this->triggerCheckpoint('io_before_close', ['purpose' => 'stage', 'path' => $stagedPath]);
                    if (!@fclose($outFp)) {
                        $outFp = null;
                        throw new RuntimeException("fclose failed for staged file: {$stagedPath}");
                    }
                    $outFp = null;

                    $this->triggerCheckpoint('io_after_close', ['purpose' => 'stage', 'path' => $stagedPath]);

                    clearstatcache(true, $stagedPath);
                    if (!is_file($stagedPath) || is_link($stagedPath)) {
                        throw new RuntimeException("Staged file missing or is link after close: {$stagedPath}");
                    }
                    ProductInventoryService::assertNoReparse($stagedPath);

                    $actualSize = filesize($stagedPath);
                    $actualHash = hash_file('sha256', $stagedPath);
                    if ($actualSize !== $expectedSize || $actualHash === false || strtolower($actualHash) !== $expectedHash) {
                        throw new RuntimeException("On-disk staged file verification failed for: {$relPath}");
                    }
                } finally {
                    if ($stream !== null && is_resource($stream)) {
                        @fclose($stream);
                    }
                    if ($outFp !== null && is_resource($outFp)) {
                        @fclose($outFp);
                    }
                }

                $stagedFiles[$relPath] = [
                    'staged_path' => $stagedPath,
                    'sha256' => $expectedHash,
                    'size' => $actualSize,
                ];
            }
        } finally {
            $zip->close();
        }

        return $stagedFiles;
    }

    /**
     * Back up every existing modified or deleted source file into workspace/backups.
     *
     * @param array<string, mixed> $descriptor
     * @return array<string, array{backup_path: string, sha256: string, size: int}>
     */
    private function backupSourceFiles(string $canonicalRoot, array $descriptor, string $workspace): array
    {
        $backupFiles = [];
        $sourceInventoryFiles = $descriptor['source_inventory']['files'];
        $backupsDir = $workspace . DIRECTORY_SEPARATOR . 'backups';
        $canonicalBackupsDir = ProductInventoryService::resolveAbsolutePath($backupsDir, true);
        ProductInventoryService::assertNoReparse($canonicalBackupsDir);

        foreach ($descriptor['files'] as $entry) {
            $relPath = $entry['path'];
            if (isset($sourceInventoryFiles[$relPath])) {
                $backupFiles[$relPath] = $this->createSourceBackup(
                    $canonicalRoot,
                    $relPath,
                    $sourceInventoryFiles[$relPath],
                    $canonicalBackupsDir
                );
            }
        }

        foreach ($descriptor['deleted_files'] as $relPath) {
            if (!isset($sourceInventoryFiles[$relPath])) {
                throw new RuntimeException("Deleted file not in source inventory: {$relPath}");
            }
            $backupFiles[$relPath] = $this->createSourceBackup(
                $canonicalRoot,
                $relPath,
                $sourceInventoryFiles[$relPath],
                $canonicalBackupsDir
            );
        }

        return $backupFiles;
    }

    /**
     * Back up a single file and verify against signed source metadata.
     *
     * @param array{sha256: string, size: int} $expectedMeta
     * @return array{backup_path: string, sha256: string, size: int}
     */
    private function createSourceBackup(
        string $canonicalRoot,
        string $relPath,
        array $expectedMeta,
        string $backupsDir
    ): array {
        $sourcePath = ProductInventoryService::resolveConfinedPath($canonicalRoot, $relPath, true);
        if (!is_file($sourcePath) || is_link($sourcePath)) {
            throw new RuntimeException("Source file for backup is missing or not a regular file: {$relPath}");
        }

        $canonicalBackupsDir = ProductInventoryService::resolveAbsolutePath($backupsDir, true);
        ProductInventoryService::assertNoReparse($canonicalBackupsDir);

        $backupFile = $canonicalBackupsDir . DIRECTORY_SEPARATOR . hash('sha256', $relPath) . '.bak';
        $inFp = @fopen($sourcePath, 'rb');
        if ($inFp === false) {
            throw new RuntimeException("Cannot open source file for backup: {$sourcePath}");
        }
        $outFp = @fopen($backupFile, 'xb');
        if ($outFp === false) {
            fclose($inFp);
            throw new RuntimeException("Cannot create backup file: {$backupFile}");
        }

        $expectedSize = $expectedMeta['size'];
        $copied = 0;
        try {
            while (!feof($inFp) && $copied <= $expectedSize) {
                $toRead = min(65536, ($expectedSize + 1) - $copied);
                $chunk = fread($inFp, $toRead);
                if ($chunk === false) {
                    throw new RuntimeException("Error reading source file: {$sourcePath}");
                }
                if ($chunk === '') {
                    break;
                }
                $w = fwrite($outFp, $chunk);
                if ($w !== strlen($chunk)) {
                    throw new RuntimeException("Short write to backup file: {$backupFile}");
                }
                $copied += $w;
                if ($copied > $expectedSize) {
                    throw new RuntimeException("Source file exceeded expected size ({$expectedSize}) for backup: {$relPath}");
                }
            }
            if (!@fclose($inFp)) {
                $inFp = null;
                throw new RuntimeException("Failed to close source file after backup read: {$sourcePath}");
            }
            $inFp = null;

            $this->triggerCheckpoint('io_before_flush', ['purpose' => 'backup', 'path' => $backupFile]);
            if (@fflush($outFp) === false) {
                throw new RuntimeException("fflush failed for backup file: {$backupFile}");
            }

            $this->triggerCheckpoint('io_before_fsync', ['purpose' => 'backup', 'path' => $backupFile]);
            if (!@fsync($outFp)) {
                throw new RuntimeException("fsync failed for backup file: {$backupFile}");
            }

            $this->triggerCheckpoint('io_before_close', ['purpose' => 'backup', 'path' => $backupFile]);
            if (!@fclose($outFp)) {
                $outFp = null;
                throw new RuntimeException("fclose failed for backup file: {$backupFile}");
            }
            $outFp = null;

            $this->triggerCheckpoint('io_after_close', ['purpose' => 'backup', 'path' => $backupFile]);

            clearstatcache(true, $backupFile);
            if (!is_file($backupFile) || is_link($backupFile)) {
                throw new RuntimeException("Backup file missing or is link after close: {$backupFile}");
            }
            ProductInventoryService::assertNoReparse($backupFile);

            $actualSize = filesize($backupFile);
            $actualHash = hash_file('sha256', $backupFile);
            if ($actualSize !== $expectedSize || $actualHash === false || strtolower($actualHash) !== strtolower($expectedMeta['sha256'])) {
                throw new RuntimeException("On-disk source file backup verification failed for: {$relPath}");
            }
        } finally {
            if ($inFp !== null && is_resource($inFp)) {
                @fclose($inFp);
            }
            if ($outFp !== null && is_resource($outFp)) {
                @fclose($outFp);
            }
        }

        return [
            'backup_path' => $backupFile,
            'sha256' => strtolower($actualHash),
            'size' => $actualSize,
        ];
    }

    /**
     * Reverify sealed manifest and signature without requiring package ZIP.
     *
     * @param array<string, mixed> $installedContext
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function reverifySealedManifest(string $workspace, array $installedContext): array
    {
        $sealedManifestPath = $workspace . DIRECTORY_SEPARATOR . 'sealed' . DIRECTORY_SEPARATOR . ShellDeltaManifestService::MANIFEST_ASSET;
        $sealedSigPath = $workspace . DIRECTORY_SEPARATOR . 'sealed' . DIRECTORY_SEPARATOR . ShellDeltaManifestService::SIGNATURE_ASSET;

        if (!is_file($sealedManifestPath) || !is_file($sealedSigPath)) {
            throw new RuntimeException("Sealed manifest or signature missing in workspace.");
        }

        $raw = $this->readBoundedFile($sealedManifestPath, ShellDeltaManifestService::MAX_MANIFEST_SIZE);
        $sig = $this->readBoundedFile($sealedSigPath, ShellDeltaManifestService::MAX_SIGNATURE_SIZE);

        $verifyResult = $this->manifests->verify($raw, $sig, $installedContext);
        if (!$verifyResult['ok'] || !is_array($verifyResult['descriptor'] ?? null)) {
            throw new RuntimeException("Sealed manifest verification failed: " . ($verifyResult['reason_code'] ?? 'invalid_manifest'));
        }

        return [$raw, $sig, $verifyResult['descriptor']];
    }

    /**
     * Reverify sealed manifest, signature, and package ZIP.
     *
     * @param array<string, mixed> $installedContext
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function reverifySealedPackage(string $workspace, array $installedContext): array
    {
        [$raw, $sig, $descriptor] = $this->reverifySealedManifest($workspace, $installedContext);

        $sealedZipPath = $workspace . DIRECTORY_SEPARATOR . 'sealed' . DIRECTORY_SEPARATOR . ShellDeltaManifestService::PACKAGE_ASSET;
        if (!is_file($sealedZipPath)) {
            throw new RuntimeException("Sealed package ZIP missing in workspace.");
        }

        $zipResult = $this->manifests->validatePackageZip($sealedZipPath, $descriptor);
        if (!$zipResult['ok']) {
            throw new RuntimeException("Sealed package ZIP verification failed: " . ($zipResult['reason_code'] ?? 'invalid_zip'));
        }

        return [$raw, $sig, $descriptor];
    }

    /**
     * Enforce strict policy constraints on verified manifest descriptor.
     *
     * @param array<string, mixed> $descriptor
     */
    private function enforceCorePolicyConstraints(array $descriptor): void
    {
        // 1. Accept regular purpose ONLY; bootstrap_completion fails closed
        if (($descriptor['purpose'] ?? null) !== 'regular') {
            throw new RuntimeException("Only 'regular' purpose is supported by this transaction core. 'bootstrap_completion' fails closed.");
        }

        // 2. Require source and target data compatibility identical (strict four keys value compare)
        $srcCompat = $descriptor['source_data_compatibility'] ?? null;
        $tgtCompat = $descriptor['data_compatibility'] ?? null;
        if (!is_array($srcCompat) || !is_array($tgtCompat)) {
            throw new RuntimeException("Missing source or target data compatibility contract.");
        }
        $compatKeys = ['format', 'ledger_sha256', 'metadata_migration', 'schema_sha256'];
        foreach ($compatKeys as $k) {
            if (!isset($srcCompat[$k], $tgtCompat[$k]) || $srcCompat[$k] !== $tgtCompat[$k]) {
                throw new RuntimeException("Source and target data compatibility contracts must be strictly identical.");
            }
        }

        // 3. No changes or deletions to ANY resources/portable/mysql/** or schema SQL scripts
        $assertAllowedPath = static function (string $p): void {
            $norm = strtolower(str_replace('\\', '/', $p));
            if (str_starts_with($norm, 'resources/portable/mysql/') || str_ends_with($norm, '.sql')) {
                throw new RuntimeException("Disallowed mutation to database asset or SQL schema: {$p}");
            }
        };

        foreach ($descriptor['files'] as $f) {
            $assertAllowedPath($f['path']);
        }
        foreach ($descriptor['deleted_files'] as $p) {
            $assertAllowedPath($p);
        }
    }

    /**
     * Assert integrity of staged files and backup files in workspace before apply.
     *
     * @param array<string, mixed> $descriptor
     * @param array<string, mixed> $journal
     */
    private function assertStagedAndBackupFilesIntegrity(
        string $workspace,
        array $descriptor,
        array $journal
    ): void {
        $stagedDir = $workspace . DIRECTORY_SEPARATOR . 'staged';
        $canonicalStagedDir = ProductInventoryService::resolveAbsolutePath($stagedDir, true);
        ProductInventoryService::assertNoReparse($canonicalStagedDir);

        foreach ($descriptor['files'] as $fEntry) {
            $relPath = $fEntry['path'];
            $stagedFile = $canonicalStagedDir . DIRECTORY_SEPARATOR . hash('sha256', $relPath) . '.staged';
            clearstatcache(true, $stagedFile);
            if (!is_file($stagedFile) || is_link($stagedFile)) {
                throw new RuntimeException("Staged file missing or is link: {$relPath}");
            }
            ProductInventoryService::assertNoReparse($stagedFile);
            if (filesize($stagedFile) !== $fEntry['size']) {
                throw new RuntimeException("Staged file size mismatch: {$relPath}");
            }
            $hash = hash_file('sha256', $stagedFile);
            if ($hash === false || strtolower($hash) !== strtolower($fEntry['sha256'])) {
                throw new RuntimeException("Staged file checksum mismatch: {$relPath}");
            }
        }

        $backupsDir = $workspace . DIRECTORY_SEPARATOR . 'backups';
        $canonicalBackupsDir = ProductInventoryService::resolveAbsolutePath($backupsDir, true);
        ProductInventoryService::assertNoReparse($canonicalBackupsDir);
        $sourceInventoryFiles = $descriptor['source_inventory']['files'];

        foreach ($descriptor['files'] as $fEntry) {
            $relPath = $fEntry['path'];
            if (isset($sourceInventoryFiles[$relPath])) {
                $this->assertBackupFileValid($canonicalBackupsDir, $relPath, $sourceInventoryFiles[$relPath]);
            }
        }

        foreach ($descriptor['deleted_files'] as $delPath) {
            if (isset($sourceInventoryFiles[$delPath])) {
                $this->assertBackupFileValid($canonicalBackupsDir, $delPath, $sourceInventoryFiles[$delPath]);
            }
        }
    }

    /**
     * Assert that a backup file exists and strictly matches signed source metadata.
     *
     * @param array{sha256: string, size: int} $expectedMeta
     */
    private function assertBackupFileValid(string $backupsDir, string $relPath, array $expectedMeta): void
    {
        $canonicalBackupsDir = ProductInventoryService::resolveAbsolutePath($backupsDir, true);
        ProductInventoryService::assertNoReparse($canonicalBackupsDir);

        $backupFile = $canonicalBackupsDir . DIRECTORY_SEPARATOR . hash('sha256', $relPath) . '.bak';
        clearstatcache(true, $backupFile);
        if (!is_file($backupFile) || is_link($backupFile)) {
            throw new RuntimeException("Backup file missing or is link for: {$relPath}");
        }
        ProductInventoryService::assertNoReparse($backupFile);
        if (filesize($backupFile) !== $expectedMeta['size']) {
            throw new RuntimeException("Backup file size mismatch for: {$relPath}");
        }
        $hash = hash_file('sha256', $backupFile);
        if ($hash === false || strtolower($hash) !== strtolower($expectedMeta['sha256'])) {
            throw new RuntimeException("Backup file checksum mismatch for: {$relPath}");
        }
    }

    /**
     * Check for file <-> directory collisions/transitions preflight.
     *
     * @param array<string, mixed> $descriptor
     */
    private function assertNoShapeTransitions(string $canonicalRoot, array $descriptor): void
    {
        foreach ($descriptor['files'] as $fEntry) {
            $relPath = $fEntry['path'];
            $syntax = ProductInventoryService::validatePathSyntax($relPath);
            if (!$syntax['ok']) {
                throw new RuntimeException("Invalid path syntax: {$relPath}");
            }

            $segments = explode('/', $relPath);
            array_pop($segments);
            $accum = '';
            foreach ($segments as $seg) {
                $accum = $accum === '' ? $seg : $accum . '/' . $seg;
                $walk = $this->readonlyProductWalk($canonicalRoot, $accum);
                if (!$walk['exists']) {
                    // Ancestor absent; no shape collision possible along this absent subtree
                    break;
                }
                if (!$walk['is_dir']) {
                    throw new RuntimeException("Target directory ancestor collision with existing file: {$accum}");
                }
            }

            // Check the target leaf itself: if it exists, it cannot be a directory
            $leafWalk = $this->readonlyProductWalk($canonicalRoot, $relPath);
            if ($leafWalk['exists'] && $leafWalk['is_dir']) {
                throw new RuntimeException("Target file collision with existing directory: {$relPath}");
            }
        }
    }

    /**
     * Assert workspace ownership and confinement.
     */
    private function assertWorkspaceOwnership(
        string $workspace,
        string $expectedOperationId,
        string $canonicalRoot,
        string $canonicalStateBase
    ): void {
        $canonicalWs = ProductInventoryService::resolveAbsolutePath($workspace, true);
        ProductInventoryService::assertNoReparse($canonicalWs);

        $normState = rtrim(strtolower(str_replace('/', '\\', $canonicalStateBase)), '\\');
        $normWs = rtrim(strtolower(str_replace('/', '\\', $canonicalWs)), '\\');
        if (!str_starts_with($normWs, $normState . '\\')) {
            throw new RuntimeException("Workspace escapes private state base boundary: {$canonicalWs}");
        }

        $ownerFile = $canonicalWs . DIRECTORY_SEPARATOR . self::OWNER_MARKER_FILENAME;
        clearstatcache(true, $ownerFile);
        if (!is_file($ownerFile) || is_link($ownerFile)) {
            throw new RuntimeException("Workspace owner marker missing or is link: {$ownerFile}");
        }
        ProductInventoryService::assertNoReparse($ownerFile);

        $raw = $this->readBoundedFile($ownerFile, 65536);
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("Workspace owner marker corrupt: {$ownerFile}");
        }

        $allowedKeys = ['operation_id', 'root', 'created_at', 'pid'];
        if (count($data) !== 4) {
            throw new RuntimeException("Workspace owner marker has invalid field count: {$ownerFile}");
        }
        foreach ($allowedKeys as $k) {
            if (!array_key_exists($k, $data)) {
                throw new RuntimeException("Workspace owner marker missing required key: {$k}");
            }
        }
        if (!is_string($data['operation_id']) || preg_match('/^tx_[a-f0-9]{32}$/D', $data['operation_id']) !== 1 || $data['operation_id'] !== $expectedOperationId) {
            throw new RuntimeException("Workspace owner marker operation_id mismatch: {$ownerFile}");
        }
        if (!is_string($data['root']) || $data['root'] !== $canonicalRoot) {
            throw new RuntimeException("Workspace owner marker root mismatch: {$ownerFile}");
        }
        if (!is_int($data['created_at']) || $data['created_at'] <= 0) {
            throw new RuntimeException("Workspace owner marker created_at invalid: {$ownerFile}");
        }
        if (!is_int($data['pid']) || $data['pid'] <= 0) {
            throw new RuntimeException("Workspace owner marker pid invalid: {$ownerFile}");
        }
    }

    /**
     * Read and validate active operation marker file.
     *
     * @return array{operation_id: string, root: string, workspace: string, created_at: int, pid: int, raw_bytes: string}
     */
    private function readAndValidateActiveMarker(
        string $markerFile,
        string $expectedOperationId,
        string $canonicalRoot,
        string $expectedWorkspace
    ): array {
        clearstatcache(true, $markerFile);
        if (!is_file($markerFile) || is_link($markerFile)) {
            throw new RuntimeException("Active operation marker missing or is symbolic link: {$markerFile}");
        }
        ProductInventoryService::assertNoReparse($markerFile);

        $raw = $this->readBoundedFile($markerFile, 65536);
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("Active operation marker corrupt: {$markerFile}");
        }

        $allowedKeys = ['operation_id', 'root', 'workspace', 'created_at', 'pid'];
        if (count($data) !== 5) {
            throw new RuntimeException("Active operation marker has invalid field count or unknown fields: {$markerFile}");
        }
        foreach ($allowedKeys as $k) {
            if (!array_key_exists($k, $data)) {
                throw new RuntimeException("Active operation marker missing required key: {$k}");
            }
        }

        if (!is_string($data['operation_id']) || preg_match('/^tx_[a-f0-9]{32}$/D', $data['operation_id']) !== 1 || $data['operation_id'] !== $expectedOperationId) {
            throw new RuntimeException("Active operation marker operation_id mismatch: expected '{$expectedOperationId}', got '" . ($data['operation_id'] ?? 'none') . "'");
        }

        if (!is_string($data['root']) || $data['root'] !== $canonicalRoot) {
            throw new RuntimeException("Active operation marker root mismatch: expected '{$canonicalRoot}', got '" . ($data['root'] ?? 'none') . "'");
        }

        if (!is_string($data['workspace'])) {
            throw new RuntimeException("Active operation marker workspace missing or invalid: {$markerFile}");
        }

        // Workspace path must match expected computed workspace path exactly (marker cannot redirect)
        $normExpectedWs = rtrim(strtolower(str_replace('/', '\\', $expectedWorkspace)), '\\');
        $normMarkerWs = rtrim(strtolower(str_replace('/', '\\', $data['workspace'])), '\\');
        if ($normMarkerWs !== $normExpectedWs) {
            throw new RuntimeException("Active operation marker workspace redirection rejected: expected '{$expectedWorkspace}', got '{$data['workspace']}'");
        }

        if (!is_int($data['created_at']) || $data['created_at'] <= 0) {
            throw new RuntimeException("Active operation marker created_at invalid: {$markerFile}");
        }

        if (!is_int($data['pid']) || $data['pid'] <= 0) {
            throw new RuntimeException("Active operation marker pid invalid: {$markerFile}");
        }

        $data['raw_bytes'] = $raw;
        return $data;
    }

    /**
     * Read and validate journal structure.
     *
     * @return array<string, mixed>
     */
    private function readAndValidateJournal(string $workspace, string $expectedOperationId, string $canonicalRoot): array
    {
        $journalFile = $workspace . DIRECTORY_SEPARATOR . self::JOURNAL_FILENAME;
        clearstatcache(true, $journalFile);
        if (!is_file($journalFile) || is_link($journalFile)) {
            throw new RuntimeException("Journal file missing or is symbolic link: {$journalFile}");
        }
        ProductInventoryService::assertNoReparse($journalFile);

        $raw = $this->readBoundedFile($journalFile, 16 * 1024 * 1024);
        $journal = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($journal)) {
            throw new RuntimeException("Journal data is not an array: {$journalFile}");
        }

        $allowedKeys = [
            'operation_id',
            'phase',
            'root',
            'descriptor_digest',
            'created_at',
            'completed_paths',
            'created_directories',
            'applied_version',
            'applied_target_digest',
            'files',
            'deleted_files',
            'staged_files',
            'backup_files',
        ];

        foreach (array_keys($journal) as $k) {
            if (!in_array($k, $allowedKeys, true)) {
                throw new RuntimeException("Journal contains unknown key '{$k}': {$journalFile}");
            }
        }

        foreach (['operation_id', 'phase', 'root', 'descriptor_digest', 'created_at'] as $k) {
            if (!isset($journal[$k])) {
                throw new RuntimeException("Journal missing required key: {$k}");
            }
        }

        if (!is_string($journal['operation_id']) || preg_match('/^tx_[a-f0-9]{32}$/D', $journal['operation_id']) !== 1 || $journal['operation_id'] !== $expectedOperationId) {
            throw new RuntimeException("Journal operation_id mismatch: expected '{$expectedOperationId}', got '{$journal['operation_id']}'");
        }

        if (!is_string($journal['root']) || $journal['root'] !== $canonicalRoot) {
            throw new RuntimeException("Journal root mismatch: expected '{$canonicalRoot}', got '{$journal['root']}'");
        }

        if (!is_string($journal['descriptor_digest']) || preg_match('/^[a-f0-9]{64}$/D', $journal['descriptor_digest']) !== 1) {
            throw new RuntimeException("Journal descriptor_digest is invalid or corrupt: {$journalFile}");
        }

        if (!is_int($journal['created_at']) || $journal['created_at'] <= 0) {
            throw new RuntimeException("Journal created_at is invalid: {$journalFile}");
        }

        if (!in_array($journal['phase'], [
            self::PHASE_PREPARED,
            self::PHASE_APPLYING,
            self::PHASE_FILES_APPLIED,
            self::PHASE_ROLLING_BACK,
            self::PHASE_ROLLED_BACK,
        ], true)) {
            throw new RuntimeException("Invalid journal phase: {$journal['phase']}");
        }

        // Validate list of string arrays
        foreach (['completed_paths', 'created_directories'] as $listKey) {
            if (isset($journal[$listKey])) {
                if (!is_array($journal[$listKey])) {
                    throw new RuntimeException("Journal key '{$listKey}' is not an array: {$journalFile}");
                }
                if (count($journal[$listKey]) > 20000) {
                    throw new RuntimeException("Journal array '{$listKey}' exceeds maximum allowed limit of 20000 entries: {$journalFile}");
                }
                foreach ($journal[$listKey] as $item) {
                    if (!is_string($item)) {
                        throw new RuntimeException("Journal array '{$listKey}' contains non-string item: {$journalFile}");
                    }
                }
            }
        }

        if (isset($journal['applied_version'])) {
            if (!is_string($journal['applied_version']) || !ProductInventoryService::isValidSemver($journal['applied_version'])) {
                throw new RuntimeException("Journal applied_version is invalid semver: {$journalFile}");
            }
        }

        if (isset($journal['applied_target_digest'])) {
            if (!is_string($journal['applied_target_digest']) || preg_match('/^[a-f0-9]{64}$/D', $journal['applied_target_digest']) !== 1) {
                throw new RuntimeException("Journal applied_target_digest is invalid: {$journalFile}");
            }
        }

        $legacyArrayKeys = ['files', 'deleted_files', 'staged_files', 'backup_files'];
        foreach ($legacyArrayKeys as $ak) {
            if (isset($journal[$ak])) {
                if (!is_array($journal[$ak])) {
                    throw new RuntimeException("Journal key '{$ak}' is not an array: {$journalFile}");
                }
                if (count($journal[$ak]) > 20000) {
                    throw new RuntimeException("Journal array '{$ak}' exceeds maximum allowed limit of 20000 entries: {$journalFile}");
                }
            }
        }

        return $journal;
    }

    /**
     * Write journal atomically.
     *
     * @param array<string, mixed> $journal
     */
    private function writeJournal(string $workspace, array $journal): void
    {
        $journalFile = $workspace . DIRECTORY_SEPARATOR . self::JOURNAL_FILENAME;
        $json = json_encode($journal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->writeAtomicFile($journalFile, $json, 'journal');
    }

    /**
     * Copy package ZIP with bounded size limit and checked IO.
     */
    private function copyPackageZipBounded(string $srcZip, string $destZip): void
    {
        $canonicalSrc = ProductInventoryService::resolveAbsolutePath($srcZip, true);
        ProductInventoryService::assertNoReparse($canonicalSrc);

        $srcSize = filesize($canonicalSrc);
        if ($srcSize === false || $srcSize <= 0 || $srcSize > ShellDeltaManifestService::MAX_PACKAGE_SIZE) {
            throw new RuntimeException("Source package ZIP size is invalid or exceeds maximum allowed size.");
        }

        $destDir = dirname($destZip);
        $canonicalDestDir = ProductInventoryService::resolveAbsolutePath($destDir, true);
        ProductInventoryService::assertNoReparse($canonicalDestDir);

        $in = @fopen($canonicalSrc, 'rb');
        if ($in === false) {
            throw new RuntimeException("Cannot open source package ZIP: {$canonicalSrc}");
        }
        $out = @fopen($destZip, 'xb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException("Cannot create destination package ZIP: {$destZip}");
        }

        try {
            $copied = 0;
            while (!feof($in) && $copied <= $srcSize) {
                $toRead = min(65536, ($srcSize + 1) - $copied);
                $chunk = fread($in, $toRead);
                if ($chunk === false) {
                    throw new RuntimeException("Error reading package ZIP: {$canonicalSrc}");
                }
                if ($chunk === '') {
                    break;
                }
                $w = fwrite($out, $chunk);
                if ($w !== strlen($chunk)) {
                    throw new RuntimeException("Short write to package ZIP: {$destZip}");
                }
                $copied += $w;
                if ($copied > $srcSize || $copied > ShellDeltaManifestService::MAX_PACKAGE_SIZE) {
                    throw new RuntimeException("Package ZIP exceeds maximum allowed size during copy.");
                }
            }
            if (!@fclose($in)) {
                $in = null;
                throw new RuntimeException("Failed to close source package ZIP: {$canonicalSrc}");
            }
            $in = null;

            $this->triggerCheckpoint('io_before_flush', ['purpose' => 'prepare-zip', 'path' => $destZip]);
            if (@fflush($out) === false) {
                throw new RuntimeException("fflush failed for package zip: {$destZip}");
            }

            $this->triggerCheckpoint('io_before_fsync', ['purpose' => 'prepare-zip', 'path' => $destZip]);
            if (!@fsync($out)) {
                throw new RuntimeException("fsync failed for package zip: {$destZip}");
            }

            $this->triggerCheckpoint('io_before_close', ['purpose' => 'prepare-zip', 'path' => $destZip]);
            if (!@fclose($out)) {
                $out = null;
                throw new RuntimeException("fclose failed for package zip: {$destZip}");
            }
            $out = null;

            $this->triggerCheckpoint('io_after_close', ['purpose' => 'prepare-zip', 'path' => $destZip]);

            clearstatcache(true, $destZip);
            if (!is_file($destZip) || is_link($destZip)) {
                throw new RuntimeException("Package ZIP copy missing or is link after close.");
            }
            ProductInventoryService::assertNoReparse($destZip);

            if ($copied !== $srcSize || filesize($destZip) !== $srcSize) {
                throw new RuntimeException("Package ZIP copy size mismatch.");
            }
        } catch (Throwable $e) {
            if ($in !== null && is_resource($in)) {
                @fclose($in);
            }
            if ($out !== null && is_resource($out)) {
                @fclose($out);
            }
            throw $e;
        }
    }

    /**
     * Write file atomically via temporary file and rename with checked IO.
     */
    private function writeAtomicFile(string $destPath, string $content, string $purpose = 'journal'): void
    {
        $dir = dirname($destPath);
        $canonicalDir = ProductInventoryService::resolveAbsolutePath($dir, true);
        ProductInventoryService::assertNoReparse($canonicalDir);

        $tmpPath = $canonicalDir . DIRECTORY_SEPARATOR . '.tmp_' . bin2hex(random_bytes(8));
        $fp = @fopen($tmpPath, 'xb');
        if ($fp === false) {
            throw new RuntimeException("Failed to open temporary file for atomic write: {$tmpPath}");
        }

        try {
            $written = @fwrite($fp, $content);
            if ($written !== strlen($content)) {
                throw new RuntimeException("Short write to temporary file: {$tmpPath}");
            }

            $this->triggerCheckpoint('io_before_flush', ['purpose' => $purpose, 'path' => $tmpPath]);
            if (@fflush($fp) === false) {
                throw new RuntimeException("fflush failed for: {$tmpPath}");
            }

            $this->triggerCheckpoint('io_before_fsync', ['purpose' => $purpose, 'path' => $tmpPath]);
            if (!@fsync($fp)) {
                throw new RuntimeException("fsync failed for: {$tmpPath}");
            }

            $this->triggerCheckpoint('io_before_close', ['purpose' => $purpose, 'path' => $tmpPath]);
            if (!@fclose($fp)) {
                $fp = null;
                throw new RuntimeException("fclose failed for: {$tmpPath}");
            }
            $fp = null;

            $this->triggerCheckpoint('io_after_close', ['purpose' => $purpose, 'path' => $tmpPath]);

            clearstatcache(true, $tmpPath);
            if (!is_file($tmpPath) || is_link($tmpPath)) {
                throw new RuntimeException("Temporary file missing or is link before rename: {$tmpPath}");
            }
            ProductInventoryService::assertNoReparse($tmpPath);

            if (filesize($tmpPath) !== strlen($content) || hash_file('sha256', $tmpPath) !== hash('sha256', $content)) {
                throw new RuntimeException("Temporary file verification failed before rename: {$tmpPath}");
            }

            $destDir = dirname($destPath);
            $canonicalDestDir = ProductInventoryService::resolveAbsolutePath($destDir, true);
            ProductInventoryService::assertNoReparse($canonicalDestDir);

            if (!@rename($tmpPath, $destPath)) {
                throw new RuntimeException("Failed to atomically rename {$tmpPath} to {$destPath}");
            }
            clearstatcache(true, $destPath);
        } catch (Throwable $e) {
            if ($fp !== null && is_resource($fp)) {
                @fclose($fp);
            }
            throw $e;
        }
    }

    /**
     * Write file exclusively without overwriting existing entry.
     */
    private function writeExclusiveFile(string $destPath, string $content, string $purpose = 'marker'): void
    {
        $dir = dirname($destPath);
        $canonicalDir = ProductInventoryService::resolveAbsolutePath($dir, true);
        ProductInventoryService::assertNoReparse($canonicalDir);

        $fp = @fopen($destPath, 'xb');
        if ($fp === false) {
            throw new RuntimeException("Failed to open file exclusively: {$destPath}");
        }

        try {
            $written = @fwrite($fp, $content);
            if ($written !== strlen($content)) {
                throw new RuntimeException("Short write to exclusive file: {$destPath}");
            }

            $this->triggerCheckpoint('io_before_flush', ['purpose' => $purpose, 'path' => $destPath]);
            if (@fflush($fp) === false) {
                throw new RuntimeException("fflush failed for: {$destPath}");
            }

            $this->triggerCheckpoint('io_before_fsync', ['purpose' => $purpose, 'path' => $destPath]);
            if (!@fsync($fp)) {
                throw new RuntimeException("fsync failed for: {$destPath}");
            }

            $this->triggerCheckpoint('io_before_close', ['purpose' => $purpose, 'path' => $destPath]);
            if (!@fclose($fp)) {
                $fp = null;
                throw new RuntimeException("fclose failed for: {$destPath}");
            }
            $fp = null;

            $this->triggerCheckpoint('io_after_close', ['purpose' => $purpose, 'path' => $destPath]);

            clearstatcache(true, $destPath);
            if (!is_file($destPath) || is_link($destPath)) {
                throw new RuntimeException("Exclusive file missing or is link: {$destPath}");
            }
            ProductInventoryService::assertNoReparse($destPath);

            if (filesize($destPath) !== strlen($content) || hash_file('sha256', $destPath) !== hash('sha256', $content)) {
                throw new RuntimeException("Exclusive file verification failed: {$destPath}");
            }
        } catch (Throwable $e) {
            if ($fp !== null && is_resource($fp)) {
                @fclose($fp);
            }
            // Fail-retain on write errors: preserve primary exceptions during I/O failures without deleting foreign replacements
            throw $e;
        }
    }

    /**
     * Copy file with bounded stream, checked IO, and verified checksum.
     */
    private function copyBoundedVerifiedFile(
        string $srcPath,
        string $destPath,
        int $expectedSize,
        string $expectedHash,
        string $purpose
    ): void {
        clearstatcache(true, $srcPath);
        if (!is_file($srcPath) || is_link($srcPath)) {
            throw new RuntimeException("Source file does not exist or is not a regular file: {$srcPath}");
        }
        ProductInventoryService::assertNoReparse($srcPath);

        $srcSize = filesize($srcPath);
        if ($srcSize !== $expectedSize) {
            throw new RuntimeException("Source file size mismatch before copy: expected {$expectedSize}, got {$srcSize}");
        }

        $destDir = dirname($destPath);
        $canonicalDestDir = ProductInventoryService::resolveAbsolutePath($destDir, true);
        ProductInventoryService::assertNoReparse($canonicalDestDir);

        $inFp = @fopen($srcPath, 'rb');
        if ($inFp === false) {
            throw new RuntimeException("Cannot open source file for copy: {$srcPath}");
        }

        $outFp = @fopen($destPath, 'xb');
        if ($outFp === false) {
            fclose($inFp);
            throw new RuntimeException("Cannot create destination file for copy: {$destPath}");
        }

        try {
            $copied = 0;
            while (!feof($inFp) && $copied <= $expectedSize) {
                $toRead = min(65536, ($expectedSize + 1) - $copied);
                $chunk = fread($inFp, $toRead);
                if ($chunk === false) {
                    throw new RuntimeException("Error reading source file during copy: {$srcPath}");
                }
                if ($chunk === '') {
                    break;
                }
                $written = fwrite($outFp, $chunk);
                if ($written !== strlen($chunk)) {
                    throw new RuntimeException("Short write to destination file during copy: {$destPath}");
                }
                $copied += $written;
                if ($copied > $expectedSize) {
                    throw new RuntimeException("File size exceeded expected size ({$expectedSize}) during copy: {$destPath}");
                }
            }
            if (!@fclose($inFp)) {
                $inFp = null;
                throw new RuntimeException("Failed to close source file during copy: {$srcPath}");
            }
            $inFp = null;

            $this->triggerCheckpoint('io_before_flush', ['purpose' => $purpose, 'path' => $destPath]);
            if (@fflush($outFp) === false) {
                throw new RuntimeException("fflush failed for: {$destPath}");
            }

            $this->triggerCheckpoint('io_before_fsync', ['purpose' => $purpose, 'path' => $destPath]);
            if (!@fsync($outFp)) {
                throw new RuntimeException("fsync failed for: {$destPath}");
            }

            $this->triggerCheckpoint('io_before_close', ['purpose' => $purpose, 'path' => $destPath]);
            if (!@fclose($outFp)) {
                $outFp = null;
                throw new RuntimeException("fclose failed for: {$destPath}");
            }
            $outFp = null;

            $this->triggerCheckpoint('io_after_close', ['purpose' => $purpose, 'path' => $destPath]);

            clearstatcache(true, $destPath);
            if (!is_file($destPath) || is_link($destPath)) {
                throw new RuntimeException("Destination file missing or is link after copy: {$destPath}");
            }
            ProductInventoryService::assertNoReparse($destPath);

            $actualSize = filesize($destPath);
            $actualHash = hash_file('sha256', $destPath);
            if ($actualSize !== $expectedSize || $actualHash === false || strtolower($actualHash) !== strtolower($expectedHash)) {
                throw new RuntimeException("On-disk file verification failed after copy for: {$destPath}");
            }
        } catch (Throwable $e) {
            if ($inFp !== null && is_resource($inFp)) {
                @fclose($inFp);
            }
            if ($outFp !== null && is_resource($outFp)) {
                @fclose($outFp);
            }
            // Fail-retain on copy errors: preserve primary exceptions without deleting foreign victim files
            throw $e;
        }
    }

    /**
     * Read text file with strict byte bound.
     */
    private function readBoundedFile(string $filePath, int $maxBytes): string
    {
        $canonical = ProductInventoryService::resolveAbsolutePath($filePath, true);
        ProductInventoryService::assertNoReparse($canonical);

        clearstatcache(true, $canonical);
        if (!is_file($canonical) || is_link($canonical)) {
            throw new RuntimeException("File does not exist or is not a regular file: {$filePath}");
        }

        $size = filesize($canonical);
        if ($size === false || $size > $maxBytes) {
            throw new RuntimeException("File size exceeds allowed bound of {$maxBytes} bytes: {$filePath}");
        }

        $fp = @fopen($canonical, 'rb');
        if ($fp === false) {
            throw new RuntimeException("Cannot open file for reading: {$filePath}");
        }

        $content = '';
        $remaining = $maxBytes;
        while (!feof($fp) && $remaining > 0) {
            $chunk = fread($fp, min(65536, $remaining));
            if ($chunk === false) {
                fclose($fp);
                throw new RuntimeException("Error reading bounded file: {$filePath}");
            }
            if ($chunk === '') {
                break;
            }
            $content .= $chunk;
            $remaining -= strlen($chunk);
        }

        if (!feof($fp) && fgetc($fp) !== false) {
            fclose($fp);
            throw new RuntimeException("File content exceeds allowed bound of {$maxBytes} bytes: {$filePath}");
        }

        if (!@fclose($fp)) {
            throw new RuntimeException("Failed to close bounded file: {$filePath}");
        }

        return $content;
    }

    /**
     * Validate canonical root and stateBase directories.
     *
     * @return array{0: string, 1: string}
     */
    private function validateCanonicalDirectories(string $root, string $privateStateBase): array
    {
        $canonicalRoot = ProductInventoryService::resolveAbsolutePath($root, true);
        ProductInventoryService::assertNoReparse($canonicalRoot);
        if (!is_dir($canonicalRoot)) {
            throw new RuntimeException("Product root is not a directory: {$root}");
        }

        $canonicalStateBase = ProductInventoryService::resolveAbsolutePath($privateStateBase, true);
        ProductInventoryService::assertNoReparse($canonicalStateBase);
        if (!is_dir($canonicalStateBase)) {
            throw new RuntimeException("Private state base is not a directory: {$privateStateBase}");
        }

        // Require same Windows drive / volume
        $rootDrive = strtoupper(substr($canonicalRoot, 0, 2));
        $stateDrive = strtoupper(substr($canonicalStateBase, 0, 2));
        if ($rootDrive !== $stateDrive) {
            throw new RuntimeException("Product root ('{$canonicalRoot}') and private state base ('{$canonicalStateBase}') must reside on the same Windows volume for atomic rename operations.");
        }

        // Must be mutually disjoint in BOTH directions
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
     * Validate installed context format.
     *
     * @param array<string, mixed> $context
     */
    private function validateInstalledContext(array $context): void
    {
        $requiredKeys = ['version', 'native_version', 'channel', 'data_compatibility'];
        foreach ($requiredKeys as $k) {
            if (!isset($context[$k])) {
                throw new RuntimeException("Missing required installedContext key: {$k}");
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
    }

    /**
     * Ensure a state directory component exists without recursive unchecked mkdir.
     */
    private function ensureStateDirectoryComponent(string $canonicalBase, string $component): string
    {
        $target = $canonicalBase . DIRECTORY_SEPARATOR . $component;
        clearstatcache(true, $target);

        if (file_exists($target) || is_link($target)) {
            ProductInventoryService::assertNoReparse($target);
            $canonical = ProductInventoryService::resolveAbsolutePath($target, true);
            ProductInventoryService::assertNoReparse($canonical);
            if (!is_dir($canonical) || is_link($canonical)) {
                throw new RuntimeException("State path component is not a directory: {$target}");
            }
            return $canonical;
        }

        if (!@mkdir($target, 0700, false) && !is_dir($target)) {
            throw new RuntimeException("Failed to create state path component: {$target}");
        }

        $canonical = ProductInventoryService::resolveAbsolutePath($target, true);
        ProductInventoryService::assertNoReparse($canonical);
        if (!is_dir($canonical) || is_link($canonical)) {
            throw new RuntimeException("Created state directory failed confinement check: {$target}");
        }

        return $canonical;
    }

    /**
     * Allocate a new unique nonce workspace under privateStateBase.
     */
    private function allocateWorkspace(string $canonicalStateBase, string $operationId, string $canonicalRoot): string
    {
        $workspacesDir = $this->ensureStateDirectoryComponent($canonicalStateBase, 'workspaces');

        $workspace = $workspacesDir . DIRECTORY_SEPARATOR . $operationId;
        clearstatcache(true, $workspace);
        if (file_exists($workspace) || is_link($workspace)) {
            throw new RuntimeException("Workspace already exists: {$workspace}. Adopting existing directory is forbidden.");
        }

        if (!@mkdir($workspace, 0700, false)) {
            throw new RuntimeException("Failed to allocate workspace directory: {$workspace}");
        }

        $canonicalWorkspace = ProductInventoryService::resolveAbsolutePath($workspace, true);
        ProductInventoryService::assertNoReparse($canonicalWorkspace);

        // Verify workspace is confined within stateBase
        $normState = rtrim(strtolower(str_replace('/', '\\', $canonicalStateBase)), '\\');
        $normWs = rtrim(strtolower(str_replace('/', '\\', $canonicalWorkspace)), '\\');
        if (!str_starts_with($normWs, $normState . '\\')) {
            throw new RuntimeException("Workspace path escape detected: {$canonicalWorkspace}");
        }

        // Write owner marker BEFORE any owned data writes
        $ownerMarker = [
            'operation_id' => $operationId,
            'root' => $canonicalRoot,
            'created_at' => time(),
            'pid' => getmypid(),
        ];
        $ownerMarkerFile = $canonicalWorkspace . DIRECTORY_SEPARATOR . self::OWNER_MARKER_FILENAME;
        $this->writeAtomicFile($ownerMarkerFile, json_encode($ownerMarker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'marker');

        // Create subdirectories
        foreach (['sealed', 'staged', 'backups', 'temp'] as $sub) {
            $subDir = $canonicalWorkspace . DIRECTORY_SEPARATOR . $sub;
            if (!@mkdir($subDir, 0700, false)) {
                throw new RuntimeException("Failed to create workspace subdirectory: {$subDir}");
            }
            ProductInventoryService::assertNoReparse($subDir);
        }

        return $canonicalWorkspace;
    }

    /**
     * Compute stable digest for product root.
     */
    private function getRootDigest(string $canonicalRoot): string
    {
        return hash('sha256', strtolower(rtrim(str_replace('/', '\\', $canonicalRoot), '\\')));
    }

    /**
     * Acquire process-owned flock on reusable root lock file.
     *
     * @return resource
     */
    private function acquireRootLock(string $canonicalStateBase, string $rootDigest)
    {
        $locksDir = $this->ensureStateDirectoryComponent($canonicalStateBase, 'locks');

        $lockFile = $locksDir . DIRECTORY_SEPARATOR . $rootDigest . '.lock';
        clearstatcache(true, $lockFile);
        if (file_exists($lockFile) || is_link($lockFile)) {
            ProductInventoryService::assertNoReparse($lockFile);
        }

        $fp = @fopen($lockFile, 'c+b');
        if ($fp === false) {
            throw new RuntimeException("Failed to open lock file: {$lockFile}");
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            throw new RuntimeException("Product root lease busy: another process is actively operating on this product root.");
        }

        return $fp;
    }

    /**
     * Release flock on root lock file.
     *
     * @param resource $fp
     */
    private function releaseRootLock($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Compute active root marker file path.
     */
    private function getActiveMarkerPath(string $canonicalStateBase, string $rootDigest): string
    {
        $markersDir = $this->ensureStateDirectoryComponent($canonicalStateBase, 'active_roots');
        return $markersDir . DIRECTORY_SEPARATOR . $rootDigest . '.json';
    }

    /**
     * Walk down relative path within canonical root segment by segment.
     * Stops at first missing segment without throwing on absent ancestors.
     *
     * @return array{exists: bool, is_dir: bool, is_file: bool, resolved_path: string}
     */
    private function readonlyProductWalk(string $canonicalRoot, string $relPath): array
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
                $fullExpected = $canonicalRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                return [
                    'exists' => false,
                    'is_dir' => false,
                    'is_file' => false,
                    'resolved_path' => $fullExpected,
                ];
            }

            ProductInventoryService::assertNoReparse($next);
            $canonicalNext = ProductInventoryService::resolveAbsolutePath($next, true);
            ProductInventoryService::assertNoReparse($canonicalNext);

            $normRoot = rtrim(strtolower(str_replace('/', '\\', $canonicalRoot)), '\\');
            $normNext = rtrim(strtolower(str_replace('/', '\\', $canonicalNext)), '\\');
            if ($normNext !== $normRoot && !str_starts_with($normNext, $normRoot . '\\')) {
                throw new RuntimeException("Path escapes product root boundary: {$relPath}");
            }

            $current = $canonicalNext;
        }

        return [
            'exists' => true,
            'is_dir' => is_dir($current),
            'is_file' => is_file($current),
            'resolved_path' => $current,
        ];
    }

    /**
     * Safely release active operation marker after validating ownership.
     *
     * @param array{operation_id: string, root: string, workspace: string, created_at: int, pid: int, raw_bytes: string}|null $snapshot
     */
    private function releaseActiveMarker(
        string $markerFile,
        string $expectedOperationId,
        string $canonicalRoot,
        string $expectedWorkspace,
        ?array $snapshot = null
    ): void {
        clearstatcache(true, $markerFile);
        if (!file_exists($markerFile) && !is_link($markerFile)) {
            return;
        }

        // Validate and get snapshot if not provided
        if ($snapshot === null || !isset($snapshot['raw_bytes'])) {
            $snapshot = $this->readAndValidateActiveMarker($markerFile, $expectedOperationId, $canonicalRoot, $expectedWorkspace);
        } else {
            // Still re-validate current marker state
            $current = $this->readAndValidateActiveMarker($markerFile, $expectedOperationId, $canonicalRoot, $expectedWorkspace);
            if ($current['raw_bytes'] !== $snapshot['raw_bytes']) {
                throw new RuntimeException("Active operation marker state changed unexpectedly before release hook: {$markerFile}");
            }
        }

        $this->triggerCheckpoint('before_marker_release', [
            'operation_id' => $expectedOperationId,
            'active_marker_path' => $markerFile,
        ]);

        // Recheck on disk IMMEDIATELY after hook:
        // Do NOT delete if altered or replaced by foreign marker!
        clearstatcache(true, $markerFile);
        if (!file_exists($markerFile) && !is_link($markerFile)) {
            return;
        }

        // Guard against symlink/reparse replacement during hook
        ProductInventoryService::assertNoReparse($markerFile);
        if (is_link($markerFile) || is_dir($markerFile)) {
            throw new RuntimeException("Active operation marker was replaced by non-regular file during release hook: {$markerFile}");
        }

        $afterBytes = $this->readBoundedFile($markerFile, 65536);
        if ($afterBytes !== $snapshot['raw_bytes']) {
            throw new RuntimeException("Refusing to delete active operation marker: marker content altered or replaced during release hook: {$markerFile}");
        }

        // Validate structure again to be absolutely sure
        $afterMarker = json_decode($afterBytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($afterMarker) ||
            ($afterMarker['operation_id'] ?? null) !== $expectedOperationId ||
            ($afterMarker['root'] ?? null) !== $canonicalRoot) {
            throw new RuntimeException("Refusing to delete foreign active operation marker after release hook: {$markerFile}");
        }

        if (!@unlink($markerFile)) {
            throw new RuntimeException("Failed to unlink active operation marker: {$markerFile}");
        }

        clearstatcache(true, $markerFile);
        if (file_exists($markerFile) || is_link($markerFile)) {
            throw new RuntimeException("Active operation marker remains present after unlink: {$markerFile}");
        }
    }

    /**
     * Check if the product root physically matches the exact signed source inventory.
     *
     * @param array<string, mixed> $descriptor
     */
    private function isRootExactSource(string $canonicalRoot, array $descriptor): bool
    {
        try {
            $actualInventory = $this->manifests->buildInventory($canonicalRoot, $descriptor['from_native_version']);
            if ($actualInventory['version'] !== $descriptor['from_version']) {
                return false;
            }
            $actualDigest = ShellDeltaManifestService::canonicalInventoryDigest($actualInventory);
            return $actualDigest === $descriptor['source_inventory_sha256'];
        } catch (Throwable) {
            return false;
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
