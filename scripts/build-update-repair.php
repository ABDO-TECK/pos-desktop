<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/vendor/autoload.php';

// Argument parsing & strict unknown option rejection
$allowedOptions = [
    '--baseline-inventory',
    '--baseline-shell-root',
    '--output',
    '--target-version',
    '--assets-dir',
    '--allow-local-test',
];

$output = 'release-assets';
$outputGiven = false;
$baselineInventoryPath = null;
$baselineShellRoot = null;
$assetsDir = null;
$targetVersionOpt = null;
$allowLocalTest = false;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--')) {
        $prefix = explode('=', $arg, 2)[0];
        if (!in_array($prefix, $allowedOptions, true)) {
            throw new RuntimeException("Unknown argument: {$arg}");
        }
        if ($prefix === '--allow-local-test') {
            $allowLocalTest = true;
        } elseif ($prefix === '--baseline-inventory') {
            if (str_contains($arg, '=')) {
                $baselineInventoryPath = substr($arg, strlen('--baseline-inventory='));
            } elseif (isset($argv[$i + 1])) {
                $baselineInventoryPath = $argv[++$i];
            } else {
                throw new RuntimeException("Missing value for {$arg}");
            }
        } elseif ($prefix === '--baseline-shell-root') {
            if (str_contains($arg, '=')) {
                $baselineShellRoot = substr($arg, strlen('--baseline-shell-root='));
            } elseif (isset($argv[$i + 1])) {
                $baselineShellRoot = $argv[++$i];
            } else {
                throw new RuntimeException("Missing value for {$arg}");
            }
        } elseif ($prefix === '--assets-dir') {
            if (str_contains($arg, '=')) {
                $assetsDir = substr($arg, strlen('--assets-dir='));
            } elseif (isset($argv[$i + 1])) {
                $assetsDir = $argv[++$i];
            } else {
                throw new RuntimeException("Missing value for {$arg}");
            }
        } elseif ($prefix === '--target-version') {
            if (str_contains($arg, '=')) {
                $targetVersionOpt = substr($arg, strlen('--target-version='));
            } elseif (isset($argv[$i + 1])) {
                $targetVersionOpt = $argv[++$i];
            } else {
                throw new RuntimeException("Missing value for {$arg}");
            }
        } elseif ($prefix === '--output') {
            if (str_contains($arg, '=')) {
                $output = substr($arg, strlen('--output='));
            } elseif (isset($argv[$i + 1])) {
                $output = $argv[++$i];
            } else {
                throw new RuntimeException("Missing value for {$arg}");
            }
            $outputGiven = true;
        }
    } else {
        if (!$outputGiven) {
            $output = $arg;
            $outputGiven = true;
        } else {
            throw new RuntimeException("Unexpected positional argument: {$arg}");
        }
    }
}

// 1. Environment & repository checks
$isGha = (getenv('GITHUB_ACTIONS') === 'true');
if (!$isGha && !$allowLocalTest) {
    throw new RuntimeException('build-update-repair.php must run in GitHub Actions (GITHUB_ACTIONS=true)');
}
$repo = getenv('GITHUB_REPOSITORY');
if ($isGha && $repo && !in_array($repo, ['ABDO-TECK/pos-desktop-updates', 'ABDO-TECK/pos-desktop'], true)) {
    throw new RuntimeException("Unexpected GITHUB_REPOSITORY: {$repo}");
}

if (!is_dir($output)) {
    mkdir($output, 0755, true);
}

// 2. Private key check: UPDATE_PRIVATE_KEY secret ONLY builder step
$key = getenv('UPDATE_PRIVATE_KEY');
if (!$key) {
    throw new RuntimeException('Signing key required (UPDATE_PRIVATE_KEY)');
}
$private = openssl_pkey_get_private($key);
if (!$private) {
    throw new RuntimeException('Invalid signing key');
}
$public = openssl_pkey_get_details($private);
$pinnedPath = __DIR__ . '/../backend/certs/update_public_key.pem';
if (!is_file($pinnedPath)) {
    throw new RuntimeException('Pinned public key missing at backend/certs/update_public_key.pem');
}
$pinned = (string)file_get_contents($pinnedPath);
$pinnedDetails = openssl_pkey_get_details(openssl_pkey_get_public($pinned));
if (!$pinnedDetails || ($public['key'] !== $pinnedDetails['key'])) {
    throw new RuntimeException('Repair signing key does not match pinned updater key');
}

// 3. Target version determination:
$versionJsonPath = __DIR__ . '/../version.json';
if (!is_file($versionJsonPath)) {
    throw new RuntimeException('version.json missing');
}
$versionData = json_decode((string)file_get_contents($versionJsonPath), true, 512, JSON_THROW_ON_ERROR);
$sourceVersion = (string)($versionData['version'] ?? '');
if ($sourceVersion !== '0.0.12-beta.1') {
    throw new RuntimeException("version.json version must be '0.0.12-beta.1', found: '{$sourceVersion}'");
}
$sourceChannel = (string)($versionData['channel'] ?? '');
if ($sourceChannel !== 'beta') {
    throw new RuntimeException("version.json channel must be 'beta', found: '{$sourceChannel}'");
}
$sourceInstallerVersion = (string)($versionData['installer_version'] ?? '0.0.11');
if ($sourceInstallerVersion !== '0.0.11') {
    throw new RuntimeException("version.json installer_version must be '0.0.11', found: '{$sourceInstallerVersion}'");
}

$targetVersion = '0.0.12-beta.1';
if ($targetVersionOpt !== null && $targetVersionOpt !== $targetVersion) {
    throw new RuntimeException("--target-version '{$targetVersionOpt}' does not match approved Beta 12 ('{$targetVersion}')");
}
$targetChannel = 'beta';

// 4. Baseline inventory validation:
if (!$baselineInventoryPath || !is_file($baselineInventoryPath)) {
    throw new RuntimeException("Baseline inventory file missing: {$baselineInventoryPath}");
}
$actualInvHash = strtolower((string)hash_file('sha256', $baselineInventoryPath));
$expectedInvHash = '88475c625b47d0e60bba21ceb52923b8ab5e0b6eb1f57cfd4f495e7e1924ced2';
if (!hash_equals($expectedInvHash, $actualInvHash)) {
    throw new RuntimeException("Baseline inventory hash mismatch! Expected {$expectedInvHash}, got {$actualInvHash}");
}
$baselineInv = json_decode((string)file_get_contents($baselineInventoryPath), true, 512, JSON_THROW_ON_ERROR);
if (($baselineInv['version'] ?? '') !== '0.0.11') {
    throw new RuntimeException("Baseline inventory version must be 0.0.11, found: '" . ($baselineInv['version'] ?? '') . "'");
}
$baselinePharEntry = $baselineInv['files']['backend/backend.phar'] ?? null;
if (!$baselinePharEntry || empty($baselinePharEntry['sha256']) || empty($baselinePharEntry['size'])) {
    throw new RuntimeException('Missing or invalid backend/backend.phar entry in baseline inventory');
}
$baselinePharHash = strtolower((string)$baselinePharEntry['sha256']);
$baselinePharSize = (int)$baselinePharEntry['size'];

// 5. Baseline shell root validation:
if (!$baselineShellRoot || !is_dir($baselineShellRoot)) {
    throw new RuntimeException("Baseline shell root directory missing: {$baselineShellRoot}");
}
$baselineShellRoot = rtrim(str_replace('\\', '/', (string)realpath($baselineShellRoot)), '/');

// Verify lab baseline PHAR matches baseline inventory before policy
$labPharPath = $baselineShellRoot . '/resources/app.asar.unpacked/backend/backend.phar';
if (!is_file($labPharPath)) {
    throw new RuntimeException("Lab baseline PHAR missing at: {$labPharPath}");
}
$actualLabPharHash = strtolower((string)hash_file('sha256', $labPharPath));
$actualLabPharSize = filesize($labPharPath);
if ($actualLabPharSize !== $baselinePharSize || !hash_equals($baselinePharHash, $actualLabPharHash)) {
    throw new RuntimeException("Lab baseline PHAR mismatch against baseline inventory: expected {$baselinePharHash} ({$baselinePharSize}B), got {$actualLabPharHash} ({$actualLabPharSize}B)");
}

// 6. Derive public immutable native binary hashes from baseline shell root
// POS Desktop.exe (1), resources/app.asar (1), resources/portable/php/php.exe (1), and 321 portable binaries = 323 total
$nativeHashes = [];
$desktopExe = $baselineShellRoot . '/POS Desktop.exe';
if (!is_file($desktopExe)) {
    throw new RuntimeException("POS Desktop.exe missing at: {$desktopExe}");
}
$nativeHashes['POS Desktop.exe'] = strtolower((string)hash_file('sha256', $desktopExe));

$appAsar = $baselineShellRoot . '/resources/app.asar';
if (!is_file($appAsar)) {
    throw new RuntimeException("resources/app.asar missing at: {$appAsar}");
}
$nativeHashes['resources/app.asar'] = strtolower((string)hash_file('sha256', $appAsar));

$phpExe = $baselineShellRoot . '/resources/portable/php/php.exe';
if (!is_file($phpExe)) {
    throw new RuntimeException("resources/portable/php/php.exe missing at: {$phpExe}");
}

$portableDir = $baselineShellRoot . '/resources/portable';
if (!is_dir($portableDir)) {
    throw new RuntimeException("resources/portable missing at: {$portableDir}");
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($portableDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO)
);
$portableBinaryCount = 0;
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) continue;
    $ext = strtolower($fileInfo->getExtension());
    if (in_array($ext, ['exe', 'dll', 'jar'], true)) {
        $filePath = str_replace('\\', '/', $fileInfo->getPathname());
        // Strict negative filter: never include private keys, certs, DBs, envs, or credentials
        if (preg_match('/(^|\/)(?:\.env|private[_-]?key|credentials?|secrets?|cert|qz-certs?|ownership|pos_schema\.sql)(?:\.|\/|$)/i', $filePath)) {
            continue;
        }
        $rel = ltrim(substr($filePath, strlen($baselineShellRoot)), '/');
        $nativeHashes[$rel] = strtolower((string)hash_file('sha256', $filePath));
        $portableBinaryCount++;
    }
}

// Also check resources/*.jar
$resourcesDir = $baselineShellRoot . '/resources';
if (is_dir($resourcesDir)) {
    foreach (scandir($resourcesDir) ?: [] as $entry) {
        if (str_ends_with(strtolower($entry), '.jar') && is_file($resourcesDir . '/' . $entry)) {
            $nativeHashes['resources/' . $entry] = strtolower((string)hash_file('sha256', $resourcesDir . '/' . $entry));
            $portableBinaryCount++;
        }
    }
}

// Ensure PHP extension DLLs are pinned
$requiredExtensions = ['php_openssl.dll', 'php_curl.dll', 'php_mbstring.dll', 'php_zip.dll'];
foreach ($requiredExtensions as $reqExt) {
    $found = false;
    foreach (array_keys($nativeHashes) as $pinnedRel) {
        if (str_ends_with(strtolower($pinnedRel), '/' . strtolower($reqExt))) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        throw new RuntimeException("Required portable PHP extension DLL missing from native pins: {$reqExt}");
    }
}

$totalNativeCount = count($nativeHashes);
if ($totalNativeCount < 323) {
    throw new RuntimeException("Insufficient native pins derived from baseline shell root: found {$totalNativeCount}, expected at least 323 (321 portable + POS Desktop.exe + app.asar)");
}

// 7. Check offline signed delta assets and Beta target inventory
$candidateDirs = array_unique(array_filter([$assetsDir, $output, 'release-assets']));
$manifestName = 'manifest-0.0.11.json';
$sigName = 'manifest-0.0.11.sig';
$deltaName = 'delta-0.0.11-to-' . $targetVersion . '.zip';

$resolvedAssetsDir = null;
foreach ($candidateDirs as $dir) {
    if (is_file($dir . '/' . $manifestName) && is_file($dir . '/' . $sigName) && is_file($dir . '/' . $deltaName)) {
        $resolvedAssetsDir = $dir;
        break;
    }
}

if (!$resolvedAssetsDir) {
    throw new RuntimeException("Signed delta assets missing in candidate dirs (" . implode(', ', $candidateDirs) . "): expected {$manifestName}, {$sigName}, {$deltaName}");
}

$manifestFile = $resolvedAssetsDir . '/' . $manifestName;
$sigFile = $resolvedAssetsDir . '/' . $sigName;
$deltaFile = $resolvedAssetsDir . '/' . $deltaName;

// Verify manifest with pinned key before including
$manifestBytes = (string)file_get_contents($manifestFile);
$sigBytes = base64_decode(trim((string)file_get_contents($sigFile)));
if (openssl_verify($manifestBytes, $sigBytes, $pinned, OPENSSL_ALGO_SHA256) !== 1) {
    throw new RuntimeException('Delta manifest RSA signature rejected against pinned public key');
}
$manifestData = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
if (($manifestData['type'] ?? '') !== 'delta') {
    throw new RuntimeException("Manifest type must be delta, got: '" . ($manifestData['type'] ?? '') . "'");
}
if (($manifestData['from_version'] ?? '') !== '0.0.11') {
    throw new RuntimeException("Manifest from_version must be 0.0.11, got: '" . ($manifestData['from_version'] ?? '') . "'");
}
if (($manifestData['version'] ?? '') !== $targetVersion) {
    throw new RuntimeException("Manifest version must match target {$targetVersion}, got: '" . ($manifestData['version'] ?? '') . "'");
}
if (!empty($manifestData['migrations'])) {
    throw new RuntimeException('Repair requires a delta without migrations');
}

// Target inventory MUST exist and match target version 0.0.12-beta.1
$targetInvPath = $resolvedAssetsDir . '/deployment-inventory.json';
if (!is_file($targetInvPath)) {
    throw new RuntimeException("Beta target deployment-inventory.json missing in {$resolvedAssetsDir}");
}
$targetInv = json_decode((string)file_get_contents($targetInvPath), true, 512, JSON_THROW_ON_ERROR);
if (($targetInv['version'] ?? '') !== $targetVersion) {
    throw new RuntimeException("Target inventory version must be {$targetVersion}, found '" . ($targetInv['version'] ?? '') . "'");
}
if (!is_array($targetInv['files'] ?? null) || empty($targetInv['files'])) {
    throw new RuntimeException('Target inventory files array is empty or missing');
}

// Map public managed target files (including version.json, backend/backend.phar, frontend/dist/**, cacert.pem, pos_schema.sql)
$publicTargetInventory = [];
foreach ($targetInv['files'] as $p => $fInfo) {
    $pNorm = str_replace('\\', '/', (string)$p);
    // Disallow generic private certs/credentials/keys/secrets/.env
    if (preg_match('#(^|/)(?:\.env|private[_-]?key|credentials?|secrets?|.*\.key|.*\.crt)(/|$)#i', $pNorm)) {
        continue;
    }
    // Allow public artifacts: version.json, backend.phar, frontend/dist/**, exact cacert.pem, exact pos_schema.sql
    $isAllowedPublic = (
        $pNorm === 'version.json' ||
        $pNorm === 'backend/backend.phar' ||
        str_starts_with($pNorm, 'frontend/dist/') ||
        $pNorm === 'backend/certs/cacert.pem' ||
        $pNorm === 'database/pos_schema.sql'
    );
    if (!$isAllowedPublic) {
        continue;
    }
    $publicTargetInventory[$pNorm] = [
        'sha256' => strtolower((string)($fInfo['sha256'] ?? '')),
        'size' => (int)($fInfo['size'] ?? 0),
    ];
}

if (!isset($publicTargetInventory['version.json']) || !isset($publicTargetInventory['backend/backend.phar'])) {
    throw new RuntimeException('target_inventory missing required public artifacts (version.json and backend/backend.phar)');
}

$targetInvHash = strtolower((string)hash_file('sha256', $targetInvPath));

// 8. Prepare signed engine configuration
$repairConfig = [
    'tool_version' => '1.0.0',
    'installer_version' => '0.0.11',
    'baseline_version' => '0.0.11',
    'target_version' => $targetVersion,
    'target_channel' => $targetChannel,
    'baseline_inventory_sha256' => $expectedInvHash,
    'baseline_phar' => [
        'sha256' => $baselinePharHash,
        'size' => $baselinePharSize,
    ],
    'allowed_paths' => [
        'version.json',
        'backend/backend.phar',
        'frontend/dist/*',
    ],
    'native_hashes' => $nativeHashes,
    'target_inventory_sha256' => $targetInvHash,
    'target_inventory' => $publicTargetInventory,
];

$configJson = json_encode($repairConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

// 9. Package repair-engine.zip
$work = sys_get_temp_dir() . '/repair-build-' . bin2hex(random_bytes(8));
mkdir($work, 0755, true);

$zip = new ZipArchive();
if ($zip->open($work . '/repair-engine.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create repair-engine.zip');
}
$zip->addFile(__DIR__ . '/update-repair/repair.php', 'repair.php');
$zip->addFile(__DIR__ . '/../backend/Services/UpdateAssetDownloader.php', 'UpdateAssetDownloader.php');
$zip->addFromString('update_public_key.pem', $pinned);
$zip->addFromString('repair-config.json', $configJson);
$zip->close();

// Sign repair-engine.zip with UPDATE_PRIVATE_KEY
$engineZipBytes = (string)file_get_contents($work . '/repair-engine.zip');
$engineSig = '';
openssl_sign($engineZipBytes, $engineSig, $private, OPENSSL_ALGO_SHA256);
file_put_contents($work . '/repair-engine.sig', base64_encode($engineSig));

// 10. Prepare launcher scripts and documentation
$xml = '<RSAKeyValue><Modulus>' . base64_encode($public['rsa']['n']) . '</Modulus><Exponent>' . base64_encode($public['rsa']['e']) . '</Exponent></RSAKeyValue>';
$launcherContent = file_get_contents(__DIR__ . '/update-repair/Repair-POS.ps1');
if ($launcherContent === false) {
    throw new RuntimeException('Launcher script template missing');
}
file_put_contents($work . '/Repair-POS.ps1', str_replace('@PUBLIC_KEY_XML@', $xml, $launcherContent));

$cmdTemplate = __DIR__ . '/update-repair/Repair-POS.cmd';
if (is_file($cmdTemplate)) {
    file_put_contents($work . '/Repair-POS.cmd', file_get_contents($cmdTemplate));
} else {
    file_put_contents($work . '/Repair-POS.cmd', "@echo off\r\nsetlocal\r\npowershell.exe -NoProfile -ExecutionPolicy Bypass -File \"%~dp0Repair-POS.ps1\" %*\r\nexit /b %ERRORLEVEL%\r\n");
}

$readmeText = <<<README
POS Desktop one-time signed update repair to {$targetVersion}

Close POS Desktop and all its services completely before running repair.
Extract this ZIP archive.

Run in PowerShell:
  powershell -NoProfile -ExecutionPolicy Bypass -File .\\Repair-POS.ps1

Or double-click Repair-POS.cmd.

For an alternative install folder, specify:
  powershell -NoProfile -ExecutionPolicy Bypass -File .\\Repair-POS.ps1 -InstallRoot "C:\\path\\to\\POS Desktop"

Do not uninstall POS Desktop or delete your database. This repair tool does not run migrations.
The launcher verifies the RSA-signed repair engine against the pinned updater public key before execution.
The launcher strictly verifies public native binary hashes (POS Desktop.exe, app.asar, portable runtime) before spawning PHP.
The engine verifies the signed delta manifest and exact installed 0.0.11 baseline files before replacing changed artifacts.
Offline repair assets are embedded in the assets/ directory; no internet connection is required.
Recovery files and backup snapshots are preserved in the printed TEMP directory for inspection.
Reopen POS Desktop after repair completes successfully.
If you were previously on the Stable channel, you can select Beta in POS Desktop settings after reopening.
Download only from official ABDO-TECK/pos-desktop-updates releases.
This repair uses RSA payload verification; it is not a Windows Authenticode signature.

README;
file_put_contents($work . '/README.txt', $readmeText);

// 11. Assemble self-contained outer ZIP: POS-Update-Repair-0.0.12-beta.1.zip
$outerZipPath = $output . '/POS-Update-Repair-' . $targetVersion . '.zip';
$outer = new ZipArchive();
if ($outer->open($outerZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException("Cannot create outer ZIP at {$outerZipPath}");
}
$outer->addFile($work . '/repair-engine.zip', 'repair-engine.zip');
$outer->addFile($work . '/repair-engine.sig', 'repair-engine.sig');
$outer->addFile($work . '/Repair-POS.ps1', 'Repair-POS.ps1');
$outer->addFile($work . '/Repair-POS.cmd', 'Repair-POS.cmd');
$outer->addFile($work . '/README.txt', 'README.txt');

// Embedded assets for zero-network execution
$outer->addFile($manifestFile, 'assets/' . $manifestName);
$outer->addFile($sigFile, 'assets/' . $sigName);
$outer->addFile($deltaFile, 'assets/' . $deltaName);
$outer->close();

echo "Built signed repair tool: {$outerZipPath}\n";
