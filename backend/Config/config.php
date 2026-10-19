<?php

namespace App\Config;

use App\Helpers\EnvLoader;


// ── تحميل ملف البيئة (.env) ───────────────────────────────────
require_once __DIR__ . '/../Helpers/EnvLoader.php';
require_once __DIR__ . '/DeploymentSecurity.php';
$envPath = getenv('ENV_PATH') ?: __DIR__ . '/../.env';
EnvLoader::load($envPath);
$appTimezone = trim(EnvLoader::get('APP_TIMEZONE', 'Africa/Cairo'));
try {
    $appTimezoneObject = new \DateTimeZone($appTimezone);
} catch (\Throwable $exception) {
    throw new \RuntimeException('APP_TIMEZONE must be a valid PHP timezone identifier.');
}
$appTimezone = $appTimezoneObject->getName();
date_default_timezone_set($appTimezone);
$appEnvironment = EnvLoader::get('APP_ENV', 'development');
$defaultDeploymentMode = EnvLoader::getBool('POS_LAN_ENABLED', false)
    ? 'lan'
    : ($appEnvironment === 'production' ? 'web' : 'desktop');
$deploymentMode = strtolower(EnvLoader::get('DEPLOYMENT_MODE', $defaultDeploymentMode));
// في بيئة الإنتاج: تحقق من المتغيرات الإلزامية
if ($appEnvironment === 'production') {
    EnvLoader::validate([
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
    ]);
    // تحذير أمني: استخدام root في الإنتاج
}

DeploymentSecurity::validate(
    $deploymentMode,
    EnvLoader::get('DB_USER', 'root'),
    EnvLoader::get('DB_PASS', ''),
    EnvLoader::getBool('FORCE_HTTPS', false),
    EnvLoader::getBool('SECURE_COOKIES', false),
    EnvLoader::getBool('APP_DEBUG', false),
    $appEnvironment
);

// ── Database (Runtime) ────────────────────────────────────────
define('DB_HOST',    EnvLoader::get('DB_HOST', 'localhost'));
define('DB_NAME',    EnvLoader::get('DB_NAME', 'pos_db'));
define('DB_USER',    EnvLoader::get('DB_USER', 'root'));
define('DB_PASS',    EnvLoader::get('DB_PASS', ''));
define('DB_CHARSET', EnvLoader::get('DB_CHARSET', 'utf8mb4'));
define('DB_PORT',    EnvLoader::get('DB_PORT', '3306'));
define('DB_PERSISTENT', EnvLoader::getBool('DB_PERSISTENT', false));

// ── Database (Migration) ──────────────────────────────────────
define('DB_MIGRATION_USER', EnvLoader::get('DB_MIGRATION_USER', ''));
define('DB_MIGRATION_PASS', EnvLoader::get('DB_MIGRATION_PASS', ''));
define('DB_MIGRATION_HOST', EnvLoader::get('DB_MIGRATION_HOST', ''));
define('DB_MIGRATION_PORT', EnvLoader::get('DB_MIGRATION_PORT', ''));
define('DB_MIGRATION_NAME', EnvLoader::get('DB_MIGRATION_NAME', ''));

// ── Application ───────────────────────────────────────────────
define('APP_ENV',   $appEnvironment);
define('APP_DEBUG', EnvLoader::getBool('APP_DEBUG', false));
define('DEPLOYMENT_MODE', $deploymentMode);
define('APP_TIMEZONE', $appTimezone);

// ── Auth ──────────────────────────────────────────────────────
define('TOKEN_LIFETIME', EnvLoader::getInt('TOKEN_LIFETIME', 60 * 60));           // ساعة واحدة
define('REFRESH_TOKEN_LIFETIME', EnvLoader::getInt('REFRESH_TOKEN_LIFETIME', 60 * 60 * 24 * 30)); // 30 يوم

// ── Inventory ─────────────────────────────────────────────────
define('LOW_STOCK_THRESHOLD', EnvLoader::getInt('LOW_STOCK_THRESHOLD', 5));

// ── Tax ───────────────────────────────────────────────────────
define('TAX_RATE', EnvLoader::getFloat('TAX_RATE', 0.15));

// ── Frontend ──────────────────────────────────────────────────
define('FRONTEND_URL', EnvLoader::get('FRONTEND_URL', 'http://localhost:5173'));
define('INVOICE_DEFAULT_LIMIT', max(1, min(100, EnvLoader::getInt('INVOICE_DEFAULT_LIMIT', 100))));

// ── Security ──────────────────────────────────────────────────
define('TRUSTED_PROXIES', array_map('trim', explode(',', EnvLoader::get('TRUSTED_PROXIES', '127.0.0.1,::1'))));


// ── Production Security Warnings ──────────────────────────────
