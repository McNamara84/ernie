<?php

namespace App\Services;

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
use App\Models\TitleType;
use App\Services\Entities\AffiliationService;
use App\Services\Entities\InstitutionService;
use App\Services\Entities\PersonService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResourceStorageService
{
    public function __construct(
        protected PersonService $personService,
        protected InstitutionService $institutionService,
        protected AffiliationService $affiliationService,
    ) {}

    /**
     * Store or update a Resource and all related entities (creators, contributors, affiliations, etc.)
     *
     * @param  array<string, mixed>  $data  Validated request data
     * @param  int|null  $userId  ID of the user performing the operation
     * @return array{0: Resource, 1: bool} Returns [$resource, $isUpdate]
     *
     * @throws ValidationException
     */
    public function store(array $data, ?int $userId = null): array
    {
        return DB::transaction(function () use ($data, $userId): array {
            $languageId = null;

            if (! empty($data['language'])) {
                $languageId = Language::query()
                    ->where('code', $data['language'])
                    ->value('id');
            }

            $attributes = [
                'doi' => $data['doi'] ?? null,
                'publication_year' => $data['year'],
                'resource_type_id' => $data['resourceType'],
                'version' => $data['version'] ?? null,
                'language_id' => $languageId,
            ];

            $isUpdate = ! empty($data['resourceId']);

            if ($isUpdate) {
                /** @var Resource $resource */
                $resource = Resource::query()
                    ->lockForUpdate()
                    ->findOrFail($data['resourceId']);

                // Track who updated the resource
                $attributes['updated_by_user_id'] = $userId;

                $resource->update($attributes);
            } else {
                // Track who created the resource
                $attributes['created_by_user_id'] = $userId;

                $resource = Resource::query()->create($attributes);
            }

            $this->storeTitles($resource, $data, $isUpdate);
            $this->syncLicenses($resource, $data);
            $this->storeCreators($resource, $data, $isUpdate);
            $this->storeContributors($resource, $data, $isUpdate);
            $this->storeMslLaboratories($resource, $data, $isUpdate);
            $this->storeDescriptions($resource, $data, $isUpdate);
            $this->storeDates($resource, $data, $isUpdate);
            $this->storeSubjects($resource, $data, $isUpdate);
            $this->storeGeoLocations($resource, $data, $isUpdate);
            $this->storeRelatedIdentifiers($resource, $data, $isUpdate);
            $this->storeFundingReferences($resource, $data, $isUpdate);

            return [
                $resource->load([
                    'titles',
                    'rights',
                    'creators',
                    'contributors',
                    'descriptions',
                    'dates',
                    'subjects',
                    'geoLocations',
                    'relatedIdentifiers',
                    'fundingReferences',
                ]),
                $isUpdate,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeTitles(Resource $resource, array $data, bool $isUpdate): void
    {
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

        foreach ($data['titles'] as $titleData) {
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

        foreach ($data['titles'] as $index => $title) {
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
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncLicenses(Resource $resource, array $data): void
    {
        // Sync rights (pivot table) based on the validated license identifiers.
        $licenseIdentifiers = $data['licenses'] ?? [];

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
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeCreators(Resource $resource, array $data, bool $isUpdate): void
    {
        $resource->creators()->delete();

        $authors = $data['authors'] ?? [];

        foreach ($authors as $author) {
            $position = isset($author['position']) && is_int($author['position'])
                ? $author['position']
                : 0;

            if (($author['type'] ?? 'person') === 'institution') {
                $resourceCreator = $this->storeInstitutionCreator($resource, $author, $position);
            } else {
                $resourceCreator = $this->storePersonCreator($resource, $author, $position);
            }

            $this->affiliationService->syncForCreator($resourceCreator, $author);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storePersonCreator(Resource $resource, array $data, int $position): ResourceCreator
    {
        $person = $this->personService->findOrCreate($data);

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
        $institution = $this->institutionService->findOrCreate([
            'name' => $data['institutionName'],
            'identifier' => $data['rorId'] ?? null,
            'identifierScheme' => isset($data['rorId']) ? 'ROR' : null,
        ]);

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
    private function storeContributors(Resource $resource, array $data, bool $isUpdate): void
    {
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

        $contributors = $data['contributors'] ?? [];

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
            $this->affiliationService->syncForContributor($resourceContributor, $contributor);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storePersonContributor(Resource $resource, array $data, int $position): ResourceContributor
    {
        $person = $this->personService->findOrCreate($data);

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
        $institution = $this->institutionService->findOrCreateWithIdentifier(
            $data['institutionName'],
            $data['identifier'] ?? null,
            $data['identifierType'] ?? null
        );

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
        $firstRole = array_first($roles);
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
    private function storeMslLaboratories(Resource $resource, array $data, bool $isUpdate): void
    {
        // Save MSL Laboratories as contributors with HostingInstitution type
        $mslLaboratories = $data['mslLaboratories'] ?? [];

        foreach ($mslLaboratories as $lab) {
            $position = (int) ($lab['position'] ?? 0);

            $resourceContributor = $this->storeMslLaboratory($resource, $lab, $position);
            $this->syncMslLaboratoryAffiliation($resourceContributor, $lab);
        }
    }

    /**
     * Store an MSL Laboratory as Institution contributor.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeMslLaboratory(Resource $resource, array $data, int $position): ResourceContributor
    {
        $institution = $this->institutionService->findOrCreateMslLaboratory($data);

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

        if (empty(trim($affiliationName))) {
            return;
        }

        $resourceContributor->affiliations()->create([
            'name' => trim($affiliationName),
            'identifier' => $affiliationRor,
            'identifier_scheme' => $affiliationRor ? 'ROR' : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeDescriptions(Resource $resource, array $data, bool $isUpdate): void
    {
        if ($isUpdate) {
            $resource->descriptions()->delete();
        }

        // Pre-fetch description type IDs
        /** @var array<string, int> $descriptionTypeLookup */
        $descriptionTypeLookup = DescriptionType::query()
            ->get(['id', 'slug'])
            ->mapWithKeys(fn (DescriptionType $type): array => [
                // Use lowercase slug as key for case-insensitive matching
                Str::lower($type->slug) => $type->id,
            ])
            ->all();

        $descriptions = $data['descriptions'] ?? [];

        foreach ($descriptions as $description) {
            $descTypeKey = Str::lower((string) ($description['descriptionType'] ?? ''));
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
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeDates(Resource $resource, array $data, bool $isUpdate): void
    {
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

        $dates = $data['dates'] ?? [];

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
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeSubjects(Resource $resource, array $data, bool $isUpdate): void
    {
        // Save subjects (free keywords and controlled keywords combined)
        if ($isUpdate) {
            $resource->subjects()->delete();
        }

        $freeKeywords = $data['freeKeywords'] ?? [];

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

        $controlledKeywords = $data['gcmdKeywords'] ?? [];

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
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeGeoLocations(Resource $resource, array $data, bool $isUpdate): void
    {
        // Save geo locations (spatial and temporal coverages)
        if ($isUpdate) {
            $resource->geoLocations()->delete();
        }

        $coverages = $data['spatialTemporalCoverages'] ?? [];

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
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeRelatedIdentifiers(Resource $resource, array $data, bool $isUpdate): void
    {
        // Save related identifiers
        if ($isUpdate) {
            $resource->relatedIdentifiers()->delete();
        }

        // Pre-fetch related identifier type and relation type IDs
        /** @var array<string, int> $relatedIdTypeLookup */
        $relatedIdTypeLookup = IdentifierType::pluck('id', 'slug')->all();
        /** @var array<string, int> $relationTypeLookup */
        $relationTypeLookup = RelationType::pluck('id', 'slug')->all();

        $relatedIdentifiers = $data['relatedIdentifiers'] ?? [];

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
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeFundingReferences(Resource $resource, array $data, bool $isUpdate): void
    {
        // Save funding references
        if ($isUpdate) {
            $resource->fundingReferences()->delete();
        }

        $fundingReferences = $data['fundingReferences'] ?? [];

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
    }

    /**
     * Get or create funder identifier type by name.
     */
    private function getFunderIdentifierTypeId(string $typeName): ?int
    {
        $type = IdentifierType::query()
            ->where('name', $typeName)
            ->orWhere('slug', Str::slug($typeName))
            ->first();

        return $type?->id;
    }
}
