<?php

namespace App\Middleware;

use App\Helpers\Logger;

/**
 * TimingMiddleware — قياس وتسجيل أداء الطلبات.
 *
 * - يُضيف X-Response-Time و Server-Timing headers لكل response.
 * - يُسجّل تحذيراً في اللوج لأي طلب يتجاوز الحد المسموح.
 * - يُسجّل كل طلب بمستوى DEBUG مع تفاصيل كاملة.
 */
class TimingMiddleware
{
    /** الحد الأقصى المسموح (بالميلي ثانية) قبل التسجيل كـ warning */
    private const SLOW_THRESHOLD_MS = 500;

    public function handle(callable $next): mixed
    {
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
        Logger::setRequestId($requestId);

        $start = hrtime(true);

        $response = $next();

        $elapsed = (hrtime(true) - $start) / 1e6; // ميلي ثانية

        if (is_array($response)) {
            $response['headers'] = $response['headers'] ?? [];
            $response['headers']['X-Response-Time'] = round($elapsed, 2) . 'ms';
            $response['headers']['Server-Timing']   = 'total;dur=' . round($elapsed, 2);
            $response['headers']['X-Request-Id']    = $requestId;
        }

        $method     = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $uri        = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $statusCode = $response['status_code'] ?? 200;
        $bodySize   = isset($response['body']) ? strlen(json_encode($response['body'])) : 0;
        $userId     = $_SERVER['HTTP_X_USER_ID'] ?? ($_COOKIE['pos_user_id'] ?? null);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $logContext = [
            'request_id'  => $requestId,
            'method'      => $method,
            'uri'         => $uri,
            'status'      => $statusCode,
            'elapsed_ms'  => round($elapsed, 2),
            'body_bytes'  => $bodySize,
            'ip'          => $ip,
        ];
        if ($userId) {
            $logContext['user_id'] = $userId;
        }

        // تسجيل كل الطلبات بمستوى DEBUG
        Logger::debug("API {$method} {$uri} → {$statusCode} ({$logContext['elapsed_ms']}ms)", $logContext);

        // تسجيل الطلبات البطيئة بمستوى WARNING
        if ($elapsed > self::SLOW_THRESHOLD_MS) {
            $logContext['threshold'] = self::SLOW_THRESHOLD_MS;
            Logger::warning('Slow request detected', $logContext);
        }

        // تسجيل أخطاء الخادم بمستوى ERROR
        if ($statusCode >= 500) {
            Logger::error("Server error on {$method} {$uri}", $logContext);
        }

        return $response;
    }
}
