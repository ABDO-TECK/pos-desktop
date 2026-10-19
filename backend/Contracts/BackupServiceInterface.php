<?php
namespace App\Contracts;

interface BackupServiceInterface
{
    public function createBackupFile(string $backupDir): string;
    public function streamBackup(): void;
    public function validateUploadedSqlFile(array $file): array;
    public function restoreFromSql(string $sqlContent, bool $runMigrations = true): array;
}
