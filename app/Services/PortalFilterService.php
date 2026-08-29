<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Normalizes the public portal's query-string filters for every consumer.
 *
 * Keeping this in one service prevents the paginated result list and the
 * asynchronously loaded map from drifting apart as filters evolve.
 */
final class PortalFilterService
{
    /**
     * @param  array<string, array{min: int, max: int}>  $temporalRange
     * @return array{
     *     query: string|null,
     *     type: list<string>,
     *     exclude_type: string|null,
     *     keywords: list<string>,
     *     free_keywords: list<string>,
     *     thesaurus_keywords: list<string>,
     *     datacenter: list<string>,
     *     bounds: array{north: float, south: float, east: float, west: float}|null,
     *     temporal: array{dateType: string, yearFrom: int, yearTo: int}|null,
     *     page: int,
     *     per_page: int
     * }
     */
    public function fromRequest(Request $request, array $temporalRange): array
    {
        $rawType = $request->query('type', []);
        $isLegacyDoi = is_string($rawType) && trim($rawType) === 'doi';

        // The legacy DOI filter means "everything except physical objects".
        // Keep the frontend type list empty so URL generation preserves
        // ?type=doi through the dedicated exclude_type flag.
        $typeSlugs = $isLegacyDoi ? [] : $this->normalizeTypeSlugs($rawType);

        $legacyKeywords = $this->normalizeStringFilters($request->query('keywords', []));
        $freeKeywords = $this->normalizeStringFilters($request->query('free_keywords', []));
        $thesaurusKeywords = $this->normalizeStringFilters($request->query('thesaurus_keywords', []));

        if ($freeKeywords !== [] || $thesaurusKeywords !== []) {
            $legacyKeywords = [];
        }

        $query = $request->query('q');

        return [
            'query' => is_string($query) ? $query : null,
            'type' => $typeSlugs,
            'exclude_type' => $isLegacyDoi ? 'physical-object' : null,
            'keywords' => $legacyKeywords,
            'free_keywords' => $freeKeywords,
            'thesaurus_keywords' => $thesaurusKeywords,
            'datacenter' => $this->normalizeStringFilters($request->query('datacenter', [])),
            'bounds' => $this->parseBounds($request),
            'temporal' => $this->parseTemporal($request, $temporalRange),
            'page' => max(1, (int) $request->query('page', 1)),
            'per_page' => 20,
        ];
    }

    /**
     * Convert normalized backend filters to the public Inertia contract.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function forFrontend(array $filters): array
    {
        return [
            'query' => $filters['query'],
            'type' => array_values($filters['type']),
            'exclude_type' => $filters['exclude_type'],
            'keywords' => array_values($filters['keywords']),
            'freeKeywords' => array_values($filters['free_keywords']),
            'thesaurusKeywords' => array_values($filters['thesaurus_keywords']),
            'datacenter' => array_values($filters['datacenter']),
            'bounds' => $filters['bounds'],
            'temporal' => $filters['temporal'],
        ];
    }

    /**
     * @return array{north: float, south: float, east: float, west: float}|null
     */
    private function parseBounds(Request $request): ?array
    {
        $north = $request->query('north');
        $south = $request->query('south');
        $east = $request->query('east');
        $west = $request->query('west');

        if ($north === null || $south === null || $east === null || $west === null) {
            return null;
        }

        if (! is_numeric($north) || ! is_numeric($south) || ! is_numeric($east) || ! is_numeric($west)) {
            return null;
        }

        $bounds = [
            'north' => (float) $north,
            'south' => (float) $south,
            'east' => (float) $east,
            'west' => (float) $west,
        ];

        if ($bounds['north'] < -90.0 || $bounds['north'] > 90.0
            || $bounds['south'] < -90.0 || $bounds['south'] > 90.0
            || $bounds['east'] < -180.0 || $bounds['east'] > 180.0
            || $bounds['west'] < -180.0 || $bounds['west'] > 180.0
            || $bounds['north'] < $bounds['south']) {
            return null;
        }

        return $bounds;
    }

    /**
     * @param  array<string, array{min: int, max: int}>  $temporalRange
     * @return array{dateType: string, yearFrom: int, yearTo: int}|null
     */
    private function parseTemporal(Request $request, array $temporalRange): ?array
    {
        $dateType = $request->query('date_type');
        $yearFrom = $request->query('year_from');
        $yearTo = $request->query('year_to');

        if (! is_string($dateType)
            || ! in_array($dateType, ['Created', 'Collected', 'Coverage'], true)
            || ! isset($temporalRange[$dateType])
            || ! is_numeric($yearFrom)
            || ! is_numeric($yearTo)) {
            return null;
        }

        $yearFrom = (int) $yearFrom;
        $yearTo = (int) $yearTo;
        $currentYear = (int) date('Y');

        if ($yearFrom < 1900 || $yearFrom > $currentYear + 1
            || $yearTo < 1900 || $yearTo > $currentYear + 1
            || $yearFrom > $yearTo) {
            return null;
        }

        $yearFrom = max($yearFrom, $temporalRange[$dateType]['min']);
        $yearTo = min($yearTo, $temporalRange[$dateType]['max']);

        if ($yearFrom > $yearTo) {
            return null;
        }

        return [
            'dateType' => $dateType,
            'yearFrom' => $yearFrom,
            'yearTo' => $yearTo,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeTypeSlugs(mixed $raw): array
    {
        if (is_array($raw)) {
            return $this->normalizeStringFilters($raw);
        }

        if (is_string($raw) && trim($raw) !== '') {
            return array_values(PortalSearchService::mapLegacyTypeValue(trim($raw)) ?? []);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function normalizeStringFilters(mixed $raw, int $limit = 20): array
    {
        $normalized = array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            (array) $raw,
        );

        return array_values(array_slice(array_unique(array_filter(
            $normalized,
            static fn (string $value): bool => $value !== '',
        )), 0, $limit));
    }
}
