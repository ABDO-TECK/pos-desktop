<?php

namespace App\Repositories;

use App\Models\SupplierLedger;
use App\Contracts\RepositoryInterface;

class SupplierLedgerRepository implements RepositoryInterface
{
    private SupplierLedger $model;

    public function __construct(SupplierLedger $model)
    {
        $this->model = $model;
    }

    public function all(array $filters = []): array
    {
        throw new \BadMethodCallException('Method not supported on ledger repository.');
    }

    public function create(array $data): int
    {
        return $this->model->addLedgerEntry($data);
    }

    public function findById(int $id): ?array
    {
        return $this->model->getLedgerEntry($id);
    }

    public function update(int $id, array $data): void
    {
        $this->model->updateLedgerEntry($id, $data);
    }

    public function delete(int $id): void
    {
        $this->model->deleteLedgerEntry($id);
    }

    public function getLedger(int $supplierId): array
    {
        return $this->model->getLedger($supplierId);
    }

    public function getModel(): SupplierLedger
    {
        return $this->model;
    }
}
