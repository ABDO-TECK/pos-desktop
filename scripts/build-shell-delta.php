<?php
declare(strict_types=1);

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

use App\Services\{
    ManifestSignatureService,
    ProductInventoryService,
    ShellDeltaManifestService,
    UpdateChannelPolicy,
    UpdateDataCompatibilityService
};

/**
 * Shell Delta Builder CLI
 *
 * Builds a Format 2 signed native-shell delta package and manifest.
 */
function printUsage(): void
{
    echo <<<USAGE
Usage: php scripts/build-shell-delta.php [options]

Required Options:
  --source-root <path>            Source Windows installation root directory
  --target-root <path>            Target Windows installation root directory
  --source-native-version <ver>   Source native shell SemVer (e.g. 0.0.13)
  --target-native-version <ver>   Target native shell SemVer (e.g. 0.0.14)
  --source-data-contract <path|json> Source UpdateDataCompatibilityService profile JSON
  --target-data-contract <path|json> Target UpdateDataCompatibilityService profile JSON
  --private-key <path>            Path to RSA private key PEM for manifest signing
  --output-dir <path>             Destination directory for output assets

Optional Options:
  --purpose <name>                'regular' (default) or 'bootstrap_completion'
  --bootstrap-link <sha256>       64-hex SHA-256 link digest (required for bootstrap_completion)
  -h, --help                      Show this help message

Outputs:
  shell-manifest.json
  shell-manifest.sig
  shell-delta.zip
  source-inventory.json
  target-inventory.json

USAGE;
}

// -------------------------------------------------------------
// 1. Strict CLI Argument Preflight and Parsing
// -------------------------------------------------------------
$allowedLongWithValue = [
    'source-root',
    'target-root',
    'source-native-version',
    'target-native-version',
    'source-data-contract',
    'target-data-contract',
    'private-key',
    'output-dir',
    'purpose',
    'bootstrap-link',
];

$seenFlags = [];
$argc = count($argv);
$i = 1;
$wantsHelp = false;

while ($i < $argc) {
    $arg = $argv[$i];
    if ($arg === '-h' || $arg === '--help') {
        if (isset($seenFlags['help'])) {
            fwrite(STDERR, "Error: Duplicate argument '{$arg}' provided.\n");
            exit(1);
        }
        $seenFlags['help'] = true;
        $wantsHelp = true;
        $i++;
        continue;
    }

    if (str_starts_with($arg, '--')) {
        $eqPos = strpos($arg, '=');
        if ($eqPos !== false) {
            $flagName = substr($arg, 2, $eqPos - 2);
            $flagVal = substr($arg, $eqPos + 1);
            if (!in_array($flagName, $allowedLongWithValue, true)) {
                fwrite(STDERR, "Error: Unknown argument '--{$flagName}'.\n");
                exit(1);
            }
            if (isset($seenFlags[$flagName])) {
                fwrite(STDERR, "Error: Duplicate argument '--{$flagName}' provided.\n");
                exit(1);
            }
            if ($flagVal === '') {
                fwrite(STDERR, "Error: Option '--{$flagName}' requires a non-empty value.\n");
                exit(1);
            }
            $seenFlags[$flagName] = true;
            $i++;
            continue;
        }

        $flagName = substr($arg, 2);
        if (!in_array($flagName, $allowedLongWithValue, true)) {
            fwrite(STDERR, "Error: Unknown argument '--{$flagName}'.\n");
            exit(1);
        }
        if (isset($seenFlags[$flagName])) {
            fwrite(STDERR, "Error: Duplicate argument '--{$flagName}' provided.\n");
            exit(1);
        }
        if ($i + 1 >= $argc || $argv[$i + 1] === '' || (str_starts_with($argv[$i + 1], '-') && !str_starts_with($argv[$i + 1], '{'))) {
            fwrite(STDERR, "Error: Option '--{$flagName}' requires a value.\n");
            exit(1);
        }
        $seenFlags[$flagName] = true;
        $i += 2;
        continue;
    }

    // Positional or invalid trailing tokens
    fwrite(STDERR, "Error: Unexpected or invalid argument '{$arg}'.\n");
    exit(1);
}

if ($wantsHelp) {
    printUsage();
    exit(0);
}

// Required getopts configuration (purpose: and bootstrap-link: are required-value options)
$longopts = [
    'source-root:',
    'target-root:',
    'source-native-version:',
    'target-native-version:',
    'source-data-contract:',
    'target-data-contract:',
    'private-key:',
    'output-dir:',
    'purpose:',
    'bootstrap-link:',
    'help',
];

$restIndex = 1;
$options = getopt('h', $longopts, $restIndex);

foreach ($options as $k => $val) {
    if (is_array($val)) {
        fwrite(STDERR, "Error: Duplicate argument '--{$k}' provided.\n");
        exit(1);
    }
}

$requiredKeys = [
    'source-root',
    'target-root',
    'source-native-version',
    'target-native-version',
    'source-data-contract',
    'target-data-contract',
    'private-key',
    'output-dir',
];

foreach ($requiredKeys as $k) {
    if (!isset($options[$k]) || !is_string($options[$k]) || trim($options[$k]) === '') {
        fwrite(STDERR, "Error: Missing required option --{$k}\n\n");
        printUsage();
        exit(1);
    }
}

$purpose = isset($options['purpose']) ? trim((string)$options['purpose']) : 'regular';
if (!in_array($purpose, ['regular', 'bootstrap_completion'], true)) {
    fwrite(STDERR, "Error: Invalid purpose: '{$purpose}'. Must be 'regular' or 'bootstrap_completion'.\n");
    exit(1);
}

if ($purpose === 'regular' && isset($options['bootstrap-link'])) {
    fwrite(STDERR, "Error: Option --bootstrap-link is unexpected and rejected for purpose 'regular'.\n");
    exit(1);
}

$bootstrapLink = null;
if ($purpose === 'bootstrap_completion') {
    if (!isset($options['bootstrap-link']) || trim((string)$options['bootstrap-link']) === '') {
        fwrite(STDERR, "Error: Option --bootstrap-link with 64-hex SHA-256 is required when --purpose is 'bootstrap_completion'.\n");
        exit(1);
    }
    $bootstrapLink = strtolower(trim((string)$options['bootstrap-link']));
    if (preg_match('/^[a-f0-9]{64}$/D', $bootstrapLink) !== 1) {
        fwrite(STDERR, "Error: Invalid --bootstrap-link format. Must be 64-hex SHA-256.\n");
        exit(1);
    }
}

// -------------------------------------------------------------
// 2. Safe Path Resolution & Helper Utilities
// -------------------------------------------------------------
$toAbsolute = static function (string $p): string {
    $norm = str_replace('/', '\\', trim($p));
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $norm) === 1) {
        return $norm;
    }
    if (str_starts_with($norm, '\\\\') || str_starts_with($norm, '//')) {
        return $p;
    }
    $cwd = getcwd();
    if ($cwd === false) {
        throw new RuntimeException("Cannot determine current working directory.");
    }
    return rtrim(str_replace('/', '\\', $cwd), '\\') . '\\' . ltrim($norm, '\\');
};

$readBoundedFile = static function (string $path, int $maxBytes, string $label): string {
    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("Cannot open {$label} for reading: '{$path}'.");
    }
    try {
        $content = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) {
                throw new RuntimeException("Error reading {$label}: '{$path}'.");
            }
            if ($chunk === '') {
                break;
            }
            $content .= $chunk;
            if (strlen($content) > $maxBytes) {
                throw new RuntimeException("{$label} exceeds maximum allowed size of {$maxBytes} bytes: '{$path}'.");
            }
        }
        if (strlen($content) === 0) {
            throw new RuntimeException("{$label} is empty: '{$path}'.");
        }
        return $content;
    } finally {
        @fclose($fp);
    }
};

$writeChecked = static function (
    string $filePath,
    string $content,
    ?callable $onExclusiveOpen = null,
    ?string $testFaultHook = null
): void {
    $parentDir = dirname($filePath);
    $canonicalParent = ProductInventoryService::resolveAbsolutePath($parentDir, true);
    ProductInventoryService::assertNoReparse($canonicalParent);

    $fp = @fopen($filePath, 'xb');
    if ($fp === false) {
        throw new RuntimeException("Cannot create file exclusively: '{$filePath}'.");
    }
    if ($onExclusiveOpen !== null) {
        $onExclusiveOpen($filePath);
    }
    $closed = false;
    try {
        if ($testFaultHook !== null && getenv('GITHUB_ACTIONS') === 'true' && getenv('SHELL_DELTA_BUILDER_TEST_FAULT') === $testFaultHook) {
            $partial = substr($content, 0, min(16, strlen($content)));
            fwrite($fp, $partial);
            fflush($fp);
            throw new RuntimeException("Synthetic fault simulation triggered: {$testFaultHook} (partial write)");
        }

        $total = strlen($content);
        $written = 0;
        while ($written < $total) {
            $chunk = fwrite($fp, substr($content, $written));
            if ($chunk === false || $chunk <= 0) {
                throw new RuntimeException("Write failed or stalled on '{$filePath}'.");
            }
            $written += $chunk;
        }
        if (!fflush($fp)) {
            throw new RuntimeException("Failed to flush buffer for '{$filePath}'.");
        }
    } finally {
        if (!$closed) {
            $closeOk = @fclose($fp);
            $closed = true;
            if (!$closeOk) {
                throw new RuntimeException("Failed to close file handle for '{$filePath}'.");
            }
        }
    }
};

// Lifecycle tracking state
$stageDir = null;
$stageName = null;
$stageCreated = false;
$stageMarkerPath = null;
$stageMarkerCreated = false;
$stageToken = null;
$stageRenamed = false;

$leaseFilePath = null;
$leaseCreated = false;
$leaseToken = null;
$leaseFp = null;

$zip = null;
$zipOpen = false;
$canonicalParentOutput = null;
$canonicalOutput = null;

$cleanupStageAndLease = static function (
    ?string $stageDir,
    ?string $stageName,
    bool $stageCreated,
    ?string $stageMarkerPath,
    bool $stageMarkerCreated,
    ?string $stageToken,
    bool $stageRenamed,
    ?string $leaseFilePath,
    bool $leaseCreated,
    ?string $leaseToken,
    mixed $leaseFp,
    ?ZipArchive $zip,
    bool $zipOpen,
    ?string $canonicalParentOutput,
    ?string $canonicalOutput,
    callable $readBounded
): array {
    $diagnostics = [];

    // Ensure zip is closed before deleting stage files (idempotent, catch all exceptions)
    if ($zipOpen && $zip instanceof ZipArchive) {
        $zipOpen = false;
        try {
            @$zip->close();
        } catch (Throwable $ze) {
            $diagnostics[] = "Zip close during cleanup: " . $ze->getMessage();
        }
        $zip = null;
    }

    // Ensure lease file handle is closed (idempotent, catch all exceptions)
    if ($leaseFp !== null && is_resource($leaseFp)) {
        try {
            @fclose($leaseFp);
        } catch (Throwable $fe) {
            $diagnostics[] = "Lease handle close during cleanup: " . $fe->getMessage();
        }
        $leaseFp = null;
    }

    // Clean up stage directory ONLY if stage was created by us and not yet renamed
    $stageDirRemoved = false;
    $stageProofVerified = false;

    if (!$stageRenamed && $stageCreated && $stageDir !== null && $canonicalParentOutput !== null) {
        try {
            $parentCheck = ProductInventoryService::resolveAbsolutePath($canonicalParentOutput, true);
            ProductInventoryService::assertNoReparse($parentCheck);

            // Full proof requirements whenever stageCreated is true:
            // 1. Lexical canonical parent identity and stage path identity
            if ($stageName === null || $stageToken === null || $canonicalOutput === null || $stageMarkerPath === null) {
                throw new RuntimeException("Stage ownership parameters incomplete.");
            }

            if (strcasecmp(dirname($stageDir), $canonicalParentOutput) !== 0) {
                throw new RuntimeException("Stage directory parent identity mismatch.");
            }
            if (basename($stageDir) !== $stageName) {
                throw new RuntimeException("Stage directory name mismatch.");
            }

            if (!is_dir($stageDir) || is_link($stageDir)) {
                throw new RuntimeException("Stage path is not a regular directory.");
            }

            $canonicalStage = ProductInventoryService::resolveAbsolutePath($stageDir, true);
            ProductInventoryService::assertNoReparse($canonicalStage);

            if (strcasecmp(dirname($canonicalStage), $canonicalParentOutput) !== 0) {
                throw new RuntimeException("Canonical stage directory parent identity mismatch.");
            }
            if (basename($canonicalStage) !== $stageName) {
                throw new RuntimeException("Canonical stage directory name mismatch.");
            }
            if (strcasecmp($canonicalStage, $canonicalOutput) === 0) {
                throw new RuntimeException("Stage directory identity matches canonical output directory.");
            }

            // 2. EXTERNAL sibling marker validation
            if (!file_exists($stageMarkerPath) || !is_file($stageMarkerPath) || is_link($stageMarkerPath)) {
                throw new RuntimeException("Stage owner marker missing, not a regular file, or is a link.");
            }

            $canonicalMarker = ProductInventoryService::resolveAbsolutePath($stageMarkerPath, true);
            ProductInventoryService::assertNoReparse($canonicalMarker);

            if (strcasecmp(dirname($canonicalMarker), $canonicalParentOutput) !== 0) {
                throw new RuntimeException("Stage owner marker parent identity mismatch.");
            }
            if (basename($canonicalMarker) !== ('.' . $stageName . '.owner')) {
                throw new RuntimeException("Stage owner marker file name mismatch.");
            }

            // 3. Exact expected marker payload = actual UNIQUE stageToken + '\n' + actual stageName + '\n' + basename(canonicalOutput) + '\n'
            $expectedMarkerPayload = $stageToken . "\n" . $stageName . "\n" . basename($canonicalOutput) . "\n";
            $markerRaw = $readBounded($stageMarkerPath, 4096, 'Stage owner marker');

            if ($markerRaw !== $expectedMarkerPayload) {
                throw new RuntimeException("Stage owner marker content mismatch with expected ownership payload.");
            }

            // FULL PROOF VERIFIED: we own the stage directory and its marker
            $stageProofVerified = true;

            // Delete stage contents (files and links)
            $entries = @scandir($stageDir);
            if ($entries !== false) {
                foreach ($entries as $e) {
                    if ($e === '.' || $e === '..') {
                        continue;
                    }
                    $itemPath = $stageDir . DIRECTORY_SEPARATOR . $e;
                    $lstat = @lstat($itemPath);
                    if ($lstat !== false && (is_link($itemPath) || (($lstat['mode'] & 0120000) === 0120000))) {
                        if (!@unlink($itemPath)) {
                            $diagnostics[] = "Failed to unlink link '{$itemPath}'";
                        }
                    } elseif (is_file($itemPath)) {
                        if (!@unlink($itemPath)) {
                            $diagnostics[] = "Failed to unlink file '{$itemPath}'";
                        }
                    }
                }
            }
            if (!@rmdir($stageDir)) {
                $diagnostics[] = "Failed to rmdir stage directory '{$stageDir}'";
            }
            $stageDirRemoved = !is_dir($stageDir);
        } catch (Throwable $e) {
            $diagnostics[] = "Stage ownership proof failed or cleanup error: " . $e->getMessage();
        }

        if (!$stageProofVerified) {
            $diagnostics[] = "Stage ownership proof could not be verified; retaining stage directory '{$stageDir}' and marker untouched. NEEDS ATTENTION: manual cleanup required.";
            fwrite(STDERR, "NEEDS ATTENTION: Stage directory '{$stageDir}' retained because owner proof could not be verified.\n");
        } elseif (!$stageDirRemoved && is_dir($stageDir)) {
            $diagnostics[] = "Stage directory '{$stageDir}' could not be removed; retaining owner marker for recovery. NEEDS ATTENTION: manual cleanup required.";
            fwrite(STDERR, "NEEDS ATTENTION: Stage directory '{$stageDir}' could not be removed.\n");
        }
    }

    // Clean up stage marker
    if (!$stageRenamed && $stageMarkerCreated && $stageMarkerPath !== null && $canonicalParentOutput !== null) {
        if ($stageCreated) {
            // Stage was created: marker cleanup ONLY allowed if stage was fully verified AND successfully removed
            if ($stageProofVerified && $stageDirRemoved) {
                try {
                    if (file_exists($stageMarkerPath) && !is_link($stageMarkerPath)) {
                        $canonicalMarker = ProductInventoryService::resolveAbsolutePath($stageMarkerPath, true);
                        ProductInventoryService::assertNoReparse($canonicalMarker);
                        if (!@unlink($stageMarkerPath)) {
                            $diagnostics[] = "Failed to unlink stage marker '{$stageMarkerPath}'. NEEDS ATTENTION: manual cleanup required.";
                        }
                    }
                } catch (Throwable $e) {
                    $diagnostics[] = "Exception during marker cleanup: " . $e->getMessage();
                }
            } else {
                $diagnostics[] = "Retaining stage marker '{$stageMarkerPath}' because stage directory was not removed or proof unverified.";
            }
        } else {
            // Stage was NEVER created: partial or full marker written before mkdir failed.
            // May ONLY clean up if exclusive created flag is true AND actual nonempty bytes
            // are a PREFIX of THIS operation's EXACT expected payload (str_starts_with(expected, actual)).
            try {
                if (file_exists($stageMarkerPath) && !is_link($stageMarkerPath)) {
                    $canonicalMarker = ProductInventoryService::resolveAbsolutePath($stageMarkerPath, true);
                    ProductInventoryService::assertNoReparse($canonicalMarker);
                    if (strcasecmp(dirname($canonicalMarker), $canonicalParentOutput) !== 0) {
                        throw new RuntimeException("Stage marker parent mismatch.");
                    }

                    $markerRaw = $readBounded($stageMarkerPath, 4096, 'Stage owner marker');
                    $expectedMarkerPayload = ($stageToken !== null && $stageName !== null && $canonicalOutput !== null)
                        ? ($stageToken . "\n" . $stageName . "\n" . basename($canonicalOutput) . "\n")
                        : null;

                    if ($expectedMarkerPayload !== null && $markerRaw !== '' && str_starts_with($expectedMarkerPayload, $markerRaw)) {
                        if (!@unlink($stageMarkerPath)) {
                            $diagnostics[] = "Failed to unlink stage marker '{$stageMarkerPath}'. NEEDS ATTENTION: manual cleanup required.";
                        }
                    } else {
                        $diagnostics[] = "Stage marker content does not match prefix of this operation's expected payload; marker left untouched. NEEDS ATTENTION: manual cleanup required.";
                        fwrite(STDERR, "NEEDS ATTENTION: Stage marker '{$stageMarkerPath}' retained due to ownership mismatch.\n");
                    }
                }
            } catch (Throwable $e) {
                $diagnostics[] = "Exception during partial marker cleanup: " . $e->getMessage();
            }
        }
    }

    // Clean up publication lease ONLY if created by us
    if ($leaseCreated && $leaseFilePath !== null && $canonicalParentOutput !== null) {
        try {
            if (file_exists($leaseFilePath) && !is_link($leaseFilePath)) {
                $canonicalLease = ProductInventoryService::resolveAbsolutePath($leaseFilePath, true);
                ProductInventoryService::assertNoReparse($canonicalLease);
                if (strcasecmp(dirname($canonicalLease), $canonicalParentOutput) !== 0) {
                    throw new RuntimeException("Publication lease parent mismatch.");
                }

                $leaseRaw = $readBounded($leaseFilePath, 4096, 'Publication lease');
                $expectedLeasePayload = ($leaseToken !== null) ? ($leaseToken . "\n") : null;

                // Must be nonempty and a prefix of THIS operation's exact lease token + newline
                if ($expectedLeasePayload !== null && $leaseRaw !== '' && str_starts_with($expectedLeasePayload, $leaseRaw)) {
                    if (!@unlink($leaseFilePath)) {
                        $diagnostics[] = "Failed to unlink publication lease '{$leaseFilePath}'. NEEDS ATTENTION: manual cleanup required.";
                    }
                } else {
                    $diagnostics[] = "Publication lease content does not match prefix of this operation's lease token; foreign/unverified lease left untouched. NEEDS ATTENTION: manual cleanup required.";
                    fwrite(STDERR, "NEEDS ATTENTION: Publication lease '{$leaseFilePath}' retained due to token mismatch.\n");
                }
            }
        } catch (Throwable $e) {
            $diagnostics[] = "Exception during lease cleanup: " . $e->getMessage();
        }
    }

    foreach ($diagnostics as $diag) {
        fwrite(STDERR, "Cleanup diagnostic: {$diag}\n");
    }

    return $diagnostics;
};

try {
    $sourceRootRaw = trim($options['source-root']);
    $targetRootRaw = trim($options['target-root']);
    $outputDirRaw = trim($options['output-dir']);
    $sourceNativeVersion = trim($options['source-native-version']);
    $targetNativeVersion = trim($options['target-native-version']);
    $privateKeyRaw = trim($options['private-key']);

    // 2.1 Resolve and validate source and target roots
    $canonicalSource = ProductInventoryService::resolveAbsolutePath($toAbsolute($sourceRootRaw), true);
    ProductInventoryService::assertNoReparse($canonicalSource);
    if (!is_dir($canonicalSource)) {
        throw new RuntimeException("Source root does not exist or is not a directory: {$canonicalSource}");
    }

    $canonicalTarget = ProductInventoryService::resolveAbsolutePath($toAbsolute($targetRootRaw), true);
    ProductInventoryService::assertNoReparse($canonicalTarget);
    if (!is_dir($canonicalTarget)) {
        throw new RuntimeException("Target root does not exist or is not a directory: {$canonicalTarget}");
    }

    // 2.2 Resolve and validate output directory: must be ABSENT and parent an EXISTING directory
    $canonicalOutput = ProductInventoryService::resolveAbsolutePath($toAbsolute($outputDirRaw), false);
    if (file_exists($canonicalOutput) || is_dir($canonicalOutput) || is_link($canonicalOutput)) {
        throw new RuntimeException("Output directory already exists: '{$canonicalOutput}'. Existing output is forbidden; no overwrite or deletion permitted.");
    }

    $parentOutput = dirname($canonicalOutput);
    $canonicalParentOutput = ProductInventoryService::resolveAbsolutePath($parentOutput, true);
    ProductInventoryService::assertNoReparse($canonicalParentOutput);
    if (!is_dir($canonicalParentOutput)) {
        throw new RuntimeException("Output parent is not a directory: '{$canonicalParentOutput}'.");
    }
    if (strcasecmp(dirname($canonicalOutput), $canonicalParentOutput) !== 0) {
        throw new RuntimeException("Output directory parent identity mismatch.");
    }

    // 2.3 Case-insensitive ancestor / equality overlap checks in BOTH directions
    $sNorm = strtolower(rtrim(str_replace('/', '\\', $canonicalSource), '\\')) . '\\';
    $tNorm = strtolower(rtrim(str_replace('/', '\\', $canonicalTarget), '\\')) . '\\';
    $oNorm = strtolower(rtrim(str_replace('/', '\\', $canonicalOutput), '\\')) . '\\';

    if ($sNorm === $tNorm) {
        throw new RuntimeException("Source root and target root must be distinct directories.");
    }
    if ($sNorm === $oNorm) {
        throw new RuntimeException("Source root and output directory must be distinct.");
    }
    if ($tNorm === $oNorm) {
        throw new RuntimeException("Target root and output directory must be distinct.");
    }

    if (str_starts_with($tNorm, $sNorm) || str_starts_with($sNorm, $tNorm)) {
        throw new RuntimeException("Source root and target root must not contain or overlap one another.");
    }
    if (str_starts_with($oNorm, $sNorm)) {
        throw new RuntimeException("Output directory must not be inside source root.");
    }
    if (str_starts_with($sNorm, $oNorm)) {
        throw new RuntimeException("Output directory must not be an ancestor of source root.");
    }
    if (str_starts_with($oNorm, $tNorm)) {
        throw new RuntimeException("Output directory must not be inside target root.");
    }
    if (str_starts_with($tNorm, $oNorm)) {
        throw new RuntimeException("Output directory must not be an ancestor of target root.");
    }

    // 2.4 Private key validation
    if (str_starts_with($privateKeyRaw, '\\\\') || str_starts_with($privateKeyRaw, '//') || preg_match('/[\x00-\x1f\x7f]/', $privateKeyRaw)) {
        throw new RuntimeException("Invalid private key path: UNC or control characters rejected.");
    }
    $canonicalKey = ProductInventoryService::resolveAbsolutePath($toAbsolute($privateKeyRaw), true);
    ProductInventoryService::assertNoReparse($canonicalKey);
    if (!is_file($canonicalKey) || is_link($canonicalKey)) {
        throw new RuntimeException("Private key path is not a regular file: '{$canonicalKey}'.");
    }

    $kNorm = strtolower(str_replace('/', '\\', $canonicalKey));
    if ($kNorm === rtrim($sNorm, '\\') || str_starts_with($kNorm, $sNorm)) {
        throw new RuntimeException("Private key must be outside source root.");
    }
    if ($kNorm === rtrim($tNorm, '\\') || str_starts_with($kNorm, $tNorm)) {
        throw new RuntimeException("Private key must be outside target root.");
    }
    if ($kNorm === rtrim($oNorm, '\\') || str_starts_with($kNorm, $oNorm)) {
        throw new RuntimeException("Private key must be outside output directory.");
    }

    $privKeyContent = $readBoundedFile($canonicalKey, 65536, 'Private key');
    $privKey = openssl_pkey_get_private($privKeyContent);
    if ($privKey === false) {
        throw new RuntimeException("Failed to parse private key PEM.");
    }
    $keyDetails = openssl_pkey_get_details($privKey);
    if (!$keyDetails || ($keyDetails['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA || ($keyDetails['bits'] ?? 0) < 2048) {
        throw new RuntimeException("Private key must be an RSA key of at least 2048 bits.");
    }
    $derivedPublicKeyPem = $keyDetails['key'];

    // 2.5 Data contracts validation
    $parseContract = static function (
        string $rawInput,
        string $label,
        string $sNorm,
        string $tNorm,
        string $oNorm,
        callable $toAbs,
        callable $readBounded
    ): array {
        $trimmed = trim($rawInput);
        if ($trimmed === '') {
            throw new RuntimeException("Empty {$label} provided.");
        }
        $jsonContent = '';
        if (str_starts_with($trimmed, '{')) {
            if (strlen($trimmed) > 65536) {
                throw new RuntimeException("{$label} inline JSON exceeds 64 KiB cap.");
            }
            $jsonContent = $trimmed;
        } else {
            if (str_starts_with($trimmed, '\\\\') || str_starts_with($trimmed, '//') || preg_match('/[\x00-\x1f\x7f]/', $trimmed)) {
                throw new RuntimeException("Invalid {$label} path: UNC or control characters rejected.");
            }
            $canonicalContractPath = ProductInventoryService::resolveAbsolutePath($toAbs($trimmed), true);
            ProductInventoryService::assertNoReparse($canonicalContractPath);
            if (!is_file($canonicalContractPath) || is_link($canonicalContractPath)) {
                throw new RuntimeException("{$label} file is not a regular file: '{$canonicalContractPath}'.");
            }
            $cNorm = strtolower(str_replace('/', '\\', $canonicalContractPath));
            if ($cNorm === rtrim($sNorm, '\\') || str_starts_with($cNorm, $sNorm)) {
                throw new RuntimeException("{$label} file must be outside source root.");
            }
            if ($cNorm === rtrim($tNorm, '\\') || str_starts_with($cNorm, $tNorm)) {
                throw new RuntimeException("{$label} file must be outside target root.");
            }
            if ($cNorm === rtrim($oNorm, '\\') || str_starts_with($cNorm, $oNorm)) {
                throw new RuntimeException("{$label} file must be outside output directory.");
            }
            if (ProductInventoryService::isProtectedPath($cNorm)) {
                throw new RuntimeException("{$label} file must not be a protected path.");
            }
            $jsonContent = $readBounded($canonicalContractPath, 65536, "{$label} file");
        }

        $data = json_decode($jsonContent, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid {$label} JSON profile.");
        }

        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['format', 'ledger_sha256', 'metadata_migration', 'schema_sha256']) {
            throw new RuntimeException("{$label} profile does not satisfy exact format 1 UpdateDataCompatibilityService specification.");
        }

        if ($data['format'] !== 1) {
            throw new RuntimeException("{$label} format must be integer 1.");
        }
        if ($data['metadata_migration'] !== UpdateDataCompatibilityService::METADATA_MIGRATION) {
            throw new RuntimeException("{$label} metadata_migration must be '" . UpdateDataCompatibilityService::METADATA_MIGRATION . "'.");
        }
        if (!is_string($data['schema_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $data['schema_sha256']) !== 1) {
            throw new RuntimeException("{$label} schema_sha256 must be a 64-hex SHA-256 string.");
        }
        if (!is_string($data['ledger_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $data['ledger_sha256']) !== 1) {
            throw new RuntimeException("{$label} ledger_sha256 must be a 64-hex SHA-256 string.");
        }

        return [
            'format' => 1,
            'metadata_migration' => $data['metadata_migration'],
            'schema_sha256' => strtolower($data['schema_sha256']),
            'ledger_sha256' => strtolower($data['ledger_sha256']),
        ];
    };

    $sourceDataContract = $parseContract($options['source-data-contract'], 'source data contract', $sNorm, $tNorm, $oNorm, $toAbsolute, $readBoundedFile);
    $targetDataContract = $parseContract($options['target-data-contract'], 'target data contract', $sNorm, $tNorm, $oNorm, $toAbsolute, $readBoundedFile);

    // 2.6 Validate metadata file caps before inventory build
    foreach (['source' => $canonicalSource, 'target' => $canonicalTarget] as $label => $root) {
        $versionJsonPath = ProductInventoryService::resolveConfinedPath($root, 'resources/app.asar.unpacked/version.json', true);
        $vSize = filesize($versionJsonPath);
        if ($vSize === false || $vSize <= 0 || $vSize > 65536) {
            throw new RuntimeException("{$label} version.json file size exceeds 64 KiB cap.");
        }
    }

    if (!ProductInventoryService::isValidSemver($sourceNativeVersion)) {
        throw new RuntimeException("Invalid source native version SemVer: '{$sourceNativeVersion}'.");
    }
    if (!ProductInventoryService::isValidSemver($targetNativeVersion)) {
        throw new RuntimeException("Invalid target native version SemVer: '{$targetNativeVersion}'.");
    }

    // 2.7 Build full product inventories
    $inventoryService = new ProductInventoryService();
    echo "Building source product inventory from {$canonicalSource}...\n";
    $sourceInventory = $inventoryService->buildInventory($canonicalSource, $sourceNativeVersion);

    echo "Building target product inventory from {$canonicalTarget}...\n";
    $targetInventory = $inventoryService->buildInventory($canonicalTarget, $targetNativeVersion);

    // 2.8 Channel and version progression policy
    $fromChannel = UpdateChannelPolicy::installedChannel($sourceInventory['version']);
    $toChannel = UpdateChannelPolicy::installedChannel($targetInventory['version']);
    $activeChannels = UpdateChannelPolicy::active();

    if (!in_array($fromChannel, $activeChannels, true) || !in_array($toChannel, $activeChannels, true)) {
        throw new RuntimeException("Retired or invalid channel detected (from: {$fromChannel}, to: {$toChannel}).");
    }
    if ($fromChannel !== $toChannel) {
        throw new RuntimeException("Cross-channel operation rejected (from: {$fromChannel}, to: {$toChannel}). Delta engine requires same channel.");
    }

    $isBeta = ($toChannel === 'beta');

    if ($purpose === 'regular') {
        if (version_compare($targetInventory['version'], $sourceInventory['version'], '<=')) {
            throw new RuntimeException("Target app version '{$targetInventory['version']}' must be newer than source '{$sourceInventory['version']}' for regular delta.");
        }
    } elseif ($purpose === 'bootstrap_completion') {
        if (version_compare($targetInventory['version'], $sourceInventory['version'], '<')) {
            throw new RuntimeException("Target app version cannot be older than source app version for bootstrap completion.");
        }
        if ($targetInventory['native_shell']['sha256'] === $sourceInventory['native_shell']['sha256']) {
            throw new RuntimeException("Bootstrap completion requires physical native shell hash change.");
        }
    }

    // 2.9 Calculate exact physical diff
    $sourceFiles = $sourceInventory['files'];
    $targetFiles = $targetInventory['files'];

    $filesToPackage = [];
    $manifestFiles = [];

    foreach ($targetFiles as $path => $entry) {
        if (!isset($sourceFiles[$path]) ||
            $sourceFiles[$path]['sha256'] !== $entry['sha256'] ||
            $sourceFiles[$path]['size'] !== $entry['size']) {
            $filesToPackage[$path] = $entry;
            $manifestFiles[] = [
                'path' => $path,
                'sha256' => $entry['sha256'],
                'size' => $entry['size'],
            ];
        }
    }

    usort($manifestFiles, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

    $deletedFiles = array_values(array_diff(array_keys($sourceFiles), array_keys($targetFiles)));
    sort($deletedFiles);

    if (empty($filesToPackage) && empty($deletedFiles) && $targetInventory['native_shell']['sha256'] === $sourceInventory['native_shell']['sha256']) {
        throw new RuntimeException("No physical artifact changes detected between source and target.");
    }

    if (count($filesToPackage) > ShellDeltaManifestService::MAX_FILES) {
        throw new RuntimeException("Number of changed files exceeds maximum allowed (" . ShellDeltaManifestService::MAX_FILES . ").");
    }
    if (count($deletedFiles) > ShellDeltaManifestService::MAX_FILES) {
        throw new RuntimeException("Number of deleted files exceeds maximum allowed (" . ShellDeltaManifestService::MAX_FILES . ").");
    }

    echo "Detected " . count($filesToPackage) . " changed/added file(s) and " . count($deletedFiles) . " deleted file(s).\n";

    // -------------------------------------------------------------
    // 3. Exclusive Sibling Staging & Publication Lease
    // -------------------------------------------------------------
    $outputBaseName = basename($canonicalOutput);
    $leaseFileName = '.' . $outputBaseName . '.publease';
    $leaseFilePath = $canonicalParentOutput . DIRECTORY_SEPARATOR . $leaseFileName;
    $leaseToken = 'LEASE_TOKEN_' . bin2hex(random_bytes(16));

    $leaseFp = @fopen($leaseFilePath, 'xb');
    if ($leaseFp === false) {
        throw new RuntimeException("Cannot acquire publication lease for output directory. Another process may be publishing: '{$leaseFilePath}'.");
    }
    $leaseCreated = true;

    // GHA Fault injection hook: short_lease_write
    if (getenv('GITHUB_ACTIONS') === 'true' && getenv('SHELL_DELTA_BUILDER_TEST_FAULT') === 'short_lease_write') {
        fwrite($leaseFp, substr($leaseToken, 0, 12));
        fflush($leaseFp);
        throw new RuntimeException("Synthetic fault simulation triggered: short_lease_write (partial lease write)");
    }

    $leaseWritten = fwrite($leaseFp, $leaseToken . "\n");
    if ($leaseWritten === false || $leaseWritten !== strlen($leaseToken . "\n")) {
        throw new RuntimeException("Incomplete write to publication lease file.");
    }
    if (!fflush($leaseFp)) {
        throw new RuntimeException("Failed to flush publication lease file.");
    }

    // Allocate exclusive owned random SIBLING stage directory name
    $stageName = '.stage-' . $outputBaseName . '-' . bin2hex(random_bytes(16));

    // Sibling ownership marker sits OUTSIDE the public stage in canonical parent.
    // Full ownership proof is written and verified BEFORE creating stage directory.
    $stageMarkerPath = $canonicalParentOutput . DIRECTORY_SEPARATOR . '.' . $stageName . '.owner';
    $stageToken = 'STAGE_OWNER_' . bin2hex(random_bytes(16));
    $markerPayload = $stageToken . "\n" . $stageName . "\n" . $outputBaseName . "\n";
    $writeChecked(
        $stageMarkerPath,
        $markerPayload,
        static function () use (&$stageMarkerCreated): void {
            $stageMarkerCreated = true;
        },
        'short_owner_write'
    );

    // Allocate exclusive owned random SIBLING stage directory ONLY AFTER marker exists
    $stageDir = $canonicalParentOutput . DIRECTORY_SEPARATOR . $stageName;
    if (!@mkdir($stageDir, 0700)) {
        throw new RuntimeException("Failed to allocate staging directory: '{$stageDir}'.");
    }
    $stageCreated = true;

    // Revalidate output still absent
    if (file_exists($canonicalOutput) || is_dir($canonicalOutput) || is_link($canonicalOutput)) {
        throw new RuntimeException("Output directory exists prior to staging writes: '{$canonicalOutput}'.");
    }

    // -------------------------------------------------------------
    // 4. Create Package Archive & Re-verify Input Invariants
    // -------------------------------------------------------------
    // Before creating zip: re-resolve parent and stage, ensure no reparse
    $parentCheck = ProductInventoryService::resolveAbsolutePath($canonicalParentOutput, true);
    ProductInventoryService::assertNoReparse($parentCheck);
    $stageCheck = ProductInventoryService::resolveAbsolutePath($stageDir, true);
    ProductInventoryService::assertNoReparse($stageCheck);
    if (strcasecmp($stageCheck, $canonicalOutput) === 0) {
        throw new RuntimeException("Stage directory must not match canonical output directory.");
    }

    $zipPath = $stageDir . DIRECTORY_SEPARATOR . ShellDeltaManifestService::PACKAGE_ASSET;
    echo "Packaging whole changed files into {$zipPath}...\n";

    $zip = new ZipArchive();
    $openRes = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL);
    if ($openRes !== true) {
        throw new RuntimeException("Cannot create zip archive: {$zipPath} (ZipArchive error code: {$openRes})");
    }
    $zipOpen = true;

    ksort($filesToPackage, SORT_STRING);
    $uncompressedTotal = 0;

    foreach ($filesToPackage as $relPath => $meta) {
        $sourceFilePath = ProductInventoryService::resolveConfinedPath($canonicalTarget, $relPath, true);
        $curSize = filesize($sourceFilePath);
        $curHash = hash_file('sha256', $sourceFilePath);

        if ($curSize !== $meta['size'] || $curHash !== $meta['sha256']) {
            throw new RuntimeException("Target physical file drift detected during packaging: {$relPath}");
        }

        if ($curSize > ShellDeltaManifestService::MAX_SINGLE_FILE_SIZE) {
            throw new RuntimeException("File exceeds 250 MiB limit: {$relPath}");
        }

        $uncompressedTotal += $curSize;
        if ($uncompressedTotal > ShellDeltaManifestService::MAX_UNCOMPRESSED_SIZE) {
            throw new RuntimeException("Total uncompressed payload exceeds 1 GiB limit.");
        }

        if (!$zip->addFile($sourceFilePath, $relPath)) {
            throw new RuntimeException("Failed to add file to zip archive: {$relPath}");
        }

        // Set deterministic regular Unix file attribute 0100644 << 16 on EVERY entry
        if (!$zip->setExternalAttributesName($relPath, ZipArchive::OPSYS_UNIX, (0100644 << 16))) {
            throw new RuntimeException("Failed to set regular Unix file attributes on zip entry: {$relPath}");
        }
    }

    $zipOpen = false;
    if (!$zip->close()) {
        $zip = null;
        throw new RuntimeException("Failed to finalize zip archive: {$zipPath}");
    }
    $zip = null;

    $zipSize = filesize($zipPath);
    if ($zipSize === false || $zipSize <= 0 || $zipSize > ShellDeltaManifestService::MAX_PACKAGE_SIZE) {
        throw new RuntimeException("Created zip archive invalid or exceeds 500 MiB limit.");
    }
    $zipHash = hash_file('sha256', $zipPath);
    if ($zipHash === false || strlen($zipHash) !== 64) {
        throw new RuntimeException("Failed to compute zip archive sha256 digest.");
    }

    // GHA Fault injection hook: after_zip, foreign_marker_same_prefix, simulate_target_drift, simulate_source_drift
    if (getenv('GITHUB_ACTIONS') === 'true') {
        $testFault = getenv('SHELL_DELTA_BUILDER_TEST_FAULT');
        if ($testFault === 'after_zip') {
            throw new RuntimeException("Synthetic fault simulation triggered: after_zip (simulation, not real disk full)");
        }
        if ($testFault === 'foreign_marker_same_prefix') {
            $foreignToken = 'STAGE_OWNER_foreign_' . bin2hex(random_bytes(16));
            $foreignPayload = $foreignToken . "\n" . $stageName . "\n" . $outputBaseName . "\n";
            file_put_contents($stageMarkerPath, $foreignPayload);
            throw new RuntimeException("Synthetic fault simulation triggered: foreign_marker_same_prefix (marker replaced with foreign same-prefix token)");
        }
        if ($testFault === 'simulate_target_drift') {
            file_put_contents($canonicalTarget . '/resources/app.asar.unpacked/version.json', json_encode(['version' => '0.0.99']));
        }
        if ($testFault === 'simulate_source_drift') {
            file_put_contents($canonicalSource . '/resources/app.asar.unpacked/version.json', json_encode(['version' => '0.0.99']));
        }
    }

    // Revalidate both input inventories after ZIP close and before publish to reject input drift
    $postSourceInventory = $inventoryService->buildInventory($canonicalSource, $sourceNativeVersion);
    $postTargetInventory = $inventoryService->buildInventory($canonicalTarget, $targetNativeVersion);
    if ($postSourceInventory !== $sourceInventory) {
        throw new RuntimeException("Source installation drifted during delta build.");
    }
    if ($postTargetInventory !== $targetInventory) {
        throw new RuntimeException("Target installation drifted during delta build.");
    }

    // -------------------------------------------------------------
    // 5. Assemble Format 2 Descriptor, Sign & Self-Verify
    // -------------------------------------------------------------
    $srcInvHash = ShellDeltaManifestService::canonicalInventoryDigest($sourceInventory);
    $tgtInvHash = ShellDeltaManifestService::canonicalInventoryDigest($targetInventory);

    $descriptor = [
        'format' => ShellDeltaManifestService::FORMAT,
        'operation' => ShellDeltaManifestService::OPERATION,
        'minimum_updater_protocol' => ShellDeltaManifestService::MINIMUM_UPDATER_PROTOCOL,
        'platform' => ShellDeltaManifestService::PLATFORM,
        'arch' => ShellDeltaManifestService::ARCH,
        'purpose' => $purpose,
        'from_version' => $sourceInventory['version'],
        'from_channel' => $fromChannel,
        'from_native_version' => $sourceInventory['native_version'],
        'version' => $targetInventory['version'],
        'channel' => $toChannel,
        'prerelease' => $isBeta,
        'native_version' => $targetInventory['native_version'],
        'source_inventory_sha256' => $srcInvHash,
        'source_inventory' => $sourceInventory,
        'target_inventory_sha256' => $tgtInvHash,
        'target_inventory' => $targetInventory,
        'source_native_shell' => $sourceInventory['native_shell'],
        'target_native_shell' => $targetInventory['native_shell'],
        'files' => $manifestFiles,
        'deleted_files' => $deletedFiles,
        'package' => [
            'asset' => ShellDeltaManifestService::PACKAGE_ASSET,
            'sha256' => $zipHash,
            'size' => $zipSize,
            'uncompressed_size' => $uncompressedTotal,
        ],
        'data_compatibility' => $targetDataContract,
        'source_data_compatibility' => $sourceDataContract,
        'migrations' => [],
    ];

    if ($purpose === 'bootstrap_completion') {
        $descriptor['bootstrap_link'] = $bootstrapLink;
    }

    $rawManifest = json_encode($descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (strlen($rawManifest) > ShellDeltaManifestService::MAX_MANIFEST_SIZE) {
        throw new RuntimeException("Manifest payload exceeds 16 MiB limit.");
    }

    $sigBytes = '';
    if (!openssl_sign($rawManifest, $sigBytes, $privKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException("Failed to sign manifest with private key.");
    }
    $sigBase64 = base64_encode($sigBytes);
    if (strlen($sigBase64) > ShellDeltaManifestService::MAX_SIGNATURE_SIZE) {
        throw new RuntimeException("Manifest signature exceeds 16 KiB limit.");
    }

    echo "Self-verifying manifest, source installation, and package zip...\n";
    $selfSignatureService = new ManifestSignatureService(null, [$derivedPublicKeyPem]);
    $manifestService = new ShellDeltaManifestService($selfSignatureService, $inventoryService);

    $verifyResult = $manifestService->verify($rawManifest, $sigBase64);
    if (!$verifyResult['ok']) {
        throw new RuntimeException("Manifest self-verification failed: " . ($verifyResult['reason_code'] ?? 'unknown'));
    }

    $srcVerifyResult = $manifestService->verifySource($canonicalSource, $descriptor);
    if (!$srcVerifyResult['ok']) {
        throw new RuntimeException("Source self-verification failed: " . ($srcVerifyResult['reason_code'] ?? 'unknown'));
    }

    $zipVerifyResult = $manifestService->validatePackageZip($zipPath, $descriptor);
    if (!$zipVerifyResult['ok']) {
        throw new RuntimeException("Package zip self-verification failed: " . ($zipVerifyResult['reason_code'] ?? 'unknown'));
    }

    // -------------------------------------------------------------
    // 6. Write Remaining 4 Assets into Stage & Verify On-Disk
    // -------------------------------------------------------------
    $stageManifestPath = $stageDir . DIRECTORY_SEPARATOR . ShellDeltaManifestService::MANIFEST_ASSET;
    $stageSignaturePath = $stageDir . DIRECTORY_SEPARATOR . ShellDeltaManifestService::SIGNATURE_ASSET;
    $stageSrcInvPath = $stageDir . DIRECTORY_SEPARATOR . 'source-inventory.json';
    $stageTgtInvPath = $stageDir . DIRECTORY_SEPARATOR . 'target-inventory.json';

    $revalidateStage = static function () use ($canonicalParentOutput, $stageDir, $canonicalOutput): void {
        $parentCheck = ProductInventoryService::resolveAbsolutePath($canonicalParentOutput, true);
        ProductInventoryService::assertNoReparse($parentCheck);
        $stageCheck = ProductInventoryService::resolveAbsolutePath($stageDir, true);
        ProductInventoryService::assertNoReparse($stageCheck);
        if ($canonicalOutput !== null && strcasecmp($stageCheck, $canonicalOutput) === 0) {
            throw new RuntimeException("Stage directory must not match canonical output directory.");
        }
    };

    $revalidateStage();
    $writeChecked($stageManifestPath, $rawManifest, null, 'short_asset_write');
    $revalidateStage();
    $writeChecked($stageSignaturePath, $sigBase64 . "\n");
    $revalidateStage();
    $writeChecked($stageSrcInvPath, json_encode($sourceInventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $revalidateStage();
    $writeChecked($stageTgtInvPath, json_encode($targetInventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    // Re-verify written assets directly from disk
    $readManifest = $readBoundedFile($stageManifestPath, ShellDeltaManifestService::MAX_MANIFEST_SIZE, 'Written manifest');
    if ($readManifest !== $rawManifest) {
        throw new RuntimeException("Written manifest verification mismatch.");
    }
    $readSig = trim($readBoundedFile($stageSignaturePath, ShellDeltaManifestService::MAX_SIGNATURE_SIZE, 'Written signature'));
    if ($readSig !== $sigBase64) {
        throw new RuntimeException("Written signature verification mismatch.");
    }

    $readSrcInv = json_decode($readBoundedFile($stageSrcInvPath, 16777216, 'Written source inventory'), true, 512, JSON_THROW_ON_ERROR);
    if (ShellDeltaManifestService::canonicalInventoryDigest($readSrcInv) !== $srcInvHash) {
        throw new RuntimeException("Written source inventory digest mismatch.");
    }

    $readTgtInv = json_decode($readBoundedFile($stageTgtInvPath, 16777216, 'Written target inventory'), true, 512, JSON_THROW_ON_ERROR);
    if (ShellDeltaManifestService::canonicalInventoryDigest($readTgtInv) !== $tgtInvHash) {
        throw new RuntimeException("Written target inventory digest mismatch.");
    }

    $writtenVerify = $manifestService->verify($readManifest, $readSig);
    if (!$writtenVerify['ok']) {
        throw new RuntimeException("Written manifest re-verification failed: " . ($writtenVerify['reason_code'] ?? 'unknown'));
    }

    $writtenZipVerify = $manifestService->validatePackageZip($zipPath, $descriptor);
    if (!$writtenZipVerify['ok']) {
        throw new RuntimeException("Written package zip re-verification failed: " . ($writtenZipVerify['reason_code'] ?? 'unknown'));
    }

    // GHA Fault injection hook: before_publish
    if (getenv('GITHUB_ACTIONS') === 'true') {
        $testFault = getenv('SHELL_DELTA_BUILDER_TEST_FAULT');
        if ($testFault === 'before_publish') {
            throw new RuntimeException("Synthetic fault simulation triggered: before_publish (simulation, not real disk full)");
        }
    }

    // -------------------------------------------------------------
    // 7. Atomic Publication Rename & Lease Release
    // -------------------------------------------------------------
    // Reverify canonical parent and stage via resolveAbsolutePath/assertNoReparse
    $parentRecheck = ProductInventoryService::resolveAbsolutePath($canonicalParentOutput, true);
    ProductInventoryService::assertNoReparse($parentRecheck);

    $stageRecheck = ProductInventoryService::resolveAbsolutePath($stageDir, true);
    ProductInventoryService::assertNoReparse($stageRecheck);

    // GHA Fault injection hooks: pre_rename, pre_rename_create_empty_dest, pre_rename_create_nonempty_dest
    if (getenv('GITHUB_ACTIONS') === 'true') {
        $testFault = getenv('SHELL_DELTA_BUILDER_TEST_FAULT');
        if ($testFault === 'pre_rename') {
            throw new RuntimeException("Synthetic fault simulation triggered: pre_rename (simulation, not real disk full)");
        }
        if ($testFault === 'pre_rename_create_empty_dest') {
            @mkdir($canonicalOutput, 0700);
        }
        if ($testFault === 'pre_rename_create_nonempty_dest') {
            @mkdir($canonicalOutput, 0700);
            @file_put_contents($canonicalOutput . '/foreign.txt', 'foreign_data');
        }
    }

    // Revalidate output still absent immediately prior to rename
    if (file_exists($canonicalOutput) || is_dir($canonicalOutput) || is_link($canonicalOutput)) {
        throw new RuntimeException("Output directory exists immediately prior to publication rename: '{$canonicalOutput}'.");
    }
    if (strcasecmp(dirname($canonicalOutput), $canonicalParentOutput) !== 0 || strcasecmp(dirname($stageDir), $canonicalParentOutput) !== 0) {
        throw new RuntimeException("Parent directory identity mismatch prior to rename.");
    }

    // Publication uses cooperating publication lease + pre-rename absence check + rename().
    // On Windows, the test suite empirically probes rename() directory destination preservation.
    // Uncoordinated concurrent processes have a residual TOCTOU window between pre-check and rename;
    // the builder never knowingly replaces foreign output and aborts if rename fails.
    $renamed = @rename($stageDir, $canonicalOutput);
    if (!$renamed) {
        throw new RuntimeException("Failed to atomically rename stage directory to final output directory: '{$stageDir}' -> '{$canonicalOutput}'.");
    }
    $stageRenamed = true;

    // STATE: PUBLISHED. The output directory is final and must NEVER be deleted on subsequent cleanup failures (NO ROLLBACK).
    $postRenameErrors = [];

    // Checked release of sibling owner marker
    if ($stageMarkerPath !== null && file_exists($stageMarkerPath)) {
        try {
            $canonicalMarker = ProductInventoryService::resolveAbsolutePath($stageMarkerPath, true);
            ProductInventoryService::assertNoReparse($canonicalMarker);
            if (strcasecmp(dirname($canonicalMarker), $canonicalParentOutput) !== 0) {
                throw new RuntimeException("Stage marker parent mismatch post-rename.");
            }
            $markerRaw = $readBoundedFile($stageMarkerPath, 4096, 'Stage owner marker');
            $expectedMarkerPayload = $stageToken . "\n" . $stageName . "\n" . basename($canonicalOutput) . "\n";
            if ($markerRaw === $expectedMarkerPayload) {
                if (!@unlink($stageMarkerPath)) {
                    $postRenameErrors[] = "Failed to unlink stage marker '{$stageMarkerPath}' after publication.";
                }
            } else {
                $postRenameErrors[] = "Stage marker content mismatch during post-rename release.";
            }
        } catch (Throwable $e) {
            $postRenameErrors[] = "Exception during post-rename marker release: " . $e->getMessage();
        }
    }

    // Checked release of publication lease
    if ($leaseFp !== null && is_resource($leaseFp)) {
        try {
            @fclose($leaseFp);
        } catch (Throwable $fe) {
            $postRenameErrors[] = "Exception closing lease handle after publication: " . $fe->getMessage();
        }
        $leaseFp = null;
    }
    if ($leaseFilePath !== null && file_exists($leaseFilePath)) {
        try {
            $canonicalLease = ProductInventoryService::resolveAbsolutePath($leaseFilePath, true);
            ProductInventoryService::assertNoReparse($canonicalLease);
            if (strcasecmp(dirname($canonicalLease), $canonicalParentOutput) !== 0) {
                throw new RuntimeException("Publication lease parent mismatch post-rename.");
            }
            $leaseRaw = $readBoundedFile($leaseFilePath, 4096, 'Publication lease');
            $expectedLeasePayload = $leaseToken . "\n";
            if ($leaseRaw === $expectedLeasePayload || trim($leaseRaw) === $leaseToken) {
                if (!@unlink($leaseFilePath)) {
                    $postRenameErrors[] = "Failed to unlink publication lease '{$leaseFilePath}' after publication.";
                }
            } else {
                $postRenameErrors[] = "Publication lease token mismatch during post-rename release.";
            }
        } catch (Throwable $e) {
            $postRenameErrors[] = "Exception during post-rename lease release: " . $e->getMessage();
        }
    }

    if (!empty($postRenameErrors)) {
        foreach ($postRenameErrors as $err) {
            fwrite(STDERR, "Post-rename cleanup error: {$err}\n");
        }
        fwrite(STDERR, "NEEDS ATTENTION: Publication succeeded at '{$canonicalOutput}', but cleanup of marker/lease failed.\n");
        exit(1);
    }

    $finalManifestPath = $canonicalOutput . DIRECTORY_SEPARATOR . ShellDeltaManifestService::MANIFEST_ASSET;
    $finalSigPath = $canonicalOutput . DIRECTORY_SEPARATOR . ShellDeltaManifestService::SIGNATURE_ASSET;
    $finalZipPath = $canonicalOutput . DIRECTORY_SEPARATOR . ShellDeltaManifestService::PACKAGE_ASSET;

    echo "\n=== Shell Delta Build Completed Successfully ===\n";
    echo "Manifest:     {$finalManifestPath}\n";
    echo "Signature:    {$finalSigPath}\n";
    echo "Package:      {$finalZipPath} ({$zipSize} bytes, sha256: {$zipHash})\n";
    echo "From:         {$sourceInventory['version']} (native: {$sourceInventory['native_version']})\n";
    echo "To:           {$targetInventory['version']} (native: {$targetInventory['native_version']})\n";
    echo "Channel:      {$toChannel} (prerelease: " . ($isBeta ? 'true' : 'false') . ")\n";
    echo "Purpose:      {$purpose}\n";
    echo "Payload:      " . count($manifestFiles) . " modified/new files, " . count($deletedFiles) . " deleted files\n";
    echo "Uncompressed: {$uncompressedTotal} bytes\n";
    exit(0);

} catch (Throwable $e) {
    $cleanupStageAndLease(
        $stageDir,
        $stageName,
        $stageCreated,
        $stageMarkerPath,
        $stageMarkerCreated,
        $stageToken,
        $stageRenamed,
        $leaseFilePath,
        $leaseCreated,
        $leaseToken,
        $leaseFp,
        $zip,
        $zipOpen,
        $canonicalParentOutput,
        $canonicalOutput,
        $readBoundedFile
    );
    fwrite(STDERR, "Build error: " . $e->getMessage() . "\n");
    exit(1);
}
