<?php

namespace App\Models;

use App\Services\AuthService;
use PDO;

class SupplierLedger {
    private PDO $db;
    private Supplier $supplierModel;

    public function __construct(PDO $db, Supplier $supplierModel) {
        $this->db = $db;
        $this->supplierModel = $supplierModel;
    }

    /**
     * كشف حساب المورد — مع الرصيد المتراكم لكل سطر
     * @return array{supplier: ?array, entries: list<array>, balance: float}
     */
    public function getLedger(int $supplierId): array {
        $supplier = $this->supplierModel->findById($supplierId);
        if (!$supplier) return ['supplier' => null, 'entries' => [], 'balance' => 0];

        $limit = 500;
        $summaryStmt = $this->db->prepare(
            'SELECT COUNT(*) AS total_entries,
                    COALESCE(SUM(CASE WHEN type = "debit" THEN amount ELSE -amount END), 0) AS net_change
             FROM supplier_ledger
             WHERE supplier_id = ? AND branch_id = ?'
        );
        $summaryStmt->execute([$supplierId, AuthService::getGlobalBranchId()]);
        $summary = $summaryStmt->fetch() ?: ['total_entries' => 0, 'net_change' => 0];
        $totalEntries = (int) $summary['total_entries'];

        $stmt = $this->db->prepare(
            'SELECT recent.* FROM (
                SELECT sl.*,
                u.name AS created_by_name,
                pi.id AS pinv_id
                FROM supplier_ledger sl
                LEFT JOIN users u ON u.id = sl.created_by
                LEFT JOIN purchase_invoices pi ON pi.id = sl.purchase_invoice_id
                WHERE sl.supplier_id = ? AND sl.branch_id = ?
                ORDER BY sl.created_at DESC, sl.id DESC
                LIMIT 500
             ) recent
             ORDER BY recent.created_at ASC, recent.id ASC'
        );
        $stmt->execute([$supplierId, AuthService::getGlobalBranchId()]);
        $rows = $stmt->fetchAll();

        $entries    = [];
        $initBal = (float)($supplier['initial_balance'] ?? 0);
        $recentChange = 0.0;
        foreach ($rows as $row) {
            $recentChange += $row['type'] === 'debit' ? (float) $row['amount'] : -(float) $row['amount'];
        }
        $totalBalance = $initBal + (float) $summary['net_change'];
        $runningBal = $totalBalance - $recentChange;

        if ($totalEntries > $limit) {
            $entries[] = [
                'id' => null,
                'date' => $rows[0]['created_at'] ?? $supplier['created_at'],
                'description' => 'رصيد افتتاحي قبل أحدث 500 قيد',
                'debit' => $runningBal > 0 ? $runningBal : 0,
                'credit' => $runningBal < 0 ? abs($runningBal) : 0,
                'balance' => round($runningBal, 2),
                'type' => 'opening',
            ];
        } elseif ($initBal != 0) {
            $runningBal = $initBal;
            $entries[] = [
                'id'          => null,
                'date'        => $supplier['created_at'],
                'description' => 'رصيد مبدئي',
                'debit'       => $initBal > 0 ? $initBal : 0,
                'credit'      => $initBal < 0 ? abs($initBal) : 0,
                'balance'     => round($runningBal, 2),
                'type'        => 'initial',
            ];
        }

        foreach ($rows as $row) {
            $debit  = $row['type'] === 'debit'  ? (float)$row['amount'] : 0;
            $credit = $row['type'] === 'credit' ? (float)$row['amount'] : 0;
            $runningBal += $debit - $credit;

            $entries[] = [
                'id'                  => (int)$row['id'],
                'date'                => $row['created_at'],
                'description'         => $row['description'],
                'debit'               => $debit,
                'credit'              => $credit,
                'balance'             => round($runningBal, 2),
                'type'                => $row['type'],
                'purchase_invoice_id' => $row['purchase_invoice_id'],
            ];
        }

        return [
            'supplier' => $supplier,
            'entries'  => $entries,
            'balance'  => round($totalBalance, 2),
            'total_entries' => $totalEntries,
            'truncated' => $totalEntries > $limit,
        ];
    }

    /** إضافة قيد في كشف حساب المورد */
    public function addLedgerEntry(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO supplier_ledger (branch_id, supplier_id, type, amount, description, purchase_invoice_id, created_by)
             SELECT s.branch_id, s.id, :type, :amount, :description, :purchase_invoice_id, :created_by
             FROM suppliers s
             WHERE s.id = :supplier_id
               AND s.branch_id = :branch_id
               AND s.deleted_at IS NULL
               AND (
                   :purchase_invoice_scope_id IS NULL
                   OR EXISTS (
                       SELECT 1 FROM purchase_invoices pi
                       WHERE pi.id = :purchase_invoice_scope_id_match
                         AND pi.branch_id = s.branch_id
                         AND pi.supplier_id = s.id
                   )
               )'
        );
        $stmt->execute([
            'supplier_id'         => $data['supplier_id'],
            'branch_id'           => AuthService::getGlobalBranchId(),
            'type'                => $data['type'],
            'amount'              => (float)$data['amount'],
            'description'         => $data['description'] ?? null,
            'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
            'purchase_invoice_scope_id' => $data['purchase_invoice_id'] ?? null,
            'purchase_invoice_scope_id_match' => $data['purchase_invoice_id'] ?? null,
            'created_by'          => $data['created_by'] ?? null,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Supplier or purchase invoice is outside the active branch.');
        }
        return (int) $this->db->lastInsertId();
    }

    /** تعديل قيد في كشف الحساب */
    public function updateLedgerEntry(int $entryId, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE supplier_ledger SET
                type = :type,
                amount = :amount,
                description = :description
             WHERE id = :id AND branch_id = :branch_id'
        );
        $stmt->execute([
            'type'        => $data['type'],
            'amount'      => (float)$data['amount'],
            'description' => $data['description'] ?? null,
            'id'          => $entryId,
            'branch_id'   => AuthService::getGlobalBranchId(),
        ]);
    }

    /** الحصول على قيد واحد */
    public function getLedgerEntry(int $entryId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM supplier_ledger WHERE id = ? AND branch_id = ?');
        $stmt->execute([$entryId, AuthService::getGlobalBranchId()]);
        return $stmt->fetch() ?: null;
    }

    /** حذف قيد من كشف الحساب */
    public function deleteLedgerEntry(int $entryId): void {
        $stmt = $this->db->prepare('DELETE FROM supplier_ledger WHERE id = ? AND branch_id = ?');
        $stmt->execute([$entryId, AuthService::getGlobalBranchId()]);
    }
}
