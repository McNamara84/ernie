<?php

declare(strict_types=1);

namespace App\Services\DateType;

use DateTimeImmutable;

final class DateTypePlausibilityService
{
    /**
     * Expected chronological order for the supported DataCite date types.
     */
    private const DATE_VALUE_ORDER = [
        ['Collected', 'Created'],
        ['Collected', 'Submitted'],
        ['Collected', 'Accepted'],
        ['Collected', 'Issued'],
        ['Collected', 'Available'],
        ['Created', 'Submitted'],
        ['Created', 'Accepted'],
        ['Created', 'Issued'],
        ['Created', 'Available'],
        ['Submitted', 'Accepted'],
        ['Submitted', 'Issued'],
        ['Submitted', 'Available'],
        ['Accepted', 'Issued'],
        ['Accepted', 'Available'],
        ['Issued', 'Available'],
    ];

    /**
     * @param  array<string, array<int, string>>  $dates
     * @return array<int, array<string, mixed>>
     */
    public function hint(array $dates, ?string $resourceDoi = null): array
    {
        $grouped = [];

        foreach (self::DATE_VALUE_ORDER as [$earlier, $later]) {
            $earlierValues = $dates[$earlier] ?? [];
            $laterValues = $dates[$later] ?? [];

            if ($earlierValues === [] || $laterValues === []) {
                continue;
            }

            foreach ($earlierValues as $earlierValue) {
                foreach ($laterValues as $laterValue) {
                    if (! $this->isAfter($earlierValue, $laterValue)) {
                        continue;
                    }

                    $grouped[$earlier][$earlierValue][] = [
                        'type' => $later,
                        'value' => $laterValue,
                    ];
                }
            }
        }

        $warnings = [];

        foreach ($grouped as $earlier => $values) {
            foreach ($values as $earlierValue => $conflicts) {
                $warnings[] = $this->warning(
                    $earlier,
                    (string) $earlierValue,
                    $conflicts,
                    $resourceDoi,
                );
            }
        }

        return $warnings;
    }

    private function isAfter(string $earlierValue, string $laterValue): bool
    {
        $normalizedEarlier = DateTypeNormalizerService::normalize($earlierValue);
        $normalizedLater = DateTypeNormalizerService::normalize($laterValue);

        if ($normalizedEarlier === null || $normalizedLater === null) {
            return false;
        }

        $earlierEnd = str_contains($normalizedEarlier, '/') ? explode('/', $normalizedEarlier, 2)[1] : $normalizedEarlier;
        $laterStart = str_contains($normalizedLater, '/') ? explode('/', $normalizedLater, 2)[0] : $normalizedLater;

        if (! $this->hasReducedPrecision($earlierEnd) && ! $this->hasReducedPrecision($laterStart)) {
            return $earlierEnd > $laterStart;
        }

        $earlierBounds = $this->calendarBounds($earlierEnd);
        $laterBounds = $this->calendarBounds($laterStart);

        if ($earlierBounds === null || $laterBounds === null) {
            return false;
        }

        return $earlierBounds['earliest'] > $laterBounds['latest'];
    }

    private function hasReducedPrecision(string $value): bool
    {
        return preg_match('/^\d{4}(?:-\d{2})?$/', $value) === 1;
    }

    /**
     * @return array{earliest: string, latest: string}|null
     */
    private function calendarBounds(string $value): ?array
    {
        if (preg_match('/^\d{4}$/', $value) === 1) {
            return [
                'earliest' => $value.'-01-01',
                'latest' => $value.'-12-31',
            ];
        }

        if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            $month = DateTimeImmutable::createFromFormat('!Y-m-d', $value.'-01');

            if ($month === false) {
                return null;
            }

            return [
                'earliest' => $value.'-01',
                'latest' => $month->format('Y-m-t'),
            ];
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches) === 1) {
            return [
                'earliest' => $matches[1],
                'latest' => $matches[1],
            ];
        }

        return null;
    }

    /**
     * @param  array<int, array{type: string, value: string}>  $conflicts
     * @return array<string, mixed>
     */
    private function warning(string $earlier, string $earlierValue, array $conflicts, ?string $resourceDoi = null): array
    {
        $conflictText = implode(', ', array_map(
            static fn (array $conflict): string => sprintf(
                '%s (%s)',
                $conflict['type'],
                $conflict['value'],
            ),
            $conflicts,
        ));

        return [
            'suggestion_kind' => 'hint',
            'message' => sprintf(
                '%s (%s) occurs after %s. Please check whether the date values or date types are assigned correctly.',
                $earlier,
                $earlierValue,
                $conflictText,
            ),
            'confidence' => 'medium',
            'is_ambiguous' => true,
            'source_url' => $resourceDoi ? 'https://doi.org/'.$resourceDoi : null,
        ];
    }
}
