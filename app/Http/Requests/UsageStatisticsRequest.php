<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UsageStatisticsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['from', 'to'] as $key) {
            if ($this->has($key) && ! $this->filled($key)) {
                $this->query->remove($key);
                $this->request->remove($key);
            }
        }
    }

    public function authorize(): bool
    {
        return true; // Route gate protects dashboard and export.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['sometimes', Rule::in(['counter', 'legacy'])],
            'metric' => ['sometimes', Rule::in(['unique', 'total'])],
            'access_method' => ['sometimes', Rule::in(['regular', 'machine'])],
            'from' => ['sometimes', 'date_format:Y-m'],
            'to' => ['sometimes', 'date_format:Y-m', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
        ];
    }
}
