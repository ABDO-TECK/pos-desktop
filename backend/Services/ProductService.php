<?php

namespace App\Services;

use App\Helpers\Logger;
use App\Models\Product;
use App\Models\PriceHistory;
use App\Repositories\ProductRepository;
use App\Contracts\ProductServiceInterface;
use Exception;
use PDO;
use PDOException;
use Throwable;

/**
 * ProductService — منطق الأعمال لإدارة المنتجات.
 *
 * يستخرج Business Logic من ProductController ليسهل إعادة استخدامه
 * من controllers أخرى أو من خطوط أوامر (CLI).
 */
class ProductService implements ProductServiceInterface
{
    private ProductRepository $productRepo;
    private PriceHistory $priceHistory;
    private PDO $db;

    public function __construct(ProductRepository $productRepo, PriceHistory $priceHistory, PDO $db)
    {
        $this->productRepo = $productRepo;
        $this->priceHistory = $priceHistory;
        $this->db = $db;
    }

    // ── Create product ──────────────────────────────────────

    /**
     * إنشاء منتج جديد مع معالجة الباركود التلقائي والباركودات الإضافية.
     *
     * @return array ['ok' => true, 'product' => [...]] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function createProduct(array $data): array
    {
        $validationError = $this->validateNestedProductData($data);
        if ($validationError !== null) {
            return ['ok' => false, 'error' => $validationError, 'code' => 422];
        }

        $main = trim($data['barcode'] ?? '');
        $isAutoBarcode = ($main === '');
        if ($isAutoBarcode) {
            $main = 'TEMP-' . bin2hex(random_bytes(8));
        }

        $extras = Product::normalizeAdditionalBarcodes($main, $data['additional_barcodes'] ?? []);
        $extrasToCheck = $extras;
        if (!empty($data['box_barcode'])) {
            $extrasToCheck[] = $data['box_barcode'];
        }
        $db = $this->db;
        $db->beginTransaction();
        try {
            $this->productRepo->assertBarcodesAvailable(null, $main, $extrasToCheck);
            $data['barcode'] = $main;
            $id = $this->productRepo->create($data);

            if ($isAutoBarcode) {
                // Safeguard against collision with existing manual numeric barcodes
                $candidateBarcode = (string) $id;
                if ($this->productRepo->findByBarcode($candidateBarcode) !== null) {
                    $candidateBarcode = 'PRD-' . $id . '-' . time();
                }
                $this->productRepo->updateMainBarcode($id, $candidateBarcode);
            }

            $this->productRepo->syncAdditionalBarcodes($id, $extras);

            // حفظ المقاسات المرفقة
            if (!empty($data['sizes']) && is_array($data['sizes'])) {
                foreach ($data['sizes'] as $size) {
                    $sizeBarcode = trim($size['barcode'] ?? '');
                    $isSizeAutoBarcode = ($sizeBarcode === '');
                    if ($isSizeAutoBarcode) {
                        $sizeBarcode = 'TEMP-SZ-' . bin2hex(random_bytes(8));
                    }

                    $this->productRepo->assertBarcodesAvailable(null, $sizeBarcode, []);

                    $sizeId = $this->productRepo->create([
                        'name'                => $data['name'] . ' - ' . $size['size_name'],
                        'barcode'             => $sizeBarcode,
                        'box_barcode'         => null,
                        'price'               => $size['price'],
                        'cost'                => $size['cost'] ?? 0,
                        'quantity'            => $size['quantity'] ?? 0,
                        'low_stock_threshold' => $size['low_stock_threshold'] ?? ($data['low_stock_threshold'] ?? (defined('LOW_STOCK_THRESHOLD') ? LOW_STOCK_THRESHOLD : 5)),
                        'category_id'         => $data['category_id'] ?? null,
                        'parent_product_id'   => $id,
                        'size_name'           => $size['size_name'],
                        'units_per_box'       => 1,
                        'sell_by_weight'      => 0,
                        'unit_type'           => $data['unit_type'] ?? 'piece',
                    ]);

                    if ($isSizeAutoBarcode) {
                        $sizeCandidate = (string) $sizeId;
                        if ($this->productRepo->findByBarcode($sizeCandidate) !== null) {
                            $sizeCandidate = 'SZ-' . $sizeId . '-' . time();
                        }
                        $this->productRepo->updateMainBarcode($sizeId, $sizeCandidate);
                    }
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            // 1. Log with full exception details and sanitized payload
            $sanitizedPayload = [
                'name'          => $data['name'] ?? null,
                'barcode'       => $data['barcode'] ?? null,
                'category_id'   => $data['category_id'] ?? null,
                'unit_type'     => $data['unit_type'] ?? null,
                'units_per_box' => $data['units_per_box'] ?? null,
            ];
            Logger::error('فشل إضافة المنتج', Logger::exceptionContext($e, $sanitizedPayload));

            // 2. Intelligent error categorization
            $driverCode = ($e instanceof PDOException && isset($e->errorInfo[1])) ? (int)$e->errorInfo[1] : 0;
            $sqlState   = ($e instanceof PDOException && isset($e->errorInfo[0])) ? (string)$e->errorInfo[0] : (string)$e->getCode();
            $msg        = $e->getMessage();

            // Duplicate barcode (MySQL 1062 / SQLSTATE 23000)
            if ($driverCode === 1062 || ($sqlState === '23000' && (str_contains($msg, 'Duplicate') || str_contains($msg, '1062')))) {
                return ['ok' => false, 'error' => 'هذا الباركود مستخدم لمنتج آخر في قاعدة البيانات. اختر باركوداً غير مكرر.', 'code' => 422];
            }

            // Foreign key failure (MySQL 1452: category_id or parent_product_id not found)
            if ($driverCode === 1452 || str_contains($msg, 'foreign key constraint fails') || str_contains($msg, '1452')) {
                if (str_contains($msg, 'fk_product_category')) {
                    return ['ok' => false, 'error' => 'الفئة المحددة غير موجودة أو تم حذفها من قاعدة البيانات.', 'code' => 422];
                }
                return ['ok' => false, 'error' => 'السجل المرتبط (الفئة أو المنتج الأب) غير موجود في قاعدة البيانات.', 'code' => 422];
            }

            // Missing column due to incomplete migration (MySQL 1054 / SQLSTATE 42S22)
            if ($driverCode === 1054 || $sqlState === '42S22') {
                return ['ok' => false, 'error' => 'قاعدة البيانات بحاجة إلى تحديث (أعمدة غير مكتملة). يرجى تشغيل التحديثات.', 'code' => 503];
            }

            // Missing table (MySQL 1146 / SQLSTATE 42S02)
            if ($driverCode === 1146 || $sqlState === '42S02') {
                return ['ok' => false, 'error' => 'جدول مفقود في قاعدة البيانات. يرجى تشغيل التحديثات.', 'code' => 503];
            }

            // Trigger DEFINER error (MySQL 1449 / 1142)
            if ($driverCode === 1449 || $driverCode === 1142 || str_contains($msg, 'definer')) {
                return ['ok' => false, 'error' => 'خطأ في صلاحيات مشغلات قاعدة البيانات (Trigger Definer). يرجى تحديث النظام لإعادة بناء المشغلات.', 'code' => 500];
            }

            // Invalid numeric / decimal format (MySQL 1366 / 1265 / SQLSTATE 22007)
            if ($driverCode === 1366 || $driverCode === 1265 || $sqlState === '22007') {
                return ['ok' => false, 'error' => 'قيمة عددية غير صالحة تم إدخالها في حقول المنتج (الكمية أو التكلفة أو السعر).', 'code' => 422];
            }

            // Domain exception from barcode assertions
            if ($e instanceof Exception && str_starts_with($e->getMessage(), 'الباركود')) {
                return ['ok' => false, 'error' => $e->getMessage(), 'code' => 422];
            }

            return ['ok' => false, 'error' => 'تعذر إنشاء المنتج في قاعدة البيانات. يرجى مراجعة سجلات الخادم.', 'code' => 500];
        }

        return ['ok' => true, 'product' => $this->productRepo->findById($id)];
    }

    // ── Update product ──────────────────────────────────────

    /**
     * تحديث منتج موجود مع معالجة الباركودات.
     *
     * @return array ['ok' => true, 'product' => [...]] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function updateProduct(int $id, array $data, ?int $actorId = null): array
    {
        $validationError = $this->validateNestedProductData($data);
        if ($validationError !== null) {
            return ['ok' => false, 'error' => $validationError, 'code' => 422];
        }

        $product = $this->productRepo->findById($id);
        if (!$product) {
            return ['ok' => false, 'error' => 'المنتج غير موجود', 'code' => 404];
        }

        $main = trim($data['barcode'] ?? '');
        if ($main === '') {
            $main = $product['barcode'];
        }

        $extras = Product::normalizeAdditionalBarcodes($main, $data['additional_barcodes'] ?? []);
        $extrasToCheck = $extras;
        if (!empty($data['box_barcode'])) {
            $extrasToCheck[] = $data['box_barcode'];
        }
        $db = $this->db;
        $db->beginTransaction();
        try {
            $this->productRepo->assertBarcodesAvailable($id, $main, $extrasToCheck);
            $data['barcode'] = $main;
            $this->productRepo->update($id, $data);

            // تسجيل تغيير السعر (إن وُجد)
            $this->priceHistory->record($id, $product, $data, null);

            $this->productRepo->syncAdditionalBarcodes($id, $extras);

            // مزامنة المقاسات
            // Partial product updates must preserve size children unless sizes are supplied explicitly.
            if (array_key_exists('sizes', $data) && is_array($data['sizes'])) {
                $stmt = $db->prepare(
                    'SELECT id FROM products
                     WHERE parent_product_id = ? AND branch_id = ? AND deleted_at IS NULL'
                );
                $stmt->execute([$id, \App\Services\AuthService::getGlobalBranchId()]);
                $existingSizeIds = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
                $keepSizeIds = [];

                foreach ($data['sizes'] as $size) {
                    $sizeBarcode = trim($size['barcode'] ?? '');
                    $isSizeAutoBarcode = ($sizeBarcode === '');

                    $sizeId = !empty($size['id']) ? (int)$size['id'] : null;

                    if ($sizeId) {
                        if ($isSizeAutoBarcode) {
                            $sizeBarcode = (string)$sizeId;
                        }
                        $this->productRepo->assertBarcodesAvailable($sizeId, $sizeBarcode, []);

                        $this->productRepo->update($sizeId, [
                            'name'                => $data['name'] . ' - ' . $size['size_name'],
                            'barcode'             => $sizeBarcode,
                            'box_barcode'         => null,
                            'price'               => $size['price'],
                            'cost'                => $size['cost'] ?? 0,
                            'quantity'            => $size['quantity'] ?? 0,
                            'low_stock_threshold' => $size['low_stock_threshold'] ?? ($data['low_stock_threshold'] ?? LOW_STOCK_THRESHOLD),
                            'category_id'         => $data['category_id'] ?? null,
                            'parent_product_id'   => $id,
                            'size_name'           => $size['size_name'],
                            'units_per_box'       => 1,
                            'sell_by_weight'      => 0,
                            'unit_type'           => $data['unit_type'] ?? 'piece',
                        ]);
                        $keepSizeIds[] = $sizeId;
                    } else {
                        if ($isSizeAutoBarcode) {
                            $sizeBarcode = 'TEMP-SZ-' . uniqid('', true);
                        }
                        $this->productRepo->assertBarcodesAvailable(null, $sizeBarcode, []);

                        $newSizeId = $this->productRepo->create([
                            'name'                => $data['name'] . ' - ' . $size['size_name'],
                            'barcode'             => $sizeBarcode,
                            'box_barcode'         => null,
                            'price'               => $size['price'],
                            'cost'                => $size['cost'] ?? 0,
                            'quantity'            => $size['quantity'] ?? 0,
                            'low_stock_threshold' => $size['low_stock_threshold'] ?? ($data['low_stock_threshold'] ?? LOW_STOCK_THRESHOLD),
                            'category_id'         => $data['category_id'] ?? null,
                            'parent_product_id'   => $id,
                            'size_name'           => $size['size_name'],
                            'units_per_box'       => 1,
                            'sell_by_weight'      => 0,
                            'unit_type'           => $data['unit_type'] ?? 'piece',
                        ]);

                        if ($isSizeAutoBarcode) {
                            $this->productRepo->updateMainBarcode($newSizeId, (string)$newSizeId);
                        }
                        $keepSizeIds[] = $newSizeId;
                    }
                }

                // حذف المقاسات الملغاة
                $toDeleteIds = array_diff($existingSizeIds, $keepSizeIds);
                foreach ($toDeleteIds as $delId) {
                    $this->productRepo->delete($delId);
                }
            }

            if ($actorId !== null) {
                \App\Helpers\AuditLog::logOrFail(
                    $actorId,
                    'update_product',
                    'product',
                    $id,
                    $product,
                    $data
                );
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $sanitizedPayload = [
                'id'            => $id,
                'name'          => $data['name'] ?? null,
                'barcode'       => $data['barcode'] ?? null,
                'category_id'   => $data['category_id'] ?? null,
                'unit_type'     => $data['unit_type'] ?? null,
            ];
            Logger::error('فشل تحديث المنتج', Logger::exceptionContext($e, $sanitizedPayload));

            $driverCode = ($e instanceof PDOException && isset($e->errorInfo[1])) ? (int)$e->errorInfo[1] : 0;
            $sqlState   = ($e instanceof PDOException && isset($e->errorInfo[0])) ? (string)$e->errorInfo[0] : (string)$e->getCode();
            $msg        = $e->getMessage();

            if ($driverCode === 1062 || ($sqlState === '23000' && (str_contains($msg, 'Duplicate') || str_contains($msg, '1062')))) {
                return ['ok' => false, 'error' => 'هذا الباركود مستخدم لمنتج آخر في قاعدة البيانات. اختر باركوداً غير مكرر.', 'code' => 422];
            }
            if ($driverCode === 1452 || str_contains($msg, 'foreign key constraint fails') || str_contains($msg, '1452')) {
                return ['ok' => false, 'error' => 'السجل المرتبط (الفئة أو المنتج الأب) غير موجود في قاعدة البيانات.', 'code' => 422];
            }
            if ($driverCode === 1054 || $sqlState === '42S22') {
                return ['ok' => false, 'error' => 'قاعدة البيانات بحاجة إلى تحديث (أعمدة غير مكتملة). يرجى تشغيل التحديثات.', 'code' => 503];
            }
            if ($driverCode === 1146 || $sqlState === '42S02') {
                return ['ok' => false, 'error' => 'جدول مفقود في قاعدة البيانات. يرجى تشغيل التحديثات.', 'code' => 503];
            }
            if ($driverCode === 1449 || $driverCode === 1142 || str_contains($msg, 'definer')) {
                return ['ok' => false, 'error' => 'خطأ في صلاحيات مشغلات قاعدة البيانات (Trigger Definer). يرجى تحديث النظام لإعادة بناء المشغلات.', 'code' => 500];
            }
            if ($driverCode === 1366 || $driverCode === 1265 || $sqlState === '22007') {
                return ['ok' => false, 'error' => 'قيمة عددية غير صالحة تم إدخالها في حقول المنتج (الكمية أو التكلفة أو السعر).', 'code' => 422];
            }
            if ($e instanceof Exception && str_starts_with($e->getMessage(), 'الباركود')) {
                return ['ok' => false, 'error' => $e->getMessage(), 'code' => 422];
            }
            return ['ok' => false, 'error' => 'تعذر تحديث المنتج. حاول مرة أخرى.', 'code' => 500];
        }

        return ['ok' => true, 'product' => $this->productRepo->findById($id)];
    }

    private function validateNestedProductData(array $data): ?string
    {
        $barcodes = $data['additional_barcodes'] ?? [];
        if (!is_array($barcodes) || count($barcodes) > 20) {
            return 'A product can have at most 20 additional barcodes.';
        }
        foreach ($barcodes as $barcode) {
            if (!is_string($barcode) && !is_numeric($barcode)) {
                return 'Each additional barcode must be text.';
            }
            if (mb_strlen(trim((string) $barcode), 'UTF-8') > 100) {
                return 'Barcodes cannot exceed 100 characters.';
            }
        }

        $sizes = $data['sizes'] ?? [];
        if (!is_array($sizes) || count($sizes) > 100) {
            return 'A product can have at most 100 sizes.';
        }
        foreach ($sizes as $size) {
            if (!is_array($size)) {
                return 'Each size must be an object.';
            }
            $name = $size['size_name'] ?? null;
            if (!is_string($name) || trim($name) === '' || mb_strlen($name, 'UTF-8') > 100) {
                return 'Each size needs a valid name of at most 100 characters.';
            }
            if (
                !isset($size['price'])
                || !is_numeric($size['price'])
                || !is_finite((float) $size['price'])
                || (float) $size['price'] < 0
            ) {
                return 'Each size needs a non-negative price.';
            }
            foreach (['cost', 'quantity', 'low_stock_threshold'] as $field) {
                if (
                    isset($size[$field])
                    && (!is_numeric($size[$field]) || !is_finite((float) $size[$field]) || (float) $size[$field] < 0)
                ) {
                    return "Size {$field} must be a non-negative number.";
                }
            }
            if (isset($size['barcode']) && mb_strlen((string) $size['barcode'], 'UTF-8') > 100) {
                return 'Size barcodes cannot exceed 100 characters.';
            }
            if (
                isset($size['id'])
                && filter_var($size['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            ) {
                return 'Invalid size identifier.';
            }
        }

        return null;
    }

    // ── Delete product ──────────────────────────────────────

    /**
     * حذف منتج مع فحص المراجع (فواتير ومشتريات).
     *
     * @return array ['ok' => true] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function deleteProduct(int $id, ?int $actorId = null): array
    {
        $product = $this->productRepo->findById($id);
        if (!$product) {
            return ['ok' => false, 'error' => 'المنتج غير موجود', 'code' => 404];
        }

        $refs = $this->productRepo->referenceCounts($id);
        if ($refs['invoice_items'] > 0 || $refs['purchases'] > 0) {
            $parts = [];
            if ($refs['invoice_items'] > 0) {
                $parts[] = sprintf('موجود في %d سطر من فواتير البيع', $refs['invoice_items']);
            }
            if ($refs['purchases'] > 0) {
                $parts[] = sprintf('موجود في %d سجل مشتريات', $refs['purchases']);
            }
            $detail = implode('، ', $parts);
            return [
                'ok'    => false,
                'error' => 'لا يمكن حذف المنتج: ' . $detail
                    . '. احذف الفواتير المرتبطة من صفحة المبيعات أو عدّل سجلات المشتريات، أو أبقِ المنتج للحفاظ على السجل المحاسبي.',
                'code'  => 409,
            ];
        }

        $db = $this->db;
        $db->beginTransaction();
        try {
            $this->productRepo->delete($id);
            if ($actorId !== null) {
                \App\Helpers\AuditLog::logOrFail(
                    $actorId,
                    'delete_product',
                    'product',
                    $id,
                    $product,
                    null
                );
            }
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), '1451')) {
                return ['ok' => false, 'error' => 'لا يمكن حذف المنتج لأنه مرتبط بسجلات أخرى في النظام.', 'code' => 409];
            }
            Logger::error('فشل حذف المنتج', \App\Helpers\Logger::exceptionContext($e));
            return ['ok' => false, 'error' => 'فشل حذف المنتج', 'code' => 500];
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل حذف المنتج', \App\Helpers\Logger::exceptionContext($e));
            return ['ok' => false, 'error' => 'فشل حذف المنتج', 'code' => 500];
        }

        return ['ok' => true];
    }

    // ── Low stock products ──────────────────────────────────

    /**
     * جلب المنتجات ذات المخزون المنخفض.
     */
    public function getLowStockProducts(): array
    {
        return $this->productRepo->getLowStock();
    }

    // ── Accessor ────────────────────────────────────────────

    /**
     * Synchronize the branch catalog with a resumable, opaque checkpoint.
     */
    public function syncCatalog(?string $checkpoint, int $limit = 500): array
    {
        $branchId = AuthService::getGlobalBranchId();
        $limit = max(1, min(500, $limit));
        $state = $checkpoint === null || $checkpoint === ''
            ? null
            : $this->decodeCatalogCheckpoint($checkpoint);

        if ($state !== null && $state['branch_id'] !== $branchId) {
            throw new \InvalidArgumentException('Catalog checkpoint belongs to another branch.');
        }

        if ($state === null || $state['mode'] === 'snapshot') {
            $afterId = $state['position'] ?? 0;
            $catalogVersion = $state['catalog_version'] ?? $this->productRepo->getCatalogVersion();
            $page = $this->productRepo->getCatalogSnapshotPage($afterId, $limit);
            $nextState = $page['has_more']
                ? [
                    'version' => 1,
                    'branch_id' => $branchId,
                    'mode' => 'snapshot',
                    'position' => $page['last_id'],
                    'catalog_version' => $catalogVersion,
                ]
                : [
                    'version' => 1,
                    'branch_id' => $branchId,
                    'mode' => 'delta',
                    'position' => $catalogVersion,
                    'catalog_version' => $catalogVersion,
                ];

            return $this->catalogSyncResponse(
                $page['data'],
                $nextState,
                'snapshot',
                $limit,
                $page['has_more'],
                $afterId === 0,
                $branchId,
                $catalogVersion
            );
        }

        $page = $this->productRepo->getCatalogChangePage($state['position'], $limit);
        $catalogVersion = $this->productRepo->getCatalogVersion();
        $nextState = [
            'version' => 1,
            'branch_id' => $branchId,
            'mode' => 'delta',
            'position' => $page['last_sequence'],
            'catalog_version' => $catalogVersion,
        ];

        return $this->catalogSyncResponse(
            $page['data'],
            $nextState,
            'delta',
            $limit,
            $page['has_more'],
            false,
            $branchId,
            $catalogVersion
        );
    }

    private function catalogSyncResponse(
        array $products,
        array $nextState,
        string $mode,
        int $limit,
        bool $hasMore,
        bool $reset,
        int $branchId,
        int $catalogVersion
    ): array {
        return [
            'data' => $products,
            'catalog_scope' => 'branch:' . $branchId,
            'catalog_version' => $catalogVersion,
            'pagination' => [
                'type' => 'cursor',
                'mode' => $mode,
                'limit' => $limit,
                'has_more' => $hasMore,
                'truncated' => $hasMore,
                'reset' => $reset,
                'next_checkpoint' => $this->encodeCatalogCheckpoint($nextState),
            ],
        ];
    }

    private function encodeCatalogCheckpoint(array $state): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode($state, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = hash_hmac(
            'sha256',
            $payload,
            \App\Middleware\CsrfMiddleware::getCsrfSecret()
        );
        return $payload . '.' . $signature;
    }

    /**
     * @return array{version: int, branch_id: int, mode: string, position: int, catalog_version: int}
     */
    private function decodeCatalogCheckpoint(string $checkpoint): array
    {
        $parts = explode('.', $checkpoint, 2);
        if (
            count($parts) !== 2
            || !hash_equals(
                hash_hmac('sha256', $parts[0], \App\Middleware\CsrfMiddleware::getCsrfSecret()),
                $parts[1]
            )
        ) {
            throw new \InvalidArgumentException('Invalid catalog checkpoint.');
        }

        $payload = strtr($parts[0], '-_', '+/');
        $padding = strlen($payload) % 4;
        if ($padding !== 0) {
            $payload .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($payload, true);
        $state = $decoded === false ? null : json_decode($decoded, true);
        if (
            !is_array($state)
            || ($state['version'] ?? null) !== 1
            || !is_int($state['branch_id'] ?? null)
            || !in_array($state['mode'] ?? null, ['snapshot', 'delta'], true)
            || !is_int($state['position'] ?? null)
            || !is_int($state['catalog_version'] ?? null)
            || $state['position'] < 0
            || $state['catalog_version'] < 0
        ) {
            throw new \InvalidArgumentException('Invalid catalog checkpoint.');
        }

        return $state;
    }

    public function getProductModel(): Product
    {
        return $this->productRepo->getModel();
    }
}
