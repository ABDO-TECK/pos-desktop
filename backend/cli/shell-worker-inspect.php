<?php
declare(strict_types=1);

/**
 * Isolated Shell Worker Inspection CLI entrypoint.
 * Executed exclusively inside a compiled PHAR under Windows CLI.
 * Strictly avoids loading application bootstrap, router, config, dotenv, or database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

if (!class_exists('Phar', false) || Phar::running(true) === '') {
    $failJson = json_encode([
        'ok' => false,
        'operation' => 'inspect_snapshot',
        'inspection_format' => 1,
        'error' => 'Execution outside compiled PHAR is prohibited.',
        'can_apply' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    fwrite(STDOUT, $failJson);
    fwrite(STDERR, "Execution outside compiled PHAR is prohibited.\n");
    exit(1);
}

try {
    $pharRoot = Phar::running(true);
    $autoloadPath = $pharRoot . '/vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        throw new RuntimeException("Missing PHAR autoload dependency.");
    }
    require_once $autoloadPath;

    $parsed = App\Services\ShellDeltaWorkerInspectionService::parseCliArgs($argv);

    $service = new App\Services\ShellDeltaWorkerInspectionService();
    $result = $service->inspect(
        $parsed['product_root'],
        $parsed['state_base'],
        $parsed['snapshot_id'],
        $parsed['snapshot_sha256'],
        $parsed['installed_context']
    );

    $jsonOut = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonOut === false) {
        throw new RuntimeException("Failed to encode inspection result JSON.");
    }

    echo $jsonOut . "\n";
    exit(0);
} catch (Throwable $e) {
    $rawMsg = $e->getMessage();
    if (function_exists('mb_substr')) {
        $errorMessage = mb_substr($rawMsg, 0, 500, 'UTF-8');
    } else {
        $errorMessage = substr($rawMsg, 0, 500);
    }

    $errorPayload = [
        'ok' => false,
        'operation' => 'inspect_snapshot',
        'inspection_format' => 1,
        'error' => $errorMessage,
        'can_apply' => false,
    ];

    $jsonOut = json_encode($errorPayload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonOut === false) {
        $jsonOut = '{"ok":false,"operation":"inspect_snapshot","inspection_format":1,"error":"Internal inspection error","can_apply":false}';
    }

    echo $jsonOut . "\n";
    exit(1);
}
