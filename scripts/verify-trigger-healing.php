<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/vendor/autoload.php';

// Guarantee isolated test storage throughout execution
$testStorageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_test_heal_storage_' . bin2hex(random_bytes(8));
@mkdir($testStorageDir, 0777, true);
putenv('APP_STORAGE_DIR=' . $testStorageDir);
register_shutdown_function(function() use ($testStorageDir) {
    if (is_dir($testStorageDir)) {
        @array_map('unlink', glob($testStorageDir . '/*.*') ?: []);
        @rmdir($testStorageDir);
    }
});

use App\Services\MigrationService;
use App\Services\ProductService;
use App\Repositories\ProductRepository;
use App\Models\PriceHistory;
use App\Models\Product;

echo "=======================================================\n";
echo "VERIFYING TRIGGER HEALING & DEFINER SELF-REPAIR\n";
echo "=======================================================\n";

// ── Test 1: MigrationService::reconcileCatalogTriggers definer detection ──
$rebuiltTriggers = [];
$mockPdo = new class($rebuiltTriggers) extends PDO {
    public array $executed = [];
    public array $rebuiltTriggersRef;

    public function __construct(array &$rebuiltTriggersRef) {
        $this->rebuiltTriggersRef = &$rebuiltTriggersRef;
        parent::__construct('sqlite::memory:');
        $this->exec('CREATE TABLE schema_versions (version TEXT)');
        $this->exec("INSERT INTO schema_versions VALUES ('043_add_product_catalog_changes.sql')");
    }

    public function getAttribute($attr): mixed {
        if ($attr === PDO::ATTR_DRIVER_NAME) return 'mysql';
        return parent::getAttribute($attr);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (str_contains($query, 'schema_versions')) {
            $stmt = parent::query('SELECT "043_add_product_catalog_changes.sql" AS version');
            return $stmt;
        }
        if (str_contains($query, 'information_schema.TABLES')) {
            $stmt = parent::query('SELECT "products" AS TABLE_NAME');
            return $stmt;
        }
        if (str_contains($query, 'information_schema.TRIGGERS')) {
            // Simulate triggers existing with broken 'root'@'localhost' definers initially,
            // and returning the updated definer once rebuilt!
            $insertDef = in_array('trg_products_catalog_insert', $this->rebuiltTriggersRef, true) ? 'pos_migration@127.0.0.1' : 'root@localhost';
            $deleteDef = in_array('trg_products_catalog_delete', $this->rebuiltTriggersRef, true) ? 'pos_migration@127.0.0.1' : 'old_admin@localhost';
            $stmt = parent::query("
                SELECT 'trg_products_catalog_insert' AS TRIGGER_NAME, '{$insertDef}' AS DEFINER
                UNION ALL
                SELECT 'trg_products_catalog_update' AS TRIGGER_NAME, 'pos_migration@127.0.0.1' AS DEFINER
                UNION ALL
                SELECT 'trg_products_catalog_delete' AS TRIGGER_NAME, '{$deleteDef}' AS DEFINER
            ");
            return $stmt;
        }
        if (str_contains($query, 'SELECT CURRENT_USER()')) {
            $stmt = parent::query('SELECT "pos_migration@127.0.0.1"');
            return $stmt;
        }
        return parent::query($query);
    }

    public function exec(string $statement): int|false {
        $this->executed[] = $statement;
        if (str_contains($statement, 'CREATE TRIGGER')) {
            preg_match('/CREATE TRIGGER\s+`?([a-zA-Z0-9_]+)`?/i', $statement, $matches);
            if (!empty($matches[1])) {
                $this->rebuiltTriggersRef[] = $matches[1];
            }
            return 1;
        }
        if (str_contains($statement, 'DROP TRIGGER')) {
            return 1;
        }
        if (str_contains($statement, 'CREATE TABLE') && str_contains($statement, 'product_catalog_changes')) {
            return parent::exec('CREATE TABLE IF NOT EXISTS product_catalog_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER, product_id INTEGER, changed_at DATETIME)');
        }
        return parent::exec($statement);
    }
};

$migrationService = new MigrationService($mockPdo);
$errors = $migrationService->reconcileCatalogTriggers(false);

if (!empty($errors)) {
    echo "FAILED: reconcileCatalogTriggers returned errors: " . implode('; ', $errors) . "\n";
    exit(1);
}

// Triggers with @localhost definers must have been identified and rebuilt
if (!in_array('trg_products_catalog_insert', $rebuiltTriggers, true) || !in_array('trg_products_catalog_delete', $rebuiltTriggers, true)) {
    echo "FAILED: Expected triggers with localhost definers to be rebuilt, got: " . json_encode($rebuiltTriggers) . "\n";
    exit(1);
}
// trg_products_catalog_update had valid pos_migration@127.0.0.1 definer, so it should NOT be rebuilt unless forced
if (in_array('trg_products_catalog_update', $rebuiltTriggers, true)) {
    echo "FAILED: Valid trigger was unnecessarily rebuilt: " . json_encode($rebuiltTriggers) . "\n";
    exit(1);
}
echo "PASS Test 1: MigrationService correctly detected broken localhost definers and rebuilt only affected triggers.\n";

// ── Test 2: ProductService honest failure when privileged repair is unavailable (SQLite / unprivileged) ──
$attempts = 0;
$mockRepo = new class($attempts) extends ProductRepository {
    public int $attempts;
    public function __construct(int &$attempts) {
        $this->attempts = &$attempts;
    }
    public function assertBarcodesAvailable(?int $id, string $main, array $extras): void {}
    public function findByBarcode(string $barcode): ?array { return null; }
    public function syncAdditionalBarcodes(int $productId, array $barcodes): void {}
    public function updateMainBarcode(int $productId, string $barcode): void {}
    public function findById(int $id): ?array {
        return ['id' => $id, 'name' => 'Test Product', 'barcode' => '12345678', 'branch_id' => 1];
    }
    public function create(array $data): int {
        $this->attempts++;
        if ($this->attempts === 1) {
            // First attempt simulates MariaDB Error 1449: Definer does not exist
            $pdoException = new PDOException("The user specified as a definer ('root'@'localhost') does not exist");
            $pdoException->errorInfo = ['HY000', 1449, "The user specified as a definer ('root'@'localhost') does not exist"];
            throw $pdoException;
        }
        return 42;
    }
    public function update(int $id, array $data): void {
        $this->attempts++;
        if ($this->attempts === 1) {
            $pdoException = new PDOException("TRIGGER command denied to user 'pos_app'@'127.0.0.1' for table 'products'");
            $pdoException->errorInfo = ['42000', 1142, "TRIGGER command denied to user 'pos_app'@'127.0.0.1' for table 'products'"];
            throw $pdoException;
        }
    }
    public function delete(int $id): void {
        $this->attempts++;
        if ($this->attempts === 1) {
            $pdoException = new PDOException("The user specified as a definer ('root'@'localhost') does not exist");
            $pdoException->errorInfo = ['HY000', 1449, "The user specified as a definer ('root'@'localhost') does not exist"];
            throw $pdoException;
        }
    }
    public function referenceCounts(int $id): array {
        return ['invoice_items' => 0, 'purchases' => 0];
    }
};

$sqlitePdo = new PDO('sqlite::memory:');
$sqlitePdo->exec('CREATE TABLE price_history (id INTEGER PRIMARY KEY, product_id INTEGER, old_price REAL, new_price REAL, old_cost REAL, new_cost REAL, changed_by INTEGER, created_at DATETIME)');
$sqlitePdo->exec('CREATE TABLE product_catalog_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER, product_id INTEGER, changed_at DATETIME)');
$priceHistory = new PriceHistory($sqlitePdo);
$productServiceUnprivileged = new ProductService($mockRepo, $priceHistory, $sqlitePdo);

// Verify that without privileged migration context, ProductService does NOT pretend to heal on SQLite,
// and returns an honest 503 catalog_trigger_repair_required without a second attempt.
$attempts = 0;
$result = $productServiceUnprivileged->createProduct([
    'name' => 'Unhealed Chips',
    'barcode' => '12345678',
    'price' => 10.0,
    'branch_id' => 1,
]);

if (($result['ok'] ?? false) !== false) {
    echo "FAILED: createProduct falsely claimed success on unprivileged SQLite environment: " . json_encode($result) . "\n";
    exit(1);
}
if (($result['code'] ?? 0) !== 503 || ($result['reason_code'] ?? '') !== 'catalog_trigger_repair_required') {
    echo "FAILED: Expected code 503 and reason_code catalog_trigger_repair_required, got: " . json_encode($result) . "\n";
    exit(1);
}
if ($attempts !== 1) {
    echo "FAILED: Expected exactly 1 attempt (no false retry without real repair), got: {$attempts}\n";
    exit(1);
}
echo "PASS Test 2: ProductService correctly returned honest 503 catalog_trigger_repair_required when privileged repair was unavailable.\n";

// ── Test 3: ProductService bounded single retry after real verified repair in privileged CLI context ──
// Create mock privileged PDO that implements real reconciliation behavior
$privilegedRebuilt = [];
$privilegedPdo = new class($privilegedRebuilt) extends PDO {
    public array $rebuilt;
    public function __construct(array &$rebuilt) {
        $this->rebuilt = &$rebuilt;
        parent::__construct('sqlite::memory:');
        $this->exec('CREATE TABLE schema_versions (version TEXT)');
        $this->exec("INSERT INTO schema_versions VALUES ('043_add_product_catalog_changes.sql')");
    }
    public function getAttribute($attr): mixed {
        if ($attr === PDO::ATTR_DRIVER_NAME) return 'mysql';
        return parent::getAttribute($attr);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (str_contains($query, 'SELECT CURRENT_USER()')) {
            return parent::query('SELECT "pos_migration@127.0.0.1"');
        }
        if (str_contains($query, 'information_schema.TABLES')) {
            return parent::query('SELECT "products" AS TABLE_NAME');
        }
        if (str_contains($query, 'information_schema.TRIGGERS')) {
            $insertDef = in_array('trg_products_catalog_insert', $this->rebuilt, true) ? 'pos_migration@127.0.0.1' : 'root@localhost';
            $updateDef = in_array('trg_products_catalog_update', $this->rebuilt, true) ? 'pos_migration@127.0.0.1' : 'pos_app@127.0.0.1';
            $deleteDef = in_array('trg_products_catalog_delete', $this->rebuilt, true) ? 'pos_migration@127.0.0.1' : 'root@localhost';
            return parent::query("
                SELECT 'trg_products_catalog_insert' AS TRIGGER_NAME, '{$insertDef}' AS DEFINER
                UNION ALL
                SELECT 'trg_products_catalog_update' AS TRIGGER_NAME, '{$updateDef}' AS DEFINER
                UNION ALL
                SELECT 'trg_products_catalog_delete' AS TRIGGER_NAME, '{$deleteDef}' AS DEFINER
            ");
        }
        return parent::query($query);
    }
    public function exec(string $statement): int|false {
        if (str_contains($statement, 'CREATE TRIGGER')) {
            preg_match('/CREATE TRIGGER\s+`?([a-zA-Z0-9_]+)`?/i', $statement, $matches);
            if (!empty($matches[1])) {
                $this->rebuilt[] = $matches[1];
            }
            return 1;
        }
        if (str_contains($statement, 'DROP TRIGGER')) {
            return 1;
        }
        if (str_contains($statement, 'CREATE TABLE') && str_contains($statement, 'product_catalog_changes')) {
            return parent::exec('CREATE TABLE IF NOT EXISTS product_catalog_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER, product_id INTEGER, changed_at DATETIME)');
        }
        return parent::exec($statement);
    }
};

// Runtime PDO for ProductService
$runtimePdo = new class extends PDO {
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

// Inject privileged migration connection and env for CLI privileged repair
putenv('DB_MIGRATION_USER=pos_migration');
$ref = new ReflectionProperty(\App\Config\Database::class, 'migrationInstance');
$ref->setAccessible(true);
$ref->setValue(null, $privilegedPdo);

$privilegedPriceHistory = new PriceHistory($runtimePdo);
$productServicePrivileged = new ProductService($mockRepo, $privilegedPriceHistory, $runtimePdo);

$attempts = 0;
$result = $productServicePrivileged->createProduct([
    'name' => 'Healed Chips',
    'barcode' => '12345678',
    'price' => 10.0,
    'branch_id' => 1,
]);

if (!($result['ok'] ?? false) || ($result['product']['id'] ?? 0) !== 42) {
    echo "FAILED: createProduct did not self-heal and succeed after privileged repair: " . json_encode($result) . "\n";
    exit(1);
}
if ($attempts !== 2) {
    echo "FAILED: Expected exactly 2 attempts (1 failure + 1 healed retry), got: {$attempts}\n";
    exit(1);
}
echo "PASS Test 3: ProductService intercepted Error 1449, executed SIMULATED privileged repair mock, retried once and succeeded.\n";

// ── Test 4: updateProduct bounded single retry after simulated verified repair ──
$attempts = 0;
$updateResult = $productServicePrivileged->updateProduct(42, [
    'name' => 'Updated Chips',
    'barcode' => '12345678',
    'price' => 12.0,
]);

if (!($updateResult['ok'] ?? false)) {
    echo "FAILED: updateProduct did not self-heal and succeed: " . json_encode($updateResult) . "\n";
    exit(1);
}
if ($attempts !== 2) {
    echo "FAILED: Expected 2 attempts for updateProduct, got: {$attempts}\n";
    exit(1);
}
echo "PASS Test 4: ProductService intercepted Error 1142 on update, executed SIMULATED privileged repair mock, retried once and succeeded.\n";

// ── Test 5: deleteProduct bounded single retry after simulated verified repair ──
$attempts = 0;
$deleteResult = $productServicePrivileged->deleteProduct(42);

if (!($deleteResult['ok'] ?? false)) {
    echo "FAILED: deleteProduct did not self-heal and succeed: " . json_encode($deleteResult) . "\n";
    exit(1);
}
if ($attempts !== 2) {
    echo "FAILED: Expected 2 attempts for deleteProduct, got: {$attempts}\n";
    exit(1);
}
echo "PASS Test 5: ProductService intercepted Error 1449 on delete, executed SIMULATED privileged repair mock, retried once and succeeded.\n";

// Clean up
putenv('DB_MIGRATION_USER');
\App\Config\Database::resetMigrationInstance();

echo "\n🎉 ALL TRIGGER HEALING VERIFICATION TESTS PASSED SUCCESSFULLY!\n";
