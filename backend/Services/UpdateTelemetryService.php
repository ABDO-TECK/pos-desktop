<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\EnvLoader;
use App\Helpers\Logger;
use PDO;
use Throwable;

/**
 * Service for managing privacy-preserving update telemetry and fleet analytics.
 */
class UpdateTelemetryService
{
    public const ALLOWED_EVENTS = [
        'update_check_started',
        'update_available',
        'update_ui_opened',
        'update_download_started',
        'update_download_completed',
        'installer_started',
        'installer_completed',
        'installer_failed',
        'update_applied',
        'update_failed',
        'rollback_completed',
        'update_recovery_started',
        'update_recovery_completed',
        'update_recovery_failed',
        'update_auto_rollback',
    ];

    public const TEST_DEVICE_PREFIXES = [
        'local-terminal',
        'local-test-',
        'local-test_',
        'test-',
        'test_',
        'dev-',
        'dev_',
        'fixture-',
        'fixture_',
        'sandbox-',
        'sandbox_',
        'dummy-',
        'dummy_',
    ];

    public static function isTestDeviceId(string $deviceId): bool
    {
        $trimmed = trim(strtolower($deviceId));
        if ($trimmed === '' || $trimmed === 'local-terminal') {
            return true;
        }
        foreach (self::TEST_DEVICE_PREFIXES as $prefix) {
            if (str_starts_with($trimmed, strtolower($prefix))) {
                return true;
            }
        }
        return false;
    }

    public static function getTestDeviceExclusionSql(string $column = 'device_id'): string
    {
        return "({$column} NOT IN ('local-terminal') "
            . "AND {$column} NOT LIKE 'local-test-%' "
            . "AND {$column} NOT LIKE 'local-test_%' "
            . "AND {$column} NOT LIKE 'test-%' "
            . "AND {$column} NOT LIKE 'test_%' "
            . "AND {$column} NOT LIKE 'dev-%' "
            . "AND {$column} NOT LIKE 'dev_%' "
            . "AND {$column} NOT LIKE 'fixture-%' "
            . "AND {$column} NOT LIKE 'sandbox-%' "
            . "AND {$column} NOT LIKE 'dummy-%')";
    }

    protected string $storageDir;
    protected string $queueFile;
    protected ?PDO $pdo;
    protected ?string $appRoot;

    public function __construct(?string $storageDir = null, ?PDO $pdo = null, ?string $appRoot = null)
    {
        $this->storageDir = $storageDir ?? (realpath(__DIR__ . '/../storage') ?: __DIR__ . '/../storage');
        $this->queueFile = $this->storageDir . '/telemetry_queue.json';
        $this->pdo = $pdo;
        $this->appRoot = $appRoot !== null
            ? UpdateRuntimePaths::deployedRoot($appRoot)
            : UpdateRuntimePaths::deployedRoot(realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2));
    }

    /**
     * Resolves the canonical current release / target version for fleet comparison.
     */
    public function getTargetVersion(): ?string
    {
        $root = $this->appRoot ?? UpdateRuntimePaths::deployedRoot(realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2));
        $vFile = $root . '/version.json';
        if (file_exists($vFile)) {
            $data = json_decode((string) @file_get_contents($vFile), true);
            if (is_array($data)) {
                if (!empty($data['version']) && is_string($data['version'])) {
                    return (string) $data['version'];
                }
                if (!empty($data['application_version']) && is_string($data['application_version'])) {
                    return (string) $data['application_version'];
                }
            }
        }
        return null;
    }

    protected function getPdo(): ?PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        // Prevent tests from accidentally connecting to the real database
        if (defined('PHPUNIT_TEST_SUITE') || getenv('APP_ENV') === 'testing') {
            return null;
        }

        try {
            if (!defined('DB_HOST')) {
                $configFile = dirname(__DIR__) . '/Config/config.php';
                if (file_exists($configFile)) {
                    @include_once $configFile;
                }
            }

            return Database::getInstance();
        } catch (Throwable) {
            return null;
        }
    }


    /**
     * Check if telemetry collection is globally enabled.
     */
    public function isTelemetryEnabled(): bool
    {
        return EnvLoader::getBool('ENABLE_UPDATE_TELEMETRY', true);
    }

    /**
     * Ingest and record a single update telemetry event.
     * Guaranteed non-blocking: never throws exceptions, falls back to offline queue.
     *
     * @param array $payload
     * @return bool True if validated and stored (or queued), false if payload is invalid
     */
    public function recordEvent(array $payload): bool
    {
        if (!$this->isTelemetryEnabled()) {
            return true;
        }

        $validated = $this->validatePayload($payload);
        if ($validated === null) {
            return false;
        }

        $pdo = $this->getPdo();
        if ($pdo === null) {
            $this->enqueueOffline($validated);
            return true;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO update_telemetry (
                    device_id, current_version, target_version, channel,
                    event_type, success, error_code, duration_ms, metadata, created_at
                ) VALUES (
                    :device_id, :current_version, :target_version, :channel,
                    :event_type, :success, :error_code, :duration_ms, :metadata, :created_at
                )
            ");


            $stmt->execute([
                ':device_id'       => $validated['device_id'],
                ':current_version' => $validated['current_version'],
                ':target_version'  => $validated['target_version'],
                ':channel'         => $validated['channel'],
                ':event_type'      => $validated['event_type'],
                ':success'         => $validated['success'] ? 1 : 0,
                ':error_code'      => $validated['error_code'],
                ':duration_ms'     => $validated['duration_ms'],
                ':metadata'        => !empty($validated['metadata']) ? json_encode($validated['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':created_at'      => $validated['created_at'] ?? date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (Throwable $e) {
            // Database failed or offline - queue locally without breaking POS operations
            Logger::warning('UpdateTelemetryService DB write failed, queueing offline', [
                'error' => $e->getMessage(),
                'event_type' => $validated['event_type'],
            ]);
            $this->enqueueOffline($validated);
            return true;
        }
    }

    /**
     * Ingest a batch of telemetry events.
     *
     * @param list<array> $events
     * @return array{received: int, inserted: int, queued: int, invalid: int}
     */
    public function recordBatch(array $events): array
    {
        $inserted = 0;
        $queued = 0;
        $invalid = 0;

        $pdo = $this->getPdo();
        if ($pdo === null) {
            foreach ($events as $event) {
                if (is_array($event) && ($val = $this->validatePayload($event))) {
                    $this->enqueueOffline($val);
                    $queued++;
                } else {
                    $invalid++;
                }
            }
            return ['received' => count($events), 'inserted' => 0, 'queued' => $queued, 'invalid' => $invalid];
        }


        foreach ($events as $event) {
            if (!is_array($event)) {
                $invalid++;
                continue;
            }

            $validated = $this->validatePayload($event);
            if ($validated === null) {
                $invalid++;
                continue;
            }

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO update_telemetry (
                        device_id, current_version, target_version, channel,
                        event_type, success, error_code, duration_ms, metadata, created_at
                    ) VALUES (
                        :device_id, :current_version, :target_version, :channel,
                        :event_type, :success, :error_code, :duration_ms, :metadata, :created_at
                    )
                ");
                $stmt->execute([
                    ':device_id'       => $validated['device_id'],
                    ':current_version' => $validated['current_version'],
                    ':target_version'  => $validated['target_version'],
                    ':channel'         => $validated['channel'],
                    ':event_type'      => $validated['event_type'],
                    ':success'         => $validated['success'] ? 1 : 0,
                    ':error_code'      => $validated['error_code'],
                    ':duration_ms'     => $validated['duration_ms'],
                    ':metadata'        => !empty($validated['metadata']) ? json_encode($validated['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                    ':created_at'      => $validated['created_at'] ?? date('Y-m-d H:i:s'),
                ]);
                $inserted++;
            } catch (Throwable $e) {
                $this->enqueueOffline($validated);
                $queued++;
            }
        }

        return [
            'received' => count($events),
            'inserted' => $inserted,
            'queued'   => $queued,
            'invalid'  => $invalid,
        ];
    }

    /**
     * Flush offline queued telemetry events into database.
     */
    public function flushLocalQueue(): int
    {
        if (!file_exists($this->queueFile)) {
            return 0;
        }

        $content = @file_get_contents($this->queueFile);
        if (!$content) {
            return 0;
        }

        $items = json_decode($content, true);
        if (!is_array($items) || empty($items)) {
            @unlink($this->queueFile);
            return 0;
        }

        $pdo = $this->getPdo();
        if ($pdo === null) {
            return 0;
        }

        $flushed = 0;
        $remaining = [];

        try {
            $stmt = $pdo->prepare("
                INSERT INTO update_telemetry (
                    device_id, current_version, target_version, channel,
                    event_type, success, error_code, duration_ms, metadata, created_at
                ) VALUES (
                    :device_id, :current_version, :target_version, :channel,
                    :event_type, :success, :error_code, :duration_ms, :metadata, :created_at
                )
            ");

            foreach ($items as $item) {
                try {
                    $stmt->execute([
                        ':device_id'       => $item['device_id'],
                        ':current_version' => $item['current_version'],
                        ':target_version'  => $item['target_version'],
                        ':channel'         => $item['channel'],
                        ':event_type'      => $item['event_type'],
                        ':success'         => !empty($item['success']) ? 1 : 0,
                        ':error_code'      => $item['error_code'] ?? null,
                        ':duration_ms'     => $item['duration_ms'] ?? null,
                        ':metadata'        => !empty($item['metadata']) ? json_encode($item['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                        ':created_at'      => $item['created_at'] ?? date('Y-m-d H:i:s'),
                    ]);
                    $flushed++;
                } catch (Throwable) {
                    $remaining[] = $item;
                }
            }

            if (empty($remaining)) {
                @unlink($this->queueFile);
            } else {
                @file_put_contents($this->queueFile, json_encode($remaining, JSON_PRETTY_PRINT));
            }
        } catch (Throwable) {
            // Leave queue file intact
        }

        return $flushed;
    }

    /**
     * Calculate fleet health and telemetry analytics.
     */
    public function getFleetStats(): array
    {
        $this->flushLocalQueue();

        try {
            $pdo = $this->getPdo();
            if ($pdo === null) {
                throw new \RuntimeException('Database is unavailable');
            }

            $deviceFilter = self::getTestDeviceExclusionSql('device_id');
            $tDeviceFilter = self::getTestDeviceExclusionSql('t.device_id');
            $intervalSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? "datetime('now', '-30 days')"
                : "DATE_SUB(NOW(), INTERVAL 30 DAY)";

            // Total devices seen in last 30 days (excluding test/diagnostic identities)
            $stmt = $pdo->query("
                SELECT COUNT(DISTINCT device_id) as total_devices 
                FROM update_telemetry 
                WHERE created_at >= {$intervalSql}
                  AND {$deviceFilter}
            ");
            $totalDevices = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total_devices'] ?? 0);

            // Latest version per device (excluding test/diagnostic identities)
            $stmt = $pdo->query("
                SELECT t.current_version, COUNT(*) as count
                FROM update_telemetry t
                INNER JOIN (
                    SELECT device_id, MAX(id) as max_id
                    FROM update_telemetry
                    WHERE {$deviceFilter}
                    GROUP BY device_id
                ) latest ON t.id = latest.max_id
                WHERE {$tDeviceFilter}
                GROUP BY t.current_version
                ORDER BY count DESC
            ");
            $versionDist = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $versionDist[$row['current_version']] = (int) $row['count'];
            }

            // Latest channel per device (excluding test/diagnostic identities)
            $stmt = $pdo->query("
                SELECT t.channel, COUNT(*) as count
                FROM update_telemetry t
                INNER JOIN (
                    SELECT device_id, MAX(id) as max_id
                    FROM update_telemetry
                    WHERE {$deviceFilter}
                    GROUP BY device_id
                ) latest ON t.id = latest.max_id
                WHERE {$tDeviceFilter}
                GROUP BY t.channel
                ORDER BY count DESC
            ");
            $channelDist = ['stable' => 0, 'beta' => 0, 'rc' => 0];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ch = strtolower((string) $row['channel']);
                $channelDist[$ch] = (int) $row['count'];
            }

            // Update success vs failure & recovery in last 30 days (excluding test/diagnostic identities)
            $stmt = $pdo->query("
                SELECT 
                    COUNT(*) as total_actions,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_actions,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_actions,
                    SUM(CASE WHEN event_type IN ('rollback_completed', 'update_auto_rollback') THEN 1 ELSE 0 END) as rollback_count,
                    SUM(CASE WHEN event_type = 'update_recovery_completed' THEN 1 ELSE 0 END) as recovered_count,
                    SUM(CASE WHEN event_type = 'update_recovery_failed' THEN 1 ELSE 0 END) as recovery_failed_count,
                    SUM(CASE WHEN event_type = 'update_auto_rollback' THEN 1 ELSE 0 END) as auto_rollback_count
                FROM update_telemetry
                WHERE event_type IN ('update_applied', 'update_failed', 'rollback_completed', 'update_recovery_completed', 'update_recovery_failed', 'update_auto_rollback')
                  AND created_at >= {$intervalSql}
                  AND {$deviceFilter}
            ");
            $healthData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalActions = (int) ($healthData['total_actions'] ?? 0);
            $successfulActions = (int) ($healthData['successful_actions'] ?? 0);
            $failedActions = (int) ($healthData['failed_actions'] ?? 0);
            $rollbackCount = (int) ($healthData['rollback_count'] ?? 0);
            $recoveredCount = (int) ($healthData['recovered_count'] ?? 0);
            $recoveryFailedCount = (int) ($healthData['recovery_failed_count'] ?? 0);
            $autoRollbackCount = (int) ($healthData['auto_rollback_count'] ?? 0);

            $successRate = $totalActions > 0 ? round(($successfulActions / $totalActions) * 100, 1) : 100.0;
            $failureRate = $totalActions > 0 ? round(($failedActions / $totalActions) * 100, 1) : 0.0;

            // Generate Fleet Alerts
            $alerts = [];

            if ($totalActions >= 5 && $failureRate > 10.0) {
                $alerts[] = [
                    'severity' => 'critical',
                    'code' => 'high_failure_rate',
                    'title' => 'معدل فشل تحديثات مرتفع',
                    'message' => "تجاوز معدل فشل التحديثات {$failureRate}% عبر الأسطول خلال آخر 30 يومًا.",
                ];
            }

            if ($rollbackCount > 0) {
                $alerts[] = [
                    'severity' => 'warning',
                    'code' => 'recent_rollbacks',
                    'title' => 'تم رصد عمليات تراجع (Rollbacks)',
                    'message' => "تم تنفيذ {$rollbackCount} عملية تراجع (منها {$autoRollbackCount} تراجع تلقائي لحماية الاستقرار).",
                ];
            }

            if ($recoveryFailedCount > 0) {
                $alerts[] = [
                    'severity' => 'critical',
                    'code' => 'recovery_failures_detected',
                    'title' => 'فشل في الاستعادة الذاتية (Self-Healing)',
                    'message' => "تم رصد {$recoveryFailedCount} محاولة استعادة ذاتية فاشلة تتطلب فحص المشرف.",
                ];
            }

            // Detect devices on legacy version
            $targetVersion = $this->getTargetVersion();
            $outdatedCount = 0;
            if ($targetVersion !== null && $targetVersion !== '' && $targetVersion !== '0.0.0') {
                foreach ($versionDist as $ver => $count) {
                    if (version_compare($ver, $targetVersion, '<')) {
                        $outdatedCount += $count;
                    }
                }
                if ($outdatedCount > 0) {
                    $alerts[] = [
                        'severity' => 'info',
                        'code' => 'outdated_devices',
                        'title' => 'أجهزة تعمل بإصدارات قديمة',
                        'message' => "يوجد {$outdatedCount} جهاز يعمل بإصدار أقدم من v{$targetVersion} ويتطلب التحديث.",
                    ];
                }
            } elseif ($totalDevices > 0) {
                $alerts[] = [
                    'severity' => 'warning',
                    'code' => 'target_version_unavailable',
                    'title' => 'الإصدار المستهدف غير متاح',
                    'message' => 'تعذر تحديد الإصدار الرسمي للتطبيق من version.json؛ لا يمكن تصنيف الأجهزة القديمة حالياً.',
                ];
            }

            return [
                'ok'                   => true,
                'total_devices'        => $totalDevices,
                'version_distribution' => $versionDist,
                'channel_distribution' => $channelDist,
                'update_health'        => [
                    'total_events'     => $totalActions,
                    'successful'       => $successfulActions,
                    'failed'           => $failedActions,
                    'rollbacks'        => $rollbackCount,
                    'recovered'        => $recoveredCount,
                    'auto_rollbacks'   => $autoRollbackCount,
                    'recovery_failed'  => $recoveryFailedCount,
                    'success_rate'     => $successRate,
                    'failure_rate'     => $failureRate,
                ],
                'alerts'               => $alerts,
                'last_synced_at'       => date('Y-m-d H:i:s'),
            ];

        } catch (Throwable $e) {
            Logger::error('UpdateTelemetryService getFleetStats failed', ['error' => $e->getMessage()]);
            return [
                'ok'                   => false,
                'total_devices'        => 0,
                'version_distribution' => [],
                'channel_distribution' => ['stable' => 0, 'beta' => 0, 'rc' => 0],
                'update_health'        => [
                    'total_events' => 0,
                    'successful'   => 0,
                    'failed'       => 0,
                    'rollbacks'    => 0,
                    'success_rate' => 100.0,
                    'failure_rate' => 0.0,
                ],
                'alerts'               => [],
                'error'                => $e->getMessage(),
            ];
        }
    }

    /**
     * Get list of unique fleet terminals with latest telemetry state.
     */
    public function getFleetDevices(int $limit = 50, int $offset = 0, ?string $search = null): array
    {
        try {
            $pdo = $this->getPdo();
            if ($pdo === null) {
                return ['ok' => false, 'devices' => [], 'total' => 0, 'error' => 'Database unavailable'];
            }

            $deviceFilter = self::getTestDeviceExclusionSql('device_id');
            $tDeviceFilter = self::getTestDeviceExclusionSql('t.device_id');

            $params = [];
            $where = "WHERE {$tDeviceFilter}";

            if ($search !== null && trim($search) !== '') {
                $where .= ' AND (t.device_id LIKE :search OR t.current_version LIKE :search)';
                $params[':search'] = '%' . trim($search) . '%';
            }

            $sql = "
                SELECT 
                    t.device_id,
                    t.current_version,
                    t.channel,
                    t.event_type as last_event,
                    t.success as last_event_success,
                    t.created_at as last_seen_at,
                    (SELECT COUNT(*) FROM update_telemetry ut WHERE ut.device_id = t.device_id) as total_events
                FROM update_telemetry t
                INNER JOIN (
                    SELECT device_id, MAX(id) as max_id
                    FROM update_telemetry
                    WHERE {$deviceFilter}
                    GROUP BY device_id
                ) latest ON t.id = latest.max_id
                {$where}
                ORDER BY t.created_at DESC
                LIMIT {$limit} OFFSET {$offset}
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $devices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Count total (excluding test identities)
            $countSql = "SELECT COUNT(DISTINCT device_id) as total FROM update_telemetry WHERE {$deviceFilter}";
            $total = (int) ($pdo->query($countSql)->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            return [
                'ok'      => true,
                'devices' => $devices,
                'total'   => $total,
                'limit'   => $limit,
                'offset'  => $offset,
            ];
        } catch (Throwable $e) {
            Logger::error('UpdateTelemetryService getFleetDevices failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'devices' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * One-time or maintenance purge for test/diagnostic telemetry rows.
     */
    public function purgeTestTelemetry(): int
    {
        try {
            $pdo = $this->getPdo();
            if ($pdo === null) {
                return 0;
            }

            $testFilter = "(" . implode(' OR ', [
                "device_id IN ('local-terminal')",
                "device_id LIKE 'local-test-%'",
                "device_id LIKE 'local-test_%'",
                "device_id LIKE 'test-%'",
                "device_id LIKE 'test_%'",
                "device_id LIKE 'dev-%'",
                "device_id LIKE 'dev_%'",
                "device_id LIKE 'fixture-%'",
                "device_id LIKE 'sandbox-%'",
                "device_id LIKE 'dummy-%'",
            ]) . ")";

            $stmt = $pdo->prepare("DELETE FROM update_telemetry WHERE {$testFilter}");
            $stmt->execute();
            return (int) $stmt->rowCount();
        } catch (Throwable $e) {
            Logger::error('UpdateTelemetryService purgeTestTelemetry failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get single device telemetry timeline.
     */
    public function getDeviceDetails(string $deviceId): ?array
    {
        try {
            $pdo = $this->getPdo();
            if ($pdo === null) {
                return null;
            }

            $stmt = $pdo->prepare("
                SELECT id, device_id, current_version, target_version, channel,
                       event_type, success, error_code, duration_ms, metadata, created_at
                FROM update_telemetry
                WHERE device_id = :device_id
                ORDER BY id DESC
                LIMIT 50
            ");
            $stmt->execute([':device_id' => $deviceId]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($events)) {
                return null;
            }

            foreach ($events as &$ev) {
                if (!empty($ev['metadata']) && is_string($ev['metadata'])) {
                    $ev['metadata'] = json_decode($ev['metadata'], true);
                }
            }

            $latest = $events[0];

            return [
                'device_id'       => $deviceId,
                'current_version' => $latest['current_version'],
                'channel'         => $latest['channel'],
                'last_seen_at'    => $latest['created_at'],
                'events'          => $events,
            ];
        } catch (Throwable $e) {
            Logger::error('UpdateTelemetryService getDeviceDetails failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Purge telemetry events older than retention period (default: 90 days).
     */
    public function purgeOldRecords(int $retentionDays = 90): int
    {
        $days = max(7, min(365, $retentionDays));
        try {
            $pdo = $this->getPdo();
            if ($pdo === null) {
                return 0;
            }

            $dateClause = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? "datetime('now', '-{$days} days')"
                : "DATE_SUB(NOW(), INTERVAL :days DAY)";

            $stmt = $pdo->prepare("
                DELETE FROM update_telemetry 
                WHERE created_at < {$dateClause}
            ");
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $stmt->execute([':days' => $days]);
            } else {
                $stmt->execute();
            }
            $count = $stmt->rowCount();
            Logger::info("Purged {$count} old update telemetry records older than {$days} days.");
            return $count;
        } catch (Throwable $e) {
            Logger::error('UpdateTelemetryService purgeOldRecords failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Validate and sanitize incoming telemetry event payload.

     * Returns null if invalid or contains unpermitted keys.
     */
    public function validatePayload(array $data): ?array
    {
        $deviceId = trim((string) ($data['device_id'] ?? ''));
        if ($deviceId === '' || strlen($deviceId) > 64) {
            return null;
        }

        $eventType = trim((string) ($data['event_type'] ?? ''));
        if (!in_array($eventType, self::ALLOWED_EVENTS, true)) {
            return null;
        }

        $currentVersion = trim((string) ($data['application_version'] ?? ($data['current_version'] ?? '')));
        if ($currentVersion === '' || strlen($currentVersion) > 32) {
            return null;
        }

        $targetVersion = isset($data['target_version']) && is_string($data['target_version'])
            ? trim(substr($data['target_version'], 0, 32))
            : null;

        $channel = strtolower(trim((string) ($data['channel'] ?? 'stable')));
        if (!in_array($channel, ['stable', 'beta', 'rc'], true)) {
            $channel = 'stable';
        }

        $success = isset($data['success']) ? (bool) $data['success'] : true;
        $errorCode = isset($data['error_code']) && is_string($data['error_code'])
            ? trim(substr($data['error_code'], 0, 64))
            : null;

        $durationMs = isset($data['duration_ms']) && is_numeric($data['duration_ms'])
            ? max(0, (int) $data['duration_ms'])
            : null;

        // Strip non-whitelisted metadata
        $safeMetadata = [];
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $allowedMetaKeys = ['files_count', 'error_message', 'is_delta', 'snapshot_name', 'update_type', 'engine_version', 'attempt'];
            foreach ($allowedMetaKeys as $key) {
                if (isset($data['metadata'][$key])) {
                    $safeMetadata[$key] = is_string($data['metadata'][$key]) ? substr($data['metadata'][$key], 0, 255) : $data['metadata'][$key];
                }
            }
        }

        return [
            'device_id'       => $deviceId,
            'event_type'      => $eventType,
            'current_version' => $currentVersion,
            'target_version'  => $targetVersion,
            'channel'         => $channel,
            'success'         => $success,
            'error_code'      => $errorCode,
            'duration_ms'     => $durationMs,
            'metadata'        => $safeMetadata,
            'created_at'      => isset($data['timestamp']) && is_string($data['timestamp']) ? date('Y-m-d H:i:s', strtotime($data['timestamp'])) : date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Enqueue telemetry event to local storage buffer when offline.
     */
    protected function enqueueOffline(array $event): void
    {
        @mkdir($this->storageDir, 0755, true);
        $queue = [];
        if (file_exists($this->queueFile)) {
            $raw = @file_get_contents($this->queueFile);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $queue = $decoded;
            }
        }

        // Cap queue size at 500 items to avoid unbounded growth
        if (count($queue) > 500) {
            array_shift($queue);
        }

        $queue[] = $event;
        @file_put_contents($this->queueFile, json_encode($queue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
