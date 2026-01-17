<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\DateType;
use App\Models\GeoLocation;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Services\DataCiteJsonExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for IGSN (International Generic Sample Number) resources.
 *
 * Handles listing and display of physical sample resources with IGSN identifiers.
 */
class IgsnController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MIN_PER_PAGE = 1;

    private const MAX_PER_PAGE = 100;

    private const DEFAULT_SORT_KEY = 'updated_at';

    private const DEFAULT_SORT_DIRECTION = 'desc';

    private const ALLOWED_SORT_KEYS = [
        'id',
        'igsn',
        'title',
        'sample_type',
        'material',
        'collection_date',
        'upload_status',
        'created_at',
        'updated_at',
    ];

    private const ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];

    /**
     * Display a listing of IGSNs (Physical Sample resources).
     */
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));

        [$sortKey, $sortDirection] = $this->resolveSortState($request);

        $query = $this->buildQuery();
        $this->applySorting($query, $sortKey, $sortDirection);

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $igsns = $paginated->getCollection()->map(function (Resource $resource) {
            return $this->transformResource($resource);
        });

        // Check if current user is admin (only admins can delete IGSNs)
        $user = $request->user();
        $canDelete = $user !== null && $user->role === UserRole::ADMIN;

        return Inertia::render('igsns/index', [
            'igsns' => $igsns,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
                'has_more' => $paginated->hasMorePages(),
            ],
            'sort' => [
                'key' => $sortKey,
                'direction' => $sortDirection,
            ],
            'canDelete' => $canDelete,
        ]);
    }

    /**
     * Delete an IGSN resource.
     *
     * Only admins can delete IGSN resources.
     */
    public function destroy(Request $request, Resource $resource): RedirectResponse
    {
        $user = $request->user();

        // Only admins can delete IGSNs
        if ($user === null || $user->role !== UserRole::ADMIN) {
            abort(403, 'You are not authorized to delete this IGSN.');
        }

        // Verify this is actually an IGSN resource (has igsnMetadata)
        if ($resource->igsnMetadata === null) {
            abort(404, 'IGSN not found.');
        }

        $resource->delete();

        return redirect()
            ->route('igsns.index')
            ->with('success', 'IGSN deleted successfully.');
    }

    /**
     * Export an IGSN resource as DataCite JSON.
     */
    public function exportJson(Resource $resource): StreamedResponse
    {
        // Verify this is actually an IGSN resource (has igsnMetadata)
        if ($resource->igsnMetadata === null) {
            abort(404, 'IGSN not found.');
        }

        // Generate DataCite JSON
        $exporter = new DataCiteJsonExporter();
        $dataCiteData = $exporter->export($resource);

        // Generate filename from IGSN (stored in doi field)
        $igsn = $resource->doi ?? "resource-{$resource->id}";
        $safeIgsn = preg_replace('/[^a-zA-Z0-9._-]/', '-', $igsn) ?? $igsn;
        $filename = "igsn-{$safeIgsn}.json";

        // Return as download
        return response()->streamDownload(function () use ($dataCiteData): void {
            echo json_encode($dataCiteData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Build the base query for IGSN resources.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Resource>
     */
    private function buildQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Get the Physical Object resource type
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();

        $query = Resource::query()
            ->with([
                'titles',
                'igsnMetadata',
                'geoLocations',
                'creators.creatorable',
                'dates.dateType',
            ])
            ->whereHas('igsnMetadata'); // Only resources with IGSN metadata

        if ($physicalObjectType !== null) {
            $query->where('resource_type_id', $physicalObjectType->id);
        }

        return $query;
    }

    /**
     * Transform a Resource into the IGSN list format.
     *
     * @return array<string, mixed>
     */
    private function transformResource(Resource $resource): array
    {
        // Get MainTitle type ID dynamically (don't hardcode ID)
        $mainTitleTypeId = TitleType::where('slug', 'MainTitle')->value('id');

        // Find the main title by type, fallback to first title, then 'Untitled'
        $mainTitleRecord = $resource->titles->firstWhere('title_type_id', $mainTitleTypeId)
            ?? $resource->titles->first();
        $mainTitle = $mainTitleRecord !== null ? $mainTitleRecord->value : 'Untitled';

        $metadata = $resource->igsnMetadata;

        // Get collection date from dates relation (using date_type_id for reliable filtering)
        $collectedDateTypeId = DateType::where('slug', 'Collected')->value('id');
        $collectionDate = $resource->dates->firstWhere('date_type_id', $collectedDateTypeId);

        // Get first geo location
        $geoLocation = $resource->geoLocations->first();

        // Get first creator
        $firstCreator = $resource->creators->first();

        return [
            'id' => $resource->id,
            'igsn' => $resource->doi, // IGSN is stored in DOI field
            'title' => $mainTitle,
            'sample_type' => $metadata?->sample_type,
            'material' => $metadata?->material,
            'collection_date' => $this->formatCollectionDate($collectionDate),
            'location' => $geoLocation->place ?? $this->formatCoordinates($geoLocation),
            'latitude' => $geoLocation?->point_latitude,
            'longitude' => $geoLocation?->point_longitude,
            'upload_status' => $metadata->upload_status ?? 'pending',
            'upload_error_message' => $metadata?->upload_error_message,
            'parent_resource_id' => $metadata?->parent_resource_id,
            'collector' => $this->formatCreator($firstCreator),
            'created_at' => $resource->created_at?->toISOString(),
            'updated_at' => $resource->updated_at?->toISOString(),
        ];
    }

    /**
     * Format collection date from ResourceDate model.
     * Handles both single dates (date_value) and date ranges (start_date/end_date).
     */
    private function formatCollectionDate(?ResourceDate $date): ?string
    {
        if ($date === null) {
            return null;
        }

        // If start_date and end_date are set, format as range
        if ($date->start_date !== null) {
            return $date->end_date !== null
                ? "{$date->start_date} – {$date->end_date}"
                : $date->start_date;
        }

        // Fall back to date_value for single dates
        return $date->date_value;
    }

    /**
     * Format coordinates as a string.
     */
    private function formatCoordinates(?GeoLocation $geoLocation): ?string
    {
        if ($geoLocation === null || $geoLocation->point_latitude === null || $geoLocation->point_longitude === null) {
            return null;
        }

        return sprintf('%.4f, %.4f', $geoLocation->point_latitude, $geoLocation->point_longitude);
    }

    /**
     * Format creator name.
     */
    private function formatCreator(?ResourceCreator $creator): ?string
    {
        if ($creator === null || ! $creator->isPerson()) {
            return null;
        }

        $person = $creator->creatorable;

        if (! $person instanceof Person) {
            return null;
        }

        if ($person->family_name && $person->given_name) {
            return $person->family_name . ', ' . $person->given_name;
        }

        return $person->family_name ?? $person->given_name ?? null;
    }

    /**
     * Resolve sort state from request.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveSortState(Request $request): array
    {
        $sortKey = $request->query('sort', self::DEFAULT_SORT_KEY);
        $sortDirection = $request->query('direction', self::DEFAULT_SORT_DIRECTION);

        if (! is_string($sortKey) || ! in_array($sortKey, self::ALLOWED_SORT_KEYS, true)) {
            $sortKey = self::DEFAULT_SORT_KEY;
        }

        if (! is_string($sortDirection) || ! in_array($sortDirection, self::ALLOWED_SORT_DIRECTIONS, true)) {
            $sortDirection = self::DEFAULT_SORT_DIRECTION;
        }

        return [$sortKey, $sortDirection];
    }

    /**
     * Apply sorting to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Resource>  $query
     */
    private function applySorting(\Illuminate\Database\Eloquent\Builder $query, string $sortKey, string $sortDirection): void
    {
        switch ($sortKey) {
            case 'igsn':
                $query->orderBy('doi', $sortDirection);
                break;

            case 'title':
                $query->orderBy(function ($q) {
                    return $q->select('title as sort_value')
                        ->from('titles')
                        ->whereColumn('titles.resource_id', 'resources.id')
                        ->orderBy('position')
                        ->limit(1);
                }, $sortDirection);
                break;

            case 'sample_type':
            case 'material':
            case 'upload_status':
                $query->orderBy(function ($q) use ($sortKey) {
                    return $q->select($sortKey)
                        ->from('igsn_metadata')
                        ->whereColumn('igsn_metadata.resource_id', 'resources.id')
                        ->limit(1);
                }, $sortDirection);
                break;

            case 'collection_date':
                // Use COALESCE to prefer start_date over date_value (consistent with formatCollectionDate)
                $query->orderBy(function ($q) {
                    return $q->selectRaw('COALESCE(start_date, date_value) as sort_value')
                        ->from('resource_dates')
                        ->join('date_types', 'resource_dates.date_type_id', '=', 'date_types.id')
                        ->whereColumn('resource_dates.resource_id', 'resources.id')
                        ->where('date_types.slug', 'Collected')
                        ->limit(1);
                }, $sortDirection);
                break;

            default:
                $query->orderBy($sortKey, $sortDirection);
                break;
        }
    }
}
