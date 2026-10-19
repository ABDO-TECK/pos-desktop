<?php
namespace App\Contracts;

use App\Models\Product;

interface ProductServiceInterface
{
    public function createProduct(array $data): array;
    public function updateProduct(int $id, array $data, ?int $actorId = null): array;
    public function deleteProduct(int $id, ?int $actorId = null): array;
    public function getLowStockProducts(): array;
    public function getProductModel(): Product;
}
