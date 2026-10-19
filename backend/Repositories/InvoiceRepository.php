<?php

namespace App\Repositories;

use App\Models\Invoice;
use App\Contracts\RepositoryInterface;

class InvoiceRepository implements RepositoryInterface
{
    private Invoice $model;

    public function __construct(Invoice $model)
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

    public function findByIdForUpdate(int $id): ?array
    {
        return $this->model->findByIdForUpdate($id);
    }

    public function findHeaderForUpdate(int $id): ?array
    {
        return $this->model->findHeaderForUpdate($id);
    }

    public function getItems(int $invoiceId): array
    {
        return $this->model->getItems($invoiceId);
    }

    public function create(array $data): int
    {
        return $this->model->create($data);
    }

    public function claimIdempotency(string $key, string $requestHash): void
    {
        $this->model->claimIdempotency($key, $requestHash);
    }

    public function findIdempotency(string $key): ?array
    {
        return $this->model->findIdempotency($key);
    }

    public function completeIdempotency(
        string $key,
        string $requestHash,
        int $invoiceId,
        int $responseCode,
        string $responseMessage,
        string $responseJson
    ): void {
        $this->model->completeIdempotency(
            $key,
            $requestHash,
            $invoiceId,
            $responseCode,
            $responseMessage,
            $responseJson
        );
    }

    public function addItem(int $invoiceId, array $item): void
    {
        $this->model->addItem($invoiceId, $item);
    }

    public function deleteItemsByInvoiceId(int $invoiceId): void
    {
        $this->model->deleteItemsByInvoiceId($invoiceId);
    }

    public function updateTotals(int $id, array $data): void
    {
        $this->model->updateTotals($id, $data);
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->model->updateStatus($id, $status);
    }

    public function update(int $id, array $data): void
    {
        throw new \BadMethodCallException('Use updateTotals or updateStatus instead.');
    }

    public function delete(int $id): void
    {
        $this->model->delete($id);
    }

    public function deleteLocked(int $id): int
    {
        return $this->model->deleteLocked($id);
    }

    public function getDailySummary(string $date): array
    {
        return $this->model->getDailySummary($date);
    }

    public function getTotalCostForDate(string $date): float
    {
        return $this->model->getTotalCostForDate($date);
    }

    public function getTotalCostForMonth(int $month, int $year): float
    {
        return $this->model->getTotalCostForMonth($month, $year);
    }

    public function getTotalProfitForDate(string $date): float
    {
        return $this->model->getTotalProfitForDate($date);
    }

    public function getTotalProfitForMonth(int $month, int $year): float
    {
        return $this->model->getTotalProfitForMonth($month, $year);
    }

    public function getMonthlySummary(int $month, int $year): array
    {
        return $this->model->getMonthlySummary($month, $year);
    }

    public function getTopProducts(int $limit = 10, ?string $fromDate = null, ?string $toDate = null): array
    {
        return $this->model->getTopProducts($limit, $fromDate, $toDate);
    }

    public function getProfitReportTotals(int $month, int $year): array
    {
        return $this->model->getProfitReportTotals($month, $year);
    }

    public function getTopProfitProducts(int $month, int $year, int $limit = 20): array
    {
        return $this->model->getTopProfitProducts($month, $year, $limit);
    }

    public function getDailyProfitBreakdown(int $month, int $year): array
    {
        return $this->model->getDailyProfitBreakdown($month, $year);
    }

    public function getTodayRevenue(): float
    {
        return $this->model->getTodayRevenue();
    }

    public function getMonthRevenue(): float
    {
        return $this->model->getMonthRevenue();
    }

    public function getTodayInvoicesCount(): int
    {
        return $this->model->getTodayInvoicesCount();
    }

    public function getModel(): Invoice
    {
        return $this->model;
    }
}
