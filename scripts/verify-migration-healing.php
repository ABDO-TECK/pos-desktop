<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\MigrationService;
use App\Services\UpdateDataCompatibilityService;

// Test with SQLite in-memory PDO to verify the reconciliation logic isolated from live DB
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Set up schema_versions table in sqlite
$pdo->exec('CREATE TABLE schema_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, version TEXT NOT NULL, executed_at DATETIME)');

// Insert test cases:
// 1. Normal canonical: 002_update.sql
// 2. Duplicate canonical: 002_update.sql (duplicate)
// 3. Extensionless with canonical present: 016_add_tokens and 016_add_tokens.sql
// 4. Extensionless without canonical present: 017_new_feature
// 5. Empty version: ""
$pdo->exec("INSERT INTO schema_versions (version, executed_at) VALUES ('002_update.sql', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO schema_versions (version, executed_at) VALUES ('002_update.sql', '2026-01-02 00:00:00')");
$pdo->exec("INSERT INTO schema_versions (version, executed_at) VALUES ('016_add_tokens', '2026-05-20 21:58:41')");
$pdo->exec("INSERT INTO schema_versions (version, executed_at) VALUES ('016_add_tokens.sql', '2026-05-20 21:59:00')");
$pdo->exec("INSERT INTO schema_versions (version, executed_at) VALUES ('017_new_feature', '2026-06-01 00:00:00')");
$pdo->exec("INSERT INTO schema_versions (version, executed_at) VALUES ('', '2026-06-01 00:00:00')");

// Also create legacy table 'migrations' to test its cleanup
$pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec("INSERT INTO migrations (name) VALUES ('legacy_migration')");

$service = new MigrationService($pdo);
$errors = $service->reconcileMigrationLedger();

if (!empty($errors)) {
    echo "FAILED: reconciliation returned errors: " . implode('; ', $errors) . "\n";
    exit(1);
}

$remaining = $pdo->query('SELECT version FROM schema_versions ORDER BY version ASC')->fetchAll(PDO::FETCH_COLUMN);
$expected = ['002_update.sql', '016_add_tokens.sql', '017_new_feature.sql'];

if ($remaining !== $expected) {
    echo "FAILED: Expected " . json_encode($expected) . ", got " . json_encode($remaining) . "\n";
    exit(1);
}

// Verify legacy table 'migrations' was dropped
$legacyExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'")->fetchColumn();
if ($legacyExists) {
    echo "FAILED: Legacy table 'migrations' was not dropped\n";
    exit(1);
}

echo "PASS MigrationService::reconcileMigrationLedger() successfully self-healed, normalized and cleaned legacy tables.\n";

// Now test UpdateDataCompatibilityService normalization
$mockDb = new class extends PDO {
    public function __construct() { parent::__construct('sqlite::memory:'); }
    public function getAttribute($attr): mixed {
        if ($attr === PDO::ATTR_DRIVER_NAME) return 'mysql';
        return parent::getAttribute($attr);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (str_contains($query, 'information_schema.TABLES')) {
            $stmt = parent::query('SELECT "users" as name, "BASE TABLE" as type, "InnoDB" as engine, "utf8mb4" as collation');
            return $stmt;
        }
        if (str_contains($query, 'information_schema.COLUMNS')) {
            return parent::query('SELECT "users" as table_name, "id" as name, 1 as position, "int" as type, "NO" as nullable, "" as default_value, "" as extra, "utf8mb4" as charset_name, "utf8mb4_unicode_ci" as collation');
        }
        if (str_contains($query, 'information_schema.STATISTICS') || str_contains($query, 'information_schema.KEY_COLUMN_USAGE') || str_contains($query, 'information_schema.REFERENTIAL_CONSTRAINTS') || str_contains($query, 'information_schema.CHECK_CONSTRAINTS')) {
            return parent::query('SELECT 1 WHERE 0');
        }
        if (str_contains($query, 'product_catalog_trigger_metadata')) {
            return parent::query('SELECT "trigger" as object_type, "trg" as object_name, "def" as definition, "ctx" as context, "users" as table_name');
        }
        if (str_contains($query, 'schema_versions')) {
            // Return rows with a mix of extensionless, duplicate, and empty
            return parent::query('
                SELECT "001_initial.sql" as version UNION ALL
                SELECT "001_initial.sql" as version UNION ALL
                SELECT "002_feature" as version UNION ALL
                SELECT "" as version
            ');
        }
        return parent::query($query);
    }
};

$compat = new UpdateDataCompatibilityService();
$desc = $compat->describe($mockDb);

if (!isset($desc['schema_sha256']) || !isset($desc['ledger_sha256'])) {
    echo "FAILED: describe() did not return expected hashes\n";
    exit(1);
}

$expectedLedger = ['001_initial.sql', '002_feature.sql'];
if ($desc['ledger'] !== $expectedLedger) {
    echo "FAILED: describe() ledger normalization. Expected " . json_encode($expectedLedger) . ", got " . json_encode($desc['ledger']) . "\n";
    exit(1);
}

echo "PASS UpdateDataCompatibilityService::describe() successfully normalizes and deduplicates without crashing.\n";
exit(0);
