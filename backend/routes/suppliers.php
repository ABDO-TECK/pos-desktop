<?php
use App\Controllers\SupplierController;
use App\Controllers\PurchaseController;
use App\Controllers\LedgerPdfController;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;

// Suppliers
$router->get('/api/suppliers',         [SupplierController::class, 'index',   [AuthMiddleware::class, PermissionMiddleware::require('suppliers.view')]]);
$router->get('/api/suppliers/{id}',    [SupplierController::class, 'show',    [AuthMiddleware::class, PermissionMiddleware::require('suppliers.view')]]);
$router->post('/api/suppliers',        [SupplierController::class, 'store',   [AuthMiddleware::class, PermissionMiddleware::require('suppliers.create')]]);
$router->put('/api/suppliers/{id}',    [SupplierController::class, 'update',  [AuthMiddleware::class, PermissionMiddleware::require('suppliers.update')]]);
$router->delete('/api/suppliers/{id}', [SupplierController::class, 'destroy', [AuthMiddleware::class, PermissionMiddleware::require('suppliers.delete')]]);
$router->post('/api/purchases',        [PurchaseController::class, 'purchase',[AuthMiddleware::class, PermissionMiddleware::require('purchases.create')]]);
$router->get('/api/purchases',         [PurchaseController::class, 'purchases',[AuthMiddleware::class, PermissionMiddleware::require('purchases.view')]]);

// Purchase Invoices
$router->get('/api/purchase-invoices',         [PurchaseController::class, 'purchaseInvoices',       [AuthMiddleware::class, PermissionMiddleware::require('purchases.view')]]);
$router->get('/api/purchase-invoices/{id}',    [PurchaseController::class, 'purchaseInvoiceDetail',  [AuthMiddleware::class, PermissionMiddleware::require('purchases.view')]]);
$router->delete('/api/purchase-invoices/{id}', [PurchaseController::class, 'purchaseInvoiceDelete',  [AuthMiddleware::class, PermissionMiddleware::require('purchases.delete')]]);

// Bulk Purchases & Payments
$router->post('/api/purchases/bulk', [PurchaseController::class, 'purchaseBulk', [AuthMiddleware::class, PermissionMiddleware::require('purchases.create')]]);
$router->post('/api/suppliers/{id}/payment', [SupplierController::class, 'addPayment', [AuthMiddleware::class, PermissionMiddleware::require('suppliers.payment')]]);
$router->put('/api/suppliers/ledger/{entryId}', [SupplierController::class, 'updateLedgerEntry', [AuthMiddleware::class, PermissionMiddleware::require('suppliers.ledger.update')]]);
$router->delete('/api/suppliers/ledger/{entryId}', [SupplierController::class, 'deleteLedgerEntry', [AuthMiddleware::class, PermissionMiddleware::require('suppliers.ledger.delete')]]);

// Ledger PDF
$router->get('/api/suppliers/{id}/pdf', [LedgerPdfController::class, 'supplierPdf', [AuthMiddleware::class, PermissionMiddleware::require('suppliers.view')]]);
