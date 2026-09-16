<?php

declare(strict_types=1);

namespace App\Http\Requests\Assistance;

use App\Http\Requests\Concerns\ResolvesResourceImpactFilter;
use Illuminate\Foundation\Http\FormRequest;

final class IndexAssistanceRequest extends FormRequest
{
    use ResolvesResourceImpactFilter;

    private const int DEFAULT_PER_PAGE = 25;

    private const int MAX_PER_PAGE = 100;

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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function perPage(): int
    {
        return max(1, min((int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));
    }
}
