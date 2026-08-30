<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Enums\DataCiteUrlUpdateScope;
use App\Enums\UserRole;
use App\Exceptions\JsonValidationException;
use App\Http\Requests\IndexIgsnsRequest;
use App\Models\Datacenter;
use App\Models\DataCiteUrlUpdateRun;
use App\Models\DateType;
use App\Models\GeoLocation;
use App\Models\IgsnMetadata;
use App\Models\IgsnRegistrationRun;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteLinkedDataExporter;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteUrlUpdateRunPresenter;
use App\Services\IgsnRegistrationExclusionService;
use App\Services\IgsnRegistrationRunPresenterService;
use App\Services\JsonSchemaValidator;
use App\Services\ListingCountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    public function __construct(
        private readonly DataCiteUrlUpdateRunPresenter $dataCiteUrlUpdateRunPresenter,
        private readonly ListingCountService $listingCountService,
        private readonly IgsnRegistrationRunPresenterService $igsnRegistrationRunPresenter,
        private readonly IgsnRegistrationExclusionService $igsnRegistrationExclusion,
    ) {}

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
     * Minimum number of characters for a search query.
     * Must match the frontend constant in igsn-search-input.tsx.
     */
    private const MIN_SEARCH_LENGTH = 3;

    /**
     * Display a listing of IGSNs (Physical Sample resources).
     */
    public function index(IndexIgsnsRequest $request): Response
    {
        $criteria = $this->resolveListingCriteria($request);
        $page = $criteria['page'];
        $perPage = $criteria['per_page'];
        $search = $criteria['search'];
        $prefix = $criteria['prefix'];
        $status = $criteria['status'];
        $datacenterId = $criteria['datacenter_id'];
        $withoutDatacenter = $criteria['without_datacenter'];
        $sortKey = $criteria['sort'];
        $sortDirection = $criteria['direction'];

        $base = $this->baseQuery();
        $query = $this->buildQueryFrom($base);

        $this->applyFilters($query, $prefix, $status, $datacenterId, $withoutDatacenter);
        $this->applySearch($query, $search);
        $this->applySorting($query, $sortKey, $sortDirection);

        $paginated = $query->simplePaginate($perPage, ['*'], 'page', $page);
        $filterFingerprint = $this->listingCountService->fingerprint($this->countCriteria($criteria));

        // Resolve global vocabulary IDs once per request. Resolving these inside
        // transformResource() would add two identical queries per list row.
        $mainTitleTypeId = TitleType::query()->where('slug', 'MainTitle')->value('id');
        $collectedDateTypeId = DateType::query()->where('slug', 'Collected')->value('id');

        $igsns = $paginated->getCollection()->map(function (Resource $resource) use ($mainTitleTypeId, $collectedDateTypeId) {
            return $this->transformResource($resource, $mainTitleTypeId, $collectedDateTypeId);
        });

        // Check if current user is admin (only admins can delete IGSNs)
        $user = $request->user();
        $canDelete = $user !== null && $user->role === UserRole::ADMIN;
        $canRegister = $user?->can('register-doi') ?? false;
        $canImport = $user?->can('importFromDataCite', Resource::class) ?? false;

        $canUpdateDataCiteLandingPageUrls = $user?->can('update-datacite-landing-page-urls') ?? false;
        $urlUpdateRun = $canUpdateDataCiteLandingPageUrls
            ? DataCiteUrlUpdateRun::query()->active()->latest()->first()
                ?? DataCiteUrlUpdateRun::query()->where('scope', DataCiteUrlUpdateScope::IGSNS)->latest()->first()
            : null;
        $registrationRun = $canRegister
            ? IgsnRegistrationRun::query()->forUser($user)->active()->latest()->first()
                ?? IgsnRegistrationRun::query()->forUser($user)->latest()->first()
            : null;

        return Inertia::render('igsns/index', [
            'igsns' => $igsns,
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => null,
                'per_page' => $paginated->perPage(),
                'total' => null,
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
                'has_more' => $paginated->hasMorePages(),
                'count_status' => 'pending',
                'filter_fingerprint' => $filterFingerprint,
            ],
            'sort' => [
                'key' => $sortKey,
                'direction' => $sortDirection,
            ],
            'search' => $search,
            'totalCount' => null,
            'canDelete' => $canDelete,
            'canRegister' => $canRegister,
            'canImport' => $canImport,
            'canUpdateDataCiteLandingPageUrls' => $canUpdateDataCiteLandingPageUrls,
            'dataCiteUrlUpdateRun' => $urlUpdateRun === null ? null : $this->dataCiteUrlUpdateRunPresenter->run($urlUpdateRun),
            'igsnRegistrationRun' => $registrationRun === null ? null : $this->igsnRegistrationRunPresenter->run($registrationRun),
            'igsnPrefix' => (string) config('datacite.production.igsn_prefix', '10.60510'),
            'filters' => array_filter([
                'prefix' => $prefix,
                'status' => $status,
                'datacenter_id' => $datacenterId,
                'without_datacenter' => $withoutDatacenter ?: null,
            ], static fn (mixed $value): bool => $value !== null),
            'filterOptions' => $this->getFilterOptionsData($base),
        ]);
    }

    /**
     * Resolve exact IGSN totals independently from the result-page request.
     */
    public function count(IndexIgsnsRequest $request): JsonResponse
    {
        $criteria = $this->resolveListingCriteria($request);
        $countCriteria = $this->countCriteria($criteria);
        $fingerprint = $this->listingCountService->fingerprint($countCriteria);

        $filteredTotal = $this->listingCountService->remember(
            CacheKey::IGSN_LISTING_COUNT,
            ['scope' => 'filtered', ...$countCriteria],
            function () use ($criteria): int {
                $query = $this->baseQuery();
                $this->applyFilters(
                    $query,
                    $criteria['prefix'],
                    $criteria['status'],
                    $criteria['datacenter_id'],
                    $criteria['without_datacenter'],
                );
                $this->applySearch($query, $criteria['search']);

                return $query->count();
            },
        );

        $hasFilters = $criteria['search'] !== ''
            || $criteria['prefix'] !== ''
            || $criteria['status'] !== ''
            || $criteria['datacenter_id'] !== null
            || $criteria['without_datacenter'];

        $inventoryTotal = $hasFilters
            ? $this->listingCountService->remember(
                CacheKey::IGSN_LISTING_COUNT,
                ['scope' => 'inventory'],
                fn (): int => $this->baseQuery()->count(),
            )
            : $filteredTotal;

        return response()->json([
            'filter_fingerprint' => $fingerprint,
            'filtered_total' => $filteredTotal,
            'inventory_total' => $inventoryTotal,
            'last_page' => max(1, (int) ceil($filteredTotal / $criteria['per_page'])),
            'count_status' => 'ready',
        ]);
    }

    /**
     * Return available filter options for the IGSN list.
     *
     * Provides distinct IGSN prefixes (DOI part before the slash), distinct
     * upload statuses, and Datacenters currently assigned to IGSNs.
     */
    public function filterOptions(): JsonResponse
    {
        return response()->json($this->getFilterOptionsData($this->baseQuery()));
    }

    /**
     * Compute the available filter options from a base query.
     *
     * Shared between the JSON endpoint and the Inertia page props.
     *
     * @param  Builder<\App\Models\Resource>  $base
     * @return array{prefixes: list<string>, statuses: list<string>, datacenters: list<array{id: int, name: string}>}
     */
    private function getFilterOptionsData(Builder $base): array
    {
        /** @var list<string> $prefixes */
        $prefixes = [];
        /** @var list<string> $statuses */
        $statuses = [];
        /** @var list<array{id: int, name: string}> $datacenters */
        $datacenters = [];

        try {
            $driver = DB::getDriverName();

            // Extract DOI prefix (part before the first slash)
            // Use database-specific syntax for MySQL vs SQLite compatibility
            $prefixExpr = $driver === 'sqlite'
                ? "SUBSTR(doi, 1, INSTR(doi, '/') - 1)"
                : "SUBSTRING_INDEX(doi, '/', 1)";

            /** @var list<string> $prefixes */
            $prefixes = (clone $base)
                ->whereNotNull('doi')
                ->where('doi', 'like', '%/%')
                ->selectRaw("DISTINCT {$prefixExpr} as prefix")
                ->pluck('prefix')
                ->sort()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('Failed to load IGSN prefix filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            /** @var list<string> $statuses */
            $statuses = IgsnMetadata::query()
                ->whereIn('resource_id', (clone $base)->select('id'))
                ->select('upload_status')
                ->distinct()
                ->orderBy('upload_status')
                ->pluck('upload_status')
                ->all();
        } catch (\Throwable $e) {
            Log::warning('Failed to load IGSN status filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            /** @var list<array{id: int, name: string}> $datacenters */
            $datacenters = Datacenter::query()
                ->whereIn('id', (clone $base)
                    ->whereNotNull('datacenter_id')
                    ->select('datacenter_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (Datacenter $datacenter): array => [
                    'id' => $datacenter->id,
                    'name' => $datacenter->name,
                ])
                ->all();
        } catch (\Throwable $e) {
            Log::warning('Failed to load IGSN datacenter filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'prefixes' => $prefixes,
            'statuses' => $statuses,
            'datacenters' => $datacenters,
        ];
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
     *
     * All authenticated users can export IGSNs (confirmed requirement).
     */
    public function exportJson(Resource $resource, JsonSchemaValidator $validator): StreamedResponse|JsonResponse
    {
        // Verify this is actually an IGSN resource (has igsnMetadata)
        if ($resource->igsnMetadata === null) {
            abort(404, 'IGSN not found.');
        }

        // Generate DataCite JSON
        $exporter = new DataCiteJsonExporter;
        $dataCiteData = $exporter->export($resource);

        // Validate attributes against DataCite 4.7 schema
        // Schema expects flat structure, export has data.attributes wrapper
        try {
            $validator->validate($dataCiteData['data']['attributes']);
        } catch (JsonValidationException $e) {
            return response()->json([
                'message' => 'JSON export validation failed against DataCite Schema.',
                'errors' => $e->getErrors(),
                'schema_version' => $e->getSchemaVersion(),
            ], 422);
        }

        // Generate filename from IGSN (stored in doi field)
        $igsn = $resource->doi ?? "resource-{$resource->id}";
        $safeIgsn = preg_replace('/[^a-zA-Z0-9._-]/', '-', $igsn);
        if ($safeIgsn === null) {
            // preg_replace returns null only on PCRE error
            report(new \RuntimeException("preg_replace failed for IGSN: {$igsn}"));
            $safeIgsn = "resource-{$resource->id}";
        }
        $filename = "igsn-{$safeIgsn}.json";

        // Return as download with explicit Content-Disposition header
        return response()->streamDownload(function () use ($dataCiteData): void {
            echo json_encode($dataCiteData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }, $filename, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export an IGSN resource as DataCite Linked Data JSON-LD.
     */
    public function exportJsonLd(Resource $resource): StreamedResponse|JsonResponse
    {
        if ($resource->igsnMetadata === null) {
            abort(404, 'IGSN not found.');
        }

        $exporter = new DataCiteLinkedDataExporter;
        $jsonLd = $exporter->export($resource);

        $igsn = $resource->doi ?? "resource-{$resource->id}";
        $safeIgsn = preg_replace('/[^a-zA-Z0-9._-]/', '-', $igsn);
        if ($safeIgsn === null) {
            report(new \RuntimeException("preg_replace failed for IGSN: {$igsn}"));
            $safeIgsn = "resource-{$resource->id}";
        }
        $filename = "igsn-{$safeIgsn}.jsonld";

        return response()->streamDownload(function () use ($jsonLd): void {
            echo json_encode($jsonLd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }, $filename, [
            'Content-Type' => 'application/ld+json; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Register or update an IGSN at DataCite.
     *
     * For new registrations, sends a POST with the IGSN preserved as DOI.
     * For already-registered IGSNs, sends a PUT to update metadata.
     * Sets publicationYear to the current year (Issue #438).
     */
    public function registerAtDataCite(Request $request, Resource $resource): JsonResponse
    {
        // Authorization: users with DOI registration access may register IGSNs; Beginner users are forced to DataCite test mode.
        if (! $request->user()?->can('register-doi')) {
            abort(403, 'You are not authorized to register IGSNs.');
        }

        // Verify this is an IGSN resource
        $metadata = $resource->igsnMetadata;
        if ($metadata === null) {
            abort(404, 'IGSN not found.');
        }

        $registrationLock = $this->igsnRegistrationExclusion->resourceLock($resource->id);
        if (! $registrationLock->get()) {
            return response()->json([
                'error' => 'Registration in progress',
                'message' => 'This IGSN is currently being registered. Please try again shortly.',
            ], 409);
        }

        try {
            if ($this->igsnRegistrationExclusion->hasActiveRun($resource->id)) {
                return response()->json([
                    'error' => 'Registration already queued',
                    'message' => 'This IGSN is already part of an active batch registration.',
                ], 409);
            }

            $resource->refresh();

            return $this->performDataCiteRegistration($resource);
        } finally {
            $registrationLock->release();
        }
    }

    private function performDataCiteRegistration(Resource $resource): JsonResponse
    {
        $metadata = $resource->igsnMetadata;
        if ($metadata === null) {
            abort(404, 'IGSN not found.');
        }

        // Check landing page requirement
        $resource->loadMissing('landingPage');
        if (! $resource->landingPage) {
            return response()->json([
                'error' => 'Landing page required',
                'message' => 'A landing page must be set up before registering at DataCite.',
            ], 422);
        }
        $wasAlreadyRegistered = $metadata->isRegistered();

        // Set publicationYear to current year only for new registrations (Issue #438).
        // Already-registered IGSNs keep their original publicationYear.
        // Only persisted after a successful DataCite response to avoid inconsistent local state.
        if (! $wasAlreadyRegistered) {
            $resource->publication_year = (int) date('Y');
        }

        try {
            // Set status to "registering"
            $metadata->updateStatus(IgsnMetadata::STATUS_REGISTERING);

            /** @var DataCiteRegistrationService $service */
            $service = app(DataCiteRegistrationService::class);

            if ($wasAlreadyRegistered) {
                // Already registered → update metadata
                $response = $service->updateMetadata($resource);
                $doi = $response['data']['id'] ?? $resource->doi;

                // Keep status as registered after successful update
                $metadata->updateStatus(IgsnMetadata::STATUS_REGISTERED);

                Log::info('IGSN metadata updated at DataCite', [
                    'resource_id' => $resource->id,
                    'doi' => $doi,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'IGSN metadata updated at DataCite.',
                    'doi' => $doi,
                    'mode' => $service->isTestMode() ? 'test' : 'production',
                    'updated' => true,
                ]);
            }

            // New registration
            $response = $service->registerIgsn($resource);
            $doi = $response['data']['id'] ?? $resource->doi;

            // Update resource DOI if DataCite returned a different one
            if ($doi !== null && $doi !== $resource->doi) {
                $resource->doi = $doi;
            }

            // Persist publicationYear (and possibly updated DOI) after successful DataCite response
            $resource->save();

            // Mark as registered
            $metadata->updateStatus(IgsnMetadata::STATUS_REGISTERED);

            Log::info('IGSN registered at DataCite', [
                'resource_id' => $resource->id,
                'doi' => $doi,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'IGSN registered at DataCite successfully.',
                'doi' => $doi,
                'mode' => $service->isTestMode() ? 'test' : 'production',
                'updated' => false,
            ]);
        } catch (\InvalidArgumentException $e) {
            $metadata->markAsError($e->getMessage());

            Log::warning('IGSN registration invalid request', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Invalid request',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\RuntimeException $e) {
            $metadata->markAsError($e->getMessage());

            Log::warning('IGSN registration runtime error', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Registration failed',
                'message' => $e->getMessage(),
            ], 422);

        } catch (RequestException $e) {
            // DataCite API error
            $apiResponse = $e->response;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $statusCode = $apiResponse !== null ? $apiResponse->status() : 500;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $apiError = $apiResponse !== null ? $apiResponse->json() : null;

            $errorMessage = 'Failed to communicate with DataCite API.';
            if (isset($apiError['errors']) && is_array($apiError['errors']) && count($apiError['errors']) > 0) {
                $firstError = $apiError['errors'][0];
                $errorMessage = $firstError['title'] ?? $firstError['detail'] ?? $errorMessage;
            }

            $metadata->markAsError($errorMessage);

            Log::error('DataCite API error during IGSN registration', [
                'resource_id' => $resource->id,
                'status' => $statusCode,
                'error' => $e->getMessage(),
                'api_response' => $apiError,
            ]);

            return response()->json([
                'error' => 'DataCite API error',
                'message' => $errorMessage,
                'details' => config('app.debug') ? $apiError : null,
            ], $statusCode >= 400 && $statusCode < 500 ? $statusCode : 500);

        } catch (\Exception $e) {
            $safeMessage = 'An unexpected error occurred during IGSN registration. Please contact support.';
            $metadata->markAsError($safeMessage);

            Log::error('Unexpected error during IGSN registration', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Unexpected error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : $safeMessage,
            ], 500);
        }
    }

    /**
     * Base query for IGSN resources without eager-loading.
     *
     * Shared by buildQueryFrom(), filterOptions(), and other methods
     * to ensure consistent scoping (Physical Object type + igsnMetadata).
     *
     * @return Builder<Resource>
     */
    private function baseQuery(): Builder
    {
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();

        $query = Resource::query()
            ->whereHas('igsnMetadata');

        if ($physicalObjectType !== null) {
            $query->where('resource_type_id', $physicalObjectType->id);
        }

        return $query;
    }

    /**
     * Clone a base query and add eager-loading for the IGSN list.
     *
     * @param  Builder<Resource>  $base
     * @return Builder<Resource>
     */
    private function buildQueryFrom(Builder $base): Builder
    {
        return (clone $base)
            ->with([
                'titles',
                'igsnMetadata',
                'geoLocations',
                'creators.creatorable',
                'dates.dateType',
                'landingPage',
            ]);
    }

    /**
     * Transform a Resource into the IGSN list format.
     *
     * @return array<string, mixed>
     */
    private function transformResource(Resource $resource, ?int $mainTitleTypeId, ?int $collectedDateTypeId): array
    {
        // Find the main title by type, fallback to first title, then 'Untitled'
        $mainTitleRecord = ($mainTitleTypeId === null
            ? null
            : $resource->titles->firstWhere('title_type_id', $mainTitleTypeId))
            ?? $resource->titles->first();
        $mainTitle = $mainTitleRecord !== null ? $mainTitleRecord->value : 'Untitled';

        $metadata = $resource->igsnMetadata;

        // Get collection date from dates relation (using date_type_id for reliable filtering)
        $collectionDate = $collectedDateTypeId === null
            ? null
            : $resource->dates->firstWhere('date_type_id', $collectedDateTypeId);

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
            'has_landing_page' => $resource->landingPage !== null,
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
            return $person->family_name.', '.$person->given_name;
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
     * @return array{
     *     page:int,
     *     per_page:int,
     *     search:string,
     *     prefix:string,
     *     status:string,
     *     datacenter_id:int|null,
     *     without_datacenter:bool,
     *     sort:string,
     *     direction:string
     * }
     */
    private function resolveListingCriteria(IndexIgsnsRequest $request): array
    {
        $search = trim((string) $request->query('search', ''));

        if (mb_strlen($search) < self::MIN_SEARCH_LENGTH) {
            $search = '';
        }

        $status = trim((string) $request->query('status', ''));

        if ($status !== '' && ! in_array($status, IgsnMetadata::getValidStatuses(), true)) {
            $status = '';
        }

        [$sortKey, $sortDirection] = $this->resolveSortState($request);

        return [
            'page' => max(1, (int) $request->query('page', 1)),
            'per_page' => $request->perPage(),
            'search' => $search,
            'prefix' => trim((string) $request->query('prefix', '')),
            'status' => $status,
            'datacenter_id' => $request->datacenterId(),
            'without_datacenter' => $request->withoutDatacenter(),
            'sort' => $sortKey,
            'direction' => $sortDirection,
        ];
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    private function countCriteria(array $criteria): array
    {
        return [
            'search' => $criteria['search'],
            'prefix' => $criteria['prefix'],
            'status' => $criteria['status'],
            'datacenter_id' => $criteria['datacenter_id'],
            'without_datacenter' => $criteria['without_datacenter'],
        ];
    }

    /**
     * Apply prefix, status, and Datacenter filters to the query.
     *
     * @param  Builder<Resource>  $query
     */
    private function applyFilters(
        Builder $query,
        string $prefix,
        string $status,
        ?int $datacenterId,
        bool $withoutDatacenter,
    ): void {
        if ($prefix !== '') {
            // Escape SQL LIKE meta-characters in the prefix
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
            $query->whereRaw('doi like ? escape ?', [$escaped.'/%', '\\']);
        }

        if ($status !== '') {
            $query->whereHas('igsnMetadata', function (Builder $q) use ($status): void {
                $q->where('upload_status', $status);
            });
        }

        if ($withoutDatacenter) {
            $query->whereNull('datacenter_id');
        } elseif ($datacenterId !== null) {
            $query->where('datacenter_id', $datacenterId);
        }
    }

    /**
     * Apply text search filter to the query.
     *
     * Searches in the DOI field (where IGSN is stored) and in title values.
     *
     * @param  Builder<Resource>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        // Escape SQL LIKE meta-characters so %, _ and \ in user input are treated literally.
        // Use an explicit ESCAPE clause so this works on both MySQL and SQLite.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $pattern = "%{$escaped}%";

        $query->where(function (Builder $q) use ($pattern): void {
            $q->whereRaw('doi like ? escape ?', [$pattern, '\\'])
                ->orWhereHas('titles', function (Builder $titleQuery) use ($pattern): void {
                    $titleQuery->whereRaw('value like ? escape ?', [$pattern, '\\']);
                });
        });
    }

    /**
     * Apply sorting to the query.
     *
     * @param  Builder<Resource>  $query
     */
    private function applySorting(Builder $query, string $sortKey, string $sortDirection): void
    {
        $direction = $sortDirection === 'asc' ? 'asc' : 'desc';

        switch ($sortKey) {
            case 'igsn':
                $query->orderBy('doi', $direction);
                break;

            case 'title':
                // Sort by the first title (ordered by title_type_id to prioritize MainTitle)
                // Note: The column is named 'value' in the titles table
                $query->orderBy(function ($q) {
                    return $q->select('value as sort_value')
                        ->from('titles')
                        ->whereColumn('titles.resource_id', 'resources.id')
                        ->orderBy('title_type_id')
                        ->limit(1);
                }, $direction);
                break;

            case 'sample_type':
            case 'material':
            case 'upload_status':
                $query->orderBy(function ($q) use ($sortKey) {
                    return $q->select($sortKey)
                        ->from('igsn_metadata')
                        ->whereColumn('igsn_metadata.resource_id', 'resources.id')
                        ->limit(1);
                }, $direction);
                break;

            case 'collection_date':
                // Use COALESCE to prefer start_date over date_value (consistent with formatCollectionDate)
                // Note: The table is named 'dates' (not 'resource_dates') - see ResourceDate model
                $query->orderBy(function ($q) {
                    return $q->selectRaw('COALESCE(start_date, date_value) as sort_value')
                        ->from('dates')
                        ->join('date_types', 'dates.date_type_id', '=', 'date_types.id')
                        ->whereColumn('dates.resource_id', 'resources.id')
                        ->where('date_types.slug', 'Collected')
                        ->limit(1);
                }, $direction);
                break;

            default:
                $query->orderBy($sortKey, $direction);
                break;
        }
    }
}
