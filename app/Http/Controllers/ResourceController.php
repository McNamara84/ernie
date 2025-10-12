<?php

namespace App\Http\Controllers;

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
use App\Support\BooleanNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ResourceController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MIN_PER_PAGE = 5;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));

        $resources = Resource::query()
            ->with([
                'resourceType:id,name,slug',
                'language:id,code,name',
                'titles' => function ($query): void {
                    $query->select(['id', 'resource_id', 'title', 'title_type_id'])
                        ->with(['titleType:id,name,slug']);
                },
                'licenses:id,identifier,name',
                'descriptions:id,resource_id,description_type,description',
                'dates:id,resource_id,date_type,start_date,end_date,date_information',
                'keywords:id,resource_id,keyword',
                'controlledKeywords:id,resource_id,keyword_id,text,path,language,scheme,scheme_uri,vocabulary_type',
                'coverages',
                'relatedIdentifiers:id,resource_id,identifier,identifier_type,relation_type,position',
                'fundingReferences:id,resource_id,funder_name,funder_identifier,funder_identifier_type,award_number,award_uri,award_title,position',
                'authors' => function ($query): void {
                    $query
                        ->with([
                            'authorable',
                            'roles:id,name,slug,applies_to',
                            'affiliations:id,resource_author_id,value,ror_id',
                        ]);
                },
                'contributors' => function ($query): void {
                    $query
                        ->with([
                            'authorable',
                            'roles:id,name,slug,applies_to',
                            'affiliations:id,resource_author_id,value,ror_id',
                        ]);
                },
            ])
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(static function (Resource $resource): array {
                return [
                    'id' => $resource->id,
                    'doi' => $resource->doi,
                    'year' => $resource->year,
                    'version' => $resource->version,
                    'created_at' => $resource->created_at?->toIso8601String(),
                    'updated_at' => $resource->updated_at?->toIso8601String(),
                    'resource_type' => $resource->resourceType ? [
                        'name' => $resource->resourceType->name,
                        'slug' => $resource->resourceType->slug,
                    ] : null,
                    'language' => $resource->language ? [
                        'code' => $resource->language->code,
                        'name' => $resource->language->name,
                    ] : null,
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
                    'authors' => $resource->authors
                        ->filter(static function (ResourceAuthor $resourceAuthor): bool {
                            // Filter: Only ResourceAuthors with "Author" role
                            return $resourceAuthor->roles->contains(static fn (Role $role): bool => $role->applies_to === Role::APPLIES_TO_AUTHOR);
                        })
                        ->map(static function (ResourceAuthor $resourceAuthor): ?array {
                            $affiliations = $resourceAuthor->affiliations
                                ->map(static fn (\App\Models\Affiliation $affiliation): array => [
                                    'value' => $affiliation->value,
                                    'rorId' => $affiliation->ror_id,
                                ])
                                ->values()
                                ->all();

                            $base = [
                                'position' => $resourceAuthor->position,
                                'affiliations' => $affiliations,
                            ];

                            $authorable = $resourceAuthor->authorable;

                            if ($authorable instanceof Person) {
                                $isContact = $resourceAuthor->roles->contains(
                                    static fn (Role $role): bool => $role->slug === 'contact-person',
                                );

                                return $base + [
                                    'type' => 'person',
                                    'orcid' => $authorable->orcid,
                                    'firstName' => $authorable->first_name,
                                    'lastName' => $authorable->last_name,
                                    'email' => $resourceAuthor->email,
                                    'website' => $resourceAuthor->website,
                                    'isContact' => $isContact,
                                ];
                            }

                            if ($authorable instanceof Institution) {
                                return $base + [
                                    'type' => 'institution',
                                    'institutionName' => $authorable->name,
                                    'rorId' => $authorable->ror_id,
                                ];
                            }

                            return null;
                        })
                        ->filter()
                        ->values()
                        ->all(),
                    'contributors' => $resource->contributors
                        ->filter(static function (ResourceAuthor $resourceContributor): bool {
                            // Filter: Only ResourceAuthors with Contributor roles
                            return $resourceContributor->roles->contains(static fn (Role $role): bool => 
                                in_array($role->applies_to, [
                                    Role::APPLIES_TO_CONTRIBUTOR_PERSON,
                                    Role::APPLIES_TO_CONTRIBUTOR_INSTITUTION,
                                    Role::APPLIES_TO_CONTRIBUTOR_PERSON_AND_INSTITUTION,
                                ], true)
                            );
                        })
                        ->map(static function (ResourceAuthor $resourceContributor): ?array {
                            $affiliations = $resourceContributor->affiliations
                                ->map(static fn (\App\Models\Affiliation $affiliation): array => [
                                    'value' => $affiliation->value,
                                    'rorId' => $affiliation->ror_id,
                                ])
                                ->values()
                                ->all();

                            $roles = $resourceContributor->roles
                                ->map(static fn (Role $role): string => $role->name)
                                ->values()
                                ->all();

                            $base = [
                                'position' => $resourceContributor->position,
                                'affiliations' => $affiliations,
                                'roles' => $roles,
                            ];

                            $contributorAble = $resourceContributor->authorable;

                            if ($contributorAble instanceof Person) {
                                return $base + [
                                    'type' => 'person',
                                    'orcid' => $contributorAble->orcid,
                                    'firstName' => $contributorAble->first_name,
                                    'lastName' => $contributorAble->last_name,
                                ];
                            }

                            if ($contributorAble instanceof Institution) {
                                return $base + [
                                    'type' => 'institution',
                                    'institutionName' => $contributorAble->name,
                                ];
                            }

                            return null;
                        })
                        ->filter()
                        ->values()
                        ->all(),
                    'descriptions' => $resource->descriptions
                        ->map(static function (\App\Models\ResourceDescription $description): array {
                            return [
                                'descriptionType' => $description->description_type,
                                'description' => $description->description,
                            ];
                        })
                        ->values()
                        ->all(),
                    'dates' => $resource->dates
                        ->map(static function (\App\Models\ResourceDate $date): array {
                            return [
                                'dateType' => $date->date_type,
                                'startDate' => $date->start_date?->toDateString(),
                                'endDate' => $date->end_date?->toDateString(),
                                'dateInformation' => $date->date_information,
                            ];
                        })
                        ->values()
                        ->all(),
                    'freeKeywords' => $resource->keywords
                        ->map(static function (\App\Models\ResourceKeyword $keyword): string {
                            return $keyword->keyword;
                        })
                        ->values()
                        ->all(),
                    'controlledKeywords' => $resource->controlledKeywords
                        ->map(static function (\App\Models\ResourceControlledKeyword $keyword): array {
                            return [
                                'id' => $keyword->keyword_id,
                                'text' => $keyword->text,
                                'path' => $keyword->path,
                                'language' => $keyword->language,
                                'scheme' => $keyword->scheme,
                                'schemeURI' => $keyword->scheme_uri,
                                'vocabularyType' => $keyword->vocabulary_type,
                            ];
                        })
                        ->values()
                        ->all(),
                    'spatialTemporalCoverages' => $resource->coverages
                        ->map(static function (\App\Models\ResourceCoverage $coverage): array {
                            return [
                                'latMin' => $coverage->lat_min !== null ? (string) $coverage->lat_min : '',
                                'latMax' => $coverage->lat_max !== null ? (string) $coverage->lat_max : '',
                                'lonMin' => $coverage->lon_min !== null ? (string) $coverage->lon_min : '',
                                'lonMax' => $coverage->lon_max !== null ? (string) $coverage->lon_max : '',
                                'startDate' => $coverage->start_date?->toDateString() ?? '',
                                'endDate' => $coverage->end_date?->toDateString() ?? '',
                                'startTime' => $coverage->start_time ?? '',
                                'endTime' => $coverage->end_time ?? '',
                                'timezone' => $coverage->timezone ?? 'UTC',
                                'description' => $coverage->description ?? '',
                            ];
                        })
                        ->values()
                        ->all(),
                    'relatedIdentifiers' => $resource->relatedIdentifiers
                        ->sortBy('position')
                        ->map(static function (\App\Models\RelatedIdentifier $relatedIdentifier): array {
                            return [
                                'identifier' => $relatedIdentifier->identifier,
                                'identifierType' => $relatedIdentifier->identifier_type,
                                'relationType' => $relatedIdentifier->relation_type,
                                'position' => $relatedIdentifier->position,
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            });

        return Inertia::render('resources', [
            'resources' => $resources->items(),
            'pagination' => [
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
                'per_page' => $resources->perPage(),
                'total' => $resources->total(),
                'from' => $resources->firstItem(),
                'to' => $resources->lastItem(),
                'has_more' => $resources->hasMorePages(),
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
                    /** @var Resource $resource */
                    $resource = Resource::query()
                        ->lockForUpdate()
                        ->findOrFail($validated['resourceId']);

                    $resource->update($attributes);
                } else {
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
                    if (!empty(trim($keyword))) {
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
                    // Validate required fields
                    if (!empty($keyword['id']) && !empty($keyword['text']) && !empty($keyword['vocabularyType'])) {
                        $controlledKeywordsData[] = [
                            'keyword_id' => $keyword['id'],
                            'text' => $keyword['text'],
                            'path' => $keyword['path'] ?? $keyword['text'],
                            'language' => $keyword['language'] ?? 'en',
                            'scheme' => $keyword['scheme'] ?? '',
                            'scheme_uri' => $keyword['schemeURI'] ?? '',
                            'vocabulary_type' => $keyword['vocabularyType'],
                        ];
                    }
                }

                // Bulk create controlled keywords using Eloquent (handles timestamps automatically)
                if (!empty($controlledKeywordsData)) {
                    $resource->controlledKeywords()->createMany($controlledKeywordsData);
                }

                // Save spatial and temporal coverages
                if ($isUpdate) {
                    $resource->coverages()->delete();
                }

                $coverages = $validated['spatialTemporalCoverages'] ?? [];

                foreach ($coverages as $coverage) {
                    // Only save coverage if it has at least one meaningful field
                    $hasData = !empty($coverage['latMin']) || !empty($coverage['lonMin']) ||
                               !empty($coverage['startDate']) || !empty($coverage['description']);

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
                    if (!empty(trim($relatedIdentifier['identifier']))) {
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
                    if (!empty(trim($fundingReference['funderName']))) {
                        $resource->fundingReferences()->create([
                            'funder_name' => trim($fundingReference['funderName']),
                            'funder_identifier' => !empty($fundingReference['funderIdentifier']) ? trim($fundingReference['funderIdentifier']) : null,
                            'funder_identifier_type' => !empty($fundingReference['funderIdentifierType']) ? trim($fundingReference['funderIdentifierType']) : null,
                            'award_number' => !empty($fundingReference['awardNumber']) ? trim($fundingReference['awardNumber']) : null,
                            'award_uri' => !empty($fundingReference['awardUri']) ? trim($fundingReference['awardUri']) : null,
                            'award_title' => !empty($fundingReference['awardTitle']) ? trim($fundingReference['awardTitle']) : null,
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
     * @param array<string, mixed> $data
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

        $person->fill([
            'first_name' => $data['firstName'] ?? $person->first_name,
            'last_name' => $data['lastName'] ?? $person->last_name,
        ]);

        if (! empty($data['orcid'])) {
            $person->orcid = $data['orcid'];
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
     * @param array<string, mixed> $data
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
            $institution = new Institution();
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
     * @param array<string, mixed> $data
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
     * @param array<string, mixed> $data
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
     * @param array<string, mixed> $data
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

        $person->fill([
            'first_name' => $data['firstName'] ?? $person->first_name,
            'last_name' => $data['lastName'] ?? $person->last_name,
        ]);

        if (! empty($data['orcid'])) {
            $person->orcid = $data['orcid'];
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
     * @param array<string, mixed> $data
     */
    private function storeInstitutionContributor(Resource $resource, array $data, int $position): ResourceAuthor
    {
        $name = $data['institutionName'];

        // Contributors don't have a direct rorId field, only in affiliations
        $institution = Institution::query()
            ->where('name', $name)
            ->whereNull('ror_id')
            ->first();

        if ($institution === null) {
            $institution = new Institution();
            $institution->name = $name;
            $institution->save();
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
     * @param array<string, mixed> $data
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
     * @param array<string, mixed> $data
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
}
