<?php

namespace App\Repositories;

use App\Models\Supplier;
use App\Models\PurchaseInvoice;
use App\Models\SupplierLedger;

use App\Contracts\RepositoryInterface;

class SupplierRepository implements RepositoryInterface
{
    private Supplier $model;
    private PurchaseInvoice $purchaseInvoiceModel;
    private SupplierLedger $ledgerModel;

    public function __construct(Supplier $model, PurchaseInvoice $purchaseInvoiceModel, SupplierLedger $ledgerModel)
    {
        $this->model = $model;
        $this->purchaseInvoiceModel = $purchaseInvoiceModel;
        $this->ledgerModel = $ledgerModel;
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

    public function createPurchaseInvoice(array $data): int
    {
        return $this->purchaseInvoiceModel->createPurchaseInvoice($data);
    }

    public function createPurchase(array $data): int
    {
        return $this->purchaseInvoiceModel->createPurchase($data);
    }

    /** @param list<array<string,mixed>> $items */
    public function createPurchases(array $items): int
    {
        return $this->purchaseInvoiceModel->createPurchases($items);
    }

    public function getPurchaseInvoices(array $filters = []): array
    {
        return $this->purchaseInvoiceModel->getPurchaseInvoices($filters);
    }

    public function getPurchaseInvoice(int $id): ?array
    {
        return $this->purchaseInvoiceModel->getPurchaseInvoice($id);
    }

    public function getPurchaseInvoiceHeaderForUpdate(int $id): ?array
    {
        return $this->purchaseInvoiceModel->getPurchaseInvoiceHeaderForUpdate($id);
    }

    public function getPurchaseInvoiceItems(int $id): array
    {
        return $this->purchaseInvoiceModel->getPurchaseInvoiceItems($id);
    }

    public function deletePurchaseInvoiceItems(int $id): void
    {
        $this->purchaseInvoiceModel->deletePurchaseInvoiceItems($id);
    }

    public function updatePurchaseInvoiceTotals(int $id, array $data): void
    {
        $this->purchaseInvoiceModel->updatePurchaseInvoiceTotals($id, $data);
    }

    public function deletePurchaseInvoice(int $id): int
    {
        return $this->purchaseInvoiceModel->deletePurchaseInvoice($id);
    }

    public function getPurchases(array $filters = []): array
    {
        return $this->purchaseInvoiceModel->getPurchases($filters);
    }

    public function getLedger(int $supplierId): array
    {
        return $this->ledgerModel->getLedger($supplierId);
    }

    public function addLedgerEntry(array $data): int
    {
        return $this->ledgerModel->addLedgerEntry($data);
    }

    public function updateLedgerEntry(int $entryId, array $data): void
    {
        $this->ledgerModel->updateLedgerEntry($entryId, $data);
    }

    public function getLedgerEntry(int $entryId): ?array
    {
        return $this->ledgerModel->getLedgerEntry($entryId);
    }

    public function getTotalSuppliersCount(): int
    {
        return $this->model->getTotalSuppliersCount();
    }

    public function getModel(): Supplier
    {
        return $this->model;
    }
}
