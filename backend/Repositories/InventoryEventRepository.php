<?php

namespace App\Repositories;

use App\Models\InventoryEvent;
use App\Contracts\RepositoryInterface;

class InventoryEventRepository implements RepositoryInterface
{
    private InventoryEvent $model;

    public function __construct(InventoryEvent $model)
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

    public function record(int $productId, string $action, float $newQuantity, float $delta = 0.0): void
    {
        $this->model->record($productId, $action, $newQuantity, $delta);
    }

    public function getAfter(int $lastId, int $limit = 50): array
    {
        return $this->model->getAfter($lastId, $limit);
    }

    public function cleanup(): void
    {
        $this->model->cleanup();
    }

    public function getModel(): InventoryEvent
    {
        return $this->model;
    }
}
