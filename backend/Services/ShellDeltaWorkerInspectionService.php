<?php
declare(strict_types=1);

namespace App\Services;

use Phar;
use RuntimeException;
use Throwable;

/**
 * Isolated read-only inspection service for sealed worker snapshots.
 * Generates an isolated execution plan and executes readonly probes to prove
 * the copied PHP runtime and built backend.phar are authentic, intact, and runnable
 * without executing php.ini directives or inheriting ambient PHP configuration or PHP environment variables.
 *
 * This service never mutates product files, never writes database state,
 * never performs cleanup, and does not claim apply capability (can_apply=false).
 */
final class ShellDeltaWorkerInspectionService
{
    public const OPERATION = 'inspect_snapshot';
    public const INSPECTION_FORMAT = 1;
    public const PHASE_INSPECTED = 'snapshot_inspected';

    public const REQUIRED_EXTENSIONS = [
        'openssl' => 'php_openssl.dll',
        'zip' => 'php_zip.dll',
        'mbstring' => 'php_mbstring.dll',
    ];

    public const MAX_CONTEXT_JSON_BYTES = 4096;
    public const INSPECTION_MARKER_ENTRY = 'shell-worker-inspection.json';
    public const MAX_INSPECTION_MARKER_BYTES = 4096;
    public const EXPECTED_WORKER_ENTRYPOINT = 'cli/shell-worker-inspect.php';

    private ShellDeltaWorkerSnapshotService $snapshotService;

    /**
     * @param ShellDeltaWorkerSnapshotService|null $snapshotService Optional injected snapshot service for testing
     */
    public function __construct(?ShellDeltaWorkerSnapshotService $snapshotService = null)
    {
        if ($snapshotService !== null) {
            $this->snapshotService = $snapshotService;
        } else {
            $exactKeyPath = dirname(__DIR__) . '/certs/update_public_key.pem';
            $sigService = new ManifestSignatureService($exactKeyPath, [], false, false);
            $manifestService = new ShellDeltaManifestService($sigService);
            $this->snapshotService = new ShellDeltaWorkerSnapshotService($manifestService);
        }
    }

    /**
     * Generate a bounded read-only execution plan for snapshot inspection.
     * Fully validates the sealed snapshot before constructing the isolated plan.
     *
     * @param string $productRoot Absolute Windows product root directory
     * @param string $privateStateBase Absolute Windows private state base directory
     * @param string $snapshotId Snapshot identifier (rt_<32lowerhex>)
     * @param string $expectedSnapshotSha256 Pinned 64-character lowercase hex digest of snapshot.json
     * @param array<string, mixed> $installedContext Required source context
     * @return array{
     *     operation: string,
     *     can_apply: false,
     *     snapshot_id: string,
     *     snapshot_sha256: string,
     *     source_inventory_sha256: string,
     *     manifest_sha256: string,
     *     command: string,
     *     cwd: string,
     *     args: list<string>,
     *     env: array<string, string>,
     *     phase: string,
     *     expected_php_sha256: string,
     *     expected_phar_sha256: string
     * }
     */
    public function plan(
        string $productRoot,
        string $privateStateBase,
        string $snapshotId,
        string $expectedSnapshotSha256,
        array $installedContext
    ): array {
        // 1. Verify sealed snapshot fully BEFORE returning plan
        $verified = $this->snapshotService->verifySealed(
            $productRoot,
            $privateStateBase,
            $snapshotId,
            $expectedSnapshotSha256,
            $installedContext
        );

        $phpPath = $verified['php_path'];
        $pharPath = $verified['phar_path'];

        // 2. Verify static inspection-only capability marker inside source PHAR before constructing plan
        $this->verifyPharInspectionCapability($pharPath);

        $phpDir = dirname($phpPath);
        ProductInventoryService::assertNoReparse($phpDir);
        $extDir = $phpDir . DIRECTORY_SEPARATOR . 'ext';
        ProductInventoryService::assertNoReparse($extDir);

        if (!is_dir($extDir) || is_link($extDir)) {
            throw new RuntimeException("Private PHP extension directory missing or not a directory: {$extDir}");
        }

        // 2. Require signed private ext/php_openssl.dll, ext/php_zip.dll, ext/php_mbstring.dll exist and no-reparse
        $requiredExtPaths = [];
        foreach (self::REQUIRED_EXTENSIONS as $extName => $dllFile) {
            $dllPath = $extDir . DIRECTORY_SEPARATOR . $dllFile;
            clearstatcache(true, $dllPath);
            ProductInventoryService::assertNoReparse($dllPath);
            if (!is_file($dllPath) || is_link($dllPath)) {
                throw new RuntimeException("Required signed private extension DLL missing: {$dllFile} at {$dllPath}");
            }
            $requiredExtPaths[$extName] = $dllPath;
        }

        // 3. Canonicalize installed context to bounded JSON and base64
        $canonicalContext = $installedContext;
        if (isset($canonicalContext['data_compatibility']) && is_array($canonicalContext['data_compatibility'])) {
            ksort($canonicalContext['data_compatibility'], SORT_STRING);
        }
        ksort($canonicalContext, SORT_STRING);
        $jsonContext = json_encode($canonicalContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($jsonContext) > self::MAX_CONTEXT_JSON_BYTES) {
            throw new RuntimeException("Installed context exceeds size limit.");
        }
        $base64Context = base64_encode($jsonContext);

        // 4. Construct isolated CLI arguments
        $args = [
            '-n',
            '-d', 'date.timezone=UTC',
            '-d', 'auto_prepend_file=',
            '-d', 'auto_append_file=',
            '-d', 'user_ini.filename=',
            '-d', 'extension_dir=' . $extDir,
            '-d', 'extension=' . $requiredExtPaths['openssl'],
            '-d', 'extension=' . $requiredExtPaths['zip'],
            '-d', 'extension=' . $requiredExtPaths['mbstring'],
            $pharPath,
            'shell-worker-inspect',
            '--product-root=' . $productRoot,
            '--state-base=' . $privateStateBase,
            '--snapshot-id=' . $snapshotId,
            '--snapshot-sha256=' . $expectedSnapshotSha256,
            '--installed-context=' . $base64Context,
        ];

        // Conservative native command-line upper bound check (< 30000 chars for Windows 32767 limit)
        $estCmdLength = strlen($phpPath) + 3;
        foreach ($args as $arg) {
            $estCmdLength += strlen($arg) + 3;
        }
        if ($estCmdLength >= 30000) {
            throw new RuntimeException("Estimated command-line length ({$estCmdLength}) exceeds safety bound.");
        }

        // 5. Construct environment FROM SCRATCH (no array_merge with ambient variables)
        $rawSystemRoot = getenv('SystemRoot') ?: (getenv('WINDIR') ?: 'C:\\Windows');
        if (!is_string($rawSystemRoot) || trim($rawSystemRoot) === '') {
            throw new RuntimeException("SystemRoot environment metadata is missing.");
        }
        $cleanSystemRoot = rtrim(str_replace('/', '\\', $rawSystemRoot), '\\');
        if (preg_match('/^[a-zA-Z]:\\\\[a-zA-Z0-9_\-\.\s\\\\]+$/D', $cleanSystemRoot) !== 1) {
            throw new RuntimeException("Unsafe or invalid SystemRoot path: {$cleanSystemRoot}");
        }
        ShellDeltaWorkerSnapshotService::assertNotDriveRoot($cleanSystemRoot, 'SystemRoot');
        if (!is_dir($cleanSystemRoot)) {
            throw new RuntimeException("SystemRoot directory does not exist: {$cleanSystemRoot}");
        }
        ProductInventoryService::assertNoReparse($cleanSystemRoot);

        $system32Dir = $cleanSystemRoot . '\\System32';
        if (!is_dir($system32Dir)) {
            throw new RuntimeException("System32 directory does not exist: {$system32Dir}");
        }
        ProductInventoryService::assertNoReparse($system32Dir);

        $canonicalStateBase = ProductInventoryService::resolveAbsolutePath($privateStateBase, true);
        ProductInventoryService::assertNoReparse($canonicalStateBase);

        $env = [
            'SystemRoot' => $cleanSystemRoot,
            'WINDIR' => $cleanSystemRoot,
            'PATH' => $phpDir . ';' . $system32Dir,
            'PHPRC' => '',
            'PHP_INI_SCAN_DIR' => '',
            'OPENSSL_CONF' => 'NUL',
            'OPENSSL_MODULES' => $phpDir,
            'OPENSSL_ENGINES' => $phpDir,
            'TEMP' => $canonicalStateBase,
            'TMP' => $canonicalStateBase,
        ];

        return [
            'operation' => self::OPERATION,
            'can_apply' => false,
            'snapshot_id' => $verified['snapshot_id'],
            'snapshot_sha256' => $verified['snapshot_sha256'],
            'source_inventory_sha256' => $verified['source_inventory_sha256'],
            'manifest_sha256' => $verified['manifest_sha256'],
            'command' => $phpPath,
            'cwd' => $phpDir,
            'args' => $args,
            'env' => $env,
            'phase' => $verified['phase'],
            'expected_php_sha256' => $verified['expected_php_sha256'],
            'expected_phar_sha256' => $verified['expected_phar_sha256'],
        ];
    }

    /**
     * Isolated inspection entry method executed within the spawned isolated process.
     * Strictly verifies runtime environment isolation, running binary, running PHAR,
     * and sealed snapshot integrity before outputting bounded JSON.
     *
     * @param string $productRoot Absolute Windows product root directory
     * @param string $privateStateBase Absolute Windows private state base directory
     * @param string $snapshotId Snapshot identifier (rt_<32lowerhex>)
     * @param string $expectedSnapshotSha256 Pinned 64-character lowercase hex digest of snapshot.json
     * @param array<string, mixed> $installedContext Required source context
     * @return array{
     *     ok: true,
     *     operation: string,
     *     inspection_format: int,
     *     phase: string,
     *     snapshot_id: string,
     *     snapshot_sha256: string,
     *     source_inventory_sha256: string,
     *     manifest_sha256: string,
     *     php_sha256: string,
     *     phar_sha256: string,
     *     no_ini: true,
     *     can_apply: false
     * }
     */
    public function inspect(
        string $productRoot,
        string $privateStateBase,
        string $snapshotId,
        string $expectedSnapshotSha256,
        array $installedContext
    ): array {
        // 1. Must be executed in Windows CLI environment
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException("Isolated snapshot inspection requires CLI execution.");
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException("Isolated snapshot inspection requires Windows platform.");
        }

        // 2. Perform full verifySealed check
        $verified = $this->snapshotService->verifySealed(
            $productRoot,
            $privateStateBase,
            $snapshotId,
            $expectedSnapshotSha256,
            $installedContext
        );

        // 3. Verify running physical PHAR matches snapshot verified PHAR
        $runningPhar = Phar::running(false);
        if (!is_string($runningPhar) || $runningPhar === '') {
            throw new RuntimeException("Isolated inspection must execute from within a running PHAR.");
        }
        $canonicalRunningPhar = ProductInventoryService::resolveAbsolutePath($runningPhar, true);
        $canonicalExpectedPhar = ProductInventoryService::resolveAbsolutePath($verified['phar_path'], true);
        if (strtolower(str_replace('/', '\\', $canonicalRunningPhar)) !== strtolower(str_replace('/', '\\', $canonicalExpectedPhar))) {
            throw new RuntimeException("Running PHAR does not match snapshot verified PHAR path.");
        }

        // 4. Verify running PHP binary matches snapshot verified PHP binary
        $canonicalRunningPhp = ProductInventoryService::resolveAbsolutePath(PHP_BINARY, true);
        $canonicalExpectedPhp = ProductInventoryService::resolveAbsolutePath($verified['php_path'], true);
        if (strtolower(str_replace('/', '\\', $canonicalRunningPhp)) !== strtolower(str_replace('/', '\\', $canonicalExpectedPhp))) {
            throw new RuntimeException("Running PHP binary does not match snapshot verified PHP binary path.");
        }

        // 5. Verify real cwd matches private PHP directory
        $currentCwd = getcwd();
        if (!is_string($currentCwd) || $currentCwd === '') {
            throw new RuntimeException("Unable to determine current working directory.");
        }
        $canonicalCwd = ProductInventoryService::resolveAbsolutePath($currentCwd, true);
        $expectedPhpDir = dirname($canonicalExpectedPhp);
        $canonicalExpectedPhpDir = ProductInventoryService::resolveAbsolutePath($expectedPhpDir, true);
        if (strtolower(str_replace('/', '\\', $canonicalCwd)) !== strtolower(str_replace('/', '\\', $canonicalExpectedPhpDir))) {
            throw new RuntimeException("Current working directory does not match snapshot private PHP directory.");
        }

        // 6. Verify no loaded or scanned INI files (-n flag enforcement)
        $loadedIni = php_ini_loaded_file();
        if ($loadedIni !== false) {
            throw new RuntimeException("PHP INI file loaded during isolated inspection (-n flag violation): {$loadedIni}");
        }
        $scannedIni = php_ini_scanned_files();
        if ($scannedIni !== false && trim((string)$scannedIni) !== '') {
            throw new RuntimeException("PHP INI scanned files detected during isolated inspection: {$scannedIni}");
        }

        // 7. Verify auto_prepend_file, auto_append_file, user_ini.filename are empty
        $prepend = ini_get('auto_prepend_file');
        if ($prepend !== false && $prepend !== '') {
            throw new RuntimeException("auto_prepend_file must be empty during isolated inspection.");
        }
        $append = ini_get('auto_append_file');
        if ($append !== false && $append !== '') {
            throw new RuntimeException("auto_append_file must be empty during isolated inspection.");
        }
        $userIni = ini_get('user_ini.filename');
        if ($userIni !== false && $userIni !== '') {
            throw new RuntimeException("user_ini.filename must be empty during isolated inspection.");
        }

        // 8. Verify required extensions are loaded
        foreach (array_keys(self::REQUIRED_EXTENSIONS) as $extName) {
            if (!extension_loaded($extName)) {
                throw new RuntimeException("Required extension '{$extName}' is not loaded.");
            }
        }

        // 9. Re-verify SHA-256 of own running binary and PHAR against verified snapshot files and signed expected hashes
        $actualPhpSha256 = hash_file('sha256', $canonicalExpectedPhp);
        if (!is_string($actualPhpSha256) || preg_match('/^[a-f0-9]{64}$/D', $actualPhpSha256) !== 1) {
            throw new RuntimeException("Failed to compute SHA-256 digest of PHP binary: {$canonicalExpectedPhp}");
        }
        if (!hash_equals($verified['expected_php_sha256'], $actualPhpSha256)) {
            throw new RuntimeException("Running PHP binary SHA-256 mismatch against signed manifest expectation.");
        }

        $actualPharSha256 = hash_file('sha256', $canonicalExpectedPhar);
        if (!is_string($actualPharSha256) || preg_match('/^[a-f0-9]{64}$/D', $actualPharSha256) !== 1) {
            throw new RuntimeException("Failed to compute SHA-256 digest of PHAR file: {$canonicalExpectedPhar}");
        }
        if (!hash_equals($verified['expected_phar_sha256'], $actualPharSha256)) {
            throw new RuntimeException("Running PHAR SHA-256 mismatch against signed manifest expectation.");
        }

        // 10. Output bounded strict result
        return [
            'ok' => true,
            'operation' => self::OPERATION,
            'inspection_format' => self::INSPECTION_FORMAT,
            'phase' => self::PHASE_INSPECTED,
            'snapshot_id' => $verified['snapshot_id'],
            'snapshot_sha256' => $verified['snapshot_sha256'],
            'source_inventory_sha256' => $verified['source_inventory_sha256'],
            'manifest_sha256' => $verified['manifest_sha256'],
            'php_sha256' => $actualPhpSha256,
            'phar_sha256' => $actualPharSha256,
            'no_ini' => true,
            'can_apply' => false,
        ];
    }

    /**
     * Verify static inspection-only capability marker inside source PHAR.
     * Prevents launching older authenticated PHAR releases that lack shell-worker CLI handling.
     * Never executes archive stub, never reads metadata / unserializes objects, performs zero writes.
     *
     * @param string $pharPath Absolute path to verified copied backend.phar
     */
    private function verifyPharInspectionCapability(string $pharPath): void
    {
        ProductInventoryService::assertNoReparse($pharPath);

        $pharObj = null;
        try {
            $pharObj = new Phar($pharPath);
        } catch (Throwable $e) {
            // Handle alias conflict if backend.phar is already mapped in runtime
            $uniqueAlias = 'inspect_' . bin2hex(random_bytes(8)) . '.phar';
            try {
                if (Phar::loadPhar($pharPath, $uniqueAlias)) {
                    $pharObj = new Phar($pharPath, 0, $uniqueAlias);
                } else {
                    throw $e;
                }
            } catch (Throwable) {
                throw new RuntimeException("Failed to open source PHAR archive readonly: " . $e->getMessage(), 0, $e);
            }
        }

        try {
            // 1. Check required marker entry exists
            if (!isset($pharObj[self::INSPECTION_MARKER_ENTRY])) {
                throw new RuntimeException("Source PHAR lacks inspection capability marker '" . self::INSPECTION_MARKER_ENTRY . "'.");
            }

            // 2. Check uncompressed size BEFORE getContent() and read bounded marker JSON (never call getMetadata)
            $markerEntry = $pharObj[self::INSPECTION_MARKER_ENTRY];
            if ($markerEntry->getSize() > self::MAX_INSPECTION_MARKER_BYTES) {
                throw new RuntimeException("Inspection capability marker uncompressed size exceeds limit.");
            }
            $markerRaw = $markerEntry->getContent();
            if (strlen($markerRaw) > self::MAX_INSPECTION_MARKER_BYTES) {
                throw new RuntimeException("Inspection capability marker exceeds size limit.");
            }

            try {
                $marker = json_decode($markerRaw, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new RuntimeException("Inspection capability marker is not valid JSON.");
            }

            if (!is_array($marker)) {
                throw new RuntimeException("Inspection capability marker must decode to an associative array.");
            }

            // 3. Strict schema validation: EXACT 6 keys
            $expectedKeys = ['can_apply', 'entrypoint', 'entrypoint_sha256', 'format', 'operation', 'stub_sha256'];
            $actualKeys = array_keys($marker);
            sort($actualKeys, SORT_STRING);
            if ($actualKeys !== $expectedKeys) {
                throw new RuntimeException("Inspection capability marker key set mismatch.");
            }

            if ($marker['format'] !== self::INSPECTION_FORMAT) {
                throw new RuntimeException("Inspection capability marker format mismatch.");
            }
            if ($marker['operation'] !== self::OPERATION) {
                throw new RuntimeException("Inspection capability marker operation mismatch.");
            }
            if ($marker['can_apply'] !== false) {
                throw new RuntimeException("Inspection capability marker must declare can_apply=false.");
            }
            if ($marker['entrypoint'] !== self::EXPECTED_WORKER_ENTRYPOINT) {
                throw new RuntimeException("Inspection capability marker entrypoint mismatch.");
            }

            // Validate both hash strings are 64 lowercase hex
            if (!is_string($marker['entrypoint_sha256']) || !preg_match('/^[0-9a-f]{64}$/', $marker['entrypoint_sha256'])) {
                throw new RuntimeException("Inspection capability entrypoint hash is not a valid 64-hex lowercase SHA-256.");
            }
            if (!is_string($marker['stub_sha256']) || !preg_match('/^[0-9a-f]{64}$/', $marker['stub_sha256'])) {
                throw new RuntimeException("Inspection capability stub hash is not a valid 64-hex lowercase SHA-256.");
            }

            // 4. Bound expected CLI entrypoint size BEFORE getContent, verify entrypoint exists and match hash
            if (!isset($pharObj[self::EXPECTED_WORKER_ENTRYPOINT])) {
                throw new RuntimeException("Inspection entrypoint '" . self::EXPECTED_WORKER_ENTRYPOINT . "' missing in source PHAR.");
            }
            $entrypointEntry = $pharObj[self::EXPECTED_WORKER_ENTRYPOINT];
            if ($entrypointEntry->getSize() > 65536) {
                throw new RuntimeException("Inspection entrypoint uncompressed size exceeds limit.");
            }
            $entrypointBytes = $entrypointEntry->getContent();
            $actualEntrypointSha256 = hash('sha256', $entrypointBytes);
            if (!hash_equals($marker['entrypoint_sha256'], $actualEntrypointSha256)) {
                throw new RuntimeException("Inspection capability entrypoint hash mismatch.");
            }

            // 5. Verify actual stub hash matches marker
            $actualStub = $pharObj->getStub();
            $actualStubSha256 = hash('sha256', $actualStub);
            if (!hash_equals($marker['stub_sha256'], $actualStubSha256)) {
                throw new RuntimeException("Inspection capability stub hash mismatch.");
            }
        } finally {
            unset($pharObj);
            gc_collect_cycles();
        }
    }

    /**
     * Strict CLI option parser for shell-worker-inspect command.
     * Accepts ONLY exact named flags without arbitrary or duplicate options.
     *
     * @param list<string> $argv
     * @return array{
     *     product_root: string,
     *     state_base: string,
     *     snapshot_id: string,
     *     snapshot_sha256: string,
     *     installed_context: array<string, mixed>
     * }
     */
    public static function parseCliArgs(array $argv): array
    {
        $rawSubcommand = $argv[1] ?? '';
        if ($rawSubcommand !== 'shell-worker-inspect') {
            $boundedSubcommand = substr($rawSubcommand, 0, 64);
            throw new RuntimeException("Unsupported or invalid subcommand: '{$boundedSubcommand}'");
        }

        $allowedFlags = [
            'product-root',
            'state-base',
            'snapshot-id',
            'snapshot-sha256',
            'installed-context',
        ];

        $seenFlags = [];
        $flagValues = [];
        $argc = count($argv);
        $i = 2;

        while ($i < $argc) {
            $arg = $argv[$i];
            if (!str_starts_with($arg, '--')) {
                throw new RuntimeException("Unexpected positional argument: '{$arg}'");
            }

            $flagName = '';
            $flagValue = null;

            if (str_contains($arg, '=')) {
                $eqPos = strpos($arg, '=');
                $flagName = substr($arg, 2, $eqPos - 2);
                $flagValue = substr($arg, $eqPos + 1);
            } else {
                $flagName = substr($arg, 2);
                if ($i + 1 < $argc && !str_starts_with($argv[$i + 1], '--')) {
                    $flagValue = $argv[$i + 1];
                    $i++;
                } else {
                    throw new RuntimeException("Flag '--{$flagName}' requires a value.");
                }
            }

            if (!in_array($flagName, $allowedFlags, true)) {
                throw new RuntimeException("Disallowed or unknown flag: '--{$flagName}'");
            }

            if (isset($seenFlags[$flagName])) {
                throw new RuntimeException("Duplicate flag '--{$flagName}' is rejected.");
            }

            $seenFlags[$flagName] = true;
            $flagValues[$flagName] = $flagValue;
            $i++;
        }

        foreach ($allowedFlags as $reqFlag) {
            if (!isset($flagValues[$reqFlag]) || $flagValues[$reqFlag] === '') {
                throw new RuntimeException("Missing required flag: '--{$reqFlag}'");
            }
        }

        $rawB64 = $flagValues['installed-context'];
        if (strlen($rawB64) > self::MAX_CONTEXT_JSON_BYTES * 2) {
            throw new RuntimeException("Installed context parameter exceeds allowed size limit.");
        }

        $decodedJson = base64_decode($rawB64, true);
        if ($decodedJson === false || base64_encode($decodedJson) !== $rawB64) {
            throw new RuntimeException("Installed context is not valid strict base64.");
        }

        if (strlen($decodedJson) > self::MAX_CONTEXT_JSON_BYTES) {
            throw new RuntimeException("Decoded installed context JSON exceeds allowed size limit.");
        }

        try {
            $context = json_decode($decodedJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException("Installed context is not valid JSON.");
        }

        if (!is_array($context)) {
            throw new RuntimeException("Installed context must decode to an object or associative array.");
        }

        return [
            'product_root' => $flagValues['product-root'],
            'state_base' => $flagValues['state-base'],
            'snapshot_id' => $flagValues['snapshot-id'],
            'snapshot_sha256' => $flagValues['snapshot-sha256'],
            'installed_context' => $context,
        ];
    }
}
