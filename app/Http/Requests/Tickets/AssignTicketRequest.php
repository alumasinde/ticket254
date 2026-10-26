<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Core\FormRequest;

final class AssignTicketRequest extends FormRequest
{
    /**
     * @return array<string, string|list<string>>
     */
    public function rules(): array
    {
        return [
            'version' => 'required|integer|min:1',
            'team_id' => 'nullable|integer|min:1',
            'assigned_user_id' => 'nullable|integer|min:1',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.required' => 'Reload the ticket before assigning it.',
            'version.integer' => 'Reload the ticket before assigning it.',
        ];
    }
}
