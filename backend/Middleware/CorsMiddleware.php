<?php

namespace App\Middleware;

use App\Helpers\EnvLoader;
use App\Helpers\NetworkHelper;

/**
 * CorsMiddleware — يعالج طلبات Cross-Origin Resource Sharing.
 * يسمح بالأصول المعروفة + أي أصل من الشبكة المحلية (LAN).
 */
class CorsMiddleware
{
    public function handle(callable $next): mixed
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $appEnv = EnvLoader::get('APP_ENV', 'development');

        // Log CORS origins only in development or debug mode
        if (class_exists('App\Helpers\Logger') && $origin !== '' && $appEnv === 'development') {
            \App\Helpers\Logger::debug("CORS Origin: '$origin', Method: '" . ($_SERVER['REQUEST_METHOD'] ?? '') . "'");
        }

        if ($appEnv === 'development') {
            $allowedOrigins = [
                'http://localhost:5173',
                'http://localhost:3000',
                'http://127.0.0.1:5173',
                'https://localhost:5173',
                'https://127.0.0.1:5173',
                'file://',
                'app://.',
                'app://pos-app',
                FRONTEND_URL,
            ];

            $originAllowed = $origin !== '' && in_array($origin, $allowedOrigins, true);

            // السماح بأصل من IP الشبكة المحلية (LAN) في وضع التطوير
            if (!$originAllowed && $origin !== '') {
                $originAllowed = NetworkHelper::isLanOrigin($origin);
            }

            // السماح بطلبات بدون Origin
            if ($origin === '' && php_sapi_name() === 'cli-server') {
                $originAllowed = true;
            }
        } else {
            // Production - allow only the custom protocol origin + extra configured origins
            $allowedOrigins = [
                'app://pos-app',
            ];

            // Comma-separated extra allowed origins
            $extraOrigins = EnvLoader::get('CORS_ALLOWED_ORIGINS', '');
            if ($extraOrigins !== '') {
                $extras = array_map('trim', explode(',', $extraOrigins));
                $allowedOrigins = array_merge($allowedOrigins, $extras);
            }

            $originAllowed = $origin !== '' && in_array($origin, $allowedOrigins, true);

            // Also allow local network (LAN) access in production if explicitly configured
            if (!$originAllowed && $origin !== '' && EnvLoader::getBool('CORS_ALLOW_LAN', false)) {
                $originAllowed = NetworkHelper::isLanOrigin($origin);
            }
        }

        if ($originAllowed && $origin !== '') {
            header("Access-Control-Allow-Origin: $origin");
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-XSRF-TOKEN, X-POS-Delta-Handoff');
        header('Access-Control-Allow-Credentials: true');

        // Preflight
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        return $next();
    }
}
