<?php

namespace App\Repositories;

use App\Models\Customer;
use App\Contracts\RepositoryInterface;

class CustomerRepository implements RepositoryInterface
{
    private Customer $model;

    public function __construct(Customer $model)
    {
        $this->model = $model;
    }

    public function all(array $filters = []): array
    {
        return $this->model->all($filters);
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

    public function getLedger(int $customerId): array
    {
        return $this->model->getLedger($customerId);
    }

    public function addLedgerEntry(array $data): int
    {
        return $this->model->addLedgerEntry($data);
    }

    public function updateLedgerEntry(int $entryId, array $data): void
    {
        $this->model->updateLedgerEntry($entryId, $data);
    }

    public function getLedgerEntry(int $entryId): ?array
    {
        return $this->model->getLedgerEntry($entryId);
    }

    public function deleteLedgerEntry(int $entryId): void
    {
        $this->model->deleteLedgerEntry($entryId);
    }

    public function getModel(): Customer
    {
        return $this->model;
    }
}
