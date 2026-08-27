<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Services\TemporalCoverageValueService;
use App\Support\Xml\XmlElementHelpers;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses spatial and temporal coverage data from a DataCite XML document.
 *
 * Spatial coverage comes from `<geoLocations>`; temporal coverage is sourced
 * from a `<date dateType="Coverage">` entry produced by {@see DateSectionParser}.
 */
final readonly class CoverageSectionParser
{
    public function __construct(private TemporalCoverageValueService $temporalCoverageValueService) {}

    /**
     * @param  array<int, array<string, string>>  $dates  Already-extracted dates (raw shape from DateSectionParser)
     * @return array<int, array<string, mixed>>
     */
    public function parse(XmlReader $reader, array $dates): array
    {
        $coverages = [];

        $temporalCoverages = array_values(array_map(
            fn (array $date): array => $this->temporalCoverageValueService->parse($date['rawValue'] ?? ''),
            array_filter($dates, fn (array $date): bool => ($date['dateType'] ?? '') === 'coverage'),
        ));
        $emptyTemporal = $this->temporalCoverageValueService->parse('');

        $geoLocationElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="geoLocations"]/*[local-name()="geoLocation"]')
            ->get();

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
                ...$emptyTemporal,
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
                $coverage['description'] !== '' || $this->hasTemporalData($coverage)) {
                $coverages[] = $coverage;
                $index++;
            }
        }

        return $this->mergeIsoExtentsAndDataCiteFallbacks(
            $coverages,
            $this->parseIsoExtents($reader),
            $temporalCoverages,
        );
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

    /** @param array<string, mixed> $coverage */
    private function hasTemporalData(array $coverage): bool
    {
        return ($coverage['startDate'] ?? '') !== ''
            || ($coverage['endDate'] ?? '') !== ''
            || ($coverage['startTime'] ?? '') !== ''
            || ($coverage['endTime'] ?? '') !== ''
            || ($coverage['timezone'] ?? '') !== '';
    }

    /**
     * ISO extents carry the spatial-to-temporal association that DataCite's
     * separate dates and geoLocations lists cannot express.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseIsoExtents(XmlReader $reader): array
    {
        $elements = $reader
            ->xpathElement('//*[local-name()="identificationInfo"]//*[local-name()="EX_Extent"]')
            ->get();
        $coverages = [];

        foreach ($elements as $index => $element) {
            $position = (int) $index + 1;
            $path = '(//*[local-name()="identificationInfo"]//*[local-name()="EX_Extent"])['.$position.']';
            $coverage = [
                'id' => 'iso-coverage-'.$position,
                'type' => 'point',
                'latMin' => '',
                'latMax' => '',
                'lonMin' => '',
                'lonMax' => '',
                'polygonPoints' => [],
                ...$this->temporalCoverageValueService->parse(''),
                'description' => $this->queryString(
                    $reader,
                    $path.'/*[local-name()="description"]//*[local-name()="CharacterString"]',
                ) ?? '',
            ];

            $west = $this->queryString($reader, $path.'//*[local-name()="EX_GeographicBoundingBox"]/*[local-name()="westBoundLongitude"]//*[local-name()="Decimal"]');
            $east = $this->queryString($reader, $path.'//*[local-name()="EX_GeographicBoundingBox"]/*[local-name()="eastBoundLongitude"]//*[local-name()="Decimal"]');
            $south = $this->queryString($reader, $path.'//*[local-name()="EX_GeographicBoundingBox"]/*[local-name()="southBoundLatitude"]//*[local-name()="Decimal"]');
            $north = $this->queryString($reader, $path.'//*[local-name()="EX_GeographicBoundingBox"]/*[local-name()="northBoundLatitude"]//*[local-name()="Decimal"]');

            if ($west !== null && $east !== null && $south !== null && $north !== null) {
                $coverage['type'] = 'box';
                $coverage['lonMin'] = self::formatCoordinate($west);
                $coverage['lonMax'] = self::formatCoordinate($east);
                $coverage['latMin'] = self::formatCoordinate($south);
                $coverage['latMax'] = self::formatCoordinate($north);
            }

            $begin = $this->queryString($reader, $path.'//*[local-name()="TimePeriod"]/*[local-name()="beginPosition"]');
            $end = $this->queryString($reader, $path.'//*[local-name()="TimePeriod"]/*[local-name()="endPosition"]');
            $instant = $this->queryString($reader, $path.'//*[local-name()="TimeInstant"]/*[local-name()="timePosition"]');
            $temporal = $instant !== null
                ? $this->temporalCoverageValueService->parse($instant)
                : $this->temporalCoverageValueService->parse(($begin ?? '').'/'.($end ?? ''));
            $coverage = array_merge($coverage, $temporal);

            if ($this->hasSpatialData($coverage) || $this->hasTemporalData($coverage)) {
                $coverages[] = $coverage;
            }
        }

        return $coverages;
    }

    private function queryString(XmlReader $reader, string $query): ?string
    {
        return XmlElementHelpers::firstStringFromQuery($reader->xpathValue($query));
    }

    /**
     * @param  array<int, array<string, mixed>>  $dataCiteSpatial
     * @param  array<int, array<string, mixed>>  $isoExtents
     * @param  array<int, array<string, string>>  $dataCiteTemporal
     * @return array<int, array<string, mixed>>
     */
    private function mergeIsoExtentsAndDataCiteFallbacks(
        array $dataCiteSpatial,
        array $isoExtents,
        array $dataCiteTemporal,
    ): array {
        $result = [];
        $usedSpatial = [];
        $usedTemporal = [];
        $allowPositionalFallback = count($isoExtents) === count($dataCiteSpatial);

        foreach ($isoExtents as $isoExtent) {
            $matchedIndex = $this->matchingSpatialIndex(
                $isoExtent,
                $dataCiteSpatial,
                $usedSpatial,
                $allowPositionalFallback,
            );
            if ($matchedIndex !== null) {
                $usedSpatial[$matchedIndex] = true;
                $isoExtent = array_merge($dataCiteSpatial[$matchedIndex], array_filter(
                    $isoExtent,
                    static fn (mixed $value, string $key): bool => in_array($key, [
                        'startDate', 'endDate', 'startTime', 'endTime', 'timezone', 'temporalMode',
                    ], true) || ($key === 'description' && $value !== ''),
                    ARRAY_FILTER_USE_BOTH,
                ));
            }

            foreach ($dataCiteTemporal as $temporalIndex => $temporal) {
                if ($this->sameTemporalCoverage($isoExtent, $temporal)) {
                    $usedTemporal[$temporalIndex] = true;
                }
            }

            $result[] = $isoExtent;
        }

        foreach ($dataCiteSpatial as $spatialIndex => $spatial) {
            if (! isset($usedSpatial[$spatialIndex])) {
                $result[] = $spatial;
            }
        }

        foreach ($dataCiteTemporal as $temporalIndex => $temporal) {
            if (isset($usedTemporal[$temporalIndex]) || ! $this->hasTemporalData($temporal)) {
                continue;
            }

            $result[] = [
                'id' => 'temporal-coverage-'.($temporalIndex + 1),
                'type' => 'point',
                'latMin' => '',
                'latMax' => '',
                'lonMin' => '',
                'lonMax' => '',
                'polygonPoints' => [],
                ...$temporal,
                'description' => '',
            ];
        }

        foreach ($result as $index => &$coverage) {
            $coverage['id'] = 'coverage-'.($index + 1);
        }
        unset($coverage);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $needle
     * @param  array<int, array<string, mixed>>  $haystack
     * @param  array<int, bool>  $used
     */
    private function matchingSpatialIndex(array $needle, array $haystack, array $used, bool $allowPositionalFallback): ?int
    {
        $signature = $this->spatialSignature($needle);
        if ($signature !== null) {
            foreach ($haystack as $index => $candidate) {
                if (! isset($used[$index]) && $this->spatialSignature($candidate) === $signature) {
                    return $index;
                }
            }
        }

        if ($allowPositionalFallback) {
            foreach (array_keys($haystack) as $index) {
                if (! isset($used[$index])) {
                    return $index;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $coverage */
    private function spatialSignature(array $coverage): ?string
    {
        if (($coverage['type'] ?? '') === 'box') {
            return implode('|', array_map(
                static fn (string $key): string => number_format((float) ($coverage[$key] ?? 0), 6, '.', ''),
                ['latMin', 'latMax', 'lonMin', 'lonMax'],
            ));
        }

        if (($coverage['latMin'] ?? '') !== '' && ($coverage['lonMin'] ?? '') !== '') {
            return number_format((float) $coverage['latMin'], 6, '.', '').'|'
                .number_format((float) $coverage['lonMin'], 6, '.', '');
        }

        return null;
    }

    /** @param array<string, mixed> $coverage */
    private function hasSpatialData(array $coverage): bool
    {
        return ($coverage['latMin'] ?? '') !== ''
            || ($coverage['lonMin'] ?? '') !== ''
            || ! empty($coverage['polygonPoints'])
            || ($coverage['description'] ?? '') !== '';
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function sameTemporalCoverage(array $left, array $right): bool
    {
        foreach (['startDate', 'endDate', 'startTime', 'endTime', 'timezone', 'temporalMode'] as $key) {
            if (($left[$key] ?? '') !== ($right[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }
}
