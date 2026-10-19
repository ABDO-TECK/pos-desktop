<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
\App\Helpers\ErrorHandler::register();
require dirname(__DIR__) . '/Config/config.php';

use App\Config\Database;
use App\Helpers\PasswordHasher;

$email = filter_var(getenv('INITIAL_ADMIN_EMAIL') ?: '', FILTER_VALIDATE_EMAIL);
$password = (string) (getenv('INITIAL_ADMIN_PASSWORD') ?: '');
$name = trim((string) (getenv('INITIAL_ADMIN_NAME') ?: 'Administrator'));

if ($email === false || $name === '' || strlen($password) < 14) {
    fwrite(STDERR, "Set INITIAL_ADMIN_EMAIL, INITIAL_ADMIN_PASSWORD (14+ characters), and optionally INITIAL_ADMIN_NAME.\n");
    exit(1);
}

if (preg_match('/^(?:password|admin|123456|qwerty)/i', $password)) {
    fwrite(STDERR, "The bootstrap password is too predictable.\n");
    exit(1);
}

$db = Database::getInstance();
$db->beginTransaction();

try {
    $existingAdmins = (int) $db->query(
        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"
    )->fetchColumn();

    if ($existingAdmins !== 0) {
        throw new RuntimeException('An active administrator already exists.');
    }

    $statement = $db->prepare(
        'INSERT INTO users (name, email, password, role, is_active, force_password_change)
         VALUES (:name, :email, :password, :role, 1, 0)
         ON DUPLICATE KEY UPDATE
             name = VALUES(name),
             password = VALUES(password),
             role = VALUES(role),
             is_active = 1,
             force_password_change = 0'
    );
    $statement->execute([
        'name' => $name,
        'email' => $email,
        'password' => PasswordHasher::hash($password),
        'role' => 'admin',
    ]);

    $db->commit();
    fwrite(STDOUT, "Administrator bootstrapped successfully. Remove the bootstrap environment variables now.\n");
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
