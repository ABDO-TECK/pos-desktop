<?php

namespace App\Middleware;

use App\Helpers\Messages;
use App\Middleware\Traits\ClientIpTrait;

/**
 * Shared fixed-window rate limiter with APCu and SQLite storage.
 */
class RateLimiter
{
    use ClientIpTrait;

    private int $maxRequests;
    private int $windowSeconds;

    public function __construct(int $maxRequests = 200, int $windowSeconds = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Apply a per-user bucket when authenticated, otherwise a per-IP bucket.
     */
    public function check(string $prefix = 'rate_limit', ?int $userId = null): void
    {
        $now = time();
        $windowKey = $this->buildStorageKey($prefix, $userId, $now);
        $count = RateLimitStore::incrementApcu(
            $windowKey,
            $this->windowSeconds + 10
        );

        $expiresAt = $now + $this->windowSeconds + 10;
        if ($count === null) {
            $count = RateLimitStore::increment($windowKey, $expiresAt);
        }

        if ($count === null) {
            if (!$this->isAvailabilityFirst($prefix)) {
                RateLimitStore::logStorageFailure('shared_storage');
                $this->send503();
                return;
            }

            $count = RateLimitStore::incrementEmergency(
                $windowKey,
                $expiresAt,
                $now
            );
        }

        if ($count === null) {
            RateLimitStore::logStorageFailure('emergency_capacity');
            if ($this->isAvailabilityFirst($prefix)) {
                return;
            }
            $this->send503();
        }

        if ($count > $this->maxRequests) {
            $this->send429($now);
        }
    }

    protected function resolveIdentifier(?int $userId): string
    {
        return $userId !== null
            ? "user_{$userId}"
            : 'ip_' . $this->getClientIp();
    }

    protected function buildStorageKey(
        string $prefix,
        ?int $userId,
        int $now
    ): string {
        return $prefix
            . '_'
            . md5($this->resolveIdentifier($userId))
            . '_'
            . floor($now / $this->windowSeconds);
    }

    protected function isAvailabilityFirst(string $prefix): bool
    {
        return $prefix === 'read_rl';
    }

    protected function rateLimitMessage(): string
    {
        return 'تم تجاوز الحد المسموح من الطلبات. يرجى الانتظار.';
    }

    protected function send429(int $now): void
    {
        $retryAfter = max(
            1,
            $this->windowSeconds - ($now % $this->windowSeconds)
        );
        header("Retry-After: $retryAfter");
        http_response_code(429);
        echo json_encode([
            'status' => 'error',
            'message' => $this->rateLimitMessage(),
            'retry_after' => $retryAfter,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function send503(): void
    {
        header('Retry-After: 1');
        http_response_code(503);
        echo json_encode([
            'status' => 'error',
            'message' => Messages::SERVICE_UNAVAILABLE,
            'retry_after' => 1,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Remove expired SQLite counters and legacy JSON counter files.
     */
    public function cleanup(): void
    {
        $db = RateLimitStore::getDB();
        if ($db) {
            try {
                $db->exec('DELETE FROM rate_limits WHERE expires_at < ' . time());
            } catch (\Throwable) {
                RateLimitStore::logStorageFailure('sqlite_cleanup');
            }
        }

        $files = glob(__DIR__ . '/../storage/rate_limit/*.json') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}
