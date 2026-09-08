<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SystemMetricsPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SystemMetricsHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', Rule::enum(SystemMetricsPeriod::class)],
        ];
    }

    public function period(): SystemMetricsPeriod
    {
        return SystemMetricsPeriod::from((string) $this->validated('period', SystemMetricsPeriod::DAY->value));
    }
}
