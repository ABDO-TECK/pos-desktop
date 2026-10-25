<?php
declare(strict_types=1);

// CLI-only supervisor trust verifier for Setup 0.0.11 one-time repair bootstrap.
// Verifies Candidate 0.0.12 backend PHAR against signed manifest using ONLY original Setup 11 trust anchor.
// Never executes candidate code. Returns public verified context JSON.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

function failVerifier(string $code, string $message): never
{
    $payload = [
        'verified' => false,
        'error_code' => $code,
        'message' => $message,
    ];
    fwrite(STDERR, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

// 1. Strict CI & Repository Ownership Guards
if (getenv('GITHUB_ACTIONS') !== 'true' || !getenv('RUNNER_TEMP')) {
    failVerifier('ERR_EPHEMERAL_RUNNER_REQUIRED', 'Ephemeral Windows GitHub Actions runner required.');
}

if (getenv('GITHUB_REPOSITORY') !== 'ABDO-TECK/pos-desktop-updates') {
    failVerifier('ERR_OWNERSHIP_INVALID_REPO', 'Repair verifier requires GITHUB_REPOSITORY === ABDO-TECK/pos-desktop-updates.');
}

$rawRunnerTemp = realpath((string)getenv('RUNNER_TEMP'));
if ($rawRunnerTemp === false || !is_dir($rawRunnerTemp)) {
    failVerifier('ERR_INVALID_RUNNER_TEMP', 'RUNNER_TEMP is invalid or missing.');
}
$runnerTemp = rtrim(str_replace('\\', '/', $rawRunnerTemp), '/');

// Exact owned lab directory
$expectedLabRaw = $rawRunnerTemp . DIRECTORY_SEPARATOR . 'setup11-channel-transition-lab';
$realLab = realpath($expectedLabRaw);
if ($realLab === false || !is_dir($realLab)) {
    failVerifier('ERR_LAB_NOT_FOUND', "Lab directory missing: {$expectedLabRaw}");
}
$labBoundary = rtrim(str_replace('\\', '/', $realLab), '/');
if (!str_starts_with(strtolower($labBoundary) . '/', strtolower($runnerTemp) . '/')) {
    failVerifier('ERR_LAB_OUTSIDE_RUNNER_TEMP', "Lab directory escapes RUNNER_TEMP: {$labBoundary}");
}

// Existing owned-lab.json marker check
$ownedLabMarker = $labBoundary . '/owned-lab.json';
if (!is_file($ownedLabMarker)) {
    failVerifier('ERR_OWNED_LAB_MARKER_MISSING', "Owned lab marker missing: {$ownedLabMarker}");
}
$ownedLabData = json_decode((string)file_get_contents($ownedLabMarker), true);
if (!is_array($ownedLabData) || empty($ownedLabData['application']) || ($ownedLabData['version'] ?? '') !== '0.0.11') {
    failVerifier('ERR_OWNED_LAB_MARKER_INVALID', 'Owned lab marker data is invalid or version is not 0.0.11.');
}

$expectedAppRaw = $labBoundary . '/app';
$realExpectedApp = realpath($expectedAppRaw);
if ($realExpectedApp === false || !is_dir($realExpectedApp)) {
    failVerifier('ERR_APP_DIR_MISSING', "Expected application directory missing: {$expectedAppRaw}");
}
$expectedApp = rtrim(str_replace('\\', '/', $realExpectedApp), '/');

$realOwnedApp = realpath((string)$ownedLabData['application']);
if ($realOwnedApp === false || rtrim(str_replace('\\', '/', $realOwnedApp), '/') !== $expectedApp) {
    failVerifier('ERR_OWNED_LAB_MARKER_MISMATCH', 'Owned lab marker application does not match expected lab/app.');
}

// Safe lab path validation: walk RAW path ancestors BEFORE realpath, then verify strict descendant of labBoundary
function assertSafeLabPath(string $path, string $labBoundary, bool $mustExist = true): string
{
    // Walk raw path ancestors before canonicalization for reparse point / symlink
    $normalizedSeparators = str_replace('\\', '/', $path);
    $segments = explode('/', $normalizedSeparators);
    $cursor = '';
    if (preg_match('#^[a-zA-Z]:#', $segments[0])) {
        $cursor = array_shift($segments);
    }
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') {
            $cursor = dirname($cursor);
            continue;
        }
        $cursor .= '/' . $segment;
        if (file_exists($cursor) && is_link($cursor)) {
            failVerifier('ERR_SYMLINK_REJECTED', "Reparse point / symlink rejected in raw path: {$cursor}");
        }
    }

    if ($mustExist) {
        $real = realpath($path);
        if ($real === false) {
            failVerifier('ERR_PATH_NOT_FOUND', "Path does not exist: {$path}");
        }
        $norm = rtrim(str_replace('\\', '/', $real), '/');
    } else {
        $parent = dirname($path);
        $realParent = realpath($parent);
        if ($realParent === false) {
            failVerifier('ERR_PARENT_NOT_FOUND', "Parent directory does not exist: {$parent}");
        }
        $norm = rtrim(str_replace('\\', '/', $realParent), '/') . '/' . basename($path);
    }

    if (!str_starts_with(strtolower($norm), strtolower($labBoundary) . '/')) {
        failVerifier('ERR_OUTSIDE_OWNED_PATH', "Path escapes owned lab boundary ({$labBoundary}): {$norm}");
    }

    $anc = $norm;
    while ($anc && $anc !== $labBoundary && $anc !== dirname($anc)) {
        if (file_exists($anc) && is_link($anc)) {
            failVerifier('ERR_SYMLINK_REJECTED', "Reparse point / symlink rejected: {$anc}");
        }
        $anc = dirname($anc);
    }

    return $norm;
}

$shortopts = '';
$longopts = [
    'application:',
    'candidate-phar:',
    'manifest:',
    'signature:',
    'job-id:',
    'candidate-inventory::',
];
$options = getopt($shortopts, $longopts);

$appArg = (string)($options['application'] ?? '');
$candidatePharArg = (string)($options['candidate-phar'] ?? '');
$manifestArg = (string)($options['manifest'] ?? '');
$signatureArg = (string)($options['signature'] ?? '');
$jobIdArg = (string)($options['job-id'] ?? '');
$candidateInvArg = isset($options['candidate-inventory']) ? (string)$options['candidate-inventory'] : '';

if ($appArg === '' || $candidatePharArg === '' || $manifestArg === '' || $signatureArg === '' || $jobIdArg === '') {
    failVerifier('ERR_MISSING_REQUIRED_OPTIONS', 'Missing required verifier options (--application, --candidate-phar, --manifest, --signature, --job-id).');
}

// Strict digits regex for job-id: reject '6foo', non-positive, etc.
if (!preg_match('/^\d+$/', $jobIdArg) || (int)$jobIdArg <= 0) {
    failVerifier('ERR_INVALID_JOB_ID', "Target job id must be a positive integer, got: {$jobIdArg}");
}
$jobId = (int)$jobIdArg;

$appDir = assertSafeLabPath($appArg, $labBoundary, true);
if ($appDir !== $expectedApp) {
    failVerifier('ERR_APPLICATION_ARG_MISMATCH', "Application argument must match owned lab/app ({$expectedApp}), got: {$appDir}");
}

$candidatePharPath = assertSafeLabPath($candidatePharArg, $labBoundary, true);
$manifestPath = assertSafeLabPath($manifestArg, $labBoundary, true);
$signaturePath = assertSafeLabPath($signatureArg, $labBoundary, true);

// Unpacked root
$appUnpacked = $appDir . '/resources/app.asar.unpacked';
if (!is_dir($appUnpacked)) {
    failVerifier('ERR_APP_UNPACKED_MISSING', "Unpacked application root missing: {$appUnpacked}");
}
$appUnpacked = assertSafeLabPath($appUnpacked, $labBoundary, true);

// 1. Verify source on-disk version is EXACTLY 0.0.11
$versionJsonPath = $appUnpacked . '/version.json';
if (!is_file($versionJsonPath)) {
    failVerifier('ERR_SOURCE_VERSION_MISSING', "Source version.json not found at: {$versionJsonPath}");
}
$sourceVersionData = json_decode((string)file_get_contents($versionJsonPath), true);
if (!is_array($sourceVersionData)) {
    failVerifier('ERR_SOURCE_VERSION_INVALID', 'Source version.json is not valid JSON.');
}
$onDiskVersion = (string)($sourceVersionData['version'] ?? '');
if ($onDiskVersion !== '0.0.11') {
    failVerifier('ERR_WRONG_SOURCE_VERSION', "Source version on disk must be exactly 0.0.11, found: '{$onDiskVersion}'.");
}

// 2. Discover original frozen Setup 11 backend.phar and verify its SHA256 against setup11-inventory.json BEFORE requiring autoload
$origPharPath = $appUnpacked . '/backend/backend.phar';
if (!is_file($origPharPath)) {
    failVerifier('ERR_ORIGINAL_PHAR_MISSING', "Original Setup 11 backend.phar missing at: {$origPharPath}");
}

$setup11InventoryFile = $labBoundary . '/setup11-inventory.json';
if (!is_file($setup11InventoryFile)) {
    failVerifier('ERR_SETUP11_INVENTORY_MISSING', "setup11-inventory.json missing in lab: {$setup11InventoryFile}");
}
$setup11Inv = json_decode((string)file_get_contents($setup11InventoryFile), true);
if (!is_array($setup11Inv) || ($setup11Inv['version'] ?? '') !== '0.0.11') {
    failVerifier('ERR_SETUP11_INVENTORY_INVALID', 'setup11-inventory.json invalid or version mismatch.');
}
$expectedOldPharHash = strtolower((string)($setup11Inv['files']['backend/backend.phar']['sha256'] ?? ''));
if ($expectedOldPharHash === '' || !preg_match('/^[a-f0-9]{64}$/', $expectedOldPharHash)) {
    failVerifier('ERR_SETUP11_INVENTORY_PHAR_HASH_MISSING', 'setup11-inventory.json missing valid SHA256 for backend/backend.phar.');
}
$actualOldPharHash = strtolower((string)hash_file('sha256', $origPharPath));
if (!hash_equals($expectedOldPharHash, $actualOldPharHash)) {
    failVerifier('ERR_ORIGINAL_PHAR_TAMPERED', "Original Setup 11 backend.phar hash mismatch against setup11-inventory.json baseline.");
}

$origPharStream = 'phar://' . str_replace('\\', '/', $origPharPath);
$origAutoload = $origPharStream . '/vendor/autoload.php';
if (!is_file($origAutoload)) {
    failVerifier('ERR_ORIGINAL_AUTOLOAD_MISSING', "Original Setup 11 autoloader missing inside phar: {$origAutoload}");
}
require_once $origAutoload;

// 3. Locate and load public key strictly from original Setup 11 frozen trust anchor
$origKeyInPhar = $origPharStream . '/certs/update_public_key.pem';
$origKeyOnDisk = $appUnpacked . '/backend/certs/update_public_key.pem';
$pubKeyContent = null;
if (is_file($origKeyInPhar)) {
    $pubKeyContent = (string)file_get_contents($origKeyInPhar);
} elseif (is_file($origKeyOnDisk)) {
    // If external fallback, EXPLICITLY hash match owned Setup 11 baseline file entry (no external repo key fallback)
    $expectedKeyHash = strtolower((string)($setup11Inv['files']['backend/certs/update_public_key.pem']['sha256'] ?? ''));
    $actualKeyHash = strtolower((string)hash_file('sha256', $origKeyOnDisk));
    if ($expectedKeyHash === '' || !hash_equals($expectedKeyHash, $actualKeyHash)) {
        failVerifier('ERR_TRUST_ANCHOR_TAMPERED', 'External update_public_key.pem does not match setup11-inventory.json baseline hash.');
    }
    $pubKeyContent = (string)file_get_contents($origKeyOnDisk);
}
if (!$pubKeyContent || trim($pubKeyContent) === '') {
    failVerifier('ERR_TRUST_ANCHOR_MISSING', 'Original Setup 11 pinned public key not found or empty.');
}

$pubKeyResource = openssl_pkey_get_public($pubKeyContent);
if (!$pubKeyResource) {
    failVerifier('ERR_TRUST_ANCHOR_INVALID', 'Original Setup 11 public key is not a valid OpenSSL RSA public key.');
}

// 4. Verify signed bootstrap manifest using original frozen ManifestSignatureService
$rawManifest = (string)file_get_contents($manifestPath);
$rawSignature = (string)file_get_contents($signaturePath);

$sigService = new \App\Services\ManifestSignatureService();
if (!$sigService->verifySignature($rawManifest, $rawSignature, $pubKeyContent)) {
    failVerifier('ERR_SIGNATURE_INVALID', 'Bootstrap manifest digital signature rejected against original Setup 11 public key.');
}

// 5. Decode and validate manifest baseline structure via original UpdateManifestService
$manifestData = json_decode($rawManifest, true);
if (!is_array($manifestData)) {
    failVerifier('ERR_MANIFEST_INVALID_JSON', 'Bootstrap manifest content is not valid JSON.');
}

if (($manifestData['version'] ?? '') !== '0.0.12') {
    failVerifier('ERR_WRONG_TARGET_VERSION', "Manifest target version must be 0.0.12, got: '" . ($manifestData['version'] ?? '') . "'.");
}
if (($manifestData['from_version'] ?? '') !== '0.0.11') {
    failVerifier('ERR_WRONG_SOURCE_VERSION', "Manifest from_version must be 0.0.11, got: '" . ($manifestData['from_version'] ?? '') . "'.");
}
if (($manifestData['type'] ?? '') !== 'delta') {
    failVerifier('ERR_MANIFEST_TYPE_NOT_DELTA', "Manifest type must be delta, got: '" . ($manifestData['type'] ?? '') . "'.");
}

$manifestValidator = new \App\Services\UpdateManifestService();
$validation = $manifestValidator->validateManifest($manifestData);
if (!$validation['valid']) {
    failVerifier('ERR_MANIFEST_SCHEMA_INVALID', 'Manifest validation failed: ' . implode('; ', $validation['errors']));
}

// 6. Ensure unique file entry for backend/backend.phar in signed manifest
$pharEntries = array_values(array_filter(
    $manifestData['files'] ?? [],
    static fn(array $entry): bool => ($entry['path'] ?? '') === 'backend/backend.phar'
));
if (count($pharEntries) !== 1) {
    failVerifier('ERR_MANIFEST_PHAR_ENTRY_NOT_UNIQUE', 'Manifest must contain exactly one entry for backend/backend.phar (found ' . count($pharEntries) . ').');
}
$expectedEntry = $pharEntries[0];
$expectedPharHash = strtolower((string)($expectedEntry['sha256'] ?? ''));
$expectedPharSize = (int)($expectedEntry['size'] ?? 0);

if (!preg_match('/^[a-f0-9]{64}$/', $expectedPharHash) || $expectedPharSize <= 0) {
    failVerifier('ERR_MANIFEST_PHAR_ENTRY_INVALID', 'Manifest backend/backend.phar entry missing valid 64-hex hash or positive size.');
}

// 7. Validate candidate PHAR on disk BEFORE executing any candidate code
$actualPharSize = filesize($candidatePharPath);
$actualPharHash = strtolower((string)hash_file('sha256', $candidatePharPath));

if ($actualPharSize !== $expectedPharSize || !hash_equals($expectedPharHash, $actualPharHash)) {
    failVerifier('ERR_PHAR_HASH_MISMATCH', "Candidate backend.phar size or SHA256 mismatch against signed manifest. Expected {$expectedPharHash} ({$expectedPharSize}B), got {$actualPharHash} ({$actualPharSize}B).");
}

// 8. Optionally verify candidate inventory if provided
if ($candidateInvArg !== '') {
    $candidateInvPath = assertSafeLabPath($candidateInvArg, $labBoundary, true);
    $candInv = json_decode((string)file_get_contents($candidateInvPath), true);
    if (!is_array($candInv) || ($candInv['version'] ?? '') !== '0.0.12') {
        failVerifier('ERR_CANDIDATE_INVENTORY_INVALID', "Candidate deployment inventory version must be 0.0.12 (found: " . ($candInv['version'] ?? '') . ").");
    }
    $invBackendHash = strtolower((string)($candInv['files']['backend/backend.phar']['sha256'] ?? ''));
    if (!hash_equals($actualPharHash, $invBackendHash)) {
        failVerifier('ERR_CANDIDATE_INVENTORY_HASH_MISMATCH', "Candidate inventory backend.phar SHA256 mismatch against candidate phar.");
    }
}

// 9. Output public verified context JSON (never output secrets or private keys)
$manifestHash = strtolower((string)hash('sha256', $rawManifest));
$verifiedContext = [
    'verified' => true,
    'candidate_backend_sha256' => $actualPharHash,
    'candidate_phar_path' => $candidatePharPath,
    'manifest_sha256' => $manifestHash,
    'from_version' => '0.0.11',
    'to_version' => '0.0.12',
    'target_job_id' => $jobId,
    'application_root' => $appUnpacked,
    'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'owned_paths' => [
        $appUnpacked,
        $candidatePharPath,
        $manifestPath,
    ],
];

echo json_encode($verifiedContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(0);
