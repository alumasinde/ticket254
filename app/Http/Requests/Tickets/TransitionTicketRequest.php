<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Core\FormRequest;

final class TransitionTicketRequest extends FormRequest
{
    /**
     * @return array<string, string|list<string>>
     */
    public function rules(): array
    {
        return [
            'version' => 'required|integer|min:1',
            'status' => 'required|string|max:30',
            'note' => 'nullable|string|max:5000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.required' => 'Reload the ticket before changing its status.',
            'version.integer' => 'Reload the ticket before changing its status.',
            'status.required' => 'Choose the next status.',
        ];
    }
}
