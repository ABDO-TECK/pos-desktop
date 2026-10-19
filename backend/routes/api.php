<?php
/**
 * API Route Loader (v1) — يحمّل ملفات المسارات المقسمة حسب الوحدة.
 * كل ملف يستقبل المتغير $router تلقائياً.
 *
 * هذا الملف يُحمّل لجميع طلبات /api/v1/* (والطلبات بدون رقم نسخة).
 * عند إضافة API v2 مستقبلاً:
 *   1. أنشئ ملف routes/api_v2.php
 *   2. في Router.php، أضف شرط لتحميل الملف المناسب حسب النسخة
 */

$routeFiles = [
    'auth',
    'products',
    'sales',
    'suppliers',
    'customers',
    'admin',
    'system',
    'sse',
    'branches',
];

foreach ($routeFiles as $file) {
    require_once __DIR__ . "/{$file}.php";
}
