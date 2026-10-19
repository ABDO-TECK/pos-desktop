<?php

namespace App\Requests;

use App\Core\FormRequest;

class DailyReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date' => 'required|date',
            'page' => 'required|integer|min_value:1',
            'limit' => 'required|integer|min_value:1|max_value:500',
        ];
    }
}
