<?php

namespace App\Helpers;

/**
 * JobQueue — نظام طوابير بسيط يعتمد على قاعدة البيانات.
 *
 * الاستخدام:
 *   JobQueue::dispatch('generate_report', ['month' => 5, 'year' => 2026]);
 *
 * التنفيذ (عبر cron أو CLI):
 *   php backend/cli/process-jobs.php
 */
class JobQueue
{
    private static bool $maintenanceChecked = false;

    public static function ensureMaintenanceJobs(): void
    {
        if (self::$maintenanceChecked) {
            return;
        }
        self::$maintenanceChecked = true;

        $db = \App\Config\Database::getInstance();
        $stmt = $db->query(
            "SELECT COUNT(*) FROM job_queue
             WHERE job_name IN ('cleanup_old_jobs', 'cleanup_old_logs')
               AND status IN ('pending', 'processing')
               AND created_at >= NOW() - INTERVAL 1 DAY"
        );

        if ((int) $stmt->fetchColumn() === 0) {
            self::dispatch('cleanup_old_jobs', ['days' => 7], -10);
            self::dispatch('cleanup_old_logs', ['days' => 30], -10);
        }
    }

    /**
     * إضافة مهمة جديدة للطابور.
     */
    public static function dispatch(
        string $job,
        array $payload = [],
        int $priority = 0,
        int $maxAttempts = 3
    ): int
    {
        $db = \App\Config\Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO job_queue
                (job_name, payload, priority, max_attempts, status, created_at)
             VALUES
                (:job, :payload, :priority, :max_attempts, :status, NOW())'
        );
        $stmt->execute([
            'job'          => $job,
            'payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'priority'     => $priority,
            'max_attempts' => max(1, min(10, $maxAttempts)),
            'status'       => 'pending',
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Enqueue at most one pending/processing job of a given name.
     */
    public static function dispatchUnique(
        string $job,
        array $payload = [],
        int $priority = 0,
        int $maxAttempts = 3
    ): int {
        $db = \App\Config\Database::getInstance();
        $db->beginTransaction();

        try {
            $existing = $db->prepare(
                "SELECT id
                 FROM job_queue
                 WHERE job_name = ?
                   AND status IN ('pending', 'processing')
                 ORDER BY id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $existing->execute([$job]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) {
                $db->commit();
                return (int) $existingId;
            }

            $stmt = $db->prepare(
                'INSERT INTO job_queue
                    (job_name, payload, priority, max_attempts, status, created_at)
                 VALUES
                    (:job, :payload, :priority, :max_attempts, :status, NOW())'
            );
            $stmt->execute([
                'job'          => $job,
                'payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'priority'     => $priority,
                'max_attempts' => max(1, min(10, $maxAttempts)),
                'status'       => 'pending',
            ]);

            $id = (int) $db->lastInsertId();
            $db->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    public static function find(int $id): ?array
    {
        $stmt = \App\Config\Database::getInstance()->prepare(
            'SELECT id, job_name, status, attempts, max_attempts,
                    last_error, failure_code, created_at, completed_at
             FROM job_queue
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$job) {
            return null;
        }

        $job['id'] = (int) $job['id'];
        $job['attempts'] = (int) $job['attempts'];
        $job['max_attempts'] = (int) $job['max_attempts'];
        $job['failure_code'] = $job['failure_code'] === null
            ? null
            : (int) $job['failure_code'];
        return $job;
    }

    /**
     * جلب وتنفيذ المهمة التالية.
     * @return bool true إذا تم تنفيذ مهمة
     */
    public static function processNext(): bool
    {
        $db = null;
        try {
            $db = \App\Config\Database::getInstance();
            $db->beginTransaction();

            // Keep this query compatible with the MariaDB versions bundled
            // with XAMPP. The optional non-blocking row-lock clause is not
            // available on older releases; this short FOR UPDATE transaction
            // still prevents two workers from claiming the same pending job.
            $stmt = $db->prepare(
                'SELECT * FROM job_queue
                 WHERE status = "pending" AND attempts < max_attempts
                 ORDER BY priority DESC, id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute();
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$job) {
                $db->commit();
                return false;
            }

            // تحديث الحالة
            $db->prepare('UPDATE job_queue SET status = "processing", attempts = attempts + 1 WHERE id = ?')
               ->execute([$job['id']]);
            $db->commit();

            // تنفيذ
            try {
                $handler = self::resolveHandler($job['job_name']);
                $handler(json_decode($job['payload'], true) ?? []);
                $db->prepare('UPDATE job_queue SET status = "completed", last_error = NULL, failure_code = NULL, completed_at = NOW() WHERE id = ?')
                   ->execute([$job['id']]);
            } catch (\Throwable $e) {
                $reference = bin2hex(random_bytes(8));
                Logger::error("Job failed: {$job['job_name']}", [
                    'id' => $job['id'],
                    'reference' => $reference,
                    'exception' => get_class($e),
                ]);
                $newStatus = ((int)$job['attempts'] + 1 >= (int)$job['max_attempts']) ? 'failed' : 'pending';
                $failureCode = $e instanceof JobFailure ? (int) $e->getCode() : null;
                $lastError = $e instanceof JobFailure
                    ? $e->getMessage()
                    : "Reference: {$reference}";
                $db->prepare('UPDATE job_queue SET status = ?, last_error = ?, failure_code = ? WHERE id = ?')
                   ->execute([$newStatus, $lastError, $failureCode, $job['id']]);
            }

            return true;
        } catch (\Throwable $e) {
            if ($db instanceof \PDO && $db->inTransaction()) {
                $db->rollBack();
            }
            Logger::error('Job queue error', [
                'exception' => get_class($e),
                'code' => (int) $e->getCode(),
            ]);
            return false;
        }
    }

    private static function resolveHandler(string $jobName): callable
    {
        $handlers = [
            'backup_database' => function (array $p) {
                $service = new \App\Services\BackupService();
                $storageDir = $_ENV['APP_STORAGE_DIR'] ?? getenv('APP_STORAGE_DIR');
                $backupDir = $storageDir
                    ? rtrim((string) $storageDir, '/\\') . '/backups'
                    : dirname(__DIR__) . '/storage/backups';
                $service->createBackupFile($backupDir);
            },
            'cleanup_old_logs' => function (array $p) {
                $logsPath = $_ENV['LOGS_PATH'] ?? getenv('LOGS_PATH');
                if (!$logsPath) {
                    $storageDir = $_ENV['APP_STORAGE_DIR'] ?? getenv('APP_STORAGE_DIR');
                    $logsPath = $storageDir ? $storageDir . '/logs' : __DIR__ . '/../logs';
                }
                $dir = rtrim($logsPath, '/\\') . '/';
                $days = max(1, min(3650, (int) ($p['days'] ?? 30)));
                $cutoff = strtotime("-{$days} days");
                foreach (glob($dir . '*.log') as $file) {
                    if (filemtime($file) < $cutoff) {
                        @unlink($file);
                    }
                }
            },
            'cleanup_old_jobs' => function (array $p) {
                $db = \App\Config\Database::getInstance();
                $days = max(1, min(3650, (int) ($p['days'] ?? 7)));
                $db->exec(
                    "DELETE FROM job_queue
                     WHERE status IN ('completed','failed')
                       AND created_at < NOW() - INTERVAL {$days} DAY"
                );
            },
            'cleanup_inventory_events' => function (array $p) {
                $db = \App\Config\Database::getInstance();
                (new \App\Models\InventoryEvent($db))->cleanup();
            },
            'send_low_stock_alert' => function (array $p) {
                $productId = $p['product_id'] ?? 0;
                $quantity  = $p['quantity'] ?? 0;
                $name      = $p['name'] ?? 'Unknown';
                Logger::warning("Low stock alert: {$name} (ID: {$productId}) — {$quantity} remaining");
            },
            'log_audit_event' => function (array $p) {
                Logger::info('Audit: ' . ($p['action'] ?? 'unknown'), $p);
            },
            'earn_loyalty_points' => function (array $p) {
                $branchId  = (int) ($p['branch_id'] ?? 0);
                $customerId = $p['customer_id'] ?? 0;
                $invoiceId  = $p['invoice_id'] ?? 0;
                $total      = (float)($p['total'] ?? 0);
                if ($branchId <= 0 || $customerId <= 0 || $invoiceId <= 0 || $total <= 0) return;

                $auth = new \App\Services\AuthService();
                $previousBranchId = \App\Services\AuthService::getGlobalBranchId();
                $auth->setBranchId($branchId);
                try {
                    $db = \App\Config\Database::getInstance();
                    $loyalty = new \App\Services\LoyaltyService(
                        new \App\Repositories\LoyaltyRepository($db),
                        $db
                    );
                    $points = $loyalty->earnPoints($customerId, $invoiceId, $total);
                } finally {
                    $auth->setBranchId($previousBranchId);
                }
                if ($points > 0) {
                    Logger::info("Loyalty: earned {$points} points", [
                        'customer_id' => $customerId,
                        'invoice_id'  => $invoiceId,
                    ]);
                }
            },
            'apply_update' => static function (array $p): void {
                $container = new \App\Core\Container();
                /** @var \App\Services\UpdateService $service */
                $service = $container->get(\App\Services\UpdateService::class);
                $result = $service->applyUpdate((bool) ($p['force'] ?? false));

                if (!($result['ok'] ?? false)) {
                    throw new JobFailure(
                        (string) ($result['error'] ?? 'Update failed'),
                        (int) ($result['code'] ?? 500)
                    );
                }
            },
        ];

        if (!isset($handlers[$jobName])) {
            throw new \RuntimeException("Unknown job: {$jobName}");
        }
        return $handlers[$jobName];
    }
}
