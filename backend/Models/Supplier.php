<?php

namespace App\Models;

use App\Config\Database;
use App\Services\AuthService;
use PDO;


class Supplier {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /** جميع الموردين مع رصيدهم — مع دعم pagination اختياري */
    public function all(array $filters = []): array {
        $where  = ['s.deleted_at IS NULL', 's.branch_id = :branch_id'];
        $params = ['branch_id' => AuthService::getGlobalBranchId()];

        if (!empty($filters['search'])) {
            // Native PDO prepares require a unique named placeholder for each
            // occurrence, even when every column uses the same search value.
            $search = trim((string) $filters['search']) . '%';
            $where[] = '(s.name LIKE :search_name OR s.phone LIKE :search_phone OR s.email LIKE :search_email)';
            $params['search_name'] = $search;
            $params['search_phone'] = $search;
            $params['search_email'] = $search;
        }

        $whereClause = implode(' AND ', $where);

        // ── Pagination الإجباري ──
        $page  = isset($filters['page'])  ? max(1, (int) $filters['page'])  : 1;
        $limit = isset($filters['limit']) ? max(1, min(100, (int) $filters['limit'])) : 100;

        $countSql = "SELECT COUNT(*) FROM suppliers s WHERE $whereClause";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $sql = "WITH filtered AS (
            SELECT s.*
            FROM suppliers s
            WHERE $whereClause
            ORDER BY s.name ASC, s.id ASC
            LIMIT :pag_limit OFFSET :pag_offset
        ), balances AS (
            SELECT sl.branch_id, sl.supplier_id,
                   COALESCE(SUM(CASE WHEN sl.type = 'debit' THEN sl.amount ELSE 0 END), 0) AS total_debit,
                   COALESCE(SUM(CASE WHEN sl.type = 'credit' THEN sl.amount ELSE 0 END), 0) AS total_credit
            FROM supplier_ledger sl
            INNER JOIN filtered f
                ON f.branch_id = sl.branch_id AND f.id = sl.supplier_id
            GROUP BY sl.branch_id, sl.supplier_id
        )
        SELECT f.*,
               COALESCE(b.total_debit, 0) AS total_debit,
               COALESCE(b.total_credit, 0) AS total_credit
        FROM filtered f
        LEFT JOIN balances b
            ON b.branch_id = f.branch_id AND b.supplier_id = f.id
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
                (float)($r['initial_balance'] ?? 0) + (float)$r['total_debit'] - (float)$r['total_credit'],
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
            'SELECT s.*,
                COALESCE(SUM(CASE WHEN sl.type = "debit"  THEN sl.amount ELSE 0 END), 0) AS total_debit,
                COALESCE(SUM(CASE WHEN sl.type = "credit" THEN sl.amount ELSE 0 END), 0) AS total_credit
             FROM suppliers s
             LEFT JOIN supplier_ledger sl ON sl.supplier_id = s.id AND sl.branch_id = s.branch_id
             WHERE s.id = ? AND s.branch_id = ? AND s.deleted_at IS NULL
             GROUP BY s.id'
        );
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['balance'] = round(
            (float)($row['initial_balance'] ?? 0) + (float)$row['total_debit'] - (float)$row['total_credit'],
            2
        );
        return $row;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO suppliers (branch_id, name, phone, email, address, initial_balance)
             VALUES (:branch_id, :name, :phone, :email, :address, :initial_balance)'
        );
        $stmt->execute([
            'branch_id'       => AuthService::getGlobalBranchId(),
            'name'            => $data['name'],
            'phone'           => $data['phone'] ?? null,
            'email'           => $data['email'] ?? null,
            'address'         => $data['address'] ?? null,
            'initial_balance' => (float)($data['initial_balance'] ?? 0),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE suppliers
             SET name = :name, phone = :phone, email = :email, address = :address, initial_balance = :initial_balance
             WHERE id = :id AND branch_id = :branch_id'
        );
        $stmt->execute([
            'name'            => $data['name'],
            'phone'           => $data['phone'] ?? null,
            'email'           => $data['email'] ?? null,
            'address'         => $data['address'] ?? null,
            'initial_balance' => (float)($data['initial_balance'] ?? 0),
            'id'              => $id,
            'branch_id'       => AuthService::getGlobalBranchId(),
        ]);
    }

    public function delete(int $id): void {
        $this->db->prepare('UPDATE suppliers SET deleted_at = NOW() WHERE id = ? AND branch_id = ?')
            ->execute([$id, AuthService::getGlobalBranchId()]);
    }



    public function getTotalSuppliersCount(): int {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM suppliers WHERE branch_id = ? AND deleted_at IS NULL');
        $stmt->execute([AuthService::getGlobalBranchId()]);
        return (int) $stmt->fetchColumn();
    }
}
