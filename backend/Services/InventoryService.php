<?php

namespace App\Services;

use App\Controllers\SaleController;
use App\Controllers\SupplierController;
use App\Helpers\Logger;
use App\Models\Product;
use App\Repositories\SupplierRepository;
use PDO;
use Throwable;
use App\Contracts\InventoryServiceInterface;

/**
 * InventoryService — منطق المخزون المشترك.
 *
 * يُوحّد عمليات زيادة/خصم المخزون المستخدمة في SaleController
 * و SupplierController لتجنب التكرار.
 */
class InventoryService implements InventoryServiceInterface
{
    private const MAX_QUANTITY = 9999999.999;
    private const MAX_MONEY = 99999999.99;

    private Product  $productModel;
    private SupplierRepository $supplierRepo;
    private PDO $db;

    public function __construct(Product $productModel, SupplierRepository $supplierRepo, PDO $db)
    {
        $this->productModel = $productModel;
        $this->supplierRepo = $supplierRepo;
        $this->db = $db;
    }

    // ── Bulk purchase (from supplier) ────────────────────────

    /**
     * تنفيذ عملية شراء بالجملة من مورد.
     *
     * @return array ['ok' => true, 'invoice_id' => int, 'items_processed' => int]
     *               أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function processBulkPurchase(array $data, array $authUser): array
    {
        if (
            !isset($data['supplier_id'])
            || filter_var($data['supplier_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
        ) {
            return ['ok' => false, 'error' => 'supplier_id is required', 'code' => 422];
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            return ['ok' => false, 'error' => 'items array is required', 'code' => 422];
        }
        if (count($data['items']) > 500) {
            return ['ok' => false, 'error' => 'A purchase cannot contain more than 500 items', 'code' => 422];
        }

        $seenProductIds = [];
        $subtotal = 0.0;
        foreach ($data['items'] as $item) {
            if (
                !is_array($item)
                || !isset($item['product_id'], $item['quantity'], $item['cost'])
                || filter_var($item['product_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || !is_numeric($item['quantity'])
                || !is_finite((float) $item['quantity'])
                || (float) $item['quantity'] <= 0
                || (float) $item['quantity'] > self::MAX_QUANTITY
                || !is_numeric($item['cost'])
                || !is_finite((float) $item['cost'])
                || (float) $item['cost'] < 0
                || (float) $item['cost'] > self::MAX_MONEY
            ) {
                return ['ok' => false, 'error' => 'Invalid purchase item', 'code' => 422];
            }
            $productId = (int) $item['product_id'];
            if (isset($seenProductIds[$productId])) {
                return ['ok' => false, 'error' => 'Duplicate products are not allowed', 'code' => 422];
            }
            $seenProductIds[$productId] = true;
            $lineTotal = (float) $item['cost'] * (float) $item['quantity'];
            if (!is_finite($lineTotal) || $lineTotal > self::MAX_MONEY) {
                return ['ok' => false, 'error' => 'Invalid purchase item total', 'code' => 422];
            }
            $subtotal += $lineTotal;
            if (!is_finite($subtotal)) {
                return ['ok' => false, 'error' => 'Purchase total is too large', 'code' => 422];
            }
        }

        $discount = $data['discount'] ?? 0;
        if (
            !is_numeric($discount)
            || !is_finite((float) $discount)
            || (float) $discount < 0
            || (float) $discount > 999999999
        ) {
            return ['ok' => false, 'error' => 'Invalid supplier discount', 'code' => 422];
        }
        $discount = round((float) $discount, 2);
        if ($discount > round($subtotal, 2)) {
            return ['ok' => false, 'error' => 'Supplier discount cannot exceed purchase subtotal', 'code' => 422];
        }

        $shippingCost = $data['shipping_cost'] ?? 0;
        if (
            !is_numeric($shippingCost)
            || !is_finite((float) $shippingCost)
            || (float) $shippingCost < 0
            || (float) $shippingCost > 999999999
        ) {
            return ['ok' => false, 'error' => 'Invalid shipping cost', 'code' => 422];
        }
        $shippingCost = round((float) $shippingCost, 2);
        $grandTotal = round(max(0.0, $subtotal - $discount + $shippingCost), 2);
        if (!is_finite($grandTotal) || $grandTotal > self::MAX_MONEY) {
            return ['ok' => false, 'error' => 'Purchase total is too large', 'code' => 422];
        }

        $paymentType = $data['payment_type'] ?? 'cash';
        if (!in_array($paymentType, ['cash', 'credit'], true)) {
            return ['ok' => false, 'error' => 'Invalid payment type', 'code' => 422];
        }
        $deposit = $data['deposit'] ?? 0;
        if (!is_numeric($deposit) || !is_finite((float) $deposit) || (float) $deposit < 0) {
            return ['ok' => false, 'error' => 'Invalid deposit', 'code' => 422];
        }
        if ((float) $deposit > $grandTotal) {
            return ['ok' => false, 'error' => 'Deposit cannot exceed purchase total', 'code' => 422];
        }

        $supplier = $this->supplierRepo->findById((int) $data['supplier_id']);
        if (!$supplier) {
            return ['ok' => false, 'error' => 'Supplier not found', 'code' => 404];
        }

        $replaceInvoiceId = isset($data['replace_invoice_id']) ? (int) $data['replace_invoice_id'] : 0;
        $existingInvoice = null;
        if ($this->db->inTransaction()) {
            return ['ok' => false, 'error' => 'Inventory transaction already active', 'code' => 409];
        }

        try {
            $this->db->beginTransaction();

            $productIds = array_map(
                static fn(array $item): int => (int) $item['product_id'],
                $data['items']
            );
            $products = $this->productModel->findByIds($productIds);
            foreach ($productIds as $productId) {
                if (!isset($products[$productId])) {
                    throw new \RuntimeException("Product ID {$productId} not found");
                }
            }

            if ($replaceInvoiceId > 0) {
                $existingInvoice = $this->supplierRepo->getPurchaseInvoiceHeaderForUpdate($replaceInvoiceId);
                if (!$existingInvoice) {
                    throw new \RuntimeException('Original invoice not found for replacement');
                }
                if ((int) $existingInvoice['supplier_id'] !== (int) $data['supplier_id']) {
                    throw new \RuntimeException('Replacement supplier does not match original invoice');
                }
                $existingInvoice['items'] = $this->supplierRepo->getPurchaseInvoiceItems($replaceInvoiceId);
            }

            if ($replaceInvoiceId > 0) {
                $this->productModel->batchDecrementQuantity(
                    $this->aggregateQuantityChanges($existingInvoice['items'])
                );
                $this->supplierRepo->deletePurchaseInvoiceItems($replaceInvoiceId);
                $this->supplierRepo->updatePurchaseInvoiceTotals($replaceInvoiceId, [
                    'total'          => $grandTotal,
                    'discount'       => $discount,
                    'shipping_cost'  => $shippingCost,
                    'items_count'    => count($data['items']),
                    'notes'          => $data['notes'] ?? null,
                    'driver_name'    => $data['driver_name'] ?? null,
                    'delivery_date'  => $data['delivery_date'] ?? null,
                    'delivery_notes' => $data['delivery_notes'] ?? null,
                ]);
                $invoiceId = $replaceInvoiceId;
            } else {
                $invoiceId = $this->supplierRepo->createPurchaseInvoice([
                    'supplier_id'    => (int) $data['supplier_id'],
                    'total'          => $grandTotal,
                    'discount'       => $discount,
                    'shipping_cost'  => $shippingCost,
                    'items_count'    => count($data['items']),
                    'notes'          => $data['notes'] ?? null,
                    'driver_name'    => $data['driver_name'] ?? null,
                    'delivery_date'  => $data['delivery_date'] ?? null,
                    'delivery_notes' => $data['delivery_notes'] ?? null,
                ]);
            }

            $purchaseRows = [];
            $quantityIncrements = [];
            $costUpdates = [];
            foreach ($data['items'] as $item) {
                $purchaseRows[] = [
                    'purchase_invoice_id' => $invoiceId,
                    'supplier_id'         => (int) $data['supplier_id'],
                    'product_id'          => (int) $item['product_id'],
                    'quantity'            => (float) $item['quantity'],
                    'cost'                => (float) $item['cost'],
                ];
                $quantityIncrements[] = [
                    'product_id' => (int) $item['product_id'],
                    'quantity' => (float) $item['quantity'],
                ];

                if (!empty($item['update_cost'])) {
                    $costUpdates[] = [
                        'product_id' => (int) $item['product_id'],
                        'cost' => (float) $item['cost'],
                    ];
                }
            }
            $this->supplierRepo->createPurchases($purchaseRows);
            $this->productModel->batchIncrementQuantity($quantityIncrements);
            $this->productModel->batchUpdateCosts($costUpdates);

            // تسجيل قيود كشف حساب المورد
            if ($replaceInvoiceId > 0) {
                // Delete old ledger entries linked to this invoice before writing the updated ones
                $this->db->prepare(
                    'DELETE FROM supplier_ledger WHERE purchase_invoice_id = ? AND branch_id = ?'
                )->execute([$invoiceId, \App\Services\AuthService::getGlobalBranchId()]);
            }

            if ($paymentType === 'credit') {
                $this->recordSupplierLedger(
                    (int) $data['supplier_id'],
                    $invoiceId,
                    $grandTotal,
                    (float) ($data['deposit'] ?? 0),
                    $authUser,
                    'credit'
                );
            } elseif ($paymentType === 'cash') {
                $this->recordSupplierLedger(
                    (int) $data['supplier_id'],
                    $invoiceId,
                    $grandTotal,
                    $grandTotal,
                    $authUser,
                    'cash'
                );
            }

            $this->db->commit();
        } catch (\RuntimeException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e->getMessage() === 'Original invoice not found for replacement') {
                return ['ok' => false, 'error' => 'Original invoice not found for replacement', 'code' => 404];
            }
            if ($e->getMessage() === 'Replacement supplier does not match original invoice') {
                return ['ok' => false, 'error' => 'Replacement supplier does not match original invoice', 'code' => 409];
            }
            if (str_starts_with($e->getMessage(), 'Product ID ')) {
                return ['ok' => false, 'error' => $e->getMessage(), 'code' => 404];
            }
            if ($e->getMessage() === 'Insufficient stock or out-of-scope product') {
                return ['ok' => false, 'error' => 'Insufficient stock to replace purchase', 'code' => 409];
            }
            if ($e->getMessage() === 'Out-of-scope product') {
                return ['ok' => false, 'error' => 'Product changed concurrently', 'code' => 409];
            }
            Logger::error('Bulk purchase transaction failed', Logger::exceptionContext($e));
            return ['ok' => false, 'error' => 'Failed to record bulk purchase', 'code' => 500];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Logger::error('فشل عملية شراء بالجملة', Logger::exceptionContext($e));
            return ['ok' => false, 'error' => 'Failed to record bulk purchase', 'code' => 500];
        }

        return [
            'ok'              => true,
            'invoice_id'      => $invoiceId,
            'items_processed' => count($data['items']),
            'is_update'       => $replaceInvoiceId > 0,
        ];
    }

    // ── Delete purchase invoice ──────────────────────────────

    /**
     * حذف فاتورة شراء مع إرجاع الكميات من المخزون.
     */
    public function deletePurchaseInvoice(int $id, ?int $actorId = null): array
    {
        if ($this->db->inTransaction()) {
            return ['ok' => false, 'error' => 'Inventory transaction already active', 'code' => 409];
        }

        try {
            $this->db->beginTransaction();
            $invoice = $this->supplierRepo->getPurchaseInvoiceHeaderForUpdate($id);
            if (!$invoice) {
                throw new \RuntimeException('Purchase invoice not found');
            }
            $invoice['items'] = $this->supplierRepo->getPurchaseInvoiceItems($id);

            $this->productModel->batchDecrementQuantity(
                $this->aggregateQuantityChanges($invoice['items'])
            );
            
            // Delete related supplier ledger entries
            $this->db->prepare(
                'DELETE FROM supplier_ledger WHERE purchase_invoice_id = ? AND branch_id = ?'
            )->execute([$id, \App\Services\AuthService::getGlobalBranchId()]);

            if ($this->supplierRepo->deletePurchaseInvoice($id) !== 1) {
                throw new \RuntimeException('Purchase invoice changed concurrently');
            }
            if ($actorId !== null) {
                \App\Helpers\AuditLog::logOrFail(
                    $actorId,
                    'delete_purchase_invoice',
                    'purchase_invoice',
                    $id
                );
            }
            $this->db->commit();
        } catch (\RuntimeException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e->getMessage() === 'Purchase invoice not found') {
                return ['ok' => false, 'error' => 'Purchase invoice not found', 'code' => 404];
            }
            if ($e->getMessage() === 'Insufficient stock or out-of-scope product') {
                return ['ok' => false, 'error' => 'Insufficient stock to delete purchase', 'code' => 409];
            }
            if (in_array($e->getMessage(), ['Purchase invoice changed concurrently', 'Out-of-scope product'], true)) {
                return ['ok' => false, 'error' => 'Purchase invoice changed concurrently', 'code' => 409];
            }
            Logger::error('Purchase deletion failed', Logger::exceptionContext($e));
            return ['ok' => false, 'error' => 'Failed to delete purchase invoice', 'code' => 500];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Logger::error('فشل حذف فاتورة الشراء', Logger::exceptionContext($e));
            return ['ok' => false, 'error' => 'Failed to delete purchase invoice', 'code' => 500];
        }

        return ['ok' => true];
    }

    // ── Supplier ledger helper ───────────────────────────────

    private function recordSupplierLedger(int $supplierId, int $invoiceId, float $grandTotal, float $deposit, array $authUser, string $paymentType = 'credit'): void
    {
        $desc = "فاتورة شراء #{$invoiceId}";
        if ($paymentType === 'cash') {
            $desc .= " (نقدي)";
        } elseif ($deposit > 0) {
            $desc .= " (عربون {$deposit})";
        }

        $this->supplierRepo->addLedgerEntry([
            'supplier_id'         => $supplierId,
            'type'                => 'debit',
            'amount'              => $grandTotal,
            'description'         => $desc,
            'purchase_invoice_id' => $invoiceId,
            'created_by'          => $authUser['id'],
        ]);

        if ($paymentType === 'cash') {
            $this->supplierRepo->addLedgerEntry([
                'supplier_id'         => $supplierId,
                'type'                => 'credit',
                'amount'              => $grandTotal,
                'description'         => "سداد نقدي لفاتورة شراء #{$invoiceId}",
                'purchase_invoice_id' => $invoiceId,
                'created_by'          => $authUser['id'],
            ]);
        } elseif ($deposit > 0) {
            $this->supplierRepo->addLedgerEntry([
                'supplier_id'         => $supplierId,
                'type'                => 'credit',
                'amount'              => $deposit,
                'description'         => "عربون فاتورة شراء #{$invoiceId}",
                'purchase_invoice_id' => $invoiceId,
                'created_by'          => $authUser['id'],
            ]);
        }
    }

    /**
     * Aggregate legacy duplicate purchase rows before a batch stock reversal.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array{product_id:int,quantity:float}>
     */
    private function aggregateQuantityChanges(array $items): array
    {
        $quantities = [];
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantities[$productId] = ($quantities[$productId] ?? 0.0) + (float) $item['quantity'];
        }

        $changes = [];
        foreach ($quantities as $productId => $quantity) {
            $changes[] = ['product_id' => $productId, 'quantity' => $quantity];
        }

        return $changes;
    }
}
