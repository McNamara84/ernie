<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UsageStatisticsExportRequest extends UsageStatisticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'mode' => ['sometimes', Rule::in(['counter'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['mode.in' => 'CSV export is only available for COUNTER statistics.'];
    }
}
