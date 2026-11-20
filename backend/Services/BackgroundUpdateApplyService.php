<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\EnvLoader;
use App\Helpers\JobFailure;
use App\Helpers\JobQueue;
use App\Helpers\Logger;
use App\Services\UpdateOperationLock;
use App\Services\UpdateService;

class BackgroundUpdateApplyService
{
    protected UpdateService $updateService;
    protected string $storageDir;

    public function __construct(UpdateService $updateService, ?string $storageDir = null)
    {
        $this->updateService = $updateService;
        $this->storageDir = $storageDir ?? $this->updateService->getDeltaUpdateService()->getStorageDir();
    }

    public function getUpdateService(): UpdateService
    {
        return $this->updateService;
    }

    /**
     * Coordinate scheduling of background apply job.
     * Ensures:
     * - Automatic updates enabled
     * - No active update lock / state
     * - Immutable payload with operation_id
     * - At most one apply_update job in pending/processing
     * - No post-dispatch row payload mutation
     * - Never leaks internal scheduling exceptions to HTTP
     */
    public function scheduleApply(bool $force = false, bool $deltaCapable = true, ?int $userId = null, ?string $shellVersion = null): array
    {
        if (!EnvLoader::getBool('ENABLE_AUTO_UPDATE', true)) {
            return [
                'ok'    => false,
                'error' => 'التحديث التلقائي معطل. الرجاء تفعيله من ملف .env (ENABLE_AUTO_UPDATE=true)',
                'code'  => 403,
            ];
        }

        $state = $this->updateService->getDeltaUpdateService()->getUpdateState();
        if (UpdateOperationLock::isActiveState($state)) {
            return [
                'ok'     => false,
                'error'  => 'هناك عملية تحديث قيد التنفيذ بالفعل.',
                'code'   => 409,
                'status' => 'busy',
            ];
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                "SELECT id, status, payload
                 FROM job_queue
                 WHERE job_name = 'apply_update'
                   AND status IN ('pending', 'processing')
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $stmt->execute();
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                $existingPayload = json_decode((string) $existing['payload'], true);
                if (($existingPayload['shell_version'] ?? null) !== $shellVersion) {
                    return ['ok' => false, 'code' => 409, 'status' => 'busy', 'error' => 'هناك مهمة تحديث أخرى قيد التنفيذ.'];
                }
                return [
                    'ok'      => true,
                    'job_id'  => (int) $existing['id'],
                    'status'  => (string) $existing['status'],
                    'message' => 'هناك عملية تطبيق تحديث مسجلة بالفعل في قائمة الانتظار.',
                ];
            }

            $operationId = bin2hex(random_bytes(16));
            $payload = [
                'operation_id'  => $operationId,
                'force'         => $force,
                'delta_capable' => $deltaCapable,
                'user_id'       => $userId,
                'created_at'    => date('Y-m-d\TH:i:sP'),
            ];
            if ($shellVersion !== null) {
                $payload['shell_version'] = $shellVersion;
            }

            $jobId = JobQueue::dispatchUnique('apply_update', $payload, 10, 1);

            // Verify whether dispatchUnique returned our new job or an existing job
            $row = null;
            try {
                $verifyStmt = $db->prepare('SELECT id, payload, status FROM job_queue WHERE id = ? LIMIT 1');
                $verifyStmt->execute([$jobId]);
                $row = $verifyStmt->fetch(\PDO::FETCH_ASSOC);
            } catch (\Throwable $queryEx) {
                // Post-dispatch query failure must return admitted job_id
                Logger::warning('Post-dispatch verification query failed, returning admitted job_id', [
                    'job_id'    => $jobId,
                    'exception' => $queryEx->getMessage(),
                ]);
                return [
                    'ok'      => true,
                    'job_id'  => $jobId,
                    'status'  => 'pending',
                    'message' => 'تمت جدولة تطبيق التحديث في الخلفية بنجاح.',
                ];
            }

            $rowPayload = [];
            if ($row && !empty($row['payload'])) {
                $rowPayload = json_decode((string) $row['payload'], true) ?? [];
            }

            if (($rowPayload['operation_id'] ?? null) === $operationId) {
                // Newly created job: worker alone initializes and settles the per-job file.
                // Do NOT write any job file here; queue row is sufficient pending identity.
                try {
                    Logger::info('Background update apply job dispatched', [
                        'job_id'        => $jobId,
                        'operation_id'  => $operationId,
                        'user_id'       => $userId,
                        'delta_capable' => $deltaCapable,
                    ]);
                } catch (\Throwable) {
                    // Logging failure must not derail successful dispatch
                }

                return [
                    'ok'      => true,
                    'job_id'  => $jobId,
                    'status'  => 'pending',
                    'message' => 'تمت جدولة تطبيق التحديث في الخلفية بنجاح.',
                ];
            }

            // Existing job returned by dispatchUnique: retain ORIGINAL immutable payload and file
            if (($rowPayload['shell_version'] ?? null) !== $shellVersion) {
                return ['ok' => false, 'code' => 409, 'status' => 'busy', 'error' => 'هناك مهمة تحديث أخرى قيد التنفيذ.'];
            }
            return [
                'ok'      => true,
                'job_id'  => $jobId,
                'status'  => (string) ($row['status'] ?? 'pending'),
                'message' => 'هناك عملية تطبيق تحديث مسجلة بالفعل في قائمة الانتظار.',
            ];
        } catch (\Throwable $e) {
            Logger::error('Failed to schedule background update apply', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            return [
                'ok'    => false,
                'error' => 'تعذر بدء عملية التحديث. يرجى مراجعة سجلات النظام.',
                'code'  => 500,
            ];
        }
    }

    /**
     * Worker execution: invoked by JobQueue CLI worker process.
     * Accepts payload and explicit claimed jobId passed from JobQueue::processNext.
     */
    public function processWorkerJob(array $payload, ?int $jobId = null): void
    {
        // Explicit claimed jobId from caller takes precedence; fallback to immutable payload job_id if legacy
        $actualJobId = $jobId ?? (isset($payload['job_id']) ? (int) $payload['job_id'] : null);
        $operationId = (string) ($payload['operation_id'] ?? '');
        $force = (bool) ($payload['force'] ?? false);
        $deltaCapable = (bool) ($payload['delta_capable'] ?? true);
        $shellVersion = $payload['shell_version'] ?? null;

        if ($actualJobId !== null && $actualJobId > 0) {
            $this->saveJobData($actualJobId, [
                'job_id'       => $actualJobId,
                'operation_id' => $operationId,
                'status'       => 'processing',
                'updated_at'   => date('Y-m-d\TH:i:sP'),
            ]);
        }

        try {
            if ($shellVersion !== null && !ShellDeltaDeliveryService::isVersionSelector($shellVersion)) {
                throw new JobFailure('Invalid queued shell update version.', 400);
            }
            $ownerOperation = $operationId !== '' ? $operationId : null;
            $result = $shellVersion === null
                ? $this->updateService->applyUpdate($force, $deltaCapable, $ownerOperation)
                : $this->updateService->applySelectedShellUpdate($shellVersion, $deltaCapable, $ownerOperation);
            $sanitizedResult = $this->sanitizeResult($result);

            $saved = true;
            if ($actualJobId !== null && $actualJobId > 0) {
                $finalStatus = ($result['ok'] ?? false) ? 'completed' : 'failed';
                $saved = $this->saveJobData($actualJobId, [
                    'job_id'       => $actualJobId,
                    'operation_id' => $operationId,
                    'status'       => $finalStatus,
                    'result'       => $sanitizedResult,
                    'completed_at' => date('Y-m-d\TH:i:sP'),
                ]);
            }

            if (!$saved) {
                // Staged files or handoff prepared, but persistence failed; fail queue job so it never implies safe retry/success
                throw new JobFailure('تعذر حفظ نتيجة عملية التحديث المعتمدة للمهمة.', 500);
            }

            if (!($result['ok'] ?? false)) {
                throw new JobFailure(
                    self::redactPaths((string) ($result['error'] ?? 'Update failed')),
                    (int) ($result['code'] ?? 500)
                );
            }
        } catch (\Throwable $e) {
            if ($actualJobId !== null && $actualJobId > 0) {
                $isPersistenceFailure = str_contains($e->getMessage(), 'تعذر حفظ نتيجة عملية التحديث');
                $this->saveJobData($actualJobId, [
                    'job_id'           => $actualJobId,
                    'operation_id'     => $operationId,
                    'status'           => 'failed',
                    'settlement_error' => $isPersistenceFailure ? 'persistence_failure_after_apply' : null,
                    'result'           => [
                        'ok'    => false,
                        'error' => self::redactPaths($e->getMessage()),
                    ],
                    'completed_at'     => date('Y-m-d\TH:i:sP'),
                ]);
            }
            throw $e;
        }
    }

    /**
     * Status endpoint reader for GET /api/update/jobs/{id}.
     * Returns sanitized, job-correlated progress and result without path leakage.
     * Exposes progress and update_state ONLY when the active operation matches this job's operation marker.
     */
    public function getJobStatus(int $jobId): ?array
    {
        $job = JobQueue::find($jobId);
        if (!$job || $job['job_name'] !== 'apply_update') {
            return null;
        }

        $jobData = $this->readJobData($jobId);

        // Determine the immutable operationId for this job strictly from row payload
        $payload = [];
        if (!empty($job['payload'])) {
            $payload = is_array($job['payload']) ? $job['payload'] : (json_decode((string) $job['payload'], true) ?? []);
        }
        $jobOperationId = (string) ($payload['operation_id'] ?? '');

        $state = $this->updateService->getDeltaUpdateService()->getUpdateState();

        // Operation identity binding: expose active update_state and progress ONLY if this job owns the active operation
        $isOwnedActiveOperation = false;
        if ($jobOperationId !== '' && is_array($state) && isset($state['operation_id']) && is_string($state['operation_id'])) {
            $isOwnedActiveOperation = hash_equals($state['operation_id'], $jobOperationId);
        }

        $progress = null;
        $exposedState = null;
        if ($isOwnedActiveOperation && is_array($state)) {
            $exposedState = $state['state'] ?? null;
            if ($exposedState === 'downloading') {
                $progress = [
                    'downloaded_bytes' => max(0, (int) ($state['download_bytes'] ?? 0)),
                    'total_bytes'      => max(0, (int) ($state['total_bytes'] ?? 0)),
                    'download_attempt' => max(1, (int) ($state['download_attempt'] ?? 1)),
                ];
            }
        }

        // Validate completed result: fail-closed if job claims completed without a matching persisted successful result
        $effectiveStatus = (string) $job['status'];
        $resultData = null;
        $settlementError = null;

        if ($effectiveStatus === 'completed') {
            $persistedResult = $jobData['result'] ?? null;
            $persistedOpId = (string) ($jobData['operation_id'] ?? '');
            $persistedJobId = isset($jobData['job_id']) ? (int) $jobData['job_id'] : null;

            if ($jobOperationId !== '') {
                // Background job: require non-empty exact operation ID, matching job_id, and ok===true
                $isMatchingOperation = ($persistedOpId !== '' && hash_equals($jobOperationId, $persistedOpId));
                $isMatchingJobId = ($persistedJobId !== null && $persistedJobId === $jobId);
                $isResultOk = is_array($persistedResult) && ($persistedResult['ok'] ?? false) === true;

                if (!$isMatchingOperation || !$isMatchingJobId || !$isResultOk) {
                    // Completed missing/unbound result is UNKNOWN/needs-attention, NOT definitively failed safe-to-retry
                    $effectiveStatus = 'needs_attention';
                    $settlementError = !$isMatchingOperation ? 'unbound_operation' : (!$isMatchingJobId ? 'unbound_job_id' : 'missing_verified_result');
                } else {
                    $payloadData = is_array($persistedResult['data'] ?? null) ? $persistedResult['data'] : [];
                    // CRITICAL: Strip any nested desktop_handoff_completed claim before derivation
                    unset($payloadData['desktop_handoff_completed']);
                    $payloadData['ok'] = true;

                    // Bound native completion attestation
                    $persistedRequiresHandoff = ($persistedResult['data']['requires_desktop_handoff'] ?? null) === true;
                    $targetHandoffVersion = isset($payloadData['handoff_version']) && is_string($payloadData['handoff_version'])
                        ? trim($payloadData['handoff_version'])
                        : '';

                    if ($persistedRequiresHandoff && $targetHandoffVersion !== '') {
                        $handoffOperation = ($payloadData['update_type'] ?? null) === ShellDeltaManifestService::OPERATION
                            ? ShellDeltaManifestService::OPERATION : 'electron_delta_install';
                        $stateErrorAbsent = !isset($state['error']) || $state['error'] === null || $state['error'] === '';
                        $noRecoveryMarkers = empty($state['primary_error'])
                            && empty($state['secondary_errors'])
                            && empty($state['requires_recovery'])
                            && empty($state['recovery_in_progress'])
                            && empty($state['rollback'])
                            && empty($state['rolled_back'])
                            && empty($state['rollback_in_progress'])
                            && empty($state['db_restore_error'])
                            && empty($state['failed'])
                            && (($state['reason_code'] ?? null) !== 'recovery_required');

                        $stateToVersion = isset($state['to_version']) ? (string) $state['to_version'] : null;
                        $stateTargetVersion = isset($state['target_version']) ? (string) $state['target_version'] : null;
                        $hasConflictingTargets = ($stateToVersion !== null && $stateTargetVersion !== null && $stateToVersion !== $stateTargetVersion);

                        $toVersionMatches = ($stateToVersion === null || $stateToVersion === $targetHandoffVersion);
                        $targetVersionMatches = ($stateTargetVersion === null || $stateTargetVersion === $targetHandoffVersion);
                        $hasTargetInState = ($stateToVersion !== null || $stateTargetVersion !== null);
                        $targetsMatch = $toVersionMatches && $targetVersionMatches && $hasTargetInState && !$hasConflictingTargets;

                        if (
                            $isOwnedActiveOperation
                            && is_array($state)
                            && ($state['state'] ?? null) === 'completed'
                            && ($state['operation'] ?? null) === $handoffOperation
                            && ($state['phase'] ?? null) === 'completed'
                            && $stateErrorAbsent
                            && $noRecoveryMarkers
                            && $targetsMatch
                        ) {
                            $localVersionData = $this->updateService->getLocalVersion();
                            $installedVersion = isset($localVersionData['version']) ? (string) $localVersionData['version'] : '';

                            if ($installedVersion !== '' && $installedVersion === $targetHandoffVersion) {
                                $payloadData['desktop_handoff_completed'] = true;
                                $payloadData['requires_desktop_handoff'] = false;
                            }
                        }
                    }

                    $resultData = $payloadData;
                }
            } else {
                // Legacy job: preserve old synchronous behavior
                if (is_array($persistedResult) && ($persistedResult['ok'] ?? false) === true) {
                    $payloadData = is_array($persistedResult['data'] ?? null) ? $persistedResult['data'] : [];
                    unset($payloadData['desktop_handoff_completed']);
                    $payloadData['ok'] = true;
                    $resultData = $payloadData;
                }
            }
        } elseif ($effectiveStatus === 'failed') {
            $lastErr = (string) ($job['last_error'] ?? ($jobData['result']['error'] ?? ''));
            $isHandoffOrPersistence = ($exposedState === 'desktop_handoff_pending')
                || (($jobData['result']['data']['requires_desktop_handoff'] ?? false) === true)
                || str_contains($lastErr, 'تعذر حفظ نتيجة عملية التحديث')
                || (($jobData['settlement_error'] ?? null) === 'persistence_failure_after_apply');

            if ($isHandoffOrPersistence) {
                $effectiveStatus = 'needs_attention';
                $settlementError = (($jobData['settlement_error'] ?? null) === 'persistence_failure_after_apply' || str_contains($lastErr, 'تعذر حفظ نتيجة'))
                    ? 'persistence_failure_after_apply'
                    : 'desktop_handoff_uncertain';
            } else {
                $failedData = is_array($jobData['result']['data'] ?? null) ? $jobData['result']['data'] : null;
                if (is_array($failedData)) {
                    unset($failedData['ok']);
                    unset($failedData['desktop_handoff_completed']);
                }
                $resultData = $failedData;
            }
        }

        if ($effectiveStatus === 'completed' && $isOwnedActiveOperation && is_array($state) && is_array($resultData)) {
            $recoverySettlement = $this->recoveredShellSettlement($state, $resultData);
            if ($recoverySettlement !== null) {
                $effectiveStatus = 'failed';
                $settlementError = $recoverySettlement;
                $resultData['requires_desktop_handoff'] = false;
                unset($resultData['ok'], $resultData['desktop_handoff_completed']);
            }
        }

        $sanitizedLastError = null;
        if (!empty($job['last_error'])) {
            $sanitizedLastError = self::redactPaths((string) $job['last_error']);
        } elseif ($effectiveStatus === 'failed' && isset($jobData['result']['error'])) {
            $sanitizedLastError = self::redactPaths((string) $jobData['result']['error']);
        } elseif ($settlementError !== null) {
            $sanitizedLastError = match ($settlementError) {
                'persistence_failure_after_apply' => 'تعذر حفظ نتيجة العملية بعد تطبيق التحديث؛ النظام في حالة غير مؤكدة وتحتاج فحصاً يدوياً.',
                'shell_rolled_back'               => 'تعذر تشغيل التحديث، وتم التحقق من عودة الإصدار السابق تلقائياً.',
                'shell_source_reopened'           => 'لم يكتمل إيقاف الخدمات للتحديث، وتمت إعادة تشغيل الإصدار السابق دون استبدال ملفاته.',
                'desktop_handoff_uncertain'       => 'فشل التحديث أثناء مرحلة تسليم سطح المكتب؛ النظام في حالة غير مؤكدة.',
                'unbound_operation'               => 'معرّف العملية غير متطابق مع المهمة المجدولة؛ تم إيقاف المتابعة تلقائياً.',
                'unbound_job_id'                  => 'رقم المهمة غير متطابق مع ملف النتيجة؛ تم إيقاف المتابعة تلقائياً.',
                default                           => 'اكتملت المهمة في قائمة الانتظار ولكن تعذر التحقق من ملف النتيجة المعتمد. يرجى فحص حالة النظام قبل المحاولة مجدداً.',
            };
        }

        return [
            'id'               => $job['id'],
            'job_name'         => 'apply_update',
            'status'           => $effectiveStatus,
            'attempts'         => (int) $job['attempts'],
            'max_attempts'     => (int) $job['max_attempts'],
            'last_error'       => $sanitizedLastError,
            'failure_code'     => $job['failure_code'] !== null ? (int) $job['failure_code'] : null,
            'settlement_error' => $settlementError,
            'created_at'       => $job['created_at'],
            'completed_at'     => $job['completed_at'],
            'progress'         => $progress,
            'update_state'     => $exposedState,
            'result'           => $resultData,
        ];
    }

    private function recoveredShellSettlement(array $state, array $result): ?string
    {
        $terminal = $state['state'] ?? null;
        $source = $state['from_version'] ?? null;
        $target = $result['handoff_version'] ?? null;
        if (($result['update_type'] ?? null) !== ShellDeltaManifestService::OPERATION ||
            ($result['requires_desktop_handoff'] ?? null) !== true ||
            ($state['operation'] ?? null) !== ShellDeltaManifestService::OPERATION ||
            !in_array($terminal, ['rolled_back', 'failed'], true) ||
            ($state['phase'] ?? null) !== $terminal ||
            ($state['requires_recovery'] ?? null) !== false ||
            ($state['source_runtime_recovered'] ?? null) !== true ||
            !is_string($state['shell_transaction_id'] ?? null) ||
            !preg_match('/^tx_[a-f0-9]{32}$/D', $state['shell_transaction_id']) ||
            !is_string($target) || $target === '' ||
            ($state['to_version'] ?? null) !== $target || ($state['target_version'] ?? null) !== $target ||
            !is_string($source) || !ProductInventoryService::isValidSemver($source) ||
            ($this->updateService->getLocalVersion()['version'] ?? null) !== $source) {
            return null;
        }

        if ($terminal === 'rolled_back') {
            return 'shell_rolled_back';
        }
        return ($state['source_reopened_without_mutation'] ?? null) === true ? 'shell_source_reopened' : null;
    }

    /**
     * Redact Windows and Unix filesystem paths from strings.
     */
    public static function redactPaths(string $text): string
    {
        // Redact Windows absolute paths (e.g. C:\xampp\htdocs\pos\...)
        $text = (string) preg_replace('/[A-Za-z]:[\\\\\/][^:\s\'"<>\)]+/', '[path]', $text);
        // Redact Unix absolute paths (e.g. /var/www/..., /home/..., /tmp/...)
        $text = (string) preg_replace('/(?:\/(?:var|tmp|home|etc|usr|opt|app|srv|root|bin|sbin|lib|proc|sys)[^\s:\'"<>\)]*)/', '[path]', $text);
        return $text;
    }

    public function sanitizeResult(array $result): array
    {
        $sanitized = [];
        $sanitized['ok'] = (bool) ($result['ok'] ?? false);

        if (isset($result['data']) && is_array($result['data'])) {
            $data = $result['data'];
            $cleanData = [];
            if (isset($data['latest_version'])) {
                $cleanData['latest_version'] = (string) $data['latest_version'];
            }
            if (isset($data['update_type'])) {
                $cleanData['update_type'] = (string) $data['update_type'];
            }
            if (isset($data['requires_desktop_handoff'])) {
                $cleanData['requires_desktop_handoff'] = (bool) $data['requires_desktop_handoff'];
            }
            if (isset($data['handoff_version'])) {
                $cleanData['handoff_version'] = $data['handoff_version'] ? (string) $data['handoff_version'] : null;
            }
            if (isset($data['applied_files']) && is_array($data['applied_files'])) {
                $cleanData['applied_files'] = array_map(static function ($file) {
                    return basename((string) $file);
                }, $data['applied_files']);
            }
            if (isset($data['logs']) && is_array($data['logs'])) {
                $cleanData['logs'] = array_map(static function ($log) {
                    return self::redactPaths((string) $log);
                }, $data['logs']);
            }
            $sanitized['data'] = $cleanData;
        }

        if (isset($result['error'])) {
            $sanitized['error'] = self::redactPaths((string) $result['error']);
        }

        return $sanitized;
    }

    private function getJobsDir(): string
    {
        return rtrim($this->storageDir, '/\\') . '/update-jobs';
    }

    private function getJobFilePath(int $jobId): string
    {
        return $this->getJobsDir() . '/job_' . $jobId . '.json';
    }

    /**
     * Atomically persist per-job state file.
     */
    public function saveJobData(int $jobId, array $data): bool
    {
        $dir = $this->getJobsDir();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                Logger::error("Failed to create update-jobs directory: {$dir}");
                return false;
            }
        }

        $filePath = $this->getJobFilePath($jobId);
        $current = $this->readJobData($jobId) ?? [];
        $merged = array_merge($current, $data);

        $encoded = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return false;
        }

        $tmpFile = $dir . '/tmp_job_' . $jobId . '_' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tmpFile, $encoded . "\n", LOCK_EX) === false) {
            Logger::error("Failed to write temporary job file: {$tmpFile}");
            return false;
        }

        if (!@rename($tmpFile, $filePath)) {
            @unlink($tmpFile);
            Logger::error("Failed to atomically rename job file to {$filePath}");
            return false;
        }

        return true;
    }

    public function readJobData(int $jobId): ?array
    {
        $filePath = $this->getJobFilePath($jobId);
        if (!is_file($filePath)) {
            return null;
        }
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }
}
