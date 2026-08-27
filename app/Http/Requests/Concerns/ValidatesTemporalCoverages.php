<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\DataCiteDateNormalizer;
use Illuminate\Validation\Validator;

trait ValidatesTemporalCoverages
{
    /** @param array<string, mixed> $coverage */
    private function validateTemporalCoverage(Validator $validator, array $coverage, int $index): void
    {
        $path = "spatialTemporalCoverages.$index";
        $startDate = $this->temporalString($coverage['startDate'] ?? null);
        $endDate = $this->temporalString($coverage['endDate'] ?? null);
        $startTime = $this->temporalString($coverage['startTime'] ?? null);
        $endTime = $this->temporalString($coverage['endTime'] ?? null);
        $timezone = $this->temporalString($coverage['timezone'] ?? null);
        $temporalMode = $this->temporalString($coverage['temporalMode'] ?? null);

        if ($startTime !== null && ($startDate === null || strlen($startDate) !== 10 || ! DataCiteDateNormalizer::isDateOnly($startDate))) {
            $validator->errors()->add(
                "$path.startTime",
                '[Spatial & Temporal Coverage] A start time requires a complete start date.',
            );
        }

        if ($endTime !== null && ($endDate === null || strlen($endDate) !== 10 || ! DataCiteDateNormalizer::isDateOnly($endDate))) {
            $validator->errors()->add(
                "$path.endTime",
                '[Spatial & Temporal Coverage] An end time requires a complete end date.',
            );
        }

        if ($timezone !== null && $startDate === null && $endDate === null) {
            $validator->errors()->add(
                "$path.timezone",
                '[Spatial & Temporal Coverage] A timezone requires a start or end date.',
            );
        }

        if ($temporalMode === 'instant' && ($startDate === null || $endDate !== null)) {
            $validator->errors()->add(
                "$path.temporalMode",
                '[Spatial & Temporal Coverage] A temporal instant requires exactly one start date.',
            );
        }

        $datesAreReversed = $startDate !== null
            && $endDate !== null
            && DataCiteDateNormalizer::isRangeReversed($startDate, $endDate);
        $sameDayTimesAreReversed = $startDate !== null
            && $startDate === $endDate
            && strlen($startDate) === 10
            && $startTime !== null
            && $endTime !== null
            && $this->canonicalTemporalTime($startTime) > $this->canonicalTemporalTime($endTime);

        if ($datesAreReversed || $sameDayTimesAreReversed) {
            $validator->errors()->add(
                "$path.endDate",
                '[Spatial & Temporal Coverage] The end must be after or equal to the start.',
            );
        }
    }

    private function temporalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function canonicalTemporalTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
