<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/vendor/autoload.php';
require_once __DIR__ . '/build-channel-transition.php';
require_once __DIR__ . '/deployment-inventory.php';

use App\Services\ChannelTransitionManifestService;
use App\Services\ManifestSignatureService;
use App\Services\ReleaseVersionPolicy;
use App\Services\UpdateChannelPolicy;
use App\Services\UpdateDataCompatibilityService;
use App\Services\UpdateManifestService;

final class Setup11TransitionAcceptanceHelper
{
    private string $runnerTemp;

    public function __construct(string $runnerTemp)
    {
        $real = realpath($runnerTemp);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException("Invalid RUNNER_TEMP directory: {$runnerTemp}");
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

        // Verify no reparse points or symlinks in ancestors
        $cursor = $norm;
        while ($cursor && $cursor !== $this->runnerTemp && $cursor !== dirname($cursor)) {
            if (file_exists($cursor) && is_link($cursor)) {
                throw new RuntimeException("Symlink rejected: {$cursor}");
            }
            $cursor = dirname($cursor);
        }

        return $norm;
    }

    public function reconstructBeta10(
        string $setup001Root,
        string $setup001InventoryPath,
        string $beta10Download,
        string $output
    ): void {
        $setup001Root = $this->assertSafeRunnerTempPath($setup001Root, true);
        $setup001InventoryPath = $this->assertSafeRunnerTempPath($setup001InventoryPath, true);
        $beta10Download = $this->assertSafeRunnerTempPath($beta10Download, true);
        $output = $this->assertSafeRunnerTempPath($output, false);

        // 1. Verify published baseline Setup 0.0.1 inventory hash
        $expected001InvHash = 'dc8f213aa3cbd2659529eb6cd8b0d298c9138e74d4b9142e42a52c774a27f488';
        $actual001InvHash = hash_file('sha256', $setup001InventoryPath);
        if (strtolower($actual001InvHash) !== $expected001InvHash) {
            throw new RuntimeException("Setup 0.0.1 inventory hash mismatch! Expected {$expected001InvHash}, got {$actual001InvHash}");
        }
        $setup001Inventory = json_decode((string)file_get_contents($setup001InventoryPath), true, 64, JSON_THROW_ON_ERROR);

        // Validate all baseline files in setup001Root against pinned published inventory before copying
        foreach ($setup001Inventory['files'] as $relPath => $expected) {
            $baselineFile = $setup001Root . '/' . $relPath;
            if (!is_file($baselineFile) || is_link($baselineFile)) {
                throw new RuntimeException("Setup 0.0.1 baseline file missing or symlink: {$relPath}");
            }
            if (filesize($baselineFile) !== ($expected['size'] ?? 0)) {
                throw new RuntimeException("Setup 0.0.1 baseline file size mismatch: {$relPath}");
            }
            if (hash_file('sha256', $baselineFile) !== ($expected['sha256'] ?? '')) {
                throw new RuntimeException("Setup 0.0.1 baseline file SHA256 mismatch: {$relPath}");
            }
        }

        // 2. Discover manifest and signature from Beta 10 download
        $manifestPath = is_file($beta10Download . '/manifest-0.0.1.json')
            ? $beta10Download . '/manifest-0.0.1.json'
            : $beta10Download . '/manifest.json';
        $sigPath = is_file($beta10Download . '/manifest-0.0.1.sig')
            ? $beta10Download . '/manifest-0.0.1.sig'
            : $beta10Download . '/manifest.sig';
        $inventoryPath = $beta10Download . '/deployment-inventory.json';

        if (!is_file($manifestPath) || !is_file($sigPath) || !is_file($inventoryPath)) {
            throw new RuntimeException("Beta 10 download artifacts missing in: {$beta10Download}");
        }

        $pubKeyPath = dirname(__DIR__) . '/backend/certs/update_public_key.pem';
        if (!is_file($pubKeyPath)) {
            throw new RuntimeException("Production public key missing: {$pubKeyPath}");
        }

        $manifestRaw = (string)file_get_contents($manifestPath);
        $sigRaw = (string)file_get_contents($sigPath);
        $sigService = new ManifestSignatureService();
        if (!$sigService->verifySignature($manifestRaw, $sigRaw, $pubKeyPath)) {
            throw new RuntimeException("Beta 10 manifest signature failed verification against update_public_key.pem");
        }

        $manifest = json_decode($manifestRaw, true, 64, JSON_THROW_ON_ERROR);
        $manifestPolicy = new UpdateManifestService();
        $validation = $manifestPolicy->validateManifest($manifest);
        if (!$validation['valid']) {
            throw new RuntimeException('Beta 10 manifest schema invalid: ' . implode('; ', $validation['errors']));
        }
        if (($manifest['version'] ?? '') !== '0.0.10-beta.10' || ($manifest['from_version'] ?? '') !== '0.0.1' || ($manifest['channel'] ?? '') !== 'beta') {
            throw new RuntimeException("Beta 10 manifest attributes mismatch (expected version 0.0.10-beta.10, from 0.0.1, channel beta).");
        }

        if (!is_dir($output)) {
            mkdir($output, 0755, true);
        }
        $realOutput = realpath($output);
        if ($realOutput === false) throw new RuntimeException("Cannot resolve output dir: {$output}");
        $outputNorm = rtrim(str_replace('\\', '/', $realOutput), '/');

        if (!$manifestPolicy->validateManifestPaths($manifest, $outputNorm)['ok']) {
            throw new RuntimeException("Unsafe paths in Beta 10 manifest.");
        }

        // Copy 0.0.1 baseline app files to output
        $this->copyDirectory($setup001Root, $outputNorm);

        // Find delta archive
        $deltaZip = null;
        foreach (scandir($beta10Download) as $file) {
            if (str_starts_with($file, 'delta') && str_ends_with($file, '.zip')) {
                $deltaZip = $beta10Download . '/' . $file;
                break;
            }
        }
        if (!$deltaZip || !is_file($deltaZip)) {
            throw new RuntimeException("Beta 10 delta archive not found in: {$beta10Download}");
        }

        if (!empty($manifest['archive_sha256'])) {
            $actualZipHash = hash_file('sha256', $deltaZip);
            if (strtolower($actualZipHash) !== strtolower($manifest['archive_sha256'])) {
                throw new RuntimeException("Beta 10 delta archive hash mismatch against signed manifest!");
            }
        }

        // Stream verify and extract delta files
        $this->streamVerifyAndExtractDelta($deltaZip, $outputNorm, $manifest, $manifestPolicy);

        // Delete files declared in deleted_files
        foreach ($manifest['deleted_files'] ?? [] as $del) {
            $delNorm = str_replace('\\', '/', $del);
            if (!$manifestPolicy->isPathSafe($delNorm, $outputNorm)) {
                throw new RuntimeException("Unsafe path in deleted_files: {$delNorm}");
            }
            $delPath = $outputNorm . '/' . $delNorm;
            if (file_exists($delPath)) {
                if (is_link($delPath)) {
                    throw new RuntimeException("Symlink rejected in deleted_files: {$delPath}");
                }
                unlink($delPath);
            }
        }

        // Verify against published deployment-inventory.json
        $inventoryRaw = (string)file_get_contents($inventoryPath);
        $inventory = json_decode($inventoryRaw, true, 64, JSON_THROW_ON_ERROR);
        if (($inventory['version'] ?? '') !== '0.0.10-beta.10' || empty($inventory['files'])) {
            throw new RuntimeException("Invalid Beta 10 deployment inventory.");
        }

        foreach ($inventory['files'] as $relPath => $expected) {
            $relNorm = str_replace('\\', '/', $relPath);
            if (!$manifestPolicy->isPathSafe($relNorm, $outputNorm)) {
                throw new RuntimeException("Unsafe path in deployment inventory: {$relNorm}");
            }
            $filePath = $outputNorm . '/' . $relNorm;
            if (!is_file($filePath) || is_link($filePath)) {
                throw new RuntimeException("Reconstructed Beta 10 missing expected file or symlink: {$relPath}");
            }
            if (filesize($filePath) !== ($expected['size'] ?? 0)) {
                throw new RuntimeException("Reconstructed Beta 10 size mismatch for: {$relPath}");
            }
            if (hash_file('sha256', $filePath) !== ($expected['sha256'] ?? '')) {
                throw new RuntimeException("Reconstructed Beta 10 sha256 mismatch for: {$relPath}");
            }
        }

        // Verify absence of deleted unlisted source artifacts
        $actualFiles = deploymentFiles($outputNorm);
        foreach (array_keys($actualFiles) as $actualRel) {
            if (!isset($inventory['files'][$actualRel])) {
                throw new RuntimeException("Unlisted artifact remains in reconstructed Beta 10: {$actualRel}");
            }
        }

        // Copy deployment-inventory.json to output
        copy($inventoryPath, $outputNorm . '/deployment-inventory.json');
        echo "Reconstructed v0.0.10-beta.10 app files verified against published inventory.\n";
    }

    public function prepareCandidate(string $sourceRoot, string $setup11InventoryPath, string $output): void
    {
        $sourceRoot = realpath($sourceRoot);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new RuntimeException("Invalid source root: {$sourceRoot}");
        }
        $sourceRoot = rtrim(str_replace('\\', '/', $sourceRoot), '/');

        $setup11InventoryPath = $this->assertSafeRunnerTempPath($setup11InventoryPath, true);
        $output = $this->assertSafeRunnerTempPath($output, false);

        if (!is_dir($output)) {
            mkdir($output, 0755, true);
        }
        $output = rtrim(str_replace('\\', '/', realpath($output)), '/');

        // Verify source version metadata
        $sourceVersionPath = $sourceRoot . '/version.json';
        if (!is_file($sourceVersionPath)) {
            throw new RuntimeException("version.json missing in source root: {$sourceVersionPath}");
        }
        $vData = json_decode((string)file_get_contents($sourceVersionPath), true, 32, JSON_THROW_ON_ERROR);
        if (($vData['version'] ?? '') !== '0.0.12' || ($vData['application_version'] ?? '') !== '0.0.12') {
            throw new RuntimeException("Source version.json must declare ephemeral candidate application version 0.0.12 (found: " . ($vData['version'] ?? '') . ")");
        }
        if (($vData['installer_version'] ?? '') !== '0.0.11') {
            throw new RuntimeException("Source version.json must retain Setup 11 native installer_version 0.0.11 (found: " . ($vData['installer_version'] ?? '') . ")");
        }

        file_put_contents($output . '/version.json', json_encode($vData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        // Copy backend files
        $backendOut = $output . '/backend';
        $certsOut = $backendOut . '/certs';
        $dbOut = $output . '/database';
        if (!is_dir($certsOut)) mkdir($certsOut, 0755, true);
        if (!is_dir($dbOut)) mkdir($dbOut, 0755, true);

        $pharSrc = $sourceRoot . '/backend/backend.phar';
        if (!is_file($pharSrc)) {
            throw new RuntimeException("Built backend.phar not found at: {$pharSrc}");
        }
        copy($pharSrc, $backendOut . '/backend.phar');

        $caSrc = $sourceRoot . '/backend/certs/cacert.pem';
        if (is_file($caSrc)) copy($caSrc, $certsOut . '/cacert.pem');

        $schemaSrc = $sourceRoot . '/database/pos_schema.sql';
        if (is_file($schemaSrc)) copy($schemaSrc, $dbOut . '/pos_schema.sql');

        // Copy frontend/dist
        $distSrc = $sourceRoot . '/frontend/dist';
        if (!is_dir($distSrc)) {
            throw new RuntimeException("Built frontend/dist not found at: {$distSrc}");
        }
        $this->copyDirectory($distSrc, $output . '/frontend/dist');

        // Generate deployment inventory using production desktopShellFingerprint
        $candidateInventory = createDeploymentInventory($output, $sourceRoot);
        if ($candidateInventory['version'] !== '0.0.12') {
            throw new RuntimeException("Candidate inventory version mismatch: {$candidateInventory['version']}");
        }

        file_put_contents(
            $output . '/deployment-inventory.json',
            json_encode($candidateInventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        echo "Candidate v0.0.12 app files prepared and inventory generated.\n";
    }

    public function sign(string $labDir, string $assetsDir): void
    {
        $labDir = $this->assertSafeRunnerTempPath($labDir, true);
        $assetsDir = $this->assertSafeRunnerTempPath($assetsDir, false);

        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
        }
        $assetsDir = rtrim(str_replace('\\', '/', realpath($assetsDir)), '/');

        // Verify owned lab marker exists
        $ownedLabMarker = $labDir . '/owned-lab.json';
        if (!is_file($ownedLabMarker)) {
            throw new RuntimeException("Owned lab marker missing ({$ownedLabMarker}); refusing to sign.");
        }

        $privateKey = (string)getenv('UPDATE_PRIVATE_KEY');
        if (trim($privateKey) === '') {
            throw new RuntimeException('CI signing key is required in UPDATE_PRIVATE_KEY.');
        }

        $setup11InventoryPath = $labDir . '/setup11-inventory.json';
        $candidateInventoryPath = $labDir . '/candidate-inventory.json';
        $candidateApp = $labDir . '/candidate-app';
        $candidateInventoryInApp = $candidateApp . '/deployment-inventory.json';
        if (!is_file($candidateInventoryPath) && is_file($candidateInventoryInApp)) {
            copy($candidateInventoryInApp, $candidateInventoryPath);
        }

        $beta10InventoryPath = $labDir . '/beta10-inventory.json';
        $beta10App = $labDir . '/beta10-app';
        $beta10InventoryInApp = $beta10App . '/deployment-inventory.json';
        if (!is_file($beta10InventoryPath) && is_file($beta10InventoryInApp)) {
            copy($beta10InventoryInApp, $beta10InventoryPath);
        }

        $contractPath = $labDir . '/data-contract.json';
        $shellRoot = $labDir . '/app';

        if (!is_file($setup11InventoryPath) || !is_file($candidateInventoryPath) || !is_file($beta10InventoryPath) || !is_file($contractPath)) {
            throw new RuntimeException('Prerequisite inventories or contract missing for signing phase.');
        }

        $setup11Inventory = json_decode((string)file_get_contents($setup11InventoryPath), true, 64, JSON_THROW_ON_ERROR);
        $candidateInventory = json_decode((string)file_get_contents($candidateInventoryPath), true, 64, JSON_THROW_ON_ERROR);
        $beta10Inventory = json_decode((string)file_get_contents($beta10InventoryPath), true, 64, JSON_THROW_ON_ERROR);

        // 1. Build Bootstrap Delta (0.0.11 -> 0.0.12)
        $modifiedFiles = [];
        $deletedFiles = [];
        foreach ($candidateInventory['files'] as $path => $entry) {
            if (($setup11Inventory['files'][$path]['sha256'] ?? null) !== $entry['sha256']) {
                $modifiedFiles[] = $path;
            }
        }
        foreach ($setup11Inventory['files'] as $path => $entry) {
            if (!isset($candidateInventory['files'][$path])) {
                $deletedFiles[] = $path;
            }
        }

        $deltaZipPath = $assetsDir . '/delta-0.0.11-to-0.0.12.zip';
        $genericDeltaZipPath = $assetsDir . '/delta.zip';
        $versionedManifestPath = $assetsDir . '/manifest-0.0.11.json';
        $versionedSigPath = $assetsDir . '/manifest-0.0.11.sig';

        // Refuse overwrites; do not unlink existing outputs
        if (file_exists($deltaZipPath)) {
            throw new RuntimeException("Artifact output already exists: {$deltaZipPath}. Overwrites are refused.");
        }
        if (file_exists($genericDeltaZipPath)) {
            throw new RuntimeException("Artifact output already exists: {$genericDeltaZipPath}. Overwrites are refused.");
        }
        if (file_exists($assetsDir . '/manifest.json')) {
            throw new RuntimeException("Manifest already exists in {$assetsDir}. Overwrites are refused.");
        }
        if (file_exists($versionedManifestPath)) {
            throw new RuntimeException("Versioned manifest already exists in {$assetsDir}. Overwrites are refused.");
        }
        if (file_exists($assetsDir . '/manifest.sig')) {
            throw new RuntimeException("Signature already exists in {$assetsDir}. Overwrites are refused.");
        }
        if (file_exists($versionedSigPath)) {
            throw new RuntimeException("Versioned signature already exists in {$assetsDir}. Overwrites are refused.");
        }

        $zip = new ZipArchive();
        if ($zip->open($deltaZipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException("Cannot create delta package: {$deltaZipPath}");
        }
        $manifestFiles = [];
        foreach ($modifiedFiles as $rel) {
            $src = $candidateApp . '/' . $rel;
            if (!$zip->addFile($src, $rel)) {
                $zip->close();
                throw new RuntimeException("Cannot add file to delta zip: {$rel}");
            }
            $manifestFiles[] = [
                'path' => $rel,
                'action' => 'replace',
                'sha256' => hash_file('sha256', $src),
                'size' => filesize($src),
            ];
        }
        $zip->close();
        copy($deltaZipPath, $genericDeltaZipPath);

        $manifestData = [
            'manifest_version' => '1.0',
            'version' => '0.0.12',
            'type' => 'delta',
            'minimum_version' => '0.0.11',
            'from_version' => '0.0.11',
            'baseline_files' => array_map(static fn(array $f): string => $f['sha256'], $setup11Inventory['files']),
            'update_engine_version' => '1.0.0',
            'channel' => 'stable',
            'released_at' => date('Y-m-d'),
            'changelog' => ['Setup 0.0.11 bootstrap to 0.0.12 channel transition repair candidate.'],
            'files' => $manifestFiles,
            'deleted_files' => $deletedFiles,
            'migrations' => [],
        ];

        $manifestPolicy = new UpdateManifestService();
        $validation = $manifestPolicy->validateManifest($manifestData);
        if (!$validation['valid']) {
            throw new RuntimeException('Invalid generated bootstrap manifest: ' . implode('; ', $validation['errors']));
        }
        if (!$manifestPolicy->validateManifestPaths($manifestData, $candidateApp)['ok']) {
            throw new RuntimeException('Unsafe generated bootstrap manifest paths.');
        }

        $manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($assetsDir . '/manifest.json', $manifestJson);
        file_put_contents($versionedManifestPath, $manifestJson);

        $sigService = new ManifestSignatureService();
        $signature = $sigService->signData($manifestJson, $privateKey);
        file_put_contents($assetsDir . '/manifest.sig', $signature);
        file_put_contents($versionedSigPath, $signature);

        $pubKeyPath = dirname(__DIR__) . '/backend/certs/update_public_key.pem';
        if (!$sigService->verifySignature($manifestJson, $signature, $pubKeyPath)) {
            throw new RuntimeException('Generated bootstrap manifest signature failed verification!');
        }
        $versionedManifestContent = (string)file_get_contents($versionedManifestPath);
        $versionedSigContent = (string)file_get_contents($versionedSigPath);
        if (!$sigService->verifySignature($versionedManifestContent, $versionedSigContent, $pubKeyPath)) {
            throw new RuntimeException('Generated versioned bootstrap manifest signature failed verification!');
        }

        copy($candidateInventoryPath, $assetsDir . '/deployment-inventory.json');
        echo "Bootstrap delta 0.0.11 -> 0.0.12 built and signed successfully.\n";

        // 2. Build Channel Transitions (Bidirectional on carrier v0.0.12)
        $builder = new ChannelTransitionBuilder();

        // Direction 1: 0.0.12 (stable) -> 0.0.10-beta.10 (beta)
        $res1 = $builder->build([
            'target-root' => $beta10App,
            'shell-root' => $shellRoot,
            'target-inventory' => $beta10InventoryPath,
            'contract' => $contractPath,
            'output' => $assetsDir,
            'source-root' => $candidateApp,
            'source-inventory' => $candidateInventoryPath,
            'carrier-tag' => 'v0.0.12',
            'target-repo' => 'pos-desktop-updates',
            'target-tag' => 'v0.0.10-beta.10',
        ], $privateKey);
        echo "Transition 0.0.12 -> 0.0.10-beta.10 built: {$res1['message']}\n";

        // Direction 2: 0.0.10-beta.10 (beta) -> 0.0.12 (stable) [Append to index]
        $res2 = $builder->build([
            'target-root' => $candidateApp,
            'shell-root' => $shellRoot,
            'target-inventory' => $candidateInventoryPath,
            'contract' => $contractPath,
            'output' => $assetsDir,
            'source-root' => $beta10App,
            'source-inventory' => $beta10InventoryPath,
            'carrier-tag' => 'v0.0.12',
            'target-repo' => 'pos-desktop-updates',
            'target-tag' => 'v0.0.12',
            'append' => true,
        ], $privateKey);
        echo "Return transition 0.0.10-beta.10 -> 0.0.12 appended: {$res2['message']}\n";

        // Verify index signature
        $indexContent = (string)file_get_contents($assetsDir . '/channel-transitions.json');
        $indexSig = (string)file_get_contents($assetsDir . '/channel-transitions.sig');
        if (!$sigService->verifySignature($indexContent, $indexSig, $pubKeyPath)) {
            throw new RuntimeException('Final channel-transitions.json signature failed verification!');
        }
        $indexDecoded = json_decode($indexContent, true, 32, JSON_THROW_ON_ERROR);
        if (count($indexDecoded['entries'] ?? []) !== 4) {
            throw new RuntimeException('Expected 4 entries in bidirectional transition index, found: ' . count($indexDecoded['entries'] ?? []));
        }

        echo "All channel transitions built, appended and verified successfully.\n";
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException("Symlink rejected in directory copy: " . $item->getPathname());
            }
            $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function streamVerifyAndExtractDelta(
        string $zipPath,
        string $destination,
        array $manifest,
        UpdateManifestService $manifestPolicy
    ): void {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Cannot open zip archive: {$zipPath}");
        }

        $manifestFiles = array_column($manifest['files'] ?? [], null, 'path');
        if (count($manifestFiles) !== count($manifest['files'] ?? [])) {
            throw new RuntimeException('Duplicate or missing path in signed manifest files.');
        }
        $zipEntryNames = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', $stat['name']);
            if (isset($zipEntryNames[$name])) {
                $zip->close();
                throw new RuntimeException("Duplicate entry in zip archive: {$name}");
            }
            $zipEntryNames[$name] = true;

            if (!isset($manifestFiles[$name])) {
                $zip->close();
                throw new RuntimeException("Extra unsigned entry in zip archive: {$name}");
            }
            if (!$manifestPolicy->isPathSafe($name, $destination)) {
                $zip->close();
                throw new RuntimeException("Unsafe path in archive: {$name}");
            }
        }

        if (count($zipEntryNames) !== count($manifestFiles)) {
            $zip->close();
            throw new RuntimeException("Zip entry count (" . count($zipEntryNames) . ") does not match signed manifest files count (" . count($manifestFiles) . ")");
        }

        // Stream verify and write each entry
        foreach ($manifestFiles as $name => $meta) {
            $stream = $zip->getStream($name);
            if (!$stream) {
                $zip->close();
                throw new RuntimeException("Cannot stream zip entry: {$name}");
            }

            $ctx = hash_init('sha256');
            $length = 0;
            $chunks = [];
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    fclose($stream);
                    $zip->close();
                    throw new RuntimeException("Read failure streaming entry: {$name}");
                }
                $length += strlen($chunk);
                hash_update($ctx, $chunk);
                $chunks[] = $chunk;
            }
            fclose($stream);

            $entrySha = hash_final($ctx);
            if (strtolower($entrySha) !== strtolower($meta['sha256'])) {
                $zip->close();
                throw new RuntimeException("Streamed entry SHA256 mismatch for {$name}! Expected {$meta['sha256']}, got {$entrySha}");
            }
            if ($length !== (int)$meta['size']) {
                $zip->close();
                throw new RuntimeException("Streamed entry size mismatch for {$name}! Expected {$meta['size']}, got {$length}");
            }

            $targetFile = $destination . '/' . $name;
            $targetDir = dirname($targetFile);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            file_put_contents($targetFile, implode('', $chunks));
        }

        $zip->close();
    }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    if (getenv('GITHUB_ACTIONS') !== 'true') {
        throw new RuntimeException('Setup 0.0.11 transition acceptance builder requires ephemeral CI environment.');
    }
    $repo = (string)getenv('GITHUB_REPOSITORY');
    if ($repo !== 'ABDO-TECK/pos-desktop-updates') {
        throw new RuntimeException("Acceptance builder restricted to canonical updater repo ABDO-TECK/pos-desktop-updates (got '{$repo}').");
    }
    $runnerTemp = (string)getenv('RUNNER_TEMP');
    if ($runnerTemp === '' || !is_dir($runnerTemp)) {
        throw new RuntimeException('RUNNER_TEMP is required and must exist.');
    }

    $options = getopt('', [
        'action:',
        'setup001-root:',
        'setup001-inventory:',
        'beta10-download:',
        'setup11-inventory:',
        'source-root:',
        'output:',
        'lab-dir:',
        'assets-dir:',
    ]);

    $action = $options['action'] ?? '';
    $helper = new Setup11TransitionAcceptanceHelper($runnerTemp);

    switch ($action) {
        case 'reconstruct-beta10':
            $helper->reconstructBeta10(
                $options['setup001-root'] ?? '',
                $options['setup001-inventory'] ?? '',
                $options['beta10-download'] ?? '',
                $options['output'] ?? ''
            );
            break;
        case 'prepare-candidate':
            $helper->prepareCandidate(
                $options['source-root'] ?? dirname(__DIR__),
                $options['setup11-inventory'] ?? '',
                $options['output'] ?? ''
            );
            break;
        case 'sign':
            $helper->sign(
                $options['lab-dir'] ?? '',
                $options['assets-dir'] ?? ''
            );
            break;
        default:
            throw new RuntimeException("Unknown action: {$action}");
    }
}
