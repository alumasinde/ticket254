<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Core\FormRequest;

final class StoreTicketRequest extends FormRequest
{
    /**
     * @return array<string, string|list<string>>
     */
    public function rules(): array
    {
        return [
            'requester_name' => 'required|string|max:160',
            'requester_email' => 'required|email|max:254',
            'requester_phone' => 'nullable|string|max:32',
            'subject' => 'required|string|max:200',
            'description' => 'required|string|max:20000',
            'category_id' => 'nullable|integer|min:1',
            'site_id' => 'nullable|integer|min:1',
            'team_id' => 'nullable|integer|min:1',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'due_at' => 'nullable|date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'requester_name.required' => 'Enter the requester name.',
            'requester_email.required' => 'Add the requester email so the confirmation can reach them.',
            'subject.required' => 'Enter a short subject.',
            'description.required' => 'Describe what needs attention.',
        ];
    }
}
