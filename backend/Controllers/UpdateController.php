<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Helpers\EnvLoader;
use App\Helpers\JobQueue;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Services\AuthService;
use App\Services\ReleaseVersionPolicy;
use App\Services\UpdateService;
use Throwable;

class UpdateController extends Controller
{
    private AuthService $authService;
    private UpdateService $updateService;

    public function __construct(AuthService $authService, UpdateService $updateService)
    {
        $this->authService   = $authService;
        $this->updateService = $updateService;
    }

    // ══════════════════════════════════════════════════════════════
    //  1. GET /api/updates/status & /api/update/status
    // ══════════════════════════════════════════════════════════════

    public function status(?string $id = null)
    {
        // If an ID is passed, check background job status for backward compatibility
        if ($id !== null && $id !== '') {
            $job = JobQueue::find($this->resolveId($id));
            if (!$job || $job['job_name'] !== 'apply_update') {
                return Response::notFound('Update job not found');
            }
            return Response::success($job);
        }

        $local = $this->updateService->getLocalVersion();
        $currentVersion = $local['version'] ?? '0.0.0';
        $state = $this->updateService->getDeltaUpdateService()->getUpdateState();

        // Check if there is cached or active release info
        $remote = $this->updateService->fetchRemoteVersion(null, false) ?? [];
        $latestVersion = $remote['version'] ?? null;
        $compatibility = ReleaseVersionPolicy::assess($currentVersion, $latestVersion);
        $hasUpdate = $compatibility['compatible']
            && version_compare((string) $latestVersion, (string) $currentVersion, '>');
        $visibleLatestVersion = $compatibility['compatible'] ? $latestVersion : null;

        $type = 'full';
        $bootstrapRequired = false;
        $filesCount = null;
        if (!$compatibility['compatible']) {
            $type = 'none';
        } elseif (!empty($remote['manifest']['files']) && is_array($remote['manifest']['files'])) {
            $type = 'delta';
            $filesCount = count($remote['manifest']['files']);
        } elseif (!empty($remote['delta_url'])) {
            $type = 'delta';
        }

        if ($type === 'delta' && !$this->hasDesktopHandoffCapability()) {
            $type = 'bootstrap';
            $bootstrapRequired = true;
        }

        $releaseInfo = [
            'title' => $compatibility['compatible'] ? ($remote['tag_name'] ?? ($latestVersion ? "Release v{$latestVersion}" : null)) : null,
            'tag_name' => $compatibility['compatible'] ? ($remote['tag_name'] ?? ($latestVersion ? "v{$latestVersion}" : null)) : null,
            'changelog' => $compatibility['compatible'] ? ($remote['changelog'] ?? []) : [],
            'released_at' => $compatibility['compatible'] ? ($remote['released_at'] ?? null) : null,
            'files_count' => $filesCount,
            'release_url' => $compatibility['compatible'] ? ($remote['release_url'] ?? null) : null,
            'download_url' => $compatibility['compatible'] ? ($remote['delta_url'] ?? ($remote['download_url'] ?? null)) : null,
            'full_package_url' => $compatibility['compatible'] ? ($remote['full_package_url'] ?? null) : null,
        ];

        $interruptedUpdate = $this->updateService->getDeltaUpdateService()->detectInterruptedUpdate();
        $clientChannel = $this->updateService->getClientChannel();

        return Response::success([
            'current_version'    => $currentVersion,
            'latest_version'     => $visibleLatestVersion,
            'update_available'   => $hasUpdate,
            'type'               => $type,
            'bootstrap_required' => $bootstrapRequired,
            'channel'            => $clientChannel,
            'available_channels' => ['stable', 'beta', 'rc'],
            'device_id'          => substr($this->updateService->getDeviceId(), 0, 8) . '...',
            'release_info'       => $releaseInfo,
            'update_state'       => $state,
            'interrupted_update' => $interruptedUpdate,
            'compatibility_reason' => $compatibility['reason'],
            'compatibility_reason_code' => $compatibility['reason_code'],
        ]);
    }

    public function getChannel()
    {
        return Response::success([
            'channel' => $this->updateService->getClientChannel(),
            'available_channels' => ['stable', 'beta', 'rc'],
            'device_id' => $this->updateService->getDeviceId(),
        ]);
    }

    public function setChannel()
    {
        $user = $this->authService->user();
        $userId = $user['id'] ?? null;
        $role = $user['role'] ?? 'user';

        if ($role !== 'admin' && !$this->authService->hasPermission($userId, 'updates.manage_channel')) {
            return Response::forbidden('You do not have permission to change release update channels.');
        }

        $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
        $channel = trim($input['channel'] ?? '');

        if (!in_array($channel, ['stable', 'beta', 'rc'], true)) {
            return Response::error('Invalid channel. Allowed: stable, beta, rc.', 400);
        }

        $result = $this->updateService->setClientChannel($channel);
        if (!$result['ok']) {
            return Response::error($result['error'] ?? 'Failed to update channel.', 500);
        }

        Logger::info('Update channel changed', ['user_id' => $userId, 'channel' => $channel]);
        return Response::success($result);
    }


    // ══════════════════════════════════════════════════════════════
    //  2. POST /api/updates/check & GET /api/update/check
    // ══════════════════════════════════════════════════════════════

    public function check()
    {
        $body = $this->getBody();
        $force = isset($body['force']) ? filter_var($body['force'], FILTER_VALIDATE_BOOLEAN) : true;
        $result = $this->updateService->checkForUpdate($this->hasDesktopHandoffCapability(), $force);
        return Response::success($result);
    }

    /**
     * GET /api/bootstrap/update & /api/update/bootstrap
     * Minimal bootstrap migration bridge for legacy clients (e.g. v1.1.46).
     */
    public function bootstrapUpdate()
    {
        $currentVersion = $this->updateService->getLocalVersion()['version'] ?? '0.0.0';
        $remote = $this->updateService->fetchRemoteVersion();
        if (!$remote || empty($remote['version'])) {
            return Response::error('Bootstrap release metadata unavailable.', 503);
        }

        $manifest = $remote['manifest'] ?? [];
        $targetVersion = $remote['version'];
        $compatibility = ReleaseVersionPolicy::assess($currentVersion, $targetVersion);
        if (!$compatibility['compatible']) {
            $statusCode = $compatibility['reason_code'] === 'legacy_generation' ? 409 : 422;
            return Response::error($compatibility['reason'], $statusCode, [
                'reason_code' => $compatibility['reason_code'],
                'current_version' => $currentVersion,
                'target_version' => $targetVersion,
            ]);
        }
        $packageUrl = $remote['full_package_url'] ?? "https://github.com/ABDO-TECK/pos-desktop/releases/download/v{$targetVersion}/full-package.zip";
        $manifestUrl = $remote['manifest_url'] ?? "https://github.com/ABDO-TECK/pos-desktop/releases/download/v{$targetVersion}/manifest.json";
        $signatureUrl = $remote['signature_url'] ?? "https://github.com/ABDO-TECK/pos-desktop/releases/download/v{$targetVersion}/manifest.sig";

        $sha256 = $manifest['package_sha256'] ?? ($remote['package_sha256'] ?? null);

        return Response::success([
            'target_version'     => $targetVersion,
            'bootstrap_release'  => true,
            'package_url'        => $packageUrl,
            'manifest_url'       => $manifestUrl,
            'signature_url'      => $signatureUrl,
            'package_sha256'     => $sha256,
            'changelog'          => $remote['changelog'] ?? [],
            'requires_full_pack' => true,
        ]);
    }

    public function changelog()
    {
        $currentVersion = $this->updateService->getLocalVersion()['version'] ?? '0.0.0';
        $remote = $this->updateService->fetchRemoteVersion();
        $compatibility = ReleaseVersionPolicy::assess($currentVersion, $remote['version'] ?? null);
        if (!$compatibility['compatible']) {
            return Response::success([]);
        }
        return Response::success($remote['changelog'] ?? []);
    }


    // ══════════════════════════════════════════════════════════════
    //  3. POST /api/updates/apply & POST /api/update/apply
    // ══════════════════════════════════════════════════════════════

    public function apply()
    {
        if (!EnvLoader::getBool('ENABLE_AUTO_UPDATE', true)) {
            return Response::error('التحديث التلقائي معطل. الرجاء تفعيله من ملف .env (ENABLE_AUTO_UPDATE=true)', 403);
        }

        $user = $this->authService->user();
        $body  = $this->getBody();
        $force = isset($body['force']) && filter_var($body['force'], FILTER_VALIDATE_BOOLEAN);

        $startTime = microtime(true);
        Logger::info('Admin update apply initiated', [
            'user_id'  => $user['id'] ?? null,
            'username' => $user['username'] ?? 'system',
            'role'     => $user['role'] ?? 'unknown',
            'force'    => $force,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        // Execute update
        $result = $this->updateService->applyUpdate($force, $this->hasDesktopHandoffCapability($body));
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        if (!$result['ok']) {
            Logger::error('Admin update apply failed', [
                'user_id'     => $user['id'] ?? null,
                'duration_ms' => $durationMs,
                'error'       => $result['error'] ?? 'Unknown error',
            ]);

            $code = $result['code'] ?? 500;
            return Response::error(
                $result['error'] ?? 'فشل تطبيق التحديث.',
                $code,
                $result['data'] ?? null
            );
        }

        Logger::info('Admin update apply completed successfully', [
            'user_id'        => $user['id'] ?? null,
            'latest_version' => $result['data']['latest_version'] ?? null,
            'duration_ms'    => $durationMs,
        ]);

        return Response::success(
            $result['data'] ?? [],
            'تم تطبيق التحديث بنجاح'
        );
    }

    private function hasDesktopHandoffCapability(?array $body = null): bool
    {
        $header = $_SERVER['HTTP_X_POS_DELTA_HANDOFF'] ?? '';
        if ($header !== '1') {
            return false;
        }

        if ($body === null) {
            return true;
        }

        return filter_var($body['delta_capable'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    // ══════════════════════════════════════════════════════════════
    //  4. GET /api/updates/history
    // ══════════════════════════════════════════════════════════════

    public function history()
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query('SELECT * FROM update_history ORDER BY id DESC LIMIT 50');
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return Response::success($rows);
        } catch (Throwable $e) {
            Logger::warning('Could not retrieve update history: ' . $e->getMessage());
            return Response::success([]);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  5. POST /api/updates/rollback
    // ══════════════════════════════════════════════════════════════

    public function rollback()
    {
        $user = $this->authService->user();
        $body = $this->getBody();
        $snapshotPath = isset($body['snapshot_path']) && is_string($body['snapshot_path'])
            ? trim($body['snapshot_path'])
            : null;

        if (\App\Services\UpdateRuntimePaths::isPackagedPharDeployment($this->updateService->getRootDir()) && empty($body['delta_capable'])) {
            return Response::error('يلزم تشغيل الاسترجاع من تطبيق سطح المكتب الذي يدعم الاستبدال الآمن.', 426);
        }

        Logger::info('Admin update rollback initiated', [
            'user_id'       => $user['id'] ?? null,
            'username'      => $user['username'] ?? 'system',
            'snapshot_path' => $snapshotPath,
        ]);

        $result = $this->updateService->rollbackUpdate($snapshotPath);

        if (!$result['ok']) {
            Logger::error('Admin update rollback failed', [
                'user_id' => $user['id'] ?? null,
                'error'   => $result['error'] ?? 'Unknown error',
            ]);

            return Response::error(
                $result['error'] ?? 'فشل التراجع عن التحديث.',
                $result['code'] ?? 500,
                ['logs' => $result['logs'] ?? []]
            );
        }

        Logger::info(!empty($result['requires_desktop_handoff']) ? 'Admin rollback prepared for desktop handoff' : 'Admin update rollback completed successfully', [
            'user_id'  => $user['id'] ?? null,
            'snapshot' => $result['snapshot'] ?? null,
        ]);

        return Response::success(
            $result,
            !empty($result['requires_desktop_handoff']) ? 'تم التحقق من نقطة الاسترجاع وتجهيز الاستبدال الآمن بعد إيقاف الخدمات.' : 'تم التراجع عن التحديث بنجاح وإعادة النظام لحالته السابقة'
        );
    }


    // ══════════════════════════════════════════════════════════════
    //  6. GET /api/updates/snapshots
    // ══════════════════════════════════════════════════════════════

    public function snapshots()
    {
        $planner = new \App\Services\UpdateRollbackPlanner($this->updateService->getDeltaUpdateService());
        return Response::success($planner->availableSnapshots());
    }

    /**
     * GET /api/updates/customer-status
     * Simple customer-facing update status representation without technical jargon.
     */
    public function customerStatus()
    {
        $local = $this->updateService->getLocalVersion();
        $currentVersion = $local['version'] ?? ($local['application_version'] ?? '1.1.46');
        $remote = $this->updateService->fetchRemoteVersion() ?? [];
        $latestVersion = $remote['version'] ?? null;
        $compatibility = ReleaseVersionPolicy::assess($currentVersion, $latestVersion);
        $hasUpdate = $compatibility['compatible']
            && version_compare((string) $latestVersion, (string) $currentVersion, '>');
        $visibleLatestVersion = $compatibility['compatible'] ? $latestVersion : null;

        $updateType = 'delta_update';
        $size = 0;
        $manifest = $compatibility['compatible'] ? ($remote['manifest'] ?? []) : [];
        if (!empty($manifest['type']) && $manifest['type'] === 'bootstrap_installer') {
            $updateType = 'bootstrap_installer';
            $size = (int) ($manifest['installer_size'] ?? 296929980);
        } elseif (!empty($manifest['package_size'])) {
            $size = (int) $manifest['package_size'];
        }

        $releaseNotes = is_array($remote['changelog'] ?? null) 
            ? implode("\n", $remote['changelog']) 
            : ($compatibility['compatible'] ? ($remote['changelog'] ?? 'تحديثات وتحسينات في الأداء والاستقرار.') : '');

        return Response::success([
            'current_version'   => $currentVersion,
            'available_version' => $visibleLatestVersion,
            'update_available'  => $hasUpdate,
            'update_type'       => $updateType,
            'size'              => $size,
            'release_notes'     => $releaseNotes,
            'mandatory'         => (bool) ($manifest['mandatory'] ?? false),
            'installer_name'    => $manifest['installer_name'] ?? ($updateType === 'bootstrap_installer' && $visibleLatestVersion ? "POS-Desktop-Setup-{$visibleLatestVersion}.exe" : null),
            'compatibility_reason' => $compatibility['reason'],
            'compatibility_reason_code' => $compatibility['reason_code'],
        ]);
    }

    /**
     * POST /api/updates/customer-result
     * Ingest customer update lifecycle events into UpdateTelemetryService.
     */
    public function customerResult()
    {
        $body = $this->getJsonBody();
        $telemetryService = new \App\Services\UpdateTelemetryService();

        $eventType = $body['event_type'] ?? 'update_ui_opened';
        $currentVersion = $body['current_version'] ?? ($this->updateService->getLocalVersion()['version'] ?? 'unknown');
        $targetVersion = $body['target_version'] ?? null;
        $success = $body['success'] ?? true;
        $errorCode = $body['error_code'] ?? null;
        $durationMs = isset($body['duration_ms']) ? (int) $body['duration_ms'] : null;
        $metadata = $body['metadata'] ?? [];

        $deviceId = $body['device_id'] ?? $this->updateService->getDeviceId();

        $recorded = $telemetryService->recordEvent([
            'device_id'       => $deviceId,
            'current_version' => $currentVersion,
            'target_version'  => $targetVersion,
            'channel'         => $this->updateService->getClientChannel(),
            'event_type'      => $eventType,
            'success'         => (bool) $success,
            'error_code'      => $errorCode,
            'duration_ms'     => $durationMs,
            'metadata'        => $metadata,
        ]);

        return Response::success([
            'recorded' => $recorded,
            'event'    => $eventType,
        ]);
    }
}
