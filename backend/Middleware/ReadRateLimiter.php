<?php

namespace App\Middleware;

use App\Services\AuthService;

/**
 * ReadRateLimiter — حد مخصص لعمليات القراءة (GET) لكل مستخدم.
 * الحد: 200 طلب قراءة / دقيقة لكل مستخدم مسجل.
 */
class ReadRateLimiter extends RateLimiter
{
    private AuthService $auth;

    public function __construct(AuthService $auth)
    {
        parent::__construct(200, 60);
        $this->auth = $auth;
    }

    public function handle(callable $next): mixed
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            $userId = $this->auth->id();
            // per-user إذا كان مسجل دخول، per-IP كـ fallback للطلبات العامة
            $this->check('read_rl', $userId);
        }
        return $next();
    }
}
