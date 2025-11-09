<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterDoiRequest;
use App\Http\Requests\StoreResourceRequest;
use App\Models\Institution;
use App\Models\Language;
use App\Models\License;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceAuthor;
use App\Models\ResourceTitle;
use App\Models\Role;
use App\Models\TitleType;
use App\Models\User;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteXmlExporter;
use App\Services\DataCiteXmlValidator;
use App\Support\BooleanNormalizer;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        $resources = $query
            ->paginate($perPage, ['*'], 'page', $page);

        $resourcesData = collect($resources->items())
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
                    'year' => $validated['year'],
                    'resource_type_id' => $validated['resourceType'],
                    'version' => $validated['version'] ?? null,
                    'language_id' => $languageId,
                ];

                $isUpdate = ! empty($validated['resourceId']);

                if ($isUpdate) {
                    /** @var resource $resource */
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

                $titleTypeSlugs = [];

                foreach ($validated['titles'] as $titleData) {
                    $titleTypeSlugs[] = $titleData['titleType'];
                }

                /** @var array<string, int> $titleTypeMap */
                $titleTypeMap = TitleType::query()
                    ->whereIn('slug', $titleTypeSlugs)
                    ->pluck('id', 'slug')
                    ->all();

                $resourceTitles = [];

                foreach ($validated['titles'] as $title) {
                    $resourceTitles[] = [
                        'title' => $title['title'],
                        'title_type_id' => $titleTypeMap[$title['titleType']],
                    ];
                }

                if ($isUpdate) {
                    $resource->titles()->delete();
                }

                $resource->titles()->createMany($resourceTitles);

                /** @var array<int, int> $licenseIds */
                $licenseIds = License::query()
                    ->whereIn('identifier', $validated['licenses'])
                    ->pluck('id')
                    ->all();

                $resource->licenses()->sync($licenseIds);

                $resource->authors()->delete();

                $authors = $validated['authors'] ?? [];

                foreach ($authors as $author) {
                    $position = isset($author['position']) && is_int($author['position'])
                        ? $author['position']
                        : 0;

                    if (($author['type'] ?? 'person') === 'institution') {
                        $resourceAuthor = $this->storeInstitutionAuthor($resource, $author, $position);
                    } else {
                        $resourceAuthor = $this->storePersonAuthor($resource, $author, $position);
                    }

                    $this->syncAuthorRoles($resourceAuthor, $author);
                    $this->syncAuthorAffiliations($resourceAuthor, $author);
                }

                // Delete old MSL labs if updating (before adding new ones)
                if ($isUpdate) {
                    // Get all existing MSL labs (institutions with identifier_type = 'labid')
                    // Use whereIn with subquery to avoid morph type issues
                    $mslLabIds = Institution::where('identifier_type', 'labid')
                        ->pluck('id');

                    $mslLabs = ResourceAuthor::query()
                        ->where('resource_id', $resource->id)
                        ->where('authorable_type', Institution::class)
                        ->whereIn('authorable_id', $mslLabIds)
                        ->get();

                    // Properly cleanup relationships before deleting
                    foreach ($mslLabs as $mslLab) {
                        $mslLab->roles()->detach();      // Remove pivot table entries
                        $mslLab->affiliations()->delete(); // Delete child affiliation records
                        $mslLab->delete();               // Finally delete the ResourceAuthor
                    }
                }

                $contributors = $validated['contributors'] ?? [];

                foreach ($contributors as $contributor) {
                    $position = isset($contributor['position']) && is_int($contributor['position'])
                        ? $contributor['position']
                        : 0;

                    if (($contributor['type'] ?? 'person') === 'institution') {
                        $resourceContributor = $this->storeInstitutionContributor($resource, $contributor, $position);
                    } else {
                        $resourceContributor = $this->storePersonContributor($resource, $contributor, $position);
                    }

                    $this->syncContributorRoles($resourceContributor, $contributor);
                    $this->syncContributorAffiliations($resourceContributor, $contributor);
                }

                // Save MSL Laboratories
                $mslLaboratories = $validated['mslLaboratories'] ?? [];

                foreach ($mslLaboratories as $lab) {
                    $position = (int) ($lab['position'] ?? 0);

                    $resourceAuthor = $this->storeMslLaboratory($resource, $lab, $position);
                    $this->syncMslLaboratoryRole($resourceAuthor);
                    $this->syncMslLaboratoryAffiliation($resourceAuthor, $lab);
                }

                // Save descriptions
                if ($isUpdate) {
                    $resource->descriptions()->delete();
                }

                $descriptions = $validated['descriptions'] ?? [];

                foreach ($descriptions as $description) {
                    $resource->descriptions()->create([
                        'description_type' => $description['descriptionType'],
                        'description' => $description['description'],
                    ]);
                }

                // Save dates
                if ($isUpdate) {
                    $resource->dates()->delete();
                }

                $dates = $validated['dates'] ?? [];

                foreach ($dates as $date) {
                    $resource->dates()->create([
                        'date_type' => $date['dateType'],
                        'start_date' => $date['startDate'] ?? null,
                        'end_date' => $date['endDate'] ?? null,
                        'date_information' => $date['dateInformation'] ?? null,
                    ]);
                }

                // Save free keywords
                if ($isUpdate) {
                    $resource->keywords()->delete();
                }

                $freeKeywords = $validated['freeKeywords'] ?? [];

                foreach ($freeKeywords as $keyword) {
                    // Only save non-empty keywords
                    if (! empty(trim($keyword))) {
                        $resource->keywords()->create([
                            'keyword' => trim($keyword),
                        ]);
                    }
                }

                // Save controlled keywords (GCMD vocabularies)
                if ($isUpdate) {
                    $resource->controlledKeywords()->delete();
                }

                $controlledKeywords = $validated['gcmdKeywords'] ?? [];

                // Prepare controlled keywords for bulk creation
                $controlledKeywordsData = [];
                foreach ($controlledKeywords as $keyword) {
                    // Validate required fields (scheme is now the discriminator instead of vocabularyType)
                    if (! empty($keyword['id']) && ! empty($keyword['text']) && ! empty($keyword['scheme'])) {
                        $controlledKeywordsData[] = [
                            'keyword_id' => $keyword['id'],
                            'text' => $keyword['text'],
                            'path' => $keyword['path'] ?? $keyword['text'],
                            'language' => $keyword['language'] ?? 'en',
                            'scheme' => $keyword['scheme'],
                            'scheme_uri' => $keyword['schemeURI'] ?? '',
                        ];
                    }
                }

                // Bulk create controlled keywords using Eloquent (handles timestamps automatically)
                if (! empty($controlledKeywordsData)) {
                    $resource->controlledKeywords()->createMany($controlledKeywordsData);
                }

                // Save spatial and temporal coverages
                if ($isUpdate) {
                    $resource->coverages()->delete();
                }

                $coverages = $validated['spatialTemporalCoverages'] ?? [];

                foreach ($coverages as $coverage) {
                    // Only save coverage if it has at least one meaningful field
                    $hasData = ! empty($coverage['latMin']) || ! empty($coverage['lonMin']) ||
                               ! empty($coverage['startDate']) || ! empty($coverage['description']);

                    if ($hasData) {
                        $resource->coverages()->create([
                            'lat_min' => $coverage['latMin'] ?? null,
                            'lat_max' => $coverage['latMax'] ?? null,
                            'lon_min' => $coverage['lonMin'] ?? null,
                            'lon_max' => $coverage['lonMax'] ?? null,
                            'start_date' => $coverage['startDate'] ?? null,
                            'end_date' => $coverage['endDate'] ?? null,
                            'start_time' => $coverage['startTime'] ?? null,
                            'end_time' => $coverage['endTime'] ?? null,
                            'timezone' => $coverage['timezone'] ?? 'UTC',
                            'description' => $coverage['description'] ?? null,
                        ]);
                    }
                }

                // Save related identifiers
                if ($isUpdate) {
                    $resource->relatedIdentifiers()->delete();
                }

                $relatedIdentifiers = $validated['relatedIdentifiers'] ?? [];

                foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
                    // Only save if identifier is not empty
                    if (! empty(trim($relatedIdentifier['identifier']))) {
                        $resource->relatedIdentifiers()->create([
                            'identifier' => trim($relatedIdentifier['identifier']),
                            'identifier_type' => $relatedIdentifier['identifierType'],
                            'relation_type' => $relatedIdentifier['relationType'],
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
                            'funder_identifier_type' => ! empty($fundingReference['funderIdentifierType']) ? trim($fundingReference['funderIdentifierType']) : null,
                            'award_number' => ! empty($fundingReference['awardNumber']) ? trim($fundingReference['awardNumber']) : null,
                            'award_uri' => ! empty($fundingReference['awardUri']) ? trim($fundingReference['awardUri']) : null,
                            'award_title' => ! empty($fundingReference['awardTitle']) ? trim($fundingReference['awardTitle']) : null,
                            'position' => $index,
                        ]);
                    }
                }

                return [$resource->load(['titles', 'licenses', 'authors', 'descriptions', 'dates', 'keywords', 'controlledKeywords', 'coverages', 'relatedIdentifiers', 'fundingReferences']), $isUpdate];
            });
        } catch (Throwable $exception) {
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

    public function destroy(Resource $resource): RedirectResponse
    {
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

        $resources = $query
            ->paginate($perPage, ['*'], 'page', $page);

        $resourcesData = collect($resources->items())
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
        // Get distinct resource types
        $resourceTypes = \App\Models\ResourceType::query()
            ->whereHas('resources')
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(fn ($type) => ['name' => $type->name, 'slug' => $type->slug])
            ->all();

        // Get distinct curators (users who updated or created resources)
        // Prioritize updatedBy, fallback to createdBy if never updated
        $updatedByIds = Resource::query()
            ->whereNotNull('updated_by_user_id')
            ->distinct()
            ->pluck('updated_by_user_id');

        $createdByIdsWithoutUpdates = Resource::query()
            ->whereNull('updated_by_user_id')
            ->whereNotNull('created_by_user_id')
            ->distinct()
            ->pluck('created_by_user_id');

        $curatorIds = $updatedByIds->merge($createdByIdsWithoutUpdates)->unique();

        $curators = User::query()
            ->whereIn('id', $curatorIds)
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();

        // Get year range
        $yearMin = Resource::query()->min('year');
        $yearMax = Resource::query()->max('year');

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
    private function storePersonAuthor(Resource $resource, array $data, int $position): ResourceAuthor
    {
        $search = null;

        if (! empty($data['orcid'])) {
            $search = ['orcid' => $data['orcid']];
        }

        if ($search === null) {
            $search = [
                'first_name' => $data['firstName'] ?? null,
                'last_name' => $data['lastName'],
            ];
        }

        $person = Person::query()->firstOrNew($search);

        // Only update names if this is a new person (not yet saved to database)
        if (! $person->exists) {
            $person->fill([
                'first_name' => $data['firstName'] ?? $person->first_name,
                'last_name' => $data['lastName'] ?? $person->last_name,
            ]);

            if (! empty($data['orcid'])) {
                $person->orcid = $data['orcid'];
            }
        }

        $person->save();

        return ResourceAuthor::query()->create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => $position,
            'email' => $data['email'] ?? null,
            'website' => $data['website'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeInstitutionAuthor(Resource $resource, array $data, int $position): ResourceAuthor
    {
        $name = $data['institutionName'];
        $rorId = $data['rorId'] ?? null;

        $institution = null;

        if ($rorId !== null) {
            $institution = Institution::query()->where('ror_id', $rorId)->first();

            if ($institution === null) {
                $institution = Institution::query()
                    ->where('name', $name)
                    ->whereNull('ror_id')
                    ->first();
            }
        }

        if ($institution === null) {
            $institution = Institution::query()
                ->where('name', $name)
                ->whereNull('ror_id')
                ->first();
        }

        if ($institution === null) {
            $institution = new Institution;
        }

        $institution->name = $name;

        if ($rorId !== null && $institution->ror_id !== $rorId) {
            $institution->ror_id = $rorId;
        }

        $institution->save();

        return ResourceAuthor::query()->create([
            'resource_id' => $resource->id,
            'authorable_id' => $institution->id,
            'authorable_type' => Institution::class,
            'position' => $position,
            'email' => null,
            'website' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAuthorRoles(ResourceAuthor $resourceAuthor, array $data): void
    {
        $roles = ['Author'];

        if (($data['type'] ?? 'person') === 'person' && BooleanNormalizer::isTrue($data['isContact'] ?? false)) {
            $roles[] = 'Contact Person';
        }

        $roleIds = [];

        foreach ($roles as $roleName) {
            $role = Role::query()->firstOrCreate(
                ['slug' => Str::slug($roleName)],
                ['name' => $roleName],
            );

            $roleIds[] = $role->id;
        }

        $resourceAuthor->roles()->sync($roleIds);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAuthorAffiliations(ResourceAuthor $resourceAuthor, array $data): void
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
                'value' => $value,
                'ror_id' => $rorId,
            ];
        }

        if ($payload === []) {
            return;
        }

        $resourceAuthor->affiliations()->createMany($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storePersonContributor(Resource $resource, array $data, int $position): ResourceAuthor
    {
        $search = null;

        if (! empty($data['orcid'])) {
            $search = ['orcid' => $data['orcid']];
        }

        if ($search === null) {
            $search = [
                'first_name' => $data['firstName'] ?? null,
                'last_name' => $data['lastName'],
            ];
        }

        $person = Person::query()->firstOrNew($search);

        // Only update names if this is a new person (not yet saved to database)
        if (! $person->exists) {
            $person->fill([
                'first_name' => $data['firstName'] ?? $person->first_name,
                'last_name' => $data['lastName'] ?? $person->last_name,
            ]);

            if (! empty($data['orcid'])) {
                $person->orcid = $data['orcid'];
            }
        }

        $person->save();

        return ResourceAuthor::query()->create([
            'resource_id' => $resource->id,
            'authorable_id' => $person->id,
            'authorable_type' => Person::class,
            'position' => $position,
            'email' => null,
            'website' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeInstitutionContributor(Resource $resource, array $data, int $position): ResourceAuthor
    {
        $name = $data['institutionName'];
        $identifier = $data['identifier'] ?? null;
        $identifierType = $data['identifierType'] ?? null;

        $institution = null;

        // Try to find by identifier and identifier_type first (if provided)
        if ($identifier !== null && $identifierType !== null) {
            $institution = Institution::query()
                ->where('identifier', $identifier)
                ->where('identifier_type', $identifierType)
                ->first();
        }

        // Fallback: find by name without identifier
        if ($institution === null) {
            $institution = Institution::query()
                ->where('name', $name)
                ->whereNull('identifier')
                ->whereNull('identifier_type')
                ->first();
        }

        // Create new institution if not found
        if ($institution === null) {
            $institution = new Institution;
            $institution->name = $name;

            if ($identifier !== null && $identifierType !== null) {
                $institution->identifier = $identifier;
                $institution->identifier_type = $identifierType;
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
                if ($institution->identifier !== $identifier || $institution->identifier_type !== $identifierType) {
                    $institution->identifier = $identifier;
                    $institution->identifier_type = $identifierType;
                    $needsUpdate = true;
                }
            }

            if ($needsUpdate) {
                $institution->save();
            }
        }

        return ResourceAuthor::query()->create([
            'resource_id' => $resource->id,
            'authorable_id' => $institution->id,
            'authorable_type' => Institution::class,
            'position' => $position,
            'email' => null,
            'website' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncContributorRoles(ResourceAuthor $resourceContributor, array $data): void
    {
        $roles = $data['roles'] ?? [];

        if (! is_array($roles) || $roles === []) {
            return;
        }

        $roleIds = [];

        foreach ($roles as $roleName) {
            if (! is_string($roleName) || trim($roleName) === '') {
                continue;
            }

            $role = Role::query()->firstOrCreate(
                ['slug' => Str::slug($roleName)],
                ['name' => $roleName],
            );

            $roleIds[] = $role->id;
        }

        $resourceContributor->roles()->sync($roleIds);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncContributorAffiliations(ResourceAuthor $resourceContributor, array $data): void
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
                'value' => $value,
                'ror_id' => $rorId,
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
    private function storeMslLaboratory(Resource $resource, array $data, int $position): ResourceAuthor
    {
        $identifier = $data['identifier'];
        $name = $data['name'];

        // Try to find existing laboratory by identifier
        $institution = Institution::query()
            ->where('identifier', $identifier)
            ->where('identifier_type', 'labid')
            ->first();

        // Create or update institution
        if ($institution === null) {
            $institution = Institution::query()->create([
                'name' => $name,
                'identifier' => $identifier,
                'identifier_type' => 'labid',
            ]);
        } else {
            // Update name if changed
            if ($institution->name !== $name) {
                $institution->name = $name;
                $institution->save();
            }
        }

        // Create ResourceAuthor link
        return ResourceAuthor::query()->create([
            'resource_id' => $resource->id,
            'authorable_id' => $institution->id,
            'authorable_type' => Institution::class,
            'position' => $position,
            'email' => null,
            'website' => null,
        ]);
    }

    /**
     * Sync the "Hosting Institution" role for an MSL Laboratory.
     */
    private function syncMslLaboratoryRole(ResourceAuthor $resourceAuthor): void
    {
        // Get or create the "Hosting Institution" role
        $role = Role::query()->firstOrCreate(
            ['slug' => 'hosting-institution'],
            ['name' => 'Hosting Institution', 'applies_to' => 'institution'],
        );

        $resourceAuthor->roles()->sync([$role->id]);
    }

    /**
     * Sync the affiliation (host institution) for an MSL Laboratory.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncMslLaboratoryAffiliation(ResourceAuthor $resourceAuthor, array $data): void
    {
        $affiliationName = $data['affiliation_name'] ?? '';
        $affiliationRor = $data['affiliation_ror'] ?? null;

        // Skip if no affiliation name
        if (trim($affiliationName) === '') {
            return;
        }

        // Create affiliation for the host institution
        $resourceAuthor->affiliations()->create([
            'value' => trim($affiliationName),
            'ror_id' => $affiliationRor !== '' ? $affiliationRor : null,
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
     * Build the base query with eager-loaded relationships.
     *
     * @return \Illuminate\Database\Eloquent\Builder<resource>
     */
    protected function baseQuery()
    {
        return Resource::query()
            ->with([
                'resourceType:id,name,slug',
                'language:id,code,name',
                'createdBy:id,name',
                'updatedBy:id,name',
                'landingPage:id,resource_id,status,published_at',
                'titles' => function ($query): void {
                    $query->select(['id', 'resource_id', 'title', 'title_type_id'])
                        ->with(['titleType:id,name,slug'])
                        ->orderBy('id');
                },
                'licenses:id,identifier,name',
                'authors' => function ($query): void {
                    $query
                        ->with([
                            'authorable',
                            'roles:id,name,slug,applies_to',
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
        // - review: has DOI AND has landing page with status 'draft'
        // - published: has DOI AND has landing page with status 'published'
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
                        // Review: DOI registered + landing page with draft status
                        $q->orWhere(function ($subQ) {
                            $subQ->whereNotNull('doi')
                                ->whereHas('landingPage', function ($lpQ) {
                                    $lpQ->where('status', 'draft');
                                });
                        });
                    } elseif ($status === 'published') {
                        // Published: DOI registered + landing page with published status
                        $q->orWhere(function ($subQ) {
                            $subQ->whereNotNull('doi')
                                ->whereHas('landingPage', function ($lpQ) {
                                    $lpQ->where('status', 'published');
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
                $query->leftJoin('resource_titles', function ($join) {
                    $join->on('resources.id', '=', 'resource_titles.resource_id')
                        ->whereRaw('resource_titles.id = (SELECT MIN(id) FROM resource_titles WHERE resource_id = resources.id)');
                })
                    ->orderBy('resource_titles.title', $sortDirection)
                    ->select('resources.*');
                break;

            case 'resourcetypegeneral':
                $query->leftJoin('resource_types', 'resources.resource_type_id', '=', 'resource_types.id')
                    ->orderBy('resource_types.name', $sortDirection)
                    ->select('resources.*');
                break;

            case 'first_author':
                // Sort by first author's last name
                $query->leftJoin('resource_authors', function ($join) {
                    $join->on('resources.id', '=', 'resource_authors.resource_id')
                        ->whereRaw('resource_authors.position = (SELECT MIN(position) FROM resource_authors WHERE resource_id = resources.id)');
                })
                    ->leftJoin('persons', function ($join) {
                        $join->on('resource_authors.authorable_id', '=', 'persons.id')
                            ->where('resource_authors.authorable_type', '=', Person::class);
                    })
                    ->leftJoin('institutions', function ($join) {
                        $join->on('resource_authors.authorable_id', '=', 'institutions.id')
                            ->where('resource_authors.authorable_type', '=', Institution::class);
                    })
                    ->orderByRaw("COALESCE(persons.last_name, institutions.name) {$sortDirection}")
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
     * @param  resource  $resource  The resource to serialize (must have titles, licenses, authors relationships loaded)
     * @return array<string, mixed> The serialized resource data
     */
    private function serializeResource(Resource $resource): array
    {
        // Get first author
        $firstAuthor = $resource->authors->first();
        $firstAuthorData = null;

        if ($firstAuthor) {
            $authorable = $firstAuthor->authorable;
            if ($authorable instanceof Person) {
                $firstAuthorData = [
                    'givenName' => $authorable->first_name,
                    'familyName' => $authorable->last_name,
                ];
            } elseif ($authorable instanceof Institution) {
                $firstAuthorData = [
                    'name' => $authorable->name,
                ];
            }
        }

        // Determine publication status based on DOI and landing page status
        $publicStatus = 'curation'; // Default status
        if ($resource->doi && $resource->landingPage) {
            $publicStatus = $resource->landingPage->status === 'published' ? 'published' : 'review';
        }

        return [
            'id' => $resource->id,
            'doi' => $resource->doi,
            'year' => $resource->year,
            'version' => $resource->version,
            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
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
            'title' => $resource->titles->first()?->title,
            'titles' => $resource->titles
                ->map(static function (ResourceTitle $title): array {
                    return [
                        'title' => $title->title,
                        'title_type' => $title->titleType ? [
                            'name' => $title->titleType->name,
                            'slug' => $title->titleType->slug,
                        ] : null,
                    ];
                })
                ->values()
                ->all(),
            'licenses' => $resource->licenses
                ->map(static function (License $license): array {
                    return [
                        'identifier' => $license->identifier,
                        'name' => $license->name,
                    ];
                })
                ->values()
                ->all(),
            'first_author' => $firstAuthorData,
            'landingPage' => $resource->landingPage ? [
                'id' => $resource->landingPage->id,
                'status' => $resource->landingPage->status,
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
}
