<?php
use App\Controllers\CustomerController;
use App\Controllers\LedgerPdfController;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;

$router->get('/api/customers',                 [CustomerController::class, 'index',      [AuthMiddleware::class, PermissionMiddleware::require('customers.view')]]);
$router->post('/api/customers',                [CustomerController::class, 'store',      [AuthMiddleware::class, PermissionMiddleware::require('customers.create')]]);
$router->get('/api/customers/{id}',            [CustomerController::class, 'show',       [AuthMiddleware::class, PermissionMiddleware::require('customers.view')]]);
$router->put('/api/customers/{id}',            [CustomerController::class, 'update',     [AuthMiddleware::class, PermissionMiddleware::require('customers.update')]]);
$router->delete('/api/customers/{id}',         [CustomerController::class, 'destroy',    [AuthMiddleware::class, PermissionMiddleware::require('customers.delete')]]);
$router->post('/api/customers/{id}/payment',   [CustomerController::class, 'addPayment',       [AuthMiddleware::class, PermissionMiddleware::require('customers.payment')]]);
$router->put('/api/customers/ledger/{entryId}', [CustomerController::class, 'updateLedgerEntry', [AuthMiddleware::class, PermissionMiddleware::require('customers.ledger.update')]]);
$router->delete('/api/customers/ledger/{entryId}', [CustomerController::class, 'deleteLedgerEntry', [AuthMiddleware::class, PermissionMiddleware::require('customers.ledger.delete')]]);
$router->get('/api/customers/{id}/pdf', [LedgerPdfController::class, 'customerPdf', [AuthMiddleware::class, PermissionMiddleware::require('customers.view')]]);

use App\Controllers\LoyaltyController;
$router->get('/api/loyalty/status',            [LoyaltyController::class, 'status',  [AuthMiddleware::class]]);
$router->get('/api/loyalty/{customerId}/history', [LoyaltyController::class, 'history', [AuthMiddleware::class, PermissionMiddleware::require('customers.view')]]);
$router->post('/api/loyalty/{customerId}/redeem', [LoyaltyController::class, 'redeem',  [AuthMiddleware::class, PermissionMiddleware::require('loyalty.redeem')]]);
