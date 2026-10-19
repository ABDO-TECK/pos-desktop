<?php

namespace App\Requests;

use App\Core\FormRequest;

class SettingsRequest extends FormRequest {
    public function rules(): array {
        return [
            'store_name'  => 'string|max:200',
            'tax_enabled' => 'in:0,1',
            'tax_rate'    => 'numeric|min_value:0|max_value:100',
            'prevent_negative_stock' => 'in:0,1',
            'loyalty_enabled' => 'in:0,1',
            'loyalty_points_per_rial' => 'numeric|min_value:0|max_value:1000',
            'loyalty_rial_per_point' => 'numeric|min_value:0.000001|max_value:100000',
            'store_logo' => 'nullable|string|max:3000000|data_image',
        ];
    }
}
