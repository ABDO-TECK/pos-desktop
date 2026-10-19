<?php
/**
 * CLI Seeder — تشغيل بيانات البذر الافتراضية.
 *
 * الاستخدام:
 *   php backend/cli/seed.php
 *
 * The first administrator is created separately by initialize-admin.php so
 * seed data can be reused safely during a factory reset without a shared
 * password.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: CLI only.');
}

require_once __DIR__ . '/../vendor/autoload.php';
\App\Helpers\ErrorHandler::register();
require_once __DIR__ . '/../Config/config.php';

use App\Config\Database;

echo "Running Database Seeders...\n";
echo "===========================\n";

try {
    $db = Database::getInstance();
    $pharRunning = \Phar::running(false);
    $seedDir = $pharRunning
        ? 'phar://' . str_replace('\\', '/', $pharRunning) . '/database/seeders'
        : __DIR__ . '/../../database/seeders';

    if (!is_dir($seedDir)) {
        echo "Seeder directory not found: database/seeders/\n";
        exit(1);
    }

    $files = ['permissions_seed.sql', 'default_data.sql'];
    
    // إضافة الديمو إذا تم طلبها
    global $argv;
    if (in_array('--demo', $argv ?? [])) {
        $files[] = 'demo_data.sql';
        echo "Demo mode activated! Will insert demo data.\n";
    }

    $executed = 0;
    foreach ($files as $file) {
        $name = basename($file);
        echo "  Seeding: {$name} ... ";
        
        $filePath = $seedDir . '/' . $name;
        if (!file_exists($filePath)) {
            echo "NOT FOUND (skipped)\n";
            continue;
        }

        $sql = file_get_contents($filePath);
        if (!$sql) {
            echo "EMPTY (skipped)\n";
            continue;
        }

        // Remove full-line SQL comments before splitting. The old parser
        // discarded any statement preceded by a comment because every chunk
        // started with `--`, leaving a fresh install without categories and
        // default settings.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        // تنفيذ كل statement على حدة
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => $s !== ''
        );

        foreach ($statements as $stmt) {
            $db->exec($stmt);
        }

        echo "OK\n";
        $executed++;
    }

    echo "===========================\n";
    echo "Done. Executed {$executed} seeder(s).\n";
} catch (\Throwable $e) {
    echo "ERROR: {$e->getMessage()}\n";
    exit(1);
}
