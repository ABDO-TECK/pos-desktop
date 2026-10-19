<?php
use App\Controllers\ReportController;
use App\Controllers\UserController;
use App\Controllers\SettingsController;
use App\Controllers\UpdateController;
use App\Controllers\BackupController;
use App\Controllers\ExpenseController;
use App\Controllers\TelemetryController;
use App\Controllers\UpdateRecoveryController;
use App\Middleware\AuthMiddleware;


use App\Middleware\PermissionMiddleware;

// Reports
$router->get('/api/reports/daily',    [ReportController::class, 'daily',        [AuthMiddleware::class, PermissionMiddleware::require('reports.view')]]);
$router->get('/api/reports/monthly',  [ReportController::class, 'monthly',      [AuthMiddleware::class, PermissionMiddleware::require('reports.view')]]);
$router->get('/api/reports/products', [ReportController::class, 'topProducts',  [AuthMiddleware::class, PermissionMiddleware::require('reports.view')]]);
$router->get('/api/reports/summary',  [ReportController::class, 'summary',      [AuthMiddleware::class, PermissionMiddleware::require('reports.view')]]);
$router->get('/api/reports/profit',   [ReportController::class, 'profitReport', [AuthMiddleware::class, PermissionMiddleware::require('reports.view')]]);

// Users
$router->get('/api/users',         [UserController::class, 'index',   [AuthMiddleware::class, PermissionMiddleware::require('users.manage')]]);
$router->post('/api/users',        [UserController::class, 'store',   [AuthMiddleware::class, PermissionMiddleware::require('users.manage')]]);
$router->put('/api/users/{id}',    [UserController::class, 'update',  [AuthMiddleware::class]]);
$router->delete('/api/users/{id}', [UserController::class, 'destroy', [AuthMiddleware::class, PermissionMiddleware::require('users.manage')]]);

// Settings
$router->get('/api/settings',  [SettingsController::class, 'index',  [AuthMiddleware::class, PermissionMiddleware::require('settings.view')]]);
$router->post('/api/settings', [SettingsController::class, 'update', [AuthMiddleware::class, PermissionMiddleware::require('settings.update')]]);

// Updates
$router->get('/api/updates/status',    [UpdateController::class, 'status',    [AuthMiddleware::class, PermissionMiddleware::require('updates.view'), \App\Middleware\EndpointRateLimiter::limit('update_status', 60, 60)]]);
$router->get('/api/update/status',     [UpdateController::class, 'status',    [AuthMiddleware::class, PermissionMiddleware::require('updates.view'), \App\Middleware\EndpointRateLimiter::limit('update_status', 60, 60)]]);
$router->get('/api/updates/check',     [UpdateController::class, 'check',     [AuthMiddleware::class, PermissionMiddleware::require('updates.check'), \App\Middleware\EndpointRateLimiter::limit('update_check', 10, 60)]]);
$router->post('/api/updates/check',    [UpdateController::class, 'check',     [AuthMiddleware::class, PermissionMiddleware::require('updates.check'), \App\Middleware\EndpointRateLimiter::limit('update_check', 10, 60)]]);
$router->get('/api/update/check',      [UpdateController::class, 'check',     [AuthMiddleware::class, PermissionMiddleware::require('updates.check'), \App\Middleware\EndpointRateLimiter::limit('update_check', 10, 60)]]);
$router->post('/api/update/check',     [UpdateController::class, 'check',     [AuthMiddleware::class, PermissionMiddleware::require('updates.check'), \App\Middleware\EndpointRateLimiter::limit('update_check', 10, 60)]]);
$router->get('/api/update/changelog',  [UpdateController::class, 'changelog', [AuthMiddleware::class, PermissionMiddleware::require('updates.view'), \App\Middleware\EndpointRateLimiter::limit('update_changelog', 10, 60)]]);
$router->get('/api/updates/changelog', [UpdateController::class, 'changelog', [AuthMiddleware::class, PermissionMiddleware::require('updates.view'), \App\Middleware\EndpointRateLimiter::limit('update_changelog', 10, 60)]]);
$router->post('/api/updates/apply',    [UpdateController::class, 'apply',     [AuthMiddleware::class, PermissionMiddleware::require('updates.apply'), \App\Middleware\EndpointRateLimiter::limit('update_apply', 2, 300)]]);
$router->post('/api/update/apply',     [UpdateController::class, 'apply',     [AuthMiddleware::class, PermissionMiddleware::require('updates.apply'), \App\Middleware\EndpointRateLimiter::limit('update_apply', 2, 300)]]);
$router->get('/api/updates/history',   [UpdateController::class, 'history',   [AuthMiddleware::class, PermissionMiddleware::require('updates.view'), \App\Middleware\EndpointRateLimiter::limit('update_history', 30, 60)]]);
$router->get('/api/update/history',    [UpdateController::class, 'history',   [AuthMiddleware::class, PermissionMiddleware::require('updates.view'), \App\Middleware\EndpointRateLimiter::limit('update_history', 30, 60)]]);
$router->post('/api/updates/rollback', [UpdateController::class, 'rollback',  [AuthMiddleware::class, PermissionMiddleware::require('updates.rollback'), \App\Middleware\EndpointRateLimiter::limit('update_rollback', 5, 300)]]);
$router->post('/api/update/rollback',  [UpdateController::class, 'rollback',  [AuthMiddleware::class, PermissionMiddleware::require('updates.rollback'), \App\Middleware\EndpointRateLimiter::limit('update_rollback', 5, 300)]]);
$router->get('/api/updates/snapshots', [UpdateController::class, 'snapshots', [AuthMiddleware::class, PermissionMiddleware::require('updates.view'), \App\Middleware\EndpointRateLimiter::limit('update_snapshots', 30, 60)]]);
$router->get('/api/update/snapshots',  [UpdateController::class, 'snapshots', [AuthMiddleware::class, PermissionMiddleware::require('updates.view'), \App\Middleware\EndpointRateLimiter::limit('update_snapshots', 30, 60)]]);
$router->get('/api/update/jobs/{id}',  [UpdateController::class, 'status',    [AuthMiddleware::class, PermissionMiddleware::require('updates.view'), \App\Middleware\EndpointRateLimiter::limit('update_status', 60, 60)]]);
$router->get('/api/updates/channel',   [UpdateController::class, 'getChannel', [AuthMiddleware::class, PermissionMiddleware::require('updates.view'), \App\Middleware\EndpointRateLimiter::limit('update_channel_get', 30, 60)]]);
$router->post('/api/updates/channel',  [UpdateController::class, 'setChannel', [AuthMiddleware::class, PermissionMiddleware::require('updates.manage_channel'), \App\Middleware\EndpointRateLimiter::limit('update_channel_post', 10, 60)]]);
$router->get('/api/bootstrap/update',  [UpdateController::class, 'bootstrapUpdate', [AuthMiddleware::class, \App\Middleware\EndpointRateLimiter::limit('bootstrap_update', 30, 60)]]);
$router->get('/api/update/bootstrap',  [UpdateController::class, 'bootstrapUpdate', [AuthMiddleware::class, \App\Middleware\EndpointRateLimiter::limit('bootstrap_update', 30, 60)]]);
$router->get('/api/updates/customer-status', [UpdateController::class, 'customerStatus', [AuthMiddleware::class, \App\Middleware\EndpointRateLimiter::limit('customer_status', 60, 60)]]);
$router->post('/api/updates/customer-result', [UpdateController::class, 'customerResult', [AuthMiddleware::class, \App\Middleware\EndpointRateLimiter::limit('customer_result', 60, 60)]]);


// Telemetry & Fleet Management
$router->post('/api/telemetry/updates',        [TelemetryController::class, 'record',        [AuthMiddleware::class, \App\Middleware\EndpointRateLimiter::limit('telemetry_ingest', 120, 60)]]);
$router->post('/api/telemetry/updates/batch',  [TelemetryController::class, 'recordBatch',   [AuthMiddleware::class, \App\Middleware\EndpointRateLimiter::limit('telemetry_batch', 30, 60)]]);
$router->get('/api/admin/fleet/stats',         [TelemetryController::class, 'stats',         [AuthMiddleware::class, PermissionMiddleware::require('updates.telemetry.view'), \App\Middleware\EndpointRateLimiter::limit('fleet_stats', 60, 60)]]);
$router->get('/api/admin/fleet/devices',       [TelemetryController::class, 'devices',       [AuthMiddleware::class, PermissionMiddleware::require('updates.telemetry.view'), \App\Middleware\EndpointRateLimiter::limit('fleet_devices', 60, 60)]]);
$router->get('/api/admin/fleet/devices/{id}',  [TelemetryController::class, 'deviceDetails', [AuthMiddleware::class, PermissionMiddleware::require('updates.telemetry.view'), \App\Middleware\EndpointRateLimiter::limit('fleet_device_details', 60, 60)]]);
$router->post('/api/admin/fleet/purge',        [TelemetryController::class, 'purge',         [AuthMiddleware::class, PermissionMiddleware::require('updates.telemetry.manage'), \App\Middleware\EndpointRateLimiter::limit('fleet_purge', 10, 60)]]);

// Update Self-Healing & Recovery
$router->get('/api/admin/updates/recovery/diagnose',     [UpdateRecoveryController::class, 'diagnose',    [AuthMiddleware::class, PermissionMiddleware::require('updates.recovery.view'), \App\Middleware\EndpointRateLimiter::limit('recovery_diag', 60, 60)]]);
$router->post('/api/admin/updates/recovery/execute',     [UpdateRecoveryController::class, 'execute',     [AuthMiddleware::class, PermissionMiddleware::require('updates.recovery.manage'), \App\Middleware\EndpointRateLimiter::limit('recovery_exec', 10, 60)]]);
$router->get('/api/admin/updates/recovery/audit',        [UpdateRecoveryController::class, 'audit',       [AuthMiddleware::class, PermissionMiddleware::require('updates.recovery.view'), \App\Middleware\EndpointRateLimiter::limit('recovery_audit', 60, 60)]]);
$router->post('/api/admin/updates/recovery/health-check',[UpdateRecoveryController::class, 'healthCheck', [AuthMiddleware::class, PermissionMiddleware::require('updates.recovery.view'), \App\Middleware\EndpointRateLimiter::limit('recovery_health', 30, 60)]]);





// Backup
$router->get('/api/backup', [BackupController::class, 'download', [AuthMiddleware::class, PermissionMiddleware::require('backup.manage')]]);
$router->post('/api/backup/restore', [BackupController::class, 'restore', [AuthMiddleware::class, PermissionMiddleware::require('backup.manage')]]);
$router->post('/api/admin/backup/schedule', [BackupController::class, 'schedule', [AuthMiddleware::class, PermissionMiddleware::require('backup.manage')]]);

// Expenses
$router->get('/api/expense-categories',         [ExpenseController::class, 'getCategories', [AuthMiddleware::class, PermissionMiddleware::require('expenses.view')]]);
$router->post('/api/expense-categories',        [ExpenseController::class, 'createCategory', [AuthMiddleware::class, PermissionMiddleware::require('expenses.manage')]]);
$router->put('/api/expense-categories/{id}',    [ExpenseController::class, 'updateCategory', [AuthMiddleware::class, PermissionMiddleware::require('expenses.manage')]]);
$router->delete('/api/expense-categories/{id}', [ExpenseController::class, 'deleteCategory', [AuthMiddleware::class, PermissionMiddleware::require('expenses.delete')]]);
$router->get('/api/expenses',         [ExpenseController::class, 'getExpenses', [AuthMiddleware::class, PermissionMiddleware::require('expenses.view')]]);
$router->post('/api/expenses',        [ExpenseController::class, 'createExpense', [AuthMiddleware::class, PermissionMiddleware::require('expenses.create')]]);
$router->put('/api/expenses/{id}',    [ExpenseController::class, 'updateExpense', [AuthMiddleware::class, PermissionMiddleware::require('expenses.update')]]);
$router->delete('/api/expenses/{id}', [ExpenseController::class, 'deleteExpense', [AuthMiddleware::class, PermissionMiddleware::require('expenses.delete')]]);

// Health
$router->get('/api/admin/health/slow-queries', [\App\Controllers\HealthController::class, 'slowQueries', [AuthMiddleware::class, PermissionMiddleware::require('settings.update')]]);
$router->get('/api/admin/client-logs', [\App\Controllers\ClientLogController::class, 'index', [AuthMiddleware::class, PermissionMiddleware::require('audit.view')]]);
$router->get('/api/admin/error-logs', [\App\Controllers\ClientLogController::class, 'all', [AuthMiddleware::class, PermissionMiddleware::require('audit.view')]]);

// Audit Logs
$router->get('/api/admin/audit-logs', [\App\Controllers\AuditLogController::class, 'index', [AuthMiddleware::class, PermissionMiddleware::require('audit.view')]]);
