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
 */

require_once __DIR__ . '/../../backend/vendor/autoload.php';
require_once __DIR__ . '/../build-channel-transition.php';
require_once __DIR__ . '/../deployment-inventory.php';

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

    public function inspectDb(string $configDir, string $sentinel): array
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
        $settingKey = 'sentinel_' . substr(md5($sentinel), 0, 10);
        $stmt = $db->prepare('SELECT `key`, `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$settingKey]);
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

    private function connectDatabase(string $configDir): PDO
    {
        if (getenv('GITHUB_ACTIONS') !== 'true') {
            throw new RuntimeException('Database operations strictly require ephemeral GITHUB_ACTIONS environment.');
        }

        // 1. Guard connection by early ownership receipt
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
}

// -----------------------------------------------------------------------------
// CLI Dispatcher
// -----------------------------------------------------------------------------

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $options = getopt('', [
        'action:',
        'lab-dir:',
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

            case 'inspect-db':
                $result = $helper->inspectDb(
                    (string)($options['config-dir'] ?? ''),
                    (string)($options['sentinel'] ?? 'codex_setup12_regression_sentinel')
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

            default:
                throw new RuntimeException("Unknown action: {$action}");
        }
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        exit(1);
    }
}
