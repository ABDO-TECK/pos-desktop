<?php

namespace App\Repositories;

use App\Contracts\RepositoryInterface;
use App\Services\AuthService;
use PDO;

class LoyaltyRepository implements RepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function all(array $filters = []): array
    {
        throw new \BadMethodCallException('Method not supported.');
    }

    public function findById(int $id): ?array
    {
        throw new \BadMethodCallException('Method not supported.');
    }

    public function create(array $data): int
    {
        throw new \BadMethodCallException('Method not supported.');
    }

    public function update(int $id, array $data): void
    {
        throw new \BadMethodCallException('Method not supported.');
    }

    public function delete(int $id): void
    {
        throw new \BadMethodCallException('Method not supported.');
    }

    public function isEnabled(): bool
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'loyalty_enabled'");
        $stmt->execute();
        return (bool)($stmt->fetchColumn() ?: false);
    }

    public function getPointsPerRial(): int
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'loyalty_points_per_rial'");
        $stmt->execute();
        return (int)($stmt->fetchColumn() ?: 1);
    }

    public function getRialPerPoint(): float
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'loyalty_rial_per_point'");
        $stmt->execute();
        return (float)($stmt->fetchColumn() ?: 0.01);
    }

    public function getCustomerPoints(int $customerId): int
    {
        $stmt = $this->db->prepare(
            'SELECT loyalty_points FROM customers
             WHERE id = ? AND branch_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$customerId, AuthService::getGlobalBranchId()]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function recordTransaction(int $customerId, ?int $invoiceId, int $points, string $type, string $description): void
    {
        $branchId = AuthService::getGlobalBranchId();
        $stmt = $this->db->prepare(
            'INSERT INTO loyalty_transactions (customer_id, invoice_id, points, type, description)
             SELECT c.id, :invoice_id, :points, :type, :description
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
                         AND i.customer_id = c.id
                   )
               )'
        );
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'points' => $points,
            'type' => $type,
            'description' => $description,
            'customer_id' => $customerId,
            'branch_id' => $branchId,
            'invoice_scope_id' => $invoiceId,
            'invoice_scope_id_match' => $invoiceId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Customer or invoice is outside the active branch.');
        }
    }

    public function updateCustomerPoints(int $customerId, int $points): void
    {
        $stmt = $this->db->prepare(
            'UPDATE customers
             SET loyalty_points = loyalty_points + ?
             WHERE id = ? AND branch_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$points, $customerId, AuthService::getGlobalBranchId()]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Customer is outside the active branch.');
        }
    }

    public function getHistory(int $customerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT lt.*
             FROM loyalty_transactions lt
             JOIN customers c ON c.id = lt.customer_id
             WHERE lt.customer_id = ?
               AND c.branch_id = ?
               AND c.deleted_at IS NULL
             ORDER BY lt.created_at DESC
             LIMIT 100'
        );
        $stmt->execute([$customerId, AuthService::getGlobalBranchId()]);
        return $stmt->fetchAll();
    }
}
