<?php

declare(strict_types=1);

/**
 * Restore a validated SQL backup from the command line.
 *
 * Usage:
 *   php backend/cli/restore-backup.php path/to/backup.sql
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Forbidden: CLI only.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';
\App\Helpers\ErrorHandler::register();
require_once __DIR__ . '/../Config/config.php';

use App\Helpers\Logger;
use App\Services\BackupService;

$path = trim((string) ($argv[1] ?? ''));
if ($path === '' || str_starts_with($path, '--')) {
    fwrite(STDERR, "Usage: php backend/cli/restore-backup.php <backup.sql>\n");
    exit(2);
}

$resolvedPath = realpath($path);
if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
    fwrite(STDERR, "Backup file does not exist or is not readable.\n");
    exit(2);
}

$size = filesize($resolvedPath);
if ($size === false) {
    fwrite(STDERR, "Unable to inspect the backup file.\n");
    exit(2);
}

try {
    $service = new BackupService();
    $validation = $service->validateUploadedSqlFile([
        'name' => basename($resolvedPath),
        'error' => UPLOAD_ERR_OK,
        'size' => $size,
        'tmp_name' => $resolvedPath,
    ]);

    if (!$validation['ok']) {
        fwrite(STDERR, (string) $validation['error'] . "\n");
        exit((int) ($validation['code'] ?? 400));
    }

    $skipMigrations = in_array('--skip-migrations', $argv, true) || in_array('--no-migrations', $argv, true);
    fwrite(STDOUT, "Restoring validated backup: " . basename($resolvedPath) . "\n");
    $result = $service->restoreFromSql((string) $validation['content'], !$skipMigrations);
    if (!$result['ok']) {
        fwrite(STDERR, (string) $result['error'] . "\n");
        exit((int) ($result['code'] ?? 1));
    }

    fwrite(STDOUT, (string) $result['message'] . "\n");
    exit(0);
} catch (Throwable $exception) {
    $reference = bin2hex(random_bytes(8));
    Logger::error('CLI backup restore failed', [
        'reference' => $reference,
        'exception' => get_class($exception),
    ]);
    fwrite(STDERR, "Backup restore failed. Reference: {$reference}\n");
    exit(1);
}
