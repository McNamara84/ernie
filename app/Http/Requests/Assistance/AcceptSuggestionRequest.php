<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistance;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AcceptSuggestionRequest extends FormRequest
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
            'relation_type_id' => [
                'sometimes',
                'integer',
                Rule::exists('relation_types', 'id')->where(
                    fn (Builder $query): Builder => $query->where('is_active', true),
                ),
            ],
        ];
    }
}
