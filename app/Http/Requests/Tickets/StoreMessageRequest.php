<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Core\FormRequest;

final class StoreMessageRequest extends FormRequest
{
    /**
     * @return array<string, string|list<string>>
     */
    public function rules(): array
    {
        return [
            'visibility' => 'required|in:public,internal',
            'body' => 'required|string|max:20000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'visibility.required' => 'Choose a public reply or an internal note.',
            'body.required' => 'Write a message before sending.',
        ];
    }
}
