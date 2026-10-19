<?php
namespace App\Contracts;

interface SupplierServiceInterface
{
    public function addPayment(int $supplierId, array $data, array $authUser): array;
    public function updateLedgerEntry(int $entryId, array $data): array;
    public function recordSinglePurchase(array $data): array;
    public function deleteLedgerEntry(int $entryId): array;
}
