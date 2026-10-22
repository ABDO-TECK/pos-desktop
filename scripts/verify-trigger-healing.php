<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/vendor/autoload.php';

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
    private array $executed = [];
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
            // Simulate triggers existing with broken 'root'@'localhost' definers!
            $stmt = parent::query('
                SELECT "trg_products_catalog_insert" AS TRIGGER_NAME, "root@localhost" AS DEFINER
                UNION ALL
                SELECT "trg_products_catalog_update" AS TRIGGER_NAME, "pos_migration@127.0.0.1" AS DEFINER
                UNION ALL
                SELECT "trg_products_catalog_delete" AS TRIGGER_NAME, "old_admin@localhost" AS DEFINER
            ');
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
            preg_match('/CREATE TRIGGER\s+([a-zA-Z0-9_]+)/i', $statement, $matches);
            if (!empty($matches[1])) {
                $this->rebuiltTriggersRef[] = $matches[1];
            }
        }
        try {
            return parent::exec($statement);
        } catch (\Throwable) {
            return 1;
        }
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

// ── Test 2: ProductService Self-Healing Retry on Trigger Definer Error 1449 ──
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
$productService = new ProductService($mockRepo, $priceHistory, $sqlitePdo);

// Verify createProduct self-heals and retries successfully
$attempts = 0;
$result = $productService->createProduct([
    'name' => 'Healed Chips',
    'barcode' => '12345678',
    'price' => 10.0,
    'branch_id' => 1,
]);

if (!($result['ok'] ?? false) || ($result['product']['id'] ?? 0) !== 42) {
    echo "FAILED: createProduct did not self-heal and succeed: " . json_encode($result) . "\n";
    exit(1);
}
if ($attempts !== 2) {
    echo "FAILED: Expected 2 attempts (1 failure + 1 healed retry), got: {$attempts}\n";
    exit(1);
}
echo "PASS Test 2: ProductService intercepted Error 1449, self-healed, retried and successfully created product.\n";

// Verify updateProduct self-heals and retries successfully
$attempts = 0;
$updateResult = $productService->updateProduct(42, [
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
echo "PASS Test 3: ProductService intercepted Error 1142 on update, self-healed, retried and successfully updated product.\n";

// Verify deleteProduct self-heals and retries successfully
$attempts = 0;
$deleteResult = $productService->deleteProduct(42);

if (!($deleteResult['ok'] ?? false)) {
    echo "FAILED: deleteProduct did not self-heal and succeed: " . json_encode($deleteResult) . "\n";
    exit(1);
}
if ($attempts !== 2) {
    echo "FAILED: Expected 2 attempts for deleteProduct, got: {$attempts}\n";
    exit(1);
}
echo "PASS Test 4: ProductService intercepted Error 1449 on delete, self-healed, retried and successfully deleted product.\n";

echo "\n🎉 ALL TRIGGER HEALING VERIFICATION TESTS PASSED SUCCESSFULLY!\n";
