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
            $time = substr($value, 11);
            preg_match(
                '/^(?<hour>[0-9]{2}):(?<minute>[0-9]{2})(?::(?<second>[0-9]{2}))?(?:[.,][0-9]+)?(?:[Zz]|[+-](?<offsetHour>[0-9]{2}):?(?<offsetMinute>[0-9]{2}))?$/',
                $time,
                $matches,
            );

            $offsetHour = (int) ($matches['offsetHour'] ?? 0);
            $offsetMinute = (int) ($matches['offsetMinute'] ?? 0);
            if (
                $matches === []
                || (int) $matches['hour'] > 23
                || (int) $matches['minute'] > 59
                || (int) ($matches['second'] ?? 0) > 59
                || $offsetHour > 14
                || $offsetMinute > 59
                || ($offsetHour === 14 && $offsetMinute !== 0)
            ) {
                $fail('[Dates] The :attribute must contain a valid time.');

                return;
            }

            try {
                new DateTimeImmutable($value);
            } catch (Throwable) {
                $fail('[Dates] The :attribute must contain a valid time.');
            }
        }
    }
}
