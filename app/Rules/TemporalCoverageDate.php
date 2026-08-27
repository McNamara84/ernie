<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\DataCiteDateNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class TemporalCoverageDate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! DataCiteDateNormalizer::isDateOnly($value)) {
            $fail('[Spatial & Temporal Coverage] The :attribute must use YYYY, YYYY-MM, or YYYY-MM-DD format.');
        }
    }
}
