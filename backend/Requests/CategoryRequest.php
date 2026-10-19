<?php

namespace App\Requests;

use App\Core\FormRequest;

class CategoryRequest extends FormRequest {
    public function rules(): array {
        return [
            'name' => 'required|string|max:100',
        ];
    }
}
