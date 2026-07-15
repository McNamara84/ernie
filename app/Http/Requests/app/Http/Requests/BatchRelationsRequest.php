<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BatchRelationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'suggestion_ids' => ['required', 'array', 'min:1'],
            'suggestion_ids.*' => ['integer', 'distinct'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}