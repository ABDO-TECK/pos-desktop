<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Services\AuthService;
use App\Services\UpdateRecoveryService;

class UpdateRecoveryController extends Controller
{
    private AuthService $authService;
    private UpdateRecoveryService $recoveryService;

    public function __construct(AuthService $authService, ?UpdateRecoveryService $recoveryService = null)
    {
        $this->authService = $authService;
        $this->recoveryService = $recoveryService ?? new UpdateRecoveryService();
    }

    /**
     * GET /api/admin/updates/recovery/diagnose
     * Diagnose interrupted update or system faults.
     */
    public function diagnose()
    {
        $user = $this->authService->user();
        $userId = $user['id'] ?? null;
        $role = $user['role'] ?? 'user';

        if ($role !== 'admin' && !$this->authService->hasPermission($userId, 'updates.recovery.view')) {
            return Response::forbidden('You do not have permission to view update recovery diagnostics.');
        }

        $diagnosis = $this->recoveryService->diagnoseState();
        $diagnosis['is_locked'] = $this->recoveryService->isLocked();
        $diagnosis['auto_recovery_enabled'] = $this->recoveryService->isAutoRecoveryEnabled();

        return Response::success($diagnosis);
    }

    /**
     * POST /api/admin/updates/recovery/execute
     * Trigger manual recovery action.
     */
    public function execute()
    {
        $user = $this->authService->user();
        $userId = $user['id'] ?? null;
        $role = $user['role'] ?? 'user';

        if ($role !== 'admin' && !$this->authService->hasPermission($userId, 'updates.recovery.manage')) {
            return Response::forbidden('You do not have permission to execute update recovery actions.');
        }

        $body = $this->getBody();
        $action = isset($body['action']) && is_string($body['action']) ? trim($body['action']) : '';

        if ($action === '') {
            return Response::error('Missing required recovery action.', 422);
        }

        $result = $this->recoveryService->executeAction($action, $body);

        if (!$result['ok']) {
            return Response::error($result['error'] ?? 'Recovery action failed.', 500, $result);
        }

        Logger::info('Update recovery action executed by admin', [
            'user_id' => $userId,
            'action'  => $action,
        ]);

        return Response::success($result, $result['message'] ?? 'Recovery action completed successfully.');
    }

    /**
     * GET /api/admin/updates/recovery/audit
     * Get recent recovery audit history.
     */
    public function audit()
    {
        $user = $this->authService->user();
        $userId = $user['id'] ?? null;
        $role = $user['role'] ?? 'user';

        if ($role !== 'admin' && !$this->authService->hasPermission($userId, 'updates.recovery.view')) {
            return Response::forbidden('You do not have permission to view recovery audit logs.');
        }

        $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 50;
        $auditLogs = $this->recoveryService->getAuditLog($limit);

        return Response::success(['logs' => $auditLogs, 'total' => count($auditLogs)]);
    }

    /**
     * POST /api/admin/updates/recovery/health-check
     * Run post-update health validation on demand.
     */
    public function healthCheck()
    {
        $user = $this->authService->user();
        $userId = $user['id'] ?? null;
        $role = $user['role'] ?? 'user';

        if ($role !== 'admin' && !$this->authService->hasPermission($userId, 'updates.recovery.view')) {
            return Response::forbidden('You do not have permission to run post-update health checks.');
        }

        $body = $this->getBody();
        $snapshot = $body['snapshot_path'] ?? null;

        $health = $this->recoveryService->validatePostUpdateHealth($snapshot);
        return Response::success($health);
    }
}
