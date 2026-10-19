<?php

namespace App\Requests;

use App\Core\FormRequest;


class SupplierRequest extends FormRequest {
    public function rules(): array {
        return [
            'name'            => 'required|string|max:200',
            'phone'           => 'string|max:30',
            'email'           => 'email|max:150',
            'address'         => 'string|max:1000',
            'initial_balance' => 'numeric',
        ];
    }
}
