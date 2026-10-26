<?php

declare(strict_types=1);

namespace App\Http\Requests\Tickets;

use App\Core\FormRequest;

final class UpdateTicketRequest extends FormRequest
{
    /**
     * @return array<string, string|list<string>>
     */
    public function rules(): array
    {
        return [
            'version' => 'required|integer|min:1',
            'subject' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:20000',
            'category_id' => 'nullable|integer|min:1',
            'site_id' => 'nullable|integer|min:1',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.required' => 'Reload the ticket before saving changes.',
            'version.integer' => 'Reload the ticket before saving changes.',
        ];
    }
}
