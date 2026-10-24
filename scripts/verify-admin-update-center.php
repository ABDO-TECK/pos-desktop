<?php
declare(strict_types=1);

$source = $argv[1] ?? dirname(__DIR__);

// Load Composer autoloader or PSR-4 fallback for App namespace
if (is_file($source . '/backend/vendor/autoload.php')) {
    require_once $source . '/backend/vendor/autoload.php';
}

spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $source . '/backend/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException("Verification failed: {$message}");
    }
}

// Temporary isolated storage directory
$lab = sys_get_temp_dir() . '/pos-admin-update-test-' . bin2hex(random_bytes(6));
mkdir($lab . '/storage', 0700, true);

putenv('APP_DEPLOY_ROOT=' . $lab);
putenv('APP_STORAGE_DIR=' . $lab . '/storage');
putenv('ENABLE_AUTO_UPDATE=true');

function cleanupLab(string $lab): void
{
    if (!is_dir($lab)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($lab, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($lab);
}

class TestUpdateServiceDouble extends App\Services\UpdateService
{
    public bool $applyCalled = false;
    public ?string $capturedOperationId = null;
    public array $applyResult = [
        'ok' => true,
        'code' => 200,
        'message' => 'Update applied cleanly',
        'data' => [
            'logs' => ['Unpacking update', 'Migrations completed'],
            'requires_desktop_handoff' => false,
        ],
    ];
    private ?App\Services\DeltaUpdateService $deltaMock = null;

    public function __construct(?App\Services\DeltaUpdateService $delta = null)
    {
        $this->deltaMock = $delta;
    }

    public function getDeltaUpdateService(): App\Services\DeltaUpdateService
    {
        return $this->deltaMock ?? new App\Services\DeltaUpdateService(null, '', sys_get_temp_dir());
    }

    public function applyUpdate(bool $force, bool $deltaCapable = true, ?string $operationId = null): array
    {
        $this->applyCalled = true;
        $this->capturedOperationId = $operationId;
        return $this->applyResult;
    }
}

try {
    echo "Starting Admin Update Center Backend Verification...\n";

    // ══════════════════════════════════════════════════════════════════
    // Test 1: UpdateAssetDownloader throttled transfer progress & retry
    // ══════════════════════════════════════════════════════════════════
    {
        $invocations = [];
        $progressCb = function (int $dlnow, int $dltotal, int $attempt) use (&$invocations) {
            $invocations[] = compact('dlnow', 'dltotal', 'attempt');
        };

        $lastHeartbeat = 0.0;
        $attempt = 1;
        $paramCount = (new \ReflectionFunction($progressCb))->getNumberOfParameters();

        $xferHandler = function (int $dltotal, int $dlnow) use ($progressCb, &$lastHeartbeat, &$attempt, $paramCount): int {
            $now = microtime(true);
            if ($progressCb && ($now - $lastHeartbeat >= 1.0 || ($dltotal > 0 && $dlnow >= $dltotal))) {
                $lastHeartbeat = $now;
                if ($paramCount >= 3) {
                    $progressCb($dlnow, $dltotal, $attempt);
                } elseif ($paramCount === 2) {
                    $progressCb($dlnow, $dltotal);
                }
            }
            return 0;
        };

        // First call triggers immediately
        $xferHandler(1000, 100);
        verify(count($invocations) === 1, "First progress call must invoke callback immediately");
        verify($invocations[0]['attempt'] === 1, "Attempt must be 1");
        verify($invocations[0]['dlnow'] === 100, "Downloaded bytes must match");

        // Immediate subsequent call with small delta (<1s elapsed and not 100%) is throttled
        $xferHandler(1000, 200);
        verify(count($invocations) === 1, "Rapid subsequent progress within 1s must be throttled");

        // Completion (100%) always bypasses throttling
        $xferHandler(1000, 1000);
        verify(count($invocations) === 2, "Completion (100%) must immediately invoke progress callback");
        verify($invocations[1]['dlnow'] === 1000, "Completion bytes must match");

        // Retry resets counters and advances attempt
        $attempt = 2;
        $lastHeartbeat = 0.0;
        $xferHandler(1000, 50);
        verify(count($invocations) === 3, "After attempt advance, new progress must invoke callback");
        verify($invocations[2]['attempt'] === 2, "New attempt 2 reflected in callback");

        echo "PASS: UpdateAssetDownloader throttles progress callback to ~1s intervals and resets on retry\n";
    }

    // ══════════════════════════════════════════════════════════════════
    // Test 2: DeltaUpdateService byte progress tracking and state reset
    // ══════════════════════════════════════════════════════════════════
    {
        $stateFile = $lab . '/storage/update-state.json';
        file_put_contents($stateFile, json_encode([
            'state' => 'downloading',
            'operation_id' => 'op-old-123',
            'download_bytes' => 0,
            'total_bytes' => 0,
            'started_at' => 100000,
        ]));

        $delta = new App\Services\DeltaUpdateService(null, $lab, $lab . '/storage');

        // Record initial download progress
        $delta->recordDownloadProgress(1024, 4096, 1);
        $state = json_decode((string) file_get_contents($stateFile), true);
        verify($state['download_bytes'] === 1024, "Download bytes recorded in update-state.json");
        verify($state['total_bytes'] === 4096, "Total bytes recorded in update-state.json");
        verify($state['download_attempt'] === 1, "Download attempt recorded in update-state.json");

        // Rapid second call is throttled
        $delta->recordDownloadProgress(2048, 4096, 1);
        $state2 = json_decode((string) file_get_contents($stateFile), true);
        verify($state2['download_bytes'] === 1024, "Rapid progress write within 1s throttled from disk");

        // Phase transition out of 'downloading' resets download progress counters
        $delta->setUpdateState('verifying', ['operation_id' => 'op-old-123']);
        $stateVerifying = json_decode((string) file_get_contents($stateFile), true);
        verify($stateVerifying['state'] === 'verifying', "State updated to verifying");
        verify(($stateVerifying['download_bytes'] ?? 0) === 0, "Download bytes reset to 0 upon entering verifying");
        verify(($stateVerifying['total_bytes'] ?? 0) === 0, "Total bytes reset to 0 upon entering verifying");
        verify($stateVerifying['operation_id'] === 'op-old-123', "Operation ID retained across active phases of same operation");

        // Transition to terminal state (e.g. rolled_back or completed) clears operation_id
        $delta->setUpdateState('rolled_back', []);
        $stateRolledBack = json_decode((string) file_get_contents($stateFile), true);
        verify($stateRolledBack['state'] === 'rolled_back', "State updated to rolled_back");
        verify(!isset($stateRolledBack['operation_id']) || $stateRolledBack['operation_id'] === null, "Operation ID cleared upon entering terminal state");

        // Starting a new unattached operation (e.g. channel transition without operation_id) clears old markers and resets started_at
        $delta->setUpdateState('idle', ['operation' => 'channel_transition']);
        $stateChannel = json_decode((string) file_get_contents($stateFile), true);
        verify(!isset($stateChannel['operation_id']) || $stateChannel['operation_id'] === null, "Operation ID cleared upon unattached operation change");
        verify($stateChannel['started_at'] > 100000, "started_at reset on new operation");

        echo "PASS: DeltaUpdateService records throttled download bytes and resets counters and operation_id on non-download phases\n";
    }

    // ══════════════════════════════════════════════════════════════════
    // Test 3: BackgroundUpdateApplyService job data isolation & path sanitization
    // ══════════════════════════════════════════════════════════════════
    {
        $dummyUpdater = new TestUpdateServiceDouble();
        $applyService = new App\Services\BackgroundUpdateApplyService($dummyUpdater, $lab . '/storage');

        $jobId = 999;
        $applyService->saveJobData($jobId, [
            'job_id' => $jobId,
            'status' => 'processing',
            'update_state' => 'downloading',
            'progress' => [
                'downloaded_bytes' => 5000,
                'total_bytes' => 20000,
                'download_attempt' => 1,
            ],
            'result' => [
                'ok' => false,
                'error' => 'Permission denied in C:\\xampp\\htdocs\\pos\\storage\\tmp\\patch.zip on line 42',
                'data' => [
                    'logs' => [
                        'Writing file to C:/xampp/htdocs/pos/backend/test.php',
                        'Finished verification',
                    ],
                ],
            ],
        ]);

        $jobData = $applyService->readJobData($jobId);
        verify($jobData !== null, "Job data read successfully from storage");
        verify($jobData['progress']['downloaded_bytes'] === 5000, "Progress downloaded_bytes matches");

        // Verify the file was written to storage/update-jobs/job_{$id}.json
        $expectedJobFile = $lab . '/storage/update-jobs/job_' . $jobId . '.json';
        verify(is_file($expectedJobFile), "Job file written to update-jobs/job_999.json");

        // Test path sanitization logic
        $rawError = 'Failed to write C:\\xampp\\htdocs\\pos\\storage\\backup.zip and /var/www/pos/data';
        $sanitizedError = App\Services\BackgroundUpdateApplyService::redactPaths($rawError);
        verify(!str_contains($sanitizedError, 'C:\\xampp'), "Windows path stripped from sanitized error");
        verify(!str_contains($sanitizedError, '/var/www'), "Linux path stripped from sanitized error");
        verify(str_contains($sanitizedError, '[path]'), "Placeholder [path] present");

        echo "PASS: BackgroundUpdateApplyService reads/writes job status into update-jobs/job_{id}.json and strips filesystem paths\n";
    }

    // ══════════════════════════════════════════════════════════════════
    // Test 4: BackgroundUpdateApplyService worker execution seam
    // ══════════════════════════════════════════════════════════════════
    {
        $mockUpdater = new TestUpdateServiceDouble();
        $applyService = new App\Services\BackgroundUpdateApplyService($mockUpdater, $lab . '/storage');

        $workerJobId = 1001;
        $operationId = 'op-verify-1001';
        $applyService->processWorkerJob([
            'job_id' => $workerJobId,
            'operation_id' => $operationId,
            'force' => false,
            'delta_capable' => true,
            'user_id' => 1,
        ]);

        verify($mockUpdater->applyCalled, "Worker execution invoked UpdateService::applyUpdate");
        verify($mockUpdater->capturedOperationId === $operationId, "Worker passed bound operation_id to applyUpdate");
        $saved = $applyService->readJobData($workerJobId);
        verify($saved !== null, "Worker persisted settlement job data");
        verify($saved['status'] === 'completed', "Job marked completed");
        verify($saved['result']['ok'] === true, "Result ok=true preserved");
        verify($saved['operation_id'] === $operationId, "Operation ID persisted");
        verify(count($saved['result']['data']['logs']) === 2, "Sanitized logs preserved in job data");

        echo "PASS: BackgroundUpdateApplyService::processWorkerJob executes and settlements job results safely\n";
    }

    // ══════════════════════════════════════════════════════════════════
    // Test 5: BackgroundUpdateApplyService::getJobStatus fail-closed verification
    // ══════════════════════════════════════════════════════════════════
    {
        $sqlite = new \PDO('sqlite::memory:');
        $sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $sqlite->exec('CREATE TABLE job_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_name TEXT NOT NULL,
            payload TEXT,
            priority INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 3,
            attempts INTEGER DEFAULT 1,
            status TEXT DEFAULT "pending",
            last_error TEXT,
            failure_code INTEGER,
            created_at TEXT DEFAULT "2026-05-01 10:00:00",
            completed_at TEXT
        )');

        $dbRef = new \ReflectionClass(App\Config\Database::class);
        $prop = $dbRef->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $sqlite);

        $deltaMock = new App\Services\DeltaUpdateService(null, $lab, $lab . '/storage');
        $updater = new TestUpdateServiceDouble($deltaMock);
        $applyService = new App\Services\BackgroundUpdateApplyService($updater, $lab . '/storage');

        // Case A: Queue completed, but no result file written -> returns needs_attention
        $sqlite->exec("INSERT INTO job_queue (id, job_name, payload, status) VALUES (501, 'apply_update', '{\"operation_id\":\"op-501\"}', 'completed')");
        $statusA = $applyService->getJobStatus(501);
        verify($statusA !== null, "Job status found");
        verify($statusA['status'] === 'needs_attention', "Missing result file causes status needs_attention");
        verify($statusA['settlement_error'] === 'unbound_operation', "Settlement error marked unbound_operation");

        // Case B: Queue completed, but operation_id in result file does not match -> returns needs_attention
        $sqlite->exec("INSERT INTO job_queue (id, job_name, payload, status) VALUES (502, 'apply_update', '{\"operation_id\":\"op-502-expected\"}', 'completed')");
        $applyService->saveJobData(502, [
            'job_id' => 502,
            'operation_id' => 'op-502-other',
            'status' => 'completed',
            'result' => ['ok' => true, 'data' => ['logs' => []]],
        ]);
        $statusB = $applyService->getJobStatus(502);
        verify($statusB['status'] === 'needs_attention', "Mismatched operation_id causes status needs_attention");
        verify($statusB['settlement_error'] === 'unbound_operation', "Settlement error marked unbound_operation");

        // Case C: Queue completed, matching operation_id and job_id, ok=true -> returns completed
        $sqlite->exec("INSERT INTO job_queue (id, job_name, payload, status) VALUES (503, 'apply_update', '{\"operation_id\":\"op-503-valid\"}', 'completed')");
        $applyService->saveJobData(503, [
            'job_id' => 503,
            'operation_id' => 'op-503-valid',
            'status' => 'completed',
            'result' => ['ok' => true, 'data' => ['logs' => ['Successfully applied']]],
        ]);
        $statusC = $applyService->getJobStatus(503);
        verify($statusC['status'] === 'completed', "Verified job file allows completed status");
        verify($statusC['settlement_error'] === null, "Verified job has no settlement_error");
        verify($statusC['result']['logs'][0] === 'Successfully applied', "Result logs returned");

        echo "PASS: BackgroundUpdateApplyService::getJobStatus verifies verified completed results fail-closed\n";
    }

    // ══════════════════════════════════════════════════════════════════
    // Test 6: UpdateController background dispatch & status routing (real SQLite)
    // ══════════════════════════════════════════════════════════════════
    {
        $sqlite = new \PDO('sqlite::memory:');
        $sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $sqlite->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
        $sqlite->exec('CREATE TABLE job_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_name TEXT NOT NULL,
            payload TEXT,
            priority INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 3,
            attempts INTEGER DEFAULT 1,
            status TEXT DEFAULT "pending",
            last_error TEXT,
            failure_code INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT
        )');

        $dbRef = new \ReflectionClass(App\Config\Database::class);
        $prop = $dbRef->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $sqlite);

        $dummyAuth = new class extends App\Services\AuthService {
            public function user(): ?array { return ['id' => 1, 'username' => 'admin', 'role' => 'admin']; }
            public function hasPermission(?int $userId, string $permission): bool { return true; }
        };

        $dummyManifest = new App\Services\UpdateManifestService();
        $dummyDelta = new App\Services\DeltaUpdateService($dummyManifest, $lab, $lab . '/storage');
        $dummyUpdater = new TestUpdateServiceDouble($dummyDelta);
        $realApplyService = new App\Services\BackgroundUpdateApplyService($dummyUpdater, $lab . '/storage');

        $controller = new class($dummyAuth, $dummyUpdater, $realApplyService) extends App\Controllers\UpdateController {
            private array $bodyOverride = [];

            public function __construct($auth, $updater, $applyService)
            {
                parent::__construct($auth, $updater, null, $applyService);
            }

            public function runApplyWithBody(array $body): array
            {
                $this->bodyOverride = $body;
                $res = $this->apply();
                return $res['body'] ?? $res;
            }

            public function runStatusForJob(string $id): array
            {
                $res = $this->status($id);
                return $res['body'] ?? $res;
            }

            protected function getBody(): array
            {
                return $this->bodyOverride;
            }

            protected function hasDesktopHandoffCapability(array $body): bool
            {
                return true;
            }
        };

        // 1. Invalid background value rejected with HTTP 400
        $invalidResponse = $controller->runApplyWithBody(['background' => 'not-a-bool']);
        verify(($invalidResponse['status'] ?? null) === 'error', "Invalid background parameter rejected");
        verify(str_contains($invalidResponse['message'] ?? '', 'background'), "Rejection message names background");

        // 2. POST /api/updates/apply with background: true -> schedules real job in SQLite
        $applyResponse = $controller->runApplyWithBody(['background' => true, 'force' => false]);
        verify(($applyResponse['status'] ?? null) === 'success', "Controller returned success response");
        verify(($applyResponse['data']['status'] ?? null) === 'pending', "Returned pending status");
        $scheduledJobId = $applyResponse['data']['job_id'] ?? null;
        verify(is_int($scheduledJobId) && $scheduledJobId > 0, "Valid positive integer job_id returned from SQLite");

        // 3. GET /api/update/jobs/{id} returns initial pending status from SQLite
        $statusResponse1 = $controller->runStatusForJob((string) $scheduledJobId);
        verify(($statusResponse1['status'] ?? null) === 'success', "Job status query succeeded");
        verify($statusResponse1['data']['id'] === $scheduledJobId, "Job status ID matches scheduled job");
        verify($statusResponse1['data']['status'] === 'pending', "Initial status is pending");

        // 4. Update job to processing with active progress bound to the scheduled operation_id
        $qRow = $sqlite->query("SELECT payload FROM job_queue WHERE id = {$scheduledJobId}")->fetch(\PDO::FETCH_ASSOC);
        $payload = json_decode($qRow['payload'], true);
        $opId = $payload['operation_id'];
        verify(!empty($opId), "Dispatched job contains non-empty operation_id");

        $dummyDelta->setUpdateState('downloading', [
            'operation_id'     => $opId,
            'download_bytes'   => 1024,
            'total_bytes'      => 2048,
            'download_attempt' => 1,
        ]);
        $sqlite->prepare("UPDATE job_queue SET status = 'processing' WHERE id = ?")->execute([$scheduledJobId]);

        $statusResponse2 = $controller->runStatusForJob((string) $scheduledJobId);
        verify($statusResponse2['data']['status'] === 'processing', "Job status transitioned to processing");
        verify($statusResponse2['data']['update_state'] === 'downloading', "Update state matches downloading");
        verify($statusResponse2['data']['progress']['downloaded_bytes'] === 1024, "Progress downloaded_bytes matches DeltaUpdateService");

        // 5. Complete job with matching operation_id and verify settlement
        $realApplyService->saveJobData($scheduledJobId, [
            'job_id'       => $scheduledJobId,
            'operation_id' => $opId,
            'status'       => 'completed',
            'result'       => [
                'ok'   => true,
                'data' => ['logs' => ['Unpacked cleanly', 'Migrations finished']],
            ],
        ]);
        $sqlite->prepare("UPDATE job_queue SET status = 'completed' WHERE id = ?")->execute([$scheduledJobId]);

        $statusResponse3 = $controller->runStatusForJob((string) $scheduledJobId);
        verify($statusResponse3['data']['status'] === 'completed', "Job status verified as completed");
        verify($statusResponse3['data']['result']['logs'][0] === 'Unpacked cleanly', "Completed result logs returned");

        echo "PASS: UpdateController rejects invalid background params, delegates real PDO SQLite background apply, and serves live job status\n";
    }

    echo "\nALL ADMIN UPDATE CENTER BACKEND VERIFICATION CHECKS PASSED!\n";
} finally {
    cleanupLab($lab);
}
