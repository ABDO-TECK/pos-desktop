<?php
use App\Controllers\SaleController;
use App\Controllers\InventoryController;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;

// Sales
$router->post('/api/sales',          [SaleController::class, 'store',   [AuthMiddleware::class, PermissionMiddleware::require('invoices.create'), \App\Middleware\EndpointRateLimiter::limit('sales_create', 200, 60)]]);
$router->get('/api/sales',           [SaleController::class, 'index',   [AuthMiddleware::class, PermissionMiddleware::require('invoices.view')]]);
$router->get('/api/sales/{id}',      [SaleController::class, 'show',    [AuthMiddleware::class, PermissionMiddleware::require('invoices.view')]]);
$router->put('/api/sales/{id}/status',[SaleController::class, 'updateStatus', [AuthMiddleware::class, PermissionMiddleware::require('invoices.update_status')]]);
$router->delete('/api/sales/{id}',  [SaleController::class, 'destroy', [AuthMiddleware::class, PermissionMiddleware::require('invoices.delete')]]);

// Inventory
$router->get('/api/inventory',          [InventoryController::class, 'index',   [AuthMiddleware::class, PermissionMiddleware::require('inventory.view')]]);
$router->get('/api/inventory/low-stock',[InventoryController::class, 'lowStock',[AuthMiddleware::class, PermissionMiddleware::require('inventory.view')]]);
$router->put('/api/inventory/{id}',     [InventoryController::class, 'adjust',  [AuthMiddleware::class, PermissionMiddleware::require('inventory.adjust')]]);
