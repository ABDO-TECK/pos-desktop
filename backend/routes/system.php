<?php
use App\Controllers\HealthController;
use App\Controllers\ClientLogController;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;

$router->get('/api/health', [HealthController::class, 'check']);
$router->get('/api/health/diagnostics', [HealthController::class, 'diagnostics', [AuthMiddleware::class, PermissionMiddleware::require('settings.update')]]);
$router->get('/api/health/metrics', [HealthController::class, 'metrics', [AuthMiddleware::class, PermissionMiddleware::require('settings.update')]]);
$router->post('/api/client-log', [ClientLogController::class, 'store', [
    AuthMiddleware::class,
    \App\Middleware\EndpointRateLimiter::limit('client_log', 30, 60),
]]);
$router->get('/api/system/network-info', [HealthController::class, 'networkInfo', [AuthMiddleware::class, PermissionMiddleware::require('settings.view')]]);
