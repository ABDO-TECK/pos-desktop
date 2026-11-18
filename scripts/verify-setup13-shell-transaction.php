<?php
declare(strict_types=1);

if (PHP_OS_FAMILY !== 'Windows' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted') {
    fwrite(STDERR, "This acceptance probe runs only on GitHub-hosted Windows Actions.\n");
    exit(1);
}

require_once __DIR__ . '/../backend/vendor/autoload.php';
require_once __DIR__ . '/deployment-inventory.php';

use App\Services\ManifestSignatureService;
use App\Services\ProductInventoryService;
use App\Services\ShellDeltaFileTransactionService;
use App\Services\ShellDeltaManifestService;
use App\Services\UpdateDataCompatibilityService;

function ensure(bool $condition, string $failure): void
{
    if (!$condition) {
        throw new RuntimeException($failure);
    }
}

function readJson(string $path): array
{
    $raw = file_get_contents($path);
    ensure($raw !== false, "Could not read {$path}.");
    return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
}

function writeJson(string $path, array $data): void
{
    $raw = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    ensure(file_put_contents($path, $raw) === strlen($raw), "Could not write {$path}.");
}

function measureDatabase(string $lab, string $role): void
{
    ensure(in_array($role, ['source', 'target'], true), 'Unknown database profile role.');
    $port = filter_var(getenv('DB_PORT'), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1025, 'max_range' => 65535],
    ]);
    ensure(is_int($port) && !in_array($port, [3306, 3307], true), 'Expected an ephemeral database port.');
    $db = new PDO("mysql:host=127.0.0.1;port={$port};dbname=pos_db;charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $dataDir = $role === 'source' ? $lab . '/source/resources/portable/mysql/data' : $lab . '/target-data';
    $actual = realpath((string) $db->query('SELECT @@datadir')->fetchColumn());
    ensure($actual !== false && strcasecmp($actual, realpath($dataDir) ?: '') === 0, 'Unexpected MariaDB data directory.');

    $description = (new UpdateDataCompatibilityService())->describe($db);
    $contract = [
        'format' => $description['format'],
        'metadata_migration' => UpdateDataCompatibilityService::METADATA_MIGRATION,
        'schema_sha256' => $description['schema_sha256'],
        'ledger_sha256' => $description['ledger_sha256'],
    ];
    writeJson($lab . "/{$role}-contract.json", $contract);

    if ($role === 'source') {
        $marker = 'shell-transaction-acceptance-' . bin2hex(random_bytes(8));
        $db->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$marker]);
        $read = $db->prepare('SELECT name FROM categories WHERE id = ?');
        $read->execute([$db->lastInsertId()]);
        ensure($read->fetchColumn() === $marker, 'The disposable database marker could not be read back.');
    }

    writeJson($lab . "/evidence/{$role}-data-profile.json", [
        'scope' => 'Packaged initial schema and packaged PHAR migrations on isolated MariaDB',
        'mariadb_version' => $db->query('SELECT VERSION()')->fetchColumn(),
        'populated_source_row_verified' => $role === 'source',
        'migration_count' => count($description['ledger']),
        'contract' => $contract,
    ]);
}

function checkElectron(string $root, string $expected): string
{
    $original = getenv('ELECTRON_RUN_AS_NODE');
    putenv('ELECTRON_RUN_AS_NODE=1');
    try {
        $process = proc_open(
            [$root . '/POS Desktop.exe', '-p', 'process.versions.electron'],
            [0 => ['file', 'NUL', 'rb'], 1 => ['pipe', 'w'], 2 => STDERR],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        ensure(is_resource($process), 'Could not start packaged Electron in Node mode.');
        $version = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        ensure(proc_close($process) === 0 && $version === $expected, "Expected Electron {$expected}, observed '{$version}'.");
        return $version;
    } finally {
        putenv($original === false ? 'ELECTRON_RUN_AS_NODE' : 'ELECTRON_RUN_AS_NODE=' . $original);
    }
}

function snapshotDatabaseFiles(string $directory): array
{
    $root = ProductInventoryService::resolveAbsolutePath($directory);
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();
        ProductInventoryService::assertNoReparse($path);
        ensure($file->isFile(), 'Expected a regular file in the cold MariaDB data directory.');
        $hash = hash_file('sha256', $path);
        ensure($hash !== false, 'Could not hash cold MariaDB data.');
        $files[substr($path, strlen($root) + 1)] = $hash;
    }
    ksort($files, SORT_STRING);
    ensure(count($files) > 1, 'The protected MariaDB directory must contain actual database files.');
    return $files;
}

function buildSignedDelta(string $lab, string $source, string $target, string $packageDir): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    ensure($key !== false, 'Could not generate the ephemeral RSA key.');
    $details = openssl_pkey_get_details($key);
    ensure($details !== false, 'Could not derive the ephemeral RSA public key.');
    $privateKey = $lab . '/ephemeral-signing-key.pem';
    ensure(!file_exists($privateKey) && openssl_pkey_export_to_file($key, $privateKey), 'Could not write the ephemeral signing key.');
    try {
        $arguments = [
            PHP_BINARY, __DIR__ . '/build-shell-delta.php',
            '--source-root', $source, '--target-root', $target,
            '--source-native-version', '0.0.13', '--target-native-version', '0.0.14',
            '--source-data-contract', $lab . '/source-contract.json',
            '--target-data-contract', $lab . '/target-contract.json',
            '--private-key', $privateKey, '--output-dir', $packageDir,
        ];
        $builder = proc_open($arguments, [0 => ['file', 'NUL', 'rb'], 1 => STDOUT, 2 => STDERR], $pipes, null, null, [
            'bypass_shell' => true,
        ]);
        ensure(is_resource($builder) && proc_close($builder) === 0, 'Signed Format 2 builder failed on packaged bytes.');
    } finally {
        ProductInventoryService::assertNoReparse($privateKey);
        ensure(unlink($privateKey), 'Could not delete the generated test signing key.');
    }
    return $details['key'];
}

function verifyManifestChanges(array $descriptor): array
{
    $changes = array_column($descriptor['files'], null, 'path');
    $required = [
        'POS Desktop.exe',
        'resources/app.asar',
        'resources/app.asar.unpacked/backend/backend.phar',
        'resources/app.asar.unpacked/frontend/dist/index.html',
        'resources/app.asar.unpacked/version.json',
    ];
    $evidence = [];
    foreach ($required as $path) {
        $source = $descriptor['source_inventory']['files'][$path] ?? null;
        $target = $descriptor['target_inventory']['files'][$path] ?? null;
        ensure($source !== null && $target !== null && $source['sha256'] !== $target['sha256']
            && ($changes[$path]['sha256'] ?? null) === $target['sha256'], "Required changed packaged asset missing: {$path}.");
        $evidence[$path] = ['source_sha256' => $source['sha256'], 'target_sha256' => $target['sha256']];
    }
    return $evidence;
}

function verifyTransaction(string $lab): void
{
    $source = ProductInventoryService::resolveAbsolutePath($lab . '/source');
    $target = ProductInventoryService::resolveAbsolutePath($lab . '/target');
    $state = $lab . '/state';
    $packageDir = $lab . '/delta';
    ensure(mkdir($state), 'Could not create the isolated transaction state directory.');

    $published = readJson($lab . '/deployment-inventory.json');
    ensure($published['format'] === 1 && $published['version'] === '0.0.13'
        && deploymentFiles($source . '/resources/app.asar.unpacked') === $published['files'],
        'The Setup payload does not match its published deployment file hashes.');
    ensure(!file_exists($source . '/Uninstall POS Desktop.exe'), 'The source is expected to be the cold NSIS archive tree.');

    $contract = readJson($lab . '/source-contract.json');
    ensure($contract === readJson($lab . '/target-contract.json'), 'Live packaged source and target contracts differ.');

    $sourceElectron = checkElectron($source, '43.2.0');
    $targetElectron = checkElectron($target, '43.8.11');
    $databasePath = $source . '/resources/portable/mysql/data';
    $database = snapshotDatabaseFiles($databasePath);
    $environmentPath = $source . '/.env';
    $environment = "POS_ACCEPTANCE_FIXTURE=present\n";
    ensure(!file_exists($environmentPath) && file_put_contents($environmentPath, $environment) === strlen($environment),
        'Could not create a disposable protected configuration sentinel.');
    $verifyData = static function () use ($databasePath, $database, $environmentPath, $environment): void {
        ensure(snapshotDatabaseFiles($databasePath) === $database, 'MariaDB data bytes changed during the file transaction.');
        ensure(file_get_contents($environmentPath) === $environment, 'Protected configuration bytes changed during the file transaction.');
    };

    $publicKey = buildSignedDelta($lab, $source, $target, $packageDir);
    $manifests = new ShellDeltaManifestService(new ManifestSignatureService(null, [$publicKey]));
    $transaction = new ShellDeltaFileTransactionService($manifests);
    $context = [
        'version' => '0.0.13',
        'native_version' => '0.0.13',
        'channel' => 'stable',
        'data_compatibility' => $contract,
    ];
    $raw = file_get_contents($packageDir . '/shell-manifest.json');
    $signature = file_get_contents($packageDir . '/shell-manifest.sig');
    ensure($raw !== false && $signature !== false, 'Signed builder outputs are missing.');
    $verified = $manifests->verify($raw, $signature, $context);
    ensure($verified['ok'], 'The Format 2 manifest failed production signature and context validation.');
    $manifest = $verified['descriptor'];
    ensure($manifest['format'] === 2 && $manifest['purpose'] === 'regular'
        && $manifest['version'] === '0.0.14' && $manifest['native_version'] === '0.0.14'
        && $manifest['migrations'] === [], 'Unexpected shell delta identity or migration plan.');
    $changes = verifyManifestChanges($manifest);
    $package = $packageDir . '/shell-delta.zip';

    $initial = $manifests->buildInventory($source, '0.0.13');
    $expected = $manifests->buildInventory($target, '0.0.14');
    ensure(ShellDeltaManifestService::canonicalInventoryDigest($initial) === $manifest['source_inventory_sha256']
        && ShellDeltaManifestService::canonicalInventoryDigest($expected) === $manifest['target_inventory_sha256'],
        'Signed inventory digests do not describe the full cold source and candidate.');

    $prepared = $transaction->prepare($source, $state, $raw, $signature, $package, $context);
    ensure($prepared['phase'] === ShellDeltaFileTransactionService::PHASE_PREPARED
        && $manifests->buildInventory($source, '0.0.13') === $initial, 'Prepare changed the cold source product tree.');
    $verifyData();
    echo "Prepared and verified the full source product inventory.\n";

    $id = $prepared['operation_id'];
    $applied = $transaction->apply($source, $state, $id, $context);
    ensure($applied['phase'] === ShellDeltaFileTransactionService::PHASE_FILES_APPLIED
        && $manifests->buildInventory($source, '0.0.14') === $expected, 'Apply did not produce the exact candidate tree.');
    $verifyData();
    $appliedElectron = checkElectron($source, '43.8.11');
    $frontend = file_get_contents($source . '/resources/app.asar.unpacked/frontend/dist/index.html');
    ensure(is_string($frontend) && str_contains($frontend, 'name="pos-shell-ci-candidate" content="0.0.14"'),
        'The applied Vite entry document does not contain the candidate version marker.');
    echo "Applied and verified the full target product inventory and runnable Electron version.\n";

    $rolledBack = $transaction->rollback($source, $state, $id, $context);
    ensure($rolledBack['phase'] === ShellDeltaFileTransactionService::PHASE_ROLLED_BACK
        && $manifests->buildInventory($source, '0.0.13') === $initial, 'Rollback did not restore the full Setup product inventory.');
    $verifyData();
    $restoredElectron = checkElectron($source, '43.2.0');
    echo "Rolled back and verified the full source product inventory, Electron version and cold MariaDB data.\n";

    writeJson($lab . '/evidence/transaction.json', [
        'scope' => 'Cold Setup package file transaction; no NSIS installation, client bootstrap, production signing, or updater integration',
        'application_versions' => ['source' => '0.0.13', 'target' => '0.0.14'],
        'electron_node_mode' => ['source' => $sourceElectron, 'candidate' => $targetElectron,
            'applied' => $appliedElectron, 'restored' => $restoredElectron],
        'published_deployment_files_verified' => count($published['files']),
        'source_inventory_sha256' => $manifest['source_inventory_sha256'],
        'target_inventory_sha256' => $manifest['target_inventory_sha256'],
        'source_inventory_files' => count($initial['files']),
        'target_inventory_files' => count($expected['files']),
        'format2_manifest_sha256' => hash('sha256', $raw),
        'format2_package_sha256' => hash_file('sha256', $package),
        'format2_package_size' => filesize($package),
        'required_changed_files' => $changes,
        'changed_file_count' => count($manifest['files']),
        'deleted_file_count' => count($manifest['deleted_files']),
        'phases' => [$prepared['phase'], $applied['phase'], $rolledBack['phase']],
        'unchanged_protected_mariadb_file_count' => count($database),
        'unchanged_protected_mariadb_snapshot_sha256' => hash('sha256', json_encode($database, JSON_THROW_ON_ERROR)),
        'unchanged_protected_environment_file' => true,
    ]);
}

try {
    $runnerTemp = getenv('RUNNER_TEMP');
    ensure(is_string($runnerTemp) && $runnerTemp !== '', 'RUNNER_TEMP is required.');
    $lab = ProductInventoryService::resolveAbsolutePath($runnerTemp . '/setup13-shell');
    if ($argc === 3 && $argv[1] === 'profile') {
        measureDatabase($lab, $argv[2]);
    } elseif ($argc === 2 && $argv[1] === 'transaction') {
        verifyTransaction($lab);
    } else {
        throw new RuntimeException('Expected profile source, profile target, or transaction.');
    }
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}
