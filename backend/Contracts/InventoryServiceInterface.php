<?php
namespace App\Contracts;

interface InventoryServiceInterface
{
    public function processBulkPurchase(array $data, array $authUser): array;
    public function deletePurchaseInvoice(int $id, ?int $actorId = null): array;
}
