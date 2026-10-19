<?php

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;
use App\Services\AuthService;

/**
 * PermissionMiddleware — فحص صلاحيات RBAC من قاعدة البيانات.
 *
 * الاستخدام في Routes:
 *   PermissionMiddleware::require('products.create')
 *
 * Admin يتجاوز كل الفحوصات (backward compatible).
 * إذا لم يتم تمرير اسم صلاحية، يتصرف كـ AdminMiddleware (يقبل admin فقط).
 */
class PermissionMiddleware
{
    /** APCu key that stores the current permission cache version number */
    private const CACHE_VERSION_KEY = 'perm_cache_version';

    private AuthService $authService;
    private string $permission;

    public function __construct(AuthService $authService, string $permission = '')
    {
        $this->authService = $authService;
        $this->permission  = $permission;
    }

    /**
     * Factory method — تُنشئ callable يمكن استخدامه في مصفوفة middlewares.
     *
     * مثال:
     *   $router->post('/api/products', [ProductController::class, 'store', [
     *       AuthMiddleware::class,
     *       PermissionMiddleware::require('products.create'),
     *   ]]);
     *
     * @param string $permission اسم الصلاحية (مثل: 'products.create')
     * @return array [className, permission] — يعالجها Router لبناء الـ middleware
     */
    public static function require(string $permission): array
    {
        return [self::class, $permission];
    }

    public function handle(callable $next): mixed
    {
        $user = $this->authService->user();
        if (!$user) {
            return Response::unauthorized();
        }

        // Admin يتجاوز كل الفحوصات (backward compatible)
        if ($user['role'] === 'admin') {
            return $next();
        }

        // إذا لم يتم تحديد صلاحية معينة → يعمل كـ AdminMiddleware (admin فقط)
        if ($this->permission === '') {
            return Response::forbidden('Admin access required');
        }

        // فحص الصلاحية من قاعدة البيانات
        if (!self::allows($this->authService, $this->permission)) {
            return Response::forbidden("ليس لديك صلاحية: {$this->permission}");
        }

        return $next();
    }

    public static function allows(AuthService $authService, string $permission): bool
    {
        $user = $authService->user();
        if (!$user) {
            return false;
        }
        if ($user['role'] === 'admin') {
            return true;
        }
        return self::roleHasPermission($user['role'], $permission);
    }

    private static function roleHasPermission(string $role, string $permission): bool
    {
        // Per-process cache (fast path for repeated checks in same request)
        static $processCache = [];
        $version = self::getCacheVersion();
        $key = "perm:v{$version}:{$role}:{$permission}";
        if (isset($processCache[$key])) return $processCache[$key];

        // APCu cache (persists across requests, 5-minute TTL)
        if (function_exists('apcu_fetch')) {
            $success = false;
            $cached = apcu_fetch($key, $success);
            if ($success) {
                $processCache[$key] = $cached;
                return $cached;
            }
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT 1 FROM role_permissions rp
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role = ? AND p.name = ?
                 LIMIT 1'
            );
            $stmt->execute([$role, $permission]);
            $result = (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            // إذا فشل الاستعلام (مثلاً الجداول غير موجودة) → ارفض الوصول
            $result = false;
        }

        // Store in both caches
        $processCache[$key] = $result;
        if (function_exists('apcu_store')) {
            apcu_store($key, $result, 300); // 5 minutes
        }

        return $result;
    }

    /**
     * Get the current cache version number.
     * Starts at 1 if no version exists yet.
     */
    private static function getCacheVersion(): int
    {
        if (function_exists('apcu_fetch')) {
            $success = false;
            $version = apcu_fetch(self::CACHE_VERSION_KEY, $success);
            if ($success) {
                return (int) $version;
            }
        }
        return 1;
    }

    /**
     * Invalidate all cached permissions by bumping the version number.
     * Old versioned keys will expire naturally via their 5-minute TTL.
     * This is O(1) instead of scanning the entire APCu store.
     */
    public static function clearPermissionCache(): void
    {
        if (function_exists('apcu_inc')) {
            try {
                // apcu_inc atomically increments; if the key doesn't exist, create it at 2
                $result = apcu_inc(self::CACHE_VERSION_KEY);
                if ($result === false) {
                    // Key didn't exist yet — initialize it
                    apcu_store(self::CACHE_VERSION_KEY, 2, 0); // TTL=0 means never expire
                }
            } catch (\Throwable $e) {
                // Silently ignore — cache will expire naturally via TTL
            }
        }
    }
}
