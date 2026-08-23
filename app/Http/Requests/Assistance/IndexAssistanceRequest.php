<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistance;

use App\Http\Requests\Concerns\ResolvesResourceImpactFilter;
use Illuminate\Foundation\Http\FormRequest;

final class IndexAssistanceRequest extends FormRequest
{
    use ResolvesResourceImpactFilter;

    protected function prepareForValidation(): void
    {
        $this->prepareResourceImpactFilterForValidation();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('access-assistance') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->resourceImpactFilterRules(),
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
