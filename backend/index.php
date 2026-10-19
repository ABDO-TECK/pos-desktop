<?php

declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Container;
use App\Core\Router;
use App\Core\ValidationException;
use App\Core\HttpException;
use App\Helpers\Response;
use App\Helpers\Logger;
use App\Middleware\RateLimiter;
use App\Helpers\ErrorCodes;

// ── Config (loads .env via EnvLoader) ─────────────────────────
// Configuration is loaded after the bootstrap handlers so deployment failures are recorded.

// ── Composer Autoloader ───────────────────────────────────────
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die("Composer dependencies not installed. Please run 'composer install'.");
}
require_once __DIR__ . '/vendor/autoload.php';
\App\Helpers\ErrorHandler::register();
require_once __DIR__ . '/Config/config.php';

// ── Auto-Migrate on Update (Self-Healing) ──────────────────────
// Production and packaged deployments run migrations once before the HTTP
// server starts. Local development may opt in explicitly.
if (APP_ENV === 'development' && \App\Helpers\EnvLoader::getBool('AUTO_MIGRATE', false)) {
    try {
        require_once __DIR__ . '/Services/MigrationService.php';
        $migrationResult = (new \App\Services\MigrationService())->runAllMigrations();
        if (!empty($migrationResult['errors'])) {
            throw new \RuntimeException(implode('; ', $migrationResult['errors']));
        }
    } catch (\Throwable $e) {
        Logger::error('Dev auto-migration failed during boot', Logger::exceptionContext($e));
        http_response_code(503);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'message' => 'Database migration failed; service is unavailable.',
            'data' => null,
            'errors' => ['code' => 'MIGRATION_FAILED'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── Event System ──────────────────────────────────────────────
\App\Helpers\CacheSubscriber::register();

use App\Services\LoyaltyService;
\App\Helpers\EventDispatcher::listen('sale.completed', function(array $data) {
    if (!empty($data['customer_id']) && !empty($data['invoice_id'])) {
        $db = \App\Config\Database::getInstance();
        $repo = new \App\Repositories\LoyaltyRepository($db);
        (new LoyaltyService($repo, $db))->earnPoints(
            (int)$data['customer_id'],
            (int)$data['invoice_id'],
            (float)($data['total'] ?? 0)
        );
    }
});

// ── CORS + Preflight ──────────────────────────────────────────
(new \App\Middleware\CorsMiddleware())->handle(fn() => null);

header('Content-Type: application/json; charset=UTF-8');

// ── Security Headers ──────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");



// ── Rate Limiting ── (200 طلب / دقيقة per-IP كحد أقصى عام)
// هذا الحد العام يعمل per-IP قبل المصادقة كخط دفاع أول.
// بعد المصادقة، يتم تطبيق حدود أدق per-user عبر:
//   - ReadRateLimiter:   200/دقيقة (GET)    — per-user أو per-IP
//   - WriteRateLimiter:  60/دقيقة  (POST/PUT) — per-user أو per-IP
//   - DeleteRateLimiter: 30/دقيقة  (DELETE)   — per-user أو per-IP
(new RateLimiter(200, 60))->check('coarse_public_ip', null);

// ── Error handling ─────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    if ($e instanceof HttpException) {
        $resp = Response::error(
            $e->getMessage(),
            $e->getStatusCode(),
            $e->getErrors(),
            $e->getErrorCode()
        );
        http_response_code($e->getStatusCode());
        echo json_encode($resp['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($e instanceof ValidationException) {
        $resp = Response::error($e->getMessage(), 422, $e->getErrors(), ErrorCodes::VALIDATION_FAILED);
        http_response_code(422);
        echo json_encode($resp['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    $message = (APP_DEBUG && APP_ENV !== 'production') ? $e->getMessage() : 'Internal server error';
    Logger::critical('Unhandled application exception', Logger::exceptionContext($e));
    $resp = Response::serverError($message);
    http_response_code(500);
    echo json_encode($resp['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

// ── Routes ─────────────────────────────────────────────────────
$container = new \App\Core\Container();
require_once __DIR__ . '/Config/bindings.php';
$router = new Router($container);
require_once __DIR__ . '/routes/api.php';
$router->dispatch();
