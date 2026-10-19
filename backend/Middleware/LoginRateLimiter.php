<?php

namespace App\Middleware;

/**
 * Public login limiter: five attempts per minute per client IP by default.
 */
class LoginRateLimiter extends RateLimiter
{
    public function __construct(int $maxAttempts = 5, int $windowSeconds = 60)
    {
        parent::__construct($maxAttempts, $windowSeconds);
    }

    public function handle(callable $next): mixed
    {
        // Login is public, so it must always use the client IP bucket.
        $this->check('login_rl', null);
        return $next();
    }

    protected function rateLimitMessage(): string
    {
        return 'تم تجاوز الحد المسموح لمحاولات تسجيل الدخول. يرجى الانتظار.';
    }
}
