<?php

namespace App\Models;

use App\Config\Database;
use App\Services\AuthService;
use PDO;


class Customer {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /** جميع العملاء مع رصيدهم الحالي — مع دعم pagination اختياري */
    public function all(array $filters = []): array {
        $where  = ['c.deleted_at IS NULL', 'c.branch_id = :branch_id'];
        $params = ['branch_id' => AuthService::getGlobalBranchId()];

        if (!empty($filters['search'])) {
            // Native PDO prepares do not allow the same named placeholder to be
            // reused in a statement. Keep one binding per searched column.
            $search = trim((string) $filters['search']) . '%';
            $where[] = '(c.name LIKE :search_name OR c.phone LIKE :search_phone)';
            $params['search_name'] = $search;
            $params['search_phone'] = $search;
        }

        $whereClause = implode(' AND ', $where);

        // ── Pagination الإجباري ──
        $page  = isset($filters['page'])  ? max(1, (int) $filters['page'])  : 1;
        $limit = isset($filters['limit']) ? max(1, min(100, (int) $filters['limit'])) : 100;

        $countSql = "SELECT COUNT(*) FROM customers c WHERE $whereClause";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $sql = "WITH filtered AS (
            SELECT c.*
            FROM customers c
            WHERE $whereClause
            ORDER BY c.name ASC, c.id ASC
            LIMIT :pag_limit OFFSET :pag_offset
        ), balances AS (
            SELECT cl.branch_id, cl.customer_id,
                   COALESCE(SUM(CASE WHEN cl.type = 'debit' THEN cl.amount ELSE 0 END), 0) AS total_debit,
                   COALESCE(SUM(CASE WHEN cl.type = 'credit' THEN cl.amount ELSE 0 END), 0) AS total_credit
            FROM customer_ledger cl
            INNER JOIN filtered f
                ON f.branch_id = cl.branch_id AND f.id = cl.customer_id
            GROUP BY cl.branch_id, cl.customer_id
        )
        SELECT f.*,
               COALESCE(b.total_debit, 0) AS total_debit,
               COALESCE(b.total_credit, 0) AS total_credit
        FROM filtered f
        LEFT JOIN balances b
            ON b.branch_id = f.branch_id AND b.customer_id = f.id
        ORDER BY f.name ASC, f.id ASC";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['balance'] = round(
                (float)$r['initial_balance'] + (float)$r['total_debit'] - (float)$r['total_credit'],
                2
            );
        }
        unset($r);

        return [
            'data' => $rows,
            'pagination' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];

    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT c.*,
                COALESCE(SUM(CASE WHEN cl.type = "debit"  THEN cl.amount ELSE 0 END), 0) AS total_debit,
                COALESCE(SUM(CASE WHEN cl.type = "credit" THEN cl.amount ELSE 0 END), 0) AS total_credit
             FROM customers c
             LEFT JOIN customer_ledger cl ON cl.customer_id = c.id AND cl.branch_id = c.branch_id
             WHERE c.id = ? AND c.branch_id = ? AND c.deleted_at IS NULL
             GROUP BY c.id'
        );
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['balance'] = round(
            (float)$row['initial_balance'] + (float)$row['total_debit'] - (float)$row['total_credit'],
            2
        );
        return $row;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO customers (branch_id, name, phone, address, initial_balance)
             VALUES (:branch_id, :name, :phone, :address, :initial_balance)'
        );
        $stmt->execute([
            'branch_id'       => AuthService::getGlobalBranchId(),
            'name'            => $data['name'],
            'phone'           => $data['phone'] ?? null,
            'address'         => $data['address'] ?? null,
            'initial_balance' => (float)($data['initial_balance'] ?? 0),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE customers SET
                name = :name,
                phone = :phone,
                address = :address,
                initial_balance = :initial_balance
             WHERE id = :id AND branch_id = :branch_id'
        );
        $stmt->execute([
            'name'            => $data['name'],
            'phone'           => $data['phone'] ?? null,
            'address'         => $data['address'] ?? null,
            'initial_balance' => (float)($data['initial_balance'] ?? 0),
            'id'              => $id,
            'branch_id'       => AuthService::getGlobalBranchId(),
        ]);
    }

    public function delete(int $id): void {
        $this->db->prepare('UPDATE customers SET deleted_at = NOW() WHERE id = ? AND branch_id = ?')
            ->execute([$id, AuthService::getGlobalBranchId()]);
    }

    /**
     * كشف حساب العميل — مع الرصيد المتراكم لكل سطر
     * @return array{entries: list<array>, balance: float}
     */
    public function getLedger(int $customerId): array {
        $customer = $this->findById($customerId);
        if (!$customer) return ['entries' => [], 'balance' => 0];

        $limit = 500;
        $summaryStmt = $this->db->prepare(
            'SELECT COUNT(*) AS total_entries,
                    COALESCE(SUM(CASE WHEN type = "debit" THEN amount ELSE -amount END), 0) AS net_change
             FROM customer_ledger
             WHERE customer_id = ? AND branch_id = ?'
        );
        $summaryStmt->execute([$customerId, AuthService::getGlobalBranchId()]);
        $summary = $summaryStmt->fetch() ?: ['total_entries' => 0, 'net_change' => 0];
        $totalEntries = (int) $summary['total_entries'];

        // Load only the latest bounded window, then restore chronological order.
        $stmt = $this->db->prepare(
            'SELECT recent.* FROM (
                SELECT cl.*,
                u.name AS created_by_name,
                i.id AS inv_id
                FROM customer_ledger cl
                LEFT JOIN users u ON u.id = cl.created_by
                LEFT JOIN invoices i ON i.id = cl.invoice_id
                WHERE cl.customer_id = ? AND cl.branch_id = ?
                ORDER BY cl.created_at DESC, cl.id DESC
                LIMIT 500
             ) recent
             ORDER BY recent.created_at ASC, recent.id ASC'
        );
        $stmt->execute([$customerId, AuthService::getGlobalBranchId()]);
        $rows = $stmt->fetchAll();

        $entries    = [];
        $initBal = (float)$customer['initial_balance'];
        $recentChange = 0.0;
        foreach ($rows as $row) {
            $recentChange += $row['type'] === 'debit' ? (float) $row['amount'] : -(float) $row['amount'];
        }
        $totalBalance = $initBal + (float) $summary['net_change'];
        $runningBal = $totalBalance - $recentChange;

        if ($totalEntries > $limit) {
            $entries[] = [
                'id' => null,
                'date' => $rows[0]['created_at'] ?? $customer['created_at'],
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
                'date'        => $customer['created_at'],
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
                'id'          => (int)$row['id'],
                'date'        => $row['created_at'],
                'description' => $row['description'],
                'debit'       => $debit,
                'credit'      => $credit,
                'balance'     => round($runningBal, 2),
                'type'        => $row['type'],
                'invoice_id'  => $row['invoice_id'],
            ];
        }

        return [
            'customer' => $customer,
            'entries'  => $entries,
            'balance'  => round($totalBalance, 2),
            'total_entries' => $totalEntries,
            'truncated' => $totalEntries > $limit,
        ];
    }

    /** إضافة قيد في كشف الحساب */
    public function addLedgerEntry(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO customer_ledger (branch_id, customer_id, type, amount, description, invoice_id, created_by)
             SELECT c.branch_id, c.id, :type, :amount, :description, :invoice_id, :created_by
             FROM customers c
             WHERE c.id = :customer_id
               AND c.branch_id = :branch_id
               AND c.deleted_at IS NULL
               AND (
                   :invoice_scope_id IS NULL
                   OR EXISTS (
                       SELECT 1 FROM invoices i
                       WHERE i.id = :invoice_scope_id_match
                         AND i.branch_id = c.branch_id
                         AND (i.customer_id IS NULL OR i.customer_id = c.id)
                   )
               )'
        );
        $stmt->execute([
            'customer_id' => $data['customer_id'],
            'branch_id'   => AuthService::getGlobalBranchId(),
            'type'        => $data['type'],
            'amount'      => (float)$data['amount'],
            'description' => $data['description'] ?? null,
            'invoice_id'  => $data['invoice_id'] ?? null,
            'invoice_scope_id' => $data['invoice_id'] ?? null,
            'invoice_scope_id_match' => $data['invoice_id'] ?? null,
            'created_by'  => $data['created_by'] ?? null,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Customer or invoice is outside the active branch.');
        }
        return (int) $this->db->lastInsertId();
    }

    /** تعديل قيد في كشف الحساب */
    public function updateLedgerEntry(int $entryId, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE customer_ledger SET
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
        $stmt = $this->db->prepare('SELECT * FROM customer_ledger WHERE id = ? AND branch_id = ?');
        $stmt->execute([$entryId, AuthService::getGlobalBranchId()]);
        return $stmt->fetch() ?: null;
    }

    /** حذف قيد من كشف الحساب */
    public function deleteLedgerEntry(int $entryId): void {
        $stmt = $this->db->prepare('DELETE FROM customer_ledger WHERE id = ? AND branch_id = ?');
        $stmt->execute([$entryId, AuthService::getGlobalBranchId()]);
    }
}
