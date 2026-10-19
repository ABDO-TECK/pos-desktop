<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\EnvLoader;
use App\Helpers\Logger;
use PDO;
use Throwable;
use ZipArchive;

class DeltaUpdateService
{
    private string $rootDir;
    private string $storageDir;
    /** @var list<string> */
    private array $allowedUpdateHosts;
    private UpdateManifestService $manifestService;
    private ?PDO $db = null;

    public function __construct(
        ?UpdateManifestService $manifestService = null,
        ?string $rootDir = null,
        ?string $storageDir = null,
        ?PDO $db = null
    ) {
        $this->manifestService = $manifestService ?? new UpdateManifestService();
        $this->rootDir = $rootDir ?? UpdateRuntimePaths::deployedRoot(realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2));
        $this->rootDir = str_replace('\\', '/', $this->rootDir);

        $configuredStorage = EnvLoader::get('APP_STORAGE_DIR');
        $this->storageDir = $storageDir ?? ($configuredStorage ?: $this->rootDir . '/backend/storage');
        $this->storageDir = str_replace('\\', '/', $this->storageDir);
        $this->db = $db;

        $defaultHosts = 'api.github.com,github.com,raw.githubusercontent.com,objects.githubusercontent.com,github-releases.githubusercontent.com';
        $configuredHosts = EnvLoader::get('UPDATE_ALLOWED_HOSTS', $defaultHosts);
        $this->allowedUpdateHosts = array_values(array_unique(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', $configuredHosts . ',' . $defaultHosts)
        ), static fn (string $host): bool => $host !== '')));
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    public function getStagingDir(string $version): string
    {
        return $this->storageDir . '/update_staging/' . preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $version);
    }

    public function getBackupsDir(): string
    {
        return $this->storageDir . '/update-backups';
    }

    public function getStateFilePath(): string
    {
        return $this->storageDir . '/update-state.json';
    }

    /**
     * backend.phar is held open by the packaged PHP runtime on Windows. The
     * Electron owner must stop that runtime and perform the final move.
     */
    public function requiresDesktopHandoff(array $manifest): bool
    {
        if (\Phar::running(false) === '') {
            return false;
        }

        foreach ($manifest['files'] ?? [] as $file) {
            if (($file['path'] ?? null) === 'backend/backend.phar') {
                return true;
            }
        }

        return false;
    }

    /**
     * Persist a verified, local-only plan for Electron's trusted main process.
     * No network path or renderer-supplied file path is used for hand-off.
     */
    public function prepareDesktopHandoff(array $manifest, string $snapshotPath, ?array $dbRecovery = null): array
    {
        $version = (string) ($manifest['version'] ?? 'unknown');
        $stagingDir = $this->getStagingDir($version);
        $verification = $this->manifestService->verifyStagedFiles($stagingDir, $manifest['files'] ?? []);
        if (!$verification['ok']) {
            return ['ok' => false, 'error' => 'Verified staged files are unavailable for desktop hand-off.'];
        }

        $plan = [
            'version' => $version,
            'root_dir' => $this->rootDir,
            'storage_dir' => $this->storageDir,
            'staging_dir' => $stagingDir,
            'snapshot_path' => $snapshotPath,
            'db_recovery' => $dbRecovery,
            'manifest' => $manifest,
        ];
        $planPath = $stagingDir . '/desktop-handoff.json';
        if (@file_put_contents($planPath, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Unable to persist desktop update hand-off plan.'];
        }

        $this->setUpdateState('desktop_handoff_pending', [
            'to_version' => $version,
            'backup_snapshot' => $snapshotPath,
            'db_recovery' => $dbRecovery,
            'handoff_plan' => $planPath,
        ]);

        return ['ok' => true, 'version' => $version, 'plan_path' => $planPath];
    }

    // ══════════════════════════════════════════════════════════════
    //  1. Update Transaction State Management
    // ══════════════════════════════════════════════════════════════

    /**
     * Update the current transaction state in storage/update-state.json
     */
    public function setUpdateState(string $state, array $context = []): void
    {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }

        $current = $this->getUpdateState() ?? [];
        $payload = array_merge(
            $current,
            [
                'started_at' => date('Y-m-d\TH:i:sP'),
                'updated_at' => date('Y-m-d\TH:i:sP'),
            ],
            $context,
            ['state' => $state]
        );


        @file_put_contents(
            $this->getStateFilePath(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );

        if (isset($payload['owner_id']) && is_string($payload['owner_id']) && $payload['owner_id'] !== '') {
            UpdateOperationLock::heartbeatOwner(
                $this->storageDir,
                $payload['owner_id'],
                $state,
                ['to_version' => $payload['to_version'] ?? null]
            );
        }
    }

    /**
     * Get the current update transaction state.
     */
    public function getUpdateState(): ?array
    {
        $path = $this->getStateFilePath();
        if (!is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if (!$content) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    public function clearUpdateState(): void
    {
        $path = $this->getStateFilePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Detect if a previous update transaction was interrupted (e.g. power loss, crash).
     *
     * @param int $timeoutSeconds Threshold in seconds to consider an active state abandoned
     * @return array{interrupted: bool, state: string|null, snapshot_path: string|null, message: string|null, details: array|null}
     */
    public function detectInterruptedUpdate(int $timeoutSeconds = 300): array
    {
        $state = $this->getUpdateState();
        if (!$state || empty($state['state'])) {
            return [
                'interrupted' => false,
                'state' => null,
                'snapshot_path' => null,
                'message' => null,
                'details' => null,
            ];
        }

        $activeStates = [
            'applying',
            'migrating',
            'backing_up',
            'downloading',
            'verifying',
            'desktop_handoff_pending',
            'full_ready_to_install',
            'installing',
        ];
        if (in_array($state['state'], $activeStates, true)) {
            $updatedAt = isset($state['updated_at']) ? strtotime($state['updated_at']) : 0;
            $elapsed = time() - $updatedAt;

            if ($elapsed > $timeoutSeconds) {
                $snapshotPath = $state['backup_snapshot'] ?? null;
                return [
                    'interrupted' => true,
                    'state' => $state['state'],
                    'snapshot_path' => $snapshotPath,
                    'message' => "تم مقاطعة عملية تحديث سابقة بشكل غير متوقع أثناء مرحلة: {$state['state']}",
                    'details' => $state,
                ];
            }
        }

        return [
            'interrupted' => false,
            'state' => $state['state'],
            'snapshot_path' => $state['backup_snapshot'] ?? null,
            'message' => null,
            'details' => $state,
        ];
    }

    /**
     * Check available free disk space before initiating update operations.
     *
     * @param int $requiredBytes Minimum required free disk space in bytes (default: 100 MB)
     * @return array{ok: bool, free_bytes: int|float, required_bytes: int, error: string|null}
     */
    public function checkDiskSpace(int $requiredBytes = 104857600): array
    {
        $freeSpace = @disk_free_space($this->storageDir);
        if ($freeSpace === false) {
            $freeSpace = @disk_free_space($this->rootDir);
        }

        if ($freeSpace !== false && $freeSpace < $requiredBytes) {
            $freeMb = round($freeSpace / (1024 * 1024), 1);
            $reqMb = round($requiredBytes / (1024 * 1024), 1);
            return [
                'ok' => false,
                'free_bytes' => $freeSpace,
                'required_bytes' => $requiredBytes,
                'error' => "مساحة القرص غير كافية لإجراء التحديث. المتوفر: {$freeMb} MB، المطلوب: {$reqMb} MB",
            ];
        }

        return [
            'ok' => true,
            'free_bytes' => $freeSpace ?: 0,
            'required_bytes' => $requiredBytes,
            'error' => null,
        ];
    }

    /**
     * Check if a relative path is a protected production file that cannot be modified by updates.
     */
    public function isProtectedFile(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', trim($relativePath));
        
        // Public keys must be deployable for key rotation
        if (str_contains(basename($normalized), 'public') && str_ends_with($normalized, '.pem')) {
            return false;
        }

        $protectedPatterns = [
            '#(^|/)\.env#i',
            '#(^|/)\.git(/|$)#i',
            '#^\.github(/|$)#i',
            '#^backend/storage/#i',
            '#^storage/#i',
            '#(^|/)[^/]*private[^/]*\.pem$#i',
            '#\.(key|crt|cert|sqlite|db|lock|log)$#i',
            '#^backend/Config/database\.sqlite#i',
        ];


        foreach ($protectedPatterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }


    // ══════════════════════════════════════════════════════════════
    //  2. Backup Snapshot System
    // ══════════════════════════════════════════════════════════════


    /**
     * Create an atomic backup snapshot before applying updates.
     */
    public function createBackupSnapshot(
        string $fromVersion,
        string $toVersion,
        array $manifest,
        ?array $dbRecovery = null
    ): array {
        $pathCheck = $this->manifestService->validateManifestPaths($manifest, $this->rootDir);
        if (!$pathCheck['ok']) {
            $err = 'Manifest contains unsafe paths: ' . implode(', ', $pathCheck['unsafe_paths']);
            return ['ok' => false, 'snapshot_path' => '', 'backed_up_files' => [], 'new_files' => [], 'error' => $err];
        }

        $this->setUpdateState('backing_up', [
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
        ]);

        $timestamp = date('Ymd_His');
        $safeFrom = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $fromVersion);
        $safeTo = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $toVersion);
        $snapshotName = "patch_{$safeFrom}_to_{$safeTo}_{$timestamp}";
        $snapshotDir = $this->getBackupsDir() . '/' . $snapshotName;
        $filesBackupDir = $snapshotDir . '/files';

        if (!is_dir($filesBackupDir) && !@mkdir($filesBackupDir, 0755, true)) {
            $err = "Unable to create backup snapshot directory: {$filesBackupDir}";
            Logger::error($err);
            $this->setUpdateState('failed', ['error' => $err]);
            return [
                'ok' => false,
                'snapshot_path' => $snapshotDir,
                'backed_up_files' => [],
                'new_files' => [],
                'error' => $err,
            ];
        }

        $backedUpFiles = [];
        $newFiles = [];
        $existingFilesMetadata = [];
        $deletedFilesMetadata = [];

        // 1. Backup all files that will be replaced
        foreach ($manifest['files'] ?? [] as $fileEntry) {
            $relativePath = str_replace('\\', '/', $fileEntry['path'] ?? '');
            if ($relativePath === '') continue;

            $sourcePath = $this->rootDir . '/' . $relativePath;
            if (is_file($sourcePath)) {
                $destPath = $filesBackupDir . '/' . $relativePath;
                $destDir = dirname($destPath);
                if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                    $err = "Failed to create backup directory for: {$relativePath}";
                    $this->setUpdateState('failed', ['error' => $err]);
                    return [
                        'ok' => false,
                        'snapshot_path' => $snapshotDir,
                        'backed_up_files' => $backedUpFiles,
                        'new_files' => $newFiles,
                        'error' => $err,
                    ];
                }

                if (!@copy($sourcePath, $destPath)) {
                    $err = "Failed to backup file: {$relativePath}";
                    $this->setUpdateState('failed', ['error' => $err]);
                    return [
                        'ok' => false,
                        'snapshot_path' => $snapshotDir,
                        'backed_up_files' => $backedUpFiles,
                        'new_files' => $newFiles,
                        'error' => $err,
                    ];
                }

                $hash = hash_file('sha256', $sourcePath) ?: '';
                $size = filesize($sourcePath) ?: 0;
                $existingFilesMetadata[] = [
                    'path' => $relativePath,
                    'sha256' => $hash,
                    'size' => $size,
                ];
                $backedUpFiles[] = $relativePath;
            } else {
                $newFiles[] = $relativePath;
            }
        }

        // 2. Backup files that will be deleted
        foreach ($manifest['deleted_files'] ?? [] as $deletedRelativePath) {
            $relativePath = str_replace('\\', '/', $deletedRelativePath);
            if ($relativePath === '') continue;

            $sourcePath = $this->rootDir . '/' . $relativePath;
            if (is_file($sourcePath)) {
                $destPath = $filesBackupDir . '/' . $relativePath;
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0755, true);
                }

                if (@copy($sourcePath, $destPath)) {
                    $hash = hash_file('sha256', $sourcePath) ?: '';
                    $size = filesize($sourcePath) ?: 0;
                    $deletedFilesMetadata[] = [
                        'path' => $relativePath,
                        'sha256' => $hash,
                        'size' => $size,
                    ];
                    $backedUpFiles[] = $relativePath;
                }
            }
        }

        // Backup existing version.json
        $versionFile = $this->rootDir . '/version.json';
        $versionContent = is_file($versionFile) ? @file_get_contents($versionFile) : null;

        // 3. Write metadata.json
        $metadata = [
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'timestamp' => date('Y-m-d\TH:i:sP'),
            'db_backup_path' => $dbRecovery['backup_path'] ?? null,
            'db_recovery' => $dbRecovery,
            'files' => $existingFilesMetadata,
            'new_files' => $newFiles,
            'deleted_files' => $deletedFilesMetadata,
            'version_json_backup' => $versionContent,
        ];

        $metadataPath = $snapshotDir . '/metadata.json';
        @file_put_contents(
            $metadataPath,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );

        $this->setUpdateState('backing_up', [
            'backup_snapshot' => $snapshotDir,
            'db_recovery' => $dbRecovery,
        ]);

        Logger::info('Delta update backup snapshot created', [
            'snapshot' => $snapshotName,
            'backed_up_count' => count($backedUpFiles),
            'new_files_count' => count($newFiles),
        ]);

        return [
            'ok' => true,
            'snapshot_path' => $snapshotDir,
            'backed_up_files' => $backedUpFiles,
            'new_files' => $newFiles,
            'error' => null,
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  3. Download, ZIP Extraction & Staging
    // ══════════════════════════════════════════════════════════════

    /**
     * Download a GitHub Release delta zip package and extract safely to staging.
     *
     * @param array $manifest Validated manifest
     * @param string $zipUrl GitHub release asset URL for delta zip
     * @param GitHubReleaseProvider|null $provider Optional provider instance
     * @return array{ok: bool, staging_dir: string, downloaded_count: int, errors: list<string>, logs: list<string>}
     */
    public function downloadReleaseZipToStaging(
        array $manifest,
        string $zipUrl,
        ?GitHubReleaseProvider $provider = null
    ): array {
        $version = $manifest['version'] ?? 'unknown';
        $stagingDir = $this->getStagingDir($version);
        $logs = [];
        $errors = [];

        $this->setUpdateState('downloading', ['to_version' => $version, 'zip_url' => $zipUrl]);
        $logs[] = "📦 بدء تحميل حزمة التحديث المضغوطة v{$version} من GitHub Releases...";

        // Pre-validate all manifest paths before downloading
        $pathCheck = $this->manifestService->validateManifestPaths($manifest, $this->rootDir);
        if (!$pathCheck['ok']) {
            $err = 'Manifest contains unsafe paths: ' . implode(', ', $pathCheck['unsafe_paths']);
            Logger::error('Delta update unsafe paths detected', ['paths' => $pathCheck['unsafe_paths']]);
            $this->setUpdateState('failed', ['error' => $err]);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'downloaded_count' => 0,
                'errors' => [$err],
                'logs' => ["❌ {$err}"],
            ];
        }

        $this->cleanStaging($version);
        if (!is_dir($stagingDir) && !@mkdir($stagingDir, 0755, true)) {
            $err = "Unable to create staging directory at {$stagingDir}";
            $this->setUpdateState('failed', ['error' => $err]);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'downloaded_count' => 0,
                'errors' => [$err],
                'logs' => ["❌ {$err}"],
            ];
        }

        $zipPath = $stagingDir . '/delta_package.zip';

        if ($provider !== null) {
            $dlResult = $provider->downloadAssetFile($zipUrl, $zipPath);
        } else {
            $dlResult = $this->downloadFileHttp($zipUrl, $zipPath);
        }

        if (!$dlResult['ok']) {
            $this->cleanStaging($version);
            $err = "Failed to download delta zip: {$dlResult['error']}";
            $this->setUpdateState('failed', ['error' => $err]);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'downloaded_count' => 0,
                'errors' => [$err],
                'logs' => ["❌ {$err}"],
            ];
        }

        $logs[] = "✅ تم تحميل الحزمة بنجاح. جاري فك الضغط بأمان...";

        // Extract ZIP with ZipSlip protection
        $extractResult = $this->extractZipToStaging($zipPath, $stagingDir);
        @unlink($zipPath); // Clean zip archive after extraction

        if (!$extractResult['ok']) {
            $this->cleanStaging($version);
            $err = implode('; ', $extractResult['errors']);
            $this->setUpdateState('failed', ['error' => $err]);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'downloaded_count' => 0,
                'errors' => $extractResult['errors'],
                'logs' => array_merge($logs, $extractResult['logs']),
            ];
        }

        $logs = array_merge($logs, $extractResult['logs']);

        // Verify all extracted files in staging against manifest SHA-256 hashes
        $this->setUpdateState('verifying');
        $verification = $this->manifestService->verifyStagedFiles($stagingDir, $manifest['files'] ?? []);
        if (!$verification['ok']) {
            $failedSummary = [];
            foreach ($verification['failed_files'] as $failed) {
                $failedSummary[] = "{$failed['path']}: {$failed['reason']}";
                $logs[] = "❌ فشل التحقق من الملف: {$failed['path']} ({$failed['reason']})";
            }
            $this->cleanStaging($version);
            $this->setUpdateState('failed', ['error' => implode('; ', $failedSummary)]);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'downloaded_count' => $extractResult['extracted_count'],
                'errors' => $failedSummary,
                'logs' => $logs,
            ];
        }

        $logs[] = "🔒 تم التحقق بنجاح من جميع تجزئات SHA-256 وأحجام الملفات داخل الحزمة.";

        return [
            'ok' => true,
            'staging_dir' => $stagingDir,
            'downloaded_count' => $extractResult['extracted_count'],
            'errors' => [],
            'logs' => $logs,
        ];
    }

    /**
     * Safely extract a ZIP archive into staging folder with strict ZipSlip prevention.
     *
     * @param string $zipFilePath Absolute path to zip file
     * @param string $stagingDir Absolute path to destination staging directory
     * @return array{ok: bool, extracted_count: int, errors: list<string>, logs: list<string>}
     */
    public function extractZipToStaging(string $zipFilePath, string $stagingDir): array
    {
        if (!class_exists('ZipArchive')) {
            return [
                'ok' => false,
                'extracted_count' => 0,
                'errors' => ['PHP ZipArchive extension is not installed.'],
                'logs' => ['❌ امتداد PHP ZipArchive غير مثبت.'],
            ];
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipFilePath);
        if ($res !== true) {
            return [
                'ok' => false,
                'extracted_count' => 0,
                'errors' => ["Failed to open zip archive. ZipArchive error code: {$res}"],
                'logs' => ["❌ تعذر فتح الملف المضغوط (رمز الخطأ: {$res})."],
            ];
        }

        $stagingDirNormalized = rtrim(str_replace('\\', '/', $stagingDir), '/');
        $extractedCount = 0;
        $errors = [];
        $logs = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) continue;

            $normalized = str_replace('\\', '/', $entryName);

            // Strip optional root folder prefix if the zip was packaged with a root dir (e.g. "files/...")
            // ZipSlip Security checks: prevent path traversal attacks
            if (
                str_contains($normalized, '..')
                || str_starts_with($normalized, '/')
                || str_contains($normalized, "\0")
                || preg_match('/^[a-zA-Z]:/', $normalized)
            ) {
                $zip->close();
                return [
                    'ok' => false,
                    'extracted_count' => 0,
                    'errors' => ["Malicious zip entry detected (ZipSlip attack attempt): {$normalized}"],
                    'logs' => ["❌ تم اكتشاف مسار غير آمن في الأرشيف: {$normalized}"],
                ];
            }

            // Skip directory entries themselves
            if (str_ends_with($normalized, '/')) {
                continue;
            }

            // If files were packed under 'files/' prefix, normalize path
            $targetRelPath = preg_replace('#^files/#i', '', $normalized);

            $targetPath = $stagingDirNormalized . '/' . $targetRelPath;
            $targetDir = dirname($targetPath);

            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
                $errors[] = "Failed to create directory {$targetDir} for entry {$normalized}";
                continue;
            }

            $fp = $zip->getStream($entryName);
            if (!$fp) {
                $errors[] = "Failed to read zip stream for {$normalized}";
                continue;
            }

            $outFp = @fopen($targetPath, 'wb');
            if (!$outFp) {
                fclose($fp);
                $errors[] = "Failed to open destination file {$targetPath}";
                continue;
            }

            stream_copy_to_stream($fp, $outFp);
            fclose($fp);
            fclose($outFp);

            $extractedCount++;
        }

        $zip->close();

        if (!empty($errors)) {
            return [
                'ok' => false,
                'extracted_count' => $extractedCount,
                'errors' => $errors,
                'logs' => $logs,
            ];
        }

        $logs[] = "📂 تم فك ضغط {$extractedCount} ملف(ات) بنجاح.";
        return [
            'ok' => true,
            'extracted_count' => $extractedCount,
            'errors' => [],
            'logs' => $logs,
        ];
    }

    /**
     * Download individual modified files into staging directory.
     */
    public function downloadFilesToStaging(array $manifest, string $baseUrl): array
    {
        $version = $manifest['version'] ?? 'unknown';
        $stagingDir = $this->getStagingDir($version);
        $logs = [];
        $errors = [];
        $downloadedCount = 0;

        $this->setUpdateState('downloading', ['to_version' => $version]);

        $baseUrlClean = rtrim($baseUrl, '/');

        if (!$this->isAllowedUrl($baseUrlClean)) {
            $err = "Update server base URL '{$baseUrlClean}' is not in the allowed update hosts.";
            Logger::warning('Delta update rejected unapproved download URL', ['url' => $baseUrlClean]);
            $this->setUpdateState('failed', ['error' => $err]);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'downloaded_count' => 0,
                'errors' => [$err],
                'logs' => ["❌ {$err}"],
            ];
        }

        // Validate all paths are safe before attempting to download anything
        $pathCheck = $this->manifestService->validateManifestPaths($manifest, $this->rootDir);
        if (!$pathCheck['ok']) {
            $err = 'Manifest contains unsafe paths: ' . implode(', ', $pathCheck['unsafe_paths']);
            Logger::error('Delta update unsafe paths detected', ['paths' => $pathCheck['unsafe_paths']]);
            $this->setUpdateState('failed', ['error' => $err]);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'downloaded_count' => 0,
                'errors' => [$err],
                'logs' => ["❌ {$err}"],
            ];
        }

        // Prepare clean staging directory
        $this->cleanStaging($version);
        if (!is_dir($stagingDir) && !@mkdir($stagingDir, 0755, true)) {
            $err = "Unable to create staging directory at {$stagingDir}";
            Logger::error($err);
            $this->setUpdateState('failed', ['error' => $err]);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'downloaded_count' => 0,
                'errors' => [$err],
                'logs' => ["❌ {$err}"],
            ];
        }

        $logs[] = "📦 بدء تحميل حزمة التحديث الجزئي v{$version} إلى مجلد التجهيز...";

        foreach ($manifest['files'] ?? [] as $fileEntry) {
            $relativePath = str_replace('\\', '/', $fileEntry['path'] ?? '');
            $action = $fileEntry['action'] ?? 'replace';

            if ($action === 'delete') {
                continue;
            }

            $targetStagedFile = $stagingDir . '/' . $relativePath;
            $targetStagedDir = dirname($targetStagedFile);

            if (!is_dir($targetStagedDir) && !@mkdir($targetStagedDir, 0755, true)) {
                $errors[] = "Failed to create directory {$targetStagedDir}";
                continue;
            }

            // Construct file URL
            $fileUrl = $baseUrlClean . '/files/' . $relativePath;
            $logs[] = "⬇️ تحميل: {$relativePath}";

            $downloadResult = $this->downloadFileHttp($fileUrl, $targetStagedFile);
            if (!$downloadResult['ok']) {
                $errors[] = "Failed to download {$relativePath}: {$downloadResult['error']}";
                $logs[] = "❌ فشل تحميل: {$relativePath} ({$downloadResult['error']})";
                break; // Stop immediately on download failure
            }

            $downloadedCount++;
        }

        if (!empty($errors)) {
            $this->cleanStaging($version);
            $this->setUpdateState('failed', ['error' => implode('; ', $errors)]);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'downloaded_count' => $downloadedCount,
                'errors' => $errors,
                'logs' => $logs,
            ];
        }

        $logs[] = "✅ اكتمل تحميل {$downloadedCount} ملف(ات) إلى مجلد التجهيز.";

        // Verify all downloaded files in staging
        $this->setUpdateState('verifying');
        $verification = $this->manifestService->verifyStagedFiles($stagingDir, $manifest['files'] ?? []);
        if (!$verification['ok']) {
            $failedSummary = [];
            foreach ($verification['failed_files'] as $failed) {
                $failedSummary[] = "{$failed['path']}: {$failed['reason']}";
                $logs[] = "❌ فشل التحقق من الملف: {$failed['path']} ({$failed['reason']})";
            }
            $this->cleanStaging($version);
            $this->setUpdateState('failed', ['error' => implode('; ', $failedSummary)]);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'downloaded_count' => $downloadedCount,
                'errors' => $failedSummary,
                'logs' => $logs,
            ];
        }

        $logs[] = "🔒 تم التحقق بنجاح من جميع تجزئات SHA-256 وأحجام الملفات المحدثة.";

        return [
            'ok' => true,
            'staging_dir' => $stagingDir,
            'downloaded_count' => $downloadedCount,
            'errors' => [],
            'logs' => $logs,
        ];
    }

    /**
     * Copy files from a local directory into staging and verify them.
     */
    public function stageFromLocalFiles(array $manifest, string $sourceFilesDir): array
    {
        $version = $manifest['version'] ?? 'unknown';
        $stagingDir = $this->getStagingDir($version);
        $logs = [];
        $errors = [];
        $stagedCount = 0;
        $sourceFilesDirNormalized = rtrim(str_replace('\\', '/', $sourceFilesDir), '/');

        $pathCheck = $this->manifestService->validateManifestPaths($manifest, $this->rootDir);
        if (!$pathCheck['ok']) {
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'staged_count' => 0,
                'errors' => ['Manifest contains unsafe paths: ' . implode(', ', $pathCheck['unsafe_paths'])],
                'logs' => [],
            ];
        }

        $this->cleanStaging($version);
        if (!is_dir($stagingDir) && !@mkdir($stagingDir, 0755, true)) {
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'staged_count' => 0,
                'errors' => ["Unable to create staging directory at {$stagingDir}"],
                'logs' => ["❌ فشل إنشاء مجلد التجهيز."],
            ];
        }

        foreach ($manifest['files'] ?? [] as $fileEntry) {
            $relativePath = str_replace('\\', '/', $fileEntry['path'] ?? '');
            $action = $fileEntry['action'] ?? 'replace';

            if ($action === 'delete') {
                continue;
            }

            $sourcePath = $sourceFilesDirNormalized . '/' . $relativePath;
            if (!is_file($sourcePath)) {
                $flatSourcePath = $sourceFilesDirNormalized . '/' . basename($relativePath);
                if (is_file($flatSourcePath)) {
                    $sourcePath = $flatSourcePath;
                } else {
                    $errors[] = "Source file missing: {$relativePath}";
                    continue;
                }
            }

            $destPath = $stagingDir . '/' . $relativePath;
            $destDir = dirname($destPath);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                $errors[] = "Unable to create directory {$destDir}";
                continue;
            }

            if (!@copy($sourcePath, $destPath)) {
                $errors[] = "Failed to copy {$sourcePath} to {$destPath}";
                continue;
            }

            $stagedCount++;
        }

        if (!empty($errors)) {
            $this->cleanStaging($version);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'staged_count' => $stagedCount,
                'errors' => $errors,
                'logs' => $logs,
            ];
        }

        // Verify staged files
        $verification = $this->manifestService->verifyStagedFiles($stagingDir, $manifest['files'] ?? []);
        if (!$verification['ok']) {
            $failedReasons = array_map(fn ($f) => "{$f['path']}: {$f['reason']}", $verification['failed_files']);
            $this->cleanStaging($version);
            return [
                'ok' => false,
                'staging_dir' => $stagingDir,
                'staged_count' => $stagedCount,
                'errors' => $failedReasons,
                'logs' => $logs,
            ];
        }

        return [
            'ok' => true,
            'staging_dir' => $stagingDir,
            'staged_count' => $stagedCount,
            'errors' => [],
            'logs' => ["✅ تم تجهيز والتحقق من {$stagedCount} ملف(ات) بنجاح."],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  4. Atomic Apply & Automatic Rollback
    // ══════════════════════════════════════════════════════════════

    /**
     * Atomically apply verified staged files to production root.
     */
    public function applyStagedFiles(array $manifest, ?string $snapshotPath = null): array
    {
        $version = $manifest['version'] ?? 'unknown';
        $stagingDir = $this->getStagingDir($version);
        $logs = [];
        $errors = [];
        $appliedFiles = [];
        $deletedFiles = [];

        $pathCheck = $this->manifestService->validateManifestPaths($manifest, $this->rootDir);
        if (!$pathCheck['ok']) {
            $err = 'Manifest contains unsafe paths: ' . implode(', ', $pathCheck['unsafe_paths']);
            return [
                'ok' => false,
                'applied_files' => [],
                'deleted_files' => [],
                'errors' => [$err],
                'logs' => ["❌ {$err}"],
                'rolled_back' => false,
            ];
        }

        $this->setUpdateState('applying', [
            'to_version' => $version,
            'backup_snapshot' => $snapshotPath,
        ]);

        // Final verification before applying
        $verification = $this->manifestService->verifyStagedFiles($stagingDir, $manifest['files'] ?? []);
        if (!$verification['ok']) {
            $err = 'Pre-apply verification failed. Staged files are missing or modified.';
            $this->setUpdateState('failed', ['error' => $err]);
            return [
                'ok' => false,
                'applied_files' => [],
                'deleted_files' => [],
                'errors' => [$err],
                'logs' => ['❌ فشل التحقق النهائي من ملفات التجهيز قبل الاستبدال.'],
                'rolled_back' => false,
            ];
        }

        $logs[] = "🚀 تطبيق التحديث الجزئي v{$version}...";

        // Replace / Add files atomically
        foreach ($manifest['files'] ?? [] as $fileEntry) {
            $relativePath = str_replace('\\', '/', $fileEntry['path'] ?? '');
            $action = $fileEntry['action'] ?? 'replace';

            if ($action === 'delete') {
                continue;
            }

            if ($this->isProtectedFile($relativePath)) {
                $errors[] = "Security violation: Attempt to modify protected file {$relativePath}";
                $logs[] = "❌ تم حظر محاولة استبدال ملف نظام محمي: {$relativePath}";
                break;
            }

            $stagedFilePath = $stagingDir . '/' . $relativePath;
            $targetFilePath = $this->rootDir . '/' . $relativePath;
            $targetDir = dirname($targetFilePath);


            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
                $errors[] = "Failed to create target directory: {$targetDir}";
                $logs[] = "❌ تعذر إنشاء مجلد الوجهة: {$targetDir}";
                break;
            }

            // Write to temporary file in target directory first
            $tmpFile = $targetDir . '/.' . basename($relativePath) . '.tmp.' . bin2hex(random_bytes(4));

            if (!@copy($stagedFilePath, $tmpFile)) {
                $errors[] = "Failed to stage temporary file for: {$relativePath}";
                $logs[] = "❌ فشل نسخ الملف المؤقت: {$relativePath}";
                break;
            }

            // Validate hash of the temporary file before atomic rename
            $expectedHash = $fileEntry['sha256'] ?? '';
            if (!$this->manifestService->verifyFileHash($tmpFile, $expectedHash)) {
                @unlink($tmpFile);
                $errors[] = "Integrity check failed on temporary file for {$relativePath}";
                $logs[] = "❌ فشل التحقق من سلامة الملف المؤقت: {$relativePath}";
                break;
            }

            // Atomic replacement: rename tmpFile to targetFilePath
            $renameSuccess = @rename($tmpFile, $targetFilePath);
            if (!$renameSuccess) {
                // If rename failed (e.g. cross-device or Windows file locking), copy then remove tmp
                if (!@copy($tmpFile, $targetFilePath)) {
                    @unlink($tmpFile);
                    $errors[] = "Atomic replace failed for: {$relativePath}";
                    $logs[] = "❌ فشل الاستبدال النهائي للملف: {$relativePath}";
                    break;
                }
                @unlink($tmpFile);
            }

            $appliedFiles[] = $relativePath;
            $logs[] = "✅ تم استبدال: {$relativePath}";
        }

        // Process deleted files if all file replacements succeeded
        if (empty($errors)) {
            foreach ($manifest['deleted_files'] ?? [] as $deletedRelativePath) {
                $normalized = str_replace('\\', '/', $deletedRelativePath);
                if ($this->isProtectedFile($normalized)) {
                    $errors[] = "Security violation: Attempt to delete protected file {$normalized}";
                    $logs[] = "❌ تم حظر محاولة حذف ملف نظام محمي: {$normalized}";
                    break;
                }
                $targetFile = $this->rootDir . '/' . $normalized;

                if (is_file($targetFile)) {
                    if (@unlink($targetFile)) {
                        $deletedFiles[] = $normalized;
                        $logs[] = "🗑️ تم حذف: {$normalized}";
                    } else {
                        Logger::warning("Could not delete deprecated file during update: {$normalized}");
                    }
                }
            }
        }

        // Handle failure & automatic rollback
        if (!empty($errors)) {
            $errMessage = implode('; ', $errors);
            $logs[] = "⚠️ حدث خطأ أثناء تطبيق التحديث: {$errMessage}";

            if ($snapshotPath && is_dir($snapshotPath)) {
                $logs[] = "🔄 جاري التراجع التلقائي عن التعديلات وإعادة النظام لحالته السابقة...";
                $rollbackResult = $this->rollbackFiles($snapshotPath);
                $logs = array_merge($logs, $rollbackResult['logs']);
                $rollbackSucceeded = ($rollbackResult['ok'] ?? false) === true;
                $rollbackErrors = $rollbackResult['errors'] ?? [];
                if ($rollbackSucceeded) {
                    $this->setUpdateState('rolled_back', ['error' => $errMessage]);
                } else {
                    $this->setUpdateState('rollback_failed', [
                        'error' => $errMessage,
                        'backup_snapshot' => $snapshotPath,
                        'rollback_errors' => $rollbackErrors,
                        'restored_files' => $rollbackResult['restored_files'] ?? [],
                    ]);
                }

                return [
                    'ok' => false,
                    'applied_files' => $appliedFiles,
                    'deleted_files' => $deletedFiles,
                    'errors' => array_values(array_merge($errors, $rollbackErrors)),
                    'logs' => $logs,
                    'rolled_back' => $rollbackSucceeded,
                    'rollback_failed' => !$rollbackSucceeded,
                    'rollback_errors' => $rollbackErrors,
                ];
            }

            $this->setUpdateState('failed', ['error' => $errMessage]);
            return [
                'ok' => false,
                'applied_files' => $appliedFiles,
                'deleted_files' => $deletedFiles,
                'errors' => $errors,
                'logs' => $logs,
                'rolled_back' => false,
                'rollback_failed' => false,
            ];
        }

        // Update local version.json
        $this->updateLocalVersionFile($manifest);
        $logs[] = "📝 تم تحديث ملف الإصدار المحلي إلى v{$version}.";

        // Invalidate OPcache if enabled
        if (function_exists('opcache_reset')) {
            @opcache_reset();
            $logs[] = "⚡ تم تفريغ كاش PHP OPcache.";
        }

        // Clean staging directory
        $this->cleanStaging($version);
        $this->setUpdateState('completed', [
            'to_version' => $version,
            'applied_files' => $appliedFiles,
        ]);

        Logger::info("Delta update applied successfully to v{$version}", [
            'applied_files' => $appliedFiles,
            'deleted_files' => $deletedFiles,
        ]);

        return [
            'ok' => true,
            'applied_files' => $appliedFiles,
            'deleted_files' => $deletedFiles,
            'errors' => [],
            'logs' => $logs,
            'rolled_back' => false,
        ];
    }

    /**
     * Restore all files from a backup snapshot to the application root.
     */
    public function rollbackFiles(string $snapshotPath): array
    {
        $metadataFile = rtrim($snapshotPath, '/\\') . '/metadata.json';
        $filesDir = rtrim($snapshotPath, '/\\') . '/files';
        $logs = [];
        $errors = [];
        $restoredFiles = [];
        $removedNewFiles = [];

        if (!is_file($metadataFile)) {
            $err = "Backup metadata file not found at: {$metadataFile}";
            $this->setUpdateState('rollback_failed', [
                'backup_snapshot' => $snapshotPath,
                'rollback_errors' => [$err],
            ]);
            return [
                'ok' => false,
                'restored_files' => [],
                'removed_new_files' => [],
                'errors' => [$err],
                'logs' => ["❌ {$err}"],
            ];
        }

        $metadata = json_decode((string) file_get_contents($metadataFile), true);
        if (!is_array($metadata)) {
            $err = "Corrupt snapshot metadata in {$metadataFile}";
            $this->setUpdateState('rollback_failed', [
                'backup_snapshot' => $snapshotPath,
                'rollback_errors' => [$err],
            ]);
            return [
                'ok' => false,
                'restored_files' => [],
                'removed_new_files' => [],
                'errors' => [$err],
                'logs' => ["❌ {$err}"],
            ];
        }

        $logs[] = "⏪ بدء استعادة الملفات من النسخة الاحتياطية...";

        // 1. Restore all original files that were replaced
        foreach ($metadata['files'] ?? [] as $fileEntry) {
            $relativePath = str_replace('\\', '/', $fileEntry['path'] ?? '');
            if ($relativePath === '') continue;

            $backupFilePath = $filesDir . '/' . $relativePath;
            $targetFilePath = $this->rootDir . '/' . $relativePath;
            $targetDir = dirname($targetFilePath);

            if (!is_file($backupFilePath)) {
                $errors[] = "Required backup file is missing for {$relativePath}.";
                $logs[] = "❌ ملف النسخة الاحتياطية المطلوب مفقود: {$relativePath}";
                continue;
            }

            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                $errors[] = "Failed to create restore directory for {$relativePath}.";
                $logs[] = "❌ فشل إنشاء مجلد الاسترجاع: {$relativePath}";
                continue;
            }

            $tmpRestore = $targetDir . '/.' . basename($relativePath) . '.rback.' . bin2hex(random_bytes(4));
            $restored = false;
            if (@copy($backupFilePath, $tmpRestore)) {
                if (@rename($tmpRestore, $targetFilePath)) {
                    $restored = true;
                } elseif (@copy($tmpRestore, $targetFilePath) && @unlink($tmpRestore)) {
                    $restored = true;
                } else {
                    @unlink($tmpRestore);
                }
            }

            if ($restored) {
                $restoredFiles[] = $relativePath;
                $logs[] = "↩️ تم استرجاع: {$relativePath}";
            } else {
                $errors[] = "Failed to restore {$relativePath} from backup.";
                $logs[] = "❌ فشل استرجاع: {$relativePath}";
            }
        }

        // 2. Remove newly created files
        foreach ($metadata['new_files'] ?? [] as $newFilePath) {
            $relativePath = str_replace('\\', '/', $newFilePath);
            $targetFilePath = $this->rootDir . '/' . $relativePath;

            if (is_file($targetFilePath)) {
                if (@unlink($targetFilePath)) {
                    $removedNewFiles[] = $relativePath;
                    $logs[] = "🧹 تم حذف الملف المضاف حديثاً: {$relativePath}";
                }
            }
        }

        // 3. Restore files that were deleted during update
        foreach ($metadata['deleted_files'] ?? [] as $deletedEntry) {
            $relativePath = str_replace('\\', '/', $deletedEntry['path'] ?? '');
            if ($relativePath === '') continue;

            $backupFilePath = $filesDir . '/' . $relativePath;
            $targetFilePath = $this->rootDir . '/' . $relativePath;
            $targetDir = dirname($targetFilePath);

            if (!is_file($backupFilePath)) {
                $errors[] = "Required backup file is missing for deleted file {$relativePath}.";
                $logs[] = "❌ ملف النسخة الاحتياطية للملف المحذوف مفقود: {$relativePath}";
                continue;
            }

            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                $errors[] = "Failed to create restore directory for deleted file {$relativePath}.";
                $logs[] = "❌ فشل إنشاء مجلد استرجاع الملف المحذوف: {$relativePath}";
                continue;
            }

            if (@copy($backupFilePath, $targetFilePath)) {
                $restoredFiles[] = $relativePath;
                $logs[] = "↩️ تم استرجاع الملف المحذوف: {$relativePath}";
            } else {
                $errors[] = "Failed to restore deleted file {$relativePath} from backup.";
                $logs[] = "❌ فشل استرجاع الملف المحذوف: {$relativePath}";
            }
        }

        // 4. Restore original version.json
        if (array_key_exists('version_json_backup', $metadata)) {
            if (@file_put_contents($this->rootDir . '/version.json', (string) $metadata['version_json_backup'], LOCK_EX) === false) {
                $errors[] = 'Failed to restore version.json from backup.';
                $logs[] = '❌ فشل استرجاع بيانات الإصدار.';
            } else {
                $logs[] = "📝 تم استرجاع بيانات الإصدار v" . ($metadata['from_version'] ?? '?');
            }
        }

        // 5. Invalidate OPcache
        if (function_exists('opcache_reset')) {
            @opcache_reset();
            $logs[] = "⚡ تم تفريغ كاش PHP OPcache.";
        }

        if (empty($errors)) {
            $this->setUpdateState('rolled_back', [
                'backup_snapshot' => $snapshotPath,
                'restored_files' => $restoredFiles,
            ]);
        } else {
            $this->setUpdateState('rollback_failed', [
                'backup_snapshot' => $snapshotPath,
                'restored_files' => $restoredFiles,
                'rollback_errors' => $errors,
            ]);
        }

        Logger::warning('Update files rolled back to snapshot', [
            'snapshot' => basename($snapshotPath),
            'from_version' => $metadata['from_version'] ?? '?',
            'restored_count' => count($restoredFiles),
        ]);

        return [
            'ok' => empty($errors),
            'restored_files' => $restoredFiles,
            'removed_new_files' => $removedNewFiles,
            'errors' => $errors,
            'logs' => $logs,
        ];
    }

    /**
     * Recovery command: Rollback update automatically or from a specified snapshot.
     */
    public function rollbackUpdate(?string $snapshotPath = null): array
    {
        $targetSnapshot = $snapshotPath;

        if ($targetSnapshot === null) {
            $targetSnapshot = $this->findLatestSnapshot();
        }

        if (!$targetSnapshot || !is_dir($targetSnapshot)) {
            $err = 'No backup snapshot found for rollback.';
            Logger::error($err);
            return [
                'ok' => false,
                'snapshot' => null,
                'logs' => ["❌ {$err}"],
                'error' => $err,
            ];
        }

        $result = $this->rollbackFiles($targetSnapshot);

        $metadataFile = $targetSnapshot . '/metadata.json';
        $fromVer = 'unknown';
        $toVer = 'unknown';
        if (is_file($metadataFile)) {
            $m = json_decode((string) file_get_contents($metadataFile), true);
            $fromVer = $m['from_version'] ?? 'unknown';
            $toVer = $m['to_version'] ?? 'unknown';
        }

        $rollbackStatus = !empty($result['ok']) ? 'rolled_back' : 'rollback_failed';
        $this->recordHistory(
            $toVer,
            $fromVer,
            'delta',
            $rollbackStatus,
            count($result['restored_files']),
            $targetSnapshot,
            !empty($result['errors']) ? implode('; ', $result['errors']) : 'Manual rollback executed.',
            'github_release',
            "v{$toVer}"
        );

        return [
            'ok' => $result['ok'],
            'snapshot' => $targetSnapshot,
            'logs' => $result['logs'],
            'error' => !empty($result['errors']) ? implode('; ', $result['errors']) : null,
        ];
    }

    public function findLatestSnapshot(): ?string
    {
        $backupsDir = $this->getBackupsDir();
        if (!is_dir($backupsDir)) {
            return null;
        }

        $dirs = glob($backupsDir . '/patch_*', GLOB_ONLYDIR) ?: [];
        if (empty($dirs)) {
            return null;
        }

        rsort($dirs);
        return $dirs[0] ?? null;
    }

    // ══════════════════════════════════════════════════════════════
    //  5. Update History Recording
    // ══════════════════════════════════════════════════════════════

    /**
     * Record update transaction in database table update_history
     */
    public function recordHistory(
        string $fromVersion,
        string $toVersion,
        string $type,
        string $status,
        int $filesCount = 0,
        ?string $backupPath = null,
        ?string $errorMessage = null,
        string $source = 'github_release',
        ?string $releaseTag = null,
        ?string $downloadUrl = null
    ): void {
        try {
            $db = $this->db ?? Database::getInstance();
            if (!$db) {
                return;
            }

            // Attempt insert with extended fields
            try {
                $stmt = $db->prepare("
                    INSERT INTO update_history (from_version, to_version, type, source, release_tag, status, files_count, backup_path, download_url, error_message)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $fromVersion,
                    $toVersion,
                    $type,
                    $source,
                    $releaseTag,
                    $status,
                    $filesCount,
                    $backupPath ? str_replace('\\', '/', $backupPath) : null,
                    $downloadUrl,
                    $errorMessage,
                ]);
            } catch (Throwable $t) {
                // Fallback for unextended table schema
                $stmt = $db->prepare("
                    INSERT INTO update_history (from_version, to_version, type, status, files_count, backup_path, error_message)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $fromVersion,
                    $toVersion,
                    $type,
                    $status,
                    $filesCount,
                    $backupPath ? str_replace('\\', '/', $backupPath) : null,
                    $errorMessage,
                ]);
            }
        } catch (Throwable $e) {
            Logger::warning('Could not record update history to database', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════

    public function cleanStaging(string $version): void
    {
        $stagingDir = $this->getStagingDir($version);
        if (is_dir($stagingDir)) {
            $this->deleteDirectoryRecursive($stagingDir);
        }
    }

    private function updateLocalVersionFile(array $manifest): void
    {
        $versionFile = $this->rootDir . '/version.json';
        $current = [];
        if (is_file($versionFile)) {
            $existingContent = @file_get_contents($versionFile);
            if ($existingContent) {
                $current = json_decode($existingContent, true) ?: [];
            }
        }

        $current['version'] = $manifest['version'] ?? ($current['version'] ?? '0.0.0');
        $current['released_at'] = $manifest['released_at'] ?? date('Y-m-d');
        if (isset($manifest['changelog']) && is_array($manifest['changelog'])) {
            $current['changelog'] = $manifest['changelog'];
        }
        if (isset($manifest['requires_npm_install'])) {
            $current['requires_npm_install'] = (bool) $manifest['requires_npm_install'];
        }

        @file_put_contents(
            $versionFile,
            json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
    }

    private function downloadFileHttp(string $url, string $destPath): array
    {
        $fp = @fopen($destPath, 'w+b');
        if (!$fp) {
            return ['ok' => false, 'error' => "Cannot open destination file {$destPath} for writing."];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'ABDO-TECK-POS-DeltaUpdater/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
        ]);

        $certPath = $this->resolveCurlCaBundlePath();
        if ($certPath !== null && $certPath !== '' && !str_starts_with($certPath, 'phar://') && is_file($certPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $certPath);
        }

        $success = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode < 200 || $httpCode >= 400) {
            @unlink($destPath);
            $reason = $httpCode > 0 ? "HTTP {$httpCode}" : $curlErr;
            return ['ok' => false, 'error' => "cURL download failed: {$reason}"];
        }

        return ['ok' => true, 'error' => null];
    }

    public function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        foreach ($this->allowedUpdateHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function resolveCurlCaBundlePath(): ?string
    {
        return UpdateRuntimePaths::getCaBundlePath($this->rootDir);
    }

    private function deleteDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
