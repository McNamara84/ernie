<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterDoiRequest;
use App\Http\Requests\StoreResourceRequest;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\IdentifierType;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\Right;
use App\Models\Title;
use App\Models\TitleType;
use App\Models\User;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteXmlExporter;
use App\Services\DataCiteXmlValidator;
use App\Services\ResourceCacheService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class ResourceController extends Controller
{
    private const DEFAULT_PER_PAGE = 50;

    private const MIN_PER_PAGE = 1;

    private const MAX_PER_PAGE = 100;

    private const DEFAULT_SORT_KEY = 'updated_at';

    private const DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * Create a new controller instance.
     */
    public function __construct(
        private readonly ResourceCacheService $cacheService
    ) {}

    private const ALLOWED_SORT_KEYS = [
        'id',
        'doi',
        'title',
        'resourcetypegeneral',
        'first_author',
        'year',
        'curator',
        'publicstatus',
        'created_at',
        'updated_at',
    ];

    private const ALLOWED_SORT_DIRECTIONS = ['asc', 'desc'];

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));

        [$sortKey, $sortDirection] = $this->resolveSortState($request);
        $filters = $this->extractFilters($request);

        $query = $this->baseQuery();

        // Apply filters
        $this->applyFilters($query, $filters);

        // Apply sorting
        $this->applySorting($query, $sortKey, $sortDirection);

        // Prepare cache key data
        $cacheFilters = array_merge($filters, [
            'sort' => $sortKey,
            'direction' => $sortDirection,
        ]);

        // Use caching for resource listing
        $resources = $this->cacheService->cacheResourceList(
            $query,
            $perPage,
            $page,
            $cacheFilters
        );

        /** @var array<int, Resource> $items */
        $items = $resources->items();
        $resourcesData = collect($items)
            ->map(fn (Resource $resource): array => $this->serializeResource($resource))
            ->all();

        return Inertia::render('resources', [
            'resources' => $resourcesData,
            'pagination' => [
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
                'per_page' => $resources->perPage(),
                'total' => $resources->total(),
                'from' => $resources->firstItem(),
                'to' => $resources->lastItem(),
                'has_more' => $resources->hasMorePages(),
            ],
            'sort' => [
                'key' => $sortKey,
                'direction' => $sortDirection,
            ],
            'canImportFromDataCite' => $request->user()?->can('importFromDataCite', Resource::class) ?? false,
        ]);
    }

    public function store(StoreResourceRequest $request): JsonResponse
    {
        try {
            [$resource, $isUpdate] = DB::transaction(function () use ($request): array {
                $validated = $request->validated();

                $languageId = null;

                if (! empty($validated['language'])) {
                    $languageId = Language::query()
                        ->where('code', $validated['language'])
                        ->value('id');
                }

                $attributes = [
                    'doi' => $validated['doi'] ?? null,
                    'publication_year' => $validated['year'],
                    'resource_type_id' => $validated['resourceType'],
                    'version' => $validated['version'] ?? null,
                    'language_id' => $languageId,
                ];

                $isUpdate = ! empty($validated['resourceId']);

                if ($isUpdate) {
                    /** @var Resource $resource */
                    $resource = Resource::query()
                        ->lockForUpdate()
                        ->findOrFail($validated['resourceId']);

                    // Track who updated the resource
                    $attributes['updated_by_user_id'] = $request->user()?->id;

                    $resource->update($attributes);
                } else {
                    // Track who created the resource
                    $attributes['created_by_user_id'] = $request->user()?->id;

                    $resource = Resource::query()->create($attributes);
                }

                /**
                 * Collect all titleType values for lookup.
                 * prepareForValidation() maps incoming values to DB slugs where possible.
                 * All titles (including MainTitle) are stored with their TitleType ID.
                 *
                 * Note: In DataCite XML, MainTitle has no titleType attribute, but in the
                 * database we always store the reference to the TitleType record.
                 *
                 * @var array<int, string> $titleTypeInputValues
                 */
                $titleTypeInputValues = [];

                foreach ($validated['titles'] as $titleData) {
                    $normalized = Str::kebab($titleData['titleType'] ?? '');

                    // Empty or 'main-title' defaults to MainTitle slug
                    if ($normalized === '' || $normalized === 'main-title') {
                        $titleTypeInputValues[] = 'MainTitle';
                    } else {
                        $titleTypeInputValues[] = (string) ($titleData['titleType'] ?? '');
                    }
                }

                $titleTypeInputValues = array_values(array_unique($titleTypeInputValues));

                /**
                 * Map normalized (kebab-case) slugs to DB title type IDs.
                 *
                 * @var array<string, int> $titleTypeMap
                 */
                $titleTypeMap = TitleType::query()
                    ->whereIn('slug', $titleTypeInputValues)
                    ->get(['id', 'slug'])
                    ->mapWithKeys(fn (TitleType $type): array => [Str::kebab($type->slug) => $type->id])
                    ->all();

                // Also add mapping for empty string and 'main-title' to MainTitle ID
                // Note: 'MainTitle' in kebab-case becomes 'main-title'
                $mainTitleId = $titleTypeMap['main-title'] ?? null;
                if ($mainTitleId === null) {
                    // MainTitle TitleType is required - throw specific error
                    throw new \RuntimeException(
                        'TitleType "MainTitle" not found in database. Please run: php artisan db:seed --class=TitleTypeSeeder'
                    );
                }
                $titleTypeMap[''] = $mainTitleId;

                $resourceTitles = [];

                foreach ($validated['titles'] as $index => $title) {
                    $normalized = Str::kebab($title['titleType'] ?? '');

                    $titleTypeId = $titleTypeMap[$normalized] ?? null;
                    if ($titleTypeId === null) {
                        // This should be prevented by StoreResourceRequest validation, but keep a safe failure mode.
                        throw ValidationException::withMessages([
                            "titles.$index.titleType" => 'Unknown title type. Please select a valid title type.',
                        ]);
                    }

                    $resourceTitles[] = [
                        'value' => $title['title'],
                        'title_type_id' => $titleTypeId,
                    ];
                }

                if ($isUpdate) {
                    $resource->titles()->delete();
                }

                $resource->titles()->createMany($resourceTitles);

                // Sync rights (pivot table) based on the validated license identifiers.
                $licenseIdentifiers = $validated['licenses'] ?? [];

                /**
                 * @var array<string, int> $rightsByIdentifier
                 */
                $rightsByIdentifier = Right::query()
                    ->whereIn('identifier', $licenseIdentifiers)
                    ->pluck('id', 'identifier')
                    ->all();

                $missingLicenses = array_values(array_diff($licenseIdentifiers, array_keys($rightsByIdentifier)));
                if (count($missingLicenses) > 0) {
                    throw ValidationException::withMessages([
                        'licenses' => 'Some provided licenses are unknown: '.implode(', ', $missingLicenses),
                    ]);
                }

                $resource->rights()->sync(array_values($rightsByIdentifier));

                $resource->creators()->delete();

                $authors = $validated['authors'] ?? [];

                foreach ($authors as $author) {
                    $position = isset($author['position']) && is_int($author['position'])
                        ? $author['position']
                        : 0;

                    if (($author['type'] ?? 'person') === 'institution') {
                        $resourceCreator = $this->storeInstitutionCreator($resource, $author, $position);
                    } else {
                        $resourceCreator = $this->storePersonCreator($resource, $author, $position);
                    }

                    $this->syncCreatorAffiliations($resourceCreator, $author);
                }

                // Delete old MSL labs if updating (before adding new ones)
                if ($isUpdate) {
                    // Get all existing MSL labs (institutions with name_identifier_scheme = 'labid')
                    // Use whereIn with subquery to avoid morph type issues
                    $mslLabIds = Institution::where('name_identifier_scheme', 'labid')
                        ->pluck('id');

                    $mslLabs = ResourceContributor::query()
                        ->where('resource_id', $resource->id)
                        ->where('contributorable_type', Institution::class)
                        ->whereIn('contributorable_id', $mslLabIds)
                        ->get();

                    // Properly cleanup relationships before deleting
                    foreach ($mslLabs as $mslLab) {
                        $mslLab->affiliations()->delete(); // Delete child affiliation records
                        $mslLab->delete();               // Finally delete the ResourceContributor
                    }
                }

                $contributors = $validated['contributors'] ?? [];

                // Delete old contributors if updating
                if ($isUpdate) {
                    $existingContributors = ResourceContributor::query()
                        ->where('resource_id', $resource->id)
                        ->get();

                    foreach ($existingContributors as $contrib) {
                        $contrib->affiliations()->delete();
                        $contrib->delete();
                    }
                }

                foreach ($contributors as $contributor) {
                    $position = isset($contributor['position']) && is_int($contributor['position'])
                        ? $contributor['position']
                        : 0;

                    if (($contributor['type'] ?? 'person') === 'institution') {
                        $resourceContributor = $this->storeInstitutionContributor($resource, $contributor, $position);
                    } else {
                        $resourceContributor = $this->storePersonContributor($resource, $contributor, $position);
                    }

                    $this->syncContributorType($resourceContributor, $contributor);
                    $this->syncContributorAffiliations($resourceContributor, $contributor);
                }

                // Save MSL Laboratories as contributors with HostingInstitution type
                $mslLaboratories = $validated['mslLaboratories'] ?? [];

                foreach ($mslLaboratories as $lab) {
                    $position = (int) ($lab['position'] ?? 0);

                    $resourceContributor = $this->storeMslLaboratory($resource, $lab, $position);
                    $this->syncMslLaboratoryAffiliation($resourceContributor, $lab);
                }

                // Save descriptions
                if ($isUpdate) {
                    $resource->descriptions()->delete();
                }

                // Pre-fetch description type IDs
                /** @var array<string, int> $descriptionTypeLookup */
                $descriptionTypeLookup = DescriptionType::query()
                    ->get(['id', 'slug'])
                    ->mapWithKeys(fn (DescriptionType $type): array => [Str::kebab($type->slug) => $type->id])
                    ->all();

                $descriptions = $validated['descriptions'] ?? [];

                foreach ($descriptions as $description) {
                    $descTypeKey = Str::kebab((string) ($description['descriptionType'] ?? ''));
                    $descTypeId = $descriptionTypeLookup[$descTypeKey] ?? null;

                    if ($descTypeId === null) {
                        // Throw validation exception for unknown description type to prevent silent data loss.
                        // This matches the date type handling behavior for consistency.
                        Log::warning('Unknown description type slug: '.($description['descriptionType'] ?? 'empty'));

                        throw ValidationException::withMessages([
                            'descriptions' => ["Unknown description type: {$description['descriptionType']}. Please select a valid description type."],
                        ]);
                    }

                    $resource->descriptions()->create([
                        'description_type_id' => $descTypeId,
                        'value' => $description['description'],
                        // Language is always null because per-description language selection
                        // is not part of the current API contract. The resource-level language
                        // field (DataCite #9) serves this purpose. Any 'language' key in the
                        // request payload is intentionally ignored.
                        'language' => null,
                    ]);
                }

                // Save dates
                // Note: 'created' and 'updated' dates are auto-managed and should not be
                // submitted by the frontend. They are handled separately below.

                // Pre-fetch all date type IDs in a single query to avoid N+1 queries
                // This lookup map is used for both system date types and user-provided dates
                /** @var array<string, int> $dateTypeLookup */
                $dateTypeLookup = DateType::query()
                    ->get(['id', 'slug'])
                    ->mapWithKeys(fn (DateType $type): array => [Str::kebab($type->slug) => $type->id])
                    ->all();
                $createdDateTypeId = $dateTypeLookup['created'] ?? null;
                $updatedDateTypeId = $dateTypeLookup['updated'] ?? null;

                if ($isUpdate) {
                    // When updating, preserve 'created' date (never overwritten) and delete
                    // all other user-managed dates. The 'updated' date is handled separately below.
                    if ($createdDateTypeId !== null) {
                        $resource->dates()
                            ->where('date_type_id', '!=', $createdDateTypeId)
                            ->delete();
                    } else {
                        // If 'created' type doesn't exist, delete all dates
                        $resource->dates()->delete();
                    }
                }

                $dates = $validated['dates'] ?? [];

                foreach ($dates as $date) {
                    // Skip 'created' and 'updated' date types - these are auto-managed
                    if (in_array(Str::kebab((string) ($date['dateType'] ?? '')), ['created', 'updated'], true)) {
                        continue;
                    }

                    // Use the pre-fetched lookup map instead of querying for each date
                    $dateTypeKey = Str::kebab((string) ($date['dateType'] ?? ''));
                    $dateTypeId = $dateTypeLookup[$dateTypeKey] ?? null;

                    if ($dateTypeId === null) {
                        // Throw validation exception for unknown date type to prevent silent data loss
                        // This will rollback the transaction and return a proper validation error response
                        Log::warning('Unknown date type slug: '.$date['dateType']);

                        throw ValidationException::withMessages([
                            'dates' => ["Unknown date type: {$date['dateType']}. Please select a valid date type."],
                        ]);
                    }

                    // Date storage strategy:
                    // - When BOTH startDate AND endDate are provided: store as a date range
                    //   (date_value=null, start_date/end_date populated)
                    // - When only ONE date is provided: store as a single date
                    //   (date_value=the provided date, start_date/end_date=null)
                    // This allows the model to distinguish between point-in-time dates
                    // and date ranges while maintaining backward compatibility.
                    $hasRange = ($date['startDate'] ?? null) && ($date['endDate'] ?? null);

                    $resource->dates()->create([
                        'date_type_id' => $dateTypeId,
                        'date_value' => $hasRange ? null : ($date['startDate'] ?? $date['endDate'] ?? null),
                        'start_date' => $hasRange ? $date['startDate'] : null,
                        'end_date' => $hasRange ? $date['endDate'] : null,
                        'date_information' => $date['dateInformation'] ?? null,
                    ]);
                }

                // Auto-manage 'created' date: Set only on new resources (not on updates)
                if (! $isUpdate && $createdDateTypeId !== null) {
                    // Create a 'created' date with current timestamp for new resources
                    $resource->dates()->create([
                        'date_type_id' => $createdDateTypeId,
                        'date_value' => now()->format('Y-m-d'),
                        'start_date' => null,
                        'end_date' => null,
                        'date_information' => null,
                    ]);
                }

                // Auto-manage 'updated' date: Update timestamp on every update operation.
                // This reflects when the resource was last saved by a curator.
                if ($isUpdate && $updatedDateTypeId !== null) {
                    // Create new 'updated' date with current timestamp
                    // (any existing 'updated' entry was already deleted above)
                    $resource->dates()->create([
                        'date_type_id' => $updatedDateTypeId,
                        'date_value' => now()->format('Y-m-d'),
                        'start_date' => null,
                        'end_date' => null,
                        'date_information' => null,
                    ]);
                }

                // Save subjects (free keywords and controlled keywords combined)
                if ($isUpdate) {
                    $resource->subjects()->delete();
                }

                $freeKeywords = $validated['freeKeywords'] ?? [];

                foreach ($freeKeywords as $keyword) {
                    // Only save non-empty keywords
                    if (! empty(trim($keyword))) {
                        $resource->subjects()->create([
                            'value' => trim($keyword),
                            'subject_scheme' => null,
                            'scheme_uri' => null,
                            'value_uri' => null,
                            'classification_code' => null,
                        ]);
                    }
                }

                $controlledKeywords = $validated['gcmdKeywords'] ?? [];

                // Prepare controlled keywords for bulk creation
                $controlledKeywordsData = [];
                foreach ($controlledKeywords as $keyword) {
                    // Validate required fields (scheme is now the discriminator instead of vocabularyType)
                    if (! empty($keyword['id']) && ! empty($keyword['text']) && ! empty($keyword['scheme'])) {
                        $controlledKeywordsData[] = [
                            'value' => $keyword['text'],
                            'subject_scheme' => $keyword['scheme'],
                            'scheme_uri' => $keyword['schemeURI'] ?? null,
                            'value_uri' => $keyword['id'],
                            'classification_code' => null,
                        ];
                    }
                }

                // Bulk create controlled keywords using Eloquent (handles timestamps automatically)
                if (! empty($controlledKeywordsData)) {
                    $resource->subjects()->createMany($controlledKeywordsData);
                }

                // Save geo locations (spatial and temporal coverages)
                if ($isUpdate) {
                    $resource->geoLocations()->delete();
                }

                $coverages = $validated['spatialTemporalCoverages'] ?? [];

                foreach ($coverages as $coverage) {
                    $type = $coverage['type'] ?? 'point';

                    // Only save coverage if it has at least one meaningful field.
                    // Helper closure to check if a coordinate value is provided.
                    // Accepts: numeric 0, string "0", floats, integers.
                    // Rejects: null, empty string, non-numeric values.
                    $isCoordinateProvided = static function (mixed $value): bool {
                        if ($value === null || $value === '') {
                            return false;
                        }

                        // Accept any numeric value (including 0)
                        return is_numeric($value);
                    };

                    $hasData = $isCoordinateProvided($coverage['latMin'] ?? null)
                        || $isCoordinateProvided($coverage['lonMin'] ?? null)
                        || ! empty($coverage['polygonPoints'])
                        || ! empty($coverage['description']);

                    if ($hasData) {
                        $geoLocationData = [
                            'place' => $coverage['description'] ?? null,
                        ];

                        // For polygon type, store polygon points as JSON (geo_locations.polygon_points)
                        if ($type === 'polygon' && ! empty($coverage['polygonPoints']) && is_array($coverage['polygonPoints'])) {
                            $invalidPoints = [];

                            // Filter and transform polygon points, tracking rejected points for feedback.
                            // Valid ranges: latitude -90 to 90, longitude -180 to 180
                            $validPoints = array_filter(
                                $coverage['polygonPoints'],
                                static function (mixed $point, int $index) use (&$invalidPoints): bool {
                                    if (! is_array($point)) {
                                        $invalidPoints[] = 'Point '.($index + 1).': not a valid coordinate pair';

                                        return false;
                                    }
                                    $lon = $point['longitude'] ?? $point['lon'] ?? null;
                                    $lat = $point['latitude'] ?? $point['lat'] ?? null;

                                    // Must have both values present.
                                    // Use is_numeric() to correctly accept 0 (Equator/Prime Meridian).
                                    if (! is_numeric($lon) || ! is_numeric($lat)) {
                                        $invalidPoints[] = 'Point '.($index + 1).': missing or non-numeric coordinates';

                                        return false;
                                    }

                                    // Validate coordinate ranges per WGS84 specification.
                                    // Normalize to float early so error messages show sanitized values,
                                    // not raw user input (security best practice).
                                    $lonFloat = (float) $lon;
                                    $latFloat = (float) $lat;

                                    if ($latFloat < -90.0 || $latFloat > 90.0 || $lonFloat < -180.0 || $lonFloat > 180.0) {
                                        $invalidPoints[] = sprintf(
                                            'Point %d: coordinates out of range (lat: %.6f, lon: %.6f)',
                                            $index + 1,
                                            $latFloat,
                                            $lonFloat
                                        );

                                        return false;
                                    }

                                    return true;
                                },
                                ARRAY_FILTER_USE_BOTH
                            );

                            // A valid polygon requires at least 3 points to form a closed shape.
                            // Throw validation error if we don't have enough valid points to prevent
                            // silent data loss where a polygon "disappears" without explanation.
                            // Note: GeoJSON/DataCite polygon semantics auto-close the shape (first point
                            // implicitly connects to last point), so explicit closure is not required.
                            if (count($validPoints) < 3) {
                                $message = 'Polygon requires at least 3 valid points, but only '.count($validPoints).' valid point(s) found.';
                                if (! empty($invalidPoints)) {
                                    $message .= ' Rejected points: '.implode('; ', array_slice($invalidPoints, 0, 5));
                                    if (count($invalidPoints) > 5) {
                                        $message .= ' and '.(count($invalidPoints) - 5).' more.';
                                    }
                                }

                                throw ValidationException::withMessages([
                                    'coverages' => [$message],
                                ]);
                            }

                            $geoLocationData['polygon_points'] = array_values(array_map(
                                static fn (array $point): array => [
                                    'longitude' => (float) ($point['longitude'] ?? $point['lon']),
                                    'latitude' => (float) ($point['latitude'] ?? $point['lat']),
                                ],
                                $validPoints
                            ));

                            $resource->geoLocations()->create($geoLocationData);
                        } elseif ($type === 'point') {
                            // Point type
                            $geoLocationData['point_longitude'] = $coverage['lonMin'] ?? null;
                            $geoLocationData['point_latitude'] = $coverage['latMin'] ?? null;
                            $resource->geoLocations()->create($geoLocationData);
                        } else {
                            // Box type
                            $geoLocationData['west_bound_longitude'] = $coverage['lonMin'] ?? null;
                            $geoLocationData['east_bound_longitude'] = $coverage['lonMax'] ?? null;
                            $geoLocationData['south_bound_latitude'] = $coverage['latMin'] ?? null;
                            $geoLocationData['north_bound_latitude'] = $coverage['latMax'] ?? null;
                            $resource->geoLocations()->create($geoLocationData);
                        }
                    }
                }

                // Save related identifiers
                if ($isUpdate) {
                    $resource->relatedIdentifiers()->delete();
                }

                // Pre-fetch related identifier type and relation type IDs
                /** @var array<string, int> $relatedIdTypeLookup */
                $relatedIdTypeLookup = IdentifierType::pluck('id', 'slug')->all();
                /** @var array<string, int> $relationTypeLookup */
                $relationTypeLookup = RelationType::pluck('id', 'slug')->all();

                $relatedIdentifiers = $validated['relatedIdentifiers'] ?? [];

                foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
                    // Only save if identifier is not empty
                    if (! empty(trim($relatedIdentifier['identifier']))) {
                        $resource->relatedIdentifiers()->create([
                            'identifier' => trim($relatedIdentifier['identifier']),
                            'identifier_type_id' => $relatedIdTypeLookup[$relatedIdentifier['identifierType']] ?? null,
                            'relation_type_id' => $relationTypeLookup[$relatedIdentifier['relationType']] ?? null,
                            'position' => $index,
                        ]);
                    }
                }

                // Save funding references
                if ($isUpdate) {
                    $resource->fundingReferences()->delete();
                }

                $fundingReferences = $validated['fundingReferences'] ?? [];

                foreach ($fundingReferences as $index => $fundingReference) {
                    // Only save if funder name is not empty (required field)
                    if (! empty(trim($fundingReference['funderName']))) {
                        $resource->fundingReferences()->create([
                            'funder_name' => trim($fundingReference['funderName']),
                            'funder_identifier' => ! empty($fundingReference['funderIdentifier']) ? trim($fundingReference['funderIdentifier']) : null,
                            'funder_identifier_type_id' => ! empty($fundingReference['funderIdentifierType']) ? $this->getFunderIdentifierTypeId($fundingReference['funderIdentifierType']) : null,
                            'scheme_uri' => null,
                            'award_number' => ! empty($fundingReference['awardNumber']) ? trim($fundingReference['awardNumber']) : null,
                            'award_uri' => ! empty($fundingReference['awardUri']) ? trim($fundingReference['awardUri']) : null,
                            'award_title' => ! empty($fundingReference['awardTitle']) ? trim($fundingReference['awardTitle']) : null,
                        ]);
                    }
                }

                return [$resource->load(['titles', 'rights', 'creators', 'contributors', 'descriptions', 'dates', 'subjects', 'geoLocations', 'relatedIdentifiers', 'fundingReferences']), $isUpdate];
            });
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Unable to save resource. Please review the highlighted issues.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            // Log detailed context to help diagnose production issues
            Log::error('ResourceController::store failed', [
                'exception' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'user_id' => $request->user()?->id,
                'resource_id' => $request->input('resourceId'),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
            report($exception);

            return response()->json([
                'message' => 'Unable to save resource. Please try again later.',
            ], 500);
        }

        $message = $isUpdate ? 'Successfully updated resource.' : 'Successfully saved resource.';
        $status = $isUpdate ? 200 : 201;

        return response()->json([
            'message' => $message,
            'resource' => [
                'id' => $resource->id,
            ],
        ], $status);
    }

    /**
     * Delete a resource.
     *
     * @param  Request  $request  The HTTP request - needed for user() access to check authorization.
     *                            While Laravel's route model binding could inject User directly,
     *                            using Request allows for consistent null-safety checks and follows
     *                            the pattern used in other controller methods.
     * @param  Resource  $resource  The resource to delete (injected via route model binding).
     */
    public function destroy(Request $request, Resource $resource): RedirectResponse
    {
        // Authorize deletion using ResourcePolicy - only Admin/GroupLeader can delete
        if ($request->user()?->cannot('delete', $resource)) {
            abort(403, 'You are not authorized to delete this resource.');
        }

        $resource->delete();

        return redirect()
            ->route('resources')
            ->with('success', 'Resource deleted successfully.');
    }

    /**
     * API endpoint for loading more resources (for infinite scrolling).
     */
    public function loadMore(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));

        [$sortKey, $sortDirection] = $this->resolveSortState($request);
        $filters = $this->extractFilters($request);

        $query = $this->baseQuery();

        // Apply filters
        $this->applyFilters($query, $filters);

        // Apply sorting
        $this->applySorting($query, $sortKey, $sortDirection);

        // Prepare cache key data
        $cacheFilters = array_merge($filters, [
            'sort' => $sortKey,
            'direction' => $sortDirection,
        ]);

        // Use caching for resource listing
        $resources = $this->cacheService->cacheResourceList(
            $query,
            $perPage,
            $page,
            $cacheFilters
        );

        /** @var array<int, Resource> $items */
        $items = $resources->items();
        $resourcesData = collect($items)
            ->map(fn (Resource $resource): array => $this->serializeResource($resource))
            ->all();

        return response()->json([
            'resources' => $resourcesData,
            'pagination' => [
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
                'per_page' => $resources->perPage(),
                'total' => $resources->total(),
                'from' => $resources->firstItem(),
                'to' => $resources->lastItem(),
                'has_more' => $resources->hasMorePages(),
            ],
            'sort' => [
                'key' => $sortKey,
                'direction' => $sortDirection,
            ],
        ]);
    }

    /**
     * API endpoint to get available filter options.
     */
    public function getFilterOptions(): JsonResponse
    {
        $resourceTypes = [];
        $curators = [];
        $yearMin = null;
        $yearMax = null;

        // Get distinct resource types
        try {
            $resourceTypes = \App\Models\ResourceType::query()
                ->whereHas('resources')
                ->orderBy('name')
                ->get(['name', 'slug'])
                ->map(fn ($type) => ['name' => $type->name, 'slug' => $type->slug])
                ->all();
        } catch (Throwable $e) {
            Log::warning('Failed to load resource type filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        // Get distinct curators (users who updated or created resources)
        // Prioritize updatedBy, fallback to createdBy if never updated
        try {
            $resourceQuery = Resource::query();
            $hasUpdatedBy = Schema::hasColumn('resources', 'updated_by_user_id');
            $hasCreatedBy = Schema::hasColumn('resources', 'created_by_user_id');

            $updatedByIds = collect();
            $createdByIdsWithoutUpdates = collect();

            if ($hasUpdatedBy) {
                $updatedByIds = (clone $resourceQuery)
                    ->whereNotNull('updated_by_user_id')
                    ->distinct()
                    ->pluck('updated_by_user_id');
            }

            if ($hasCreatedBy) {
                $createdByQuery = clone $resourceQuery;

                if ($hasUpdatedBy) {
                    $createdByQuery->whereNull('updated_by_user_id');
                }

                $createdByIdsWithoutUpdates = $createdByQuery
                    ->whereNotNull('created_by_user_id')
                    ->distinct()
                    ->pluck('created_by_user_id');
            }

            $curatorIds = $updatedByIds->merge($createdByIdsWithoutUpdates)->unique()->values();

            if ($curatorIds->isNotEmpty()) {
                $curators = User::query()
                    ->whereIn('id', $curatorIds->all())
                    ->orderBy('name')
                    ->pluck('name')
                    ->unique()
                    ->values()
                    ->all();
            }
        } catch (Throwable $e) {
            Log::warning('Failed to load curator filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        // Get year range
        try {
            if (Schema::hasColumn('resources', 'year')) {
                $yearMin = Resource::query()->min('year');
                $yearMax = Resource::query()->max('year');
            }

            // When there are no resources yet, min/max can be null.
            // Keep the API shape stable (numbers) to avoid frontend crashes.
            if ($yearMin === null || $yearMax === null) {
                $currentYear = (int) now()->year;
                $yearMin = $currentYear;
                $yearMax = $currentYear;
            }
        } catch (Throwable $e) {
            Log::warning('Failed to load year range filter options', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        // Available publication statuses
        $statuses = ['curation', 'review', 'published'];

        return response()->json([
            'resource_types' => $resourceTypes,
            'curators' => $curators,
            'year_range' => [
                'min' => $yearMin,
                'max' => $yearMax,
            ],
            'statuses' => $statuses,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storePersonCreator(Resource $resource, array $data, int $position): ResourceCreator
    {
        $search = null;

        if (! empty($data['orcid'])) {
            $search = ['name_identifier' => $data['orcid']];
        }

        if ($search === null) {
            $search = [
                'given_name' => $data['firstName'] ?? null,
                'family_name' => $data['lastName'],
            ];
        }

        $person = Person::query()->firstOrNew($search);

        // Only update names if this is a new person (not yet saved to database)
        if (! $person->exists) {
            $person->fill([
                'given_name' => $data['firstName'] ?? $person->given_name,
                'family_name' => $data['lastName'] ?? $person->family_name,
            ]);

            if (! empty($data['orcid'])) {
                $person->name_identifier = $data['orcid'];
                $person->name_identifier_scheme = 'ORCID';
            }
        }

        $person->save();

        return ResourceCreator::query()->create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => $position,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeInstitutionCreator(Resource $resource, array $data, int $position): ResourceCreator
    {
        $name = $data['institutionName'];
        $rorId = $data['rorId'] ?? null;

        $institution = null;

        if ($rorId !== null) {
            $institution = Institution::query()->where('name_identifier', $rorId)->first();

            if ($institution === null) {
                $institution = Institution::query()
                    ->where('name', $name)
                    ->whereNull('name_identifier')
                    ->first();
            }
        }

        if ($institution === null) {
            $institution = Institution::query()
                ->where('name', $name)
                ->whereNull('name_identifier')
                ->first();
        }

        if ($institution === null) {
            $institution = new Institution;
        }

        $institution->name = $name;

        if ($rorId !== null && $institution->name_identifier !== $rorId) {
            $institution->name_identifier = $rorId;
            $institution->name_identifier_scheme = 'ROR';
        }

        $institution->save();

        return ResourceCreator::query()->create([
            'resource_id' => $resource->id,
            'creatorable_id' => $institution->id,
            'creatorable_type' => Institution::class,
            'position' => $position,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncCreatorAffiliations(ResourceCreator $resourceCreator, array $data): void
    {
        $affiliations = $data['affiliations'] ?? [];

        if (! is_array($affiliations) || $affiliations === []) {
            return;
        }

        $payload = [];

        foreach ($affiliations as $affiliation) {
            if (! is_array($affiliation)) {
                continue;
            }

            $value = isset($affiliation['value']) ? trim((string) $affiliation['value']) : '';

            if ($value === '') {
                continue;
            }

            $rorId = null;

            if (array_key_exists('rorId', $affiliation)) {
                $rawRorId = $affiliation['rorId'];

                if ($rawRorId !== null) {
                    $trimmedRorId = trim((string) $rawRorId);
                    $rorId = $trimmedRorId === '' ? null : $trimmedRorId;
                }
            }

            $payload[] = [
                'name' => $value,
                'identifier' => $rorId,
                'identifier_scheme' => $rorId ? 'ROR' : null,
            ];
        }

        if ($payload === []) {
            return;
        }

        $resourceCreator->affiliations()->createMany($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storePersonContributor(Resource $resource, array $data, int $position): ResourceContributor
    {
        $search = null;

        if (! empty($data['orcid'])) {
            $search = ['name_identifier' => $data['orcid']];
        }

        if ($search === null) {
            $search = [
                'given_name' => $data['firstName'] ?? null,
                'family_name' => $data['lastName'],
            ];
        }

        $person = Person::query()->firstOrNew($search);

        // Only update names if this is a new person (not yet saved to database)
        if (! $person->exists) {
            $person->fill([
                'given_name' => $data['firstName'] ?? $person->given_name,
                'family_name' => $data['lastName'] ?? $person->family_name,
            ]);

            if (! empty($data['orcid'])) {
                $person->name_identifier = $data['orcid'];
                $person->name_identifier_scheme = 'ORCID';
            }
        }

        $person->save();

        // Get default contributor type
        $contributorType = ContributorType::where('slug', 'other')->first();

        return ResourceContributor::query()->create([
            'resource_id' => $resource->id,
            'contributorable_id' => $person->id,
            'contributorable_type' => Person::class,
            'contributor_type_id' => $contributorType?->id,
            'position' => $position,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeInstitutionContributor(Resource $resource, array $data, int $position): ResourceContributor
    {
        $name = $data['institutionName'];
        $identifier = $data['identifier'] ?? null;
        $identifierType = $data['identifierType'] ?? null;

        $institution = null;

        // Try to find by identifier and identifier_type first (if provided)
        if ($identifier !== null && $identifierType !== null) {
            $institution = Institution::query()
                ->where('name_identifier', $identifier)
                ->where('name_identifier_scheme', $identifierType)
                ->first();
        }

        // Fallback: find by name without identifier
        if ($institution === null) {
            $institution = Institution::query()
                ->where('name', $name)
                ->whereNull('name_identifier')
                ->whereNull('name_identifier_scheme')
                ->first();
        }

        // Create new institution if not found
        if ($institution === null) {
            $institution = new Institution;
            $institution->name = $name;

            if ($identifier !== null && $identifierType !== null) {
                $institution->name_identifier = $identifier;
                $institution->name_identifier_scheme = $identifierType;
            }

            $institution->save();
        } else {
            // Update existing institution if identifier info is provided
            $needsUpdate = false;

            if ($institution->name !== $name) {
                $institution->name = $name;
                $needsUpdate = true;
            }

            if ($identifier !== null && $identifierType !== null) {
                if ($institution->name_identifier !== $identifier || $institution->name_identifier_scheme !== $identifierType) {
                    $institution->name_identifier = $identifier;
                    $institution->name_identifier_scheme = $identifierType;
                    $needsUpdate = true;
                }
            }

            if ($needsUpdate) {
                $institution->save();
            }
        }

        // Get default contributor type
        $contributorType = ContributorType::where('slug', 'other')->first();

        return ResourceContributor::query()->create([
            'resource_id' => $resource->id,
            'contributorable_id' => $institution->id,
            'contributorable_type' => Institution::class,
            'contributor_type_id' => $contributorType?->id,
            'position' => $position,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncContributorType(ResourceContributor $resourceContributor, array $data): void
    {
        $roles = $data['roles'] ?? [];

        if (! is_array($roles) || $roles === []) {
            return;
        }

        // Get the first role and find the matching contributor type
        $firstRole = reset($roles);
        if (! is_string($firstRole) || trim($firstRole) === '') {
            return;
        }

        $contributorType = ContributorType::where('name', $firstRole)
            ->orWhere('slug', Str::slug($firstRole))
            ->first();

        if ($contributorType) {
            $resourceContributor->contributor_type_id = $contributorType->id;
            $resourceContributor->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncContributorAffiliations(ResourceContributor $resourceContributor, array $data): void
    {
        $affiliations = $data['affiliations'] ?? [];

        if (! is_array($affiliations) || $affiliations === []) {
            return;
        }

        $payload = [];

        foreach ($affiliations as $affiliation) {
            if (! is_array($affiliation)) {
                continue;
            }

            $value = isset($affiliation['value']) ? trim((string) $affiliation['value']) : '';

            if ($value === '') {
                continue;
            }

            $rorId = null;

            if (array_key_exists('rorId', $affiliation)) {
                $rawRorId = $affiliation['rorId'];

                if ($rawRorId !== null) {
                    $trimmedRorId = trim((string) $rawRorId);
                    $rorId = $trimmedRorId === '' ? null : $trimmedRorId;
                }
            }

            $payload[] = [
                'name' => $value,
                'identifier' => $rorId,
                'identifier_scheme' => $rorId !== null ? 'ROR' : null,
            ];
        }

        if ($payload === []) {
            return;
        }

        $resourceContributor->affiliations()->createMany($payload);
    }

    /**
     * Store an MSL Laboratory as Institution contributor.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeMslLaboratory(Resource $resource, array $data, int $position): ResourceContributor
    {
        $identifier = $data['identifier'];
        $name = $data['name'];

        // Try to find existing laboratory by identifier
        $institution = Institution::query()
            ->where('name_identifier', $identifier)
            ->where('name_identifier_scheme', 'labid')
            ->first();

        // Create or update institution
        if ($institution === null) {
            $institution = Institution::query()->create([
                'name' => $name,
                'name_identifier' => $identifier,
                'name_identifier_scheme' => 'labid',
            ]);
        } else {
            // Update name if changed
            if ($institution->name !== $name) {
                $institution->name = $name;
                $institution->save();
            }
        }

        // Get HostingInstitution contributor type
        $contributorType = ContributorType::where('slug', 'hosting-institution')->first();

        // Create ResourceContributor link
        return ResourceContributor::query()->create([
            'resource_id' => $resource->id,
            'contributorable_id' => $institution->id,
            'contributorable_type' => Institution::class,
            'contributor_type_id' => $contributorType?->id,
            'position' => $position,
        ]);
    }

    /**
     * Sync the affiliation (host institution) for an MSL Laboratory.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncMslLaboratoryAffiliation(ResourceContributor $resourceContributor, array $data): void
    {
        $affiliationName = $data['affiliation_name'] ?? '';
        $affiliationRor = $data['affiliation_ror'] ?? null;

        // Skip if no affiliation name
        if (trim($affiliationName) === '') {
            return;
        }

        // Create affiliation for the host institution
        $resourceContributor->affiliations()->create([
            'name' => trim($affiliationName),
            'identifier' => ($affiliationRor !== '' && $affiliationRor !== null) ? $affiliationRor : null,
            'identifier_scheme' => ($affiliationRor !== '' && $affiliationRor !== null) ? 'ROR' : null,
        ]);
    }

    /**
     * Resolve the requested sort state, falling back to the default when invalid.
     *
     * @return array{string, string}
     */
    protected function resolveSortState(Request $request): array
    {
        $requestedKey = strtolower((string) $request->get('sort_key', self::DEFAULT_SORT_KEY));
        $requestedDirection = strtolower((string) $request->get('sort_direction', self::DEFAULT_SORT_DIRECTION));

        $sortKey = in_array($requestedKey, self::ALLOWED_SORT_KEYS, true)
            ? $requestedKey
            : self::DEFAULT_SORT_KEY;

        $sortDirection = in_array($requestedDirection, self::ALLOWED_SORT_DIRECTIONS, true)
            ? $requestedDirection
            : self::DEFAULT_SORT_DIRECTION;

        return [$sortKey, $sortDirection];
    }

    /**
     * Base query for resource listing with optimized eager loading.
     *
     * This query eager loads all necessary relationships to avoid N+1 problems:
     * - Polymorphic relations (creators, contributors) with their affiliations
     * - All lookup tables (resource types, languages, title types)
     * - User relationships for audit tracking
     *
     * Performance: ~10 queries for 50+ resources with complex relationships
     *
     * @return \Illuminate\Database\Eloquent\Builder<Resource>
     */
    protected function baseQuery()
    {
        return Resource::query()
            ->with([
                'resourceType:id,name,slug',
                'language:id,code,name',
                'createdBy:id,name',
                'updatedBy:id,name',
                'landingPage:id,resource_id,is_published,published_at,preview_token',
                'titles' => function ($query): void {
                    $query->select(['id', 'resource_id', 'value', 'title_type_id'])
                        ->with(['titleType:id,name,slug'])
                        ->orderBy('id');
                },
                'rights:id,identifier,name',
                // Eager load dates with the dateType relation.
                // Note: date_type_id MUST be included in the select() for the dateType relation
                // to work, as Eloquent uses it as the foreign key for the belongsTo relation.
                'dates' => function ($query): void {
                    $query->select(['id', 'resource_id', 'date_type_id', 'date_value', 'start_date', 'end_date'])
                        ->with(['dateType:id,slug']);
                },
                'creators' => function ($query): void {
                    $query
                        ->with([
                            'creatorable', // Eager load Person or Institution
                            'affiliations', // Eager load affiliations
                        ])
                        ->orderBy('position');
                },
                'contributors' => function ($query): void {
                    $query
                        ->with([
                            'contributorType',
                            'contributorable', // Eager load Person or Institution
                            'affiliations', // Eager load affiliations
                        ])
                        ->orderBy('position');
                },
            ]);
    }

    /**
    /**
     * Extract filters from the request.
     *
     * @return array<string, mixed>
     */
    protected function extractFilters(Request $request): array
    {
        $filters = [];

        // Resource Type filter
        if ($request->has('resource_type')) {
            $resourceType = $request->input('resource_type');
            if (is_array($resourceType)) {
                $filters['resource_type'] = array_filter($resourceType);
            } elseif (! empty($resourceType)) {
                $filters['resource_type'] = [$resourceType];
            }
        }

        // Curator filter
        if ($request->has('curator')) {
            $curator = $request->input('curator');
            if (is_array($curator)) {
                $filters['curator'] = array_filter($curator);
            } elseif (! empty($curator)) {
                $filters['curator'] = [$curator];
            }
        }

        // Status filter (currently only 'curation' for new resources)
        if ($request->has('status')) {
            $status = $request->input('status');
            if (is_array($status)) {
                $filters['status'] = array_filter($status);
            } elseif (! empty($status)) {
                $filters['status'] = [$status];
            }
        }

        // Publication Year Range
        if ($request->has('year_from') && is_numeric($request->input('year_from'))) {
            $filters['year_from'] = (int) $request->input('year_from');
        }

        if ($request->has('year_to') && is_numeric($request->input('year_to'))) {
            $filters['year_to'] = (int) $request->input('year_to');
        }

        // Text Search
        if ($request->has('search')) {
            $search = trim((string) $request->input('search'));
            if (! empty($search)) {
                $filters['search'] = $search;
            }
        }

        // Date Range filters
        if ($request->has('created_from')) {
            $createdFrom = $request->input('created_from');
            if (! empty($createdFrom)) {
                $filters['created_from'] = $createdFrom;
            }
        }

        if ($request->has('created_to')) {
            $createdTo = $request->input('created_to');
            if (! empty($createdTo)) {
                $filters['created_to'] = $createdTo;
            }
        }

        if ($request->has('updated_from')) {
            $updatedFrom = $request->input('updated_from');
            if (! empty($updatedFrom)) {
                $filters['updated_from'] = $updatedFrom;
            }
        }

        if ($request->has('updated_to')) {
            $updatedTo = $request->input('updated_to');
            if (! empty($updatedTo)) {
                $filters['updated_to'] = $updatedTo;
            }
        }

        return $filters;
    }

    /**
     * Apply filters to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<resource>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters($query, array $filters): void
    {
        // Resource Type filter
        if (! empty($filters['resource_type'])) {
            $query->whereHas('resourceType', function ($q) use ($filters) {
                $q->whereIn('slug', $filters['resource_type']);
            });
        }

        // Curator filter - filter by updatedBy (last editor), fallback to createdBy if never updated
        if (! empty($filters['curator'])) {
            $query->where(function ($q) use ($filters) {
                $q->whereHas('updatedBy', function ($subQ) use ($filters) {
                    $subQ->whereIn('name', $filters['curator']);
                })->orWhere(function ($subQ) use ($filters) {
                    // Fallback: if never updated (updated_by_user_id is null), check creator
                    $subQ->whereNull('updated_by_user_id')
                        ->whereHas('createdBy', function ($creatorQ) use ($filters) {
                            $creatorQ->whereIn('name', $filters['curator']);
                        });
                });
            });
        }

        // Status filter - filter based on DOI and landing page status
        // Must match logic in serializeResource():
        // - curation: no DOI OR (has DOI but no landing page)
        // - review: has DOI AND has landing page with is_published = false
        // - published: has DOI AND has landing page with is_published = true
        if (! empty($filters['status'])) {
            $statuses = $filters['status'];
            $query->where(function ($q) use ($statuses) {
                foreach ($statuses as $status) {
                    if ($status === 'curation') {
                        // Curation: No DOI OR (has DOI but no landing page)
                        $q->orWhere(function ($subQ) {
                            $subQ->whereNull('doi')
                                ->orWhereDoesntHave('landingPage');
                        });
                    } elseif ($status === 'review') {
                        // Review: DOI registered + landing page with is_published = false
                        $q->orWhere(function ($subQ) {
                            $subQ->whereNotNull('doi')
                                ->whereHas('landingPage', function ($lpQ) {
                                    $lpQ->where('is_published', false);
                                });
                        });
                    } elseif ($status === 'published') {
                        // Published: DOI registered + landing page with is_published = true
                        $q->orWhere(function ($subQ) {
                            $subQ->whereNotNull('doi')
                                ->whereHas('landingPage', function ($lpQ) {
                                    $lpQ->where('is_published', true);
                                });
                        });
                    }
                }
            });
        }

        // Year range filter
        if (isset($filters['year_from'])) {
            $query->where('year', '>=', $filters['year_from']);
        }

        if (isset($filters['year_to'])) {
            $query->where('year', '<=', $filters['year_to']);
        }

        // Text search (title, DOI)
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('doi', 'like', "%{$search}%")
                    ->orWhereHas('titles', function ($titleQuery) use ($search) {
                        $titleQuery->where('title', 'like', "%{$search}%");
                    });
            });
        }

        // Created date range
        if (! empty($filters['created_from'])) {
            $query->whereDate('created_at', '>=', $filters['created_from']);
        }

        if (! empty($filters['created_to'])) {
            $query->whereDate('created_at', '<=', $filters['created_to']);
        }

        // Updated date range
        if (! empty($filters['updated_from'])) {
            $query->whereDate('updated_at', '>=', $filters['updated_from']);
        }

        if (! empty($filters['updated_to'])) {
            $query->whereDate('updated_at', '<=', $filters['updated_to']);
        }
    }

    /**
     * Apply sorting to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<resource>  $query
     */
    protected function applySorting($query, string $sortKey, string $sortDirection): void
    {
        switch ($sortKey) {
            case 'title':
                // Sort by first title
                $query->leftJoin('titles', function ($join) {
                    $join->on('resources.id', '=', 'titles.resource_id')
                        ->whereRaw('titles.id = (SELECT MIN(id) FROM titles WHERE resource_id = resources.id)');
                })
                    ->orderBy('titles.value', $sortDirection)
                    ->select('resources.*');
                break;

            case 'resourcetypegeneral':
                $query->leftJoin('resource_types', 'resources.resource_type_id', '=', 'resource_types.id')
                    ->orderBy('resource_types.name', $sortDirection)
                    ->select('resources.*');
                break;

            case 'first_author':
                // Sort by first creator's family name
                $query->leftJoin('resource_creators', function ($join) {
                    $join->on('resources.id', '=', 'resource_creators.resource_id')
                        ->whereRaw('resource_creators.position = (SELECT MIN(position) FROM resource_creators WHERE resource_id = resources.id)');
                })
                    ->leftJoin('persons', function ($join) {
                        $join->on('resource_creators.creatorable_id', '=', 'persons.id')
                            ->where('resource_creators.creatorable_type', '=', Person::class);
                    })
                    ->leftJoin('institutions', function ($join) {
                        $join->on('resource_creators.creatorable_id', '=', 'institutions.id')
                            ->where('resource_creators.creatorable_type', '=', Institution::class);
                    })
                    ->orderByRaw("COALESCE(persons.family_name, institutions.name) {$sortDirection}")
                    ->select('resources.*');
                break;

            case 'curator':
                $query->leftJoin('users as creator_users', 'resources.created_by_user_id', '=', 'creator_users.id')
                    ->orderBy('creator_users.name', $sortDirection)
                    ->select('resources.*');
                break;

            case 'publicstatus':
                // All resources have 'curation' status, so this doesn't really sort
                // But we keep it for consistency
                $query->orderBy('id', $sortDirection);
                break;

            default:
                // Direct column sorting (id, doi, year, created_at, updated_at)
                $query->orderBy($sortKey, $sortDirection);
                break;
        }
    }

    /**
     * Serialize a Resource model to an array for API responses.
     *
     * @param  Resource  $resource  The resource to serialize (must have titles, rights, creators relationships loaded)
     * @return array<string, mixed> The serialized resource data
     */
    private function serializeResource(Resource $resource): array
    {
        // In development, assert all required relations are loaded to detect N+1 queries
        if (app()->environment('local', 'testing')) {
            $this->assertRelationsLoaded($resource);
        }

        // Get first creator
        $firstCreator = $resource->creators->first();
        $firstCreatorData = null;

        if ($firstCreator) {
            $creatorable = $firstCreator->creatorable;
            if ($creatorable instanceof Person) {
                $firstCreatorData = [
                    'givenName' => $creatorable->given_name,
                    'familyName' => $creatorable->family_name,
                ];
            } elseif ($creatorable instanceof Institution) {
                $firstCreatorData = [
                    'name' => $creatorable->name,
                ];
            }
        }

        // Determine publication status based on DOI and landing page status
        $publicStatus = 'curation'; // Default status
        if ($resource->doi && $resource->landingPage) {
            $publicStatus = $resource->landingPage->is_published ? 'published' : 'review';
        }

        // Get DataCite dates (Created/Updated) from the dates relation instead of Eloquent timestamps.
        // This preserves the original creation/update dates from imported datasets.
        //
        // Performance note: The dates relation is eager loaded by baseQuery() with only the
        // necessary columns (id, resource_id, date_type_id, date_value, start_date, end_date)
        // and the dateType relation. Filtering is done on the in-memory collection which is
        // efficient for the typical small number of dates per resource (usually <10).
        //
        // If resources commonly have many dates, consider indexing dates by type during
        // eager loading using a custom accessor or query scope.
        $createdDateRecord = $resource->dates->first(fn ($date) => $date->dateType->slug === 'Created');
        $createdDate = $createdDateRecord !== null
            ? ($createdDateRecord->date_value ?? $createdDateRecord->start_date)
            : null;

        // For Updated dates, get the most recent one by sorting only Updated dates
        $updatedDateRecord = $resource->dates
            ->filter(fn ($date) => $date->dateType->slug === 'Updated')
            ->sortByDesc(fn ($date) => $date->date_value ?? $date->start_date ?? '')
            ->first();
        $updatedDate = $updatedDateRecord !== null
            ? ($updatedDateRecord->date_value ?? $updatedDateRecord->start_date)
            : null;

        return [
            'id' => $resource->id,
            'doi' => $resource->doi,
            'year' => $resource->publication_year,
            'version' => $resource->version,
            // Use DataCite dates if available, otherwise fall back to Eloquent timestamps
            'created_at' => $createdDate ?? $resource->created_at?->toIso8601String(),
            'updated_at' => $updatedDate ?? $resource->updated_at?->toIso8601String(),
            'curator' => $resource->updatedBy?->name ?? $resource->createdBy?->name, // @phpstan-ignore nullsafe.neverNull (updatedBy can be null if updated_by_user_id is null)
            'publicstatus' => $publicStatus,
            'resourcetypegeneral' => $resource->resourceType?->name,
            'resource_type' => $resource->resourceType ? [
                'name' => $resource->resourceType->name,
                'slug' => $resource->resourceType->slug,
            ] : null,
            'language' => $resource->language ? [
                'code' => $resource->language->code,
                'name' => $resource->language->name,
            ] : null,
            'title' => $resource->titles->first()?->value,
            'titles' => $resource->titles
                ->map(static function (Title $title): array {
                    return [
                        'title' => $title->value,
                        // Use null-safe operator for legacy data where titleType may be null
                        'title_type' => $title->titleType !== null ? [
                            'name' => $title->titleType->name,
                            'slug' => $title->titleType->slug,
                        ] : [
                            'name' => 'Main Title',
                            'slug' => 'MainTitle',
                        ],
                    ];
                })
                ->values()
                ->all(),
            'rights' => $resource->rights
                ->map(static function (Right $right): array {
                    return [
                        'identifier' => $right->identifier,
                        'name' => $right->name,
                    ];
                })
                ->values()
                ->all(),
            'first_author' => $firstCreatorData,
            'landingPage' => $resource->landingPage ? [
                'id' => $resource->landingPage->id,
                'is_published' => $resource->landingPage->is_published,
                'public_url' => $resource->landingPage->public_url,
            ] : null,
        ];
    }

    /**
     * Export a resource as DataCite JSON
     */
    public function exportDataCiteJson(Resource $resource): SymfonyResponse
    {
        $exporter = new DataCiteJsonExporter;
        $dataCiteJson = $exporter->export($resource);

        // Generate filename with timestamp
        $timestamp = now()->format('YmdHis');
        $filename = "resource-{$resource->id}-{$timestamp}-datacite.json";

        return response()->json($dataCiteJson, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Export a resource as DataCite XML
     */
    public function exportDataCiteXml(Resource $resource): SymfonyResponse
    {
        try {
            // Generate XML
            $exporter = new DataCiteXmlExporter;
            $xml = $exporter->export($resource);

            // Validate against XSD schema
            $validator = new DataCiteXmlValidator;
            $isValid = $validator->validate($xml);

            // Generate filename with timestamp
            $timestamp = now()->format('YmdHis');
            $filename = "resource-{$resource->id}-{$timestamp}-datacite.xml";

            $headers = [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            // Add validation warning header if validation failed
            if (! $isValid && $validator->hasWarnings()) {
                $warningMessage = $validator->getFormattedWarningMessage();
                if ($warningMessage) {
                    $headers['X-Validation-Warning'] = base64_encode($warningMessage);
                }
            }

            return response($xml, 200, $headers);

        } catch (\Exception $e) {
            // Log full exception details for debugging
            Log::error('DataCite XML export failed', [
                'resource_id' => $resource->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return generic error message in production, detailed in development
            $message = config('app.debug')
                ? $e->getMessage()
                : 'An error occurred while generating the XML export. Please contact support if the problem persists.';

            return response()->json([
                'error' => 'Failed to export DataCite XML',
                'message' => $message,
            ], 500);
        }
    }

    /**
     * Register a DOI with DataCite or update metadata for existing DOI
     */
    public function registerDoi(RegisterDoiRequest $request, Resource $resource): JsonResponse
    {
        try {
            // Check if resource has a landing page
            $resource->load('landingPage');
            if (! $resource->landingPage) {
                return response()->json([
                    'error' => 'Landing page required',
                    'message' => 'A landing page must be created before registering a DOI. Please set up a landing page first.',
                ], 422);
            }

            // Resolve service from container (allows testing with fake service)
            $service = app(DataCiteRegistrationService::class);

            // Check if DOI already exists - if yes, update metadata instead of registering
            if ($resource->doi) {
                Log::info('Updating existing DOI metadata', [
                    'resource_id' => $resource->id,
                    'doi' => $resource->doi,
                ]);

                $response = $service->updateMetadata($resource);

                // Extract DOI from response
                $doi = $response['data']['id'] ?? $resource->doi;

                return response()->json([
                    'success' => true,
                    'message' => 'DOI metadata updated successfully',
                    'doi' => $doi,
                    'mode' => $service->isTestMode() ? 'test' : 'production',
                    'updated' => true,
                ]);
            }

            // Register new DOI
            $validated = $request->validated();
            $prefix = $validated['prefix'];

            Log::info('Registering new DOI', [
                'resource_id' => $resource->id,
                'prefix' => $prefix,
                'test_mode' => $service->isTestMode(),
            ]);

            $response = $service->registerDoi($resource, $prefix);

            // Extract DOI from DataCite response
            $doi = $response['data']['id'] ?? null;

            if (! $doi) {
                Log::error('DataCite response missing DOI', [
                    'resource_id' => $resource->id,
                    'response' => $response,
                ]);

                return response()->json([
                    'error' => 'Registration incomplete',
                    'message' => 'DOI was registered but the response did not contain the DOI identifier.',
                ], 500);
            }

            // Save DOI to resource
            $resource->doi = $doi;
            $resource->save();

            Log::info('DOI saved to resource', [
                'resource_id' => $resource->id,
                'doi' => $doi,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'DOI registered successfully',
                'doi' => $doi,
                'mode' => $service->isTestMode() ? 'test' : 'production',
                'updated' => false,
            ]);

        } catch (\InvalidArgumentException $e) {
            Log::warning('Invalid DOI registration request', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Invalid request',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\RuntimeException $e) {
            Log::warning('DOI registration runtime error', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Registration failed',
                'message' => $e->getMessage(),
            ], 422);

        } catch (RequestException $e) {
            // DataCite API error
            // PHPDoc indicates response is always present, but it can be null at runtime
            $response = $e->response;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $statusCode = $response !== null ? $response->status() : 500;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $apiError = $response !== null ? $response->json() : null;

            Log::error('DataCite API error during DOI registration', [
                'resource_id' => $resource->id,
                'status' => $statusCode,
                'error' => $e->getMessage(),
                'api_response' => $apiError,
            ]);

            // Extract error message from DataCite response
            $errorMessage = 'Failed to communicate with DataCite API.';
            if (isset($apiError['errors']) && is_array($apiError['errors']) && count($apiError['errors']) > 0) {
                $firstError = $apiError['errors'][0];
                $errorMessage = $firstError['title'] ?? $firstError['detail'] ?? $errorMessage;
            }

            return response()->json([
                'error' => 'DataCite API error',
                'message' => $errorMessage,
                'details' => config('app.debug') ? $apiError : null,
            ], $statusCode >= 400 && $statusCode < 500 ? $statusCode : 500);

        } catch (\Exception $e) {
            Log::error('Unexpected error during DOI registration', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Unexpected error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred during DOI registration. Please contact support.',
            ], 500);
        }
    }

    /**
     * Get available DataCite prefixes based on test mode configuration
     */
    public function getDataCitePrefixes(): JsonResponse
    {
        $testMode = (bool) config('datacite.test_mode', true);

        $prefixes = [
            'test' => config('datacite.test.prefixes', []),
            'production' => config('datacite.production.prefixes', []),
            'test_mode' => $testMode,
        ];

        return response()->json($prefixes);
    }

    /**
     * Assert that all required relations are loaded to prevent N+1 queries.
     *
     * This method is only called in development environment and throws an
     * exception if any required relation is not eager loaded.
     *
     *
     * @throws \RuntimeException if a required relation is not loaded
     */
    private function assertRelationsLoaded(Resource $resource): void
    {
        $requiredRelations = [
            'creators',
            'contributors',
            'titles',
            'rights',
            'dates',
            'resourceType',
            'language',
            'createdBy',
            'updatedBy',
            'landingPage',
        ];

        foreach ($requiredRelations as $relation) {
            if (! $resource->relationLoaded($relation)) {
                throw new \RuntimeException(
                    "Relation '{$relation}' not loaded on Resource #{$resource->id}. N+1 query detected! ".
                    'Ensure baseQuery() eager loads all required relationships.'
                );
            }
        }

        // Check that dateType is loaded on dates to prevent N+1 when accessing $date->dateType->slug
        if ($resource->dates->isNotEmpty()) {
            $firstDate = $resource->dates->first();
            if (! $firstDate->relationLoaded('dateType')) {
                throw new \RuntimeException(
                    'Relation dateType not loaded on ResourceDate. N+1 query detected!'
                );
            }
        }

        // Check nested relations on creators if creators exist
        if ($resource->creators->isNotEmpty()) {
            $firstCreator = $resource->creators->first();
            if (! $firstCreator->relationLoaded('creatorable')) {
                throw new \RuntimeException(
                    'Relation creatorable not loaded on ResourceCreator. N+1 query detected!'
                );
            }
            // Also check affiliations relation (note: affiliations are polymorphic and store
            // affiliation data directly - they don't have a separate institution relation)
            if (! $firstCreator->relationLoaded('affiliations')) {
                throw new \RuntimeException(
                    'Relation affiliations not loaded on ResourceCreator. N+1 query detected!'
                );
            }
        }

        // Check nested relations on contributors if contributors exist
        if ($resource->contributors->isNotEmpty()) {
            $firstContributor = $resource->contributors->first();
            if (! $firstContributor->relationLoaded('contributorable')) {
                throw new \RuntimeException(
                    'Relation contributorable not loaded on ResourceContributor. N+1 query detected!'
                );
            }
            if (! $firstContributor->relationLoaded('contributorType')) {
                throw new \RuntimeException(
                    'Relation contributorType not loaded on ResourceContributor. N+1 query detected!'
                );
            }
            // Also check affiliations relation (note: affiliations are polymorphic and store
            // affiliation data directly - they don't have a separate institution relation)
            if (! $firstContributor->relationLoaded('affiliations')) {
                throw new \RuntimeException(
                    'Relation affiliations not loaded on ResourceContributor. N+1 query detected!'
                );
            }
        }
    }

    /**
     * Get funder identifier type ID from type name.
     */
    private function getFunderIdentifierTypeId(string $type): ?int
    {
        static $cache = [];

        if (isset($cache[$type])) {
            return $cache[$type];
        }

        $funderType = \App\Models\FunderIdentifierType::where('name', $type)
            ->orWhere('slug', \Illuminate\Support\Str::slug($type))
            ->first();

        $cache[$type] = $funderType?->id;

        return $cache[$type];
    }
}
