<?php

namespace App\Middleware;

/**
 * HttpsMiddleware — يعيد التوجيه إلى HTTPS في بيئة الإنتاج.
 *
 * في وضع التطوير (FORCE_HTTPS=false) يسمح بـ HTTP.
 * في الإنتاج: إذا كان الطلب HTTP → يرد بـ 301 redirect إلى HTTPS.
 * يُضيف HSTS header عند الاتصال عبر HTTPS لمنع downgrade attacks.
 */
class HttpsMiddleware
{
    public function handle(callable $next): mixed
    {
        $forceHttps = \App\Helpers\EnvLoader::getBool('FORCE_HTTPS', false);

        // فحص هل الاتصال محلي (loopback)
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $hostName = strtolower(preg_replace('/:\d+$/', '', $host));
        $isLoopback = in_array($hostName, ['127.0.0.1', 'localhost', '[::1]'], true);

        // فحص هل الاتصال HTTPS
        $isHttps = $this->isSecureConnection();

        if ($isLoopback) {
            // إزالة HSTS المحفوظ محلياً لمنع توجيه طلبات المنافذ الأخرى تلقائياً إلى HTTPS
            header('Strict-Transport-Security: max-age=0', true);
        } elseif ($isHttps) {
            // إضافة HSTS header إذا كان الاتصال HTTPS (حتى بدون FORCE_HTTPS)
            // يخبر المتصفح بعدم قبول HTTP لمدة سنة (31536000 ثانية)
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains', true);
        }

        // تخطي إذا لم يتم تفعيل فرض HTTPS أو كان الطلب محلياً (loopback)
        if (!$forceHttps || $isLoopback) {
            return $next();
        }

        // تخطي مسار فحص الجاهزية الداخلي لتسهيل التحقق من صحة النظام
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_ends_with(strtok($requestUri, '?'), '/api/health')) {
            return $next();
        }

        if ($isHttps) {
            return $next();
        }

        // إعادة التوجيه إلى HTTPS (301 Permanent Redirect)
        $frontendUrl = defined('FRONTEND_URL') ? FRONTEND_URL : 'https://localhost';
        $host = parse_url($frontendUrl, PHP_URL_HOST);
        $port = parse_url($frontendUrl, PHP_URL_PORT);
        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('FRONTEND_URL must contain a valid host');
        }
        if (str_contains($host, ':')) {
            $host = '[' . trim($host, '[]') . ']';
        }
        if (is_int($port) && !in_array($port, [80, 443], true)) {
            $host .= ':' . $port;
        }
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $url  = 'https://' . $host . $uri;

        header('Location: ' . $url, true, 301);
        exit;
    }

    /**
     * فحص هل الاتصال الحالي عبر HTTPS.
     * يدعم: اتصال مباشر، عبر Proxy، أو عبر منفذ 443.
     */
    private function isSecureConnection(): bool
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
}
