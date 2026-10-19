<?php

namespace App\Requests;

use App\Core\FormRequest;

class SaleStatusRequest extends FormRequest {
    public function rules(): array {
        return [
            'status' => 'required|in:completed,reserved,cancelled',
        ];
    }
}
