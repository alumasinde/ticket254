<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Core\FormRequest;

final class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|string|email|max:254',
            'password' => 'required|string|max:255',
        ];
    }
}
