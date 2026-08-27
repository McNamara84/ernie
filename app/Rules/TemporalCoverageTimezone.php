<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\TemporalCoverageValueService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class TemporalCoverageTimezone implements ValidationRule
{
    public function __construct(private TemporalCoverageValueService $temporalCoverageValueService) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $this->temporalCoverageValueService->normalizeTimezone($value) === null) {
            $fail('The :attribute must be UTC, a valid IANA timezone, or a numeric UTC offset.');
        }
    }
}
