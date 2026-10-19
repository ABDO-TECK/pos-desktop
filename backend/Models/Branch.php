<?php
namespace App\Models;

use App\Config\Database;
use PDO;

class Branch {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function all(): array {
        return $this->db->query('SELECT * FROM branches ORDER BY name LIMIT 100')->fetchAll();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM branches WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare('INSERT INTO branches (name, address, phone) VALUES (?, ?, ?)');
        $stmt->execute([$data['name'], $data['address'] ?? '', $data['phone'] ?? '']);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $stmt = $this->db->prepare('UPDATE branches SET name = ?, address = ?, phone = ? WHERE id = ?');
        $stmt->execute([$data['name'], $data['address'] ?? '', $data['phone'] ?? '', $id]);
    }
}
