<?php

namespace App\Requests;

use App\Core\FormRequest;


class CustomerRequest extends FormRequest {
    public function rules(): array {
        return [
            'name'            => 'required|string|max:200',
            'phone'           => 'string|max:30',
            'address'         => 'string|max:1000',
            'initial_balance' => 'numeric',
        ];
    }
}
