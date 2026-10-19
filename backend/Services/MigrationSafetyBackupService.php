<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Logger;
use RuntimeException;

/** Owns the database half of a migration-bearing Delta transaction. */
class MigrationSafetyBackupService
{
    private string $backupRoot;

    public function __construct(
        private readonly BackupService $backupService,
        ?string $storageDir = null,
    ) {
        $pharRunning = \Phar::running(false);
        $defaultStorage = $pharRunning ? dirname($pharRunning) . '/storage' : __DIR__ . '/../storage';
        $storage = $storageDir ?? ($_ENV['APP_STORAGE_DIR'] ?? getenv('APP_STORAGE_DIR') ?: $defaultStorage);
        $this->backupRoot = rtrim(str_replace('\\', '/', $storage), '/') . '/update-backups/migration-safety';
    }

    /** @return array{ok:bool,backup_path?:string,metadata_path?:string,sha256?:string,recovery_id?:string,error?:string} */
    public function createMigrationSafetyBackup(string $fromVersion, string $toVersion, string $recoveryId): array
    {
        $safeId = $this->normalizeRecoveryId($recoveryId);
        if (!is_dir($this->backupRoot) && !@mkdir($this->backupRoot, 0750, true)) {
            return ['ok' => false, 'error' => 'Unable to create the migration recovery backup directory.'];
        }

        try {
            $temporary = $this->backupService->createBackupFile($this->backupRoot);
            $backupPath = $this->backupRoot . '/migration-safety-' . $safeId . '.sql';
            if (!@rename($temporary, $backupPath)) {
                if (!@copy($temporary, $backupPath)) {
                    throw new RuntimeException('Unable to place the migration recovery backup.');
                }
                @unlink($temporary);
            }

            $metadata = [
                'recovery_id' => $safeId,
                'from_version' => $fromVersion,
                'to_version' => $toVersion,
                'created_at' => date('c'),
                'backup_path' => $backupPath,
                'sha256' => hash_file('sha256', $backupPath),
                'size' => filesize($backupPath) ?: 0,
            ];
            $metadataPath = $backupPath . '.json';
            if (@file_put_contents($metadataPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
                throw new RuntimeException('Unable to persist migration recovery metadata.');
            }

            $verification = $this->verifyBackup($backupPath, $safeId);
            if (!$verification['ok']) {
                throw new RuntimeException($verification['error'] ?? 'Migration recovery backup verification failed.');
            }

            return [
                'ok' => true,
                'backup_path' => $backupPath,
                'metadata_path' => $metadataPath,
                'sha256' => $metadata['sha256'],
                'recovery_id' => $safeId,
            ];
        } catch (\Throwable $exception) {
            Logger::error('Migration safety backup creation failed', [
                'recovery_id' => $safeId,
                'exception' => get_class($exception),
            ]);
            return ['ok' => false, 'error' => 'Migration safety backup could not be created and verified.'];
        }
    }

    /** @return array{ok:bool,error?:string,metadata?:array<string,mixed>} */
    public function verifyBackup(string $backupPath, ?string $recoveryId = null): array
    {
        try {
            $path = $this->assertTrustedPath($backupPath);
        } catch (RuntimeException $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
        $metadataPath = $path . '.json';
        if (!is_file($path) || !is_readable($path) || filesize($path) < 30 || !is_file($metadataPath)) {
            return ['ok' => false, 'error' => 'Migration recovery backup is missing or unreadable.'];
        }

        $metadata = json_decode((string) @file_get_contents($metadataPath), true);
        if (!is_array($metadata) || ($metadata['backup_path'] ?? null) !== $path || empty($metadata['sha256'])) {
            return ['ok' => false, 'error' => 'Migration recovery metadata is invalid.'];
        }
        if ($recoveryId !== null && ($metadata['recovery_id'] ?? null) !== $this->normalizeRecoveryId($recoveryId)) {
            return ['ok' => false, 'error' => 'Migration recovery backup does not belong to this update.'];
        }
        if (!hash_equals((string) $metadata['sha256'], (string) hash_file('sha256', $path))) {
            return ['ok' => false, 'error' => 'Migration recovery backup integrity verification failed.'];
        }

        $head = (string) @file_get_contents($path, false, null, 0, 8192);
        if (!str_contains($head, 'POS Auto-Update Backup') || !str_contains($head, 'SET FOREIGN_KEY_CHECKS=0')) {
            return ['ok' => false, 'error' => 'Migration recovery backup has an unexpected SQL format.'];
        }

        return ['ok' => true, 'metadata' => $metadata];
    }

    /** @return array{ok:bool,error?:string,code?:int} */
    public function restoreMigrationSafetyBackup(string $backupPath, string $recoveryId, ?array $connectionOverrides = null): array
    {
        $verification = $this->verifyBackup($backupPath, $recoveryId);
        if (!$verification['ok']) {
            return ['ok' => false, 'code' => 500, 'error' => $verification['error']];
        }

        try {
            $content = @file_get_contents($this->assertTrustedPath($backupPath));
            if ($content === false) {
                throw new RuntimeException('Migration recovery backup is unreadable.');
            }
            $result = $this->backupService->restoreFromSql($content, false, $connectionOverrides);
            if (!$result['ok']) {
                return ['ok' => false, 'code' => (int) ($result['code'] ?? 500), 'error' => $result['error'] ?? 'Database restore failed.'];
            }
            return ['ok' => true];
        } catch (\Throwable $exception) {
            Logger::critical('Migration safety database restore failed', [
                'recovery_id' => $this->normalizeRecoveryId($recoveryId),
                'exception' => get_class($exception),
            ]);
            return ['ok' => false, 'code' => 500, 'error' => 'Migration safety database restore failed.'];
        }
    }

    private function normalizeRecoveryId(string $recoveryId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $recoveryId);
        if ($safe === '' || $safe === null) {
            throw new RuntimeException('Invalid migration recovery identifier.');
        }
        return $safe;
    }

    private function assertTrustedPath(string $backupPath): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->backupRoot), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $backupPath);
        if (!str_starts_with($normalizedPath, $normalizedRoot) || !str_ends_with($normalizedPath, '.sql')) {
            throw new RuntimeException('Migration recovery backup path is outside protected storage.');
        }
        return $normalizedPath;
    }
}
