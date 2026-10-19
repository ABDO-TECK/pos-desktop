<?php

namespace App\Repositories;

use App\Models\Product;
use App\Repositories\CachedRepository;
use App\Services\AuthService;

/**
 * ProductRepository — طبقة وسيطة بين ProductService و Product Model.
 *
 * ⚠️  ملاحظة معمارية:
 * هذا هو الـ Repository الوحيد في المشروع حالياً. بقية الـ Models
 * (Invoice, Supplier, Customer, ...) تُستخدم مباشرة من الـ Controllers/Services
 * بدون Repository وسيط.
 *
 * تم الإبقاء على هذا الكلاس لأن ProductService يعتمد عليه
 * ولأنه يُسهّل عمل Mock أثناء الاختبارات.
 *
 * عند التوسع مستقبلاً: إما أنشئ Repository لكل Model أو أزل هذا النمط كلياً.
 */
use App\Contracts\RepositoryInterface;

class ProductRepository implements RepositoryInterface
{
    private Product $model;

    public function __construct(Product $model)
    {
        $this->model = $model;
    }

    public function all(array $filters = []): array
    {
        $cacheContext = [
            'branch_id' => AuthService::getGlobalBranchId(),
            'filters' => $filters,
        ];

        return CachedRepository::wrap(
            'products',
            fn() => $this->model->all($filters),
            300,
            json_encode($cacheContext, JSON_THROW_ON_ERROR)
        );
    }

    public function findById(int $id): ?array
    {
        return $this->model->findById($id) ?: null;
    }

    public function getCatalogSnapshotPage(int $afterId, int $limit): array
    {
        return $this->model->getCatalogSnapshotPage($afterId, $limit);
    }

    public function getCatalogVersion(): int
    {
        return $this->model->getCatalogVersion();
    }

    public function getCatalogChangePage(int $afterSequence, int $limit): array
    {
        return $this->model->getCatalogChangePage($afterSequence, $limit);
    }

    /**
     * Batch-fetch multiple products by ID in a single query.
     *
     * @param  int[]  $ids
     * @return array<int, array>  Keyed by product ID
     */
    public function findByIds(array $ids): array
    {
        return $this->model->findByIds($ids);
    }

    /**
     * Fetch only quantities for multiple product IDs.
     *
     * @param  int[]  $ids
     * @return array<int, float>  product_id => quantity
     */
    public function getQuantitiesByIds(array $ids): array
    {
        return $this->model->getQuantitiesByIds($ids);
    }

    public function findByBarcode(string $barcode): ?array
    {
        return $this->model->findByBarcode($barcode) ?: null;
    }

    public function create(array $data): int
    {
        $id = $this->model->create($data);
        \App\Helpers\EventDispatcher::dispatch('product.created', ['id' => $id]);
        return $id;
    }

    public function update(int $id, array $data): void
    {
        $this->model->update($id, $data);
        \App\Helpers\EventDispatcher::dispatch('product.updated', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->model->delete($id);
        \App\Helpers\EventDispatcher::dispatch('product.deleted', ['id' => $id]);
    }

    public function assertBarcodesAvailable(?int $excludeId, string $main, array $extras): void
    {
        $this->model->assertBarcodesAvailable($excludeId, $main, $extras);
    }

    public function updateMainBarcode(int $id, string $barcode): void
    {
        $this->model->updateMainBarcode($id, $barcode);
    }

    public function syncAdditionalBarcodes(int $id, array $barcodes): void
    {
        $this->model->syncAdditionalBarcodes($id, $barcodes);
    }



    public function referenceCounts(int $id): array
    {
        return $this->model->referenceCounts($id);
    }

    public function getLowStock(): array
    {
        return $this->model->getLowStock();
    }

    public function getTotalProductsCount(): int
    {
        return $this->model->getTotalProductsCount();
    }

    public function getLowStockProductsCount(): int
    {
        return $this->model->getLowStockProductsCount();
    }

    public function incrementQuantity(int $id, float $qty): void
    {
        $this->model->incrementQuantity($id, $qty);
    }

    public function batchIncrementQuantity(array $increments): void
    {
        $this->model->batchIncrementQuantity($increments);
    }

    public function batchUpdateCosts(array $updates): void
    {
        $this->model->batchUpdateCosts($updates);
    }

    public function decrementQuantity(int $id, float $qty): void
    {
        $this->model->decrementQuantity($id, $qty);
    }

    public function batchDecrementQuantity(array $decrements, bool $preventNegativeStock = true): void
    {
        if ($preventNegativeStock) {
            // Preserve the guarded legacy call shape for existing consumers.
            $this->model->batchDecrementQuantity($decrements);
        } else {
            $this->model->batchDecrementQuantity($decrements, false);
        }
    }

    public function getLowStockByProductIds(array $ids): array
    {
        return $this->model->getLowStockByProductIds($ids);
    }

    /** يُتيح الوصول للـ Model الأصلي عند الحاجة (backward compatibility) */
    public function getModel(): Product
    {
        return $this->model;
    }
}
