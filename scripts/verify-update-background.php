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

// Explicit verification helper throwing RuntimeException (independent of zend.assertions ini)
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException("Verification failed: {$message}");
    }
}

// Safe lab cleanup helper strictly bounded to pos-bg-update-test- temp prefix
function cleanupLab(string $lab): void
{
    $prefix = sys_get_temp_dir() . '/pos-bg-update-test-';
    $prefixWin = sys_get_temp_dir() . '\\pos-bg-update-test-';
    if (!str_starts_with($lab, $prefix) && !str_starts_with($lab, $prefixWin)) {
        return;
    }
    if (!is_dir($lab)) {
        return;
    }

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

// Create isolated temporary workspace - no deployed root or live DB touched
$lab = sys_get_temp_dir() . '/pos-bg-update-test-' . bin2hex(random_bytes(6));
mkdir($lab . '/storage', 0700, true);
mkdir($lab . '/keys', 0700, true);

putenv('APP_DEPLOY_ROOT=' . $lab);
putenv('APP_STORAGE_DIR=' . $lab . '/storage');
putenv('ENABLE_UPDATE_CHECKS=true');
putenv('UPDATE_SERVER_URL=https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/latest');

// Create test version.json
$initialVersion = [
    'version'               => '1.0.0',
    'update_engine_version' => '1.0.0',
    'update_channel'        => 'stable',
];
file_put_contents($lab . '/version.json', json_encode($initialVersion, JSON_PRETTY_PRINT));

// Initial test key fixture
$testKeyPath = $lab . '/keys/update_public.pem';
file_put_contents($testKeyPath, "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0testkeyA==\n-----END PUBLIC KEY-----\n");

// Fake GitHub Provider to deterministically monitor and control provider calls
class ControlledFakeGitHubProvider extends App\Services\GitHubReleaseProvider
{
    public int $latestReleaseCalls = 0;
    public int $fetchAssetCalls = 0;
    public ?array $customReleaseResponse = null;
    public ?string $customManifestContent = null;
    public ?string $customSignatureContent = null;

    public function getLatestRelease(string $channel = 'stable', ?string $currentVersion = null): array
    {
        $this->latestReleaseCalls++;
        if ($this->customReleaseResponse !== null) {
            return $this->customReleaseResponse;
        }

        return [
            'ok'               => true,
            'tag_name'         => 'v1.1.0',
            'latest_version'   => '1.1.0',
            'channel'          => $channel,
            'published_at'     => '2026-05-01T12:00:00Z',
            'manifest_url'     => 'https://api.github.com/repos/fake/manifest.json',
            'signature_url'    => 'https://api.github.com/repos/fake/manifest.sig',
            'delta_url'        => 'https://api.github.com/repos/fake/delta.zip',
            'download_url'     => 'https://api.github.com/repos/fake/full.zip',
            'full_package_url' => 'https://api.github.com/repos/fake/full.zip',
            'release_url'      => 'https://github.com/fake/releases/tag/v1.1.0',
            'assets'           => [],
            'changelog'        => ['Fix performance in background checks'],
        ];
    }

    public function fetchAssetContent(string $url): array
    {
        $this->fetchAssetCalls++;
        if (str_ends_with($url, 'manifest.json')) {
            $content = $this->customManifestContent ?? json_encode([
                'version'            => '1.1.0',
                'channel'            => 'stable',
                'engine_version'     => '1.0.0',
                'files'              => [
                    [
                        'path'   => 'frontend/dist/index.html',
                        'sha256' => hash('sha256', 'test-html'),
                        'size'   => 9,
                    ],
                ],
                'rollout_percentage' => 100,
            ]);
            return ['ok' => true, 'content' => $content];
        }

        if (str_ends_with($url, 'manifest.sig')) {
            return ['ok' => true, 'content' => $this->customSignatureContent ?? 'valid-test-signature'];
        }

        return ['ok' => false, 'error' => 'Not found'];
    }

    public function totalProviderCalls(): int
    {
        return $this->latestReleaseCalls + $this->fetchAssetCalls;
    }
}

// Controlled signature service tracking verification invocations and fixture key paths
class ControlledSignatureService extends App\Services\ManifestSignatureService
{
    public bool $signatureValid = true;
    public int $verifyCalls = 0;
    private ?string $testPublicKeyPath;
    private array $testTrustedKeyPaths;

    public function __construct(?string $publicKeyPath = null, array $additionalTrustedKeys = [])
    {
        $this->testPublicKeyPath = $publicKeyPath;
        $this->testTrustedKeyPaths = $additionalTrustedKeys;
        parent::__construct($publicKeyPath, $additionalTrustedKeys);
    }

    public function getPublicKeyPath(): ?string
    {
        return $this->testPublicKeyPath;
    }

    public function getTrustedKeyPaths(): array
    {
        return $this->testTrustedKeyPaths;
    }

    public function verifySignature(string $data, string $signature, ?string $customPublicKey = null): bool
    {
        $this->verifyCalls++;
        return $this->signatureValid;
    }
}

// Noop Telemetry Service so no database singleton is touched
class NoopUpdateTelemetryService extends App\Services\UpdateTelemetryService
{
    public function recordEvent(array $eventData): bool
    {
        return true;
    }
}

// Testable BackgroundUpdateCheckService with isolated queue & simulated seams
class TestableBackgroundUpdateCheckService extends App\Services\BackgroundUpdateCheckService
{
    public array $dispatchedJobs = [];
    public int $currentTime = 1700000000;
    public bool $simulateQueueFailure = false;
    public bool $simulateScheduleBusy = false;
    public bool $simulateScheduleLockNull = false;
    public bool $simulateRenameFailure = false;

    protected function dispatchQueueJob(array $context, bool $deltaCapable): int
    {
        if ($this->simulateQueueFailure) {
            throw new \RuntimeException('Database connection lost');
        }

        // Emulate JobQueue::dispatchUnique behavior: return existing job id if already pending/processing
        foreach ($this->dispatchedJobs as $job) {
            if ($job['job_name'] === 'check_for_updates' && in_array($job['status'], ['pending', 'processing'], true)) {
                return $job['id'];
            }
        }

        $id = count($this->dispatchedJobs) + 1;
        $this->dispatchedJobs[] = [
            'id'            => $id,
            'job_name'      => 'check_for_updates',
            'context'       => $context,
            'delta_capable' => $deltaCapable,
            'status'        => 'pending',
        ];
        return $id;
    }

    protected function acquireScheduleLock(): mixed
    {
        if ($this->simulateScheduleBusy) {
            return ['status' => 'busy'];
        }
        if ($this->simulateScheduleLockNull) {
            return null;
        }
        return parent::acquireScheduleLock();
    }

    protected function performRename(string $source, string $destination): bool
    {
        if ($this->simulateRenameFailure) {
            return false;
        }
        return parent::performRename($source, $destination);
    }

    protected function currentTime(): int
    {
        return $this->currentTime;
    }
}

// Helper to build UpdateService with controlled dependencies
function createTestUpdateService(
    ControlledFakeGitHubProvider $provider,
    ControlledSignatureService $signatureService,
    string $lab
): App\Services\UpdateService {
    $manifestService = new App\Services\UpdateManifestService();
    $deltaService = new App\Services\DeltaUpdateService($manifestService, $lab, $lab . '/storage');
    $telemetry = new NoopUpdateTelemetryService();

    return new App\Services\UpdateService(
        new App\Services\GitService(),
        new App\Services\FrontendBuildService(),
        new App\Services\BackupService(),
        $deltaService,
        $manifestService,
        null,
        $provider,
        $signatureService,
        $telemetry
    );
}

// Dummy auth service for UpdateController
class DummyAuthService extends App\Services\AuthService
{
    public function user(): ?array
    {
        return ['id' => 1, 'username' => 'admin', 'role' => 'admin'];
    }

    public function hasPermission(?int $userId, string $permission): bool
    {
        return true;
    }
}

try {
    echo "Starting isolated Update Background Verification...\n";

    $fakeProvider = new ControlledFakeGitHubProvider();
    $fakeSignature = new ControlledSignatureService($testKeyPath);
    $updateService = createTestUpdateService($fakeProvider, $fakeSignature, $lab);
    $bgService = new TestableBackgroundUpdateCheckService($updateService);
    $controller = new App\Controllers\UpdateController(
        new DummyAuthService(),
        $updateService,
        $bgService
    );

    // ══════════════════════════════════════════════════════════════════════════════
    // 1. Cold automatic HTTP check: ZERO provider requests, returns check_pending
    // ══════════════════════════════════════════════════════════════════════════════
    $callsBefore = $fakeProvider->totalProviderCalls();
    $coldResult = $bgService->readOrSchedule(true);

    verify($coldResult['status'] === 'check_pending', 'Cold check must return check_pending');
    verify($coldResult['pending'] === true, 'Cold check must have pending=true');
    verify($coldResult['has_update'] === false, 'Cold pending must not claim has_update');
    verify(empty($coldResult['updates_unreachable']), 'Cold pending must not report unreachable');
    verify($fakeProvider->totalProviderCalls() === $callsBefore, 'HTTP path must produce ZERO provider requests on cold check');
    verify(count($bgService->dispatchedJobs) === 1, 'Cold check must schedule exactly 1 background job');
    echo "PASS: Cold automatic check produces ZERO provider calls and returns check_pending\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 2. Dedup & Queue Uniqueness: Repeated cold calls return same job_id, no extra jobs
    // ══════════════════════════════════════════════════════════════════════════════
    $secondCold = $bgService->readOrSchedule(true);
    verify($secondCold['pending'] === true, 'Second call while still pending remains pending');
    verify($secondCold['job_id'] === $coldResult['job_id'], 'Repeated cold check returns existing job id');
    verify(count($bgService->dispatchedJobs) === 1, 'dispatchUnique must not duplicate pending job in queue');
    verify($fakeProvider->totalProviderCalls() === $callsBefore, 'Repeated pending HTTP checks produce ZERO provider calls');
    echo "PASS: Repeated cold checks deduplicate via dispatchUnique and produce ZERO provider calls\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 3. Worker execution: invokes real discovery and verifies cryptographic signature
    // ══════════════════════════════════════════════════════════════════════════════
    $sigCallsBeforeWorker = $fakeSignature->verifyCalls;
    $jobPayload = [
        'context'       => $bgService->dispatchedJobs[0]['context'],
        'delta_capable' => true,
    ];

    $workerSuccess = $bgService->processWorkerJob($jobPayload);
    verify($workerSuccess === true, 'Worker job must succeed');
    verify($fakeProvider->totalProviderCalls() > $callsBefore, 'Worker must perform real discovery flow');
    verify($fakeSignature->verifyCalls > $sigCallsBeforeWorker, 'Worker must perform actual signature verification');
    echo "PASS: Worker executes real discovery, manifest, and signature verification\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 4. Warm automatic HTTP check: returns fresh cached result with ZERO provider calls
    // ══════════════════════════════════════════════════════════════════════════════
    $callsAfterWorker = $fakeProvider->totalProviderCalls();
    $warmResult = $bgService->readOrSchedule(true);

    verify(empty($warmResult['pending']), 'Warm check must not be pending');
    verify($warmResult['has_update'] === true, 'Warm check must return has_update=true');
    verify($warmResult['latest_version'] === '1.1.0', 'Warm check returns latest version 1.1.0');
    verify($fakeProvider->totalProviderCalls() === $callsAfterWorker, 'Warm check must produce ZERO provider calls');
    echo "PASS: Warm check returns validated full result from atomic cache with ZERO provider calls\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 5. Schedule lock handling: busy vs failure (repro: acquireScheduleLock => null)
    // ══════════════════════════════════════════════════════════════════════════════
    // Subclass / seam returning null (simulating cannot create or acquire lock)
    $bgService->simulateScheduleLockNull = true;
    $jobsBeforeNull = count($bgService->dispatchedJobs);
    $callsBeforeNull = $fakeProvider->totalProviderCalls();
    $nullLockResult = $bgService->readOrSchedule(false);

    verify($nullLockResult['status'] === 'queue_unavailable', 'Cannot create lock must return queue_unavailable');
    verify($nullLockResult['updates_unreachable'] === true, 'Cannot create lock reports unreachable');
    verify(count($bgService->dispatchedJobs) === $jobsBeforeNull, 'No dispatch without owning schedule lock!');
    verify($fakeProvider->totalProviderCalls() === $callsBeforeNull, 'Cannot create lock must NEVER fall back to sync discovery');
    $bgService->simulateScheduleLockNull = false;

    // Subclass / seam returning busy (another process currently scheduling)
    $bgService->simulateScheduleBusy = true;
    $jobsBeforeBusy = count($bgService->dispatchedJobs);
    $busyResult = $bgService->readOrSchedule(false);
    verify($busyResult['status'] === 'check_pending', 'Busy lock returns pending immediately');
    verify($busyResult['pending'] === true, 'Busy lock is pending');
    verify(count($bgService->dispatchedJobs) === $jobsBeforeBusy, 'Busy lock must NOT dispatch extra queue job');
    verify($fakeProvider->totalProviderCalls() === $callsBeforeNull, 'Busy lock must produce ZERO provider calls');
    $bgService->simulateScheduleBusy = false;
    echo "PASS: Schedule lock distinguishes busy (immediate pending) vs failure (explicit unavailable, no dispatch)\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 6. writeCache atomic replacement & simulated rename failure preserves old cache
    // ══════════════════════════════════════════════════════════════════════════════
    $currentContext = $updateService->getContextFingerprint(true);
    // Write valid cache A
    $validCacheA = [
        'success'             => true,
        'status'              => 'update_available',
        'has_update'          => true,
        'latest_version'      => '1.1.0',
        'current_version'     => '1.0.0',
        'updates_disabled'    => false,
        'updates_unreachable' => false,
        'changelog'           => ['Release A'],
    ];
    verify($bgService->writeCache($currentContext, $validCacheA), 'Write cache A succeeds');

    // Simulate rename failure when writing cache B
    $bgService->simulateRenameFailure = true;
    $validCacheB = [
        'success'             => true,
        'status'              => 'update_available',
        'has_update'          => true,
        'latest_version'      => '1.2.0',
        'current_version'     => '1.0.0',
        'updates_disabled'    => false,
        'updates_unreachable' => false,
        'changelog'           => ['Release B'],
    ];
    $renameFailWrite = $bgService->writeCache($currentContext, $validCacheB);
    verify($renameFailWrite === false, 'writeCache reports false on rename failure');

    // Previous valid cache A MUST be preserved intact!
    $cachedAfterRenameFail = $bgService->readCache($currentContext);
    verify($cachedAfterRenameFail !== null, 'Existing cache must NOT be unlinked before rename');
    verify($cachedAfterRenameFail['latest_version'] === '1.1.0', 'Existing cache content preserved on rename failure');
    $bgService->simulateRenameFailure = false;

    // Successive replacement works atomically
    verify($bgService->writeCache($currentContext, $validCacheB), 'Successive writeCache succeeds');
    $cachedSuccessive = $bgService->readCache($currentContext);
    verify($cachedSuccessive['latest_version'] === '1.2.0', 'Successive writeCache replaces cache');
    echo "PASS: writeCache replaces atomically without pre-unlink and preserves existing cache on rename failure\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 7. Complete cache shape, types, TTL bounds, and file limits validation
    // ══════════════════════════════════════════════════════════════════════════════
    $safeFp = preg_replace('/[^a-zA-Z0-9_-]/', '', $currentContext['fingerprint']);
    $cacheFilePath = $lab . '/storage/bg_update_check_' . $safeFp . '.json';

    // 7a. Incomplete shape: e.g. only {'status': 'no_update_available'}
    $malformedPayload = [
        'cached_at' => $bgService->currentTime,
        'ttl'       => 300,
        'context'   => $currentContext,
        'result'    => ['status' => 'no_update_available'], // missing current_version, has_update, booleans
    ];
    file_put_contents($cacheFilePath, json_encode($malformedPayload));
    verify($bgService->readCache($currentContext) === null, 'Incomplete result shape must be rejected by readCache');
    verify(is_file($cacheFilePath), 'readCache must NOT unlink invalid file on HTTP read');

    // 7b. Truncated JSON
    file_put_contents($cacheFilePath, '{"cached_at": 1700000000, "ttl": 300, "result": { malformed...');
    verify($bgService->readCache($currentContext) === null, 'Truncated JSON must return null');

    // 7c. Oversized file (> 64KB)
    $largeString = str_repeat('A', 70 * 1024);
    $oversizedPayload = [
        'cached_at' => $bgService->currentTime,
        'ttl'       => 300,
        'context'   => $currentContext,
        'result'    => $validCacheA,
        'padding'   => $largeString,
    ];
    file_put_contents($cacheFilePath, json_encode($oversizedPayload));
    verify($bgService->readCache($currentContext) === null, 'Oversized cache file must return null');

    // 7d. Invalid TTL (0, negative, > 300)
    $invalidTtlPayload = [
        'cached_at' => $bgService->currentTime,
        'ttl'       => 0,
        'context'   => $currentContext,
        'result'    => $validCacheA,
    ];
    file_put_contents($cacheFilePath, json_encode($invalidTtlPayload));
    verify($bgService->readCache($currentContext) === null, 'TTL <= 0 must return null');

    $invalidTtlPayload['ttl'] = -10;
    file_put_contents($cacheFilePath, json_encode($invalidTtlPayload));
    verify($bgService->readCache($currentContext) === null, 'TTL negative must return null');

    $invalidTtlPayload['ttl'] = 301;
    file_put_contents($cacheFilePath, json_encode($invalidTtlPayload));
    verify($bgService->readCache($currentContext) === null, 'TTL > 300 must return null');

    $invalidTtlPayload['ttl'] = 300;
    file_put_contents($cacheFilePath, json_encode($invalidTtlPayload));
    verify($bgService->readCache($currentContext) !== null, 'TTL = 300 must be valid');

    // 7e. Future timestamp (> now + 5)
    $futurePayload = [
        'cached_at' => $bgService->currentTime + 100,
        'ttl'       => 300,
        'context'   => $currentContext,
        'result'    => $validCacheA,
    ];
    file_put_contents($cacheFilePath, json_encode($futurePayload));
    verify($bgService->readCache($currentContext) === null, 'Future cached_at must return null');

    // 7f. Version mismatch
    $versionMismatchPayload = [
        'cached_at' => $bgService->currentTime,
        'ttl'       => 300,
        'context'   => $currentContext,
        'result'    => array_merge($validCacheA, ['current_version' => '0.9.0']),
    ];
    file_put_contents($cacheFilePath, json_encode($versionMismatchPayload));
    verify($bgService->readCache($currentContext) === null, 'Application version mismatch in result must return null');
    echo "PASS: Complete cache shape, types, TTL bounds, and file limits validation enforced\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 8. Failures and short retry cooldown (~60s vs ~300s success)
    // ══════════════════════════════════════════════════════════════════════════════
    $fakeProvider->customReleaseResponse = [
        'ok'         => false,
        'error_code' => 'github_network_timeout',
        'error'      => 'Connection timed out',
        'checkedUrl' => 'https://api.github.com/fake',
    ];
    $failJob = [
        'context'       => $updateService->getContextFingerprint(true),
        'delta_capable' => true,
    ];
    $bgService->processWorkerJob($failJob);

    // The failure is cached. An HTTP read returns the failure without provider calls.
    $callsBeforeFailCheck = $fakeProvider->totalProviderCalls();
    $failCacheHit = $bgService->readOrSchedule(true);
    verify($failCacheHit['status'] === 'github_network_timeout', 'Failed check returned from cache');
    verify($fakeProvider->totalProviderCalls() === $callsBeforeFailCheck, 'Cached failure produces ZERO provider calls');

    // At +70s, the failure cache (TTL 60s) has expired, allowing a new job
    $bgService->currentTime += 70;
    // Mark previous job completed so dispatchUnique schedules fresh job
    $bgService->dispatchedJobs[0]['status'] = 'completed';
    $jobsBeforeExpire = count($bgService->dispatchedJobs);
    $retryPending = $bgService->readOrSchedule(true);
    verify($retryPending['pending'] === true, 'Expired failure cache triggers pending schedule');
    verify(count($bgService->dispatchedJobs) === $jobsBeforeExpire + 1, 'Retry job dispatched');
    echo "PASS: Failure results cache short (~60s) and expire to allow retry\n";

    $fakeProvider->customReleaseResponse = null;

    // ══════════════════════════════════════════════════════════════════════════════
    // 9. Context changes (channel, keys, engine version, app version, URL, hosts)
    // ══════════════════════════════════════════════════════════════════════════════
    $baseFingerprint = $updateService->getContextFingerprint(true)['fingerprint'];

    // 9a. Channel change
    $updateService->setClientChannel('beta');
    $betaFingerprint = $updateService->getContextFingerprint(true)['fingerprint'];
    verify($betaFingerprint !== $baseFingerprint, 'Channel switch must change context fingerprint');
    $updateService->setClientChannel('stable');

    // 9b. Delta capability change
    $noDeltaFingerprint = $updateService->getContextFingerprint(false)['fingerprint'];
    verify($noDeltaFingerprint !== $baseFingerprint, 'Delta capability must change context fingerprint');

    // 9c. Key rotation: modify key content in isolated fixture
    file_put_contents($testKeyPath, "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0ROTATEDkeyB==\n-----END PUBLIC KEY-----\n");
    $rotatedKeyFingerprint = $updateService->getContextFingerprint(true)['fingerprint'];
    verify($rotatedKeyFingerprint !== $baseFingerprint, 'Rotating public key content must change context fingerprint');

    // 9d. Key revocation / missing: remove key file
    @unlink($testKeyPath);
    $revokedKeyFingerprint = $updateService->getContextFingerprint(true)['fingerprint'];
    verify($revokedKeyFingerprint !== $rotatedKeyFingerprint, 'Revoking/deleting key file must change context fingerprint');

    // Restore key for remaining tests
    file_put_contents($testKeyPath, "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0testkeyA==\n-----END PUBLIC KEY-----\n");

    // 9e. Application version change
    file_put_contents($lab . '/version.json', json_encode([
        'version'               => '1.0.1',
        'update_engine_version' => '1.0.0',
        'update_channel'        => 'stable',
    ]));
    $appVerFingerprint = $updateService->getContextFingerprint(true)['fingerprint'];
    verify($appVerFingerprint !== $baseFingerprint, 'Mutating application version must change context fingerprint');

    // 9f. Engine version change: absent (null) vs explicit ('1.0.0')
    file_put_contents($lab . '/version.json', json_encode([
        'version'        => '1.0.0',
        'update_channel' => 'stable',
        // update_engine_version absent
    ]));
    $nullEngineFp = $updateService->getContextFingerprint(true);
    verify($nullEngineFp['update_engine_version'] === null, 'Missing update_engine_version must be null in context');
    verify($nullEngineFp['fingerprint'] !== $baseFingerprint, 'Absent engine version must produce different fingerprint than explicit 1.0.0');

    // Restore version.json
    file_put_contents($lab . '/version.json', json_encode($initialVersion));

    // 9g. In-memory PEM key material vs file path: different PEM materials produce different fingerprints
    $inlineKey1 = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA1INLINEkey1==\n-----END PUBLIC KEY-----\n";
    $inlineKey2 = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA2INLINEkey2==\n-----END PUBLIC KEY-----\n";

    $sigServiceInline1 = new ControlledSignatureService(null, [$inlineKey1]);
    $updateServiceInline1 = createTestUpdateService($fakeProvider, $sigServiceInline1, $lab);
    $fpInline1 = $updateServiceInline1->getContextFingerprint(true)['fingerprint'];

    $sigServiceInline2 = new ControlledSignatureService(null, [$inlineKey2]);
    $updateServiceInline2 = createTestUpdateService($fakeProvider, $sigServiceInline2, $lab);
    $fpInline2 = $updateServiceInline2->getContextFingerprint(true)['fingerprint'];

    verify($fpInline1 !== $fpInline2, 'Different inline key materials must produce different fingerprints');

    // 9h. Identical effective key sets in different orders must produce identical fingerprints (order-independent)
    $sigServiceOrderA = new ControlledSignatureService(null, [$inlineKey1, $inlineKey2]);
    $updateServiceOrderA = createTestUpdateService($fakeProvider, $sigServiceOrderA, $lab);
    $fpOrderA = $updateServiceOrderA->getContextFingerprint(true)['fingerprint'];

    $sigServiceOrderB = new ControlledSignatureService(null, [$inlineKey2, $inlineKey1]);
    $updateServiceOrderB = createTestUpdateService($fakeProvider, $sigServiceOrderB, $lab);
    $fpOrderB = $updateServiceOrderB->getContextFingerprint(true)['fingerprint'];

    verify($fpOrderA === $fpOrderB, 'Identical effective key sets in different orders must produce identical fingerprints');
    echo "PASS: Context changes (channel, capability, key rotation/revocation, inline PEM keys, app version, engine version) isolate cache\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 10. Queued stale context ignored by worker
    // ══════════════════════════════════════════════════════════════════════════════
    $staleContext = $updateService->getContextFingerprint(true);
    $staleContext['fingerprint'] = 'stale-dummy-fingerprint';
    $staleJob = [
        'context'       => $staleContext,
        'delta_capable' => true,
    ];
    $callsBeforeStale = $fakeProvider->totalProviderCalls();
    $staleSuccess = $bgService->processWorkerJob($staleJob);
    verify($staleSuccess === false, 'Worker must reject stale queued context');
    verify($fakeProvider->totalProviderCalls() === $callsBeforeStale, 'Stale job must not call provider');
    echo "PASS: Queued stale context is ignored before worker discovery\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 11. Mid-flight context switch prevents publishing stale result
    // ══════════════════════════════════════════════════════════════════════════════
    class ContextSwitchingUpdateService extends App\Services\UpdateService
    {
        public function checkForUpdate(bool $deltaCapable = false, bool $force = true): array
        {
            $this->setClientChannel('beta');
            return [
                'success'         => true,
                'status'          => 'update_available',
                'has_update'      => true,
                'latest_version'  => '2.0.0',
                'current_version' => '1.0.0',
            ];
        }
    }

    $switchingService = new ContextSwitchingUpdateService(
        new App\Services\GitService(),
        new App\Services\FrontendBuildService(),
        new App\Services\BackupService(),
        $updateService->getDeltaUpdateService(),
        $updateService->getManifestService(),
        null,
        $fakeProvider,
        $fakeSignature,
        new NoopUpdateTelemetryService()
    );
    $switchingBgService = new TestableBackgroundUpdateCheckService($switchingService);
    $switchJobContext = $switchingService->getContextFingerprint(true);
    $safeSwitchFp = preg_replace('/[^a-zA-Z0-9_-]/', '', $switchJobContext['fingerprint']);
    @unlink($lab . '/storage/bg_update_check_' . $safeSwitchFp . '.json');

    $published = $switchingBgService->processWorkerJob([
        'context'       => $switchJobContext,
        'delta_capable' => true,
    ]);
    verify($published === false, 'Worker must abort publication if context changed during discovery');
    verify($switchingBgService->readCache($switchJobContext) === null, 'Cache must not be published on mid-flight context switch');
    $updateService->setClientChannel('stable');
    echo "PASS: Mid-flight context switch prevents publishing stale result\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 12. GET /api/updates/status cache miss produces ZERO network requests
    // ══════════════════════════════════════════════════════════════════════════════
    @unlink($lab . '/storage/remote_version_cache_stable.json');
    @unlink($lab . '/storage/remote_version_cache_beta.json');

    $providerCallsBeforeStatus = $fakeProvider->totalProviderCalls();
    $statusResponse = $controller->status();

    verify($fakeProvider->totalProviderCalls() === $providerCallsBeforeStatus, 'GET /api/updates/status must make ZERO outbound calls on cache miss');
    verify($statusResponse['body']['data']['update_available'] === false, 'Status cache miss reports update_available=false');
    verify($statusResponse['body']['data']['latest_version'] === null, 'Status cache miss reports latest_version=null');
    echo "PASS: UpdateController::status on cache miss produces ZERO network requests\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 13. UpdateController::check wiring: background query vs manual default
    // ══════════════════════════════════════════════════════════════════════════════
    // 13a. Background check wiring: passes $_GET['background'] = 'true'
    $_GET['background'] = 'true';
    $bgControllerResult = $controller->check();
    verify(isset($bgControllerResult['body']['data']['pending']), 'Controller with background=true delegates to background service');
    unset($_GET['background']);

    // 13b. Manual check default wiring: synchronous discovery and signature verification
    $callsBeforeManual = $fakeProvider->totalProviderCalls();
    $sigCallsBeforeManual = $fakeSignature->verifyCalls;
    $_GET = [];
    $manualControllerResult = $controller->check();

    verify($fakeProvider->totalProviderCalls() > $callsBeforeManual, 'Manual check must perform outbound discovery');
    verify($fakeSignature->verifyCalls > $sigCallsBeforeManual, 'Manual check must perform signature verification');
    verify($manualControllerResult['body']['data']['has_update'] === true, 'Manual check verified latest release');
    verify($manualControllerResult['body']['data']['latest_version'] === '1.1.0', 'Manual check verified version');
    echo "PASS: UpdateController::check wiring verified for background query and manual default\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 14. Queue failure returns explicit unavailable state without fallback to sync discovery
    //     & Sanitize details against leaking raw exceptions / internal paths / secrets
    // ══════════════════════════════════════════════════════════════════════════════
    $sentinelSecret = 'CRITICAL_SECRET_PASSWORD_hunter2_AND_PATH_C:\\internal\\secret.db';
    class SecretLeakingBackgroundService extends TestableBackgroundUpdateCheckService
    {
        public string $secret;
        protected function dispatchQueueJob(array $context, bool $deltaCapable): int
        {
            throw new \RuntimeException($this->secret);
        }
    }

    $secretBgService = new SecretLeakingBackgroundService($updateService);
    $secretBgService->secret = $sentinelSecret;
    $callsBeforeSecretFail = $fakeProvider->totalProviderCalls();
    $secretFailResult = $secretBgService->readOrSchedule(true);

    verify($secretFailResult['status'] === 'queue_unavailable', 'Queue failure must return queue_unavailable');
    verify($secretFailResult['updates_unreachable'] === true, 'Queue failure reports unreachable');
    verify($fakeProvider->totalProviderCalls() === $callsBeforeSecretFail, 'Queue failure must NEVER fall back to synchronous network discovery');

    $encodedResult = json_encode($secretFailResult);
    verify(!str_contains($encodedResult, $sentinelSecret), 'JSON response must NOT contain raw exception message or secrets');
    verify(!str_contains($encodedResult, 'hunter2'), 'JSON response must NOT contain sensitive credential fragments');
    verify(!str_contains($encodedResult, 'secret.db'), 'JSON response must NOT contain internal file paths');
    verify($secretFailResult['details'] === 'Job queue dispatch failed.', 'Details must be fixed safe generic message');

    // Also assert schedule lock exception does NOT leak raw secrets
    class SecretLockBackgroundService extends TestableBackgroundUpdateCheckService
    {
        public string $secret;
        protected function acquireScheduleLock(): mixed
        {
            throw new \RuntimeException($this->secret);
        }
    }
    $lockSecretBg = new SecretLockBackgroundService($updateService);
    $lockSecretBg->secret = $sentinelSecret;
    $lockSecretResult = $lockSecretBg->readOrSchedule(true);
    verify($lockSecretResult['status'] === 'queue_unavailable', 'Lock exception must return queue_unavailable');
    $encodedLockResult = json_encode($lockSecretResult);
    verify(!str_contains($encodedLockResult, $sentinelSecret), 'Schedule lock response must NOT leak raw exception');
    verify($lockSecretResult['details'] === 'Schedule lock unavailable.', 'Lock details must be fixed safe generic message');
    echo "PASS: Queue failure returns explicit unavailable state without fallback to synchronous discovery and sanitizes raw exceptions\n";

    // ══════════════════════════════════════════════════════════════════════════════
    // 15. ENABLE_UPDATE_CHECKS environment flag behavior via EnvLoader
    // ══════════════════════════════════════════════════════════════════════════════
    $origEnv = getenv('ENABLE_UPDATE_CHECKS');
    $origServerEnv = $_ENV['ENABLE_UPDATE_CHECKS'] ?? null;

    try {
        // 15a. Missing flag: defaults to true (enabled -> schedules background check)
        putenv('ENABLE_UPDATE_CHECKS'); // unsets process env
        unset($_ENV['ENABLE_UPDATE_CHECKS']);
        $missingFlagBg = new TestableBackgroundUpdateCheckService($updateService);
        $missingResult = $missingFlagBg->readOrSchedule(true);
        verify(empty($missingResult['updates_disabled']), 'Missing ENABLE_UPDATE_CHECKS must default to enabled');
        verify($missingResult['status'] === 'check_pending', 'Missing flag must schedule background check');

        // 15b. Explicit false: updates_disabled=true, zero queue jobs
        putenv('ENABLE_UPDATE_CHECKS=false');
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'false';
        $disabledBg = new TestableBackgroundUpdateCheckService($updateService);
        $jobsBeforeDisabled = count($disabledBg->dispatchedJobs);
        $disabledResult = $disabledBg->readOrSchedule(true);
        verify($disabledResult['updates_disabled'] === true, 'Explicit false must return updates_disabled=true');
        verify(count($disabledBg->dispatchedJobs) === $jobsBeforeDisabled, 'Disabled check must dispatch zero queue jobs');

        // 15c. Conflict: process env 'false' takes precedence over $_ENV 'true' (per EnvLoader)
        putenv('ENABLE_UPDATE_CHECKS=false');
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $conflictBg = new TestableBackgroundUpdateCheckService($updateService);
        $conflictResult = $conflictBg->readOrSchedule(true);
        verify($conflictResult['updates_disabled'] === true, 'Process env false must override $_ENV true');

        // 15d. Empty string: defaults to helper default (true)
        putenv('ENABLE_UPDATE_CHECKS=');
        $_ENV['ENABLE_UPDATE_CHECKS'] = '';
        $emptyBg = new TestableBackgroundUpdateCheckService($updateService);
        $emptyResult = $emptyBg->readOrSchedule(true);
        verify(empty($emptyResult['updates_disabled']), 'Empty string must fall back to helper default (true)');
    } finally {
        // Restore test process env afterward
        if ($origEnv !== false) {
            putenv('ENABLE_UPDATE_CHECKS=' . $origEnv);
        } else {
            putenv('ENABLE_UPDATE_CHECKS');
        }
        if ($origServerEnv !== null) {
            $_ENV['ENABLE_UPDATE_CHECKS'] = $origServerEnv;
        } else {
            unset($_ENV['ENABLE_UPDATE_CHECKS']);
        }
    }
    echo "PASS: ENABLE_UPDATE_CHECKS EnvLoader integration (missing default, explicit false, conflict precedence, empty fallback) verified\n";

    echo "\nALL PHP BACKGROUND UPDATE VERIFICATION CHECKS PASSED!\n";
} finally {
    cleanupLab($lab);
}
