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
use App\Services\ShellDeltaWorkerSnapshotService;
use App\Services\UpdateChannelPolicy;
use App\Services\UpdateDataCompatibilityService;

// Validate RAW_RUNNER_TEMP through resolver before lab creation and guard raw ancestors
ProductInventoryService::assertNoReparse($rawRunnerTemp);
$canonicalRunnerTemp = ProductInventoryService::resolveAbsolutePath($rawRunnerTemp, true);
ProductInventoryService::assertNoReparse($canonicalRunnerTemp);

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
 * Capture full state of directory tree (files, sizes, hashes, subdirectories, links).
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

/** @var (callable(mixed): (?string))|null */
$globalProcessStatusHook = null;

/**
 * Direct process handle status query (Resource only, no PID kill).
 *
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
 * Run native command (e.g. mklink, rmdir) using fixed System32 cmd.exe.
 * Direct-handle tracked in $trackedChildProcs with bounded timeout.
 * Logs are retained in $labRoot for audit and not prematurely unlinked.
 *
 * @param list<string> $args
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runNativeCmd(array $args, string $labRoot): array
{
    global $trackedChildProcs;
    $systemRoot = getenv('SystemRoot') ?: (getenv('WINDIR') ?: 'C:\\Windows');
    $cmdExe = rtrim($systemRoot, '\\/') . '\\System32\\cmd.exe';
    if (!is_file($cmdExe)) {
        throw new RuntimeException("Fixed cmd.exe not found at: {$cmdExe}");
    }

    $cmd = array_merge([$cmdExe, '/d', '/c'], $args);
    $nonce = bin2hex(random_bytes(6));
    $stdoutLog = $labRoot . DIRECTORY_SEPARATOR . 'cmd-out-' . $nonce . '.log';
    $stderrLog = $labRoot . DIRECTORY_SEPARATOR . 'cmd-err-' . $nonce . '.log';

    $descriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $stdoutLog, 'w'],
        2 => ['file', $stderrLog, 'w'],
    ];

    $proc = proc_open($cmd, $descriptors, $pipes, $labRoot, null);
    if (!is_resource($proc)) {
        throw new RuntimeException("Failed to spawn native cmd process.");
    }

    $trackedChildProcs[] = $proc;
    $start = microtime(true);
    $running = true;
    $exitCode = -1;
    while ((microtime(true) - $start) < 15.0) {
        $st = getVerifiedProcessStatus($proc);
        if ($st['state'] === 'DEAD') {
            $running = false;
            $exitCode = $st['exitcode'];
            break;
        }
        if ($st['state'] === 'UNKNOWN') {
            break;
        }
        usleep(25000);
    }

    if ($running) {
        forceKillChildAndVerify($proc, 5);
        throw new RuntimeException("Native cmd process exceeded timeout.");
    }

    @proc_close($proc);
    $idx = array_search($proc, $trackedChildProcs, true);
    if ($idx !== false) {
        unset($trackedChildProcs[$idx]);
        $trackedChildProcs = array_values($trackedChildProcs);
    }

    $stdout = is_file($stdoutLog) ? (string) file_get_contents($stdoutLog, false, null, 0, 65536) : '';
    $stderr = is_file($stderrLog) ? (string) file_get_contents($stderrLog, false, null, 0, 65536) : '';

    return [
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

// -----------------------------------------------------------------------------
// TRACKED JUNCTIONS & SAFE LEXICAL CLEANUP
// -----------------------------------------------------------------------------

/** @var list<string> */
$createdJunctions = [];
/** @var array<string, string> */
$createdJunctionTargets = [];

function normalizeJunctionTarget(string $target): string
{
    $clean = str_replace('/', '\\', trim($target));
    if (str_starts_with($clean, '\\\\?\\')) {
        $clean = substr($clean, 4);
    } elseif (str_starts_with($clean, '\\??\\')) {
        $clean = substr($clean, 4);
    }
    return strtolower(rtrim($clean, '\\'));
}

/**
 * Independent native leaf metadata oracle via PowerShell 5.1.
 * Inspects leaf attributes and link type without resolving leaf target contents or recursing.
 * Uses bounded exact read (8192 bytes) after proven DEAD exit 0.
 * Logs are retained until global teardown.
 *
 * @return array{kind: 'junction'|'reparse'|'regular'|'absent'|'error'|'unknown', linkType: ?string, target: ?string, attributes: ?int, error: ?string}
 */
function queryNativeItemMetadata(string $path): array
{
    global $lab, $trackedChildProcs;

    if (!isset($lab) || !is_string($lab) || $lab === '' || !is_dir($lab)) {
        throw new RuntimeException("Global lab directory is not set or not a valid directory.");
    }
    ProductInventoryService::assertNoReparse($lab);

    $cleanPath = rtrim(str_replace('/', '\\', $path), '\\');
    $normLab = strtolower(rtrim(str_replace('/', '\\', $lab), '\\'));
    $normQuery = strtolower($cleanPath);
    if (!str_starts_with($normQuery, $normLab . '\\')) {
        throw new RuntimeException("Query path is not under lab root: '{$cleanPath}'");
    }

    $parentDir = dirname($cleanPath);
    ProductInventoryService::assertNoReparse($parentDir);

    $nonce = bin2hex(random_bytes(8));
    $stdoutLog = $lab . DIRECTORY_SEPARATOR . 'ps-oracle-out-' . $nonce . '.log';
    $stderrLog = $lab . DIRECTORY_SEPARATOR . 'ps-oracle-err-' . $nonce . '.log';

    $descriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $stdoutLog, 'w'],
        2 => ['file', $stderrLog, 'w'],
    ];

    $systemRoot = getenv('SystemRoot') ?: (getenv('WINDIR') ?: 'C:\\Windows');
    $psExe = rtrim($systemRoot, '\\/') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    if (!is_file($psExe)) {
        return [
            'kind' => 'unknown',
            'linkType' => null,
            'target' => null,
            'attributes' => null,
            'error' => 'PowerShell 5.1 executable not found',
        ];
    }

    $inputPayload = json_encode(['path' => $cleanPath], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $b64Input = base64_encode($inputPayload);

    $psScript = <<<POWERSHELL
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new(\$false)
\$ProgressPreference = 'SilentlyContinue'
\$ErrorActionPreference = 'Stop'
try {
    \$raw = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String('{$b64Input}'))
    \$in = ConvertFrom-Json \$raw
    \$item = Get-Item -LiteralPath \$in.path -Force -ErrorAction Stop
    \$attr = [int]\$item.Attributes
    \$isReparse = (\$attr -band 1024) -ne 0
    \$linkType = [string]\$item.LinkType
    \$target = ''
    if (\$item.Target) {
        if (\$item.Target -is [array] -or \$item.Target -is [System.Collections.IList]) {
            \$target = [string](\$item.Target | Select-Object -First 1)
        } else {
            \$target = [string]\$item.Target
        }
    }
    \$kind = 'regular'
    if (\$isReparse) {
        if (\$linkType -ieq 'Junction') {
            \$kind = 'junction'
        } else {
            \$kind = 'reparse'
        }
    }
    @{
        kind = \$kind
        linkType = \$linkType
        target = \$target
        attributes = \$attr
    } | ConvertTo-Json -Compress
    exit 0
} catch [System.Management.Automation.ItemNotFoundException] {
    @{ kind = 'absent' } | ConvertTo-Json -Compress
    exit 0
} catch [System.IO.FileNotFoundException] {
    @{ kind = 'absent' } | ConvertTo-Json -Compress
    exit 0
} catch [System.IO.DirectoryNotFoundException] {
    @{ kind = 'absent' } | ConvertTo-Json -Compress
    exit 0
} catch [System.UnauthorizedAccessException] {
    @{ kind = 'error'; error = 'AccessDenied' } | ConvertTo-Json -Compress
    exit 0
} catch {
    \$errId = [string]\$_.FullyQualifiedErrorId
    \$ex = \$_.Exception
    if (\$errId -like '*ItemNotFound*' -or \$errId -like '*PathNotFound*' -or (\$ex -is [System.IO.FileNotFoundException]) -or (\$ex -is [System.IO.DirectoryNotFoundException])) {
        @{ kind = 'absent' } | ConvertTo-Json -Compress
        exit 0
    } elseif (\$errId -like '*PermissionDenied*' -or (\$ex -is [System.UnauthorizedAccessException])) {
        @{ kind = 'error'; error = 'AccessDenied' } | ConvertTo-Json -Compress
        exit 0
    } else {
        @{ kind = 'error'; error = [string]\$_.ToString() } | ConvertTo-Json -Compress
        exit 0
    }
}
POWERSHELL;

    $encodedCommand = base64_encode(mb_convert_encoding($psScript, 'UTF-16LE', 'UTF-8'));
    $cmd = [
        $psExe,
        '-NoLogo',
        '-NoProfile',
        '-NonInteractive',
        '-EncodedCommand',
        $encodedCommand,
    ];

    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), null);
    if (!is_resource($proc)) {
        return [
            'kind' => 'unknown',
            'linkType' => null,
            'target' => null,
            'attributes' => null,
            'error' => 'Failed to spawn powershell oracle process',
        ];
    }

    $trackedChildProcs[] = $proc;
    $start = microtime(true);
    $running = true;
    $exitCode = -1;
    while ((microtime(true) - $start) < 10.0) {
        $st = getVerifiedProcessStatus($proc);
        if ($st['state'] === 'DEAD') {
            $running = false;
            $exitCode = $st['exitcode'];
            break;
        }
        if ($st['state'] === 'UNKNOWN') {
            break;
        }
        usleep(25000);
    }

    if ($running) {
        try {
            forceKillChildAndVerify($proc, 5);
        } catch (Throwable) {
        }
        return [
            'kind' => 'unknown',
            'linkType' => null,
            'target' => null,
            'attributes' => null,
            'error' => 'PowerShell oracle timed out or status UNKNOWN',
        ];
    }

    @proc_close($proc);
    $idx = array_search($proc, $trackedChildProcs, true);
    if ($idx !== false) {
        unset($trackedChildProcs[$idx]);
        $trackedChildProcs = array_values($trackedChildProcs);
    }

    if ($exitCode !== 0) {
        return [
            'kind' => 'unknown',
            'linkType' => null,
            'target' => null,
            'attributes' => null,
            'error' => "PowerShell oracle exited with non-zero code {$exitCode}",
        ];
    }

    if (!is_file($stdoutLog)) {
        return [
            'kind' => 'unknown',
            'linkType' => null,
            'target' => null,
            'attributes' => null,
            'error' => "Stdout log file does not exist",
        ];
    }

    $stdoutSize = @filesize($stdoutLog);
    if ($stdoutSize === false || $stdoutSize === 0 || $stdoutSize > 8192) {
        return [
            'kind' => 'unknown',
            'linkType' => null,
            'target' => null,
            'attributes' => null,
            'error' => "Stdout log size invalid or exceeds 8192 bytes limit",
        ];
    }

    $stdout = @file_get_contents($stdoutLog, false, null, 0, 8192);
    if ($stdout === false || strlen($stdout) !== $stdoutSize) {
        return [
            'kind' => 'unknown',
            'linkType' => null,
            'target' => null,
            'attributes' => null,
            'error' => "Failed to read exact stdout log bytes",
        ];
    }

    $stdoutTrim = ltrim(trim($stdout), "\xEF\xBB\xBF");
    if ($stdoutTrim === '') {
        return [
            'kind' => 'unknown',
            'linkType' => null,
            'target' => null,
            'attributes' => null,
            'error' => 'Stdout output was empty',
        ];
    }

    $decoded = null;
    try {
        $decoded = json_decode($stdoutTrim, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return [
            'kind' => 'unknown',
            'linkType' => null,
            'target' => null,
            'attributes' => null,
            'error' => 'Oracle JSON parsing failed: ' . $e->getMessage(),
        ];
    }

    if (!is_array($decoded) || !isset($decoded['kind']) || !is_string($decoded['kind'])) {
        return [
            'kind' => 'unknown',
            'linkType' => null,
            'target' => null,
            'attributes' => null,
            'error' => 'Oracle JSON missing string kind property',
        ];
    }

    $kind = $decoded['kind'];
    if (!in_array($kind, ['junction', 'reparse', 'regular', 'absent', 'error'], true)) {
        return [
            'kind' => 'unknown',
            'linkType' => null,
            'target' => null,
            'attributes' => null,
            'error' => "Unrecognized oracle kind: '{$kind}'",
        ];
    }

    if ($kind === 'junction') {
        if (!isset($decoded['attributes']) || !is_int($decoded['attributes']) || (($decoded['attributes'] & 1024) === 0)) {
            return [
                'kind' => 'unknown',
                'linkType' => null,
                'target' => null,
                'attributes' => null,
                'error' => 'Malformed junction: attributes missing ReparsePoint bit',
            ];
        }
        if (!isset($decoded['linkType']) || !is_string($decoded['linkType']) || strcasecmp($decoded['linkType'], 'Junction') !== 0) {
            return [
                'kind' => 'unknown',
                'linkType' => null,
                'target' => null,
                'attributes' => null,
                'error' => 'Malformed junction: linkType is not Junction',
            ];
        }
        if (!isset($decoded['target']) || !is_string($decoded['target']) || trim($decoded['target']) === '') {
            return [
                'kind' => 'unknown',
                'linkType' => null,
                'target' => null,
                'attributes' => null,
                'error' => 'Malformed junction: target is missing or empty',
            ];
        }
    } elseif ($kind === 'regular') {
        if (!isset($decoded['attributes']) || !is_int($decoded['attributes']) || (($decoded['attributes'] & 1024) !== 0)) {
            return [
                'kind' => 'unknown',
                'linkType' => null,
                'target' => null,
                'attributes' => null,
                'error' => 'Malformed regular item: attributes has ReparsePoint bit set',
            ];
        }
    }

    return [
        'kind' => $kind,
        'linkType' => isset($decoded['linkType']) && is_string($decoded['linkType']) ? $decoded['linkType'] : null,
        'target' => isset($decoded['target']) && is_string($decoded['target']) ? $decoded['target'] : null,
        'attributes' => isset($decoded['attributes']) && is_int($decoded['attributes']) ? $decoded['attributes'] : null,
        'error' => isset($decoded['error']) && is_string($decoded['error']) ? $decoded['error'] : null,
    ];
}

function createTrackedJunction(string $link, string $target): void
{
    global $lab, $victimDir, $createdJunctions, $createdJunctionTargets;

    if (!isset($lab) || !is_string($lab) || $lab === '' || !is_dir($lab)) {
        throw new RuntimeException("Global lab directory is not set or not a valid directory.");
    }
    ProductInventoryService::assertNoReparse($lab);

    $normLink = str_replace('/', '\\', $link);
    $normLab = strtolower(rtrim(str_replace('/', '\\', $lab), '\\'));
    if (!str_starts_with(strtolower($normLink), $normLab . '\\')) {
        throw new RuntimeException("Cannot create junction outside lab root: '{$link}'");
    }

    $parentDir = dirname($normLink);
    ProductInventoryService::assertNoReparse($parentDir);

    clearstatcache(true, $normLink);
    if (file_exists($normLink) || is_link($normLink)) {
        throw new RuntimeException("Link target already exists before creation: '{$link}'");
    }

    $canonicalTarget = ProductInventoryService::resolveAbsolutePath($target, true);
    ProductInventoryService::assertNoReparse($canonicalTarget);
    $normTarget = strtolower(rtrim(str_replace('/', '\\', $canonicalTarget), '\\'));

    $isOwnedTarget = false;
    if (isset($victimDir) && is_string($victimDir) && $victimDir !== '') {
        $normVictim = strtolower(rtrim(str_replace('/', '\\', $victimDir), '\\'));
        if ($normTarget === $normVictim || str_starts_with($normTarget, $normVictim . '\\')) {
            $isOwnedTarget = true;
        }
    }
    if ($normTarget === $normLab || str_starts_with($normTarget, $normLab . '\\')) {
        $isOwnedTarget = true;
    }
    if (!$isOwnedTarget) {
        throw new RuntimeException("Target path is not an owned victim or lab target: '{$target}'");
    }

    // Spawn native cmd using tracked helper
    $cmdRes = runNativeCmd(['mklink', '/J', $normLink, $canonicalTarget], $lab);
    if ($cmdRes['exitCode'] !== 0) {
        throw new RuntimeException("Failed to create junction from '{$link}' to '{$target}' (exit code {$cmdRes['exitCode']})");
    }

    // Track IMMEDIATELY after creation before oracle verification
    if (!in_array($normLink, $createdJunctions, true)) {
        $createdJunctions[] = $normLink;
    }
    $createdJunctionTargets[$normLink] = $canonicalTarget;
    if ($link !== $normLink) {
        $createdJunctionTargets[$link] = $canonicalTarget;
    }

    clearstatcache(true, $normLink);
    $postMeta = queryNativeItemMetadata($link);
    if ($postMeta['kind'] !== 'junction') {
        throw new RuntimeException("Created junction failed native junction verification: '{$link}'");
    }

    $expectedNorm = normalizeJunctionTarget($canonicalTarget);
    $actualNorm = normalizeJunctionTarget((string) ($postMeta['target'] ?? ''));
    if ($expectedNorm !== $actualNorm) {
        throw new RuntimeException("Created junction target mismatch: '{$link}'");
    }

    $rejectedByInventory = false;
    try {
        ProductInventoryService::assertNoReparse($normLink);
    } catch (Throwable) {
        $rejectedByInventory = true;
    }
    if (!$rejectedByInventory) {
        throw new RuntimeException("Created junction was unexpectedly accepted by ProductInventoryService: '{$link}'");
    }
}

function removeTrackedJunction(string $link, string $labRoot): void
{
    global $createdJunctions, $createdJunctionTargets;

    $normLink = str_replace('/', '\\', $link);
    $idx = array_search($normLink, $createdJunctions, true);
    if ($idx === false) {
        $idx = array_search($link, $createdJunctions, true);
        if ($idx === false) {
            return;
        }
    }

    $normLab = strtolower(rtrim(str_replace('/', '\\', $labRoot), '\\'));
    if (!str_starts_with(strtolower($normLink), $normLab . '\\')) {
        throw new RuntimeException("Tracked junction is not under lab root: '{$link}'");
    }

    $parentDir = dirname($normLink);
    ProductInventoryService::assertNoReparse($parentDir);

    $meta = queryNativeItemMetadata($link);
    if ($meta['kind'] === 'absent') {
        unset($createdJunctions[$idx]);
        $createdJunctions = array_values($createdJunctions);
        unset($createdJunctionTargets[$normLink], $createdJunctionTargets[$link]);
        return;
    }

    if ($meta['kind'] !== 'junction') {
        throw new RuntimeException("Refusing to remove tracked junction: leaf is not a verified junction: '{$link}'");
    }

    $expectedTarget = $createdJunctionTargets[$normLink] ?? ($createdJunctionTargets[$link] ?? null);
    if ($expectedTarget === null || !is_string($expectedTarget) || trim($expectedTarget) === '') {
        throw new RuntimeException("Refusing to remove tracked junction: missing expected target binding: '{$link}'");
    }
    $expectedNorm = normalizeJunctionTarget($expectedTarget);
    $actualNorm = normalizeJunctionTarget((string) ($meta['target'] ?? ''));
    if ($expectedNorm !== $actualNorm) {
        throw new RuntimeException("Refusing to remove tracked junction: target mismatch: '{$link}'");
    }

    // Run native cmd rmdir (NO /S: removes reparse link only)
    $cmdRes = runNativeCmd(['rmdir', $normLink], $labRoot);
    if ($cmdRes['exitCode'] !== 0) {
        throw new RuntimeException("Failed to remove tracked junction (exit code {$cmdRes['exitCode']}): '{$link}'");
    }

    clearstatcache(true, $normLink);
    $postMeta = queryNativeItemMetadata($link);
    if ($postMeta['kind'] !== 'absent') {
        throw new RuntimeException("Tracked junction still exists after rmdir: '{$link}'");
    }

    unset($createdJunctions[$idx]);
    $createdJunctions = array_values($createdJunctions);
    unset($createdJunctionTargets[$normLink], $createdJunctionTargets[$link]);
}

/**
 * Remove tracked junctions that reside under the specified directory without touching other junctions.
 */
function removeAllTrackedJunctions(string $labRoot): void
{
    global $createdJunctions;
    $junctionsCopy = $createdJunctions;
    $normLab = strtolower(rtrim(str_replace('/', '\\', $labRoot), '\\'));
    foreach ($junctionsCopy as $junc) {
        $normJunc = strtolower(str_replace('/', '\\', $junc));
        if ($normJunc === $normLab || str_starts_with($normJunc, $normLab . '\\')) {
            removeTrackedJunction($junc, $labRoot);
        }
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

// -----------------------------------------------------------------------------
// BUILDER CLI SUBPROCESS RUNNER
// -----------------------------------------------------------------------------

/**
 * Run real builder CLI subprocess to produce signed delta package and manifest.
 * Logs are retained in $labRoot for audit and not unlinked prematurely.
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

    $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), null);
    if (!is_resource($proc)) {
        throw new RuntimeException("Failed to run builder CLI.");
    }

    $trackedChildProcs[] = $proc;
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

    $stdout = is_file($stdoutLog) ? (string) file_get_contents($stdoutLog, false, null, 0, 1048576) : '';
    $stderr = is_file($stderrLog) ? (string) file_get_contents($stderrLog, false, null, 0, 1048576) : '';

    return [
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

// -----------------------------------------------------------------------------
// TEARDOWN AND RETENTION DECISION HELPER
// -----------------------------------------------------------------------------

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
            'reason' => 'NEEDS ATTENTION: Child process was still live or uncertain; retaining workspaces and junctions for audit.',
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

    try {
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
            'reason' => 'All tests passed cleanly and workspaces cleaned up',
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
// MAIN TEST RUNNER
// -----------------------------------------------------------------------------

echo "======================================================================\n";
echo "Shell Delta Worker Snapshot Verification Suite\n";
echo "Platform: " . PHP_OS_FAMILY . " | PHP: " . PHP_VERSION . "\n";
echo "======================================================================\n";

$labNonce = bin2hex(random_bytes(8));
$lab = $canonicalRunnerTemp . DIRECTORY_SEPARATOR . 'lab-worker-snap-' . $labNonce;
if (!mkdir($lab, 0700, false)) {
    fwrite(STDERR, "Failed to create lab directory: {$lab}\n");
    exit(1);
}
ProductInventoryService::assertNoReparse($lab);
$lab = ProductInventoryService::resolveAbsolutePath($lab, true);
ProductInventoryService::assertNoReparse($lab);

$labOwnerToken = bin2hex(random_bytes(16));
file_put_contents($lab . DIRECTORY_SEPARATOR . '.pos_owned_marker', $labOwnerToken);

$victimNonce = bin2hex(random_bytes(8));
$victimDir = $canonicalRunnerTemp . DIRECTORY_SEPARATOR . 'victim-worker-snap-' . $victimNonce;
if (!mkdir($victimDir, 0700, false)) {
    fwrite(STDERR, "Failed to create victim directory: {$victimDir}\n");
    exit(1);
}
ProductInventoryService::assertNoReparse($victimDir);
$victimDir = ProductInventoryService::resolveAbsolutePath($victimDir, true);
ProductInventoryService::assertNoReparse($victimDir);

$victimOwnerToken = bin2hex(random_bytes(16));
file_put_contents($victimDir . DIRECTORY_SEPARATOR . '.pos_owned_marker', $victimOwnerToken);

$victimSentinelFile = $victimDir . DIRECTORY_SEPARATOR . 'sentinel.txt';
$victimSentinelExpected = "VICTIM_INTACT_" . bin2hex(random_bytes(16));
file_put_contents($victimSentinelFile, $victimSentinelExpected);

// Setup mirrored subdirectories in victim to test attack containment
mkdir($victimDir . DIRECTORY_SEPARATOR . 'ext', 0700, false);
file_put_contents($victimDir . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'victim_keep.txt', "VICTIM_EXT_INTACT");

$initialVictimState = captureDirectoryTreeState($victimDir);

$allSuitesPassed = false;
$primaryThrowable = null;

try {
    // -------------------------------------------------------------------------
    // Setup 1: Ephemeral RSA Keypair & ManifestSignatureService
    // -------------------------------------------------------------------------
    echo "[Setup] Generating ephemeral RSA-2048 keypair...\n";
    $rsaRes = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($rsaRes === false) {
        throw new RuntimeException("Failed to generate ephemeral RSA keypair.");
    }
    openssl_pkey_export($rsaRes, $privKeyPem);
    $keyDetails = openssl_pkey_get_details($rsaRes);
    $pubKeyPem = $keyDetails['key'];

    $privKeyFile = $lab . DIRECTORY_SEPARATOR . 'ephemeral_signing_key.pem';
    file_put_contents($privKeyFile, $privKeyPem);

    $sigService = new ManifestSignatureService(null, [$pubKeyPem]);
    $manifestService = new ShellDeltaManifestService($sigService);
    $snapshotService = new ShellDeltaWorkerSnapshotService($manifestService);

    // -------------------------------------------------------------------------
    // Setup 2: Data Compatibility Contract
    // -------------------------------------------------------------------------
    $dataContract = [
        'format' => 1,
        'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
        'schema_sha256' => hash('sha256', 'MOCK_SCHEMA_DATA_V12'),
        'ledger_sha256' => hash('sha256', 'MOCK_LEDGER_DATA_V12'),
    ];
    $contractFile = $lab . DIRECTORY_SEPARATOR . 'contract.json';
    file_put_contents($contractFile, json_encode($dataContract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    // -------------------------------------------------------------------------
    // Setup 3: Beta Synthetic Product Files (with >= 128 allowed PHP ext DLL files)
    // -------------------------------------------------------------------------
    echo "[Setup] Building real Beta delta package via builder CLI (with >= 128 ext DLLs)...\n";
    $sourceBetaFiles = [
        'POS Desktop.exe' => "EXE_SHELL_V13_CONTENT_" . str_repeat('A', 500),
        'resources/app.asar' => "ASAR_SHELL_V13_CONTENT_" . str_repeat('B', 1000),
        'resources/app.asar.unpacked/version.json' => json_encode(['version' => '0.0.12-beta.1']),
        'resources/app.asar.unpacked/package.json' => json_encode(['name' => 'pos-desktop', 'version' => '0.0.12-beta.1']),
        'resources/app.asar.unpacked/backend/backend.phar' => "PHAR_V12_CONTENT_FILESYSTEM_ONLY",
        'resources/app.asar.unpacked/backend/certs/cacert.pem' => "CACERT_CONTENT",
        'resources/app.asar.unpacked/backend/certs/update_public_key.pem' => $pubKeyPem,
        'resources/app.asar.unpacked/database/pos_schema.sql' => "-- schema v12",
        'resources/app.asar.unpacked/frontend/dist/app.js' => "console.log('v12');",
        'resources/portable/runtime-manifest.json' => json_encode(['schemaVersion' => 1]),
        'resources/portable/php/php.exe' => "PHP_EXE_MOCK_BYTES",
        'resources/portable/php/php.ini' => "; mock php.ini\nmemory_limit = 512M\n",
        'resources/portable/php/php8embed.lib' => "LIB_DATA",
        'resources/portable/php/ext/php_openssl.dll' => "OPENSSL_DLL_MOCK",
        'resources/portable/php/ext/php_zip.dll' => "ZIP_DLL_MOCK",
        'resources/portable/mysql/bin/mysqld.exe' => "MYSQLD_MOCK",
        'locales/en-US.pak' => "PAK_EN_US",
        'icudtl.dat' => "ICU_DATA",
    ];

    // Add 130 allowed PHP extension DLL files to prove >= 128 bounded metadata handling
    for ($d = 1; $d <= 130; $d++) {
        $dllName = sprintf('resources/portable/php/ext/php_ext_%03d.dll', $d);
        $sourceBetaFiles[$dllName] = "MOCK_EXT_DLL_BYTES_" . $d;
    }

    $targetBetaFiles = $sourceBetaFiles;
    $targetBetaFiles['POS Desktop.exe'] = "EXE_SHELL_V14_CONTENT_" . str_repeat('Z', 600);
    $targetBetaFiles['resources/app.asar'] = "ASAR_SHELL_V14_CONTENT_" . str_repeat('Y', 1200);
    $targetBetaFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.14-beta.1']);
    $targetBetaFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.14-beta.1']);
    $targetBetaFiles['resources/app.asar.unpacked/backend/backend.phar'] = "PHAR_V14_CONTENT_FILESYSTEM_ONLY";

    $betaSrcDir = $lab . DIRECTORY_SEPARATOR . 'beta-src';
    $betaTgtDir = $lab . DIRECTORY_SEPARATOR . 'beta-tgt';
    $betaOutDir = $lab . DIRECTORY_SEPARATOR . 'beta-out';
    populateTree($betaSrcDir, $sourceBetaFiles);
    populateTree($betaTgtDir, $targetBetaFiles);

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
    // Setup 4: Stable Synthetic Product Files & Real Builder CLI Execution
    // -------------------------------------------------------------------------
    echo "[Setup] Building real Stable delta package via builder CLI...\n";
    $sourceStableFiles = $sourceBetaFiles;
    $sourceStableFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.12']);
    $sourceStableFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.12']);

    $targetStableFiles = $targetBetaFiles;
    $targetStableFiles['resources/app.asar.unpacked/version.json'] = json_encode(['version' => '0.0.14']);
    $targetStableFiles['resources/app.asar.unpacked/package.json'] = json_encode(['name' => 'pos-desktop', 'version' => '0.0.14']);

    $stableSrcDir = $lab . DIRECTORY_SEPARATOR . 'stable-src';
    $stableTgtDir = $lab . DIRECTORY_SEPARATOR . 'stable-tgt';
    $stableOutDir = $lab . DIRECTORY_SEPARATOR . 'stable-out';
    populateTree($stableSrcDir, $sourceStableFiles);
    populateTree($stableTgtDir, $targetStableFiles);

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

    // -------------------------------------------------------------------------
    // Suite 1: Beta channel create & verify + exact physical inspection (>= 128 DLLs)
    // -------------------------------------------------------------------------
    echo "[Suite 1] Beta channel create & verify (with >= 128 extension DLLs)...\n";
    $betaStateBase = $lab . DIRECTORY_SEPARATOR . 'state-beta';
    mkdir($betaStateBase, 0700, false);

    $betaSnap = $snapshotService->create($betaSrcDir, $betaStateBase, $betaManifestRaw, $betaSig, $betaContext);
    assertEqual($betaSnap['phase'], ShellDeltaWorkerSnapshotService::PHASE_FILES_VERIFIED, "Beta create phase must be verified");
    assertTrue(preg_match('/^rt_[a-f0-9]{32}$/D', $betaSnap['snapshot_id']) === 1, "Snapshot ID format");
    assertTrue(preg_match('/^[a-f0-9]{64}$/D', $betaSnap['snapshot_sha256']) === 1, "Snapshot SHA-256 scalar format");
    assertTrue(is_file($betaSnap['php_path']), "PHP path exists and is file");
    assertTrue(is_file($betaSnap['phar_path']), "PHAR path exists and is file");

    $snapRoot = $betaSnap['snapshot_root'];
    // Verify metadata completion file size is strictly <= 8 KiB despite >= 128 files
    $snapJsonBytes = file_get_contents($snapRoot . '/snapshot.json');
    assertTrue(strlen($snapJsonBytes) <= ShellDeltaWorkerSnapshotService::MAX_METADATA_SIZE, "snapshot.json size must be <= 8 KiB");

    // Verify scalar 64-hex files_sha256 in completion JSON
    $decodedSnapJson = json_decode($snapJsonBytes, true);
    assertTrue(is_string($decodedSnapJson['files_sha256']), "files_sha256 must be string");
    assertTrue(preg_match('/^[a-f0-9]{64}$/D', $decodedSnapJson['files_sha256']) === 1, "files_sha256 must be 64-hex string");

    // Verify all 130 DLL files exist in payload
    for ($d = 1; $d <= 130; $d++) {
        $dllPath = sprintf('%s/payload/resources/portable/php/ext/php_ext_%03d.dll', $snapRoot, $d);
        assertTrue(is_file($dllPath), "Payload DLL php_ext_{$d}.dll exists");
    }

    // Verify core payload files exist
    assertTrue(is_file($snapRoot . '/payload/resources/portable/php/php.exe'), "payload php.exe exists");
    assertTrue(is_file($snapRoot . '/payload/resources/portable/php/php.ini'), "payload php.ini exists");
    assertTrue(is_file($snapRoot . '/payload/resources/app.asar.unpacked/backend/backend.phar'), "payload backend.phar exists");
    assertTrue(is_file($snapRoot . '/payload/resources/app.asar.unpacked/backend/certs/update_public_key.pem'), "payload update_public_key.pem exists");
    assertTrue(is_file($snapRoot . '/payload/resources/app.asar.unpacked/backend/certs/cacert.pem'), "payload cacert.pem exists");

    // Verify unselected files were NOT copied
    assertFalse(file_exists($snapRoot . '/payload/POS Desktop.exe'), "POS Desktop.exe must not be copied");
    assertFalse(file_exists($snapRoot . '/payload/resources/app.asar'), "resources/app.asar must not be copied");
    assertFalse(file_exists($snapRoot . '/payload/resources/app.asar.unpacked/frontend'), "frontend must not be copied");
    assertFalse(file_exists($snapRoot . '/payload/resources/app.asar.unpacked/database'), "database must not be copied");
    assertFalse(file_exists($snapRoot . '/payload/resources/portable/mysql'), "mysql must not be copied");
    assertFalse(file_exists($snapRoot . '/payload/resources/portable/runtime-manifest.json'), "runtime-manifest.json must not be copied");

    // Verify sealed assets
    assertEqual(file_get_contents($snapRoot . '/sealed/shell-manifest.json'), $betaManifestRaw, "Sealed manifest exact bytes");
    assertEqual(file_get_contents($snapRoot . '/sealed/shell-manifest.sig'), $betaSig, "Sealed signature exact bytes");

    // Verify verify() call returns exact matching descriptor
    $betaVerify = $snapshotService->verify(
        $betaSrcDir,
        $betaStateBase,
        $betaSnap['snapshot_id'],
        $betaSnap['snapshot_sha256'],
        $betaManifestRaw,
        $betaSig,
        $betaContext
    );
    assertEqual($betaVerify, $betaSnap, "verify() return must match create() return exactly");

    // -------------------------------------------------------------------------
    // Suite 2: Stable channel create & verify
    // -------------------------------------------------------------------------
    echo "[Suite 2] Stable channel create & verify...\n";
    $stableStateBase = $lab . DIRECTORY_SEPARATOR . 'state-stable';
    mkdir($stableStateBase, 0700, false);

    $stableSnap = $snapshotService->create($stableSrcDir, $stableStateBase, $stableManifestRaw, $stableSig, $stableContext);
    assertEqual($stableSnap['phase'], ShellDeltaWorkerSnapshotService::PHASE_FILES_VERIFIED, "Stable create phase");
    $stableVerify = $snapshotService->verify(
        $stableSrcDir,
        $stableStateBase,
        $stableSnap['snapshot_id'],
        $stableSnap['snapshot_sha256'],
        $stableManifestRaw,
        $stableSig,
        $stableContext
    );
    assertEqual($stableVerify, $stableSnap, "Stable verify must match create");

    // -------------------------------------------------------------------------
    // Suite 3: Protected source state exclusion & protected junction
    // -------------------------------------------------------------------------
    echo "[Suite 3] Protected source state exclusion & protected junction exclusion...\n";
    $protectedSrcDir = $lab . DIRECTORY_SEPARATOR . 'beta-src-protected';
    $protectedFiles = $sourceBetaFiles;
    $protectedFiles['resources/portable/php/logs/app.log'] = "SECRET_LOG";
    $protectedFiles['resources/portable/php/.env'] = "SECRET_ENV=1";
    $protectedFiles['resources/portable/php/private.pem'] = "SECRET_PRIVATE_KEY";
    $protectedFiles['resources/portable/php/userdata/cache.db'] = "SECRET_DB";
    populateTree($protectedSrcDir, $protectedFiles);

    // Create protected junction inside product root pointing to outside victim
    $protectedJunc = $protectedSrcDir . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'portable' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'storage';
    createTrackedJunction($protectedJunc, $victimDir);

    $protectedStateBase = $lab . DIRECTORY_SEPARATOR . 'state-protected';
    mkdir($protectedStateBase, 0700, false);

    $protSnap = $snapshotService->create($protectedSrcDir, $protectedStateBase, $betaManifestRaw, $betaSig, $betaContext);
    $protSnapRoot = $protSnap['snapshot_root'];

    // Verify protected files and storage junction were completely excluded
    assertFalse(file_exists($protSnapRoot . '/payload/resources/portable/php/logs'), "Protected logs excluded");
    assertFalse(file_exists($protSnapRoot . '/payload/resources/portable/php/.env'), "Protected .env excluded");
    assertFalse(file_exists($protSnapRoot . '/payload/resources/portable/php/private.pem'), "Protected private key excluded");
    assertFalse(file_exists($protSnapRoot . '/payload/resources/portable/php/userdata'), "Protected userdata excluded");
    assertFalse(file_exists($protSnapRoot . '/payload/resources/portable/php/storage'), "Protected junction excluded");

    // Verify outside victim entire tree untouched
    assertDirectoryTreeStateMatches($victimDir, $initialVictimState, "Victim directory after protected exclusion");
    removeTrackedJunction($protectedJunc, $lab);

    // -------------------------------------------------------------------------
    // Suite 4: Verification independence from product root contents
    // -------------------------------------------------------------------------
    echo "[Suite 4] Snapshot verification independence from product root contents...\n";
    // 4A: Mutate product root contents on disk
    file_put_contents($betaSrcDir . '/resources/portable/php/php.exe', "MUTATED_PHP_EXE_CORRUPTED");
    file_put_contents($betaSrcDir . '/resources/app.asar.unpacked/backend/backend.phar', "MUTATED_PHAR_CORRUPTED");

    $verifyWithMutatedRoot = $snapshotService->verify(
        $betaSrcDir,
        $betaStateBase,
        $betaSnap['snapshot_id'],
        $betaSnap['snapshot_sha256'],
        $betaManifestRaw,
        $betaSig,
        $betaContext
    );
    assertEqual($verifyWithMutatedRoot, $betaSnap, "verify() succeeds even when product root file contents are mutated");

    // Restore files
    file_put_contents($betaSrcDir . '/resources/portable/php/php.exe', "PHP_EXE_MOCK_BYTES");
    file_put_contents($betaSrcDir . '/resources/app.asar.unpacked/backend/backend.phar', "PHAR_V12_CONTENT_FILESYSTEM_ONLY");

    // 4B: Rename product root directory to an aside directory (root completely missing)
    $betaAsideDir = $lab . DIRECTORY_SEPARATOR . 'beta-src-aside';
    rename($betaSrcDir, $betaAsideDir);
    assertFalse(file_exists($betaSrcDir), "betaSrcDir is missing on disk");

    $verifyWithMissingRoot = $snapshotService->verify(
        $betaSrcDir,
        $betaStateBase,
        $betaSnap['snapshot_id'],
        $betaSnap['snapshot_sha256'],
        $betaManifestRaw,
        $betaSig,
        $betaContext
    );
    assertEqual($verifyWithMissingRoot, $betaSnap, "verify() succeeds even when product root directory is missing on disk");

    // Rename back
    rename($betaAsideDir, $betaSrcDir);
    assertTrue(is_dir($betaSrcDir), "betaSrcDir restored");

    // -------------------------------------------------------------------------
    // Suite 5: Pre-create authentication, drive-root rejection, & genuine missing files
    // -------------------------------------------------------------------------
    echo "[Suite 5] Pre-create authentication, drive-root rejection, & genuine missing files...\n";
    $failStateBase = $lab . DIRECTORY_SEPARATOR . 'state-fail';
    mkdir($failStateBase, 0700, false);

    // 5A: Invalid signature
    assertThrows(
        fn() => $snapshotService->create($betaSrcDir, $failStateBase, $betaManifestRaw, base64_encode(str_repeat('X', 256)), $betaContext),
        "signature",
        "Invalid signature must fail pre-flight"
    );
    assertEqual(glob($failStateBase . '/worker_snapshots/*'), [], "No snapshot allocation on invalid signature");

    // 5B: Context with root key
    $ctxWithRoot = $betaContext;
    $ctxWithRoot['root'] = $betaSrcDir;
    assertThrows(
        fn() => $snapshotService->create($betaSrcDir, $failStateBase, $betaManifestRaw, $betaSig, $ctxWithRoot),
        "root",
        "Context with root key must be rejected"
    );
    assertEqual(glob($failStateBase . '/worker_snapshots/*'), [], "No snapshot allocation on root context key");

    // 5C: Context with unknown key
    $ctxUnknown = $betaContext;
    $ctxUnknown['arbitrary_extra'] = 'val';
    assertThrows(
        fn() => $snapshotService->create($betaSrcDir, $failStateBase, $betaManifestRaw, $betaSig, $ctxUnknown),
        "unknown",
        "Context with unknown key must be rejected"
    );

    // 5D: Context with wrong channel
    $ctxWrongChan = $betaContext;
    $ctxWrongChan['channel'] = 'stable';
    assertThrows(
        fn() => $snapshotService->create($betaSrcDir, $failStateBase, $betaManifestRaw, $betaSig, $ctxWrongChan),
        "channel",
        "Context with wrong channel must be rejected"
    );

    // 5E: Extra unsupported file in product root before create (drift)
    $driftSrcDir = $lab . DIRECTORY_SEPARATOR . 'beta-src-drift';
    $driftFiles = $sourceBetaFiles;
    $driftFiles['resources/portable/php/php-win.exe'] = "EXTRA_PHP_WIN_EXE";
    populateTree($driftSrcDir, $driftFiles);
    assertThrows(
        fn() => $snapshotService->create($driftSrcDir, $failStateBase, $betaManifestRaw, $betaSig, $betaContext),
        "inventory",
        "Source root drift pre-create must fail"
    );
    assertEqual(glob($failStateBase . '/worker_snapshots/*'), [], "No snapshot allocation on drift");

    // 5F: GENUINE builder fixture missing required php.exe from BOTH source & target
    echo "  Building genuine fixture missing php.exe from both source and target...\n";
    $noPhpSrc = $sourceBetaFiles;
    unset($noPhpSrc['resources/portable/php/php.exe']);
    $noPhpTgt = $targetBetaFiles;
    unset($noPhpTgt['resources/portable/php/php.exe']);

    $noPhpSrcDir = $lab . DIRECTORY_SEPARATOR . 'no-php-src';
    $noPhpTgtDir = $lab . DIRECTORY_SEPARATOR . 'no-php-tgt';
    $noPhpOutDir = $lab . DIRECTORY_SEPARATOR . 'no-php-out';
    populateTree($noPhpSrcDir, $noPhpSrc);
    populateTree($noPhpTgtDir, $noPhpTgt);

    $bResNoPhp = runBuilderCli([
        '--source-root', $noPhpSrcDir,
        '--target-root', $noPhpTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $contractFile,
        '--private-key', $privKeyFile,
        '--output-dir', $noPhpOutDir,
    ], $lab);
    assertEqual($bResNoPhp['exitCode'], 0, "Builder CLI must succeed when php.exe absent from both source and target");

    $noPhpManifestRaw = file_get_contents($noPhpOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $noPhpSig = file_get_contents($noPhpOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig');

    // Confirm manifest verification passes
    $noPhpVerifyPre = $manifestService->verify($noPhpManifestRaw, $noPhpSig, $betaContext);
    assertTrue($noPhpVerifyPre['ok'], "Manifest verify passes on genuine builder package without php.exe");

    // Snapshot create must reject specifically with required error and NO allocations
    assertThrows(
        fn() => $snapshotService->create($noPhpSrcDir, $failStateBase, $noPhpManifestRaw, $noPhpSig, $betaContext),
        "required payload file: resources/portable/php/php.exe",
        "Snapshot create must reject missing required php.exe"
    );
    assertEqual(glob($failStateBase . '/worker_snapshots/*'), [], "No snapshot allocation on missing required php.exe");

    // 5G: GENUINE builder fixture missing required backend.phar
    echo "  Building genuine fixture missing backend.phar from both source and target...\n";
    $noPharSrc = $sourceBetaFiles;
    unset($noPharSrc['resources/app.asar.unpacked/backend/backend.phar']);
    $noPharTgt = $targetBetaFiles;
    unset($noPharTgt['resources/app.asar.unpacked/backend/backend.phar']);

    $noPharSrcDir = $lab . DIRECTORY_SEPARATOR . 'no-phar-src';
    $noPharTgtDir = $lab . DIRECTORY_SEPARATOR . 'no-phar-tgt';
    $noPharOutDir = $lab . DIRECTORY_SEPARATOR . 'no-phar-out';
    populateTree($noPharSrcDir, $noPharSrc);
    populateTree($noPharTgtDir, $noPharTgt);

    $bResNoPhar = runBuilderCli([
        '--source-root', $noPharSrcDir,
        '--target-root', $noPharTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $contractFile,
        '--private-key', $privKeyFile,
        '--output-dir', $noPharOutDir,
    ], $lab);
    assertEqual($bResNoPhar['exitCode'], 0, "Builder CLI must succeed when backend.phar absent");

    $noPharManifestRaw = file_get_contents($noPharOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $noPharSig = file_get_contents($noPharOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig');

    assertThrows(
        fn() => $snapshotService->create($noPharSrcDir, $failStateBase, $noPharManifestRaw, $noPharSig, $betaContext),
        "required payload file: resources/app.asar.unpacked/backend/backend.phar",
        "Snapshot create must reject missing required backend.phar"
    );
    assertEqual(glob($failStateBase . '/worker_snapshots/*'), [], "No snapshot allocation on missing required backend.phar");

    // 5H: GENUINE builder fixture missing required update_public_key.pem
    echo "  Building genuine fixture missing update_public_key.pem from both source and target...\n";
    $noKeySrc = $sourceBetaFiles;
    unset($noKeySrc['resources/app.asar.unpacked/backend/certs/update_public_key.pem']);
    $noKeyTgt = $targetBetaFiles;
    unset($noKeyTgt['resources/app.asar.unpacked/backend/certs/update_public_key.pem']);

    $noKeySrcDir = $lab . DIRECTORY_SEPARATOR . 'no-key-src';
    $noKeyTgtDir = $lab . DIRECTORY_SEPARATOR . 'no-key-tgt';
    $noKeyOutDir = $lab . DIRECTORY_SEPARATOR . 'no-key-out';
    populateTree($noKeySrcDir, $noKeySrc);
    populateTree($noKeyTgtDir, $noKeyTgt);

    $bResNoKey = runBuilderCli([
        '--source-root', $noKeySrcDir,
        '--target-root', $noKeyTgtDir,
        '--source-native-version', '0.0.13',
        '--target-native-version', '0.0.14',
        '--source-data-contract', $contractFile,
        '--target-data-contract', $contractFile,
        '--private-key', $privKeyFile,
        '--output-dir', $noKeyOutDir,
    ], $lab);
    assertEqual($bResNoKey['exitCode'], 0, "Builder CLI must succeed when update_public_key.pem absent");

    $noKeyManifestRaw = file_get_contents($noKeyOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.json');
    $noKeySig = file_get_contents($noKeyOutDir . DIRECTORY_SEPARATOR . 'shell-manifest.sig');

    assertThrows(
        fn() => $snapshotService->create($noKeySrcDir, $failStateBase, $noKeyManifestRaw, $noKeySig, $betaContext),
        "required payload file: resources/app.asar.unpacked/backend/certs/update_public_key.pem",
        "Snapshot create must reject missing required update_public_key.pem"
    );
    assertEqual(glob($failStateBase . '/worker_snapshots/*'), [], "No snapshot allocation on missing required update_public_key.pem");

    // 5I: Pure lexical drive-root rejection on create and verify (without creating fixtures or traversing drive root)
    echo "  Testing lexical drive-root rejection on create and verify...\n";
    $driveRoots = ['C:\\', 'D:/', 'Z:\\', 'C:', 'd:/'];
    foreach ($driveRoots as $dr) {
        assertThrows(
            fn() => $snapshotService->create($dr, $failStateBase, $betaManifestRaw, $betaSig, $betaContext),
            "drive root is not permitted as productroot",
            "Drive root '{$dr}' as productRoot must be rejected lexically on create"
        );
        assertThrows(
            fn() => $snapshotService->create($betaSrcDir, $dr, $betaManifestRaw, $betaSig, $betaContext),
            "drive root is not permitted as privatestatebase",
            "Drive root '{$dr}' as privateStateBase must be rejected lexically on create"
        );
        assertThrows(
            fn() => $snapshotService->verify($dr, $betaStateBase, $betaSnap['snapshot_id'], $betaSnap['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
            "drive root is not permitted as productroot",
            "Drive root '{$dr}' as productRoot must be rejected lexically on verify"
        );
        assertThrows(
            fn() => $snapshotService->verify($betaSrcDir, $dr, $betaSnap['snapshot_id'], $betaSnap['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
            "drive root is not permitted as privatestatebase",
            "Drive root '{$dr}' as privateStateBase must be rejected lexically on verify"
        );
    }
    assertEqual(glob($failStateBase . '/worker_snapshots/*'), [], "No snapshot allocation on drive-root rejections");

    // -------------------------------------------------------------------------
    // Suite 6: Mid-copy, drift, and before_completion faults via checkpoint callbacks
    // -------------------------------------------------------------------------
    echo "[Suite 6] Mid-copy, drift, and before_completion faults via checkpoint callbacks...\n";

    // 6A: Mid-copy SOURCE file modified with SAME LENGTH different bytes ('before_source_copy')
    $faultStateBaseA = $lab . DIRECTORY_SEPARATOR . 'state-fault-a';
    mkdir($faultStateBaseA, 0700, false);
    $faultSrcA = $lab . DIRECTORY_SEPARATOR . 'src-fault-a';
    populateTree($faultSrcA, $sourceBetaFiles);

    $midCopyRan = false;
    $callbackA = static function (string $checkpoint, array $context) use ($faultSrcA, &$midCopyRan): void {
        if ($checkpoint === 'before_source_copy' && ($context['path'] ?? '') === 'resources/portable/php/php.exe') {
            $origLen = strlen("PHP_EXE_MOCK_BYTES");
            file_put_contents($faultSrcA . '/resources/portable/php/php.exe', str_repeat('X', $origLen));
            $midCopyRan = true;
        }
    };
    $serviceA = new ShellDeltaWorkerSnapshotService($manifestService, $callbackA);
    assertThrows(
        fn() => $serviceA->create($faultSrcA, $faultStateBaseA, $betaManifestRaw, $betaSig, $betaContext),
        "digest does not match signed source metadata: resources/portable/php/php.exe",
        "Mid-copy source modification must fail with specific digest mismatch"
    );
    assertTrue($midCopyRan, "Mid-copy fault injection actually ran");
    $snapsA = glob($faultStateBaseA . '/worker_snapshots/*');
    assertTrue(!empty($snapsA), "Partial snapshot directory retained");
    assertFalse(file_exists($snapsA[0] . '/snapshot.json'), "Completion record must be absent on mid-copy fault");

    // 6B: Private copied bytes corrupted after payload close ('after_payload_close')
    $faultStateBaseB = $lab . DIRECTORY_SEPARATOR . 'state-fault-b';
    mkdir($faultStateBaseB, 0700, false);
    $faultSrcB = $lab . DIRECTORY_SEPARATOR . 'src-fault-b';
    populateTree($faultSrcB, $sourceBetaFiles);

    $afterCloseRan = false;
    $callbackB = static function (string $checkpoint, array $context) use (&$afterCloseRan): void {
        if ($checkpoint === 'after_payload_close' && ($context['path'] ?? '') === 'resources/portable/php/php.exe') {
            file_put_contents($context['snapshot_path'], "CORRUPTED_COPIED_PAYLOAD_BYTES");
            $afterCloseRan = true;
        }
    };
    $serviceB = new ShellDeltaWorkerSnapshotService($manifestService, $callbackB);
    assertThrows(
        fn() => $serviceB->create($faultSrcB, $faultStateBaseB, $betaManifestRaw, $betaSig, $betaContext),
        "mismatch",
        "Corrupted payload bytes after close must fail"
    );
    assertTrue($afterCloseRan, "After close fault injection actually ran");
    $snapsB = glob($faultStateBaseB . '/worker_snapshots/*');
    assertTrue(!empty($snapsB), "Partial snapshot directory retained");
    assertFalse(file_exists($snapsB[0] . '/snapshot.json'), "Completion record must be absent on payload corruption");

    // 6C: Mutate unselected source file before completion ('before_completion')
    $faultStateBaseC = $lab . DIRECTORY_SEPARATOR . 'state-fault-c';
    mkdir($faultStateBaseC, 0700, false);
    $faultSrcC = $lab . DIRECTORY_SEPARATOR . 'src-fault-c';
    populateTree($faultSrcC, $sourceBetaFiles);

    $driftRan = false;
    $callbackC = static function (string $checkpoint, array $context) use ($faultSrcC, &$driftRan): void {
        if ($checkpoint === 'before_completion') {
            file_put_contents($faultSrcC . '/POS Desktop.exe', "MUTATED_UNSELECTED_EXE_CONTENT");
            $driftRan = true;
        }
    };
    $serviceC = new ShellDeltaWorkerSnapshotService($manifestService, $callbackC);
    assertThrows(
        fn() => $serviceC->create($faultSrcC, $faultStateBaseC, $betaManifestRaw, $betaSig, $betaContext),
        "drift",
        "Source root inventory drift before completion must fail"
    );
    assertTrue($driftRan, "Drift fault injection actually ran");
    $snapsC = glob($faultStateBaseC . '/worker_snapshots/*');
    assertTrue(!empty($snapsC), "Partial snapshot directory retained");
    assertFalse(file_exists($snapsC[0] . '/snapshot.json'), "Completion record must be absent on drift before completion");

    // 6D: Alter early copied PHAR bytes (same size to force digest) before completion
    $faultStateBaseD = $lab . DIRECTORY_SEPARATOR . 'state-fault-d';
    mkdir($faultStateBaseD, 0700, false);
    $pharFaultRan = false;
    $callbackD = static function (string $checkpoint, array $context) use (&$pharFaultRan): void {
        if ($checkpoint === 'before_completion') {
            $pharPath = $context['snapshot_root'] . '/payload/resources/app.asar.unpacked/backend/backend.phar';
            $origLen = strlen("PHAR_V12_CONTENT_FILESYSTEM_ONLY");
            file_put_contents($pharPath, str_repeat('P', $origLen));
            $pharFaultRan = true;
        }
    };
    $serviceD = new ShellDeltaWorkerSnapshotService($manifestService, $callbackD);
    assertThrows(
        fn() => $serviceD->create($betaSrcDir, $faultStateBaseD, $betaManifestRaw, $betaSig, $betaContext),
        "digest mismatch before completion: resources/app.asar.unpacked/backend/backend.phar",
        "Altering copied PHAR bytes before completion must fail"
    );
    assertTrue($pharFaultRan, "PHAR fault injection actually ran");
    $snapsD = glob($faultStateBaseD . '/worker_snapshots/*');
    assertTrue(!empty($snapsD), "Partial snapshot directory retained");
    assertFalse(file_exists($snapsD[0] . '/snapshot.json'), "Completion record must be absent");

    // 6E: Alter owner record bytes before completion
    $faultStateBaseE = $lab . DIRECTORY_SEPARATOR . 'state-fault-e';
    mkdir($faultStateBaseE, 0700, false);
    $ownerFaultRan = false;
    $callbackE = static function (string $checkpoint, array $context) use (&$ownerFaultRan): void {
        if ($checkpoint === 'before_completion') {
            $ownerFile = $context['snapshot_root'] . '/.pos_worker_snapshot_owner';
            file_put_contents($ownerFile, "TAMPERED_OWNER_BEFORE_COMPLETION");
            $ownerFaultRan = true;
        }
    };
    $serviceE = new ShellDeltaWorkerSnapshotService($manifestService, $callbackE);
    assertThrows(
        fn() => $serviceE->create($betaSrcDir, $faultStateBaseE, $betaManifestRaw, $betaSig, $betaContext),
        "owner record was modified or tampered before completion",
        "Altering owner record before completion must fail"
    );
    assertTrue($ownerFaultRan, "Owner fault injection actually ran");
    $snapsE = glob($faultStateBaseE . '/worker_snapshots/*');
    assertTrue(!empty($snapsE), "Partial snapshot directory retained");
    assertFalse(file_exists($snapsE[0] . '/snapshot.json'), "Completion record must be absent");

    // 6F: Alter sealed manifest bytes before completion
    $faultStateBaseF = $lab . DIRECTORY_SEPARATOR . 'state-fault-f';
    mkdir($faultStateBaseF, 0700, false);
    $sealedFaultRan = false;
    $callbackF = static function (string $checkpoint, array $context) use (&$sealedFaultRan): void {
        if ($checkpoint === 'before_completion') {
            $sealedFile = $context['snapshot_root'] . '/sealed/shell-manifest.json';
            file_put_contents($sealedFile, "TAMPERED_SEALED_MANIFEST_BEFORE_COMPLETION");
            $sealedFaultRan = true;
        }
    };
    $serviceF = new ShellDeltaWorkerSnapshotService($manifestService, $callbackF);
    assertThrows(
        fn() => $serviceF->create($betaSrcDir, $faultStateBaseF, $betaManifestRaw, $betaSig, $betaContext),
        "sealed manifest was modified or tampered before completion",
        "Altering sealed manifest before completion must fail"
    );
    assertTrue($sealedFaultRan, "Sealed manifest fault injection actually ran");
    $snapsF = glob($faultStateBaseF . '/worker_snapshots/*');
    assertTrue(!empty($snapsF), "Partial snapshot directory retained");
    assertFalse(file_exists($snapsF[0] . '/snapshot.json'), "Completion record must be absent");

    // 6G: Add extra empty directory in payload before completion
    $faultStateBaseG = $lab . DIRECTORY_SEPARATOR . 'state-fault-g';
    mkdir($faultStateBaseG, 0700, false);
    $extraDirRan = false;
    $callbackG = static function (string $checkpoint, array $context) use (&$extraDirRan): void {
        if ($checkpoint === 'before_completion') {
            $extraDir = $context['snapshot_root'] . '/payload/resources/portable/php/extra_empty_dir';
            mkdir($extraDir, 0700, false);
            $extraDirRan = true;
        }
    };
    $serviceG = new ShellDeltaWorkerSnapshotService($manifestService, $callbackG);
    assertThrows(
        fn() => $serviceG->create($betaSrcDir, $faultStateBaseG, $betaManifestRaw, $betaSig, $betaContext),
        "foreign directory",
        "Extra empty directory before completion must fail"
    );
    assertTrue($extraDirRan, "Extra dir fault injection actually ran");
    $snapsG = glob($faultStateBaseG . '/worker_snapshots/*');
    assertTrue(!empty($snapsG), "Partial snapshot directory retained");
    assertFalse(file_exists($snapsG[0] . '/snapshot.json'), "Completion record must be absent");

    // 6H: Add protected .env in payload before completion
    $faultStateBaseH = $lab . DIRECTORY_SEPARATOR . 'state-fault-h';
    mkdir($faultStateBaseH, 0700, false);
    $envFaultRan = false;
    $callbackH = static function (string $checkpoint, array $context) use (&$envFaultRan): void {
        if ($checkpoint === 'before_completion') {
            $envFile = $context['snapshot_root'] . '/payload/resources/portable/php/.env';
            file_put_contents($envFile, "SECRET_ENV");
            $envFaultRan = true;
        }
    };
    $serviceH = new ShellDeltaWorkerSnapshotService($manifestService, $callbackH);
    assertThrows(
        fn() => $serviceH->create($betaSrcDir, $faultStateBaseH, $betaManifestRaw, $betaSig, $betaContext),
        "foreign file",
        "Protected .env before completion must fail"
    );
    assertTrue($envFaultRan, "Protected .env fault injection actually ran");
    $snapsH = glob($faultStateBaseH . '/worker_snapshots/*');
    assertTrue(!empty($snapsH), "Partial snapshot directory retained");
    assertFalse(file_exists($snapsH[0] . '/snapshot.json'), "Completion record must be absent");

    // 6I: Base missing-child junction attack on FIRST selected source path (REQUIRED_BACKEND_PHAR)
    // Exercises raw BASE guard before mkdir when payloadRoot is replaced with junction to victimRoot.
    echo "  Testing payloadRoot base missing-child junction attack...\n";
    $faultStateBaseI = $lab . DIRECTORY_SEPARATOR . 'state-fault-i';
    mkdir($faultStateBaseI, 0700, false);
    $juncInCopyRan = false;
    $createdJuncInsideCopy = null;

    // Assert victim/resources MUST NOT exist before attack
    assertFalse(file_exists($victimDir . DIRECTORY_SEPARATOR . 'resources'), "victim/resources must not exist before attack");
    $victimTreeBefore6I = captureDirectoryTreeState($victimDir);
    $sourceBetaStateBefore6I = captureDirectoryTreeState($betaSrcDir);

    $callbackIConcrete = static function (string $checkpoint, array $context) use ($victimDir, $faultStateBaseI, &$juncInCopyRan, &$createdJuncInsideCopy): void {
        // Intercept on the VERY FIRST payload copy (REQUIRED_BACKEND_PHAR)
        if ($checkpoint === 'before_source_copy' && ($context['path'] ?? '') === ShellDeltaWorkerSnapshotService::REQUIRED_BACKEND_PHAR) {
            $activeSnaps = glob($faultStateBaseI . '/worker_snapshots/*');
            if (empty($activeSnaps)) {
                throw new RuntimeException("No active snapshot found under faultStateBaseI");
            }
            $snapshotRoot = $activeSnaps[0];
            $payloadRoot = $snapshotRoot . DIRECTORY_SEPARATOR . 'payload';
            ProductInventoryService::assertNoReparse($payloadRoot);
            $canonicalPayload = ProductInventoryService::resolveAbsolutePath($payloadRoot, true);
            ProductInventoryService::assertNoReparse($canonicalPayload);
            if (!is_dir($canonicalPayload)) {
                throw new RuntimeException("payloadRoot is not a directory before rename: {$payloadRoot}");
            }

            // Rename payloadRoot to an explicit owned aside directory under faultStateBase
            $asideDir = $snapshotRoot . DIRECTORY_SEPARATOR . 'payload_aside';
            ProductInventoryService::assertNoReparse(dirname($asideDir));
            if (!rename($canonicalPayload, $asideDir)) {
                throw new RuntimeException("Failed to rename payloadRoot to payload_aside");
            }
            clearstatcache(true, $canonicalPayload);
            if (file_exists($canonicalPayload) || is_link($canonicalPayload)) {
                throw new RuntimeException("payloadRoot still exists after rename");
            }

            // Create tracked junction at raw payloadRoot pointing to OWN victimRoot
            createTrackedJunction($payloadRoot, $victimDir);
            $juncMeta = queryNativeItemMetadata($payloadRoot);
            if ($juncMeta['kind'] !== 'junction') {
                throw new RuntimeException("Failed to attest junction at payloadRoot");
            }
            $createdJuncInsideCopy = $payloadRoot;
            $juncInCopyRan = true;
        }
    };

    $serviceI = new ShellDeltaWorkerSnapshotService($manifestService, $callbackIConcrete);
    assertThrows(
        fn() => $serviceI->create($betaSrcDir, $faultStateBaseI, $betaManifestRaw, $betaSig, $betaContext),
        "reparse",
        "Junction at payloadRoot base must fail closed with reparse error before mkdir"
    );
    assertTrue($juncInCopyRan, "Junction in copy fault injection actually ran");
    // Assert victim/resources was NOT created by module
    assertFalse(file_exists($victimDir . DIRECTORY_SEPARATOR . 'resources'), "victim/resources must not have been created by module");
    // Verify outside victim entire tree remains completely untouched
    assertDirectoryTreeStateMatches($victimDir, $victimTreeBefore6I, "Victim directory after payload base junction attack");
    // Verify source fixture remains unchanged
    assertDirectoryTreeStateMatches($betaSrcDir, $sourceBetaStateBefore6I, "Source fixture unchanged after 6I attack");
    if ($createdJuncInsideCopy !== null) {
        removeTrackedJunction($createdJuncInsideCopy, $lab);
    }
    $snapsI = glob($faultStateBaseI . '/worker_snapshots/*');
    assertTrue(!empty($snapsI), "Partial snapshot directory retained");
    assertTrue(is_dir($snapsI[0] . '/payload_aside'), "payload_aside retained in partial snapshot");
    assertFalse(file_exists($snapsI[0] . '/snapshot.json'), "Completion record must be absent");

    // 6J: SEPARATE source ancestor junction attack during before_source_copy
    echo "  Testing source ancestor junction attack during before_source_copy...\n";
    $faultStateBaseJ = $lab . DIRECTORY_SEPARATOR . 'state-fault-j';
    mkdir($faultStateBaseJ, 0700, false);
    $faultSrcJ = $lab . DIRECTORY_SEPARATOR . 'src-fault-j';
    populateTree($faultSrcJ, $sourceBetaFiles);

    $sourceJuncRan = false;
    $createdSourceJunc = null;
    $victimTreeBefore6J = captureDirectoryTreeState($victimDir);

    $callbackJ = static function (string $checkpoint, array $context) use ($faultSrcJ, $victimDir, &$sourceJuncRan, &$createdSourceJunc): void {
        if ($checkpoint === 'before_source_copy' && ($context['path'] ?? '') === 'resources/portable/php/ext/php_openssl.dll') {
            $extDir = $faultSrcJ . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'portable' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'ext';
            $extAside = $faultSrcJ . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'portable' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'ext_aside';
            if (rename($extDir, $extAside)) {
                createTrackedJunction($extDir, $victimDir . DIRECTORY_SEPARATOR . 'ext');
                $createdSourceJunc = $extDir;
                $sourceJuncRan = true;
            }
        }
    };

    $serviceJ = new ShellDeltaWorkerSnapshotService($manifestService, $callbackJ);
    assertThrows(
        fn() => $serviceJ->create($faultSrcJ, $faultStateBaseJ, $betaManifestRaw, $betaSig, $betaContext),
        "reparse",
        "Source ancestor junction must be rejected during walk before file copy"
    );
    assertTrue($sourceJuncRan, "Source ancestor junction fault actually ran");
    assertDirectoryTreeStateMatches($victimDir, $victimTreeBefore6J, "Victim directory after source ancestor junction attack");
    if ($createdSourceJunc !== null) {
        removeTrackedJunction($createdSourceJunc, $lab);
    }
    $snapsJ = glob($faultStateBaseJ . '/worker_snapshots/*');
    assertTrue(!empty($snapsJ), "Partial snapshot directory retained on source junction");
    assertFalse(file_exists($snapsJ[0] . '/snapshot.json'), "Completion record must be absent on source junction");

    // -------------------------------------------------------------------------
    // Suite 7: Reparse point & junction attacks
    // -------------------------------------------------------------------------
    echo "[Suite 7] Reparse point & junction attacks...\n";

    // 7A: Junction at privateStateBase
    $realStateBase = $lab . DIRECTORY_SEPARATOR . 'real-state';
    mkdir($realStateBase, 0700, false);
    $juncStateBase = $lab . DIRECTORY_SEPARATOR . 'junc-state';
    createTrackedJunction($juncStateBase, $realStateBase);

    assertThrows(
        fn() => $snapshotService->create($betaSrcDir, $juncStateBase, $betaManifestRaw, $betaSig, $betaContext),
        "reparse",
        "Junction at privateStateBase must be rejected"
    );
    removeTrackedJunction($juncStateBase, $lab);

    // 7B: Junction at productRoot during create
    $juncProductRoot = $lab . DIRECTORY_SEPARATOR . 'junc-product-root';
    createTrackedJunction($juncProductRoot, $betaSrcDir);

    assertThrows(
        fn() => $snapshotService->create($juncProductRoot, $betaStateBase, $betaManifestRaw, $betaSig, $betaContext),
        "reparse",
        "Junction at productRoot must be rejected during create"
    );
    removeTrackedJunction($juncProductRoot, $lab);

    // 7C: Junction at worker_snapshots targeting victim
    $juncSnapBase = $lab . DIRECTORY_SEPARATOR . 'state-junc-snap';
    mkdir($juncSnapBase, 0700, false);
    $juncSnapDir = $juncSnapBase . DIRECTORY_SEPARATOR . 'worker_snapshots';
    createTrackedJunction($juncSnapDir, $victimDir);

    assertThrows(
        fn() => $snapshotService->create($betaSrcDir, $juncSnapBase, $betaManifestRaw, $betaSig, $betaContext),
        "reparse",
        "Junction at worker_snapshots must be rejected"
    );
    assertDirectoryTreeStateMatches($victimDir, $initialVictimState, "Victim directory after worker_snapshots junction attack");
    removeTrackedJunction($juncSnapDir, $lab);

    // 7D: Junction attack inside payload during verify (using fresh snapshot create, NO xcopy)
    $stateVerifyJunc = $lab . DIRECTORY_SEPARATOR . 'state-verify-junc';
    mkdir($stateVerifyJunc, 0700, false);
    $freshSnapForJunc = $snapshotService->create($betaSrcDir, $stateVerifyJunc, $betaManifestRaw, $betaSig, $betaContext);

    $victimPayloadJunc = $freshSnapForJunc['snapshot_root'] . '\\payload\\victim_junc';
    createTrackedJunction($victimPayloadJunc, $victimDir);

    assertThrows(
        fn() => $snapshotService->verify(
            $betaSrcDir,
            $stateVerifyJunc,
            $freshSnapForJunc['snapshot_id'],
            $freshSnapForJunc['snapshot_sha256'],
            $betaManifestRaw,
            $betaSig,
            $betaContext
        ),
        "reparse",
        "Junction inside payload must fail closed during verify"
    );
    assertDirectoryTreeStateMatches($victimDir, $initialVictimState, "Victim directory after verify junction attack");
    removeTrackedJunction($victimPayloadJunc, $lab);

    // -------------------------------------------------------------------------
    // Suite 8: Verification tamper & fail-closed checks (NO xcopy, fresh snapshots)
    // -------------------------------------------------------------------------
    echo "[Suite 8] Verification tamper & fail-closed checks (fresh snapshot fixtures)...\n";

    // 8A: Tamper snapshot.json completion bytes
    $base8A = $lab . DIRECTORY_SEPARATOR . 'state-tamper-8a';
    mkdir($base8A, 0700, false);
    $snap8A = $snapshotService->create($betaSrcDir, $base8A, $betaManifestRaw, $betaSig, $betaContext);
    file_put_contents($snap8A['snapshot_root'] . '/snapshot.json', "TAMPERED_COMPLETION_JSON");
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $base8A, $snap8A['snapshot_id'], $snap8A['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
        "digest",
        "Tampered completion file must fail digest comparison"
    );

    // 8B: Tamper .pos_worker_snapshot_owner
    $base8B = $lab . DIRECTORY_SEPARATOR . 'state-tamper-8b';
    mkdir($base8B, 0700, false);
    $snap8B = $snapshotService->create($betaSrcDir, $base8B, $betaManifestRaw, $betaSig, $betaContext);
    file_put_contents($snap8B['snapshot_root'] . '/.pos_worker_snapshot_owner', "TAMPERED_OWNER");
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $base8B, $snap8B['snapshot_id'], $snap8B['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
        "owner",
        "Tampered owner file must fail owner digest comparison"
    );

    // 8C: Tamper sealed/shell-manifest.json
    $base8C = $lab . DIRECTORY_SEPARATOR . 'state-tamper-8c';
    mkdir($base8C, 0700, false);
    $snap8C = $snapshotService->create($betaSrcDir, $base8C, $betaManifestRaw, $betaSig, $betaContext);
    file_put_contents($snap8C['snapshot_root'] . '/sealed/shell-manifest.json', "TAMPERED_SEALED_MANIFEST");
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $base8C, $snap8C['snapshot_id'], $snap8C['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
        "sealed",
        "Tampered sealed manifest must fail comparison"
    );

    // 8D: Tamper sealed/shell-manifest.sig
    $base8D = $lab . DIRECTORY_SEPARATOR . 'state-tamper-8d';
    mkdir($base8D, 0700, false);
    $snap8D = $snapshotService->create($betaSrcDir, $base8D, $betaManifestRaw, $betaSig, $betaContext);
    file_put_contents($snap8D['snapshot_root'] . '/sealed/shell-manifest.sig', "TAMPERED_SIG");
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $base8D, $snap8D['snapshot_id'], $snap8D['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
        "sealed",
        "Tampered sealed signature must fail comparison"
    );

    // 8E: Tamper expectedSnapshotSha256 argument
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $betaStateBase, $betaSnap['snapshot_id'], hash('sha256', 'wrong_digest'), $betaManifestRaw, $betaSig, $betaContext),
        "digest",
        "Wrong expectedSnapshotSha256 must fail verification"
    );

    // 8F: Pass DIFFERENT valid signed manifest (Stable manifest passed to Beta snapshot)
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $betaStateBase, $betaSnap['snapshot_id'], $betaSnap['snapshot_sha256'], $stableManifestRaw, $stableSig, $betaContext),
        "manifest",
        "Different valid signed manifest must fail comparison with sealed manifest"
    );

    // 8G: Unknown extra file in payload
    $base8G = $lab . DIRECTORY_SEPARATOR . 'state-tamper-8g';
    mkdir($base8G, 0700, false);
    $snap8G = $snapshotService->create($betaSrcDir, $base8G, $betaManifestRaw, $betaSig, $betaContext);
    file_put_contents($snap8G['snapshot_root'] . '/payload/resources/portable/php/extra.txt', "EXTRA_FILE");
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $base8G, $snap8G['snapshot_id'], $snap8G['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
        "foreign",
        "Extra unknown file in payload must fail closed"
    );

    // 8H: Unknown extra empty directory in payload
    $base8H = $lab . DIRECTORY_SEPARATOR . 'state-tamper-8h';
    mkdir($base8H, 0700, false);
    $snap8H = $snapshotService->create($betaSrcDir, $base8H, $betaManifestRaw, $betaSig, $betaContext);
    mkdir($snap8H['snapshot_root'] . '/payload/resources/portable/php/extra_dir', 0700, false);
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $base8H, $snap8H['snapshot_id'], $snap8H['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
        "foreign",
        "Extra empty directory in payload must fail closed"
    );

    // 8I: Protected path injected into payload (.env)
    $base8I = $lab . DIRECTORY_SEPARATOR . 'state-tamper-8i';
    mkdir($base8I, 0700, false);
    $snap8I = $snapshotService->create($betaSrcDir, $base8I, $betaManifestRaw, $betaSig, $betaContext);
    file_put_contents($snap8I['snapshot_root'] . '/payload/resources/portable/php/.env', "SECRET_ENV");
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $base8I, $snap8I['snapshot_id'], $snap8I['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
        "foreign",
        "Protected .env file in payload must fail closed"
    );

    // 8J: Invalid snapshotId format (traversal, ADS, uppercase)
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $betaStateBase, '../rt_' . bin2hex(random_bytes(16)), $betaSnap['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
        "format",
        "Traversal snapshotId rejected"
    );
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $betaStateBase, 'rt_1234:ads', $betaSnap['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
        "format",
        "ADS snapshotId rejected"
    );
    assertThrows(
        fn() => $snapshotService->verify($betaSrcDir, $betaStateBase, 'RT_' . bin2hex(random_bytes(16)), $betaSnap['snapshot_sha256'], $betaManifestRaw, $betaSig, $betaContext),
        "format",
        "Uppercase snapshotId rejected"
    );

    // 8K: Overlapping directories
    assertThrows(
        fn() => $snapshotService->create($betaSrcDir, $betaSrcDir, $betaManifestRaw, $betaSig, $betaContext),
        "same",
        "Same directory root and state base rejected"
    );
    assertThrows(
        fn() => $snapshotService->create($betaSrcDir, $betaSrcDir . '/state', $betaManifestRaw, $betaSig, $betaContext),
        "inside",
        "State base inside product root rejected"
    );

    // -------------------------------------------------------------------------
    // Suite 9: Synthetic child UNKNOWN injection proving teardown retention
    // -------------------------------------------------------------------------
    echo "[Suite 9] Synthetic child UNKNOWN status injection proving teardown retention...\n";
    $suite9Nonce = bin2hex(random_bytes(8));
    $faultLab = $lab . DIRECTORY_SEPARATOR . 'suite9-lab-' . $suite9Nonce;
    mkdir($faultLab, 0700, false);
    ProductInventoryService::assertNoReparse($faultLab);
    $faultLab = ProductInventoryService::resolveAbsolutePath($faultLab, true);
    ProductInventoryService::assertNoReparse($faultLab);

    $faultLabToken = bin2hex(random_bytes(16));
    file_put_contents($faultLab . DIRECTORY_SEPARATOR . '.pos_owned_marker', $faultLabToken);
    file_put_contents($faultLab . DIRECTORY_SEPARATOR . 'known_log.log', "KNOWN_LOG_BYTES_" . $suite9Nonce);

    $faultVictim = $lab . DIRECTORY_SEPARATOR . 'suite9-victim-' . $suite9Nonce;
    mkdir($faultVictim, 0700, false);
    ProductInventoryService::assertNoReparse($faultVictim);
    $faultVictim = ProductInventoryService::resolveAbsolutePath($faultVictim, true);
    ProductInventoryService::assertNoReparse($faultVictim);

    $faultVictimToken = bin2hex(random_bytes(16));
    file_put_contents($faultVictim . DIRECTORY_SEPARATOR . '.pos_owned_marker', $faultVictimToken);
    $faultSentinelFile = $faultVictim . DIRECTORY_SEPARATOR . 'sentinel.txt';
    $faultSentinelExpected = "SUITE9_SENTINEL_" . $suite9Nonce;
    file_put_contents($faultSentinelFile, $faultSentinelExpected);

    // Create tracked junction inside faultLab pointing to faultVictim
    $faultJunc = $faultLab . DIRECTORY_SEPARATOR . 'fault_link';
    createTrackedJunction($faultJunc, $faultVictim);

    // Spawn bounded 30s real PHP child process with file output inside faultLab
    $childStdout = $faultLab . DIRECTORY_SEPARATOR . 'child-out.log';
    $childStderr = $faultLab . DIRECTORY_SEPARATOR . 'child-err.log';
    $descriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $childStdout, 'w'],
        2 => ['file', $childStderr, 'w'],
    ];
    $testChildProc = proc_open([PHP_BINARY, '-r', 'usleep(30000000);'], $descriptors, $pipes, $faultLab, null);
    assertTrue(is_resource($testChildProc), "Spawn test child process bounded to 30s");
    $trackedChildProcs[] = $testChildProc;

    try {
        // Assert REAL status is RUNNING immediately before injecting status hook
        $stPre = getVerifiedProcessStatus($testChildProc);
        assertEqual($stPre['state'], 'RUNNING', "Real status of test child must be RUNNING before injecting status hook");

        // Inject UNKNOWN status ONLY for this specific resource
        $globalProcessStatusHook = static function (mixed $p) use ($testChildProc): ?string {
            if ($p === $testChildProc) {
                return 'UNKNOWN';
            }
            return null;
        };

        $faultTracked = [$testChildProc];
        $retentionResult = performTeardownAndRetention(
            $faultTracked,
            $faultLab,
            $faultLabToken,
            $faultVictim,
            $faultVictimToken,
            $faultSentinelFile,
            $faultSentinelExpected,
            $canonicalRunnerTemp,
            0.5
        );

        // Require exact UNKNOWN-process reason, uncleared child handle, unchanged bytes, roots retained
        assertFalse($retentionResult['cleaned'], "Teardown helper MUST refuse cleanup when child status is uncertain");
        assertTrue($retentionResult['uncertain'], "Teardown helper MUST flag uncertain process status");
        assertEqual($retentionResult['reason'], 'NEEDS ATTENTION: Child process was still live or uncertain; retaining workspaces and junctions for audit.', "Exact UNKNOWN-process reason required");
        assertTrue(in_array($testChildProc, $faultTracked, true), "Helper MUST NOT clear child handle of alive/uncertain process");
        assertTrue(is_resource($testChildProc), "Helper MUST keep process handle open");

        // Verify both roots and files are retained
        assertTrue(is_dir($faultLab), "faultLab retained");
        assertTrue(is_dir($faultVictim), "faultVictim retained");
        assertEqual(file_get_contents($faultLab . DIRECTORY_SEPARATOR . '.pos_owned_marker'), $faultLabToken, "faultLab owner marker unchanged");
        assertEqual(file_get_contents($faultLab . DIRECTORY_SEPARATOR . 'known_log.log'), "KNOWN_LOG_BYTES_" . $suite9Nonce, "faultLab log unchanged");
        assertEqual(file_get_contents($faultVictim . DIRECTORY_SEPARATOR . '.pos_owned_marker'), $faultVictimToken, "faultVictim owner marker unchanged");
        assertEqual(file_get_contents($faultSentinelFile), $faultSentinelExpected, "faultVictim sentinel unchanged");

        // Verify native expected junction target still exists
        $juncMeta = queryNativeItemMetadata($faultJunc);
        assertEqual($juncMeta['kind'], 'junction', "faultJunc must still exist as native junction");
        assertEqual(normalizeJunctionTarget((string) $juncMeta['target']), normalizeJunctionTarget($faultVictim), "faultJunc target matches faultVictim");
    } finally {
        // Clear mock hook so it doesn't mask real teardown
        $globalProcessStatusHook = null;

        // Assert child still RUNNING before kill
        $stPreKill = getVerifiedProcessStatus($testChildProc);
        assertEqual($stPreKill['state'], 'RUNNING', "Process must still be RUNNING before kill");

        // Force kill child and prove DEAD before cleanup
        forceKillChildAndVerify($testChildProc, 5);
        assertFalse(is_resource($testChildProc), "Verified-dead child handle must be closed by helper");
        assertFalse(in_array($testChildProc, $trackedChildProcs, true), "Verified-dead child must be untracked by helper");

        // Remove tracked fault junction and safely clean up fault workspaces
        removeTrackedJunction($faultJunc, $lab);
        safeLexicalCleanup($faultLab, $faultLabToken, $canonicalRunnerTemp);
        safeLexicalCleanup($faultVictim, $faultVictimToken, $canonicalRunnerTemp);
    }

    $allSuitesPassed = true;

} catch (Throwable $e) {
    $primaryThrowable = $e;
} finally {
    // Ensure mock hook is cleared
    $globalProcessStatusHook = null;

    echo "[Teardown] Performing final teardown and retention checks...\n";
    $tdResult = performTeardownAndRetention(
        $trackedChildProcs,
        $lab,
        $labOwnerToken,
        $victimDir,
        $victimOwnerToken,
        $victimSentinelFile,
        $victimSentinelExpected,
        $canonicalRunnerTemp
    );

    echo "[Teardown Result] " . $tdResult['reason'] . "\n";

    if ($primaryThrowable !== null) {
        fwrite(STDERR, "PRIMARY TEST FAILURE: " . $primaryThrowable->getMessage() . "\n" . $primaryThrowable->getTraceAsString() . "\n");
        if (!$tdResult['cleaned'] || $tdResult['uncertain']) {
            fwrite(STDERR, "NOTE: Workspaces retained for post-mortem: lab='{$lab}', victim='{$victimDir}'\n");
        }
        exit(1);
    }

    if (!$tdResult['cleaned'] || $tdResult['uncertain']) {
        fwrite(STDERR, "CRITICAL: Teardown retained workspaces due to uncertainty or failure: {$tdResult['reason']}\n");
        fwrite(STDERR, "Retained: lab='{$lab}', victim='{$victimDir}'\n");
        exit(1);
    }

    if ($allSuitesPassed) {
        echo "======================================================================\n";
        echo "ALL SUITES PASSED SUCCESSFULLY AND WORKSPACES CLEANED\n";
        echo "======================================================================\n";
        exit(0);
    }

    exit(1);
}
