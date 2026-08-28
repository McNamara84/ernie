<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use App\Http\Requests\Concerns\ResolvesResourceImpactFilter;
use Illuminate\Foundation\Http\FormRequest;

final class IndexAssessmentRequest extends FormRequest
{
    use ResolvesResourceImpactFilter;

    protected function prepareForValidation(): void
    {
        $this->prepareResourceImpactFilterForValidation();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('access-assessment') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->resourceImpactFilterRules(),
            'include_external_resources' => ['nullable', 'boolean'],
            'include_draft_review_resources' => ['nullable', 'boolean'],
        ];
    }
}
