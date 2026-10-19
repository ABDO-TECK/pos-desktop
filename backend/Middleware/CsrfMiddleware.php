<?php

namespace App\Middleware;

use App\Helpers\Response;


class CsrfMiddleware {
    
    /** Routes that don't require CSRF verification (pre-auth) */
    private array $exempt = [
        '/api/login',
        '/api/csrf-cookie',
    ];
    
    public function handle(callable $next): mixed {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        // Skip safe methods
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return $next();
        }
        
        // Skip exempt routes (pre-authentication)
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $uri = preg_replace('#^/pos/backend#', '', $uri);
        $uri = preg_replace('#^/api/v\d+/#', '/api/', $uri);
        foreach ($this->exempt as $path) {
            if ($uri === $path) {
                return $next();
            }
        }

        // Packaged desktop mutations must come from the exact app origin and
        // still carry the signed double-submit token. Development desktop
        // tooling may use its explicitly configured browser origin.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $appEnv = \App\Helpers\EnvLoader::get(
            'APP_ENV',
            defined('APP_ENV') ? (string) constant('APP_ENV') : 'development'
        );
        $deploymentMode = \App\Helpers\EnvLoader::get(
            'DEPLOYMENT_MODE',
            defined('DEPLOYMENT_MODE') ? (string) constant('DEPLOYMENT_MODE') : ''
        );
        if ($appEnv === 'production' && $deploymentMode === 'desktop' && $origin !== 'app://pos-app') {
            return Response::forbidden('Origin not allowed');
        }
        
        $cookieNonce = $_COOKIE['XSRF-TOKEN'] ?? '';
        $headerToken = $this->getHeaderToken();
        
        if (empty($cookieNonce) || empty($headerToken)) {
            return Response::forbidden('CSRF token missing');
        }

        // HMAC-signed double-submit: verify that the header token is HMAC(nonce, secret)
        // This prevents XSS from bypassing CSRF — even if JS reads the cookie nonce,
        // it cannot forge the HMAC without the server-side secret.
        $expectedSignature = hash_hmac('sha256', $cookieNonce, self::getCsrfSecret());

        if (!hash_equals($expectedSignature, $headerToken)) {
            return Response::forbidden('CSRF token mismatch');
        }
        
        return $next();
    }
    
    /**
     * Retrieve the CSRF HMAC secret.
     * Priority: CSRF_SECRET env var → auto-generated file → runtime generation.
     * The auto-generated secret is persisted to a file so it survives process restarts
     * but is unique per deployment (not derivable from known config values).
     */
    public static function getCsrfSecret(): string
    {
        // 1. Explicit env var has highest priority
        $secret = \App\Helpers\EnvLoader::get('CSRF_SECRET', '');
        if ($secret !== '') {
            if (preg_match('/\A[a-f0-9]{64,}\z/i', $secret) !== 1) {
                throw new \RuntimeException(
                    'CSRF_SECRET must be at least 32 random bytes encoded as hex'
                );
            }
            return $secret;
        }

        // 2. Auto-generated persistent secret file
        $pharRunning = \Phar::running(false);
        $storageDir = $_ENV['APP_STORAGE_DIR']
            ?? (getenv('APP_STORAGE_DIR') ?: null)
            ?? ($pharRunning ? dirname($pharRunning) . '/storage' : dirname(__DIR__) . '/storage');
        $secretFile = rtrim($storageDir, '/\\') . '/.csrf_secret';

        // Read existing secret
        if (is_file($secretFile)) {
            $stored = @file_get_contents($secretFile);
            if ($stored !== false) {
                $stored = trim($stored);
                if (strlen($stored) >= 32) {
                    return $stored;
                }
            }
        }

        // 3. Generate and persist under an exclusive lock so concurrent first
        // requests cannot issue signatures from different secrets.
        $dir = dirname($secretFile);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create CSRF secret directory');
        }

        $handle = fopen($secretFile, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Unable to lock CSRF secret file');
        }

        try {
            rewind($handle);
            $stored = trim((string) stream_get_contents($handle));
            if (strlen($stored) >= 32) {
                return $stored;
            }

            $secret = bin2hex(random_bytes(32));
            ftruncate($handle, 0);
            rewind($handle);
            if (fwrite($handle, $secret) !== strlen($secret) || !fflush($handle)) {
                throw new \RuntimeException('Unable to persist CSRF secret');
            }
            @chmod($secretFile, 0600);
            return $secret;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function getHeaderToken(): string {
        $header = $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '';
        if (empty($header) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header  = $headers['X-XSRF-TOKEN'] ?? $headers['x-xsrf-token'] ?? '';
        }
        return $header;
    }
}
