<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class RestoreSchemaConvergenceService
{
    // Provenance: GHA 40801685426, head ca75504e21ba72bfcc728c617e0677644ea39db1, format 1 synthetic_legacy025_native_reference
    public const LEGACY_SCHEMA_SHA256 = 'b5e6ffbe01960450555be6d80036552c6c0337a126afe87549fd5e7e136205bc';
    public const TARGET_SCHEMA_SHA256 = '43a71b5f0c7f4500ad1f353ba462c39bb2fafa51607000248ad815467e02b222';
    public const EXPECTED_LEDGER_SHA256 = '3c2520db3036230a293c175d61c936ad64d88c0677a04870ab30353ec2c42ecd';

    private PDO $db;
    private UpdateDataCompatibilityService $compatService;

    public function __construct(PDO $db, ?UpdateDataCompatibilityService $compatService = null)
    {
        $this->db = $db;
        $this->compatService = $compatService ?? new UpdateDataCompatibilityService();
    }

    /**
     * Converge a legacy database schema post-migration 059 to the exact
     * signed fresh baseline contract. Idempotent no-op if already at target.
     *
     * @param callable(): void $migrationCallback Fixed callback executing the convergence DDL
     * @return array{converged: bool, already_target?: bool, schema_sha256: string, ledger_sha256: string}
     */
    public function converge(callable $migrationCallback): array
    {
        if (
            !$this->is64LowerHex(self::LEGACY_SCHEMA_SHA256) ||
            !$this->is64LowerHex(self::TARGET_SCHEMA_SHA256) ||
            !$this->is64LowerHex(self::EXPECTED_LEDGER_SHA256)
        ) {
            throw new RuntimeException('Restore convergence schema fingerprints are unconfigured or invalid');
        }

        $description = $this->compatService->describe($this->db);
        $currentSchemaSha = (string)($description['schema_sha256'] ?? '');
        $currentLedgerSha = (string)($description['ledger_sha256'] ?? '');

        // 1. Idempotent no-op if already exact target
        if (
            hash_equals(self::TARGET_SCHEMA_SHA256, $currentSchemaSha) &&
            hash_equals(self::EXPECTED_LEDGER_SHA256, $currentLedgerSha)
        ) {
            return [
                'converged' => false,
                'already_target' => true,
                'schema_sha256' => $currentSchemaSha,
                'ledger_sha256' => $currentLedgerSha,
            ];
        }

        // 2. Strict baseline verification: fail closed if not exact known legacy
        if (
            !hash_equals(self::LEGACY_SCHEMA_SHA256, $currentSchemaSha) ||
            !hash_equals(self::EXPECTED_LEDGER_SHA256, $currentLedgerSha)
        ) {
            throw new RuntimeException('Database schema does not match known legacy restore baseline or target contract');
        }

        // 3. Bounded preflight before ANY convergence DDL
        $this->validatePreflight();

        // 4. Execute fixed convergence migration via MigrationService runner
        $migrationCallback();

        // 5. Post-DDL strict verification against target contract
        $postDescription = $this->compatService->describe($this->db);
        $postSchemaSha = (string)($postDescription['schema_sha256'] ?? '');
        $postLedgerSha = (string)($postDescription['ledger_sha256'] ?? '');

        if (
            !hash_equals(self::TARGET_SCHEMA_SHA256, $postSchemaSha) ||
            !hash_equals(self::EXPECTED_LEDGER_SHA256, $postLedgerSha)
        ) {
            throw new RuntimeException('Post-convergence database schema or ledger does not match target contract');
        }

        return [
            'converged' => true,
            'schema_sha256' => $postSchemaSha,
            'ledger_sha256' => $postLedgerSha,
        ];
    }

    /**
     * Read-only bounded preflight queries verifying that existing data
     * will safely satisfy DECIMAL(10,3) bounds and foreign key integrity.
     */
    private function validatePreflight(): void
    {
        // 1. Check products.quantity bounds [-9999999.999, 9999999.999]
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM products WHERE quantity IS NOT NULL AND (quantity < -9999999.999 OR quantity > 9999999.999)"
        );
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Products quantity value out of decimal(10,3) bounds');
        }

        // 2. Check invoice_items.quantity bounds [-9999999.999, 9999999.999]
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM invoice_items WHERE quantity IS NOT NULL AND (quantity < -9999999.999 OR quantity > 9999999.999)"
        );
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Invoice items quantity value out of decimal(10,3) bounds');
        }

        // 3. Check purchases.quantity bounds [-9999999.999, 9999999.999]
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM purchases WHERE quantity IS NOT NULL AND (quantity < -9999999.999 OR quantity > 9999999.999)"
        );
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Purchases quantity value out of decimal(10,3) bounds');
        }

        // 4. Check orphan non-null invoices.customer_id against customers
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM invoices i WHERE i.customer_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM customers c WHERE c.id = i.customer_id)"
        );
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Orphan invoices customer_id reference detected');
        }
    }

    private function is64LowerHex(string $val): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $val) === 1;
    }
}
