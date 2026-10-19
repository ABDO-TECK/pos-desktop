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

$recoveryId = trim((string) ($argv[1] ?? ''));
if ($recoveryId === '' || str_starts_with($recoveryId, '--')) {
    fwrite(STDERR, "Usage: php backend.phar create-restore-safety <recovery-id>\n");
    exit(2);
}

try {
    $service = new MigrationSafetyBackupService(new BackupService());
    $result = $service->createMigrationSafetyBackup('current', 'restore', $recoveryId);

    if (!$result['ok']) {
        fwrite(STDERR, ($result['error'] ?? 'Pre-restore safety snapshot creation failed.') . "\n");
        exit(1);
    }

    echo json_encode([
        'ok' => true,
        'backup_path' => $result['backup_path'],
        'metadata_path' => $result['metadata_path'] ?? null,
        'recovery_id' => $result['recovery_id'],
        'sha256' => $result['sha256'],
    ], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (\Throwable $exception) {
    $reference = bin2hex(random_bytes(8));
    Logger::error('CLI pre-restore safety snapshot failed', [
        'reference' => $reference,
        'recovery_id' => $recoveryId,
        'exception' => get_class($exception),
    ]);
    fwrite(STDERR, "Pre-restore safety snapshot failed. Reference: {$reference}\n");
    exit(1);
}
