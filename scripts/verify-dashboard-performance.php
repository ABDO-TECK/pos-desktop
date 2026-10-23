<?php
declare(strict_types=1);

/**
 * scripts/verify-dashboard-performance.php
 *
 * Self-contained verification script comparing baseline 12-query dashboard summary
 * against optimized 6-query implementation using real models, repositories, and services
 * over an isolated SQLite in-memory PDO database.
 */

$source = dirname(__DIR__);

// Load Composer autoloader or PSR-4 fallback for App namespace
if (is_file($source . '/backend/vendor/autoload.php')) {
    require_once $source . '/backend/vendor/autoload.php';
}
if (is_file($source . '/vendor/autoload.php')) {
    require_once $source . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class) use ($source): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $source . '/backend/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

// Explicit verify helper throwing RuntimeException (independent of zend.assertions ini)
function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException("Verification failed: {$message}");
    }
}

/**
 * Statement subclass counting execute() invocations when tracking is active.
 */
class CountingStatement extends \PDOStatement
{
    private CountingPDO $countingPdo;

    protected function __construct(CountingPDO $countingPdo)
    {
        $this->countingPdo = $countingPdo;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->countingPdo->isTracking()) {
            $this->countingPdo->incrementExecuteCount();
        }
        return parent::execute($params);
    }
}

/**
 * Test-only PDO adapter wrapping in-memory SQLite.
 *
 * SQLite does not implement MySQL CURDATE(), INTERVAL, or DATE_FORMAT() functions.
 * This adapter normalizes ONLY those MySQL date expressions into exact literal boundary
 * strings evaluated from $dbToday. No SQL joins, aggregations, columns, or query logic
 * are altered.
 */
class CountingPDO extends \PDO
{
    private int $executeCount = 0;
    private bool $tracking = false;
    private string $dbToday = '2025-02-15';

    public function __construct(string $dsn = 'sqlite::memory:')
    {
        parent::__construct($dsn);
        $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, [$this]]);
        $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function setDbToday(string $date): void
    {
        $this->dbToday = $date;
    }

    public function getDbToday(): string
    {
        return $this->dbToday;
    }

    public function startTracking(): void
    {
        $this->tracking = true;
    }

    public function stopTracking(): void
    {
        $this->tracking = false;
    }

    public function isTracking(): bool
    {
        return $this->tracking;
    }

    public function resetCount(): void
    {
        $this->executeCount = 0;
    }

    public function getCount(): int
    {
        return $this->executeCount;
    }

    public function incrementExecuteCount(): void
    {
        $this->executeCount++;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $normalized = $this->normalizeDateExpressions($query);
        return parent::prepare($normalized, $options);
    }

    /**
     * Replaces only MySQL date functions with ISO-8601 boundary literals based on $this->dbToday.
     */
    private function normalizeDateExpressions(string $sql): string
    {
        $month = (int) date('n', strtotime($this->dbToday));
        $year  = (int) date('Y', strtotime($this->dbToday));
        $monthStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $monthEnd   = date('Y-m-d 00:00:00', strtotime($monthStart . ' +1 month'));
        $dayStart   = $this->dbToday . ' 00:00:00';
        $dayEnd     = date('Y-m-d 00:00:00', strtotime($this->dbToday . ' +1 day'));

        // Replace DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, "%Y-%m-01") or '%Y-%m-01'
        $sql = preg_replace(
            '/DATE_FORMAT\s*\(\s*CURDATE\(\)\s*\+\s*INTERVAL\s+1\s+MONTH\s*,\s*[\'"]%Y-%m-01[\'"]\s*\)/i',
            "'{$monthEnd}'",
            $sql
        );
        // Replace DATE_FORMAT(CURDATE(), "%Y-%m-01") or '%Y-%m-01'
        $sql = preg_replace(
            '/DATE_FORMAT\s*\(\s*CURDATE\(\)\s*,\s*[\'"]%Y-%m-01[\'"]\s*\)/i',
            "'{$monthStart}'",
            $sql
        );
        // Replace CURDATE() + INTERVAL 1 DAY
        $sql = preg_replace(
            '/CURDATE\(\)\s*\+\s*INTERVAL\s+1\s+DAY/i',
            "'{$dayEnd}'",
            $sql
        );
        // Replace CURDATE()
        $sql = preg_replace(
            '/CURDATE\(\)/i',
            "'{$dayStart}'",
            $sql
        );

        return $sql;
    }
}

/**
 * Subclass of ReportService providing a protected seam for testing specific PHP dates.
 */
class TestableReportService extends \App\Services\ReportService
{
    private string $testDate = '2025-02-15';

    public function setTestDate(string $date): void
    {
        $this->testDate = $date;
    }

    protected function getDashboardDate(): string
    {
        return $this->testDate;
    }
}

/**
 * Baseline 12-query dashboard summary helper reproducing the original ReportService mapping.
 * Uses only unmodified legacy methods to establish ground truth.
 */
function runBaselineDashboardSummary(
    \App\Repositories\InvoiceRepository $invoiceRepo,
    \App\Repositories\ExpenseRepository $expenseRepo,
    \App\Repositories\ProductRepository $productRepo,
    \App\Repositories\SupplierRepository $supplierRepo,
    string $todayDate
): array {
    $todayRevenue   = $invoiceRepo->getTodayRevenue();
    $monthRevenue   = $invoiceRepo->getMonthRevenue();
    $todayInvoices  = $invoiceRepo->getTodayInvoicesCount();

    $totalProducts  = $productRepo->getTotalProductsCount();
    $lowStockCount  = $productRepo->getLowStockProductsCount();
    $totalSuppliers = $supplierRepo->getTotalSuppliersCount();

    $month = (int) date('n', strtotime($todayDate));
    $year  = (int) date('Y', strtotime($todayDate));

    $monthProfit = $invoiceRepo->getTotalProfitForMonth($month, $year);
    $todayProfit = $invoiceRepo->getTotalProfitForDate($todayDate);
    $todayCost   = $invoiceRepo->getTotalCostForDate($todayDate);
    $monthCost   = $invoiceRepo->getTotalCostForMonth($month, $year);

    $todayExpenses = $expenseRepo->getTotalExpensesForDate($todayDate);
    $monthExpenses = $expenseRepo->getTotalExpensesForMonth($month, $year);

    return [
        'today_revenue'    => (float) $todayRevenue,
        'month_revenue'    => (float) $monthRevenue,
        'today_cost'       => $todayCost,
        'month_cost'       => $monthCost,
        'today_profit'     => $todayProfit,
        'month_profit'     => $monthProfit,
        'today_expenses'   => $todayExpenses,
        'month_expenses'   => $monthExpenses,
        'today_net_profit' => $todayProfit - $todayExpenses,
        'month_net_profit' => $monthProfit - $monthExpenses,
        'today_invoices'   => (int) $todayInvoices,
        'total_products'   => (int) $totalProducts,
        'low_stock_count'  => (int) $lowStockCount,
        'total_suppliers'  => (int) $totalSuppliers,
    ];
}

/**
 * Compare all summary keys and types with appropriate floating-point tolerance.
 */
function assertSummaryMatches(array $expected, array $actual, string $context): void
{
    $expectedKeys = [
        'today_revenue', 'month_revenue', 'today_cost', 'month_cost',
        'today_profit', 'month_profit', 'today_expenses', 'month_expenses',
        'today_net_profit', 'month_net_profit', 'today_invoices',
        'total_products', 'low_stock_count', 'total_suppliers',
    ];

    verify(array_keys($actual) === $expectedKeys, "{$context}: Keys mismatch or out of order");

    foreach ($expectedKeys as $key) {
        verify(array_key_exists($key, $actual), "{$context}: Missing key '{$key}'");
        $exp = $expected[$key];
        $act = $actual[$key];

        if (is_int($exp)) {
            verify(is_int($act), "{$context}: Key '{$key}' expected int, got " . gettype($act));
            verify($exp === $act, "{$context}: Key '{$key}' expected {$exp}, got {$act}");
        } elseif (is_float($exp)) {
            verify(is_float($act), "{$context}: Key '{$key}' expected float, got " . gettype($act));
            verify(abs($exp - $act) < 0.0001, "{$context}: Key '{$key}' expected {$exp}, got {$act}");
        } else {
            verify($exp === $act, "{$context}: Key '{$key}' value mismatch");
        }
    }
}

/**
 * Set AuthService globalBranchId via Reflection (TEST-ONLY, safe and restored in finally).
 */
function setBranchContext(int $branchId, callable $fn): mixed
{
    $ref = new \ReflectionClass(\App\Services\AuthService::class);
    $prop = $ref->getProperty('globalBranchId');
    $prop->setAccessible(true);
    $orig = $prop->getValue();
    try {
        $prop->setValue(null, $branchId);
        return $fn();
    } finally {
        $prop->setValue(null, $orig);
    }
}

/**
 * Setup fixture database schema and indexes.
 */
function initializeFixtureDb(CountingPDO $pdo): void
{
    $pdo->exec('DROP TABLE IF EXISTS invoice_items');
    $pdo->exec('DROP TABLE IF EXISTS invoices');
    $pdo->exec('DROP TABLE IF EXISTS expenses');
    $pdo->exec('DROP TABLE IF EXISTS products');
    $pdo->exec('DROP TABLE IF EXISTS suppliers');

    $pdo->exec('
        CREATE TABLE invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL DEFAULT 1,
            customer_id INTEGER DEFAULT NULL,
            total REAL NOT NULL DEFAULT 0.0,
            discount REAL NOT NULL DEFAULT 0.0,
            tax REAL NOT NULL DEFAULT 0.0,
            status TEXT NOT NULL DEFAULT "completed",
            created_at TEXT NOT NULL
        )
    ');

    $pdo->exec('
        CREATE TABLE invoice_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity REAL NOT NULL DEFAULT 1.0,
            price REAL NOT NULL DEFAULT 0.0,
            unit_cost REAL DEFAULT NULL,
            subtotal REAL NOT NULL DEFAULT 0.0
        )
    ');

    $pdo->exec('
        CREATE TABLE expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER NOT NULL,
            category_id INTEGER NOT NULL DEFAULT 1,
            user_id INTEGER NOT NULL DEFAULT 1,
            amount REAL NOT NULL DEFAULT 0.0,
            notes TEXT DEFAULT NULL,
            expense_date TEXT NOT NULL
        )
    ');

    $pdo->exec('
        CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            barcode TEXT DEFAULT NULL,
            price REAL NOT NULL DEFAULT 0.0,
            cost REAL NOT NULL DEFAULT 0.0,
            quantity REAL NOT NULL DEFAULT 0.0,
            low_stock_threshold REAL NOT NULL DEFAULT 5.0,
            deleted_at TEXT DEFAULT NULL
        )
    ');

    $pdo->exec('
        CREATE TABLE suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            deleted_at TEXT DEFAULT NULL
        )
    ');

    // Composite indexes demonstrating index-friendly range predicates
    $pdo->exec('CREATE INDEX idx_invoices_branch_status_created ON invoices(branch_id, status, created_at)');
    $pdo->exec('CREATE INDEX idx_invoice_items_invoice ON invoice_items(invoice_id)');
    $pdo->exec('CREATE INDEX idx_expenses_branch_date ON expenses(branch_id, expense_date)');
    $pdo->exec('CREATE INDEX idx_products_branch_deleted ON products(branch_id, deleted_at)');
    $pdo->exec('CREATE INDEX idx_suppliers_branch_deleted ON suppliers(branch_id, deleted_at)');
}

/**
 * Instantiate standard application stack.
 */
function createServiceStack(CountingPDO $pdo): array
{
    $invoiceModel = new \App\Models\Invoice($pdo);
    $expenseModel = new \App\Models\Expense($pdo);
    $productModel = new \App\Models\Product($pdo);
    $supplierModel = new \App\Models\Supplier($pdo);
    $purchaseInvoiceModel = new \App\Models\PurchaseInvoice($pdo);
    $ledgerModel = new \App\Models\SupplierLedger($pdo, $supplierModel);

    $invoiceRepo = new \App\Repositories\InvoiceRepository($invoiceModel);
    $expenseRepo = new \App\Repositories\ExpenseRepository($expenseModel);
    $productRepo = new \App\Repositories\ProductRepository($productModel);
    $supplierRepo = new \App\Repositories\SupplierRepository($supplierModel, $purchaseInvoiceModel, $ledgerModel);

    $service = new TestableReportService($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo);

    return [
        'service'      => $service,
        'invoiceRepo'  => $invoiceRepo,
        'expenseRepo'  => $expenseRepo,
        'productRepo'  => $productRepo,
        'supplierRepo' => $supplierRepo,
    ];
}

// =========================================================================
// RUN ALL TEST SUITES
// =========================================================================

echo "====================================================================\n";
echo " POS Dashboard Performance Verification (Baseline 12 vs Optimized 6)\n";
echo "====================================================================\n\n";

$pdo = new CountingPDO();
initializeFixtureDb($pdo);
$stack = createServiceStack($pdo);

/** @var TestableReportService $service */
$service = $stack['service'];
$invoiceRepo = $stack['invoiceRepo'];
$expenseRepo = $stack['expenseRepo'];
$productRepo = $stack['productRepo'];
$supplierRepo = $stack['supplierRepo'];

// -------------------------------------------------------------------------
// Test Case 1: Empty Database
// -------------------------------------------------------------------------
echo "[Case 1] Empty Database State... ";
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

$pdo->resetCount();
$pdo->startTracking();
$baselineEmpty = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
$pdo->stopTracking();
$baselineCount = $pdo->getCount();
verify($baselineCount === 12, "Case 1: Baseline query count must be 12 (got {$baselineCount})");

$pdo->resetCount();
$pdo->startTracking();
$optimizedEmpty = $service->getDashboardSummary();
$pdo->stopTracking();
$optimizedCount = $pdo->getCount();
verify($optimizedCount === 6, "Case 1: Optimized query count must be exactly 6 (got {$optimizedCount})");

assertSummaryMatches($baselineEmpty, $optimizedEmpty, 'Case 1: Empty DB');
verify($optimizedEmpty['today_revenue'] === 0.0, 'Case 1: today_revenue 0.0');
verify($optimizedEmpty['today_invoices'] === 0, 'Case 1: today_invoices 0');
verify($optimizedEmpty['total_products'] === 0, 'Case 1: total_products 0');
echo "PASS (12 -> 6 queries)\n";

// -------------------------------------------------------------------------
// Test Case 2: Nominal Same-Day Operations (PHP Today == DB Today)
// -------------------------------------------------------------------------
echo "[Case 2] Nominal Flow (PHP Today == DB Today)... ";
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

// Invoices on today (2025-02-15)
$pdo->exec("INSERT INTO invoices (branch_id, total, discount, tax, status, created_at) VALUES (1, 150.0, 0, 0, 'completed', '2025-02-15 10:30:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 101, 3.0, 50.0, 30.0, 150.0)");

// Invoice on earlier day in current month (2025-02-05)
$pdo->exec("INSERT INTO invoices (branch_id, total, discount, tax, status, created_at) VALUES (1, 200.0, 0, 0, 'completed', '2025-02-05 14:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (2, 102, 2.0, 100.0, 70.0, 200.0)");

// Expenses today and earlier this month
$pdo->exec("INSERT INTO expenses (branch_id, amount, expense_date) VALUES (1, 40.0, '2025-02-15 11:00:00')");
$pdo->exec("INSERT INTO expenses (branch_id, amount, expense_date) VALUES (1, 60.0, '2025-02-08 09:00:00')");

// Products: 1 normal, 1 low stock, 1 deleted
$pdo->exec("INSERT INTO products (branch_id, name, barcode, price, cost, quantity, low_stock_threshold, deleted_at) VALUES (1, 'Product A', 'A1', 50.0, 30.0, 10.0, 5.0, NULL)");
$pdo->exec("INSERT INTO products (branch_id, name, barcode, price, cost, quantity, low_stock_threshold, deleted_at) VALUES (1, 'Product B', 'B1', 100.0, 70.0, 3.0, 5.0, NULL)");
$pdo->exec("INSERT INTO products (branch_id, name, barcode, price, cost, quantity, low_stock_threshold, deleted_at) VALUES (1, 'Deleted P', 'D1', 20.0, 10.0, 1.0, 5.0, '2025-01-01 00:00:00')");

// Suppliers: 1 active, 1 deleted
$pdo->exec("INSERT INTO suppliers (branch_id, name, deleted_at) VALUES (1, 'Active Supplier', NULL)");
$pdo->exec("INSERT INTO suppliers (branch_id, name, deleted_at) VALUES (1, 'Deleted Supplier', '2025-01-01 00:00:00')");

$pdo->resetCount();
$pdo->startTracking();
$baselineNominal = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
$pdo->stopTracking();
$baselineCount = $pdo->getCount();
verify($baselineCount === 12, "Case 2: Baseline query count must be 12");

$pdo->resetCount();
$pdo->startTracking();
$optimizedNominal = $service->getDashboardSummary();
$pdo->stopTracking();
$optimizedCount = $pdo->getCount();
verify($optimizedCount === 6, "Case 2: Optimized query count must be exactly 6");

assertSummaryMatches($baselineNominal, $optimizedNominal, 'Case 2: Nominal Flow');
verify($optimizedNominal['today_revenue'] === 150.0, 'Case 2: today_revenue 150.0');
verify($optimizedNominal['month_revenue'] === 350.0, 'Case 2: month_revenue 350.0');
verify($optimizedNominal['today_cost'] === 90.0, 'Case 2: today_cost 90.0');
verify($optimizedNominal['month_cost'] === 230.0, 'Case 2: month_cost 230.0');
verify($optimizedNominal['today_profit'] === 60.0, 'Case 2: today_profit 60.0');
verify($optimizedNominal['month_profit'] === 120.0, 'Case 2: month_profit 120.0');
verify($optimizedNominal['today_expenses'] === 40.0, 'Case 2: today_expenses 40.0');
verify($optimizedNominal['month_expenses'] === 100.0, 'Case 2: month_expenses 100.0');
verify($optimizedNominal['today_net_profit'] === 20.0, 'Case 2: today_net_profit 20.0');
verify($optimizedNominal['month_net_profit'] === 20.0, 'Case 2: month_net_profit 20.0');
verify($optimizedNominal['today_invoices'] === 1, 'Case 2: today_invoices 1');
verify($optimizedNominal['total_products'] === 2, 'Case 2: total_products 2');
verify($optimizedNominal['low_stock_count'] === 1, 'Case 2: low_stock_count 1');
verify($optimizedNominal['total_suppliers'] === 1, 'Case 2: total_suppliers 1');
echo "PASS (12 -> 6 queries, exact value parity)\n";

// -------------------------------------------------------------------------
// Test Case 3: Clock Boundary Preservation (PHP Today != DB Today)
// -------------------------------------------------------------------------
echo "[Case 3] Clock Boundary Preservation (PHP Today != DB Today)... ";
// Simulate DB clock ahead/behind PHP clock:
// DB today is 2025-02-16 (DB midnight crossed), but PHP today is still 2025-02-15
$pdo->setDbToday('2025-02-16');
$service->setTestDate('2025-02-15');

// Insert an invoice on 2025-02-16
$pdo->exec("INSERT INTO invoices (branch_id, total, discount, tax, status, created_at) VALUES (1, 500.0, 0, 0, 'completed', '2025-02-16 01:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (3, 103, 5.0, 100.0, 60.0, 500.0)");

$pdo->resetCount();
$pdo->startTracking();
$baselineClock = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
$pdo->stopTracking();
verify($pdo->getCount() === 12, "Case 3: Baseline query count 12");

$pdo->resetCount();
$pdo->startTracking();
$optimizedClock = $service->getDashboardSummary();
$pdo->stopTracking();
verify($pdo->getCount() === 6, "Case 3: Optimized query count 6");

// In both baseline and optimized:
// DB CURDATE is 2025-02-16 -> today_revenue should be 500.0, today_invoices should be 1
// PHP date is 2025-02-15 -> today_cost should still be 90.0, today_profit should still be 60.0
assertSummaryMatches($baselineClock, $optimizedClock, 'Case 3: Preserving distinct clocks');
verify($optimizedClock['today_revenue'] === 500.0, 'Case 3: today_revenue reflects DB date');
verify($optimizedClock['today_cost'] === 90.0, 'Case 3: today_cost reflects PHP date');
echo "PASS (Preserves DB CURDATE revenue vs PHP date cost/profit/expenses)\n";

// -------------------------------------------------------------------------
// Test Case 4: Month, Year, and Leap Day Boundaries
// -------------------------------------------------------------------------
echo "[Case 4] Month, Year, and Leap Day Boundaries... ";
initializeFixtureDb($pdo);

// Leap Day: 2024-02-29
$pdo->setDbToday('2024-02-29');
$service->setTestDate('2024-02-29');
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 100.0, 'completed', '2024-02-29 12:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 1, 1.0, 100.0, 40.0, 100.0)");
$pdo->exec("INSERT INTO expenses (branch_id, amount, expense_date) VALUES (1, 10.0, '2024-02-29 15:00:00')");

$pdo->resetCount();
$pdo->startTracking();
$baseLeap = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2024-02-29');
$pdo->stopTracking();

$pdo->resetCount();
$pdo->startTracking();
$optLeap = $service->getDashboardSummary();
$pdo->stopTracking();

assertSummaryMatches($baseLeap, $optLeap, 'Case 4: Leap Day 2024-02-29');
verify($optLeap['today_revenue'] === 100.0 && $optLeap['month_revenue'] === 100.0, 'Case 4: Leap revenue');

// Year End: 2025-12-31
$pdo->setDbToday('2025-12-31');
$service->setTestDate('2025-12-31');
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 75.0, 'completed', '2025-12-31 23:59:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (2, 2, 1.0, 75.0, 25.0, 75.0)");

$pdo->resetCount();
$pdo->startTracking();
$baseYearEnd = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-12-31');
$pdo->stopTracking();

$pdo->resetCount();
$pdo->startTracking();
$optYearEnd = $service->getDashboardSummary();
$pdo->stopTracking();

assertSummaryMatches($baseYearEnd, $optYearEnd, 'Case 4: Year End 2025-12-31');
verify($optYearEnd['today_revenue'] === 75.0 && $optYearEnd['month_revenue'] === 75.0, 'Case 4: Year end revenue');
echo "PASS (Leap day 2024-02-29 & Year end 2025-12-31 verified)\n";

// -------------------------------------------------------------------------
// Test Case 5: Branch Isolation (Branch 1 vs Branch 2)
// -------------------------------------------------------------------------
echo "[Case 5] Multi-Branch Isolation... ";
initializeFixtureDb($pdo);
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

// Branch 1 data
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 100.0, 'completed', '2025-02-15 10:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 1, 1.0, 100.0, 40.0, 100.0)");
$pdo->exec("INSERT INTO expenses (branch_id, amount, expense_date) VALUES (1, 15.0, '2025-02-15 11:00:00')");
$pdo->exec("INSERT INTO products (branch_id, name, price, cost, quantity, low_stock_threshold) VALUES (1, 'B1 Prod', 100.0, 40.0, 10, 5)");
$pdo->exec("INSERT INTO suppliers (branch_id, name) VALUES (1, 'B1 Supp')");

// Branch 2 data
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (2, 900.0, 'completed', '2025-02-15 12:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (2, 2, 3.0, 300.0, 150.0, 900.0)");
$pdo->exec("INSERT INTO expenses (branch_id, amount, expense_date) VALUES (2, 80.0, '2025-02-15 13:00:00')");
$pdo->exec("INSERT INTO products (branch_id, name, price, cost, quantity, low_stock_threshold) VALUES (2, 'B2 Prod', 300.0, 150.0, 20, 5)");
$pdo->exec("INSERT INTO suppliers (branch_id, name) VALUES (2, 'B2 Supp')");

// Test under Branch 1
setBranchContext(1, function () use ($service, $invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, $pdo) {
    $pdo->resetCount();
    $pdo->startTracking();
    $b1Base = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
    $pdo->stopTracking();

    $pdo->resetCount();
    $pdo->startTracking();
    $b1Opt = $service->getDashboardSummary();
    $pdo->stopTracking();

    assertSummaryMatches($b1Base, $b1Opt, 'Case 5: Branch 1');
    verify($b1Opt['today_revenue'] === 100.0, 'Case 5: Branch 1 revenue 100.0');
    verify($b1Opt['total_products'] === 1, 'Case 5: Branch 1 products 1');
    verify($b1Opt['total_suppliers'] === 1, 'Case 5: Branch 1 suppliers 1');
});

// Test under Branch 2
setBranchContext(2, function () use ($service, $invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, $pdo) {
    $pdo->resetCount();
    $pdo->startTracking();
    $b2Base = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
    $pdo->stopTracking();

    $pdo->resetCount();
    $pdo->startTracking();
    $b2Opt = $service->getDashboardSummary();
    $pdo->stopTracking();

    assertSummaryMatches($b2Base, $b2Opt, 'Case 5: Branch 2');
    verify($b2Opt['today_revenue'] === 900.0, 'Case 5: Branch 2 revenue 900.0');
    verify($b2Opt['total_products'] === 1, 'Case 5: Branch 2 products 1');
    verify($b2Opt['total_suppliers'] === 1, 'Case 5: Branch 2 suppliers 1');
});
echo "PASS (Branch 1 and Branch 2 strictly isolated)\n";

// -------------------------------------------------------------------------
// Test Case 6: Status Exclusion (Draft & Cancelled Ignored)
// -------------------------------------------------------------------------
echo "[Case 6] Status Exclusion (Draft & Cancelled Ignored)... ";
initializeFixtureDb($pdo);
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 100.0, 'completed', '2025-02-15 10:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 1, 1.0, 100.0, 30.0, 100.0)");

$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 250.0, 'draft', '2025-02-15 11:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (2, 2, 1.0, 250.0, 50.0, 250.0)");

$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 400.0, 'cancelled', '2025-02-15 12:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (3, 3, 1.0, 400.0, 100.0, 400.0)");

$baseStatus = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
$optStatus  = $service->getDashboardSummary();
assertSummaryMatches($baseStatus, $optStatus, 'Case 6: Status filtering');
verify($optStatus['today_revenue'] === 100.0, 'Case 6: Only completed revenue included');
verify($optStatus['today_invoices'] === 1, 'Case 6: Only completed invoice counted');
verify($optStatus['today_cost'] === 30.0, 'Case 6: Only completed item cost included');
echo "PASS (draft and cancelled properly excluded)\n";

// -------------------------------------------------------------------------
// Test Case 7: Completed Header With No Items
// -------------------------------------------------------------------------
echo "[Case 7] Completed Header With No Items... ";
initializeFixtureDb($pdo);
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

// Completed invoice total 300.0 with NO items in invoice_items
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 300.0, 'completed', '2025-02-15 09:00:00')");

$baseNoItems = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
$optNoItems  = $service->getDashboardSummary();
assertSummaryMatches($baseNoItems, $optNoItems, 'Case 7: Header without items');
verify($optNoItems['today_revenue'] === 300.0, 'Case 7: Revenue includes header without items');
verify($optNoItems['today_invoices'] === 1, 'Case 7: Invoice count includes header without items');
verify($optNoItems['today_cost'] === 0.0, 'Case 7: Cost is 0.0 when no items');
verify($optNoItems['today_profit'] === 0.0, 'Case 7: Profit is 0.0 when no items');
echo "PASS (Header without items accounted for in revenue/count, 0 cost/profit)\n";

// -------------------------------------------------------------------------
// Test Case 8: Multi-Item Invoice (Revenue Not Duplicated)
// -------------------------------------------------------------------------
echo "[Case 8] Multi-Item Invoice (Revenue Not Duplicated)... ";
initializeFixtureDb($pdo);
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

// Invoice total 300.0 having 3 line items
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 300.0, 'completed', '2025-02-15 10:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 1, 1.0, 100.0, 40.0, 100.0)");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 2, 1.0, 100.0, 50.0, 100.0)");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 3, 1.0, 100.0, 60.0, 100.0)");

$baseMulti = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
$optMulti  = $service->getDashboardSummary();
assertSummaryMatches($baseMulti, $optMulti, 'Case 8: Multi-item invoice');
verify($optMulti['today_revenue'] === 300.0, 'Case 8: Revenue is 300.0 (not 900.0)');
verify($optMulti['today_invoices'] === 1, 'Case 8: Invoices count is 1 (not 3)');
verify($optMulti['today_cost'] === 150.0, 'Case 8: Cost correctly sums 3 items (150.0)');
verify($optMulti['today_profit'] === 150.0, 'Case 8: Profit correctly sums 3 items (150.0)');
echo "PASS (Single header query prevents revenue duplication across items)\n";

// -------------------------------------------------------------------------
// Test Case 9: Historical unit_cost vs Mutable Current Product Cost
// -------------------------------------------------------------------------
echo "[Case 9] Historical unit_cost vs Mutable Current Product Cost... ";
initializeFixtureDb($pdo);
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

// Product currently has cost = 80.0 in products table
$pdo->exec("INSERT INTO products (id, branch_id, name, price, cost, quantity, low_stock_threshold) VALUES (10, 1, 'Widget', 100.0, 80.0, 5, 2)");
// But when sold, invoice item captured historical unit_cost = 35.0
$pdo->exec("INSERT INTO invoices (id, branch_id, total, status, created_at) VALUES (1, 1, 100.0, 'completed', '2025-02-15 10:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 10, 1.0, 100.0, 35.0, 100.0)");

$baseHist = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
$optHist  = $service->getDashboardSummary();
assertSummaryMatches($baseHist, $optHist, 'Case 9: Historical unit_cost');
verify($optHist['today_cost'] === 35.0, 'Case 9: Cost uses historical ii.unit_cost (35.0), not products.cost (80.0)');
verify($optHist['today_profit'] === 65.0, 'Case 9: Profit is 100 - 35 = 65.0');
echo "PASS (Preserves historical unit_cost snapshot, no products join)\n";

// -------------------------------------------------------------------------
// Test Case 10: Discounts & Tax (Header Total != Line Price Sum)
// -------------------------------------------------------------------------
echo "[Case 10] Header Total With Discount & Tax vs Line Price Sum... ";
initializeFixtureDb($pdo);
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

// Line item price sum: 200.0. Discount: 30.0, Tax: 10.0 -> Final invoice total = 180.0
$pdo->exec("INSERT INTO invoices (branch_id, total, discount, tax, status, created_at) VALUES (1, 180.0, 30.0, 10.0, 'completed', '2025-02-15 12:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 1, 2.0, 100.0, 50.0, 200.0)");

$baseDisc = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
$optDisc  = $service->getDashboardSummary();
assertSummaryMatches($baseDisc, $optDisc, 'Case 10: Discount/tax invoice total');
verify($optDisc['today_revenue'] === 180.0, 'Case 10: Revenue is header total 180.0');
verify($optDisc['today_cost'] === 100.0, 'Case 10: Cost is 2 * 50 = 100.0');
verify($optDisc['today_profit'] === 100.0, 'Case 10: Profit is (100 - 50) * 2 = 100.0 (per existing SQL definition)');
echo "PASS (Header revenue 180.0 preserved independently of line sum)\n";

// -------------------------------------------------------------------------
// Test Case 11: Soft-Deleted Product & Supplier Counters
// -------------------------------------------------------------------------
echo "[Case 11] Soft-Deleted Products and Suppliers Filtering... ";
initializeFixtureDb($pdo);
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

// Active products: 1 normal, 1 low stock
$pdo->exec("INSERT INTO products (branch_id, name, price, cost, quantity, low_stock_threshold, deleted_at) VALUES (1, 'P1', 10, 5, 20, 5, NULL)");
$pdo->exec("INSERT INTO products (branch_id, name, price, cost, quantity, low_stock_threshold, deleted_at) VALUES (1, 'P2', 10, 5, 2, 5, NULL)");
// Soft-deleted products: 1 with low stock, 1 with normal stock
$pdo->exec("INSERT INTO products (branch_id, name, price, cost, quantity, low_stock_threshold, deleted_at) VALUES (1, 'P3-Del', 10, 5, 1, 5, '2025-01-01 00:00:00')");
$pdo->exec("INSERT INTO products (branch_id, name, price, cost, quantity, low_stock_threshold, deleted_at) VALUES (1, 'P4-Del', 10, 5, 50, 5, '2025-01-01 00:00:00')");

// Suppliers: 2 active, 2 deleted
$pdo->exec("INSERT INTO suppliers (branch_id, name, deleted_at) VALUES (1, 'S1', NULL)");
$pdo->exec("INSERT INTO suppliers (branch_id, name, deleted_at) VALUES (1, 'S2', NULL)");
$pdo->exec("INSERT INTO suppliers (branch_id, name, deleted_at) VALUES (1, 'S3-Del', '2025-01-01 00:00:00')");
$pdo->exec("INSERT INTO suppliers (branch_id, name, deleted_at) VALUES (1, 'S4-Del', '2025-01-01 00:00:00')");

$baseSoft = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
$optSoft  = $service->getDashboardSummary();
assertSummaryMatches($baseSoft, $optSoft, 'Case 11: Soft-deleted filtering');
verify($optSoft['total_products'] === 2, 'Case 11: Total products excludes deleted');
verify($optSoft['low_stock_count'] === 1, 'Case 11: Low stock count excludes deleted');
verify($optSoft['total_suppliers'] === 2, 'Case 11: Total suppliers excludes deleted');
echo "PASS (Soft-deleted records excluded from active counters)\n";

// -------------------------------------------------------------------------
// Test Case 12: Future Days in Current Month & Month-End Boundaries
// -------------------------------------------------------------------------
echo "[Case 12] Future Days in Current Month & Month-End Boundaries... ";
initializeFixtureDb($pdo);
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

// Previous month end boundary: 2025-01-31 23:59:59 (Excluded from Feb)
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 1000.0, 'completed', '2025-01-31 23:59:59')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 1, 1, 1000.0, 500.0, 1000.0)");

// Current month start boundary: 2025-02-01 00:00:00 (Included in Feb)
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 100.0, 'completed', '2025-02-01 00:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (2, 2, 1, 100.0, 40.0, 100.0)");

// Current day: 2025-02-15 12:00:00 (Included in today & Feb)
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 200.0, 'completed', '2025-02-15 12:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (3, 3, 1, 200.0, 80.0, 200.0)");

// Future day in current month: 2025-02-25 15:00:00 (Included in whole-month Feb, excluded from today)
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 300.0, 'completed', '2025-02-25 15:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (4, 4, 1, 300.0, 120.0, 300.0)");

// Month end boundary: 2025-02-28 23:59:59 (Included in Feb)
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 400.0, 'completed', '2025-02-28 23:59:59')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (5, 5, 1, 400.0, 160.0, 400.0)");

// Next month upper boundary: 2025-03-01 00:00:00 (Excluded from Feb: created_at < 2025-03-01 00:00:00)
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 5000.0, 'completed', '2025-03-01 00:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (6, 6, 1, 5000.0, 2500.0, 5000.0)");

$baseFuture = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
$optFuture  = $service->getDashboardSummary();
assertSummaryMatches($baseFuture, $optFuture, 'Case 12: Future days and boundaries');
verify($optFuture['today_revenue'] === 200.0, 'Case 12: today_revenue is 200.0');
verify($optFuture['month_revenue'] === 1000.0, 'Case 12: month_revenue is 100 + 200 + 300 + 400 = 1000.0');
verify($optFuture['today_cost'] === 80.0, 'Case 12: today_cost is 80.0');
verify($optFuture['month_cost'] === 400.0, 'Case 12: month_cost is 40 + 80 + 120 + 160 = 400.0');
echo "PASS (Whole month scan includes future days, strictly excludes prev & next month)\n";

// -------------------------------------------------------------------------
// Test Case 13: Fractional Prices/Quantities, Refund Semantics, NULL unit_cost
// -------------------------------------------------------------------------
echo "[Case 13] Fractional Values, Refund Semantics, & NULL unit_cost... ";
initializeFixtureDb($pdo);
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

// Fractional items
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 53.125, 'completed', '2025-02-15 10:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 1, 2.5, 21.25, 11.50, 53.125)");

// Refund item with negative quantity
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, -20.0, 'completed', '2025-02-15 11:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (2, 2, -1.0, 20.0, 8.0, -20.0)");

// Item with NULL historical unit_cost
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 40.0, 'completed', '2025-02-15 12:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (3, 3, 2.0, 20.0, NULL, 40.0)");

$baseEdge = runBaselineDashboardSummary($invoiceRepo, $expenseRepo, $productRepo, $supplierRepo, '2025-02-15');
$optEdge  = $service->getDashboardSummary();
assertSummaryMatches($baseEdge, $optEdge, 'Case 13: Edge numerical and NULL semantics');
verify(abs($optEdge['today_revenue'] - 73.125) < 0.0001, 'Case 13: Revenue accounts for refund');
verify(abs($optEdge['today_cost'] - (2.5 * 11.50 - 8.0)) < 0.0001, 'Case 13: Cost with refund and NULL ignored');
echo "PASS (Fractional, refund negative values, and NULL unit_cost preserve exact SQL semantics)\n";

// -------------------------------------------------------------------------
// Test Case 14: Dynamic Follow-Up Writes Reflected Immediately (No Caching)
// -------------------------------------------------------------------------
echo "[Case 14] Dynamic Follow-up Writes Reflected Immediately (No Caching)... ";
initializeFixtureDb($pdo);
$pdo->setDbToday('2025-02-15');
$service->setTestDate('2025-02-15');

$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 100.0, 'completed', '2025-02-15 10:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (1, 1, 1.0, 100.0, 40.0, 100.0)");

$first = $service->getDashboardSummary();
verify($first['today_revenue'] === 100.0, 'Case 14: Initial revenue 100.0');

// Follow-up insert immediately after
$pdo->exec("INSERT INTO invoices (branch_id, total, status, created_at) VALUES (1, 150.0, 'completed', '2025-02-15 11:00:00')");
$pdo->exec("INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal) VALUES (2, 2, 1.0, 150.0, 60.0, 150.0)");

$second = $service->getDashboardSummary();
verify($second['today_revenue'] === 250.0, 'Case 14: Immediate second invocation reflects follow-up write');
verify($second['today_invoices'] === 2, 'Case 14: Second invocation reflects 2 invoices');
verify($second['today_cost'] === 100.0, 'Case 14: Second invocation reflects cost 100.0');
echo "PASS (Zero caching risk: follow-up database writes reflected immediately)\n";

// -------------------------------------------------------------------------
// Test Case 15: Index Range Predicate Demonstration (SQLite EXPLAIN QUERY PLAN)
// -------------------------------------------------------------------------
echo "[Case 15] Index Range Predicate Demonstration (EXPLAIN QUERY PLAN)... ";
$stmt = $pdo->query("
    EXPLAIN QUERY PLAN
    SELECT
        COALESCE(SUM(CASE WHEN created_at >= '2025-02-15 00:00:00' AND created_at < '2025-02-16 00:00:00' THEN total ELSE 0 END), 0) AS today_revenue,
        COALESCE(SUM(total), 0) AS month_revenue,
        COALESCE(SUM(CASE WHEN created_at >= '2025-02-15 00:00:00' AND created_at < '2025-02-16 00:00:00' THEN 1 ELSE 0 END), 0) AS today_invoices
    FROM invoices
    WHERE branch_id = 1
      AND status = 'completed'
      AND created_at >= '2025-02-01 00:00:00'
      AND created_at < '2025-03-01 00:00:00'
");
$planLines = [];
while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    $planLines[] = $row['detail'] ?? '';
}
$planText = implode("\n", $planLines);
verify(str_contains($planText, 'idx_invoices_branch_status_created'), "Case 15: EXPLAIN QUERY PLAN must utilize composite index");
echo "PASS (Range predicates utilize composite index: {$planLines[0]})\n";

// -------------------------------------------------------------------------
// FINAL SUMMARY
// -------------------------------------------------------------------------
echo "\n====================================================================\n";
echo " ALL 15 VERIFICATION TEST SUITES PASSED SUCCESSFULLY!\n";
echo " Executed SQL Queries: Baseline = 12, Optimized = 6 (50% Reduction)\n";
echo " Accounting Integrity: 100% matched across all dimensions\n";
echo "====================================================================\n";
