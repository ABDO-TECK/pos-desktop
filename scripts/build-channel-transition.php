<?php
declare(strict_types=1);
require_once __DIR__.'/../backend/vendor/autoload.php';

use App\Services\{
    ChannelTransitionManifestService,
    ManifestSignatureService,
    ReleaseVersionPolicy,
    UpdateChannelPolicy,
    UpdateDataCompatibilityService,
    UpdateManifestService
};

final class ChannelTransitionBuilder
{
    private ManifestSignatureService $signatureService;
    private ChannelTransitionManifestService $verifier;
    private UpdateManifestService $manifestPolicy;
    private ?string $legacyDefaultSourceRoot;

    public function __construct(
        ?ManifestSignatureService $signatureService = null,
        ?ChannelTransitionManifestService $verifier = null,
        ?UpdateManifestService $manifestPolicy = null,
        ?string $legacyDefaultSourceRoot = null
    ) {
        $this->signatureService = $signatureService ?? new ManifestSignatureService();
        $this->verifier = $verifier ?? new ChannelTransitionManifestService($this->signatureService);
        $this->manifestPolicy = $manifestPolicy ?? new UpdateManifestService();
        $this->legacyDefaultSourceRoot = $legacyDefaultSourceRoot;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, message: string, entries: array<array>}
     */
    public function build(array $options, string $privateKey): array
    {
        if ($privateKey === '') {
            throw new RuntimeException('CI signing key is required.');
        }

        foreach (['target-root', 'shell-root', 'target-inventory', 'contract', 'output'] as $name) {
            if (!isset($options[$name]) || !is_string($options[$name])) {
                throw new RuntimeException('Missing builder input: ' . $name);
            }
        }

        $genericFlags = ['carrier-tag', 'target-repo', 'target-tag', 'source-root', 'source-inventory'];
        $hasAnyGeneric = false;
        foreach ($genericFlags as $flag) {
            if (isset($options[$flag])) {
                $hasAnyGeneric = true;
                break;
            }
        }

        if ($hasAnyGeneric) {
            foreach ($genericFlags as $flag) {
                if (!isset($options[$flag]) || !is_string($options[$flag])) {
                    throw new RuntimeException("Generic transition mode requires explicit --{$flag}.");
                }
            }
            $isLegacyMode = false;
        } else {
            $isLegacyMode = true;
        }

        $output = $options['output'];
        if (!is_dir($output) || !is_writable($output)) {
            throw new RuntimeException('Output directory does not exist or is not writable: ' . $output);
        }

        $targetRoot = realpath($options['target-root']);
        if ($targetRoot === false || !is_dir($targetRoot)) {
            throw new RuntimeException('Invalid target root directory: ' . $options['target-root']);
        }

        $sourceRoot = $isLegacyMode
            ? ($this->legacyDefaultSourceRoot !== null ? realpath($this->legacyDefaultSourceRoot) : realpath(__DIR__ . '/..'))
            : realpath($options['source-root']);
        if ($sourceRoot === false || !is_dir($sourceRoot)) {
            throw new RuntimeException('Invalid source root directory.');
        }

        $sourceInventoryPath = $isLegacyMode ? ($output . '/deployment-inventory.json') : $options['source-inventory'];
        if (!is_file($sourceInventoryPath)) {
            throw new RuntimeException('Source inventory file not found: ' . $sourceInventoryPath);
        }

        $targetInventoryPath = $options['target-inventory'];
        if (!is_file($targetInventoryPath)) {
            throw new RuntimeException('Target inventory file not found: ' . $targetInventoryPath);
        }

        $sourceRaw = file_get_contents($sourceInventoryPath);
        $targetRaw = file_get_contents($targetInventoryPath);
        if ($sourceRaw === false || $targetRaw === false) {
            throw new RuntimeException('Failed to read inventory files.');
        }

        $source = json_decode($sourceRaw, true, 64, JSON_THROW_ON_ERROR);
        $target = json_decode($targetRaw, true, 64, JSON_THROW_ON_ERROR);

        if (!isset($source['version'], $source['files']) || !is_array($source['files'])) {
            throw new RuntimeException('Malformed source inventory.');
        }
        if (!isset($target['version'], $target['files']) || !is_array($target['files'])) {
            throw new RuntimeException('Malformed target inventory.');
        }

        $fromChannel = UpdateChannelPolicy::installedChannel($source['version']);
        $targetChannel = UpdateChannelPolicy::installedChannel($target['version']);

        if ($isLegacyMode) {
            if ($fromChannel !== 'beta' || $target['version'] !== '0.0.1') {
                throw new RuntimeException('Only a beta carrier returning to the preserved stable baseline is approved.');
            }
            $carrierTag = 'v' . $source['version'];
            $targetRepo = 'pos-desktop';
            $targetTag = 'v0.0.1';
            $stemPrefix = 'transition-' . $source['version'] . '-to-stable-0.0.1-';
        } else {
            if (!in_array($fromChannel, UpdateChannelPolicy::active(), true) || !in_array($targetChannel, UpdateChannelPolicy::active(), true)) {
                throw new RuntimeException('Source and target must belong to active channels.');
            }
            if ($fromChannel === $targetChannel) {
                throw new RuntimeException('Source and target must belong to different active channels.');
            }
            $carrierTag = $options['carrier-tag'];
            $targetRepo = $options['target-repo'];
            $targetTag = $options['target-tag'];
            if (!in_array($targetRepo, ['pos-desktop', 'pos-desktop-updates'], true)) {
                throw new RuntimeException('Invalid target repository: ' . $targetRepo);
            }
            if ($targetTag !== 'v' . $target['version']) {
                throw new RuntimeException('Target tag must match target version: v' . $target['version']);
            }
            if (!preg_match('/^v\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $carrierTag)) {
                throw new RuntimeException('Invalid carrier tag format: ' . $carrierTag);
            }
            $stemPrefix = 'transition-' . $source['version'] . '-to-' . $targetChannel . '-' . $target['version'] . '-';
        }

        // Semantic pre-validation of data compatibility contract BEFORE ANY output mutations
        $contractRaw = file_get_contents($options['contract']);
        if ($contractRaw === false) throw new RuntimeException('Failed to read contract.');
        $contract = json_decode($contractRaw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($contract)
            || ($contract['format'] ?? null) !== 1
            || ($contract['metadata_migration'] ?? null) !== UpdateDataCompatibilityService::METADATA_MIGRATION
            || !is_string($contract['schema_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $contract['schema_sha256'])
            || !is_string($contract['ledger_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $contract['ledger_sha256'])
        ) {
            throw new RuntimeException('Invalid data compatibility contract: format, migration or hashes invalid.');
        }
        $contract = array_intersect_key($contract, array_flip(['format', 'metadata_migration', 'schema_sha256', 'ledger_sha256']));

        // Semantic pre-validation of source and target inventories and version.json identity
        foreach ([['source', $sourceRoot, $source], ['target', $targetRoot, $target]] as [$label, $root, $inventory]) {
            if (!isset($inventory['files']['version.json']) || !is_array($inventory['files']['version.json'])) {
                throw new RuntimeException("Malformed {$label} inventory: missing version.json.");
            }
            $vFile = $root . '/version.json';
            if (!is_file($vFile) || is_link($vFile)) {
                throw new RuntimeException("Missing or symlinked {$label} version.json file.");
            }
            $vRaw = file_get_contents($vFile);
            $vJson = json_decode((string)$vRaw, true);
            if (!is_array($vJson) || ($vJson['version'] ?? null) !== $inventory['version']) {
                throw new RuntimeException("{$label} version.json content does not match inventory version.");
            }
            if (!$this->manifestPolicy->validateManifestPaths(['baseline_files' => array_map(static fn(array $file): string => $file['sha256'], $inventory['files'])], $root)['ok']) {
                throw new RuntimeException("Unsafe or protected {$label} inventory path.");
            }
            foreach ($inventory['files'] as $path => $file) {
                if (!is_string($path) || !$this->manifestPolicy->isPathSafe($path, $root)) {
                    throw new RuntimeException("Unsafe or protected {$label} inventory path: " . $path);
                }
                $physical = $root . '/' . $path;
                if (!is_file($physical) || is_link($physical) || filesize($physical) !== $file['size'] || !hash_equals($file['sha256'], hash_file('sha256', $physical))) {
                    throw new RuntimeException('Actual build or original Setup bytes differ from inventory: ' . $path);
                }
            }
        }

        // Retained shell verification: leaf and all ancestor directories must be genuine (no symlinks, no reparse points)
        $shellRealRoot = realpath($options['shell-root']);
        if ($shellRealRoot === false || !is_dir($shellRealRoot)) {
            throw new RuntimeException('Invalid shell root directory: ' . $options['shell-root']);
        }
        $shellRootNorm = rtrim(str_replace('\\', '/', $shellRealRoot), '/');

        $shell = [];
        foreach (['POS Desktop.exe', 'resources/app.asar'] as $file) {
            $shellFile = $options['shell-root'] . '/' . $file;
            $realPath = realpath($shellFile);
            if ($realPath === false || !is_file($realPath) || is_link($shellFile)) {
                throw new RuntimeException('Retained shell artifact missing or is a symlink: ' . $file);
            }
            $realPathNorm = str_replace('\\', '/', $realPath);
            if (!str_starts_with($realPathNorm, $shellRootNorm . '/')) {
                throw new RuntimeException('Retained shell path escapes shell root: ' . $file);
            }
            $cursor = $options['shell-root'];
            $segments = explode('/', str_replace('\\', '/', $file));
            foreach ($segments as $segment) {
                $cursor .= '/' . $segment;
                if (is_link($cursor)) {
                    throw new RuntimeException('Retained shell path contains a symlink ancestor: ' . $file);
                }
            }
            $shell[$file] = hash_file('sha256', $realPath);
        }
        $shellDigest = hash('sha256', json_encode($shell, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $installed = [
            'version' => $source['version'],
            'channel' => $fromChannel,
            'shell_sha256' => $shellDigest,
            'inventory_sha256' => hash('sha256', $sourceRaw),
            'inventory' => $source
        ];
        $destination = [
            'version' => $target['version'],
            'channel' => $targetChannel,
            'repo' => $targetRepo,
            'tag' => $targetTag,
            'inventory_sha256' => hash('sha256', $targetRaw),
            'inventory' => $target
        ];
        $carrier = ['tag_name' => $carrierTag, 'draft' => false];

        $isAppend = isset($options['append']);
        $indexFile = $output . '/channel-transitions.json';
        $sigFile = $output . '/channel-transitions.sig';
        $existingEntries = [];

        // Reject orphan index signature or dangling link
        if (!is_file($indexFile) && (file_exists($sigFile) || is_link($sigFile))) {
            throw new RuntimeException("Orphan index signature detected without index file: {$sigFile}.");
        }

        if (is_file($indexFile)) {
            if (!$isAppend) {
                throw new RuntimeException("Transition index already exists in output directory: {$indexFile}. Pass --append to append new transitions.");
            }
            if (!is_file($sigFile)) {
                throw new RuntimeException("Existing transition index signature file missing: {$sigFile}.");
            }
            $existingRaw = file_get_contents($indexFile);
            $existingSig = file_get_contents($sigFile);
            $indexCheck = $this->verifier->verifyIndex($existingRaw, $existingSig, $carrier);
            if (!$indexCheck['ok']) {
                throw new RuntimeException('Existing transition index verification failed; cannot append to invalid or unsigned index.');
            }
            $existingDecoded = json_decode($existingRaw, true, 16, JSON_THROW_ON_ERROR);
            $existingEntries = $existingDecoded['entries'] ?? [];
            if (($existingDecoded['carrier_tag'] ?? null) !== $carrierTag) {
                throw new RuntimeException('Carrier tag mismatch between existing index and requested build.');
            }
            // Enforce bounded 32 entry cap BEFORE build
            if (count($existingEntries) + 2 > 32) {
                throw new RuntimeException('Transition index entry cap reached (32 entries).');
            }
        }

        // Pre-validate that output files do not already exist (non-destructive failure, no overwrite)
        foreach (['delta', 'full'] as $type) {
            $stem = $stemPrefix . $type;
            $zipPath = $output . '/' . $stem . '.zip';
            $descPath = $output . '/' . $stem . '.json';
            $sigPath = $output . '/' . $stem . '.sig';

            if (file_exists($zipPath) || is_link($zipPath) || file_exists($descPath) || is_link($descPath) || file_exists($sigPath) || is_link($sigPath)) {
                throw new RuntimeException("Output artifact already exists: {$stem}; refusing to overwrite.");
            }

            if ($isAppend) {
                foreach ($existingEntries as $e) {
                    if (($e['from_version'] ?? '') === $source['version'] && ($e['version'] ?? '') === $target['version'] && ($e['type'] ?? '') === $type) {
                        throw new RuntimeException("Duplicate transition entry already exists in index: {$source['version']} -> {$target['version']} ({$type}).");
                    }
                }
            }
        }

        // All pre-validations passed. Now execute mutations safely.
        $newEntries = [];
        foreach (['delta', 'full'] as $type) {
            $files = $target['files'];
            if ($type === 'delta') {
                $files = array_filter($files, static fn(array $file, string $path): bool => ($source['files'][$path] ?? null) !== $file, ARRAY_FILTER_USE_BOTH);
            }
            $stem = $stemPrefix . $type;
            $zipPath = $output . '/' . $stem . '.zip';

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Transition ZIP already exists or cannot be created: ' . $zipPath);
            }
            foreach ($files as $path => $file) {
                if (!$zip->addFile($targetRoot . '/' . $path, $path)) {
                    throw new RuntimeException('Cannot package target artifact: ' . $path);
                }
            }
            if (!$zip->close()) throw new RuntimeException('Cannot finalize transition ZIP.');

            $descriptor = [
                'format' => 1,
                'operation' => 'channel_transition',
                'carrier_tag' => $carrier['tag_name'],
                'from_version' => $source['version'],
                'from_channel' => $fromChannel,
                'source_inventory_sha256' => $installed['inventory_sha256'],
                'source_inventory' => $source,
                'version' => $target['version'],
                'channel' => $targetChannel,
                'target_repo' => $targetRepo,
                'target_tag' => $targetTag,
                'target_inventory_sha256' => $destination['inventory_sha256'],
                'target_inventory' => $target,
                'shell_sha256' => $shellDigest,
                'type' => $type,
                'files' => array_map(static fn(string $path): array => ['path' => $path, ...$files[$path]], array_keys($files)),
                'deleted_files' => array_keys(array_diff_key($source['files'], $target['files'])),
                'migrations' => [],
                'package' => [
                    'asset' => $stem . '.zip',
                    'sha256' => hash_file('sha256', $zipPath),
                    'size' => filesize($zipPath),
                ],
                'data_compatibility' => $contract,
            ];

            $raw = json_encode($descriptor, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            $signature = $this->signatureService->signData($raw, $privateKey);
            $checked = $this->verifier->verify($raw, $signature, $carrier, $installed, $destination);
            if (!$checked['ok']) {
                throw new RuntimeException('Transition descriptor verification failed: ' . $checked['reason_code']);
            }

            file_put_contents($output . '/' . $stem . '.json', $raw);
            file_put_contents($output . '/' . $stem . '.sig', $signature);
            $newEntries[] = [
                'from_version' => $source['version'],
                'source_inventory_sha256' => $installed['inventory_sha256'],
                'version' => $target['version'],
                'channel' => $targetChannel,
                'descriptor' => $stem . '.json',
                'signature' => $stem . '.sig',
                'descriptor_sha256' => hash('sha256', $raw),
                'type' => $type,
            ];
        }

        $mergedEntries = array_merge($existingEntries, $newEntries);
        $index = json_encode(['format' => 1, 'carrier_tag' => $carrier['tag_name'], 'entries' => $mergedEntries], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $indexSignature = $this->signatureService->signData($index, $privateKey);
        if (!$this->verifier->verifyIndex($index, $indexSignature, $carrier)['ok']) {
            throw new RuntimeException('Transition index verification failed.');
        }

        file_put_contents($indexFile, $index);
        file_put_contents($sigFile, $indexSignature);

        $msg = $isLegacyMode
            ? "PASS signed beta carrier: exact original stable artifacts, changed-only delta and complete application bundle; no Setup or SQL payload"
            : "PASS signed transition carrier: exact target artifacts, changed-only delta and complete application bundle; no Setup or SQL payload";

        return ['ok' => true, 'message' => $msg, 'entries' => $mergedEntries];
    }
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('GITHUB_REPOSITORY') !== 'ABDO-TECK/pos-desktop-updates') {
        throw new RuntimeException('Signed transition publishing requires the isolated release workflow.');
    }

    $options = getopt('', [
        'target-root:',
        'shell-root:',
        'target-inventory:',
        'contract:',
        'output:',
        'source-root:',
        'source-inventory:',
        'carrier-tag:',
        'target-repo:',
        'target-tag:',
        'append',
    ]);

    $key = getenv('UPDATE_PRIVATE_KEY');
    if (!is_string($key) || $key === '') {
        throw new RuntimeException('CI signing key is required.');
    }

    $builder = new ChannelTransitionBuilder();
    $result = $builder->build($options, $key);
    echo $result['message'] . "\n";
}
