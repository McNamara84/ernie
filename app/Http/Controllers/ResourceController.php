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
                'authors' => function ($query): void {
                    $query
                        ->with([
                            'authorable',
                            'roles:id,name,slug',
                            'affiliations:id,resource_author_id,value,ror_id',
                        ]);
                },
                'contributors' => function ($query): void {
                    $query
                        ->with([
                            'authorable',
                            'roles:id,name,slug',
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

                return [$resource->load(['titles', 'licenses', 'authors']), $isUpdate];
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
