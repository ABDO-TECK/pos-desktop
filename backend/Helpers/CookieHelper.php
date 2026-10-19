<?php

namespace App\Helpers;

/**
 * CookieHelper — دالة مركزية لضبط خيارات الـ Cookies الأمنية.
 *
 * تضمن أن جميع Cookies تستخدم نفس إعدادات الأمان:
 * - Secure flag: يُفرض تلقائياً إذا كان الاتصال HTTPS (حتى عبر Proxy)
 * - SameSite: Strict في الإنتاج، Lax في التطوير
 * - HttpOnly: دائماً true (إلا إذا تم تجاوزه صراحة)
 */
class CookieHelper
{
    /**
     * فحص هل الاتصال الحالي آمن (HTTPS).
     * يدعم: اتصال مباشر + عبر Proxy/Load Balancer + منفذ 443.
     */
    public static function isSecureConnection(): bool
    {
        // 1. فحص مباشر (Apache/IIS)
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        // 2. فحص عبر Proxy/Load Balancer — only trust from configured proxies
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
            $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : ['127.0.0.1', '::1'];
            if (in_array($remoteAddr, $trustedProxies, true)) {
                return true;
            }
        }
        // 3. فحص المنفذ
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        return false;
    }

    /**
     * بناء مصفوفة خيارات Cookie آمنة.
     *
     * @param int    $expires  وقت انتهاء الصلاحية (Unix timestamp)
     * @param string $path     المسار (افتراضي: '/')
     * @param bool   $httpOnly هل تُمنع JavaScript من الوصول (افتراضي: true)
     * @return array مصفوفة خيارات جاهزة لاستخدامها مع setcookie()
     */
    public static function options(int $expires, string $path = '/', bool $httpOnly = true): array
    {
        $forceSecure = EnvLoader::getBool('SECURE_COOKIES', false);
        $isHttps     = self::isSecureConnection();
        $isProduction = defined('APP_ENV') && APP_ENV === 'production';
        $isDesktop = defined('DEPLOYMENT_MODE') && DEPLOYMENT_MODE === 'desktop';

        // في الإنتاج: إذا كان الاتصال HTTPS → فرض Secure حتى لو لم يُضبط SECURE_COOKIES
        $secure = $forceSecure || $isHttps;

        // Desktop mode uses app:// scheme → http://127.0.0.1 (cross-origin).
        // SameSite=Strict would block cookies in these cross-origin requests.
        // Lax is appropriate for desktop since the server is loopback-only.
        if ($isDesktop) {
            $sameSite = 'Lax';
        } else {
            $sameSite = ($forceSecure || $isProduction) ? 'Strict' : 'Lax';
        }

        return [
            'expires'  => $expires,
            'path'     => $path,
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ];
    }

    /**
     * Expire both authentication cookies after a security-sensitive session reset.
     */
    public static function clearAuthCookies(): void
    {
        setcookie('pos_token', '', self::options(time() - 3600));
        setcookie(
            'pos_refresh_token',
            '',
            self::options(time() - 3600, '/api/v1/refresh')
        );
    }
}
