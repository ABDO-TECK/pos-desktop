<?php

namespace App\Repositories;

use App\Models\Expense;
use App\Contracts\RepositoryInterface;

class ExpenseRepository implements RepositoryInterface
{
    private Expense $model;

    public function __construct(Expense $model)
    {
        $this->model = $model;
    }

    public function all(array $filters = []): array
    {
        return $this->model->getAll($filters);
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

    public function getTotalExpensesForDate(string $date): float
    {
        return $this->model->getTotalExpensesForDate($date);
    }

    public function getTotalExpensesForMonth(int $month, int $year): float
    {
        return $this->model->getTotalExpensesForMonth($month, $year);
    }

    public function getModel(): Expense
    {
        return $this->model;
    }
}
