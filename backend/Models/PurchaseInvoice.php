<?php

namespace App\Models;

use PDO;
use App\Services\AuthService;

class PurchaseInvoice {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Create a purchase invoice header and return its ID.
     */
    public function createPurchaseInvoice(array $data): int {
        $this->assertSupplierInCurrentBranch((int) $data['supplier_id']);

        $stmt = $this->db->prepare(
            'INSERT INTO purchase_invoices (branch_id, supplier_id, total, discount, shipping_cost, items_count, notes, driver_name, delivery_date, delivery_notes)
             VALUES (:branch_id, :supplier_id, :total, :discount, :shipping_cost, :items_count, :notes, :driver_name, :delivery_date, :delivery_notes)'
        );
        $stmt->execute([
            'branch_id'      => AuthService::getGlobalBranchId(),
            'supplier_id'    => $data['supplier_id'],
            'total'          => $data['total'] ?? 0,
            'discount'       => $data['discount'] ?? 0,
            'shipping_cost'  => $data['shipping_cost'] ?? 0,
            'items_count'    => $data['items_count'] ?? 0,
            'notes'          => $data['notes'] ?? null,
            'driver_name'    => $data['driver_name'] ?? null,
            'delivery_date'  => $data['delivery_date'] ?? null,
            'delivery_notes' => $data['delivery_notes'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Create a purchase line item linked to a purchase invoice.
     */
    public function createPurchase(array $data): int {
        return $this->createPurchases([$data]);
    }

    /**
     * Insert a validated purchase batch with a single database round trip.
     *
     * @param list<array<string,mixed>> $items
     */
    public function createPurchases(array $items): int {
        if ($items === []) {
            throw new \InvalidArgumentException('Purchase batch cannot be empty.');
        }

        $branchId = AuthService::getGlobalBranchId();
        $supplierId = (int) $items[0]['supplier_id'];
        $invoiceId = isset($items[0]['purchase_invoice_id'])
            ? (int) $items[0]['purchase_invoice_id']
            : null;

        $this->assertSupplierInCurrentBranch($supplierId);
        if ($invoiceId !== null) {
            $invoiceStmt = $this->db->prepare(
                'SELECT 1 FROM purchase_invoices
                 WHERE id = ? AND branch_id = ? AND supplier_id = ?
                 LIMIT 1'
            );
            $invoiceStmt->execute([$invoiceId, $branchId, $supplierId]);
            if (!$invoiceStmt->fetchColumn()) {
                throw new \DomainException('Purchase invoice is outside the active branch.');
            }
        }

        $productIds = [];
        foreach ($items as $item) {
            if (
                (int) $item['supplier_id'] !== $supplierId
                || (isset($item['purchase_invoice_id']) ? (int) $item['purchase_invoice_id'] : null) !== $invoiceId
            ) {
                throw new \InvalidArgumentException('A purchase batch must share one supplier and invoice.');
            }
            $productIds[] = (int) $item['product_id'];
        }
        $productIds = array_values(array_unique($productIds));
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $productStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM products
             WHERE id IN ({$placeholders}) AND branch_id = ? AND deleted_at IS NULL"
        );
        $productStmt->execute([...$productIds, $branchId]);
        if ((int) $productStmt->fetchColumn() !== count($productIds)) {
            throw new \DomainException('Purchase item is outside the active branch.');
        }

        $values = [];
        $params = [];
        foreach ($items as $index => $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $cost = (float) ($item['cost'] ?? 0);
            if (
                !is_numeric($item['quantity'] ?? null)
                || !is_finite($quantity)
                || $quantity <= 0
                || !is_numeric($item['cost'] ?? null)
                || !is_finite($cost)
                || $cost < 0
            ) {
                throw new \InvalidArgumentException(
                    'Purchase quantity and cost must be finite and valid'
                );
            }

            $values[] = "(:invoice_{$index}, :supplier_{$index}, :product_{$index}, :quantity_{$index}, :cost_{$index}, :total_{$index}, :notes_{$index})";
            $params["invoice_{$index}"] = $invoiceId;
            $params["supplier_{$index}"] = $supplierId;
            $params["product_{$index}"] = (int) $item['product_id'];
            $params["quantity_{$index}"] = $quantity;
            $params["cost_{$index}"] = $cost;
            $params["total_{$index}"] = $quantity * $cost;
            $params["notes_{$index}"] = $item['notes'] ?? null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO purchases (purchase_invoice_id, supplier_id, product_id, quantity, cost, total, notes) VALUES '
            . implode(', ', $values)
        );
        $stmt->execute($params);

        return (int) $this->db->lastInsertId();
    }

    /**
     * List purchase invoices (for the purchase log — like sales list).
     */
    public function getPurchaseInvoices(array $filters = []): array {
        $where  = ['pi.branch_id = :branch_id'];
        $params = ['branch_id' => AuthService::getGlobalBranchId()];

        if (!empty($filters['supplier_id'])) {
            $where[]               = 'pi.supplier_id = :supplier_id';
            $params['supplier_id'] = $filters['supplier_id'];
        }
        if (!empty($filters['date'])) {
            $where[] = 'pi.created_at >= :date_start AND pi.created_at < :date_end';
            $params['date_start'] = $filters['date'] . ' 00:00:00';
            $params['date_end'] = date('Y-m-d 00:00:00', strtotime($filters['date'] . ' +1 day'));
        }
        if (!empty($filters['month']) && !empty($filters['year'])) {
            $monthStart = sprintf('%04d-%02d-01 00:00:00', (int) $filters['year'], (int) $filters['month']);
            $where[] = 'pi.created_at >= :month_start AND pi.created_at < :month_end';
            $params['month_start'] = $monthStart;
            $params['month_end'] = date('Y-m-d 00:00:00', strtotime($monthStart . ' +1 month'));
        }

        // ── Search (بحث برقم الفاتورة أو اسم منتج) ──
        if (!empty($filters['search'])) {
            $searchTerm = trim($filters['search']);
            if (ctype_digit($searchTerm)) {
                $where[] = 'pi.id = :search_id';
                $params['search_id'] = (int)$searchTerm;
            } else {
                if (mb_strlen($searchTerm, 'UTF-8') >= 3) {
                    $where[] = 'pi.id IN (SELECT p.purchase_invoice_id FROM purchases p JOIN products pr ON pr.id = p.product_id WHERE MATCH(pr.name) AGAINST(:search_ft IN BOOLEAN MODE))';
                    $params['search_ft'] = $searchTerm . '*';
                } else {
                    $where[] = 'pi.id IN (SELECT p.purchase_invoice_id FROM purchases p JOIN products pr ON pr.id = p.product_id WHERE pr.name LIKE :search_name)';
                    $params['search_name'] = '%' . $searchTerm . '%';
                }
            }
        }

        $hasPagination = false;
        if (isset($filters['page']) && isset($filters['limit'])) {
            $page  = max(1, min(1000, (int)$filters['page']));
            $limit = max(1, min(100, (int)$filters['limit']));
            $offset = ($page - 1) * $limit;
            $hasPagination = true;
            
            $countStmt = $this->db->prepare('SELECT COUNT(*) FROM purchase_invoices pi WHERE ' . implode(' AND ', $where));
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();
            
            $limitStr = "LIMIT :pag_limit OFFSET :pag_offset";
        } else {
            $limitStr = "LIMIT 100";
        }

        $stmt = $this->db->prepare(
            'SELECT pi.*, s.name AS supplier_name
             FROM purchase_invoices pi
             JOIN suppliers s ON s.id = pi.supplier_id AND s.branch_id = pi.branch_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pi.created_at DESC, pi.id DESC
             ' . $limitStr
        );
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        if ($hasPagination) {
            $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
        }
        $stmt->execute();

        if (isset($page, $limit)) {
            return [
                'data' => $stmt->fetchAll(),
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $limit,
                    'current_page' => $page,
                    'last_page'    => ceil($total / $limit)
                ]
            ];
        }

        return $stmt->fetchAll();
    }

    /**
     * Get a single purchase invoice with its items (like invoice detail).
     */
    public function getPurchaseInvoice(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT pi.*, s.name AS supplier_name
             FROM purchase_invoices pi
             JOIN suppliers s ON s.id = pi.supplier_id AND s.branch_id = pi.branch_id
             WHERE pi.id = ? AND pi.branch_id = ?'
        );
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);
        $invoice = $stmt->fetch();
        if (!$invoice) return null;

        $itemStmt = $this->db->prepare(
            'SELECT pu.*, p.name AS product_name, p.barcode AS product_barcode
             FROM purchases pu
             JOIN products p ON p.id = pu.product_id
             JOIN purchase_invoices pi ON pi.id = pu.purchase_invoice_id
             WHERE pu.purchase_invoice_id = ? AND pi.branch_id = ?
             ORDER BY pu.id ASC'
        );
        $itemStmt->execute([$id, AuthService::getGlobalBranchId()]);
        $invoice['items'] = $itemStmt->fetchAll();

        return $invoice;
    }

    /**
     * Lock a purchase invoice header in the active branch.
     *
     * The caller must already own the transaction and load items afterwards.
     */
    public function getPurchaseInvoiceHeaderForUpdate(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT pi.*
             FROM purchase_invoices pi
             WHERE pi.id = ? AND pi.branch_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);
        $invoice = $stmt->fetch();

        return $invoice ?: null;
    }

    public function getPurchaseInvoiceItems(int $id): array {
        $stmt = $this->db->prepare(
            'SELECT pu.*, p.name AS product_name, p.barcode AS product_barcode
             FROM purchases pu
             JOIN products p ON p.id = pu.product_id
             JOIN purchase_invoices pi ON pi.id = pu.purchase_invoice_id
             WHERE pu.purchase_invoice_id = ? AND pi.branch_id = ?
             ORDER BY pu.id ASC'
        );
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);

        return $stmt->fetchAll();
    }

    /**
     * Delete a purchase invoice and restore stock quantities.
     */
    public function deletePurchaseInvoiceItems(int $id): void {
        $this->db->prepare(
            'DELETE pu FROM purchases pu
             JOIN purchase_invoices pi ON pi.id = pu.purchase_invoice_id
             WHERE pu.purchase_invoice_id = ? AND pi.branch_id = ?'
        )->execute([$id, AuthService::getGlobalBranchId()]);
    }

    public function updatePurchaseInvoiceTotals(int $id, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE purchase_invoices 
             SET total = :total, 
                 discount = :discount,
                 shipping_cost = :shipping_cost,
                 items_count = :items_count, 
                 notes = :notes, 
                 driver_name = :driver_name, 
                 delivery_date = :delivery_date, 
                 delivery_notes = :delivery_notes 
             WHERE id = :id AND branch_id = :branch_id'
        );
        $stmt->execute([
            'id'             => $id, 
            'branch_id'      => AuthService::getGlobalBranchId(),
            'total'          => $data['total'], 
            'discount'       => $data['discount'] ?? 0,
            'shipping_cost'  => $data['shipping_cost'] ?? 0,
            'items_count'    => $data['items_count'], 
            'notes'          => $data['notes'] ?? null,
            'driver_name'    => $data['driver_name'] ?? null,
            'delivery_date'  => $data['delivery_date'] ?? null,
            'delivery_notes' => $data['delivery_notes'] ?? null,
        ]);
    }

    /**
     * Delete a previously locked purchase invoice and return header rows deleted.
     */
    public function deletePurchaseInvoice(int $id): int {
        $this->deletePurchaseInvoiceItems($id);
        $stmt = $this->db->prepare(
            'DELETE FROM purchase_invoices WHERE id = ? AND branch_id = ?'
        );
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);

        return $stmt->rowCount();
    }

    /**
     * Legacy: get flat purchase list (kept for backward compatibility).
     */
    public function getPurchases(array $filters = []): array {
        $where  = ['pi.branch_id = :branch_id'];
        $params = ['branch_id' => AuthService::getGlobalBranchId()];

        if (!empty($filters['supplier_id'])) {
            $where[]                 = 'pu.supplier_id = :supplier_id';
            $params['supplier_id']   = $filters['supplier_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'pu.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'pu.created_at < :date_to';
            $params['date_to'] = date('Y-m-d 00:00:00', strtotime($filters['date_to'] . ' +1 day'));
        }

        $hasPagination = false;
        if (isset($filters['page']) && isset($filters['limit'])) {
            $page  = max(1, min(1000, (int)$filters['page']));
            $limit = max(1, min(100, (int)$filters['limit']));
            $offset = ($page - 1) * $limit;
            $hasPagination = true;

            $countStmt = $this->db->prepare(
                'SELECT COUNT(*)
                 FROM purchases pu
                 JOIN purchase_invoices pi ON pi.id = pu.purchase_invoice_id
                 WHERE ' . implode(' AND ', $where)
            );
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $limitStr = "LIMIT :pag_limit OFFSET :pag_offset";
        } else {
            $limitStr = "LIMIT 100";
        }

        $stmt = $this->db->prepare(
            'SELECT pu.*, s.name AS supplier_name, p.name AS product_name, p.barcode AS product_barcode
             FROM purchases pu
             JOIN purchase_invoices pi ON pi.id = pu.purchase_invoice_id
             JOIN suppliers s ON s.id = pu.supplier_id AND s.branch_id = pi.branch_id
             JOIN products p ON p.id = pu.product_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pu.created_at DESC, pu.id DESC
             ' . $limitStr
        );
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        if ($hasPagination) {
            $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
        }
        $stmt->execute();

        if ($hasPagination) {
            return [
                'data' => $stmt->fetchAll(),
                'pagination' => [
                    'page'  => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit)
                ]
            ];
        }

        return $stmt->fetchAll();
    }

    private function assertSupplierInCurrentBranch(int $supplierId): void
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM suppliers
             WHERE id = ? AND branch_id = ? AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$supplierId, AuthService::getGlobalBranchId()]);
        if (!$stmt->fetchColumn()) {
            throw new \DomainException('Supplier is outside the active branch.');
        }
    }
}
