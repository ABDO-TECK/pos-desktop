<?php

namespace App\Models;

use App\Config\Database;
use PDO;


class ExpenseCategory
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getAll(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM expense_categories ORDER BY name ASC LIMIT 100');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM expense_categories WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('INSERT INTO expense_categories (name) VALUES (:name)');
        $stmt->execute(['name' => $data['name']]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare('UPDATE expense_categories SET name = :name WHERE id = :id');
        $stmt->execute([
            'name' => $data['name'],
            'id'   => $id
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM expense_categories WHERE id = ?');
        $stmt->execute([$id]);
    }
}
