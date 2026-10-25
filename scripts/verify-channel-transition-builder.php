<?php
declare(strict_types=1);
require_once __DIR__.'/../backend/vendor/autoload.php';
require_once __DIR__.'/build-channel-transition.php';

use App\Services\{
    ChannelTransitionManifestService,
    ManifestSignatureService,
    UpdateDataCompatibilityService,
    UpdateManifestService
};

function builderAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Builder test failed: ' . $message);
    }
}

$lab = sys_get_temp_dir() . '/pos-builder-test-' . bin2hex(random_bytes(6));
mkdir($lab, 0700, true);

$sourceRoot = $lab . '/source';
$targetRoot = $lab . '/target';
$shellRoot = $lab . '/shell';
$output = $lab . '/output';
mkdir($sourceRoot . '/backend', 0700, true);
mkdir($sourceRoot . '/frontend/dist', 0700, true);
mkdir($targetRoot . '/backend', 0700, true);
mkdir($targetRoot . '/frontend/dist', 0700, true);
mkdir($shellRoot . '/resources', 0700, true);
mkdir($output, 0700, true);

file_put_contents($shellRoot . '/POS Desktop.exe', 'fixture shell binary');
file_put_contents($shellRoot . '/resources/app.asar', 'fixture shell asar');

// Generate ephemeral test key pair
$rsaKey = openssl_pkey_new(['private_key_bits' => 2048]);
$keyDetails = openssl_pkey_get_details($rsaKey);
$publicKeyPath = $lab . '/public.pem';
file_put_contents($publicKeyPath, $keyDetails['key']);
openssl_pkey_export($rsaKey, $privateKeyPem);

$sigService = new ManifestSignatureService($publicKeyPath);
$verifier = new ChannelTransitionManifestService($sigService);
$manifestPolicy = new UpdateManifestService();
$builder = new ChannelTransitionBuilder($sigService, $verifier, $manifestPolicy);

// Setup contract
$contractData = [
    'format' => 1,
    'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
    'schema_sha256' => str_repeat('a', 64),
    'ledger_sha256' => str_repeat('b', 64),
];
$contractPath = $lab . '/contract.json';
file_put_contents($contractPath, json_encode($contractData, JSON_PRETTY_PRINT));

// Setup source files: v0.0.11 (stable)
file_put_contents($sourceRoot . '/version.json', json_encode(['version' => '0.0.11']));
file_put_contents($sourceRoot . '/backend/backend.phar', 'source phar 0.0.11');
file_put_contents($sourceRoot . '/frontend/dist/app.js', 'console.log("0.0.11");');
$sourceFiles = [
    'version.json' => ['sha256' => hash_file('sha256', $sourceRoot . '/version.json'), 'size' => filesize($sourceRoot . '/version.json')],
    'backend/backend.phar' => ['sha256' => hash_file('sha256', $sourceRoot . '/backend/backend.phar'), 'size' => filesize($sourceRoot . '/backend/backend.phar')],
    'frontend/dist/app.js' => ['sha256' => hash_file('sha256', $sourceRoot . '/frontend/dist/app.js'), 'size' => filesize($sourceRoot . '/frontend/dist/app.js')],
];
$sourceInventory = [
    'format' => 1,
    'version' => '0.0.11',
    'shell_sha256' => str_repeat('c', 64),
    'files' => $sourceFiles,
];
$sourceInvPath = $lab . '/source-inventory.json';
file_put_contents($sourceInvPath, json_encode($sourceInventory, JSON_PRETTY_PRINT));

// Setup target files: v0.0.10-beta.10 (beta)
file_put_contents($targetRoot . '/version.json', json_encode(['version' => '0.0.10-beta.10']));
file_put_contents($targetRoot . '/backend/backend.phar', 'target phar 0.0.10-beta.10');
file_put_contents($targetRoot . '/frontend/dist/app.js', 'console.log("0.0.10-beta.10");');
$targetFiles = [
    'version.json' => ['sha256' => hash_file('sha256', $targetRoot . '/version.json'), 'size' => filesize($targetRoot . '/version.json')],
    'backend/backend.phar' => ['sha256' => hash_file('sha256', $targetRoot . '/backend/backend.phar'), 'size' => filesize($targetRoot . '/backend/backend.phar')],
    'frontend/dist/app.js' => ['sha256' => hash_file('sha256', $targetRoot . '/frontend/dist/app.js'), 'size' => filesize($targetRoot . '/frontend/dist/app.js')],
];
$targetInventory = [
    'format' => 1,
    'version' => '0.0.10-beta.10',
    'shell_sha256' => str_repeat('c', 64),
    'files' => $targetFiles,
];
$targetInvPath = $lab . '/target-inventory.json';
file_put_contents($targetInvPath, json_encode($targetInventory, JSON_PRETTY_PRINT));

// =========================================================================
// TEST 1: Positive legacy CLI/codepath compatibility without touching real repo
// =========================================================================
$legacySourceRoot = $lab . '/legacy-source';
$legacyTargetRoot = $lab . '/legacy-target';
$legacyOutput = $lab . '/legacy-output';
mkdir($legacySourceRoot . '/backend', 0700, true);
mkdir($legacyTargetRoot . '/backend', 0700, true);
mkdir($legacyOutput, 0700, true);

file_put_contents($legacySourceRoot . '/version.json', json_encode(['version' => '0.0.10-beta.5']));
file_put_contents($legacySourceRoot . '/backend/backend.phar', 'legacy source phar beta.5');
$legacySourceFiles = [
    'version.json' => ['sha256' => hash_file('sha256', $legacySourceRoot . '/version.json'), 'size' => filesize($legacySourceRoot . '/version.json')],
    'backend/backend.phar' => ['sha256' => hash_file('sha256', $legacySourceRoot . '/backend/backend.phar'), 'size' => filesize($legacySourceRoot . '/backend/backend.phar')],
];
$legacySourceInv = ['format' => 1, 'version' => '0.0.10-beta.5', 'shell_sha256' => str_repeat('c', 64), 'files' => $legacySourceFiles];
file_put_contents($legacyOutput . '/deployment-inventory.json', json_encode($legacySourceInv));

file_put_contents($legacyTargetRoot . '/version.json', json_encode(['version' => '0.0.1']));
file_put_contents($legacyTargetRoot . '/backend/backend.phar', 'legacy target phar 0.0.1');
$legacyTargetFiles = [
    'version.json' => ['sha256' => hash_file('sha256', $legacyTargetRoot . '/version.json'), 'size' => filesize($legacyTargetRoot . '/version.json')],
    'backend/backend.phar' => ['sha256' => hash_file('sha256', $legacyTargetRoot . '/backend/backend.phar'), 'size' => filesize($legacyTargetRoot . '/backend/backend.phar')],
];
$legacyTargetInv = ['format' => 1, 'version' => '0.0.1', 'shell_sha256' => str_repeat('c', 64), 'files' => $legacyTargetFiles];
$legacyTargetInvPath = $lab . '/legacy-target-inv.json';
file_put_contents($legacyTargetInvPath, json_encode($legacyTargetInv));

$legacyBuilder = new ChannelTransitionBuilder($sigService, $verifier, $manifestPolicy, $legacySourceRoot);
$legacyRes = $legacyBuilder->build([
    'target-root' => $legacyTargetRoot,
    'shell-root' => $shellRoot,
    'target-inventory' => $legacyTargetInvPath,
    'contract' => $contractPath,
    'output' => $legacyOutput,
], $privateKeyPem);
builderAssert($legacyRes['ok'] && count($legacyRes['entries']) === 2, 'Legacy codepath must produce delta and full transitions');
builderAssert(str_contains($legacyRes['message'], 'PASS signed beta carrier'), 'Legacy build message must match');
$legacyIndexRaw = file_get_contents($legacyOutput . '/channel-transitions.json');
$legacyIndexSig = file_get_contents($legacyOutput . '/channel-transitions.sig');
$legacyCheck = $verifier->verifyIndex($legacyIndexRaw, $legacyIndexSig, ['tag_name' => 'v0.0.10-beta.5', 'draft' => false]);
builderAssert($legacyCheck['ok'], 'Legacy index must verify against carrier tag v0.0.10-beta.5');

// =========================================================================
// TEST 2: Generic mode build (stable 0.0.11 to beta 0.0.10-beta.10)
// =========================================================================
$optionsGeneric = [
    'source-root' => $sourceRoot,
    'source-inventory' => $sourceInvPath,
    'target-root' => $targetRoot,
    'target-inventory' => $targetInvPath,
    'carrier-tag' => 'v0.0.10-beta.10',
    'target-repo' => 'pos-desktop-updates',
    'target-tag' => 'v0.0.10-beta.10',
    'shell-root' => $shellRoot,
    'contract' => $contractPath,
    'output' => $output,
];

$resGeneric = $builder->build($optionsGeneric, $privateKeyPem);
builderAssert($resGeneric['ok'] && count($resGeneric['entries']) === 2, 'Generic build must produce 2 entries (delta and full)');
builderAssert(is_file($output . '/channel-transitions.json'), 'channel-transitions.json must exist');
builderAssert(is_file($output . '/channel-transitions.sig'), 'channel-transitions.sig must exist');

$indexRaw = file_get_contents($output . '/channel-transitions.json');
$indexSig = file_get_contents($output . '/channel-transitions.sig');
$checkedIndex = $verifier->verifyIndex($indexRaw, $indexSig, ['tag_name' => 'v0.0.10-beta.10', 'draft' => false]);
builderAssert($checkedIndex['ok'], 'Built transition index must pass cryptographic verification');

// =========================================================================
// TEST 3: Generic reverse beta -> newer stable in isolated output directory
// =========================================================================
$reverseOutput = $lab . '/reverse-output';
mkdir($reverseOutput, 0700, true);
$optionsReverse = [
    'source-root' => $targetRoot,
    'source-inventory' => $targetInvPath,
    'target-root' => $sourceRoot,
    'target-inventory' => $sourceInvPath,
    'carrier-tag' => 'v0.0.11',
    'target-repo' => 'pos-desktop-updates',
    'target-tag' => 'v0.0.11',
    'shell-root' => $shellRoot,
    'contract' => $contractPath,
    'output' => $reverseOutput,
];
$resReverse = $builder->build($optionsReverse, $privateKeyPem);
builderAssert($resReverse['ok'] && count($resReverse['entries']) === 2, 'Reverse transition build must succeed');
$reverseIndexRaw = file_get_contents($reverseOutput . '/channel-transitions.json');
$reverseIndexSig = file_get_contents($reverseOutput . '/channel-transitions.sig');
builderAssert($verifier->verifyIndex($reverseIndexRaw, $reverseIndexSig, ['tag_name' => 'v0.0.11', 'draft' => false])['ok'], 'Reverse index must verify');

// =========================================================================
// TEST 4: Explicit append of DIFFERENT pair preserving old entry bytes/signatures
// =========================================================================
// Setup a 3rd distinct version: v0.0.12 (stable)
$v12Root = $lab . '/v12';
mkdir($v12Root . '/backend', 0700, true);
file_put_contents($v12Root . '/version.json', json_encode(['version' => '0.0.12']));
file_put_contents($v12Root . '/backend/backend.phar', 'phar 0.0.12');
$v12Files = [
    'version.json' => ['sha256' => hash_file('sha256', $v12Root . '/version.json'), 'size' => filesize($v12Root . '/version.json')],
    'backend/backend.phar' => ['sha256' => hash_file('sha256', $v12Root . '/backend/backend.phar'), 'size' => filesize($v12Root . '/backend/backend.phar')],
];
$v12Inv = ['format' => 1, 'version' => '0.0.12', 'shell_sha256' => str_repeat('c', 64), 'files' => $v12Files];
$v12InvPath = $lab . '/v12-inventory.json';
file_put_contents($v12InvPath, json_encode($v12Inv));

// Record file hashes before append
$beforeHashes = [];
foreach (glob($output . '/*') as $f) {
    if (is_file($f)) $beforeHashes[basename($f)] = hash_file('sha256', $f);
}

$appendOptions = [
    'source-root' => $v12Root,
    'source-inventory' => $v12InvPath,
    'target-root' => $targetRoot,
    'target-inventory' => $targetInvPath,
    'carrier-tag' => 'v0.0.10-beta.10',
    'target-repo' => 'pos-desktop-updates',
    'target-tag' => 'v0.0.10-beta.10',
    'shell-root' => $shellRoot,
    'contract' => $contractPath,
    'output' => $output,
    'append' => true,
];

$resAppend = $builder->build($appendOptions, $privateKeyPem);
builderAssert($resAppend['ok'] && count($resAppend['entries']) === 4, 'Append mode must produce 4 total entries (2 old + 2 new)');

// Assert old entries' files (.zip, .json, .sig) retained EXACT bytes
foreach ($beforeHashes as $name => $hash) {
    if ($name === 'channel-transitions.json' || $name === 'channel-transitions.sig') continue;
    builderAssert(hash_file('sha256', $output . '/' . $name) === $hash, "Appended build must not modify preexisting artifact bytes: {$name}");
}

// Assert appended index verifies cryptographically
$appendedIndexRaw = file_get_contents($output . '/channel-transitions.json');
$appendedIndexSig = file_get_contents($output . '/channel-transitions.sig');
builderAssert($verifier->verifyIndex($appendedIndexRaw, $appendedIndexSig, ['tag_name' => 'v0.0.10-beta.10', 'draft' => false])['ok'], 'Appended index must verify');

// =========================================================================
// TEST 5: Refusal does not change ANY existing output hashes
// =========================================================================
$hashesBeforeRefusal = [];
foreach (glob($output . '/*') as $f) {
    if (is_file($f)) $hashesBeforeRefusal[basename($f)] = hash_file('sha256', $f);
}

$refusedRebuild = false;
try {
    // Rebuild without --append must refuse
    $builder->build($optionsGeneric, $privateKeyPem);
} catch (RuntimeException $e) {
    $refusedRebuild = true;
    builderAssert(str_contains($e->getMessage(), 'already exists'), 'Must refuse overwrite');
}
builderAssert($refusedRebuild, 'Rebuild without append must be refused');

$hashesAfterRefusal = [];
foreach (glob($output . '/*') as $f) {
    if (is_file($f)) $hashesAfterRefusal[basename($f)] = hash_file('sha256', $f);
}
builderAssert($hashesBeforeRefusal === $hashesAfterRefusal, 'Refusal must NOT modify ANY existing output file hashes');

// =========================================================================
// TEST 6: Duplicate entry rejection in append mode
// =========================================================================
$refusedDup = false;
try {
    $builder->build($appendOptions, $privateKeyPem);
} catch (RuntimeException $e) {
    $refusedDup = true;
    builderAssert(str_contains($e->getMessage(), 'already exists'), 'Must reject duplicate entries in index');
}
builderAssert($refusedDup, 'Append mode must reject duplicate transition');

// =========================================================================
// TEST 7: Corrupt or unsigned existing index rejection in append mode
// =========================================================================
$savedSig = file_get_contents($output . '/channel-transitions.sig');
file_put_contents($output . '/channel-transitions.sig', 'corrupt_signature');
$refusedCorrupt = false;
try {
    $builder->build([...$appendOptions, 'source-root' => $sourceRoot, 'source-inventory' => $sourceInvPath], $privateKeyPem);
} catch (RuntimeException $e) {
    $refusedCorrupt = true;
    builderAssert(str_contains($e->getMessage(), 'verification failed'), 'Must reject append to corrupt/unsigned index');
}
builderAssert($refusedCorrupt, 'Corrupt existing index must be refused');
file_put_contents($output . '/channel-transitions.sig', $savedSig);

// =========================================================================
// TEST 8: Generic mode missing explicit inputs must fail before mutations
// =========================================================================
$missingInput = false;
try {
    $builder->build([
        'target-root' => $targetRoot,
        'target-inventory' => $targetInvPath,
        'carrier-tag' => 'v0.0.10-beta.10',
        'target-repo' => 'pos-desktop-updates',
        'shell-root' => $shellRoot,
        'contract' => $contractPath,
        'output' => $output,
    ], $privateKeyPem);
} catch (RuntimeException $e) {
    $missingInput = true;
    builderAssert(str_contains($e->getMessage(), 'Generic transition mode requires explicit'), 'Generic mode must require all explicit flags');
}
builderAssert($missingInput, 'Generic mode without explicit source flags must fail');

// =========================================================================
// TEST 9: Invalid contract failure creates ZERO outputs on fresh directory
// =========================================================================
$freshContractLab = $lab . '/fresh-contract-output';
mkdir($freshContractLab, 0700, true);
$badContractPath = $lab . '/bad-contract.json';
file_put_contents($badContractPath, json_encode(['format' => 2, 'metadata_migration' => 'wrong', 'schema_sha256' => 'invalid']));
$contractFailed = false;
try {
    $builder->build([
        ...$optionsGeneric,
        'contract' => $badContractPath,
        'output' => $freshContractLab,
    ], $privateKeyPem);
} catch (RuntimeException $e) {
    $contractFailed = true;
    builderAssert(str_contains($e->getMessage(), 'Invalid data compatibility contract'), 'Must identify invalid contract');
}
builderAssert($contractFailed, 'Invalid contract must fail prevalidation');
$freshContractFiles = glob($freshContractLab . '/*');
builderAssert(empty($freshContractFiles), 'Invalid contract failure must create ZERO outputs');

// =========================================================================
// TEST 10: Protected paths on FRESH output creates ZERO outputs
// =========================================================================
$freshProtLab = $lab . '/fresh-prot-output';
mkdir($freshProtLab, 0700, true);
$targetInventoryProtected = $targetInventory;
$targetInventoryProtected['files']['customer.db'] = ['sha256' => str_repeat('0', 64), 'size' => 100];
$badTargetInvPath = $lab . '/target-protected.json';
file_put_contents($badTargetInvPath, json_encode($targetInventoryProtected));
$protRefused = false;
try {
    $builder->build([
        ...$optionsGeneric,
        'target-inventory' => $badTargetInvPath,
        'output' => $freshProtLab,
    ], $privateKeyPem);
} catch (RuntimeException $e) {
    $protRefused = true;
}
builderAssert($protRefused, 'Protected paths must be rejected');
$freshProtFiles = glob($freshProtLab . '/*');
builderAssert(empty($freshProtFiles), 'Protected path rejection must create ZERO outputs');

// =========================================================================
// TEST 11: Retained shell path missing artifact rejection
// =========================================================================
$shellMissingLab = $lab . '/shell-missing';
mkdir($shellMissingLab, 0700, true);
$badShellRoot = $lab . '/bad-shell';
mkdir($badShellRoot, 0700, true);
// Only POS Desktop.exe present, app.asar missing
file_put_contents($badShellRoot . '/POS Desktop.exe', 'binary');
$shellRefused = false;
try {
    $builder->build([
        ...$optionsGeneric,
        'shell-root' => $badShellRoot,
        'output' => $shellMissingLab,
    ], $privateKeyPem);
} catch (RuntimeException $e) {
    $shellRefused = true;
}
builderAssert($shellRefused, 'Missing/invalid shell artifacts must be rejected');
builderAssert(empty(glob($shellMissingLab . '/*')), 'Shell artifact failure must create ZERO outputs');

// =========================================================================
// TEST 11B: Retained shell path symlink / ancestor escape rejection
// =========================================================================
$symlinkEscapeLab = $lab . '/shell-symlink-escape';
mkdir($symlinkEscapeLab, 0700, true);
$symlinkShellRoot = $lab . '/symlink-shell';
mkdir($symlinkShellRoot, 0700, true);
file_put_contents($symlinkShellRoot . '/POS Desktop.exe', 'fixture shell binary');

// Legitimate resources directory outside symlink-shell
$outsideResources = $lab . '/outside-resources';
mkdir($outsideResources, 0700, true);
file_put_contents($outsideResources . '/app.asar', 'legitimate asar outside shell root');

// Ancestor directory link: symlinkShellRoot/resources -> outsideResources
$ancestorLink = $symlinkShellRoot . '/resources';
$createdLink = @symlink($outsideResources, $ancestorLink);
if (!$createdLink && PHP_OS_FAMILY === 'Windows') {
    $cmd = 'cmd /c mklink /J ' . escapeshellarg(str_replace('/', '\\', $ancestorLink)) . ' ' . escapeshellarg(str_replace('/', '\\', $outsideResources));
    @exec($cmd, $out, $ret);
    $createdLink = is_link($ancestorLink) || (is_dir($ancestorLink) && $ret === 0);
}
builderAssert($createdLink && (is_link($ancestorLink) || is_dir($ancestorLink)), 'Directory symlink/junction fixture must be created');

$ancestorEscapeRefused = false;
try {
    $builder->build([
        ...$optionsGeneric,
        'shell-root' => $symlinkShellRoot,
        'output' => $symlinkEscapeLab,
    ], $privateKeyPem);
} catch (RuntimeException $e) {
    $ancestorEscapeRefused = true;
    builderAssert(
        str_contains($e->getMessage(), 'symlink') || str_contains($e->getMessage(), 'escapes shell root'),
        'Must reject retained shell symlink or ancestor escape: ' . $e->getMessage()
    );
}
builderAssert($ancestorEscapeRefused, 'Retained shell path with symlink ancestor must be rejected');
builderAssert(empty(glob($symlinkEscapeLab . '/*')), 'Ancestor escape rejection must produce ZERO outputs');

// Remove only the link itself safely with a checked absolute path without following it
$resolvedAncestorLink = str_replace('\\', '/', $ancestorLink);
$normalizedLab = rtrim(str_replace('\\', '/', realpath($lab) ?: $lab), '/');
builderAssert(
    str_starts_with(str_replace('\\', '/', realpath(dirname($resolvedAncestorLink)) ?: dirname($resolvedAncestorLink)), $normalizedLab),
    'Ancestor link must reside within owned lab directory'
);
if (is_link($ancestorLink)) {
    if (PHP_OS_FAMILY === 'Windows' && is_dir($ancestorLink)) {
        rmdir($ancestorLink);
    } else {
        unlink($ancestorLink);
    }
}
builderAssert(!file_exists($ancestorLink) && !is_link($ancestorLink), 'Ancestor link must be removed');
// Confirm outside resource target remained intact
builderAssert(is_file($outsideResources . '/app.asar'), 'Target resources must remain intact after link removal');

// =========================================================================
// TEST 12: Orphan index signature rejection
// =========================================================================
$orphanLab = $lab . '/orphan-output';
mkdir($orphanLab, 0700, true);
file_put_contents($orphanLab . '/channel-transitions.sig', 'orphan_signature_data');
$orphanRefused = false;
try {
    $builder->build([
        ...$optionsGeneric,
        'output' => $orphanLab,
    ], $privateKeyPem);
} catch (RuntimeException $e) {
    $orphanRefused = true;
    builderAssert(str_contains($e->getMessage(), 'Orphan index signature detected'), 'Must reject orphan index signature');
}
builderAssert($orphanRefused, 'Orphan index signature must be rejected');

// =========================================================================
// TEST 13: Entry cap (32 entries max) refusal before build mutations
// =========================================================================
// 13A: Valid signed 31-entry index (+2 entries to append = 33 > 32 cap)
$cap31Lab = $lab . '/cap-31-output';
mkdir($cap31Lab, 0700, true);

$valid31Entries = array_map(static fn(int $i): array => [
    'from_version' => '0.0.' . $i,
    'source_inventory_sha256' => hash('sha256', "source-inv-{$i}"),
    'version' => '0.0.10-beta.10',
    'channel' => 'beta',
    'descriptor' => "d{$i}.json",
    'signature' => "d{$i}.sig",
    'descriptor_sha256' => hash('sha256', "desc-{$i}"),
    'type' => 'delta',
], range(1, 31));

$index31Raw = json_encode(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.10', 'entries' => $valid31Entries], JSON_PRETTY_PRINT);
$index31Sig = $sigService->signData($index31Raw, $privateKeyPem);
$verify31 = $verifier->verifyIndex($index31Raw, $index31Sig, ['tag_name' => 'v0.0.10-beta.10', 'draft' => false]);
builderAssert($verify31['ok'] === true, '31-entry fixture index must pass verifyIndex: ' . ($verify31['reason_code'] ?? ''));

file_put_contents($cap31Lab . '/channel-transitions.json', $index31Raw);
file_put_contents($cap31Lab . '/channel-transitions.sig', $index31Sig);

$hashesBeforeCap31 = [];
foreach (glob($cap31Lab . '/*') as $f) {
    if (is_file($f)) $hashesBeforeCap31[basename($f)] = hash_file('sha256', $f);
}

$cap31Refused = false;
try {
    $builder->build([
        ...$optionsGeneric,
        'output' => $cap31Lab,
        'append' => true,
    ], $privateKeyPem);
} catch (RuntimeException $e) {
    $cap31Refused = true;
    builderAssert(str_contains($e->getMessage(), '32 entries'), 'Must enforce 32 entry cap: ' . $e->getMessage());
}
builderAssert($cap31Refused, '31-entry index with +2 append (33 > 32) must be refused before build');

$hashesAfterCap31 = [];
foreach (glob($cap31Lab . '/*') as $f) {
    if (is_file($f)) $hashesAfterCap31[basename($f)] = hash_file('sha256', $f);
}
builderAssert($hashesBeforeCap31 === $hashesAfterCap31, '31-entry cap refusal must produce ZERO directory mutations');

// 13B: Valid signed 32-entry index (+2 entries to append = 34 > 32 cap)
$cap32Lab = $lab . '/cap-32-output';
mkdir($cap32Lab, 0700, true);

$valid32Entries = array_map(static fn(int $i): array => [
    'from_version' => '0.0.' . $i,
    'source_inventory_sha256' => hash('sha256', "source-inv-32-{$i}"),
    'version' => '0.0.10-beta.10',
    'channel' => 'beta',
    'descriptor' => "d{$i}.json",
    'signature' => "d{$i}.sig",
    'descriptor_sha256' => hash('sha256', "desc-32-{$i}"),
    'type' => 'delta',
], range(1, 32));

$index32Raw = json_encode(['format' => 1, 'carrier_tag' => 'v0.0.10-beta.10', 'entries' => $valid32Entries], JSON_PRETTY_PRINT);
$index32Sig = $sigService->signData($index32Raw, $privateKeyPem);
$verify32 = $verifier->verifyIndex($index32Raw, $index32Sig, ['tag_name' => 'v0.0.10-beta.10', 'draft' => false]);
builderAssert($verify32['ok'] === true, '32-entry fixture index must pass verifyIndex: ' . ($verify32['reason_code'] ?? ''));

file_put_contents($cap32Lab . '/channel-transitions.json', $index32Raw);
file_put_contents($cap32Lab . '/channel-transitions.sig', $index32Sig);

$hashesBeforeCap32 = [];
foreach (glob($cap32Lab . '/*') as $f) {
    if (is_file($f)) $hashesBeforeCap32[basename($f)] = hash_file('sha256', $f);
}

$cap32Refused = false;
try {
    $builder->build([
        ...$optionsGeneric,
        'output' => $cap32Lab,
        'append' => true,
    ], $privateKeyPem);
} catch (RuntimeException $e) {
    $cap32Refused = true;
    builderAssert(str_contains($e->getMessage(), '32 entries'), 'Must enforce 32 entry cap: ' . $e->getMessage());
}
builderAssert($cap32Refused, '32-entry index with +2 append (34 > 32) must be refused before build');

$hashesAfterCap32 = [];
foreach (glob($cap32Lab . '/*') as $f) {
    if (is_file($f)) $hashesAfterCap32[basename($f)] = hash_file('sha256', $f);
}
builderAssert($hashesBeforeCap32 === $hashesAfterCap32, '32-entry cap refusal must produce ZERO directory mutations');

// =========================================================================
// Cleanup
// =========================================================================
$canonical = realpath($lab);
builderAssert(
    $canonical !== false && str_starts_with(strtolower(str_replace('\\', '/', $canonical)), strtolower(str_replace('\\', '/', realpath(sys_get_temp_dir()))) . '/pos-builder-test-'),
    'Unsafe fixture cleanup root: ' . $lab
);
$cleanup = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lab, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($cleanup as $file) {
    $filePath = $file->getPathname();
    if (is_link($filePath)) {
        if (PHP_OS_FAMILY === 'Windows' && is_dir($filePath)) {
            rmdir($filePath);
        } else {
            unlink($filePath);
        }
    } elseif ($file->isDir()) {
        rmdir($filePath);
    } else {
        unlink($filePath);
    }
}
rmdir($lab);

echo "PASS channel transition builder: legacy & generic modes, reverse transition, append verification, non-destructive overwrite guards, protected path checks, orphan signature rejection, and 32-entry cap\n";
