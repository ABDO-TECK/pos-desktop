<?php

namespace App\Requests;

use App\Core\FormRequest;

class UserUpdateRequest extends FormRequest {
    public function rules(): array {
        return [
            'name'      => 'required|string|max:150',
            'email'     => 'required|email',
            'role'      => 'in:admin,cashier',
            'is_active' => 'numeric|in:0,1',
            'password'  => 'string|max:256|strong_password',
            'current_password' => 'string|max:256',
        ];
    }
}
