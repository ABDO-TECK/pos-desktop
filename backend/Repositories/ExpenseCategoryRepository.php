<?php

namespace App\Repositories;

use App\Models\ExpenseCategory;
use App\Contracts\RepositoryInterface;

class ExpenseCategoryRepository implements RepositoryInterface
{
    private ExpenseCategory $model;

    public function __construct(ExpenseCategory $model)
    {
        $this->model = $model;
    }

    public function all(array $filters = []): array
    {
        return $this->model->getAll();
    }

    public function findById(int $id): ?array
    {
        return $this->model->findById($id);
    }

    public function create(array $data): int
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): void
    {
        $this->model->update($id, $data);
    }

    public function delete(int $id): void
    {
        $this->model->delete($id);
    }

    public function getModel(): ExpenseCategory
    {
        return $this->model;
    }
}
