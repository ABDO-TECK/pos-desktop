<?php

namespace App\Requests;

use App\Core\FormRequest;

class LedgerEntryRequest extends FormRequest {
    public function rules(): array {
        return [
            'type'        => 'required|in:debit,credit',
            'amount'      => 'required|numeric|min_value:0.01',
            'description' => 'string|max:500',
        ];
    }
}
