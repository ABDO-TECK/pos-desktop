<?php
declare(strict_types=1);

/**
 * scripts/verify-catalog-trigger-recovery.php
 *
 * Comprehensive verification of catalog trigger root-cause repair and definer recovery:
 * 1. Fast-path hash skip reconciliation failure propagation and flag invalidation.
 * 2. Real subprocess CLI migration exit code sequencing (errors checked before skipped-success).
 * 3. Exact CURRENT_USER() definer matching vs stale/bad definers (pos_app, ghost pos_migration, root).
 * 4. Fail-closed behavior on unresolvable current user identity.
 * 5. Ordinary MySQL error 1142 classifier discrimination (no misclassification of DML denials).
 * 6. Elimination of unsafe DROP-and-bypass and partial manual logging workarounds.
 * 7. Bounded single retry under privileged context and honest 503 on unprivileged/failed repair.
 * 8. Healthy triggers remain untouched (idempotency).
 * 9. Trigger contracts preserved (AFTER INSERT/UPDATE/DELETE with OLD.id tombstone).
 * 10. SQLite graceful handling and negative fixture guard tests (rejects 3306/3307/remote hosts/unsafe paths).
 * 11. Isolated live MariaDB end-to-end integration test runner (--mariadb).
 */

// Establish global test storage isolation for the entire test process
$globalTestStorage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_test_cat_storage_' . bin2hex(random_bytes(8));
@mkdir($globalTestStorage, 0777, true);
putenv('APP_STORAGE_DIR=' . $globalTestStorage);
register_shutdown_function(function() use ($globalTestStorage) {
    if (is_dir($globalTestStorage)) {
        @array_map('unlink', glob($globalTestStorage . '/*.*') ?: []);
        @rmdir($globalTestStorage);
    }
});

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\MigrationService;
use App\Services\ProductService;
use App\Repositories\ProductRepository;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Config\Database;

$testCount = 0;
$passCount = 0;

function verify(bool $condition, string $description): void {
    global $testCount, $passCount;
    $testCount++;
    if (!$condition) {
        echo "FAIL [Test {$testCount}]: {$description}\n";
        exit(1);
    }
    $passCount++;
    echo "PASS [Test {$testCount}]: {$description}\n";
}

echo "====================================================================\n";
echo "VERIFYING CATALOG TRIGGER ROOT-CAUSE RECOVERY & DEFINER ALIGNMENT\n";
echo "====================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// Section 1: Fast-Path Hash Skip Reconciliation Failure Propagation
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Section 1: Fast-Path Hash Skip Reconciliation & Flag Invalidation ---\n";

$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_mig_' . uniqid('', true);
mkdir($tempDir, 0777, true);
$migrationsDir = $tempDir . DIRECTORY_SEPARATOR . 'migrations';
mkdir($migrationsDir, 0777, true);
$storageDir = $tempDir . DIRECTORY_SEPARATOR . 'storage';
mkdir($storageDir, 0777, true);
$flagFile = $storageDir . DIRECTORY_SEPARATOR . 'migrations_hash.flag';

// Create a dummy migration file
file_put_contents($migrationsDir . DIRECTORY_SEPARATOR . '001_initial.sql', 'CREATE TABLE dummy_test (id INT);');

// Subclass MigrationService to control reconcileLegacyArtifacts during test
class TestableMigrationService extends MigrationService {
    public array $injectedReconciliationErrors = [];
    public bool $reconciliationWasCalled = false;

    public function __construct(PDO $db, string $migrationsDir, string $storageDir) {
        putenv('APP_STORAGE_DIR=' . $storageDir);
        parent::__construct($db);
        $ref = new ReflectionProperty(MigrationService::class, 'migrationsPath');
        $ref->setAccessible(true);
        $ref->setValue($this, rtrim($migrationsDir, '/\\') . DIRECTORY_SEPARATOR);
    }

    public function reconcileLegacyArtifacts(): array {
        $this->reconciliationWasCalled = true;
        return $this->injectedReconciliationErrors;
    }
}

// Mock PDO for migration runs
$mockPdoForMig = new class extends PDO {
    public function __construct() {
        parent::__construct('sqlite::memory:');
        $this->exec('CREATE TABLE schema_versions (version TEXT PRIMARY KEY, executed_at DATETIME)');
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (str_contains($query, 'GET_LOCK') || str_contains($query, 'RELEASE_LOCK')) {
            return parent::query('SELECT 1');
        }
        return parent::query($query);
    }
    public function exec(string $statement): int|false {
        if (str_contains($statement, 'CREATE TABLE IF NOT EXISTS schema_versions')) {
            return parent::exec('CREATE TABLE IF NOT EXISTS schema_versions (version TEXT PRIMARY KEY, executed_at DATETIME)');
        }
        return parent::exec($statement);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (str_contains($query, 'GET_LOCK')) {
            return parent::prepare('SELECT 1 AS locked, :lock_name AS lk, :timeout_seconds AS ts');
        }
        if (str_contains($query, 'RELEASE_LOCK')) {
            return parent::prepare('SELECT 1 AS released, :lock_name AS lk');
        }
        return parent::prepare($query);
    }
};

$migService = new TestableMigrationService($mockPdoForMig, $migrationsDir, $storageDir);

// Initial run: executes migration, creates flag
$result1 = $migService->runMigrations(null, false);
verify($result1['executed'] === 1 && empty($result1['errors']) && is_file($flagFile),
    "Initial migration executes and generates hash flag file");

// Second run with unchanged files BUT reconciliation produces an error
$migService->injectedReconciliationErrors = ['Trigger definer mismatch detected on catalog triggers'];
$result2 = $migService->runMigrations(null, false);

verify($result2['skipped'] === true,
    "Fast-skip branch reached when files are unchanged");
verify(!empty($result2['errors']) && $result2['errors'][0] === 'Trigger definer mismatch detected on catalog triggers',
    "Reconciliation failure in fast-skip is propagated into result errors instead of being swallowed");
verify(!file_exists($flagFile),
    "Hash flag file is invalidated (unlinked) when reconciliation fails during fast-skip");

// Clean up Section 1 files and restore global test storage
@unlink($migrationsDir . DIRECTORY_SEPARATOR . '001_initial.sql');
@rmdir($migrationsDir);
@unlink($flagFile);
@rmdir($storageDir);
@rmdir($tempDir);
putenv('APP_STORAGE_DIR=' . $globalTestStorage);

// ─────────────────────────────────────────────────────────────────────────────
// Section 2: Real Subprocess CLI Migration Exit Code Sequencing
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Section 2: CLI migrate.php Exit Code Sequencing ---\n";

// 2a: Source analysis check
$cliMigrateCode = file_get_contents(__DIR__ . '/../backend/cli/migrate.php');
$errorPos = strpos($cliMigrateCode, "!empty(\$result['errors'])");
$skipPos = strpos($cliMigrateCode, "\$result['skipped']");

verify($errorPos !== false && $skipPos !== false && $errorPos < $skipPos,
    "cli/migrate.php source checks !empty(\$result['errors']) and exits 1 BEFORE checking \$result['skipped']");

// 2b: Temp mirrored CLI fixture executing real child PHP subprocess using file descriptors (avoids pipe EPERM)
$tempCliDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_cli_fixture_' . bin2hex(random_bytes(8));
mkdir($tempCliDir . '/cli', 0777, true);
mkdir($tempCliDir . '/vendor', 0777, true);
mkdir($tempCliDir . '/Config', 0777, true);
mkdir($tempCliDir . '/Services', 0777, true);
mkdir($tempCliDir . '/Helpers', 0777, true);

// Verbatim copy of original backend/cli/migrate.php
copy(__DIR__ . '/../backend/cli/migrate.php', $tempCliDir . '/cli/migrate.php');

// Isolated stubs (no customer config load)
file_put_contents($tempCliDir . '/Helpers/ErrorHandler.php', "<?php\nnamespace App\\Helpers;\nclass ErrorHandler { public static function register(): void {} }\n");
file_put_contents($tempCliDir . '/vendor/autoload.php', "<?php\nrequire_once __DIR__ . '/../Helpers/ErrorHandler.php';\n");
file_put_contents($tempCliDir . '/Config/config.php', "<?php\n");

$stubServiceCode = <<<'CODE'
<?php
namespace App\Services;

class MigrationService {
    public function runMigrations(?array $declared = null, bool $force = false): array {
        $mode = getenv('TEST_CLI_MODE') ?: 'error_with_skip';
        if ($mode === 'error_with_skip') {
            return [
                'executed' => 0,
                'errors' => ['Reconciliation error during fast-skip'],
                'skipped' => true,
            ];
        }
        return [
            'executed' => 0,
            'errors' => [],
            'skipped' => true,
        ];
    }
}
CODE;
file_put_contents($tempCliDir . '/Services/MigrationService.php', $stubServiceCode);

// Execute child process with stdio redirected to disk files (no pipes, avoiding Windows sandbox EPERM)
$runCliSubprocess = function(string $mode) use ($tempCliDir): array {
    $stdoutFile = $tempCliDir . "/out_{$mode}.log";
    $stderrFile = $tempCliDir . "/err_{$mode}.log";
    $descriptors = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $stdoutFile, 'w'],
        2 => ['file', $stderrFile, 'w'],
    ];

    $env = array_merge($_ENV, ['TEST_CLI_MODE' => $mode]);
    $proc = proc_open([PHP_BINARY, $tempCliDir . '/cli/migrate.php'], $descriptors, $pipes, null, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException("Failed to spawn CLI child process");
    }
    $exitCode = proc_close($proc);
    $stdout = file_exists($stdoutFile) ? file_get_contents($stdoutFile) : '';
    $stderr = file_exists($stderrFile) ? file_get_contents($stderrFile) : '';
    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
};

$resFailure = $runCliSubprocess('error_with_skip');
verify($resFailure['exit_code'] === 1,
    "Mirrored cli/migrate.php child process exits with code 1 when skipped=true has errors");
verify(str_contains($resFailure['stdout'], 'Errors encountered') && !str_contains($resFailure['stdout'], 'No changes detected in migration files'),
    "Mirrored cli/migrate.php displays error list and suppresses skipped-success message");

$resHealthy = $runCliSubprocess('healthy_skip');
verify($resHealthy['exit_code'] === 0,
    "Mirrored cli/migrate.php child process exits with code 0 on healthy skipped migration");
verify(str_contains($resHealthy['stdout'], 'No changes detected in migration files'),
    "Mirrored cli/migrate.php displays skipped message on healthy fast-skip");

// Clean up temp mirrored CLI fixture
@unlink($tempCliDir . '/cli/migrate.php');
@rmdir($tempCliDir . '/cli');
@unlink($tempCliDir . '/vendor/autoload.php');
@rmdir($tempCliDir . '/vendor');
@unlink($tempCliDir . '/Helpers/ErrorHandler.php');
@rmdir($tempCliDir . '/Helpers');
@unlink($tempCliDir . '/Config/config.php');
@rmdir($tempCliDir . '/Config');
@unlink($tempCliDir . '/Services/MigrationService.php');
@rmdir($tempCliDir . '/Services');
@unlink($tempCliDir . '/out_error_with_skip.log');
@unlink($tempCliDir . '/err_error_with_skip.log');
@unlink($tempCliDir . '/out_healthy_skip.log');
@unlink($tempCliDir . '/err_healthy_skip.log');
@rmdir($tempCliDir);

// ─────────────────────────────────────────────────────────────────────────────
// Section 3: Exact CURRENT_USER() vs Stale / Bad Definers
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Section 3: Exact CURRENT_USER() Definer Matching & Fail-Closed ---\n";

class DefinerMockPdo extends PDO {
    public ?string $currentUser = 'pos_migration@127.0.0.1';
    public array $triggerDefiners = [];
    public array $droppedTriggers = [];
    public array $createdTriggers = [];
    public bool $schemaVersionExists = true;

    public function __construct() {
        parent::__construct('sqlite::memory:');
        $this->exec('CREATE TABLE schema_versions (version TEXT PRIMARY KEY, executed_at DATETIME)');
        $this->exec("INSERT INTO schema_versions VALUES ('043_add_product_catalog_changes.sql', datetime('now'))");
    }

    public function getAttribute($attr): mixed {
        if ($attr === PDO::ATTR_DRIVER_NAME) {
            return 'mysql';
        }
        return parent::getAttribute($attr);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (str_contains($query, 'SELECT CURRENT_USER()')) {
            if ($this->currentUser === null) {
                return parent::query('SELECT NULL');
            }
            return parent::query("SELECT '{$this->currentUser}'");
        }

        if (str_contains($query, 'schema_versions')) {
            if ($this->schemaVersionExists) {
                return parent::query("SELECT '043_add_product_catalog_changes.sql' AS version");
            }
            return parent::query("SELECT '' AS version WHERE 1=0");
        }

        if (str_contains($query, 'information_schema.TABLES')) {
            return parent::query("SELECT 'products' AS TABLE_NAME");
        }

        if (str_contains($query, 'information_schema.TRIGGERS')) {
            $unions = [];
            foreach ($this->triggerDefiners as $name => $definer) {
                $unions[] = "SELECT '{$name}' AS TRIGGER_NAME, '{$definer}' AS DEFINER";
            }
            if (empty($unions)) {
                return parent::query("SELECT '' AS TRIGGER_NAME, '' AS DEFINER WHERE 1=0");
            }
            return parent::query(implode(' UNION ALL ', $unions));
        }

        return parent::query($query);
    }

    public function exec(string $statement): int|false {
        if (str_contains($statement, 'schema_versions')) {
            return parent::exec($statement);
        }
        if (preg_match('/CREATE TRIGGER\s+`?([a-zA-Z0-9_]+)`?/i', $statement, $m)) {
            $this->createdTriggers[] = $m[1];
            $this->triggerDefiners[$m[1]] = $this->currentUser;
            return 1;
        }
        if (preg_match('/DROP TRIGGER IF EXISTS\s+`?([a-zA-Z0-9_]+)`?/i', $statement, $m)) {
            $this->droppedTriggers[] = $m[1];
            unset($this->triggerDefiners[$m[1]]);
            return 1;
        }
        if (str_contains($statement, 'product_catalog_changes')) {
            return parent::exec('CREATE TABLE IF NOT EXISTS product_catalog_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER, product_id INTEGER, changed_at DATETIME)');
        }
        return parent::exec($statement);
    }
}

// 3a: Exact CURRENT_USER() match -> NO rebuild
$mock3a = new DefinerMockPdo();
$mock3a->triggerDefiners = [
    'trg_products_catalog_insert' => 'pos_migration@127.0.0.1',
    'trg_products_catalog_update' => 'pos_migration@127.0.0.1',
    'trg_products_catalog_delete' => 'pos_migration@127.0.0.1',
];
$svc3a = new MigrationService($mock3a);
$errors3a = $svc3a->reconcileCatalogTriggers(false);

verify(empty($errors3a), "reconcileCatalogTriggers succeeds with healthy triggers");
verify(empty($mock3a->createdTriggers), "Exact CURRENT_USER() definers are NOT rebuilt (idempotent)");

// 3b: Bad definers: pos_app (runtime account), ghost pos_migration@wronghost, root@localhost
$mock3b = new DefinerMockPdo();
$mock3b->triggerDefiners = [
    'trg_products_catalog_insert' => 'pos_app@127.0.0.1',        // runtime user without TRIGGER priv
    'trg_products_catalog_update' => 'pos_migration@wronghost',  // ghost user
    'trg_products_catalog_delete' => 'root@localhost',           // defunct root
];
$svc3b = new MigrationService($mock3b);
$errors3b = $svc3b->reconcileCatalogTriggers(false);

verify(empty($errors3b), "reconcileCatalogTriggers successfully rebuilds bad definers");
verify(in_array('trg_products_catalog_insert', $mock3b->createdTriggers, true),
    "pos_app@127.0.0.1 definer was rejected and rebuilt to CURRENT_USER");
verify(in_array('trg_products_catalog_update', $mock3b->createdTriggers, true),
    "pos_migration@wronghost ghost definer was rejected and rebuilt to CURRENT_USER");
verify(in_array('trg_products_catalog_delete', $mock3b->createdTriggers, true),
    "root@localhost definer was rejected and rebuilt to CURRENT_USER");
verify($mock3b->triggerDefiners['trg_products_catalog_insert'] === 'pos_migration@127.0.0.1',
    "Rebuilt insert trigger definer readback matches pos_migration@127.0.0.1");

// 3c: Fail closed when CURRENT_USER() is unresolvable
$mock3c = new DefinerMockPdo();
$mock3c->currentUser = null;
$svc3c = new MigrationService($mock3c);
$errors3c = $svc3c->reconcileCatalogTriggers(false);

verify(!empty($errors3c), "Fails closed when CURRENT_USER() is unresolvable");
verify(str_contains($errors3c[0], 'unable to determine current privileged database user'),
    "Error explicitly indicates inability to determine current privileged database user");

// ─────────────────────────────────────────────────────────────────────────────
// Section 4: Ordinary MySQL Error 1142 Discrimination
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Section 4: MySQL Error 1142 Discrimination ---\n";

$dummyRepo = new class extends ProductRepository {
    public function __construct() {}
};
$dummyPdo = new PDO('sqlite::memory:');
$dummyPriceHistory = new PriceHistory($dummyPdo);
$productService = new ProductService($dummyRepo, $dummyPriceHistory, $dummyPdo);

$refMethod = new ReflectionMethod(ProductService::class, 'isTriggerDefinerOrPrivilegeError');
$refMethod->setAccessible(true);

// 4a: Error 1449 -> true
$e1449 = new PDOException("The user specified as a definer ('root'@'localhost') does not exist");
$e1449->errorInfo = ['HY000', 1449, "The user specified as a definer ('root'@'localhost') does not exist"];
verify($refMethod->invoke($productService, $e1449) === true,
    "Error 1449 definer does not exist classified as trigger definer error");

// 4b: Error 1142 with TRIGGER command denied -> true
$e1142Trigger = new PDOException("TRIGGER command denied to user 'pos_app'@'127.0.0.1' for table 'products'");
$e1142Trigger->errorInfo = ['42000', 1142, "TRIGGER command denied to user 'pos_app'@'127.0.0.1' for table 'products'"];
verify($refMethod->invoke($productService, $e1142Trigger) === true,
    "Error 1142 with 'TRIGGER command denied' classified as trigger definer error");

// 4c: Error 1142 with does not have the TRIGGER privilege -> true
$e1142Priv = new PDOException("User does not have the TRIGGER privilege on 'pos_db'.'products'");
$e1142Priv->errorInfo = ['42000', 1142, "User does not have the TRIGGER privilege on 'pos_db'.'products'"];
verify($refMethod->invoke($productService, $e1142Priv) === true,
    "Error 1142 with 'does not have the TRIGGER privilege' classified as trigger definer error");

// 4d: Ordinary DML 1142 on SELECT -> false
$e1142Select = new PDOException("SELECT command denied to user 'pos_app'@'127.0.0.1' for table 'products'");
$e1142Select->errorInfo = ['42000', 1142, "SELECT command denied to user 'pos_app'@'127.0.0.1' for table 'products'"];
verify($refMethod->invoke($productService, $e1142Select) === false,
    "Error 1142 on SELECT is NOT misclassified as a trigger definer error");

// 4e: Ordinary DML 1142 on INSERT -> false
$e1142Insert = new PDOException("INSERT command denied to user 'pos_app'@'127.0.0.1' for table 'products'");
$e1142Insert->errorInfo = ['42000', 1142, "INSERT command denied to user 'pos_app'@'127.0.0.1' for table 'products'"];
verify($refMethod->invoke($productService, $e1142Insert) === false,
    "Error 1142 on INSERT is NOT misclassified as a trigger definer error");

// 4f: Ordinary DML 1142 on UPDATE -> false
$e1142Update = new PDOException("UPDATE command denied to user 'pos_app'@'127.0.0.1' for table 'products'");
$e1142Update->errorInfo = ['42000', 1142, "UPDATE command denied to user 'pos_app'@'127.0.0.1' for table 'products'"];
verify($refMethod->invoke($productService, $e1142Update) === false,
    "Error 1142 on UPDATE is NOT misclassified as a trigger definer error");

// 4g: Ordinary DML 1142 on DELETE -> false
$e1142Delete = new PDOException("DELETE command denied to user 'pos_app'@'127.0.0.1' for table 'products'");
$e1142Delete->errorInfo = ['42000', 1142, "DELETE command denied to user 'pos_app'@'127.0.0.1' for table 'products'"];
verify($refMethod->invoke($productService, $e1142Delete) === false,
    "Error 1142 on DELETE is NOT misclassified as a trigger definer error");

// 4h: Other error codes -> false
$e1062 = new PDOException("Duplicate entry '123' for key 'barcode'");
$e1062->errorInfo = ['23000', 1062, "Duplicate entry '123' for key 'barcode'"];
verify($refMethod->invoke($productService, $e1062) === false,
    "Error 1062 duplicate key is not classified as trigger definer error");

$e1452 = new PDOException("Cannot add or update a child row: a foreign key constraint fails");
$e1452->errorInfo = ['23000', 1452, "Cannot add or update a child row: a foreign key constraint fails"];
verify($refMethod->invoke($productService, $e1452) === false,
    "Error 1452 foreign key is not classified as trigger definer error");

// ─────────────────────────────────────────────────────────────────────────────
// Section 5: Elimination of Unsafe Bypass Methods
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Section 5: Elimination of Unsafe Bypass Methods ---\n";

verify(!method_exists(ProductService::class, 'emergencyBypassCatalogTriggers'),
    "emergencyBypassCatalogTriggers() method completely removed from ProductService");
verify(!method_exists(ProductService::class, 'recordCatalogChangeDirectly'),
    "recordCatalogChangeDirectly() method completely removed from ProductService");

// ─────────────────────────────────────────────────────────────────────────────
// Section 6: Actionable 503 catalog_trigger_repair_required
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Section 6: Actionable 503 catalog_trigger_repair_required ---\n";

$failingRepo = new class extends ProductRepository {
    public function __construct() {}
    public function assertBarcodesAvailable(?int $id, string $main, array $extras): void {}
    public function findByBarcode(string $barcode): ?array { return null; }
    public function syncAdditionalBarcodes(int $productId, array $barcodes): void {}
    public function updateMainBarcode(int $productId, string $barcode): void {}
    public function findById(int $id): ?array {
        return ['id' => 1, 'name' => 'Test', 'barcode' => '9999', 'branch_id' => 1];
    }
    public function create(array $data): int {
        $e = new PDOException("The user specified as a definer ('root'@'localhost') does not exist");
        $e->errorInfo = ['HY000', 1449, "The user specified as a definer ('root'@'localhost') does not exist"];
        throw $e;
    }
    public function update(int $id, array $data): void {
        $e = new PDOException("TRIGGER command denied to user 'pos_app'@'127.0.0.1' for table 'products'");
        $e->errorInfo = ['42000', 1142, "TRIGGER command denied to user 'pos_app'@'127.0.0.1' for table 'products'"];
        throw $e;
    }
    public function delete(int $id): void {
        $e = new PDOException("The user specified as a definer ('root'@'localhost') does not exist");
        $e->errorInfo = ['HY000', 1449, "The user specified as a definer ('root'@'localhost') does not exist"];
        throw $e;
    }
    public function referenceCounts(int $id): array {
        return ['invoice_items' => 0, 'purchases' => 0];
    }
};

$sqliteFor503 = new PDO('sqlite::memory:');
$sqliteFor503->exec('CREATE TABLE price_history (id INTEGER PRIMARY KEY, product_id INTEGER, old_price REAL, new_price REAL, old_cost REAL, new_cost REAL, changed_by INTEGER, created_at DATETIME)');
$sqliteFor503->exec('CREATE TABLE product_catalog_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER, product_id INTEGER, changed_at DATETIME)');

$ps503 = new ProductService($failingRepo, new PriceHistory($sqliteFor503), $sqliteFor503);

$resCreate = $ps503->createProduct(['name' => 'Test', 'barcode' => '9999', 'price' => 10, 'branch_id' => 1]);
verify($resCreate['ok'] === false && $resCreate['code'] === 503 && $resCreate['reason_code'] === 'catalog_trigger_repair_required',
    "createProduct returns honest 503 with reason_code catalog_trigger_repair_required when repair unavailable");

$resUpdate = $ps503->updateProduct(1, ['name' => 'Test', 'barcode' => '9999', 'price' => 12]);
verify($resUpdate['ok'] === false && $resUpdate['code'] === 503 && $resUpdate['reason_code'] === 'catalog_trigger_repair_required',
    "updateProduct returns honest 503 with reason_code catalog_trigger_repair_required when repair unavailable");

$resDelete = $ps503->deleteProduct(1);
verify($resDelete['ok'] === false && $resDelete['code'] === 503 && $resDelete['reason_code'] === 'catalog_trigger_repair_required',
    "deleteProduct returns honest 503 with reason_code catalog_trigger_repair_required when repair unavailable");

// ─────────────────────────────────────────────────────────────────────────────
// Section 7: Bounded Single Retry in Privileged Context (SIMULATED SQLite Mock)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Section 7: Bounded Single Retry in Privileged Context (SIMULATED SQLite Mock) ---\n";

$retryCount = 0;
$retryRepo = new class($retryCount) extends ProductRepository {
    public int $attempts;
    public function __construct(int &$attempts) {
        $this->attempts = &$attempts;
    }
    public function assertBarcodesAvailable(?int $id, string $main, array $extras): void {}
    public function findByBarcode(string $barcode): ?array { return null; }
    public function syncAdditionalBarcodes(int $productId, array $barcodes): void {}
    public function updateMainBarcode(int $productId, string $barcode): void {}
    public function findById(int $id): ?array { return ['id' => $id]; }
    public function create(array $data): int {
        $this->attempts++;
        if ($this->attempts === 1) {
            $e = new PDOException("The user specified as a definer ('root'@'localhost') does not exist");
            $e->errorInfo = ['HY000', 1449, "The user specified as a definer ('root'@'localhost') does not exist"];
            throw $e;
        }
        return 777;
    }
};

$runtimeMysqlMock = new class extends PDO {
    public function __construct() {
        parent::__construct('sqlite::memory:');
        $this->exec('CREATE TABLE price_history (id INTEGER PRIMARY KEY, product_id INTEGER, old_price REAL, new_price REAL, old_cost REAL, new_cost REAL, changed_by INTEGER, created_at DATETIME)');
        $this->exec('CREATE TABLE product_catalog_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER, product_id INTEGER, changed_at DATETIME)');
    }
    public function getAttribute($attr): mixed {
        if ($attr === PDO::ATTR_DRIVER_NAME) return 'mysql';
        return parent::getAttribute($attr);
    }
};

$privMysqlMock = new class extends PDO {
    public function __construct() {
        parent::__construct('sqlite::memory:');
        $this->exec('CREATE TABLE schema_versions (version TEXT PRIMARY KEY, executed_at DATETIME)');
        $this->exec("INSERT INTO schema_versions VALUES ('043_add_product_catalog_changes.sql', datetime('now'))");
    }
    public function getAttribute($attr): mixed {
        if ($attr === PDO::ATTR_DRIVER_NAME) return 'mysql';
        return parent::getAttribute($attr);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (str_contains($query, 'SELECT CURRENT_USER()')) {
            return parent::query("SELECT 'pos_migration@127.0.0.1'");
        }
        if (str_contains($query, 'information_schema.TABLES')) {
            return parent::query("SELECT 'products' AS TABLE_NAME");
        }
        if (str_contains($query, 'information_schema.TRIGGERS')) {
            return parent::query("
                SELECT 'trg_products_catalog_insert' AS TRIGGER_NAME, 'pos_migration@127.0.0.1' AS DEFINER
                UNION ALL
                SELECT 'trg_products_catalog_update' AS TRIGGER_NAME, 'pos_migration@127.0.0.1' AS DEFINER
                UNION ALL
                SELECT 'trg_products_catalog_delete' AS TRIGGER_NAME, 'pos_migration@127.0.0.1' AS DEFINER
            ");
        }
        return parent::query($query);
    }
    public function exec(string $statement): int|false {
        if (str_contains($statement, 'CREATE TRIGGER') || str_contains($statement, 'DROP TRIGGER')) {
            return 1;
        }
        if (str_contains($statement, 'product_catalog_changes')) {
            return parent::exec('CREATE TABLE IF NOT EXISTS product_catalog_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER, product_id INTEGER, changed_at DATETIME)');
        }
        return parent::exec($statement);
    }
};

putenv('DB_MIGRATION_USER=pos_migration');
$refProp = new ReflectionProperty(Database::class, 'migrationInstance');
$refProp->setAccessible(true);
$refProp->setValue(null, $privMysqlMock);

$psPriv = new ProductService($retryRepo, new PriceHistory($runtimeMysqlMock), $runtimeMysqlMock);

$retryCount = 0;
$resRetry = $psPriv->createProduct(['name' => 'RetryMe', 'barcode' => '8888', 'price' => 20, 'branch_id' => 1]);

verify($resRetry['ok'] === true && ($resRetry['product']['id'] ?? 0) === 777,
    "createProduct succeeded on retry after simulated privileged repair");
verify($retryCount === 2,
    "createProduct executed exactly 2 attempts (initial + 1 retry)");

// Verify bounded retry stops at attempt 2 if retry still fails
$failTwiceCount = 0;
$failTwiceRepo = new class($failTwiceCount) extends ProductRepository {
    public int $attempts;
    public function __construct(int &$attempts) { $this->attempts = &$attempts; }
    public function assertBarcodesAvailable(?int $id, string $main, array $extras): void {}
    public function findByBarcode(string $barcode): ?array { return null; }
    public function syncAdditionalBarcodes(int $productId, array $barcodes): void {}
    public function updateMainBarcode(int $productId, string $barcode): void {}
    public function findById(int $id): ?array { return ['id' => $id]; }
    public function create(array $data): int {
        $this->attempts++;
        $e = new PDOException("The user specified as a definer ('root'@'localhost') does not exist");
        $e->errorInfo = ['HY000', 1449, "The user specified as a definer ('root'@'localhost') does not exist"];
        throw $e;
    }
};

$psFailTwice = new ProductService($failTwiceRepo, new PriceHistory($runtimeMysqlMock), $runtimeMysqlMock);
$failTwiceResult = $psFailTwice->createProduct(['name' => 'FailForever', 'barcode' => '7777', 'price' => 20, 'branch_id' => 1]);

verify($failTwiceResult['ok'] === false && $failTwiceResult['code'] === 503,
    "createProduct bounded retry safely returns 503 when retry also fails");
verify($failTwiceCount === 2,
    "createProduct capped at exactly 2 attempts, no infinite loop");

putenv('DB_MIGRATION_USER');
Database::resetMigrationInstance();

// ─────────────────────────────────────────────────────────────────────────────
// Section 8: Trigger Contracts Preserved (OLD.id Tombstone & NEW.id)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Section 8: Trigger Contracts Preserved (OLD.id Tombstone & NEW.id) ---\n";

$migCode = file_get_contents(__DIR__ . '/../backend/Services/MigrationService.php');
verify(str_contains($migCode, 'AFTER INSERT ON products') &&
       str_contains($migCode, 'VALUES (NEW.branch_id, NEW.id)'),
    "AFTER INSERT trigger contract records NEW.branch_id, NEW.id");

verify(str_contains($migCode, 'AFTER UPDATE ON products') &&
       str_contains($migCode, 'VALUES (NEW.branch_id, NEW.id)'),
    "AFTER UPDATE trigger contract records NEW.branch_id, NEW.id");

verify(str_contains($migCode, 'AFTER DELETE ON products') &&
       str_contains($migCode, 'VALUES (OLD.branch_id, OLD.id)'),
    "AFTER DELETE trigger contract records OLD.branch_id, OLD.id historical tombstone");

// ─────────────────────────────────────────────────────────────────────────────
// Section 9: SQLite Driver Graceful Handling & Negative Identity Guard Tests
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Section 9: SQLite Limits & Negative Identity Guard Tests ---\n";

$pureSqlite = new PDO('sqlite::memory:');
$pureSqliteMigService = new MigrationService($pureSqlite);
$sqliteErrors = $pureSqliteMigService->reconcileCatalogTriggers(false);

verify(empty($sqliteErrors), "reconcileCatalogTriggers gracefully skips non-MySQL driver without errors");

/**
 * Fixture parameter validation enforcing strict lab isolation:
 * - Prohibits default ports 3306 and 3307
 * - Prohibits non-loopback host
 * - Requires exact dbname 'test_pos_recovery'
 * - Requires datadir to be a strict child of system temp with directory separator boundary (rejects TEMProot and siblings)
 * - Requires datadir basename to match unique pattern pos_mariadb_lab_<GUID> (or space)
 * - Requires actual fixture_manifest.json inside lab datadir, non-symlink
 * - Parses and matches marker, PID, port, datadir, and db against explicit args BEFORE PDO connect
 * - Requires positive PID matching owned process
 */
function validateFixtureParams(array $params): void {
    $requiredKeys = [
        'host', 'port', 'db', 'expected_datadir', 'expected_pid',
        'fixture_marker', 'mig_user', 'mig_pass', 'app_user',
        'app_pass', 'root_user', 'root_pass'
    ];
    foreach ($requiredKeys as $k) {
        if (!array_key_exists($k, $params) || trim((string)$params[$k]) === '') {
            if ($k === 'mig_pass' || $k === 'app_pass' || $k === 'root_pass') {
                continue;
            }
            throw new InvalidArgumentException("Missing required fixture argument: {$k}");
        }
    }

    if ($params['host'] !== '127.0.0.1') {
        throw new InvalidArgumentException("Refusing non-loopback host '{$params['host']}'. Only 127.0.0.1 is permitted.");
    }

    $port = (int)$params['port'];
    if ($port < 1024 || $port > 65535 || $port === 3306 || $port === 3307) {
        throw new InvalidArgumentException("Refusing prohibited/reserved port {$port}. Must be dynamic loopback port != 3306 and != 3307.");
    }

    if ($params['db'] !== 'test_pos_recovery') {
        throw new InvalidArgumentException("Refusing unexpected database '{$params['db']}'. Only 'test_pos_recovery' is permitted.");
    }

    $pid = (int)$params['expected_pid'];
    if ($pid <= 0) {
        throw new InvalidArgumentException("Invalid expected PID {$pid}.");
    }

    $datadir = $params['expected_datadir'];
    $realDatadir = realpath($datadir);
    $tempBase = rtrim(sys_get_temp_dir(), '/\\');
    $realTemp = realpath($tempBase);
    if ($realDatadir === false || $realTemp === false) {
        throw new InvalidArgumentException("Refusing datadir '{$datadir}' which does not exist or cannot be resolved.");
    }

    $tempSeparator = $realTemp . DIRECTORY_SEPARATOR;
    if ($realDatadir === $realTemp || !str_starts_with(strtolower($realDatadir), strtolower($tempSeparator))) {
        throw new InvalidArgumentException("Refusing datadir '{$datadir}' which is not a child inside system temp directory '{$realTemp}'.");
    }

    $leaf = basename($realDatadir);
    if (!preg_match('/^pos_mariadb_lab[_ ][a-f0-9]{32}$/i', $leaf)) {
        throw new InvalidArgumentException("Refusing datadir '{$leaf}' which does not match unique lab directory pattern pos_mariadb_lab_<GUID>.");
    }

    // Verify fixture_manifest.json inside lab datadir before any database connection
    $manifestPath = $realDatadir . DIRECTORY_SEPARATOR . 'fixture_manifest.json';
    if (!file_exists($manifestPath)) {
        throw new InvalidArgumentException("Refusing datadir missing fixture manifest at '{$manifestPath}'.");
    }
    if (is_link($manifestPath)) {
        throw new InvalidArgumentException("Refusing fixture manifest because it is a symbolic link: '{$manifestPath}'.");
    }

    $rawContent = @file_get_contents($manifestPath);
    if ($rawContent === false || trim($rawContent) === '') {
        throw new InvalidArgumentException("Refusing empty or unreadable fixture manifest: '{$manifestPath}'.");
    }
    $cleanJson = preg_replace('/^\xEF\xBB\xBF/', '', $rawContent);
    try {
        $manifest = json_decode($cleanJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new InvalidArgumentException("Refusing invalid fixture manifest JSON: " . $e->getMessage());
    }
    if (!is_array($manifest)) {
        throw new InvalidArgumentException("Fixture manifest did not decode into an associative array.");
    }

    if (($manifest['marker'] ?? '') !== $params['fixture_marker']) {
        throw new InvalidArgumentException("Fixture marker mismatch: manifest marker does not match parameter.");
    }
    if ((int)($manifest['port'] ?? 0) !== $port) {
        throw new InvalidArgumentException("Fixture port mismatch: manifest port {$manifest['port']} does not match {$port}.");
    }
    if ((int)($manifest['pid'] ?? 0) !== $pid) {
        throw new InvalidArgumentException("Fixture PID mismatch: manifest PID {$manifest['pid']} does not match {$pid}.");
    }
    if (($manifest['db'] ?? '') !== $params['db']) {
        throw new InvalidArgumentException("Fixture database mismatch: manifest db '{$manifest['db']}' does not match '{$params['db']}'.");
    }
    $manifestDatadir = realpath($manifest['datadir'] ?? '');
    if ($manifestDatadir === false || strtolower($manifestDatadir) !== strtolower($realDatadir)) {
        throw new InvalidArgumentException("Fixture datadir mismatch: manifest datadir does not match expected datadir.");
    }
}

// 9b: Negative guards testing without touching live DB
$dummyLabDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_mariadb_lab ' . bin2hex(random_bytes(16));
mkdir($dummyLabDir, 0777, true);
$dummyMarker = 'marker_' . bin2hex(random_bytes(8));
$dummyManifestFile = $dummyLabDir . DIRECTORY_SEPARATOR . 'fixture_manifest.json';
$dummyManifest = [
    'datadir' => $dummyLabDir,
    'port' => 54321,
    'pid' => 12345,
    'db' => 'test_pos_recovery',
    'marker' => $dummyMarker,
];
file_put_contents($dummyManifestFile, json_encode($dummyManifest));

$cleanupDummy = function() use ($dummyLabDir, $dummyManifestFile) {
    if (file_exists($dummyManifestFile)) @unlink($dummyManifestFile);
    if (is_dir($dummyLabDir)) @rmdir($dummyLabDir);
};
register_shutdown_function($cleanupDummy);

$baseValidFixture = [
    'host' => '127.0.0.1',
    'port' => 54321,
    'db' => 'test_pos_recovery',
    'expected_datadir' => $dummyLabDir,
    'expected_pid' => 12345,
    'fixture_marker' => $dummyMarker,
    'mig_user' => 'pos_migration',
    'mig_pass' => '',
    'app_user' => 'pos_app',
    'app_pass' => '',
    'root_user' => 'root',
    'root_pass' => ''
];

// Verify base valid fixture validates cleanly
$baseValidPassed = false;
try {
    validateFixtureParams($baseValidFixture);
    $baseValidPassed = true;
} catch (InvalidArgumentException) {}
verify($baseValidPassed, "Synthetic isolated fixture validates cleanly against schema");

$guardPort3306Rejected = false;
try {
    validateFixtureParams(array_merge($baseValidFixture, ['port' => 3306]));
} catch (InvalidArgumentException) {
    $guardPort3306Rejected = true;
}
verify($guardPort3306Rejected, "Negative identity guard: fixture validator strictly rejects port 3306");

$guardPort3307Rejected = false;
try {
    validateFixtureParams(array_merge($baseValidFixture, ['port' => 3307]));
} catch (InvalidArgumentException) {
    $guardPort3307Rejected = true;
}
verify($guardPort3307Rejected, "Negative identity guard: fixture validator strictly rejects port 3307");

$guardRemoteHostRejected = false;
try {
    validateFixtureParams(array_merge($baseValidFixture, ['host' => '192.168.1.50']));
} catch (InvalidArgumentException) {
    $guardRemoteHostRejected = true;
}
verify($guardRemoteHostRejected, "Negative identity guard: fixture validator strictly rejects non-127.0.0.1 host");

$guardUnsafeDatadirRejected = false;
try {
    validateFixtureParams(array_merge($baseValidFixture, ['expected_datadir' => 'C:\\xampp\\mysql\\data']));
} catch (InvalidArgumentException) {
    $guardUnsafeDatadirRejected = true;
}
verify($guardUnsafeDatadirRejected, "Negative identity guard: fixture validator strictly rejects non-temp datadir");

$guardTempRootRejected = false;
try {
    validateFixtureParams(array_merge($baseValidFixture, ['expected_datadir' => sys_get_temp_dir()]));
} catch (InvalidArgumentException) {
    $guardTempRootRejected = true;
}
verify($guardTempRootRejected, "Negative identity guard: fixture validator strictly rejects system temp root itself");

$dummySibling = rtrim(sys_get_temp_dir(), '/\\') . '_sibling';
$guardSiblingRejected = false;
try {
    validateFixtureParams(array_merge($baseValidFixture, ['expected_datadir' => $dummySibling]));
} catch (InvalidArgumentException) {
    $guardSiblingRejected = true;
}
verify($guardSiblingRejected, "Negative identity guard: fixture validator strictly rejects sibling of temp directory");

$guardWrongDbRejected = false;
try {
    validateFixtureParams(array_merge($baseValidFixture, ['db' => 'pos_production']));
} catch (InvalidArgumentException) {
    $guardWrongDbRejected = true;
}
verify($guardWrongDbRejected, "Negative identity guard: fixture validator strictly rejects unexpected database name");

$guardMissingMarkerRejected = false;
try {
    validateFixtureParams(array_merge($baseValidFixture, ['fixture_marker' => '']));
} catch (InvalidArgumentException) {
    $guardMissingMarkerRejected = true;
}
verify($guardMissingMarkerRejected, "Negative identity guard: fixture validator strictly rejects missing fixture marker");

$guardMismatchedMarkerRejected = false;
try {
    validateFixtureParams(array_merge($baseValidFixture, ['fixture_marker' => 'corrupted_marker_9999']));
} catch (InvalidArgumentException) {
    $guardMismatchedMarkerRejected = true;
}
verify($guardMismatchedMarkerRejected, "Negative identity guard: fixture validator strictly rejects marker mismatch against manifest");

$guardMariaDbAloneRejected = false;
try {
    validateFixtureParams([]);
} catch (InvalidArgumentException) {
    $guardMariaDbAloneRejected = true;
}
verify($guardMariaDbAloneRejected, "Negative identity guard: --mariadb invocation alone without fixture params safely rejects");

$cleanupDummy();

// ─────────────────────────────────────────────────────────────────────────────
// Section 10: Isolated Live MariaDB Mode
// ─────────────────────────────────────────────────────────────────────────────
$runMariaDb = false;
$mariaDbParams = [];
foreach ($argv as $arg) {
    if ($arg === '--mariadb') {
        $runMariaDb = true;
    } elseif (str_starts_with($arg, '--host=')) {
        $mariaDbParams['host'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--port=')) {
        $mariaDbParams['port'] = (int)substr($arg, 7);
    } elseif (str_starts_with($arg, '--db=')) {
        $mariaDbParams['db'] = substr($arg, 5);
    } elseif (str_starts_with($arg, '--expected-datadir=')) {
        $mariaDbParams['expected_datadir'] = substr($arg, 19);
    } elseif (str_starts_with($arg, '--expected-pid=')) {
        $mariaDbParams['expected_pid'] = (int)substr($arg, 15);
    } elseif (str_starts_with($arg, '--fixture-marker=')) {
        $mariaDbParams['fixture_marker'] = substr($arg, 17);
    } elseif (str_starts_with($arg, '--mig-user=')) {
        $mariaDbParams['mig_user'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--mig-pass=')) {
        $mariaDbParams['mig_pass'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--app-user=')) {
        $mariaDbParams['app_user'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--app-pass=')) {
        $mariaDbParams['app_pass'] = substr($arg, 11);
    } elseif (str_starts_with($arg, '--root-user=')) {
        $mariaDbParams['root_user'] = substr($arg, 12);
    } elseif (str_starts_with($arg, '--root-pass=')) {
        $mariaDbParams['root_pass'] = substr($arg, 12);
    }
}

if ($runMariaDb) {
    echo "\n--- Section 10: Live MariaDB Verification ---\n";

    // Enforce strict fixture validation: NO DEFAULT PORTS, NO PRODUCTION FALLBACKS
    try {
        validateFixtureParams($mariaDbParams);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, "SAFETY ERROR: --mariadb mode rejected: " . $e->getMessage() . "\n");
        exit(1);
    }

    $host = $mariaDbParams['host'];
    $port = (int)$mariaDbParams['port'];
    $dbName = $mariaDbParams['db'];
    $expectedDatadir = realpath($mariaDbParams['expected_datadir']);
    $expectedPid = (int)$mariaDbParams['expected_pid'];
    $migUser = $mariaDbParams['mig_user'];
    $migPass = $mariaDbParams['mig_pass'];
    $appUser = $mariaDbParams['app_user'];
    $appPass = $mariaDbParams['app_pass'];
    $rootUser = $mariaDbParams['root_user'];
    $rootPass = $mariaDbParams['root_pass'];

    // 10a: Connect root PDO and verify @@datadir, @@port, @@pid_file match owned process BEFORE ANY WRITES
    $rootDsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
    $rootPdo = new PDO($rootDsn, $rootUser, $rootPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $rootIdentity = $rootPdo->query('SELECT @@datadir AS datadir, @@port AS port, @@pid_file AS pid_file')->fetch();
    $realDatadir = realpath(rtrim($rootIdentity['datadir'], '/\\'));

    verify($realDatadir !== false && strtolower($realDatadir) === strtolower($expectedDatadir),
        "Live MariaDB root connection @@datadir matches verified ephemeral lab datadir");
    verify((int)$rootIdentity['port'] === $port,
        "Live MariaDB root connection @@port matches expected dynamic loopback port {$port}");

    $pidFileContent = file_exists($rootIdentity['pid_file']) ? (int)trim(file_get_contents($rootIdentity['pid_file'])) : 0;
    verify($pidFileContent === $expectedPid,
        "Live MariaDB @@pid_file content ({$pidFileContent}) matches StartProcess owned PID ({$expectedPid})");

    // 10b: Connect migration PDO and verify identity
    $migDsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
    $migPdo = new PDO($migDsn, $migUser, $migPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $migIdentity = $migPdo->query('SELECT @@datadir AS datadir, @@port AS port, CURRENT_USER() AS cur_user')->fetch();
    $realMigDatadir = realpath(rtrim($migIdentity['datadir'], '/\\'));
    verify($realMigDatadir !== false && strtolower($realMigDatadir) === strtolower($expectedDatadir),
        "Live MariaDB migration connection @@datadir matches verified ephemeral lab datadir");
    verify((int)$migIdentity['port'] === $port,
        "Live MariaDB migration connection @@port matches expected port {$port}");

    $currentUser = $migIdentity['cur_user'];
    verify(!empty($currentUser) && str_starts_with($currentUser, $migUser . '@'),
        "Live MariaDB migration connection CURRENT_USER() = {$currentUser}");

    // 10c: Connect runtime pos_app PDO and verify privileges
    $appDsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
    $appPdo = new PDO($appDsn, $appUser, $appPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $grants = $appPdo->query("SHOW GRANTS FOR CURRENT_USER()")->fetchAll(PDO::FETCH_COLUMN);
    $allGrants = strtoupper(implode('; ', $grants));
    verify(!str_contains($allGrants, 'TRIGGER') && !str_contains($allGrants, 'ALL PRIVILEGES') && !str_contains($allGrants, 'SUPER'),
        "Live MariaDB runtime pos_app user has strictly DML privileges (NO TRIGGER, NO ALL PRIVILEGES, NO SUPER)");

    $ddlDenied = false;
    try {
        $appPdo->exec("CREATE TABLE runtime_ddl_should_fail (id INT)");
    } catch (PDOException $e) {
        $ddlDenied = true;
    }
    verify($ddlDenied, "Live MariaDB runtime pos_app user is actively denied DDL (CREATE TABLE throws PDOException)");

    // 10d: Snapshot sentinel row values before any test DML
    $sentinelsBefore = $appPdo->query("
        SELECT id, name, barcode, price, cost, branch_id
        FROM products
        WHERE barcode LIKE 'SENTINEL_%'
        ORDER BY id ASC
    ")->fetchAll();
    verify(count($sentinelsBefore) === 2, "Sentinel business rows present in initial database state");

    // 10e: Unprivileged reconciliation failure propagation test
    // Attempting trigger reconciliation with pos_app (which lacks DDL) must fail closed and return errors
    $appMigService = new MigrationService($appPdo);
    $appReconcileErrors = $appMigService->reconcileCatalogTriggers(false);
    verify(!empty($appReconcileErrors),
        "reconcileCatalogTriggers fails closed and propagates errors when attempted without DDL privileges");

    // 10f: Reproduce actual MariaDB Error 1449 (nonexistent definer)
    $rootPdo->exec("DROP TRIGGER IF EXISTS trg_products_catalog_insert");
    $rootPdo->exec("
        CREATE DEFINER = 'nonexistent_definer'@'localhost' TRIGGER trg_products_catalog_insert
        AFTER INSERT ON products
        FOR EACH ROW
        INSERT INTO product_catalog_changes (branch_id, product_id)
        VALUES (NEW.branch_id, NEW.id)
    ");

    $caught1449 = false;
    try {
        $appPdo->exec("INSERT INTO products (name, barcode, price, cost, branch_id) VALUES ('Fail 1449', 'FAIL_1449', 10, 5, 1)");
    } catch (PDOException $e) {
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        if ($driverCode === 1449 || str_contains(strtolower($e->getMessage()), 'definer')) {
            $caught1449 = true;
        }
    }
    verify($caught1449, "Reproduced actual MariaDB Error 1449 (Definer does not exist) on product insert");

    // 10g: Reproduce actual MariaDB Error 1142 (pos_app definer without TRIGGER privilege)
    $rootPdo->exec("DROP TRIGGER IF EXISTS trg_products_catalog_insert");
    $rootPdo->exec("
        CREATE DEFINER = 'pos_app'@'127.0.0.1' TRIGGER trg_products_catalog_insert
        AFTER INSERT ON products
        FOR EACH ROW
        INSERT INTO product_catalog_changes (branch_id, product_id)
        VALUES (NEW.branch_id, NEW.id)
    ");

    $caught1142 = false;
    try {
        $appPdo->exec("INSERT INTO products (name, barcode, price, cost, branch_id) VALUES ('Fail 1142', 'FAIL_1142', 10, 5, 1)");
    } catch (PDOException $e) {
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        $msg = strtolower($e->getMessage());
        if ($driverCode === 1142 && (str_contains($msg, 'trigger') || str_contains($msg, 'denied'))) {
            $caught1142 = true;
        }
    }
    verify($caught1142, "Reproduced actual MariaDB Error 1142 (TRIGGER command denied to pos_app) on product insert");

    // 10h: Privileged reconciliation with forceRebuild = FALSE (actual automatic startup flow)
    $liveMigService = new MigrationService($migPdo);
    $liveErrors = $liveMigService->reconcileCatalogTriggers(false);
    verify(empty($liveErrors), "Privileged reconcileCatalogTriggers(false) successfully detected bad definer and repaired with 0 errors");

    // 10i: Check definer readback from information_schema
    $stmt = $migPdo->prepare("
        SELECT TRIGGER_NAME, DEFINER
        FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = :schema
          AND TRIGGER_NAME IN ('trg_products_catalog_insert', 'trg_products_catalog_update', 'trg_products_catalog_delete')
    ");
    $stmt->execute(['schema' => $dbName]);
    $liveTriggers = $stmt->fetchAll();
    verify(count($liveTriggers) === 3, "Live MariaDB has exactly 3 catalog triggers in information_schema");
    foreach ($liveTriggers as $trg) {
        verify($trg['DEFINER'] === $currentUser,
            "Live MariaDB trigger {$trg['TRIGGER_NAME']} DEFINER matches exact CURRENT_USER: {$currentUser}");
    }

    // 10j: DML operations with runtime pos_app user without TRIGGER privilege
    $migPdo->exec("DELETE FROM product_catalog_changes");
    $migPdo->exec("DELETE FROM products WHERE barcode LIKE 'LIVE_TEST_%'");

    // INSERT via runtime pos_app
    $appPdo->exec("
        INSERT INTO products (name, barcode, price, cost, branch_id)
        VALUES ('Live Test Product', 'LIVE_TEST_001', 50.00, 30.00, 1)
    ");
    $insertedId = (int)$appPdo->lastInsertId();
    verify($insertedId > 0, "pos_app successfully inserted product with repaired trigger firing");

    // UPDATE via runtime pos_app
    $appPdo->exec("
        UPDATE products
        SET price = 60.00
        WHERE id = {$insertedId}
    ");
    verify(true, "pos_app successfully updated product with repaired trigger firing");

    // DELETE via runtime pos_app
    $appPdo->exec("
        DELETE FROM products
        WHERE id = {$insertedId}
    ");
    verify(true, "pos_app successfully deleted product with repaired trigger firing");

    // Verify catalog changes table entries
    $changes = $migPdo->query("
        SELECT branch_id, product_id
        FROM product_catalog_changes
        ORDER BY id ASC
    ")->fetchAll();

    verify(count($changes) === 3, "product_catalog_changes recorded exactly 3 events (insert, update, delete)");
    verify((int)$changes[0]['product_id'] === $insertedId, "Change 1 matches inserted NEW.id");
    verify((int)$changes[1]['product_id'] === $insertedId, "Change 2 matches updated NEW.id");
    verify((int)$changes[2]['product_id'] === $insertedId, "Change 3 matches deleted OLD.id tombstone");

    // 10k: Sentinel row deep equality check (business rows untouched)
    $sentinelsAfter = $appPdo->query("
        SELECT id, name, barcode, price, cost, branch_id
        FROM products
        WHERE barcode LIKE 'SENTINEL_%'
        ORDER BY id ASC
    ")->fetchAll();
    verify($sentinelsAfter === $sentinelsBefore, "Sentinel business rows values remain identical and untouched");

    // 10l: Idempotency check: second reconciliation with force=false makes no changes
    $secondReconcileErrors = $liveMigService->reconcileCatalogTriggers(false);
    verify(empty($secondReconcileErrors), "Second reconcileCatalogTriggers(false) is clean and idempotent");

    // 10m: pos_app still has no TRIGGER or DDL privilege after repair
    $grantsAfter = $appPdo->query("SHOW GRANTS FOR CURRENT_USER()")->fetchAll(PDO::FETCH_COLUMN);
    $allGrantsAfter = strtoupper(implode('; ', $grantsAfter));
    verify(!str_contains($allGrantsAfter, 'TRIGGER') && !str_contains($allGrantsAfter, 'ALL PRIVILEGES') && !str_contains($allGrantsAfter, 'SUPER'),
        "Live MariaDB runtime pos_app user STILL has no TRIGGER/SUPER/ALL privileges after repair");

    $ddlPostRepairDenied = false;
    try {
        $appPdo->exec("CREATE TABLE runtime_ddl_post_repair_fail (id INT)");
    } catch (PDOException $e) {
        $ddlPostRepairDenied = true;
    }
    verify($ddlPostRepairDenied, "Live MariaDB runtime pos_app user is STILL denied DDL after repair");
}

echo "\n====================================================================\n";
echo "SUMMARY: {$passCount}/{$testCount} VERIFICATION CHECKS PASSED!\n";
echo "====================================================================\n";
