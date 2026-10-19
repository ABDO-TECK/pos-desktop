<?php

namespace App\Requests;

use App\Core\FormRequest;

class PaymentRequest extends FormRequest {
    public function rules(): array {
        return [
            'amount'      => 'required|numeric|min:0.01',
            'type'        => 'required|in:credit,debit',
            'description' => 'string',
        ];
    }
}
