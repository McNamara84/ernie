<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Support\DataCiteDateNormalizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<dates>/<date>` elements from a DataCite XML document.
 *
 * The output is intentionally rich: each entry contains the normalised
 * `startDate`/`endDate` plus the original raw value so that
 * downstream processing (e.g. coverage extraction) can recover full
 * datetime components. Partial DataCite dates keep their precision.
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
     * Normalize a DataCite date string without inventing precision.
     *
     * Handles various input formats:
     * - Full date: "2024-01-15" -> "2024-01-15"
     * - Year only: "2024" -> "2024"
     * - Year-month: "2024-06" -> "2024-06"
     * - DateTime: "2024-01-15 10:30:00" -> "2024-01-15"
     * - Invalid/empty: returns empty string
     */
    public static function normalizeDateString(string $dateValue): string
    {
        $dateValue = trim($dateValue);

        if ($dateValue === '') {
            return '';
        }

        $normalized = DataCiteDateNormalizer::normalize($dateValue);

        if ($normalized !== null) {
            return $normalized;
        }

        Log::warning('Could not parse date value during XML import', [
            'value' => $dateValue,
        ]);

        return '';
    }
}
