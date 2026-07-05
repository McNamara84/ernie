<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;
use Throwable;

trait ValidatesEditorDates
{
    /**
     * DataCite date types that may be stored as closed date periods from the editor.
     *
     * @var list<string>
     */
    private const EDITOR_PERIOD_DATE_TYPES = ['created', 'collected', 'valid', 'other'];

    private function validateEditorDates(Validator $validator): void
    {
        $dates = $this->input('dates', []);

        if (! is_array($dates)) {
            return;
        }

        foreach ($dates as $index => $date) {
            if (! is_array($date)) {
                continue;
            }

            $dateType = Str::kebab((string) ($date['dateType'] ?? ''));
            $dateMode = $this->normalizedDateMode($date['dateMode'] ?? null);
            $startDate = $this->nonEmptyDateValue($date['startDate'] ?? null);
            $endDate = $this->nonEmptyDateValue($date['endDate'] ?? null);

            if ($dateMode !== null && ! in_array($dateMode, ['single', 'range'], true)) {
                $validator->errors()->add(
                    "dates.$index.dateMode",
                    '[Dates] Date #'.($index + 1).' has an invalid date mode.',
                );

                continue;
            }

            if ($dateMode === 'single' && $endDate !== null) {
                $validator->errors()->add(
                    "dates.$index.endDate",
                    '[Dates] Date #'.($index + 1).' is set to single-date mode and must not include an end date.',
                );

                continue;
            }

            if ($dateMode === 'range' && $startDate === null) {
                $validator->errors()->add(
                    "dates.$index.startDate",
                    '[Dates] Date #'.($index + 1).' requires a start date for period mode.',
                );
            } elseif ($endDate !== null && $startDate === null) {
                $validator->errors()->add(
                    "dates.$index.startDate",
                    '[Dates] Date #'.($index + 1).' requires a start date when an end date is provided.',
                );
            }

            if ($dateMode === 'range' && $endDate === null) {
                $validator->errors()->add(
                    "dates.$index.endDate",
                    '[Dates] Date #'.($index + 1).' requires an end date for period mode.',
                );
            }

            $hasPeriodIntent = $dateMode === 'range' || ($dateMode === null && $endDate !== null);

            if (! $hasPeriodIntent || $startDate === null || $endDate === null) {
                continue;
            }

            if (! in_array($dateType, self::EDITOR_PERIOD_DATE_TYPES, true)) {
                $validator->errors()->add(
                    "dates.$index.endDate",
                    '[Dates] Date #'.($index + 1).' can only use a period for Created, Collected, Valid, or Other.',
                );

                continue;
            }

            $start = $this->parseComparableDate($startDate);
            $end = $this->parseComparableDate($endDate);

            if ($start !== null && $end !== null && $end < $start) {
                $validator->errors()->add(
                    "dates.$index.endDate",
                    '[Dates] Date #'.($index + 1).' end date must not be before the start date.',
                );
            }
        }
    }

    private function normalizedDateMode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : Str::kebab($trimmed);
    }

    private function nonEmptyDateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function parseComparableDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
