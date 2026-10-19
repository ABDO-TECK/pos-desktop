<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Validator;
use App\Helpers\AuditLog;
use App\Helpers\Logger;
use App\Helpers\PasswordHasher;
use PDO;

/**
 * Local password recovery used by the trusted desktop recovery flow.
 *
 * This service deliberately has no HTTP endpoint. A forgotten-password reset
 * is a local operator action and must not become an unauthenticated remote
 * account-takeover primitive. The Electron main process invokes the CLI
 * command only after the user has confirmed the operation in the desktop UI.
 */
final class PasswordRecoveryService
{
    /**
     * Accounts disabled by migration 033 because they still used the shipped
     * predictable password. They may be recovered once, locally, by replacing
     * that password; intentionally disabled accounts must remain blocked.
     */
    private const LEGACY_DISABLED_ACCOUNTS = [
        'admin@pos.com' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        'cashier@pos.com' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Reset an account password and revoke every existing session.
     * Legacy accounts disabled by the security migration remain recoverable
     * locally only when their email and old predictable hash match the
     * migration marker; intentionally disabled accounts remain blocked.
     *
     * @return array{ok: bool, user_id?: int, reactivated?: bool, error?: string, errors?: array}
     */
    public function resetByEmail(string $email, string $password): array
    {
        // MySQL's normal email collation is case-insensitive. Normalize before
        // lookup as well so the legacy recovery marker works for equivalent
        // casing entered in the desktop form.
        $email = strtolower(trim($email));
        $validationErrors = Validator::validate(
            ['email' => $email, 'password' => $password],
            [
                'email' => 'required|string|email|max:254',
                'password' => 'required|string|max:256|strong_password',
            ]
        );
        if ($validationErrors !== []) {
            return ['ok' => false, 'error' => 'Invalid recovery details.', 'errors' => $validationErrors];
        }

        $statement = $this->db->prepare(
            'SELECT id, email, password, is_active, force_password_change
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        $legacyPasswordHash = self::LEGACY_DISABLED_ACCOUNTS[$email] ?? null;
        $isInactiveLegacyAccount = $user
            && (int) $user['is_active'] !== 1
            && (int) ($user['force_password_change'] ?? 0) === 1
            && $legacyPasswordHash !== null
            && hash_equals($legacyPasswordHash, (string) ($user['password'] ?? ''));
        if (!$user || ((int) $user['is_active'] !== 1 && !$isInactiveLegacyAccount)) {
            return ['ok' => false, 'error' => 'Active account not found.'];
        }

        $userId = (int) $user['id'];
        $reactivated = (int) $user['is_active'] !== 1;
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE users
                 SET password = :password, is_active = 1, force_password_change = 0
                 WHERE id = :id'
            )->execute([
                'password' => PasswordHasher::hash($password),
                'id' => $userId,
            ]);

            // A password reset must invalidate both short-lived and refresh
            // sessions, including sessions on other devices.
            $this->db->prepare('DELETE FROM tokens WHERE user_id = :user_id')
                ->execute(['user_id' => $userId]);
            $timestampExpression = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? 'UTC_TIMESTAMP()'
                : 'CURRENT_TIMESTAMP';
            $this->db->prepare(
                'UPDATE refresh_tokens
                 SET revoked_at = COALESCE(revoked_at, ' . $timestampExpression . ')
                 WHERE user_id = :user_id'
            )->execute(['user_id' => $userId]);

            // Password recovery is complete only if its security event is
            // durably recorded in the same transaction.
            AuditLog::logOrFail(null, 'local_password_recovery', 'user', $userId, null, [
                'sessions_revoked' => true,
                'reactivated' => $reactivated,
                'source' => 'desktop_recovery',
            ]);

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Logger::error('Local password recovery failed', Logger::exceptionContext($exception));
            throw $exception;
        }

        return ['ok' => true, 'user_id' => $userId, 'reactivated' => $reactivated];
    }
}
