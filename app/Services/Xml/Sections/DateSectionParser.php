<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<dates>/<date>` elements from a DataCite XML document.
 *
 * The output is intentionally rich: each entry contains the normalised
 * `startDate`/`endDate` (YYYY-MM-DD) plus the original raw value so that
 * downstream processing (e.g. coverage extraction) can recover full
 * datetime components.
 */
final readonly class DateSectionParser
{
    /**
     * @return array<int, array{dateType: string, startDate: string, endDate: string, rawValue: string}>
     */
    public function parse(XmlReader $reader): array
    {
        $dateElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="dates"]/*[local-name()="date"]')
            ->get();

        $dates = [];

        foreach ($dateElements as $element) {
            $dateType = $element->getAttribute('dateType');
            $dateValue = $element->getContent();

            if (! is_string($dateValue) || trim($dateValue) === '') {
                continue;
            }

            $dateValue = trim($dateValue);

            $startDate = '';
            $endDate = '';

            if (str_contains($dateValue, '/')) {
                [$start, $end] = explode('/', $dateValue, 2);
                $startDate = self::normalizeDateString(trim($start));
                $endDate = self::normalizeDateString(trim($end));
            } else {
                $startDate = self::normalizeDateString($dateValue);
            }

            $dates[] = [
                'dateType' => Str::kebab(is_string($dateType) && $dateType !== '' ? $dateType : 'other'),
                'startDate' => $startDate,
                'endDate' => $endDate,
                'rawValue' => $dateValue,
            ];
        }

        return $dates;
    }

    /**
     * Normalize a date string to YYYY-MM-DD format.
     *
     * Handles various input formats:
     * - Full date: "2024-01-15" -> "2024-01-15"
     * - Year only: "2024" -> "2024-01-01"
     * - Year-month: "2024-06" -> "2024-06-01"
     * - DateTime: "2024-01-15 10:30:00" -> "2024-01-15"
     * - Invalid/empty: returns empty string
     */
    public static function normalizeDateString(string $dateValue): string
    {
        $dateValue = trim($dateValue);

        if ($dateValue === '') {
            return '';
        }

        if (str_contains($dateValue, ' ')) {
            $dateValue = explode(' ', $dateValue)[0];
        }

        if (str_contains($dateValue, 'T')) {
            $dateValue = explode('T', $dateValue)[0];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
            return $dateValue;
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $dateValue, $matches)) {
            return $matches[1].'-'.$matches[2].'-01';
        }

        if (preg_match('/^\d{4}$/', $dateValue)) {
            return $dateValue.'-01-01';
        }

        $timestamp = strtotime($dateValue);
        if ($timestamp !== false) {
            Log::debug('Date normalization used strtotime fallback', [
                'original' => $dateValue,
                'normalized' => date('Y-m-d', $timestamp),
            ]);

            return date('Y-m-d', $timestamp);
        }

        Log::warning('Could not parse date value during XML import', [
            'value' => $dateValue,
        ]);

        return '';
    }
}
