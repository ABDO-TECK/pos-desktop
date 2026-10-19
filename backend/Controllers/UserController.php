<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\CookieHelper;
use App\Helpers\Response;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Helpers\Messages;


class UserController extends Controller {

    private UserRepository $userRepo;
    private AuthService $authService;

    public function __construct(UserRepository $userRepo, AuthService $authService) {
        $this->userRepo = $userRepo;
        $this->authService = $authService;
    }

    public function index() {
        $filters = [];
        $filters += $this->getPaginationParams();

        $result = $this->userRepo->all($filters);

        if (isset($result['pagination'])) {
            return Response::cacheable($result['data'], 300, null, ['pagination' => $result['pagination']]);
        } else {
            return Response::cacheable($result['data'] ?? $result, 300);
        }
    }

    public function store() {
        $request = new \App\Requests\UserStoreRequest($this->getBody());
        $data = $request->validated();

        return $this->withTransaction(function () use ($data) {
            $id   = $this->userRepo->create($data);
            $user = $this->userRepo->findByIdInCurrentBranch($id);
            return Response::success($user, Messages::USER_CREATED, 201);
        });
    }

    public function update(string $id) {
        $id = $this->resolveId($id);
        $auth = $this->authService->user();
        $isSelf = $id === (int)$auth['id'];
        $isAdmin = $auth['role'] === 'admin';
        $targetUser = $this->userRepo->findByIdInCurrentBranch($id);
        if (!$targetUser) {
            return Response::notFound('User not found');
        }

        // Non-admin users can ONLY update their own profile
        if (!$isAdmin && !$isSelf) {
            \App\Helpers\Logger::warning('Unauthorized user update attempt', [
                'attacker_id' => $auth['id'],
                'target_id'   => $id,
                'role'         => $auth['role'],
            ]);
            return Response::error(Messages::ACCESS_DENIED, 403);
        }

        $request = new \App\Requests\UserUpdateRequest($this->getBody());
        $data = $request->validated();
        $passwordChanged = isset($data['password']) && $data['password'] !== '';

        if ($passwordChanged && $isSelf) {
            $currentPassword = $data['current_password'] ?? '';
            if ($currentPassword === '') {
                return Response::error(
                    'Current password is required to change your password',
                    422,
                    ['current_password' => ['Current password is required']]
                );
            }

            $passwordRecord = $this->userRepo->findForPasswordChangeInCurrentBranch($id);
            $passwordHash = $passwordRecord['password']
                ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
            if (!$passwordRecord || !password_verify($currentPassword, $passwordHash)) {
                return Response::error(
                    'Current password is incorrect',
                    422,
                    ['current_password' => ['Current password is incorrect']]
                );
            }
        }

        // Verification-only input must never reach persistence, logs, or responses.
        unset($data['current_password']);

        // Non-admin users cannot change role, is_active, or email
        if (!$isAdmin) {
            unset($data['role']);
            unset($data['is_active']);
            $data['email'] = $targetUser['email']; // prevent email changes by non-admins
        }

        $response = $this->withTransaction(function () use ($id, $data, $isSelf, $auth, $passwordChanged) {
            $this->userRepo->update($id, $data);
            if ($passwordChanged) {
                $this->userRepo->revokeAllSessions($id);
            }
            \App\Middleware\PermissionMiddleware::clearPermissionCache();

            // Audit log: track who updated whom
            \App\Helpers\AuditLog::logOrFail(
                (int) $auth['id'],
                $isSelf ? 'update_own_profile' : 'update_user',
                'user',
                $id,
                null,
                array_diff_key($data, ['password' => true])
            );

            if ($passwordChanged) {
                \App\Helpers\AuditLog::logOrFail(
                    (int) $auth['id'],
                    $isSelf ? 'password_changed_sessions_revoked' : 'admin_password_reset_sessions_revoked',
                    'user',
                    $id,
                    null,
                    ['sessions_revoked' => true]
                );
            }

            return Response::success(
                $this->userRepo->findByIdInCurrentBranch($id),
                Messages::USER_UPDATED,
                200,
                $passwordChanged
                    ? [
                        'sessions_revoked' => true,
                        'requires_reauthentication' => $isSelf,
                    ]
                    : []
            );
        });

        // The transaction must commit before expiring the browser's current cookies.
        if ($passwordChanged && $isSelf) {
            CookieHelper::clearAuthCookies();
        }

        return $response;
    }

    public function destroy(string $id) {
        $id = $this->resolveId($id);
        $auth = $this->authService->user();
        if ($id === $auth['id']) {
            return Response::error(Messages::CANNOT_DELETE_SELF, 400);
        }
        if (!$this->userRepo->findByIdInCurrentBranch($id)) {
            return Response::notFound('User not found');
        }
        return $this->withTransaction(function () use ($id, $auth) {
            $this->userRepo->delete($id);
            \App\Middleware\PermissionMiddleware::clearPermissionCache();

            \App\Helpers\AuditLog::logOrFail(
                (int) $auth['id'],
                'deactivate_user',
                'user',
                $id
            );

            return Response::success(null, Messages::USER_DELETED);
        });
    }
}

