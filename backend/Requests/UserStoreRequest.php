<?php

namespace App\Requests;

use App\Core\FormRequest;

class UserStoreRequest extends FormRequest {
    public function rules(): array {
        return [
            'name'     => 'required|string|max:150',
            'email'    => 'required|email',
            'password' => 'required|max:256|strong_password',
            'role'     => 'in:admin,cashier',
        ];
    }
}
