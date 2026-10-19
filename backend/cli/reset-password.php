<?php

declare(strict_types=1);

/**
 * Local desktop password recovery.
 *
 * The Electron main process sends one JSON object on STDIN so the new
 * password never appears in a process argument or application log:
 *   {"email":"user@example.com","password":"NewPassword1"}
 *
 * For manual recovery, pipe the same JSON object to this command. It must be
 * run on the machine hosting the desktop database; it is intentionally not a
 * web endpoint.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
\App\Helpers\ErrorHandler::register();
require dirname(__DIR__) . '/Config/config.php';

use App\Config\Database;
use App\Services\PasswordRecoveryService;

$rawInput = stream_get_contents(STDIN);
$payload = json_decode($rawInput ?: '', true);
if (!is_array($payload)) {
    fwrite(STDERR, "Password recovery input must be a JSON object on STDIN.\n");
    exit(2);
}

$email = is_string($payload['email'] ?? null) ? $payload['email'] : '';
$password = is_string($payload['password'] ?? null) ? $payload['password'] : '';

try {
    $result = (new PasswordRecoveryService(Database::getInstance()))
        ->resetByEmail($email, $password);
    if (!$result['ok']) {
        // Validation details are useful to the local UI, but never include the
        // supplied password or a database exception.
        fwrite(STDERR, json_encode([
            'ok' => false,
            'error' => $result['error'] ?? 'Password recovery failed.',
            'errors' => $result['errors'] ?? null,
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL);
        exit(1);
    }

    fwrite(STDOUT, json_encode(['ok' => true], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    $reference = bin2hex(random_bytes(8));
    \App\Helpers\Logger::error('Password recovery command failed', [
        'reference' => $reference,
        'exception' => get_class($exception),
    ]);
    fwrite(STDERR, "Password recovery failed. Reference: {$reference}\n");
    exit(1);
}
