<?php
namespace App\Models;

use PDO;
use App\Services\AuthService;

class InventoryEvent
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * تسجيل حدث تغيير مخزون.
     * يُستدعى من SaleService / InventoryService / ProductService عند أي تغيير.
     */
    public function record(int $productId, string $action, float $newQuantity, float $delta = 0.0): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO inventory_events (branch_id, product_id, action, quantity, delta)
             VALUES (:branch_id, :pid, :action, :qty, :delta)'
        );
        $stmt->execute([
            'branch_id' => AuthService::getGlobalBranchId(),
            'pid'    => $productId,
            'action' => $action,
            'qty'    => $newQuantity,
            'delta'  => $delta,
        ]);
    }

    /**
     * جلب الأحداث الجديدة بعد ID معين.
     * يُستدعى من SSE endpoint.
     */
    public function getAfter(int $lastId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, action, quantity, delta, created_at
             FROM inventory_events
             WHERE branch_id = :branch_id AND id > :last_id
             ORDER BY id ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':last_id', $lastId, PDO::PARAM_INT);
        $stmt->bindValue(':branch_id', AuthService::getGlobalBranchId(), PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getLatestId(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(id), 0) FROM inventory_events WHERE branch_id = ?'
        );
        $stmt->execute([AuthService::getGlobalBranchId()]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Remove events older than the 24-hour synchronization window.
     * Called by scheduled maintenance, never from a user request.
     */
    public function cleanup(): void
    {
        $this->db->exec("DELETE FROM inventory_events WHERE created_at < NOW() - INTERVAL 24 HOUR");
    }
}
