<?php

namespace App\Models;

use App\Config\Database;
use PDO;


class Category {

    protected string $table = 'categories';
    protected PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function all(array $filters = []): array {
        $sql = "SELECT * FROM {$this->table} ORDER BY name ASC";
        
        // التحقق مما إذا كان هناك ترقيم (Pagination) مطلوب
        if (!empty($filters['page']) && !empty($filters['limit'])) {
            $page  = max(1, (int)$filters['page']);
            $limit = max(1, min(100, (int)$filters['limit']));
            $offset = ($page - 1) * $limit;
            
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table}");
            $countStmt->execute();
            $total = $countStmt->fetchColumn();
            
            $sql .= " LIMIT :pag_limit OFFSET :pag_offset";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetchAll();
            
            return [
                'data'       => $data,
                'pagination' => [
                    'page'  => $page,
                    'limit' => $limit,
                    'total' => (int) $total,
                    'pages' => (int) ceil($total / $limit),
                ]
            ];
        }

        // إرجاع كل البيانات بدون صفحات
        $sql .= ' LIMIT 100';
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $data = $stmt->fetchAll();
        return ['data' => $data];
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO {$this->table} (name) VALUES (?)");
        $stmt->execute([$data['name']]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        return $this->db->prepare("UPDATE {$this->table} SET name = ? WHERE id = ?")->execute([$data['name'], $id]);
    }

    public function delete(int $id): bool {
        return $this->db->prepare("DELETE FROM {$this->table} WHERE id = ?")->execute([$id]);
    }
}
