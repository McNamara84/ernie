<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PublicTrafficPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PublicTrafficHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', Rule::enum(PublicTrafficPeriod::class)],
        ];
    }

    public function period(): PublicTrafficPeriod
    {
        return PublicTrafficPeriod::from((string) $this->validated('period', PublicTrafficPeriod::TWELVE_WEEKS->value));
    }
}
