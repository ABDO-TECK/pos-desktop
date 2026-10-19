<?php

namespace App\Middleware;

use App\Services\AuthService;

/**
 * DeleteRateLimiter — حد مخصص لعمليات الحذف الحساسة.
 * الحد: 30 عملية حذف / دقيقة لكل IP أو مستخدم.
 */
class DeleteRateLimiter extends RateLimiter
{
    private AuthService $auth;

    public function __construct(AuthService $auth)
    {
        parent::__construct(30, 60);
        $this->auth = $auth;
    }

    public function handle(callable $next): mixed
    {
        // فقط على طلبات DELETE
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'DELETE') {
            $this->check('delete_rl', $this->auth->id());
        }
        return $next();
    }
}
