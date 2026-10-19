<?php

namespace App\Models;

use App\Config\Database;
use App\Helpers\PasswordHasher;
use PDO;


class User {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare('SELECT id, name, email, role, is_active, force_password_change, created_at FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT id, name, email, role, is_active, force_password_change, branch_id, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Authentication-only lookup. Callers must remove the password field before
     * returning or logging the record.
     */
    public function findForAuthentication(string $email): ?array {
        $stmt = $this->db->prepare(
            'SELECT id, name, email, password, role, is_active, force_password_change, branch_id, created_at
             FROM users WHERE email = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void {
        $stmt = $this->db->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $userId]);
    }

    public function findByIdInCurrentBranch(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT id, name, email, role, is_active, force_password_change, branch_id, created_at
             FROM users WHERE id = ? AND branch_id = ?'
        );
        $stmt->execute([$id, \App\Services\AuthService::getGlobalBranchId()]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Password-change-only lookup. The returned hash must never be serialized or logged.
     */
    public function findForPasswordChangeInCurrentBranch(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT id, password
             FROM users
             WHERE id = ? AND branch_id = ?
             LIMIT 1'
        );
        $stmt->execute([$id, \App\Services\AuthService::getGlobalBranchId()]);
        return $stmt->fetch() ?: null;
    }

    public function createToken(int $userId): string {
        $token     = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + TOKEN_LIFETIME);
        $stmt = $this->db->prepare('INSERT INTO tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $hashedToken, $expiresAt]);
        return $token;
    }

    public function deleteToken(string $token): void {
        $stmt = $this->db->prepare('DELETE FROM tokens WHERE token = ?');
        $stmt->execute([hash('sha256', $token)]);
    }

    public function extendToken(string $token, string $expiresAt): void {
        $stmt = $this->db->prepare('UPDATE tokens SET expires_at = ? WHERE token = ?');
        $stmt->execute([$expiresAt, hash('sha256', $token)]);
    }

    public function createRefreshToken(int $userId, ?string $familyId = null): string {
        $token = bin2hex(random_bytes(64));
        $hashedToken = hash('sha256', $token);
        $familyId ??= bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + REFRESH_TOKEN_LIFETIME);
        $stmt = $this->db->prepare(
            'INSERT INTO refresh_tokens (user_id, token, family_id, expires_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $hashedToken, $familyId, $expiresAt]);
        return $token;
    }

    /**
     * Atomically consume a refresh token and issue its replacement pair.
     *
     * @return array{status: 'ok', refresh_token: string, access_token: string, user_id: int}|array{status: 'invalid'|'reused'}
     */
    public function rotateRefreshToken(string $token): array
    {
        $tokenHash = hash('sha256', $token);
        $this->db->beginTransaction();

        try {
            $lockingClause = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' FOR UPDATE'
                : '';
            $stmt = $this->db->prepare(
                'SELECT rt.*, u.is_active
                 FROM refresh_tokens rt
                 JOIN users u ON u.id = rt.user_id
                 WHERE rt.token = ?' . $lockingClause
            );
            $stmt->execute([$tokenHash]);
            $record = $stmt->fetch();

            if (!$record || !$record['is_active']) {
                $this->db->commit();
                return ['status' => 'invalid'];
            }

            if ($record['used_at'] !== null || $record['revoked_at'] !== null) {
                $this->db->prepare(
                    'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE family_id = ?'
                )->execute([$record['family_id']]);
                $this->db->prepare('DELETE FROM tokens WHERE user_id = ?')
                    ->execute([$record['user_id']]);
                $this->db->commit();
                return ['status' => 'reused'];
            }

            $expiresAt = new \DateTimeImmutable($record['expires_at'], new \DateTimeZone('UTC'));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($expiresAt <= $now) {
                $this->db->commit();
                return ['status' => 'invalid'];
            }

            $newRefreshToken = bin2hex(random_bytes(64));
            $newRefreshHash = hash('sha256', $newRefreshToken);
            $refreshExpiry = gmdate('Y-m-d H:i:s', time() + REFRESH_TOKEN_LIFETIME);

            $this->db->prepare(
                'INSERT INTO refresh_tokens (user_id, token, family_id, expires_at)
                 VALUES (?, ?, ?, ?)'
            )->execute([
                $record['user_id'],
                $newRefreshHash,
                $record['family_id'],
                $refreshExpiry,
            ]);

            $this->db->prepare(
                'UPDATE refresh_tokens
                 SET used_at = UTC_TIMESTAMP(), replaced_by_token = ?
                 WHERE id = ?'
            )->execute([$newRefreshHash, $record['id']]);

            $accessToken = bin2hex(random_bytes(32));
            $this->db->prepare(
                'INSERT INTO tokens (user_id, token, expires_at) VALUES (?, ?, ?)'
            )->execute([
                $record['user_id'],
                hash('sha256', $accessToken),
                gmdate('Y-m-d H:i:s', time() + TOKEN_LIFETIME),
            ]);

            $this->db->commit();
            return [
                'status' => 'ok',
                'refresh_token' => $newRefreshToken,
                'access_token' => $accessToken,
                'user_id' => (int) $record['user_id'],
            ];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function findRefreshToken(string $token): ?array {
        $stmt = $this->db->prepare(
            'SELECT rt.*, u.is_active FROM refresh_tokens rt
             JOIN users u ON u.id = rt.user_id
             WHERE rt.token = ?
               AND rt.expires_at > UTC_TIMESTAMP()
               AND rt.used_at IS NULL
               AND rt.revoked_at IS NULL'
        );
        $stmt->execute([hash('sha256', $token)]);
        return $stmt->fetch() ?: null;
    }

    public function deleteRefreshToken(string $token): void {
        $this->db->prepare('DELETE FROM refresh_tokens WHERE token = ?')->execute([hash('sha256', $token)]);
    }

    /**
     * Invalidate every access token and revoke every refresh-token family for a user.
     * Refresh rows are retained so reuse detection and the security audit trail remain intact.
     */
    public function revokeAllSessions(int $userId): void {
        $this->db->prepare('DELETE FROM tokens WHERE user_id = ?')->execute([$userId]);
        $this->db->prepare(
            'UPDATE refresh_tokens
             SET revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP)
             WHERE user_id = ?'
        )->execute([$userId]);
    }

    public function all(array $filters = []): array {
        $branchId = \App\Services\AuthService::getGlobalBranchId();
        $sql = 'SELECT id, name, email, role, is_active, force_password_change, branch_id, created_at
                FROM users WHERE branch_id = :branch_id ORDER BY id DESC';
        
        if (!empty($filters['page']) && !empty($filters['limit'])) {
            $page  = max(1, (int)$filters['page']);
            $limit = max(1, min(100, (int)$filters['limit']));
            $offset = ($page - 1) * $limit;
            
            $countStmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE branch_id = ?');
            $countStmt->execute([$branchId]);
            $total = $countStmt->fetchColumn();
            
            $sql .= " LIMIT :pag_limit OFFSET :pag_offset";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':branch_id', $branchId, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetchAll();
            
            return [
                'data'       => $data,
                'pagination' => [
                    'total' => (int) $total,
                    'limit' => $limit,
                    'page'  => $page,
                    'pages' => (int) ceil($total / $limit),
                ]
            ];
        }

        $sql .= ' LIMIT 100';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['branch_id' => $branchId]);
        return ['data' => $stmt->fetchAll()];
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO users (name, email, password, role, force_password_change, branch_id)
             VALUES (:name, :email, :password, :role, :force_pw, :branch_id)'
        );
        $stmt->execute([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => PasswordHasher::hash($data['password']),
            'role'     => $data['role'] ?? 'cashier',
            'force_pw' => 1,
            'branch_id' => \App\Services\AuthService::getGlobalBranchId(),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $fields = ['name = :name', 'email = :email'];
        $params = [
            'name'  => $data['name'],
            'email' => $data['email'],
            'id'    => $id,
            'branch_id' => \App\Services\AuthService::getGlobalBranchId(),
        ];

        if (array_key_exists('role', $data)) {
            $fields[] = 'role = :role';
            $params['role'] = $data['role'];
        }

        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = $data['is_active'];
        }

        if (!empty($data['password'])) {
            $fields[] = 'password = :password';
            $fields[] = 'force_password_change = 0';
            $params['password'] = PasswordHasher::hash($data['password']);
        }

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id AND branch_id = :branch_id';
        $this->db->prepare($sql)->execute($params);
    }

    /**
     * Soft-delete a user by deactivating their account.
     * Preserves the user record for audit trail and foreign key integrity.
     * Also invalidates all active tokens and refresh tokens for the user.
     */
    public function delete(int $id): void {
        // Deactivate the user (soft delete)
        $stmt = $this->db->prepare('UPDATE users SET is_active = 0 WHERE id = ? AND branch_id = ?');
        $stmt->execute([$id, \App\Services\AuthService::getGlobalBranchId()]);
        if ($stmt->rowCount() === 0) {
            return;
        }

        // Revoke all active sessions for this user
        $this->revokeAllSessions($id);
    }

    /**
     * Delete expired access and refresh tokens.
     *
     * @param int $gracePeriodSeconds Grace period after expiry before purge (default: 3600 = 1 hour)
     * @return array{access: int, refresh: int} Number of deleted tokens
     */
    public function purgeExpiredTokens(int $gracePeriodSeconds = 3600): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(0, $gracePeriodSeconds));

        $stmt = $this->db->prepare('DELETE FROM tokens WHERE expires_at IS NOT NULL AND expires_at < ?');
        $stmt->execute([$cutoff]);
        $accessDeleted = $stmt->rowCount();

        $stmt = $this->db->prepare('DELETE FROM refresh_tokens WHERE expires_at < ?');
        $stmt->execute([$cutoff]);
        $refreshDeleted = $stmt->rowCount();

        return ['access' => $accessDeleted, 'refresh' => $refreshDeleted];
    }
}
