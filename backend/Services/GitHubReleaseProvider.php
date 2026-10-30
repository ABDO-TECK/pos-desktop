<?php

namespace App\Services;

use App\Helpers\EnvLoader;
use App\Helpers\Logger;
use Throwable;

class GitHubReleaseProvider
{
    private string $owner;
    private string $repo;
    private ?string $token;
    private ?string $caCertPath;
    private int $timeout;
    private ?\Closure $downloadProgress = null;

    public function setDownloadProgress(?\Closure $progress): void
    {
        $this->downloadProgress = $progress;
    }
    /** @var list<string> */
    private array $allowedHosts;

    public function __construct(
        ?string $owner = null,
        ?string $repo = null,
        ?string $token = null,
        ?string $caCertPath = null,
        int $timeout = 15
    ) {
        $this->owner = $owner ?? EnvLoader::get('UPDATE_REPO_OWNER', 'ABDO-TECK');
        $this->repo = $repo ?? EnvLoader::get('UPDATE_REPO_NAME', 'pos-desktop-updates');
        if ($this->owner === 'ABDO-TECK' && in_array($this->repo, ['pos', 'pos-desktop'], true)) {
            $this->repo = 'pos-desktop-updates';
        }
        $this->token = $token ?? EnvLoader::get('GITHUB_TOKEN');
        $this->timeout = $timeout;
        $this->caCertPath = $caCertPath ?? $this->resolveCaCertPath();

        $defaultHosts = 'api.github.com,github.com,raw.githubusercontent.com,objects.githubusercontent.com,github-releases.githubusercontent.com';
        $configuredHosts = EnvLoader::get('UPDATE_ALLOWED_HOSTS', $defaultHosts);
        $this->allowedHosts = array_values(array_unique(array_filter(array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', $configuredHosts . ',' . $defaultHosts)
        ), static fn (string $h): bool => $h !== '')));
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getRepo(): string
    {
        return $this->repo;
    }

    /**
     * Fetch the latest stable release from GitHub Releases API.
     *
     * @return array{
     *   ok: bool,
     *   latest_version: ?string,
     *   tag_name: ?string,
     *   release_url: ?string,
     *   published_at: ?string,
     *   changelog: list<string>,
     *   manifest_url: ?string,
     *   signature_url: ?string,
     *   delta_url: ?string,
     *   full_package_url: ?string,
     *   assets: array<string, string>,
     *   error: ?string,
     *   error_code: ?string
     * }
     */
    /**
     * Fetch the latest release from GitHub Releases API for a specified channel.
     *
     * @param string $channel Target channel ('stable', 'beta')
     * @return array{
     *   ok: bool,
     *   latest_version: ?string,
     *   tag_name: ?string,
     *   release_url: ?string,
     *   published_at: ?string,
     *   changelog: list<string>,
     *   manifest_url: ?string,
     *   signature_url: ?string,
     *   delta_url: ?string,
     *   full_package_url: ?string,
     *   assets: array<string, string>,
     *   channel: string,
     *   no_eligible_release?: bool,
     *   error: ?string,
     *   error_code: ?string
     * }
     */
    public function getLatestRelease(string $channel = 'stable', ?string $currentVersion = null): array
    {
        return $this->getReleasePage($channel, $currentVersion, 1);
    }

    /** Raw metadata for explicit transitions; intentionally does not filter by delta baseline. */
    public function listChannelCandidates(string $channel, int $page = 1): array
    {
        if (!in_array($channel, UpdateChannelPolicy::active(), true)) return ['ok'=>false,'releases'=>[],'next_page'=>null,'error'=>'Inactive channel.'];
        $result = $this->transitionMetadata('pos-desktop-updates', null, $page);
        if (!$result['ok']) return $result;
        $result['releases'] = array_values(array_filter($result['releases'], static function(array $release) use ($channel): bool {
            $actual = UpdateChannelPolicy::installedChannel(ltrim($release['tag_name'], 'vV'));
            return !$release['draft'] && $actual === $channel && $release['prerelease'] === ($actual === 'beta');
        }));
        return $result;
    }

    /** Fixed Setup authority; never passes through the ordinary repository redirect. */
    public function getLatestSetupRelease(?string $currentVersion = null): array
    {
        $candidates = [];
        for ($page = 1; $page <= 10; $page++) {
            $result = $this->transitionMetadata('pos-desktop', null, $page);
            if (!$result['ok']) return $result;
            foreach ($result['releases'] as $release) {
                $version = ltrim($release['tag_name'], 'vV');
                if ($release['draft'] || $release['prerelease'] || UpdateChannelPolicy::installedChannel($version) !== 'stable') continue;
                if ($currentVersion !== null && !ReleaseVersionPolicy::assess($currentVersion, $version)['compatible']) continue;
                $candidates[] = $release;
            }
            if ($result['next_page'] === null) {
                usort($candidates, static fn(array $a, array $b): int => version_compare(ltrim($b['tag_name'],'vV'), ltrim($a['tag_name'],'vV')));
                return ['ok'=>true,'release'=>$candidates[0]??null,'error'=>null];
            }
        }
        return ['ok'=>false,'release'=>null,'error'=>'Setup release pagination limit exceeded.'];
    }

    public function getReleaseByTag(string $tag, string $repo = 'pos-desktop-updates'): array
    {
        if (!ReleaseVersionPolicy::isValid(ltrim($tag, 'vV'))) return ['ok'=>false,'release'=>null,'error'=>'Invalid release tag.'];
        $result = $this->transitionMetadata($repo, $tag);
        if (!$result['ok']) return $result;
        $release = $result['releases'][0] ?? null;
        if (!$release || $release['tag_name'] !== $tag || $release['draft']) return ['ok'=>false,'release'=>null,'error'=>'Release is unavailable.'];
        return ['ok'=>true,'release'=>$release,'error'=>null];
    }

    private function transitionMetadata(string $repo, ?string $tag = null, int $page = 1): array
    {
        if (!in_array($repo, ['pos-desktop','pos-desktop-updates'], true) || $page < 1 || $page > 10) throw new \InvalidArgumentException('Invalid release authority.');
        $url = 'https://api.github.com/repos/ABDO-TECK/'.$repo.'/releases'.($tag === null ? '?per_page=100&page='.$page : '/tags/'.rawurlencode($tag));
        if (!$this->isAllowedUrl($url)) return ['ok'=>false,'error'=>'Release authority is not allowed.'];
        $headers = ['User-Agent: ABDO-TECK-POS-Updater/1.0','Accept: application/vnd.github+json','Cache-Control: no-cache'];
        if ($this->token) $headers[] = 'Authorization: Bearer '.$this->token;
        $response = $this->executeCurlGet($url, $headers);
        if (!$response['ok']) return ['ok'=>false,'error'=>'Release metadata request failed.','http_code'=>$response['http_code']??0];
        $decoded = json_decode((string)$response['body'], true);
        $releases = $tag === null ? $decoded : [$decoded];
        if (!is_array($releases) || !array_is_list($releases)) return ['ok'=>false,'error'=>'Invalid release list.'];
        foreach ($releases as $release) {
            if (!is_array($release) || !is_string($release['tag_name']??null) || !ReleaseVersionPolicy::isValid(ltrim($release['tag_name'],'vV')) || !is_bool($release['prerelease']??null) || !is_bool($release['draft']??null) || !is_array($release['assets']??null)) return ['ok'=>false,'error'=>'Malformed release metadata.'];
        }
        return ['ok'=>true,'releases'=>$releases,'next_page'=>$tag===null&&count($releases)===100?$page+1:null,'error'=>null];
    }

    private function getReleasePage(string $channel, ?string $currentVersion, int $page): array
    {
        $channel = strtolower(trim($channel ?: 'stable'));
        if (!in_array($channel, UpdateChannelPolicy::active(), true)) {
            return $this->failureResult('invalid_channel', 'Only stable and beta channels are active.');
        }

        // Use the same semantic policy as every other discovery/apply path.
        $isIncompatibleRelease = static function (string $tag) use ($currentVersion): bool {
            $cleanTag = ltrim(trim($tag), 'vV');
            if (!ReleaseVersionPolicy::isValid($cleanTag)) {
                return true;
            }

            if ($currentVersion === null) {
                return false;
            }

            return !ReleaseVersionPolicy::assess($currentVersion, $cleanTag)['compatible'];
        };

        // Enumerate releases for every channel: GitHub's latest Setup may be
        // intentionally older than newer delta-only releases.
        // Fetch recent releases list to find the newest compatible release in the active generation
        $listUrl = "https://api.github.com/repos/{$this->owner}/{$this->repo}/releases?per_page=100&page={$page}";
        if (!$this->isAllowedUrl($listUrl)) {
            $err = "Configured GitHub repository URL '{$listUrl}' is not in the allowed update hosts.";
            Logger::error($err);
            return $this->failureResult('invalid_update_url', $err);
        }

        $headers = [
            'User-Agent: ABDO-TECK-POS-Updater/1.0',
            'Accept: application/vnd.github.v3+json, application/json',
            'Cache-Control: no-cache',
        ];
        if ($this->token && trim($this->token) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim($this->token);
        }

        $response = $this->executeCurlGet($listUrl, $headers);
        if (!$response['ok']) {
            $errorCode = $this->classifyError(
                $response['http_code'],
                $response['curl_error'],
                $response['curl_errno'],
                $response['response_headers'] ?? [],
                $response['body'] ?? false,
            );
            $msg = $response['http_code'] > 0
                ? "GitHub API returned HTTP {$response['http_code']}: {$response['curl_error']}"
                : "Failed to connect to GitHub Releases API: {$response['curl_error']}";

            Logger::warning('GitHubReleaseProvider fetch releases list failed', [
                'repo' => "{$this->owner}/{$this->repo}",
                'http_code' => $response['http_code'],
                'error_code' => $errorCode,
            ]);

            return $this->failureResult($errorCode, $msg, $this->responseDiagnostics($response));
        }

        $body = (string) $response['body'];
        $decodedRoot = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedRoot)) {
            return $this->failureResult('invalid_release_list', 'GitHub returned an invalid release list.');
        }

        $releases = json_decode($body, true);
        if (!is_array($releases)) {
            return $this->failureResult('invalid_release_list', 'GitHub returned an invalid release list.');
        }

        foreach ($releases as $release) {
            if (
                !is_array($release)
                || !is_string($release['tag_name'] ?? null)
                || trim($release['tag_name']) === ''
                || !is_bool($release['prerelease'] ?? null)
                || !ReleaseVersionPolicy::isValid(ltrim(trim($release['tag_name']), 'vV'))
                || (isset($release['assets']) && !is_array($release['assets']))
            ) {
                return $this->failureResult('invalid_release_list', 'GitHub returned a malformed release record.');
            }
        }

        // Find all compatible releases matching channel and active generation
        $compatibleReleases = [];
        foreach ($releases as $rel) {
            if (!is_array($rel) || empty($rel['tag_name'])) {
                continue;
            }

            $tag = (string) $rel['tag_name'];
            if ($isIncompatibleRelease($tag)) {
                continue;
            }

            $relChannel = UpdateChannelPolicy::installedChannel(ltrim($tag, 'vV'));
            if ($relChannel === 'retired') continue;
            if ((bool)$rel['prerelease'] !== ($relChannel === 'beta')) continue;
            if ($relChannel !== $channel) continue;

            if (!empty($rel['draft'])) continue;
            $mapped = $this->mapReleaseData($rel, $currentVersion);
            if (!empty($mapped['unsupported_baseline'])) continue;
            $mapped['channel'] = $relChannel;
            $compatibleReleases[] = $mapped;
        }

        if (empty($compatibleReleases)) {
            if (count($releases) === 100) {
                if ($page >= 10) return $this->failureResult('release_catalog_limit', 'Release search exceeded the bounded page limit.');
                return $this->getReleasePage($channel, $currentVersion, $page + 1);
            }
            return $this->noEligibleReleaseResult($channel);
        }

        // Sort compatible releases by SemVer descending to pick the newest release
        usort($compatibleReleases, static function (array $a, array $b): int {
            $verA = $a['latest_version'] ?? '0.0.0';
            $verB = $b['latest_version'] ?? '0.0.0';
            return version_compare($verB, $verA);
        });

        return $compatibleReleases[0];
    }

    /**
     * A successful enumeration with no channel- and generation-compatible
     * release is distinct from a failed request or malformed response.
     *
     * @return array<string, mixed>
     */
    private function noEligibleReleaseResult(string $channel): array
    {
        return [
            'ok' => true,
            'latest_version' => null,
            'tag_name' => null,
            'release_url' => null,
            'published_at' => null,
            'changelog' => [],
            'manifest_url' => null,
            'signature_url' => null,
            'delta_url' => null,
            'full_package_url' => null,
            'assets' => [],
            'channel' => $channel,
            'no_eligible_release' => true,
            'error' => null,
            'error_code' => null,
        ];
    }

    /**
     * Fetch and parse a single release endpoint.
     */
    private function fetchSingleReleaseUrl(string $apiUrl, ?string $currentVersion = null): array
    {
        if (!$this->isAllowedUrl($apiUrl)) {
            return $this->failureResult('invalid_update_url', 'Invalid update URL.');
        }

        $headers = [
            'User-Agent: ABDO-TECK-POS-Updater/1.0',
            'Accept: application/vnd.github.v3+json, application/json',
            'Cache-Control: no-cache',
        ];
        if ($this->token && trim($this->token) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim($this->token);
        }

        $response = $this->executeCurlGet($apiUrl, $headers);
        if (!$response['ok']) {
            $errorCode = $this->classifyError(
                $response['http_code'],
                $response['curl_error'],
                $response['curl_errno'],
                $response['response_headers'] ?? [],
                $response['body'] ?? false,
            );
            $message = $response['http_code'] > 0
                ? "GitHub API returned HTTP {$response['http_code']}."
                : $response['curl_error'];
            return $this->failureResult($errorCode, $message, $this->responseDiagnostics($response));
        }

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data) || empty($data['tag_name'])) {
            return $this->failureResult('invalid_release_json', 'Invalid release payload.');
        }

        return $this->mapReleaseData($data, $currentVersion);
    }

    /**
     * Map raw GitHub API release JSON to structured provider array.
     */
    public function mapReleaseData(array $data, ?string $currentVersion = null): array
    {
        $tagName = (string) ($data['tag_name'] ?? '');
        $cleanVersion = preg_replace('/^v/i', '', trim($tagName));
        $releaseUrl = (string) ($data['html_url'] ?? '');
        $publishedAt = (string) ($data['published_at'] ?? '');

        // Extract changelog lines from body
        $changelog = [];
        if (!empty($data['body']) && is_string($data['body'])) {
            $lines = preg_split('/\r\n|\r|\n/', trim($data['body'])) ?: [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $changelog[] = preg_replace('/^[\*\-]\s+/', '', $trimmed);
                }
            }
        }

        // Map release assets
        $manifestUrl = null;
        $signatureUrl = null;
        $deltaUrl = null;
        $fullPackageUrl = null;
        $assetsMap = [];

        foreach ($data['assets'] ?? [] as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $downloadUrl = (string) ($asset['browser_download_url'] ?? '');
            if ($name === '' || $downloadUrl === '') {
                continue;
            }

            $assetsMap[$name] = $downloadUrl;
            $lowerName = strtolower($name);

            if ($lowerName === 'manifest.json') {
                $manifestUrl = $downloadUrl;
            } elseif ($lowerName === 'manifest.sig' || $lowerName === 'signature.sig' || str_ends_with($lowerName, '.sig')) {
                $signatureUrl = $downloadUrl;
            } elseif (
                str_starts_with($lowerName, 'delta-') && str_ends_with($lowerName, '.zip')
                || $lowerName === 'delta.zip'
                || str_starts_with($lowerName, 'patch-') && str_ends_with($lowerName, '.zip')
            ) {
                $deltaUrl = $downloadUrl;
            } elseif (str_ends_with($lowerName, '.zip') || str_ends_with($lowerName, '.exe') || str_ends_with($lowerName, '.phar') || $lowerName === 'backend.phar') {
                $fullPackageUrl = $downloadUrl;
            }
        }

        // Each baseline has its own signed manifest and ZIP. Never apply a
        // package built for another installed version or mix asset signatures.
        $versionedManifests = array_filter(array_keys($assetsMap), static fn (string $name): bool => preg_match('/^manifest-.*\.json$/', $name) === 1);
        $unsupportedBaseline = false;
        if ($versionedManifests) {
            $manifestUrl = $assetsMap["manifest-{$currentVersion}.json"] ?? null;
            $signatureUrl = $assetsMap["manifest-{$currentVersion}.sig"] ?? null;
            $deltaUrl = $assetsMap["delta-{$currentVersion}-to-{$cleanVersion}.zip"] ?? null;
            $unsupportedBaseline = !$manifestUrl || !$signatureUrl || !$deltaUrl;
        }

        return [
            'ok' => true,
            'unsupported_baseline' => $unsupportedBaseline,
            'latest_version' => $cleanVersion,
            'tag_name' => $tagName,
            'release_url' => $releaseUrl,
            'published_at' => $publishedAt,
            'changelog' => $changelog,
            'manifest_url' => $manifestUrl,
            'signature_url' => $signatureUrl,
            'delta_url' => $deltaUrl,
            'full_package_url' => $fullPackageUrl,
            'assets' => $assetsMap,
            'channel' => 'stable',
            'error' => null,
            'error_code' => null,
        ];
    }


    /**
     * Download text asset into string (e.g. manifest.json, manifest.sig).
     *
     * @param string $url Asset download URL
     * @return array{ok: bool, content: ?string, error: ?string}
     */
    public function fetchAssetContent(string $url): array
    {
        if (!$this->isAllowedUrl($url)) {
            $err = "Asset URL '{$url}' is not in the allowed update hosts.";
            Logger::warning($err);
            return ['ok' => false, 'content' => null, 'error' => $err];
        }

        $headers = [
            'User-Agent: ABDO-TECK-POS-Updater/1.0',
            'Accept: application/octet-stream, application/json, text/plain, */*',
        ];

        if ($this->token && trim($this->token) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim($this->token);
        }

        $res = $this->executeCurlGet($url, $headers, true);
        if (!$res['ok']) {
            return ['ok' => false, 'content' => null, 'error' => $res['curl_error'] ?: "HTTP {$res['http_code']}"];
        }

        return ['ok' => true, 'content' => (string) $res['body'], 'error' => null];
    }

    /**
     * Stream download asset directly to file path.
     *
     * @param string $url Asset URL
     * @param string $destPath Destination file path
     * @return array{ok: bool, error: ?string}
     */
    public function downloadAssetFile(string $url, string $destPath): array
    {
        if (!$this->isAllowedUrl($url)) {
            $err = "Download URL '{$url}' is not in the allowed update hosts.";
            Logger::warning($err);
            return ['ok' => false, 'error' => $err];
        }

        $destDir = dirname($destPath);
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
            return ['ok' => false, 'error' => "Cannot create target directory: {$destDir}"];
        }

        return (new UpdateAssetDownloader($this->caCertPath, $this->token ?? ''))
            ->download($url, $destPath, $this->downloadProgress);
    }

    /**
     * Execute standard cURL GET request.
     */
    protected function executeCurlGet(string $url, array $headers = [], bool $followRedirects = false): array
    {
        if ($this->caCertPath === null || !is_file($this->caCertPath) || str_starts_with($this->caCertPath, 'phar://')) {
            return [
                'ok' => false,
                'body' => false,
                'http_code' => 0,
                'curl_error' => 'CA certificate bundle not found for TLS verification',
                'curl_errno' => defined('CURLE_SSL_CACERT') ? (int) constant('CURLE_SSL_CACERT') : 60,
                'response_headers' => [],
            ];
        }

        $ch = curl_init($url);
        $responseHeaders = [];
        $headerCallback = static function ($handle, string $headerLine) use (&$responseHeaders): int {
            $separator = strpos($headerLine, ':');
            if ($separator === false) {
                return strlen($headerLine);
            }

            $name = strtolower(trim(substr($headerLine, 0, $separator)));
            $allowed = [
                'retry-after',
                'x-ratelimit-limit',
                'x-ratelimit-remaining',
                'x-ratelimit-reset',
                'x-ratelimit-used',
                'x-ratelimit-resource',
            ];
            if (in_array($name, $allowed, true)) {
                $responseHeaders[$name] = trim(substr($headerLine, $separator + 1));
            }

            return strlen($headerLine);
        };
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => $this->caCertPath,
            CURLOPT_USERAGENT      => 'ABDO-TECK-POS-Updater/1.0',
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS      => $followRedirects ? 5 : 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => $headerCallback,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = (string) curl_error($ch);
        $curlErrNo = (int) curl_errno($ch);
        curl_close($ch);

        $ok = ($httpCode >= 200 && $httpCode < 300) && ($body !== false);

        return [
            'ok' => $ok,
            'body' => $body,
            'http_code' => $httpCode,
            'curl_error' => $curlErr,
            'curl_errno' => $curlErrNo,
            'response_headers' => $responseHeaders,
        ];
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

        foreach ($this->allowedHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function classifyError(
        int $httpCode,
        string $curlErr,
        int $curlErrNo,
        array $responseHeaders = [],
        string|false $body = false,
    ): string
    {
        $lower = strtolower($curlErr);
        $message = '';
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && is_string($decoded['message'] ?? null)) {
                $message = strtolower($decoded['message']);
            }
        }

        $retryAfter = trim((string) ($responseHeaders['retry-after'] ?? ''));
        $remaining = trim((string) ($responseHeaders['x-ratelimit-remaining'] ?? ''));
        if (
            $httpCode === 429
            || str_contains($message, 'secondary rate limit')
            || $retryAfter !== ''
        ) {
            return 'github_secondary_rate_limited';
        }
        if (
            ($httpCode === 403 && $remaining !== '' && is_numeric($remaining) && (int) $remaining <= 0)
            || str_contains($message, 'api rate limit exceeded')
            || str_contains($message, 'rate limit exceeded for')
        ) {
            return 'github_primary_rate_limited';
        }
        if ($httpCode === 404) {
            return 'github_release_not_found';
        }
        if ($httpCode === 403) {
            return 'github_http_403_forbidden';
        }
        if ($curlErrNo === 28 || str_contains($lower, 'timed out')) {
            return 'github_network_timeout';
        }
        if ($curlErrNo !== 0 && (str_contains($lower, 'ssl') || str_contains($lower, 'certificate') || str_contains($lower, 'ca cert'))) {
            return 'github_ssl_error';
        }

        return 'github_connection_failed';
    }

    private function failureResult(string $code, string $message, array $diagnostics = []): array
    {
        return [
            'ok' => false,
            'latest_version' => null,
            'tag_name' => null,
            'release_url' => null,
            'published_at' => null,
            'changelog' => [],
            'manifest_url' => null,
            'signature_url' => null,
            'delta_url' => null,
            'full_package_url' => null,
            'assets' => [],
            'error' => $message,
            'error_code' => $code,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Return only non-secret response metadata for diagnostics and backoff.
     * Response bodies and request headers are intentionally excluded.
     */
    private function responseDiagnostics(array $response): array
    {
        $headers = is_array($response['response_headers'] ?? null) ? $response['response_headers'] : [];
        $diagnostics = [
            'http_code' => (int) ($response['http_code'] ?? 0),
        ];

        foreach ([
            'retry-after' => 'retry_after',
            'x-ratelimit-limit' => 'rate_limit_limit',
            'x-ratelimit-remaining' => 'rate_limit_remaining',
            'x-ratelimit-reset' => 'rate_limit_reset',
            'x-ratelimit-used' => 'rate_limit_used',
            'x-ratelimit-resource' => 'rate_limit_resource',
        ] as $source => $target) {
            if (isset($headers[$source]) && is_scalar($headers[$source])) {
                $diagnostics[$target] = (string) $headers[$source];
            }
        }

        return $diagnostics;
    }

    private function resolveCaCertPath(): ?string
    {
        return UpdateRuntimePaths::getCaBundlePath();
    }
}
