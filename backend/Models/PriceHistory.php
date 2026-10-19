<?php

namespace App\Models;

use PDO;

/**
 * PriceHistory — تسجيل تغييرات الأسعار.
 */
class PriceHistory
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * تسجيل تغيير في السعر أو التكلفة.
     * يُسجّل فقط إذا تغيّر أحد القيمتين (price أو cost).
     */
    public function record(int $productId, array $oldData, array $newData, ?int $userId = null): void
    {
        $oldPrice = (float)($oldData['price'] ?? 0);
        $newPrice = (float)($newData['price'] ?? 0);
        $oldCost  = (float)($oldData['cost']  ?? 0);
        $newCost  = (float)($newData['cost']  ?? 0);

        // لا تسجّل إذا لم يتغيّر شيء
        if (abs($oldPrice - $newPrice) < 0.001 && abs($oldCost - $newCost) < 0.001) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO price_history (product_id, old_price, new_price, old_cost, new_cost, changed_by)
             VALUES (:product_id, :old_price, :new_price, :old_cost, :new_cost, :changed_by)'
        );
        $stmt->execute([
            'product_id' => $productId,
            'old_price'  => $oldPrice,
            'new_price'  => $newPrice,
            'old_cost'   => $oldCost,
            'new_cost'   => $newCost,
            'changed_by' => $userId,
        ]);
    }

    /**
     * جلب سجل تغييرات الأسعار لمنتج معين.
     *
     * @return array قائمة بالتغييرات مرتبة من الأحدث للأقدم
     */
    public function getByProductId(int $productId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT ph.*, u.name AS changed_by_name
             FROM price_history ph
             LEFT JOIN users u ON u.id = ph.changed_by
             WHERE ph.product_id = :pid
             ORDER BY ph.created_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':pid', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
