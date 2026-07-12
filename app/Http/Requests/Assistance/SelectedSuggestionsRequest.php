<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates bulk suggestion actions for the Assistance workflow.
 */
class SelectedSuggestionsRequest extends FormRequest
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
            'suggestions' => ['required', 'array', 'min:1'],
            'suggestions.*.assistantId' => ['required', 'string'],
            'suggestions.*.suggestionId' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
