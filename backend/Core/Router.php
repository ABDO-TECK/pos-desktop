<?php

namespace App\Core;

use App\Helpers\Response;
use App\Middleware\CsrfMiddleware;


class Router {
    private array $routes = [];
    private Container $container;
    private string $apiVersion = 'v1';

    public function __construct(?Container $container = null) {
        $this->container = $container ?? new Container();
    }

    public function get(string $path, array $handler): void {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, array $handler): void {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, array $handler): void {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, array $handler): void {
        $this->routes[] = [
            'method'  => $method,
            'path'    => $path,
            'handler' => $handler,
        ];
    }

    public function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Strip /backend/api prefix if running under /pos/backend
        $base = '/pos/backend';
        if (str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        // ── API Versioning ────────────────────────────────────────
        // يستخرج رقم النسخة من المسار (مثل: /api/v1/products → v1)
        // - v1: يُوجَّه إلى routes/api.php (الافتراضي)
        // أي نسخة مرقمة غير مدعومة تُرفض حتى يوجد لها Router مستقل.
        // X-API-Version يعبّر دائماً عن النسخة التي خدمت الاستجابة فعلياً.
        if (preg_match('#^/api/(v\d+)(?:/|$)#', $uri, $vMatch)) {
            $requestedVersion = $vMatch[1];
            if ($requestedVersion !== $this->apiVersion) {
                $this->sendResponse(Response::error(
                    'Unsupported API version',
                    404,
                    [
                        'requested_version' => $requestedVersion,
                        'supported_versions' => [$this->apiVersion],
                    ],
                    \App\Helpers\ErrorCodes::UNSUPPORTED_API_VERSION
                ));
                return;
            }
        }
        // فقط v1 تُطبّع إلى المسارات المسجلة حالياً.
        $uri = preg_replace('#^/api/v1(?=/|$)#', '/api', $uri);
        header('X-API-Version: ' . $this->apiVersion);

        foreach ($this->routes as $route) {
            $params = $this->match($route['method'], $route['path'], $method, $uri);
            if ($params !== null) {
                [$controllerClass, $action, $middlewares] = $this->parseHandler($route['handler']);
                
                $middlewares = $this->prepareMiddlewares($middlewares);

                $response = $this->runMiddlewares($middlewares, function () use ($controllerClass, $action, $params, $method) {
                    // Authenticate, authorize, rate-limit, and validate CSRF
                    // before consuming/parsing an attacker-controlled body.
                    if ($this->expectsJsonBody($method)) {
                        RequestBody::readJson();
                    }

                    $controller = $this->container->get($controllerClass);
                    return $controller->$action(...array_values($params));
                });
                $this->sendResponse($response);
                return;
            }
        }

        $this->sendResponse(Response::notFound('Route not found'));
    }

    private function sendResponse(mixed $response): void {
        if (is_array($response) && isset($response['status_code'])) {
            http_response_code($response['status_code']);
            header('X-API-Version: ' . $this->apiVersion);
            
            if ($response['status_code'] !== 304) {
                header('Content-Type: application/json; charset=utf-8');
            }

            if (isset($response['headers']) && is_array($response['headers'])) {
                foreach ($response['headers'] as $k => $v) {
                    header("{$k}: {$v}");
                }
            }
            
            if ($response['status_code'] !== 304) {
                if (isset($response['compressed_body'])) {
                    echo $response['compressed_body'];
                } elseif (isset($response['body'])) {
                    echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        } else if (is_string($response)) {
            echo $response;
        }
    }

    private function match(string $routeMethod, string $routePath, string $method, string $uri): ?array {
        if ($routeMethod !== $method) {
            return null;
        }

        // Convert route path to regex: /products/{id} -> /products/([^/]+)
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '([^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (!preg_match($pattern, $uri, $matches)) {
            return null;
        }

        // Extract param names
        preg_match_all('/\{([a-zA-Z_]+)\}/', $routePath, $paramNames);
        $params = [];
        foreach ($paramNames[1] as $index => $name) {
            $params[$name] = $matches[$index + 1];
        }

        return $params;
    }

    private function parseHandler(array $handler): array {
        $controllerClass = $handler[0];
        $action          = $handler[1];
        $middlewares     = $handler[2] ?? [];
        return [$controllerClass, $action, $middlewares];
    }

    /**
     * Keep authenticated rate limits behind authentication so their keys can be
     * user-scoped. Public routes intentionally fall back to IP-scoped limits.
     */
    private function prepareMiddlewares(array $routeMiddlewares): array
    {
        $requestMiddleware = [
            \App\Middleware\HttpsMiddleware::class,
            \App\Middleware\CompressionMiddleware::class,
            \App\Middleware\TimingMiddleware::class,
            CsrfMiddleware::class,
        ];
        $rateLimiters = [
            \App\Middleware\ReadRateLimiter::class,
            \App\Middleware\WriteRateLimiter::class,
            \App\Middleware\DeleteRateLimiter::class,
        ];

        $authIndex = array_search(
            \App\Middleware\AuthMiddleware::class,
            $routeMiddlewares,
            true
        );

        if ($authIndex === false) {
            return array_merge($requestMiddleware, $rateLimiters, $routeMiddlewares);
        }

        return array_merge(
            $requestMiddleware,
            array_slice($routeMiddlewares, 0, $authIndex + 1),
            $rateLimiters,
            array_slice($routeMiddlewares, $authIndex + 1)
        );
    }

    private function expectsJsonBody(string $method): bool
    {
        if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return false;
        }

        $contentType = trim((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if ($contentType === '') {
            return true;
        }

        return preg_match(
            '#^application/(?:[a-z0-9.+-]+\+)?json(?:\s*;|$)#i',
            $contentType
        ) === 1;
    }

    private function runMiddlewares(array $middlewares, callable $final): mixed {
        $chain = array_reduce(
            array_reverse($middlewares),
            function ($next, $mw) {
                return function () use ($next, $mw) {
                    // دعم middleware مع معاملات:
                    // مصفوفة من 2 عناصر: [ClassName, param] → PermissionMiddleware
                    // مصفوفة من 4 عناصر: [ClassName, key, max, window] → EndpointRateLimiter
                    if (is_array($mw)) {
                        $className = $mw[0];
                        $authService = $this->container->get(\App\Services\AuthService::class);

                        if (count($mw) === 2) {
                            // PermissionMiddleware::require('permission.name')
                            $instance = new $className($authService, $mw[1]);
                        } elseif (count($mw) >= 4) {
                            // EndpointRateLimiter::limit('key', max, window)
                            $instance = new $className($authService, $mw[1], (int)$mw[2], (int)$mw[3]);
                        } else {
                            // fallback — حاول بناء مع عنصر واحد
                            $instance = new $className($authService, $mw[1] ?? '');
                        }
                        return $instance->handle($next);
                    }
                    // الحالة العادية: string class name
                    return $this->container->get($mw)->handle($next);
                };
            },
            $final
        );
        return $chain();
    }
}
