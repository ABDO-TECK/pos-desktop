<?php

namespace App\Services;

use App\Helpers\EnvLoader;
use App\Helpers\Logger;
use Throwable;

/**
 * UpdateService — منطق فحص وتطبيق التحديثات بالاعتماد على منصة GitHub Releases
 * مع التحقق من التوقيع الرقمي RSA، التحديثات الجزئية الذرية، والنسخ الاحتياطي والتراجع التلقائي.
 */
class UpdateService
{
    private string $repoUrl;
    private string $localVersionFile;
    private string $clientChannelFile;
    private string $rootDir;
    /** @var list<string> */
    private array $allowedUpdateHosts;

    private GitService $gitService;
    private FrontendBuildService $buildService;
    private BackupService $backupService;
    private DeltaUpdateService $deltaUpdateService;
    private UpdateManifestService $manifestService;
    private ?MigrationService $migrationService;
    private GitHubReleaseProvider $githubProvider;
    private ManifestSignatureService $signatureService;
    private ?UpdateTelemetryService $telemetryService;
    private MigrationSafetyBackupService $migrationSafetyBackupService;

    public function __construct(
        GitService $gitService,
        FrontendBuildService $buildService,
        BackupService $backupService,
        ?DeltaUpdateService $deltaUpdateService = null,
        ?UpdateManifestService $manifestService = null,
        ?MigrationService $migrationService = null,
        ?GitHubReleaseProvider $githubProvider = null,
        ?ManifestSignatureService $signatureService = null,
        ?UpdateTelemetryService $telemetryService = null,
        ?MigrationSafetyBackupService $migrationSafetyBackupService = null
    ) {
        $this->rootDir          = UpdateRuntimePaths::deployedRoot(realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2));
        $normalizedPath         = str_replace('\\', '/', $this->rootDir);
        $this->localVersionFile = $normalizedPath . '/version.json';
        $this->gitService       = $gitService;
        $this->buildService     = $buildService;
        $this->backupService    = $backupService;
        $this->manifestService  = $manifestService ?? new UpdateManifestService();
        $this->deltaUpdateService = $deltaUpdateService ?? new DeltaUpdateService($this->manifestService, $this->rootDir);
        $this->migrationService = $migrationService;
        $this->githubProvider   = $githubProvider ?? new GitHubReleaseProvider();
        $this->signatureService = $signatureService ?? new ManifestSignatureService();
        $this->telemetryService = $telemetryService;
        $storageDir = trim((string) $this->deltaUpdateService->getStorageDir());
        if ($storageDir === '') {
            $storageDir = $normalizedPath . '/backend/storage';
        }
        $this->clientChannelFile = rtrim(str_replace('\\', '/', $storageDir), '/') . '/update_channel.json';
        $this->migrationSafetyBackupService = $migrationSafetyBackupService
            ?? new MigrationSafetyBackupService($backupService, $this->deltaUpdateService->getStorageDir());
        $this->repoUrl          = EnvLoader::get('UPDATE_SERVER_URL', 'https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/latest');
        if (preg_match('#^https://api\.github\.com/repos/ABDO-TECK/(?:pos|pos-desktop)/(?:contents/version\.json|releases)(?:[/?]|$)#', $this->repoUrl)) {
            $this->repoUrl = 'https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/latest';
        }


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

    public function getDeltaUpdateService(): DeltaUpdateService
    {
        return $this->deltaUpdateService;
    }

    public function getManifestService(): UpdateManifestService
    {
        return $this->manifestService;
    }

    public function getGitHubProvider(): GitHubReleaseProvider
    {
        return $this->githubProvider;
    }

    public function getSignatureService(): ManifestSignatureService
    {
        return $this->signatureService;
    }

    public function getTelemetryService(): UpdateTelemetryService
    {
        if ($this->telemetryService === null) {
            $storage = $this->rootDir . '/backend/storage';
            if (!is_dir($storage)) {
                $storage = $this->rootDir . '/storage';
            }
            $this->telemetryService = new UpdateTelemetryService($storage);
        }
        return $this->telemetryService;
    }


    /**
     * Get client update channel ('stable', 'beta', 'rc').
     */
    public function getClientChannel(): string
    {
        $preference = @file_get_contents($this->clientChannelFile);
        if ($preference !== false) {
            $data = json_decode($preference, true);
            $storedChannel = is_array($data) ? strtolower(trim((string) ($data['channel'] ?? ''))) : '';
            if (in_array($storedChannel, ['stable', 'beta', 'rc'], true)) {
                return $storedChannel;
            }
        }

        $local = $this->getLocalVersion();
        $channel = strtolower(trim($local['update_channel'] ?? EnvLoader::get('APP_UPDATE_CHANNEL', 'stable')));
        return in_array($channel, ['stable', 'beta', 'rc'], true) ? $channel : 'stable';
    }

    /**
     * Set client update channel preference.
     */
    public function setClientChannel(string $channel): array
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, ['stable', 'beta', 'rc'], true)) {
            return ['ok' => false, 'error' => 'قناة التحديث المحددة غير صحيحة. القنوات المسموحة: stable, beta, rc.'];
        }

        $storageDir = dirname($this->clientChannelFile);
        if (!is_dir($storageDir) && !@mkdir($storageDir, 0750, true) && !is_dir($storageDir)) {
            return ['ok' => false, 'error' => 'تعذر حفظ إعدادات قناة التحديث.'];
        }

        $json = json_encode([
            'channel' => $channel,
            'updated_at' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($this->clientChannelFile, $json . "\n", LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'تعذر حفظ إعدادات قناة التحديث.'];
        }

        return ['ok' => true, 'channel' => $channel];
    }

    /**
     * Get or generate a persistent, privacy-preserving device identifier.
     */
    public function getDeviceId(): string
    {
        $storageDir = $this->rootDir . '/backend/storage';
        if (!is_dir($storageDir)) {
            $storageDir = $this->rootDir . '/storage';
        }
        $deviceFile = $storageDir . '/.device_id';

        if (file_exists($deviceFile)) {
            $id = trim((string) @file_get_contents($deviceFile));
            if ($id !== '') {
                return $id;
            }
        }

        // Generate RFC 4122 v4 UUID
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

        @mkdir(dirname($deviceFile), 0755, true);
        @file_put_contents($deviceFile, $uuid);

        return $uuid;
    }

    /**
     * قراءة النسخة المحلية من version.json
     */
    public function getLocalVersion(): array
    {
        if (!file_exists($this->localVersionFile)) {
            return ['version' => '0.0.0', 'released_at' => null, 'changelog' => [], 'update_channel' => 'stable'];
        }
        $content = @file_get_contents($this->localVersionFile);
        $data    = $content ? json_decode($content, true) : null;
        return is_array($data) ? $data : ['version' => '0.0.0', 'released_at' => null, 'changelog' => [], 'update_channel' => 'stable'];
    }

    /**
     * جلب النسخة البعيدة من GitHub Releases أو خادم التحديثات
     */
    public function fetchRemoteVersion(?string $channel = null, bool $force = false): ?array
    {
        $targetChannel = $channel ?? $this->getClientChannel();
        $safeChannel = preg_replace('/[^a-z0-9_-]/i', '', $targetChannel) ?: 'stable';
        $storageDir = $this->deltaUpdateService->getStorageDir();
        $cacheFile = rtrim($storageDir, '/\\') . '/remote_version_cache_' . $safeChannel . '.json';

        if (!$force && is_file($cacheFile)) {
            $mtime = @filemtime($cacheFile);
            if ($mtime !== false && (time() - $mtime) < 300) {
                $cached = @json_decode((string) @file_get_contents($cacheFile), true);
                if (is_array($cached) && !empty($cached['ok']) && !empty($cached['data'])) {
                    return $cached['data'];
                }
            }
        }

        $result = $this->fetchRemoteVersionDiagnostics($channel, $force);
        return $result['ok'] ? $result['data'] : null;
    }

    /**
     * جلب النسخة البعيدة مع تشخيص آمن لفحص التحديثات.
     */
    protected function fetchRemoteVersionDiagnostics(?string $channel = null, bool $force = false): array
    {
        if (!$this->isAllowedUpdateUrl($this->repoUrl)) {
            Logger::warning('fetchRemoteVersion rejected a non-allowlisted update URL', [
                'host' => $this->updateHostForLog($this->repoUrl),
                'error_code' => 'invalid_update_url',
            ]);
            return $this->remoteFailure('invalid_update_url', 'Configured update URL is not allowed.');
        }

        $targetChannel = $channel ?? $this->getClientChannel();
        $storageDir = $this->deltaUpdateService->getStorageDir();
        $safeChannel = preg_replace('/[^a-z0-9_-]/i', '', $targetChannel) ?: 'stable';
        $cacheFile = rtrim($storageDir, '/\\') . '/remote_version_cache_' . $safeChannel . '.json';
        $failureCacheFile = rtrim($storageDir, '/\\') . '/remote_version_failure_cache_' . $safeChannel . '.json';

        $cachedFailure = $this->readRemoteFailureCache($failureCacheFile);
        if ($cachedFailure !== null) {
            return $cachedFailure;
        }

        // If repoUrl points specifically to GitHub releases endpoint, use GitHubReleaseProvider
        if (str_contains($this->repoUrl, '/releases')) {
            $local = $this->getLocalVersion();
            $ghRelease = $this->githubProvider->getLatestRelease($targetChannel, $local['version'] ?? null);
            $noEligibleRelease = !empty($ghRelease['no_eligible_release']);
            if ($ghRelease['ok'] && (!empty($ghRelease['latest_version']) || $noEligibleRelease)) {
                $cachePayload = [
                    'ok' => true,
                    'data' => [
                        'version' => $noEligibleRelease ? null : $ghRelease['latest_version'],
                        'tag_name' => $ghRelease['tag_name'],
                        'released_at' => $ghRelease['published_at'],
                        'changelog' => $ghRelease['changelog'],
                        'requires_npm_install' => false,
                        'release_url' => $ghRelease['release_url'],
                        'manifest_url' => $ghRelease['manifest_url'],
                        'signature_url' => $ghRelease['signature_url'],
                        'delta_url' => $ghRelease['delta_url'],
                        'full_package_url' => $ghRelease['full_package_url'],
                        'assets' => $ghRelease['assets'],
                        'channel' => $ghRelease['channel'] ?? 'stable',
                        'no_eligible_release' => $noEligibleRelease,
                    ],
                    'checkedUrl' => $this->repoUrl,
                    'errorCode' => null,
                    'details' => null,
                ];
                if (is_dir(dirname($cacheFile))) {
                    @file_put_contents($cacheFile, json_encode($cachePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
                @unlink($failureCacheFile);
                return $cachePayload;
            }

            $failure = $this->remoteFailure(
                $ghRelease['error_code'] ?? 'github_fetch_failed',
                $ghRelease['error'] ?? 'Failed to fetch latest release from GitHub.',
                (int) ($ghRelease['diagnostics']['http_code'] ?? 0),
                is_array($ghRelease['diagnostics'] ?? null) ? $ghRelease['diagnostics'] : [],
            );
            $this->writeRemoteFailureCache($failureCacheFile, $failure);
            return $failure;
        }


        // Direct HTTP request to UPDATE_SERVER_URL
        $curlOptions = [
            CURLOPT_URL            => $this->repoUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'ABDO-TECK-POS-Updater/1.0',
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github.v3.raw, application/json',
                'Cache-Control: no-cache',
            ],
        ];

        $certPath = $this->resolveCurlCaBundlePath();
        if ($certPath !== null && $certPath !== '' && !str_starts_with($certPath, 'phar://') && is_file($certPath)) {
            $curlOptions[CURLOPT_CAINFO] = $certPath;
        }

        $response = $this->executeRemoteVersionRequest($curlOptions);
        $result   = $response['body'];
        $httpCode = (int) $response['httpCode'];
        $curlErr  = (string) $response['curlErr'];
        $curlErrNo = (int) $response['curlErrNo'];

        if ($httpCode >= 200 && $httpCode < 300 && $result) {
            $data = json_decode($result, true);
            if (!is_array($data) || empty($data['version']) || !is_string($data['version'])) {
                return $this->remoteFailure('invalid_version_json', 'Update server returned invalid version data.', $httpCode);
            }

            $cachePayload = [
                'ok' => true,
                'data' => $data,
                'checkedUrl' => $this->repoUrl,
                'errorCode' => null,
                'details' => null,
            ];
            if (is_dir(dirname($cacheFile))) {
                @file_put_contents($cacheFile, json_encode($cachePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
                @unlink($failureCacheFile);
                return $cachePayload;
        }

        $errorCode = $this->classifyRemoteFailure($httpCode, $curlErr, $curlErrNo);
        $details = $httpCode > 0
            ? "Update server returned HTTP {$httpCode}."
            : 'Unable to contact the update server.';

        Logger::warning('fetchRemoteVersion failed', [
            'http_code' => $httpCode,
            'curl_err'  => $curlErr,
            'curl_errno' => $curlErrNo,
            'host' => $this->updateHostForLog($this->repoUrl),
            'error_code' => $errorCode,
        ]);
        $failure = $this->remoteFailure($errorCode, $details, $httpCode);
        $this->writeRemoteFailureCache($failureCacheFile, $failure);
        return $failure;
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @return array{body: string|false, httpCode: int, curlErr: string, curlErrNo: int}
     */
    protected function executeRemoteVersionRequest(array $curlOptions): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, $curlOptions);
        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        return [
            'body' => $result,
            'httpCode' => $httpCode,
            'curlErr' => $curlErr,
            'curlErrNo' => $curlErrNo,
        ];
    }

    private function resolveCurlCaBundlePath(): ?string
    {
        return UpdateRuntimePaths::getCaBundlePath($this->rootDir);
    }

    private function classifyRemoteFailure(int $httpCode, string $curlErr, int $curlErrNo): string
    {
        $lowerError = strtolower($curlErr);

        if (!$this->isAllowedUpdateUrl($this->repoUrl)) {
            return 'invalid_update_url';
        }
        if ($curlErrNo === 28 || str_contains($lowerError, 'timed out')) {
            return 'github_network_timeout';
        }
        if ($curlErrNo !== 0 && (str_contains($lowerError, 'ssl') || str_contains($lowerError, 'certificate'))) {
            return 'github_ssl_error';
        }
        if ($httpCode === 404) {
            return 'github_http_404';
        }
        if ($httpCode === 403) {
            return 'github_http_403_rate_limited';
        }

        return $curlErrNo !== 0 ? 'github_network_timeout' : 'invalid_version_json';
    }

    private function isAllowedUpdateUrl(string $url): bool
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

    private function updateHostForLog(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : 'invalid';
    }

    private function remoteFailure(string $errorCode, string $details, int $httpCode = 0, array $diagnostics = []): array
    {
        return [
            'ok' => false,
            'data' => null,
            'checkedUrl' => $this->repoUrl,
            'errorCode' => $errorCode,
            'details' => $httpCode > 0 && !str_contains($details, 'HTTP')
                ? "HTTP {$httpCode}: {$details}"
                : $details,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Read a short-lived rate-limit failure cache. A forced check may bypass
     * successful-result caching, but it must not hammer a known rate-limited
     * endpoint before the server's retry window has elapsed.
     */
    private function readRemoteFailureCache(string $cacheFile): ?array
    {
        if (!is_file($cacheFile)) {
            return null;
        }

        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (!is_array($cached) || ($cached['ok'] ?? true) !== false) {
            return null;
        }

        $retryAt = (int) ($cached['retry_at'] ?? 0);
        if ($retryAt <= time()) {
            return null;
        }

        return [
            'ok' => false,
            'data' => null,
            'checkedUrl' => (string) ($cached['checkedUrl'] ?? $this->repoUrl),
            'errorCode' => (string) ($cached['errorCode'] ?? 'github_rate_limited'),
            'details' => (string) ($cached['details'] ?? 'GitHub update discovery is temporarily rate limited.'),
            'diagnostics' => is_array($cached['diagnostics'] ?? null) ? $cached['diagnostics'] : [],
        ];
    }

    private function writeRemoteFailureCache(string $cacheFile, array $failure): void
    {
        $errorCode = (string) ($failure['errorCode'] ?? '');
        if (!in_array($errorCode, ['github_primary_rate_limited', 'github_secondary_rate_limited'], true)) {
            return;
        }

        $diagnostics = is_array($failure['diagnostics'] ?? null) ? $failure['diagnostics'] : [];
        $retryAt = time() + 60;
        if ($errorCode === 'github_primary_rate_limited') {
            $reset = (int) ($diagnostics['rate_limit_reset'] ?? 0);
            if ($reset > time()) {
                $retryAt = $reset;
            }
        } else {
            $retryAfter = (int) ($diagnostics['retry_after'] ?? 0);
            if ($retryAfter > 0) {
                $retryAt = time() + min($retryAfter, 900);
            }
        }

        $payload = [
            'ok' => false,
            'checkedUrl' => $failure['checkedUrl'] ?? $this->repoUrl,
            'errorCode' => $errorCode,
            'details' => $failure['details'] ?? 'GitHub update discovery is temporarily rate limited.',
            'diagnostics' => $diagnostics,
            'retry_at' => $retryAt,
        ];
        $directory = dirname($cacheFile);
        if (is_dir($directory)) {
            @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
    }

    /**
     * مقارنة النسخة المحلية والبعيدة من GitHub Releases والتحقق من التوقيع الرقمي RSA.
     */
    public function checkForUpdate(bool $deltaCapable = false, bool $force = true): array
    {
        $local   = $this->getLocalVersion();
        $enabled = EnvLoader::getBool('ENABLE_UPDATE_CHECKS', true);

        if (!$enabled || empty($this->repoUrl)) {
            return [
                'success'             => false,
                'status'              => 'invalid_update_url',
                'ok'                  => true,
                'updates_disabled'    => true,
                'updates_unreachable' => false,
                'message'             => 'خادم التحديثات غير مهيأ.',
                'current_version'     => $local['version'] ?? '0.0.0',
                'latest_version'      => null,
                'has_update'          => false,
                'currentVersion'      => $local['version'] ?? '0.0.0',
                'latestVersion'       => null,
                'updateAvailable'     => false,
                'checkedUrl'          => $this->repoUrl,
                'errorCode'           => 'invalid_update_url',
                'details'             => $enabled ? 'UPDATE_SERVER_URL is empty.' : 'ENABLE_UPDATE_CHECKS is disabled.',
                'changelog'           => [],
            ];
        }

        $remoteResult = $this->fetchRemoteVersionDiagnostics(null, $force);
        $remote = $remoteResult['ok'] ? $remoteResult['data'] : null;
        $checkedUrl = $remoteResult['checkedUrl'] ?? $this->repoUrl;

        if (!$remote) {
            return [
                'success'             => false,
                'status'              => $remoteResult['errorCode'] ?? 'github_network_timeout',
                'ok'                  => true,
                'updates_disabled'    => false,
                'updates_unreachable' => true,
                'message'             => 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.',
                'current_version'     => $local['version'] ?? '0.0.0',
                'latest_version'      => null,
                'has_update'          => false,
                'currentVersion'      => $local['version'] ?? '0.0.0',
                'latestVersion'       => null,
                'updateAvailable'     => false,
                'checkedUrl'          => $checkedUrl,
                'errorCode'           => $remoteResult['errorCode'] ?? 'github_network_timeout',
                'details'             => $remoteResult['details'] ?? null,
                'diagnostics'         => $remoteResult['diagnostics'] ?? [],
                'changelog'           => [],
            ];
        }

        $currentVersion = $local['version'] ?? '0.0.0';
        if (!empty($remote['no_eligible_release'])) {
            $clientChannel = $this->getClientChannel();
            $this->getTelemetryService()->recordEvent([
                'device_id' => $this->getDeviceId(),
                'application_version' => $currentVersion,
                'update_engine_version' => $local['update_engine_version'] ?? '1.0.0',
                'channel' => $clientChannel,
                'target_version' => null,
                'event_type' => 'update_check_started',
                'success' => true,
                'metadata' => ['discovery' => 'no_eligible_release'],
            ]);

            return [
                'success' => true,
                'status' => 'no_update_available',
                'ok' => true,
                'updates_disabled' => false,
                'updates_unreachable' => false,
                'message' => 'النظام محدّث لأحدث إصدار.',
                'current_version' => $currentVersion,
                'latest_version' => null,
                'update_engine_version' => $local['update_engine_version'] ?? null,
                'client_channel' => $clientChannel,
                'release_channel' => $remote['channel'] ?? $clientChannel,
                'rollout_percentage' => 100,
                'has_update' => false,
                'currentVersion' => $currentVersion,
                'latestVersion' => null,
                'updateAvailable' => false,
                'checkedUrl' => $checkedUrl,
                'errorCode' => null,
                'details' => null,
                'source' => 'github_release',
                'release_tag' => null,
                'release_url' => null,
                'released_at' => null,
                'changelog' => [],
                'requires_npm_install' => false,
                'update_type' => 'full',
                'is_delta' => false,
                'bootstrap_required' => false,
                'manifest_url' => null,
                'signature_url' => null,
                'delta_url' => null,
                'files_count' => null,
                'fallback_reason' => null,
                'fallback_reason_code' => null,
                'compatibility_reason' => null,
                'compatibility_reason_code' => null,
            ];
        }

        $latestVersion = $remote['version'] ?? null;
        $compatibility = ReleaseVersionPolicy::assess($currentVersion, is_string($latestVersion) ? $latestVersion : null);
        if (!$compatibility['compatible'] && $compatibility['reason_code'] === 'invalid_version') {
            return [
                'success'             => false,
                'status'              => 'invalid_version_json',
                'ok'                  => true,
                'updates_disabled'    => false,
                'updates_unreachable' => true,
                'message'             => 'تعذر قراءة ملف التحديثات من الخادم.',
                'current_version'     => $currentVersion,
                'latest_version'      => null,
                'has_update'          => false,
                'currentVersion'      => $currentVersion,
                'latestVersion'       => null,
                'updateAvailable'     => false,
                'checkedUrl'          => $checkedUrl,
                'errorCode'           => 'invalid_version_json',
                'details'             => 'Missing version field in version payload.',
                'changelog'           => [],
            ];
        }

        $local = $this->getLocalVersion();
        $currentVersion = $local['version'] ?? '0.0.0';
        $clientEngineVersion = $local['update_engine_version'] ?? null;
        $clientChannel = $this->getClientChannel();
        $deviceId = $this->getDeviceId();

        // Release generation guard: v0 clients must never update to a newer generation.
        $compatibility = ReleaseVersionPolicy::assess($currentVersion, $latestVersion);
        $hasUpdate = $compatibility['compatible'] && version_compare($latestVersion, $currentVersion, '>');
        $deltaReason = $compatibility['reason'];
        $deltaReasonCode = $compatibility['reason_code'];

        // Emit update_check_started telemetry
        $this->getTelemetryService()->recordEvent([
            'device_id'             => $deviceId,
            'application_version'   => $currentVersion,
            'update_engine_version' => $clientEngineVersion ?? '1.0.0',
            'channel'               => $clientChannel,
            'target_version'        => $latestVersion,
            'event_type'            => 'update_check_started',
            'success'               => true,
        ]);

        // Check if delta update is available and verified in the GitHub Release assets

        $updateType = 'full';
        $isDelta = false;
        $bootstrapRequired = false;
        $deltaManifest = null;
        $manifestUrl = $remote['manifest_url'] ?? null;
        $signatureUrl = $remote['signature_url'] ?? null;
        $deltaUrl = $remote['delta_url'] ?? null;

        if ($hasUpdate) {
            $manifestContent = null;
            $signatureContent = null;

            // 1. Direct manifest in payload
            if (isset($remote['manifest']) && is_array($remote['manifest'])) {
                $deltaManifest = $remote['manifest'];
            } elseif ($manifestUrl) {
                // 2. Download manifest.json from GitHub Release asset
                $mfFetch = $this->githubProvider->fetchAssetContent($manifestUrl);
                if ($mfFetch['ok'] && $mfFetch['content']) {
                    $manifestContent = $mfFetch['content'];
                    $decoded = json_decode($manifestContent, true);
                    if (is_array($decoded)) {
                        $deltaManifest = $decoded;
                    }
                }
            }

            if ($deltaManifest !== null) {
                // Verify digital signature if signature URL exists or manifest string exists
                $requireSignature = $this->requiresUpdateSignature();
                $signatureValid = true;

                if ($signatureUrl) {
                    $sigFetch = $this->githubProvider->fetchAssetContent($signatureUrl);
                    if ($sigFetch['ok'] && $sigFetch['content']) {
                        $signatureContent = $sigFetch['content'];
                    }
                }

                if ($manifestContent !== null && $signatureContent !== null) {
                    $signatureValid = $this->signatureService->verifySignature($manifestContent, $signatureContent);
                    if (!$signatureValid) {
                        Logger::warning('Update manifest signature verification failed!', [
                            'version' => $latestVersion,
                            'manifest_url' => $manifestUrl,
                        ]);
                        $deltaReason = 'Manifest digital signature verification failed.';
                    }
                } elseif ($requireSignature) {
                    $signatureValid = false;
                    $deltaReason = 'Release is missing required cryptographic signature.';
                }

                if ($signatureValid) {
                    $validation = $this->manifestService->validateManifest($deltaManifest);
                    if ($validation['valid'] && $validation['manifest'] !== null) {
                        $manifestData = $validation['manifest'];
                        $association = $this->manifestService->checkReleaseAssociation($manifestData, $latestVersion, $remote['tag_name'] ?? ('v' . $latestVersion), $clientChannel);
                        $manifestCompatibility = ReleaseVersionPolicy::assess(
                            $currentVersion,
                            $manifestData['version'] ?? null
                        );

                        if (!$association['compatible']) {
                            $hasUpdate = false;
                            $deltaReason = $association['reason'];
                            $deltaReasonCode = 'manifest_release_mismatch';
                        } elseif (!$manifestCompatibility['compatible']) {
                            $hasUpdate = false;
                            $deltaReason = $manifestCompatibility['reason'];
                            $deltaReasonCode = $manifestCompatibility['reason_code'];
                        } elseif (!(($engineCheck = $this->manifestService->checkEngineCompatibility($clientEngineVersion, $manifestData))['compatible'] ?? false)) {
                            $deltaReason = $engineCheck['reason'];
                            $deltaReasonCode = 'engine_incompatible';
                            $hasUpdate = false;
                        } elseif (!empty($manifestData['migration_release']) || ($manifestData['type'] ?? '') === 'full') {
                            $updateType = 'full';
                            $isDelta = false;
                            $deltaReason = 'Release is a full bootstrap migration package.';
                        } else {
                            // Check channel compatibility
                            $relChannel = $manifestData['channel'] ?? ($remote['channel'] ?? 'stable');
                            $chanCheck = $this->manifestService->checkChannelCompatibility($clientChannel, $relChannel);
                            if (!$chanCheck['compatible']) {
                                $deltaReason = $chanCheck['reason'];
                                $hasUpdate = false;
                            } else {
                                // Check gradual rollout percentage
                                $rollout = (int) ($manifestData['rollout_percentage'] ?? 100);
                                $rolloutCheck = $this->manifestService->checkRolloutEligibility($deviceId, $latestVersion, $rollout);
                                if (!$rolloutCheck['eligible']) {
                                    $hasUpdate = false;
                                    $deltaReason = $rolloutCheck['reason'];
                                } else {
                                    $compat = $this->manifestService->checkVersionCompatibility($currentVersion, $manifestData);
                                    if ($compat['compatible']) {
                                        if (!$deltaCapable) {
                                            $updateType = 'bootstrap';
                                            $bootstrapRequired = true;
                                            $deltaReason = 'Application update required before compatible incremental updates can be installed.';
                                        } else {
                                            $updateType = 'delta';
                                            $isDelta = true;
                                            $deltaManifest = $manifestData;
                                        }
                                    } else {
                                        $deltaReason = $compat['reason'];
                                    }
                                }
                            }
                        }
                    } else {
                        $deltaReason = !empty($validation['errors']) ? implode('; ', $validation['errors']) : 'Invalid manifest structure.';
                    }
                }
            }
        }

        if ($hasUpdate) {
            $this->getTelemetryService()->recordEvent([
                'device_id'             => $deviceId,
                'application_version'   => $currentVersion,
                'update_engine_version' => $clientEngineVersion ?? '1.0.0',
                'channel'               => $clientChannel,
                'target_version'        => $latestVersion,
                'event_type'            => 'update_available',
                'success'               => true,
                'metadata'              => [
                    'is_delta'    => $isDelta,
                    'files_count' => $deltaManifest ? count($deltaManifest['files'] ?? []) : null,
                    'update_type' => $updateType,
                ],
            ]);
        }

        return [
            'success'               => true,

            'status'                => $hasUpdate ? 'update_available' : 'no_update_available',
            'ok'                    => true,
            'updates_disabled'      => false,
            'updates_unreachable'   => false,
            'message'               => $hasUpdate ? 'يتوفر تحديث جديد.' : ($deltaReason ?? 'النظام محدّث لأحدث إصدار.'),
            'current_version'       => $currentVersion,
            'latest_version'        => $latestVersion,
            'update_engine_version' => $clientEngineVersion,
            'client_channel'        => $clientChannel,
            'release_channel'       => $deltaManifest['channel'] ?? ($remote['channel'] ?? 'stable'),
            'rollout_percentage'    => $deltaManifest['rollout_percentage'] ?? 100,
            'has_update'            => $hasUpdate,
            'currentVersion'        => $currentVersion,
            'latestVersion'         => $latestVersion,
            'updateAvailable'       => $hasUpdate,
            'checkedUrl'            => $checkedUrl,
            'errorCode'             => null,
            'details'               => null,
            'source'                => 'github_release',
            'release_tag'           => $remote['tag_name'] ?? "v{$latestVersion}",
            'release_url'           => $remote['release_url'] ?? null,
            'released_at'           => $remote['released_at'] ?? null,
            'changelog'             => $remote['changelog'] ?? [],
            'requires_npm_install'  => $remote['requires_npm_install'] ?? false,
            'update_type'           => $updateType,
            'is_delta'              => $isDelta,
            'bootstrap_required'    => $bootstrapRequired,
            'manifest_url'          => $manifestUrl,
            'signature_url'         => $signatureUrl,
            'delta_url'             => $deltaUrl,
            'files_count'           => $deltaManifest ? count($deltaManifest['files'] ?? []) : null,
            'fallback_reason'       => $deltaReason,
            'fallback_reason_code'  => $deltaReasonCode,
            'compatibility_reason'  => $deltaReason,
            'compatibility_reason_code' => $deltaReasonCode,
        ];

    }

    /**
     * تطبيق التحديث بالاعتماد على GitHub Releases أو التحديث الكامل.
     *
     * @return array ['ok' => bool, 'data' => array|null, 'error' => string|null, 'code' => int]
     */
    public function applyUpdate(bool $force, bool $deltaCapable = true): array
    {
        $lock = new UpdateOperationLock($this->deltaUpdateService->getStorageDir());
        $state = $this->deltaUpdateService->getUpdateState();
        if (UpdateOperationLock::isActiveState($state)) {
            return $this->updateCoordinationFailure($state['owner_id'] ?? null, $state['operation'] ?? null);
        }

        $lease = $lock->acquire('backend_delta_apply');
        if (!$lease['acquired']) {
            return $this->updateCoordinationFailure(
                $lease['owner']['owner_id'] ?? null,
                $lease['owner']['operation'] ?? null,
                $lease
            );
        }

        try {
            $lock->heartbeat('starting');
            $this->deltaUpdateService->setUpdateState('applying', [
                'operation' => 'backend_delta_apply',
                'owner_id' => $lease['owner_id'],
            ]);

            $result = $this->applyUpdateUnlocked($force, $deltaCapable);
            if (!$result['ok']) {
                $currentState = $this->deltaUpdateService->getUpdateState();
                if (!in_array($currentState['state'] ?? null, [
                    'failed',
                    'rolled_back',
                    'database_recovery_failed',
                    'backup_failed',
                ], true)) {
                    $this->deltaUpdateService->setUpdateState('failed', [
                        'error' => $result['error'] ?? 'Update operation failed.',
                        'operation' => 'backend_delta_apply',
                        'owner_id' => $lease['owner_id'],
                    ]);
                }
            }
            return $result;
        } finally {
            $lock->release();
        }
    }

    private function applyUpdateUnlocked(bool $force, bool $deltaCapable = true): array
    {
        $output = [];
        $currentVersion = $this->getLocalVersion()['version'] ?? '0.0.0';

        $enabled = EnvLoader::getBool('ENABLE_UPDATE_CHECKS', true);
        if (!$enabled || empty($this->repoUrl)) {
            return [
                'ok'               => false,
                'updates_disabled' => true,
                'error'            => 'خادم التحديثات غير مهيأ.',
                'code'             => 403,
                'data'             => ['logs' => $output]
            ];
        }

        if (!$this->isAllowedUpdateUrl($this->repoUrl)) {
            return [
                'ok' => false,
                'error' => 'Automatic updates are not configured for an approved server.',
                'code' => 400,
                'data' => ['logs' => $output],
            ];
        }

        // فحص مساحة القرص قبل البدء
        $diskCheck = $this->deltaUpdateService->checkDiskSpace(104857600); // 100 MB
        if (!$diskCheck['ok']) {
            $err = $diskCheck['error'] ?? 'مساحة القرص غير كافية لإجراء التحديث.';
            Logger::error('Update aborted: insufficient disk space', ['free_bytes' => $diskCheck['free_bytes']]);
            return [
                'ok' => false,
                'error' => $err,
                'code' => 507,
                'data' => ['logs' => $output],
            ];
        }

        // Database recovery backups are intentionally deferred until a
        // verified Delta manifest proves that migrations are present.
        $migrationSafetyBackup = null;

        // الخطوة 1: جلب معلومات الإصدار البعيد
        $output[] = '🌐 الاتصال بخادم التحديثات...';
        $remote = $this->fetchRemoteVersion();
        if (!$remote) {
            return ['ok' => false, 'error' => 'تعذر الاتصال بخادم التحديثات. تحقق من اتصالك بالإنترنت.', 'code' => 502, 'data' => ['logs' => $output]];
        }
        $targetVersion = $remote['version'] ?? 'unknown';
        $compatibility = ReleaseVersionPolicy::assess(
            $currentVersion,
            is_string($targetVersion) ? $targetVersion : null
        );
        if (!$compatibility['compatible']) {
            $code = $compatibility['reason_code'] === 'legacy_generation' ? 409 : 422;
            return [
                'ok' => false,
                'error' => $compatibility['reason'],
                'code' => $code,
                'data' => [
                    'reason_code' => $compatibility['reason_code'],
                    'current_version' => $currentVersion,
                    'target_version' => is_string($targetVersion) ? $targetVersion : null,
                    'logs' => $output,
                ],
            ];
        }
        $releaseTag = $remote['tag_name'] ?? "v{$targetVersion}";
        $output[] = "✅ الإصدار المتاح: {$releaseTag}";

        // التحقق مما إذا كان هناك مانيفست دلتا متوافق
        $potentialManifest = $remote['manifest'] ?? (isset($remote['files']) && is_array($remote['files']) ? $remote : null);

        // Fetch manifest if URL provided
        $manifestContent = null;
        $signatureContent = null;
        if ($potentialManifest === null && !empty($remote['manifest_url'])) {
            $mfRes = $this->githubProvider->fetchAssetContent($remote['manifest_url']);
            if ($mfRes['ok'] && $mfRes['content']) {
                $manifestContent = $mfRes['content'];
                $potentialManifest = json_decode($manifestContent, true);
            }
        }

        if (!empty($remote['signature_url'])) {
            $sigRes = $this->githubProvider->fetchAssetContent($remote['signature_url']);
            if ($sigRes['ok'] && $sigRes['content']) {
                $signatureContent = $sigRes['content'];
            }
        }

        $signatureRequired = $this->requiresUpdateSignature();
        if ($signatureRequired && (!$manifestContent || !$signatureContent)) {
            return [
                'ok' => false,
                'error' => 'Release manifest and cryptographic signature are required for this deployment.',
                'code' => 403,
                'data' => ['logs' => $output],
            ];
        }

        // Verify the signature whenever a manifest was fetched. Packaged
        // desktop deployments require both the manifest and the signature.
        if ($manifestContent && $signatureContent) {
            if (!$this->signatureService->verifySignature($manifestContent, $signatureContent)) {
                $err = 'فشل التحقق من التوقيع الرقمي لملف التحديث (Digital Signature Mismatch). تم رفض التحديث لأسباب أمنية.';
                Logger::error('Rejected update due to invalid signature', ['tag' => $releaseTag]);
                $this->deltaUpdateService->recordHistory(
                    $currentVersion,
                    $targetVersion,
                    'delta',
                    'failed',
                    0,
                    null,
                    $err,
                    'github_release',
                    $releaseTag
                );
                return ['ok' => false, 'error' => $err, 'code' => 403, 'data' => ['logs' => $output]];
            }
            $output[] = '✅ تم التحقق بنجاح من التوقيع الرقمي RSA المعتمد لحزمة التحديث.';
        }

        if (is_array($potentialManifest)) {
            $val = $this->manifestService->validateManifest($potentialManifest);
            if ($val['valid'] && $val['manifest'] !== null) {
                $association = $this->manifestService->checkReleaseAssociation($val['manifest'], $targetVersion, $releaseTag, $this->getClientChannel());
                if (!$association['compatible']) {
                    return ['ok' => false, 'error' => $association['reason'], 'code' => 403, 'data' => ['logs' => $output]];
                }
                $manifestCompatibility = ReleaseVersionPolicy::assess(
                    $currentVersion,
                    $val['manifest']['version'] ?? null
                );
                if (!$manifestCompatibility['compatible']) {
                    $code = $manifestCompatibility['reason_code'] === 'legacy_generation' ? 409 : 422;
                    return [
                        'ok' => false,
                        'error' => $manifestCompatibility['reason'],
                        'code' => $code,
                        'data' => [
                            'reason_code' => $manifestCompatibility['reason_code'],
                            'current_version' => $currentVersion,
                            'target_version' => $targetVersion,
                            'logs' => $output,
                        ],
                    ];
                }

                $compat = $this->manifestService->checkVersionCompatibility(
                    $currentVersion,
                    $val['manifest'],
                    $force
                );

                if ($compat['compatible']) {
                    $manifest = $val['manifest'];
                    if (!$deltaCapable) {
                        return [
                            'ok' => false,
                            'error' => 'Application update required before this incremental update can be installed.',
                            'code' => 426,
                            'data' => ['bootstrap_required' => true, 'logs' => $output],
                        ];
                    }
                    $output[] = '📦 تجهيز التحديث عبر نظام التحديث الجزئي الآمن (Delta Update)...';

                    // 1. تحميل الملفات أو حزمة ZIP إلى مجلد التجهيز
                    $deltaZipUrl = $remote['delta_url'] ?? null;
                    if ($deltaZipUrl) {
                        $downloadResult = $this->deltaUpdateService->downloadReleaseZipToStaging(
                            $manifest,
                            $deltaZipUrl,
                            $this->githubProvider
                        );
                    } else {
                        $baseUrl = $remote['download_url'] ?? dirname($this->repoUrl);
                        $downloadResult = $this->deltaUpdateService->downloadFilesToStaging($manifest, $baseUrl);
                    }

                    $output = array_merge($output, $downloadResult['logs']);

                    if ($downloadResult['ok']) {
                        if (!empty($manifest['migrations'])) {
                            $recoveryId = 'delta-' . $currentVersion . '-to-' . $targetVersion . '-' . bin2hex(random_bytes(6));
                            $output[] = '💾 إنشاء والتحقق من نسخة استرداد قاعدة البيانات...';
                            $migrationSafetyBackup = $this->migrationSafetyBackupService->createMigrationSafetyBackup(
                                $currentVersion,
                                $targetVersion,
                                $recoveryId
                            );
                            if (!$migrationSafetyBackup['ok']) {
                                $err = 'Migration safety backup could not be created and verified; update was not applied.';
                                $this->deltaUpdateService->setUpdateState('backup_failed', ['error' => $err, 'to_version' => $targetVersion]);
                                return ['ok' => false, 'error' => $err, 'code' => 500, 'data' => ['logs' => $output]];
                            }
                            $output[] = '✅ تم إنشاء والتحقق من نسخة استرداد قاعدة البيانات.';
                        }

                        // 2. أخذ لقطة احتياطية كاملة للملفات السابقة
                        $output[] = '📸 أخذ لقطة احتياطية كاملة للملفات السابقة...';
                        $snapshot = $this->deltaUpdateService->createBackupSnapshot(
                            $currentVersion,
                            $targetVersion,
                            $manifest,
                            $migrationSafetyBackup
                        );

                        if (!$snapshot['ok']) {
                            $err = "فشل إنشاء اللقطة الاحتياطية للملفات: {$snapshot['error']}";
                            $this->deltaUpdateService->recordHistory(
                                $currentVersion,
                                $targetVersion,
                                'delta',
                                'failed',
                                0,
                                null,
                                $err,
                                'github_release',
                                $releaseTag
                            );
                            return [
                                'ok' => false,
                                'error' => $err,
                                'code' => 500,
                                'data' => ['logs' => $output],
                            ];
                        }
                        $output[] = '✅ تم إنشاء لقطة النسخ الاحتياطي الذرية بنجاح.';

                        if ($this->deltaUpdateService->requiresDesktopHandoff($manifest)) {
                            $handoff = $this->deltaUpdateService->prepareDesktopHandoff($manifest, $snapshot['snapshot_path'], $migrationSafetyBackup);
                            if (!$handoff['ok']) {
                                return ['ok' => false, 'error' => $handoff['error'], 'code' => 500, 'data' => ['logs' => $output]];
                            }
                            $output[] = '🔁 تم تجهيز التحديث لإعادة تشغيل آمنة واستبدال backend.phar.';
                            return ['ok' => true, 'data' => [
                                'message' => 'التحديث جاهز لإعادة التشغيل الآمنة',
                                'latest_version' => $targetVersion,
                                'update_type' => 'delta',
                                'requires_desktop_handoff' => true,
                                'handoff_version' => $handoff['version'],
                                'logs' => $output,
                            ]];
                        }

                        // 3. الاستبدال الذري للملفات مع التراجع التلقائي في حال الخطأ
                        $applyResult = $this->deltaUpdateService->applyStagedFiles($manifest, $snapshot['snapshot_path']);
                        $output = array_merge($output, $applyResult['logs']);

                        if (!$applyResult['ok']) {
                            $err = 'فشل استبدال ملفات التحديث وتم التراجع التلقائي: ' . implode('; ', $applyResult['errors']);
                            $this->deltaUpdateService->recordHistory(
                                $currentVersion,
                                $targetVersion,
                                'delta',
                                'rolled_back',
                                count($applyResult['applied_files']),
                                $snapshot['snapshot_path'],
                                $err,
                                'github_release',
                                $releaseTag
                            );
                            return [
                                'ok' => false,
                                'error' => $err,
                                'code' => 500,
                                'data' => ['logs' => $output],
                            ];
                        }

                        // 4. A delta declares the canonical migrations it
                        // carries inside backend.phar. Do not touch the schema
                        // for a frontend-only or code-only patch.
                        $migrationResult = ['executed' => 0, 'errors' => []];
                        if (!empty($manifest['migrations'])) {
                            $this->deltaUpdateService->setUpdateState('migrating');
                            $output[] = '🗄️ تطبيق تحديثات قاعدة البيانات المطلوبة...';
                            require_once __DIR__ . '/MigrationService.php';
                            $migrationService = $this->migrationService ?? new MigrationService();
                            $migrationResult = $migrationService->runMigrations($manifest['migrations'], false);
                        } else {
                            $output[] = 'ℹ️ لا توجد ترحيلات قاعدة بيانات في هذا التحديث الجزئي.';
                        }

                        if (!empty($migrationResult['errors'])) {
                            $migrationErr = 'فشل ترحيل قاعدة البيانات: ' . implode('; ', $migrationResult['errors']);
                            $output[] = "❌ {$migrationErr}";
                            $output[] = '🔄 جاري التراجع التلقائي عن ملفات التحديث لحماية سلامة النظام...';

                            $dbRecovery = $this->migrationSafetyBackupService->restoreMigrationSafetyBackup(
                                (string) ($migrationSafetyBackup['backup_path'] ?? ''),
                                (string) ($migrationSafetyBackup['recovery_id'] ?? '')
                            );
                            if (!$dbRecovery['ok']) {
                                $recoveryError = 'Database recovery failed after migration failure: ' . ($dbRecovery['error'] ?? 'unknown error');
                                $this->deltaUpdateService->setUpdateState('database_recovery_failed', [
                                    'error' => $recoveryError,
                                    'backup_snapshot' => $snapshot['snapshot_path'],
                                    'db_recovery' => $migrationSafetyBackup,
                                ]);
                                $this->deltaUpdateService->recordHistory($currentVersion, $targetVersion, 'delta', 'database_recovery_failed', count($applyResult['applied_files']), $snapshot['snapshot_path'], $recoveryError, 'github_release', $releaseTag);
                                return ['ok' => false, 'error' => $recoveryError, 'code' => 500, 'data' => ['logs' => $output]];
                            }
                            $fileRollback = $this->deltaUpdateService->rollbackFiles($snapshot['snapshot_path']);
                            $output = array_merge($output, $fileRollback['logs'] ?? []);
                            if (empty($fileRollback['ok'])) {
                                $fileRollbackErrors = $fileRollback['errors'] ?? ['Unknown file restoration error.'];
                                $fileRollbackError = 'فشل استعادة ملفات التحديث: ' . implode('; ', $fileRollbackErrors);
                                $output[] = "❌ {$fileRollbackError}";
                                $this->deltaUpdateService->setUpdateState('rollback_failed', [
                                    'error' => $fileRollbackError,
                                    'backup_snapshot' => $snapshot['snapshot_path'],
                                    'rollback_errors' => $fileRollbackErrors,
                                    'restored_files' => $fileRollback['restored_files'] ?? [],
                                ]);
                                $this->deltaUpdateService->recordHistory(
                                    $currentVersion,
                                    $targetVersion,
                                    'delta',
                                    'rollback_failed',
                                    count($applyResult['applied_files']),
                                    $snapshot['snapshot_path'],
                                    "{$migrationErr}; {$fileRollbackError}",
                                    'github_release',
                                    $releaseTag
                                );

                                return [
                                    'ok' => false,
                                    'error' => "{$migrationErr}; {$fileRollbackError}",
                                    'code' => 500,
                                    'data' => ['logs' => $output],
                                ];
                            }
                            $this->deltaUpdateService->recordHistory(
                                $currentVersion,
                                $targetVersion,
                                'delta',
                                'rolled_back',
                                count($applyResult['applied_files']),
                                $snapshot['snapshot_path'],
                                $migrationErr,
                                'github_release',
                                $releaseTag
                            );

                            return [
                                'ok' => false,
                                'error' => "{$migrationErr} (تم التراجع التلقائي بنجاح).",
                                'code' => 500,
                                'data' => ['logs' => $output],
                            ];
                        }

                        if ($migrationResult['executed'] > 0) {
                            $output[] = "✅ تم تطبيق {$migrationResult['executed']} تحديث(ات) لقاعدة البيانات";
                        } elseif (!empty($manifest['migrations'])) {
                            $output[] = "✅ قاعدة البيانات محدثة سلفاً";
                        }

                        $output[] = '';
                        $output[] = '🎉 تم استكمال التحديث الجزئي بنجاح إلى v' . $targetVersion;

                        $this->deltaUpdateService->recordHistory(
                            $currentVersion,
                            $targetVersion,
                            'delta',
                            'success',
                            count($applyResult['applied_files']),
                            $snapshot['snapshot_path'],
                            null,
                            'github_release',
                            $releaseTag,
                            $deltaZipUrl
                        );

                        Logger::info('Delta update completed successfully', [
                            'from' => $currentVersion,
                            'to' => $targetVersion,
                            'files_count' => count($applyResult['applied_files']),
                        ]);

                        return ['ok' => true, 'data' => [
                            'message' => 'تم استكمال التحديث بنجاح',
                            'latest_version' => $targetVersion,
                            'release_tag' => $releaseTag,
                            'update_type' => 'delta',
                            'applied_files' => $applyResult['applied_files'],
                            'changelog' => $manifest['changelog'] ?? ($remote['changelog'] ?? []),
                            'logs' => $output,
                        ]];
                    }

                    $output[] = '⚠️ تعذر إكمال تحميل ملفات التحديث الجزئي، الانتقال إلى التحديث الكامل كإجراء احتياطي...';
                } else {
                    $output[] = "ℹ️ التحديث الجزئي غير متوافق: {$compat['reason']}. جاري الانتقال للتحديث الكامل...";
                }
            }
        }

        // ══════════════════════════════════════════════════════════════
        // Fallback: Full Git Package Update Mechanism
        // ══════════════════════════════════════════════════════════════
        $output[] = '🔄 تشغيل آلية التحديث الكامل الاحتياطية (Full Update)...';

        $expectedCommit = strtolower(trim(
            EnvLoader::get('UPDATE_COMMIT_SHA', '')
        ));
        if (!preg_match('/\A[0-9a-f]{40}\z/i', $expectedCommit)) {
            return [
                'ok' => false,
                'error' => 'Full update fallback is disabled until UPDATE_COMMIT_SHA is configured.',
                'code' => 503,
                'data' => ['logs' => $output],
            ];
        }

        // فحص Git
        $output[] = '🔧 التحقق من Git...';
        $diag = $this->gitService->diagnoseGit();
        $gitDir = $this->getRootDir() . DIRECTORY_SEPARATOR . '.git';

        if (!is_dir($gitDir) && !file_exists($gitDir)) {
            [$revOut, $revCode] = $this->gitService->runGit(['rev-parse', '--git-dir']);
            if ($revCode !== 0) {
                Logger::error('Update: .git not found', $diag);
                return ['ok' => false, 'error' => 'Automatic update is unavailable for this installation.', 'code' => 400, 'data' => ['logs' => $output]];
            }
        }

        $this->gitService->ensureSafeDirectory();

        [$testOut, $testCode] = $this->gitService->runGit(['status', '--porcelain']);
        if ($testCode !== 0) {
            $this->gitService->runGit(['config', '--global', '--add', 'safe.directory', $this->getRootDir()]);
        }

        [$statusOut, $statusCode] = $this->gitService->runGit(['status', '--porcelain']);
        $ignoredPatterns = [
            'frontend/dist/',
            'frontend/node_modules/',
            'backend/storage/',
            'backend/cache/',
            'backend/logs/',
            'backend/vendor/',
            '.env',
        ];
        $significantChanges = array_filter($statusOut, function($line) use ($ignoredPatterns) {
            $line = trim($line);
            if ($line === '') return false;
            if (str_starts_with($line, '??')) return false;
            foreach ($ignoredPatterns as $pattern) {
                if (str_contains($line, $pattern)) return false;
            }
            return true;
        });

        if (!empty($significantChanges) && !$force) {
            $output[] = '⚠️ توجد تعديلات محلية.';
            return ['ok' => false, 'error' => 'يوجد تعديلات محلية في ملفات النظام. إذا قمت بالتحديث، سيتم استبدال هذه التعديلات. هل أنت متأكد من رغبتك في المتابعة ومسح التعديلات المحلية؟', 'code' => 409, 'data' => ['logs' => $output, 'local_changes' => $significantChanges]];
        }

        // سحب التحديثات من Git
        $output[] = '📥 سحب التحديثات من خادم Git...';
        $t0 = microtime(true);

        [$fetchOut, $fetchCode] = $this->gitService->runGit(['fetch', '--depth=1', 'origin', 'main', '--force']);
        $output = array_merge($output, array_filter($fetchOut, fn($l) => trim($l) !== ''));

        if ($fetchCode !== 0) {
            Logger::error('Update: git fetch failed', ['code' => $fetchCode, 'output' => $fetchOut]);
            return ['ok' => false, 'error' => 'فشل أمر git fetch — تحقق من اتصال الإنترنت ومن إعدادات المستودع.', 'code' => 500, 'data' => ['logs' => $output]];
        }

        [$headOut, $headCode] = $this->gitService->runGit(['rev-parse', 'FETCH_HEAD']);
        $fetchedCommit = strtolower(trim(implode("\n", $headOut)));
        if ($headCode !== 0 || !hash_equals($expectedCommit, $fetchedCommit)) {
            Logger::error('Update: fetched commit did not match the pinned release', [
                'expected' => $expectedCommit,
                'fetched' => $fetchedCommit,
            ]);
            return ['ok' => false, 'error' => 'The remote release does not match the trusted release.', 'code' => 409, 'data' => ['logs' => $output]];
        }

        [, $verifyCode] = $this->gitService->runGit(['verify-commit', '--strict', $fetchedCommit]);
        if ($verifyCode !== 0) {
            Logger::error('Update: release commit signature verification failed', [
                'commit' => $fetchedCommit,
            ]);
            return ['ok' => false, 'error' => 'Release signature verification failed.', 'code' => 412, 'data' => ['logs' => $output]];
        }

        $this->gitService->runGit(['stash', '--include-untracked']);

        [$resetOut, $resetCode] = $this->gitService->runGit(['reset', '--hard', $fetchedCommit]);
        $output = array_merge($output, array_filter($resetOut, fn($l) => trim($l) !== ''));

        if ($resetCode !== 0) {
            Logger::error('Update: git reset failed', ['code' => $resetCode, 'output' => $resetOut]);
            return ['ok' => false, 'error' => 'فشل أمر git reset — تحقق من صلاحيات المجلد.', 'code' => 500, 'data' => ['logs' => $output]];
        }

        $elapsed  = round(microtime(true) - $t0, 1);
        $output[] = "✅ تم سحب التحديثات ({$elapsed}s)";

        // تثبيت حزم npm
        $requiresNpm = $remote['requires_npm_install'] ?? false;
        if ($requiresNpm) {
            $this->buildService->installDependencies($output);
        }

        // بناء الـ frontend
        $this->buildService->buildFrontend($output);

        // تطبيق المهاجرات
        $output[] = '🗄️ تطبيق تحديثات قاعدة البيانات (إن وجدت)...';
        $t0 = microtime(true);
        require_once __DIR__ . '/MigrationService.php';
        $migrationService = $this->migrationService ?? new MigrationService();
        $migrationResult = $migrationService->runAllMigrations(true);
        $elapsed = round(microtime(true) - $t0, 1);
        if ($migrationResult['executed'] > 0) {
            $output[] = "✅ تم تطبيق {$migrationResult['executed']} تحديث(ات) لقاعدة البيانات ({$elapsed}s)";
        } else {
            $output[] = "✅ قاعدة البيانات محدثة سلفاً ({$elapsed}s)";
        }

        $output[] = '';
        $output[] = '🎉 تم استكمال التحديث بنجاح إلى v' . $targetVersion;

        $this->deltaUpdateService->recordHistory(
            $currentVersion,
            $targetVersion,
            'full',
            'success',
            0,
            null,
            null,
            'git_fallback',
            $releaseTag
        );

        Logger::info('Full update applied successfully', [
            'from'    => $currentVersion,
            'to'      => $targetVersion,
        ]);

        return ['ok' => true, 'data' => [
            'message'        => 'تم استكمال التحديث بنجاح',
            'latest_version' => $targetVersion,
            'update_type'    => 'full',
            'changelog'      => $remote['changelog'] ?? [],
            'logs'           => $output,
        ]];
    }

    public function rollbackUpdate(?string $snapshotPath = null): array
    {
        $snapshot = $snapshotPath ?? $this->deltaUpdateService->findLatestSnapshot();
        if ($snapshot && is_file(rtrim($snapshot, '/\\') . '/metadata.json')) {
            $metadata = json_decode((string) @file_get_contents(rtrim($snapshot, '/\\') . '/metadata.json'), true);
            $dbRecovery = is_array($metadata) ? ($metadata['db_recovery'] ?? null) : null;
            if (is_array($dbRecovery) && !empty($dbRecovery['backup_path']) && !empty($dbRecovery['recovery_id'])) {
                $database = $this->migrationSafetyBackupService->restoreMigrationSafetyBackup(
                    (string) $dbRecovery['backup_path'],
                    (string) $dbRecovery['recovery_id']
                );
                if (!$database['ok']) {
                    return [
                        'ok' => false,
                        'snapshot' => $snapshot,
                        'logs' => [],
                        'error' => 'Database recovery failed; application files were not rolled back: ' . ($database['error'] ?? 'unknown error'),
                    ];
                }
            }
        }

        $res = $this->deltaUpdateService->rollbackUpdate($snapshot);
        if (!empty($res['ok'])) {
            $this->getTelemetryService()->recordEvent([
                'device_id'             => $this->getDeviceId(),
                'application_version'   => $this->getLocalVersion()['version'] ?? '0.0.0',
                'update_engine_version' => '1.0.0',
                'channel'               => $this->getClientChannel(),
                'target_version'        => null,
                'event_type'            => 'rollback_completed',
                'success'               => true,
                'metadata'              => [
                    'snapshot_name' => basename($res['snapshot'] ?? ($snapshotPath ?? 'latest')),
                ],
            ]);
        }
        return $res;
    }

    private function updateCoordinationFailure(
        ?string $ownerId,
        ?string $operation,
        ?array $lockResult = null
    ): array {
        $owner = $lockResult['owner'] ?? null;
        if ($owner === null && ($ownerId !== null || $operation !== null)) {
            $owner = [
                'owner_id' => $ownerId,
                'operation' => $operation,
            ];
        }

        return [
            'ok' => false,
            'error' => 'Another update or sale operation is already in progress.',
            'code' => 409,
            'data' => [
                'reason_code' => $lockResult['reason_code'] ?? 'update_in_progress',
                'owner' => $owner,
            ],
        ];
    }

    private function requiresUpdateSignature(): bool
    {
        return EnvLoader::getBool('REQUIRE_UPDATE_SIGNATURE', \Phar::running(false) !== '');
    }
}
