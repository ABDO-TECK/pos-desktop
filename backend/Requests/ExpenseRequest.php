<?php

namespace App\Requests;

use App\Core\FormRequest;


class ExpenseRequest extends FormRequest {
    public function rules(): array {
        return [
            'category_id'  => 'required|numeric',
            'amount'       => 'required|numeric|min_value:0.01',
            'expense_date' => 'required|date',
            'notes'        => 'string|max:1000',
        ];
    }
}
