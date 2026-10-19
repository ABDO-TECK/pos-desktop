<?php
namespace App\Contracts;

interface LoyaltyServiceInterface
{
    public function isEnabled(): bool;
    public function calculatePoints(float $total): int;
    public function earnPoints(int $customerId, int $invoiceId, float $total): int;
    public function redeemPoints(int $customerId, int $points, ?int $invoiceId = null): float;
    public function getHistory(int $customerId): array;
}
