<?php

namespace App\Middleware;

use App\Helpers\Response;
use App\Services\AuthService;


class AdminMiddleware {
    private AuthService $authService;

    public function __construct(AuthService $authService) {
        $this->authService = $authService;
    }

    public function handle(callable $next): mixed {
        $user = $this->authService->user() ?? null;
        if (!$user || $user['role'] !== 'admin') {
            return Response::forbidden('Admin access required');
        }
        return $next();
    }
}
