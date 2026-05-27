<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortalSearchAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'search_term' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function searchTerm(): ?string
    {
        $searchTerm = $this->validated('search_term');

        return is_string($searchTerm) ? $searchTerm : null;
    }
}