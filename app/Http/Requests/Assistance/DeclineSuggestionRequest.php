<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the optional decline reason for a curation-assistant suggestion.
 */
class DeclineSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'relation_type_correction_fingerprint' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
