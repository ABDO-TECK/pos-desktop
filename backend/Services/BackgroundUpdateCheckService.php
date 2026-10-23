<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\EnvLoader;
use App\Helpers\JobQueue;
use App\Helpers\Logger;

class BackgroundUpdateCheckService
{
    private const TTL_SUCCESS = 300; // 5 minutes fresh cache
    private const TTL_FAILURE = 60;  // 1 minute failure cooldown

    private UpdateService $updateService;

    public function __construct(UpdateService $updateService)
    {
        $this->updateService = $updateService;
    }

    /**
     * Entry point for HTTP check endpoint (background mode).
     * Returns:
     * - Cached result array if fresh and matching context
     * - Pending result array if job scheduled or already running
     * - Disabled result array if updates are disabled
     */
    public function readOrSchedule(bool $deltaCapable = false): array
    {
        // If updates are disabled, return immediately with zero network and zero queue
        $repoUrl = (string) $this->updateService->getRepoUrl();
        $enabled = EnvLoader::getBool('ENABLE_UPDATE_CHECKS', true);

        if (!$enabled || empty($repoUrl)) {
            $local = $this->updateService->getLocalVersion();
            return [
                'success'             => true,
                'status'              => 'invalid_update_url',
                'pending'             => false,
                'ok'                  => true,
                'updates_disabled'    => true,
                'updates_unreachable' => false,
                'message'             => 'خادم التحديثات غير مهيأ.',
                'current_version'     => $local['version'] ?? '0.0.0',
                'latest_version'      => null,
                'has_update'          => false,
                'currentVersion'      => $local['version'] ?? '0.0.0',
                'latestVersion'       => null,
                'updateAvailable'     => false,
                'checkedUrl'          => $repoUrl,
                'errorCode'           => 'invalid_update_url',
                'details'             => empty($repoUrl) ? 'UPDATE_SERVER_URL is empty.' : 'ENABLE_UPDATE_CHECKS is disabled.',
                'changelog'           => [],
            ];
        }

        $context = $this->updateService->getContextFingerprint($deltaCapable);

        // Fast path: valid and fresh cache hit
        $cached = $this->readCache($context);
        if ($cached !== null) {
            return $cached;
        }

        // Cache miss: coordinate short scheduling
        $lock = null;
        try {
            $lock = $this->acquireScheduleLock();
        } catch (\Throwable $e) {
            Logger::error('Failed to acquire schedule lock', [
                'exception' => get_class($e),
            ]);
            return $this->queueUnavailableResponse($context, 'Schedule lock unavailable.');
        }

        // Distinguish busy lock (another process is actively scheduling/checking)
        if (is_array($lock) && ($lock['status'] ?? '') === 'busy') {
            $localVersion = $context['application_version'] ?? '0.0.0';
            return [
                'success'             => true,
                'status'              => 'check_pending',
                'pending'             => true,
                'ok'                  => true,
                'updates_disabled'    => false,
                'updates_unreachable' => false,
                'message'             => 'جاري التحقق من التحديثات في الخلفية...',
                'current_version'     => $localVersion,
                'latest_version'      => null,
                'has_update'          => false,
                'currentVersion'      => $localVersion,
                'latestVersion'       => null,
                'updateAvailable'     => false,
                'checkedUrl'          => $this->updateService->getRepoUrl(),
                'errorCode'           => null,
                'details'             => null,
                'changelog'           => [],
                'job_id'              => null,
            ];
        }

        // No dispatch without owning schedule lock!
        $hasLock = is_resource($lock) || (is_array($lock) && ($lock['status'] ?? '') === 'acquired');
        if (!$hasLock) {
            return $this->queueUnavailableResponse($context, 'Unable to create or acquire schedule lock.');
        }

        $jobId = null;
        try {
            // Re-check cache under short lock in case another request just published it
            $cached = $this->readCache($context);
            if ($cached !== null) {
                return $cached;
            }

            $jobId = $this->dispatchQueueJob($context, $deltaCapable);
        } catch (\Throwable $e) {
            Logger::error('Failed to dispatch background update check job', [
                'exception' => get_class($e),
            ]);
            return $this->queueUnavailableResponse($context, 'Job queue dispatch failed.');
        } finally {
            $this->releaseScheduleLock($lock);
        }

        $localVersion = $context['application_version'] ?? '0.0.0';
        return [
            'success'             => true,
            'status'              => 'check_pending',
            'pending'             => true,
            'ok'                  => true,
            'updates_disabled'    => false,
            'updates_unreachable' => false,
            'message'             => 'جاري التحقق من التحديثات في الخلفية...',
            'current_version'     => $localVersion,
            'latest_version'      => null,
            'has_update'          => false,
            'currentVersion'      => $localVersion,
            'latestVersion'       => null,
            'updateAvailable'     => false,
            'checkedUrl'          => $this->updateService->getRepoUrl(),
            'errorCode'           => null,
            'details'             => null,
            'changelog'           => [],
            'job_id'              => $jobId,
        ];
    }

    /**
     * Build unavailable response when job queue cannot be scheduled.
     */
    private function queueUnavailableResponse(array $context, string $details): array
    {
        $localVersion = $context['application_version'] ?? '0.0.0';
        return [
            'success'             => false,
            'status'              => 'queue_unavailable',
            'pending'             => false,
            'ok'                  => true,
            'updates_disabled'    => false,
            'updates_unreachable' => true,
            'message'             => 'تعذر جدولة فحص التحديثات في الخلفية.',
            'current_version'     => $localVersion,
            'latest_version'      => null,
            'has_update'          => false,
            'currentVersion'      => $localVersion,
            'latestVersion'       => null,
            'updateAvailable'     => false,
            'checkedUrl'          => $this->updateService->getRepoUrl(),
            'errorCode'           => 'queue_unavailable',
            'details'             => $details,
            'changelog'           => [],
        ];
    }

    /**
     * Worker execution: invoked by JobQueue CLI process.
     */
    public function processWorkerJob(array $payload): bool
    {
        $expectedContext = $payload['context'] ?? null;
        $deltaCapable = (bool) ($payload['delta_capable'] ?? false);

        if (!is_array($expectedContext) || empty($expectedContext['fingerprint'])) {
            Logger::warning('check_for_updates job payload missing context fingerprint');
            return false;
        }

        $workerLock = $this->acquireWorkerLock();
        if ($workerLock === null) {
            Logger::info('check_for_updates worker execution skipped: another worker active');
            return false;
        }

        try {
            // 1. Verify context before discovery (ignore queued stale jobs)
            $preContext = $this->updateService->getContextFingerprint($deltaCapable);
            if (($preContext['fingerprint'] ?? '') !== $expectedContext['fingerprint']) {
                Logger::info('check_for_updates job context stale before execution, skipping', [
                    'expected' => $expectedContext['fingerprint'],
                    'current'  => $preContext['fingerprint'] ?? null,
                ]);
                return false;
            }

            // 2. Perform discovery and cryptographic/channel validation
            $result = $this->updateService->checkForUpdate($deltaCapable, true);

            // 3. Verify context after discovery (context change DURING execution prevents publication)
            $postContext = $this->updateService->getContextFingerprint($deltaCapable);
            if (($postContext['fingerprint'] ?? '') !== $expectedContext['fingerprint']) {
                Logger::info('check_for_updates context changed during worker execution, aborting cache write', [
                    'expected' => $expectedContext['fingerprint'],
                    'current'  => $postContext['fingerprint'] ?? null,
                ]);
                return false;
            }

            // 4. Cache full result atomically
            return $this->writeCache($expectedContext, $result);
        } finally {
            $this->releaseWorkerLock($workerLock);
        }
    }

    /**
     * Dispatch queue job via existing JobQueue::dispatchUnique.
     * Note: Existing dispatchUnique with same job_name ('check_for_updates') intentionally
     * permits an older-context job currently pending/processing to finish or skip, then the
     * next periodic poll will schedule the current context. We do not alter or bypass this behavior.
     */
    protected function dispatchQueueJob(array $context, bool $deltaCapable): int
    {
        return JobQueue::dispatchUnique(
            'check_for_updates',
            [
                'context'       => $context,
                'delta_capable' => $deltaCapable,
            ],
            -5
        );
    }

    /**
     * Protected seam for current timestamp.
     */
    protected function currentTime(): int
    {
        return time();
    }

    /**
     * Protected seam for cache storage directory.
     */
    protected function getStorageDir(): string
    {
        return $this->updateService->getDeltaUpdateService()->getStorageDir();
    }

    protected function getCacheFilePath(string $fingerprint): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $fingerprint) ?: 'default';
        $storageDir = $this->getStorageDir();
        return rtrim($storageDir, '/\\') . '/bg_update_check_' . $safe . '.json';
    }

    /**
     * Read and validate atomic cache.
     * Note: Does NOT unlink invalid cache files on HTTP read to prevent competing
     * replacement writers from being deleted by concurrent readers.
     */
    public function readCache(array $currentContext): ?array
    {
        $cacheFile = $this->getCacheFilePath($currentContext['fingerprint'] ?? '');
        if (!is_file($cacheFile)) {
            return null;
        }

        clearstatcache(true, $cacheFile);
        $size = @filesize($cacheFile);
        if ($size === false || $size <= 0 || $size > 64 * 1024) {
            return null;
        }

        $content = @file_get_contents($cacheFile);
        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        $cachedAt = $data['cached_at'] ?? null;
        $ttl = $data['ttl'] ?? null;
        $context = $data['context'] ?? null;
        $result = $data['result'] ?? null;

        if (!is_int($cachedAt) || !is_int($ttl) || !is_array($context) || !is_array($result)) {
            return null;
        }

        // Bounded positive TTL (1 to 300s)
        if ($ttl <= 0 || $ttl > 300) {
            return null;
        }

        $now = $this->currentTime();
        // Cached_at sanity: not in the future (within 5s clock skew tolerance), not expired, positive
        if ($cachedAt > ($now + 5) || ($now - $cachedAt) >= $ttl || $cachedAt <= 0) {
            return null;
        }

        // Context fingerprint and application version match
        if (($context['fingerprint'] ?? '') !== ($currentContext['fingerprint'] ?? '')) {
            return null;
        }
        if (($context['application_version'] ?? '') !== ($currentContext['application_version'] ?? '')) {
            return null;
        }

        // Complete result shape validation
        if (!is_string($result['current_version'] ?? null) || $result['current_version'] === '') {
            return null;
        }
        if ($result['current_version'] !== ($currentContext['application_version'] ?? '')) {
            return null;
        }
        if (!is_string($result['status'] ?? null) || $result['status'] === '') {
            return null;
        }
        if (!is_bool($result['has_update'] ?? null)) {
            return null;
        }
        if (!is_bool($result['updates_disabled'] ?? null)) {
            return null;
        }
        if (!is_bool($result['updates_unreachable'] ?? null)) {
            return null;
        }
        if (!is_array($result['changelog'] ?? null)) {
            return null;
        }
        if ($result['has_update']) {
            if (!is_string($result['latest_version'] ?? null) || $result['latest_version'] === '') {
                return null;
            }
        } else {
            if (isset($result['latest_version']) && $result['latest_version'] !== null && !is_string($result['latest_version'])) {
                return null;
            }
        }

        // Sanitize any internal paths or secrets
        unset($result['staging_dir'], $result['temp_dir'], $result['backup_dir']);

        return $result;
    }

    /**
     * Atomically write result to cache.
     * Preserves existing target file on failure (no pre-unlink).
     */
    public function writeCache(array $context, array $result): bool
    {
        $storageDir = $this->getStorageDir();
        if (!is_dir($storageDir)) {
            if (!@mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
                return false;
            }
        }

        $isSuccess = !empty($result['success']) && empty($result['updates_unreachable']) && ($result['status'] ?? '') !== 'queue_unavailable';
        $ttl = $isSuccess ? self::TTL_SUCCESS : self::TTL_FAILURE;

        $payload = [
            'cached_at' => $this->currentTime(),
            'ttl'       => $ttl,
            'context'   => $context,
            'result'    => $result,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $targetFile = $this->getCacheFilePath($context['fingerprint'] ?? '');
        $tempFile = $targetFile . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tempFile, $json, LOCK_EX) === false) {
            @unlink($tempFile);
            return false;
        }

        // Atomic replacement: rename directly over target without pre-unlinking
        if (!$this->performRename($tempFile, $targetFile)) {
            @unlink($tempFile);
            return false;
        }

        return true;
    }

    /**
     * Minimal protected seam for atomic rename to allow simulating replacement failure in tests.
     */
    protected function performRename(string $source, string $destination): bool
    {
        return @rename($source, $destination);
    }

    /**
     * Acquire short scheduling lock.
     * Returns:
     * - resource: acquired handle
     * - ['status' => 'busy']: another process holds schedule lock
     * - null: lock creation failed
     */
    protected function acquireScheduleLock(): mixed
    {
        $storageDir = $this->getStorageDir();
        if (!is_dir($storageDir)) {
            if (!@mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
                return null;
            }
        }
        $lockFile = rtrim($storageDir, '/\\') . '/bg_check_schedule.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return null;
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            return ['status' => 'busy'];
        }
        return $fp;
    }

    protected function releaseScheduleLock(mixed $lock): void
    {
        $fp = is_array($lock) ? ($lock['handle'] ?? null) : $lock;
        if (is_resource($fp)) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    protected function acquireWorkerLock(): mixed
    {
        $storageDir = $this->getStorageDir();
        if (!is_dir($storageDir)) {
            if (!@mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
                return null;
            }
        }
        $lockFile = rtrim($storageDir, '/\\') . '/bg_check_worker.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return null;
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            return null;
        }
        return $fp;
    }

    protected function releaseWorkerLock(mixed $fp): void
    {
        if (is_resource($fp)) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }
}
