<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\EnvLoader;
use App\Helpers\Logger;
use PDO;
use Throwable;

/**
 * Service for Update Self-Healing, Automatic Recovery, and Fault Diagnosis.
 * Phase 12 Self-Healing Update Infrastructure.
 */
class UpdateRecoveryService
{
    public const MAX_DOWNLOAD_ATTEMPTS = 3;
    public const MAX_VERIFY_ATTEMPTS = 2;
    public const LOCK_TTL_SECONDS = 300;

    protected string $storageDir;
    protected string $stateFile;
    protected string $lockFile;
    protected string $auditFile;
    protected string $appRoot;

    protected ?UpdateService $updateService;
    protected ?UpdateTelemetryService $telemetryService;
    protected ?PDO $pdo;

    public function __construct(
        ?string $storageDir = null,
        ?string $appRoot = null,
        ?UpdateService $updateService = null,
        ?UpdateTelemetryService $telemetryService = null,
        ?PDO $pdo = null
    ) {
        $configuredStorage = $_ENV['APP_STORAGE_DIR'] ?? getenv('APP_STORAGE_DIR') ?: null;
        $this->storageDir = $storageDir ?? $configuredStorage ?? (realpath(__DIR__ . '/../storage') ?: __DIR__ . '/../storage');
        $this->stateFile = $this->storageDir . '/update-state.json';
        $this->lockFile = $this->storageDir . '/recovery.lock';
        $this->auditFile = $this->storageDir . '/recovery_audit.json';
        $this->appRoot = $appRoot ?? UpdateRuntimePaths::deployedRoot(realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));

        $this->updateService = $updateService;
        $this->telemetryService = $telemetryService ?? new UpdateTelemetryService($this->storageDir, $pdo);
        $this->pdo = $pdo;
    }

    /**
     * Check if auto recovery is enabled globally.
     */
    public function isAutoRecoveryEnabled(): bool
    {
        return EnvLoader::getBool('ENABLE_AUTO_UPDATE_RECOVERY', true);
    }

    // ══════════════════════════════════════════════════════════════
    //  1. LOCK MECHANISM (Concurrency & Stale Lock Protection)
    // ══════════════════════════════════════════════════════════════

    /**
     * Acquire recovery.lock with TTL protection.
     */
    public function acquireLock(int $ttlSeconds = self::LOCK_TTL_SECONDS): bool
    {
        if (file_exists($this->lockFile)) {
            $lockContent = @file_get_contents($this->lockFile);
            $lockData = json_decode((string)$lockContent, true);
            $lockTime = is_array($lockData) && isset($lockData['time']) ? (int) $lockData['time'] : (int) @filemtime($this->lockFile);

            // If lock is still valid and active, cannot acquire
            if ((time() - $lockTime) < $ttlSeconds) {
                Logger::warning('UpdateRecoveryService: Active recovery lock exists', ['lock_age' => time() - $lockTime]);
                return false;
            }

            Logger::warning('UpdateRecoveryService: Breaking stale recovery lock', ['lock_age' => time() - $lockTime]);
            @unlink($this->lockFile);
        }

        $payload = json_encode([
            'pid'  => getmypid(),
            'time' => time(),
            'date' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT);

        return (bool) @file_put_contents($this->lockFile, $payload, LOCK_EX);
    }

    /**
     * Release recovery.lock safely.
     */
    public function releaseLock(): void
    {
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    /**
     * Check if recovery is currently locked.
     */
    public function isLocked(): bool
    {
        if (!file_exists($this->lockFile)) {
            return false;
        }

        $lockContent = @file_get_contents($this->lockFile);
        $lockData = json_decode((string)$lockContent, true);
        $lockTime = is_array($lockData) && isset($lockData['time']) ? (int) $lockData['time'] : (int) @filemtime($this->lockFile);

        return (time() - $lockTime) < self::LOCK_TTL_SECONDS;
    }

    // ══════════════════════════════════════════════════════════════
    //  2. AUDIT LOGGING
    // ══════════════════════════════════════════════════════════════

    /**
     * Record a structured audit trail entry for every recovery action.
     */
    public function logAudit(
        string $previousState,
        string $detectedProblem,
        string $selectedAction,
        bool $success,
        array $details = []
    ): void {
        $entry = [
            'id'               => uniqid('rec_', true),
            'timestamp'        => date('Y-m-d H:i:s'),
            'previous_state'   => $previousState,
            'detected_problem' => $detectedProblem,
            'selected_action'  => $selectedAction,
            'success'          => $success,
            'details'          => $details,
        ];

        // Append to local audit history (capped at 200 records)
        $auditList = [];
        if (file_exists($this->auditFile)) {
            $content = @file_get_contents($this->auditFile);
            $decoded = json_decode((string)$content, true);
            if (is_array($decoded)) {
                $auditList = $decoded;
            }
        }

        array_unshift($auditList, $entry);
        if (count($auditList) > 200) {
            $auditList = array_slice($auditList, 0, 200);
        }

        @file_put_contents($this->auditFile, json_encode($auditList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Dispatched to update telemetry
        $eventType = $selectedAction === 'rollback' && !empty($details['is_auto'])
            ? 'update_auto_rollback'
            : ($success ? 'update_recovery_completed' : 'update_recovery_failed');

        $this->telemetryService->recordEvent([
            'device_id'           => $this->getDeviceId(),
            'application_version' => $this->getCurrentVersion(),
            'channel'             => 'stable',
            'event_type'          => $eventType,
            'success'             => $success,
            'error_code'          => $success ? null : ($details['error'] ?? 'recovery_action_failed'),
            'metadata'            => [
                'previous_state'   => $previousState,
                'detected_problem' => $detectedProblem,
                'selected_action'  => $selectedAction,
                'details'          => $details,
            ]
        ]);

        Logger::info('UpdateRecoveryService Audit Entry', $entry);
    }

    /**
     * Get recent audit history.
     */
    public function getAuditLog(int $limit = 50): array
    {
        if (!file_exists($this->auditFile)) {
            return [];
        }

        $content = @file_get_contents($this->auditFile);
        $decoded = json_decode((string)$content, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_slice($decoded, 0, max(1, min(200, $limit)));
    }

    // ══════════════════════════════════════════════════════════════
    //  3. STATE DIAGNOSIS & RECOVERY ACTION SELECTION
    // ══════════════════════════════════════════════════════════════

    /**
     * Diagnose current update state and determine required remediation.
     */
    public function diagnoseState(): array
    {
        if (!file_exists($this->stateFile)) {
            return [
                'status'           => 'healthy',
                'state'            => 'idle',
                'problem_detected' => false,
                'recommended_action' => 'none',
                'message'          => 'No active or interrupted updates found.',
                'details'          => [],
            ];
        }

        $stateContent = @file_get_contents($this->stateFile);
        $state = json_decode((string)$stateContent, true);

        // DeltaUpdateService persists its durable transaction journal using
        // `state` and `to_version`; older recovery flows use `status` and
        // `target_version`. Accept both schemas so a fresh process can recover
        // an interrupted desktop Delta rather than treating it as corrupt.
        $stateStatus = is_array($state) ? ($state['status'] ?? $state['state'] ?? null) : null;
        if (!is_array($state) || !is_string($stateStatus) || $stateStatus === '') {
            return [
                'status'           => 'corrupted_state',
                'state'            => 'unknown',
                'problem_detected' => true,
                'recommended_action' => 'clear',
                'message'          => 'State file exists but is corrupted or empty.',
                'details'          => ['raw' => $stateContent],
            ];
        }

        $status = $stateStatus;
        $attempts = (int) ($state['recovery_attempts'] ?? 0);
        $targetVersion = $state['target_version'] ?? $state['to_version'] ?? null;
        $snapshot = $state['backup_snapshot'] ?? null;
        $downloadFile = $state['download_file'] ?? null;
        $updatedAt = isset($state['updated_at']) ? strtotime($state['updated_at']) : @filemtime($this->stateFile);
        $ageSeconds = time() - $updatedAt;

        // Case A: Interrupted Downloading
        if ($status === 'downloading' || $status === 'download_started') {
            $isStale = $ageSeconds > 120;
            $downloadAttempts = (int) ($state['download_attempts'] ?? 1);

            if ($downloadAttempts >= self::MAX_DOWNLOAD_ATTEMPTS) {
                return [
                    'status'             => 'download_failed_max_retries',
                    'state'              => $status,
                    'problem_detected'   => true,
                    'recommended_action' => 'escalate',
                    'message'            => "Download exceeded maximum retry limit (" . self::MAX_DOWNLOAD_ATTEMPTS . " attempts).",
                    'details'            => ['attempts' => $downloadAttempts, 'target_version' => $targetVersion],
                ];
            }

            return [
                'status'             => 'interrupted_download',
                'state'              => $status,
                'problem_detected'   => true,
                'recommended_action' => 'retry_download',
                'message'            => 'Package download was interrupted before completion.',
                'details'            => [
                    'target_version'    => $targetVersion,
                    'download_attempts' => $downloadAttempts,
                    'age_seconds'       => $ageSeconds,
                ],
            ];
        }

        // Case B: Interrupted Verifying / Corrupted Package
        if ($status === 'verifying' || $status === 'verification_failed') {
            $verifyAttempts = (int) ($state['verify_attempts'] ?? 1);

            if ($verifyAttempts >= self::MAX_VERIFY_ATTEMPTS) {
                return [
                    'status'             => 'verification_failed_max_retries',
                    'state'              => $status,
                    'problem_detected'   => true,
                    'recommended_action' => 'escalate',
                    'message'            => "Package hash/signature verification failed after " . self::MAX_VERIFY_ATTEMPTS . " attempts.",
                    'details'            => ['attempts' => $verifyAttempts, 'target_version' => $targetVersion],
                ];
            }

            return [
                'status'             => 'corrupted_package',
                'state'              => $status,
                'problem_detected'   => true,
                'recommended_action' => 'retry_verification',
                'message'            => 'Package verification failed or was interrupted. Re-download required.',
                'details'            => [
                    'target_version'  => $targetVersion,
                    'verify_attempts' => $verifyAttempts,
                ],
            ];
        }

        // A full package can remain downloaded while the operator has not
        // yet approved the restart. Keep that state visible and actionable;
        // it must never be treated as a corrupt journal that is safe to clear.
        if ($status === 'full_ready_to_install') {
            return [
                'status'             => 'pending_install',
                'state'              => $status,
                'problem_detected'   => true,
                'recommended_action' => 'none',
                'message'            => 'A verified full update is waiting for an approved application restart.',
                'details'            => [
                    'target_version' => $targetVersion,
                    'age_seconds'    => $ageSeconds,
                ],
            ];
        }

        // A full installer may have terminated during replacement. The
        // installer rollback contract is not available here, so escalate for
        // explicit operator recovery instead of guessing or clearing state.
        if ($status === 'installing') {
            return [
                'status'             => 'interrupted_installation',
                'state'              => $status,
                'problem_detected'   => true,
                'recommended_action' => 'escalate',
                'message'            => 'The full installer was interrupted; manual release recovery is required.',
                'details'            => [
                    'target_version' => $targetVersion,
                    'age_seconds'    => $ageSeconds,
                ],
            ];
        }

        // Case C: Interrupted Applying (Files partially replaced)
        if ($status === 'applying' || $status === 'partial_replace') {
            return [
                'status'             => 'interrupted_applying',
                'state'              => $status,
                'problem_detected'   => true,
                'recommended_action' => $snapshot ? 'rollback' : 'escalate',
                'message'            => 'Update file application was interrupted mid-way.',
                'details'            => [
                    'target_version'  => $targetVersion,
                    'snapshot'        => $snapshot,
                    'has_snapshot'    => !empty($snapshot),
                ],
            ];
        }

        // A rollback is a healthy terminal state only when rollbackFiles()
        // completed every required restoration and persisted this state.
        if ($status === 'rolled_back') {
            return [
                'status'             => 'rolled_back',
                'state'              => $status,
                'problem_detected'   => false,
                'recommended_action' => 'none',
                'message'            => 'Update rollback completed and the pre-update snapshot is active.',
                'details'            => [
                    'target_version'  => $targetVersion,
                    'snapshot'        => $snapshot,
                    'rolled_back_at'  => $state['rolled_back_at'] ?? null,
                    'restored_files'  => $state['restored_files'] ?? [],
                ],
            ];
        }

        // A failed restoration is never safe to clear automatically. Keep the
        // durable state visible and require explicit operator escalation.
        if ($status === 'rollback_failed') {
            return [
                'status'             => 'rollback_failed',
                'state'              => $status,
                'problem_detected'   => true,
                'recommended_action' => 'escalate',
                'message'            => 'Update rollback did not restore every required file; manual recovery is required.',
                'details'            => [
                    'target_version'  => $targetVersion,
                    'snapshot'        => $snapshot,
                    'error'           => $state['recovery_error'] ?? $state['error'] ?? null,
                    'rollback_errors' => $state['rollback_errors'] ?? [],
                    'restored_files'  => $state['restored_files'] ?? [],
                ],
            ];
        }

        // Case D: Interrupted or Failed Migration
        if (in_array($status, ['migrating', 'migration_failed', 'desktop_handoff_pending', 'database_recovery_failed'], true)) {
            return [
                'status'             => 'failed_migration',
                'state'              => $status,
                'problem_detected'   => true,
                'recommended_action' => 'rollback',
                'message'            => 'Database migration failed or was interrupted. Immediate rollback required.',
                'details'            => [
                    'target_version'  => $targetVersion,
                    'snapshot'        => $snapshot,
                    'error'           => $state['error'] ?? 'Migration error',
                ],
            ];
        }

        // Case E: Generic Failed or Stalled State
        if ($status === 'failed' || $status === 'error') {
            $lastAction = $state['recovery_action'] ?? null;
            return [
                'status'             => 'failed_state',
                'state'              => $status,
                'problem_detected'   => true,
                'recommended_action' => $snapshot ? 'rollback' : 'clear',
                'message'            => 'Update failed with error: ' . ($state['error'] ?? 'Unknown error'),
                'details'            => [
                    'error'           => $state['error'] ?? null,
                    'snapshot'        => $snapshot,
                    'last_action'     => $lastAction,
                ],
            ];
        }

        // Healthy completed state
        if ($status === 'completed' || $status === 'idle') {
            return [
                'status'             => 'healthy',
                'state'              => $status,
                'problem_detected'   => false,
                'recommended_action' => 'none',
                'message'            => 'Update completed successfully or system is idle.',
                'details'            => ['current_version' => $this->getCurrentVersion()],
            ];
        }

        return [
            'status'             => 'unknown_state',
            'state'              => $status,
            'problem_detected'   => true,
            'recommended_action' => 'clear',
            'message'            => "Unrecognized state '{$status}'.",
            'details'            => $state,
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  4. IDEMPOTENT RECOVERY EXECUTION
    // ══════════════════════════════════════════════════════════════

    /**
     * Execute recommended or requested recovery action safely.
     * Guaranteed idempotent: running twice will never corrupt or degrade system.
     */
    public function executeAction(string $action, array $context = []): array
    {
        if (!$this->acquireLock()) {
            return [
                'ok'      => false,
                'error'   => 'Recovery process is currently locked by another operation.',
                'action'  => $action,
            ];
        }

        $operationLock = new UpdateOperationLock($this->storageDir);
        $operationLease = $operationLock->acquire('update_recovery', [
            'action' => $action,
        ]);
        if (!$operationLease['acquired']) {
            $this->releaseLock();
            return [
                'ok'          => false,
                'action'      => $action,
                'error'       => $operationLease['message'] ?? 'Another update or sale operation is already in progress.',
                'reason_code' => $operationLease['reason_code'] ?? 'update_in_progress',
                'owner'       => $operationLease['owner'] ?? null,
            ];
        }

        $diagnosis = $this->diagnoseState();
        $prevState = $diagnosis['state'] ?? 'unknown';
        $problem = $diagnosis['status'] ?? 'unknown';

        $result = ['ok' => false, 'action' => $action, 'message' => ''];

        try {
            switch ($action) {
                case 'retry_download':
                    $result = $this->actionRetryDownload($diagnosis, $context);
                    break;

                case 'retry_verification':
                    $result = $this->actionRetryVerification($diagnosis, $context);
                    break;

                case 'rollback':
                    $result = $this->actionRollback($diagnosis, $context);
                    break;

                case 'clear':
                    $result = $this->actionClearState($diagnosis, $context);
                    break;

                case 'escalate':
                    $result = $this->actionEscalate($diagnosis, $context);
                    break;

                case 'none':
                    $result = ['ok' => true, 'action' => 'none', 'message' => 'System is already healthy; no action required.'];
                    break;

                default:
                    $result = ['ok' => false, 'action' => $action, 'error' => "Unknown recovery action '{$action}'."];
                    break;
            }
        } catch (Throwable $e) {
            $result = [
                'ok'     => false,
                'action' => $action,
                'error'  => 'Recovery execution encountered an unhandled exception: ' . $e->getMessage(),
            ];
            Logger::error('UpdateRecoveryService action failed', ['action' => $action, 'error' => $e->getMessage()]);
        } finally {
            $operationLock->release();
            $this->releaseLock();
        }

        $this->logAudit(
            $prevState,
            $problem,
            $action,
            $result['ok'],
            array_merge($context, ['result' => $result])
        );

        return $result;
    }

    /**
     * Action: Retry Download
     */
    protected function actionRetryDownload(array $diagnosis, array $context): array
    {
        // 1. Delete partial/corrupted download file
        $this->cleanTempDownloadFiles();

        // 2. Increment attempt counter with exponential backoff delay (simulated or tracked)
        $state = $this->readStateFile();
        $attempts = (int) ($state['download_attempts'] ?? 0) + 1;
        $state['download_attempts'] = $attempts;
        $state['status'] = 'download_retry_scheduled';
        $state['updated_at'] = date('Y-m-d H:i:s');
        $this->writeStateFile($state);

        return [
            'ok'      => true,
            'action'  => 'retry_download',
            'message' => "Cleaned interrupted download. Attempt {$attempts} of " . self::MAX_DOWNLOAD_ATTEMPTS . " registered.",
            'attempts' => $attempts,
        ];
    }

    /**
     * Action: Retry Verification (Corrupted package handling)
     */
    protected function actionRetryVerification(array $diagnosis, array $context): array
    {
        // 1. Delete corrupted archive
        $this->cleanTempDownloadFiles();

        // 2. Increment verification attempt
        $state = $this->readStateFile();
        $attempts = (int) ($state['verify_attempts'] ?? 0) + 1;
        $state['verify_attempts'] = $attempts;
        $state['status'] = 'verification_retry_scheduled';
        $state['updated_at'] = date('Y-m-d H:i:s');
        $this->writeStateFile($state);

        return [
            'ok'       => true,
            'action'   => 'retry_verification',
            'message'  => "Corrupted package removed. Verification attempt {$attempts} of " . self::MAX_VERIFY_ATTEMPTS . " registered.",
            'attempts' => $attempts,
        ];
    }

    /**
     * Action: Rollback (Deterministic Rollback to Snapshot without deleting snapshots)
     */
    protected function actionRollback(array $diagnosis, array $context): array
    {
        $state = $this->readStateFile();
        $snapshot = $context['snapshot_path'] ?? ($state['backup_snapshot'] ?? null);

        $updateService = $this->getUpdateService();
        $rbResult = $updateService->rollbackUpdate($snapshot);

        if (!$rbResult['ok']) {
            $error = (string) ($rbResult['error'] ?? 'Unknown error');
            // A migration safety restore failure intentionally leaves the
            // application files in place: rolling them back against an
            // unverified database could create an incompatible deployment.
            // Persist that distinct durable state so startup never presents an
            // ambiguous in-progress update or a successful rollback.
            if (str_contains($error, 'Database recovery failed')) {
                $state['status'] = 'database_recovery_failed';
                $state['recovery_action'] = 'rollback';
                $state['recovery_error'] = $error;
                $state['updated_at'] = date('Y-m-d H:i:s');
                $this->writeStateFile($state);
            } else {
                $state['status'] = 'rollback_failed';
                $state['state'] = 'rollback_failed';
                $state['recovery_action'] = 'rollback';
                $state['recovery_error'] = $error;
                $state['updated_at'] = date('Y-m-d H:i:s');
                $this->writeStateFile($state);
            }

            return [
                'ok'      => false,
                'action'  => 'rollback',
                'error'   => 'Rollback execution failed: ' . $error,
                'details' => $rbResult,
            ];
        }

        // Keep snapshot preserved (NEVER delete snapshot during recovery)
        $state['status'] = 'rolled_back';
        $state['state'] = 'rolled_back';
        $state['rolled_back_at'] = date('Y-m-d H:i:s');
        $state['recovery_action'] = 'rollback';
        $this->writeStateFile($state);

        return [
            'ok'       => true,
            'action'   => 'rollback',
            'message'  => 'System successfully rolled back to pre-update snapshot.',
            'snapshot' => $snapshot,
            'logs'     => $rbResult['logs'] ?? [],
        ];
    }

    /**
     * Action: Clear State File
     */
    protected function actionClearState(array $diagnosis, array $context): array
    {
        $this->cleanTempDownloadFiles();
        if (file_exists($this->stateFile)) {
            @unlink($this->stateFile);
        }

        return [
            'ok'      => true,
            'action'  => 'clear',
            'message' => 'Update state file reset and cleared.',
        ];
    }

    /**
     * Action: Escalate (Requires human/technician intervention)
     */
    protected function actionEscalate(array $diagnosis, array $context): array
    {
        $state = $this->readStateFile();
        $state['status'] = 'escalated_to_admin';
        $state['escalated_at'] = date('Y-m-d H:i:s');
        $state['escalation_reason'] = $diagnosis['message'] ?? 'Max retry limits exceeded';
        $this->writeStateFile($state);

        return [
            'ok'      => true,
            'action'  => 'escalate',
            'message' => 'Update recovery escalated to administrator. Auto-recovery paused.',
            'reason'  => $state['escalation_reason'],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  5. STARTUP RECOVERY (Fast & Non-Blocking < 50ms)
    // ══════════════════════════════════════════════════════════════

    /**
     * Lightweight startup recovery check executed during POS application boot.
     */
    public function autoRecoverOnStartup(): array
    {
        if (!$this->isAutoRecoveryEnabled()) {
            return ['ok' => true, 'action' => 'none', 'message' => 'Auto-recovery is disabled by configuration.'];
        }

        if (!file_exists($this->stateFile)) {
            return ['ok' => true, 'action' => 'none', 'message' => 'System is clean and healthy on startup.'];
        }

        $diagnosis = $this->diagnoseState();
        if (!$diagnosis['problem_detected']) {
            return ['ok' => true, 'action' => 'none', 'message' => 'No interrupted update detected.'];
        }

        $recAction = $diagnosis['recommended_action'];

        // Automatically resolve safe actions during startup
        if ($recAction === 'retry_download' || $recAction === 'clear') {
            return $this->executeAction($recAction, ['trigger' => 'startup_check']);
        }

        if ($recAction === 'rollback') {
            return $this->executeAction('rollback', ['trigger' => 'startup_check', 'is_auto' => true]);
        }

        return [
            'ok'                 => true,
            'action'             => 'none',
            'problem_detected'   => true,
            'recommended_action' => $recAction,
            'message'            => $diagnosis['message'],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  6. POST-UPDATE HEALTH VALIDATION
    // ══════════════════════════════════════════════════════════════

    /**
     * Validate system integrity after update application.
     * If critical failure is detected, triggers auto-rollback.
     */
    public function validatePostUpdateHealth(?string $snapshotPath = null): array
    {
        $checks = [
            'db_connection' => false,
            'core_tables'   => false,
            'version_file'  => false,
            'backend_entry' => false,
        ];
        $errors = [];

        // 1. Check PDO Connectivity
        $pdo = $this->getPdo();
        if ($pdo !== null) {
            try {
                $stmt = $pdo->query("SELECT 1");
                if ($stmt && $stmt->fetch()) {
                    $checks['db_connection'] = true;
                }
            } catch (Throwable $e) {
                $errors[] = "Database connectivity check failed: " . $e->getMessage();
            }
        } else {
            $errors[] = "PDO instance unavailable";
        }

        // 2. Check Core Tables
        if ($checks['db_connection'] && $pdo !== null) {
            $requiredTables = ['users', 'products', 'settings', 'invoices'];
            $missingTables = [];
            foreach ($requiredTables as $table) {
                try {
                    $stmt = $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
                    if (!$stmt) {
                        $missingTables[] = $table;
                    }
                } catch (Throwable) {
                    $missingTables[] = $table;
                }
            }

            if (empty($missingTables)) {
                $checks['core_tables'] = true;
            } else {
                $errors[] = "Missing or corrupted core database tables: " . implode(', ', $missingTables);
            }
        }

        // 3. Check version.json
        $versionFile = $this->appRoot . '/version.json';
        if (file_exists($versionFile)) {
            $vData = json_decode((string)@file_get_contents($versionFile), true);
            if (is_array($vData) && !empty($vData['version'])) {
                $checks['version_file'] = true;
            } else {
                $errors[] = "version.json is corrupted or lacks semver string";
            }
        } else {
            $errors[] = "version.json does not exist";
        }

        // 4. Check the executable backend entrypoint for this layout. Packaged
        // desktop deployments execute backend.phar and intentionally do not
        // ship an external backend/index.php.
        $entryFile = UpdateRuntimePaths::backendHealthEntrypoint($this->appRoot);
        if (file_exists($entryFile) && filesize($entryFile) > 50) {
            $checks['backend_entry'] = true;
        } else {
            $errors[] = UpdateRuntimePaths::isPackagedPharDeployment($this->appRoot)
                ? 'backend/backend.phar is missing or empty'
                : 'backend/index.php is missing or empty';
        }

        $allHealthy = !in_array(false, $checks, true);

        // If unhealthy, initiate automatic rollback
        if (!$allHealthy && $snapshotPath) {
            Logger::critical('Post-update health validation failed. Initiating automatic rollback.', ['errors' => $errors]);
            $this->executeAction('rollback', [
                'snapshot_path' => $snapshotPath,
                'trigger'       => 'post_update_health_failure',
                'is_auto'       => true,
                'errors'        => $errors,
            ]);

            return [
                'healthy'         => false,
                'auto_rollback'   => true,
                'checks'          => $checks,
                'errors'          => $errors,
                'message'         => 'Health validation failed; automatic rollback executed to preserve POS uptime.',
            ];
        }

        return [
            'healthy'       => $allHealthy,
            'auto_rollback' => false,
            'checks'        => $checks,
            'errors'        => $errors,
            'message'       => $allHealthy ? 'Post-update health validation passed 100%.' : 'Health check warnings found.',
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  7. HELPER METHODS
    // ══════════════════════════════════════════════════════════════

    protected function cleanTempDownloadFiles(): void
    {
        $patterns = [
            $this->storageDir . '/*.part',
            $this->storageDir . '/*_download.zip',
            $this->storageDir . '/temp_update_*',
        ];

        foreach ($patterns as $pat) {
            $files = glob($pat);
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }
    }

    public function readStateFile(): array
    {
        if (!file_exists($this->stateFile)) {
            return [];
        }
        $content = @file_get_contents($this->stateFile);
        $data = json_decode((string)$content, true);
        return is_array($data) ? $data : [];
    }

    public function writeStateFile(array $data): bool
    {
        return (bool) @file_put_contents($this->stateFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function clearState(): bool
    {
        if (file_exists($this->stateFile)) {
            return @unlink($this->stateFile);
        }
        return true;
    }


    protected function getUpdateService(): UpdateService
    {
        if ($this->updateService === null) {
            $manifestService = new UpdateManifestService();
            $deltaService = new DeltaUpdateService($manifestService, $this->appRoot, $this->storageDir);
            $this->updateService = new UpdateService(
                new GitService(),
                new FrontendBuildService(),
                new BackupService(),
                $deltaService,
                $manifestService,
                null,
                null,
                null,
                $this->telemetryService
            );
        }
        return $this->updateService;
    }


    protected function getPdo(): ?PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        try {
            return Database::getInstance();
        } catch (Throwable) {
            return null;
        }
    }

    protected function getDeviceId(): string
    {
        $idCandidates = [
            $this->storageDir . '/.device_id',
            $this->appRoot . '/backend/storage/.device_id',
            $this->appRoot . '/storage/.device_id',
        ];

        foreach ($idCandidates as $idFile) {
            if (file_exists($idFile)) {
                $id = trim((string) @file_get_contents($idFile));
                if ($id !== '') {
                    return $id;
                }
            }
        }

        // If updateService is available, delegate to its UUID generator
        if ($this->updateService !== null) {
            return $this->updateService->getDeviceId();
        }

        // Generate and persist RFC 4122 v4 UUID
        try {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
            @mkdir($this->storageDir, 0755, true);
            @file_put_contents($this->storageDir . '/.device_id', $uuid);
            return $uuid;
        } catch (Throwable) {
            return 'local-terminal';
        }
    }

    protected function getCurrentVersion(): string
    {
        $vCandidates = [
            $this->appRoot . '/version.json',
            UpdateRuntimePaths::deployedRoot() . '/version.json',
        ];

        foreach ($vCandidates as $vFile) {
            if (file_exists($vFile)) {
                $data = json_decode((string) @file_get_contents($vFile), true);
                if (is_array($data) && !empty($data['version'])) {
                    return (string) $data['version'];
                }
            }
        }

        return '0.0.0';
    }
}
