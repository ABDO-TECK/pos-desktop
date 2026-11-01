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
use App\Services\ShellDeltaManifestService;
use App\Services\ShellDeltaWorkerInspectionService;
use App\Services\ShellDeltaWorkerSnapshotService;
use App\Services\UpdateChannelPolicy;
use App\Services\UpdateDataCompatibilityService;

// Pure lexical drive-root rejection of RAW_RUNNER_TEMP before ANY filesystem resolution or I/O
ShellDeltaWorkerSnapshotService::assertNotDriveRoot($rawRunnerTemp, 'rawRunnerTemp');
ProductInventoryService::assertNoReparse($rawRunnerTemp);
$canonicalRunnerTemp = ProductInventoryService::resolveAbsolutePath($rawRunnerTemp, true);
ProductInventoryService::assertNoReparse($canonicalRunnerTemp);
ShellDeltaWorkerSnapshotService::assertNotDriveRoot($canonicalRunnerTemp, 'canonicalRunnerTemp');

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
    } catch (Throwable $e) {
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
 * Populate directory tree with provided files map.
 *
 * @param array<string, string> $files
 */
function populateTree(string $root, array $files): void
{
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

/**
 * Capture full state of directory tree (files, sizes, hashes, subdirectories).
 *
 * @return array<string, array{type: 'file'|'dir'|'link', size?: int, sha256?: string}>
 */
function captureDirectoryTreeState(string $dir): array
{
    ProductInventoryService::assertNoReparse($dir);
    $state = [];
    $queue = [$dir];
    $baseLen = strlen(rtrim(str_replace('/', '\\', $dir), '\\'));

    while (!empty($queue)) {
        $current = array_shift($queue);
        ProductInventoryService::assertNoReparse($current);
        $entries = scandir($current);
        if ($entries === false) {
            throw new RuntimeException("Failed to scan directory: {$current}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $current . DIRECTORY_SEPARATOR . $entry;
            $rel = ltrim(substr(str_replace('/', '\\', $full), $baseLen), '\\');
            $normRel = strtolower(str_replace('\\', '/', $rel));
            if (is_link($full)) {
                $state[$normRel] = ['type' => 'link'];
            } elseif (is_dir($full)) {
                $state[$normRel] = ['type' => 'dir'];
                $queue[] = $full;
            } elseif (is_file($full)) {
                $state[$normRel] = [
                    'type' => 'file',
                    'size' => filesize($full),
                    'sha256' => hash_file('sha256', $full),
                ];
            }
        }
    }
    ksort($state, SORT_STRING);
    return $state;
}

function assertDirectoryTreeStateMatches(string $dir, array $expectedState, string $message): void
{
    $actualState = captureDirectoryTreeState($dir);
    if ($actualState !== $expectedState) {
        throw new RuntimeException("Directory tree state modified: {$message}. Expected: " . json_encode($expectedState) . ", Got: " . json_encode($actualState));
    }
}

// -----------------------------------------------------------------------------
// PROCESS TRACKING & SAFE WATCHDOG
// -----------------------------------------------------------------------------

/** @var list<resource> */
$trackedChildProcs = [];

/** @var resource|null Test hook for fault injection in teardown self-test */
$testHookUnknownResource = null;

/**
 * Direct process handle status query (Resource only, no PID kill).
 *
 * @return array{state: 'RUNNING'|'DEAD'|'UNKNOWN', exitcode: int}
 */
function getVerifiedProcessStatus(mixed $proc): array
{
    global $testHookUnknownResource;
    if ($testHookUnknownResource !== null && $proc === $testHookUnknownResource) {
        return ['state' => 'UNKNOWN', 'exitcode' => -1];
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

/**
 * Force kill child process using proc_terminate on resource and verify dead.
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
        throw new RuntimeException("Cannot terminate child: process status is UNKNOWN.");
    }

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

    throw new RuntimeException("Child process failed to terminate within {$timeoutSeconds}s.");
}

/**
 * Run a subprocess with file-based stdio in lab directory.
 * Immediate handle tracking, bounded output read only AFTER resource is proven DEAD.
 *
 * @param list<string> $cmd
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runProcess(array $cmd, string $cwd, ?array $env, string $labDir, float $timeoutSeconds = 90.0): array
{
    global $trackedChildProcs;

    if (!is_dir($labDir)) {
        throw new RuntimeException("Lab directory does not exist: {$labDir}");
    }
    ProductInventoryService::assertNoReparse($labDir);

    $nonce = bin2hex(random_bytes(6));
    $stdoutFile = $labDir . DIRECTORY_SEPARATOR . 'proc-out-' . $nonce . '.log';
    $stderrFile = $labDir . DIRECTORY_SEPARATOR . 'proc-err-' . $nonce . '.log';

    $descriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $stdoutFile, 'w'],
        2 => ['file', $stderrFile, 'w'],
    ];

    $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException("proc_open failed to spawn process: " . json_encode($cmd));
    }

    $trackedChildProcs[] = $proc;
    $start = microtime(true);
    $running = true;
    $exitCode = -1;

    while ((microtime(true) - $start) < $timeoutSeconds) {
        $st = getVerifiedProcessStatus($proc);
        if ($st['state'] === 'DEAD') {
            $running = false;
            $exitCode = $st['exitcode'];
            break;
        }
        if ($st['state'] === 'UNKNOWN') {
            throw new RuntimeException("Child process status became UNKNOWN.");
        }
        usleep(25000);
    }

    if ($running) {
        forceKillChildAndVerify($proc, 5);
        throw new RuntimeException("Subprocess exceeded {$timeoutSeconds}s timeout: " . json_encode($cmd));
    }

    @proc_close($proc);
    $idx = array_search($proc, $trackedChildProcs, true);
    if ($idx !== false) {
        unset($trackedChildProcs[$idx]);
        $trackedChildProcs = array_values($trackedChildProcs);
    }

    $stdout = is_file($stdoutFile) ? (string) file_get_contents($stdoutFile, false, null, 0, 65536) : '';
    $stderr = is_file($stderrFile) ? (string) file_get_contents($stderrFile, false, null, 0, 65536) : '';

    return [
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

/**
 * Run builder CLI subprocess to produce signed delta package and manifest.
 *
 * @param list<string> $args
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runBuilderCli(array $args, string $labRoot): array
{
    $builderScript = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build-shell-delta.php';
    ProductInventoryService::assertNoReparse($builderScript);
    $cmd = array_merge([PHP_BINARY, $builderScript], $args);

    return runProcess($cmd, dirname(__DIR__), null, $labRoot, 90.0);
}

// -----------------------------------------------------------------------------
// TEARDOWN AND RETENTION HELPERS
// -----------------------------------------------------------------------------

function safeLexicalSubdirCleanup(string $root, string $current): void
{
    ProductInventoryService::assertNoReparse($current);
    $entries = scandir($current);
    if ($entries === false) {
        throw new RuntimeException("Failed to scan directory for cleanup: '{$current}'");
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $current . DIRECTORY_SEPARATOR . $entry;
        if (is_link($full)) {
            @unlink($full);
        } elseif (is_dir($full)) {
            safeLexicalSubdirCleanup($root, $full);
            if (!@rmdir($full)) {
                throw new RuntimeException("Failed to rmdir: '{$full}'");
            }
        } else {
            if (!@unlink($full)) {
                throw new RuntimeException("Failed to unlink: '{$full}'");
            }
        }
    }
}

function safeLexicalCleanup(string $dir, string $expectedMarker, string $canonicalRunnerTemp): void
{
    if (!file_exists($dir) && !is_link($dir)) {
        return;
    }

    ProductInventoryService::assertNoReparse($dir);
    $canonicalDir = ProductInventoryService::resolveAbsolutePath($dir, true);
    ProductInventoryService::assertNoReparse($canonicalDir);

    $normDir = strtolower(str_replace('/', '\\', $canonicalDir));
    $normTemp = strtolower(str_replace('/', '\\', $canonicalRunnerTemp));

    if (!str_starts_with($normDir, $normTemp . '\\')) {
        throw new RuntimeException("Refusing to clean up directory outside RUNNER_TEMP: '{$dir}'");
    }

    $markerFile = $canonicalDir . DIRECTORY_SEPARATOR . '.pos_owned_marker';
    ProductInventoryService::assertNoReparse($markerFile);
    if (!is_file($markerFile)) {
        throw new RuntimeException("Refusing to clean up directory without valid regular ownership marker: '{$dir}'");
    }

    $content = file_get_contents($markerFile, false, null, 0, 1024);
    if ($content === false || !hash_equals($expectedMarker, $content)) {
        throw new RuntimeException("Refusing to clean up directory with mismatched ownership marker: '{$dir}'");
    }

    safeLexicalSubdirCleanup($canonicalDir, $canonicalDir);

    if (!@rmdir($canonicalDir)) {
        throw new RuntimeException("Failed to rmdir root: '{$canonicalDir}'");
    }

    clearstatcache(true, $canonicalDir);
    if (file_exists($canonicalDir) || is_link($canonicalDir)) {
        throw new RuntimeException("Directory still exists after cleanup: {$canonicalDir}");
    }
}

function performTeardownAndRetention(
    array &$procs,
    string $labDir,
    string $labMarkerToken,
    string $victimDirectory,
    string $victimMarkerToken,
    string $victimSentinelFile,
    string $victimSentinelExpected,
    string $runnerTemp,
    float $terminateTimeout = 5.0,
    ?callable $onSafeRestoration = null
): array {
    $uncleanProcesses = false;
    $remainingProcs = [];

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
                continue;
            }
        }

        @proc_close($p);
    }

    $procs = array_values($remainingProcs);

    if ($uncleanProcesses) {
        return [
            'cleaned' => false,
            'reason' => 'NEEDS ATTENTION: Child process was still live or uncertain; retaining workspaces for audit.',
            'uncertain' => true,
        ];
    }

    if ($victimSentinelFile !== '') {
        ProductInventoryService::assertNoReparse(dirname($victimSentinelFile));
        ProductInventoryService::assertNoReparse($victimSentinelFile);
        $actualSentinel = @file_get_contents($victimSentinelFile, false, null, 0, 1024);
        if ($actualSentinel === false || !hash_equals($victimSentinelExpected, $actualSentinel)) {
            return [
                'cleaned' => false,
                'reason' => 'CRITICAL ERROR: Outside victim sentinel was modified or deleted!',
                'uncertain' => true,
            ];
        }
    }

    // Perform safe pending restoration callback ONLY after all child processes are proven DEAD
    if ($onSafeRestoration !== null) {
        try {
            $onSafeRestoration();
        } catch (Throwable $e) {
            return [
                'cleaned' => false,
                'reason' => 'CRITICAL ERROR: Safe restoration callback failed: ' . $e->getMessage(),
                'uncertain' => true,
            ];
        }
    }

    try {
        if ($labDir !== '' && $labMarkerToken !== '' && $runnerTemp !== '') {
            safeLexicalCleanup($labDir, $labMarkerToken, $runnerTemp);
        }
        if ($victimDirectory !== '' && $victimMarkerToken !== '' && $runnerTemp !== '') {
            safeLexicalCleanup($victimDirectory, $victimMarkerToken, $runnerTemp);
        }
        return [
            'cleaned' => true,
            'reason' => 'All tracked children verified dead; owned workspaces cleaned up',
            'uncertain' => false,
        ];
    } catch (Throwable $e) {
        return [
            'cleaned' => false,
            'reason' => 'Error during directory cleanup: ' . $e->getMessage(),
            'uncertain' => true,
        ];
    }
}

// -----------------------------------------------------------------------------
// PORTABLE PHP FIXTURE COPIER
// -----------------------------------------------------------------------------

function populatePortablePhpFixture(string $destPhpDir, string $poisonMarkerPath): void
{
    $hostPhpExe = PHP_BINARY;
    $hostPhpDir = dirname($hostPhpExe);

    if (!is_dir($destPhpDir)) {
        if (!mkdir($destPhpDir, 0700, true)) {
            throw new RuntimeException("Failed to create portable PHP directory: {$destPhpDir}");
        }
    }
    $destExtDir = $destPhpDir . DIRECTORY_SEPARATOR . 'ext';
    if (!is_dir($destExtDir)) {
        if (!mkdir($destExtDir, 0700, true)) {
            throw new RuntimeException("Failed to create portable PHP ext directory: {$destExtDir}");
        }
    }

    // 1. Copy php.exe
    if (!copy($hostPhpExe, $destPhpDir . DIRECTORY_SEPARATOR . 'php.exe')) {
        throw new RuntimeException("Failed to copy host php.exe to fixture: {$destPhpDir}");
    }

    // 2. Copy root DLLs matching validateProductPath
    $hostEntries = scandir($hostPhpDir);
    if ($hostEntries !== false) {
        foreach ($hostEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullSrc = $hostPhpDir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($fullSrc) && preg_match('/^[A-Za-z0-9_.-]+\.dll$/i', $entry)) {
                $check = ProductInventoryService::validateProductPath('resources/portable/php/' . $entry);
                if ($check['ok']) {
                    if (!copy($fullSrc, $destPhpDir . DIRECTORY_SEPARATOR . $entry)) {
                        throw new RuntimeException("Failed to copy root DLL {$entry} to {$destPhpDir}");
                    }
                }
            }
        }
    }

    // 3. Copy required extension DLLs: php_openssl.dll, php_zip.dll, php_mbstring.dll
    $hostExtDir = $hostPhpDir . DIRECTORY_SEPARATOR . 'ext';
    $requiredDlls = ['php_openssl.dll', 'php_zip.dll', 'php_mbstring.dll'];
    foreach ($requiredDlls as $dll) {
        $srcDll = $hostExtDir . DIRECTORY_SEPARATOR . $dll;
        if (!is_file($srcDll)) {
            $altSrc = $hostPhpDir . DIRECTORY_SEPARATOR . $dll;
            if (is_file($altSrc)) {
                $srcDll = $altSrc;
            } else {
                throw new RuntimeException("Host PHP extension DLL not found: {$dll}");
            }
        }
        $check = ProductInventoryService::validateProductPath('resources/portable/php/ext/' . $dll);
        if (!$check['ok']) {
            throw new RuntimeException("Disallowed extension DLL path policy: {$dll}");
        }
        if (!copy($srcDll, $destExtDir . DIRECTORY_SEPARATOR . $dll)) {
            throw new RuntimeException("Failed to copy extension DLL {$dll} to {$destExtDir}");
        }
    }

    // 4. Create signed php.ini (ProductInventoryService explicitly allows php.ini, NOT php-cli.ini)
    $poisonIni = "; Deliberate signed poison php.ini\n"
        . "auto_prepend_file = \"" . addslashes($poisonMarkerPath) . "\"\n"
        . "auto_append_file = \"" . addslashes($poisonMarkerPath) . "\"\n"
        . "open_basedir = \"C:\\\\nonexistent_poison_base\"\n"
        . "extension_dir = \"C:\\\\nonexistent_poison_ext\"\n"
        . "memory_limit = 256M\n";
    if (file_put_contents($destPhpDir . DIRECTORY_SEPARATOR . 'php.ini', $poisonIni) === false) {
        throw new RuntimeException("Failed to write poison php.ini to {$destPhpDir}");
    }
}

// -----------------------------------------------------------------------------
// MAIN TEST RUNNER
// -----------------------------------------------------------------------------

echo "======================================================================\n";
echo "Shell Worker Isolated Inspection Verification Suite\n";
echo "Platform: " . PHP_OS_FAMILY . " | PHP: " . PHP_VERSION . "\n";
echo "======================================================================\n";

$labNonce = bin2hex(random_bytes(8));
$lab = $canonicalRunnerTemp . DIRECTORY_SEPARATOR . 'lab-inspect-' . $labNonce;
if (!mkdir($lab, 0700, false)) {
    fwrite(STDERR, "Failed to create lab directory: {$lab}\n");
    exit(1);
}
ProductInventoryService::assertNoReparse($lab);
$lab = ProductInventoryService::resolveAbsolutePath($lab, true);
ProductInventoryService::assertNoReparse($lab);

$labOwnerToken = bin2hex(random_bytes(16));
file_put_contents($lab . DIRECTORY_SEPARATOR . '.pos_owned_marker', $labOwnerToken);

// Create outside victim directory to verify no unauthorized writes or deletions
$victimDir = $canonicalRunnerTemp . DIRECTORY_SEPARATOR . 'victim-inspect-' . $labNonce;
if (!mkdir($victimDir, 0700, false)) {
    fwrite(STDERR, "Failed to create victim directory: {$victimDir}\n");
    exit(1);
}
ProductInventoryService::assertNoReparse($victimDir);
$victimDir = ProductInventoryService::resolveAbsolutePath($victimDir, true);
ProductInventoryService::assertNoReparse($victimDir);

$victimOwnerToken = bin2hex(random_bytes(16));
file_put_contents($victimDir . DIRECTORY_SEPARATOR . '.pos_owned_marker', $victimOwnerToken);

$victimSentinelFile = $victimDir . DIRECTORY_SEPARATOR . 'outside_sentinel.txt';
$victimSentinelExpected = "OUTSIDE_VICTIM_INTACT_" . $labNonce;
file_put_contents($victimSentinelFile, $victimSentinelExpected);

// Outside poison probe marker file
$poisonProbeFile = $victimDir . DIRECTORY_SEPARATOR . 'probe_marker.php';
$poisonHitFile = $victimDir . DIRECTORY_SEPARATOR . 'probe_marker_hit.txt';
$poisonProbeScript = "<?php\n"
    . "file_put_contents('" . addslashes($poisonHitFile) . "', 'POISON_HIT');\n"
    . "throw new \\RuntimeException('POISON_HIT_EXECUTED');\n";
file_put_contents($poisonProbeFile, $poisonProbeScript);

// Capture full initial victim directory tree state BEFORE ANY TEST OPERATION
$initialVictimTreeState = captureDirectoryTreeState($victimDir);

/** @var array{path: string, original_bytes: string}|null Pending public cert restoration */
$pendingCertRestoration = null;

$primaryThrowable = null;
$allSuitesPassed = false;

try {
    // -------------------------------------------------------------------------
    // Setup 1: Ephemeral RSA Keypair & Real backend.phar Build
    // -------------------------------------------------------------------------
    echo "[Setup 1] Generating ephemeral RSA keypairs (CI fixture only)...\n";
    $keyPair = ManifestSignatureService::generateKeyPair(2048);
    $ephemeralPubKey = $keyPair['public_key'];
    $ephemeralPrivKey = $keyPair['private_key'];

    $privKeyFile = $lab . DIRECTORY_SEPARATOR . 'ephemeral_signing_key.pem';
    file_put_contents($privKeyFile, $ephemeralPrivKey);

    $pubKeyFile = $lab . DIRECTORY_SEPARATOR . 'ephemeral_pubkey.pem';
    file_put_contents($pubKeyFile, $ephemeralPubKey);

    echo "[Setup 1] Building real backend.phar with ephemeral test key...\n";
    $repoRoot = dirname(__DIR__);
    $repoCertPath = $repoRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'update_public_key.pem';
    ProductInventoryService::assertNoReparse($repoCertPath);
    $originalCertBytes = file_get_contents($repoCertPath);
    if ($originalCertBytes === false) {
        throw new RuntimeException("Could not read original update_public_key.pem");
    }

    $tempPharFile = $repoRoot . DIRECTORY_SEPARATOR . 'backend_temp.phar';
    $generatedPhar = $repoRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'backend.phar';
    if (file_exists($tempPharFile)) {
        throw new RuntimeException("Stale backend_temp.phar exists before build");
    }
    if (file_exists($generatedPhar)) {
        throw new RuntimeException("Stale backend/backend.phar exists before build");
    }

    // Register pending restoration: restored ONLY at shared final teardown AFTER global death proof
    $pendingCertRestoration = [
        'path' => $repoCertPath,
        'original_bytes' => $originalCertBytes,
    ];

    $builtPharDest = $lab . DIRECTORY_SEPARATOR . 'backend.phar';

    // Temporarily set repo cert to ephemeral test key for compilation
    if (file_put_contents($repoCertPath, $ephemeralPubKey) === false) {
        throw new RuntimeException("Failed to write ephemeral test key to repo cert path");
    }

    $buildPharScript = $repoRoot . DIRECTORY_SEPARATOR . 'build-phar.php';
    ProductInventoryService::assertNoReparse($buildPharScript);
    $buildRes = runProcess([PHP_BINARY, '-d', 'phar.readonly=0', $buildPharScript], $repoRoot, null, $lab, 90.0);
    assertEqual($buildRes['exitCode'], 0, "build-phar.php must exit with 0. Stderr: {$buildRes['stderr']}");

    if (!is_file($generatedPhar)) {
        throw new RuntimeException("Generated backend.phar missing from: {$generatedPhar}");
    }

    if (!copy($generatedPhar, $builtPharDest)) {
        throw new RuntimeException("Failed to copy compiled backend.phar to lab");
    }

    echo "[FIXTURE] Compiled backend.phar is an EPHEMERAL-KEY TEST fixture (not published v13/Beta)\n";
    assertTrue(is_file($builtPharDest), "Lab backend.phar must exist");

    // -------------------------------------------------------------------------
    // Setup 2: Data Compatibility Contract
    // -------------------------------------------------------------------------
    $dataContract = [
        'format' => 1,
        'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
        'schema_sha256' => hash('sha256', 'FIXTURE_SCHEMA_DATA_V12'),
        'ledger_sha256' => hash('sha256', 'FIXTURE_LEDGER_DATA_V12'),
    ];
    $contractFile = $lab . DIRECTORY_SEPARATOR . 'contract.json';
    file_put_contents($contractFile, json_encode($dataContract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    // -------------------------------------------------------------------------
    // Setup 3: Build Real Beta Delta Package via Builder CLI
    // -------------------------------------------------------------------------
    echo "[Setup 2] Populating Beta product files and compiling delta package...\n";
    $betaSrcDir = $lab . DIRECTORY_SEPARATOR . 'beta-src';
    $betaTgtDir = $lab . DIRECTORY_SEPARATOR . 'beta-tgt';
    $betaOutDir = $lab . DIRECTORY_SEPARATOR . 'beta-out';

    $cacertBytes = file_get_contents($repoRoot . '/backend/certs/cacert.pem');
    if ($cacertBytes === false) {
        $cacertBytes = "CACERT_FIXTURE_DATA";
    }

    $sourceBetaFiles = [
        'POS Desktop.exe' => "EXE_SHELL_V13_CONTENT_" . str_repeat('A', 500),
        'resources/app.asar' => "ASAR_SHELL_V13_CONTENT_" . str_repeat('B', 1000),
        'resources/app.asar.unpacked/version.json' => json_encode(['version' => '0.0.12-beta.1']),
        'resources/app.asar.unpacked/package.json' => json_encode(['name' => 'pos-desktop', 'version' => '0.0.12-beta.1']),
        'resources/app.asar.unpacked/backend/certs/cacert.pem' => $cacertBytes,
        'resources/app.asar.unpacked/backend/certs/update_public_key.pem' => $ephemeralPubKey,
        'resources/app.asar.unpacked/database/pos_schema.sql' => "-- schema v12",
        'resources/portable/runtime-manifest.json' => json_encode(['schemaVersion' => 1]),
    ];

    populateTree($betaSrcDir, $sourceBetaFiles);
    // Copy real built backend.phar into Beta source
    $destBetaPhar = $betaSrcDir . '/resources/app.asar.unpacked/backend/backend.phar';
    if (!is_dir(dirname($destBetaPhar))) {
        mkdir(dirname($destBetaPhar), 0700, true);
    }
    if (!copy($builtPharDest, $destBetaPhar)) {
        throw new RuntimeException("Failed to copy backend.phar to beta source");
    }

    // Copy real portable PHP into Beta source
    populatePortablePhpFixture($betaSrcDir . '/resources/portable/php', $poisonProbeFile);

    // Target Beta tree
    $targetBetaFiles = $sourceBetaFiles;
    $targetBetaFiles['POS Desktop.exe'] = "EXE_SHELL_V14_CONTENT_" . str_repeat('Z', 600);
    $targetBetaFiles['resources/app.asar'] = "ASAR_SHELL_V14_CONTENT_" . str_repeat('Y', 1200);
    $targetBetaFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.14-beta.1']);
    $targetBetaFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.14-beta.1']);
    populateTree($betaTgtDir, $targetBetaFiles);

    $destBetaTgtPhar = $betaTgtDir . '/resources/app.asar.unpacked/backend/backend.phar';
    if (!is_dir(dirname($destBetaTgtPhar))) {
        mkdir(dirname($destBetaTgtPhar), 0700, true);
    }
    if (!copy($builtPharDest, $destBetaTgtPhar)) {
        throw new RuntimeException("Failed to copy backend.phar to beta target");
    }
    populatePortablePhpFixture($betaTgtDir . '/resources/portable/php', $poisonProbeFile);

    $builderBetaRes = runBuilderCli([
        '--source-root', $betaSrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $contractFile,
        '--private-key', $privKeyFile,
        '--output-dir', $betaOutDir,
    ], $lab);
    assertEqual($builderBetaRes['exitCode'], 0, "Beta builder CLI must succeed with exit code 0. Stderr: {$builderBetaRes['stderr']}");

    $betaManifestRaw = file_get_contents($betaOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $betaSig = file_get_contents($betaOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig');
    $betaContext = [
        'version' => '0.0.12-beta.1',
        'native_version' => '0.0.13',
        'channel' => 'beta',
        'data_compatibility' => $dataContract,
    ];

    // -------------------------------------------------------------------------
    // Setup 4: Build Real Stable Delta Package via Builder CLI
    // -------------------------------------------------------------------------
    echo "[Setup 3] Populating Stable product files and compiling delta package...\n";
    $stableSrcDir = $lab . DIRECTORY_SEPARATOR . 'stable-src';
    $stableTgtDir = $lab . DIRECTORY_SEPARATOR . 'stable-tgt';
    $stableOutDir = $lab . DIRECTORY_SEPARATOR . 'stable-out';

    $sourceStableFiles = $sourceBetaFiles;
    $sourceStableFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.12']);
    $sourceStableFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.12']);
    populateTree($stableSrcDir, $sourceStableFiles);

    $destStablePhar = $stableSrcDir . '/resources/app.asar.unpacked/backend/backend.phar';
    if (!is_dir(dirname($destStablePhar))) {
        mkdir(dirname($destStablePhar), 0700, true);
    }
    if (!copy($builtPharDest, $destStablePhar)) {
        throw new RuntimeException("Failed to copy backend.phar to stable source");
    }
    populatePortablePhpFixture($stableSrcDir . '/resources/portable/php', $poisonProbeFile);

    $targetStableFiles = $targetBetaFiles;
    $targetStableFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.14']);
    $targetStableFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.14']);
    populateTree($stableTgtDir, $targetStableFiles);

    $destStableTgtPhar = $stableTgtDir . '/resources/app.asar.unpacked/backend/backend.phar';
    if (!is_dir(dirname($destStableTgtPhar))) {
        mkdir(dirname($destStableTgtPhar), 0700, true);
    }
    if (!copy($builtPharDest, $destStableTgtPhar)) {
        throw new RuntimeException("Failed to copy backend.phar to stable target");
    }
    populatePortablePhpFixture($stableTgtDir . '/resources/portable/php', $poisonProbeFile);

    $builderStableRes = runBuilderCli([
        '--source-root', $stableSrcDir,
        '--target-root', $stableTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $contractFile,
        '--private-key', $privKeyFile,
        '--output-dir', $stableOutDir,
    ], $lab);
    assertEqual($builderStableRes['exitCode'], 0, "Stable builder CLI must succeed with exit code 0. Stderr: {$builderStableRes['stderr']}");

    $stableManifestRaw = file_get_contents($stableOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $stableSig = file_get_contents($stableOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig');
    $stableContext = [
        'version' => '0.0.12',
        'native_version' => '0.0.13',
        'channel' => 'stable',
        'data_compatibility' => $dataContract,
    ];

    // Decoy directory containing ambient php-cli.ini and openssl poison
    $decoyDir = $lab . DIRECTORY_SEPARATOR . 'decoy_cwd';
    if (!is_dir($decoyDir)) {
        mkdir($decoyDir, 0700, true);
    }
    $decoyIni = "; Ambient decoy php-cli.ini poison\n"
        . "auto_prepend_file = \"" . addslashes($poisonProbeFile) . "\"\n"
        . "auto_append_file = \"" . addslashes($poisonProbeFile) . "\"\n"
        . "open_basedir = \"C:\\\\nonexistent_decoy_base\"\n"
        . "extension_dir = \"C:\\\\nonexistent_decoy_ext\"\n";
    file_put_contents($decoyDir . DIRECTORY_SEPARATOR . 'php-cli.ini', $decoyIni);
    file_put_contents($decoyDir . DIRECTORY_SEPARATOR . 'php.ini', $decoyIni);

    $opensslPoisonCnf = "; Decoy OpenSSL poison cnf\n"
        . "openssl_conf = default_conf\n"
        . "[default_conf]\n"
        . "ssl_conf = ssl_sect\n"
        . "[ssl_sect]\n"
        . "system_default = system_default_sect\n"
        . "[system_default_sect]\n"
        . "MinProtocol = TLSv1.3\n";
    file_put_contents($decoyDir . DIRECTORY_SEPARATOR . 'openssl_poison.cnf', $opensslPoisonCnf);

    // Decoy scan-dir containing actual scanned .ini poison
    $decoyScanDir = $lab . DIRECTORY_SEPARATOR . 'decoy_scan_dir';
    if (!is_dir($decoyScanDir)) {
        mkdir($decoyScanDir, 0700, true);
    }
    file_put_contents($decoyScanDir . DIRECTORY_SEPARATOR . '99_poison.ini', $decoyIni);

    // Private state base for worker snapshots
    $stateBase = $lab . DIRECTORY_SEPARATOR . 'private_state';
    if (!is_dir($stateBase)) {
        mkdir($stateBase, 0700, true);
    }
    ProductInventoryService::assertNoReparse($stateBase);

    // -------------------------------------------------------------------------
    // SUITE 1: Pure Pinned Verifier & Backward Compatibility
    // -------------------------------------------------------------------------
    echo "\n[Suite 1] Pure Pinned Verifier & Backward Compatibility...\n";

    // Subcase 1a: discoverDefaultKeys=false never resolves default paths or scans mutable certs
    $pureVerifier = new ManifestSignatureService(null, [], false, false);
    assertEqual($pureVerifier->getPublicKeyPath(), null, "discover=false with null key must leave publicKeyPath null");
    assertEqual($pureVerifier->getTrustedKeyPaths(), [], "discover=false must have empty trustedKeyPaths without additional keys");

    // Subcase 1b: Pinned verifier with separate primary public key file and secondary key
    $secondaryKeyPair = ManifestSignatureService::generateKeyPair(2048);
    $untrustedKeyPair = ManifestSignatureService::generateKeyPair(2048);

    $pinnedVerifier = new ManifestSignatureService(
        $pubKeyFile,
        [$secondaryKeyPair['public_key']],
        false,
        false
    );
    assertEqual($pinnedVerifier->getPublicKeyPath(), str_replace('\\', '/', $pubKeyFile), "Explicit pubkey file must be set");
    assertEqual($pinnedVerifier->getTrustedKeyPaths(), [$secondaryKeyPair['public_key']], "Explicit trusted secondary key must be set");

    // Primary key signature verification succeeds
    $testPayload = "TEST_PAYLOAD_FOR_SIGNATURE_VERIFICATION_1";
    openssl_sign($testPayload, $primaryRawSig, $ephemeralPrivKey, OPENSSL_ALGO_SHA256);
    $primaryBase64Sig = base64_encode($primaryRawSig);
    assertTrue($pinnedVerifier->verifySignature($testPayload, $primaryBase64Sig), "Primary pinned key must verify signature");

    // Secondary key signature verification succeeds
    openssl_sign($testPayload, $secondaryRawSig, $secondaryKeyPair['private_key'], OPENSSL_ALGO_SHA256);
    $secondaryBase64Sig = base64_encode($secondaryRawSig);
    assertTrue($pinnedVerifier->verifySignature($testPayload, $secondaryBase64Sig), "Secondary pinned key must verify signature");

    // Untrusted key signature verification rejected
    openssl_sign($testPayload, $untrustedRawSig, $untrustedKeyPair['private_key'], OPENSSL_ALGO_SHA256);
    $untrustedBase64Sig = base64_encode($untrustedRawSig);
    assertFalse($pinnedVerifier->verifySignature($testPayload, $untrustedBase64Sig), "Untrusted key must be rejected");

    // Subcase 1c: enableLogging=false produces no log writes even on verification failure under victim trap env
    $savedTrapEnv = [
        'LOGS_PATH' => getenv('LOGS_PATH') ?: '',
        'APP_STORAGE_DIR' => getenv('APP_STORAGE_DIR') ?: '',
    ];
    try {
        putenv('LOGS_PATH=' . $victimDir . DIRECTORY_SEPARATOR . 'logs');
        putenv('APP_STORAGE_DIR=' . $victimDir . DIRECTORY_SEPARATOR . 'storage');

        $preTrapVictimState = captureDirectoryTreeState($victimDir);
        $verifyFail = $pinnedVerifier->verifySignature("CORRUPT_PAYLOAD", $primaryBase64Sig);
        assertFalse($verifyFail, "Corrupt payload signature must fail verification");

        // Assert victim directory was NOT modified (no log files or storage folders created)
        assertDirectoryTreeStateMatches($victimDir, $preTrapVictimState, "enableLogging=false must not write any logs");
    } finally {
        foreach ($savedTrapEnv as $k => $v) {
            putenv($k . ($v !== '' ? '=' . $v : ''));
        }
    }

    // Subcase 1d: default 2-argument constructor preserves old behavior (discover=true, logging=true)
    $defaultVerifier = new ManifestSignatureService();
    assertTrue($defaultVerifier->getPublicKeyPath() !== null, "Default constructor must discover default public key");

    echo "  [PASS] Suite 1: Pure Pinned Verifier passed.\n";

    // -------------------------------------------------------------------------
    // SUITE 2: Real Beta Snapshot Creation & Plan Execution under Poisoned Ambient
    // -------------------------------------------------------------------------
    echo "\n[Suite 2] Real Beta Snapshot & Plan Inspection under Poisoned Ambient...\n";

    $sigService = new ManifestSignatureService(null, [$ephemeralPubKey], false, false);
    $manifestService = new ShellDeltaManifestService($sigService);
    $snapshotService = new ShellDeltaWorkerSnapshotService($manifestService);

    $betaSnapshot = $snapshotService->create(
        $betaSrcDir,
        $stateBase,
        $betaManifestRaw,
        $betaSig,
        $betaContext
    );
    assertEqual($betaSnapshot['phase'], ShellDeltaWorkerSnapshotService::PHASE_FILES_VERIFIED, "Phase must be snapshot_files_verified");

    // Capture state of the closed snapshot directory before planning/inspection
    $snapshotRoot = $betaSnapshot['snapshot_root'];
    $preInspectTreeState = captureDirectoryTreeState($snapshotRoot);

    // Instantiate inspection service with matching snapshot service
    $inspectionService = new ShellDeltaWorkerInspectionService($snapshotService);

    // Generate isolated plan WHILE parent ambient environment is poisoned and cwd is decoy
    $savedEnv = [
        'PHPRC' => getenv('PHPRC') ?: '',
        'PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR') ?: '',
        'OPENSSL_CONF' => getenv('OPENSSL_CONF') ?: '',
        'LOGS_PATH' => getenv('LOGS_PATH') ?: '',
        'APP_STORAGE_DIR' => getenv('APP_STORAGE_DIR') ?: '',
        'DB_DATABASE' => getenv('DB_DATABASE') ?: '',
        'DB_CONNECTION' => getenv('DB_CONNECTION') ?: '',
    ];

    $originalCwd = getcwd();
    try {
        putenv('PHPRC=' . $decoyDir);
        putenv('PHP_INI_SCAN_DIR=' . $decoyScanDir);
        putenv('OPENSSL_CONF=' . $decoyDir . DIRECTORY_SEPARATOR . 'openssl_poison.cnf');
        putenv('LOGS_PATH=' . $victimDir . DIRECTORY_SEPARATOR . 'logs');
        putenv('APP_STORAGE_DIR=' . $victimDir . DIRECTORY_SEPARATOR . 'storage');
        putenv('DB_DATABASE=' . $victimDir . DIRECTORY_SEPARATOR . 'poison.sqlite');
        putenv('DB_CONNECTION=sqlite');

        chdir($decoyDir);

        $plan = $inspectionService->plan(
            $betaSrcDir,
            $stateBase,
            $betaSnapshot['snapshot_id'],
            $betaSnapshot['snapshot_sha256'],
            $betaContext
        );
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }
        foreach ($savedEnv as $k => $v) {
            putenv($k . ($v !== '' ? '=' . $v : ''));
        }
    }

    assertEqual($plan['operation'], 'inspect_snapshot', "Plan operation must be inspect_snapshot");
    assertFalse($plan['can_apply'], "Plan must explicitly set can_apply=false");
    assertEqual($plan['command'], $betaSnapshot['php_path'], "Plan command must be copied php.exe");
    assertEqual($plan['cwd'], dirname($betaSnapshot['php_path']), "Plan cwd must be private PHP directory");
    assertTrue(in_array('-n', $plan['args'], true), "Plan args must contain -n");

    // Assert all 3 absolute private DLL extension args in plan args
    $expectedExtDir = dirname($betaSnapshot['php_path']) . DIRECTORY_SEPARATOR . 'ext';
    assertTrue(in_array('-d', $plan['args'], true), "Plan args must contain -d flags");
    assertTrue(in_array('extension_dir=' . $expectedExtDir, $plan['args'], true), "Plan args must contain absolute extension_dir");
    assertTrue(in_array('extension=' . $expectedExtDir . DIRECTORY_SEPARATOR . 'php_openssl.dll', $plan['args'], true), "Plan args must contain openssl dll");
    assertTrue(in_array('extension=' . $expectedExtDir . DIRECTORY_SEPARATOR . 'php_zip.dll', $plan['args'], true), "Plan args must contain zip dll");
    assertTrue(in_array('extension=' . $expectedExtDir . DIRECTORY_SEPARATOR . 'php_mbstring.dll', $plan['args'], true), "Plan args must contain mbstring dll");

    // Assert exact sparse environment key set
    $expectedEnvKeys = ['OPENSSL_CONF', 'OPENSSL_ENGINES', 'OPENSSL_MODULES', 'PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'SystemRoot', 'TEMP', 'TMP', 'WINDIR'];
    $actualEnvKeys = array_keys($plan['env']);
    sort($actualEnvKeys, SORT_STRING);
    assertEqual($actualEnvKeys, $expectedEnvKeys, "Plan env key set must be strictly sparse without ambient variables");

    assertEqual($plan['env']['OPENSSL_CONF'], 'NUL', "Plan env OPENSSL_CONF must be NUL");
    assertEqual($plan['env']['PHPRC'], '', "Plan env PHPRC must be empty");
    assertEqual($plan['env']['PHP_INI_SCAN_DIR'], '', "Plan env PHP_INI_SCAN_DIR must be empty");

    // Assert plan includes expected signed hashes
    assertTrue(isset($plan['expected_php_sha256']), "Plan must contain expected_php_sha256");
    assertTrue(isset($plan['expected_phar_sha256']), "Plan must contain expected_phar_sha256");

    // Execute plan using plan cwd and plan env
    $procCmd = array_merge([$plan['command']], $plan['args']);
    $inspectRes = runProcess($procCmd, $plan['cwd'], $plan['env'], $lab, 60.0);
    assertEqual($inspectRes['exitCode'], 0, "Plan execution must succeed with exit code 0. Stderr: {$inspectRes['stderr']}");

    // Verify probe marker was NEVER executed
    assertFalse(file_exists($poisonHitFile), "Poison probe marker must NOT be executed");

    // Parse bounded single JSON line output
    $stdoutTrimmed = trim($inspectRes['stdout']);
    assertTrue(!str_contains($stdoutTrimmed, "\n"), "Stdout must be exactly ONE line of JSON");
    $inspectJson = json_decode($stdoutTrimmed, true, 32, JSON_THROW_ON_ERROR);
    assertTrue(is_array($inspectJson), "Stdout must parse as valid JSON object");

    assertEqual($inspectJson['ok'], true, "JSON ok must be true");
    assertEqual($inspectJson['operation'], 'inspect_snapshot', "JSON operation must be inspect_snapshot");
    assertEqual($inspectJson['inspection_format'], 1, "JSON inspection_format must be 1");
    assertEqual($inspectJson['phase'], 'snapshot_inspected', "JSON phase must be snapshot_inspected");
    assertEqual($inspectJson['snapshot_id'], $betaSnapshot['snapshot_id'], "JSON snapshot_id must match");
    assertEqual($inspectJson['snapshot_sha256'], $betaSnapshot['snapshot_sha256'], "JSON snapshot_sha256 must match");
    assertEqual($inspectJson['source_inventory_sha256'], $betaSnapshot['source_inventory_sha256'], "JSON source_inventory_sha256 must match");
    assertEqual($inspectJson['manifest_sha256'], $betaSnapshot['manifest_sha256'], "JSON manifest_sha256 must match");
    assertEqual($inspectJson['no_ini'], true, "JSON no_ini must be true");
    assertEqual($inspectJson['can_apply'], false, "JSON can_apply must be false");
    assertEqual($inspectJson['php_sha256'], hash_file('sha256', $betaSnapshot['php_path']), "JSON php_sha256 must match verified php.exe");
    assertEqual($inspectJson['phar_sha256'], hash_file('sha256', $betaSnapshot['phar_path']), "JSON phar_sha256 must match verified backend.phar");

    // Verify closed snapshot tree was completely unchanged byte-for-byte
    assertDirectoryTreeStateMatches($snapshotRoot, $preInspectTreeState, "Beta snapshot tree must be unmodified after inspection");

    // Verify outside victim directory is completely intact
    assertDirectoryTreeStateMatches($victimDir, $initialVictimTreeState, "Outside victim state must be completely untouched");

    echo "  [PASS] Suite 2: Beta snapshot and plan inspection passed.\n";

    // -------------------------------------------------------------------------
    // SUITE 3: Real Stable Snapshot Creation & Plan Execution
    // -------------------------------------------------------------------------
    echo "\n[Suite 3] Real Stable Snapshot & Plan Inspection...\n";

    $stableSnapshot = $snapshotService->create(
        $stableSrcDir,
        $stateBase,
        $stableManifestRaw,
        $stableSig,
        $stableContext
    );
    assertEqual($stableSnapshot['phase'], ShellDeltaWorkerSnapshotService::PHASE_FILES_VERIFIED, "Stable phase verified");

    $stableSnapshotRoot = $stableSnapshot['snapshot_root'];
    $preStableTreeState = captureDirectoryTreeState($stableSnapshotRoot);

    $stablePlan = $inspectionService->plan(
        $stableSrcDir,
        $stateBase,
        $stableSnapshot['snapshot_id'],
        $stableSnapshot['snapshot_sha256'],
        $stableContext
    );

    $stableCmd = array_merge([$stablePlan['command']], $stablePlan['args']);
    $stableInspectRes = runProcess($stableCmd, $stablePlan['cwd'], $stablePlan['env'], $lab, 60.0);
    assertEqual($stableInspectRes['exitCode'], 0, "Stable inspection must exit 0. Stderr: {$stableInspectRes['stderr']}");

    $stableJson = json_decode(trim($stableInspectRes['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($stableJson['ok'], true, "Stable JSON ok true");
    assertEqual($stableJson['phase'], 'snapshot_inspected', "Stable JSON phase snapshot_inspected");
    assertEqual($stableJson['snapshot_id'], $stableSnapshot['snapshot_id'], "Stable snapshot ID matches");

    assertDirectoryTreeStateMatches($stableSnapshotRoot, $preStableTreeState, "Stable snapshot tree unmodified after inspection");
    assertDirectoryTreeStateMatches($victimDir, $initialVictimTreeState, "Outside victim state must be untouched");

    echo "  [PASS] Suite 3: Stable snapshot and plan inspection passed.\n";

    // -------------------------------------------------------------------------
    // SUITE 4: Product Root Mutation / Deletion Post-Snapshot
    // -------------------------------------------------------------------------
    echo "\n[Suite 4] Product Root Mutation / Deletion Post-Snapshot...\n";

    // 4a: Rename beta source tree aside under lab so original product root is ABSENT
    $betaAsideDir = $lab . DIRECTORY_SEPARATOR . 'beta-src-aside';
    ProductInventoryService::assertNoReparse($betaSrcDir);
    if (!rename($betaSrcDir, $betaAsideDir)) {
        throw new RuntimeException("Failed to rename beta-src aside");
    }
    ProductInventoryService::assertNoReparse($betaAsideDir);
    assertFalse(file_exists($betaSrcDir), "Original beta-src path must NOT exist during absent test");

    $preAsideState = captureDirectoryTreeState($betaAsideDir);

    // Plan and inspection succeed while product root is absent
    $absentPlan = $inspectionService->plan(
        $betaSrcDir,
        $stateBase,
        $betaSnapshot['snapshot_id'],
        $betaSnapshot['snapshot_sha256'],
        $betaContext
    );
    $absentCmd = array_merge([$absentPlan['command']], $absentPlan['args']);
    $absentRes = runProcess($absentCmd, $absentPlan['cwd'], $absentPlan['env'], $lab, 60.0);
    assertEqual($absentRes['exitCode'], 0, "Inspection must succeed when product root is absent");

    $absentJson = json_decode(trim($absentRes['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($absentJson['ok'], true, "Absent product inspection JSON ok true");
    assertEqual($absentJson['snapshot_sha256'], $betaSnapshot['snapshot_sha256'], "Snapshot sha256 stable");

    // 4b: Recreate original betaSrcDir as a deliberate poison tree with corrupt cert/config
    mkdir($betaSrcDir, 0700, true);
    ProductInventoryService::assertNoReparse($betaSrcDir);
    file_put_contents($betaSrcDir . DIRECTORY_SEPARATOR . 'CORRUPTED.txt', 'POISON_CONTENT');
    mkdir($betaSrcDir . '/resources/app.asar.unpacked/backend/certs', 0700, true);
    file_put_contents($betaSrcDir . '/resources/app.asar.unpacked/backend/certs/update_public_key.pem', 'CORRUPT_KEY');

    $prePoisonRootState = captureDirectoryTreeState($betaSrcDir);

    // Inspection still succeeds completely independent of mutated/corrupt product root
    $poisonedPlan = $inspectionService->plan(
        $betaSrcDir,
        $stateBase,
        $betaSnapshot['snapshot_id'],
        $betaSnapshot['snapshot_sha256'],
        $betaContext
    );
    $poisonedCmd = array_merge([$poisonedPlan['command']], $poisonedPlan['args']);
    $poisonedRes = runProcess($poisonedCmd, $poisonedPlan['cwd'], $poisonedPlan['env'], $lab, 60.0);
    assertEqual($poisonedRes['exitCode'], 0, "Inspection must succeed even with poisoned product root");

    $poisonedJson = json_decode(trim($poisonedRes['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($poisonedJson['ok'], true, "Poisoned product root inspection JSON ok true");

    // Verify all trees remained completely unmodified
    assertDirectoryTreeStateMatches($betaAsideDir, $preAsideState, "Aside beta source tree must be unchanged");
    assertDirectoryTreeStateMatches($betaSrcDir, $prePoisonRootState, "Poisoned product root must be unchanged");
    assertDirectoryTreeStateMatches($snapshotRoot, $preInspectTreeState, "Snapshot closed tree must be unchanged");
    assertDirectoryTreeStateMatches($victimDir, $initialVictimTreeState, "Outside victim state must be unchanged");

    echo "  [PASS] Suite 4: Product root mutation and deletion independence passed.\n";

    // -------------------------------------------------------------------------
    // SUITE 5: Fail-Closed Plan Before Spawn (No Spawn, No Logs)
    // -------------------------------------------------------------------------
    echo "\n[Suite 5] Fail-Closed Plan Before Spawn...\n";

    // Helper to assert no child process is spawned and no logs/files written during negative calls
    $assertNegativePlanFailsBeforeSpawn = static function (callable $action, string $expectedMessageSubstr, string $testName) use (&$trackedChildProcs, $victimDir, $lab, $initialVictimTreeState): void {
        $procsBefore = count($trackedChildProcs);
        $logsBefore = glob($lab . DIRECTORY_SEPARATOR . 'proc-*.log') ?: [];
        assertThrows($action, $expectedMessageSubstr, $testName);
        $procsAfter = count($trackedChildProcs);
        $logsAfter = glob($lab . DIRECTORY_SEPARATOR . 'proc-*.log') ?: [];
        assertEqual($procsAfter, $procsBefore, "{$testName}: No child process should be spawned on plan failure");
        assertEqual(count($logsAfter), count($logsBefore), "{$testName}: No proc-log files should be allocated on plan failure");
        assertDirectoryTreeStateMatches($victimDir, $initialVictimTreeState, "{$testName}: No logs or victim files written");
    };

    // Subcase 5a: Wrong pin digest
    $assertNegativePlanFailsBeforeSpawn(static function () use ($inspectionService, $betaSrcDir, $stateBase, $betaSnapshot, $betaContext): void {
        $wrongPin = hash('sha256', 'WRONG_PIN');
        $inspectionService->plan($betaSrcDir, $stateBase, $betaSnapshot['snapshot_id'], $wrongPin, $betaContext);
    }, 'mismatch', "Wrong pin must fail closed in plan()");

    // Subcase 5b: Tampered completion record
    $completionFile = $snapshotRoot . DIRECTORY_SEPARATOR . 'snapshot.json';
    $originalCompletionBytes = file_get_contents($completionFile);
    try {
        file_put_contents($completionFile, $originalCompletionBytes . " ");
        $assertNegativePlanFailsBeforeSpawn(static function () use ($inspectionService, $betaSrcDir, $stateBase, $betaSnapshot, $betaContext): void {
            $inspectionService->plan($betaSrcDir, $stateBase, $betaSnapshot['snapshot_id'], $betaSnapshot['snapshot_sha256'], $betaContext);
        }, 'mismatch', "Tampered completion file must fail closed");
    } finally {
        file_put_contents($completionFile, $originalCompletionBytes);
    }

    // Subcase 5c: Tampered payload file
    $payloadOpensslDll = $snapshotRoot . DIRECTORY_SEPARATOR . 'payload' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'portable' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'php_openssl.dll';
    $originalDllBytes = file_get_contents($payloadOpensslDll);
    try {
        file_put_contents($payloadOpensslDll, $originalDllBytes . "TAMPER");
        $assertNegativePlanFailsBeforeSpawn(static function () use ($inspectionService, $betaSrcDir, $stateBase, $betaSnapshot, $betaContext): void {
            $inspectionService->plan($betaSrcDir, $stateBase, $betaSnapshot['snapshot_id'], $betaSnapshot['snapshot_sha256'], $betaContext);
        }, 'mismatch', "Tampered payload DLL must fail closed");
    } finally {
        file_put_contents($payloadOpensslDll, $originalDllBytes);
    }

    // Subcase 5d: Tampered sealed signature
    $sealedSigFile = $snapshotRoot . DIRECTORY_SEPARATOR . 'sealed' . DIRECTORY_SEPARATOR . 'shell-manifest.sig';
    $originalSigBytes = file_get_contents($sealedSigFile);
    try {
        file_put_contents($sealedSigFile, base64_encode("TAMPERED_SIG"));
        $assertNegativePlanFailsBeforeSpawn(static function () use ($inspectionService, $betaSrcDir, $stateBase, $betaSnapshot, $betaContext): void {
            $inspectionService->plan($betaSrcDir, $stateBase, $betaSnapshot['snapshot_id'], $betaSnapshot['snapshot_sha256'], $betaContext);
        }, 'signature', "Tampered sealed signature must fail closed with signature error");
    } finally {
        file_put_contents($sealedSigFile, $originalSigBytes);
    }

    // Subcase 5e: Old-style no-marker PHAR fixture with deliberate router-style side-effect stub to OWN marker
    $legacySideEffect = $victimDir . DIRECTORY_SEPARATOR . 'legacy_side_effect.txt';
    $legacyPharDir = $lab . DIRECTORY_SEPARATOR . 'legacy-phar';
    mkdir($legacyPharDir, 0700, true);
    ProductInventoryService::assertNoReparse($legacyPharDir);
    $legacyPharFile = $legacyPharDir . DIRECTORY_SEPARATOR . 'backend.phar';

    $legacyBuilderScript = $lab . DIRECTORY_SEPARATOR . 'build_legacy_phar.php';
    $legacyBuilderCode = '<?php' . "\n"
        . '$phar = new Phar("' . addslashes($legacyPharFile) . '");' . "\n"
        . '$phar->startBuffering();' . "\n"
        . '$phar->addFromString("version.json", json_encode(["version" => "0.0.11"]));' . "\n"
        . '$phar->addFromString("router.php", "<?php file_put_contents(\'' . addslashes($legacySideEffect) . '\', \'POISON_ROUTED\');\n");' . "\n"
        . '$legacyStub = "<?php\nPhar::mapPhar(\'backend.phar\');\nrequire \'phar://backend.phar/router.php\';\n__HALT_COMPILER();\n";' . "\n"
        . '$phar->setStub($legacyStub);' . "\n"
        . '$phar->stopBuffering();' . "\n";
    file_put_contents($legacyBuilderScript, $legacyBuilderCode);
    $legacyBuildRes = runProcess([PHP_BINARY, '-d', 'phar.readonly=0', $legacyBuilderScript], $lab, null, $lab, 30.0);
    assertEqual($legacyBuildRes['exitCode'], 0, "Legacy PHAR build must exit 0. Stderr: {$legacyBuildRes['stderr']}");

    // Create source tree for legacy product
    $legacySrcDir = $lab . DIRECTORY_SEPARATOR . 'legacy-src';
    $legacyFiles = $sourceBetaFiles;
    populateTree($legacySrcDir, $legacyFiles);
    $destLegacyPhar = $legacySrcDir . '/resources/app.asar.unpacked/backend/backend.phar';
    if (!is_dir(dirname($destLegacyPhar))) {
        mkdir(dirname($destLegacyPhar), 0700, true);
    }
    if (!copy($legacyPharFile, $destLegacyPhar)) {
        throw new RuntimeException("Failed to copy legacy PHAR to source");
    }
    populatePortablePhpFixture($legacySrcDir . '/resources/portable/php', $poisonProbeFile);

    // Build authentic signed delta manifest via builder CLI
    $legacyOutDir = $lab . DIRECTORY_SEPARATOR . 'legacy-out';
    $builderLegacyRes = runBuilderCli([
        '--source-root', $legacySrcDir,
        '--target-root', $betaTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $contractFile,
        '--private-key', $privKeyFile,
        '--output-dir', $legacyOutDir,
    ], $lab);
    assertEqual($builderLegacyRes['exitCode'], 0, "Legacy builder CLI must succeed");

    $legacyManifestRaw = file_get_contents($legacyOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $legacySig = file_get_contents($legacyOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig');

    $legacySnapshot = $snapshotService->create(
        $legacySrcDir,
        $stateBase,
        $legacyManifestRaw,
        $legacySig,
        $betaContext
    );

    // Plan MUST reject static capability BEFORE spawn, no log allocation, side effect never executed
    $assertNegativePlanFailsBeforeSpawn(static function () use ($inspectionService, $legacySrcDir, $stateBase, $legacySnapshot, $betaContext): void {
        $inspectionService->plan(
            $legacySrcDir,
            $stateBase,
            $legacySnapshot['snapshot_id'],
            $legacySnapshot['snapshot_sha256'],
            $betaContext
        );
    }, 'inspection capability marker', "Legacy PHAR lacking marker must fail closed in plan()");

    assertFalse(file_exists($legacySideEffect), "Legacy side-effect file must not exist (stub was never executed)");

    echo "  [PASS] Suite 5: Fail-closed plan before spawn passed.\n";

    // -------------------------------------------------------------------------
    // SUITE 6: CLI Flag Validation & Isolation Defense
    // -------------------------------------------------------------------------
    echo "\n[Suite 6] CLI Flag Validation & Isolation Defense...\n";

    $phpExe = $betaSnapshot['php_path'];
    $pharFile = $betaSnapshot['phar_path'];
    $phpDir = dirname($phpExe);

    // Subcase 6a: Unknown shell-worker command (reserved prefix passthrough)
    $badCmdRes = runProcess([$phpExe, '-n', $pharFile, 'shell-worker-badsub'], $phpDir, null, $lab, 30.0);
    assertEqual($badCmdRes['exitCode'], 1, "Unknown shell-worker command must exit 1");
    $badCmdTrimmed = trim($badCmdRes['stdout']);
    assertTrue(!str_contains($badCmdTrimmed, "\n"), "Unknown command stdout must be single line JSON");
    $badCmdJson = json_decode($badCmdTrimmed, true, 32, JSON_THROW_ON_ERROR);
    assertEqual($badCmdJson['ok'], false, "Bad subcommand ok must be false");
    assertTrue(str_contains($badCmdJson['error'], 'subcommand'), "Error must indicate invalid subcommand");

    // Subcase 6b: Long reserved subcommand output bounded (never unbounded string)
    $longSub = 'shell-worker-' . str_repeat('abcde', 40);
    $longSubRes = runProcess([$phpExe, '-n', $pharFile, $longSub], $phpDir, null, $lab, 30.0);
    assertEqual($longSubRes['exitCode'], 1, "Long shell-worker subcommand must exit 1");
    $longSubTrimmed = trim($longSubRes['stdout']);
    assertTrue(!str_contains($longSubTrimmed, "\n"), "Long command stdout must be single line JSON");
    $longSubJson = json_decode($longSubTrimmed, true, 32, JSON_THROW_ON_ERROR);
    assertEqual($longSubJson['ok'], false, "Long subcommand ok must be false");
    assertTrue(mb_strlen($longSubJson['error']) <= 500, "Error message must be bounded to <= 500 characters");

    // Subcase 6c: Duplicate flag rejected
    $dupFlagRes = runProcess([
        $phpExe, '-n', $pharFile, 'shell-worker-inspect',
        '--product-root=' . $betaSrcDir,
        '--product-root=' . $betaSrcDir,
        '--state-base=' . $stateBase,
        '--snapshot-id=' . $betaSnapshot['snapshot_id'],
        '--snapshot-sha256=' . $betaSnapshot['snapshot_sha256'],
        '--installed-context=' . base64_encode(json_encode($betaContext)),
    ], $phpDir, null, $lab, 30.0);
    assertEqual($dupFlagRes['exitCode'], 1, "Duplicate flag must exit 1");
    $dupJson = json_decode(trim($dupFlagRes['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($dupJson['ok'], false, "Duplicate flag ok false");
    assertTrue(str_contains($dupJson['error'], 'Duplicate flag'), "Stdout error must report duplicate flag");

    // Subcase 6d: Unknown arbitrary flag rejected
    $unknownFlagRes = runProcess([
        $phpExe, '-n', $pharFile, 'shell-worker-inspect',
        '--product-root=' . $betaSrcDir,
        '--state-base=' . $stateBase,
        '--snapshot-id=' . $betaSnapshot['snapshot_id'],
        '--snapshot-sha256=' . $betaSnapshot['snapshot_sha256'],
        '--installed-context=' . base64_encode(json_encode($betaContext)),
        '--disallowed-flag=1',
    ], $phpDir, null, $lab, 30.0);
    assertEqual($unknownFlagRes['exitCode'], 1, "Unknown flag must exit 1");
    $unknownJson = json_decode(trim($unknownFlagRes['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($unknownJson['ok'], false, "Unknown flag ok false");
    assertTrue(str_contains($unknownJson['error'], 'Disallowed or unknown flag'), "Stdout error must report unknown flag");

    // Subcase 6e: Missing required flag rejected
    $missingFlagRes = runProcess([
        $phpExe, '-n', $pharFile, 'shell-worker-inspect',
        '--product-root=' . $betaSrcDir,
        '--snapshot-id=' . $betaSnapshot['snapshot_id'],
        '--snapshot-sha256=' . $betaSnapshot['snapshot_sha256'],
        '--installed-context=' . base64_encode(json_encode($betaContext)),
    ], $phpDir, null, $lab, 30.0);
    assertEqual($missingFlagRes['exitCode'], 1, "Missing flag must exit 1");
    $missingJson = json_decode(trim($missingFlagRes['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($missingJson['ok'], false, "Missing flag ok false");
    assertTrue(str_contains($missingJson['error'], 'Missing required flag'), "Stdout error must report missing flag");

    // Subcase 6f: Bad base64 context rejected
    $badB64Res = runProcess([
        $phpExe, '-n', $pharFile, 'shell-worker-inspect',
        '--product-root=' . $betaSrcDir,
        '--state-base=' . $stateBase,
        '--snapshot-id=' . $betaSnapshot['snapshot_id'],
        '--snapshot-sha256=' . $betaSnapshot['snapshot_sha256'],
        '--installed-context=NOT_VALID_BASE64!@#',
    ], $phpDir, null, $lab, 30.0);
    assertEqual($badB64Res['exitCode'], 1, "Bad base64 context must exit 1");
    $badB64Json = json_decode(trim($badB64Res['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($badB64Json['ok'], false, "Bad base64 ok false");
    assertTrue(str_contains($badB64Json['error'], 'not valid strict base64'), "Stdout error must report bad base64");

    // Subcase 6g: Oversized context rejected (> 4096 bytes cap, fits Windows argv < 32767 chars)
    $oversizedContext = $betaContext;
    $oversizedContext['padding'] = str_repeat('X', 4800);
    $oversizedB64 = base64_encode(json_encode($oversizedContext));
    $oversizedRes = runProcess([
        $phpExe, '-n', $pharFile, 'shell-worker-inspect',
        '--product-root=' . $betaSrcDir,
        '--state-base=' . $stateBase,
        '--snapshot-id=' . $betaSnapshot['snapshot_id'],
        '--snapshot-sha256=' . $betaSnapshot['snapshot_sha256'],
        '--installed-context=' . $oversizedB64,
    ], $phpDir, null, $lab, 30.0);
    assertEqual($oversizedRes['exitCode'], 1, "Oversized context must exit 1");
    $oversizedJson = json_decode(trim($oversizedRes['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($oversizedJson['ok'], false, "Oversized ok false");
    assertTrue(str_contains($oversizedJson['error'], 'size limit'), "Stdout error must report size limit");

    // Subcase 6h: Loose script execution outside PHAR is rejected (outputs single JSON line)
    $looseScript = $repoRoot . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'shell-worker-inspect.php';
    $looseRes = runProcess([PHP_BINARY, $looseScript], $repoRoot, null, $lab, 30.0);
    assertEqual($looseRes['exitCode'], 1, "Loose script execution must exit 1");
    $looseJson = json_decode(trim($looseRes['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($looseJson['ok'], false, "Loose script JSON ok false");
    assertTrue(str_contains($looseJson['error'], 'outside compiled PHAR is prohibited'), "Loose script error message required in JSON");

    // Subcase 6i: Wrong PHAR identity using plan args (copied PHP, but outside built backend.phar)
    $wrongPharArgs = $plan['args'];
    $pharArgIndex = array_search($pharFile, $wrongPharArgs, true);
    assertTrue($pharArgIndex !== false, "Phar file must be present in plan args");
    $wrongPharArgs[$pharArgIndex] = $builtPharDest;

    $wrongPharRes = runProcess(array_merge([$phpExe], $wrongPharArgs), $phpDir, $plan['env'], $lab, 30.0);
    assertEqual($wrongPharRes['exitCode'], 1, "Wrong PHAR identity must exit 1");
    $wrongPharJson = json_decode(trim($wrongPharRes['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($wrongPharJson['ok'], false, "Wrong PHAR JSON ok false");
    assertTrue(str_contains($wrongPharJson['error'], 'PHAR path'), "Stdout error must report PHAR path mismatch");

    // Subcase 6j: Wrong runtime identity check (host PHP executing snapshot PHAR with plan args)
    $wrongBinaryRes = runProcess(array_merge([PHP_BINARY], $plan['args']), $phpDir, $plan['env'], $lab, 30.0);
    assertEqual($wrongBinaryRes['exitCode'], 1, "Host PHP executing PHAR must exit 1 on binary identity mismatch");
    $wrongBinaryJson = json_decode(trim($wrongBinaryRes['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($wrongBinaryJson['ok'], false, "Wrong binary JSON ok false");
    assertTrue(str_contains($wrongBinaryJson['error'], 'binary path'), "Stdout error must report binary path mismatch");

    // Subcase 6k: Wrong cwd using plan args (not private PHP directory)
    $wrongCwdRes = runProcess(array_merge([$phpExe], $plan['args']), $decoyDir, $plan['env'], $lab, 30.0);
    assertEqual($wrongCwdRes['exitCode'], 1, "Wrong working directory must exit 1");
    $wrongCwdJson = json_decode(trim($wrongCwdRes['stdout']), true, 32, JSON_THROW_ON_ERROR);
    assertEqual($wrongCwdJson['ok'], false, "Wrong cwd JSON ok false");
    assertTrue(str_contains($wrongCwdJson['error'], 'working directory'), "Stdout error must report working directory mismatch");

    echo "  [PASS] Suite 6: CLI flag validation and isolation defense passed.\n";

    // -------------------------------------------------------------------------
    // SUITE 7: Teardown Invariant Self-Test
    // -------------------------------------------------------------------------
    echo "\n[Suite 7] Teardown Invariant Self-Test...\n";

    $faultLab = $lab . DIRECTORY_SEPARATOR . 'fault-lab';
    mkdir($faultLab, 0700, true);
    ProductInventoryService::assertNoReparse($faultLab);
    $faultLabMarker = bin2hex(random_bytes(16));
    file_put_contents($faultLab . DIRECTORY_SEPARATOR . '.pos_owned_marker', $faultLabMarker);

    // Both fault roots nested in OWN lab to preserve outside victim immutability
    $faultVictim = $lab . DIRECTORY_SEPARATOR . 'fault-victim';
    mkdir($faultVictim, 0700, true);
    ProductInventoryService::assertNoReparse($faultVictim);
    $faultVictimMarker = bin2hex(random_bytes(16));
    file_put_contents($faultVictim . DIRECTORY_SEPARATOR . '.pos_owned_marker', $faultVictimMarker);

    $faultSentinelFile = $faultVictim . DIRECTORY_SEPARATOR . 'fault_sentinel.txt';
    $faultSentinelExpected = 'FAULT_SENTINEL_OK';
    file_put_contents($faultSentinelFile, $faultSentinelExpected);

    $faultLogFile = $faultLab . DIRECTORY_SEPARATOR . 'fault_proc.log';
    $faultLogExpected = 'FAULT_LOG_DATA';
    file_put_contents($faultLogFile, $faultLogExpected);

    // Spawn real child process running for 30s
    $faultDescriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', 'NUL', 'w'],
        2 => ['file', 'NUL', 'w'],
    ];
    $faultProc = proc_open([PHP_BINARY, '-r', 'usleep(30000000);'], $faultDescriptors, $faultPipes, $lab);
    assertTrue(is_resource($faultProc), "Fault child process must spawn successfully");

    // IMMEDIATELY register into global trackedChildProcs BEFORE any status/assert/hook
    $trackedChildProcs[] = $faultProc;
    $faultProcsList = [$faultProc];

    $initialStatus = getVerifiedProcessStatus($faultProc);
    assertEqual($initialStatus['state'], 'RUNNING', "Fault child must be RUNNING initially");

    $pendingRestorationWitness = false;
    $faultRestorationCallback = static function () use (&$pendingRestorationWitness): void {
        $pendingRestorationWitness = true;
    };

    // Set test hook for UNKNOWN status on exact child
    $testHookUnknownResource = $faultProc;

    $faultTdResult = performTeardownAndRetention(
        $faultProcsList,
        $faultLab,
        $faultLabMarker,
        $faultVictim,
        $faultVictimMarker,
        $faultSentinelFile,
        $faultSentinelExpected,
        $canonicalRunnerTemp,
        2.0,
        $faultRestorationCallback
    );

    // Teardown MUST return exact NEEDS ATTENTION and uncertain
    assertEqual($faultTdResult['cleaned'], false, "Teardown must not clean up when child is UNKNOWN");
    assertEqual($faultTdResult['uncertain'], true, "Teardown must report uncertain when child is UNKNOWN");
    assertEqual($faultTdResult['reason'], 'NEEDS ATTENTION: Child process was still live or uncertain; retaining workspaces for audit.', "Teardown reason must match exact expected string");

    // Assert handle still resource AND present in local fault list and global list
    assertTrue(is_resource($faultProc), "Fault child handle must still be a resource");
    assertTrue(in_array($faultProc, $faultProcsList, true), "Fault child must still be present in local fault list");
    assertTrue(in_array($faultProc, $trackedChildProcs, true), "Fault child must still be present in global trackedChildProcs");

    // Markers, log, and sentinel EXACT BYTES (not exists alone)
    assertEqual(file_get_contents($faultLab . DIRECTORY_SEPARATOR . '.pos_owned_marker'), $faultLabMarker, "Fault lab marker exact bytes must match");
    assertEqual(file_get_contents($faultVictim . DIRECTORY_SEPARATOR . '.pos_owned_marker'), $faultVictimMarker, "Fault victim marker exact bytes must match");
    assertEqual(file_get_contents($faultSentinelFile), $faultSentinelExpected, "Fault sentinel exact bytes must match");
    assertEqual(file_get_contents($faultLogFile), $faultLogExpected, "Fault log exact bytes must match");

    // Callback witness false
    assertFalse($pendingRestorationWitness, "Restoration callback witness must remain false when uncertain");

    // Clear hook, prove child is STILL real RUNNING, then kill and prove DEAD
    $testHookUnknownResource = null;
    $stBeforeKill = getVerifiedProcessStatus($faultProc);
    assertEqual($stBeforeKill['state'], 'RUNNING', "Fault child must still be RUNNING after clearing hook");

    $killExit = forceKillChildAndVerify($faultProc, 5);
    assertFalse(is_resource($faultProc), "Fault child process must be closed after forceKillChildAndVerify");
    assertFalse(in_array($faultProc, $trackedChildProcs, true), "Fault child process must be untracked from global trackedChildProcs");

    echo "  [PASS] Suite 7: Teardown invariant self-test passed.\n";

    $allSuitesPassed = true;

} catch (Throwable $e) {
    $primaryThrowable = $e;
} finally {
    // Clear synthetic test hook before REAL teardown
    $testHookUnknownResource = null;

    echo "\n[Teardown] Performing safe teardown and retention checks...\n";

    $safeRestoration = static function () use ($pendingCertRestoration): void {
        if ($pendingCertRestoration !== null) {
            $path = $pendingCertRestoration['path'];
            $bytes = $pendingCertRestoration['original_bytes'];

            ProductInventoryService::assertNoReparse($path);
            $canonicalPath = ProductInventoryService::resolveAbsolutePath($path, false);
            ProductInventoryService::assertNoReparse($canonicalPath);

            if (file_put_contents($path, $bytes) === false) {
                throw new RuntimeException("Failed to restore original update_public_key.pem");
            }
            $restored = file_get_contents($path);
            if ($restored === false || !hash_equals($bytes, $restored)) {
                throw new RuntimeException("Restored update_public_key.pem bytes do not match original bytes");
            }
        }
    };

    // Assert whole victim initial state is unchanged BEFORE cleanup
    $victimModifiedBeforeCleanup = false;
    $victimDiffError = null;
    try {
        assertDirectoryTreeStateMatches($victimDir, $initialVictimTreeState, "Final teardown: victim directory was modified during tests");
    } catch (Throwable $ve) {
        $victimModifiedBeforeCleanup = true;
        $victimDiffError = $ve->getMessage();
    }

    $tdResult = performTeardownAndRetention(
        $trackedChildProcs,
        $lab,
        $labOwnerToken,
        $victimDir,
        $victimOwnerToken,
        $victimSentinelFile,
        $victimSentinelExpected,
        $canonicalRunnerTemp,
        5.0,
        $safeRestoration
    );

    echo "[Teardown Result] " . $tdResult['reason'] . "\n";

    if ($victimModifiedBeforeCleanup) {
        fwrite(STDERR, "CRITICAL ERROR: " . $victimDiffError . "\n");
    }

    if ($primaryThrowable !== null) {
        fwrite(STDERR, "PRIMARY TEST FAILURE: " . $primaryThrowable->getMessage() . "\n" . $primaryThrowable->getTraceAsString() . "\n");
        if (!$tdResult['cleaned'] || $tdResult['uncertain']) {
            fwrite(STDERR, "NOTE: Workspaces retained for post-mortem: lab='{$lab}', victim='{$victimDir}'\n");
        }
        exit(1);
    }

    if ($victimModifiedBeforeCleanup) {
        exit(1);
    }

    if (!$tdResult['cleaned'] || $tdResult['uncertain']) {
        fwrite(STDERR, "CRITICAL: Teardown retained workspaces due to uncertainty or failure: {$tdResult['reason']}\n");
        exit(1);
    }

    if ($allSuitesPassed) {
        echo "======================================================================\n";
        echo "ALL INSPECTION SUITES PASSED SUCCESSFULLY AND WORKSPACES CLEANED\n";
        echo "======================================================================\n";
        exit(0);
    }

    exit(1);
}
