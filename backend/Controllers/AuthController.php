<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\CookieHelper;
use App\Helpers\PasswordHasher;
use App\Helpers\Response;
use App\Models\User;
use App\Services\AuthService;


class AuthController extends Controller {

    private User $userModel;
    private AuthService $authService;
    private int $tokenLifetime;
    private int $refreshTokenLifetime;

    public function __construct(
        User $userModel,
        AuthService $authService,
        ?int $tokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ) {
        $this->userModel = $userModel;
        $this->authService = $authService;
        $this->tokenLifetime = $tokenLifetime
            ?? (defined('TOKEN_LIFETIME') ? (int) constant('TOKEN_LIFETIME') : 3600);
        $this->refreshTokenLifetime = $refreshTokenLifetime
            ?? (defined('REFRESH_TOKEN_LIFETIME') ? (int) constant('REFRESH_TOKEN_LIFETIME') : 2592000);
    }

    public function login() {
        $request = new \App\Requests\LoginRequest($this->getBody());
        $data = $request->validated();

        $user = $this->userModel->findForAuthentication($data['email']);
        // Perform a real password verification even for an unknown account to
        // reduce email-enumeration timing differences.
        $passwordHash = $user['password']
            ?? PasswordHasher::dummyHash();
        $passwordValid = password_verify($data['password'], $passwordHash);

        if (!$user || !$passwordValid) {
            return Response::unauthorized('البريد الإلكتروني أو كلمة المرور غير صحيحة');
        }
        if (PasswordHasher::needsRehash($passwordHash)) {
            $this->userModel->updatePasswordHash(
                (int) $user['id'],
                PasswordHasher::hash($data['password'])
            );
        }
        $token = $this->userModel->createToken($user['id']);
        $refreshToken = $this->userModel->createRefreshToken($user['id']);

        // Set HttpOnly cookie for access token
        setcookie('pos_token', $token, CookieHelper::options(time() + $this->tokenLifetime));

        // Set HttpOnly cookie for refresh token
        setcookie('pos_refresh_token', $refreshToken, CookieHelper::options(time() + $this->refreshTokenLifetime, '/api/v1/refresh'));

        return Response::success([
            'user' => $this->authenticatedUser($user),
        ], 'Login successful');
    }

    public function logout() {
        $token = $this->extractToken();
        if ($token) {
            $this->userModel->deleteToken($token);
        }
        // Delete refresh token
        $refreshToken = $_COOKIE['pos_refresh_token'] ?? null;
        if ($refreshToken) {
            $this->userModel->deleteRefreshToken($refreshToken);
        }
        CookieHelper::clearAuthCookies();
        return Response::success(null, 'Logged out successfully');
    }

    public function csrfCookie() {
        $nonce = bin2hex(random_bytes(32));
        // Set cookie with the raw nonce (httpOnly = false so JS can read it for fingerprinting)
        setcookie('XSRF-TOKEN', $nonce, CookieHelper::options(time() + $this->tokenLifetime, '/', false));
        // Return the HMAC signature — this is what the frontend must send in X-XSRF-TOKEN header
        $signature = hash_hmac('sha256', $nonce, \App\Middleware\CsrfMiddleware::getCsrfSecret());
        return Response::success(['csrf_token' => $signature], 'CSRF cookie set');
    }

    public function me() {
        $auth = $this->authService->user();
        $user = $this->userModel->findById($auth['id']);
        return Response::success($this->authenticatedUser($user));
    }

    private function extractToken(): ?string {
        if (!empty($_COOKIE['pos_token'])) return $_COOKIE['pos_token'];
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    public function refresh() {
        $refreshToken = $_COOKIE['pos_refresh_token'] ?? null;
        if (!$refreshToken) {
            return Response::unauthorized('No refresh token');
        }

        $rotation = $this->userModel->rotateRefreshToken($refreshToken);
        if ($rotation['status'] !== 'ok') {
            CookieHelper::clearAuthCookies();
            if ($rotation['status'] === 'reused') {
                \App\Helpers\Logger::warning('Refresh token reuse detected; token family revoked');
            }
            return Response::unauthorized('Invalid refresh token');
        }

        setcookie('pos_refresh_token', $rotation['refresh_token'], CookieHelper::options(time() + $this->refreshTokenLifetime, '/api/v1/refresh'));
        setcookie('pos_token', $rotation['access_token'], CookieHelper::options(time() + $this->tokenLifetime));

        $user = $this->userModel->findById($rotation['user_id']);
        return Response::success(['user' => $this->authenticatedUser($user)], 'Token refreshed');
    }

    /**
     * Keep the authenticated identity contract identical across login, me, and refresh.
     */
    private function authenticatedUser(?array $user): ?array {
        if ($user === null) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'branch_id' => (int) $user['branch_id'],
            'force_password_change' => (int) ($user['force_password_change'] ?? 0),
        ];
    }
}


