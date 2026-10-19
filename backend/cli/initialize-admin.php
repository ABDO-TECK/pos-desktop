<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: CLI only.');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
\App\Helpers\ErrorHandler::register();
require_once dirname(__DIR__) . '/Config/config.php';

try {
    $result = (new \App\Services\InitialAdminService())->ensure();
    // The desktop launcher parses this one JSON line. Do not log or print the
    // generated password anywhere else.
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (\Throwable $exception) {
    $reference = bin2hex(random_bytes(8));
    \App\Helpers\Logger::error('Initial administrator setup failed', [
        'reference' => $reference,
        'exception' => get_class($exception),
        'code' => (int) $exception->getCode(),
    ]);
    fwrite(STDERR, "Initial administrator setup failed. Reference: {$reference}\n");
    exit(1);
}
