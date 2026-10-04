<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistance;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'suggestions.*.relation_type_correction_fingerprint' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'resource_id' => ['required', 'integer', 'min:1'],
            'suggestions' => ['required', 'array', 'min:1', 'max:250'],
            'suggestions.*.assistant_id' => ['required', 'string', 'max:64'],
            'suggestions.*.suggestion_id' => ['required', 'integer', 'min:1'],
            'suggestions.*.relation_type_id' => [
                'sometimes',
                'integer',
                Rule::exists('relation_types', 'id')->where(
                    fn (Builder $query): Builder => $query->where('is_active', true),
                ),
            ],
            'suggestions.*.selected_leaf_ids' => ['sometimes', 'array', 'min:1', 'max:50000'],
            'suggestions.*.selected_leaf_ids.*' => ['string', 'max:1000'],
            'suggestions.*.subject_hierarchy_fingerprint' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'suggestions.*.size_conflict_resolution' => ['sometimes', 'string', Rule::in(['replace'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
