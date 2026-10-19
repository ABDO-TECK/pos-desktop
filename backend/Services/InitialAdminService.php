<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\PasswordHasher;

/**
 * Creates the first local administrator for a brand-new database.
 *
 * A shared/default password is deliberately never stored in the schema or
 * shipped with the application. The generated password is returned only to
 * the trusted desktop bootstrap process and the account is marked so the
 * operator must choose a private password immediately after signing in.
 */
final class InitialAdminService
{
    private const EMAIL = 'admin@pos.local';
    private const NAME = 'Administrator';

    /**
     * @return array{created: bool, email?: string, name?: string, password?: string, force_password_change?: bool}
     */
    public function ensure(): array
    {
        $db = Database::getInstance();

        $existingUsers = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($existingUsers > 0) {
            return ['created' => false];
        }

        $password = $this->generateTemporaryPassword();

        $db->beginTransaction();
        try {
            // Re-check inside the transaction so a second bootstrap process
            // cannot create a second first administrator after the initial
            // count above.
            $existingUsers = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($existingUsers > 0) {
                $db->rollBack();
                return ['created' => false];
            }

            $statement = $db->prepare(
                'INSERT INTO users
                    (name, email, password, role, is_active, force_password_change)
                 VALUES
                    (:name, :email, :password, :role, 1, 1)'
            );
            $statement->execute([
                'name' => self::NAME,
                'email' => self::EMAIL,
                'password' => PasswordHasher::hash($password),
                'role' => 'admin',
            ]);

            $db->commit();
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        return [
            'created' => true,
            'email' => self::EMAIL,
            'name' => self::NAME,
            'password' => $password,
            'force_password_change' => true,
        ];
    }

    private function generateTemporaryPassword(): string
    {
        // Hex keeps the one-time credential easy to copy from a desktop
        // dialog while still providing 128 bits of randomness.
        return 'Pos-' . bin2hex(random_bytes(16));
    }
}
