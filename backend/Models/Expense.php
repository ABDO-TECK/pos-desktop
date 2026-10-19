<?php

namespace App\Models;

use App\Config\Database;
use PDO;
use App\Services\AuthService;


class Expense
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getAll(array $filters = []): array
    {
        $where = ['e.branch_id = :branch_id'];
        $params = ['branch_id' => AuthService::getGlobalBranchId()];

        if (!empty($filters['date'])) {
            $where[] = 'e.expense_date >= :date_start AND e.expense_date < :date_end';
            $params['date_start'] = $filters['date'] . ' 00:00:00';
            $params['date_end'] = date('Y-m-d 00:00:00', strtotime($filters['date'] . ' +1 day'));
        }
        if (!empty($filters['month']) && !empty($filters['year'])) {
            $monthStart = sprintf('%04d-%02d-01 00:00:00', (int) $filters['year'], (int) $filters['month']);
            $where[] = 'e.expense_date >= :month_start AND e.expense_date < :month_end';
            $params['month_start'] = $monthStart;
            $params['month_end'] = date('Y-m-d 00:00:00', strtotime($monthStart . ' +1 month'));
        }
        if (!empty($filters['category_id'])) {
            $where[] = "e.category_id = :category_id";
            $params['category_id'] = $filters['category_id'];
        }

        $whereClause = implode(' AND ', $where);

        $page  = isset($filters['page'])  ? max(1, (int) $filters['page'])  : 1;
        $limit = isset($filters['limit']) ? max(1, min(100, (int) $filters['limit'])) : 100;

        $countSql = "SELECT COUNT(*) FROM expenses e WHERE $whereClause";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;

        $sql = "SELECT e.*, c.name as category_name, u.name as user_name 
                FROM expenses e 
                JOIN expense_categories c ON e.category_id = c.id 
                JOIN users u ON e.user_id = u.id 
                WHERE $whereClause
                ORDER BY e.expense_date DESC, e.id DESC
                LIMIT :pag_limit OFFSET :pag_offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

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

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT e.*, c.name as category_name, u.name as user_name 
                                    FROM expenses e 
                                    JOIN expense_categories c ON e.category_id = c.id 
                                    JOIN users u ON e.user_id = u.id 
                                    WHERE e.id = ? AND e.branch_id = ?");
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO expenses (branch_id, category_id, user_id, amount, notes, expense_date)
            VALUES (:branch_id, :category_id, :user_id, :amount, :notes, :expense_date)
        ');
        $stmt->execute([
            'branch_id'    => AuthService::getGlobalBranchId(),
            'category_id'  => $data['category_id'],
            'user_id'      => $data['user_id'],
            'amount'       => $data['amount'],
            'notes'        => $data['notes'] ?? null,
            'expense_date' => $data['expense_date']
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare('
            UPDATE expenses SET 
                category_id = :category_id,
                amount = :amount,
                notes = :notes,
                expense_date = :expense_date
            WHERE id = :id AND branch_id = :branch_id
        ');
        $stmt->execute([
            'category_id'  => $data['category_id'],
            'amount'       => $data['amount'],
            'notes'        => $data['notes'] ?? null,
            'expense_date' => $data['expense_date'],
            'id'           => $id,
            'branch_id'    => AuthService::getGlobalBranchId(),
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM expenses WHERE id = ? AND branch_id = ?');
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);
    }

    public function getTotalExpensesForDate(string $date): float
    {
        $stmt = $this->db->prepare(
            'SELECT SUM(amount) FROM expenses
             WHERE branch_id = ? AND expense_date >= ? AND expense_date < ?'
        );
        $stmt->execute([
            AuthService::getGlobalBranchId(),
            $date . ' 00:00:00',
            date('Y-m-d 00:00:00', strtotime($date . ' +1 day')),
        ]);
        return (float) $stmt->fetchColumn();
    }

    public function getTotalExpensesForMonth(int $month, int $year): float
    {
        $monthStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $stmt = $this->db->prepare(
            'SELECT SUM(amount) FROM expenses
             WHERE branch_id = ? AND expense_date >= ? AND expense_date < ?'
        );
        $stmt->execute([
            AuthService::getGlobalBranchId(),
            $monthStart,
            date('Y-m-d 00:00:00', strtotime($monthStart . ' +1 month')),
        ]);
        return (float) $stmt->fetchColumn();
    }
}
