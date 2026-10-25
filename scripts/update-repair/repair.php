<?php
declare(strict_types=1);

// Invoked only after the launcher verifies the repair engine's RSA signature.
// All mutable state is kept in a separate recovery directory, never the DB or customer userData.
try {
    $mode = $argv[1] ?? '';
    $root = $argv[2] ?? '';
    $work = $argv[3] ?? '';
    $assets = $argv[4] ?? '';
    $dataRoot = $argv[5] ?? '';

    $root = str_replace('\\', '/', (string)realpath($root));
    if (!in_array($mode, ['prepare', 'apply'], true) || !$root || !is_dir($work)) {
        throw new RuntimeException('Invalid repair arguments');
    }
    $work = str_replace('\\', '/', (string)realpath($work));

    // 1. Read signed engine configuration (trusted because launcher verified repair-engine.zip RSA)
    $configFile = __DIR__ . '/repair-config.json';
    if (!is_file($configFile)) {
        throw new RuntimeException('Repair engine configuration missing');
    }
    $config = json_decode((string)file_get_contents($configFile), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($config)) {
        throw new RuntimeException('Invalid repair configuration');
    }

    $toolVersion = (string)($config['tool_version'] ?? '');
    if ($toolVersion !== '1.0.0') {
        throw new RuntimeException("Invalid repair tool_version in config: {$toolVersion}");
    }
    $target = (string)($config['target_version'] ?? '');
    if ($target !== '0.0.12-beta.1') {
        throw new RuntimeException("Invalid repair target_version in config: {$target}");
    }
    $targetChannel = (string)($config['target_channel'] ?? 'beta');
    $expectedInstallerVersion = (string)($config['installer_version'] ?? '0.0.11');

    $versionData = json_decode((string)file_get_contents($root . '/version.json'), true, 512, JSON_THROW_ON_ERROR);
    $current = (string)($versionData['version'] ?? '');

    $supportedBaselines = isset($config['baseline_version'])
        ? (is_array($config['baseline_version']) ? $config['baseline_version'] : [(string)$config['baseline_version']])
        : ['0.0.11'];

    if (!in_array($current, $supportedBaselines, true)) {
        throw new RuntimeException("Current version {$current} is not an approved repair baseline (" . implode(', ', $supportedBaselines) . ")");
    }

    $baselinePharPin = null;
    if (isset($config['baseline_phars'][$current]) && is_array($config['baseline_phars'][$current])) {
        $baselinePharPin = $config['baseline_phars'][$current];
    } elseif (isset($config['baseline_phar']) && is_array($config['baseline_phar'])) {
        $baselinePharPin = $config['baseline_phar'];
    }

    if (!$baselinePharPin || empty($baselinePharPin['sha256']) || empty($baselinePharPin['size'])) {
        throw new RuntimeException('Missing approved baseline PHAR hash or size pin in signed configuration');
    }

    // Validate mandatory target_inventory typed map BEFORE autoload / apply
    if (!isset($config['target_inventory']) || !is_array($config['target_inventory']) || empty($config['target_inventory'])) {
        throw new RuntimeException('Missing or empty target_inventory map in signed configuration');
    }
    $rawTargetInventory = $config['target_inventory'];

    // At minimum, target_inventory must contain version.json and backend/backend.phar
    if (!isset($rawTargetInventory['version.json']) || !isset($rawTargetInventory['backend/backend.phar'])) {
        throw new RuntimeException('target_inventory in signed configuration must contain at least version.json and backend/backend.phar');
    }

    $validatedTargetInventory = [];
    $seenNormalizedTargetPaths = [];
    foreach ($rawTargetInventory as $rawRelPath => $info) {
        if (!is_string($rawRelPath)) {
            throw new RuntimeException('target_inventory path key must be string');
        }
        $relPath = str_replace('\\', '/', $rawRelPath);

        // Strict relative path checks: no '..', no leading '/', no ':', no null bytes
        if (str_contains($relPath, '..') || str_starts_with($relPath, '/') || str_contains($relPath, ':') || str_contains($relPath, "\0")) {
            throw new RuntimeException("Unsafe path in target_inventory: {$rawRelPath}");
        }

        // Canonical case / alias duplicate detection
        $lowerRel = strtolower($relPath);
        if (isset($seenNormalizedTargetPaths[$lowerRel])) {
            throw new RuntimeException("Duplicate or case-colliding entry in target_inventory: {$relPath}");
        }
        $seenNormalizedTargetPaths[$lowerRel] = true;

        // Allow READONLY public inventory paths distinct from WRITE repair allowlist:
        // Allowed: version.json, backend/backend.phar, frontend/dist/**, exact backend/certs/cacert.pem, database/pos_schema.sql
        $isAllowedReadOnly = (
            $relPath === 'version.json' ||
            $relPath === 'backend/backend.phar' ||
            str_starts_with($relPath, 'frontend/dist/') ||
            $relPath === 'backend/certs/cacert.pem' ||
            $relPath === 'database/pos_schema.sql'
        );
        if (!$isAllowedReadOnly) {
            throw new RuntimeException("Disallowed path in target_inventory (only public app artifacts permitted): {$relPath}");
        }

        // Strict negative filter: never generic private cert/credentials/keys/secrets/.env
        if (preg_match('#(^|/)(?:\.env|private[_-]?key|credentials?|secrets?|.*\.key|.*\.crt)(/|$)#i', $relPath)) {
            throw new RuntimeException("Forbidden private artifact in target_inventory: {$relPath}");
        }

        if (!is_array($info)) {
            throw new RuntimeException("Invalid target_inventory entry for {$relPath}: info must be array");
        }
        $sha256 = (string)($info['sha256'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            throw new RuntimeException("Invalid sha256 in target_inventory for {$relPath}: {$sha256}");
        }
        $size = $info['size'] ?? null;
        if (!is_int($size) || $size < 0) {
            throw new RuntimeException("Invalid non-negative size in target_inventory for {$relPath}: " . var_export($size, true));
        }

        $validatedTargetInventory[$relPath] = [
            'sha256' => strtolower($sha256),
            'size' => $size,
        ];
    }

    // Explicit source provenance metadata
    $targetInventoryProvenance = (string)($config['target_inventory_sha256'] ?? '');

    // 2. Readonly UserData Guard: check canonical and custom data roots for active/recovery/uncertain operations
    // Fail closed: check ALL 8 canonical roots PLUS explicit custom root (never replace canonical roots)
    $progData = getenv('ProgramData') ?: 'C:/ProgramData';
    $appData = getenv('APPDATA') ?: '';
    $localAppData = getenv('LOCALAPPDATA') ?: '';
    $candidateRoots = [
        $progData . '/POS System',
        $appData ? $appData . '/POS System' : '',
        $appData ? $appData . '/POS Desktop' : '',
        $appData ? $appData . '/pos-desktop' : '',
        $localAppData ? $localAppData . '/POS System' : '',
        $localAppData ? $localAppData . '/POS Desktop' : '',
        $localAppData ? $localAppData . '/pos-desktop' : '',
        $localAppData ? $localAppData . '/pos-desktop-updater' : '',
    ];
    $customUserData = $dataRoot !== '' ? $dataRoot : (getenv('POS_DATA_ROOT') ?: ($_ENV['POS_DATA_ROOT'] ?? null));
    if ($customUserData) {
        $candidateRoots[] = $customUserData;
    }

    $activeDataRoots = [];
    foreach ($candidateRoots as $cand) {
        if ($cand !== '' && is_dir($cand)) {
            $real = realpath($cand);
            if ($real !== false) {
                $activeDataRoots[] = str_replace('\\', '/', $real);
            }
        }
    }
    $activeDataRoots = array_values(array_unique($activeDataRoots));

    // Ensure work directory is outside application root and all data roots
    $forbiddenContainers = array_merge([$root], $activeDataRoots);
    foreach ($forbiddenContainers as $fc) {
        $fcNorm = rtrim(str_replace('\\', '/', $fc), '/');
        if (strcasecmp($work, $fcNorm) === 0 || str_starts_with(strtolower($work), strtolower($fcNorm) . '/')) {
            throw new RuntimeException("Work directory cannot be located inside application or user data root: {$work}");
        }
    }

    // Route internal updater state to isolated work recovery directory, never customer data
    putenv('APP_DEPLOY_ROOT=' . $root);
    putenv('APP_STORAGE_DIR=' . $work . '/state');
    $_ENV['APP_STORAGE_DIR'] = $work . '/state';

    // Inspect userData targets safely with readonly reads; fail closed on corruption; never mutate customer data
    foreach ($activeDataRoots as $udDir) {
        $lockNames = ['update-operation.lock', 'update-coordination.gate', 'recovery.lock'];
        foreach ($lockNames as $ln) {
            $lp = $udDir . '/' . $ln;
            if (file_exists($lp)) {
                throw new RuntimeException("Active update lock detected: {$lp}. Refuse repair while operation or recovery is pending.");
            }
        }
        $locks = glob($udDir . '/sale-operation-*.lock');
        if (!empty($locks)) {
            throw new RuntimeException("Active sale coordination lock detected in {$udDir}. Refuse repair while sale operation is active.");
        }

        $statePath = $udDir . '/update-state.json';
        if (file_exists($statePath)) {
            $rawState = @file_get_contents($statePath);
            if ($rawState === false) {
                throw new RuntimeException("Unreadable update-state.json in {$udDir}; clean completed state required.");
            }
            $stateData = json_decode($rawState, true);
            if (!is_array($stateData)) {
                throw new RuntimeException("Corrupted update-state.json in {$udDir}; clean completed state required.");
            }
            $stateVal = $stateData['state'] ?? $stateData['status'] ?? '';
            if (!in_array($stateVal, ['completed', 'idle'], true)) {
                throw new RuntimeException("Pending or active update state '{$stateVal}' in {$statePath}; clean completed state required.");
            }
            if (!empty($stateData['requires_recovery']) ||
                !empty($stateData['recovery_in_progress']) ||
                !empty($stateData['rollback_in_progress']) ||
                !empty($stateData['failed']) ||
                !empty($stateData['error']) ||
                !empty($stateData['primary_error'])) {
                throw new RuntimeException("Recovery or failure flags detected in {$statePath}; clean completed state required.");
            }
        }

        $jobsDir = $udDir . '/update-jobs';
        if (is_dir($jobsDir)) {
            $entries = scandir($jobsDir);
            if ($entries === false) {
                throw new RuntimeException("Unreadable update-jobs directory: {$jobsDir}");
            }
            foreach ($entries as $jf) {
                if ($jf === '.' || $jf === '..') {
                    continue;
                }
                if (!preg_match('/^job_(\d+)\.json$/', $jf, $m)) {
                    if (str_starts_with($jf, 'job_') || str_starts_with($jf, 'tmp_job_')) {
                        throw new RuntimeException("Pending or corrupt job entry in {$jobsDir}/{$jf}");
                    }
                    continue;
                }
                $expectedJobId = (int)$m[1];
                $jobPath = $jobsDir . '/' . $jf;
                $rawJob = @file_get_contents($jobPath);
                if ($rawJob === false) {
                    throw new RuntimeException("Unreadable job file: {$jobPath}");
                }
                $jobContent = json_decode($rawJob, true);
                if (!is_array($jobContent)) {
                    throw new RuntimeException("Corrupted job file JSON in {$jobPath}");
                }
                $actualJobId = $jobContent['job_id'] ?? null;
                if ($actualJobId !== $expectedJobId) {
                    throw new RuntimeException("Job ID mismatch in {$jobPath}: expected {$expectedJobId}, found " . var_export($actualJobId, true));
                }
                $operationId = $jobContent['operation_id'] ?? null;
                if (!is_string($operationId) || trim($operationId) === '') {
                    throw new RuntimeException("Missing operation_id in job file {$jobPath}");
                }

                // Any non-empty settlement_error blocks even contradictory completed record
                if (array_key_exists('settlement_error', $jobContent) && !empty($jobContent['settlement_error'])) {
                    throw new RuntimeException("Settlement error detected in job file {$jobPath}: " . $jobContent['settlement_error']);
                }

                $status = (string)($jobContent['status'] ?? '');
                if ($status !== 'completed') {
                    throw new RuntimeException("Incomplete update job in {$jobPath} (status: {$status})");
                }
                $res = $jobContent['result'] ?? null;
                if (!is_array($res) || ($res['ok'] ?? null) !== true) {
                    throw new RuntimeException("Update job in {$jobPath} lacks verified result.ok === true");
                }
                if (!empty($res['error'])) {
                    throw new RuntimeException("Update job in {$jobPath} reports failure in result: " . $res['error']);
                }

                // Require data object if present to be non-null
                if (array_key_exists('data', $res)) {
                    if ($res['data'] === null || !is_array($res['data'])) {
                        throw new RuntimeException("Update job in {$jobPath} has invalid or null data object");
                    }
                    if (!empty($res['data']['requires_desktop_handoff'])) {
                        throw new RuntimeException("Pending desktop handoff apply job not completed on installed version in {$jobPath}");
                    }
                }
            }
        }
    }

    // 3. Before ANY old autoload / candidate code execution:
    // Verify currently installed PHAR exact baseline hash+size with built-in PHP functions only
    $installedPhar = $root . '/backend/backend.phar';
    if (!is_file($installedPhar)) {
        throw new RuntimeException("Installed backend.phar missing at: {$installedPhar}");
    }
    $actualPharSize = filesize($installedPhar);
    $actualPharHash = strtolower((string)hash_file('sha256', $installedPhar));
    $expectedPharHash = strtolower((string)$baselinePharPin['sha256']);
    $expectedPharSize = (int)$baselinePharPin['size'];

    if ($actualPharSize !== $expectedPharSize || !hash_equals($expectedPharHash, $actualPharHash)) {
        throw new RuntimeException("Installed backend.phar hash or size does not match approved baseline before autoload. Expected {$expectedPharHash} ({$expectedPharSize}B), got {$actualPharHash} ({$actualPharSize}B)");
    }

    // Copy into unique owned work/engine.phar and verify copy; do not overwrite unless matching approved baseline
    $ownedPhar = $work . '/engine.phar';
    if (file_exists($ownedPhar)) {
        if (filesize($ownedPhar) !== $expectedPharSize || !hash_equals($expectedPharHash, strtolower((string)hash_file('sha256', $ownedPhar)))) {
            throw new RuntimeException('Existing engine.phar in work directory does not match approved baseline hash or size');
        }
    } else {
        if (!copy($installedPhar, $ownedPhar)) {
            throw new RuntimeException('Cannot copy verified backend.phar to work directory');
        }
        if (filesize($ownedPhar) !== $expectedPharSize || !hash_equals($expectedPharHash, strtolower((string)hash_file('sha256', $ownedPhar)))) {
            throw new RuntimeException('Copied engine.phar hash or size mismatch against approved baseline');
        }
    }

    // Now safely autoload verified engine
    require 'phar://' . str_replace('\\', '/', $work) . '/engine.phar/vendor/autoload.php';
    require __DIR__ . '/UpdateAssetDownloader.php';

    $policy = new App\Services\UpdateManifestService();
    $signature = new App\Services\ManifestSignatureService(__DIR__ . '/update_public_key.pem');

    $base = 'https://github.com/ABDO-TECK/pos-desktop-updates/releases/download/v' . $target . '/';
    $names = ['manifest-' . $current . '.json', 'manifest-' . $current . '.sig', 'delta-' . $current . '-to-' . $target . '.zip'];
    $download = new App\Services\UpdateAssetDownloader($root . '/backend/certs/cacert.pem');

    // In prepare mode, retrieve offline assets or download
    foreach ($names as $name) {
        if ($mode !== 'prepare') continue;
        if ($assets !== '' && is_dir($assets)) {
            if (!copy($assets . '/' . $name, $work . '/' . $name)) {
                throw new RuntimeException('Cannot read offline signed asset ' . $name);
            }
        } else {
            echo 'Downloading ' . $name . "\n";
            $result = $download->download($base . $name, $work . '/' . $name);
            if (!$result['ok']) throw new RuntimeException($result['error']);
        }

        // Verify the manifest before downloading or accepting the large ZIP
        if ($name === $names[1]) {
            if (!$signature->verifyManifestFile($work . '/' . $names[0], $work . '/' . $names[1], __DIR__ . '/update_public_key.pem')['ok']) {
                throw new RuntimeException('RSA manifest signature rejected');
            }
        }
    }

    // Manifest verification
    if (!$signature->verifyManifestFile($work . '/' . $names[0], $work . '/' . $names[1], __DIR__ . '/update_public_key.pem')['ok']) {
        throw new RuntimeException('RSA manifest signature rejected');
    }
    $validated = $policy->validateManifest((string)file_get_contents($work . '/' . $names[0]));
    if (!$validated['valid']) {
        throw new RuntimeException('Invalid signed manifest');
    }
    $manifest = $validated['manifest'];

    if (($manifest['type'] ?? '') !== 'delta' || empty($manifest['baseline_files']) || !empty($manifest['migrations'])) {
        throw new RuntimeException('Repair requires a baseline-specific delta without migrations');
    }
    if (!$policy->checkReleaseAssociation($manifest, $target, 'v' . $target, $targetChannel)['compatible'] ||
        !$policy->checkVersionCompatibility($current, $manifest)['compatible']) {
        throw new RuntimeException('Wrong repair target or baseline');
    }

    // 4. Strict repair allowlist check: evaluate BEFORE general baseline checks to avoid reading protected paths
    // ONLY version.json, backend/backend.phar, frontend/dist/**
    // Reject SQL, schema, certs, native, .env, storage, credential paths regardless of signature
    $manifestEntries = array_merge(array_column($manifest['files'], 'path'), $manifest['deleted_files'] ?? []);
    foreach ($manifestEntries as $relative) {
        $norm = str_replace('\\', '/', $relative);
        if (preg_match('#\.(sql|db|sqlite|key|crt|cert|pem|exe|dll|node|jar)$#i', $norm) ||
            preg_match('#(^|/)(?:\.env|certs/|database/|storage/|private[_-]?key|credentials?|secrets?)(/|$)#i', $norm)) {
            throw new RuntimeException("Forbidden repair target path: {$norm}");
        }
        $allowed = ($norm === 'version.json' || $norm === 'backend/backend.phar' || str_starts_with($norm, 'frontend/dist/'));
        if (!$allowed) {
            throw new RuntimeException("Unexpected repair target: {$norm}");
        }
    }

    // Now recheck exact baseline files with production manifest policy
    if (!$policy->validateManifestPaths($manifest, $root)['ok'] || !$policy->verifyBaselineFiles($root, $manifest)) {
        throw new RuntimeException('Installed files differ from the signed baseline');
    }

    $delta = new App\Services\DeltaUpdateService($policy, $root, $work . '/state');

    if ($mode === 'prepare') {
        $zip = new ZipArchive();
        if ($zip->open($work . '/' . $names[2]) !== true) {
            throw new RuntimeException('Cannot open downloaded delta ZIP');
        }
        $expected = array_fill_keys(array_column($manifest['files'], 'path'), true);
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                $zip->close();
                throw new RuntimeException("Unreadable ZIP entry at index {$i}");
            }
            $entryNorm = str_replace('\\', '/', $entry);
            // Check traversal and prefix ambiguity on ALL entries (including directories) BEFORE skipping
            if (str_contains($entryNorm, '..') || str_starts_with($entryNorm, '/') || str_contains($entryNorm, ':')) {
                $zip->close();
                throw new RuntimeException("Directory traversal rejected in ZIP entry: {$entry}");
            }
            if (str_ends_with($entryNorm, '/')) {
                continue;
            }
            $cleanEntry = preg_replace('#^files/#', '', $entryNorm);
            if (!isset($expected[$cleanEntry])) {
                $zip->close();
                throw new RuntimeException("Unexpected ZIP entry: {$entry}");
            }
            if (isset($seen[$cleanEntry])) {
                $zip->close();
                throw new RuntimeException("Duplicate ZIP entry: {$entry}");
            }
            $seen[$cleanEntry] = true;
        }
        $zip->close();
        if (count($seen) !== count($expected)) {
            throw new RuntimeException('Delta ZIP entries count does not match manifest files count');
        }

        $stage = $delta->extractZipToStaging($work . '/' . $names[2], $delta->getStagingDir($target));
        if (!$stage['ok']) {
            throw new RuntimeException(implode('; ', $stage['errors']));
        }
    }

    if (!$policy->verifyStagedFiles($delta->getStagingDir($target), $manifest['files'])['ok']) {
        throw new RuntimeException('Staged payload hash mismatch');
    }

    if ($mode === 'apply') {
        $snapshot = $delta->createBackupSnapshot($current, $target, $manifest);
        if (!$snapshot['ok']) {
            throw new RuntimeException($snapshot['error']);
        }
        $result = $delta->applyStagedFiles($manifest, $snapshot['snapshot_path']);
        if (!$result['ok']) {
            // applyStagedFiles already rolled back internally on error
            throw new RuntimeException('Apply staged files failed: ' . implode('; ', $result['errors'] ?? []));
        }

        // Wrap ENTIRE post-apply verification in try/catch Throwable to invoke rollback exactly once on ANY verification failure
        try {
            if (!$policy->verifyBaselineFiles($root, ['baseline_files' => array_column($manifest['files'], 'sha256', 'path')])) {
                throw new RuntimeException('Applied files do not match signed manifest hashes');
            }

            foreach ($manifest['deleted_files'] ?? [] as $delPath) {
                if (file_exists($root . '/' . $delPath)) {
                    throw new RuntimeException("Deleted path survived: {$delPath}");
                }
            }

            // Verify full target inventory: all target code paths from embedded validatedTargetInventory
            foreach ($validatedTargetInventory as $relPath => $expectedInfo) {
                $targetFile = $root . '/' . $relPath;
                if (!is_file($targetFile)) {
                    throw new RuntimeException("Target inventory file missing after apply: {$relPath}");
                }
                $actualSize = filesize($targetFile);
                $expectedSize = $expectedInfo['size'];
                if ($actualSize !== $expectedSize) {
                    throw new RuntimeException("Target inventory file size mismatch for {$relPath}: expected {$expectedSize}, got {$actualSize}");
                }
                $actualHash = strtolower((string)hash_file('sha256', $targetFile));
                $expectedHash = $expectedInfo['sha256'];
                if (!hash_equals($expectedHash, $actualHash)) {
                    throw new RuntimeException("Target inventory file hash mismatch for {$relPath}: expected {$expectedHash}, got {$actualHash}");
                }
            }

            $postVersionRaw = @file_get_contents($root . '/version.json');
            if ($postVersionRaw === false) {
                throw new RuntimeException('version.json missing or unreadable after apply');
            }
            $postVersion = json_decode($postVersionRaw, true);
            if (!is_array($postVersion)) {
                throw new RuntimeException('version.json corrupted after apply');
            }
            if (($postVersion['version'] ?? '') !== $target) {
                throw new RuntimeException("version.json version mismatch: expected {$target}, got " . ($postVersion['version'] ?? ''));
            }
            if (($postVersion['channel'] ?? '') !== $targetChannel) {
                throw new RuntimeException("version.json channel mismatch: expected {$targetChannel}, got " . ($postVersion['channel'] ?? ''));
            }
            if (isset($postVersion['installer_version']) && $postVersion['installer_version'] !== $expectedInstallerVersion) {
                throw new RuntimeException("version.json installer_version mismatch: expected {$expectedInstallerVersion}, got {$postVersion['installer_version']}");
            }
        } catch (Throwable $verifyError) {
            // Invoke rollback exactly once for ANY verification exception; preserve primary failure and rollback details
            $rollbackResult = null;
            $rollbackException = null;
            try {
                $rollbackResult = $delta->rollbackFiles($snapshot['snapshot_path']);
            } catch (Throwable $rbEx) {
                $rollbackException = $rbEx;
            }
            $errDetails = 'Post-apply verification failed: ' . $verifyError->getMessage();
            if ($rollbackException !== null) {
                $errDetails .= '; rollback exception: ' . $rollbackException->getMessage();
            } elseif ($rollbackResult !== null) {
                $errDetails .= '; rollback result: ' . json_encode($rollbackResult, JSON_UNESCAPED_SLASHES);
            }
            throw new RuntimeException($errDetails, 0, $verifyError);
        }

        $resultData = [
            'ok' => true,
            'from' => $current,
            'to' => $target,
            'snapshot' => $snapshot['snapshot_path'],
            'applied' => $result['applied_files'],
        ];
        $receiptFile = $work . '/result.json';
        if (file_put_contents($receiptFile, json_encode($resultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false || !is_file($receiptFile)) {
            throw new RuntimeException('Failed to write result.json receipt before finishing repair');
        }
    }

    echo strtoupper($mode) . ' verified successfully. Recovery directory: ' . $work . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Repair stopped: ' . $error->getMessage() . "\n");
    exit(1);
}
