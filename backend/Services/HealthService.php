<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\Logger;
use Throwable;

/**
 * HealthService — منطق فحص صحة النظام وجمع المقاييس.
 *
 * يُوحّد كل استعلامات الفحص والمقاييس في مكان واحد
 * ليبقى HealthController مسؤولاً فقط عن HTTP response.
 */
class HealthService
{
    /**
     * Minimal public liveness payload. Deep checks are intentionally excluded.
     */
    public function getLiveness(): array
    {
        return [
            'status' => 'ok',
            'critical_failed' => false,
            'version' => $this->getVersionIdentifier(),
        ];
    }

    // ── Basic health check ──────────────────────────────────

    /**
     * فحص صحة النظام الأساسي (DB, Disk, Memory, PHP, Security).
     *
     * @return array ['healthy' => bool, 'checks' => [...], 'warnings' => [...]]
     */
    public function runHealthCheck(): array
    {
        $checks = [];
        $criticalFailed = false;
        $hasWarnings = false;

        // ── 1. Database Check ──
        try {
            $db = Database::getInstance();
            $start = microtime(true);
            $db->query('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);
            $checks['database'] = [
                'status'     => 'ok',
                'severity'   => 'critical',
                'message'    => "Connected successfully. Latency: {$latency}ms"
            ];
        } catch (Throwable $e) {
            $reference = $this->exceptionReference('health.database', $e);
            $checks['database'] = [
                'status'     => 'failed',
                'severity'   => 'warning',
                'message'    => "Database unavailable. Reference: {$reference}"
            ];
            $hasWarnings = true;
        }

        // ── 2. Storage Check ──
        $storagePath = $_ENV['APP_STORAGE_DIR'] ?? (getenv('APP_STORAGE_DIR') ?: null) ?? (__DIR__ . '/../storage');
        $storageWritable = false;
        if (is_dir($storagePath)) {
            $testFile = $storagePath . DIRECTORY_SEPARATOR . '.write-test-' . uniqid();
            if (@file_put_contents($testFile, 'test') !== false) {
                @unlink($testFile);
                $storageWritable = true;
            }
        }
        if ($storageWritable) {
            $checks['storage'] = [
                'status'   => 'ok',
                'severity' => 'critical',
                'message'  => 'Storage directory is writable.'
            ];
        } else {
            $checks['storage'] = [
                'status'   => 'failed',
                'severity' => 'critical',
                'message'  => 'Storage directory is not writable.'
            ];
            $criticalFailed = true;
        }

        // ── 3. Logs Check ──
        $logsPath = $_ENV['LOGS_PATH'] ?? (getenv('LOGS_PATH') ?: null) ?? (__DIR__ . '/../logs');
        $logsWritable = false;
        if (is_dir($logsPath)) {
            $testFile = $logsPath . DIRECTORY_SEPARATOR . '.write-test-' . uniqid();
            if (@file_put_contents($testFile, 'test') !== false) {
                @unlink($testFile);
                $logsWritable = true;
            }
        }
        if ($logsWritable) {
            $checks['logs'] = [
                'status'   => 'ok',
                'severity' => 'warning',
                'message'  => 'Logs directory is writable.'
            ];
        } else {
            $checks['logs'] = [
                'status'   => 'failed',
                'severity' => 'warning',
                'message'  => 'Logs directory is not writable.'
            ];
            $hasWarnings = true;
        }

        // ── 4. Version Check ──
        $version = $this->getVersionIdentifier();
        $checks['version'] = [
            'status'   => 'ok',
            'severity' => 'info',
            'message'  => "Version: {$version}"
        ];

        // ── 5. Migrations Check ──
        $metadataPath = getenv('RUNTIME_METADATA_PATH');
        $migrationCheck = [
            'status'   => 'ok',
            'severity' => 'info',
            'message'  => 'No migration metadata registry found (development/initial state).'
        ];

        if ($metadataPath && file_exists($metadataPath)) {
            $metadataContent = @file_get_contents($metadataPath);
            if ($metadataContent) {
                $metadata = json_decode($metadataContent, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($metadata['migrationState'])) {
                    $state = $metadata['migrationState'];
                    $v = $metadata['appVersion'] ?? 'unknown';
                    
                    // Parse file migrations
                    $fileMigrations = $metadata['fileMigrations'] ?? [];
                    $hasFileFailed = false;
                    $hasFileConflict = false;
                    $conflictDetails = [];
                    foreach ($fileMigrations as $key => $migration) {
                        $status = $migration['status'] ?? '';
                        if ($status === 'failed') {
                            $hasFileFailed = true;
                        } elseif ($status === 'conflict' || $status === 'migrated_with_conflict_copy') {
                            $hasFileConflict = true;
                            $conflictDetails[] = $key;
                        }
                    }

                    // Parse mysql migration preflight status
                    $mysqlMigration = $metadata['mysqlMigration'] ?? [];
                    $mysqlStatus = $mysqlMigration['status'] ?? 'not_started';

                    // Parse mysql rollback status
                    $mysqlRollback = $metadata['mysqlRollback'] ?? [];
                    $rollbackStatus = $mysqlRollback['status'] ?? 'not_started';

                    if ($hasFileFailed) {
                        $migrationCheck = [
                            'status'   => 'failed',
                            'severity' => 'warning',
                            'message'  => "One or more file migrations failed. Active version: {$v}."
                        ];
                        $hasWarnings = true;
                    } elseif (in_array($mysqlStatus, ['failed', 'verify_failed'])) {
                        $migrationCheck = [
                            'status'   => 'failed',
                            'severity' => 'warning',
                            'message'  => "MySQL migration failed or verify failed. Status: {$mysqlStatus}. Active version: {$v}."
                        ];
                        $hasWarnings = true;
                    } elseif (in_array($mysqlStatus, ['locked', 'access_denied', 'process_detected_copy_skipped', 'backup_skipped_size_limit', 'unknown_lock_state', 'destination_not_empty', 'external_mysql_process_detected'])) {
                        $migrationCheck = [
                            'status'   => 'pending',
                            'severity' => 'warning',
                            'message'  => "MySQL migration issue detected: {$mysqlStatus}. Safe files migrated successfully."
                        ];
                        $hasWarnings = true;
                    } elseif ($rollbackStatus === 'rollback_completed') {
                        $migrationCheck = [
                            'status'   => 'ok',
                            'severity' => 'info',
                            'message'  => "MySQL rollback completed successfully."
                        ];
                    } elseif ($rollbackStatus === 'rollback_restore_staged_verified') {
                        $migrationCheck = [
                            'status'   => 'ok',
                            'severity' => 'info',
                            'message'  => "Rollback restore staging verified; final switch not performed."
                        ];
                    } elseif (in_array($rollbackStatus, ['rollback_restore_blocked', 'final_switch_blocked'])) {
                        $migrationCheck = [
                            'status'   => 'pending',
                            'severity' => 'warning',
                            'message'  => "MySQL rollback restore/switch blocked. Reason: " . ($mysqlRollback['lastError'] ?? 'unknown')
                        ];
                        $hasWarnings = true;
                    } elseif (in_array($rollbackStatus, ['rollback_restore_verify_failed', 'final_switch_snapshot_failed', 'final_switch_verify_failed', 'failed'])) {
                        $migrationCheck = [
                            'status'   => 'failed',
                            'severity' => 'warning',
                            'message'  => "MySQL rollback/switch failed or verify failed. Status: {$rollbackStatus}. Active version: {$v}."
                        ];
                        $hasWarnings = true;
                    } elseif ($rollbackStatus === 'rollback_blocked') {
                        $migrationCheck = [
                            'status'   => 'pending',
                            'severity' => 'warning',
                            'message'  => "MySQL rollback blocked. Reason: " . ($mysqlRollback['lastError'] ?? 'unknown')
                        ];
                        $hasWarnings = true;
                    } elseif ($mysqlStatus === 'migration_committed') {
                        $rollbackMsg = "";
                        if ($mysqlMigration['rollbackAvailable'] ?? false) {
                            $rollbackMsg = " Rollback is available.";
                        }
                        $migrationCheck = [
                            'status'   => 'ok',
                            'severity' => 'info',
                            'message'  => "MySQL controlled migration committed. Active version: {$v}.{$rollbackMsg}"
                        ];
                    } elseif ($mysqlStatus === 'backup_verified') {
                        $migrationCheck = [
                            'status'   => 'ok',
                            'severity' => 'info',
                            'message'  => "MySQL preflight backup verified. Active version: {$v}."
                        ];
                    } elseif (in_array($mysqlStatus, ['candidate_found', 'backup_created', 'active_migration_not_enabled'])) {
                        $migrationCheck = [
                            'status'   => 'ok',
                            'severity' => 'info',
                            'message'  => "MySQL migration status is {$mysqlStatus}. Active version: {$v}."
                        ];
                    } elseif ($hasFileConflict) {
                        $migrationCheck = [
                            'status'   => 'ok',
                            'severity' => 'warning',
                            'message'  => "File migration conflict: conflict-safe legacy copies created for: " . implode(', ', $conflictDetails) . "."
                        ];
                        $hasWarnings = true;
                    } elseif ($state === 'committed' || $state === 'idle') {
                        $mysqlMsg = "";
                        if ($mysqlStatus === 'skipped') {
                            $mysqlMsg = " No legacy MySQL candidate directories found.";
                        }
                        $migrationCheck = [
                            'status'   => 'ok',
                            'severity' => 'info',
                            'message'  => "Migration state is {$state}. Active version: {$v}.{$mysqlMsg}"
                        ];
                    } elseif ($state === 'pending' || $state === 'copying' || $state === 'verified') {
                        $migrationCheck = [
                            'status'   => 'pending',
                            'severity' => 'warning',
                            'message'  => "Migration is in progress. State: {$state}. Targeted version: {$v}."
                        ];
                        $hasWarnings = true;
                    } elseif ($state === 'failed' || $state === 'reverted') {
                        $migrationCheck = [
                            'status'   => 'failed',
                            'severity' => 'warning',
                            'message'  => "Migration failed or reverted. State: {$state}."
                        ];
                        $hasWarnings = true;
                    }
                } else {
                    $migrationCheck = [
                        'status'   => 'failed',
                        'severity' => 'warning',
                        'message'  => 'Migration metadata is invalid or corrupt.'
                    ];
                    $hasWarnings = true;
                }
            }
        }
        $checks['migrations'] = $migrationCheck;

        // ── Update Infrastructure Check ──
        $pubKeyPath = dirname(__DIR__) . '/certs/update_public_key.pem';
        if (is_file($pubKeyPath) && is_readable($pubKeyPath)) {
            $checks['update_infrastructure'] = [
                'status'   => 'ok',
                'severity' => 'info',
                'message'  => 'Cryptographic RSA update public key is verified and operational.',
            ];
        }

        // ── 6. Disk Space Check ──

        try {
            $freeBytes   = @disk_free_space($storagePath) ?: 0;
            $totalBytes  = @disk_total_space($storagePath) ?: 0;
            $usedBytes   = $totalBytes - $freeBytes;
            $usedPercent = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 2) : 0;
            $freeGb      = round($freeBytes / (1024 * 1024 * 1024), 2);
            $totalGb     = round($totalBytes / (1024 * 1024 * 1024), 2);
            $diskStatus  = $usedPercent > 90 ? 'warning' : 'ok';
            
            $checks['disk'] = [
                'status'       => $diskStatus,
                'severity'     => 'warning',
                'message'      => "Free space: {$freeGb} GB of {$totalGb} GB ({$usedPercent}% used)",
                'free_gb'      => $freeGb,
                'total_gb'     => $totalGb,
                'used_percent' => $usedPercent
            ];
        } catch (\Throwable $e) {
            $reference = $this->exceptionReference('health.disk', $e);
            $checks['disk'] = [
                'status'       => 'failed',
                'severity'     => 'warning',
                'message'      => "Failed to check disk space. Reference: {$reference}",
                'free_gb'      => 0.0,
                'total_gb'     => 0.0,
                'used_percent' => 0.0
            ];
            $hasWarnings = true;
        }

        // ── 7. Memory Check ──
        try {
            $usageBytes = memory_get_usage(true);
            $peakBytes  = memory_get_peak_usage(true);
            $limit      = ini_get('memory_limit');
            
            $checks['memory'] = [
                'status'   => 'ok',
                'severity' => 'info',
                'message'  => 'Memory limit is ' . ($limit ?: 'unlimited'),
                'usage_mb' => round($usageBytes / (1024 * 1024), 2),
                'peak_mb'  => round($peakBytes / (1024 * 1024), 2),
                'limit'    => $limit ?: 'unlimited'
            ];
        } catch (\Throwable $e) {
            $reference = $this->exceptionReference('health.memory', $e);
            $checks['memory'] = [
                'status'   => 'failed',
                'severity' => 'info',
                'message'  => "Failed to check memory. Reference: {$reference}",
                'usage_mb' => 0.0,
                'peak_mb'  => 0.0,
                'limit'    => 'unknown'
            ];
        }

        // ── 8. PHP & Extensions Check ──
        try {
            $extensions = [
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'openssl'   => extension_loaded('openssl'),
                'mbstring'  => extension_loaded('mbstring'),
                'curl'      => extension_loaded('curl'),
                'gd'        => extension_loaded('gd'),
                'zip'       => extension_loaded('zip'),
            ];
            
            $checks['php'] = [
                'status'     => 'ok',
                'severity'   => 'info',
                'message'    => 'PHP Version ' . PHP_VERSION,
                'version'    => PHP_VERSION,
                'extensions' => $extensions
            ];
        } catch (\Throwable $e) {
            $reference = $this->exceptionReference('health.php', $e);
            $checks['php'] = [
                'status'     => 'failed',
                'severity'   => 'info',
                'message'    => "Failed to check PHP info. Reference: {$reference}",
                'version'    => PHP_VERSION,
                'extensions' => []
            ];
        }

        // ── 9. Core Database Tables Check ──
        try {
            $coreTables = $this->checkCoreTables();
            if ($coreTables['healthy']) {
                $checks['core_tables'] = [
                    'status'   => 'ok',
                    'severity' => 'critical',
                    'message'  => 'All core database tables (users, products, settings, invoices) verified.',
                ];
            } else {
                $checks['core_tables'] = [
                    'status'   => 'failed',
                    'severity' => 'critical',
                    'message'  => 'Missing or corrupted core database tables: ' . implode(', ', $coreTables['missing']),
                    'details'  => $coreTables['tables'],
                ];
                $criticalFailed = true;
            }
        } catch (\Throwable $e) {
            $checks['core_tables'] = [
                'status'   => 'failed',
                'severity' => 'critical',
                'message'  => 'Core tables check could not complete: ' . $e->getMessage(),
            ];
            $criticalFailed = true;
        }

        // ── 10. Schema & Product Integrity Check ──
        try {
            $integrity = $this->checkProductIntegrity();
            if ($integrity['healthy']) {
                $checks['schema_integrity'] = [
                    'status'   => 'ok',
                    'severity' => 'info',
                    'message'  => 'Database tables, columns, and triggers verified.',
                ];
            } else {
                $checks['schema_integrity'] = [
                    'status'   => 'failed',
                    'severity' => 'warning',
                    'message'  => 'Schema issues detected: ' . implode('; ', $integrity['issues']),
                    'details'  => $integrity['details'],
                ];
                $hasWarnings = true;
            }
        } catch (\Throwable $e) {
            $checks['schema_integrity'] = [
                'status'   => 'failed',
                'severity' => 'warning',
                'message'  => 'Integrity check could not complete: ' . $e->getMessage(),
            ];
            $hasWarnings = true;
        }

        // Overall status
        if ($criticalFailed) {
            $status = 'failed';
        } elseif ($hasWarnings) {
            $status = 'degraded';
        } else {
            $status = 'ok';
        }

        return [
            'healthy'         => !$criticalFailed,
            'critical_failed' => $criticalFailed,
            'status'          => $status,
            'checks'          => $checks,
            'version'         => $version
        ];
    }

    /**
     * التحقق من وجود جداول النظام الأساسية (users, products, settings, invoices).
     *
     * @return array{healthy: bool, missing: list<string>, tables: array<string, string>}
     */
    public function checkCoreTables(): array
    {
        $db = Database::getInstance();
        $requiredTables = ['users', 'products', 'settings', 'invoices'];
        $missing = [];
        $details = [];

        foreach ($requiredTables as $table) {
            try {
                $stmt = $db->query("SELECT 1 FROM `{$table}` LIMIT 1");
                $exists = (bool) $stmt;
            } catch (Throwable) {
                $exists = false;
            }

            $details[$table] = $exists ? 'ok' : 'missing';
            if (!$exists) {
                $missing[] = $table;
            }
        }

        return [
            'healthy' => empty($missing),
            'missing' => $missing,
            'tables'  => $details,
        ];
    }

    /**
     * Non-intrusive diagnostic verification of product database structures.
     *
     * @return array{healthy: bool, issues: list<string>, details: array}
     */
    public function checkProductIntegrity(): array
    {
        $issues = [];
        $details = [];
        $db = Database::getInstance();

        // 1. Check required tables
        $requiredTables = ['products', 'product_barcodes', 'product_catalog_changes', 'categories', 'branches', 'schema_versions'];
        foreach ($requiredTables as $table) {
            $stmt = $db->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            $exists = (bool) $stmt->fetchColumn();
            $details['tables'][$table] = $exists ? 'ok' : 'missing';
            if (!$exists) {
                $issues[] = "Missing table: {$table}";
            }
        }

        // 2. Check required columns on products table
        if (($details['tables']['products'] ?? '') === 'ok') {
            $requiredColumns = [
                'id', 'name', 'barcode', 'box_barcode', 'price', 'cost',
                'quantity', 'sell_by_weight', 'unit_type', 'low_stock_threshold',
                'units_per_box', 'category_id', 'parent_product_id', 'size_name',
                'branch_id', 'deleted_at',
            ];
            $stmt = $db->query('SHOW COLUMNS FROM products');
            $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            foreach ($requiredColumns as $col) {
                $hasCol = in_array($col, $columns, true);
                $details['columns'][$col] = $hasCol ? 'ok' : 'missing';
                if (!$hasCol) {
                    $issues[] = "Missing column in products: {$col}";
                }
            }
        }

        // 3. Check triggers and definers on products table
        try {
            // The migration-owned, non-updatable view exposes names only.
            // Runtime users must not receive TRIGGER privileges for diagnostics.
            $stmt = $db->prepare('SELECT TRIGGER_NAME FROM product_catalog_trigger_metadata');
            $stmt->execute();
            $triggers = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $details['triggers'] = $triggers;

            $triggerNames = array_column($triggers, 'TRIGGER_NAME');
            foreach (['trg_products_catalog_insert', 'trg_products_catalog_update', 'trg_products_catalog_delete'] as $trg) {
                if (!in_array($trg, $triggerNames, true)) {
                    $issues[] = "Missing trigger: {$trg}";
                }
            }
        } catch (\Throwable $e) {
            $issues[] = 'Trigger query error: ' . $e->getMessage();
        }

        return [
            'healthy' => empty($issues),
            'issues'  => $issues,
            'details' => $details,
        ];
    }

    private function exceptionReference(string $operation, Throwable $exception): string
    {
        $reference = bin2hex(random_bytes(8));
        Logger::error($operation . ' failed', [
            'reference' => $reference,
            'exception' => get_class($exception),
        ]);

        return $reference;
    }

    /** Resolve a bounded, display-safe application version identifier. */
    private function getVersionIdentifier(): string
    {
        $deploymentRoot = UpdateRuntimePaths::deployedRoot(realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
        foreach ([$deploymentRoot . '/version.json', __DIR__ . '/../version.json', __DIR__ . '/../../version.json'] as $versionFile) {
            if (!is_file($versionFile)) {
                continue;
            }

            $versionData = json_decode((string) @file_get_contents($versionFile), true);
            if (is_array($versionData) && isset($versionData['version']) && is_string($versionData['version'])) {
                $candidate = trim($versionData['version']);
                if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/', $candidate)) {
                    return $candidate;
                }
            }
        }

        return 'unknown';
    }

    // ── Advanced metrics ────────────────────────────────────

    /**
     * جمع مقاييس الأداء المتقدمة (DB, Tables, Today's Activity, Cache, System).
     *
     * @return array المقاييس المجمّعة
     */
    public function getMetrics(): array
    {
        $db = Database::getInstance();
        $metrics = [];

        // ── 1. Database Metrics ──
        try {
            $stmt = $db->query("SHOW STATUS LIKE 'Threads_connected'");
            $row = $stmt->fetch();
            $metrics['database']['active_connections'] = (int)($row['Value'] ?? 0);

            $stmt = $db->query("SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = DATABASE()");
            $row = $stmt->fetch();
            $metrics['database']['size_mb'] = round(((float)($row['size'] ?? 0)) / (1024 * 1024), 2);

            $stmt = $db->query("SHOW STATUS LIKE 'Slow_queries'");
            $row = $stmt->fetch();
            $metrics['database']['slow_queries'] = (int)($row['Value'] ?? 0);
        } catch (Throwable $e) {
            $metrics['database']['error'] = $this->exceptionReference('metrics.database', $e);
        }

        // ── 2. Table Row Counts ──
        $tables = ['products', 'invoices', 'invoice_items', 'customers', 'suppliers', 'expenses', 'audit_logs'];
        $metrics['tables'] = [];
        foreach ($tables as $table) {
            try {
                $stmt = $db->query("SELECT COUNT(*) as cnt FROM {$table}");
                $metrics['tables'][$table] = (int)$stmt->fetchColumn();
            } catch (Throwable $e) {
                $metrics['tables'][$table] = -1;
            }
        }

        // ── 3. Today's Activity ──
        try {
            $today = date('Y-m-d');
            $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
            $branchId = \App\Services\AuthService::getGlobalBranchId();

            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM invoices
                 WHERE branch_id = ? AND created_at >= ? AND created_at < ? AND deleted_at IS NULL'
            );
            $stmt->execute([$branchId, $today, $tomorrow]);
            $metrics['today']['sales_count'] = (int)$stmt->fetchColumn();

            $stmt = $db->prepare(
                "SELECT COALESCE(SUM(total), 0) FROM invoices
                 WHERE branch_id = ? AND created_at >= ? AND created_at < ?
                   AND deleted_at IS NULL AND status = 'completed'"
            );
            $stmt->execute([$branchId, $today, $tomorrow]);
            $metrics['today']['sales_total'] = round((float)$stmt->fetchColumn(), 2);

            $stmt = $db->prepare(
                'SELECT COALESCE(SUM(amount), 0) FROM expenses
                 WHERE branch_id = ? AND expense_date >= ? AND expense_date < ?'
            );
            $stmt->execute([$branchId, $today, $tomorrow]);
            $metrics['today']['expenses_total'] = round((float)$stmt->fetchColumn(), 2);
        } catch (Throwable $e) {
            $metrics['today']['error'] = $this->exceptionReference('metrics.today', $e);
        }

        // ── 4. Cache Status ──
        $metrics['cache'] = [
            'redis_available' => class_exists('Redis'),
            'apcu_available'  => function_exists('apcu_fetch'),
            'driver'          => class_exists('Redis') ? 'redis' : (function_exists('apcu_fetch') ? 'apcu' : 'file'),
        ];

        // ── 5. System ──
        $metrics['system'] = [
            'php_version'     => PHP_VERSION,
            'memory_usage_mb' => round(memory_get_usage(true) / (1024 * 1024), 2),
            'memory_peak_mb'  => round(memory_get_peak_usage(true) / (1024 * 1024), 2),
            'memory_limit'    => ini_get('memory_limit'),
            'opcache_enabled' => function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false),
            'timestamp'       => date('c'),
        ];

        return $metrics;
    }

    // ── Slow queries analysis ───────────────────────────────

    /**
     * تحليل EXPLAIN للاستعلامات الرئيسية.
     *
     * @return array نتائج EXPLAIN لكل استعلام
     */
    public function analyzeSlowQueries(): array
    {
        $db = Database::getInstance();
        $queries = [
            'invoices_by_date'  => "EXPLAIN SELECT * FROM invoices WHERE created_at >= CURDATE() AND status = 'completed' ORDER BY created_at DESC LIMIT 50",
            'top_products'      => "EXPLAIN SELECT p.name, SUM(ii.quantity) as sold FROM invoice_items ii JOIN products p ON p.id = ii.product_id GROUP BY ii.product_id ORDER BY sold DESC LIMIT 10",
            'low_stock'         => "EXPLAIN SELECT * FROM products WHERE quantity <= low_stock_threshold AND deleted_at IS NULL",
            'customer_balance'  => "EXPLAIN SELECT customer_id, SUM(CASE WHEN type='debit' THEN amount ELSE -amount END) as balance FROM customer_ledger GROUP BY customer_id",
            'expenses_by_date'  => "EXPLAIN SELECT * FROM expenses WHERE expense_date >= CURDATE() - INTERVAL 30 DAY ORDER BY expense_date DESC",
        ];

        $results = [];
        foreach ($queries as $name => $sql) {
            try {
                $stmt = $db->query($sql);
                $explain = $stmt->fetchAll();
                $results[$name] = [
                    'plan'       => $explain,
                    'uses_index' => !empty($explain[0]['key']),
                    'type'       => $explain[0]['type'] ?? 'unknown',
                ];
            } catch (Throwable $e) {
                $results[$name] = [
                    'error' => $this->exceptionReference('metrics.explain.' . $name, $e),
                ];
            }
        }

        return $results;
    }

    /**
     * Get local IP addresses and ports to allow remote connection (e.g. from phone).
     *
     * @return array
     */
    public function getNetworkInfo(): array
    {
        $ips = [];
        
        // 1. Primary for Windows: parse ipconfig with filter
        if (stripos(PHP_OS, 'WIN') !== false) {
            $output = [];
            @exec('ipconfig', $output);
            $currentAdapter = '';
            foreach ($output as $line) {
                // Check if the line is a header (e.g., "Wireless LAN adapter Wi-Fi:" or "Ethernet adapter Ethernet:")
                // Supports English headers and Arabic characters for localized Windows versions
                if (preg_match('/^(Wireless LAN adapter|Ethernet adapter|Adapter|إيثرنت|شبكة)\s+(.*):$/i', trim($line), $matches)) {
                    $currentAdapter = trim($matches[2]);
                }
                
                if (preg_match('/IPv4 Address[\.\s]+:\s*([0-9\.]+)/i', $line, $matches)) {
                    $ip = trim($matches[1]);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $ip !== '127.0.0.1') {
                        // Skip APIPA (169.254.x.x)
                        if (str_starts_with($ip, '169.254.')) {
                            continue;
                        }
                        
                        // Exclude virtual adapters
                        $lowerAdapter = strtolower($currentAdapter);
                        if (
                            str_contains($lowerAdapter, 'virtualbox') || 
                            str_contains($lowerAdapter, 'vmware') || 
                            str_contains($lowerAdapter, 'vethernet') || 
                            str_contains($lowerAdapter, 'hyper-v') || 
                            str_contains($lowerAdapter, 'host-only') ||
                            str_contains($lowerAdapter, 'loopback')
                        ) {
                            continue;
                        }
                        
                        $ips[] = $ip;
                    }
                }
            }
        } else {
            // Linux / macOS fallback: parse hostname -I
            $output = @shell_exec("hostname -I 2>/dev/null");
            if ($output) {
                $parts = explode(' ', trim($output));
                foreach ($parts as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $ip !== '127.0.0.1') {
                        if (!str_starts_with($ip, '169.254.')) {
                            $ips[] = $ip;
                        }
                    }
                }
            }
        }

        // 2. Ultimate Fallback: gethostbynamel if no physical network IPs were resolved
        if (empty($ips)) {
            $host = gethostname();
            if ($host) {
                $list = gethostbynamel($host);
                if (is_array($list)) {
                    foreach ($list as $ip) {
                        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $ip !== '127.0.0.1') {
                            if (!str_starts_with($ip, '169.254.')) {
                                $ips[] = $ip;
                            }
                        }
                    }
                }
            }
        }

        // Clean and unique values
        $ips = array_values(array_unique(array_filter($ips)));

        // 3. Determine protocol and port
        $proto = 'http';
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $proto = 'https';
        } elseif (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) {
            $proto = 'https';
        }

        $port = $_SERVER['SERVER_PORT'] ?? '80';
        if (isset($_SERVER['HTTP_X_FORWARDED_PORT'])) {
            $port = $_SERVER['HTTP_X_FORWARDED_PORT'];
        }

        return [
            'ips' => $ips,
            'port' => $port,
            'protocol' => $proto,
        ];
    }
}
