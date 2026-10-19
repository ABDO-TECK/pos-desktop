<?php

namespace App\Repositories;

use App\Models\Category;
use App\Contracts\RepositoryInterface;

class CategoryRepository implements RepositoryInterface
{
    private Category $model;

    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    public function all(array $filters = []): array
    {
        return $this->model->all($filters);
    }

    public function create(array $data): int
    {
        return $this->model->create($data);
    }

    public function findById(int $id): ?array
    {
        return $this->model->find($id);
    }

    public function update(int $id, array $data): void
    {
        $this->model->update($id, $data);
    }

    public function delete(int $id): void
    {
        $this->model->delete($id);
    }

    public function getModel(): Category
    {
        return $this->model;
    }
}
