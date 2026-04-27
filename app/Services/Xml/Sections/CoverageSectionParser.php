<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Support\Xml\XmlElementHelpers;
use DateTimeImmutable;
use DateTimeZone;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses spatial and temporal coverage data from a DataCite XML document.
 *
 * Spatial coverage comes from `<geoLocations>`; temporal coverage is sourced
 * from a `<date dateType="Coverage">` entry produced by {@see DateSectionParser}.
 */
final readonly class CoverageSectionParser
{
    /**
     * @param  array<int, array<string, string>>  $dates  Already-extracted dates (raw shape from DateSectionParser)
     * @return array<int, array<string, mixed>>
     */
    public function parse(XmlReader $reader, array $dates): array
    {
        $coverages = [];

        $temporalCoverage = null;
        foreach ($dates as $date) {
            if (($date['dateType'] ?? '') === 'coverage') {
                $temporalCoverage = $date;
                break;
            }
        }

        $temporalComponents = $this->parseTemporalCoverageComponents($temporalCoverage);

        $geoLocationElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="geoLocations"]/*[local-name()="geoLocation"]')
            ->get();

        if (count($geoLocationElements) === 0 && $temporalCoverage !== null) {
            $coverages[] = [
                'id' => 'coverage-1',
                'type' => 'point',
                'latMin' => '',
                'latMax' => '',
                'lonMin' => '',
                'lonMax' => '',
                'polygonPoints' => [],
                'startDate' => $temporalComponents['startDate'],
                'endDate' => $temporalComponents['endDate'],
                'startTime' => $temporalComponents['startTime'],
                'endTime' => $temporalComponents['endTime'],
                'timezone' => $temporalComponents['timezone'],
                'description' => '',
            ];

            return $coverages;
        }

        $index = 1;
        foreach ($geoLocationElements as $geoLocationIndex => $geoLocation) {
            $coverage = [
                'id' => 'coverage-'.$index,
                'type' => 'point',
                'latMin' => '',
                'latMax' => '',
                'lonMin' => '',
                'lonMax' => '',
                'polygonPoints' => [],
                'startDate' => $temporalComponents['startDate'],
                'endDate' => $temporalComponents['endDate'],
                'startTime' => $temporalComponents['startTime'],
                'endTime' => $temporalComponents['endTime'],
                'timezone' => $temporalComponents['timezone'],
                'description' => '',
            ];

            $geoLocationPath = '//*[local-name()="resource"]/*[local-name()="geoLocations"]/*[local-name()="geoLocation"]['.((int) $geoLocationIndex + 1).']';

            $place = XmlElementHelpers::firstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationPlace"]')
            );
            if ($place !== null) {
                $coverage['description'] = trim($place);
            }

            $latText = XmlElementHelpers::firstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationPoint"]/*[local-name()="pointLatitude"]')
            );
            $lonText = XmlElementHelpers::firstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationPoint"]/*[local-name()="pointLongitude"]')
            );

            if ($latText !== null && $lonText !== null) {
                $coverage['latMin'] = self::formatCoordinate($latText);
                $coverage['lonMin'] = self::formatCoordinate($lonText);
            }

            $west = XmlElementHelpers::firstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationBox"]/*[local-name()="westBoundLongitude"]')
            );
            $east = XmlElementHelpers::firstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationBox"]/*[local-name()="eastBoundLongitude"]')
            );
            $south = XmlElementHelpers::firstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationBox"]/*[local-name()="southBoundLatitude"]')
            );
            $north = XmlElementHelpers::firstStringFromQuery(
                $reader->xpathValue($geoLocationPath.'/*[local-name()="geoLocationBox"]/*[local-name()="northBoundLatitude"]')
            );

            if ($west !== null && $east !== null && $south !== null && $north !== null) {
                $coverage['lonMin'] = self::formatCoordinate($west);
                $coverage['lonMax'] = self::formatCoordinate($east);
                $coverage['latMin'] = self::formatCoordinate($south);
                $coverage['latMax'] = self::formatCoordinate($north);
            }

            $polygonPoints = $this->extractPolygonPoints($reader, $geoLocationPath);
            if (count($polygonPoints) > 0) {
                $coverage['polygonPoints'] = $polygonPoints;
            }

            $coverage['type'] = $this->determineCoverageType($coverage);

            if ($coverage['latMin'] !== '' || $coverage['lonMin'] !== '' ||
                ! empty($coverage['polygonPoints']) ||
                $coverage['description'] !== '' || $coverage['startDate'] !== '') {
                $coverages[] = $coverage;
                $index++;
            }
        }

        return $coverages;
    }

    private static function formatCoordinate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $float = (float) $trimmed;

        return number_format($float, 6, '.', '');
    }

    /**
     * @return array<int, array{lat: float, lon: float}>
     */
    private function extractPolygonPoints(XmlReader $reader, string $geoLocationPath): array
    {
        $points = [];

        $polygonPointElements = $reader
            ->xpathElement($geoLocationPath.'/*[local-name()="geoLocationPolygon"]/*[local-name()="polygonPoint"]')
            ->get();

        foreach ($polygonPointElements as $pointElement) {
            $content = $pointElement->getContent();

            if (! is_array($content)) {
                continue;
            }

            $latElement = XmlElementHelpers::firstElementByKey($content, 'pointLatitude');
            $lonElement = XmlElementHelpers::firstElementByKey($content, 'pointLongitude');

            $latText = XmlElementHelpers::stringValue($latElement);
            $lonText = XmlElementHelpers::stringValue($lonElement);

            if ($latText !== null && $lonText !== null) {
                $points[] = [
                    'lat' => (float) self::formatCoordinate($latText),
                    'lon' => (float) self::formatCoordinate($lonText),
                ];
            }
        }

        return $points;
    }

    /**
     * @param  array<string, mixed>  $coverage
     */
    private function determineCoverageType(array $coverage): string
    {
        if (! empty($coverage['polygonPoints'])) {
            return 'polygon';
        }

        if (($coverage['latMin'] ?? '') !== '' &&
            ($coverage['latMax'] ?? '') !== '' &&
            ($coverage['lonMin'] ?? '') !== '' &&
            ($coverage['lonMax'] ?? '') !== '') {
            return 'box';
        }

        return 'point';
    }

    /**
     * @param  array<string, string>|null  $temporalCoverage
     * @return array{startDate: string, endDate: string, startTime: string, endTime: string, timezone: string}
     */
    private function parseTemporalCoverageComponents(?array $temporalCoverage): array
    {
        $default = [
            'startDate' => '',
            'endDate' => '',
            'startTime' => '',
            'endTime' => '',
            'timezone' => 'UTC',
        ];

        if ($temporalCoverage === null) {
            return $default;
        }

        $rawValue = $temporalCoverage['rawValue'] ?? '';

        if ($rawValue === '') {
            return [
                'startDate' => $temporalCoverage['startDate'] ?? '',
                'endDate' => $temporalCoverage['endDate'] ?? '',
                'startTime' => '',
                'endTime' => '',
                'timezone' => 'UTC',
            ];
        }

        if (str_contains($rawValue, '/')) {
            [$startRaw, $endRaw] = explode('/', $rawValue, 2);
            $startParts = $this->parseDateTimeComponents(trim($startRaw));
            $endParts = $this->parseDateTimeComponents(trim($endRaw));
        } else {
            $startParts = $this->parseDateTimeComponents($rawValue);
            $endParts = ['date' => '', 'time' => '', 'timezone' => ''];
        }

        $hasTimeComponent = $startParts['time'] !== '' || $endParts['time'] !== '';
        $resolvedTimezone = $startParts['timezone'] ?: $endParts['timezone'];

        if ($resolvedTimezone === '' && ! $hasTimeComponent) {
            $resolvedTimezone = 'UTC';
        }

        return [
            'startDate' => $startParts['date'],
            'endDate' => $endParts['date'],
            'startTime' => $startParts['time'],
            'endTime' => $endParts['time'],
            'timezone' => $resolvedTimezone,
        ];
    }

    /**
     * @return array{date: string, time: string, timezone: string}
     */
    private function parseDateTimeComponents(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return ['date' => '', 'time' => '', 'timezone' => ''];
        }

        if (str_contains($value, 'T')) {
            try {
                $timePart = substr($value, (int) strpos($value, 'T') + 1);
                $hasExplicitTimezone = (bool) preg_match('/[Zz]$|[+-]\d{2}:\d{2}$/', $timePart);

                $dt = $hasExplicitTimezone
                    ? new DateTimeImmutable($value)
                    : new DateTimeImmutable($value, new DateTimeZone('UTC'));

                $date = $dt->format('Y-m-d');
                $time = (int) $dt->format('s') !== 0 ? $dt->format('H:i:s') : $dt->format('H:i');
                $timezone = $hasExplicitTimezone ? $this->resolveTimezone($dt) : '';

                return ['date' => $date, 'time' => $time, 'timezone' => $timezone];
            } catch (\Exception) {
                // Fall through to plain date normalisation
            }
        }

        return ['date' => DateSectionParser::normalizeDateString($value), 'time' => '', 'timezone' => ''];
    }

    private function resolveTimezone(DateTimeImmutable $dt): string
    {
        $tz = $dt->getTimezone();
        $tzName = $tz->getName();

        if ($tzName === '' || $tzName === '+00:00' || $tzName === 'Z') {
            return 'UTC';
        }

        return $tzName;
    }
}
