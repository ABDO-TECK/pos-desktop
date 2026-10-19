<?php

namespace App\Models;

use App\Config\Database;
use PDO;
use App\Services\AuthService;


use App\Models\Traits\InvoiceStatsTrait;

class Invoice {
    use InvoiceStatsTrait;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private function getMonthDateRange(int $month, int $year): array {
        $startDate = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $endDate = date('Y-m-d 00:00:00', strtotime($startDate . ' +1 month'));
        return [$startDate, $endDate];
    }

    /**
     * جلب الفواتير مع دعم pagination اختياري.
     */
    public function all(array $filters = []): array {
        $where  = ['i.branch_id = :branch_id'];
        $params = ['branch_id' => AuthService::getGlobalBranchId()];

        if (!empty($filters['date'])) {
            $where[] = 'i.created_at >= :date_start AND i.created_at < :date_end';
            $params['date_start'] = $filters['date'] . ' 00:00:00';
            $params['date_end'] = date('Y-m-d 00:00:00', strtotime($filters['date'] . ' +1 day'));
        }
        if (!empty($filters['month']) && !empty($filters['year'])) {
            [$startDate, $endDate] = $this->getMonthDateRange((int)$filters['month'], (int)$filters['year']);
            $where[] = 'i.created_at >= :start_date AND i.created_at < :end_date';
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $where[]          = 'i.status = :status';
            $params['status'] = $filters['status'];
        } elseif (!isset($filters['status'])) {
            // Default backward compatibility: only fetch completed
            $where[]          = 'i.status = :status';
            $params['status'] = 'completed';
        }

        // ── Search (بحث برقم الفاتورة أو اسم منتج) ──
        if (!empty($filters['search'])) {
            $searchTerm = trim($filters['search']);
            // إذا كان رقمًا صرفًا، ابحث بمعرف الفاتورة
            if (ctype_digit($searchTerm)) {
                $where[] = 'i.id = :search_id';
                $params['search_id'] = (int)$searchTerm;
            } else {
                // ابحث باسم المنتج داخل بنود الفاتورة
                if (mb_strlen($searchTerm, 'UTF-8') >= 3) {
                    $where[] = 'i.id IN (SELECT ii.invoice_id FROM invoice_items ii JOIN products p ON p.id = ii.product_id WHERE MATCH(p.name) AGAINST(:search_ft IN BOOLEAN MODE))';
                    $params['search_ft'] = $searchTerm . '*';
                } else {
                    $where[] = 'i.id IN (SELECT ii.invoice_id FROM invoice_items ii JOIN products p ON p.id = ii.product_id WHERE p.name LIKE :search_name)';
                    $params['search_name'] = '%' . $searchTerm . '%';
                }
            }
        }

        $whereClause = implode(' AND ', $where);

        // ── Pagination (اختياري) ──
        $page  = isset($filters['page'])  ? max(1, min(1000, (int) $filters['page']))  : null;
        $limit = isset($filters['limit']) ? max(1, min(100, (int) $filters['limit'])) : null;

        if ($page !== null && $limit !== null) {
            $countSql = "SELECT COUNT(*) FROM invoices i WHERE $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $offset = ($page - 1) * $limit;
            $sql = "SELECT i.*, u.name AS cashier_name, c.name AS customer_name
                    FROM invoices i
                    JOIN users u ON u.id = i.user_id
                    LEFT JOIN customers c ON c.id = i.customer_id AND c.branch_id = i.branch_id
                    WHERE $whereClause
                    ORDER BY i.created_at DESC, i.id DESC
                    LIMIT :pag_limit OFFSET :pag_offset";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();
            $pages = (int) ceil($total / $limit);

            return [
                'data' => $rows,
                'pagination' => [
                    'type'      => 'page',
                    'page'      => $page,
                    'limit'     => $limit,
                    'total'     => $total,
                    'pages'     => $pages,
                    'has_more'  => $page < $pages,
                    'truncated' => $total > count($rows),
                ],
            ];
        }

        // ── بدون pagination — configurable limit ──
        $defaultLimit = defined('INVOICE_DEFAULT_LIMIT')
            ? max(1, min(100, (int) INVOICE_DEFAULT_LIMIT))
            : 100;
        $sql = "SELECT i.*, u.name AS cashier_name, c.name AS customer_name
                FROM invoices i
                JOIN users u ON u.id = i.user_id
                LEFT JOIN customers c ON c.id = i.customer_id AND c.branch_id = i.branch_id
                WHERE $whereClause
                ORDER BY i.created_at DESC, i.id DESC
                LIMIT :inv_limit";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':inv_limit', $defaultLimit, \PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll();

        // Warn if limit was hit — operator should use pagination
        if (count($results) >= $defaultLimit && class_exists('App\Helpers\Logger')) {
            \App\Helpers\Logger::warning("Invoice query hit the default limit of {$defaultLimit}. Use pagination (page/limit params) to see all records.");
        }

        return $results;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT i.*, u.name AS cashier_name
             FROM invoices i
             JOIN users u ON u.id = i.user_id
             WHERE i.id = ? AND i.branch_id = ?'
        );
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);
        $invoice = $stmt->fetch();
        if (!$invoice) return null;

        $invoice['items'] = $this->getItems($id);
        return $invoice;
    }

    /**
     * Lock an invoice before replacing or deleting its lines and totals.
     * The caller must already own the surrounding database transaction.
     */
    public function findByIdForUpdate(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT i.*, u.name AS cashier_name
             FROM invoices i
             JOIN users u ON u.id = i.user_id
             WHERE i.id = ? AND i.branch_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);
        $invoice = $stmt->fetch();
        if (!$invoice) return null;

        $invoice['items'] = $this->getItems($id);
        return $invoice;
    }

    public function findHeaderForUpdate(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT i.*
             FROM invoices i
             WHERE i.id = ? AND i.branch_id = ?
             FOR UPDATE'
        );
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);
        $invoice = $stmt->fetch();
        return $invoice ?: null;
    }

    public function getItems(int $invoiceId): array {
        $stmt = $this->db->prepare(
            'SELECT ii.*, p.name AS product_name, p.barcode, p.unit_type, p.size_name, p.sell_by_weight
             FROM invoice_items ii
             JOIN products p ON p.id = ii.product_id
             JOIN invoices i ON i.id = ii.invoice_id
             WHERE ii.invoice_id = ? AND i.branch_id = ?'
        );
        $stmt->execute([$invoiceId, AuthService::getGlobalBranchId()]);
        return $stmt->fetchAll();
    }

    public function create(array $data): int {
        $this->assertCustomerInCurrentBranch($data['customer_id'] ?? null);

        $stmt = $this->db->prepare(
            'INSERT INTO invoices (branch_id, user_id, customer_id, subtotal, discount, tax, shipping_cost, total, payment_method, amount_paid, change_due, amount_due, status, driver_name, vehicle_number, delivery_date, delivery_notes)
             VALUES (:branch_id, :user_id, :customer_id, :subtotal, :discount, :tax, :shipping_cost, :total, :payment_method, :amount_paid, :change_due, :amount_due, :status, :driver_name, :vehicle_number, :delivery_date, :delivery_notes)'
        );
        $stmt->execute([
            'branch_id'      => AuthService::getGlobalBranchId(),
            'user_id'        => $data['user_id'],
            'customer_id'    => $data['customer_id'] ?? null,
            'subtotal'       => $data['subtotal'],
            'discount'       => $data['discount'] ?? 0,
            'tax'            => $data['tax'] ?? 0,
            'shipping_cost'  => $data['shipping_cost'] ?? 0,
            'total'          => $data['total'],
            'payment_method' => $data['payment_method'] ?? 'cash',
            'amount_paid'    => $data['amount_paid'] ?? $data['total'],
            'change_due'     => $data['change_due'] ?? 0,
            'amount_due'     => $data['amount_due'] ?? 0,
            'status'         => $data['status'] ?? 'completed',
            'driver_name'    => $data['driver_name'] ?? null,
            'vehicle_number' => $data['vehicle_number'] ?? null,
            'delivery_date'  => $data['delivery_date'] ?? null,
            'delivery_notes' => $data['delivery_notes'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function claimIdempotency(string $key, string $requestHash): void {
        $stmt = $this->db->prepare(
            'INSERT INTO sale_idempotency_keys (branch_id, idempotency_key, request_hash)
             VALUES (:branch_id, :idempotency_key, :request_hash)'
        );
        $stmt->execute([
            'branch_id'       => AuthService::getGlobalBranchId(),
            'idempotency_key' => $key,
            'request_hash'    => $requestHash,
        ]);
    }

    public function findIdempotency(string $key): ?array {
        $stmt = $this->db->prepare(
            'SELECT idempotency_key, request_hash, invoice_id, response_code,
                    response_message, response_json, completed_at
             FROM sale_idempotency_keys
             WHERE branch_id = :branch_id AND idempotency_key = :idempotency_key
             LIMIT 1'
        );
        $stmt->execute([
            'branch_id'       => AuthService::getGlobalBranchId(),
            'idempotency_key' => $key,
        ]);
        $record = $stmt->fetch();
        return $record ?: null;
    }

    public function completeIdempotency(
        string $key,
        string $requestHash,
        int $invoiceId,
        int $responseCode,
        string $responseMessage,
        string $responseJson
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE sale_idempotency_keys
             SET invoice_id = :invoice_id,
                 response_code = :response_code,
                 response_message = :response_message,
                 response_json = :response_json,
                 completed_at = CURRENT_TIMESTAMP
             WHERE branch_id = :branch_id
               AND idempotency_key = :idempotency_key
               AND request_hash = :request_hash
               AND completed_at IS NULL'
        );
        $stmt->execute([
            'invoice_id'       => $invoiceId,
            'response_code'    => $responseCode,
            'response_message' => $responseMessage,
            'response_json'    => $responseJson,
            'branch_id'        => AuthService::getGlobalBranchId(),
            'idempotency_key'  => $key,
            'request_hash'     => $requestHash,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Unable to finalize sale idempotency record');
        }
    }

    public function addItem(int $invoiceId, array $item): void {
        $scopeStmt = $this->db->prepare(
            'SELECT 1
             FROM invoices i
             JOIN products p ON p.id = ?
             WHERE i.id = ? AND i.branch_id = ? AND p.branch_id = ?
             LIMIT 1'
        );
        $branchId = AuthService::getGlobalBranchId();
        $scopeStmt->execute([$item['product_id'], $invoiceId, $branchId, $branchId]);
        if (!$scopeStmt->fetchColumn()) {
            throw new \DomainException('Invoice item is outside the active branch.');
        }

        $unitCost = isset($item['unit_cost']) ? (float)$item['unit_cost'] : 0.0;
        $stmt     = $this->db->prepare(
            'INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal)
             VALUES (:invoice_id, :product_id, :quantity, :price, :unit_cost, :subtotal)'
        );
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'product_id' => $item['product_id'],
            'quantity'   => $item['quantity'],
            'price'      => $item['price'],
            'unit_cost'  => $unitCost,
            'subtotal'   => $item['quantity'] * $item['price'],
        ]);
    }

    public function deleteItemsByInvoiceId(int $invoiceId): void {
        $this->db->prepare(
            'DELETE ii FROM invoice_items ii
             JOIN invoices i ON i.id = ii.invoice_id
             WHERE ii.invoice_id = ? AND i.branch_id = ?'
        )->execute([$invoiceId, AuthService::getGlobalBranchId()]);
    }

    public function updateTotals(int $id, array $data): void {
        $this->assertCustomerInCurrentBranch($data['customer_id'] ?? null);

        $stmt = $this->db->prepare(
            'UPDATE invoices SET
                customer_id = :customer_id,
                subtotal = :subtotal,
                discount = :discount,
                tax = :tax,
                shipping_cost = :shipping_cost,
                total = :total,
                payment_method = :payment_method,
                amount_paid = :amount_paid,
                change_due = :change_due,
                amount_due = :amount_due,
                status = :status,
                driver_name = :driver_name,
                vehicle_number = :vehicle_number,
                delivery_date = :delivery_date,
                delivery_notes = :delivery_notes
             WHERE id = :id AND branch_id = :branch_id'
        );
        $stmt->execute([
            'id'               => $id,
            'branch_id'        => AuthService::getGlobalBranchId(),
            'customer_id'      => $data['customer_id'] ?? null,
            'subtotal'         => $data['subtotal'],
            'discount'         => $data['discount'] ?? 0,
            'tax'              => $data['tax'] ?? 0,
            'shipping_cost'    => $data['shipping_cost'] ?? 0,
            'total'            => $data['total'],
            'payment_method'   => $data['payment_method'],
            'amount_paid'      => $data['amount_paid'],
            'change_due'       => $data['change_due'] ?? 0,
            'amount_due'       => $data['amount_due'] ?? 0,
            'status'           => $data['status'] ?? 'completed',
            'driver_name'      => $data['driver_name'] ?? null,
            'vehicle_number'   => $data['vehicle_number'] ?? null,
            'delivery_date'    => $data['delivery_date'] ?? null,
            'delivery_notes'   => $data['delivery_notes'] ?? null,
        ]);
    }

    public function updateStatus(int $id, string $status): void {
        if ($status !== 'completed') {
            throw new \DomainException('Invoice status changes must use the inventory workflow.');
        }

        $stmt = $this->db->prepare(
            'UPDATE invoices
             SET status = ?
             WHERE id = ? AND branch_id = ? AND status = ?'
        );
        $stmt->execute([$status, $id, AuthService::getGlobalBranchId(), 'reserved']);

        if ($stmt->rowCount() > 0) {
            $this->db->prepare(
                "UPDATE customer_ledger
                 SET description = REPLACE(description, ' 🕒 (محجوزة - لم تُسلم)', '')
                 WHERE invoice_id = ? AND branch_id = ?"
            )->execute([$id, AuthService::getGlobalBranchId()]);
        }
    }

    public function delete(int $id): void {
        $this->deleteLocked($id);
    }

    public function deleteLocked(int $id): int {
        $stmt = $this->db->prepare('DELETE FROM invoices WHERE id = ? AND branch_id = ?');
        $stmt->execute([$id, AuthService::getGlobalBranchId()]);
        return $stmt->rowCount();
    }

    private function assertCustomerInCurrentBranch(?int $customerId): void
    {
        if ($customerId === null) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT 1
             FROM customers
             WHERE id = ? AND branch_id = ? AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$customerId, AuthService::getGlobalBranchId()]);
        if (!$stmt->fetchColumn()) {
            throw new \DomainException('Customer is outside the active branch.');
        }
    }


}
