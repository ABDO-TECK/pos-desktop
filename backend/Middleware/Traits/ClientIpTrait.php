<?php

namespace App\Middleware\Traits;

/**
 * ClientIpTrait — Shared proxy-aware IP detection for rate limiters.
 * Extracts the real client IP considering trusted proxies.
 */
trait ClientIpTrait
{
    /**
     * Extract the real client IP, considering trusted proxy headers.
     */
    protected function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : ['127.0.0.1', '::1'];

        if (filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }

        if (!in_array($remoteAddr, $trustedProxies, true)) {
            return $remoteAddr;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwardedIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($forwardedIp, FILTER_VALIDATE_IP) !== false) {
                return $forwardedIp;
            }
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $realIp = trim($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
                return $realIp;
            }
        }

        return $remoteAddr;
    }
}
