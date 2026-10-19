<?php

namespace App\Repositories;

use App\Models\PriceHistory;
use App\Contracts\RepositoryInterface;

class PriceHistoryRepository implements RepositoryInterface
{
    private PriceHistory $model;

    public function __construct(PriceHistory $model)
    {
        $this->model = $model;
    }

    public function all(array $filters = []): array
    {
        throw new \BadMethodCallException('Method not supported on log repository.');
    }

    public function create(array $data): int
    {
        throw new \BadMethodCallException('Method not supported on log repository. Use record().');
    }

    public function findById(int $id): ?array
    {
        throw new \BadMethodCallException('Method not supported on log repository.');
    }

    public function update(int $id, array $data): void
    {
        throw new \BadMethodCallException('Method not supported on log repository.');
    }

    public function delete(int $id): void
    {
        throw new \BadMethodCallException('Method not supported on log repository.');
    }

    public function record(int $productId, array $oldData, array $newData, ?int $userId = null): void
    {
        $this->model->record($productId, $oldData, $newData, $userId);
    }

    public function getByProductId(int $productId, int $limit = 50): array
    {
        return $this->model->getByProductId($productId, $limit);
    }

    public function getModel(): PriceHistory
    {
        return $this->model;
    }
}
