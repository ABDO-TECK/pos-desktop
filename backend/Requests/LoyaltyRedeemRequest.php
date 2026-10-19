<?php

namespace App\Requests;

use App\Core\FormRequest;

class LoyaltyRedeemRequest extends FormRequest {
    public function rules(): array {
        return [
            'points' => 'required|integer',
        ];
    }
}
