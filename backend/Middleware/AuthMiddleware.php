<?php

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\User;
use App\Services\AuthService;


class AuthMiddleware {
    private AuthService $authService;

    public function __construct(AuthService $authService) {
        $this->authService = $authService;
    }

    public function handle(callable $next): mixed {
        $token = $this->extractToken();

        if (!$token) {
            return Response::unauthorized('No authentication token provided');
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT t.user_id, t.expires_at, u.role, u.is_active, u.name, u.email, u.force_password_change, u.branch_id
             FROM tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token = ?'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();

        if (!$row) {
            return Response::unauthorized('Invalid token');
        }

        if (!$row['is_active']) {
            return Response::unauthorized('Account is disabled');
        }

        if (!empty($row['force_password_change'])) {
            $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
            $userId = $row['user_id'];

            // Normalize URI: strip /pos/backend and /api/v1 prefixes for consistent matching
            $normalizedUri = preg_replace('#^/pos/backend#', '', $requestUri);
            $normalizedUri = preg_replace('#^/api/v\d+/#', '/api/', $normalizedUri);

            // Allow: PUT /api/users/{self_id} (password change)
            $isUpdatingSelf = ($requestMethod === 'PUT' && $normalizedUri === '/api/users/' . $userId);
            // Allow: POST /api/logout
            $isLoggingOut = ($requestMethod === 'POST' && $normalizedUri === '/api/logout');
            
            if (!$isUpdatingSelf && !$isLoggingOut) {
                return Response::error('يجب تغيير كلمة المرور الافتراضية أولاً', 403, ['force_password_change' => true]);
            }
        }

        // Compare expiry using UTC to avoid timezone mismatches between PHP and MySQL (timezone safety)
        // Treat NULL expires_at as expired — tokens must always have an expiry
        if (!$row['expires_at']) {
            return Response::unauthorized('Token expired');
        }
        $expiresAtUtc = new \DateTime($row['expires_at'], new \DateTimeZone('UTC'));
        $nowUtc = new \DateTime('now', new \DateTimeZone('UTC'));
        if ($expiresAtUtc < $nowUtc) {
            return Response::unauthorized('Token expired');
        }



        // Store auth user in request context
        $this->authService->setUser([
            'id'    => $row['user_id'],
            'name'  => $row['name'],
            'email' => $row['email'],
            'role'  => $row['role'],
            'branch_id' => $row['branch_id'],
        ]);
        $this->authService->setBranchId((int) ($row['branch_id'] ?? 1));

        return $next();
    }

    private function extractToken(): ?string {
        if (!empty($_COOKIE['pos_token'])) {
            return $_COOKIE['pos_token'];
        }

        // 1. Standard $_SERVER key (works when .htaccess passes the header)
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // 2. Fallback: REDIRECT_HTTP_AUTHORIZATION (some Apache configs)
        if (empty($header)) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        // 3. Fallback: apache_request_headers() (works when mod_php is used)
        if (empty($header) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }
}
