<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\KeywordSuggestionService;
use App\Services\PortalSearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for the public portal page.
 *
 * Provides a searchable, filterable interface for browsing published
 * research datasets with an interactive map display.
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly PortalSearchService $searchService,
        private readonly KeywordSuggestionService $keywordService,
    ) {}

    /**
     * Display the portal page with search and filter capabilities.
     */
    public function index(Request $request): Response
    {
        // Compute temporal range once (cached) – used for both validation and frontend
        $temporalRange = $this->searchService->getTemporalRange();

        $rawType = $request->query('type', []);
        $typeSlugs = $this->normalizeTypeSlugs($rawType);

        // Legacy 'doi' needs an exclusion constraint (NOT physical-object)
        // instead of slug enumeration, which may resolve to an empty array
        // when no non-physical-object types exist in the database.
        $excludeType = is_string($rawType) && trim($rawType) === 'doi'
            ? 'physical-object'
            : null;

        $filters = [
            'query' => $request->query('q'),
            'type' => $typeSlugs,
            'exclude_type' => $excludeType,
            'keywords' => array_slice(array_filter(
                (array) $request->query('keywords', []),
                static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
            ), 0, 20),
            'bounds' => $this->parseBounds($request),
            'temporal' => $this->parseTemporal($request, $temporalRange),
            'page' => (int) $request->query('page', 1),
            'per_page' => 20,
        ];

        // Get paginated results (with bounds filter)
        $paginator = $this->searchService->search($filters);

        // Transform resources for frontend
        $transformedResources = collect($paginator->items())
            ->map(fn ($resource) => $this->searchService->transformForPortal($resource))
            ->all();

        // Get map data (WITHOUT bounds filter – all matching markers stay visible)
        $mapData = $this->searchService->getMapData($filters)
            ->map(fn ($resource) => $this->searchService->transformForPortal($resource))
            ->all();

        return Inertia::render('portal', [
            'resources' => $transformedResources,
            'mapData' => $mapData,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
            'filters' => [
                'query' => $filters['query'],
                'type' => array_values($filters['type']),
                'keywords' => array_values($filters['keywords']),
                'bounds' => $filters['bounds'],
                'temporal' => $filters['temporal'],
            ],
            'keywordSuggestions' => $this->keywordService->getSuggestions(),
            'temporalRange' => $temporalRange,
            'resourceTypeFacets' => $this->searchService->getResourceTypeFacets(),
        ]);
    }

    /**
     * Parse and validate bounding box parameters from the request.
     *
     * All four parameters (north, south, east, west) must be present
     * and valid for the bounds filter to be active.
     *
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

        $north = (float) $north;
        $south = (float) $south;
        $east = (float) $east;
        $west = (float) $west;

        // Validate latitude range (-90 to 90) and longitude range (-180 to 180)
        if ($north < -90.0 || $north > 90.0 || $south < -90.0 || $south > 90.0) {
            return null;
        }

        if ($east < -180.0 || $east > 180.0 || $west < -180.0 || $west > 180.0) {
            return null;
        }

        // North must be greater than or equal to south
        if ($north < $south) {
            return null;
        }

        return [
            'north' => $north,
            'south' => $south,
            'east' => $east,
            'west' => $west,
        ];
    }

    /**
     * Parse and validate temporal filter parameters from the request.
     *
     * All three parameters (date_type, year_from, year_to) must be present
     * and valid for the temporal filter to be active. The date type must
     * exist in the computed temporal range (i.e., be active and have
     * published data) to prevent ghost filters.
     *
     * @param  array<string, array{min: int, max: int}>  $temporalRange
     * @return array{dateType: string, yearFrom: int, yearTo: int}|null
     */
    private function parseTemporal(Request $request, array $temporalRange): ?array
    {
        $dateType = $request->query('date_type');
        $yearFrom = $request->query('year_from');
        $yearTo = $request->query('year_to');

        if ($dateType === null || $yearFrom === null || $yearTo === null) {
            return null;
        }

        if (! is_string($dateType) || ! in_array($dateType, ['Created', 'Collected', 'Coverage'], true)) {
            return null;
        }

        // Ensure the date type has published data (present in computed temporal range)
        if (! isset($temporalRange[$dateType])) {
            return null;
        }

        if (! is_numeric($yearFrom) || ! is_numeric($yearTo)) {
            return null;
        }

        $yearFrom = (int) $yearFrom;
        $yearTo = (int) $yearTo;

        $currentYear = (int) date('Y');

        if ($yearFrom < 1900 || $yearFrom > $currentYear + 1) {
            return null;
        }

        if ($yearTo < 1900 || $yearTo > $currentYear + 1) {
            return null;
        }

        if ($yearFrom > $yearTo) {
            return null;
        }

        // Clamp to the computed temporal range for this date type
        // so crafted URLs cannot push the slider into an invalid state
        $rangeMin = $temporalRange[$dateType]['min'];
        $rangeMax = $temporalRange[$dateType]['max'];
        $yearFrom = max($yearFrom, $rangeMin);
        $yearTo = min($yearTo, $rangeMax);

        // After clamping, the range may have inverted – discard if so
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
     * Normalize type query parameter, mapping legacy values to real slugs.
     *
     * Legacy URLs used ?type=doi (all non-PhysicalObject) and ?type=igsn
     * (PhysicalObject only). The new multi-select uses actual resource_type
     * slugs like ?type[]=dataset&type[]=software.  This method handles both
     * formats transparently.
     *
     * @param  mixed  $raw  Raw value from $request->query('type').
     * @return string[]
     */
    private function normalizeTypeSlugs(mixed $raw): array
    {
        // New array format: ?type[]=dataset&type[]=software
        if (is_array($raw)) {
            /** @var string[] $filtered */
            $filtered = array_filter(
                $raw,
                static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
            );

            return array_values(array_unique(array_map('trim', $filtered)));
        }

        // Legacy single-string format: ?type=doi or ?type=igsn
        if (is_string($raw) && trim($raw) !== '') {
            return PortalSearchService::mapLegacyTypeValue(trim($raw)) ?? [];
        }

        return [];
    }
}
