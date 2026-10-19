<?php

namespace App\Requests;

use App\Core\FormRequest;

class LoginRequest extends FormRequest {
    public function rules(): array {
        return [
            'email'    => 'required|string|email|max:254',
            'password' => 'required|string|max:256',
        ];
    }
}
