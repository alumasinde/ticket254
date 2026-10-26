<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Core\FormRequest;

final class ChangePriorityRequest extends FormRequest
{
    /**
     * @return array<string, string|list<string>>
     */
    public function rules(): array
    {
        return [
            'version' => 'required|integer|min:1',
            'priority' => 'required|in:low,normal,high,urgent',
            'due_at' => 'nullable|date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.required' => 'Reload the ticket before changing its priority.',
            'version.integer' => 'Reload the ticket before changing its priority.',
            'priority.required' => 'Choose a priority.',
        ];
    }
}
