<?php

namespace App\Repositories;

use App\Models\PurchaseInvoice;
use App\Contracts\RepositoryInterface;

class PurchaseInvoiceRepository implements RepositoryInterface
{
    private PurchaseInvoice $model;

    public function __construct(PurchaseInvoice $model)
    {
        $this->model = $model;
    }

    public function all(array $filters = []): array
    {
        return $this->model->getPurchaseInvoices($filters);
    }

    public function create(array $data): int
    {
        return $this->model->createPurchaseInvoice($data);
    }

    public function findById(int $id): ?array
    {
        return $this->model->getPurchaseInvoice($id);
    }

    public function findHeaderForUpdate(int $id): ?array
    {
        return $this->model->getPurchaseInvoiceHeaderForUpdate($id);
    }

    public function getItems(int $id): array
    {
        return $this->model->getPurchaseInvoiceItems($id);
    }

    public function update(int $id, array $data): void
    {
        $this->model->updatePurchaseInvoiceTotals($id, $data);
    }

    public function delete(int $id): void
    {
        $this->model->deletePurchaseInvoice($id);
    }

    public function deleteLocked(int $id): int
    {
        return $this->model->deletePurchaseInvoice($id);
    }

    public function createPurchase(array $data): int
    {
        return $this->model->createPurchase($data);
    }

    public function deletePurchaseInvoiceItems(int $id): void
    {
        $this->model->deletePurchaseInvoiceItems($id);
    }

    public function getPurchases(array $filters = []): array
    {
        return $this->model->getPurchases($filters);
    }

    public function getModel(): PurchaseInvoice
    {
        return $this->model;
    }
}
