<?php

namespace App\Services;

use App\Config\Database;
use App\Core\Container;
use App\Services\MigrationService;
use mysqli;
use App\Contracts\BackupServiceInterface;
use App\Helpers\Logger;
use PDO;
use RuntimeException;


class BackupService implements BackupServiceInterface {
    private ?PDO $db = null;

    public function __construct() {
    }

    public function setDb(PDO $db): void {
        $this->db = $db;
    }

    private function getDb(): PDO {
        if ($this->db === null) {
            $this->db = Database::getInstance();
        }
        return $this->db;
    }

    /**
     * Create a backup file in the specified directory.
     * Returns the full path to the backup file.
     */
    public function createBackupFile(string $backupDir): string {
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }
        $filename = rtrim($backupDir, '/\\') . '/pre_update_' . date('Y-m-d_H-i-s') . '.sql';
        $this->generateBackupSqlToFile($filename);

        // Keep only last 10 backups
        $files = glob(rtrim($backupDir, '/\\') . '/pre_update_*.sql') ?: [];
        rsort($files);
        foreach (array_slice($files, 10) as $old) {
            @unlink($old);
        }

        return $filename;
    }

    /**
     * تدفق (Stream) النسخة الاحتياطية لقاعدة البيانات مباشرة للعميل للتحميل.
     * يولد أمر التصدير ويطبعه فوراً لتقليل استهلاك الذاكرة.
     *
     * @return void
     */
    public function streamBackup(): void {
        $tables = $this->getDb()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        echo "-- POS Database Backup\n";
        echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        echo "-- POS System Database Backup\n\n";
        echo "SET FOREIGN_KEY_CHECKS=0;\n\n";
        flush();

        foreach ($tables as $table) {
            // Derived metadata is recreated by its migration after restore.
            if ($table === 'product_catalog_trigger_metadata') continue;
            // Validate table name to prevent SQL injection from a compromised DB
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                echo "-- SKIPPED suspicious table name: " . addslashes($table) . "\n";
                flush();
                continue;
            }
            // Table structure
            $createStmt = $this->getDb()->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $ddl = $createStmt['Create Table'] ?? null;
            if ($ddl === null && is_array($createStmt)) {
                $vals = array_values($createStmt);
                $ddl  = $vals[1] ?? '';
            }
            if (!$ddl) {
                continue;
            }

            echo "-- Table: $table\n";
            echo "DROP TABLE IF EXISTS `$table`;\n";
            echo $ddl . ";\n\n";
            flush();

            // Table data (Streamed row by row)
            $stmt = $this->getDb()->query("SELECT * FROM `$table`");
            $firstRow = true;
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($firstRow) {
                    $columns = '`' . implode('`, `', array_keys($row)) . '`';
                    echo "INSERT INTO `$table` ($columns) VALUES\n";
                    $firstRow = false;
                } else {
                    echo ",\n";
                }

                $escaped = array_map(function ($v) {
                    return $v === null ? 'NULL' : $this->getDb()->quote((string)$v);
                }, array_values($row));
                
                echo '(' . implode(', ', $escaped) . ')';
            }
            
            if (!$firstRow) {
                echo ";\n\n";
            }
            flush();
        }

        // Triggers
        try {
            $triggers = $this->getDb()->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($triggers as $trg) {
                $trgName = $trg['Trigger'] ?? '';
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $trgName)) {
                    continue;
                }
                $createStmt = $this->getDb()->query("SHOW CREATE TRIGGER `{$trgName}`")->fetch(PDO::FETCH_ASSOC);
                $ddl = $createStmt['SQL Original Statement'] ?? null;
                if (!$ddl && is_array($createStmt)) {
                    $vals = array_values($createStmt);
                    $ddl = $vals[2] ?? '';
                }
                if ($ddl) {
                    $cleanDdl = preg_replace('/^CREATE\s+DEFINER=`?[^`@\s]+`?@`?[^`@\s]+`?\s+/i', 'CREATE ', $ddl);
                    echo "-- Trigger: {$trgName}\n";
                    echo "DROP TRIGGER IF EXISTS `{$trgName}`;\n";
                    echo $cleanDdl . ";\n\n";
                    flush();
                }
            }
        } catch (\Throwable) {
            // Non-critical: some setups may lack SHOW TRIGGERS
        }

        echo "SET FOREIGN_KEY_CHECKS=1;\n";
        flush();
    }

    // ── Restore ─────────────────────────────────────────────

    /**
     * التحقق من صحة ملف SQL المرفوع.
     *
     * @param array $file   مصفوفة $_FILES['sql_file']
     * @return array ['ok' => true, 'content' => string] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function validateUploadedSqlFile(array $file): array
    {
        if (empty($file) || (int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'لم يتم رفع الملف أو فشل الرفع', 'code' => 400];
        }

        $name = (string) ($file['name'] ?? '');
        if (!str_ends_with(strtolower($name), '.sql')) {
            return ['ok' => false, 'error' => 'يجب أن يكون الملف بصيغة .sql', 'code' => 400];
        }

        $maxBytes = 50 * 1024 * 1024; // 50 MB
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            return ['ok' => false, 'error' => 'حجم الملف يتجاوز الحد المسموح (50 ميجابايت)', 'code' => 400];
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false || strlen($content) < 30) {
            return ['ok' => false, 'error' => 'الملف فارغ أو غير قابل للقراءة', 'code' => 400];
        }

        // إزالة BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // تنظيف UNSIGNED لمنع خطأ Foreign Key errno 150
        $content = preg_replace('/\b((?:INT|BIGINT|TINYINT|SMALLINT|MEDIUMINT)(?:\s*\([\d]+\))?)\s+UNSIGNED\b/i', '$1', $content);

        if (!preg_match('/\b(DROP\s+TABLE|CREATE\s+TABLE|INSERT\s+INTO)\b/is', $content)) {
            return ['ok' => false, 'error' => 'محتوى الملف لا يبدو ملف SQL صالحاً لقاعدة البيانات', 'code' => 400];
        }

        // منع أوامر خطرة — فحص الأوامر الإدارية والخطرة
        // 1. Strip comments
        $stripped = preg_replace('/\/\*.*?\*\//s', ' ', $content);   // block comments
        $stripped = preg_replace('/--[^\n]*/', ' ', $stripped);       // line comments
        // 2. Strip string literals so legitimate data values (e.g. descriptions mentioning "system") do not trigger false positives
        $stripped = preg_replace("/'(?:''|\\\\'|[^'])*'/s", "''", $stripped);
        // 3. Strip backtick-quoted identifiers so legitimate column names (e.g. `source`) do not trigger false positives
        $stripped = preg_replace('/`[^`]*`/', ' ', $stripped);

        $dangerousPatterns = [
            '/\b(OUTFILE|DUMPFILE|LOAD_FILE|INTO\s+OUTFILE)\b/is',
            '/\b(GRANT|REVOKE|CREATE\s+USER|ALTER\s+USER|DROP\s+USER)\b/is',
            '/\b(LOAD\s+DATA)\b/is',
            '/\b(SLEEP|BENCHMARK|GET_LOCK)\s*\(/is',
            '/\b(DROP\s+DATABASE)\b/is',
            '/\b(SHUTDOWN)\b/is',
            '/(?:^|;)\s*SOURCE\s+/is',
        ];
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $stripped)) {
                return ['ok' => false, 'error' => 'الملف يحتوي على أوامر غير مسموحة', 'code' => 400];
            }
        }

        return ['ok' => true, 'content' => $content];
    }

    /**
     * تنفيذ استعادة قاعدة البيانات من محتوى SQL + إعادة تشغيل الترحيلات.
     *
     * @param string $sqlContent  محتوى SQL المُنظّف
     * @param bool $runMigrations تشغيل الهجرات التلقائية بعد الاستيراد
     * @param array<string, mixed>|null $connectionOverrides تخصيص إعدادات الاتصال للاختبارات
     * @return array ['ok' => true, 'message' => string] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function restoreFromSql(string $sqlContent, bool $runMigrations = true, ?array $connectionOverrides = null, bool $cleanDatabase = true): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('SQL restore is CLI-only');
        }

        if ($connectionOverrides === null && $this->db !== null) {
            try {
                $activeDb = $this->db->query('SELECT DATABASE()')->fetchColumn();
                if ($activeDb) {
                    $connectionOverrides = ['name' => (string) $activeDb];
                }
            } catch (\Throwable) {
                // Ignore fallback resolution error
            }
        }

        try {
            $mysqli = Database::getMigrationMysqli($connectionOverrides);
        } catch (\Throwable $e) {
            $reference = bin2hex(random_bytes(8));
            Logger::error('Backup restore database connection failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => "Database restore failed. Reference: {$reference}", 'code' => 500];
        }

        $mysqli->set_charset('utf8mb4');

        // Relax strict mode only where needed — keep basic safety checks active
        $mysqli->query("SET sql_mode='NO_ENGINE_SUBSTITUTION'");
        $mysqli->query("SET FOREIGN_KEY_CHECKS=0");

        if ($cleanDatabase) {
            // Drop views first
            $viewsResult = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'");
            if ($viewsResult) {
                while ($row = $viewsResult->fetch_row()) {
                    $viewName = (string) $row[0];
                    if (preg_match('/^[a-zA-Z0-9_]+$/', $viewName)) {
                        $mysqli->query("DROP VIEW IF EXISTS `{$viewName}`");
                    }
                }
                $viewsResult->free();
            }

            // Drop base tables
            $tablesResult = $mysqli->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            if ($tablesResult) {
                while ($row = $tablesResult->fetch_row()) {
                    $tableName = (string) $row[0];
                    if (preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                        $mysqli->query("DROP TABLE IF EXISTS `{$tableName}`");
                    }
                }
                $tablesResult->free();
            }
        }

        if (!$mysqli->multi_query($sqlContent)) {
            $reference = bin2hex(random_bytes(8));
            Logger::error('Backup restore execution failed', [
                'reference' => $reference,
                'code' => $mysqli->errno,
            ]);
            $mysqli->close();
            return ['ok' => false, 'error' => "Database restore failed. Reference: {$reference}", 'code' => 500];
        }

        do {
            if ($res = $mysqli->store_result()) {
                $res->free();
            }
            if (!$mysqli->more_results()) {
                break;
            }
            if (!$mysqli->next_result()) {
                $reference = bin2hex(random_bytes(8));
                Logger::error('Backup restore result processing failed', [
                    'reference' => $reference,
                    'code' => $mysqli->errno,
                ]);
                $mysqli->close();
                return ['ok' => false, 'error' => "Database restore failed. Reference: {$reference}", 'code' => 500];
            }
        } while (true);

        $mysqli->close();
        Database::resetInstance();

        // Interactive restores may upgrade an old backup. Migration-safety
        // restores must leave the exact pre-update schema/version in place.
        if (!$runMigrations) {
            try {
                $migrationService = new MigrationService(Database::getMigrationConnection($connectionOverrides));
                $metadataErrors = $migrationService->reconcileLegacyArtifacts();
            } catch (\Throwable $exception) {
                $metadataErrors = ['Backup metadata could not be restored.'];
            }
            if (!empty($metadataErrors)) {
                $reference = bin2hex(random_bytes(8));
                Logger::error('Post-restore metadata reconciliation failed', [
                    'reference' => $reference,
                    'errors' => $metadataErrors,
                ]);
                return ['ok' => false, 'error' => "Backup metadata restore failed. Reference: {$reference}", 'code' => 500];
            }
            return ['ok' => true, 'message' => 'Database restored from verified backup.'];
        }

        // حذف flag الـ Smart Skip لضمان فحص جميع الترحيلات
        $pharRunning = \Phar::running(false);
        $storageDir = $_ENV['APP_STORAGE_DIR'] ?? (getenv('APP_STORAGE_DIR') ?: null) ?? ($pharRunning ? dirname($pharRunning) . '/storage' : __DIR__ . '/../storage');
        $flagFile = rtrim($storageDir, '/\\') . '/migrations_hash.flag';
        if ($flagFile && is_file($flagFile)) {
            @unlink($flagFile);
        }

        // تشغيل جميع الهجرات بحساب الهجرة (Migration Connection)
        Database::resetInstance();
        $migrationConnection = Database::getMigrationConnection($connectionOverrides);
        $migrationService = new MigrationService($migrationConnection);
        $migrationResult = $migrationService->runAllMigrations(true);

        if (!empty($migrationResult['errors'])) {
            $reference = bin2hex(random_bytes(8));
            Logger::error('Post-restore migrations failed', [
                'reference' => $reference,
                'errors' => $migrationResult['errors'],
            ]);
            return [
                'ok' => false,
                'error' => "Post-restore migrations failed. Reference: {$reference}",
                'code' => 500,
                'migration_errors' => $migrationResult['errors'],
            ];
        }

        $msg = 'تمت استعادة قاعدة البيانات بنجاح';
        if ($migrationResult['executed'] > 0) {
            $msg .= '، وتمت ترقيتها للإصدار الحديث (' . $migrationResult['executed'] . ' تحديثات).';
        }

        return ['ok' => true, 'message' => $msg];
    }

    /**
     * Generate backup SQL directly to a file using streaming (row-by-row).
     * This avoids loading the entire database into memory.
     */
    private function generateBackupSqlToFile(string $filePath): void {
        $fh = fopen($filePath, 'w');
        if ($fh === false) {
            throw new RuntimeException("Cannot open backup file for writing: $filePath");
        }

        try {
            $tables = $this->getDb()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

            fwrite($fh, "-- POS Auto-Update Backup\n");
            fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                // The restricted backup account must not need SHOW VIEW.
                if ($table === 'product_catalog_trigger_metadata') continue;
                // Validate table name to prevent SQL injection from a compromised DB
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                    fwrite($fh, "-- SKIPPED suspicious table name: " . addslashes($table) . "\n");
                    continue;
                }
                $createStmt = $this->getDb()->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $ddl = $createStmt['Create Table'] ?? null;
                if ($ddl === null && is_array($createStmt)) {
                    $vals = array_values($createStmt);
                    $ddl  = $vals[1] ?? '';
                }
                if (!$ddl) {
                    throw new RuntimeException("تعذر قراءة هيكل الجدول: $table");
                }

                fwrite($fh, "-- Table: $table\n");
                fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
                fwrite($fh, $ddl . ";\n\n");

                // Stream rows one at a time — no fetchAll()
                $stmt = $this->getDb()->query("SELECT * FROM `$table`");
                $firstRow = true;

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($firstRow) {
                        $columns = '`' . implode('`, `', array_keys($row)) . '`';
                        fwrite($fh, "INSERT INTO `$table` ($columns) VALUES\n");
                        $firstRow = false;
                    } else {
                        fwrite($fh, ",\n");
                    }

                    $escaped = array_map(function ($v) {
                        return $v === null ? 'NULL' : $this->getDb()->quote((string)$v);
                    }, array_values($row));

                    fwrite($fh, '(' . implode(', ', $escaped) . ')');
                }

                if (!$firstRow) {
                    fwrite($fh, ";\n\n");
                }
            }

            // Triggers
            try {
                $triggers = $this->getDb()->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($triggers as $trg) {
                    $trgName = $trg['Trigger'] ?? '';
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $trgName)) {
                        continue;
                    }
                    $createStmt = $this->getDb()->query("SHOW CREATE TRIGGER `{$trgName}`")->fetch(PDO::FETCH_ASSOC);
                    $ddl = $createStmt['SQL Original Statement'] ?? null;
                    if (!$ddl && is_array($createStmt)) {
                        $vals = array_values($createStmt);
                        $ddl = $vals[2] ?? '';
                    }
                    if ($ddl) {
                        $cleanDdl = preg_replace('/^CREATE\s+DEFINER=`?[^`@\s]+`?@`?[^`@\s]+`?\s+/i', 'CREATE ', $ddl);
                        fwrite($fh, "-- Trigger: {$trgName}\n");
                        fwrite($fh, "DROP TRIGGER IF EXISTS `{$trgName}`;\n");
                        fwrite($fh, $cleanDdl . ";\n\n");
                    }
                }
            } catch (\Throwable) {
                // Non-critical: some setups may lack SHOW TRIGGERS
            }

            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fh);
        }
    }
}
