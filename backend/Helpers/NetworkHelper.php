<?php

namespace App\Helpers;

/**
 * NetworkHelper — دوال مساعدة للتحقق من الشبكة.
 *
 * Centralizes LAN origin matching to avoid duplicated regex patterns
 * across CorsMiddleware, sign-message.php, and other files.
 */
class NetworkHelper
{
    /**
     * Check if an HTTP Origin header value comes from a LAN/private IP.
     *
     * @param string $origin The value of the HTTP Origin header
     * @return bool true if the origin is from a private/LAN IP address
     */
    public static function isLanOrigin(string $origin): bool
    {
        $parts = parse_url($origin);
        if (
            $parts === false
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['path'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $host = strtolower($parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $ip = ip2long($host);
        if ($ip === false) {
            return false;
        }
        $ip = (int) sprintf('%u', $ip);

        return (($ip & 0xff000000) === 0x0a000000)
            || (($ip & 0xfff00000) === 0xac100000)
            || (($ip & 0xffff0000) === 0xc0a80000);
    }
}
