<?php
declare(strict_types=1);

/**
 * scripts/fixtures/setup12-regressions.php
 *
 * Deterministic fixture helper for Setup 12 packaged regressions:
 *  - verify-beta-signatures: Verifies RSA signatures of published Beta 12.1 index and descriptor, plus ZIP binding.
 *  - reconstruct-beta12: Verifies and unpacks published Beta 12.1 full payload.
 *  - verify-phar-layout: Inspects actual backend.phar resource paths, migrations, seeders, and restore entries.
 *  - prepare-candidate-variants: Generates Setup 13 and Delta 13 inventories.
 *  - capture-contract: Captures actual SQL data compatibility contract from installed DB.
 *  - build-transitions: Builds and signs bidirectional transition packages via ChannelTransitionBuilder.
 *  - generate-provider-fixture: Builds GitHubReleaseProvider replay fixture for Bug 1 reproduction and roundtrip.
 *  - seed-db: Seeds sentinel customer and setting records for SQL integrity and restore testing.
 *  - inspect-db: Inspects schema, ledger, business records, and admin credentials.
 *  - mutate-db: Modifies sentinel business row to test restore reversion.
 *  - verify-restore-sql-preservation: Verifies byte-level SQL preservation in BackupService::validateUploadedSqlFile.
 *  - prepare-legacy-v025-backup: Prepares synthetic legacy v0.25 SQL backup injecting dynamic admin auth.
 *  - prepare-legacy-v025-variant-backup: Prepares synthetic legacy v0.25 SQL backup variant for negative convergence testing.
 *  - capture-legacy-v025-schema-baseline: Captures pre-Test5 MariaDB schema and ledger canonical baseline.
 *  - verify-legacy-v025-upgrade: Verifies post-restore migration reconciliation from legacy v0.25 to candidate 13.
 *  - verify-legacy-v025-fractional-capability: Verifies DECIMAL(10,3) fractional capabilities and constraints on converged schema.
 */

require_once __DIR__ . '/../../backend/vendor/autoload.php';
require_once __DIR__ . '/../build-channel-transition.php';
require_once __DIR__ . '/../deployment-inventory.php';

use App\Services\BackupService;
use App\Services\ManifestSignatureService;
use App\Services\UpdateChannelPolicy;
use App\Services\UpdateDataCompatibilityService;
use App\Services\UpdateManifestService;

final class Setup12RegressionsHelper
{
    private string $runnerTemp;

    public function __construct(?string $runnerTemp = null)
    {
        if (getenv('GITHUB_ACTIONS') !== 'true') {
            throw new RuntimeException('Setup 12 regressions helper strictly requires ephemeral GITHUB_ACTIONS environment.');
        }
        $temp = $runnerTemp ?? (string)getenv('RUNNER_TEMP');
        if ($temp === '') {
            throw new RuntimeException('RUNNER_TEMP environment variable is required.');
        }
        $real = realpath($temp);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException("Invalid RUNNER_TEMP directory: {$temp}");
        }
        $this->runnerTemp = rtrim(str_replace('\\', '/', $real), '/');
    }

    public function assertSafeRunnerTempPath(string $path, bool $mustExist = true): string
    {
        $norm = str_replace('\\', '/', $path);
        if ($mustExist) {
            $real = realpath($path);
            if ($real === false) {
                throw new RuntimeException("Required path does not exist: {$path}");
            }
            $norm = rtrim(str_replace('\\', '/', $real), '/');
        } else {
            $parent = dirname($path);
            $realParent = realpath($parent);
            if ($realParent === false) {
                throw new RuntimeException("Parent directory does not exist: {$parent}");
            }
            $norm = rtrim(str_replace('\\', '/', $realParent), '/') . '/' . basename($path);
        }

        if (!str_starts_with(strtolower($norm), strtolower($this->runnerTemp) . '/')) {
            throw new RuntimeException("Path escapes RUNNER_TEMP boundary ({$this->runnerTemp}): {$norm}");
        }

        $cursor = $norm;
        while ($cursor && $cursor !== $this->runnerTemp && $cursor !== dirname($cursor)) {
            if (file_exists($cursor) && is_link($cursor)) {
                throw new RuntimeException("Symlink rejected: {$cursor}");
            }
            $cursor = dirname($cursor);
        }

        return $norm;
    }

    public function verifyBetaSignatures(
        string $indexFile,
        string $indexSigFile,
        string $descFile,
        string $descSigFile,
        string $zipFile,
        string $publicKeyFile
    ): array {
        $indexFile = $this->assertSafeRunnerTempPath($indexFile, true);
        $indexSigFile = $this->assertSafeRunnerTempPath($indexSigFile, true);
        $descFile = $this->assertSafeRunnerTempPath($descFile, true);
        $descSigFile = $this->assertSafeRunnerTempPath($descSigFile, true);
        $zipFile = $this->assertSafeRunnerTempPath($zipFile, true);

        $realKey = realpath($publicKeyFile);
        if ($realKey === false || !is_file($realKey)) {
            throw new RuntimeException("Public key file not found: {$publicKeyFile}");
        }

        // 1. Verify fresh public metadata SHA256 hashes
        $expectedIndexHash = '4109ad3cdfb06201219a8939015cc1c723863e27f200d2257e408c7b8cd98f27';
        $actualIndexHash = strtolower((string)hash_file('sha256', $indexFile));
        if ($actualIndexHash !== $expectedIndexHash) {
            throw new RuntimeException("Beta 12.1 index SHA256 mismatch! Expected {$expectedIndexHash}, got {$actualIndexHash}");
        }

        $expectedIndexSigHash = '599742f4c95f842944b8705458b5f5bc7dfea4cf5d23110b04464014764b77c6';
        $actualIndexSigHash = strtolower((string)hash_file('sha256', $indexSigFile));
        if ($actualIndexSigHash !== $expectedIndexSigHash) {
            throw new RuntimeException("Beta 12.1 index signature SHA256 mismatch! Expected {$expectedIndexSigHash}, got {$actualIndexSigHash}");
        }

        $expectedDescHash = '73d8177e5605b7762e3fc6b2333752e78bbb81881b67cde3f7bd2088c34712b0';
        $actualDescHash = strtolower((string)hash_file('sha256', $descFile));
        if ($actualDescHash !== $expectedDescHash) {
            throw new RuntimeException("Beta 12.1 descriptor SHA256 mismatch! Expected {$expectedDescHash}, got {$actualDescHash}");
        }

        $expectedDescSigHash = '3861b74953c58ef7fa217774e456e04e3b8540d8026cd13330511195692860bc';
        $actualDescSigHash = strtolower((string)hash_file('sha256', $descSigFile));
        if ($actualDescSigHash !== $expectedDescSigHash) {
            throw new RuntimeException("Beta 12.1 descriptor signature SHA256 mismatch! Expected {$expectedDescSigHash}, got {$actualDescSigHash}");
        }

        $expectedZipHash = 'cb17fb94a476a3e5070e2d0f2c3db4fbc40f5bc6bc193b697fe3af7f74965cf2';
        $actualZipHash = strtolower((string)hash_file('sha256', $zipFile));
        if ($actualZipHash !== $expectedZipHash) {
            throw new RuntimeException("Beta 12.1 transition ZIP SHA256 mismatch! Expected {$expectedZipHash}, got {$actualZipHash}");
        }

        // 2. RSA signature verification using actual ManifestSignatureService API
        $sigService = new ManifestSignatureService($realKey);

        $indexRaw = (string)file_get_contents($indexFile);
        $indexSig = (string)file_get_contents($indexSigFile);
        if (!$sigService->verifySignature($indexRaw, $indexSig, $realKey)) {
            throw new RuntimeException('Beta 12.1 channel-transitions.json RSA signature verification failed.');
        }

        $descRaw = (string)file_get_contents($descFile);
        $descSig = (string)file_get_contents($descSigFile);
        if (!$sigService->verifySignature($descRaw, $descSig, $realKey)) {
            throw new RuntimeException('Beta 12.1 descriptor RSA signature verification failed.');
        }

        // 3. Verify index structure and matching entry
        $indexData = json_decode($indexRaw, true, 32, JSON_THROW_ON_ERROR);
        if (($indexData['format'] ?? null) !== 1 || !isset($indexData['entries']) || !is_array($indexData['entries'])) {
            throw new RuntimeException('Beta 12.1 channel-transitions.json has invalid format or missing entries.');
        }

        $expectedStem = 'transition-0.0.11-to-beta-0.0.12-beta.1-full';
        $descName = basename($descFile);
        $descSigName = basename($descSigFile);
        $zipName = basename($zipFile);

        if ($descName !== $expectedStem . '.json') {
            throw new RuntimeException("Beta 12.1 descriptor filename mismatch: expected {$expectedStem}.json, got {$descName}");
        }
        if ($descSigName !== $expectedStem . '.sig') {
            throw new RuntimeException("Beta 12.1 descriptor signature filename mismatch: expected {$expectedStem}.sig, got {$descSigName}");
        }
        if ($zipName !== $expectedStem . '.zip') {
            throw new RuntimeException("Beta 12.1 transition zip filename mismatch: expected {$expectedStem}.zip, got {$zipName}");
        }

        $matchingEntry = null;
        foreach ($indexData['entries'] as $entry) {
            if (($entry['descriptor'] ?? '') === $descName) {
                $matchingEntry = $entry;
                break;
            }
        }
        if ($matchingEntry === null) {
            throw new RuntimeException("Beta 12.1 index does not contain entry for descriptor: {$descName}");
        }

        // Verify index entry bindings
        if (($matchingEntry['signature'] ?? '') !== $descSigName) {
            throw new RuntimeException("Index entry signature mismatch: expected {$descSigName}, got " . ($matchingEntry['signature'] ?? 'null'));
        }
        if (strtolower((string)($matchingEntry['descriptor_sha256'] ?? '')) !== $actualDescHash) {
            throw new RuntimeException("Index entry descriptor_sha256 mismatch: expected {$actualDescHash}, got " . ($matchingEntry['descriptor_sha256'] ?? 'null'));
        }
        if (($matchingEntry['version'] ?? '') !== '0.0.12-beta.1') {
            throw new RuntimeException("Index entry target version mismatch: expected 0.0.12-beta.1, got " . ($matchingEntry['version'] ?? 'null'));
        }
        if (($matchingEntry['channel'] ?? '') !== 'beta') {
            throw new RuntimeException("Index entry target channel mismatch: expected beta, got " . ($matchingEntry['channel'] ?? 'null'));
        }
        if (($matchingEntry['type'] ?? '') !== 'full') {
            throw new RuntimeException("Index entry type mismatch: expected full, got " . ($matchingEntry['type'] ?? 'null'));
        }

        // 4. Verify descriptor contents and bindings
        $desc = json_decode($descRaw, true, 64, JSON_THROW_ON_ERROR);
        if (($desc['format'] ?? null) !== 1 || ($desc['operation'] ?? null) !== 'channel_transition') {
            throw new RuntimeException('Beta 12.1 descriptor format or operation invalid.');
        }

        // Descriptor to index entry bindings
        if (($desc['from_version'] ?? '') !== ($matchingEntry['from_version'] ?? '')) {
            throw new RuntimeException('Descriptor from_version mismatch with index entry.');
        }
        if (($desc['source_inventory_sha256'] ?? '') !== ($matchingEntry['source_inventory_sha256'] ?? '')) {
            throw new RuntimeException('Descriptor source_inventory_sha256 mismatch with index entry.');
        }
        if (($desc['version'] ?? '') !== ($matchingEntry['version'] ?? '')) {
            throw new RuntimeException('Descriptor version mismatch with index entry.');
        }
        if (($desc['channel'] ?? '') !== ($matchingEntry['channel'] ?? '')) {
            throw new RuntimeException('Descriptor channel mismatch with index entry.');
        }
        if (($desc['type'] ?? '') !== ($matchingEntry['type'] ?? '')) {
            throw new RuntimeException('Descriptor type mismatch with index entry.');
        }

        // Descriptor package binding to ZIP: actual descriptor package {asset, sha256, size}
        $package = $desc['package'] ?? null;
        if (!is_array($package)) {
            throw new RuntimeException('Beta 12.1 descriptor missing package object.');
        }
        if (($package['asset'] ?? '') !== $zipName) {
            throw new RuntimeException("Descriptor package asset mismatch: expected {$zipName}, got " . ($package['asset'] ?? 'null'));
        }
        if (strtolower((string)($package['sha256'] ?? '')) !== $actualZipHash) {
            throw new RuntimeException("Descriptor package sha256 mismatch with transition zip: expected {$actualZipHash}, got " . ($package['sha256'] ?? 'null'));
        }
        $zipSize = filesize($zipFile);
        if ((int)($package['size'] ?? -1) !== $zipSize) {
            throw new RuntimeException("Descriptor package size mismatch: expected {$zipSize}, got " . ($package['size'] ?? 'null'));
        }

        // Target inventory binding
        $expectedBeta12InvHash = '104cc1b55d9ebae69aac8d56bbb024afa178e4208fd73a41a1d4d89d504b1fb3';
        if (strtolower((string)($desc['target_inventory_sha256'] ?? '')) !== $expectedBeta12InvHash) {
            throw new RuntimeException("Descriptor target_inventory_sha256 mismatch: expected {$expectedBeta12InvHash}, got " . ($desc['target_inventory_sha256'] ?? 'null'));
        }

        // Guard target inventory & files: no PHAR fake, no SQL/private payload
        $targetInv = $desc['target_inventory'] ?? null;
        if (!is_array($targetInv) || ($targetInv['version'] ?? '') !== '0.0.12-beta.1' || !is_array($targetInv['files'] ?? null)) {
            throw new RuntimeException('Descriptor target_inventory invalid or missing files.');
        }

        $descFiles = $desc['files'] ?? null;
        if (!is_array($descFiles) || empty($descFiles)) {
            throw new RuntimeException('Descriptor files payload is empty or invalid.');
        }

        $manifestPolicy = new UpdateManifestService();
        $hasPhar = false;
        foreach ($descFiles as $fileEntry) {
            $path = (string)($fileEntry['path'] ?? '');
            if (!$manifestPolicy->isPathSafe($path, 'app')) {
                throw new RuntimeException("Unsafe path in descriptor files: {$path}");
            }
            if ($path === 'backend/backend.phar') {
                $hasPhar = true;
                if ((int)($fileEntry['size'] ?? 0) < 1000000) {
                    throw new RuntimeException('backend/backend.phar in descriptor appears to be fake or undersized.');
                }
            }
            if (str_ends_with(strtolower($path), '.sql') && $path !== 'database/pos_schema.sql') {
                throw new RuntimeException("Disallowed SQL payload in descriptor files: {$path}");
            }
        }
        if (!$hasPhar) {
            throw new RuntimeException('backend/backend.phar missing from descriptor files.');
        }

        if (!empty($desc['migrations'])) {
            throw new RuntimeException('Transition descriptor must not include SQL migrations payload.');
        }

        return [
            'ok' => true,
            'index_verified' => true,
            'descriptor_verified' => true,
            'zip_binding_verified' => true,
            'index_sha256' => $actualIndexHash,
            'descriptor_sha256' => $actualDescHash,
            'zip_sha256' => $actualZipHash,
            'target_inventory_sha256' => $expectedBeta12InvHash,
        ];
    }

    public function reconstructBeta12(
        string $beta12ZipPath,
        string $beta12InventoryPath,
        string $outputPath
    ): array {
        $beta12ZipPath = $this->assertSafeRunnerTempPath($beta12ZipPath, true);
        $beta12InventoryPath = $this->assertSafeRunnerTempPath($beta12InventoryPath, true);
        $outputPath = $this->assertSafeRunnerTempPath($outputPath, false);

        if (!is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }
        $realOutput = realpath($outputPath);
        if ($realOutput === false) {
            throw new RuntimeException("Cannot resolve reconstructed beta12 output directory: {$outputPath}");
        }
        $outputNorm = rtrim(str_replace('\\', '/', $realOutput), '/');

        $expectedBeta12InvHash = '104cc1b55d9ebae69aac8d56bbb024afa178e4208fd73a41a1d4d89d504b1fb3';
        $actualBeta12InvHash = hash_file('sha256', $beta12InventoryPath);
        if (strtolower((string)$actualBeta12InvHash) !== $expectedBeta12InvHash) {
            throw new RuntimeException("Beta 12.1 inventory hash mismatch! Expected {$expectedBeta12InvHash}, got {$actualBeta12InvHash}");
        }

        $inventory = json_decode((string)file_get_contents($beta12InventoryPath), true, 64, JSON_THROW_ON_ERROR);
        if (($inventory['version'] ?? '') !== '0.0.12-beta.1' || empty($inventory['files']) || !is_array($inventory['files'])) {
            throw new RuntimeException('Beta 12.1 deployment inventory missing required file map or version mismatch.');
        }

        $expectedZipHash = 'cb17fb94a476a3e5070e2d0f2c3db4fbc40f5bc6bc193b697fe3af7f74965cf2';
        $actualZipHash = hash_file('sha256', $beta12ZipPath);
        if (strtolower((string)$actualZipHash) !== $expectedZipHash) {
            throw new RuntimeException("Beta 12.1 forward full transition package hash mismatch! Expected {$expectedZipHash}, got {$actualZipHash}");
        }

        $zip = new ZipArchive();
        if ($zip->open($beta12ZipPath) !== true) {
            throw new RuntimeException("Cannot open Beta 12.1 full transition archive: {$beta12ZipPath}");
        }

        $manifestPolicy = new UpdateManifestService();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }
            $entryNorm = str_replace('\\', '/', $entry);
            if (str_ends_with($entryNorm, '/')) {
                continue;
            }
            if (!$manifestPolicy->isPathSafe($entryNorm, $outputNorm)) {
                $zip->close();
                throw new RuntimeException("Unsafe path in Beta 12.1 archive: {$entryNorm}");
            }
            $destFile = $outputNorm . '/' . $entryNorm;
            $destDir = dirname($destFile);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            $stream = $zip->getStream($entry);
            if ($stream === false) {
                $zip->close();
                throw new RuntimeException("Failed to read stream for entry: {$entry}");
            }
            $outHandle = fopen($destFile, 'wb');
            if ($outHandle === false) {
                fclose($stream);
                $zip->close();
                throw new RuntimeException("Failed to open destination file for write: {$destFile}");
            }
            stream_copy_to_stream($stream, $outHandle);
            fclose($stream);
            fclose($outHandle);
        }
        $zip->close();

        // Verify every file declared in deployment-inventory.json
        $verifiedCount = 0;
        foreach ($inventory['files'] as $relPath => $expected) {
            $relNorm = str_replace('\\', '/', $relPath);
            if (!$manifestPolicy->isPathSafe($relNorm, $outputNorm)) {
                throw new RuntimeException("Unsafe path in Beta 12.1 inventory: {$relNorm}");
            }
            $fullPath = $outputNorm . '/' . $relNorm;
            if (!is_file($fullPath)) {
                throw new RuntimeException("Declared Beta 12.1 inventory file missing in reconstructed output: {$relNorm}");
            }
            $actualHash = hash_file('sha256', $fullPath);
            if (strtolower((string)$actualHash) !== strtolower((string)($expected['sha256'] ?? ''))) {
                throw new RuntimeException("Reconstructed Beta 12.1 file SHA256 mismatch for {$relNorm}. Expected: {$expected['sha256']}, got: {$actualHash}");
            }
            $actualSize = filesize($fullPath);
            if (isset($expected['size']) && $actualSize !== (int)$expected['size']) {
                throw new RuntimeException("Reconstructed Beta 12.1 file size mismatch for {$relNorm}. Expected: {$expected['size']}, got: {$actualSize}");
            }
            if ($relNorm === 'backend/backend.phar' && $actualSize < 1000000) {
                throw new RuntimeException("Reconstructed Beta 12.1 backend.phar is undersized/fake ({$actualSize} bytes).");
            }
            if (str_ends_with(strtolower($relNorm), '.sql') && $relNorm !== 'database/pos_schema.sql') {
                throw new RuntimeException("Reconstructed Beta 12.1 contains disallowed SQL payload: {$relNorm}");
            }
            $verifiedCount++;
        }

        // Verify version.json in reconstructed output
        $versionJsonPath = $outputNorm . '/version.json';
        if (!is_file($versionJsonPath)) {
            throw new RuntimeException("version.json missing in reconstructed Beta 12.1 output: {$versionJsonPath}");
        }
        $vData = json_decode((string)file_get_contents($versionJsonPath), true, 32, JSON_THROW_ON_ERROR);
        if (($vData['version'] ?? '') !== '0.0.12-beta.1') {
            throw new RuntimeException("Reconstructed Beta 12.1 version.json mismatch: expected 0.0.12-beta.1, got: " . ($vData['version'] ?? 'null'));
        }

        return [
            'ok' => true,
            'version' => '0.0.12-beta.1',
            'verified_files' => $verifiedCount,
            'output' => $outputNorm,
        ];
    }

    public function prepareCandidateVariants(
        string $candidateUnpackedApp,
        string $repoSourceRoot,
        string $candidateSource,
        string $outputSetupInventory,
        string $deltaAppDir,
        string $outputDeltaInventory
    ): array {
        $candidateUnpackedApp = $this->assertSafeRunnerTempPath($candidateUnpackedApp, true);
        $candidateSource = $this->assertSafeRunnerTempPath($candidateSource, true);

        $repoSourceRoot = realpath($repoSourceRoot);
        if ($repoSourceRoot === false || !is_dir($repoSourceRoot)) {
            throw new RuntimeException("Invalid repo source root: {$repoSourceRoot}");
        }
        $repoSourceNorm = rtrim(str_replace('\\', '/', $repoSourceRoot), '/');

        $candidateSourceNorm = rtrim(str_replace('\\', '/', $candidateSource), '/');

        $outputSetupInventory = $this->assertSafeRunnerTempPath($outputSetupInventory, false);
        $deltaAppDir = $this->assertSafeRunnerTempPath($deltaAppDir, false);
        $outputDeltaInventory = $this->assertSafeRunnerTempPath($outputDeltaInventory, false);

        // Validate private package/lock versions 13 root and appropriate version metadata; never fallback silently hash original 12
        $candVersionFile = $candidateSourceNorm . '/version.json';
        if (!is_file($candVersionFile)) {
            throw new RuntimeException("version.json missing in candidate source: {$candVersionFile}");
        }
        $candVer = json_decode((string)file_get_contents($candVersionFile), true, 32, JSON_THROW_ON_ERROR);
        if (($candVer['version'] ?? '') !== '0.0.13') {
            throw new RuntimeException("Candidate source version.json must be 0.0.13, got: " . ($candVer['version'] ?? 'null'));
        }

        $candPkgFile = $candidateSourceNorm . '/package.json';
        if (!is_file($candPkgFile)) {
            throw new RuntimeException("package.json missing in candidate source: {$candPkgFile}");
        }
        $candPkg = json_decode((string)file_get_contents($candPkgFile), true, 32, JSON_THROW_ON_ERROR);
        if (($candPkg['version'] ?? '') !== '0.0.13') {
            throw new RuntimeException("Candidate source package.json must be 0.0.13, got: " . ($candPkg['version'] ?? 'null'));
        }

        $candLockFile = $candidateSourceNorm . '/package-lock.json';
        if (!is_file($candLockFile)) {
            throw new RuntimeException("package-lock.json missing in candidate source: {$candLockFile}");
        }
        $candLock = json_decode((string)file_get_contents($candLockFile), true, 32, JSON_THROW_ON_ERROR);
        if (($candLock['version'] ?? '') !== '0.0.13') {
            throw new RuntimeException("Candidate source package-lock.json must be 0.0.13, got: " . ($candLock['version'] ?? 'null'));
        }

        $unpackedVersionFile = $candidateUnpackedApp . '/version.json';
        if (!is_file($unpackedVersionFile)) {
            throw new RuntimeException("version.json missing in candidate unpacked app: {$unpackedVersionFile}");
        }
        $unpackedVer = json_decode((string)file_get_contents($unpackedVersionFile), true, 32, JSON_THROW_ON_ERROR);
        if (($unpackedVer['version'] ?? '') !== '0.0.13') {
            throw new RuntimeException("Candidate unpacked app version must be 0.0.13, got: " . ($unpackedVer['version'] ?? 'null'));
        }

        // Preserve --repo-root original Git index and restore env in finally
        $oldGitDir = getenv('GIT_DIR');
        $oldGitWorkTree = getenv('GIT_WORK_TREE');
        $oldGitIndexFile = getenv('GIT_INDEX_FILE');

        $gitDir = trim((string)shell_exec('git -C ' . escapeshellarg($repoSourceNorm) . ' rev-parse --absolute-git-dir 2>nul'));
        if ($gitDir === '') {
            if (is_dir($repoSourceNorm . '/.git')) {
                $gitDir = $repoSourceNorm . '/.git';
            } elseif (is_file($repoSourceNorm . '/.git')) {
                $gitContent = (string)file_get_contents($repoSourceNorm . '/.git');
                if (preg_match('/gitdir:\s*(.+)/', $gitContent, $m)) {
                    $gitDir = trim($m[1]);
                    if (!str_starts_with($gitDir, '/') && !preg_match('#^[a-zA-Z]:#', $gitDir)) {
                        $gitDir = $repoSourceNorm . '/' . $gitDir;
                    }
                }
            }
        }
        if ($gitDir === '') {
            throw new RuntimeException("Could not determine git directory for repo: {$repoSourceNorm}");
        }

        try {
            putenv("GIT_DIR={$gitDir}");
            putenv("GIT_WORK_TREE={$candidateSourceNorm}");

            // 1. Build genuine Setup 13 deployment inventory using candidateSource
            $setup13Inventory = createDeploymentInventory($candidateUnpackedApp, $candidateSourceNorm);
            if (($setup13Inventory['version'] ?? '') !== '0.0.13') {
                throw new RuntimeException("Candidate Setup inventory version must be 0.0.13, got: " . ($setup13Inventory['version'] ?? 'null'));
            }
            file_put_contents($outputSetupInventory, json_encode($setup13Inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            // 2. Prepare Delta 13 variant app files
            if (!is_dir($deltaAppDir)) {
                mkdir($deltaAppDir, 0755, true);
            }
            $realDeltaApp = realpath($deltaAppDir);
            if ($realDeltaApp === false) {
                throw new RuntimeException("Cannot resolve delta app directory: {$deltaAppDir}");
            }
            $deltaAppNorm = rtrim(str_replace('\\', '/', $realDeltaApp), '/');

            $this->copyDirectoryRecursive($candidateUnpackedApp, $deltaAppNorm);

            // Modify version.json in Delta variant to ONLY declare release_type = 'delta'
            $deltaVersionPath = $deltaAppNorm . '/version.json';
            if (!is_file($deltaVersionPath)) {
                throw new RuntimeException("version.json missing in Delta app variant: {$deltaVersionPath}");
            }
            $deltaVersionData = json_decode((string)file_get_contents($deltaVersionPath), true, 32, JSON_THROW_ON_ERROR);
            $deltaVersionData['release_type'] = 'delta';
            file_put_contents($deltaVersionPath, json_encode($deltaVersionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            // 3. Build genuine Delta 13 deployment inventory using candidateSource
            $delta13Inventory = createDeploymentInventory($deltaAppNorm, $candidateSourceNorm);
            if (($delta13Inventory['version'] ?? '') !== '0.0.13') {
                throw new RuntimeException("Candidate Delta inventory version must be 0.0.13, got: " . ($delta13Inventory['version'] ?? 'null'));
            }
            file_put_contents($outputDeltaInventory, json_encode($delta13Inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        } finally {
            if ($oldGitDir !== false && $oldGitDir !== null) {
                putenv("GIT_DIR={$oldGitDir}");
            } else {
                putenv("GIT_DIR");
            }
            if ($oldGitWorkTree !== false && $oldGitWorkTree !== null) {
                putenv("GIT_WORK_TREE={$oldGitWorkTree}");
            } else {
                putenv("GIT_WORK_TREE");
            }
            if ($oldGitIndexFile !== false && $oldGitIndexFile !== null) {
                putenv("GIT_INDEX_FILE={$oldGitIndexFile}");
            } else {
                putenv("GIT_INDEX_FILE");
            }
        }

        $setupHash = hash_file('sha256', $outputSetupInventory);
        $deltaHash = hash_file('sha256', $outputDeltaInventory);
        if ($setupHash === $deltaHash) {
            throw new RuntimeException('Candidate Setup 13 and Delta 13 inventories must produce distinct SHA256 hashes.');
        }

        return [
            'ok' => true,
            'version' => '0.0.13',
            'setup_inventory_sha256' => $setupHash,
            'delta_inventory_sha256' => $deltaHash,
            'setup_inventory' => $outputSetupInventory,
            'delta_inventory' => $outputDeltaInventory,
            'shell_source' => $candidateSourceNorm,
        ];
    }

    public function captureContract(string $configDir, string $outputPath): array
    {
        $db = $this->connectDatabase($configDir);
        $service = new UpdateDataCompatibilityService();
        $description = $service->describe($db);

        $contract = [
            'format' => 1,
            'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
            'schema_sha256' => $description['schema_sha256'],
            'ledger_sha256' => $description['ledger_sha256'],
        ];

        $outputNorm = $this->assertSafeRunnerTempPath($outputPath, false);
        file_put_contents($outputNorm, json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        return [
            'ok' => true,
            'contract' => $contract,
            'output' => $outputNorm,
        ];
    }

    public function buildTransitions(
        string $assetsDir,
        string $candidateApp,
        string $candidateSetupInventory,
        string $candidateDeltaApp,
        string $candidateDeltaInventory,
        string $beta12App,
        string $beta12Inventory,
        string $contractPath,
        string $shellRoot,
        string $privateKey
    ): array {
        $assetsDir = $this->assertSafeRunnerTempPath($assetsDir, false);
        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
        }

        $candidateApp = $this->assertSafeRunnerTempPath($candidateApp, true);
        $candidateSetupInventory = $this->assertSafeRunnerTempPath($candidateSetupInventory, true);
        $candidateDeltaApp = $this->assertSafeRunnerTempPath($candidateDeltaApp, true);
        $candidateDeltaInventory = $this->assertSafeRunnerTempPath($candidateDeltaInventory, true);
        $beta12App = $this->assertSafeRunnerTempPath($beta12App, true);
        $beta12Inventory = $this->assertSafeRunnerTempPath($beta12Inventory, true);
        $contractPath = $this->assertSafeRunnerTempPath($contractPath, true);
        $shellRoot = $this->assertSafeRunnerTempPath($shellRoot, true);

        if (trim($privateKey) === '') {
            throw new RuntimeException('Signing key is required to build transitions.');
        }

        $posDesktopDir = $assetsDir . '/pos-desktop';
        $posDesktopUpdatesDir = $assetsDir . '/pos-desktop-updates';
        if (!is_dir($posDesktopDir)) {
            mkdir($posDesktopDir, 0755, true);
        }
        if (!is_dir($posDesktopUpdatesDir)) {
            mkdir($posDesktopUpdatesDir, 0755, true);
        }

        $builder = new ChannelTransitionBuilder();

        // Pair 1 (pos-desktop): Candidate Setup 13 -> Public Beta 0.0.12-beta.1 (delta + full)
        $res1 = $builder->build([
            'carrier-tag' => 'v0.0.13',
            'target-repo' => 'pos-desktop-updates',
            'target-tag' => 'v0.0.12-beta.1',
            'source-root' => $candidateApp,
            'source-inventory' => $candidateSetupInventory,
            'target-root' => $beta12App,
            'target-inventory' => $beta12Inventory,
            'shell-root' => $shellRoot,
            'contract' => $contractPath,
            'output' => $posDesktopDir,
        ], $privateKey);

        if (!$res1['ok']) {
            throw new RuntimeException("Failed to build transition Setup 13 -> beta 12.1 in pos-desktop: {$res1['message']}");
        }

        // Pair 2 (pos-desktop-updates): Candidate Delta 13 -> Public Beta 0.0.12-beta.1 (delta + full)
        $res2 = $builder->build([
            'carrier-tag' => 'v0.0.13',
            'target-repo' => 'pos-desktop-updates',
            'target-tag' => 'v0.0.12-beta.1',
            'source-root' => $candidateDeltaApp,
            'source-inventory' => $candidateDeltaInventory,
            'target-root' => $beta12App,
            'target-inventory' => $beta12Inventory,
            'shell-root' => $shellRoot,
            'contract' => $contractPath,
            'output' => $posDesktopUpdatesDir,
        ], $privateKey);

        if (!$res2['ok']) {
            throw new RuntimeException("Failed to build transition Delta 13 -> beta 12.1 in pos-desktop-updates: {$res2['message']}");
        }

        // Pair 3 (pos-desktop-updates appended): Public Beta 0.0.12-beta.1 -> Candidate Delta 13 (delta + full)
        $res3 = $builder->build([
            'carrier-tag' => 'v0.0.13',
            'target-repo' => 'pos-desktop-updates',
            'target-tag' => 'v0.0.13',
            'source-root' => $beta12App,
            'source-inventory' => $beta12Inventory,
            'target-root' => $candidateDeltaApp,
            'target-inventory' => $candidateDeltaInventory,
            'shell-root' => $shellRoot,
            'contract' => $contractPath,
            'output' => $posDesktopUpdatesDir,
            'append' => '1',
        ], $privateKey);

        if (!$res3['ok']) {
            throw new RuntimeException("Failed to build transition beta 12.1 -> Delta 13 in pos-desktop-updates: {$res3['message']}");
        }

        // Verify index files exist and verify against public key
        $pubKeyPath = dirname(__DIR__, 2) . '/backend/certs/update_public_key.pem';
        $sigService = new ManifestSignatureService();

        // 1. pos-desktop index verification (must have exactly 2 entries)
        $desktopIndexPath = $posDesktopDir . '/channel-transitions.json';
        $desktopSigPath = $posDesktopDir . '/channel-transitions.sig';
        if (!is_file($desktopIndexPath) || !is_file($desktopSigPath)) {
            throw new RuntimeException("Channel transitions index or signature missing in {$posDesktopDir}");
        }
        $desktopIndexRaw = (string)file_get_contents($desktopIndexPath);
        $desktopSigRaw = (string)file_get_contents($desktopSigPath);
        if (!$sigService->verifySignature($desktopIndexRaw, $desktopSigRaw, $pubKeyPath)) {
            throw new RuntimeException('pos-desktop channel-transitions index signature failed public key verification.');
        }
        $desktopIndexData = json_decode($desktopIndexRaw, true, 64, JSON_THROW_ON_ERROR);
        $desktopCount = count($desktopIndexData['entries'] ?? []);
        if ($desktopCount !== 2) {
            throw new RuntimeException("Expected exactly 2 entries in pos-desktop transition index, got {$desktopCount}");
        }

        // 2. pos-desktop-updates index verification (must have exactly 4 entries)
        $updatesIndexPath = $posDesktopUpdatesDir . '/channel-transitions.json';
        $updatesSigPath = $posDesktopUpdatesDir . '/channel-transitions.sig';
        if (!is_file($updatesIndexPath) || !is_file($updatesSigPath)) {
            throw new RuntimeException("Channel transitions index or signature missing in {$posDesktopUpdatesDir}");
        }
        $updatesIndexRaw = (string)file_get_contents($updatesIndexPath);
        $updatesSigRaw = (string)file_get_contents($updatesSigPath);
        if (!$sigService->verifySignature($updatesIndexRaw, $updatesSigRaw, $pubKeyPath)) {
            throw new RuntimeException('pos-desktop-updates channel-transitions index signature failed public key verification.');
        }
        $updatesIndexData = json_decode($updatesIndexRaw, true, 64, JSON_THROW_ON_ERROR);
        $updatesCount = count($updatesIndexData['entries'] ?? []);
        if ($updatesCount !== 4) {
            throw new RuntimeException("Expected exactly 4 entries in pos-desktop-updates transition index, got {$updatesCount}");
        }

        return [
            'ok' => true,
            'carrier_tag' => 'v0.0.13',
            'pos_desktop_entries_count' => $desktopCount,
            'pos_desktop_updates_entries_count' => $updatesCount,
            'pos_desktop_dir' => $posDesktopDir,
            'pos_desktop_updates_dir' => $posDesktopUpdatesDir,
        ];
    }

    public function generateProviderFixture(string $mode, string $labDir, string $outputPath, string $evidenceDir = ''): array
    {
        $labDir = $this->assertSafeRunnerTempPath($labDir, true);
        $outputPath = $this->assertSafeRunnerTempPath($outputPath, false);

        if ($evidenceDir === '') {
            $evidenceDir = dirname($labDir) . '/setup12-regression-evidence';
        }
        $evidenceDir = $this->assertSafeRunnerTempPath($evidenceDir, false);
        if (!is_dir($evidenceDir)) {
            mkdir($evidenceDir, 0755, true);
        }

        $assetsDir = $labDir . '/assets';
        $setup12Inv = $labDir . '/setup12-inventory.json';
        $delta12Inv = $labDir . '/delta12-inventory.json';
        $beta12Inv = $labDir . '/beta12-inventory.json';
        $candidateSetupInv = $labDir . '/candidate13-setup-inventory.json';
        $candidateDeltaInv = $labDir . '/candidate13-delta-inventory.json';

        $responses = [];
        $assetMap = [];

        // Base release structure creator helper
        $makeRelease = function (string $repo, string $tag, array $assetList) {
            $version = ltrim($tag, 'vV');
            $assets = [];
            foreach ($assetList as $name => $localPath) {
                $downloadUrl = "https://github.com/ABDO-TECK/{$repo}/releases/download/{$tag}/{$name}";
                $assets[] = [
                    'name' => $name,
                    'browser_download_url' => $downloadUrl,
                    'size' => is_file($localPath) ? filesize($localPath) : 0,
                ];
            }
            return [
                'tag_name' => $tag,
                'name' => "POS Desktop {$tag}",
                'draft' => false,
                'prerelease' => str_contains($tag, 'beta'),
                'published_at' => '2026-10-28T00:00:00Z',
                'assets' => $assets,
            ];
        };

        if ($mode === 'reproduce-setup12') {
            // Setup 12 release in pos-desktop (full installer)
            $setup12Assets = ['deployment-inventory.json' => $setup12Inv];
            $setup12Rel = $makeRelease('pos-desktop', 'v0.0.12', $setup12Assets);
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/tags/v0.0.12'] = $setup12Rel;
            $assetMap["https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.12/deployment-inventory.json"] = $setup12Inv;

            // Delta 12 release in pos-desktop-updates (updates repository)
            $delta12Assets = ['deployment-inventory.json' => $delta12Inv];
            $delta12Rel = $makeRelease('pos-desktop-updates', 'v0.0.12', $delta12Assets);
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/tags/v0.0.12'] = $delta12Rel;
            $assetMap["https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.12/deployment-inventory.json"] = $delta12Inv;

            // Beta 12.1 release in pos-desktop-updates
            $beta12Assets = [
                'deployment-inventory.json' => $beta12Inv,
                'channel-transitions.json' => $labDir . '/beta12-download/channel-transitions.json',
                'channel-transitions.sig' => $labDir . '/beta12-download/channel-transitions.sig',
            ];
            $beta12Rel = $makeRelease('pos-desktop-updates', 'v0.0.12-beta.1', $beta12Assets);
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/tags/v0.0.12-beta.1'] = $beta12Rel;
            foreach ($beta12Assets as $name => $path) {
                if (is_file($path)) {
                    $assetMap["https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.12-beta.1/{$name}"] = $path;
                }
            }

            // Candidates listing for beta channel
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases?per_page=100&page=1'] = [$beta12Rel];
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases?per_page=100&page=2'] = [];
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases?per_page=100&page=1'] = [$setup12Rel];
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases?per_page=100&page=2'] = [];
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/latest'] = $setup12Rel;
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/tags/v0.0.12-beta.1'] = [
                'http_code' => 404,
                'body' => ['message' => 'Not Found'],
            ];
        } elseif ($mode === 'candidate13-roundtrip') {
            $posDesktopDir = is_dir($assetsDir . '/pos-desktop') ? ($assetsDir . '/pos-desktop') : $assetsDir;
            $posDesktopUpdatesDir = is_dir($assetsDir . '/pos-desktop-updates') ? ($assetsDir . '/pos-desktop-updates') : $assetsDir;

            // Assert exact 2 entries in pos-desktop transition index
            $setup13IndexPath = $posDesktopDir . '/channel-transitions.json';
            if (is_file($setup13IndexPath)) {
                $idxData = json_decode((string)file_get_contents($setup13IndexPath), true, 64, JSON_THROW_ON_ERROR);
                $entryCount = count($idxData['entries'] ?? []);
                if ($entryCount !== 2) {
                    throw new RuntimeException("Assertion failed: pos-desktop channel-transitions.json must have exactly 2 entries, found {$entryCount}");
                }
            }

            // Assert exact 4 entries in pos-desktop-updates transition index
            $updatesIndexPath = $posDesktopUpdatesDir . '/channel-transitions.json';
            if (is_file($updatesIndexPath)) {
                $idxData = json_decode((string)file_get_contents($updatesIndexPath), true, 64, JSON_THROW_ON_ERROR);
                $entryCount = count($idxData['entries'] ?? []);
                if ($entryCount !== 4) {
                    throw new RuntimeException("Assertion failed: pos-desktop-updates channel-transitions.json must have exactly 4 entries, found {$entryCount}");
                }
            }

            // Setup 13 release in pos-desktop (carrier release with 2 transition entries: Setup 13 -> Beta 12.1 delta + full)
            $setup13Assets = [
                'deployment-inventory.json' => $candidateSetupInv,
            ];
            if (is_file($setup13IndexPath)) {
                $setup13Assets['channel-transitions.json'] = $setup13IndexPath;
            }
            if (is_file($posDesktopDir . '/channel-transitions.sig')) {
                $setup13Assets['channel-transitions.sig'] = $posDesktopDir . '/channel-transitions.sig';
            }
            if (is_dir($posDesktopDir)) {
                foreach (scandir($posDesktopDir) as $file) {
                    if (str_starts_with($file, 'transition-') && (str_ends_with($file, '.zip') || str_ends_with($file, '.json') || str_ends_with($file, '.sig'))) {
                        $setup13Assets[$file] = $posDesktopDir . '/' . $file;
                    }
                }
            }
            $setup13Rel = $makeRelease('pos-desktop', 'v0.0.13', $setup13Assets);
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/tags/v0.0.13'] = $setup13Rel;
            foreach ($setup13Assets as $name => $path) {
                if (is_file($path)) {
                    $assetMap["https://github.com/ABDO-TECK/pos-desktop/releases/download/v0.0.13/{$name}"] = $path;
                }
            }

            // Delta 13 release in pos-desktop-updates (carrier release with 4 transition entries: Delta 13 -> Beta 12.1 and Beta 12.1 -> Delta 13 delta + full)
            $delta13Assets = [
                'deployment-inventory.json' => $candidateDeltaInv,
            ];
            if (is_file($updatesIndexPath)) {
                $delta13Assets['channel-transitions.json'] = $updatesIndexPath;
            }
            if (is_file($posDesktopUpdatesDir . '/channel-transitions.sig')) {
                $delta13Assets['channel-transitions.sig'] = $posDesktopUpdatesDir . '/channel-transitions.sig';
            }
            if (is_dir($posDesktopUpdatesDir)) {
                foreach (scandir($posDesktopUpdatesDir) as $file) {
                    if (str_starts_with($file, 'transition-') && (str_ends_with($file, '.zip') || str_ends_with($file, '.json') || str_ends_with($file, '.sig'))) {
                        $delta13Assets[$file] = $posDesktopUpdatesDir . '/' . $file;
                    }
                }
            }
            $delta13Rel = $makeRelease('pos-desktop-updates', 'v0.0.13', $delta13Assets);
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/tags/v0.0.13'] = $delta13Rel;
            foreach ($delta13Assets as $name => $path) {
                if (is_file($path)) {
                    $assetMap["https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.13/{$name}"] = $path;
                }
            }

            // Beta 12.1 release in pos-desktop-updates
            $beta12Assets = [
                'deployment-inventory.json' => $beta12Inv,
            ];
            if (is_file($labDir . '/beta12-download/channel-transitions.json')) {
                $beta12Assets['channel-transitions.json'] = $labDir . '/beta12-download/channel-transitions.json';
            }
            if (is_file($labDir . '/beta12-download/channel-transitions.sig')) {
                $beta12Assets['channel-transitions.sig'] = $labDir . '/beta12-download/channel-transitions.sig';
            }
            $beta12Rel = $makeRelease('pos-desktop-updates', 'v0.0.12-beta.1', $beta12Assets);
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/tags/v0.0.12-beta.1'] = $beta12Rel;
            foreach ($beta12Assets as $name => $path) {
                if (is_file($path)) {
                    $assetMap["https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v0.0.12-beta.1/{$name}"] = $path;
                }
            }

            // Candidate listings
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases?per_page=100&page=1'] = [
                $delta13Rel,
                $beta12Rel,
            ];
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases?per_page=100&page=2'] = [];
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases?per_page=100&page=1'] = [
                $setup13Rel,
            ];
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases?per_page=100&page=2'] = [];
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/latest'] = $setup13Rel;
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop-updates/releases/latest'] = $delta13Rel;
            $responses['https://api.github.com/repos/ABDO-TECK/pos-desktop/releases/tags/v0.0.12-beta.1'] = [
                'http_code' => 404,
                'body' => ['message' => 'Not Found'],
            ];
        } else {
            throw new RuntimeException("Unknown provider fixture mode: {$mode}");
        }

        $tracePath = $evidenceDir . '/provider-trace.jsonl';
        $fixtureData = [
            'mode' => $mode,
            'responses' => $responses,
            'assets' => $assetMap,
            'trace' => $tracePath,
        ];

        file_put_contents($outputPath, json_encode($fixtureData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        return [
            'ok' => true,
            'mode' => $mode,
            'responses_count' => count($responses),
            'assets_count' => count($assetMap),
            'trace_path' => $tracePath,
            'output' => $outputPath,
        ];
    }

    public function seedDb(string $configDir, string $sentinel): array
    {
        $db = $this->connectDatabase($configDir);

        // 1. Seed customer sentinel record
        $stmt = $db->prepare('INSERT INTO customers (branch_id, name, phone, initial_balance, loyalty_points) VALUES (1, ?, ?, 42.50, 20)');
        $phone = '050' . substr(strval(crc32($sentinel)), 0, 7);
        $stmt->execute([$sentinel, $phone]);
        $customerId = (int)$db->lastInsertId();

        // 2. Seed setting sentinel record
        $stmt = $db->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)');
        $settingKey = 'sentinel_' . substr(md5($sentinel), 0, 10);
        $settingValue = 'value_' . bin2hex(random_bytes(8));
        $stmt->execute([$settingKey, $settingValue]);

        return [
            'ok' => true,
            'customer_id' => $customerId,
            'sentinel_name' => $sentinel,
            'setting_key' => $settingKey,
            'setting_value' => $settingValue,
        ];
    }

    public function probeDb(string $configDir, string $sentinel, ?string $settingKey = null): array
    {
        $db = $this->connectDatabase($configDir);

        // 1. Discover trusted repo migrations dynamically
        $migrationsDir = realpath(__DIR__ . '/../../database/migrations');
        if ($migrationsDir === false || !is_dir($migrationsDir)) {
            throw new RuntimeException('Migrations directory missing');
        }
        $trustedRepoMigrations = [];
        foreach (scandir($migrationsDir) ?: [] as $file) {
            if (str_ends_with($file, '.sql') && preg_match('/^[0-9]{3}_[a-zA-Z0-9_\-]+\.sql$/', $file)) {
                $trustedRepoMigrations[] = $file;
            }
        }
        sort($trustedRepoMigrations);
        if (count($trustedRepoMigrations) !== 58) {
            throw new RuntimeException('Expected 58 trusted repo migrations, found ' . count($trustedRepoMigrations));
        }

        // 2. Metadata VIEW existence, type, and driver errno
        $driverErrno = null;
        $metadataObjectExists = null;
        $metadataObjectType = null;
        $metadataQueryOk = null;

        try {
            $stmt = $db->prepare('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute(['product_catalog_trigger_metadata']);
            $viewRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($viewRow)) {
                $metadataObjectExists = true;
                $metadataObjectType = (string)$viewRow['TABLE_TYPE'];
            } else {
                $metadataObjectExists = false;
                $metadataObjectType = null;
            }
        } catch (PDOException $pe) {
            $metadataObjectExists = null;
            $metadataObjectType = null;
            $err = $pe->errorInfo[1] ?? null;
            if (is_int($err) && $err > 0 && $err <= 99999) {
                $driverErrno = $err;
            }
        } catch (Throwable) {
            $metadataObjectExists = null;
            $metadataObjectType = null;
        }

        try {
            $q = $db->query('SELECT 1 FROM product_catalog_trigger_metadata LIMIT 1');
            $metadataQueryOk = $q !== false;
        } catch (PDOException $pe) {
            $metadataQueryOk = false;
            if ($driverErrno === null) {
                $err = $pe->errorInfo[1] ?? null;
                if (is_int($err) && $err > 0 && $err <= 99999) {
                    $driverErrno = $err;
                }
            }
        } catch (Throwable) {
            $metadataQueryOk = false;
        }

        // 3. Ledger names and count from schema_versions
        $ledgerCount = null;
        $latestLedgerVersion = null;
        $canonical58Match = null;
        try {
            $ledgerStmt = $db->query('SELECT version FROM schema_versions ORDER BY version');
            if ($ledgerStmt !== false) {
                $ledgerVersions = $ledgerStmt->fetchAll(PDO::FETCH_COLUMN);
                $ledgerCount = count($ledgerVersions);
                $rawLatest = !empty($ledgerVersions) ? (string)end($ledgerVersions) : null;
                if ($rawLatest !== null && in_array($rawLatest, $trustedRepoMigrations, true)) {
                    $latestLedgerVersion = $rawLatest;
                }
                $canonical58Match = ($ledgerCount === 58 && empty(array_diff($ledgerVersions, $trustedRepoMigrations)));
            }
        } catch (PDOException $pe) {
            $ledgerCount = null;
            $latestLedgerVersion = null;
            $canonical58Match = null;
            if ($driverErrno === null) {
                $err = $pe->errorInfo[1] ?? null;
                if (is_int($err) && $err > 0 && $err <= 99999) {
                    $driverErrno = $err;
                }
            }
        } catch (Throwable) {
            $ledgerCount = null;
            $latestLedgerVersion = null;
            $canonical58Match = null;
        }

        // 4. Sentinel customer boolean
        $sentinelCustomerPresent = null;
        try {
            $stmt = $db->prepare('SELECT 1 FROM customers WHERE name = ? LIMIT 1');
            $stmt->execute([$sentinel]);
            $sentinelCustomerPresent = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $sentinelCustomerPresent = null;
        }

        // 5. Admin user boolean
        $adminUserPresent = null;
        try {
            $stmt = $db->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1");
            $adminUserPresent = $stmt !== false ? (bool)$stmt->fetchColumn() : null;
        } catch (Throwable) {
            $adminUserPresent = null;
        }

        // 6. Sentinel setting boolean
        $sentinelSettingPresent = null;
        try {
            $resolvedSettingKey = $settingKey ?? ('sentinel_' . substr(md5($sentinel), 0, 10));
            $stmt = $db->prepare('SELECT 1 FROM settings WHERE `key` = ? LIMIT 1');
            $stmt->execute([$resolvedSettingKey]);
            $sentinelSettingPresent = (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            $sentinelSettingPresent = null;
        }

        return [
            'ok' => true,
            'metadata_object_exists' => $metadataObjectExists,
            'metadata_object_type' => $metadataObjectType,
            'metadata_query_ok' => $metadataQueryOk,
            'driver_errno' => $driverErrno,
            'ledger_count' => $ledgerCount,
            'latest_ledger_version' => $latestLedgerVersion,
            'canonical_58_match' => $canonical58Match,
            'sentinel_customer_present' => $sentinelCustomerPresent,
            'admin_user_present' => $adminUserPresent,
            'sentinel_setting_present' => $sentinelSettingPresent,
        ];
    }

    public function inspectDb(string $configDir, string $sentinel, ?string $settingKey = null): array
    {
        $db = $this->connectDatabase($configDir);
        $service = new UpdateDataCompatibilityService();
        $description = $service->describe($db);

        // Raw schema tables structure for raw baseline comparison
        $rawSchema = [];
        foreach ($db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM) as $row) {
            $table = $row[0];
            $create = $db->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM)[1];
            $rawSchema[$table] = preg_replace('/AUTO_INCREMENT=\d+\s*/', '', $create);
        }
        ksort($rawSchema);
        $rawSchemaSha256 = hash('sha256', json_encode($rawSchema, JSON_THROW_ON_ERROR));

        // Schema versions ledger - full raw rows (actual schema_versions has id, version, executed_at - NO batch column)
        $ledgerRows = $db->query('SELECT * FROM schema_versions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $ledgerVersions = array_column($ledgerRows, 'version');

        // Sentinel customer record
        $stmt = $db->prepare('SELECT id, branch_id, name, phone, initial_balance, loyalty_points FROM customers WHERE name = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$sentinel]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        // All sentinel customers (to check mutations)
        $allCustomers = $db->query("SELECT id, name, phone FROM customers WHERE name LIKE '%sentinel%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        // Sentinel setting record
        $resolvedSettingKey = $settingKey ?? ('sentinel_' . substr(md5($sentinel), 0, 10));
        $stmt = $db->prepare('SELECT `key`, `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$resolvedSettingKey]);
        $setting = $stmt->fetch(PDO::FETCH_ASSOC);

        // Admin row (including password hash for digest)
        $admin = $db->query("SELECT id, name, email, password, role FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $adminDigest = $admin ? hash('sha256', json_encode($admin, JSON_THROW_ON_ERROR)) : null;

        // Canonical component fingerprints/counts from describe schema (PRIVATE ONLY)
        $knownBucketFields = [
            'tables' => ['name', 'type', 'engine', 'collation'],
            'columns' => ['table_name', 'name', 'position', 'type', 'nullable', 'default_value', 'extra', 'charset_name', 'collation'],
            'indexes' => ['table_name', 'name', 'non_unique', 'position', 'column_name', 'collation', 'sub_part', 'type'],
            'keys' => ['table_name', 'name', 'column_name', 'position', 'referenced_table', 'referenced_column'],
            'references' => ['table_name', 'name', 'referenced_table', 'update_rule', 'delete_rule', 'match_option'],
            'checks' => ['table_name', 'name', 'clause_text'],
            'objects' => ['object_type', 'object_name', 'definition', 'context', 'table_name'],
        ];

        $canonicalComponents = [
            'buckets' => [],
        ];
        $schemaBuckets = is_array($description['schema'] ?? null) ? $description['schema'] : [];
        foreach ($knownBucketFields as $bucket => $fields) {
            $rows = is_array($schemaBuckets[$bucket] ?? null) ? $schemaBuckets[$bucket] : [];
            $fieldHashes = [];
            foreach ($fields as $field) {
                $scalars = [];
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $val = $row[$field] ?? null;
                        $scalars[] = $val === null ? null : (string)$val;
                    }
                }
                usort($scalars, static function (?string $a, ?string $b): int {
                    if ($a === null && $b === null) return 0;
                    if ($a === null) return -1;
                    if ($b === null) return 1;
                    return strcmp($a, $b);
                });
                $fieldHashes[$field] = hash('sha256', json_encode($scalars, JSON_THROW_ON_ERROR));
            }
            $canonicalComponents['buckets'][$bucket] = [
                'count' => count($rows),
                'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
                'fields' => $fieldHashes,
            ];
        }

        $canonicalComponents['ledger'] = [
            'count' => count(is_array($description['ledger'] ?? null) ? $description['ledger'] : []),
            'sha256' => (string)($description['ledger_sha256'] ?? ''),
        ];

        return [
            'ok' => true,
            'schema_sha256' => $description['schema_sha256'],
            'ledger_sha256' => $description['ledger_sha256'],
            'raw_schema_sha256' => $rawSchemaSha256,
            'canonical_components' => $canonicalComponents,
            'ledger_count' => count($ledgerVersions),
            'ledger' => $ledgerVersions,
            'ledger_rows' => $ledgerRows,
            'customer' => $customer ?: null,
            'setting' => $setting ?: null,
            'all_customers' => $allCustomers,
            'admin_digest' => $adminDigest,
            'admin_email' => $admin['email'] ?? null,
        ];
    }

    public function mutateDb(string $configDir, string $originalSentinel, string $mutatedSentinel): array
    {
        $db = $this->connectDatabase($configDir);

        $stmt = $db->prepare('UPDATE customers SET name = ? WHERE name = ?');
        $stmt->execute([$mutatedSentinel, $originalSentinel]);
        $affected = $stmt->rowCount();

        return [
            'ok' => true,
            'affected' => $affected,
            'original' => $originalSentinel,
            'mutated' => $mutatedSentinel,
        ];
    }

    public function prepareLegacyV025Backup(string $authPath, string $outputPath): array
    {
        $receipt = $this->assertOwnershipReceipt();

        $canonicalLab = str_replace('\\', '/', $this->runnerTemp) . '/setup12-regression-lab';
        $receiptLab = rtrim(str_replace('\\', '/', (string)($receipt['lab'] ?? '')), '/');
        if (strtolower($receiptLab) !== strtolower($canonicalLab)) {
            throw new RuntimeException('Ownership receipt lab is not canonical runnerTemp/setup12-regression-lab');
        }

        $safeAuth = $this->assertSafeRunnerTempPath($authPath, true);
        $canonicalAuth = $canonicalLab . '/fixture-auth.json';
        if (strtolower(str_replace('\\', '/', $safeAuth)) !== strtolower($canonicalAuth)) {
            throw new RuntimeException('Fixture auth path must be EXACT canonical lab fixture-auth.json');
        }

        $safeOutput = $this->assertSafeRunnerTempPath($outputPath, false);
        $canonicalOutput = $canonicalLab . '/legacy-v025-backup.sql';
        if (strtolower(str_replace('\\', '/', $safeOutput)) !== strtolower($canonicalOutput)) {
            throw new RuntimeException('Output path must be EXACT canonical lab legacy-v025-backup.sql');
        }

        if (file_exists($safeOutput) || is_link($safeOutput)) {
            throw new RuntimeException('Output path already exists; fresh output required');
        }

        $authRaw = (string)file_get_contents($safeAuth);
        $auth = json_decode($authRaw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($auth) || empty($auth['email']) || empty($auth['password'])) {
            throw new RuntimeException('Invalid fixture auth credentials');
        }

        $fixturePath = realpath(__DIR__ . '/legacy-v025-schema.sql');
        if ($fixturePath === false || !is_file($fixturePath)) {
            throw new RuntimeException('Missing legacy-v025-schema.sql template');
        }

        $sql = (string)file_get_contents($fixturePath);

        if (substr_count($sql, "'__ADMIN_EMAIL__'") !== 1 || substr_count($sql, "'__ADMIN_PASSWORD_HASH__'") !== 1) {
            throw new RuntimeException('Template must contain exactly one quoted admin email and password placeholder');
        }
        if (preg_match('/AUTO_INCREMENT=\d+/i', $sql)) {
            throw new RuntimeException('Template must not contain table AUTO_INCREMENT=N options');
        }
        $ddlMatches = [];
        preg_match_all('/CREATE TABLE `([a-zA-Z0-9_]+)`/i', $sql, $ddlMatches);
        $tableCount = count($ddlMatches[1] ?? []);
        if ($tableCount !== 27) {
            throw new RuntimeException("Template must contain exactly 27 CREATE TABLE statements, found {$tableCount}");
        }
        if (preg_match('/0(?:2[6-9]|[3-9][0-9])_[a-zA-Z0-9_\-]+\.sql/i', $sql)) {
            throw new RuntimeException('Template must not contain migrations 026 or above');
        }

        // Isolate exact INSERT INTO `schema_versions` block with unique one guard
        $schemaInsertMatches = [];
        if (preg_match_all('/INSERT INTO `schema_versions`\s*\([^)]+\)\s*VALUES\s*([\s\S]+?);/i', $sql, $schemaInsertMatches) !== 1) {
            throw new RuntimeException('Template must contain exactly one INSERT INTO `schema_versions` block');
        }
        $schemaValuesBlock = $schemaInsertMatches[1][0];

        $versionRowMatches = [];
        preg_match_all("/\(\s*(\d+)\s*,\s*'([0-9a-zA-Z_\.\-]+)'\s*,\s*'([^']+)'\s*\)/", $schemaValuesBlock, $versionRowMatches, PREG_SET_ORDER);
        $versionCount = count($versionRowMatches);
        if ($versionCount !== 25) {
            throw new RuntimeException("Template must contain exactly 25 seed version rows in schema_versions, found {$versionCount}");
        }

        $migrationsDir = realpath(__DIR__ . '/../../database/migrations');
        if ($migrationsDir === false || !is_dir($migrationsDir)) {
            throw new RuntimeException('Migrations directory missing');
        }
        $expectedFiles = [];
        foreach (scandir($migrationsDir) ?: [] as $file) {
            if (str_ends_with($file, '.sql') && preg_match('/^[0-9]{3}_[a-zA-Z0-9_\-]+\.sql$/', $file)) {
                $num = (int)substr($file, 0, 3);
                if ($num >= 2 && $num <= 25) {
                    $expectedFiles[] = $file;
                }
            }
        }
        sort($expectedFiles);
        if (count($expectedFiles) !== 24) {
            throw new RuntimeException('Expected 24 trusted migration files 002..025, found ' . count($expectedFiles));
        }
        $expectedLegacySet = array_merge($expectedFiles, ['016_add_refresh_tokens']);
        sort($expectedLegacySet);

        $actualVersionNames = [];
        foreach ($versionRowMatches as $match) {
            $vName = $match[2];
            $vTime = $match[3];
            if ($vTime !== '2000-01-01 00:00:00') {
                throw new RuntimeException("Seed version {$vName} has invalid timestamp '{$vTime}', expected '2000-01-01 00:00:00'");
            }
            $actualVersionNames[] = $vName;
        }
        sort($actualVersionNames);

        if ($actualVersionNames !== $expectedLegacySet) {
            throw new RuntimeException('Seed versions in schema_versions block do not match trusted 002..025 + extensionless 016');
        }

        $passwordHash = password_hash((string)$auth['password'], PASSWORD_DEFAULT);

        $escapedEmail = str_replace(["\\", "'", "\0", "\r", "\n"], ["\\\\", "\\'", '', '', ''], (string)$auth['email']);
        $escapedHash = str_replace(["\\", "'", "\0", "\r", "\n"], ["\\\\", "\\'", '', '', ''], $passwordHash);

        $sql = str_replace('__ADMIN_EMAIL__', $escapedEmail, $sql);
        $sql = str_replace('__ADMIN_PASSWORD_HASH__', $escapedHash, $sql);

        if (str_contains($sql, '__ADMIN_EMAIL__') || str_contains($sql, '__ADMIN_PASSWORD_HASH__')) {
            throw new RuntimeException('Unsubstituted admin placeholders detected');
        }

        $fh = @fopen($safeOutput, 'xb');
        if ($fh === false) {
            throw new RuntimeException('Failed to create output file exclusively');
        }
        $written = fwrite($fh, $sql);
        fclose($fh);
        if ($written !== strlen($sql)) {
            throw new RuntimeException('Failed to write complete SQL backup');
        }

        return [
            'ok' => true,
            'bytes' => strlen($sql),
            'table_count' => $tableCount,
            'version_count' => $versionCount,
        ];
    }

    public function prepareLegacyV025VariantBackup(string $variant, string $authPath, string $outputPath): array
    {
        $allowedVariants = [
            'product_quantity_out_of_range',
            'invoice_item_quantity_out_of_range',
            'purchase_quantity_out_of_range',
            'orphan_customer',
            'unknown_schema',
        ];
        if (!in_array($variant, $allowedVariants, true)) {
            throw new RuntimeException("Unauthorized legacy v025 variant: {$variant}");
        }

        $receipt = $this->assertOwnershipReceipt();

        $canonicalLab = str_replace('\\', '/', $this->runnerTemp) . '/setup12-regression-lab';
        $receiptLab = rtrim(str_replace('\\', '/', (string)($receipt['lab'] ?? '')), '/');
        if (strtolower($receiptLab) !== strtolower($canonicalLab)) {
            throw new RuntimeException('Ownership receipt lab is not canonical runnerTemp/setup12-regression-lab');
        }

        $safeAuth = $this->assertSafeRunnerTempPath($authPath, true);
        $canonicalAuth = $canonicalLab . '/fixture-auth.json';
        if (strtolower(str_replace('\\', '/', $safeAuth)) !== strtolower($canonicalAuth)) {
            throw new RuntimeException('Fixture auth path must be EXACT canonical lab fixture-auth.json');
        }

        $safeOutput = $this->assertSafeRunnerTempPath($outputPath, false);
        $normOutput = str_replace('\\', '/', $safeOutput);
        if (!str_starts_with(strtolower($normOutput), strtolower($canonicalLab) . '/')) {
            throw new RuntimeException('Variant output path must reside inside canonical lab');
        }
        if (!str_ends_with(strtolower($normOutput), '.sql')) {
            throw new RuntimeException('Variant output path must end with .sql');
        }
        if (strtolower(basename($normOutput)) === 'legacy-v025-backup.sql') {
            throw new RuntimeException('Variant output cannot overwrite canonical legacy-v025-backup.sql');
        }
        if (file_exists($safeOutput) || is_link($safeOutput)) {
            throw new RuntimeException('Output path already exists; fresh output required');
        }

        $authRaw = (string)file_get_contents($safeAuth);
        $auth = json_decode($authRaw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($auth) || empty($auth['email']) || empty($auth['password'])) {
            throw new RuntimeException('Invalid fixture auth credentials');
        }

        $fixturePath = realpath(__DIR__ . '/legacy-v025-schema.sql');
        if ($fixturePath === false || !is_file($fixturePath)) {
            throw new RuntimeException('Missing legacy-v025-schema.sql template');
        }

        $sql = (string)file_get_contents($fixturePath);

        if (substr_count($sql, "'__ADMIN_EMAIL__'") !== 1 || substr_count($sql, "'__ADMIN_PASSWORD_HASH__'") !== 1) {
            throw new RuntimeException('Template must contain exactly one quoted admin email and password placeholder');
        }
        if (preg_match('/AUTO_INCREMENT=\d+/i', $sql)) {
            throw new RuntimeException('Template must not contain table AUTO_INCREMENT=N options');
        }
        $ddlMatches = [];
        preg_match_all('/CREATE TABLE `([a-zA-Z0-9_]+)`/i', $sql, $ddlMatches);
        $tableCount = count($ddlMatches[1] ?? []);
        if ($tableCount !== 27) {
            throw new RuntimeException("Template must contain exactly 27 CREATE TABLE statements, found {$tableCount}");
        }
        if (preg_match('/0(?:2[6-9]|[3-9][0-9])_[a-zA-Z0-9_\-]+\.sql/i', $sql)) {
            throw new RuntimeException('Template must not contain migrations 026 or above');
        }

        $schemaInsertMatches = [];
        if (preg_match_all('/INSERT INTO `schema_versions`\s*\([^)]+\)\s*VALUES\s*([\s\S]+?);/i', $sql, $schemaInsertMatches) !== 1) {
            throw new RuntimeException('Template must contain exactly one INSERT INTO `schema_versions` block');
        }
        $schemaValuesBlock = $schemaInsertMatches[1][0];

        $versionRowMatches = [];
        preg_match_all("/\(\s*(\d+)\s*,\s*'([0-9a-zA-Z_\.\-]+)'\s*,\s*'([^']+)'\s*\)/", $schemaValuesBlock, $versionRowMatches, PREG_SET_ORDER);
        $versionCount = count($versionRowMatches);
        if ($versionCount !== 25) {
            throw new RuntimeException("Template must contain exactly 25 seed version rows in schema_versions, found {$versionCount}");
        }

        $migrationsDir = realpath(__DIR__ . '/../../database/migrations');
        if ($migrationsDir === false || !is_dir($migrationsDir)) {
            throw new RuntimeException('Migrations directory missing');
        }
        $expectedFiles = [];
        foreach (scandir($migrationsDir) ?: [] as $file) {
            if (str_ends_with($file, '.sql') && preg_match('/^[0-9]{3}_[a-zA-Z0-9_\-]+\.sql$/', $file)) {
                $num = (int)substr($file, 0, 3);
                if ($num >= 2 && $num <= 25) {
                    $expectedFiles[] = $file;
                }
            }
        }
        sort($expectedFiles);
        if (count($expectedFiles) !== 24) {
            throw new RuntimeException('Expected 24 trusted migration files 002..025, found ' . count($expectedFiles));
        }
        $expectedLegacySet = array_merge($expectedFiles, ['016_add_refresh_tokens']);
        sort($expectedLegacySet);

        $actualVersionNames = [];
        foreach ($versionRowMatches as $match) {
            $vName = $match[2];
            $vTime = $match[3];
            if ($vTime !== '2000-01-01 00:00:00') {
                throw new RuntimeException("Seed version {$vName} has invalid timestamp '{$vTime}', expected '2000-01-01 00:00:00'");
            }
            $actualVersionNames[] = $vName;
        }
        sort($actualVersionNames);

        if ($actualVersionNames !== $expectedLegacySet) {
            throw new RuntimeException('Seed versions in schema_versions block do not match trusted 002..025 + extensionless 016');
        }

        $passwordHash = password_hash((string)$auth['password'], PASSWORD_DEFAULT);
        $escapedEmail = str_replace(["\\", "'", "\0", "\r", "\n"], ["\\\\", "\\'", '', '', ''], (string)$auth['email']);
        $escapedHash = str_replace(["\\", "'", "\0", "\r", "\n"], ["\\\\", "\\'", '', '', ''], $passwordHash);

        $sql = str_replace('__ADMIN_EMAIL__', $escapedEmail, $sql);
        $sql = str_replace('__ADMIN_PASSWORD_HASH__', $escapedHash, $sql);

        if (str_contains($sql, '__ADMIN_EMAIL__') || str_contains($sql, '__ADMIN_PASSWORD_HASH__')) {
            throw new RuntimeException('Unsubstituted admin placeholders detected');
        }

        // Apply targeted negative variant mutation
        switch ($variant) {
            case 'product_quantity_out_of_range':
                $search = "(1, 'codex_legacy_v025_product', '6220000000001', NULL, 10.00, 5.00, 50, 0, 5, 1, 1, '2000-01-01 00:00:00', '2000-01-01 00:00:00', NULL, 1);";
                $replace = "(1, 'codex_legacy_v025_product', '6220000000001', NULL, 10.00, 5.00, 2147483647, 0, 5, 1, 1, '2000-01-01 00:00:00', '2000-01-01 00:00:00', NULL, 1);";
                if (substr_count($sql, $search) !== 1) {
                    throw new RuntimeException("Target product row for {$variant} not found uniquely");
                }
                $sql = str_replace($search, $replace, $sql);
                break;

            case 'invoice_item_quantity_out_of_range':
                $search = "(1, 1, 1, 1, 10.00, 5.00, 10.00);";
                $replace = "(1, 1, 1, 2147483647, 10.00, 5.00, 10.00);";
                if (substr_count($sql, $search) !== 1) {
                    throw new RuntimeException("Target invoice_item row for {$variant} not found uniquely");
                }
                $sql = str_replace($search, $replace, $sql);
                break;

            case 'purchase_quantity_out_of_range':
                $search = "(1, 1, 1, 1, 10, 5.00, 50.00, NULL, '2000-01-01 00:00:00');";
                $replace = "(1, 1, 1, 1, 2147483647, 5.00, 50.00, NULL, '2000-01-01 00:00:00');";
                if (substr_count($sql, $search) !== 1) {
                    throw new RuntimeException("Target purchase row for {$variant} not found uniquely");
                }
                $sql = str_replace($search, $replace, $sql);
                break;

            case 'orphan_customer':
                $search = "(1, 1, 1, 10.00, 0.00, 0.00, 10.00, 'cash', 10.00, 0.00, 0.00, 'completed', '2000-01-01 00:00:00', NULL, 1);";
                $replace = "(1, 1, 999999, 10.00, 0.00, 0.00, 10.00, 'cash', 10.00, 0.00, 0.00, 'completed', '2000-01-01 00:00:00', NULL, 1);";
                if (substr_count($sql, $search) !== 1) {
                    throw new RuntimeException("Target invoice row for {$variant} not found uniquely");
                }
                $sql = str_replace($search, $replace, $sql);
                break;

            case 'unknown_schema':
                $search = "CREATE TABLE `categories` (\n  `id` int(10) NOT NULL AUTO_INCREMENT,\n  `name` varchar(100) NOT NULL,";
                $replace = "CREATE TABLE `categories` (\n  `id` int(10) NOT NULL AUTO_INCREMENT,\n  `name` varchar(100) NOT NULL,\n  `codex_unrecognized_column` varchar(255) DEFAULT NULL,";
                if (substr_count($sql, $search) !== 1) {
                    throw new RuntimeException("Target categories table DDL for {$variant} not found uniquely");
                }
                $sql = str_replace($search, $replace, $sql);
                break;

            default:
                throw new RuntimeException("Unhandled variant: {$variant}");
        }

        $fh = @fopen($safeOutput, 'xb');
        if ($fh === false) {
            throw new RuntimeException('Failed to create output file exclusively');
        }
        $written = fwrite($fh, $sql);
        fclose($fh);
        if ($written !== strlen($sql)) {
            throw new RuntimeException('Failed to write complete SQL backup');
        }

        return [
            'ok' => true,
            'variant' => $variant,
            'bytes' => strlen($sql),
            'table_count' => $tableCount,
            'version_count' => $versionCount,
        ];
    }

    public function captureLegacyV025SchemaBaseline(string $configDir, string $contractPath, string $outputPath): array
    {
        $receipt = $this->assertOwnershipReceipt();

        $canonicalLab = str_replace('\\', '/', $this->runnerTemp) . '/setup12-regression-lab';
        $receiptLab = rtrim(str_replace('\\', '/', (string)($receipt['lab'] ?? '')), '/');
        if (strtolower($receiptLab) !== strtolower($canonicalLab)) {
            throw new RuntimeException('Ownership receipt lab is not canonical runnerTemp/setup12-regression-lab');
        }

        $safeContract = $this->assertSafeRunnerTempPath($contractPath, true);
        $canonicalContract = $canonicalLab . '/data-contract.json';
        if (strtolower(str_replace('\\', '/', $safeContract)) !== strtolower($canonicalContract)) {
            throw new RuntimeException('Contract path must be EXACT canonical lab data-contract.json');
        }

        $safeOutput = $this->assertSafeRunnerTempPath($outputPath, false);
        $canonicalOutput = $canonicalLab . '/legacy-v025-schema-baseline.json';
        if (strtolower(str_replace('\\', '/', $safeOutput)) !== strtolower($canonicalOutput)) {
            throw new RuntimeException('Output path must be EXACT canonical lab legacy-v025-schema-baseline.json');
        }

        if (file_exists($safeOutput) || is_link($safeOutput)) {
            throw new RuntimeException('Output path already exists; fresh output required');
        }

        $contractRaw = (string)file_get_contents($safeContract);
        $contractData = json_decode($contractRaw, true, 32, JSON_THROW_ON_ERROR);
        $expectedSchemaSha = (string)($contractData['schema_sha256'] ?? $contractData['contract']['schema_sha256'] ?? '');
        $expectedLedgerSha = (string)($contractData['ledger_sha256'] ?? $contractData['contract']['ledger_sha256'] ?? '');
        if ($expectedSchemaSha === '' || $expectedLedgerSha === '') {
            throw new RuntimeException('Contract file missing required schema_sha256 or ledger_sha256');
        }

        $db = $this->connectDatabase($configDir);
        $service = new UpdateDataCompatibilityService();
        $description = $service->describe($db);

        if ($description['schema_sha256'] !== $expectedSchemaSha) {
            throw new RuntimeException('Captured pre-Test5 schema SHA256 does not match immutable contract');
        }
        if ($description['ledger_sha256'] !== $expectedLedgerSha) {
            throw new RuntimeException('Captured pre-Test5 ledger SHA256 does not match immutable contract');
        }

        $baselinePayload = [
            'format' => 1,
            'schema' => $description['schema'],
            'ledger' => $description['ledger'],
        ];

        $encoded = json_encode($baselinePayload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $fh = @fopen($safeOutput, 'xb');
        if ($fh === false) {
            throw new RuntimeException('Failed to create schema baseline file exclusively');
        }
        $written = fwrite($fh, $encoded);
        fclose($fh);
        if ($written !== strlen($encoded)) {
            throw new RuntimeException('Failed to write complete schema baseline file');
        }

        return [
            'ok' => true,
            'tables_count' => count($description['schema']['tables'] ?? []),
            'columns_count' => count($description['schema']['columns'] ?? []),
            'indexes_count' => count($description['schema']['indexes'] ?? []),
            'keys_count' => count($description['schema']['keys'] ?? []),
            'references_count' => count($description['schema']['references'] ?? []),
            'checks_count' => count($description['schema']['checks'] ?? []),
            'objects_count' => count($description['schema']['objects'] ?? []),
            'ledger_count' => count($description['ledger'] ?? []),
        ];
    }

    public function verifyLegacyV025Upgrade(string $configDir, string $authPath, string $contractPath, string $baselinePath = ''): array
    {
        $safeAuth = $this->assertSafeRunnerTempPath($authPath, true);
        if (basename($safeAuth) !== 'fixture-auth.json') {
            throw new RuntimeException('Fixture auth path must be fixture-auth.json');
        }
        $safeContract = $this->assertSafeRunnerTempPath($contractPath, true);

        $canonicalLab = str_replace('\\', '/', $this->runnerTemp) . '/setup12-regression-lab';
        if ($baselinePath === '') {
            $baselinePath = $canonicalLab . '/legacy-v025-schema-baseline.json';
        }
        $safeBaseline = $this->assertSafeRunnerTempPath($baselinePath, true);
        $canonicalBaseline = $canonicalLab . '/legacy-v025-schema-baseline.json';
        if (strtolower(str_replace('\\', '/', $safeBaseline)) !== strtolower($canonicalBaseline)) {
            throw new RuntimeException('Baseline path must be EXACT canonical lab legacy-v025-schema-baseline.json');
        }
        if (!is_file($safeBaseline)) {
            throw new RuntimeException("Schema baseline missing: {$safeBaseline}");
        }

        $baselineRaw = (string)file_get_contents($safeBaseline);
        $baselineData = json_decode($baselineRaw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($baselineData) || (int)($baselineData['format'] ?? 0) !== 1) {
            throw new RuntimeException('Invalid baseline format; expected format 1');
        }
        $baselineSchema = $baselineData['schema'] ?? null;
        $baselineLedger = $baselineData['ledger'] ?? null;
        if (!is_array($baselineSchema) || !is_array($baselineLedger)) {
            throw new RuntimeException('Baseline missing required schema or ledger structures');
        }

        $authRaw = (string)file_get_contents($safeAuth);
        $auth = json_decode($authRaw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($auth) || empty($auth['email']) || empty($auth['password'])) {
            throw new RuntimeException('Invalid fixture auth credentials');
        }

        $contractData = json_decode((string)file_get_contents($safeContract), true, 32, JSON_THROW_ON_ERROR);
        $expectedSchemaSha = (string)($contractData['schema_sha256'] ?? $contractData['contract']['schema_sha256'] ?? '');
        $expectedLedgerSha = (string)($contractData['ledger_sha256'] ?? $contractData['contract']['ledger_sha256'] ?? '');
        if ($expectedSchemaSha === '' || $expectedLedgerSha === '') {
            throw new RuntimeException('Contract file missing required schema_sha256 or ledger_sha256');
        }

        $baselineSchemaSha = hash('sha256', json_encode($baselineSchema, JSON_THROW_ON_ERROR));
        $baselineLedgerSha = hash('sha256', json_encode($baselineLedger, JSON_THROW_ON_ERROR));
        if ($baselineSchemaSha !== $expectedSchemaSha) {
            throw new RuntimeException('Decoded baseline schema SHA256 does not match original immutable contract');
        }
        if ($baselineLedgerSha !== $expectedLedgerSha) {
            throw new RuntimeException('Decoded baseline ledger SHA256 does not match original immutable contract');
        }

        $db = $this->connectDatabase($configDir);

        $migrationsDir = realpath(__DIR__ . '/../../database/migrations/');
        if ($migrationsDir === false || !is_dir($migrationsDir)) {
            throw new RuntimeException('Migrations directory missing');
        }
        $expectedAll = [];
        foreach (scandir($migrationsDir) ?: [] as $file) {
            if (str_ends_with($file, '.sql') && preg_match('/^[0-9]{3}_[a-zA-Z0-9_\-]+\.sql$/', $file)) {
                $expectedAll[] = $file;
            }
        }
        sort($expectedAll);
        if (count($expectedAll) !== 58) {
            throw new RuntimeException('Expected exactly 58 migration files in repo, found ' . count($expectedAll));
        }

        $expectedLegacy = array_values(array_filter($expectedAll, static fn(string $f): bool => (int)substr($f, 0, 3) <= 25));
        $expectedPending = array_values(array_filter($expectedAll, static fn(string $f): bool => (int)substr($f, 0, 3) >= 26));

        $stmt = $db->query('SELECT id, version, executed_at FROM schema_versions ORDER BY version ASC');
        $ledgerRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $actualVersions = array_column($ledgerRows, 'version');
        sort($actualVersions);

        $exactAll58Match = ($actualVersions === $expectedAll);

        $legacyTimestampsPreserved = true;
        $pendingTimestampsNew = true;
        $actualVersionMap = [];
        $legacyRetainedCount = 0;
        $pendingAppliedCount = 0;
        foreach ($ledgerRows as $r) {
            $v = (string)$r['version'];
            $ts = (string)($r['executed_at'] ?? '');
            $actualVersionMap[$v] = $ts;
            if (str_starts_with($ts, '2000-01-01')) {
                $legacyRetainedCount++;
            } else {
                $pendingAppliedCount++;
            }
        }

        foreach ($expectedLegacy as $lf) {
            $ts = $actualVersionMap[$lf] ?? '';
            if (!str_starts_with($ts, '2000-01-01')) {
                $legacyTimestampsPreserved = false;
                break;
            }
        }

        foreach ($expectedPending as $pf) {
            $ts = $actualVersionMap[$pf] ?? '';
            if ($ts === '' || str_starts_with($ts, '2000-01-01')) {
                $pendingTimestampsNew = false;
                break;
            }
        }

        $duplicate016Gone = !in_array('016_add_refresh_tokens', $actualVersions, true) && in_array('016_add_refresh_tokens.sql', $actualVersions, true);

        $checkMigrations = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations'")->fetchColumn();
        $legacyMigrationsTableDropped = ($checkMigrations === false || $checkMigrations === null);

        $viewCheck = $db->query("SELECT 1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_catalog_trigger_metadata'")->fetchColumn();
        $catalogViewPresent = ($viewCheck !== false && $viewCheck !== null);

        $requiredTriggers = ['trg_products_catalog_insert', 'trg_products_catalog_update', 'trg_products_catalog_delete'];
        $actualTriggers = [];
        if ($catalogViewPresent) {
            $trgStmt = $db->query('SELECT TRIGGER_NAME FROM product_catalog_trigger_metadata WHERE TRIGGER_NAME IS NOT NULL');
            $actualTriggers = $trgStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
        $catalogTriggersPresent = (count(array_intersect($requiredTriggers, $actualTriggers)) === 3);

        $hasUnitType = false;
        $colStmt = $db->query("SHOW COLUMNS FROM products LIKE 'unit_type'");
        if ($colStmt && $colStmt->fetch()) {
            $hasUnitType = true;
        }

        $hasFractional = false;
        $invCol = $db->query("SHOW COLUMNS FROM inventory_events LIKE 'delta'")->fetch(PDO::FETCH_ASSOC);
        if ($invCol && str_contains(strtolower((string)($invCol['Type'] ?? '')), 'decimal')) {
            $hasFractional = true;
        }

        $custBranch = $db->query("SHOW COLUMNS FROM customers LIKE 'branch_id'")->fetch(PDO::FETCH_ASSOC);
        $customerBranchInferred = ($custBranch !== false);

        $suppBranch = $db->query("SHOW COLUMNS FROM suppliers LIKE 'branch_id'")->fetch(PDO::FETCH_ASSOC);
        $supplierBranchInferred = ($suppBranch !== false);

        $hasCatalogChanges = (bool)$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_catalog_changes'")->fetchColumn();

        $adminRow = $db->query("SELECT id, name, email, password, role, is_active FROM users WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $adminVerified = (
            $adminRow !== false &&
            (int)$adminRow['id'] === 1 &&
            $adminRow['role'] === 'admin' &&
            (int)$adminRow['is_active'] === 1 &&
            $adminRow['email'] === (string)$auth['email'] &&
            password_verify((string)$auth['password'], (string)$adminRow['password'])
        );

        $catRow = $db->query("SELECT id, name FROM categories WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $catOk = ($catRow !== false && $catRow['name'] === 'codex_legacy_v025_category');

        $custRow = $db->query("SELECT id, name, phone, initial_balance, branch_id FROM customers WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $custOk = ($custRow !== false && $custRow['name'] === 'codex_legacy_v025_customer' && $custRow['phone'] === null && (float)$custRow['initial_balance'] == 0.00 && (int)$custRow['branch_id'] === 1);

        $suppRow = $db->query("SELECT id, name, phone, email, initial_balance, branch_id FROM suppliers WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $suppOk = ($suppRow !== false && $suppRow['name'] === 'codex_legacy_v025_supplier' && $suppRow['phone'] === null && $suppRow['email'] === 'codex_supplier@example.invalid' && (float)$suppRow['initial_balance'] == 0.00 && (int)$suppRow['branch_id'] === 1);

        $prodRow = $db->query("SELECT id, name, barcode, price, cost, quantity, unit_type, branch_id FROM products WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $prodOk = ($prodRow !== false && $prodRow['name'] === 'codex_legacy_v025_product' && $prodRow['barcode'] === '6220000000001' && (float)$prodRow['price'] == 10.00 && (float)$prodRow['cost'] == 5.00 && (int)$prodRow['quantity'] === 50 && $prodRow['unit_type'] === 'piece' && (int)$prodRow['branch_id'] === 1);

        $barRow = $db->query("SELECT id, product_id, barcode FROM product_barcodes WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $barcodeOk = ($barRow !== false && (int)$barRow['product_id'] === 1 && $barRow['barcode'] === '6220000000002');

        $phRow = $db->query("SELECT id, product_id, old_price, new_price, old_cost, new_cost FROM price_history WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $priceHistOk = ($phRow !== false && (int)$phRow['product_id'] === 1 && (float)$phRow['old_price'] == 8.00 && (float)$phRow['new_price'] == 10.00 && (float)$phRow['old_cost'] == 4.00 && (float)$phRow['new_cost'] == 5.00);

        $invRow = $db->query("SELECT id, user_id, customer_id, total, status, branch_id FROM invoices WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $invOk = ($invRow !== false && (int)$invRow['customer_id'] === 1 && (float)$invRow['total'] == 10.00 && $invRow['status'] === 'completed' && (int)$invRow['branch_id'] === 1);

        $itemRow = $db->query("SELECT id, invoice_id, product_id, quantity, price, subtotal FROM invoice_items WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $invItemOk = ($itemRow !== false && (int)$itemRow['invoice_id'] === 1 && (int)$itemRow['product_id'] === 1 && (int)$itemRow['quantity'] === 1 && (float)$itemRow['price'] == 10.00 && (float)$itemRow['subtotal'] == 10.00);

        $clRow = $db->query("SELECT id, customer_id, type, amount, description, invoice_id, branch_id FROM customer_ledger WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $custLedgerOk = ($clRow !== false && (int)$clRow['customer_id'] === 1 && $clRow['type'] === 'debit' && (float)$clRow['amount'] == 10.00 && (int)$clRow['invoice_id'] === 1 && (int)$clRow['branch_id'] === 1);

        $piRow = $db->query("SELECT id, supplier_id, total, items_count, branch_id FROM purchase_invoices WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $purInvOk = ($piRow !== false && (int)$piRow['supplier_id'] === 1 && (float)$piRow['total'] == 50.00 && (int)$piRow['items_count'] === 1 && (int)$piRow['branch_id'] === 1);

        $purRow = $db->query("SELECT id, purchase_invoice_id, supplier_id, product_id, quantity, cost, total FROM purchases WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $purOk = ($purRow !== false && (int)$purRow['purchase_invoice_id'] === 1 && (int)$purRow['supplier_id'] === 1 && (int)$purRow['product_id'] === 1 && (int)$purRow['quantity'] === 10 && (float)$purRow['cost'] == 5.00 && (float)$purRow['total'] == 50.00);

        $slRow = $db->query("SELECT id, supplier_id, type, amount, description, purchase_invoice_id, branch_id FROM supplier_ledger WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $suppLedgerOk = ($slRow !== false && (int)$slRow['supplier_id'] === 1 && $slRow['type'] === 'credit' && (float)$slRow['amount'] == 50.00 && (int)$slRow['purchase_invoice_id'] === 1 && (int)$slRow['branch_id'] === 1);

        $ieRow = $db->query("SELECT id, product_id, action, delta, branch_id FROM inventory_events WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $invEventOk = ($ieRow !== false && (int)$ieRow['product_id'] === 1 && $ieRow['action'] === 'sale' && (float)$ieRow['delta'] == -1.000 && (int)$ieRow['branch_id'] === 1);

        $setStmt = $db->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('store_name', 'tax_enabled', 'tax_rate', 'loyalty_enabled', 'prevent_negative_stock')");
        $settingsMap = [];
        foreach ($setStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
            $settingsMap[$s['key']] = $s['value'];
        }
        $settingsOk = (
            ($settingsMap['store_name'] ?? null) === 'codex_legacy_v025_store' &&
            ($settingsMap['tax_enabled'] ?? null) === '0' &&
            ($settingsMap['tax_rate'] ?? null) === '15' &&
            ($settingsMap['loyalty_enabled'] ?? null) === '0' &&
            array_key_exists('prevent_negative_stock', $settingsMap)
        );

        $dataPreserved = (
            $catOk && $custOk && $suppOk && $prodOk && $barcodeOk &&
            $priceHistOk && $invOk && $invItemOk && $custLedgerOk &&
            $purInvOk && $purOk && $suppLedgerOk && $invEventOk && $settingsOk
        );

        $service = new UpdateDataCompatibilityService();
        $description = $service->describe($db);

        $schemaMatchesContract = ($description['schema_sha256'] === $expectedSchemaSha);
        $ledgerMatchesContract = ($description['ledger_sha256'] === $expectedLedgerSha);

        $structuralDiff = $this->computeStructuralDiff(
            $baselineSchema,
            $baselineLedger,
            $description['schema'],
            $description['ledger'],
            $schemaMatchesContract,
            $ledgerMatchesContract
        );

        $helperProofHolds = (
            $dataPreserved &&
            $adminVerified &&
            $exactAll58Match &&
            count($actualVersions) === 58 &&
            $legacyRetainedCount === 24 &&
            $legacyTimestampsPreserved &&
            $pendingAppliedCount === 34 &&
            $pendingTimestampsNew &&
            $duplicate016Gone &&
            $legacyMigrationsTableDropped &&
            $catalogViewPresent &&
            $catalogTriggersPresent &&
            $customerBranchInferred &&
            $supplierBranchInferred &&
            $hasUnitType &&
            $hasFractional &&
            $hasCatalogChanges &&
            in_array('059_add_update_compatibility_metadata.sql', $actualVersions, true) &&
            $ledgerMatchesContract &&
            $this->is64LowerHex($description['schema_sha256'] ?? null) &&
            $this->is64LowerHex($expectedSchemaSha) &&
            $this->is64LowerHex($expectedLedgerSha) &&
            ($description['ledger_sha256'] ?? null) === $expectedLedgerSha
        );

        $observedStructuralProfile = null;
        if ($helperProofHolds) {
            $observedStructuralProfile = [
                'format' => 1,
                'source' => 'synthetic_legacy025_native_reference',
                'current_schema_sha256' => (string)$description['schema_sha256'],
                'target_schema_sha256' => $expectedSchemaSha,
                'ledger_sha256' => $expectedLedgerSha,
            ];
        }

        return [
            'ok' => true,
            'ledger_count' => count($actualVersions),
            'legacy_retained_count' => $legacyRetainedCount,
            'pending_applied_count' => $pendingAppliedCount,
            'exact_all_58_match' => $exactAll58Match,
            'latest_migration_present' => in_array('059_add_update_compatibility_metadata.sql', $actualVersions, true),
            'legacy_timestamps_preserved' => $legacyTimestampsPreserved,
            'pending_timestamps_new' => $pendingTimestampsNew,
            'duplicate_016_gone' => $duplicate016Gone,
            'legacy_migrations_table_dropped' => $legacyMigrationsTableDropped,
            'catalog_view_present' => $catalogViewPresent,
            'catalog_triggers_present' => $catalogTriggersPresent,
            'customer_branch_inferred' => $customerBranchInferred,
            'supplier_branch_inferred' => $supplierBranchInferred,
            'products_unit_type_applied' => $hasUnitType,
            'inventory_fractional_applied' => $hasFractional,
            'catalog_changes_present' => $hasCatalogChanges,
            'admin_verified' => $adminVerified,
            'data_preserved' => $dataPreserved,
            'schema_matches_contract' => $schemaMatchesContract,
            'ledger_matches_contract' => $ledgerMatchesContract,
            'structural_diff' => $structuralDiff,
            'observed_structural_profile' => $observedStructuralProfile,
            'observedstructuralProfile' => $observedStructuralProfile,
        ];
    }

    public function verifyLegacyV025FractionalCapability(string $configDir): array
    {
        $db = $this->connectDatabase($configDir);

        // 1. Column metadata inspection via information_schema
        // products.quantity -> decimal(10,3) NOT NULL DEFAULT 0.000
        $stmt = $db->prepare(
            "SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE, IS_NULLABLE " .
            "FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'quantity'"
        );
        $stmt->execute();
        $prodQtyCol = $stmt->fetch(PDO::FETCH_ASSOC);
        $prodQtyMetaOk = $prodQtyCol &&
            strtolower((string)$prodQtyCol['DATA_TYPE']) === 'decimal' &&
            (int)$prodQtyCol['NUMERIC_PRECISION'] === 10 &&
            (int)$prodQtyCol['NUMERIC_SCALE'] === 3 &&
            strtoupper((string)$prodQtyCol['IS_NULLABLE']) === 'NO';

        // invoice_items.quantity -> decimal(10,3) NOT NULL DEFAULT 1.000
        $stmt = $db->prepare(
            "SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE, IS_NULLABLE " .
            "FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_items' AND COLUMN_NAME = 'quantity'"
        );
        $stmt->execute();
        $itemQtyCol = $stmt->fetch(PDO::FETCH_ASSOC);
        $itemQtyMetaOk = $itemQtyCol &&
            strtolower((string)$itemQtyCol['DATA_TYPE']) === 'decimal' &&
            (int)$itemQtyCol['NUMERIC_PRECISION'] === 10 &&
            (int)$itemQtyCol['NUMERIC_SCALE'] === 3 &&
            strtoupper((string)$itemQtyCol['IS_NULLABLE']) === 'NO';

        // purchases.quantity -> decimal(10,3) NOT NULL DEFAULT 1.000
        $stmt = $db->prepare(
            "SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE, IS_NULLABLE " .
            "FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'quantity'"
        );
        $stmt->execute();
        $purchQtyCol = $stmt->fetch(PDO::FETCH_ASSOC);
        $purchQtyMetaOk = $purchQtyCol &&
            strtolower((string)$purchQtyCol['DATA_TYPE']) === 'decimal' &&
            (int)$purchQtyCol['NUMERIC_PRECISION'] === 10 &&
            (int)$purchQtyCol['NUMERIC_SCALE'] === 3 &&
            strtoupper((string)$purchQtyCol['IS_NULLABLE']) === 'NO';

        // products.is_weighable -> tinyint(1) NOT NULL DEFAULT 0
        $stmt = $db->prepare(
            "SELECT DATA_TYPE, IS_NULLABLE " .
            "FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'is_weighable'"
        );
        $stmt->execute();
        $weighableCol = $stmt->fetch(PDO::FETCH_ASSOC);
        $weighableMetaOk = $weighableCol &&
            strtolower((string)$weighableCol['DATA_TYPE']) === 'tinyint' &&
            strtoupper((string)$weighableCol['IS_NULLABLE']) === 'NO';

        // invoices.fk_invoice_customer foreign key
        $stmt = $db->prepare(
            "SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME " .
            "FROM information_schema.KEY_COLUMN_USAGE " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'customer_id' AND CONSTRAINT_NAME = 'fk_invoice_customer'"
        );
        $stmt->execute();
        $fkUsage = $stmt->fetch(PDO::FETCH_ASSOC);
        $fkMetaOk = $fkUsage &&
            (string)$fkUsage['CONSTRAINT_NAME'] === 'fk_invoice_customer' &&
            (string)$fkUsage['REFERENCED_TABLE_NAME'] === 'customers' &&
            (string)$fkUsage['REFERENCED_COLUMN_NAME'] === 'id';

        // 2. Transactional capability proofs (strictly rolled back)
        $stmt = $db->query("SELECT COUNT(*) FROM invoices WHERE id = 1");
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Expected existing invoice id 1 for FK proof');
        }
        $stmt = $db->query("SELECT COUNT(*) FROM customers WHERE id = 999999");
        if ((int)$stmt->fetchColumn() !== 0) {
            throw new RuntimeException('Expected customer 999999 to be absent for FK proof');
        }

        $db->beginTransaction();
        $transactionRolledBack = false;
        $prodUpdateOk = false;
        $itemUpdateOk = false;
        $purchUpdateOk = false;
        $weighableUpdateOk = false;
        $fkEnforced = false;

        try {
            // Test products.quantity decimal(10,3) & is_weighable update/readback
            $stmt = $db->prepare("UPDATE products SET quantity = 50.125, is_weighable = 1 WHERE id = 1");
            $stmt->execute();
            $stmt = $db->query("SELECT quantity, is_weighable FROM products WHERE id = 1");
            $prodRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($prodRow && (string)$prodRow['quantity'] === '50.125' && (int)$prodRow['is_weighable'] === 1) {
                $prodUpdateOk = true;
                $weighableUpdateOk = true;
            }

            // Test invoice_items.quantity decimal(10,3) update/readback
            $stmt = $db->prepare("UPDATE invoice_items SET quantity = 1.125 WHERE id = 1");
            $stmt->execute();
            $stmt = $db->query("SELECT quantity FROM invoice_items WHERE id = 1");
            $itemRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($itemRow && (string)$itemRow['quantity'] === '1.125') {
                $itemUpdateOk = true;
            }

            // Test purchases.quantity decimal(10,3) update/readback
            $stmt = $db->prepare("UPDATE purchases SET quantity = 10.125 WHERE id = 1");
            $stmt->execute();
            $stmt = $db->query("SELECT quantity FROM purchases WHERE id = 1");
            $purchRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($purchRow && (string)$purchRow['quantity'] === '10.125') {
                $purchUpdateOk = true;
            }

            // Test foreign key constraint enforcement: updating existing invoice id 1 to non-existent customer 999999 MUST fail with FK constraint error
            try {
                $db->exec("UPDATE invoices SET customer_id = 999999 WHERE id = 1");
                $fkEnforced = false;
            } catch (PDOException $fkErr) {
                $sqlState = (string)($fkErr->errorInfo[0] ?? $fkErr->getCode());
                $driverCode = (int)($fkErr->errorInfo[1] ?? 0);
                $driverMsg = (string)($fkErr->errorInfo[2] ?? '');
                if ($sqlState === '23000' && $driverCode === 1452 && str_contains($driverMsg, 'fk_invoice_customer')) {
                    $fkEnforced = true;
                } else {
                    throw new RuntimeException('Foreign key violation did not match expected constraint error');
                }
            }
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
                $transactionRolledBack = true;
            }
        }

        $prodQtyOk = $prodQtyMetaOk && $prodUpdateOk;
        $itemQtyOk = $itemQtyMetaOk && $itemUpdateOk;
        $purchQtyOk = $purchQtyMetaOk && $purchUpdateOk;
        $weighableOk = $weighableMetaOk && $weighableUpdateOk;
        $fkOk = $fkMetaOk && $fkEnforced;

        $columnsCount = 0;
        if ($prodQtyOk) $columnsCount++;
        if ($itemQtyOk) $columnsCount++;
        if ($purchQtyOk) $columnsCount++;
        if ($weighableOk) $columnsCount++;

        $constraintsCount = 0;
        if ($fkOk) $constraintsCount++;

        $allOk = $prodQtyOk && $itemQtyOk && $purchQtyOk && $weighableOk && $fkOk && $transactionRolledBack;

        return [
            'ok' => $allOk,
            'products_quantity_fractional' => $prodQtyOk,
            'invoice_items_quantity_fractional' => $itemQtyOk,
            'purchases_quantity_fractional' => $purchQtyOk,
            'products_is_weighable_present' => $weighableOk,
            'invoice_customer_fk_enforced' => $fkOk,
            'fractional_capability_verified' => $allOk,
            'transaction_rolled_back' => $transactionRolledBack,
            'columns_verified_count' => $columnsCount,
            'constraints_verified_count' => $constraintsCount,
        ];
    }

    private function computeStructuralDiff(
        array $baselineSchema,
        array $baselineLedger,
        array $actualSchema,
        array $actualLedger,
        bool $schemaMatchesContract,
        bool $ledgerMatchesContract
    ): array {
        $buckets = ['tables', 'columns', 'indexes', 'keys', 'references', 'checks', 'objects'];
        $bucketCounts = [];
        $changedBuckets = [];
        $records = [];

        foreach ($buckets as $bucket) {
            $bRows = is_array($baselineSchema[$bucket] ?? null) ? $baselineSchema[$bucket] : [];
            $aRows = is_array($actualSchema[$bucket] ?? null) ? $actualSchema[$bucket] : [];
            $bucketCounts[$bucket] = [
                'baseline' => count($bRows),
                'actual' => count($aRows),
            ];

            $bMap = [];
            foreach ($bRows as $row) {
                if (!is_array($row)) continue;
                $id = $this->getStructuralRecordIdentity($bucket, $row);
                if ($id !== null) {
                    $bMap[$id] = $row;
                }
            }

            $aMap = [];
            foreach ($aRows as $row) {
                if (!is_array($row)) continue;
                $id = $this->getStructuralRecordIdentity($bucket, $row);
                if ($id !== null) {
                    $aMap[$id] = $row;
                }
            }

            $bucketChanged = false;

            foreach ($bMap as $id => $bRow) {
                if (!array_key_exists($id, $aMap)) {
                    $bucketChanged = true;
                    $records[] = [
                        'bucket' => $bucket,
                        'identity' => $id,
                        'status' => 'missing',
                        'changed_fields' => [],
                        'diff' => $this->buildMissingFieldDiff($bucket, $bRow),
                    ];
                } else {
                    $aRow = $aMap[$id];
                    $diffResult = $this->compareStructuralRecordFields($bucket, $bRow, $aRow);
                    if (!empty($diffResult['changed_fields'])) {
                        $bucketChanged = true;
                        $records[] = [
                            'bucket' => $bucket,
                            'identity' => $id,
                            'status' => 'changed',
                            'changed_fields' => $diffResult['changed_fields'],
                            'diff' => $diffResult['diff'],
                        ];
                    }
                }
            }

            foreach ($aMap as $id => $aRow) {
                if (!array_key_exists($id, $bMap)) {
                    $bucketChanged = true;
                    $records[] = [
                        'bucket' => $bucket,
                        'identity' => $id,
                        'status' => 'added',
                        'changed_fields' => [],
                        'diff' => $this->buildAddedFieldDiff($bucket, $aRow),
                    ];
                }
            }

            if ($bucketChanged || count($bRows) !== count($aRows)) {
                $changedBuckets[] = $bucket;
            }
        }

        $bLedger = array_values(array_filter($baselineLedger, 'is_string'));
        $aLedger = array_values(array_filter($actualLedger, 'is_string'));
        $bucketCounts['ledger'] = [
            'baseline' => count($bLedger),
            'actual' => count($aLedger),
        ];

        $bLedgerMap = array_fill_keys($bLedger, true);
        $aLedgerMap = array_fill_keys($aLedger, true);
        $ledgerChanged = false;

        foreach ($bLedgerMap as $version => $_) {
            if (!isset($aLedgerMap[$version])) {
                $ledgerChanged = true;
                if (preg_match('/^[0-9]{3}_[a-zA-Z0-9_\-]+\.sql$/', $version)) {
                    $records[] = [
                        'bucket' => 'ledger',
                        'identity' => $version,
                        'status' => 'missing',
                        'changed_fields' => [],
                        'diff' => (object)[],
                    ];
                }
            }
        }

        foreach ($aLedgerMap as $version => $_) {
            if (!isset($bLedgerMap[$version])) {
                $ledgerChanged = true;
                if (preg_match('/^[0-9]{3}_[a-zA-Z0-9_\-]+\.sql$/', $version)) {
                    $records[] = [
                        'bucket' => 'ledger',
                        'identity' => $version,
                        'status' => 'added',
                        'changed_fields' => [],
                        'diff' => (object)[],
                    ];
                }
            }
        }

        if ($ledgerChanged || count($bLedger) !== count($aLedger)) {
            $changedBuckets[] = 'ledger';
        }

        usort($records, static function (array $a, array $b): int {
            $cmp = strcmp($a['bucket'], $b['bucket']);
            if ($cmp !== 0) return $cmp;
            $cmp = strcmp($a['identity'], $b['identity']);
            if ($cmp !== 0) return $cmp;
            return strcmp($a['status'], $b['status']);
        });

        $totalRecords = count($records);
        $truncated = false;
        if ($totalRecords > 100) {
            $records = array_slice($records, 0, 100);
            $truncated = true;
        }

        return [
            'schema_matches_contract' => $schemaMatchesContract,
            'ledger_matches_contract' => $ledgerMatchesContract,
            'changed_buckets' => array_values(array_unique($changedBuckets)),
            'bucket_counts' => $bucketCounts,
            'records_count' => $totalRecords,
            'truncated' => $truncated,
            'records' => $records,
        ];
    }

    private function getStructuralRecordIdentity(string $bucket, array $row): ?string
    {
        switch ($bucket) {
            case 'tables':
                $name = (string)($row['name'] ?? '');
                return preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name) ? $name : null;

            case 'columns':
                $table = (string)($row['table_name'] ?? '');
                $col = (string)($row['name'] ?? '');
                if (preg_match('/^[a-zA-Z0-9_]{1,64}$/', $table) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $col)) {
                    return "{$table}.{$col}";
                }
                return null;

            case 'indexes':
                $table = (string)($row['table_name'] ?? '');
                $name = (string)($row['name'] ?? '');
                $pos = (string)($row['position'] ?? '');
                if (preg_match('/^[a-zA-Z0-9_]{1,64}$/', $table) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name) && preg_match('/^[0-9]{1,5}$/', $pos)) {
                    return "{$table}.{$name}.{$pos}";
                }
                return null;

            case 'keys':
                $table = (string)($row['table_name'] ?? '');
                $name = (string)($row['name'] ?? '');
                $col = (string)($row['column_name'] ?? '');
                $pos = (string)($row['position'] ?? '');
                if (preg_match('/^[a-zA-Z0-9_]{1,64}$/', $table) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $col) && preg_match('/^[0-9]{1,5}$/', $pos)) {
                    return "{$table}.{$name}.{$col}.{$pos}";
                }
                return null;

            case 'references':
                $table = (string)($row['table_name'] ?? '');
                $name = (string)($row['name'] ?? '');
                if (preg_match('/^[a-zA-Z0-9_]{1,64}$/', $table) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) {
                    return "{$table}.{$name}";
                }
                return null;

            case 'checks':
                $table = (string)($row['table_name'] ?? '');
                $name = (string)($row['name'] ?? '');
                if (preg_match('/^[a-zA-Z0-9_]{1,64}$/', $table) && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) {
                    return "{$table}.{$name}";
                }
                return null;

            case 'objects':
                $type = (string)($row['object_type'] ?? '');
                $name = (string)($row['object_name'] ?? '');
                $table = (string)($row['table_name'] ?? '');
                $allowedObjectTypes = ['trigger', 'view', 'event', 'routine:FUNCTION', 'routine:PROCEDURE'];
                if (in_array($type, $allowedObjectTypes, true)
                    && preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)
                    && preg_match('/^[a-zA-Z0-9_]{0,64}$/', $table)
                ) {
                    return "{$type}.{$name}.{$table}";
                }
                return null;

            default:
                return null;
        }
    }

    private function compareStructuralRecordFields(string $bucket, array $bRow, array $aRow): array
    {
        $changedFields = [];
        $diff = [];

        switch ($bucket) {
            case 'tables':
                foreach (['type', 'engine', 'collation'] as $field) {
                    $bVal = (string)($bRow[$field] ?? '');
                    $aVal = (string)($aRow[$field] ?? '');
                    if ($bVal !== $aVal) {
                        $changedFields[] = $field;
                        $diff[$field] = [
                            'baseline' => $this->sanitizeAlphaNumSpace($bVal, 32),
                            'actual' => $this->sanitizeAlphaNumSpace($aVal, 32),
                            'expected' => $this->sanitizeAlphaNumSpace($bVal, 32),
                        ];
                    }
                }
                break;

            case 'columns':
                foreach (['position', 'type', 'nullable', 'extra', 'charset_name', 'collation'] as $field) {
                    $bVal = (string)($bRow[$field] ?? '');
                    $aVal = (string)($aRow[$field] ?? '');
                    if ($bVal !== $aVal) {
                        $changedFields[] = $field;
                        if ($field === 'position') {
                            $diff[$field] = [
                                'baseline' => (int)$bVal,
                                'actual' => (int)$aVal,
                                'expected' => (int)$bVal,
                            ];
                        } elseif ($field === 'type') {
                            $diff[$field] = [
                                'baseline' => $this->sanitizeSafeSqlType($bVal),
                                'actual' => $this->sanitizeSafeSqlType($aVal),
                                'expected' => $this->sanitizeSafeSqlType($bVal),
                            ];
                        } elseif ($field === 'nullable') {
                            $diff[$field] = [
                                'baseline' => $bVal === 'YES' ? 'YES' : 'NO',
                                'actual' => $aVal === 'YES' ? 'YES' : 'NO',
                                'expected' => $bVal === 'YES' ? 'YES' : 'NO',
                            ];
                        } else {
                            $diff[$field] = [
                                'baseline' => $this->sanitizeAlphaNumSpace($bVal, 64),
                                'actual' => $this->sanitizeAlphaNumSpace($aVal, 64),
                                'expected' => $this->sanitizeAlphaNumSpace($bVal, 64),
                            ];
                        }
                    }
                }
                $bDef = $bRow['default_value'] ?? null;
                $aDef = $aRow['default_value'] ?? null;
                if ($bDef !== $aDef) {
                    $changedFields[] = 'default_value';
                    $diff['default_value'] = ['matches' => false];
                }
                break;

            case 'indexes':
                foreach (['non_unique', 'column_name', 'collation', 'sub_part', 'type'] as $field) {
                    $bVal = $bRow[$field] ?? null;
                    $aVal = $aRow[$field] ?? null;
                    if ($bVal !== $aVal) {
                        $changedFields[] = $field;
                        if ($field === 'sub_part') {
                            $diff[$field] = [
                                'baseline' => is_numeric($bVal) ? (int)$bVal : null,
                                'actual' => is_numeric($aVal) ? (int)$aVal : null,
                                'expected' => is_numeric($bVal) ? (int)$bVal : null,
                            ];
                        } else {
                            $diff[$field] = [
                                'baseline' => $this->sanitizeAlphaNum((string)$bVal, 64),
                                'actual' => $this->sanitizeAlphaNum((string)$aVal, 64),
                                'expected' => $this->sanitizeAlphaNum((string)$bVal, 64),
                            ];
                        }
                    }
                }
                break;

            case 'keys':
                foreach (['referenced_table', 'referenced_column'] as $field) {
                    $bVal = (string)($bRow[$field] ?? '');
                    $aVal = (string)($aRow[$field] ?? '');
                    if ($bVal !== $aVal) {
                        $changedFields[] = $field;
                        $diff[$field] = [
                            'baseline' => $this->sanitizeAlphaNum($bVal, 64),
                            'actual' => $this->sanitizeAlphaNum($aVal, 64),
                            'expected' => $this->sanitizeAlphaNum($bVal, 64),
                        ];
                    }
                }
                break;

            case 'references':
                foreach (['referenced_table', 'update_rule', 'delete_rule', 'match_option'] as $field) {
                    $bVal = (string)($bRow[$field] ?? '');
                    $aVal = (string)($aRow[$field] ?? '');
                    if ($bVal !== $aVal) {
                        $changedFields[] = $field;
                        $diff[$field] = [
                            'baseline' => $this->sanitizeAlphaSpace($bVal, 32),
                            'actual' => $this->sanitizeAlphaSpace($aVal, 32),
                            'expected' => $this->sanitizeAlphaSpace($bVal, 32),
                        ];
                    }
                }
                break;

            case 'checks':
                $bClause = (string)($bRow['clause_text'] ?? '');
                $aClause = (string)($aRow['clause_text'] ?? '');
                if ($bClause !== $aClause) {
                    $changedFields[] = 'clause_text';
                    $diff['clause_text'] = ['matches' => false];
                }
                break;

            case 'objects':
                $bCtx = (string)($bRow['context'] ?? '');
                $aCtx = (string)($aRow['context'] ?? '');
                if ($bCtx !== $aCtx) {
                    $changedFields[] = 'context';
                    $diff['context'] = [
                        'baseline' => $this->sanitizeAlphaNumSpace($bCtx, 64),
                        'actual' => $this->sanitizeAlphaNumSpace($aCtx, 64),
                        'expected' => $this->sanitizeAlphaNumSpace($bCtx, 64),
                    ];
                }
                $bDef = (string)($bRow['definition'] ?? '');
                $aDef = (string)($aRow['definition'] ?? '');
                if ($bDef !== $aDef) {
                    $changedFields[] = 'definition';
                    $diff['definition'] = ['matches' => false];
                }
                break;
        }

        return [
            'changed_fields' => $changedFields,
            'diff' => !empty($diff) ? $diff : (object)[],
        ];
    }

    private function buildMissingFieldDiff(string $bucket, array $row): array|object
    {
        if ($bucket === 'columns') {
            $diff = [];
            if (isset($row['position'])) {
                $diff['position'] = ['expected' => (int)$row['position']];
            }
            if (isset($row['type'])) {
                $type = $this->sanitizeSafeSqlType((string)$row['type']);
                if ($type !== null) {
                    $diff['type'] = ['expected' => $type];
                }
            }
            return !empty($diff) ? $diff : (object)[];
        }
        return (object)[];
    }

    private function buildAddedFieldDiff(string $bucket, array $row): array|object
    {
        if ($bucket === 'columns') {
            $diff = [];
            if (isset($row['position'])) {
                $diff['position'] = ['actual' => (int)$row['position']];
            }
            if (isset($row['type'])) {
                $type = $this->sanitizeSafeSqlType((string)$row['type']);
                if ($type !== null) {
                    $diff['type'] = ['actual' => $type];
                }
            }
            return !empty($diff) ? $diff : (object)[];
        }
        return (object)[];
    }

    private function sanitizeSafeSqlType(?string $type): ?string
    {
        if ($type === null) return null;
        $trimmed = trim($type);
        if (strlen($trimmed) === 0 || strlen($trimmed) > 64) return null;
        if (preg_match('/^[a-zA-Z0-9_(),\s]{1,64}$/', $trimmed)) {
            return $trimmed;
        }
        return null;
    }

    private function sanitizeAlphaNum(?string $val, int $maxLen = 64): ?string
    {
        if ($val === null) return null;
        $trimmed = trim($val);
        if (strlen($trimmed) === 0 || strlen($trimmed) > $maxLen) return null;
        if (preg_match('/^[a-zA-Z0-9_]{1,' . $maxLen . '}$/', $trimmed)) {
            return $trimmed;
        }
        return null;
    }

    private function sanitizeAlphaSpace(?string $val, int $maxLen = 32): ?string
    {
        if ($val === null) return null;
        $trimmed = trim($val);
        if (strlen($trimmed) === 0 || strlen($trimmed) > $maxLen) return null;
        if (preg_match('/^[a-zA-Z\s]{1,' . $maxLen . '}$/', $trimmed)) {
            return $trimmed;
        }
        return null;
    }

    private function sanitizeAlphaNumSpace(?string $val, int $maxLen = 64): ?string
    {
        if ($val === null) return null;
        $trimmed = trim($val);
        if (strlen($trimmed) === 0 || strlen($trimmed) > $maxLen) return null;
        if (preg_match('/^[a-zA-Z0-9_\s]{1,' . $maxLen . '}$/', $trimmed)) {
            return $trimmed;
        }
        return null;
    }

    private function is64LowerHex(mixed $val): bool
    {
        return is_string($val) && preg_match('/^[a-f0-9]{64}$/D', $val) === 1;
    }

    private function assertOwnershipReceipt(): array
    {
        $receiptPath = $this->runnerTemp . '/setup12-ownership-receipt.json';
        if (!is_file($receiptPath)) {
            throw new RuntimeException("Ownership receipt missing: {$receiptPath}");
        }
        $receiptRaw = (string)file_get_contents($receiptPath);
        $receipt = json_decode($receiptRaw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($receipt)) {
            throw new RuntimeException("Ownership receipt could not be parsed as JSON: {$receiptPath}");
        }

        // Verify github_run_id
        $envRunId = (string)getenv('GITHUB_RUN_ID');
        if ($envRunId !== '' && (string)($receipt['github_run_id'] ?? '') !== $envRunId) {
            throw new RuntimeException("Ownership receipt github_run_id mismatch ({$receipt['github_run_id']} vs {$envRunId})");
        }

        // Verify github_run_attempt
        $envRunAttempt = (string)getenv('GITHUB_RUN_ATTEMPT');
        if ($envRunAttempt !== '' && (string)($receipt['github_run_attempt'] ?? '') !== $envRunAttempt) {
            throw new RuntimeException("Ownership receipt github_run_attempt mismatch ({$receipt['github_run_attempt']} vs {$envRunAttempt})");
        }

        // Verify run_token (32 hex)
        $runToken = (string)($receipt['run_token'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/D', $runToken)) {
            throw new RuntimeException("Ownership receipt run_token is invalid: '{$runToken}'");
        }

        // Verify fresh_prechecks_passed: true
        if (($receipt['fresh_prechecks_passed'] ?? null) !== true) {
            throw new RuntimeException("Ownership receipt fresh_prechecks_passed is not true");
        }

        // Verify created_at timestamp
        $createdAt = (string)($receipt['created_at_utc'] ?? $receipt['created_at'] ?? '');
        if ($createdAt === '' || strtotime($createdAt) === false) {
            throw new RuntimeException("Ownership receipt created_at timestamp is invalid: '{$createdAt}'");
        }

        // Verify canonical application/lab/evidence/candidate_source paths inside runnerTemp
        foreach (['application', 'lab', 'evidence', 'candidate_source'] as $field) {
            $p = (string)($receipt[$field] ?? '');
            if ($p === '') {
                throw new RuntimeException("Ownership receipt missing '{$field}' path");
            }
            $this->assertSafeRunnerTempPath($p, false);
        }

        // Verify exact 8 Get-PosCleanupTargets roots
        $appData = (string)getenv('APPDATA');
        $localAppData = (string)getenv('LOCALAPPDATA');
        $programData = (string)getenv('PROGRAMDATA');
        if ($appData === '' || $localAppData === '' || $programData === '') {
            throw new RuntimeException('APPDATA, LOCALAPPDATA, or PROGRAMDATA environment variable missing.');
        }

        $normPath = static function (string $p): string {
            return strtolower(rtrim(str_replace('\\', '/', $p), '/'));
        };

        $canonicalTargets = [
            $programData . '/POS System',
            $appData . '/POS System',
            $appData . '/POS Desktop',
            $appData . '/pos-desktop',
            $localAppData . '/POS System',
            $localAppData . '/POS Desktop',
            $localAppData . '/pos-desktop',
            $localAppData . '/pos-desktop-updater',
        ];
        $canonicalNorm = array_map($normPath, $canonicalTargets);
        sort($canonicalNorm, SORT_STRING);

        $receiptTargets = array_map($normPath, (array)($receipt['pos_targets'] ?? []));
        sort($receiptTargets, SORT_STRING);

        if (count($receiptTargets) !== 8 || count($canonicalNorm) !== 8) {
            throw new RuntimeException("Ownership receipt POS targets count mismatch (expected 8, got " . count($receiptTargets) . ")");
        }
        for ($i = 0; $i < 8; $i++) {
            if ($receiptTargets[$i] !== $canonicalNorm[$i]) {
                throw new RuntimeException("Ownership receipt POS target at [{$i}] mismatch: '{$receiptTargets[$i]}' vs '{$canonicalNorm[$i]}'");
            }
        }

        return $receipt;
    }

    private function connectDatabase(string $configDir): PDO
    {
        if (getenv('GITHUB_ACTIONS') !== 'true') {
            throw new RuntimeException('Database operations strictly require ephemeral GITHUB_ACTIONS environment.');
        }

        // 1. Guard connection by early ownership receipt
        $receipt = $this->assertOwnershipReceipt();
        $appData = (string)getenv('APPDATA');

        // 2. Validate config directory and runtime files
        $configReal = realpath($configDir);
        if ($configReal === false || !is_dir($configReal)) {
            throw new RuntimeException("Runtime config directory missing: {$configDir}");
        }

        $expectedConfigDir = realpath(rtrim(str_replace('\\', '/', $appData), '/') . '/POS System');
        if ($expectedConfigDir === false || strtolower(str_replace('\\', '/', $configReal)) !== strtolower(str_replace('\\', '/', $expectedConfigDir))) {
            throw new RuntimeException("Database config directory ({$configReal}) does not match expected APPDATA path ({$expectedConfigDir}).");
        }

        $credentialsFile = $configReal . DIRECTORY_SEPARATOR . 'db_credentials.json';
        $portsFile = $configReal . DIRECTORY_SEPARATOR . 'runtime_ports.json';

        if (!is_file($credentialsFile) || !is_file($portsFile)) {
            throw new RuntimeException("Runtime database configuration missing in {$configReal}");
        }

        $credentials = json_decode((string)file_get_contents($credentialsFile), true, 32, JSON_THROW_ON_ERROR);
        $ports = json_decode((string)file_get_contents($portsFile), true, 32, JSON_THROW_ON_ERROR);

        $dsn = "mysql:host=127.0.0.1;port={$ports['mysqlPort']};dbname=pos_db;charset=utf8mb4";
        return new PDO($dsn, $credentials['user'], $credentials['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function verifyPharLayout(string $pharPath, string $repoRoot): array
    {
        if (getenv('GITHUB_ACTIONS') !== 'true') {
            throw new RuntimeException('PHAR layout verification strictly requires ephemeral GITHUB_ACTIONS environment.');
        }

        $pharPath = $this->assertSafeRunnerTempPath($pharPath, true);
        $realPhar = realpath($pharPath);
        if ($realPhar === false || !is_file($realPhar)) {
            throw new RuntimeException("backend.phar not found: {$pharPath}");
        }
        $realRepo = realpath($repoRoot);
        if ($realRepo === false || !is_dir($realRepo)) {
            throw new RuntimeException("Invalid repo root: {$repoRoot}");
        }
        $repoRootNorm = rtrim(str_replace('\\', '/', $realRepo), '/');

        if (filesize($realPhar) < 1000000) {
            throw new RuntimeException("backend.phar is too small (" . filesize($realPhar) . " bytes); likely corrupt or fake.");
        }

        $pharObj = new Phar($realPhar);

        // 1. Verify restore CLI entry points in stub and archive
        $stub = $pharObj->getStub();
        if (!str_contains($stub, 'create-restore-safety')) {
            throw new RuntimeException("PHAR stub missing 'create-restore-safety' CLI entry point.");
        }
        if (!str_contains($stub, 'restore-migration-safety')) {
            throw new RuntimeException("PHAR stub missing 'restore-migration-safety' CLI entry point.");
        }
        if (!str_contains($stub, 'restore-backup') && !str_contains($stub, 'restore-database')) {
            throw new RuntimeException("PHAR stub missing 'restore-backup' or 'restore-database' CLI entry point.");
        }

        $restoreFiles = [
            'cli/create-restore-safety.php' => 'backend/cli/create-restore-safety.php',
            'cli/restore-migration-safety.php' => 'backend/cli/restore-migration-safety.php',
        ];
        if (is_file($repoRootNorm . '/backend/cli/restore-backup.php')) {
            $restoreFiles['cli/restore-backup.php'] = 'backend/cli/restore-backup.php';
        }
        if (is_file($repoRootNorm . '/backend/cli/restore-database.php')) {
            $restoreFiles['cli/restore-database.php'] = 'backend/cli/restore-database.php';
        }

        foreach ($restoreFiles as $pharRel => $repoRel) {
            if (!isset($pharObj[$pharRel])) {
                throw new RuntimeException("PHAR missing restore entry: {$pharRel}");
            }
            $repoFile = $repoRootNorm . '/' . $repoRel;
            if (!is_file($repoFile)) {
                throw new RuntimeException("Repo file missing for restore entry: {$repoFile}");
            }
            $pharContent = $pharObj[$pharRel]->getContent();
            $pharSha = hash('sha256', $pharContent);
            $repoSha = hash_file('sha256', $repoFile);
            if ($pharSha !== $repoSha) {
                throw new RuntimeException("SHA256 mismatch for restore entry {$pharRel}");
            }
            if (strlen($pharContent) !== filesize($repoFile)) {
                throw new RuntimeException("Size mismatch for restore entry {$pharRel}");
            }
        }

        // 2. Discover git-tracked repo migrations (66) and seeders (3) using read-only git ls-files
        $cmd = 'git -C ' . escapeshellarg($repoRootNorm) . ' ls-files -z -- database/migrations database/seeders';
        $output = shell_exec($cmd);
        if (!is_string($output) || $output === '') {
            throw new RuntimeException("git ls-files failed or returned empty for migrations/seeders in {$repoRootNorm}");
        }

        $trackedFiles = array_values(array_filter(explode("\0", $output), static function (string $p): bool {
            return $p !== '' && str_ends_with($p, '.sql');
        }));

        $repoMigrations = [];
        $repoSeeders = [];

        foreach ($trackedFiles as $rel) {
            $relNorm = str_replace('\\', '/', $rel);
            $fullPath = $repoRootNorm . '/' . $relNorm;
            if (!is_file($fullPath)) {
                throw new RuntimeException("Tracked SQL file missing on filesystem: {$fullPath}");
            }
            $fileMeta = [
                'sha256' => hash_file('sha256', $fullPath),
                'size' => filesize($fullPath),
            ];
            if (str_starts_with($relNorm, 'database/migrations/')) {
                $repoMigrations[$relNorm] = $fileMeta;
            } elseif (str_starts_with($relNorm, 'database/seeders/')) {
                $repoSeeders[$relNorm] = $fileMeta;
            } else {
                throw new RuntimeException("Unexpected tracked SQL file outside migrations/seeders: {$relNorm}");
            }
        }

        if (count($repoMigrations) !== 66) {
            throw new RuntimeException("Expected exactly 66 git-tracked migration SQL files, found " . count($repoMigrations));
        }
        if (count($repoSeeders) !== 3) {
            throw new RuntimeException("Expected exactly 3 git-tracked seeder SQL files, found " . count($repoSeeders));
        }

        // 3. Inspect PHAR contents for migrations & seeders stripping exact known prefix
        $normalizedRealPhar = str_replace('\\', '/', $realPhar);
        $expectedPharPrefix = 'phar://' . $normalizedRealPhar . '/';
        $prefixLen = strlen($expectedPharPrefix);

        $pharMigrationEntries = [];
        $pharSeederEntries = [];
        foreach (new RecursiveIteratorIterator($pharObj) as $entry) {
            $pathname = str_replace('\\', '/', $entry->getPathname());
            if (strlen($pathname) <= $prefixLen) {
                continue;
            }
            if (substr_compare($pathname, $expectedPharPrefix, 0, $prefixLen, true) !== 0) {
                throw new RuntimeException("PHAR entry path '{$pathname}' escapes expected archive prefix '{$expectedPharPrefix}'.");
            }
            $entryRel = substr($pathname, $prefixLen);

            if (str_starts_with($entryRel, 'database/migrations/') && str_ends_with($entryRel, '.sql')) {
                $pharMigrationEntries[$entryRel] = true;
            }
            if (str_starts_with($entryRel, 'database/seeders/') && str_ends_with($entryRel, '.sql')) {
                $pharSeederEntries[$entryRel] = true;
            }
        }

        if (count($pharMigrationEntries) !== 66) {
            throw new RuntimeException("PHAR migration count mismatch: expected 66, found " . count($pharMigrationEntries));
        }
        if (count($pharSeederEntries) !== 3) {
            throw new RuntimeException("PHAR seeder count mismatch: expected 3, found " . count($pharSeederEntries));
        }

        // Compare exact sorted relative paths with PHAR
        $pharMigKeys = array_keys($pharMigrationEntries);
        sort($pharMigKeys, SORT_STRING);
        $repoMigKeys = array_keys($repoMigrations);
        sort($repoMigKeys, SORT_STRING);

        if ($pharMigKeys !== $repoMigKeys) {
            throw new RuntimeException("PHAR migration relative paths do not match git-tracked migration paths.");
        }

        $pharSeedKeys = array_keys($pharSeederEntries);
        sort($pharSeedKeys, SORT_STRING);
        $repoSeedKeys = array_keys($repoSeeders);
        sort($repoSeedKeys, SORT_STRING);

        if ($pharSeedKeys !== $repoSeedKeys) {
            throw new RuntimeException("PHAR seeder relative paths do not match git-tracked seeder paths.");
        }

        // Verify exact SHA256 and size for all 66 migrations in PHAR vs repo
        foreach ($repoMigrations as $relPath => $expected) {
            if (!isset($pharObj[$relPath])) {
                throw new RuntimeException("PHAR missing migration file: {$relPath}");
            }
            $content = $pharObj[$relPath]->getContent();
            $sha = hash('sha256', $content);
            if ($sha !== $expected['sha256']) {
                throw new RuntimeException("SHA256 mismatch for migration in PHAR: {$relPath}");
            }
            if (strlen($content) !== $expected['size']) {
                throw new RuntimeException("Size mismatch for migration in PHAR: {$relPath}");
            }
        }

        // Verify exact SHA256 and size for all 3 seeders in PHAR vs repo
        foreach ($repoSeeders as $relPath => $expected) {
            if (!isset($pharObj[$relPath])) {
                throw new RuntimeException("PHAR missing seeder file: {$relPath}");
            }
            $content = $pharObj[$relPath]->getContent();
            $sha = hash('sha256', $content);
            if ($sha !== $expected['sha256']) {
                throw new RuntimeException("SHA256 mismatch for seeder in PHAR: {$relPath}");
            }
            if (strlen($content) !== $expected['size']) {
                throw new RuntimeException("Size mismatch for seeder in PHAR: {$relPath}");
            }
        }

        return [
            'ok' => true,
            'phar' => str_replace('\\', '/', $realPhar),
            'migrations_count' => count($pharMigrationEntries),
            'seeders_count' => count($pharSeederEntries),
            'restore_entries' => array_keys($restoreFiles),
            'stub_verified' => true,
        ];
    }

    private function copyDirectoryRecursive(string $source, string $dest): void
    {
        $source = rtrim(str_replace('\\', '/', $source), '/');
        $dest = rtrim(str_replace('\\', '/', $dest), '/');
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $subPath = substr(str_replace('\\', '/', $item->getPathname()), strlen($source) + 1);
            $target = $dest . '/' . $subPath;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    public function verifyRestoreSqlPreservation(?string $labDir = null): array
    {
        $targetDir = ($labDir !== null && $labDir !== '')
            ? $this->assertSafeRunnerTempPath($labDir, true)
            : $this->runnerTemp;
        $workDir = $targetDir . '/sql-preservation-' . bin2hex(random_bytes(6));
        if (file_exists($workDir) || is_dir($workDir)) {
            throw new RuntimeException('Temporary fixture dir already exists');
        }
        if (!mkdir($workDir, 0700, false) || !is_dir($workDir)) {
            throw new RuntimeException('Failed to create temporary fixture dir');
        }
        $workDir = $this->assertSafeRunnerTempPath($workDir, true);

        $backupService = new BackupService();
        $caseCounts = 0;
        $unsignedDdlPreserved = false;
        $literalsCommentsPreserved = false;
        $bomRemovedCleanly = false;
        $dangerousCommandsRejected = false;
        $benignLiteralsAccepted = false;

        try {
            // Case 1: Exact unsigned DDL (integer sized variants, case, signed + UNSIGNED FK)
            $ddlSql = "-- Schema with unsigned variants and foreign key\n"
                . "CREATE TABLE `pos_parent` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  PRIMARY KEY (`id`)\n"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
                . "CREATE TABLE `pos_types` (\n"
                . "  `tiny_val` TINYINT UNSIGNED NOT NULL,\n"
                . "  `small_val` SMALLINT UNSIGNED NOT NULL,\n"
                . "  `med_val` MEDIUMINT UNSIGNED NOT NULL,\n"
                . "  `int_val` INT UNSIGNED NOT NULL,\n"
                . "  `big_val` BIGINT UNSIGNED NOT NULL,\n"
                . "  `int_sized` INT(10) UNSIGNED NOT NULL,\n"
                . "  `case_tiny` tinyint unsigned NOT NULL,\n"
                . "  `case_big` bigint(20) unsigned NOT NULL,\n"
                . "  `signed_col` INT NOT NULL,\n"
                . "  `parent_fk` INT UNSIGNED NOT NULL,\n"
                . "  CONSTRAINT `fk_parent` FOREIGN KEY (`parent_fk`) REFERENCES `pos_parent` (`id`)\n"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
                . "INSERT INTO `pos_parent` (`id`) VALUES (1);\n";

            $ddlFile = $workDir . '/case1_ddl.sql';
            file_put_contents($ddlFile, $ddlSql);
            $resDdl = $backupService->validateUploadedSqlFile([
                'name' => 'case1_ddl.sql',
                'tmp_name' => $ddlFile,
                'size' => filesize($ddlFile),
                'error' => UPLOAD_ERR_OK,
            ]);
            if (($resDdl['ok'] ?? false) !== true || ($resDdl['content'] ?? '') !== $ddlSql) {
                throw new RuntimeException('Unsigned DDL was not preserved byte-for-byte.');
            }
            $unsignedDdlPreserved = true;
            $caseCounts++;

            // Case 2: Unsigned large max numeric string + literal containing 'INT UNSIGNED' / comments
            $litSql = "-- Data rows with literals and comments\n"
                . "CREATE TABLE `pos_notes` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  `num_str` VARCHAR(64) NOT NULL,\n"
                . "  `note` TEXT NOT NULL,\n"
                . "  PRIMARY KEY (`id`)\n"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
                . "-- Line comment containing INT UNSIGNED reference\n"
                . "/* Block comment containing BIGINT UNSIGNED and TINYINT UNSIGNED */\n"
                . "INSERT INTO `pos_notes` (`num_str`, `note`) VALUES\n"
                . "  ('18446744073709551615', 'Notice: INT UNSIGNED field must retain exact text'),\n"
                . "  ('4294967295', 'Second notice: MEDIUMINT UNSIGNED and SMALLINT UNSIGNED in literal');\n";

            $litFile = $workDir . '/case2_literals.sql';
            file_put_contents($litFile, $litSql);
            $resLit = $backupService->validateUploadedSqlFile([
                'name' => 'case2_literals.sql',
                'tmp_name' => $litFile,
                'size' => filesize($litFile),
                'error' => UPLOAD_ERR_OK,
            ]);
            if (($resLit['ok'] ?? false) !== true || ($resLit['content'] ?? '') !== $litSql) {
                throw new RuntimeException('Literals and comments containing INT UNSIGNED were modified.');
            }
            $literalsCommentsPreserved = true;
            $caseCounts++;

            // Case 3: BOM expected only removal
            $bomSql = "\xEF\xBB\xBF" . $ddlSql;
            $bomFile = $workDir . '/case3_bom.sql';
            file_put_contents($bomFile, $bomSql);
            $resBom = $backupService->validateUploadedSqlFile([
                'name' => 'case3_bom.sql',
                'tmp_name' => $bomFile,
                'size' => filesize($bomFile),
                'error' => UPLOAD_ERR_OK,
            ]);
            if (($resBom['ok'] ?? false) !== true || ($resBom['content'] ?? '') !== $ddlSql) {
                throw new RuntimeException('BOM removal failed to preserve exact remainder.');
            }
            $bomRemovedCleanly = true;
            $caseCounts++;

            // Case 4: Existing dangerous commands still rejected
            $dangerousList = [
                "CREATE TABLE `t_drop` (`x` INT);\nDROP DATABASE `production`;\n",
                "CREATE TABLE `t_grant` (`x` INT);\nGRANT ALL ON *.* TO 'attacker'@'%';\n",
                "CREATE TABLE `t_out` (`x` INT);\nSELECT * INTO OUTFILE '/tmp/dump' FROM `t_out`;\n",
                "CREATE TABLE `t_load` (`x` INT);\nLOAD DATA INFILE '/etc/passwd' INTO TABLE `t_load`;\n",
                "CREATE TABLE `t_shut` (`x` INT);\nSHUTDOWN;\n",
            ];
            $allDangerousRejected = true;
            foreach ($dangerousList as $idx => $badSql) {
                $badFile = $workDir . "/case4_bad_{$idx}.sql";
                file_put_contents($badFile, $badSql);
                $resBad = $backupService->validateUploadedSqlFile([
                    'name' => "case4_bad_{$idx}.sql",
                    'tmp_name' => $badFile,
                    'size' => filesize($badFile),
                    'error' => UPLOAD_ERR_OK,
                ]);
                if (($resBad['ok'] ?? true) !== false) {
                    $allDangerousRejected = false;
                    break;
                }
                $caseCounts++;
            }
            if (!$allDangerousRejected) {
                throw new RuntimeException('Dangerous SQL commands were not rejected.');
            }
            $dangerousCommandsRejected = true;

            // Case 5: Valid benign literal not false positive
            $benignSql = "CREATE TABLE `feeds` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  `source` VARCHAR(100) NOT NULL,\n"
                . "  `log_entry` TEXT NOT NULL,\n"
                . "  PRIMARY KEY (`id`)\n"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
                . "INSERT INTO `feeds` (`source`, `log_entry`) VALUES\n"
                . "  ('pos-source', 'User attempted DROP DATABASE test inside string literal'),\n"
                . "  ('network', 'Action GRANT PERMISSIONS logged safely in audit trail');\n";

            $benignFile = $workDir . '/case5_benign.sql';
            file_put_contents($benignFile, $benignSql);
            $resBenign = $backupService->validateUploadedSqlFile([
                'name' => 'case5_benign.sql',
                'tmp_name' => $benignFile,
                'size' => filesize($benignFile),
                'error' => UPLOAD_ERR_OK,
            ]);
            if (($resBenign['ok'] ?? false) !== true || ($resBenign['content'] ?? '') !== $benignSql) {
                throw new RuntimeException('Benign SQL literals triggered false positive.');
            }
            $benignLiteralsAccepted = true;
            $caseCounts++;
        } finally {
            if (is_dir($workDir)) {
                $files = glob($workDir . '/*');
                if ($files !== false) {
                    foreach ($files as $f) {
                        if (is_file($f)) {
                            @unlink($f);
                        }
                    }
                }
                @rmdir($workDir);
            }
        }

        return [
            'ok' => true,
            'cases_tested' => $caseCounts,
            'unsigned_ddl_preserved' => $unsignedDdlPreserved,
            'literals_comments_preserved' => $literalsCommentsPreserved,
            'bom_removed_cleanly' => $bomRemovedCleanly,
            'dangerous_commands_rejected' => $dangerousCommandsRejected,
            'benign_literals_accepted' => $benignLiteralsAccepted,
        ];
    }
}

// -----------------------------------------------------------------------------
// CLI Dispatcher
// -----------------------------------------------------------------------------

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $options = getopt('', [
        'action:',
        'lab-dir:',
        'lab:',
        'evidence-dir:',
        'assets-dir:',
        'output-root:',
        'config-dir:',
        'phar:',
        'beta12-zip:',
        'beta12-inventory:',
        'beta12-app:',
        'beta12-index:',
        'beta12-index-sig:',
        'beta12-desc:',
        'beta12-desc-sig:',
        'public-key:',
        'candidate-unpacked:',
        'candidate-app:',
        'candidate-source:',
        'candidate-setup-inventory:',
        'candidate-delta-app:',
        'candidate-delta-inventory:',
        'repo-source:',
        'repo-root:',
        'contract:',
        'shell-root:',
        'output:',
        'mode:',
        'sentinel:',
        'mutated-name:',
        'setting-key:',
        'auth-path:',
        'output-path:',
        'contract-path:',
        'baseline-path:',
        'baseline:',
        'variant:',
    ]);

    $action = (string)($options['action'] ?? '');
    $helper = new Setup12RegressionsHelper();

    try {
        switch ($action) {
            case 'verify-beta-signatures':
                $result = $helper->verifyBetaSignatures(
                    (string)($options['beta12-index'] ?? ''),
                    (string)($options['beta12-index-sig'] ?? ''),
                    (string)($options['beta12-desc'] ?? ''),
                    (string)($options['beta12-desc-sig'] ?? ''),
                    (string)($options['beta12-zip'] ?? ''),
                    (string)($options['public-key'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'reconstruct-beta12':
                $result = $helper->reconstructBeta12(
                    (string)($options['beta12-zip'] ?? ''),
                    (string)($options['beta12-inventory'] ?? ''),
                    (string)($options['output'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'verify-phar-layout':
                $result = $helper->verifyPharLayout(
                    (string)($options['phar'] ?? ''),
                    (string)($options['repo-root'] ?? $options['repo-source'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'prepare-candidate-variants':
                $candidateSourceOpt = (string)($options['candidate-source'] ?? '');
                if ($candidateSourceOpt === '') {
                    throw new RuntimeException('Missing required --candidate-source option.');
                }
                $result = $helper->prepareCandidateVariants(
                    (string)($options['candidate-unpacked'] ?? ''),
                    (string)($options['repo-source'] ?? $options['repo-root'] ?? ''),
                    $candidateSourceOpt,
                    (string)($options['candidate-setup-inventory'] ?? ''),
                    (string)($options['candidate-delta-app'] ?? ''),
                    (string)($options['candidate-delta-inventory'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'capture-contract':
                $result = $helper->captureContract(
                    (string)($options['config-dir'] ?? ''),
                    (string)($options['output'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'build-transitions':
                $key = (string)getenv('UPDATE_PRIVATE_KEY');
                $result = $helper->buildTransitions(
                    (string)($options['assets-dir'] ?? $options['output-root'] ?? $options['output'] ?? ''),
                    (string)($options['candidate-app'] ?? ''),
                    (string)($options['candidate-setup-inventory'] ?? ''),
                    (string)($options['candidate-delta-app'] ?? ''),
                    (string)($options['candidate-delta-inventory'] ?? ''),
                    (string)($options['beta12-app'] ?? ''),
                    (string)($options['beta12-inventory'] ?? ''),
                    (string)($options['contract'] ?? ''),
                    (string)($options['shell-root'] ?? ''),
                    $key
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'generate-provider-fixture':
                $result = $helper->generateProviderFixture(
                    (string)($options['mode'] ?? ''),
                    (string)($options['lab-dir'] ?? ''),
                    (string)($options['output'] ?? ''),
                    (string)($options['evidence-dir'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'seed-db':
                $result = $helper->seedDb(
                    (string)($options['config-dir'] ?? ''),
                    (string)($options['sentinel'] ?? 'codex_setup12_regression_sentinel')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'probe-db':
                $settingKey = isset($options['setting-key']) && $options['setting-key'] !== ''
                    ? (string)$options['setting-key']
                    : null;
                $result = $helper->probeDb(
                    (string)($options['config-dir'] ?? ''),
                    (string)($options['sentinel'] ?? 'codex_setup12_regression_sentinel'),
                    $settingKey
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'inspect-db':
                $settingKey = isset($options['setting-key']) && $options['setting-key'] !== ''
                    ? (string)$options['setting-key']
                    : null;
                $result = $helper->inspectDb(
                    (string)($options['config-dir'] ?? ''),
                    (string)($options['sentinel'] ?? 'codex_setup12_regression_sentinel'),
                    $settingKey
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'mutate-db':
                $result = $helper->mutateDb(
                    (string)($options['config-dir'] ?? ''),
                    (string)($options['sentinel'] ?? 'codex_setup12_regression_sentinel'),
                    (string)($options['mutated-name'] ?? 'codex_mutated_row_sentinel')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'verify-restore-sql-preservation':
                $result = $helper->verifyRestoreSqlPreservation(
                    (string)($options['lab-dir'] ?? $options['lab'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'prepare-legacy-v025-backup':
                $result = $helper->prepareLegacyV025Backup(
                    (string)($options['auth-path'] ?? ''),
                    (string)($options['output-path'] ?? $options['output'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'capture-legacy-v025-schema-baseline':
                $result = $helper->captureLegacyV025SchemaBaseline(
                    (string)($options['config-dir'] ?? ''),
                    (string)($options['contract-path'] ?? $options['contract'] ?? ''),
                    (string)($options['output-path'] ?? $options['output'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'verify-legacy-v025-upgrade':
                $result = $helper->verifyLegacyV025Upgrade(
                    (string)($options['config-dir'] ?? ''),
                    (string)($options['auth-path'] ?? ''),
                    (string)($options['contract-path'] ?? $options['contract'] ?? ''),
                    (string)($options['baseline-path'] ?? $options['baseline'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'prepare-legacy-v025-variant-backup':
                $result = $helper->prepareLegacyV025VariantBackup(
                    (string)($options['variant'] ?? ''),
                    (string)($options['auth-path'] ?? ''),
                    (string)($options['output-path'] ?? $options['output'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            case 'verify-legacy-v025-fractional-capability':
                $result = $helper->verifyLegacyV025FractionalCapability(
                    (string)($options['config-dir'] ?? '')
                );
                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                break;

            default:
                throw new RuntimeException("Unknown action: {$action}");
        }
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        exit(1);
    }
}
