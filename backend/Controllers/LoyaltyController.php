<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Services\LoyaltyService;
use App\Requests\LoyaltyRedeemRequest;

class LoyaltyController extends Controller {
    private LoyaltyService $loyalty;

    public function __construct(LoyaltyService $loyalty) {
        $this->loyalty = $loyalty;
    }

    public function status() {
        return Response::cacheable(['enabled' => $this->loyalty->isEnabled()], 300);
    }

    public function history(string $customerId) {
        $data = $this->loyalty->getHistory((int)$customerId);
        return Response::success($data);
    }

    public function redeem(string $customerId) {
        $request = new LoyaltyRedeemRequest($this->getBody());
        $data = $request->validated();
        $points = (int)($data['points'] ?? 0);
        if ($points <= 0) return Response::error('عدد النقاط غير صالح', 400);

        try {
            $discount = $this->loyalty->redeemPoints((int)$customerId, $points);
            return Response::success([
                'discount' => $discount,
                'points_used' => $points,
            ], 'تم استرداد النقاط بنجاح');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 400);
        }
    }
}
