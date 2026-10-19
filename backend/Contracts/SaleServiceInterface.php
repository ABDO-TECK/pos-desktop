<?php
namespace App\Contracts;

use App\Repositories\InvoiceRepository;

interface SaleServiceInterface
{
    public function getSettings(): array;
    public function enrichItems(array $items): array;
    public function calculateTotals(array $enrichedItems, float $discount, array $data): array;
    public function hashSaleRequest(array $data): string;
    public function resolveIdempotency(string $key, string $requestHash): array;
    public function processSale(array $enrichedItems, array $totals, array $data, array $authUser): array;
    public function changeStatus(int $invoiceId, string $targetStatus): void;
    public function getLowStockAlerts(array $enrichedItems): array;
    public function deleteInvoice(int $invoiceId, ?int $actorId = null): array;
    public function getInvoiceRepository(): InvoiceRepository;
}
