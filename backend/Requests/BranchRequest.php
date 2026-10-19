<?php

namespace App\Requests;

use App\Core\FormRequest;

class BranchRequest extends FormRequest {
    public function rules(): array {
        return [
            'name'    => 'required|string|max:100',
            'address' => 'string|max:255',
            'phone'   => 'string|max:20',
        ];
    }
}
