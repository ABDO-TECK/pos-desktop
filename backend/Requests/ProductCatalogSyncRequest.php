<?php

namespace App\Requests;

use App\Core\FormRequest;

class ProductCatalogSyncRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'checkpoint' => 'string|max:2048',
            'limit' => 'required|integer|min_value:1|max_value:500',
        ];
    }
}
