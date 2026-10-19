<?php

namespace App\Requests;

use App\Core\FormRequest;

class PurchaseRequest extends FormRequest {
    public function rules(): array {
        return [
            'supplier_id' => 'required|integer',
            'product_id'  => 'required|integer',
            'quantity'    => 'required|numeric|min_value:0.000001|max_value:9999999.999',
            'cost'        => 'required|numeric|min_value:0|max_value:99999999',
        ];
    }
}
