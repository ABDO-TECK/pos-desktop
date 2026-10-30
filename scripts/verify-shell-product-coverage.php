<?php
declare(strict_types=1);

/**
 * Packaged Product Physical Coverage Probe
 *
 * Runs strictly in GitHub Actions (GITHUB_ACTIONS=true).
 * Independently enumerates and classifies all physical regular files in an as-built
 * Windows development package directory (dist-electron/win-unpacked),
 * identifies vendor bootstrap SQL files under portable/mysql/share,
 * reports protected user-state files without reading contents,
 * prints a deterministic sorted audit report,
 * and attempts ProductInventoryService::buildInventory to uncover allowlist coverage gaps.
 */

// GHA-only execution guard BEFORE any filesystem, crypto, or process operations
if (getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "[BLOCKED] Verification probe runs strictly in GitHub Actions environment (GITHUB_ACTIONS=true).\n");
    exit(1);
}

// Autoload backend dependencies
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

use App\Services\ProductInventoryService;

// Maximum bounds enforced
const MAX_DISCOVERED_FILES = 20000;
const MAX_UNSUPPORTED_DISPLAY_LIMIT = 2000;

// Parse CLI options
$rootOption = null;
$opts = getopt('', ['root:']);
if (isset($opts['root']) && is_string($opts['root']) && trim($opts['root']) !== '') {
    $rootOption = trim($opts['root']);
} else {
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--root' && isset($argv[$i + 1])) {
            $rootOption = trim($argv[$i + 1]);
            break;
        }
        if (str_starts_with($argv[$i], '--root=')) {
            $rootOption = trim(substr($argv[$i], strlen('--root=')));
            break;
        }
    }
}

if ($rootOption === null || $rootOption === '') {
    fwrite(STDERR, "[ERROR] Missing required --root option.\n");
    fwrite(STDERR, "Usage: php scripts/verify-shell-product-coverage.php --root <path-to-win-unpacked>\n");
    exit(1);
}

// Convert relative path to absolute Windows drive path via explicit join with getcwd()
$workingDir = getcwd();
if ($workingDir === false) {
    fwrite(STDERR, "[ERROR] Failed to determine current working directory.\n");
    exit(1);
}

$rawPath = $rootOption;
if (!preg_match('/^[A-Za-z]:[\\\\\/]/', $rawPath)) {
    $rawPath = rtrim(str_replace('/', '\\', $workingDir), '\\') . '\\' . str_replace('/', '\\', $rawPath);
}

try {
    $canonicalRoot = ProductInventoryService::resolveAbsolutePath($rawPath, true);
} catch (\Throwable $e) {
    fwrite(STDERR, "[ERROR] Failed to resolve root path: " . $e->getMessage() . "\n");
    exit(1);
}

if (!is_dir($canonicalRoot)) {
    fwrite(STDERR, "[ERROR] Resolved canonical root is not a directory: {$canonicalRoot}\n");
    exit(1);
}

// Read native version from package.json if present
$packageJsonPath = __DIR__ . '/../package.json';
$nativeVersion = '0.0.13';
if (is_file($packageJsonPath)) {
    $pkgContent = @file_get_contents($packageJsonPath);
    if ($pkgContent !== false) {
        $pkgData = json_decode($pkgContent, true);
        if (is_array($pkgData) && isset($pkgData['version']) && is_string($pkgData['version'])) {
            $nativeVersion = $pkgData['version'];
        }
    }
}

/**
 * Classify a relative path into one of the 8 independent intended categories.
 */
function classifyPath(string $rel): string
{
    if (str_starts_with($rel, 'resources/app.asar.unpacked/')) {
        return 'app unpacked';
    }
    if (str_starts_with($rel, 'resources/portable/php/') || str_starts_with($rel, 'portable/php/')) {
        return 'portable/php';
    }
    if (str_starts_with($rel, 'resources/portable/mysql/') || str_starts_with($rel, 'portable/mysql/')) {
        return 'mysql';
    }
    if (str_starts_with($rel, 'resources/portable/java/') || str_starts_with($rel, 'portable/java/')) {
        return 'java';
    }
    if (str_starts_with($rel, 'resources/portable/qz-tray/') || str_starts_with($rel, 'portable/qz-tray/')) {
        return 'qz-tray';
    }
    if (str_starts_with($rel, 'resources/portable/prerequisites/') || str_starts_with($rel, 'portable/prerequisites/')) {
        return 'prerequisites';
    }
    if (!str_contains($rel, '/') || str_starts_with($rel, 'locales/') || $rel === 'resources/app.asar') {
        return 'Electron/root';
    }
    return 'other';
}

/**
 * Identify vendor bootstrap SQL files specifically located under portable/mysql/share.
 * Arbitrary .sql files elsewhere (e.g. pos_schema.sql or user databases) are NOT vendor bootstrap SQL.
 */
function isVendorSqlPath(string $rel): bool
{
    return (bool) preg_match('#^(?:resources/)?portable/mysql/share/.*\.sql$#i', $rel);
}

/**
 * Identify executable and native binary / script files.
 */
function isExecutablePath(string $rel): bool
{
    return (bool) preg_match('/\.(?:exe|dll|node|ps1|cmd|bat|jar)$/i', $rel);
}

/**
 * Identify protected user-state directories to skip before descent.
 */
function isProtectedDirectory(string $rel): bool
{
    $normalized = str_replace('\\', '/', trim($rel));
    $lower = strtolower($normalized);

    if (str_contains($lower, 'digital-certificate') || str_contains($lower, 'devicecert') || str_contains($lower, 'device-cert') || str_contains($lower, 'device_cert')) {
        return true;
    }
    if (preg_match('/(^|\/)\.env/i', $normalized)) {
        return true;
    }
    if (preg_match('/(^|\/)\.git(\/|$)/i', $normalized) || preg_match('/^\.github(\/|$)/i', $normalized)) {
        return true;
    }
    if (preg_match('/(^|\/)(?:storage|logs|cache|caches|gpucache|userdata|temp|tmp)(\/|$)/i', $normalized)) {
        return true;
    }
    if (preg_match('/(^|\/)mysql\/data(\/|$)/i', $normalized)) {
        return true;
    }
    return false;
}

/**
 * Classify protected files into subcategories without touching file contents.
 */
function classifyProtectedFile(string $rel): string
{
    if (isVendorSqlPath($rel)) {
        return 'Vendor Bootstrap SQL (misclassified as protected)';
    }
    if (preg_match('/\.sql$/i', $rel)) {
        return 'Customer / Application SQL';
    }
    $lower = strtolower($rel);
    if (str_contains($lower, 'digital-certificate') || str_contains($lower, 'devicecert') || str_contains($lower, 'device-cert') || str_contains($lower, 'device_cert')) {
        return 'Device / Printing Certificates';
    }
    if (preg_match('/(^|\/)[^\/]*private[^\/]*\.(?:pem|key)$/i', $rel) || preg_match('/(^|\/)[^\/]*privatekey[^\/]*$/i', $rel)) {
        return 'Private Keys / Credentials';
    }
    if (preg_match('/(^|\/)\.env/i', $rel)) {
        return 'Environment Configuration';
    }
    if (preg_match('/\.(?:sqlite|sqlite3|db|db3|pid|dump|bak)$/i', $rel)) {
        return 'Database / State Storage';
    }
    if (preg_match('/\.(?:log|tmp)$/i', $rel)) {
        return 'Logs / Transient Files';
    }
    return 'Other Protected File';
}

echo "================================================================================\n";
echo "PACKAGED PRODUCT PHYSICAL COVERAGE PROBE (AS-BUILT AUDIT)\n";
echo "================================================================================\n";
echo "Target Root:     {$canonicalRoot}\n";
echo "Native Version:  {$nativeVersion}\n";
echo "Execution Mode:  Development Product Coverage Probe (Windows x64 unpacked)\n";
echo "Timestamp:       " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "================================================================================\n\n";

// Independent physical enumeration
$discoveredFiles = [];
$skippedProtectedDirs = [];
$seenLower = [];
$dirsScanned = 0;
$queue = [''];

while (!empty($queue)) {
    $currentRelDir = array_shift($queue);
    $dirsScanned++;
    $currentAbsDir = $currentRelDir === ''
        ? $canonicalRoot
        : $canonicalRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $currentRelDir);

    $entries = @scandir($currentAbsDir);
    if ($entries === false) {
        fwrite(STDERR, "[ERROR] Failed to read directory: {$currentAbsDir}\n");
        exit(1);
    }

    // Deterministic traversal order
    sort($entries, SORT_STRING);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $relEntry = $currentRelDir === '' ? $entry : $currentRelDir . '/' . $entry;
        $absEntry = $currentAbsDir . DIRECTORY_SEPARATOR . $entry;

        // Reparse point / symlink / junction detection (no FOLLOW_SYMLINKS)
        if (is_link($absEntry)) {
            fwrite(STDERR, "[ERROR] Reparse point or symbolic link rejected: {$relEntry}\n");
            exit(1);
        }
        $lstat = @lstat($absEntry);
        if ($lstat === false) {
            fwrite(STDERR, "[ERROR] Failed to stat entry: {$relEntry}\n");
            exit(1);
        }
        if (($lstat['mode'] & 0120000) === 0120000) {
            fwrite(STDERR, "[ERROR] Reparse point or symbolic link mode rejected: {$relEntry}\n");
            exit(1);
        }

        // Confinement and ancestor chain verification using approved API
        try {
            ProductInventoryService::resolveConfinedPath($canonicalRoot, $relEntry, true);
        } catch (\Throwable $e) {
            fwrite(STDERR, "[ERROR] Confinement violation for entry '{$relEntry}': " . $e->getMessage() . "\n");
            exit(1);
        }

        // Global case collision check
        $lowerRel = strtolower($relEntry);
        if (isset($seenLower[$lowerRel])) {
            fwrite(STDERR, "[ERROR] Case collision detected between '{$seenLower[$lowerRel]}' and '{$relEntry}'\n");
            exit(1);
        }
        $seenLower[$lowerRel] = $relEntry;

        if (is_dir($absEntry)) {
            // Protected user-state directories skipped before reading/descent
            if (isProtectedDirectory($relEntry)) {
                $skippedProtectedDirs[] = $relEntry;
                continue;
            }
            $queue[] = $relEntry;
        } elseif (is_file($absEntry)) {
            $currentCount = count($discoveredFiles);
            if ($currentCount >= MAX_DISCOVERED_FILES) {
                fwrite(STDERR, "[ERROR] Exceeded maximum file bound of " . MAX_DISCOVERED_FILES . " files.\n");
                exit(1);
            }

            $size = @filesize($absEntry);
            if ($size === false || $size < 0) {
                fwrite(STDERR, "[ERROR] Failed to read file size: {$relEntry}\n");
                exit(1);
            }

            $category = classifyPath($relEntry);
            $isVendorSql = isVendorSqlPath($relEntry);
            $isProtected = ProductInventoryService::isProtectedPath($relEntry);
            $validation = ProductInventoryService::validateProductPath($relEntry);
            $isExecutable = isExecutablePath($relEntry);

            $discoveredFiles[$relEntry] = [
                'path' => $relEntry,
                'abs_path' => $absEntry,
                'size' => $size,
                'category' => $category,
                'is_vendor_sql' => $isVendorSql,
                'is_protected' => $isProtected,
                'validation_ok' => $validation['ok'],
                'reason_code' => $validation['reason_code'],
                'is_executable' => $isExecutable,
            ];
        } else {
            fwrite(STDERR, "[ERROR] Unsupported filesystem entry type rejected: {$relEntry}\n");
            exit(1);
        }
    }
}

// Sort discovered files deterministically by canonical relative path
ksort($discoveredFiles, SORT_STRING);
$totalFiles = count($discoveredFiles);

echo "Total Regular Files Discovered:  {$totalFiles}\n";
echo "Total Directories Scanned:       {$dirsScanned}\n";
echo "Protected Directories Skipped:   " . count($skippedProtectedDirs) . "\n";
if (!empty($skippedProtectedDirs)) {
    foreach ($skippedProtectedDirs as $skippedDir) {
        echo "  - [SKIPPED DIR] {$skippedDir}\n";
    }
}
echo "\n";

// Aggregate counts by category and by extension
$countsByCategory = [
    'Electron/root' => 0,
    'app unpacked' => 0,
    'portable/php' => 0,
    'mysql' => 0,
    'java' => 0,
    'qz-tray' => 0,
    'prerequisites' => 0,
    'other' => 0,
];
$countsByExtension = [];
$executablePaths = [];
$vendorSqlPaths = [];
$protectedFiles = [];
$unsupportedPaths = [];

foreach ($discoveredFiles as $rel => $fileInfo) {
    $cat = $fileInfo['category'];
    if (isset($countsByCategory[$cat])) {
        $countsByCategory[$cat]++;
    } else {
        $countsByCategory['other']++;
    }

    $ext = pathinfo($rel, PATHINFO_EXTENSION);
    $extKey = $ext !== '' ? '.' . strtolower($ext) : '(none)';
    $countsByExtension[$extKey] = ($countsByExtension[$extKey] ?? 0) + 1;

    if ($fileInfo['is_executable']) {
        $executablePaths[] = $rel;
    }

    if ($fileInfo['is_vendor_sql']) {
        $vendorSqlPaths[] = $rel;
    }

    if ($fileInfo['is_protected']) {
        $protectedFiles[$rel] = $fileInfo;
    }

    if (!$fileInfo['validation_ok']) {
        $unsupportedPaths[$rel] = $fileInfo;
    }
}

ksort($countsByExtension, SORT_STRING);
sort($executablePaths, SORT_STRING);
sort($vendorSqlPaths, SORT_STRING);

// Report 1: Regular file counts per category
echo "--- 1. Regular File Counts by Intended Category ---\n";
foreach ($countsByCategory as $category => $count) {
    $pct = $totalFiles > 0 ? sprintf('%.1f%%', ($count / $totalFiles) * 100) : '0.0%';
    printf("  %-16s: %6d  (%s)\n", $category, $count, $pct);
}
printf("  %-16s: %6d\n\n", 'TOTAL', $totalFiles);

// Report 2: Regular file counts per extension
echo "--- 2. Regular File Counts by Extension ---\n";
foreach ($countsByExtension as $extension => $count) {
    printf("  %-16s: %6d\n", $extension, $count);
}
echo "\n";

// Report 3: Finite executable relative paths
echo "--- 3. Finite Executable Relative Paths (Total: " . count($executablePaths) . ") ---\n";
foreach ($executablePaths as $exePath) {
    $info = $discoveredFiles[$exePath];
    $status = $info['validation_ok'] ? 'ok' : ($info['reason_code'] ?? 'unauthorized');
    printf("  %-14s %-6s %10d bytes  %s\n", "[{$info['category']}]", "[{$status}]", $info['size'], $exePath);
}
echo "\n";

// Report 4: Vendor SQL relative paths under portable/mysql/share
echo "--- 4. Vendor Bootstrap SQL Paths under portable/mysql/share (Total: " . count($vendorSqlPaths) . ") ---\n";
if (empty($vendorSqlPaths)) {
    echo "  (No vendor bootstrap SQL files discovered under portable/mysql/share)\n";
} else {
    foreach ($vendorSqlPaths as $sqlPath) {
        $info = $discoveredFiles[$sqlPath];
        $isProtectedTag = $info['is_protected'] ? '[MISCLASSIFIED VENDOR SQL: protected]' : '[unprotected]';
        $status = $info['validation_ok'] ? 'ok' : ($info['reason_code'] ?? 'unauthorized');
        printf("  %-38s %-6s %10d bytes  %s\n", $isProtectedTag, "[{$status}]", $info['size'], $sqlPath);
    }
}
echo "\n";

// Report 5: Protected-file categories and counts (WITHOUT contents)
echo "--- 5. Protected User-State & Guarded Files (Total: " . count($protectedFiles) . ") ---\n";
$protectedSubcategories = [];
foreach ($protectedFiles as $pPath => $pInfo) {
    $subcat = classifyProtectedFile($pPath);
    $protectedSubcategories[$subcat][] = $pPath;
}
ksort($protectedSubcategories, SORT_STRING);

foreach ($protectedSubcategories as $subcat => $pathsInSubcat) {
    printf("  %-50s: %4d file(s)\n", $subcat, count($pathsInSubcat));
    sort($pathsInSubcat, SORT_STRING);
    foreach ($pathsInSubcat as $p) {
        printf("    - %s (%d bytes)\n", $p, $discoveredFiles[$p]['size']);
    }
}
if (empty($protectedFiles)) {
    echo "  (No protected files encountered in packaged product tree)\n";
}
echo "\n";

// Report 6: Every unsupported physical path (bounded at 2000, fatal if exceeded)
$unsupportedCount = count($unsupportedPaths);
$fatalTruncation = false;
echo "--- 6. Unsupported Physical Paths (Total: {$unsupportedCount}) ---\n";

$displaySlice = array_slice($unsupportedPaths, 0, MAX_UNSUPPORTED_DISPLAY_LIMIT, true);
foreach ($displaySlice as $uPath => $uInfo) {
    $reason = $uInfo['reason_code'] ?? 'unauthorized';
    $cat = $uInfo['category'];
    $vendorSqlMarker = $uInfo['is_vendor_sql'] ? ' [VENDOR SQL]' : '';
    printf("  [%-17s] (%-14s) %s%s\n", $reason, $cat, $uPath, $vendorSqlMarker);
}

if ($unsupportedCount > MAX_UNSUPPORTED_DISPLAY_LIMIT) {
    $fatalTruncation = true;
    echo "\n[FATAL TRUNCATION] Total unsupported physical paths ({$unsupportedCount}) exceeds display limit of " . MAX_UNSUPPORTED_DISPLAY_LIMIT . ".\n";
    echo "Truncation is fatal to prevent silent partial reporting.\n";
}
echo "\n";

// Summary of unsupported paths by category and reason
if ($unsupportedCount > 0) {
    echo "--- Unsupported Paths Breakdown by Category and Reason ---\n";
    $breakdown = [];
    foreach ($unsupportedPaths as $uInfo) {
        $key = sprintf("%-14s / %s", $uInfo['category'], $uInfo['reason_code'] ?? 'unknown');
        $breakdown[$key] = ($breakdown[$key] ?? 0) + 1;
    }
    ksort($breakdown, SORT_STRING);
    foreach ($breakdown as $catReason => $bCount) {
        printf("  %-32s: %5d\n", $catReason, $bCount);
    }
    echo "\n";
}

// Section 7: Attempt approved service buildInventory
echo "================================================================================\n";
echo "SERVICE INVENTORY ATTEMPT (ProductInventoryService::buildInventory)\n";
echo "================================================================================\n";

$inventory = null;
$buildInventoryException = null;

try {
    $service = new ProductInventoryService();
    $inventory = $service->buildInventory($canonicalRoot, $nativeVersion);
    echo "[SERVICE RESULT] buildInventory succeeded with " . count($inventory['files'] ?? []) . " files.\n";
} catch (\Throwable $e) {
    $buildInventoryException = $e;
    echo "[SERVICE RESULT] buildInventory threw exception: " . $e->getMessage() . "\n";
}
echo "\n";

// Section 8: Coverage Diagnostic Evaluation
echo "================================================================================\n";
echo "COVERAGE DIAGNOSTIC EVALUATION\n";
echo "================================================================================\n";

$gaps = [];

// Check A: Vendor bootstrap SQL misclassification
$misclassifiedVendorSql = [];
foreach ($vendorSqlPaths as $sqlPath) {
    if ($discoveredFiles[$sqlPath]['is_protected']) {
        $misclassifiedVendorSql[] = $sqlPath;
    }
}
if (!empty($misclassifiedVendorSql)) {
    $gaps[] = sprintf(
        "Vendor bootstrap SQL (%d files) under portable/mysql/share is misclassified as protected user-state by generic .sql rule.",
        count($misclassifiedVendorSql)
    );
}

// Check B: Required as-built nonprotected files unauthorized
$unauthorizedNonProtected = [];
foreach ($discoveredFiles as $path => $info) {
    if (!$info['is_protected'] && !$info['validation_ok']) {
        $unauthorizedNonProtected[] = $path;
    }
}
if (!empty($unauthorizedNonProtected)) {
    $gaps[] = sprintf(
        "Required as-built nonprotected physical files (%d files) unauthorized by current ProductInventoryService allowlist.",
        count($unauthorizedNonProtected)
    );
}

// Check C: Service buildInventory execution failure
if ($buildInventoryException !== null) {
    $gaps[] = "Service buildInventory failed: " . $buildInventoryException->getMessage();
}

// Check D: Inventory omissions or discrepancies (if buildInventory succeeded)
if ($inventory !== null) {
    $inventoryFiles = $inventory['files'] ?? [];

    $missingFromInventory = [];
    foreach ($discoveredFiles as $path => $info) {
        if (!$info['is_protected'] && !isset($inventoryFiles[$path])) {
            $missingFromInventory[] = $path;
        }
    }
    if (!empty($missingFromInventory)) {
        $gaps[] = sprintf("Inventory omits %d independently discovered physical files.", count($missingFromInventory));
    }

    $extraInInventory = [];
    foreach (array_keys($inventoryFiles) as $path) {
        if (!isset($discoveredFiles[$path])) {
            $extraInInventory[] = $path;
        }
    }
    if (!empty($extraInInventory)) {
        $gaps[] = sprintf("Inventory contains %d paths not present in physical filesystem.", count($extraInInventory));
    }

    // Validate inventory format and structure
    $validationCheck = $service->validateInventory($inventory);
    if (!$validationCheck['ok']) {
        $gaps[] = "Inventory failed validateInventory check: " . ($validationCheck['reason_code'] ?? 'unknown');
    }
}

// Check E: Fatal truncation
if ($fatalTruncation) {
    $gaps[] = "Fatal truncation occurred: unsupported physical paths exceeded " . MAX_UNSUPPORTED_DISPLAY_LIMIT;
}

if (!empty($gaps)) {
    echo "[STATUS] COVERAGE GAPS DETECTED (" . count($gaps) . " gap condition(s) proven):\n\n";
    foreach ($gaps as $idx => $gap) {
        echo "  " . ($idx + 1) . ". {$gap}\n";
    }

    echo "\nDiagnostic Evidence Summary:\n";
    echo "  - Total as-built physical regular files:       {$totalFiles}\n";
    echo "  - Total unauthorized physical paths:          {$unsupportedCount}\n";
    echo "  - Misclassified vendor bootstrap SQL files:   " . count($misclassifiedVendorSql) . "\n";
    echo "  - Unauthorized nonprotected physical files:   " . count($unauthorizedNonProtected) . "\n";
    echo "  - Service buildInventory status:              " . ($buildInventoryException !== null ? "FAILED (expected)" : "SUCCEEDED") . "\n";
    echo "\nNOTE: Initial actual GHA execution is EXPECTED to fail current allowlist and provide this complete evidence.\n";
    echo "This discovery output is the necessary foundation for subsequent bounded allowlist corrections.\n";
    echo "================================================================================\n";
    echo "[PROBE RESULT] PACKAGED PRODUCT COVERAGE FAILED (Expected discovery outcome).\n";
    exit(1);
}

// Verification passed only if all files covered and inventory matches
echo "[STATUS] COVERAGE CHECK PASSED: All as-built physical files covered by allowlist.\n";
echo "  - Total verified physical files: {$totalFiles}\n";
echo "  - Inventory file count: " . count($inventory['files'] ?? []) . "\n";
echo "  - User state strictly protected.\n";
echo "================================================================================\n";
echo "[PROBE RESULT] PACKAGED PRODUCT COVERAGE PASSED.\n";
exit(0);
