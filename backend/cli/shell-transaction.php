<?php
declare(strict_types=1);

use App\Services\ManifestSignatureService;
use App\Services\ShellDeltaFileTransactionService;
use App\Services\ShellDeltaManifestService;
use App\Services\ShellUpdateHealthService;

// Only the supervisor's private PHP copy calls this interface. The CLI has no
// HTTP entry point, and every executable and trust key comes from that copy.
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Windows' || PHP_INT_SIZE !== 8 ||
    Phar::running(true) === '' || $argc !== 2) {
    exit(1);
}

try {
    $request = json_decode(stream_get_contents(STDIN, 65536), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($request)) {
        throw new RuntimeException('Invalid private shell transaction request.');
    }
    $phar = Phar::running(true);
    require $phar . '/vendor/autoload.php';
    $key = file_get_contents($phar . '/certs/update_public_key.pem', false, null, 0, 16385);
    if ($key === false || strlen($key) > 16384 || openssl_pkey_get_public($key) === false) {
        throw new RuntimeException('Packaged shell update trust key is unavailable.');
    }
    $manifests = new ShellDeltaManifestService(new ManifestSignatureService(null, [$key], false, false));
    $transactions = new ShellDeltaFileTransactionService($manifests);
    $command = $request['command'] ?? '';
    if ($command === 'data-proof') {
        $result = ['data_compatibility' => ShellUpdateHealthService::databaseProof()];
    } else {
        $root = $request['root'];
        $state = $request['state'];
        $operation = $request['operation'];
        $context = $request['context'];
        $result = match ($command) {
            'prepare' => (static function () use ($transactions, $manifests, $root, $state, $operation, $context, $request): array {
                $package = $request['package'];
                $raw = file_get_contents($package . '/' . ShellDeltaManifestService::MANIFEST_ASSET, false, null, 0, ShellDeltaManifestService::MAX_MANIFEST_SIZE + 1);
                $signature = file_get_contents($package . '/' . ShellDeltaManifestService::SIGNATURE_ASSET, false, null, 0, ShellDeltaManifestService::MAX_SIGNATURE_SIZE + 1);
                if (!is_string($raw) || !is_string($signature)) {
                    throw new RuntimeException('Shell delta manifest or signature could not be read.');
                }
                $verified = $manifests->verify($raw, trim($signature), $context);
                $descriptor = $verified['descriptor'] ?? null;
                if (!($verified['ok'] ?? false) || $descriptor['version'] !== $request['target'] ||
                    $descriptor['native_version'] !== $request['target_native'] ||
                    $descriptor['channel'] !== $context['channel']) {
                    throw new RuntimeException('Signed shell delta does not match the selected source, target and channel.');
                }
                // Bind independently copied PHP and PHAR inputs to the signed source
                // entries before the supervisor allows any product mutation.
                foreach ($request['images'] as $relative => $copy) {
                    $entry = $descriptor['source_inventory']['files'][$relative] ?? null;
                    if (!is_array($entry) || filesize($copy) !== $entry['size'] ||
                        hash_file('sha256', $copy) !== $entry['sha256']) {
                        throw new RuntimeException('Private shell worker image does not match the signed source.');
                    }
                }
                return $transactions->prepare($root, $state, $raw, trim($signature),
                    $package . '/' . ShellDeltaManifestService::PACKAGE_ASSET, $context, $operation);
            })(),
            'apply' => $transactions->apply($root, $state, $operation, $context),
            'recover' => $transactions->recover($root, $state, $operation, $context),
            'commit' => $transactions->commitAfterHealth($root, $state, $operation, $context, $request['target'], $request['digest']),
            'phase' => ['phase' => $transactions->operationPhase($root, $state, $operation)],
            default => throw new RuntimeException('Unknown shell transaction command.'),
        };
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
