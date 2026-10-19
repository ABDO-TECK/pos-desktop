<?php
use App\Controllers\ProductController;
use App\Controllers\CategoryController;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;

// Categories
$router->get('/api/categories',      [CategoryController::class, 'index',   [AuthMiddleware::class, PermissionMiddleware::require('products.view')]]);
$router->post('/api/categories',     [CategoryController::class, 'store',   [AuthMiddleware::class, PermissionMiddleware::require('products.create')]]);
$router->put('/api/categories/{id}', [CategoryController::class, 'update',  [AuthMiddleware::class, PermissionMiddleware::require('products.update')]]);
$router->delete('/api/categories/{id}', [CategoryController::class, 'destroy', [AuthMiddleware::class, PermissionMiddleware::require('products.delete')]]);

// Products
$router->get('/api/products',        [ProductController::class, 'index',   [AuthMiddleware::class, PermissionMiddleware::require('products.view')]]);
$router->get('/api/products/sync',   [ProductController::class, 'catalogSync', [AuthMiddleware::class, PermissionMiddleware::require('products.view')]]);
$router->get('/api/products/{id}',   [ProductController::class, 'show',    [AuthMiddleware::class, PermissionMiddleware::require('products.view')]]);
$router->get('/api/products/{id}/price-history', [ProductController::class, 'priceHistory', [AuthMiddleware::class, PermissionMiddleware::require('products.view')]]);
$router->post('/api/products',       [ProductController::class, 'store',   [AuthMiddleware::class, PermissionMiddleware::require('products.create')]]);
$router->put('/api/products/{id}',   [ProductController::class, 'update',  [AuthMiddleware::class, PermissionMiddleware::require('products.update')]]);
$router->delete('/api/products/{id}',[ProductController::class, 'destroy', [AuthMiddleware::class, PermissionMiddleware::require('products.delete')]]);
