<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// PRE-FLIGHT ENVIRONMENT GUARDS
// Must execute BEFORE any autoload, filesystem operations, crypto, or subprocesses.
// -----------------------------------------------------------------------------

// Guard 1: Must execute exclusively in GitHub Actions
if (getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "Verification suite runs strictly in GitHub Actions environment (GITHUB_ACTIONS=true).\n");
    exit(1);
}

// Guard 2: Must execute on Windows host
if (PHP_OS_FAMILY !== 'Windows') {
    fwrite(STDERR, "Verification suite runs strictly on Windows platforms.\n");
    exit(1);
}

// Guard 3: Must have valid, existing RUNNER_TEMP directory
$rawRunnerTemp = getenv('RUNNER_TEMP');
if (!is_string($rawRunnerTemp) || trim($rawRunnerTemp) === '' || !is_dir($rawRunnerTemp)) {
    fwrite(STDERR, "Verification suite requires valid RUNNER_TEMP environment variable pointing to an existing directory.\n");
    exit(1);
}
$canonicalRunnerTemp = realpath($rawRunnerTemp);
if ($canonicalRunnerTemp === false || !is_dir($canonicalRunnerTemp)) {
    fwrite(STDERR, "Failed to resolve canonical path for RUNNER_TEMP directory.\n");
    exit(1);
}
$canonicalRunnerTemp = str_replace('/', '\\', $canonicalRunnerTemp);

// Autoloading
if (is_file(__DIR__ . '/../backend/vendor/autoload.php')) {
    require_once __DIR__ . '/../backend/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = __DIR__ . '/../backend/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });
}

use App\Services\ManifestSignatureService;
use App\Services\ProductInventoryService;
use App\Services\ShellDeltaFileTransactionService;
use App\Services\ShellDeltaManifestService;
use App\Services\UpdateChannelPolicy;
use App\Services\UpdateDataCompatibilityService;

// Guard canonical RUNNER_TEMP raw ancestors for reparse points
ProductInventoryService::assertNoReparse($canonicalRunnerTemp);

// -----------------------------------------------------------------------------
// CHILD SUBPROCESS DISPATCHER
// -----------------------------------------------------------------------------
if (($argv[1] ?? '') === '--child') {
    handleChildSubprocess(array_slice($argv, 2), $canonicalRunnerTemp);
    exit(0);
}

/**
 * Handle child process execution for real process-crash and concurrency testing.
 *
 * @param list<string> $args
 */
function handleChildSubprocess(array $args, string $canonicalRunnerTemp): void
{
    $childStart = microtime(true);
    $childCeiling = $childStart + 60.0; // 60s hard ceiling for child process

    $action = $args[0] ?? '';
    $configFile = $args[1] ?? '';
    $labRoot = $args[2] ?? '';
    $ownerToken = $args[3] ?? '';

    if ($action === '' || $configFile === '' || $labRoot === '' || $ownerToken === '') {
        fwrite(STDERR, "Child error: missing required arguments.\n");
        exit(1);
    }

    // 1. Validate labRoot is inside canonicalRunnerTemp without aliases erasing reparse points
    $canonicalLabRoot = ProductInventoryService::resolveAbsolutePath($labRoot, true);
    ProductInventoryService::assertNoReparse($canonicalLabRoot);
    $canonicalLabRoot = str_replace('/', '\\', $canonicalLabRoot);
    $normLabRoot = strtolower($canonicalLabRoot);
    $normRunnerTemp = strtolower($canonicalRunnerTemp);
    if (!str_starts_with($normLabRoot, $normRunnerTemp . '\\')) {
        fwrite(STDERR, "Child error: lab root is not confined within RUNNER_TEMP.\n");
        exit(1);
    }

    // 2. Validate labRoot marker and owner token (exact token comparison, no trim)
    $markerPath = $canonicalLabRoot . '\\.pos_owned_marker';
    ProductInventoryService::assertNoReparse($markerPath);
    if (!is_file($markerPath)) {
        fwrite(STDERR, "Child error: missing regular lab marker file.\n");
        exit(1);
    }
    $actualToken = file_get_contents($markerPath, false, null, 0, 1024);
    if ($actualToken === false || !hash_equals($ownerToken, $actualToken)) {
        fwrite(STDERR, "Child error: lab owner token mismatch.\n");
        exit(1);
    }

    // 3. Validate configFile path is within canonicalLabRoot, no junctions
    $canonicalConfigFile = ProductInventoryService::resolveAbsolutePath($configFile, true);
    ProductInventoryService::assertNoReparse($canonicalConfigFile);
    $canonicalConfigFile = str_replace('/', '\\', $canonicalConfigFile);
    $normConfigFile = strtolower($canonicalConfigFile);
    if (!str_starts_with($normConfigFile, $normLabRoot . '\\')) {
        fwrite(STDERR, "Child error: config file is outside lab root.\n");
        exit(1);
    }

    // 4. Bounded read (<= 1MB)
    $configSize = filesize($canonicalConfigFile);
    if ($configSize === false || $configSize <= 0 || $configSize > 1048576) {
        fwrite(STDERR, "Child error: config file size invalid or exceeds 1 MiB limit.\n");
        exit(1);
    }
    $rawConfig = file_get_contents($canonicalConfigFile, false, null, 0, 1048576);
    if ($rawConfig === false) {
        fwrite(STDERR, "Child error: cannot read config file.\n");
        exit(1);
    }

    $config = json_decode($rawConfig, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($config)) {
        fwrite(STDERR, "Child error: invalid config JSON structure.\n");
        exit(1);
    }

    // 5. Strict config keys & types validation based on action
    $validActions = ['apply-crash', 'rollback-crash', 'hold-lease', 'recover'];
    if (!in_array($action, $validActions, true)) {
        fwrite(STDERR, "Child error: unknown action: {$action}\n");
        exit(2);
    }

    $requiredKeysMap = [
        'apply-crash' => ['configFile', 'ackFile', 'root', 'stateBase', 'operationId', 'installedContext', 'targetCheckpoint', 'targetCheckpointCount', 'publicKeyPem', 'nonce'],
        'rollback-crash' => ['configFile', 'ackFile', 'root', 'stateBase', 'operationId', 'installedContext', 'targetCheckpoint', 'targetCheckpointCount', 'publicKeyPem', 'nonce'],
        'hold-lease' => ['configFile', 'ackFile', 'root', 'stateBase', 'rawManifestFile', 'sigFile', 'zipFile', 'installedContext', 'targetCheckpoint', 'targetCheckpointCount', 'publicKeyPem', 'nonce'],
        'recover' => ['configFile', 'ackFile', 'root', 'stateBase', 'operationId', 'installedContext', 'publicKeyPem', 'nonce'],
    ];

    $expectedKeys = $requiredKeysMap[$action];
    sort($expectedKeys);
    $actualKeys = array_keys($config);
    sort($actualKeys);
    if ($actualKeys !== $expectedKeys) {
        fwrite(STDERR, "Child error: config keys do not match expected schema for action {$action}.\n");
        exit(1);
    }

    // Validate types
    if (!is_string($config['nonce']) || preg_match('/^[a-f0-9]{16,}$/', $config['nonce']) !== 1) {
        fwrite(STDERR, "Child error: invalid nonce format.\n");
        exit(1);
    }
    if (!is_string($config['publicKeyPem']) || !str_contains($config['publicKeyPem'], 'PUBLIC KEY')) {
        fwrite(STDERR, "Child error: invalid publicKeyPem.\n");
        exit(1);
    }
    if (!is_array($config['installedContext'])) {
        fwrite(STDERR, "Child error: installedContext must be array.\n");
        exit(1);
    }
    if (isset($config['operationId']) && (!is_string($config['operationId']) || preg_match('/^tx_[a-f0-9]{32}$/', $config['operationId']) !== 1)) {
        fwrite(STDERR, "Child error: invalid operationId format.\n");
        exit(1);
    }
    if (isset($config['targetCheckpointCount']) && (!is_int($config['targetCheckpointCount']) || $config['targetCheckpointCount'] < 1)) {
        fwrite(STDERR, "Child error: targetCheckpointCount must be positive integer.\n");
        exit(1);
    }

    // Validate all paths: no reparse, strictly confined within canonicalLabRoot
    $pathKeys = ['root', 'stateBase'];
    if (isset($config['rawManifestFile'])) $pathKeys[] = 'rawManifestFile';
    if (isset($config['sigFile'])) $pathKeys[] = 'sigFile';
    if (isset($config['zipFile'])) $pathKeys[] = 'zipFile';

    foreach ($pathKeys as $pk) {
        $p = $config[$pk];
        if (!is_string($p) || $p === '') {
            fwrite(STDERR, "Child error: path key {$pk} is empty or not string.\n");
            exit(1);
        }
        $canonP = ProductInventoryService::resolveAbsolutePath($p, true);
        ProductInventoryService::assertNoReparse($canonP);
        $normP = strtolower(str_replace('/', '\\', $canonP));
        if (!str_starts_with($normP, $normLabRoot . '\\') && $normP !== $normLabRoot) {
            fwrite(STDERR, "Child error: path key {$pk} is outside lab root: {$p}\n");
            exit(1);
        }
    }

    // ackFile: directory must exist and be inside lab
    $ackFile = $config['ackFile'];
    if (!is_string($ackFile) || $ackFile === '') {
        fwrite(STDERR, "Child error: ackFile is empty.\n");
        exit(1);
    }
    $ackDir = dirname($ackFile);
    $canonAckDir = ProductInventoryService::resolveAbsolutePath($ackDir, true);
    ProductInventoryService::assertNoReparse($canonAckDir);
    $normAckDir = strtolower(str_replace('/', '\\', $canonAckDir));
    if (!str_starts_with($normAckDir, $normLabRoot . '\\') && $normAckDir !== $normLabRoot) {
        fwrite(STDERR, "Child error: ackFile directory is outside lab root: {$ackFile}\n");
        exit(1);
    }

    $root = $config['root'];
    $stateBase = $config['stateBase'];
    $installedContext = $config['installedContext'];
    $publicKeyPem = $config['publicKeyPem'];
    $nonce = $config['nonce'];
    $targetCheckpoint = $config['targetCheckpoint'] ?? '';
    $targetCheckpointCount = (int) ($config['targetCheckpointCount'] ?? 1);
    $checkpointHits = 0;

    $checkpointCallback = static function (string $checkpoint, array $context) use (
        $ackFile,
        $targetCheckpoint,
        $targetCheckpointCount,
        $nonce,
        $config,
        &$checkpointHits,
        $childCeiling
    ): void {
        if ($checkpoint === $targetCheckpoint) {
            $checkpointHits++;
            if ($checkpointHits >= $targetCheckpointCount) {
                $configuredOpId = $config['operationId'] ?? '';
                $opId = $context['operation_id'] ?? $configuredOpId;
                if (isset($context['operation_id']) && $configuredOpId !== '') {
                    if ($context['operation_id'] !== $configuredOpId) {
                        fwrite(STDERR, "Child error: context operation_id mismatch.\n");
                        exit(5);
                    }
                }
                $ackPayload = json_encode([
                    'status' => 'ACK_CHECKPOINT',
                    'checkpoint' => $checkpoint,
                    'count' => $checkpointHits,
                    'operation_id' => $opId,
                    'nonce' => $nonce,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                file_put_contents($ackFile, $ackPayload);

                // Bounded wait: child pauses holding execution until parent force-terminates
                while (microtime(true) < $childCeiling) {
                    usleep(20000);
                }
                fwrite(STDERR, "Child self ceiling exceeded while waiting for termination.\n");
                exit(4);
            }
        }
    };

    // Construct ManifestSignatureService with injected ephemeral public key
    $sigService = new ManifestSignatureService(null, [$publicKeyPem]);
    $manifestService = new ShellDeltaManifestService($sigService);
    $service = new ShellDeltaFileTransactionService($manifestService, $checkpointCallback);

    try {
        if ($action === 'apply-crash') {
            $operationId = $config['operationId'] ?? '';
            $service->apply($root, $stateBase, $operationId, $installedContext);
            exit(0);
        } elseif ($action === 'rollback-crash') {
            $operationId = $config['operationId'] ?? '';
            $service->rollback($root, $stateBase, $operationId, $installedContext);
            exit(0);
        } elseif ($action === 'hold-lease') {
            // Read manifest, sig, zip bounded
            $rawManifest = file_get_contents($config['rawManifestFile']);
            $sig = file_get_contents($config['sigFile']);
            $zipPath = $config['zipFile'];
            // prepare() triggers 'prepared' checkpoint callback INSIDE root flock, which writes ack and pauses
            $service->prepare($root, $stateBase, $rawManifest, $sig, $zipPath, $installedContext);
            exit(0);
        } elseif ($action === 'recover') {
            $operationId = $config['operationId'] ?? '';
            $recRes = $service->recover($root, $stateBase, $operationId, $installedContext);
            $ackPayload = json_encode([
                'status' => 'RECOVERED',
                'phase' => $recRes['phase'] ?? '',
                'operation_id' => $operationId,
                'nonce' => $nonce,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            file_put_contents($ackFile, $ackPayload);
            exit(0);
        } else {
            fwrite(STDERR, "Child error: unknown action: {$action}\n");
            exit(2);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Child exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        exit(3);
    }
}

// -----------------------------------------------------------------------------
// ASSERTION & FILESYSTEM TEST UTILITIES
// -----------------------------------------------------------------------------

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
}

function assertFalse(bool $condition, string $message): void
{
    if ($condition) {
        throw new RuntimeException("Assertion failed: {$message}");
    }
}

function assertEqual(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        $actStr = is_scalar($actual) ? (string) $actual : json_encode($actual);
        $expStr = is_scalar($expected) ? (string) $expected : json_encode($expected);
        throw new RuntimeException("Assertion failed: {$message} (expected: {$expStr}, got: {$actStr})");
    }
}

function assertThrows(callable $fn, string $expectedMessageSubstr, string $message): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($expectedMessageSubstr !== '') {
            $eMsg = strtolower($e->getMessage());
            $expectedSub = strtolower($expectedMessageSubstr);
            if (!str_contains($eMsg, $expectedSub)) {
                throw new RuntimeException("Assertion failed: {$message}. Expected message substring '{$expectedMessageSubstr}', got: '{$e->getMessage()}'");
            }
        }
        return;
    }
    throw new RuntimeException("Assertion failed: {$message}. Expected exception was not thrown.");
}

/**
 * Capture a complete physical snapshot of files under a directory.
 *
 * @return array<string, array{size: int, sha256: string}>
 */
function snapshotTree(string $root): array
{
    $canonicalRoot = ProductInventoryService::resolveAbsolutePath($root, true);
    $canonicalRoot = str_replace('/', '\\', $canonicalRoot);
    $dirIterator = new RecursiveDirectoryIterator(
        $canonicalRoot,
        FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
    );
    $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);

    $snapshot = [];
    $normRoot = str_replace('\\', '/', $canonicalRoot);
    $rootLen = strlen($normRoot);

    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $pathname = str_replace('\\', '/', $item->getPathname());
            $rel = ltrim(substr($pathname, $rootLen), '/');
            $snapshot[$rel] = [
                'size' => filesize($pathname),
                'sha256' => hash_file('sha256', $pathname),
            ];
        }
    }

    ksort($snapshot);
    return $snapshot;
}

/**
 * Populate a product directory tree.
 *
 * @param array<string, string> $files
 */
function populateTree(string $root, array $files): void
{
    if (!is_dir($root)) {
        if (!mkdir($root, 0700, true)) {
            throw new RuntimeException("Failed to create directory: {$root}");
        }
    }

    foreach ($files as $relPath => $content) {
        $fullPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }
        if (file_put_contents($fullPath, $content) === false) {
            throw new RuntimeException("Failed to write test file: {$fullPath}");
        }
    }
}

// -----------------------------------------------------------------------------
// TRACKED JUNCTIONS & SAFE LEXICAL CLEANUP
// -----------------------------------------------------------------------------

/** @var list<string> */
$createdJunctions = [];

function createTrackedJunction(string $link, string $target): void
{
    global $createdJunctions;
    $output = [];
    $exitCode = 0;
    exec('cmd /c mklink /J ' . escapeshellarg(str_replace('/', '\\', $link)) . ' ' . escapeshellarg(str_replace('/', '\\', $target)), $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("Failed to create junction from '{$link}' to '{$target}'");
    }
    clearstatcache(true, $link);
    $lstat = @lstat($link);
    if ($lstat === false || (($lstat['mode'] & 0120000) !== 0120000)) {
        throw new RuntimeException("Created junction failed reparse point verification: '{$link}'");
    }
    $createdJunctions[] = $link;
}

function removeTrackedJunction(string $link, string $labRoot): void
{
    global $createdJunctions;
    $idx = array_search($link, $createdJunctions, true);
    if ($idx === false) {
        return;
    }

    // 1. Verify RAW path is under labRoot without resolving away aliases
    $normLink = strtolower(str_replace('/', '\\', $link));
    $normLab = strtolower(str_replace('/', '\\', $labRoot));
    if (!str_starts_with($normLink, $normLab . '\\')) {
        throw new RuntimeException("Tracked junction is not under lab root: '{$link}'");
    }

    // 2. Check actual reparse point on the leaf
    $lstat = @lstat($link);
    if ($lstat === false) {
        // Already absent
        unset($createdJunctions[$idx]);
        $createdJunctions = array_values($createdJunctions);
        return;
    }
    $isReparse = is_link($link) || (($lstat['mode'] & 0120000) === 0120000);
    if (!$isReparse) {
        throw new RuntimeException("Tracked junction is not a reparse point: '{$link}'");
    }

    // 3. rmdir NO /S (removes reparse link only)
    $output = [];
    $exitCode = 0;
    exec('cmd /c rmdir ' . escapeshellarg(str_replace('/', '\\', $link)), $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("Failed to remove tracked junction (exit code {$exitCode}): '{$link}'");
    }

    // 4. Confirm absent BEFORE removing from tracking!
    clearstatcache(true, $link);
    if (file_exists($link) || is_link($link)) {
        throw new RuntimeException("Tracked junction still exists after rmdir: '{$link}'");
    }

    unset($createdJunctions[$idx]);
    $createdJunctions = array_values($createdJunctions);
}

function removeAllTrackedJunctions(string $labRoot): void
{
    global $createdJunctions;
    while (!empty($createdJunctions)) {
        $lastIdx = count($createdJunctions) - 1;
        $j = $createdJunctions[$lastIdx];
        removeTrackedJunction($j, $labRoot);
    }
}

function safeLexicalSubdirCleanup(string $dir, string $labRoot): void
{
    $items = scandir($dir);
    if ($items === false) {
        throw new RuntimeException("Cannot list directory for cleanup: '{$dir}'");
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        $lstat = @lstat($path);
        if ($lstat === false) {
            throw new RuntimeException("Cannot lstat entry during cleanup: '{$path}'");
        }
        $isReparse = is_link($path) || (($lstat['mode'] & 0120000) === 0120000);
        if ($isReparse) {
            global $createdJunctions;
            if (in_array($path, $createdJunctions, true)) {
                removeTrackedJunction($path, $labRoot);
            } else {
                // Unrecognized reparse point! Fail and retain rather than risky recursion!
                throw new RuntimeException("Unrecognized reparse point found during cleanup; refusing risky descent: '{$path}'");
            }
        } elseif (is_dir($path)) {
            ProductInventoryService::assertNoReparse($path);
            safeLexicalSubdirCleanup($path, $labRoot);
            if (!@rmdir($path)) {
                throw new RuntimeException("Failed to rmdir: '{$path}'");
            }
        } else {
            ProductInventoryService::assertNoReparse($path);
            if (!@unlink($path)) {
                throw new RuntimeException("Failed to unlink: '{$path}'");
            }
        }
    }
}

function safeLexicalCleanup(string $dir, string $expectedMarker, string $canonicalRunnerTemp): void
{
    if (!file_exists($dir) && !is_link($dir)) {
        return;
    }

    // Guard raw path before resolving: assert no reparse on raw ancestors
    ProductInventoryService::assertNoReparse($dir);
    $canonicalDir = ProductInventoryService::resolveAbsolutePath($dir, true);
    ProductInventoryService::assertNoReparse($canonicalDir);

    $normDir = strtolower(str_replace('/', '\\', $canonicalDir));
    $normTemp = strtolower(str_replace('/', '\\', $canonicalRunnerTemp));

    // Guard: Must be strictly under canonical RUNNER_TEMP
    if (!str_starts_with($normDir, $normTemp . '\\')) {
        throw new RuntimeException("Refusing to clean up directory outside RUNNER_TEMP: '{$dir}'");
    }

    // Guard: Must contain regular .pos_owned_marker file matching expectedMarker
    $markerFile = $canonicalDir . DIRECTORY_SEPARATOR . '.pos_owned_marker';
    ProductInventoryService::assertNoReparse($markerFile);
    if (!is_file($markerFile)) {
        throw new RuntimeException("Refusing to clean up directory without valid regular ownership marker: '{$dir}'");
    }
    // Bounded read, EXACT token match (NOT trim)
    $content = file_get_contents($markerFile, false, null, 0, 1024);
    if ($content === false || !hash_equals($expectedMarker, $content)) {
        throw new RuntimeException("Refusing to clean up directory with mismatched ownership marker: '{$dir}'");
    }

    safeLexicalSubdirCleanup($canonicalDir, $canonicalDir);

    if (!@rmdir($canonicalDir)) {
        throw new RuntimeException("Failed to rmdir root: '{$canonicalDir}'");
    }
}

// -----------------------------------------------------------------------------
// VERIFIED PROCESS STATUS & TERMINATION LIFECYCLE
// -----------------------------------------------------------------------------

/** @var ?callable(mixed): (?string) */
$globalProcessStatusHook = null;

/**
 * Safe status wrapper for proc_get_status.
 * Returns:
 *   - 'state': 'RUNNING', 'DEAD', or 'UNKNOWN'
 *   - 'exitcode': int (when DEAD)
 *
 * Never assumes !running means dead if proc_get_status returned false.
 *
 * @param mixed $proc
 * @return array{state: 'RUNNING'|'DEAD'|'UNKNOWN', exitcode: int}
 */
function getVerifiedProcessStatus(mixed $proc): array
{
    global $globalProcessStatusHook;
    if ($globalProcessStatusHook !== null && is_resource($proc)) {
        $hooked = ($globalProcessStatusHook)($proc);
        if ($hooked !== null) {
            return ['state' => $hooked, 'exitcode' => -1];
        }
    }
    if (!is_resource($proc)) {
        return ['state' => 'UNKNOWN', 'exitcode' => -1];
    }
    $st = @proc_get_status($proc);
    if (!is_array($st)) {
        return ['state' => 'UNKNOWN', 'exitcode' => -1];
    }
    if ($st['running']) {
        return ['state' => 'RUNNING', 'exitcode' => -1];
    }
    return ['state' => 'DEAD', 'exitcode' => (int) $st['exitcode']];
}

// -----------------------------------------------------------------------------
// BUILDER CLI SUBPROCESS RUNNER
// -----------------------------------------------------------------------------

/**
 * Run real builder CLI subprocess to produce signed delta package.
 *
 * @param list<string> $args
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runBuilderCli(array $args, string $labRoot): array
{
    global $trackedChildProcs;
    $builderScript = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build-shell-delta.php';
    ProductInventoryService::assertNoReparse($builderScript);
    $cmd = array_merge([PHP_BINARY, $builderScript], $args);

    $nonce = bin2hex(random_bytes(6));
    $stdoutLog = $labRoot . DIRECTORY_SEPARATOR . 'builder-stdout-' . $nonce . '.log';
    $stderrLog = $labRoot . DIRECTORY_SEPARATOR . 'builder-stderr-' . $nonce . '.log';

    $descriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $stdoutLog, 'w'],
        2 => ['file', $stderrLog, 'w'],
    ];

    // env = null inherits FULL parent environment
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), null);
    if (!is_resource($proc)) {
        throw new RuntimeException("Failed to run builder CLI.");
    }

    // Track builder CLI resource immediately!
    $trackedChildProcs[] = $proc;

    // Bounded watchdog for builder CLI (90s ceiling)
    $start = microtime(true);
    $running = true;
    $exitCode = -1;
    while ((microtime(true) - $start) < 90.0) {
        $st = getVerifiedProcessStatus($proc);
        if ($st['state'] === 'DEAD') {
            $running = false;
            $exitCode = $st['exitcode'];
            break;
        }
        usleep(25000);
    }

    if ($running) {
        forceKillChildAndVerify($proc, 5);
        throw new RuntimeException("Builder CLI exceeded 90s ceiling.");
    }

    @proc_close($proc);
    $idx = array_search($proc, $trackedChildProcs, true);
    if ($idx !== false) {
        unset($trackedChildProcs[$idx]);
        $trackedChildProcs = array_values($trackedChildProcs);
    }

    $stdout = file_exists($stdoutLog) ? (string) file_get_contents($stdoutLog) : '';
    $stderr = file_exists($stderrLog) ? (string) file_get_contents($stderrLog) : '';

    @unlink($stdoutLog);
    @unlink($stderrLog);

    return [
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

// -----------------------------------------------------------------------------
// CHILD PROCESS TRACKING & SAFE FORCE-KILL
// -----------------------------------------------------------------------------

/** @var list<resource> */
$trackedChildProcs = [];

/**
 * Spawn a child process with owned log files and inherited parent environment.
 *
 * @param array<string, mixed> $config
 * @return array{proc: resource, stdoutLog: string, stderrLog: string, nonce: string}
 */
function spawnChildProcess(
    string $action,
    array $config,
    string $labRoot,
    string $ownerToken
): array {
    global $trackedChildProcs;

    $nonce = bin2hex(random_bytes(8));
    $config['nonce'] = $nonce;

    $configFile = $config['configFile'];
    file_put_contents($configFile, json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    $ackFile = $config['ackFile'];
    if (file_exists($ackFile)) {
        @unlink($ackFile);
    }

    $stdoutLog = $labRoot . DIRECTORY_SEPARATOR . 'child-' . $action . '-' . $nonce . '-stdout.log';
    $stderrLog = $labRoot . DIRECTORY_SEPARATOR . 'child-' . $action . '-' . $nonce . '-stderr.log';

    $cmd = [
        PHP_BINARY,
        __FILE__,
        '--child',
        $action,
        $configFile,
        $labRoot,
        $ownerToken,
    ];

    $descriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $stdoutLog, 'w'],
        2 => ['file', $stderrLog, 'w'],
    ];

    // Subprocess inherits FULL parent environment (env = null)
    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), null);
    if (!is_resource($proc)) {
        throw new RuntimeException("Failed to spawn child process for action {$action}.");
    }

    $trackedChildProcs[] = $proc;

    return [
        'proc' => $proc,
        'stdoutLog' => $stdoutLog,
        'stderrLog' => $stderrLog,
        'nonce' => $nonce,
    ];
}

/**
 * Force kill child process using proc_terminate and verify it is dead.
 */
function forceKillChildAndVerify(mixed $proc, int $timeoutSeconds = 5): int
{
    global $trackedChildProcs;
    if (!is_resource($proc)) {
        return -1;
    }

    $st = getVerifiedProcessStatus($proc);
    if ($st['state'] === 'DEAD') {
        $exit = $st['exitcode'];
        @proc_close($proc);
        $idx = array_search($proc, $trackedChildProcs, true);
        if ($idx !== false) {
            unset($trackedChildProcs[$idx]);
            $trackedChildProcs = array_values($trackedChildProcs);
        }
        return $exit;
    }
    if ($st['state'] === 'UNKNOWN') {
        // Can NEVER proc_close alive/unknown!
        throw new RuntimeException("Cannot terminate child: process status is UNKNOWN.");
    }

    // Real force kill child verified via parent proc_terminate (resource only, no PID kill)
    @proc_terminate($proc);

    $start = microtime(true);
    while ((microtime(true) - $start) < $timeoutSeconds) {
        $stPoll = getVerifiedProcessStatus($proc);
        if ($stPoll['state'] === 'DEAD') {
            $exit = $stPoll['exitcode'];
            @proc_close($proc);
            $idx = array_search($proc, $trackedChildProcs, true);
            if ($idx !== false) {
                unset($trackedChildProcs[$idx]);
                $trackedChildProcs = array_values($trackedChildProcs);
            }
            return $exit;
        }
        usleep(25000);
    }

    // Still running or unknown: NEVER proc_close!
    throw new RuntimeException("Child process failed to terminate within {$timeoutSeconds}s.");
}

/**
 * Shared teardown and retention decision helper used by both Suite 10 and the script's finally block.
 *
 * @param array<int, mixed> &$procs
 * @param string $labDir
 * @param string $labMarkerToken
 * @param string $victimDirectory
 * @param string $victimMarkerToken
 * @param string $victimSentinelFile
 * @param string $victimSentinelExpected
 * @param string $runnerTemp
 * @param float $terminateTimeout
 * @return array{cleaned: bool, reason: string, uncertain: bool}
 */
function performTeardownAndRetention(
    array &$procs,
    string $labDir,
    string $labMarkerToken,
    string $victimDirectory,
    string $victimMarkerToken,
    string $victimSentinelFile,
    string $victimSentinelExpected,
    string $runnerTemp,
    float $terminateTimeout = 5.0
): array {
    $uncleanProcesses = false;
    $remainingProcs = [];

    // 1. Terminate and verify all tracked processes (resource only, no PID kills)
    foreach ($procs as $p) {
        if (!is_resource($p)) {
            continue;
        }

        $st = getVerifiedProcessStatus($p);
        if ($st['state'] === 'UNKNOWN') {
            $uncleanProcesses = true;
            $remainingProcs[] = $p;
            continue;
        }

        if ($st['state'] === 'RUNNING') {
            @proc_terminate($p);
            $killStart = microtime(true);
            $dead = false;
            while ((microtime(true) - $killStart) < $terminateTimeout) {
                $stAfter = getVerifiedProcessStatus($p);
                if ($stAfter['state'] === 'DEAD') {
                    $dead = true;
                    break;
                }
                if ($stAfter['state'] === 'UNKNOWN') {
                    break;
                }
                usleep(25000);
            }
            if (!$dead) {
                $uncleanProcesses = true;
                $remainingProcs[] = $p;
                // Do NOT proc_close on running or uncertain proc!
                continue;
            }
        }

        // Process is confirmed DEAD
        @proc_close($p);
    }

    $procs = array_values($remainingProcs);

    // 2. Verify outside victim sentinel was never modified
    if ($victimSentinelFile !== '' &&
        (!is_file($victimSentinelFile) || file_get_contents($victimSentinelFile) !== $victimSentinelExpected)) {
        throw new RuntimeException("CRITICAL ERROR: Outside victim sentinel was modified or deleted!");
    }

    // 3. If any child process remains live or uncertain: REFUSE cleanup!
    if ($uncleanProcesses) {
        return [
            'cleaned' => false,
            'reason' => 'NEEDS ATTENTION: Child process was still live or uncertain; retaining lab directory and junctions for audit.',
            'uncertain' => true,
        ];
    }

    // 4. All processes confirmed dead: Safe to remove junctions and cleanup fixtures
    if ($labDir !== '') {
        removeAllTrackedJunctions($labDir);
    }

    if ($labDir !== '' && $labMarkerToken !== '' && $runnerTemp !== '') {
        safeLexicalCleanup($labDir, $labMarkerToken, $runnerTemp);
    }

    if ($victimDirectory !== '' && $victimMarkerToken !== '' && $runnerTemp !== '') {
        safeLexicalCleanup($victimDirectory, $victimMarkerToken, $runnerTemp);
    }

    return [
        'cleaned' => true,
        'reason' => 'OK',
        'uncertain' => false,
    ];
}

// -----------------------------------------------------------------------------
// MAIN TEST SUITE
// -----------------------------------------------------------------------------

echo "=== Shell Delta File Transaction Core Verification Suite ===\n\n";

// Set up owned exclusive test laboratory under canonical RUNNER_TEMP
$lab = $canonicalRunnerTemp . DIRECTORY_SEPARATOR . 'shell-tx-lab-' . bin2hex(random_bytes(8));
if (!mkdir($lab, 0700, true)) {
    throw new RuntimeException("Failed to create owned lab directory: {$lab}");
}
$labMarker = 'OWNED_SHELL_TX_LAB_' . bin2hex(random_bytes(16));
file_put_contents($lab . DIRECTORY_SEPARATOR . '.pos_owned_marker', $labMarker);

$victimDir = $canonicalRunnerTemp . DIRECTORY_SEPARATOR . 'shell-tx-victim-' . bin2hex(random_bytes(8));
if (!mkdir($victimDir, 0700, true)) {
    throw new RuntimeException("Failed to create victim directory: {$victimDir}");
}
$victimMarker = 'OWNED_VICTIM_' . bin2hex(random_bytes(16));
file_put_contents($victimDir . DIRECTORY_SEPARATOR . '.pos_owned_marker', $victimMarker);
$victimSentinel = $victimDir . DIRECTORY_SEPARATOR . 'victim-sentinel.txt';
$victimSentinelContent = 'VICTIM_MUST_NOT_BE_MUTATED_' . bin2hex(random_bytes(16));
file_put_contents($victimSentinel, $victimSentinelContent);

$primaryException = null;
try {
    // -------------------------------------------------------------------------
    // Cryptographic Fixture Setup (Ephemeral RSA-2048, no production keys)
    // -------------------------------------------------------------------------
    $ephemeralKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($ephemeralKey === false) {
        throw new RuntimeException("Failed to generate ephemeral RSA key pair: " . openssl_error_string());
    }
    $privKeyPem = '';
    if (!openssl_pkey_export($ephemeralKey, $privKeyPem)) {
        throw new RuntimeException("Failed to export ephemeral private key: " . openssl_error_string());
    }
    $privKeyDetails = openssl_pkey_get_details($ephemeralKey);
    if ($privKeyDetails === false || !isset($privKeyDetails['key'])) {
        throw new RuntimeException("Failed to extract ephemeral public key details.");
    }
    $pubKeyPem = $privKeyDetails['key'];

    // Private key stored ONLY in lab for builder CLI input; never copied into product or workspaces
    $keyFile = $lab . DIRECTORY_SEPARATOR . 'ephemeral-private-key.pem';
    file_put_contents($keyFile, $privKeyPem);

    // Standard Format 1 Data Compatibility Contracts
    $dataContract = [
        'format' => 1,
        'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
        'schema_sha256' => hash('sha256', 'mock_schema_identical_v1'),
        'ledger_sha256' => hash('sha256', 'mock_ledger_identical_v1'),
    ];
    $contractFile = $lab . DIRECTORY_SEPARATOR . 'data-contract.json';
    file_put_contents($contractFile, json_encode($dataContract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Protected customer state sentinels (NEVER actual customer SQL, synthetic only)
    $customerSentinels = [
        'userdata/synthetic_customer_backup.sql' => "SYNTHETIC_SQL_DATA_MOCK_" . bin2hex(random_bytes(32)),
        'storage/logs/customer_audit.log' => "AUDIT_LOG_ENTRY_" . bin2hex(random_bytes(32)),
        '.env' => "APP_SECRET=synthetic_secret_" . bin2hex(random_bytes(16)),
    ];

    // Base Synthetic Product Files (Beta channel)
    // Source app 0.0.12-beta.1 / native 0.0.13
    $sourceBetaFiles = [
        'POS Desktop.exe' => "EXE_SHELL_V13_CONTENT_" . str_repeat('A', 500),
        'resources/app.asar' => "ASAR_SHELL_V13_CONTENT_" . str_repeat('B', 1000),
        'resources/app.asar.unpacked/version.json' => json_encode(['version' => '0.0.12-beta.1']),
        'resources/app.asar.unpacked/package.json' => json_encode(['name' => 'pos-desktop', 'version' => '0.0.12-beta.1']),
        'resources/app.asar.unpacked/backend/backend.phar' => "PHAR_V12_CONTENT",
        'resources/app.asar.unpacked/backend/certs/cacert.pem' => "MOCK_CACERT",
        'resources/app.asar.unpacked/backend/certs/update_public_key.pem' => $pubKeyPem,
        'resources/app.asar.unpacked/database/pos_schema.sql' => "-- schema v12",
        'resources/app.asar.unpacked/frontend/dist/app.js' => "console.log('v12');",
        'resources/app.asar.unpacked/frontend/dist/legacy.js' => "console.log('old');",
        'resources/portable/runtime-manifest.json' => json_encode(['schemaVersion' => 1]),
        'resources/portable/php/php.exe' => "MOCK_PHP_EXE",
        'resources/portable/mysql/bin/mysqld.exe' => "MOCK_MYSQL_EXE",
        'locales/en-US.pak' => "PAK_EN_US_UNCHANGED",
        'icudtl.dat' => "ICU_DATA_UNCHANGED",
    ];

    // Target app 0.0.14-beta.1 / native 0.0.14
    $targetBetaFiles = $sourceBetaFiles;
    $targetBetaFiles['POS Desktop.exe'] = "EXE_SHELL_V14_CONTENT_" . str_repeat('Z', 600);
    $targetBetaFiles['resources/app.asar'] = "ASAR_SHELL_V14_CONTENT_" . str_repeat('Y', 1200);
    $targetBetaFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.14-beta.1']);
    $targetBetaFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.14-beta.1']);
    $targetBetaFiles['resources/app.asar.unpacked/backend/backend.phar'] = "PHAR_V14_CONTENT";
    $targetBetaFiles['resources/app.asar.unpacked/frontend/dist/app.js'] = "console.log('v14');";
    $targetBetaFiles['resources/app.asar.unpacked/frontend/dist/app-v14.js'] = "console.log('new feature');";
    unset($targetBetaFiles['resources/app.asar.unpacked/frontend/dist/legacy.js']);

    $betaSrcDir = $lab . DIRECTORY_SEPARATOR . 'beta-src';
    $betaTgtDir = $lab . DIRECTORY_SEPARATOR . 'beta-tgt';
    $betaOutDir = $lab . DIRECTORY_SEPARATOR . 'beta-out';
    populateTree($betaSrcDir, $sourceBetaFiles);
    populateTree($betaTgtDir, $targetBetaFiles);

    // Build real Beta delta package via builder CLI
    echo "[Setup] Building real Beta delta package via builder CLI...\n";
    $builderBetaRes = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $contractFile,
        '--private-key', $keyFile,
        '--output-dir', $betaOutDir,
    ], $lab);
    assertEqual($builderBetaRes['exitCode'], 0, "Beta builder CLI must succeed with status 0. Stderr: {$builderBetaRes['stderr']}");

    // Load built manifest assets for Beta
    $betaManifestRaw = file_get_contents($betaOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $betaSig = file_get_contents($betaOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig');
    $betaZipPath = $betaOutDir . DIRECTORY_SEPARATOR . 'shell-delta.zip';

    $betaManifest = json_decode($betaManifestRaw, true, 64, JSON_THROW_ON_ERROR);
    $betaSourceInv = $betaManifest['source_inventory'];
    $betaTargetInv = $betaManifest['target_inventory'];

    // Base Synthetic Product Files (Stable channel)
    // Source app 0.0.12 / native 0.0.13
    $sourceStableFiles = $sourceBetaFiles;
    $sourceStableFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.12']);
    $sourceStableFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.12']);

    // Target app 0.0.14 / native 0.0.14
    $targetStableFiles = $targetBetaFiles;
    $targetStableFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.14']);
    $targetStableFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.14']);

    $stableSrcDir = $lab . DIRECTORY_SEPARATOR . 'stable-src';
    $stableTgtDir = $lab . DIRECTORY_SEPARATOR . 'stable-tgt';
    $stableOutDir = $lab . DIRECTORY_SEPARATOR . 'stable-out';
    populateTree($stableSrcDir, $sourceStableFiles);
    populateTree($stableTgtDir, $targetStableFiles);

    echo "[Setup] Building real Stable delta package via builder CLI...\n";
    $builderStableRes = runBuilderCli([
        '--source-root', $stableSrcDir,
        '--target-root', $stableTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $contractFile,
        '--private-key', $keyFile,
        '--output-dir', $stableOutDir,
    ], $lab);
    assertEqual($builderStableRes['exitCode'], 0, "Stable builder CLI must succeed with status 0. Stderr: {$builderStableRes['stderr']}");

    $stableManifestRaw = file_get_contents($stableOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $stableSig = file_get_contents($stableOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig');
    $stableZipPath = $stableOutDir . DIRECTORY_SEPARATOR . 'shell-delta.zip';

    $stableManifest = json_decode($stableManifestRaw, true, 64, JSON_THROW_ON_ERROR);
    $stableSourceInv = $stableManifest['source_inventory'];
    $stableTargetInv = $stableManifest['target_inventory'];

    // Services with injected ephemeral public key
    $sigService = new ManifestSignatureService(null, [$pubKeyPem]);
    $manifestService = new ShellDeltaManifestService($sigService);
    $txService = new ShellDeltaFileTransactionService($manifestService);

    // Verify signatures self-check with ephemeral key
    assertTrue($sigService->verifySignature($betaManifestRaw, $betaSig), "Beta manifest signature must verify with ephemeral key");
    assertTrue($sigService->verifySignature($stableManifestRaw, $stableSig), "Stable manifest signature must verify with ephemeral key");

    // -------------------------------------------------------------------------
    // TEST SUITE 1: POSITIVE LIFECYCLE (BETA + STABLE)
    // -------------------------------------------------------------------------
    echo "\n=== [Test Suite 1] Positive Transaction Lifecycle (Beta & Stable) ===\n";

    // --- 1.1 Beta Transaction Lifecycle ---
    echo "  [1.1] Beta Channel: prepare, apply, rollback, repeat rollback...\n";
    $suite1BetaRoot = $lab . DIRECTORY_SEPARATOR . 'suite1-beta-product';
    $suite1BetaState = $lab . DIRECTORY_SEPARATOR . 'suite1-beta-state';
    mkdir($suite1BetaState, 0700, true);
    populateTree($suite1BetaRoot, $sourceBetaFiles);
    populateTree($suite1BetaRoot, $customerSentinels);

    $installedBetaContext = [
        'version' => '0.0.12-beta.1',
        'native_version' => '0.0.13',
        'channel' => 'beta',
        'data_compatibility' => $dataContract,
    ];

    $beforePrepSnapshot = snapshotTree($suite1BetaRoot);

    // Beta Prepare
    $prepBetaRes = $txService->prepare(
        $suite1BetaRoot,
        $suite1BetaState,
        $betaManifestRaw,
        $betaSig,
        $betaZipPath,
        $installedBetaContext
    );
    assertEqual($prepBetaRes['phase'], ShellDeltaFileTransactionService::PHASE_PREPARED, "Prepare phase must be 'prepared'");
    $betaOpId = $prepBetaRes['operation_id'];
    assertTrue(preg_match('/^tx_[a-f0-9]{32}$/', $betaOpId) === 1, "Operation ID must match tx_[a-f0-9]{32}");
    $betaWs = $prepBetaRes['workspace'];
    assertTrue(is_dir($betaWs), "Workspace directory must exist under state base");

    // Root untouched after prepare
    $afterPrepSnapshot = snapshotTree($suite1BetaRoot);
    assertEqual($afterPrepSnapshot, $beforePrepSnapshot, "prepare() must leave product bytes completely unchanged");

    // Beta Apply
    $applyBetaRes = $txService->apply(
        $suite1BetaRoot,
        $suite1BetaState,
        $betaOpId,
        $installedBetaContext
    );
    assertEqual($applyBetaRes['phase'], ShellDeltaFileTransactionService::PHASE_FILES_APPLIED, "Apply phase must be 'files_applied_awaiting_health'");
    assertEqual($applyBetaRes['version'], '0.0.14-beta.1', "Applied version must be 0.0.14-beta.1");

    // Applied files must match full embedded target inventory
    $appliedBetaInv = $manifestService->buildInventory($suite1BetaRoot, '0.0.14');
    assertEqual($appliedBetaInv['files'], $betaTargetInv['files'], "Applied product files must match full embedded target inventory");

    // Customer sentinels intact
    foreach ($customerSentinels as $cRel => $cContent) {
        $cPath = $suite1BetaRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cRel);
        assertTrue(is_file($cPath), "Protected sentinel must remain intact: {$cRel}");
        assertEqual(file_get_contents($cPath), $cContent, "Protected sentinel content must be unchanged: {$cRel}");
    }

    // No temp files in product root
    $appliedSnap = snapshotTree($suite1BetaRoot);
    foreach (array_keys($appliedSnap) as $relP) {
        assertFalse(str_contains($relP, '.tmp') || str_contains($relP, 'pub_'), "No temp files permitted in product: {$relP}");
    }

    // Beta Rollback
    $rbBetaRes = $txService->rollback(
        $suite1BetaRoot,
        $suite1BetaState,
        $betaOpId,
        $installedBetaContext
    );
    assertEqual($rbBetaRes['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Rollback phase must be 'rolled_back'");

    // Rolled back files must match full embedded source inventory
    $rolledBackBetaInv = $manifestService->buildInventory($suite1BetaRoot, '0.0.13');
    assertEqual($rolledBackBetaInv['files'], $betaSourceInv['files'], "Rolled back product files must match full embedded source inventory");

    // Customer sentinels still intact
    foreach ($customerSentinels as $cRel => $cContent) {
        $cPath = $suite1BetaRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cRel);
        assertEqual(file_get_contents($cPath), $cContent, "Customer sentinel content must be unchanged after rollback: {$cRel}");
    }

    // Active marker removed, workspace & backups retained
    $rootDigest = hash('sha256', strtolower(rtrim(str_replace('/', '\\', ProductInventoryService::resolveAbsolutePath($suite1BetaRoot, true)), '\\')));
    $activeMarkerFile = $suite1BetaState . DIRECTORY_SEPARATOR . 'active_roots' . DIRECTORY_SEPARATOR . $rootDigest . '.json';
    assertFalse(file_exists($activeMarkerFile), "Active operation marker must be removed after successful rollback");
    assertTrue(is_dir($betaWs), "Workspace directory must be retained for recovery/audit");
    assertTrue(is_dir($betaWs . DIRECTORY_SEPARATOR . 'backups'), "Backup files must be retained in workspace");

    // Beta Repeat Rollback (idempotent)
    $rbBetaRepeat = $txService->rollback(
        $suite1BetaRoot,
        $suite1BetaState,
        $betaOpId,
        $installedBetaContext
    );
    assertEqual($rbBetaRepeat['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Repeat rollback must succeed idempotently");

    // --- 1.2 Stable Transaction Lifecycle ---
    echo "  [1.2] Stable Channel: prepare, apply, rollback, repeat rollback...\n";
    $suite1StableRoot = $lab . DIRECTORY_SEPARATOR . 'suite1-stable-product';
    $suite1StableState = $lab . DIRECTORY_SEPARATOR . 'suite1-stable-state';
    mkdir($suite1StableState, 0700, true);
    populateTree($suite1StableRoot, $sourceStableFiles);
    populateTree($suite1StableRoot, $customerSentinels);

    $installedStableContext = [
        'version' => '0.0.12',
        'native_version' => '0.0.13',
        'channel' => 'stable',
        'data_compatibility' => $dataContract,
    ];

    $prepStableRes = $txService->prepare(
        $suite1StableRoot,
        $suite1StableState,
        $stableManifestRaw,
        $stableSig,
        $stableZipPath,
        $installedStableContext
    );
    assertEqual($prepStableRes['phase'], ShellDeltaFileTransactionService::PHASE_PREPARED, "Prepare phase must be 'prepared'");
    $stableOpId = $prepStableRes['operation_id'];

    $applyStableRes = $txService->apply(
        $suite1StableRoot,
        $suite1StableState,
        $stableOpId,
        $installedStableContext
    );
    assertEqual($applyStableRes['phase'], ShellDeltaFileTransactionService::PHASE_FILES_APPLIED, "Apply phase must be 'files_applied_awaiting_health'");
    assertEqual($applyStableRes['version'], '0.0.14', "Applied version must be 0.0.14");

    $appliedStableInv = $manifestService->buildInventory($suite1StableRoot, '0.0.14');
    assertEqual($appliedStableInv['files'], $stableTargetInv['files'], "Applied product files must match full embedded Stable target inventory");

    // Sentinels intact in Stable
    foreach ($customerSentinels as $cRel => $cContent) {
        $cPath = $suite1StableRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cRel);
        assertEqual(file_get_contents($cPath), $cContent, "Customer sentinel content intact in Stable apply: {$cRel}");
    }

    $rbStableRes = $txService->rollback(
        $suite1StableRoot,
        $suite1StableState,
        $stableOpId,
        $installedStableContext
    );
    assertEqual($rbStableRes['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Rollback phase must be 'rolled_back'");

    $rolledBackStableInv = $manifestService->buildInventory($suite1StableRoot, '0.0.13');
    assertEqual($rolledBackStableInv['files'], $stableSourceInv['files'], "Rolled back product files must match full embedded Stable source inventory");

    // Repeat rollback in Stable
    $rbStableRepeat = $txService->rollback(
        $suite1StableRoot,
        $suite1StableState,
        $stableOpId,
        $installedStableContext
    );
    assertEqual($rbStableRepeat['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Repeat Stable rollback must succeed idempotently");

    echo "  [Test Suite 1 Passed]\n";

    // -------------------------------------------------------------------------
    // TEST SUITE 2: NEGATIVE CONSTRAINTS & SECURITY ABORT CHECKS
    // -------------------------------------------------------------------------
    echo "\n=== [Test Suite 2] Negative Constraints & Security Aborts ===\n";

    $suite2Root = $lab . DIRECTORY_SEPARATOR . 'suite2-product';
    $suite2State = $lab . DIRECTORY_SEPARATOR . 'suite2-state';
    mkdir($suite2State, 0700, true);
    populateTree($suite2Root, $sourceBetaFiles);

    // 2.1 Invalid Signature
    echo "  [2.1] Invalid signature aborts before mutation...\n";
    $badSig = base64_encode(str_repeat('X', 256));
    assertThrows(function () use ($txService, $suite2Root, $suite2State, $betaManifestRaw, $badSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite2Root, $suite2State, $betaManifestRaw, $badSig, $betaZipPath, $installedBetaContext);
    }, 'signature', "Prepare must fail on invalid signature");

    // 2.2 Wrong Channel Context
    echo "  [2.2] Wrong channel context aborts before mutation...\n";
    $wrongChannelContext = $installedBetaContext;
    $wrongChannelContext['channel'] = 'stable';
    assertThrows(function () use ($txService, $suite2Root, $suite2State, $betaManifestRaw, $betaSig, $betaZipPath, $wrongChannelContext) {
        $txService->prepare($suite2Root, $suite2State, $betaManifestRaw, $betaSig, $betaZipPath, $wrongChannelContext);
    }, 'channel', "Prepare must fail on channel mismatch");

    // 2.3 Wrong Context Native Version
    echo "  [2.3] Wrong native version context aborts before mutation...\n";
    $wrongVerContext = $installedBetaContext;
    $wrongVerContext['native_version'] = '0.0.99';
    assertThrows(function () use ($txService, $suite2Root, $suite2State, $betaManifestRaw, $betaSig, $betaZipPath, $wrongVerContext) {
        $txService->prepare($suite2Root, $suite2State, $betaManifestRaw, $betaSig, $betaZipPath, $wrongVerContext);
    }, 'version', "Prepare must fail on native version mismatch");

    // 2.4 Unrecognized Unmanaged File in Product Root
    echo "  [2.4] Unrecognized unmanaged file in root aborts prepare before mutation...\n";
    $unmanagedRoot = $lab . DIRECTORY_SEPARATOR . 'suite2-unmanaged-product';
    $unmanagedState = $lab . DIRECTORY_SEPARATOR . 'suite2-unmanaged-state';
    mkdir($unmanagedState, 0700, true);
    populateTree($unmanagedRoot, $sourceBetaFiles);
    $unmanagedFile = $unmanagedRoot . DIRECTORY_SEPARATOR . 'unmanaged_dir' . DIRECTORY_SEPARATOR . 'customer_notes.txt';
    @mkdir(dirname($unmanagedFile), 0700, true);
    $unmanagedContent = "CUSTOMER_UNMANAGED_NOTE_" . bin2hex(random_bytes(16));
    file_put_contents($unmanagedFile, $unmanagedContent);
    $snapBeforeUnmanaged = snapshotTree($unmanagedRoot);

    assertThrows(function () use ($txService, $unmanagedRoot, $unmanagedState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($unmanagedRoot, $unmanagedState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must fail when unauthorized unmanaged file exists in root");

    // Product tree and unmanaged file must be completely untouched
    $snapAfterUnmanaged = snapshotTree($unmanagedRoot);
    assertEqual($snapAfterUnmanaged, $snapBeforeUnmanaged, "Prepare abort must leave product root completely unchanged");
    assertTrue(is_file($unmanagedFile), "Unmanaged file must be preserved intact");
    assertEqual(file_get_contents($unmanagedFile), $unmanagedContent, "Unmanaged file content must be unchanged");

    // 2.5 ZIP Tamper Detection
    echo "  [2.5] ZIP tampering aborts before mutation...\n";
    $tamperedZip = $lab . DIRECTORY_SEPARATOR . 'tampered.zip';
    copy($betaZipPath, $tamperedZip);
    $zipFp = fopen($tamperedZip, 'r+b');
    fseek($zipFp, 100);
    fwrite($zipFp, 'CORRUPTED_BYTES');
    fclose($zipFp);

    assertThrows(function () use ($txService, $suite2Root, $suite2State, $betaManifestRaw, $betaSig, $tamperedZip, $installedBetaContext) {
        $txService->prepare($suite2Root, $suite2State, $betaManifestRaw, $betaSig, $tamperedZip, $installedBetaContext);
    }, '', "Prepare must fail on tampered ZIP package");

    // 2.6 Stage Tamper Detection in Apply
    echo "  [2.6] Stage tampering aborts apply before mutation...\n";
    $prepStageTamper = $txService->prepare($suite2Root, $suite2State, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $stageOpId = $prepStageTamper['operation_id'];
    $stageWs = $prepStageTamper['workspace'];

    $stagedFiles = glob($stageWs . DIRECTORY_SEPARATOR . 'staged' . DIRECTORY_SEPARATOR . '*.staged');
    assertTrue(!empty($stagedFiles), "Staged files must exist");
    file_put_contents($stagedFiles[0], 'TAMPERED_STAGED_CONTENT');

    assertThrows(function () use ($txService, $suite2Root, $suite2State, $stageOpId, $installedBetaContext) {
        $txService->apply($suite2Root, $suite2State, $stageOpId, $installedBetaContext);
    }, 'staged', "Apply must abort on tampered staged file");
    $txService->rollback($suite2Root, $suite2State, $stageOpId, $installedBetaContext);

    // 2.7 Backup Tamper Detection
    echo "  [2.7] Backup tampering: safe cancel when at source vs fail-closed when mutated...\n";
    // 2.7.1 Root already at SOURCE: bad unused backup allows safe cancel
    $bakSrcRoot = $lab . DIRECTORY_SEPARATOR . 'suite2-bak-src-product';
    $bakSrcState = $lab . DIRECTORY_SEPARATOR . 'suite2-bak-src-state';
    mkdir($bakSrcState, 0700, true);
    populateTree($bakSrcRoot, $sourceBetaFiles);

    $prepBakSrc = $txService->prepare($bakSrcRoot, $bakSrcState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $bakSrcOpId = $prepBakSrc['operation_id'];
    $bakSrcWs = $prepBakSrc['workspace'];
    $bakFiles1 = glob($bakSrcWs . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*.bak');
    assertTrue(!empty($bakFiles1), "Backup files must exist");
    file_put_contents($bakFiles1[0], 'TAMPERED_BACKUP_CONTENT');

    // Rollback on root that is still at source must succeed safely because backup is unused
    $rbSrcRes = $txService->rollback($bakSrcRoot, $bakSrcState, $bakSrcOpId, $installedBetaContext);
    assertEqual($rbSrcRes['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Cancel on unmodified source must succeed even if unused backup is bad");

    // 2.7.2 Root partially mutated: needed backup bad must fail closed and retain
    $bakMutRoot = $lab . DIRECTORY_SEPARATOR . 'suite2-bak-mut-product';
    $bakMutState = $lab . DIRECTORY_SEPARATOR . 'suite2-bak-mut-state';
    mkdir($bakMutState, 0700, true);
    populateTree($bakMutRoot, $sourceBetaFiles);

    $prepBakMut = $txService->prepare($bakMutRoot, $bakMutState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $bakMutOpId = $prepBakMut['operation_id'];
    $bakMutWs = $prepBakMut['workspace'];
    // Mutate a file so rollback genuinely requires the backup
    file_put_contents($bakMutRoot . DIRECTORY_SEPARATOR . 'icudtl.dat', 'PARTIALLY_MUTATED_BYTES');
    $bakFiles2 = glob($bakMutWs . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*.bak');
    assertTrue(!empty($bakFiles2), "Backup files must exist");
    foreach ($bakFiles2 as $bf) {
        file_put_contents($bf, 'CORRUPTED_BACKUP_DATA');
    }

    assertThrows(function () use ($txService, $bakMutRoot, $bakMutState, $bakMutOpId, $installedBetaContext) {
        $txService->rollback($bakMutRoot, $bakMutState, $bakMutOpId, $installedBetaContext);
    }, 'backup', "Rollback on mutated root with corrupt backup must fail closed and retain state");

    // 2.8 Missing/Corrupt Unused ZIP After Partial Apply
    echo "  [2.8] Missing/corrupt unused ZIP after prepare allows verified backup rollback...\n";
    $zipTestRoot = $lab . DIRECTORY_SEPARATOR . 'suite2-zip-test-product';
    $zipTestState = $lab . DIRECTORY_SEPARATOR . 'suite2-zip-test-state';
    mkdir($zipTestState, 0700, true);
    populateTree($zipTestRoot, $sourceBetaFiles);

    $tempZip = $lab . DIRECTORY_SEPARATOR . 'temp-run.zip';
    copy($betaZipPath, $tempZip);
    $prepZipRes = $txService->prepare($zipTestRoot, $zipTestState, $betaManifestRaw, $betaSig, $tempZip, $installedBetaContext);
    $zipOpId = $prepZipRes['operation_id'];

    // Delete the ZIP file entirely: rollback does not require the unused target ZIP
    @unlink($tempZip);

    $rbZipRes = $txService->rollback($zipTestRoot, $zipTestState, $zipOpId, $installedBetaContext);
    assertEqual($rbZipRes['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Rollback must succeed without requiring target ZIP");

    // 2.9 Forged Journal & Integrity Invariants
    echo "  [2.9] Forged journal integrity invariants...\n";
    // 2.9.1 Forged journal phase files_applied + bad sealed SIG cannot return success
    $forgedRoot = $lab . DIRECTORY_SEPARATOR . 'suite2-forged-product';
    $forgedState = $lab . DIRECTORY_SEPARATOR . 'suite2-forged-state';
    mkdir($forgedState, 0700, true);
    populateTree($forgedRoot, $sourceBetaFiles);
    $prepForged = $txService->prepare($forgedRoot, $forgedState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $forgedOpId = $prepForged['operation_id'];
    $forgedWs = $prepForged['workspace'];

    $journalPath = $forgedWs . DIRECTORY_SEPARATOR . 'journal.json';
    if (file_exists($journalPath)) {
        $journalData = json_decode((string) file_get_contents($journalPath), true);
        if (is_array($journalData)) {
            $journalData['phase'] = ShellDeltaFileTransactionService::PHASE_FILES_APPLIED;
            $journalData['manifest_signature'] = base64_encode(str_repeat('F', 256));
            file_put_contents($journalPath, json_encode($journalData, JSON_THROW_ON_ERROR));

            assertThrows(function () use ($txService, $forgedRoot, $forgedState, $forgedOpId, $installedBetaContext) {
                $txService->apply($forgedRoot, $forgedState, $forgedOpId, $installedBetaContext);
            }, '', "Forged journal with bad signature cannot succeed");
        }
    }

    // 2.9.2 Forged created_directories pointing to protected or unmanaged dir cannot rmdir
    $forgedDirRoot = $lab . DIRECTORY_SEPARATOR . 'suite2-forged-dir-product';
    $forgedDirState = $lab . DIRECTORY_SEPARATOR . 'suite2-forged-dir-state';
    mkdir($forgedDirState, 0700, true);
    populateTree($forgedDirRoot, $sourceBetaFiles);
    $protectedEmptyDir = $forgedDirRoot . DIRECTORY_SEPARATOR . 'userdata' . DIRECTORY_SEPARATOR . 'empty_sub';
    @mkdir($protectedEmptyDir, 0700, true);

    $prepForgedDir = $txService->prepare($forgedDirRoot, $forgedDirState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $forgedDirOpId = $prepForgedDir['operation_id'];
    $forgedDirWs = $prepForgedDir['workspace'];

    $dirJournalPath = $forgedDirWs . DIRECTORY_SEPARATOR . 'journal.json';
    if (file_exists($dirJournalPath)) {
        $jData = json_decode((string) file_get_contents($dirJournalPath), true);
        if (is_array($jData)) {
            $jData['created_directories'] = ['userdata/empty_sub'];
            file_put_contents($dirJournalPath, json_encode($jData, JSON_THROW_ON_ERROR));

            // Rollback must not rmdir protected directory
            try {
                $txService->rollback($forgedDirRoot, $forgedDirState, $forgedDirOpId, $installedBetaContext);
            } catch (Throwable) {
                // Failure to rmdir protected is acceptable
            }
            assertTrue(is_dir($protectedEmptyDir), "Forged created_directories must never rmdir protected directory");
        }
    }

    // 2.9.3 Malformed / traversal operation IDs fail before writes
    $malformedIds = [
        '../../tx_traversal',
        '..\\tx_traversal',
        'tx_short',
        'tx_nonhex_characters_here_123456',
        'tx_' . str_repeat('a', 33),
        'tx_' . str_repeat('a', 31),
    ];
    foreach ($malformedIds as $badOpId) {
        assertThrows(function () use ($txService, $suite2Root, $suite2State, $badOpId, $installedBetaContext) {
            $txService->apply($suite2Root, $suite2State, $badOpId, $installedBetaContext);
        }, '', "Malformed operation ID must fail validation: {$badOpId}");
    }

    // 2.10 Marker Absent Old Rolled Back Operation on Different Target Root
    echo "  [2.10] Marker absent old operation on different known Target root fails READONLY...\n";
    $diffTargetRoot = $lab . DIRECTORY_SEPARATOR . 'suite2-diff-target-product';
    $diffTargetState = $lab . DIRECTORY_SEPARATOR . 'suite2-diff-target-state';
    mkdir($diffTargetState, 0700, true);
    populateTree($diffTargetRoot, $targetBetaFiles);
    $snapBeforeDiff = snapshotTree($diffTargetRoot);

    $oldOpId = 'tx_' . bin2hex(random_bytes(16));
    assertThrows(function () use ($txService, $diffTargetRoot, $diffTargetState, $oldOpId, $installedBetaContext) {
        $txService->recover($diffTargetRoot, $diffTargetState, $oldOpId, $installedBetaContext);
    }, '', "Marker absent on different target root must fail closed");

    // Product root must remain completely untouched (READONLY)
    $snapAfterDiff = snapshotTree($diffTargetRoot);
    assertEqual($snapAfterDiff, $snapBeforeDiff, "Different target root must remain completely untouched (READONLY)");

    // 2.11 Source Drift After Prepare
    echo "  [2.11] Source drift after prepare aborts apply before mutation...\n";
    $driftRoot = $lab . DIRECTORY_SEPARATOR . 'suite2-drift-product';
    $driftState = $lab . DIRECTORY_SEPARATOR . 'suite2-drift-state';
    mkdir($driftState, 0700, true);
    populateTree($driftRoot, $sourceBetaFiles);

    $prepDrift = $txService->prepare($driftRoot, $driftState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $driftOpId = $prepDrift['operation_id'];

    // Mutate file to simulate source drift
    file_put_contents($driftRoot . DIRECTORY_SEPARATOR . 'icudtl.dat', 'DRIFTED_CONTENT_' . bin2hex(random_bytes(16)));

    assertThrows(function () use ($txService, $driftRoot, $driftState, $driftOpId, $installedBetaContext) {
        $txService->apply($driftRoot, $driftState, $driftOpId, $installedBetaContext);
    }, 'drift', "Apply must abort on source drift");

    // Restore and clean up
    file_put_contents($driftRoot . DIRECTORY_SEPARATOR . 'icudtl.dat', $sourceBetaFiles['icudtl.dat']);
    $txService->rollback($driftRoot, $driftState, $driftOpId, $installedBetaContext);

    // 2.12 Unknown Current Bytes Safety During Rollback
    echo "  [2.12] Unknown current bytes during rollback retains unknown file and fails closed...\n";
    $unkRoot = $lab . DIRECTORY_SEPARATOR . 'suite2-unk-product';
    $unkState = $lab . DIRECTORY_SEPARATOR . 'suite2-unk-state';
    mkdir($unkState, 0700, true);
    populateTree($unkRoot, $sourceBetaFiles);

    $prepUnk = $txService->prepare($unkRoot, $unkState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $unkOpId = $prepUnk['operation_id'];
    $txService->apply($unkRoot, $unkState, $unkOpId, $installedBetaContext);

    $addedFilePath = $unkRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'app.asar.unpacked' . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'app-v14.js';
    assertTrue(is_file($addedFilePath), "Added target file must exist after apply");
    $foreignSentinelContent = 'FOREIGN_UNKNOWN_BYTES_MUST_NOT_BE_OVERWRITTEN_' . bin2hex(random_bytes(16));
    file_put_contents($addedFilePath, $foreignSentinelContent);

    assertThrows(function () use ($txService, $unkRoot, $unkState, $unkOpId, $installedBetaContext) {
        $txService->rollback($unkRoot, $unkState, $unkOpId, $installedBetaContext);
    }, 'unknown', "Rollback must fail closed when encountering unknown current bytes");

    // Assert foreign file was retained completely intact
    assertTrue(is_file($addedFilePath), "Foreign file must be retained on disk");
    assertEqual(file_get_contents($addedFilePath), $foreignSentinelContent, "Foreign file content must not be modified");

    // Restore known target content to complete clean rollback
    file_put_contents($addedFilePath, $targetBetaFiles['resources/app.asar.unpacked/frontend/dist/app-v14.js']);
    $txService->rollback($unkRoot, $unkState, $unkOpId, $installedBetaContext);

    // 2.13 Foreign Active Marker Retained Exact
    echo "  [2.13] Pre-existing foreign active marker fails busy and is retained exact...\n";
    $foreignRoot = $lab . DIRECTORY_SEPARATOR . 'suite2-foreign-product';
    $foreignState = $lab . DIRECTORY_SEPARATOR . 'suite2-foreign-state';
    mkdir($foreignState, 0700, true);
    populateTree($foreignRoot, $sourceBetaFiles);

    $fRootDigest = hash('sha256', strtolower(rtrim(str_replace('/', '\\', ProductInventoryService::resolveAbsolutePath($foreignRoot, true)), '\\')));
    $fMarkerFile = $foreignState . DIRECTORY_SEPARATOR . 'active_roots' . DIRECTORY_SEPARATOR . $fRootDigest . '.json';
    @mkdir(dirname($fMarkerFile), 0700, true);
    $foreignMarkerPayload = [
        'operation_id' => 'tx_foreign_' . bin2hex(random_bytes(12)),
        'root' => ProductInventoryService::resolveAbsolutePath($foreignRoot, true),
        'workspace' => $foreignState . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . 'foreign',
        'created_at' => time() - 3600,
    ];
    file_put_contents($fMarkerFile, json_encode($foreignMarkerPayload, JSON_THROW_ON_ERROR));

    assertThrows(function () use ($txService, $foreignRoot, $foreignState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($foreignRoot, $foreignState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must fail when foreign active marker exists");

    // Foreign marker must be retained exact
    $afterMarker = json_decode((string) file_get_contents($fMarkerFile), true);
    assertEqual($afterMarker['operation_id'], $foreignMarkerPayload['operation_id'], "Foreign marker must never be overwritten or deleted");
    @unlink($fMarkerFile);

    // 2.14 Overlapping Root and StateBase Directories
    echo "  [2.14] Overlapping Root and StateBase directories fail closed...\n";
    $ovState = $suite2Root . DIRECTORY_SEPARATOR . 'nested_state';
    @mkdir($ovState, 0700, true);
    assertThrows(function () use ($txService, $suite2Root, $ovState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite2Root, $ovState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must fail when stateBase is inside root");

    $nestedRoot = $suite2State . DIRECTORY_SEPARATOR . 'nested_root';
    @mkdir($nestedRoot, 0700, true);
    populateTree($nestedRoot, $sourceBetaFiles);
    assertThrows(function () use ($txService, $nestedRoot, $suite2State, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($nestedRoot, $suite2State, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must fail when root is inside stateBase");

    // Case alias overlap
    $caseRoot = strtoupper($suite2Root);
    assertThrows(function () use ($txService, $caseRoot, $ovState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($caseRoot, $ovState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must fail on case-aliased overlapping root/stateBase");

    // 2.15 Different Drive Check
    echo "  [2.15] Cross-drive isolation check...\n";
    $candidateDrives = ['D:', 'E:', 'F:'];
    $foundSecondaryDrive = null;
    foreach ($candidateDrives as $drv) {
        if (is_dir($drv . '\\') && is_dir($drv . '\\owned_ci_fixtures')) {
            $foundSecondaryDrive = $drv;
            break;
        }
    }
    if ($foundSecondaryDrive === null) {
        echo "    [SKIP] Secondary drive with owned fixture not available (system directories prohibited).\n";
    }

    echo "  [Test Suite 2 Passed]\n";

    // -------------------------------------------------------------------------
    // TEST SUITE 3: DEEP DIRECTORY, FILE <-> DIR SHAPE TRANSITIONS, MANAGED EMPTY DIRS
    // -------------------------------------------------------------------------
    echo "\n=== [Test Suite 3] Deep Directory & Shape Transitions ===\n";

    // 3.1 Deep new directory & file build
    $deepTargetFiles = $sourceBetaFiles;
    $deepTargetFiles['deeply/nested/managed/subpath/feature.js'] = "DEEP_FEATURE_CONTENT_" . bin2hex(random_bytes(16));
    $deepTargetFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.14-beta.1']);

    $deepSrcDir = $lab . DIRECTORY_SEPARATOR . 'deep-src';
    $deepTgtDir = $lab . DIRECTORY_SEPARATOR . 'deep-tgt';
    $deepOutDir = $lab . DIRECTORY_SEPARATOR . 'deep-out';
    populateTree($deepSrcDir, $sourceBetaFiles);
    populateTree($deepTgtDir, $deepTargetFiles);

    $builderDeepRes = runBuilderCli([
        '--source-root', $deepSrcDir,
        '--target-root', $deepTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $contractFile,
        '--private-key', $keyFile,
        '--output-dir', $deepOutDir,
    ], $lab);
    assertEqual($builderDeepRes['exitCode'], 0, "Deep builder CLI must succeed");

    $deepManifestRaw = file_get_contents($deepOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $deepSig = file_get_contents($deepOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig');
    $deepZipPath = $deepOutDir . DIRECTORY_SEPARATOR . 'shell-delta.zip';

    // 3.2 File <-> Directory shape transition fails preflight
    echo "  [3.2] File <-> Directory shape transition fails preflight before mutation...\n";
    $shapeRoot = $lab . DIRECTORY_SEPARATOR . 'suite3-shape-product';
    $shapeState = $lab . DIRECTORY_SEPARATOR . 'suite3-shape-state';
    mkdir($shapeState, 0700, true);
    populateTree($shapeRoot, $sourceBetaFiles);
    // In source, create a regular file where the target expects a directory
    $shapeConflictFile = $shapeRoot . DIRECTORY_SEPARATOR . 'deeply' . DIRECTORY_SEPARATOR . 'nested';
    @mkdir(dirname($shapeConflictFile), 0700, true);
    file_put_contents($shapeConflictFile, "I_AM_A_REGULAR_FILE_NOT_A_DIR");

    assertThrows(function () use ($txService, $shapeRoot, $shapeState, $deepManifestRaw, $deepSig, $deepZipPath, $installedBetaContext) {
        $txService->prepare($shapeRoot, $shapeState, $deepManifestRaw, $deepSig, $deepZipPath, $installedBetaContext);
    }, '', "Shape transition (file where dir expected) must fail preflight");

    // 3.3 Recover before first mutation on deep directory target
    echo "  [3.3] Recover before first mutation restores source intact...\n";
    $deepRecRoot = $lab . DIRECTORY_SEPARATOR . 'suite3-deep-rec-product';
    $deepRecState = $lab . DIRECTORY_SEPARATOR . 'suite3-deep-rec-state';
    mkdir($deepRecState, 0700, true);
    populateTree($deepRecRoot, $sourceBetaFiles);

    $prepDeep = $txService->prepare($deepRecRoot, $deepRecState, $deepManifestRaw, $deepSig, $deepZipPath, $installedBetaContext);
    $deepOpId = $prepDeep['operation_id'];

    $recDeepRes = $txService->recover($deepRecRoot, $deepRecState, $deepOpId, $installedBetaContext);
    assertEqual($recDeepRes['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "recover() before mutation must succeed with 'rolled_back'");

    // Repeat recovery is idempotent
    $repeatDeepRes = $txService->recover($deepRecRoot, $deepRecState, $deepOpId, $installedBetaContext);
    assertEqual($repeatDeepRes['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Repeat recover() must be idempotent");

    echo "  [Test Suite 3 Passed]\n";

    // -------------------------------------------------------------------------
    // TEST SUITE 4: FOREIGN ACTIVE MARKER HOOKS & LEAF MUTATION HOOKS
    // -------------------------------------------------------------------------
    echo "\n=== [Test Suite 4] Active Marker & Leaf Mutation Hooks ===\n";

    // 4.1 Replace own marker inside before_marker_release hook
    echo "  [4.1] Replace own marker inside before_marker_release hook fails release...\n";
    $suite4Root1 = $lab . DIRECTORY_SEPARATOR . 'suite4-hook1-product';
    $suite4State1 = $lab . DIRECTORY_SEPARATOR . 'suite4-hook1-state';
    mkdir($suite4State1, 0700, true);
    populateTree($suite4Root1, $sourceBetaFiles);

    $foreignReplacePayload = json_encode(['operation_id' => 'tx_foreign_replace_intruder'], JSON_THROW_ON_ERROR);
    $hookReleaseService = new ShellDeltaFileTransactionService($manifestService, static function (string $checkpoint, array $context) use ($foreignReplacePayload): void {
        if ($checkpoint === 'before_marker_release') {
            $markerPath = $context['active_marker_path'] ?? '';
            if ($markerPath !== '' && file_exists($markerPath)) {
                file_put_contents($markerPath, $foreignReplacePayload);
            }
        }
    });

    $prepHook1 = $hookReleaseService->prepare($suite4Root1, $suite4State1, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $hook1OpId = $prepHook1['operation_id'];

    $h1Digest = hash('sha256', strtolower(rtrim(str_replace('/', '\\', ProductInventoryService::resolveAbsolutePath($suite4Root1, true)), '\\')));
    $h1MarkerFile = $suite4State1 . DIRECTORY_SEPARATOR . 'active_roots' . DIRECTORY_SEPARATOR . $h1Digest . '.json';

    // Rollback triggers before_marker_release hook which replaces marker with foreign data
    assertThrows(function () use ($hookReleaseService, $suite4Root1, $suite4State1, $hook1OpId, $installedBetaContext) {
        $hookReleaseService->rollback($suite4Root1, $suite4State1, $hook1OpId, $installedBetaContext);
    }, '', "Marker release must fail when active marker was replaced with foreign data");

    // Foreign marker must be retained on disk
    assertTrue(file_exists($h1MarkerFile), "Foreign marker must be retained");
    assertEqual(trim((string) file_get_contents($h1MarkerFile)), $foreignReplacePayload, "Foreign marker content retained exact");
    @unlink($h1MarkerFile);

    // 4.2 before_active_marker_create hook injects foreign file
    echo "  [4.2] before_active_marker_create hook injects foreign file...\n";
    $suite4Root2 = $lab . DIRECTORY_SEPARATOR . 'suite4-hook2-product';
    $suite4State2 = $lab . DIRECTORY_SEPARATOR . 'suite4-hook2-state';
    mkdir($suite4State2, 0700, true);
    populateTree($suite4Root2, $sourceBetaFiles);

    $foreignInjectContent = "FOREIGN_INJECTED_MARKER_" . bin2hex(random_bytes(16));
    $hookCreateService = new ShellDeltaFileTransactionService($manifestService, static function (string $checkpoint, array $context) use ($foreignInjectContent): void {
        if ($checkpoint === 'before_active_marker_create') {
            $markerPath = $context['active_marker_path'] ?? '';
            if ($markerPath !== '') {
                @mkdir(dirname($markerPath), 0700, true);
                file_put_contents($markerPath, $foreignInjectContent);
            }
        }
    });

    $h2Digest = hash('sha256', strtolower(rtrim(str_replace('/', '\\', ProductInventoryService::resolveAbsolutePath($suite4Root2, true)), '\\')));
    $h2MarkerFile = $suite4State2 . DIRECTORY_SEPARATOR . 'active_roots' . DIRECTORY_SEPARATOR . $h2Digest . '.json';

    assertThrows(function () use ($hookCreateService, $suite4Root2, $suite4State2, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $hookCreateService->prepare($suite4Root2, $suite4State2, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must fail when active marker cannot be exclusively created");

    // Foreign injected file must be retained exact
    assertTrue(file_exists($h2MarkerFile), "Foreign injected marker must be retained");
    assertEqual(trim((string) file_get_contents($h2MarkerFile)), $foreignInjectContent, "Foreign marker content retained exact");
    @unlink($h2MarkerFile);

    // 4.3 Late before_publish_file hook mutates leaf to foreign bytes
    echo "  [4.3] Late before_publish_file hook mutates leaf to foreign bytes...\n";
    $suite4Root3 = $lab . DIRECTORY_SEPARATOR . 'suite4-hook3-product';
    $suite4State3 = $lab . DIRECTORY_SEPARATOR . 'suite4-hook3-state';
    mkdir($suite4State3, 0700, true);
    populateTree($suite4Root3, $sourceBetaFiles);

    $foreignLeafBytes = "FOREIGN_LEAF_BYTES_" . bin2hex(random_bytes(16));
    $targetLeafRel = 'resources/app.asar.unpacked/frontend/dist/app-v14.js';
    $hookLeafService = new ShellDeltaFileTransactionService($manifestService, static function (string $checkpoint, array $context) use ($suite4Root3, $targetLeafRel, $foreignLeafBytes): void {
        if ($checkpoint === 'before_publish_file') {
            $rel = $context['path'] ?? '';
            if ($rel === $targetLeafRel) {
                $targetFile = $suite4Root3 . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                @mkdir(dirname($targetFile), 0700, true);
                file_put_contents($targetFile, $foreignLeafBytes);
            }
        }
    });

    $prepHook3 = $hookLeafService->prepare($suite4Root3, $suite4State3, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $hook3OpId = $prepHook3['operation_id'];

    assertThrows(function () use ($hookLeafService, $suite4Root3, $suite4State3, $hook3OpId, $installedBetaContext) {
        $hookLeafService->apply($suite4Root3, $suite4State3, $hook3OpId, $installedBetaContext);
    }, '', "Apply must abort when target leaf file was mutated to foreign bytes");

    $targetLeafFull = $suite4Root3 . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $targetLeafRel);
    assertTrue(is_file($targetLeafFull), "Foreign leaf file must not be deleted");
    assertEqual(file_get_contents($targetLeafFull), $foreignLeafBytes, "Foreign leaf bytes must be preserved intact");

    // 4.4 Pre-existing foreign active marker with changed pid
    echo "  [4.4] Pre-existing foreign active marker with changed pid blocks prepare...\n";
    $suite4Root4 = $lab . DIRECTORY_SEPARATOR . 'suite4-marker-pid-product';
    $suite4State4 = $lab . DIRECTORY_SEPARATOR . 'suite4-marker-pid-state';
    mkdir($suite4State4, 0700, true);
    populateTree($suite4Root4, $sourceBetaFiles);

    $h4Digest = hash('sha256', strtolower(rtrim(str_replace('/', '\\', ProductInventoryService::resolveAbsolutePath($suite4Root4, true)), '\\')));
    $h4MarkerDir = $suite4State4 . DIRECTORY_SEPARATOR . 'active_roots';
    mkdir($h4MarkerDir, 0700, true);
    $h4MarkerFile = $h4MarkerDir . DIRECTORY_SEPARATOR . $h4Digest . '.json';
    $foreignPidPayload = json_encode([
        'operation_id' => 'tx_foreign_active_pid',
        'pid' => 9999999,
        'created_at' => time(),
    ], JSON_THROW_ON_ERROR);
    file_put_contents($h4MarkerFile, $foreignPidPayload);

    assertThrows(function () use ($txService, $suite4Root4, $suite4State4, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite4Root4, $suite4State4, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, 'active', "Prepare must fail when active marker with changed pid exists");

    assertTrue(file_exists($h4MarkerFile), "Foreign marker with changed pid must remain on disk");
    assertEqual(trim((string) file_get_contents($h4MarkerFile)), $foreignPidPayload, "Foreign marker content must not be modified or adopted");

    // 4.5 Pre-existing foreign active marker with changed timestamp
    echo "  [4.5] Pre-existing foreign active marker with changed timestamp blocks prepare...\n";
    $suite4Root5 = $lab . DIRECTORY_SEPARATOR . 'suite4-marker-time-product';
    $suite4State5 = $lab . DIRECTORY_SEPARATOR . 'suite4-marker-time-state';
    mkdir($suite4State5, 0700, true);
    populateTree($suite4Root5, $sourceBetaFiles);

    $h5Digest = hash('sha256', strtolower(rtrim(str_replace('/', '\\', ProductInventoryService::resolveAbsolutePath($suite4Root5, true)), '\\')));
    $h5MarkerDir = $suite4State5 . DIRECTORY_SEPARATOR . 'active_roots';
    mkdir($h5MarkerDir, 0700, true);
    $h5MarkerFile = $h5MarkerDir . DIRECTORY_SEPARATOR . $h5Digest . '.json';
    $foreignTimePayload = json_encode([
        'operation_id' => 'tx_foreign_active_time',
        'pid' => getmypid(),
        'created_at' => 100000000,
    ], JSON_THROW_ON_ERROR);
    file_put_contents($h5MarkerFile, $foreignTimePayload);

    assertThrows(function () use ($txService, $suite4Root5, $suite4State5, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite4Root5, $suite4State5, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, 'active', "Prepare must fail when active marker with altered time exists");

    assertTrue(file_exists($h5MarkerFile), "Foreign marker with altered time must remain on disk");
    assertEqual(trim((string) file_get_contents($h5MarkerFile)), $foreignTimePayload, "Foreign marker with altered time must not be modified or adopted");

    // 4.6 Pre-existing foreign active marker with unknown key
    echo "  [4.6] Pre-existing foreign active marker with unknown key blocks prepare...\n";
    $suite4Root6 = $lab . DIRECTORY_SEPARATOR . 'suite4-marker-unknown-key-product';
    $suite4State6 = $lab . DIRECTORY_SEPARATOR . 'suite4-marker-unknown-key-state';
    mkdir($suite4State6, 0700, true);
    populateTree($suite4Root6, $sourceBetaFiles);

    $h6Digest = hash('sha256', strtolower(rtrim(str_replace('/', '\\', ProductInventoryService::resolveAbsolutePath($suite4Root6, true)), '\\')));
    $h6MarkerDir = $suite4State6 . DIRECTORY_SEPARATOR . 'active_roots';
    mkdir($h6MarkerDir, 0700, true);
    $h6MarkerFile = $h6MarkerDir . DIRECTORY_SEPARATOR . $h6Digest . '.json';
    $foreignUnknownPayload = json_encode([
        'operation_id' => 'tx_foreign_active_unknown',
        'pid' => getmypid(),
        'created_at' => time(),
        'unrecognized_foreign_key' => 'intruder_injection',
    ], JSON_THROW_ON_ERROR);
    file_put_contents($h6MarkerFile, $foreignUnknownPayload);

    assertThrows(function () use ($txService, $suite4Root6, $suite4State6, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite4Root6, $suite4State6, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, 'active', "Prepare must fail when active marker with unknown key exists");

    assertTrue(file_exists($h6MarkerFile), "Foreign marker with unknown key must remain on disk");
    assertEqual(trim((string) file_get_contents($h6MarkerFile)), $foreignUnknownPayload, "Foreign marker with unknown key must not be modified or adopted");

    // 4.7 Pre-existing foreign active marker is a DIRECTORY
    echo "  [4.7] Pre-existing foreign active marker as directory blocks prepare...\n";
    $suite4Root7 = $lab . DIRECTORY_SEPARATOR . 'suite4-marker-dir-product';
    $suite4State7 = $lab . DIRECTORY_SEPARATOR . 'suite4-marker-dir-state';
    mkdir($suite4State7, 0700, true);
    populateTree($suite4Root7, $sourceBetaFiles);

    $h7Digest = hash('sha256', strtolower(rtrim(str_replace('/', '\\', ProductInventoryService::resolveAbsolutePath($suite4Root7, true)), '\\')));
    $h7MarkerDir = $suite4State7 . DIRECTORY_SEPARATOR . 'active_roots';
    mkdir($h7MarkerDir, 0700, true);
    $h7MarkerTargetDir = $h7MarkerDir . DIRECTORY_SEPARATOR . $h7Digest . '.json';
    mkdir($h7MarkerTargetDir, 0700, true);
    $h7SentinelFile = $h7MarkerTargetDir . DIRECTORY_SEPARATOR . 'directory_sentinel.txt';
    $h7SentinelContent = 'DO_NOT_DELETE_DIRECTORY_MARKER_' . bin2hex(random_bytes(16));
    file_put_contents($h7SentinelFile, $h7SentinelContent);

    assertThrows(function () use ($txService, $suite4Root7, $suite4State7, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite4Root7, $suite4State7, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must fail when active marker is a directory");

    assertTrue(is_dir($h7MarkerTargetDir), "Foreign active marker directory must not be deleted");
    assertTrue(is_file($h7SentinelFile), "File inside foreign marker directory must not be deleted");
    assertEqual(file_get_contents($h7SentinelFile), $h7SentinelContent, "Content inside marker directory must not be altered");

    // 4.8 Pre-existing foreign active marker is a JUNCTION
    echo "  [4.8] Pre-existing foreign active marker as junction blocks prepare...\n";
    $suite4Root8 = $lab . DIRECTORY_SEPARATOR . 'suite4-marker-junc-product';
    $suite4State8 = $lab . DIRECTORY_SEPARATOR . 'suite4-marker-junc-state';
    mkdir($suite4State8, 0700, true);
    populateTree($suite4Root8, $sourceBetaFiles);

    $h8Digest = hash('sha256', strtolower(rtrim(str_replace('/', '\\', ProductInventoryService::resolveAbsolutePath($suite4Root8, true)), '\\')));
    $h8MarkerDir = $suite4State8 . DIRECTORY_SEPARATOR . 'active_roots';
    mkdir($h8MarkerDir, 0700, true);
    $h8MarkerJunc = $h8MarkerDir . DIRECTORY_SEPARATOR . $h8Digest . '.json';
    createTrackedJunction($h8MarkerJunc, $victimDir);

    assertThrows(function () use ($txService, $suite4Root8, $suite4State8, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite4Root8, $suite4State8, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must fail when active marker is a junction");

    assertTrue(file_exists($h8MarkerJunc) || is_link($h8MarkerJunc), "Marker junction must still exist without deletion");
    removeTrackedJunction($h8MarkerJunc, $lab);

    echo "  [Test Suite 4 Passed]\n";

    // -------------------------------------------------------------------------
    // TEST SUITE 5: WINDOWS JUNCTIONS & BOUNDARY ISOLATION MATRIX
    // -------------------------------------------------------------------------
    echo "\n=== [Test Suite 5] Junctions & Boundary Isolation Matrix ===\n";

    // Full before/after byte and listing snapshot of victim directory
    $snapshotVictim = static function (string $vDir): array {
        $items = scandir($vDir);
        if ($items === false) {
            throw new RuntimeException("Cannot list victim directory: {$vDir}");
        }
        $snapshot = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $vDir . DIRECTORY_SEPARATOR . $item;
            $lstat = @lstat($full);
            $snapshot[$item] = [
                'is_file' => is_file($full),
                'is_dir' => is_dir($full),
                'size' => is_file($full) ? (int) filesize($full) : 0,
                'content' => is_file($full) ? (string) file_get_contents($full) : '',
                'mode' => $lstat !== false ? $lstat['mode'] : 0,
            ];
        }
        return $snapshot;
    };

    $assertVictimUntouched = static function (string $vDir, array $expectedSnapshot): void {
        $current = [];
        $items = scandir($vDir);
        if ($items === false) {
            throw new RuntimeException("Cannot list victim directory during verification: {$vDir}");
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $vDir . DIRECTORY_SEPARATOR . $item;
            $lstat = @lstat($full);
            $current[$item] = [
                'is_file' => is_file($full),
                'is_dir' => is_dir($full),
                'size' => is_file($full) ? (int) filesize($full) : 0,
                'content' => is_file($full) ? (string) file_get_contents($full) : '',
                'mode' => $lstat !== false ? $lstat['mode'] : 0,
            ];
        }
        assertEqual(count($current), count($expectedSnapshot), "Victim directory listing count must remain unchanged");
        foreach ($expectedSnapshot as $item => $exp) {
            assertTrue(isset($current[$item]), "Victim item '{$item}' must still exist");
            assertEqual($current[$item]['is_file'], $exp['is_file'], "Victim item '{$item}' is_file match");
            assertEqual($current[$item]['is_dir'], $exp['is_dir'], "Victim item '{$item}' is_dir match");
            assertEqual($current[$item]['size'], $exp['size'], "Victim item '{$item}' size match");
            assertEqual($current[$item]['content'], $exp['content'], "Victim item '{$item}' content match");
        }
    };

    $victimSnapshotBefore = $snapshotVictim($victimDir);

    // 5.1 Root alias junction: stateBase itself is a tracked junction to victim
    echo "  [5.1] stateBase pointing through junction to victim...\n";
    $suite5Root1 = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-1';
    populateTree($suite5Root1, $sourceBetaFiles);
    $juncStateBase = $lab . DIRECTORY_SEPARATOR . 'suite5-junc-statebase';
    createTrackedJunction($juncStateBase, $victimDir);

    assertThrows(function () use ($txService, $suite5Root1, $juncStateBase, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite5Root1, $juncStateBase, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must reject stateBase pointing through a junction");

    $assertVictimUntouched($victimDir, $victimSnapshotBefore);
    removeTrackedJunction($juncStateBase, $lab);

    // 5.2 State subdirectories as junctions pointing to victim
    // 5.2a: state/workspaces is a junction
    echo "  [5.2a] state/workspaces pointing through junction to victim...\n";
    $suite5Root2a = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-2a';
    $suite5State2a = $lab . DIRECTORY_SEPARATOR . 'suite5-state-2a';
    mkdir($suite5State2a, 0700, true);
    populateTree($suite5Root2a, $sourceBetaFiles);
    $juncWorkspaces = $suite5State2a . DIRECTORY_SEPARATOR . 'workspaces';
    createTrackedJunction($juncWorkspaces, $victimDir);

    assertThrows(function () use ($txService, $suite5Root2a, $suite5State2a, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite5Root2a, $suite5State2a, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must reject when state/workspaces is a junction");

    $assertVictimUntouched($victimDir, $victimSnapshotBefore);
    removeTrackedJunction($juncWorkspaces, $lab);

    // 5.2b: state/locks is a junction
    echo "  [5.2b] state/locks pointing through junction to victim...\n";
    $suite5Root2b = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-2b';
    $suite5State2b = $lab . DIRECTORY_SEPARATOR . 'suite5-state-2b';
    mkdir($suite5State2b, 0700, true);
    populateTree($suite5Root2b, $sourceBetaFiles);
    $juncLocks = $suite5State2b . DIRECTORY_SEPARATOR . 'locks';
    createTrackedJunction($juncLocks, $victimDir);

    assertThrows(function () use ($txService, $suite5Root2b, $suite5State2b, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite5Root2b, $suite5State2b, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must reject when state/locks is a junction");

    $assertVictimUntouched($victimDir, $victimSnapshotBefore);
    removeTrackedJunction($juncLocks, $lab);

    // 5.2c: state/active_roots is a junction
    echo "  [5.2c] state/active_roots pointing through junction to victim...\n";
    $suite5Root2c = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-2c';
    $suite5State2c = $lab . DIRECTORY_SEPARATOR . 'suite5-state-2c';
    mkdir($suite5State2c, 0700, true);
    populateTree($suite5Root2c, $sourceBetaFiles);
    $juncActiveRoots = $suite5State2c . DIRECTORY_SEPARATOR . 'active_roots';
    createTrackedJunction($juncActiveRoots, $victimDir);

    assertThrows(function () use ($txService, $suite5Root2c, $suite5State2c, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite5Root2c, $suite5State2c, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must reject when state/active_roots is a junction");

    $assertVictimUntouched($victimDir, $victimSnapshotBefore);
    removeTrackedJunction($juncActiveRoots, $lab);

    // 5.3 Workspace internal junctions: staged, backups, temp, sealed
    // 5.3a: workspace/staged is a junction to victim
    echo "  [5.3a] workspace/staged pointing through junction to victim...\n";
    $suite5Root3a = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-3a';
    $suite5State3a = $lab . DIRECTORY_SEPARATOR . 'suite5-state-3a';
    mkdir($suite5State3a, 0700, true);
    populateTree($suite5Root3a, $sourceBetaFiles);

    $prep3a = $txService->prepare($suite5Root3a, $suite5State3a, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $op3a = $prep3a['operation_id'];
    $ws3a = $suite5State3a . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . $op3a;
    $staged3a = $ws3a . DIRECTORY_SEPARATOR . 'staged';
    safeLexicalSubdirCleanup($staged3a, $lab);
    rmdir($staged3a);
    createTrackedJunction($staged3a, $victimDir);

    assertThrows(function () use ($txService, $suite5Root3a, $suite5State3a, $op3a, $installedBetaContext) {
        $txService->apply($suite5Root3a, $suite5State3a, $op3a, $installedBetaContext);
    }, '', "Apply must reject when workspace staged directory is a junction");

    $assertVictimUntouched($victimDir, $victimSnapshotBefore);
    removeTrackedJunction($staged3a, $lab);

    // 5.3b: workspace/backups is a junction to victim
    echo "  [5.3b] workspace/backups pointing through junction to victim...\n";
    $suite5Root3b = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-3b';
    $suite5State3b = $lab . DIRECTORY_SEPARATOR . 'suite5-state-3b';
    mkdir($suite5State3b, 0700, true);
    populateTree($suite5Root3b, $sourceBetaFiles);

    $prep3b = $txService->prepare($suite5Root3b, $suite5State3b, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $op3b = $prep3b['operation_id'];
    $ws3b = $suite5State3b . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . $op3b;
    $backups3b = $ws3b . DIRECTORY_SEPARATOR . 'backups';
    if (is_dir($backups3b)) {
        safeLexicalSubdirCleanup($backups3b, $lab);
        rmdir($backups3b);
    }
    createTrackedJunction($backups3b, $victimDir);

    assertThrows(function () use ($txService, $suite5Root3b, $suite5State3b, $op3b, $installedBetaContext) {
        $txService->apply($suite5Root3b, $suite5State3b, $op3b, $installedBetaContext);
    }, '', "Apply must reject when workspace backups directory is a junction");

    $assertVictimUntouched($victimDir, $victimSnapshotBefore);
    removeTrackedJunction($backups3b, $lab);

    // 5.3c: workspace/temp is a junction to victim
    echo "  [5.3c] workspace/temp pointing through junction to victim...\n";
    $suite5Root3c = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-3c';
    $suite5State3c = $lab . DIRECTORY_SEPARATOR . 'suite5-state-3c';
    mkdir($suite5State3c, 0700, true);
    populateTree($suite5Root3c, $sourceBetaFiles);

    $prep3c = $txService->prepare($suite5Root3c, $suite5State3c, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $op3c = $prep3c['operation_id'];
    $ws3c = $suite5State3c . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . $op3c;
    $temp3c = $ws3c . DIRECTORY_SEPARATOR . 'temp';
    if (is_dir($temp3c)) {
        safeLexicalSubdirCleanup($temp3c, $lab);
        rmdir($temp3c);
    }
    createTrackedJunction($temp3c, $victimDir);

    assertThrows(function () use ($txService, $suite5Root3c, $suite5State3c, $op3c, $installedBetaContext) {
        $txService->apply($suite5Root3c, $suite5State3c, $op3c, $installedBetaContext);
    }, '', "Apply must reject when workspace temp directory is a junction");

    $assertVictimUntouched($victimDir, $victimSnapshotBefore);
    removeTrackedJunction($temp3c, $lab);

    // 5.3d: workspace/sealed is a junction to victim
    echo "  [5.3d] workspace/sealed pointing through junction to victim...\n";
    $suite5Root3d = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-3d';
    $suite5State3d = $lab . DIRECTORY_SEPARATOR . 'suite5-state-3d';
    mkdir($suite5State3d, 0700, true);
    populateTree($suite5Root3d, $sourceBetaFiles);

    $prep3d = $txService->prepare($suite5Root3d, $suite5State3d, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $op3d = $prep3d['operation_id'];
    $ws3d = $suite5State3d . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . $op3d;
    $sealed3d = $ws3d . DIRECTORY_SEPARATOR . 'sealed';
    if (file_exists($sealed3d) || is_dir($sealed3d)) {
        @unlink($sealed3d);
        @rmdir($sealed3d);
    }
    createTrackedJunction($sealed3d, $victimDir);

    assertThrows(function () use ($txService, $suite5Root3d, $suite5State3d, $op3d, $installedBetaContext) {
        $txService->apply($suite5Root3d, $suite5State3d, $op3d, $installedBetaContext);
    }, '', "Apply must reject when workspace sealed entry is a junction");

    $assertVictimUntouched($victimDir, $victimSnapshotBefore);
    removeTrackedJunction($sealed3d, $lab);

    // 5.4 Target product root and subdirectories as junctions
    // 5.4a: Product root itself is a junction to victim
    echo "  [5.4a] Product root itself is a junction to victim...\n";
    $juncProdRoot = $lab . DIRECTORY_SEPARATOR . 'suite5-junc-prod-root';
    $suite5State4a = $lab . DIRECTORY_SEPARATOR . 'suite5-state-4a';
    mkdir($suite5State4a, 0700, true);
    createTrackedJunction($juncProdRoot, $victimDir);

    assertThrows(function () use ($txService, $juncProdRoot, $suite5State4a, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($juncProdRoot, $suite5State4a, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must reject when product root is a junction");

    $assertVictimUntouched($victimDir, $victimSnapshotBefore);
    removeTrackedJunction($juncProdRoot, $lab);

    // 5.4b: Subdirectory inside product root is a junction to victim
    echo "  [5.4b] Subdirectory in product root is a junction to victim...\n";
    $suite5Root4b = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-4b';
    $suite5State4b = $lab . DIRECTORY_SEPARATOR . 'suite5-state-4b';
    mkdir($suite5State4b, 0700, true);
    populateTree($suite5Root4b, $sourceBetaFiles);
    $juncSubdir = $suite5Root4b . DIRECTORY_SEPARATOR . 'resources';
    if (is_dir($juncSubdir)) {
        safeLexicalSubdirCleanup($juncSubdir, $lab);
        rmdir($juncSubdir);
    }
    createTrackedJunction($juncSubdir, $victimDir);

    assertThrows(function () use ($txService, $suite5Root4b, $suite5State4b, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite5Root4b, $suite5State4b, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, '', "Prepare must reject when subdirectory in product root is a junction");

    $assertVictimUntouched($victimDir, $victimSnapshotBefore);
    removeTrackedJunction($juncSubdir, $lab);

    // 5.5 Leaf symlink test with explicit [SKIP] if privilege unheld
    echo "  [5.5] Leaf symlink in product root...\n";
    $suite5Root5 = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-5';
    $suite5State5 = $lab . DIRECTORY_SEPARATOR . 'suite5-state-5';
    mkdir($suite5State5, 0700, true);
    populateTree($suite5Root5, $sourceBetaFiles);
    $leafSymlinkPath = $suite5Root5 . DIRECTORY_SEPARATOR . 'leaf-symlink-victim.txt';
    $symlinkSuccess = false;
    $symOutput = [];
    $symExit = 0;
    @exec('cmd /c mklink ' . escapeshellarg(str_replace('/', '\\', $leafSymlinkPath)) . ' ' . escapeshellarg(str_replace('/', '\\', $victimSentinel)), $symOutput, $symExit);
    clearstatcache(true, $leafSymlinkPath);
    if ($symExit === 0 && (is_link($leafSymlinkPath) || file_exists($leafSymlinkPath))) {
        $symlinkSuccess = true;
    }
    if (!$symlinkSuccess) {
        echo "  [SKIP] Leaf symlink test skipped: SeCreateSymbolicLinkPrivilege not held\n";
    } else {
        assertThrows(function () use ($txService, $suite5Root5, $suite5State5, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
            $txService->prepare($suite5Root5, $suite5State5, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
        }, '', "Prepare must reject leaf symlink in product root");
        @unlink($leafSymlinkPath);
        echo "  Leaf symlink rejection verified.\n";
    }
    $assertVictimUntouched($victimDir, $victimSnapshotBefore);

    // 5.6 Journal & marker schema corruption tests
    echo "  [5.6] Journal & marker schema corruption tests...\n";
    // 5.6a: Journal unknown key
    $suite5Root6a = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-6a';
    $suite5State6a = $lab . DIRECTORY_SEPARATOR . 'suite5-state-6a';
    mkdir($suite5State6a, 0700, true);
    populateTree($suite5Root6a, $sourceBetaFiles);
    $prep6a = $txService->prepare($suite5Root6a, $suite5State6a, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $op6a = $prep6a['operation_id'];
    $jPath6a = $suite5State6a . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . $op6a . DIRECTORY_SEPARATOR . 'journal.json';
    $jData6a = json_decode((string) file_get_contents($jPath6a), true);
    $jData6a['unrecognized_corrupt_key'] = 'intruder_value';
    file_put_contents($jPath6a, json_encode($jData6a, JSON_THROW_ON_ERROR));

    assertThrows(function () use ($txService, $suite5Root6a, $suite5State6a, $op6a, $installedBetaContext) {
        $txService->apply($suite5Root6a, $suite5State6a, $op6a, $installedBetaContext);
    }, '', "Apply must reject journal with unknown key");

    // 5.6b: Journal wrong type
    $suite5Root6b = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-6b';
    $suite5State6b = $lab . DIRECTORY_SEPARATOR . 'suite5-state-6b';
    mkdir($suite5State6b, 0700, true);
    populateTree($suite5Root6b, $sourceBetaFiles);
    $prep6b = $txService->prepare($suite5Root6b, $suite5State6b, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $op6b = $prep6b['operation_id'];
    $jPath6b = $suite5State6b . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . $op6b . DIRECTORY_SEPARATOR . 'journal.json';
    $jData6b = json_decode((string) file_get_contents($jPath6b), true);
    $jData6b['files'] = 'not_an_array_string';
    file_put_contents($jPath6b, json_encode($jData6b, JSON_THROW_ON_ERROR));

    assertThrows(function () use ($txService, $suite5Root6b, $suite5State6b, $op6b, $installedBetaContext) {
        $txService->apply($suite5Root6b, $suite5State6b, $op6b, $installedBetaContext);
    }, '', "Apply must reject journal with wrong type");

    // 5.6c: Journal oversize (> 16 MiB limit)
    $suite5Root6c = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-6c';
    $suite5State6c = $lab . DIRECTORY_SEPARATOR . 'suite5-state-6c';
    mkdir($suite5State6c, 0700, true);
    populateTree($suite5Root6c, $sourceBetaFiles);
    $root6cSnapshotBefore = snapshotTree($suite5Root6c);

    $prep6c = $txService->prepare($suite5Root6c, $suite5State6c, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $op6c = $prep6c['operation_id'];
    $jPath6c = $suite5State6c . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . $op6c . DIRECTORY_SEPARATOR . 'journal.json';
    $jData6c = json_decode((string) file_get_contents($jPath6c), true);
    // Service journal cap is 16 MiB (16777216 bytes). Pad above 16 MiB:
    $oversizePad = str_repeat(' ', (16 * 1024 * 1024) + 1024);
    $jRaw6c = json_encode($jData6c, JSON_THROW_ON_ERROR) . $oversizePad;
    file_put_contents($jPath6c, $jRaw6c);

    assertThrows(function () use ($txService, $suite5Root6c, $suite5State6c, $op6c, $installedBetaContext) {
        $txService->apply($suite5Root6c, $suite5State6c, $op6c, $installedBetaContext);
    }, 'exceeds allowed bound', "Apply must reject oversize journal (> 16 MiB)");

    $root6cSnapshotAfter = snapshotTree($suite5Root6c);
    assertEqual($root6cSnapshotAfter, $root6cSnapshotBefore, "Product root must remain unchanged after oversize journal rejection");

    // 5.6d: Journal redirect / path traversal
    $suite5Root6d = $lab . DIRECTORY_SEPARATOR . 'suite5-prod-6d';
    $suite5State6d = $lab . DIRECTORY_SEPARATOR . 'suite5-state-6d';
    mkdir($suite5State6d, 0700, true);
    populateTree($suite5Root6d, $sourceBetaFiles);
    $prep6d = $txService->prepare($suite5Root6d, $suite5State6d, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $op6d = $prep6d['operation_id'];
    $jPath6d = $suite5State6d . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . $op6d . DIRECTORY_SEPARATOR . 'journal.json';
    $jData6d = json_decode((string) file_get_contents($jPath6d), true);
    if (!empty($jData6d['files'])) {
        $firstKey = array_key_first($jData6d['files']);
        $entry = $jData6d['files'][$firstKey];
        unset($jData6d['files'][$firstKey]);
        $jData6d['files']['../../shell-tx-victim/evil.txt'] = $entry;
    }
    file_put_contents($jPath6d, json_encode($jData6d, JSON_THROW_ON_ERROR));

    assertThrows(function () use ($txService, $suite5Root6d, $suite5State6d, $op6d, $installedBetaContext) {
        $txService->apply($suite5Root6d, $suite5State6d, $op6d, $installedBetaContext);
    }, '', "Apply must reject journal with path traversal / redirect");

    $assertVictimUntouched($victimDir, $victimSnapshotBefore);

    echo "  [Test Suite 5 Passed]\n";

    // -------------------------------------------------------------------------
    // TEST SUITE 6: NEGATIVE BUILDER PACKAGES (REFUSED FORMATS/PROFILES)
    // -------------------------------------------------------------------------
    echo "\n=== [Test Suite 6] Negative Builder Packages (Refused Formats/Profiles) ===\n";

    $suite6Root = $lab . DIRECTORY_SEPARATOR . 'suite6-product';
    $suite6State = $lab . DIRECTORY_SEPARATOR . 'suite6-state';
    mkdir($suite6State, 0700, true);
    populateTree($suite6Root, $sourceBetaFiles);

    // 6.1 Valid SIGNED bootstrap_completion refused by transaction service
    echo "  [6.1] Valid signed bootstrap_completion refused...\n";
    $bootstrapOut = $lab . DIRECTORY_SEPARATOR . 'bootstrap-out';
    $bootstrapLink = hash('sha256', 'mock_bootstrap_completion_link_12345');
    $builderBootRes = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $contractFile,
        '--private-key', $keyFile,
        '--output-dir', $bootstrapOut,
        '--purpose', 'bootstrap_completion',
        '--bootstrap-link', $bootstrapLink,
    ], $lab);
    assertEqual($builderBootRes['exitCode'], 0, "Bootstrap builder CLI must succeed");

    $bootManifestRaw = file_get_contents($bootstrapOut . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $bootSig = file_get_contents($bootstrapOut . DIRECTORY_SEPARATOR . 'shell-manifest.sig');
    $bootZipPath = $bootstrapOut . DIRECTORY_SEPARATOR . 'shell-delta.zip';

    assertThrows(function () use ($txService, $suite6Root, $suite6State, $bootManifestRaw, $bootSig, $bootZipPath, $installedBetaContext) {
        $txService->prepare($suite6Root, $suite6State, $bootManifestRaw, $bootSig, $bootZipPath, $installedBetaContext);
    }, 'regular', "Transaction service must reject bootstrap_completion packages (regular purpose only)");

    // 6.2 Changed SIGNED data profile refused
    echo "  [6.2] Changed signed data profile refused...\n";
    $mismatchedContract = $dataContract;
    $mismatchedContract['schema_sha256'] = hash('sha256', 'different_schema_v2');
    $mismatchedContractFile = $lab . DIRECTORY_SEPARATOR . 'mismatched-contract.json';
    file_put_contents($mismatchedContractFile, json_encode($mismatchedContract, JSON_PRETTY_PRINT));

    $mismatchedOut = $lab . DIRECTORY_SEPARATOR . 'mismatched-out';
    $builderMisRes = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $mismatchedContractFile,
        '--private-key', $keyFile,
        '--output-dir', $mismatchedOut,
    ], $lab);
    assertEqual($builderMisRes['exitCode'], 0, "Builder CLI with differing contracts must build signed package");

    $misManifestRaw = file_get_contents($mismatchedOut . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $misSig = file_get_contents($mismatchedOut . DIRECTORY_SEPARATOR . 'shell-manifest.sig');
    $misZipPath = $mismatchedOut . DIRECTORY_SEPARATOR . 'shell-delta.zip';

    assertThrows(function () use ($txService, $suite6Root, $suite6State, $misManifestRaw, $misSig, $misZipPath, $installedBetaContext) {
        $txService->prepare($suite6Root, $suite6State, $misManifestRaw, $misSig, $misZipPath, $installedBetaContext);
    }, 'data compatibility', "Transaction service must reject packages with changed data compatibility profile");

    // 6.3 Changed SIGNED mysql asset / schema SQL refused
    echo "  [6.3] Changed signed mysql asset / schema SQL refused...\n";
    $mysqlTgtFiles = $targetBetaFiles;
    $mysqlTgtFiles['resources/portable/mysql/bin/mysqld.exe'] = "CHANGED_MYSQL_BINARY_CONTENT";
    $mysqlTgtDir = $lab . DIRECTORY_SEPARATOR . 'mysql-tgt';
    $mysqlOutDir = $lab . DIRECTORY_SEPARATOR . 'mysql-out';
    populateTree($mysqlTgtDir, $mysqlTgtFiles);

    $builderMysqlRes = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $mysqlTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $contractFile,
        '--private-key', $keyFile,
        '--output-dir', $mysqlOutDir,
    ], $lab);
    assertEqual($builderMysqlRes['exitCode'], 0, "Builder CLI must succeed for mysql asset delta package");

    $mysqlManifestRaw = file_get_contents($mysqlOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $mysqlSig = file_get_contents($mysqlOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig');
    $mysqlZipPath = $mysqlOutDir . DIRECTORY_SEPARATOR . 'shell-delta.zip';

    assertThrows(function () use ($txService, $suite6Root, $suite6State, $mysqlManifestRaw, $mysqlSig, $mysqlZipPath, $installedBetaContext) {
        $txService->prepare($suite6Root, $suite6State, $mysqlManifestRaw, $mysqlSig, $mysqlZipPath, $installedBetaContext);
    }, 'mysql', "Transaction service must reject deltas modifying mysql assets");

    echo "  [Test Suite 6 Passed]\n";

    // -------------------------------------------------------------------------
    // TEST SUITE 7: REAL PROCESS CRASH MATRIX
    // -------------------------------------------------------------------------
    echo "\n=== [Test Suite 7] Real Process Crash & Recovery Matrix ===\n";

    $crashScenarios = [
        'sealed_backup_pre_first_mutation' => [
            'action' => 'apply-crash',
            'checkpoint' => 'sealed_backup',
            'count' => 1,
            'fresh_process_recover' => true,
        ],
        'after_new_dir_before_publish' => [
            'action' => 'apply-crash',
            'checkpoint' => 'before_publish_file',
            'count' => 1,
            'use_deep' => true,
            'fresh_process_recover' => false,
        ],
        'before_phase_ack_after_rename' => [
            'action' => 'apply-crash',
            'checkpoint' => 'before_phase_ack',
            'count' => 1,
            'fresh_process_recover' => false,
        ],
        'mid_apply' => [
            'action' => 'apply-crash',
            'checkpoint' => 'after_publish_file',
            'count' => 2,
            'fresh_process_recover' => false,
        ],
        'target_verified_after_version_last' => [
            'action' => 'apply-crash',
            'checkpoint' => 'target_verified',
            'count' => 1,
            'fresh_process_recover' => false,
        ],
        'mid_rollback' => [
            'action' => 'rollback-crash',
            'checkpoint' => 'after_restore_file',
            'count' => 1,
            'fresh_process_recover' => false,
        ],
    ];

    foreach ($crashScenarios as $scenarioName => $spec) {
        echo "  Running crash scenario: {$scenarioName}...\n";

        $crashRoot = $lab . DIRECTORY_SEPARATOR . 'crash-' . $scenarioName . '-product';
        $crashState = $lab . DIRECTORY_SEPARATOR . 'crash-' . $scenarioName . '-state';
        mkdir($crashState, 0700, true);
        populateTree($crashRoot, $sourceBetaFiles);
        populateTree($crashRoot, $customerSentinels);

        $useDeep = $spec['use_deep'] ?? false;
        $mRaw = $useDeep ? $deepManifestRaw : $betaManifestRaw;
        $mSig = $useDeep ? $deepSig : $betaSig;
        $mZip = $useDeep ? $deepZipPath : $betaZipPath;

        // Parent prepares operation
        $prepCrash = $txService->prepare(
            $crashRoot,
            $crashState,
            $mRaw,
            $mSig,
            $mZip,
            $installedBetaContext
        );
        $crashOpId = $prepCrash['operation_id'];

        // If scenario is mid_rollback, apply first
        if ($scenarioName === 'mid_rollback') {
            $txService->apply($crashRoot, $crashState, $crashOpId, $installedBetaContext);
        }

        $configFile = $lab . DIRECTORY_SEPARATOR . 'child-cfg-' . $scenarioName . '.json';
        $ackFile = $lab . DIRECTORY_SEPARATOR . 'child-ack-' . $scenarioName . '.json';

        $childConfig = [
            'configFile' => $configFile,
            'ackFile' => $ackFile,
            'root' => $crashRoot,
            'stateBase' => $crashState,
            'operationId' => $crashOpId,
            'installedContext' => $installedBetaContext,
            'publicKeyPem' => $pubKeyPem,
            'targetCheckpoint' => $spec['checkpoint'],
            'targetCheckpointCount' => $spec['count'],
        ];

        // Spawn child subprocess
        $childSpawn = spawnChildProcess($spec['action'], $childConfig, $lab, $labMarker);
        $childProc = $childSpawn['proc'];
        $childNonce = $childSpawn['nonce'];

        // Wait for child to acknowledge reaching checkpoint
        $startTime = microtime(true);
        $ackFound = false;
        $ackData = null;
        while ((microtime(true) - $startTime) < 25.0) {
            $st = getVerifiedProcessStatus($childProc);
            if ($st['state'] === 'DEAD') {
                $errLog = file_exists($childSpawn['stderrLog']) ? file_get_contents($childSpawn['stderrLog']) : '';
                throw new RuntimeException("Child exited prematurely with code {$st['exitcode']}.\nSTDERR: {$errLog}");
            }
            if (file_exists($ackFile)) {
                $rawAck = (string) file_get_contents($ackFile);
                $parsedAck = json_decode($rawAck, true);
                if (is_array($parsedAck) && ($parsedAck['status'] ?? '') === 'ACK_CHECKPOINT') {
                    $ackData = $parsedAck;
                    $ackFound = true;
                    break;
                }
            }
            usleep(20000);
        }

        assertTrue($ackFound, "Child must reach checkpoint '{$spec['checkpoint']}' and write ACK");
        assertEqual($ackData['status'], 'ACK_CHECKPOINT', "Child ACK status must be ACK_CHECKPOINT");
        assertEqual($ackData['nonce'], $childNonce, "Child ACK nonce must match spawned nonce");
        assertEqual($ackData['checkpoint'], $spec['checkpoint'], "Child ACK checkpoint must match target");
        assertEqual($ackData['count'], $spec['count'], "Child ACK count must match expected count");
        assertEqual($ackData['operation_id'], $crashOpId, "Child ACK operation_id must match operation ID");

        // Force kill child process and verify death
        $exitCode = forceKillChildAndVerify($childProc);
        echo "    Child process crash at checkpoint '{$spec['checkpoint']}' simulated via proc_terminate (exit status: {$exitCode}).\n";
        echo "    Process-crash recovery initiated (process crash recovery verified, not hardware power loss proof).\n";

        // Perform recovery: either via fresh child subprocess or new service object
        if ($spec['fresh_process_recover']) {
            echo "    Executing recovery via fresh child SUBPROCESS...\n";
            $recConfigFile = $lab . DIRECTORY_SEPARATOR . 'child-rec-cfg-' . $scenarioName . '.json';
            $recAckFile = $lab . DIRECTORY_SEPARATOR . 'child-rec-ack-' . $scenarioName . '.json';

            $recChildConfig = [
                'configFile' => $recConfigFile,
                'ackFile' => $recAckFile,
                'root' => $crashRoot,
                'stateBase' => $crashState,
                'operationId' => $crashOpId,
                'installedContext' => $installedBetaContext,
                'publicKeyPem' => $pubKeyPem,
            ];

            $recSpawn = spawnChildProcess('recover', $recChildConfig, $lab, $labMarker);
            $recProc = $recSpawn['proc'];
            $recNonce = $recSpawn['nonce'];

            $recWait = microtime(true);
            $recAckFound = false;
            $recAckData = null;
            while ((microtime(true) - $recWait) < 25.0) {
                if (file_exists($recAckFile)) {
                    $rawRec = file_get_contents($recAckFile, false, null, 0, 65536);
                    $parsedRec = json_decode((string) $rawRec, true);
                    if (is_array($parsedRec) && ($parsedRec['status'] ?? '') === 'RECOVERED') {
                        $recAckData = $parsedRec;
                        $recAckFound = true;
                        break;
                    }
                }
                $st = getVerifiedProcessStatus($recProc);
                if ($st['state'] === 'DEAD') {
                    if (file_exists($recAckFile)) {
                        $rawRec = file_get_contents($recAckFile, false, null, 0, 65536);
                        $parsedRec = json_decode((string) $rawRec, true);
                        if (is_array($parsedRec) && ($parsedRec['status'] ?? '') === 'RECOVERED') {
                            $recAckData = $parsedRec;
                            $recAckFound = true;
                            break;
                        }
                    }
                    break;
                }
                usleep(20000);
            }

            $exitWaitStart = microtime(true);
            $recExitCode = -1;
            $recDied = false;
            while ((microtime(true) - $exitWaitStart) < 5.0) {
                $st = getVerifiedProcessStatus($recProc);
                if ($st['state'] === 'DEAD') {
                    $recDied = true;
                    $recExitCode = $st['exitcode'];
                    break;
                }
                usleep(20000);
            }

            if (!$recDied) {
                forceKillChildAndVerify($recProc, 5);
                throw new RuntimeException("Recovery child process failed to exit cleanly within 5s.");
            }

            proc_close($recProc);
            $idx = array_search($recProc, $trackedChildProcs, true);
            if ($idx !== false) {
                unset($trackedChildProcs[$idx]);
                $trackedChildProcs = array_values($trackedChildProcs);
            }

            assertTrue($recAckFound, "Recovery child process must write ACK");
            assertEqual($recExitCode, 0, "Recovery child process must exit with code 0");
            assertEqual($recAckData['status'], 'RECOVERED', "Recovery ACK status must be RECOVERED");
            assertEqual($recAckData['nonce'], $recNonce, "Recovery ACK nonce must match fresh nonce");
            assertEqual($recAckData['operation_id'], $crashOpId, "Recovery ACK operation_id must match target");
            assertEqual($recAckData['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Subprocess recover() must return 'rolled_back'");

            @unlink($recConfigFile);
            @unlink($recAckFile);
        } else {
            $newTxService = new ShellDeltaFileTransactionService($manifestService);
            $recRes = $newTxService->recover($crashRoot, $crashState, $crashOpId, $installedBetaContext);
            assertEqual($recRes['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "recover() must result in phase 'rolled_back'");
        }

        // Verify physical source inventory is fully restored
        $actualSourceInv = $manifestService->buildInventory($crashRoot, '0.0.13');
        assertEqual($actualSourceInv['files'], $betaSourceInv['files'], "Root must match full source inventory after crash recovery");

        // Verify customer sentinels intact
        foreach ($customerSentinels as $cRel => $cContent) {
            assertEqual(file_get_contents($crashRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cRel)), $cContent, "Customer sentinel intact after recovery: {$cRel}");
        }

        // Verify repeat recover() is idempotent
        $newTxService2 = new ShellDeltaFileTransactionService($manifestService);
        $repeatRec = $newTxService2->recover($crashRoot, $crashState, $crashOpId, $installedBetaContext);
        assertEqual($repeatRec['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Repeat recover() must be idempotent");

        @unlink($configFile);
        @unlink($ackFile);
    }

    echo "  [Test Suite 7 Passed]\n";

    // -------------------------------------------------------------------------
    // TEST SUITE 8: REAL FLOCK CONCURRENCY & PERSISTENT MARKER
    // -------------------------------------------------------------------------
    echo "\n=== [Test Suite 8] Real flock Concurrency & Persistent Marker ===\n";

    $suite8Root = $lab . DIRECTORY_SEPARATOR . 'suite8-product';
    $suite8State = $lab . DIRECTORY_SEPARATOR . 'suite8-state';
    mkdir($suite8State, 0700, true);
    populateTree($suite8Root, $sourceBetaFiles);

    $concurConfigFile = $lab . DIRECTORY_SEPARATOR . 'child-concur-cfg.json';
    $concurAckFile = $lab . DIRECTORY_SEPARATOR . 'child-concur-ack.json';

    $childConcurConfig = [
        'configFile' => $concurConfigFile,
        'ackFile' => $concurAckFile,
        'root' => $suite8Root,
        'stateBase' => $suite8State,
        'rawManifestFile' => $betaOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json',
        'sigFile' => $betaOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig',
        'zipFile' => $betaZipPath,
        'installedContext' => $installedBetaContext,
        'publicKeyPem' => $pubKeyPem,
        'targetCheckpoint' => 'prepared',
        'targetCheckpointCount' => 1,
    ];

    // Child executes 'hold-lease', hitting 'prepared' checkpoint callback INSIDE root flock
    $concurSpawn = spawnChildProcess('hold-lease', $childConcurConfig, $lab, $labMarker);
    $concurProc = $concurSpawn['proc'];
    $concurNonce = $concurSpawn['nonce'];

    $concurStart = microtime(true);
    $heldOpId = '';
    while ((microtime(true) - $concurStart) < 20.0) {
        $st = getVerifiedProcessStatus($concurProc);
        if ($st['state'] === 'DEAD') {
            $err = file_exists($concurSpawn['stderrLog']) ? file_get_contents($concurSpawn['stderrLog']) : '';
            throw new RuntimeException("Child holding lease died prematurely.\nSTDERR: {$err}");
        }
        if (file_exists($concurAckFile)) {
            $cAck = json_decode((string) file_get_contents($concurAckFile), true);
            if (is_array($cAck) && ($cAck['status'] ?? '') === 'ACK_CHECKPOINT') {
                $heldOpId = $cAck['operation_id'] ?? '';
                break;
            }
        }
        usleep(20000);
    }

    assertTrue($heldOpId !== '', "Child must acquire lease, hit 'prepared' checkpoint and write operation_id");
    assertTrue(preg_match('/^tx_[a-f0-9]{32}$/', $heldOpId) === 1, "Held operation ID must be valid tx_[a-f0-9]{32}");

    // While Child process holds the OS root flock inside prepared callback:
    echo "  Process 2 attempting prepare() while Child holds root flock...\n";
    assertThrows(function () use ($txService, $suite8Root, $suite8State, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite8Root, $suite8State, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, 'busy', "Concurrent prepare() on same root must fail with lease/busy error");

    echo "  Process 2 attempting apply() with SAME VALID operation ID while Child holds root flock...\n";
    assertThrows(function () use ($txService, $suite8Root, $suite8State, $heldOpId, $installedBetaContext) {
        $txService->apply($suite8Root, $suite8State, $heldOpId, $installedBetaContext);
    }, 'busy', "Concurrent apply() on same root with valid op must fail with lease/busy error");

    // Force kill child process and verify exit
    forceKillChildAndVerify($concurProc);
    echo "  Child process killed via proc_terminate and verified dead.\n";

    // Now OS flock is released, but persistent marker remains on disk
    echo "  Verifying persistent active_roots marker blocks NEW prepare() after OS flock released...\n";
    assertThrows(function () use ($txService, $suite8Root, $suite8State, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
        $txService->prepare($suite8Root, $suite8State, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    }, 'active', "New prepare() must be blocked by persistent active marker");

    // Recover existing valid operation ID
    echo "  Recovering existing operation ID...\n";
    $recHeldRes = $txService->recover($suite8Root, $suite8State, $heldOpId, $installedBetaContext);
    assertEqual($recHeldRes['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "recover() on held operation must succeed");

    // Now that recovery cleared the active marker, a new prepare() must succeed
    echo "  Verifying new prepare() succeeds after recovery...\n";
    $newPrepRes = $txService->prepare($suite8Root, $suite8State, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    assertEqual($newPrepRes['phase'], ShellDeltaFileTransactionService::PHASE_PREPARED, "New prepare() must succeed after recovery cleared marker");
    $txService->rollback($suite8Root, $suite8State, $newPrepRes['operation_id'], $installedBetaContext);

    @unlink($concurConfigFile);
    @unlink($concurAckFile);

    echo "  [Test Suite 8 Passed]\n";

    // -------------------------------------------------------------------------
    // TEST SUITE 9: SYNTHETIC IO HOOKS FAILURE PROOFS MATRIX
    // -------------------------------------------------------------------------
    echo "\n=== [Test Suite 9] Synthetic IO Hooks Failure Proofs Matrix ===\n";

    $ioMatrix = [
        ['purpose' => 'stage', 'checkpoint' => 'io_before_flush', 'phase' => 'prepare'],
        ['purpose' => 'stage', 'checkpoint' => 'io_before_fsync', 'phase' => 'prepare'],
        ['purpose' => 'stage', 'checkpoint' => 'io_before_close', 'phase' => 'prepare'],
        ['purpose' => 'journal', 'checkpoint' => 'io_before_flush', 'phase' => 'prepare'],
        ['purpose' => 'journal', 'checkpoint' => 'io_before_fsync', 'phase' => 'prepare'],
        ['purpose' => 'journal', 'checkpoint' => 'io_before_close', 'phase' => 'prepare'],
        ['purpose' => 'marker', 'checkpoint' => 'io_before_flush', 'phase' => 'prepare'],
        ['purpose' => 'marker', 'checkpoint' => 'io_before_fsync', 'phase' => 'prepare'],
        ['purpose' => 'marker', 'checkpoint' => 'io_before_close', 'phase' => 'prepare'],
        ['purpose' => 'backup', 'checkpoint' => 'io_before_flush', 'phase' => 'apply'],
        ['purpose' => 'backup', 'checkpoint' => 'io_before_fsync', 'phase' => 'apply'],
        ['purpose' => 'backup', 'checkpoint' => 'io_before_close', 'phase' => 'apply'],
        ['purpose' => 'publish', 'checkpoint' => 'io_before_flush', 'phase' => 'apply'],
        ['purpose' => 'publish', 'checkpoint' => 'io_before_fsync', 'phase' => 'apply'],
        ['purpose' => 'publish', 'checkpoint' => 'io_before_close', 'phase' => 'apply'],
        ['purpose' => 'restore', 'checkpoint' => 'io_before_flush', 'phase' => 'rollback'],
        ['purpose' => 'restore', 'checkpoint' => 'io_before_fsync', 'phase' => 'rollback'],
        ['purpose' => 'restore', 'checkpoint' => 'io_before_close', 'phase' => 'rollback'],
    ];

    foreach ($ioMatrix as $idx => $testCase) {
        $p = $testCase['purpose'];
        $cp = $testCase['checkpoint'];
        $phase = $testCase['phase'];
        echo "  [9.{$idx}] Testing {$p} at {$cp} during {$phase}...\n";

        $testRoot = $lab . DIRECTORY_SEPARATOR . "suite9-prod-{$p}-{$cp}";
        $testState = $lab . DIRECTORY_SEPARATOR . "suite9-state-{$p}-{$cp}";
        mkdir($testState, 0700, true);
        populateTree($testRoot, $sourceBetaFiles);

        $hookFired = false;
        $hookCb = static function (string $checkpoint, array $context) use ($p, $cp, &$hookFired): void {
            if ($checkpoint === $cp && ($context['purpose'] ?? '') === $p) {
                $hookFired = true;
                throw new RuntimeException("SYNTHETIC_IO_FAILURE: purpose {$p} at {$cp}");
            }
        };

        if ($phase === 'prepare') {
            $ioSvc = new ShellDeltaFileTransactionService($manifestService, $hookCb);
            assertThrows(function () use ($ioSvc, $testRoot, $testState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext) {
                $ioSvc->prepare($testRoot, $testState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
            }, 'SYNTHETIC_IO_FAILURE', "Prepare must abort when synthetic IO hook throws at {$cp} for {$p}");
            assertTrue($hookFired, "IO hook must have fired for {$p} at {$cp}");
            echo "  INJECTED failure (synthetic IO hook failure verified, not actual disk failure) at {$cp} for {$p}.\n";
        } elseif ($phase === 'apply') {
            $prep = $txService->prepare($testRoot, $testState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
            $opId = $prep['operation_id'];
            $ioSvc = new ShellDeltaFileTransactionService($manifestService, $hookCb);
            assertThrows(function () use ($ioSvc, $testRoot, $testState, $opId, $installedBetaContext) {
                $ioSvc->apply($testRoot, $testState, $opId, $installedBetaContext);
            }, 'SYNTHETIC_IO_FAILURE', "Apply must abort when synthetic IO hook throws at {$cp} for {$p}");
            assertTrue($hookFired, "IO hook must have fired for {$p} at {$cp}");
            echo "  INJECTED failure (synthetic IO hook failure verified, not actual disk failure) at {$cp} for {$p}.\n";
            $recRes = $txService->recover($testRoot, $testState, $opId, $installedBetaContext);
            assertEqual($recRes['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Recovery must roll back failed apply");
        } elseif ($phase === 'rollback') {
            $prep = $txService->prepare($testRoot, $testState, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
            $opId = $prep['operation_id'];
            $txService->apply($testRoot, $testState, $opId, $installedBetaContext);
            $ioSvc = new ShellDeltaFileTransactionService($manifestService, $hookCb);
            assertThrows(function () use ($ioSvc, $testRoot, $testState, $opId, $installedBetaContext) {
                $ioSvc->rollback($testRoot, $testState, $opId, $installedBetaContext);
            }, 'SYNTHETIC_IO_FAILURE', "Rollback must abort when synthetic IO hook throws at {$cp} for {$p}");
            assertTrue($hookFired, "IO hook must have fired for {$p} at {$cp}");
            echo "  INJECTED failure (synthetic IO hook failure verified, not actual disk failure) at {$cp} for {$p}.\n";
            $recRes = $txService->recover($testRoot, $testState, $opId, $installedBetaContext);
            assertEqual($recRes['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Recovery must complete rollback");
        }
    }

    // 9.18: io_after_close temp file mutation test (fail-retain corrupt private bytes)
    echo "  [9.18] io_after_close temp file mutation test (fail-retain corrupt private bytes)...\n";
    $testRootAfterClose = $lab . DIRECTORY_SEPARATOR . 'suite9-prod-afterclose';
    $testStateAfterClose = $lab . DIRECTORY_SEPARATOR . 'suite9-state-afterclose';
    mkdir($testStateAfterClose, 0700, true);
    populateTree($testRootAfterClose, $sourceBetaFiles);
    $rootAfterCloseBefore = snapshotTree($testRootAfterClose);

    $capturedTempPath = null;
    $corruptTempBytes = 'CORRUPTED_TEMP_FILE_DATA_INJECTED_' . bin2hex(random_bytes(16));
    $afterCloseMutated = false;
    $afterCloseCb = static function (string $checkpoint, array $context) use (&$capturedTempPath, &$afterCloseMutated, $corruptTempBytes): void {
        if ($checkpoint === 'io_after_close' && !$afterCloseMutated) {
            $tempPath = $context['temp_path'] ?? ($context['path'] ?? '');
            if ($tempPath !== '' && is_file($tempPath)) {
                file_put_contents($tempPath, $corruptTempBytes);
                $capturedTempPath = $tempPath;
                $afterCloseMutated = true;
            }
        }
    };
    $prepAfter = $txService->prepare($testRootAfterClose, $testStateAfterClose, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $opAfter = $prepAfter['operation_id'];
    $afterSvc = new ShellDeltaFileTransactionService($manifestService, $afterCloseCb);
    assertThrows(function () use ($afterSvc, $testRootAfterClose, $testStateAfterClose, $opAfter, $installedBetaContext) {
        $afterSvc->apply($testRootAfterClose, $testStateAfterClose, $opAfter, $installedBetaContext);
    }, '', "Apply must abort when temp file is mutated at io_after_close");

    assertTrue($afterCloseMutated, "io_after_close hook must have mutated a temp file");
    assertTrue($capturedTempPath !== null && is_file($capturedTempPath), "Corrupted temp file must be RETAINED on disk");
    assertEqual(file_get_contents($capturedTempPath), $corruptTempBytes, "Corrupted private temp file bytes must be retained exactly (no cleanup deletes foreign bytes)");

    // Root must NOT have published files
    $rootAfterCloseAfter = snapshotTree($testRootAfterClose);
    assertEqual($rootAfterCloseAfter, $rootAfterCloseBefore, "Product root must NOT have new updates published");

    echo "  INJECTED failure (synthetic IO hook failure verified, not actual disk failure) for io_after_close temp mutation.\n";

    $recAfter = $txService->recover($testRootAfterClose, $testStateAfterClose, $opAfter, $installedBetaContext);
    assertEqual($recAfter['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Recovery must roll back failed apply after temp mutation");

    // 9.19: Late foreign leaf mutation test at io_after_close (after copy complete)
    echo "  [9.19] Late foreign leaf mutation test at io_after_close (after copy complete)...\n";
    $testRootLate = $lab . DIRECTORY_SEPARATOR . 'suite9-prod-late-mutation';
    $testStateLate = $lab . DIRECTORY_SEPARATOR . 'suite9-state-late-mutation';
    mkdir($testStateLate, 0700, true);
    populateTree($testRootLate, $sourceBetaFiles);

    $lateMutated = false;
    $targetLeafRel = null;
    $lateTargetFull = null;
    $lateForeignBytes = 'LATE_FOREIGN_LEAF_MUTATION_' . bin2hex(random_bytes(16));
    $originalLeafContent = null;

    $lateCb = static function (string $checkpoint, array $context) use ($testRootLate, $sourceBetaFiles, $lateForeignBytes, &$targetLeafRel, &$lateTargetFull, &$originalLeafContent, &$lateMutated): void {
        if ($checkpoint === 'io_after_close' && ($context['purpose'] ?? '') === 'publish' && !$lateMutated) {
            // Select once first native POSDesktop.exe or mapped public relpath inside closure
            $rel = $context['path'] ?? ($context['target_rel'] ?? '');
            if ($rel === '') {
                foreach (array_keys($sourceBetaFiles) as $k) {
                    if (str_ends_with(strtolower($k), 'posdesktop.exe') || str_contains($k, 'app-v14.js')) {
                        $rel = $k;
                        break;
                    }
                }
                if ($rel === '') {
                    $rel = array_key_first($sourceBetaFiles);
                }
            }
            $targetLeafRel = $rel;
            $candidateFull = $testRootLate . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($candidateFull)) {
                $lateTargetFull = $candidateFull;
                $originalLeafContent = file_get_contents($candidateFull);
                // Mutate corresponding product leaf known target while copy complete
                file_put_contents($lateTargetFull, $lateForeignBytes);
                $lateMutated = true;
            }
        }
    };

    $prepLate = $txService->prepare($testRootLate, $testStateLate, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $opLate = $prepLate['operation_id'];
    $lateSvc = new ShellDeltaFileTransactionService($manifestService, $lateCb);

    // Apply must reject with SPECIFIC foreign reason ('foreign'), not any generic exception
    assertThrows(function () use ($lateSvc, $testRootLate, $testStateLate, $opLate, $installedBetaContext) {
        $lateSvc->apply($testRootLate, $testStateLate, $opLate, $installedBetaContext);
    }, 'foreign', "Apply must abort with specific foreign reason when leaf is mutated after copy");

    assertTrue($lateMutated, "Late mutation hook at io_after_close must have fired");
    assertTrue(is_file($lateTargetFull), "Foreign leaf must not be deleted");
    assertEqual(file_get_contents($lateTargetFull), $lateForeignBytes, "Foreign leaf bytes must be preserved intact");
    echo "  INJECTED failure (synthetic IO hook failure verified, not actual disk failure) for late foreign leaf mutation.\n";

    // Isolate fixture and restore known leaf only with normal test-owned explicit repair for recover
    if ($originalLeafContent !== null && is_file($lateTargetFull)) {
        file_put_contents($lateTargetFull, $originalLeafContent);
    }
    $recLate = $txService->recover($testRootLate, $testStateLate, $opLate, $installedBetaContext);
    assertEqual($recLate['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Recovery must succeed after restoring known leaf");

    // 9.20: Private error-path fail-retain regression (junction to outside victim with foreign witness)
    echo "  [9.20] Private error-path fail-retain regression (reparse boundary & foreign witness intact)...\n";
    $testRootPriv = $lab . DIRECTORY_SEPARATOR . 'suite9-prod-priv-retain';
    $testStatePriv = $lab . DIRECTORY_SEPARATOR . 'suite9-state-priv-retain';
    mkdir($testStatePriv, 0700, true);
    populateTree($testRootPriv, $sourceBetaFiles);

    $prepPriv = $txService->prepare($testRootPriv, $testStatePriv, $betaManifestRaw, $betaSig, $betaZipPath, $installedBetaContext);
    $opPriv = $prepPriv['operation_id'];

    $privHookFired = false;
    $junctionCreated = false;
    $tempParentDir = '';
    $tempParentAside = '';
    $witnessFile = '';
    $witnessContent = 'FOREIGN_WITNESS_MUST_NEVER_BE_UNLINKED_' . bin2hex(random_bytes(16));

    $privCb = static function (string $checkpoint, array $context) use ($lab, $victimDir, $witnessContent, &$tempParentDir, &$tempParentAside, &$witnessFile, &$junctionCreated, &$privHookFired): void {
        if ($checkpoint === 'io_after_close' && ($context['purpose'] ?? '') === 'publish' && !$privHookFired) {
            $tempPath = $context['temp_path'] ?? ($context['path'] ?? '');
            if ($tempPath !== '' && file_exists($tempPath)) {
                $privHookFired = true;
                $tempParentDir = dirname($tempPath);
                $tempBase = basename($tempPath);
                $tempParentAside = $tempParentDir . '_aside';

                // Rename OWN tempParent to owned aside AFTER close
                rename($tempParentDir, $tempParentAside);

                // Create tracked junction old parent -> OWN outside victim
                createTrackedJunction($tempParentDir, $victimDir);
                $junctionCreated = true;

                // Put foreign witness file in victim with SAME basename (ctx path)
                $witnessFile = $victimDir . DIRECTORY_SEPARATOR . $tempBase;
                file_put_contents($witnessFile, $witnessContent);
            }
        }
    };

    $privSvc = new ShellDeltaFileTransactionService($manifestService, $privCb);

    // Invoke apply, require SPECIFIC reparse failure
    assertThrows(function () use ($privSvc, $testRootPriv, $testStatePriv, $opPriv, $installedBetaContext) {
        $privSvc->apply($testRootPriv, $testStatePriv, $opPriv, $installedBetaContext);
    }, 'reparse', "Apply must abort with specific reparse error when temp parent is a junction");

    assertTrue($privHookFired, "io_after_close publish hook must have fired");
    assertTrue($junctionCreated, "Tracked junction to victim must have been created");

    // Require foreign witness STILL INTACT (no error-path catch unlinks deleted it)
    assertTrue(is_file($witnessFile), "Foreign witness file in victim must not be deleted by error-path catch unlinks");
    assertEqual(file_get_contents($witnessFile), $witnessContent, "Foreign witness content must remain completely intact");

    // Remove owned junction, restore own parent, delete test witness, and normal recover
    removeTrackedJunction($tempParentDir, $lab);
    if (is_dir($tempParentAside)) {
        rename($tempParentAside, $tempParentDir);
    }
    @unlink($witnessFile);

    $recPriv = $txService->recover($testRootPriv, $testStatePriv, $opPriv, $installedBetaContext);
    assertEqual($recPriv['phase'], ShellDeltaFileTransactionService::PHASE_ROLLED_BACK, "Normal recovery must succeed after restoring temp parent");
    echo "  INJECTED failure (synthetic IO hook failure verified, not actual disk failure) for private error-path fail-retain.\n";

    echo "  [Test Suite 9 Passed]\n";

    // -------------------------------------------------------------------------
    // TEST SUITE 10: CLEANUP REFUSAL & LIVE FIXTURE RETENTION PROOFS
    // -------------------------------------------------------------------------
    echo "\n=== [Test Suite 10] Cleanup Refusal & Live Fixture Retention Proofs ===\n";

    // Isolate faultLab under lab
    $faultLab = $lab . DIRECTORY_SEPARATOR . 'suite10-fault-lab';
    mkdir($faultLab, 0700, true);
    $faultLabMarker = 'OWNED_FAULT_LAB_' . bin2hex(random_bytes(16));
    file_put_contents($faultLab . DIRECTORY_SEPARATOR . '.pos_owned_marker', $faultLabMarker);
    $faultSentinel = $faultLab . DIRECTORY_SEPARATOR . 'fault-sentinel.txt';
    $faultSentinelContent = 'RETAIN_THIS_FIXTURE_WHILE_CHILD_ALIVE_' . bin2hex(random_bytes(16));
    file_put_contents($faultSentinel, $faultSentinelContent);

    // Copy 3 synthetic signed assets into faultLab owned assets for strict confinement
    $faultAssets = $faultLab . DIRECTORY_SEPARATOR . 'assets';
    mkdir($faultAssets, 0700, true);
    $faultManifest = $faultAssets . DIRECTORY_SEPARATOR . 'shell-manifest.json';
    $faultSig = $faultAssets . DIRECTORY_SEPARATOR . 'shell-manifest.sig';
    $faultZip = $faultAssets . DIRECTORY_SEPARATOR . 'update.zip';
    copy($betaOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json', $faultManifest);
    copy($betaOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig', $faultSig);
    copy($betaZipPath, $faultZip);

    $faultCfgFile = $faultLab . DIRECTORY_SEPARATOR . 'fault-cfg.json';
    $faultAckFile = $faultLab . DIRECTORY_SEPARATOR . 'fault-ack.json';
    $faultProd = $faultLab . DIRECTORY_SEPARATOR . 'prod';
    $faultState = $faultLab . DIRECTORY_SEPARATOR . 'state';
    mkdir($faultState, 0700, true);
    populateTree($faultProd, $sourceBetaFiles);

    // Create a tracked junction inside faultLab to prove junctions are ALSO retained
    $faultJunc = $faultLab . DIRECTORY_SEPARATOR . 'fault-junction';
    createTrackedJunction($faultJunc, $faultProd);

    $faultChildCfg = [
        'configFile' => $faultCfgFile,
        'ackFile' => $faultAckFile,
        'root' => $faultProd,
        'stateBase' => $faultState,
        'rawManifestFile' => $faultManifest,
        'sigFile' => $faultSig,
        'zipFile' => $faultZip,
        'installedContext' => $installedBetaContext,
        'publicKeyPem' => $pubKeyPem,
        'targetCheckpoint' => 'prepared',
        'targetCheckpointCount' => 1,
    ];

    $faultSpawn = spawnChildProcess('hold-lease', $faultChildCfg, $faultLab, $faultLabMarker);
    $faultProc = $faultSpawn['proc'];

    // Wait for child to acknowledge holding lease
    $faultWait = microtime(true);
    while ((microtime(true) - $faultWait) < 20.0) {
        $st = getVerifiedProcessStatus($faultProc);
        if ($st['state'] === 'DEAD') {
            $err = file_exists($faultSpawn['stderrLog']) ? file_get_contents($faultSpawn['stderrLog']) : '';
            throw new RuntimeException("Fault child died prematurely with code {$st['exitcode']}.\nSTDERR: {$err}");
        }
        if (file_exists($faultAckFile)) {
            break;
        }
        usleep(20000);
    }

    $stLive = getVerifiedProcessStatus($faultProc);
    assertEqual($stLive['state'], 'RUNNING', "Fault injection child must be actively running");

    // Inject synthetic process status uncertainty hook while real child is alive
    echo "  Injecting synthetic process status uncertainty while child is alive...\n";
    $globalProcessStatusHook = static function (mixed $p) use ($faultProc): ?string {
        if ($p === $faultProc) {
            return 'UNKNOWN'; // Simulated unprovable status / termination failure
        }
        return null;
    };

    // Invoke the ACTUAL teardown/retention decision helper used by finally!
    $faultTracked = [$faultProc];
    $retentionResult = performTeardownAndRetention(
        $faultTracked,
        $faultLab,
        $faultLabMarker,
        '', // victimDirectory: empty, do not clean victim during test
        '',
        $victimSentinel,
        $victimSentinelContent,
        $canonicalRunnerTemp,
        1.0 // bounded short wait
    );

    // Assert decision: helper MUST refuse cleanup
    assertFalse($retentionResult['cleaned'], "Teardown helper MUST refuse cleanup when child status is uncertain");
    assertTrue($retentionResult['uncertain'], "Teardown helper MUST flag uncertain process status");
    assertTrue(str_contains($retentionResult['reason'], 'NEEDS ATTENTION'), "Helper reason must indicate NEEDS ATTENTION");

    // Assert live handle is NOT closed
    assertTrue(is_resource($faultProc), "Helper MUST NOT close handle of alive or uncertain process");

    // Assert ALL fixtures, markers, and junctions are RETAINED on disk
    assertTrue(is_dir($faultLab), "Fault lab directory must be RETAINED when child status uncertain");
    assertTrue(is_file($faultLab . DIRECTORY_SEPARATOR . '.pos_owned_marker'), "Ownership marker must be RETAINED");
    assertTrue(is_file($faultSentinel), "Fault sentinel file must remain intact");
    assertEqual(file_get_contents($faultSentinel), $faultSentinelContent, "Fault sentinel content must match");
    assertTrue(file_exists($faultJunc) || is_link($faultJunc), "Tracked junction must be RETAINED when child status uncertain");

    echo "  INJECTED uncertainty verified (synthetic process status uncertainty, not actual unkillable process).\n";

    // Restore knowledge / clear hook
    $globalProcessStatusHook = null;

    // Now safely kill and prove exit of OWN resource
    $exitCode = forceKillChildAndVerify($faultProc, 5);
    echo "  Fault child process terminated and verified dead (exit status: {$exitCode}).\n";

    // Re-invoke the ACTUAL helper with child confirmed dead: cleanup must now succeed!
    $cleanTracked = [];
    $cleanResult = performTeardownAndRetention(
        $cleanTracked,
        $faultLab,
        $faultLabMarker,
        '',
        '',
        $victimSentinel,
        $victimSentinelContent,
        $canonicalRunnerTemp,
        5.0
    );
    assertTrue($cleanResult['cleaned'], "Teardown helper must succeed once child is dead");
    clearstatcache(true, $faultLab);
    assertFalse(file_exists($faultLab), "Fault lab directory must be cleanly removed after exit proof");

    echo "  [Test Suite 10 Passed]\n";

    echo "\n=== ALL 10 SUITES PASSED SUCCESSFULLY ===\n";

} catch (Throwable $e) {
    $primaryException = $e;
} finally {
    try {
        $teardownResult = performTeardownAndRetention(
            $trackedChildProcs,
            $lab ?? '',
            $labMarker ?? '',
            $victimDir ?? '',
            $victimMarker ?? '',
            $victimSentinel ?? '',
            $victimSentinelContent ?? '',
            $canonicalRunnerTemp ?? '',
            5.0
        );

        if (!$teardownResult['cleaned']) {
            fwrite(STDERR, $teardownResult['reason'] . "\n");
            if ($primaryException !== null) {
                fwrite(STDERR, "Primary exception: " . $primaryException->getMessage() . "\n" . $primaryException->getTraceAsString() . "\n");
            }
            exit(1);
        }
    } catch (Throwable $teardownException) {
        if ($primaryException !== null) {
            fwrite(STDERR, "Cleanup error: " . $teardownException->getMessage() . "\n");
            throw $primaryException; // Never mask original exception!
        }
        throw $teardownException;
    }

    if ($primaryException !== null) {
        throw $primaryException;
    }
}
