<?php

namespace App\Middleware;

use App\Services\AuthService;

/**
 * WriteRateLimiter — حد مخصص لعمليات الكتابة (POST, PUT).
 * الحد: 60 عملية كتابة / دقيقة لكل IP أو لكل مستخدم.
 */
class WriteRateLimiter extends RateLimiter
{
    private AuthService $auth;

    public function __construct(AuthService $auth)
    {
        parent::__construct(60, 60);
        $this->auth = $auth;
    }

    public function handle(callable $next): mixed
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method === 'POST' || $method === 'PUT') {
            $this->check('write_rl', $this->auth->id());
        }
        return $next();
    }
}
