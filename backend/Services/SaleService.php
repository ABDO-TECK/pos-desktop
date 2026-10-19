<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\Logger;
use App\Repositories\InvoiceRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\InventoryEventRepository;
use PDO;
use PDOException;
use Throwable;
use App\Contracts\SaleServiceInterface;

/**
 * SaleService — منطق الأعمال لعمليات البيع.
 *
 * يستخرج Business Logic من SaleController ليبقى الكونترولر
 * مسؤولاً فقط عن استقبال HTTP requests وإرجاع responses.
 */
class SaleService implements SaleServiceInterface
{
    private const MAX_MONEY = 99999999.99;

    private InvoiceRepository $invoiceRepo;
    private ProductRepository $productRepo;
    private CustomerRepository $customerRepo;
    private InventoryEventRepository $inventoryEventRepo;
    private PDO      $db;

    public function __construct(
        InvoiceRepository $invoiceRepo,
        ProductRepository $productRepo,
        CustomerRepository $customerRepo,
        InventoryEventRepository $inventoryEventRepo,
        PDO $db
    ) {
        $this->invoiceRepo        = $invoiceRepo;
        $this->productRepo        = $productRepo;
        $this->customerRepo       = $customerRepo;
        $this->inventoryEventRepo = $inventoryEventRepo;
        $this->db                 = $db;
    }

    // ── Settings helper ───────────────────────────────────────
    public function getSettings(): array
    {
        // استخدام Cache لتجنب استعلام DB في كل عملية بيع
        $cached = \App\Helpers\Cache::get('settings_all');
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        try {
            $rows = $this->db->query('SELECT `key`, `value` FROM settings')->fetchAll();
            $s = [];
            foreach ($rows as $r) { $s[$r['key']] = $r['value']; }

            // حفظ في الكاش لمدة 5 دقائق (300 ثانية)
            \App\Helpers\Cache::set('settings_all', $s, 300);

            return $s;
        } catch (Throwable $e) {
            return [
                'tax_enabled' => '0',
                'tax_rate' => '15',
                'prevent_negative_stock' => '1',
            ];
        }
    }

    // ── Enrich & validate sale items ──────────────────────────

    /**
     * التحقق من المنتجات وإثراء البيانات بمعلومات من قاعدة البيانات.
     *
     * @param  array  $items  بنود السلة الخام من العميل
     * @return array  ['ok' => true, 'items' => [...]] أو ['ok' => false, 'error' => '...', 'code' => int]
     */
    public function enrichItems(array $items, bool $allowPriceOverride = false): array
    {
        $settings = $this->getSettings();
        $preventNegativeStock = (bool) (int) ($settings['prevent_negative_stock'] ?? 1);

        if (count($items) > 500) {
            return ['ok' => false, 'error' => 'A sale cannot contain more than 500 items', 'code' => 400];
        }
        // 1. Validate all items first (no DB calls)
        $productIds = [];
        foreach ($items as $item) {
            if (
                !is_array($item)
                || !isset($item['product_id'], $item['quantity'])
                || filter_var($item['product_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || !is_numeric($item['quantity'])
                || !is_finite((float) $item['quantity'])
                || (float) $item['quantity'] <= 0
            ) {
                return ['ok' => false, 'error' => 'Invalid item data', 'code' => 400];
            }
            $productId = (int) $item['product_id'];
            if (in_array($productId, $productIds, true)) {
                return ['ok' => false, 'error' => 'Duplicate products are not allowed', 'code' => 400];
            }
            $productIds[] = $productId;
        }

        // 2. Batch-fetch all products in a single query (eliminates N+1)
        $products = $this->productRepo->findByIds($productIds);

        // 3. Enrich items using the pre-fetched product map
        $enriched = [];
        foreach ($items as $item) {
            $pid = (int) $item['product_id'];
            $product = $products[$pid] ?? null;
            if (!$product) {
                return ['ok' => false, 'error' => "Product ID {$pid} not found", 'code' => 400];
            }
            $quantity = (float) $item['quantity'];
            if ($preventNegativeStock && $quantity > (float) $product['quantity']) {
                return ['ok' => false, 'error' => "Insufficient stock for product ID {$pid}", 'code' => 409];
            }

            $catalogPrice = (float) $product['price'];
            $requestedPrice = isset($item['price']) && is_numeric($item['price'])
                ? (float) $item['price']
                : $catalogPrice;
            if (!is_finite($requestedPrice) || $requestedPrice < 0) {
                return ['ok' => false, 'error' => 'Invalid item price', 'code' => 400];
            }
            if (!$allowPriceOverride && abs($requestedPrice - $catalogPrice) >= 0.005) {
                return ['ok' => false, 'error' => 'Price override permission required', 'code' => 403];
            }
            // Use float for quantity to support sell-by-weight products (e.g. 0.5 kg)
            $enriched[] = [
                'product_id' => $product['id'],
                'quantity'   => $quantity,
                'price'      => $allowPriceOverride ? $requestedPrice : $catalogPrice,
                'unit_cost'  => (float) ($product['cost'] ?? 0),
                'product'    => $product,
            ];
        }
        return ['ok' => true, 'items' => $enriched];
    }

    // ── Calculate totals ─────────────────────────────────────

    /**
     * حساب الإجماليات (المجموع، الخصم، الضريبة، الإجمالي النهائي).
     *
     * @param  array  $enrichedItems  البنود المُثرَاة
     * @param  float  $discount       الخصم اليدوي
     * @param  array  $data           بيانات الطلب (amount_paid, customer_id, deposit ...)
     * @return array  totals hash
     */
    public function calculateTotals(array $enrichedItems, float $discount, array $data): array
    {
        $settings   = $this->getSettings();
        $taxEnabled = (bool) (int) ($settings['tax_enabled'] ?? 0);
        $taxRate    = (float) ($settings['tax_rate'] ?? 15) / 100;
        if (!is_finite($taxRate) || $taxRate < 0 || $taxRate > 1) {
            throw new \RuntimeException('Tax configuration is invalid');
        }

        $subtotal   = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $enrichedItems));
        if (!is_finite($subtotal) || $subtotal > self::MAX_MONEY) {
            throw new \InvalidArgumentException('Sale subtotal is too large');
        }
        if (!is_finite($discount) || $discount < 0 || $discount > $subtotal) {
            throw new \InvalidArgumentException('Discount must be between zero and the subtotal');
        }
        $taxable    = $subtotal - $discount;
        $tax        = $taxEnabled ? round($taxable * $taxRate, 2) : 0;
        $shippingCost = isset($data['shipping_cost']) ? (float) $data['shipping_cost'] : 0.0;
        if (!is_finite($shippingCost) || $shippingCost < 0 || $shippingCost > 99999999) {
            throw new \InvalidArgumentException('Shipping cost must be a valid non-negative amount');
        }
        $total      = round($taxable + $tax + $shippingCost, 2);
        if (!is_finite($total) || $total > self::MAX_MONEY) {
            throw new \InvalidArgumentException('Sale total is too large');
        }
        
        $amountPaid = isset($data['amount_paid']) ? (float) $data['amount_paid'] : $total;
        if (!is_finite($amountPaid) || $amountPaid < 0) {
            throw new \InvalidArgumentException('Amount paid cannot be negative');
        }
        
        $isUpdate = isset($data['invoice_id']) && (int)$data['invoice_id'] > 0;
        $paymentMethod = $data['payment_method'] ?? 'cash';
        if ($isUpdate && $paymentMethod !== 'credit') {
            if ($amountPaid > $total) {
                $amountPaid = $total;
            }
        }
        
        $changeDue  = max(0, round($amountPaid - $total, 2));
        $amountDue  = max(0, round($total - $amountPaid, 2));

        $customerId = isset($data['customer_id']) && $data['customer_id'] > 0
            ? (int) $data['customer_id']
            : null;
        $isCreditSale = ($data['payment_method'] ?? '') === 'credit';
        $deposit      = $isCreditSale ? $amountPaid : 0;

        return [
            'subtotal'       => $subtotal,
            'discount'       => $discount,
            'tax'            => $tax,
            'shipping_cost'  => $shippingCost,
            'total'          => $total,
            'amount_paid'    => $amountPaid,
            'change_due'     => $changeDue,
            'amount_due'     => $amountDue,
            'customer_id'    => $customerId,
            'is_credit_sale' => $isCreditSale,
            'deposit'        => $deposit,
        ];
    }

    // ── Process sale (main transaction) ──────────────────────

    /**
     * تنفيذ عملية البيع الكاملة داخل transaction.
     *
     * @return array ['ok' => true, 'invoice_id' => int] أو ['ok' => false, 'error' => string]
     */
    /**
     * Hash only validated, client-controlled sale fields.
     *
     * Associative keys are sorted recursively so JSON object key order does not
     * affect the hash. List order remains significant and the idempotency key
     * itself is deliberately excluded.
     */
    public function hashSaleRequest(array $data): string
    {
        unset($data['idempotency_key']);
        $canonical = $this->canonicalizeForHash($data);
        return hash(
            'sha256',
            json_encode(
                $canonical,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * Resolve a committed idempotency record without touching sale side effects.
     *
     * @return array{status:string, data?:array, code?:int, message?:string, invoice_id?:int}
     */
    public function resolveIdempotency(string $key, string $requestHash): array
    {
        $record = $this->invoiceRepo->findIdempotency($key);
        if ($record === null) {
            return ['status' => 'missing'];
        }

        if (!hash_equals((string) $record['request_hash'], $requestHash)) {
            return [
                'status' => 'conflict',
                'code' => 409,
                'message' => 'Idempotency key was already used with a different sale payload',
            ];
        }

        if (empty($record['completed_at']) || !is_string($record['response_json'])) {
            return [
                'status' => 'pending',
                'code' => 409,
                'message' => 'A sale with this idempotency key is still being processed',
            ];
        }

        try {
            $responseData = json_decode($record['response_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            Logger::error('Stored sale idempotency response is invalid', [
                'idempotency_key' => $key,
                'reference' => bin2hex(random_bytes(8)),
                'exception' => get_class($exception),
            ]);
            return [
                'status' => 'error',
                'code' => 500,
                'message' => 'Unable to restore the original sale response',
            ];
        }

        if (!is_array($responseData)) {
            return [
                'status' => 'error',
                'code' => 500,
                'message' => 'Unable to restore the original sale response',
            ];
        }

        return [
            'status' => 'replay',
            'data' => $responseData,
            'code' => (int) ($record['response_code'] ?? 200),
            'message' => (string) ($record['response_message'] ?? 'Sale completed'),
            'invoice_id' => (int) ($record['invoice_id'] ?? 0),
        ];
    }

    private function canonicalizeForHash(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalizeForHash($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeForHash($item);
        }
        return $value;
    }

    private function resultFromIdempotencyResolution(array $resolution): array
    {
        if ($resolution['status'] === 'replay') {
            return [
                'ok' => true,
                'invoice_id' => (int) ($resolution['invoice_id'] ?? 0),
                'is_update' => ((int) ($resolution['code'] ?? 200)) === 200,
                'replayed' => true,
                'response_data' => $resolution['data'],
                'response_code' => (int) ($resolution['code'] ?? 200),
                'response_message' => (string) ($resolution['message'] ?? 'Sale completed'),
            ];
        }

        return [
            'ok' => false,
            'error' => (string) ($resolution['message'] ?? 'Unable to resolve duplicate sale request'),
            'code' => (int) ($resolution['code'] ?? 500),
            'idempotency_error' => true,
            'idempotency_conflict' => $resolution['status'] === 'conflict',
        ];
    }

    private function isDuplicateKeyException(PDOException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        return $driverCode === 1062
            || (string) $exception->getCode() === '23000'
            || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }

    public function changeStatus(int $invoiceId, string $targetStatus): void
    {
        $this->db->beginTransaction();

        try {
            $invoice = $this->invoiceRepo->findHeaderForUpdate($invoiceId);
            if (!$invoice) {
                throw new \DomainException('Invoice not found', 404);
            }

            $currentStatus = (string) $invoice['status'];
            if ($currentStatus === $targetStatus) {
                $this->db->commit();
                return;
            }

            // The only safe transition currently implemented here is the
            // completion of a reserved invoice. Cancellation must go through
            // an inventory and ledger reversal workflow first.
            if (
                $currentStatus !== 'reserved'
                || $targetStatus !== 'completed'
            ) {
                throw new \DomainException(
                    'This invoice status transition requires the inventory reversal workflow'
                );
            }

            $this->invoiceRepo->updateStatus($invoiceId, $targetStatus);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function processSale(array $enrichedItems, array $totals, array $data, array $authUser): array
    {
        $lock = new UpdateOperationLock($this->getUpdateCoordinationStorageDir());
        $lease = $lock->acquireSale([
            'user_id' => isset($authUser['id']) ? (int) $authUser['id'] : null,
            'branch_id' => \App\Services\AuthService::getGlobalBranchId(),
        ]);
        if (!$lease['acquired']) {
            return [
                'ok' => false,
                'error' => 'An update is in progress; the sale was not started.',
                'code' => 409,
                'reason_code' => $lease['reason_code'] ?? 'update_in_progress',
                'owner' => $lease['owner'] ?? null,
            ];
        }

        try {
            return $this->processSaleUnlocked($enrichedItems, $totals, $data, $authUser);
        } finally {
            $lock->release();
        }
    }

    private function processSaleUnlocked(array $enrichedItems, array $totals, array $data, array $authUser): array
    {
        $idempotencyKey = (string) ($data['idempotency_key'] ?? '');
        $requestHash = $this->hashSaleRequest($data);
        $replaceInvoiceId = isset($data['invoice_id']) ? (int) $data['invoice_id'] : 0;
        $existingInvoice  = null;

        $this->db->beginTransaction();
        try {
            try {
                $this->invoiceRepo->claimIdempotency($idempotencyKey, $requestHash);
            } catch (PDOException $exception) {
                if (!$this->isDuplicateKeyException($exception)) {
                    throw $exception;
                }

                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $this->resultFromIdempotencyResolution(
                    $this->resolveIdempotency($idempotencyKey, $requestHash)
                );
            }

            if ($replaceInvoiceId > 0) {
                $existingInvoice = $this->invoiceRepo->findByIdForUpdate($replaceInvoiceId);
                if (!$existingInvoice) {
                    throw new \DomainException('Invoice not found', 404);
                }
                if (($existingInvoice['status'] ?? null) !== 'reserved') {
                    throw new \DomainException('Only reserved invoices can be updated', 409);
                }
            }

            $customerId = $totals['customer_id'];

            // Keep customer, invoice, and account-statement creation atomic.
            // This applies to both new sales and reserved-invoice updates.
            if ($customerId === null && !empty($data['new_customer']['name'])) {
                $newCustomer = $data['new_customer'];
                $customerId = $this->customerRepo->create([
                    'name'            => $newCustomer['name'],
                    'phone'           => $newCustomer['phone'] ?? null,
                    'address'         => $newCustomer['address'] ?? null,
                    'initial_balance' => 0,
                ]);
                if ($customerId <= 0) {
                    throw new \RuntimeException('Customer creation did not return a valid ID');
                }
            }

            if ($replaceInvoiceId > 0) {
                // Restore quantities for old invoice items
                $incrementsByProduct = [];
                foreach ($existingInvoice['items'] as $old) {
                    $productId = (int) $old['product_id'];
                    $incrementsByProduct[$productId] =
                        ($incrementsByProduct[$productId] ?? 0.0)
                        + (float) $old['quantity'];
                }
                $this->productRepo->batchIncrementQuantity(array_map(
                    static fn (int $productId, float $quantity): array => [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                    ],
                    array_keys($incrementsByProduct),
                    array_values($incrementsByProduct)
                ));

                // Batch-fetch updated quantities (eliminates N+1)
                $oldProductIds = array_map(fn($old) => (int) $old['product_id'], $existingInvoice['items']);
                $oldQuantities = $this->productRepo->getQuantitiesByIds($oldProductIds);
                foreach ($existingInvoice['items'] as $old) {
                    $pid = (int) $old['product_id'];
                    $this->inventoryEventRepo->record(
                        $pid,
                        'delete',
                        (float) ($oldQuantities[$pid] ?? 0),
                        (float) $old['quantity']
                    );
                }
                
                // حذف قيود كشف الحساب القديمة الخاصة بهذه الفاتورة
                $this->db->prepare(
                    'DELETE FROM customer_ledger WHERE invoice_id = ? AND branch_id = ?'
                )->execute([$replaceInvoiceId, \App\Services\AuthService::getGlobalBranchId()]);
                
                $this->invoiceRepo->deleteItemsByInvoiceId($replaceInvoiceId);

                $this->invoiceRepo->updateTotals($replaceInvoiceId, [
                    'customer_id'    => $customerId,
                    'subtotal'       => $totals['subtotal'],
                    'discount'       => $totals['discount'],
                    'tax'            => $totals['tax'],
                    'total'          => $totals['total'],
                    'payment_method' => $data['payment_method'],
                    'amount_paid'    => $totals['amount_paid'],
                    'change_due'     => max(0, $totals['change_due']),
                    'amount_due'     => $totals['amount_due'],
                    'status'         => $data['status'] ?? 'completed',
                    'driver_name'    => $data['driver_name'] ?? null,
                    'vehicle_number' => $data['vehicle_number'] ?? null,
                    'shipping_cost'  => $totals['shipping_cost'] ?? 0,
                    'delivery_date'  => $data['delivery_date'] ?? null,
                    'delivery_notes' => $data['delivery_notes'] ?? null,
                ]);
                $invoiceId = $replaceInvoiceId;
                
                // تسجيل القيود الجديدة
                if ($customerId !== null) {
                    $this->recordCustomerLedger($customerId, $invoiceId, $totals, $authUser, $data['status'] ?? 'completed', $data['payment_method'] ?? 'cash');
                }
            } else {
                // إنشاء عميل جديد إذا لزم
                $invoiceId = $this->invoiceRepo->create([
                    'user_id'        => $authUser['id'],
                    'customer_id'    => $customerId,
                    'subtotal'       => $totals['subtotal'],
                    'discount'       => $totals['discount'],
                    'tax'            => $totals['tax'],
                    'total'          => $totals['total'],
                    'payment_method' => $data['payment_method'],
                    'amount_paid'    => $totals['amount_paid'],
                    'change_due'     => max(0, $totals['change_due']),
                    'amount_due'     => $totals['amount_due'],
                    'status'         => $data['status'] ?? 'completed',
                    'driver_name'    => $data['driver_name'] ?? null,
                    'vehicle_number' => $data['vehicle_number'] ?? null,
                    'shipping_cost'  => $totals['shipping_cost'] ?? 0,
                    'delivery_date'  => $data['delivery_date'] ?? null,
                    'delivery_notes' => $data['delivery_notes'] ?? null,
                ]);

                // تسجيل قيود كشف حساب العميل
                if ($customerId !== null) {
                    $this->recordCustomerLedger($customerId, $invoiceId, $totals, $authUser, $data['status'] ?? 'completed', $data['payment_method'] ?? 'cash');
                }
            }

            // إضافة البنود وخصم المخزون
            $decrements = [];
            foreach ($enrichedItems as $item) {
                $this->invoiceRepo->addItem($invoiceId, $item);
                $decrements[] = ['product_id' => $item['product_id'], 'quantity' => $item['quantity']];
                // Calculate new quantity in PHP instead of an extra DB query per item
                $newQuantity = (float) ($item['product']['quantity'] ?? 0) - (float) $item['quantity'];
                $this->inventoryEventRepo->record(
                    $item['product_id'],
                    'sale',
                    $newQuantity,
                    -(float) $item['quantity']
                );
            }
            // Batch-update all product quantities in a single query
            $preventNegativeStock = (bool) (int) ($this->getSettings()['prevent_negative_stock'] ?? 1);
            if ($preventNegativeStock) {
                // Keep the existing one-argument call for the guarded path;
                // the repository default remains fail-closed for other callers.
                $this->productRepo->batchDecrementQuantity($decrements);
            } else {
                $this->productRepo->batchDecrementQuantity($decrements, false);
            }

            $invoice = $this->invoiceRepo->findById($invoiceId);
            if ($invoice === null) {
                throw new \RuntimeException('Created invoice could not be loaded');
            }
            $responseData = [
                'invoice' => $invoice,
                'low_stock_alerts' => $this->getLowStockAlerts($enrichedItems),
            ];
            $responseCode = $replaceInvoiceId > 0 ? 200 : 201;
            $responseMessage = $replaceInvoiceId > 0 ? 'Invoice updated' : 'Sale completed';
            $this->invoiceRepo->completeIdempotency(
                $idempotencyKey,
                $requestHash,
                $invoiceId,
                $responseCode,
                $responseMessage,
                json_encode(
                    $responseData,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );

            $this->db->commit();

            // إضافة نقاط الولاء كـ Background Job (لا تبطئ استجابة البيع)
            if ($customerId !== null && $replaceInvoiceId === 0) {
                try {
                    \App\Helpers\JobQueue::dispatch('earn_loyalty_points', [
                        'branch_id' => \App\Services\AuthService::getGlobalBranchId(),
                        'customer_id' => $customerId,
                        'invoice_id'  => $invoiceId,
                        'total'       => $totals['total'],
                    ], 1); // priority=1 (أعلى من المهام العادية)
                } catch (Throwable $exception) {
                    Logger::warning('Unable to queue loyalty points after sale', [
                        'customer_id' => $customerId,
                        'invoice_id'  => $invoiceId,
                        'reference' => bin2hex(random_bytes(8)),
                        'exception' => get_class($exception),
                    ]);
                }
            }
        } catch (\DomainException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e->getCode() === 404) {
                return ['ok' => false, 'error' => 'Invoice not found', 'code' => 404];
            }
            if ($e->getCode() === 409) {
                return ['ok' => false, 'error' => $e->getMessage(), 'code' => 409];
            }
            Logger::error('Sale transaction failed', Logger::exceptionContext($e));
            return ['ok' => false, 'error' => 'Failed to process sale', 'code' => 500];
        } catch (\RuntimeException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e->getMessage() === 'Insufficient stock or out-of-scope product') {
                return ['ok' => false, 'error' => 'Insufficient stock', 'code' => 409];
            }
            Logger::error('Sale transaction failed', Logger::exceptionContext($e));
            return ['ok' => false, 'error' => 'Failed to process sale', 'code' => 500];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Logger::error('فشل إنشاء عملية بيع', Logger::exceptionContext($e));
            return ['ok' => false, 'error' => 'Failed to process sale', 'code' => 500];
        }

        return [
            'ok' => true,
            'invoice_id' => $invoiceId,
            'customer_id' => $customerId,
            'is_update' => $replaceInvoiceId > 0,
            'replayed' => false,
            'response_data' => $responseData,
            'response_code' => $responseCode,
            'response_message' => $responseMessage,
        ];
    }

    private function getUpdateCoordinationStorageDir(): string
    {
        $configured = \App\Helpers\EnvLoader::get('APP_STORAGE_DIR', '');
        return $configured !== ''
            ? $configured
            : (realpath(__DIR__ . '/../storage') ?: __DIR__ . '/../storage');
    }

    // ── Customer ledger entries ──────────────────────────────

    private function recordCustomerLedger(int $customerId, int $invoiceId, array $totals, array $authUser, string $status = 'completed', string $paymentMethod = 'cash'): void
    {
        $effectivePayment = max(0, $totals['total'] - $totals['amount_due']);
        $paymentDesc = $paymentMethod === 'credit' && $effectivePayment > 0 ? " (عربون {$effectivePayment})" : '';
        
        $statusMarker = $status === 'reserved' ? ' 🕒 (محجوزة - لم تُسلم)' : '';

        $this->customerRepo->addLedgerEntry([
            'customer_id' => $customerId,
            'type'        => 'debit',
            'amount'      => $totals['total'],
            'description' => "فاتورة بيع #{$invoiceId}{$paymentDesc}{$statusMarker}",
            'invoice_id'  => $invoiceId,
            'created_by'  => $authUser['id'],
        ]);

        if ($effectivePayment > 0) {
            $desc = $paymentMethod === 'credit' ? "عربون فاتورة #{$invoiceId}" : "سداد لفاتورة #{$invoiceId}";
            $this->customerRepo->addLedgerEntry([
                'customer_id' => $customerId,
                'type'        => 'credit',
                'amount'      => $effectivePayment,
                'description' => $desc,
                'invoice_id'  => $invoiceId,
                'created_by'  => $authUser['id'],
            ]);
        }
    }

    // ── Low stock check ─────────────────────────────────────

    /**
     * فحص تنبيهات المخزون المنخفض بعد البيع.
     */
    public function getLowStockAlerts(array $enrichedItems): array
    {
        if (empty($enrichedItems)) return [];
        $productIds = array_unique(array_column($enrichedItems, 'product_id'));
        return $this->productRepo->getLowStockByProductIds($productIds);
    }

    // ── Delete invoice ──────────────────────────────────────

    /**
     * حذف فاتورة مع إرجاع الكميات للمخزون.
     *
     * @return array ['ok' => true] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function deleteInvoice(int $invoiceId, ?int $actorId = null): array
    {
        if ($this->db->inTransaction()) {
            return ['ok' => false, 'error' => 'Sale transaction already active', 'code' => 409];
        }

        try {
            $this->db->beginTransaction();
            $invoice = $this->invoiceRepo->findByIdForUpdate($invoiceId);
            if (!$invoice) {
                throw new \RuntimeException('Invoice not found');
            }

            // 1. Aggregate increments and restore all products in one statement.
            $incrementsByProduct = [];
            foreach ($invoice['items'] as $item) {
                $productId = (int) $item['product_id'];
                $incrementsByProduct[$productId] =
                    ($incrementsByProduct[$productId] ?? 0.0)
                    + (float) $item['quantity'];
            }
            $this->productRepo->batchIncrementQuantity(array_map(
                static fn (int $productId, float $quantity): array => [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ],
                array_keys($incrementsByProduct),
                array_values($incrementsByProduct)
            ));

            // 2. Batch-fetch updated quantities in a single SELECT.
            $productIds = array_map(fn($item) => (int) $item['product_id'], $invoice['items']);
            $quantities = $this->productRepo->getQuantitiesByIds($productIds);

            // 3. Record inventory events using the pre-fetched quantities
            foreach ($invoice['items'] as $item) {
                $pid = (int) $item['product_id'];
                $newQty = $quantities[$pid] ?? 0;
                $this->inventoryEventRepo->record(
                    $pid,
                    'delete',
                    (float) $newQty,
                    (float) $item['quantity']
                );
            }
            // حذف قيود كشف الحساب المرتبطة بهذه الفاتورة
            $this->db->prepare(
                'DELETE FROM customer_ledger WHERE invoice_id = ? AND branch_id = ?'
            )->execute([$invoiceId, \App\Services\AuthService::getGlobalBranchId()]);
            
            // حذف الفاتورة (والعناصر المرتبطة بها تحذف تلقائياً بفضل ON DELETE CASCADE)
            if ($this->invoiceRepo->deleteLocked($invoiceId) !== 1) {
                throw new \RuntimeException('Invoice changed concurrently');
            }
            if ($actorId !== null) {
                \App\Helpers\AuditLog::logOrFail(
                    $actorId,
                    'delete_invoice',
                    'invoice',
                    $invoiceId
                );
            }
            $this->db->commit();
        } catch (\RuntimeException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e->getMessage() === 'Invoice not found') {
                return ['ok' => false, 'error' => 'Invoice not found', 'code' => 404];
            }
            if (in_array($e->getMessage(), ['Invoice changed concurrently', 'Out-of-scope product'], true)) {
                return ['ok' => false, 'error' => 'Invoice changed concurrently', 'code' => 409];
            }
            Logger::error('Sale invoice deletion failed', Logger::exceptionContext($e));
            return ['ok' => false, 'error' => 'Failed to delete invoice', 'code' => 500];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            Logger::error('فشل حذف الفاتورة', Logger::exceptionContext($e));
            return ['ok' => false, 'error' => 'Failed to delete invoice', 'code' => 500];
        }

        return ['ok' => true];
    }

    // ── Accessors ───────────────────────────────────────────

    public function getInvoiceRepository(): InvoiceRepository
    {
        return $this->invoiceRepo;
    }
}
