<?php
namespace App\Contracts;

interface CustomerServiceInterface
{
    public function createCustomer(array $data): int;
    public function addPayment(int $customerId, array $data, array $authUser): array;
    public function updateLedgerEntry(int $entryId, array $data): array;
    public function deleteLedgerEntry(int $entryId): array;
}
