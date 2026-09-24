<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\DataCiteDateNormalizer;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/** Accept ISO dates at year, month, day, or full date-time precision. */
final class EditorDate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || DataCiteDateNormalizer::normalize($value, true) !== $value) {
            $fail('[Dates] The :attribute must use an ISO year, year-month, date, or date-time.');

            return;
        }

        if (str_contains($value, 'T') || str_contains($value, ' ')) {
            try {
                new DateTimeImmutable($value);
            } catch (Throwable) {
                $fail('[Dates] The :attribute must contain a valid time.');
            }
        }
    }
}
