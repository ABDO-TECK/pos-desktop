<?php
namespace App\Services;

use PDO;
use App\Contracts\LoyaltyServiceInterface;
use App\Repositories\LoyaltyRepository;

class LoyaltyService implements LoyaltyServiceInterface {
    private LoyaltyRepository $loyaltyRepo;
    private PDO $db;

    public function __construct(LoyaltyRepository $loyaltyRepo, PDO $db) {
        $this->loyaltyRepo = $loyaltyRepo;
        $this->db = $db;
    }

    /** هل نظام الولاء مفعل؟ */
    public function isEnabled(): bool {
        return $this->loyaltyRepo->isEnabled();
    }

    /** احسب النقاط المكتسبة لمبلغ معين */
    public function calculatePoints(float $total): int {
        $rate = $this->loyaltyRepo->getPointsPerRial();
        return (int)floor($total * $rate);
    }

    /** أضف نقاط لعميل */
    public function earnPoints(int $customerId, int $invoiceId, float $total): int {
        if (!$this->isEnabled()) return 0;
        $points = $this->calculatePoints($total);
        if ($points <= 0) return 0;

        $this->db->beginTransaction();
        try {
            $this->loyaltyRepo->recordTransaction(
                $customerId,
                $invoiceId,
                $points,
                'earn',
                "اكتساب نقاط من فاتورة #{$invoiceId}"
            );

            $this->loyaltyRepo->updateCustomerPoints($customerId, $points);

            $this->db->commit();
            return $points;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            \App\Helpers\Logger::error('Failed to award loyalty points', \App\Helpers\Logger::exceptionContext($e));
            throw new \RuntimeException('Unable to award loyalty points', 0, $e);
        }
    }

    /** استرداد نقاط */
    public function redeemPoints(int $customerId, int $points, ?int $invoiceId = null): float {
        $this->db->beginTransaction();
        try {
            $current = $this->loyaltyRepo->getCustomerPoints($customerId);
            if ($current < $points) throw new \Exception('رصيد النقاط غير كافي');

            $rate = $this->loyaltyRepo->getRialPerPoint();
            $discount = round($points * $rate, 2);

            $this->loyaltyRepo->recordTransaction(
                $customerId,
                $invoiceId,
                -$points,
                'redeem',
                "استرداد {$points} نقطة"
            );

            $this->loyaltyRepo->updateCustomerPoints($customerId, -$points);

            $this->db->commit();
            return $discount;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e->getMessage() === 'رصيد النقاط غير كافي') {
                throw $e;
            }
            \App\Helpers\Logger::error('Failed to redeem loyalty points', \App\Helpers\Logger::exceptionContext($e));
            throw new \RuntimeException('Unable to redeem loyalty points', 0, $e);
        }
    }

    /** جلب سجل نقاط عميل */
    public function getHistory(int $customerId): array {
        return $this->loyaltyRepo->getHistory($customerId);
    }
}
