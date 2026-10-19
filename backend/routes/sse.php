<?php
/** @var \App\Core\Router $router */

use App\Controllers\SseController;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;

// SSE — لا يحتاج CSRF لكنه يحتاج Auth
$router->get('/api/sse/inventory', [
    SseController::class,
    'inventory',
    [
        AuthMiddleware::class,
        PermissionMiddleware::require('inventory.view'),
        \App\Middleware\EndpointRateLimiter::limit('inventory_sse', 10, 60),
    ],
]);
