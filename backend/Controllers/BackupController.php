<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Services\BackupService;
use PDO;


class BackupController extends Controller {

    private PDO $db;
    private BackupService $backupService;

    public function __construct(PDO $db, BackupService $backupService) {
        $this->db = $db;
        $this->backupService = $backupService;
    }

    public function download() {
        $filename = 'pos_backup_' . date('Y-m-d_H-i-s') . '.sql';

        // Set headers for streaming download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');
        
        // Disable output buffering to stream directly
        if (ob_get_level()) ob_end_clean();

        $this->backupService->streamBackup();
        exit;
    }

    /**
     * استعادة قاعدة البيانات من ملف SQL.
     * POST multipart: الحقل sql_file
     */
    public function restore() {
        if (PHP_SAPI !== 'cli') {
            \App\Helpers\Logger::warning('HTTP restore attempted — blocked; use the local desktop restore flow');
            return Response::error(
                'HTTP restore is disabled; use the desktop application restore action or the CLI command.',
                410
            );
        }

        // 1. التحقق من صحة الملف
        $validation = $this->backupService->validateUploadedSqlFile($_FILES['sql_file'] ?? []);
        if (!$validation['ok']) {
            return Response::error($validation['error'], $validation['code']);
        }

        // 2. تنفيذ الاستعادة
        $result = $this->backupService->restoreFromSql($validation['content']);
        if (!$result['ok']) {
            return Response::error($result['error'], $result['code']);
        }

        return Response::success(null, $result['message']);
    }

    /**
     * POST /api/admin/backup/schedule
     * جدولة نسخ احتياطي في الخلفية عبر Job Queue.
     */
    public function schedule(): array
    {
        \App\Helpers\JobQueue::dispatch('backup_database', [], 1);
        return Response::success(null, 'تم جدولة النسخ الاحتياطي بنجاح');
    }
}
