<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Core\FormRequest;

final class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|string|email|max:254',
            'password' => 'required|string|min:10|max:255',
            'workspace_name' => 'required|string|max:160',
            'slug' => 'required|string|min:3|max:40|regex:/^[a-z0-9-]+$/',
            'timezone' => 'nullable|string|timezone',
        ];
    }

    public function messages(): array
    {
        return [
            'password.min' => 'Use at least 10 characters for your password.',
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'slug.min' => 'The workspace address must be at least 3 characters.',
            'slug.max' => 'The workspace address may not be longer than 40 characters.',
            'timezone.timezone' => 'Choose a valid timezone.',
        ];
    }
}
