<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Services\AuthService;
use App\Services\UpdateTelemetryService;

class TelemetryController extends Controller
{
    private AuthService $authService;
    private UpdateTelemetryService $telemetryService;

    public function __construct(AuthService $authService, ?UpdateTelemetryService $telemetryService = null)
    {
        $this->authService = $authService;
        $this->telemetryService = $telemetryService ?? new UpdateTelemetryService();
    }

    /**
     * POST /api/telemetry/updates
     * Ingest single telemetry event.
     */
    public function record()
    {
        $body = $this->getBody();
        if (empty($body) || !is_array($body)) {
            return Response::error('Missing or invalid telemetry payload.', 422);
        }

        $recorded = $this->telemetryService->recordEvent($body);
        if (!$recorded) {
            return Response::error('Telemetry event rejected: invalid schema or unknown event type.', 422);
        }

        return Response::success(['recorded' => true], 'Telemetry event received successfully.');
    }

    /**
     * POST /api/telemetry/updates/batch
     * Ingest batch of telemetry events.
     */
    public function recordBatch()
    {
        $body = $this->getBody();
        $events = isset($body['events']) && is_array($body['events']) ? $body['events'] : $body;

        if (!is_array($events) || empty($events)) {
            return Response::error('Missing or invalid batch events payload.', 422);
        }

        $result = $this->telemetryService->recordBatch($events);
        return Response::success($result, 'Batch telemetry processed.');
    }

    /**
     * GET /api/admin/fleet/stats
     * Fleet telemetry analytics and active health alerts.
     */
    public function stats()
    {
        $user = $this->authService->user();
        $userId = $user['id'] ?? null;
        $role = $user['role'] ?? 'user';

        if ($role !== 'admin' && !$this->authService->hasPermission($userId, 'updates.telemetry.view')) {
            return Response::forbidden('You do not have permission to view fleet telemetry.');
        }

        $stats = $this->telemetryService->getFleetStats();
        return Response::success($stats);
    }

    /**
     * GET /api/admin/fleet/devices
     * List unique fleet devices.
     */
    public function devices()
    {
        $user = $this->authService->user();
        $userId = $user['id'] ?? null;
        $role = $user['role'] ?? 'user';

        if ($role !== 'admin' && !$this->authService->hasPermission($userId, 'updates.telemetry.view')) {
            return Response::forbidden('You do not have permission to view fleet telemetry.');
        }

        $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 50;
        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
        $search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : null;

        $devices = $this->telemetryService->getFleetDevices($limit, $offset, $search);
        return Response::success($devices);
    }

    /**
     * GET /api/admin/fleet/devices/{id}
     * Get specific device telemetry timeline.
     */
    public function deviceDetails(string $id)
    {
        $user = $this->authService->user();
        $userId = $user['id'] ?? null;
        $role = $user['role'] ?? 'user';

        if ($role !== 'admin' && !$this->authService->hasPermission($userId, 'updates.telemetry.view')) {
            return Response::forbidden('You do not have permission to view fleet telemetry.');
        }

        $details = $this->telemetryService->getDeviceDetails($id);
        if ($details === null) {
            return Response::notFound("Device '{$id}' not found or has no telemetry events.");
        }

        return Response::success($details);
    }

    /**
     * POST /api/admin/fleet/purge
     * Purge old telemetry records based on retention policy.
     */
    public function purge()
    {
        $user = $this->authService->user();
        $userId = $user['id'] ?? null;
        $role = $user['role'] ?? 'user';

        if ($role !== 'admin' && !$this->authService->hasPermission($userId, 'updates.telemetry.manage')) {
            return Response::forbidden('You do not have permission to manage telemetry data.');
        }

        $body = $this->getBody();
        $days = isset($body['days']) && is_numeric($body['days']) ? (int) $body['days'] : 90;

        $purgedCount = $this->telemetryService->purgeOldRecords($days);
        Logger::info('Telemetry records purged by admin', ['user_id' => $userId, 'days' => $days, 'count' => $purgedCount]);

        return Response::success(['deleted_count' => $purgedCount, 'retention_days' => $days], "Successfully purged {$purgedCount} telemetry records.");
    }
}
