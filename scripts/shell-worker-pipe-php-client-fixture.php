<?php
declare(strict_types=1);

/**
 * scripts/shell-worker-pipe-php-client-fixture.php
 *
 * GHA-ONLY SYNTHETIC PHP WORKER PIPE CLIENT TEST FIXTURE.
 * Used exclusively by verify-shell-native-php-worker-pipe.ps1 in GitHub Actions.
 *
 * ARCHITECTURAL TRUST & EXECUTION BOUNDARIES:
 * 1. This is a SYNTHETIC CLIENT FIXTURE, NOT a production entry point or launcher.
 * 2. Process identity is NOT code, image, or payload authentication.
 * 3. CanApply is unconditionally false across all results.
 * 4. Pure in-memory projection: zero customer DB queries, zero file application writes.
 * 5. Runs exclusively in GitHub Actions under CLI on 64-bit Windows.
 */

// Section 1: Strict Execution Environment Guards (Fail early, independent checks)
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "GUARD: CLI SAPI required.\n");
    exit(1);
}
if (getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "GUARD: GITHUB_ACTIONS=true required.\n");
    exit(1);
}
if (PHP_OS_FAMILY !== 'Windows') {
    fwrite(STDERR, "GUARD: Windows OS required.\n");
    exit(1);
}
if (getenv('RUNNER_OS') !== 'Windows') {
    fwrite(STDERR, "GUARD: RUNNER_OS=Windows required.\n");
    exit(1);
}
if (PHP_INT_SIZE !== 8) {
    fwrite(STDERR, "GUARD: 64-bit PHP runtime required.\n");
    exit(1);
}

// Section 2: Autoloading (Vendor or explicit SPL fallback for App\Services)
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
use App\Services\ShellDeltaWorkerFilePlanService;
use App\Services\ShellDeltaWorkerPipeTransportService;
use App\Services\UpdateChannelPolicy;
use App\Services\UpdateDataCompatibilityService;

// Explicit fixture memory limit for 64 MiB roundtrip buffers
ini_set('memory_limit', '256M');

// Section 3: Argument Parsing & Validation
$options = getopt('', [
    'mode:',
    'endpoint:',
    'fixture::',
    'result-file::',
    'ready-file::',
    'hold-gate-file::',
    'expected-frames::',
]);

$mode = $options['mode'] ?? null;
$endpoint = $options['endpoint'] ?? null;
$fixturePath = $options['fixture'] ?? null;
$resultFile = $options['result-file'] ?? null;
$readyFile = $options['ready-file'] ?? null;
$holdGateFile = $options['hold-gate-file'] ?? null;
$expectedFramesRaw = $options['expected-frames'] ?? null;

$allowedModes = ['echo', 'plan', 'stall', 'expect_fail'];
if (!is_string($mode) || !in_array($mode, $allowedModes, true)) {
    fwrite(STDERR, "ERROR: --mode must be one of: echo, plan, stall, expect_fail.\n");
    exit(1);
}

if (!is_string($endpoint) || preg_match('/\Apos-worker-[a-f0-9]{32}\z/D', $endpoint) !== 1) {
    fwrite(STDERR, "ERROR: --endpoint must match pos-worker-[a-f0-9]{32}.\n");
    exit(1);
}

$expectedFrames = 1;
if ($expectedFramesRaw !== null) {
    if (!is_numeric($expectedFramesRaw) || (int) $expectedFramesRaw < 1 || (int) $expectedFramesRaw > 1024) {
        fwrite(STDERR, "ERROR: --expected-frames must be an integer between 1 and 1024.\n");
        exit(1);
    }
    $expectedFrames = (int) $expectedFramesRaw;
}

/**
 * Record bounded result record to private result file via exclusive creation.
 *
 * @param string|null $path
 * @param array<string, mixed> $data
 */
function recordResultFile(?string $path, array $data): void
{
    if ($path === null || trim($path) === '') {
        return;
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (strlen($json) > 1048576) {
        throw new RuntimeException("Result file content exceeds 1 MiB cap.");
    }
    $fp = @fopen($path, 'xb');
    if ($fp === false) {
        throw new RuntimeException("Cannot open result file for exclusive write: {$path}");
    }
    $primaryEx = null;
    try {
        $len = strlen($json);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($fp, substr($json, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException("fwrite failed writing result file: {$path}");
            }
            $written += $n;
        }
        if (!@fflush($fp)) {
            throw new RuntimeException("fflush failed writing result file: {$path}");
        }
    } catch (\Throwable $t) {
        $primaryEx = $t;
        throw $t;
    } finally {
        $closeOk = false;
        $closeEx = null;
        try {
            $closeOk = @fclose($fp);
        } catch (\Throwable $fe) {
            $closeEx = $fe;
        }
        if ($closeOk !== true || $closeEx !== null) {
            $closeMsg = "fclose failed closing result file: {$path}" . ($closeEx !== null ? ": " . $closeEx->getMessage() : "");
            if ($primaryEx !== null) {
                throw new RuntimeException($closeMsg . "; primary error: " . $primaryEx->getMessage(), 0, $primaryEx);
            }
            throw new RuntimeException($closeMsg, 0, $closeEx);
        }
    }
}

/**
 * Write READY marker via exclusive creation.
 *
 * @param string|null $path
 */
function writeReadyMarker(?string $path): void
{
    if ($path === null || trim($path) === '') {
        return;
    }
    $fp = @fopen($path, 'xb');
    if ($fp === false) {
        throw new RuntimeException("Failed to open ready file for exclusive write: {$path}");
    }
    $primaryEx = null;
    try {
        $bytes = "READY\n";
        $len = strlen($bytes);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($fp, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException("fwrite failed writing ready marker to: {$path}");
            }
            $written += $n;
        }
        if (!@fflush($fp)) {
            throw new RuntimeException("fflush failed writing ready marker to: {$path}");
        }
    } catch (\Throwable $t) {
        $primaryEx = $t;
        throw $t;
    } finally {
        $closeOk = false;
        $closeEx = null;
        try {
            $closeOk = @fclose($fp);
        } catch (\Throwable $fe) {
            $closeEx = $fe;
        }
        if ($closeOk !== true || $closeEx !== null) {
            $closeMsg = "Failed to close ready file: {$path}" . ($closeEx !== null ? ": " . $closeEx->getMessage() : "");
            if ($primaryEx !== null) {
                throw new RuntimeException($closeMsg . "; primary error: " . $primaryEx->getMessage(), 0, $primaryEx);
            }
            throw new RuntimeException($closeMsg, 0, $closeEx);
        }
    }
}

/**
 * Await hold-gate file release signal from native parent before closing.
 *
 * @param string|null $gatePath
 */
function awaitHoldGate(?string $gatePath): void
{
    if ($gatePath === null || trim($gatePath) === '') {
        return;
    }
    $start = hrtime(true);
    while (!file_exists($gatePath)) {
        if ((hrtime(true) - $start) > 25000000000) {
            throw new RuntimeException("Timed out waiting for hold gate release signal: {$gatePath}");
        }
        usleep(10000); // 10ms
    }
}

$primaryError = null;
$transport = null;
$pendingResult = null;

try {
    // Mode: STALL (Suite F blocking governance)
    if ($mode === 'stall') {
        $transport = ShellDeltaWorkerPipeTransportService::connect($endpoint);
        writeReadyMarker($readyFile);
        // Sleep to let native host exercise ReadFrame timeout and job termination
        sleep(30);
    } elseif ($mode === 'expect_fail') {
        // Mode: EXPECT_FAIL (Suite E decoder fail-close tests)
        $transport = ShellDeltaWorkerPipeTransportService::connect($endpoint);
        writeReadyMarker($readyFile);

        $caughtError = null;
        $readSucceeded = false;
        try {
            $frame = $transport->readFrame();
            $readSucceeded = true;
        } catch (Throwable $pe) {
            $caughtError = $pe->getMessage();
        }

        if ($readSucceeded) {
            throw new RuntimeException("readFrame succeeded unexpectedly on invalid frame.");
        }

        $pendingResult = [
            'mode' => 'expect_fail',
            'ok' => false,
            'can_apply' => false,
            'error' => $caughtError,
            'is_faulted' => $transport->isFaulted(),
        ];

        awaitHoldGate($holdGateFile);
    } elseif ($mode === 'echo') {
        // Mode: ECHO (Suite A & Suite B roundtrips)
        $transport = ShellDeltaWorkerPipeTransportService::connect($endpoint);
        writeReadyMarker($readyFile);

        $echoCount = 0;
        for ($f = 0; $f < $expectedFrames; $f++) {
            $frame = $transport->readFrame();
            $echoCount++;
            $transport->writeFrame($frame['payload']);
        }

        $pendingResult = [
            'mode' => 'echo',
            'ok' => true,
            'can_apply' => false,
            'echo_count' => $echoCount,
        ];

        // Keep root process alive until native parent completes ReadFrame and releases hold gate
        awaitHoldGate($holdGateFile);
    } elseif ($mode === 'plan') {
        // Mode: PLAN (Suite C positive signed plans & Suite D full validator negatives)
        if (!is_string($fixturePath) || !is_file($fixturePath)) {
            throw new RuntimeException("--fixture <path> is required in plan mode.");
        }

        $fixtureRaw = file_get_contents($fixturePath);
        if ($fixtureRaw === false) {
            throw new RuntimeException("Failed to read fixture file: {$fixturePath}");
        }

        $fixtureDoc = json_decode($fixtureRaw, true, 512, JSON_THROW_ON_ERROR);
        $pubKeyInfo = $fixtureDoc['public_key'] ?? null;
        if (!is_array($pubKeyInfo) || !isset($pubKeyInfo['modulus_base64'], $pubKeyInfo['exponent_base64'])) {
            throw new RuntimeException("Fixture missing valid public_key modulus/exponent.");
        }

        $rawModulus = base64_decode((string) $pubKeyInfo['modulus_base64'], true);
        $rawExponent = base64_decode((string) $pubKeyInfo['exponent_base64'], true);
        if ($rawModulus === false || $rawExponent === false) {
            throw new RuntimeException("Base64 decode failed for RSA key components.");
        }
        if (base64_encode($rawModulus) !== $pubKeyInfo['modulus_base64'] || base64_encode($rawExponent) !== $pubKeyInfo['exponent_base64']) {
            throw new RuntimeException("RSA modulus or exponent is not canonical base64.");
        }
        if ($rawModulus === '' || strlen($rawModulus) !== 256) {
            throw new RuntimeException("RSA modulus length must be exactly 256 bytes for 2048-bit key.");
        }
        if ($rawExponent === '' || strlen($rawExponent) < 1 || strlen($rawExponent) > 4) {
            throw new RuntimeException("RSA exponent length must be between 1 and 4 bytes.");
        }

        // Reconstruct X.509 SubjectPublicKeyInfo DER in-memory (trusted fixture only, never wire)
        $derLen = static function (int $len): string {
            if ($len < 128) {
                return chr($len);
            }
            if ($len < 256) {
                return chr(0x81) . chr($len);
            }
            return chr(0x82) . chr($len >> 8) . chr($len & 0xFF);
        };
        $derTag = static function (int $tag, string $val) use ($derLen): string {
            return chr($tag) . $derLen(strlen($val)) . $val;
        };
        $derInt = static function (string $bytes) use ($derTag): string {
            if ((ord($bytes[0]) & 0x80) !== 0) {
                $bytes = "\x00" . $bytes;
            }
            return $derTag(0x02, $bytes);
        };

        $rsaPub = $derTag(0x30, $derInt($rawModulus) . $derInt($rawExponent));
        $algId = $derTag(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $bitString = $derTag(0x03, "\x00" . $rsaPub);
        $spki = $derTag(0x30, $algId . $bitString);

        $fixturePublicPem = "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split(base64_encode($spki), 64, "\n") .
            "-----END PUBLIC KEY-----\n";

        // Pure service construction: no ambient keys, no automatic app bootstrap
        $signatureService = new ManifestSignatureService(null, [$fixturePublicPem], false, false);
        $manifestService = new ShellDeltaManifestService($signatureService);
        $filePlanService = new ShellDeltaWorkerFilePlanService($manifestService);

        $transport = ShellDeltaWorkerPipeTransportService::connect($endpoint);
        writeReadyMarker($readyFile);

        // Read single framed request JSON from native host
        $requestFrame = $transport->readFrame();
        $requestData = json_decode($requestFrame['payload'], true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($requestData)) {
            throw new RuntimeException("Request payload must be a JSON object.");
        }

        // Strict validation: EXACT 5 request keys, no more, no less
        $expectedReqKeys = ['expected_manifest_sha256', 'raw_manifest_base64', 'signature', 'source_context', 'target_context'];
        $actualReqKeys = array_keys($requestData);
        sort($actualReqKeys);
        if ($expectedReqKeys !== $actualReqKeys) {
            throw new RuntimeException("Request payload must contain exactly the 5 required keys.");
        }

        $rawManifestB64 = (string) $requestData['raw_manifest_base64'];
        $signature = (string) $requestData['signature'];
        $expectedSha = (string) $requestData['expected_manifest_sha256'];
        $sourceCtx = $requestData['source_context'];
        $targetCtx = $requestData['target_context'];

        if (!is_array($sourceCtx) || !is_array($targetCtx)) {
            throw new RuntimeException("source_context and target_context must be arrays.");
        }

        // Strict validation: EXACT 6 context keys
        $expectedCtxKeys = ['channel', 'data_compatibility', 'inventory_sha256', 'native_shell_sha256', 'native_version', 'version'];
        $actualSrcKeys = array_keys($sourceCtx);
        $actualTgtKeys = array_keys($targetCtx);
        sort($actualSrcKeys);
        sort($actualTgtKeys);
        if ($actualSrcKeys !== $expectedCtxKeys || $actualTgtKeys !== $expectedCtxKeys) {
            throw new RuntimeException("Contexts must contain exactly the 6 required keys (no app_version alias).");
        }

        // Strict validation: EXACT 4 data_compatibility keys
        $expectedDcKeys = ['format', 'ledger_sha256', 'metadata_migration', 'schema_sha256'];
        $actualSrcDc = array_keys($sourceCtx['data_compatibility']);
        $actualTgtDc = array_keys($targetCtx['data_compatibility']);
        sort($actualSrcDc);
        sort($actualTgtDc);
        if ($actualSrcDc !== $expectedDcKeys || $actualTgtDc !== $expectedDcKeys) {
            throw new RuntimeException("data_compatibility must contain exactly the 4 required keys.");
        }

        if ($sourceCtx['data_compatibility']['format'] !== 1 || $targetCtx['data_compatibility']['format'] !== 1) {
            throw new RuntimeException("data_compatibility format must be 1.");
        }
        if ($sourceCtx['data_compatibility']['metadata_migration'] !== UpdateDataCompatibilityService::METADATA_MIGRATION ||
            $targetCtx['data_compatibility']['metadata_migration'] !== UpdateDataCompatibilityService::METADATA_MIGRATION) {
            throw new RuntimeException("data_compatibility metadata_migration mismatch.");
        }

        // Canonical base64 verification of raw manifest
        $rawManifest = base64_decode($rawManifestB64, true);
        if ($rawManifest === false || base64_encode($rawManifest) !== $rawManifestB64) {
            throw new RuntimeException("raw_manifest_base64 is not canonical base64.");
        }
        if (strlen($rawManifest) < 1 || strlen($rawManifest) > 67108864) {
            throw new RuntimeException("raw_manifest size out of bounds.");
        }

        $replyPayload = null;
        $resultRecord = null;
        try {
            $planResult = $filePlanService->build(
                $rawManifest,
                $signature,
                $expectedSha,
                $sourceCtx,
                $targetCtx
            );

            $replyPayload = [
                'ok' => true,
                'can_apply' => false,
                'manifest_sha256' => hash('sha256', $rawManifest),
                'plan_json' => $planResult['plan_json'],
                'plan_sha256' => $planResult['plan_sha256'],
            ];

            $pendingResult = [
                'mode' => 'plan',
                'ok' => true,
                'can_apply' => false,
                'manifest_sha256' => hash('sha256', $rawManifest),
                'plan_sha256' => $planResult['plan_sha256'],
            ];
        } catch (Throwable $planEx) {
            $replyPayload = [
                'ok' => false,
                'can_apply' => false,
                'error' => $planEx->getMessage(),
            ];

            $pendingResult = [
                'mode' => 'plan',
                'ok' => false,
                'can_apply' => false,
                'error' => $planEx->getMessage(),
            ];
        }

        $replyJson = json_encode($replyPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $transport->writeFrame($replyJson);

        // Keep root process alive until native parent completes ReadFrame and releases hold gate
        awaitHoldGate($holdGateFile);
    } else {
        throw new RuntimeException("Unknown mode '{$mode}'.");
    }

} catch (Throwable $fatal) {
    $primaryError = $fatal;
    fwrite(STDERR, "FATAL: " . $fatal->getMessage() . "\n" . $fatal->getTraceAsString() . "\n");
} finally {
    $cleanupOk = true;
    $cleanupError = null;

    if ($transport !== null && !$transport->isClosed()) {
        try {
            $transport->close();
        } catch (Throwable $closeEx) {
            $cleanupOk = false;
            $cleanupError = $closeEx->getMessage();
            fwrite(STDERR, "CLEANUP ERROR in fixture transport close: " . $closeEx->getMessage() . "\n");
            if ($primaryError === null) {
                $primaryError = $closeEx;
            } else {
                $primaryError = new RuntimeException($primaryError->getMessage() . "; close error: " . $closeEx->getMessage(), 0, $primaryError);
            }
        }
    }

    if ($pendingResult !== null) {
        $pendingResult['cleanup_ok'] = $cleanupOk;
        if (!$cleanupOk) {
            $pendingResult['cleanup_error'] = $cleanupError;
        }
        try {
            recordResultFile($resultFile, $pendingResult);
        } catch (Throwable $recEx) {
            fwrite(STDERR, "CLEANUP ERROR recording result file: " . $recEx->getMessage() . "\n");
            if ($primaryError === null) {
                $primaryError = $recEx;
            } else {
                $primaryError = new RuntimeException($primaryError->getMessage() . "; recordResultFile error: " . $recEx->getMessage(), 0, $primaryError);
            }
        }
    }

    if ($primaryError !== null) {
        exit(1);
    }
}
