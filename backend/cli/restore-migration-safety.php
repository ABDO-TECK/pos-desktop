<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Forbidden: CLI only.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';
\App\Helpers\ErrorHandler::register();
require_once __DIR__ . '/../Config/config.php';

use App\Helpers\Logger;
use App\Services\BackupService;
use App\Services\MigrationSafetyBackupService;

$backupPath = trim((string) ($argv[1] ?? ''));
$recoveryId = trim((string) ($argv[2] ?? ''));
if ($backupPath === '' || $recoveryId === '' || str_starts_with($backupPath, '--')) {
    fwrite(STDERR, "Usage: php backend.phar restore-migration-safety <backup.sql> <recovery-id>\n");
    exit(2);
}

try {
    $service = new MigrationSafetyBackupService(new BackupService());
    $result = $service->restoreMigrationSafetyBackup($backupPath, $recoveryId);
    if (!$result['ok']) {
        fwrite(STDERR, ($result['error'] ?? 'Migration safety restore failed.') . "\n");
        exit((int) ($result['code'] ?? 1));
    }

    fwrite(STDOUT, "Migration safety database restore completed.\n");
    exit(0);
} catch (\Throwable $exception) {
    $reference = bin2hex(random_bytes(8));
    Logger::critical('CLI migration safety restore failed', [
        'reference' => $reference,
        'recovery_id' => $recoveryId,
        'exception' => get_class($exception),
    ]);
    fwrite(STDERR, "Migration safety restore failed. Reference: {$reference}\n");
    exit(1);
}
