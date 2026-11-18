<?php
declare(strict_types=1);

/**
 * Isolated Shell Delta Worker Plan CLI entrypoint.
 * Executed exclusively inside a compiled PHAR under Windows CLI.
 * Strictly avoids loading application bootstrap, router, config, dotenv, or database.
 * Pure in-memory plan projection communicating via Win32 named pipe (ZWP1 framing).
 *
 * CALLER PRECONDITION & ARCHITECTURAL TRUST BOUNDARIES:
 * 1. The compiled Native host (NativeRoot) MUST authenticate and pin the SHA-256
 *    hash of the source PHAR image before launching this worker process. The PHP
 *    worker process itself cannot prove the authenticity of its own executing binary.
 * 2. The Native host MUST enforce external job deadlines and verify peer process identity.
 * 3. Pure in-memory execution: zero filesystem writes, zero DB queries, zero process spawning.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

if (PHP_OS_FAMILY !== 'Windows') {
    fwrite(STDERR, "Execution requires Windows operating system.\n");
    exit(1);
}

if (PHP_INT_SIZE !== 8) {
    fwrite(STDERR, "Execution requires 64-bit PHP runtime.\n");
    exit(1);
}

if (!class_exists('Phar', false) || Phar::running(true) === '') {
    fwrite(STDERR, "Execution outside compiled PHAR is prohibited.\n");
    exit(1);
}

if (!isset($argv) || !is_array($argv) || count($argv) !== 3) {
    fwrite(STDERR, "Invalid argument count: expected exactly 'shell-worker-plan --endpoint=pos-worker-[hex]{32}'.\n");
    exit(1);
}

if ($argv[1] !== 'shell-worker-plan') {
    fwrite(STDERR, "Invalid command: expected 'shell-worker-plan'.\n");
    exit(1);
}

if (preg_match('/^--endpoint=(pos-worker-[a-f0-9]{32})$/D', $argv[2], $matches) !== 1) {
    fwrite(STDERR, "Invalid endpoint argument: expected '--endpoint=pos-worker-[a-f0-9]{32}'.\n");
    exit(1);
}
$endpointBasename = $matches[1];

$pharRoot = Phar::running(true);
$autoloadPath = $pharRoot . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    fwrite(STDERR, "Missing PHAR autoload dependency.\n");
    exit(1);
}
require_once $autoloadPath;

$certPath = $pharRoot . '/certs/update_public_key.pem';
if (!is_file($certPath)) {
    fwrite(STDERR, "Missing update public key certificate in PHAR.\n");
    exit(1);
}
$pubPem = @file_get_contents($certPath);
if ($pubPem === false || strlen($pubPem) === 0 || strlen($pubPem) > 16384) {
    fwrite(STDERR, "Invalid or oversized update public key certificate in PHAR.\n");
    exit(1);
}

// Instantiate pipeline purely in memory with fixed PHAR public key
$sigService = new App\Services\ManifestSignatureService(null, [$pubPem], false, false);
$manifestService = new App\Services\ShellDeltaManifestService($sigService);
$filePlanService = new App\Services\ShellDeltaWorkerFilePlanService($manifestService);
$sessionService = new App\Services\ShellDeltaWorkerPlanSessionService($filePlanService);

$exitCode = 1;
$transport = null;
$primaryException = null;

try {
    $transport = App\Services\ShellDeltaWorkerPipeTransportService::connect($endpointBasename);

    // Read exactly one request frame (bounded to 24 MiB)
    $frame = $transport->readFrame(App\Services\ShellDeltaWorkerPlanSessionService::MAX_REQUEST_BYTES);
    $replyPayload = $sessionService->handleRequest($frame['payload']);

    // Write canonical reply frame (ok=true or ok=false policy reject)
    $transport->writeFrame($replyPayload);

    // Await remote peer closure before exit (prevents premature teardown race)
    $transport->awaitPeerClose();

    $exitCode = 0;
} catch (Throwable $t) {
    $primaryException = $t;
    $exitCode = 1;
} finally {
    if ($transport !== null) {
        try {
            $transport->close();
        } catch (Throwable $closeException) {
            if ($primaryException !== null) {
                $primaryException = new RuntimeException(
                    $primaryException->getMessage() . ' | Close error: ' . $closeException->getMessage(),
                    0,
                    $primaryException
                );
            } else {
                $primaryException = $closeException;
            }
            $exitCode = 1;
        }
    }
}

if ($primaryException !== null) {
    $rawMsg = $primaryException->getMessage();
    if (function_exists('mb_substr')) {
        $diag = mb_substr($rawMsg, 0, 500, 'UTF-8');
    } else {
        $diag = substr($rawMsg, 0, 500);
    }
    @fwrite(STDERR, "shell-worker-plan: {$diag}\n");
}

exit($exitCode);
