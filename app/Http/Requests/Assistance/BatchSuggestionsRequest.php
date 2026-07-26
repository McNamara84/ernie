<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistance;

use Illuminate\Foundation\Http\FormRequest;

final class BatchSuggestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resource_id' => ['required', 'integer', 'min:1'],
            'suggestions' => ['required', 'array', 'min:1', 'max:250'],
            'suggestions.*.assistant_id' => ['required', 'string', 'max:64'],
            'suggestions.*.suggestion_id' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
