<?php
declare(strict_types=1);

namespace App\Services;

use Closure;

/** Shared streaming transport. Callers validate the URL and signed payload. */
class UpdateAssetDownloader
{
    public function __construct(
        private ?string $caBundle,
        private string $token = '',
        private int $budgetSeconds = 900,
        private int $maxAttempts = 3,
        private int $retryDelayMilliseconds = 1000
    ) {}

    protected int $currentAttempt = 1;

    public function download(string $url, string $destination, ?Closure $progress = null): array
    {
        if (!$this->caBundle || !is_file($this->caBundle) || str_starts_with($this->caBundle, 'phar://')) {
            return ['ok'=>false, 'error'=>'Asset download failed: CA certificate bundle not found for TLS verification'];
        }
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return ['ok'=>false, 'error'=>'Asset download requires HTTPS'];
        }
        $paramCount = $progress ? (new \ReflectionFunction($progress))->getNumberOfParameters() : 0;
        $deadline = microtime(true) + max(1, $this->budgetSeconds);
        $error = 'Asset download time budget exhausted';
        for ($attempt = 1; $attempt <= max(1, $this->maxAttempts); $attempt++) {
            $this->currentAttempt = $attempt;
            $remaining = (int)floor($deadline - microtime(true));
            if ($remaining < 1) break;
            $file = @fopen($destination, 'w+b');
            if ($file === false) return ['ok'=>false, 'error'=>'Cannot open destination file for writing'];
            try {
                $result = $this->transfer($url, $file, $remaining, $progress);
                $flushed = fflush($file);
            } catch (\Throwable $error) {
                fclose($file);
                @unlink($destination);
                return ['ok'=>false, 'error'=>'Asset download stopped: '.$error->getMessage()];
            } finally {
                if (is_resource($file)) fclose($file);
            }
            if ($result['success'] !== false && $result['http_code'] >= 200 && $result['http_code'] < 300 && $flushed) {
                return ['ok'=>true, 'error'=>null];
            }
            @unlink($destination);
            $reason = $result['errno'] !== 0
                ? 'cURL ' . $result['errno'] . ': ' . $result['error']
                : ($flushed ? 'HTTP ' . $result['http_code'] : 'Destination write/flush failed');
            $error = sprintf('Asset download failed: %s (HTTP %d; %d bytes; %.1fs; attempt %d/%d)',
                $reason, $result['http_code'], $result['bytes'], $result['seconds'], $attempt, $this->maxAttempts);
            $retryable = in_array($result['errno'], [5,6,7,18,28,35,52,55,56,92], true)
                || ($result['errno'] === 0 && in_array($result['http_code'], [408,429,500,502,503,504], true));
            if (!$retryable || $attempt === $this->maxAttempts) break;
            if ($progress) {
                // Reset progress counters on retry so UI does not retain stale 100%
                if ($paramCount >= 3) {
                    $progress(0, 0, $attempt + 1);
                } elseif ($paramCount === 2) {
                    $progress(0, 0);
                } elseif ($paramCount === 1) {
                    $progress(0);
                } else {
                    $progress();
                }
            }
            usleep(max(0, min($this->retryDelayMilliseconds, (int)max(0, ($deadline - microtime(true))*1000))) * 1000);
        }
        return ['ok'=>false, 'error'=>$error];
    }

    protected function transfer(string $url, $file, int $remainingSeconds, ?Closure $progress): array
    {
        $handle = curl_init($url);
        $lastHeartbeat = 0.0;
        $terminalReported = false;
        $paramCount = $progress ? (new \ReflectionFunction($progress))->getNumberOfParameters() : 0;
        $attempt = $this->currentAttempt;

        $invokeProgress = static function (int $dlnow, int $dltotal) use ($progress, $attempt, $paramCount): void {
            if (!$progress) return;
            if ($paramCount >= 3) {
                $progress($dlnow, $dltotal, $attempt);
            } elseif ($paramCount === 2) {
                $progress($dlnow, $dltotal);
            } elseif ($paramCount === 1) {
                $progress($dlnow);
            } else {
                $progress();
            }
        };

        curl_setopt_array($handle, [
            CURLOPT_FILE=>$file,
            CURLOPT_TIMEOUT=>$remainingSeconds,
            CURLOPT_CONNECTTIMEOUT=>min(20, $remainingSeconds),
            CURLOPT_LOW_SPEED_LIMIT=>1024,
            CURLOPT_LOW_SPEED_TIME=>60,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CAINFO=>$this->caBundle,
            CURLOPT_USERAGENT=>'ABDO-TECK-POS-Updater/1.1',
            CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_MAXREDIRS=>5,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_NOPROGRESS=>false,
            CURLOPT_XFERINFOFUNCTION=>static function ($ch, int $dltotal, int $dlnow, int $ultotal, int $ulnow) use ($invokeProgress, &$lastHeartbeat, &$terminalReported): int {
                $now = microtime(true);
                $isTerminal = ($dltotal > 0 && $dlnow >= $dltotal);
                if ($isTerminal) {
                    if (!$terminalReported) {
                        $terminalReported = true;
                        $lastHeartbeat = $now;
                        $invokeProgress($dlnow, $dltotal);
                    }
                } elseif ($lastHeartbeat <= 0.0 || ($now - $lastHeartbeat >= 1.0)) {
                    $lastHeartbeat = $now;
                    $invokeProgress($dlnow, $dltotal);
                }
                return 0;
            },
        ]);
        // libcurl does not forward Authorization to a different redirect host.
        if (trim($this->token) !== '') curl_setopt($handle, CURLOPT_HTTPHEADER, ['Authorization: Bearer '.trim($this->token)]);
        try {
            $success = curl_exec($handle);
            return ['success'=>$success, 'http_code'=>(int)curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'errno'=>curl_errno($handle), 'error'=>curl_error($handle),
                'bytes'=>(int)curl_getinfo($handle, CURLINFO_SIZE_DOWNLOAD),
                'seconds'=>(float)curl_getinfo($handle, CURLINFO_TOTAL_TIME)];
        } finally {
            curl_close($handle);
        }
    }
}
