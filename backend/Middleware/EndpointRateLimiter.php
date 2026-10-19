<?php

namespace App\Middleware;

use App\Services\AuthService;

/**
 * EndpointRateLimiter — حد مخصص لـ endpoint محدد.
 *
 * الاستخدام في Routes:
 *   EndpointRateLimiter::limit('sales_create', 120, 60)
 *   → 120 طلب / 60 ثانية على endpoint البيع
 */
class EndpointRateLimiter extends RateLimiter
{
    private string $endpointKey;
    private AuthService $auth;

    /**
     * @param AuthService $auth
     * @param string $endpointKey   مفتاح فريد لهذا الـ endpoint (مثل: 'sales_create')
     * @param int $maxRequests      الحد الأقصى للطلبات
     * @param int $windowSeconds    فترة النافذة بالثواني
     */
    public function __construct(AuthService $auth, string $endpointKey = '', int $maxRequests = 60, int $windowSeconds = 60)
    {
        parent::__construct($maxRequests, $windowSeconds);
        $this->auth = $auth;
        $this->endpointKey = $endpointKey;
    }

    /**
     * Factory method — ينشئ بيانات rate limiter مخصص لاستخدامها في Routes.
     *
     * مثال:
     *   $router->post('/api/sales', [SaleController::class, 'store', [
     *       AuthMiddleware::class,
     *       EndpointRateLimiter::limit('sales_create', 120, 60),
     *   ]]);
     *
     * @param string $key          مفتاح فريد لهذا الـ endpoint
     * @param int $maxRequests     الحد الأقصى
     * @param int $windowSeconds   فترة النافذة بالثواني
     * @return array
     */
    public static function limit(string $key, int $maxRequests = 60, int $windowSeconds = 60): array
    {
        return [self::class, $key, $maxRequests, $windowSeconds];
    }

    public function handle(callable $next): mixed
    {
        if ($this->endpointKey !== '') {
            $this->check('ep_' . $this->endpointKey, $this->auth->id());
        }
        return $next();
    }
}
