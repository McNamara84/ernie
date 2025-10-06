<?php

use App\Models\Affiliation;
use App\Models\Institution;
use App\Models\Language;
use App\Models\License;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceAuthor;
use App\Models\ResourceTitle;
use App\Models\Role;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();

    actingAs(User::factory()->create([
        'email_verified_at' => now(),
    ]));
});

it('renders the resources index with paginated data', function (): void {
    $resourceType = ResourceType::query()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $language = Language::query()->create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);

    $mainTitleType = TitleType::query()->create([
        'name' => 'Main Title',
        'slug' => 'main-title',
    ]);

    $alternateTitleType = TitleType::query()->create([
        'name' => 'Subtitle',
        'slug' => 'subtitle',
    ]);

    $license = License::query()->create([
        'identifier' => 'cc-by-4',
        'name' => 'Creative Commons Attribution 4.0',
    ]);

    $resource = Resource::query()->create([
        'doi' => '10.1234/example-one',
        'year' => 2024,
        'resource_type_id' => $resourceType->id,
        'version' => '1.2.0',
        'language_id' => $language->id,
    ]);

    Resource::query()->whereKey($resource->id)->update([
        'created_at' => Carbon::parse('2024-03-10 09:30:00'),
        'updated_at' => Carbon::parse('2024-03-12 15:00:00'),
    ]);

    $resource->refresh();

    $person = Person::query()->create([
        'orcid' => '0000-0001-2345-6789',
        'first_name' => 'Avery',
        'last_name' => 'Taylor',
    ]);

    $authorRole = Role::query()->create([
        'name' => 'Author',
        'slug' => 'author',
    ]);

    $contactRole = Role::query()->create([
        'name' => 'Contact Person',
        'slug' => 'contact-person',
    ]);

    $primaryAuthor = ResourceAuthor::query()->create([
        'resource_id' => $resource->id,
        'authorable_id' => $person->id,
        'authorable_type' => Person::class,
        'position' => 0,
        'email' => 'avery.taylor@example.org',
        'website' => 'https://avery.example.org',
    ]);

    $primaryAuthor->roles()->attach([$authorRole->id, $contactRole->id]);

    $primaryAuthor->affiliations()->create([
        'value' => 'Metadata Lab',
        'ror_id' => 'https://ror.org/05d7xk087',
    ]);

    $institution = Institution::query()->create([
        'name' => 'Example Research Institute',
        'ror_id' => 'https://ror.org/03yrm5c26',
    ]);

    $institutionAuthor = ResourceAuthor::query()->create([
        'resource_id' => $resource->id,
        'authorable_id' => $institution->id,
        'authorable_type' => Institution::class,
        'position' => 1,
        'email' => null,
        'website' => null,
    ]);

    $institutionAuthor->roles()->attach($authorRole->id);

    $institutionAuthor->affiliations()->create([
        'value' => 'Consortium for Research',
        'ror_id' => null,
    ]);

    ResourceTitle::query()->create([
        'resource_id' => $resource->id,
        'title' => 'Exploring metadata interoperability',
        'title_type_id' => $mainTitleType->id,
    ]);

    ResourceTitle::query()->create([
        'resource_id' => $resource->id,
        'title' => 'A practical subtitle',
        'title_type_id' => $alternateTitleType->id,
    ]);

    $resource->licenses()->attach($license->id);

    $secondaryResourceType = ResourceType::query()->create([
        'name' => 'Text',
        'slug' => 'text',
    ]);

    $secondaryResource = Resource::query()->create([
        'doi' => null,
        'year' => 2023,
        'resource_type_id' => $secondaryResourceType->id,
        'version' => null,
        'language_id' => null,
    ]);

    Resource::query()->whereKey($secondaryResource->id)->update([
        'created_at' => Carbon::parse('2023-12-24 11:00:00'),
        'updated_at' => Carbon::parse('2023-12-24 11:00:00'),
    ]);

    $secondaryResource->refresh();

    ResourceTitle::query()->create([
        'resource_id' => $secondaryResource->id,
        'title' => 'Second resource title',
        'title_type_id' => $mainTitleType->id,
    ]);

    get(route('resources'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 2)
            ->where('resources.0.id', $resource->id)
            ->where('resources.0.doi', $resource->doi)
            ->where('resources.0.year', 2024)
            ->where('resources.0.version', '1.2.0')
            ->where('resources.0.resource_type.name', 'Dataset')
            ->where('resources.0.language.code', 'en')
            ->where('resources.0.titles', fn ($titles) => count($titles) === 2)
            ->where('resources.0.licenses', fn ($licenses) => count($licenses) === 1)
            ->where('resources.0.authors', function ($authors) use ($person, $institution): bool {
                if ($authors instanceof Collection) {
                    $authors = $authors->toArray();
                }

                expect($authors)->toBeArray()->toHaveCount(2);

                expect($authors[0])->toEqual([
                    'type' => 'person',
                    'position' => 0,
                    'orcid' => $person->orcid,
                    'firstName' => $person->first_name,
                    'lastName' => $person->last_name,
                    'email' => 'avery.taylor@example.org',
                    'website' => 'https://avery.example.org',
                    'isContact' => true,
                    'affiliations' => [
                        [
                            'value' => 'Metadata Lab',
                            'rorId' => 'https://ror.org/05d7xk087',
                        ],
                    ],
                ]);

                expect($authors[1])->toEqual([
                    'type' => 'institution',
                    'position' => 1,
                    'institutionName' => $institution->name,
                    'rorId' => $institution->ror_id,
                    'affiliations' => [
                        [
                            'value' => 'Consortium for Research',
                            'rorId' => null,
                        ],
                    ],
                ]);

                return true;
            })
            ->where('resources.0.created_at', $resource->created_at?->toIso8601String())
            ->where('resources.0.updated_at', $resource->updated_at?->toIso8601String())
            ->where('resources.1.id', $secondaryResource->id)
            ->where('resources.1.doi', null)
            ->where('resources.1.year', 2023)
            ->where('resources.1.resource_type.name', 'Text')
            ->where('resources.1.titles', fn ($titles) => count($titles) === 1)
            ->where('resources.1.licenses', fn ($licenses) => count($licenses) === 0)
            ->where('resources.1.language', null)
            ->where('resources.1.authors', [])
            ->where('resources.1.created_at', $secondaryResource->created_at?->toIso8601String())
            ->where('resources.1.updated_at', $secondaryResource->updated_at?->toIso8601String())
            ->where('pagination', [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 25,
                'total' => 2,
                'from' => 1,
                'to' => 2,
                'has_more' => false,
            ])
        );
});

it('caps the per page parameter to protect performance', function (): void {
    $resourceType = ResourceType::query()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $mainTitleType = TitleType::query()->create([
        'name' => 'Main Title',
        'slug' => 'main-title',
    ]);

    $startDate = Carbon::parse('2024-01-01 00:00:00');

    for ($index = 0; $index < 105; $index++) {
        $resource = Resource::query()->create([
            'doi' => sprintf('10.5555/example-%03d', $index),
            'year' => 2020 + ($index % 5),
            'resource_type_id' => $resourceType->id,
            'version' => null,
            'language_id' => null,
        ]);

        Resource::query()->whereKey($resource->id)->update([
            'created_at' => $startDate->copy()->addMinutes($index),
            'updated_at' => $startDate->copy()->addMinutes($index),
        ]);

        ResourceTitle::query()->create([
            'resource_id' => $resource->id,
            'title' => sprintf('Example resource %d', $index + 1),
            'title_type_id' => $mainTitleType->id,
        ]);
    }

    get(route('resources', ['per_page' => 500, 'page' => -3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 100)
            ->where('pagination.per_page', 100)
            ->where('pagination.current_page', 1)
            ->where('pagination.has_more', true)
        );
});

it('updates an existing resource when the request includes a resource identifier', function (): void {
    $resourceType = ResourceType::query()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $language = Language::query()->create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);

    $mainTitleType = TitleType::query()->create([
        'name' => 'Main Title',
        'slug' => 'main-title',
    ]);

    $subtitleType = TitleType::query()->create([
        'name' => 'Subtitle',
        'slug' => 'subtitle',
    ]);

    $originalLicense = License::query()->create([
        'identifier' => 'cc-by-3',
        'name' => 'Creative Commons Attribution 3.0',
    ]);

    $updatedLicense = License::query()->create([
        'identifier' => 'cc-by-4',
        'name' => 'Creative Commons Attribution 4.0',
    ]);

    $resource = Resource::query()->create([
        'doi' => '10.1234/original',
        'year' => 2020,
        'resource_type_id' => $resourceType->id,
        'version' => '1.0',
        'language_id' => $language->id,
    ]);

    ResourceTitle::query()->create([
        'resource_id' => $resource->id,
        'title' => 'Original main title',
        'title_type_id' => $mainTitleType->id,
    ]);

    ResourceTitle::query()->create([
        'resource_id' => $resource->id,
        'title' => 'Original subtitle',
        'title_type_id' => $subtitleType->id,
    ]);

    $resource->licenses()->attach($originalLicense->id);

    $existingPerson = Person::query()->create([
        'first_name' => 'Original',
        'last_name' => 'Author',
    ]);

    $existingAuthor = ResourceAuthor::query()->create([
        'resource_id' => $resource->id,
        'authorable_id' => $existingPerson->id,
        'authorable_type' => Person::class,
        'position' => 0,
        'email' => null,
        'website' => null,
    ]);

    $payload = [
        'resourceId' => $resource->id,
        'doi' => '10.1234/updated',
        'year' => 2025,
        'resourceType' => $resourceType->id,
        'version' => '2.0',
        'language' => 'en',
        'titles' => [
            ['title' => 'Updated main title', 'titleType' => 'main-title'],
            ['title' => 'Updated subtitle', 'titleType' => 'subtitle'],
        ],
        'licenses' => ['cc-by-4'],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => null,
                'firstName' => 'Grace',
                'lastName' => 'Hopper',
                'email' => 'grace@example.org',
                'website' => null,
                'isContact' => true,
                'affiliations' => [
                    ['value' => 'US Navy', 'rorId' => null],
                ],
                'position' => 0,
            ],
            [
                'type' => 'institution',
                'institutionName' => 'Computing Society',
                'rorId' => null,
                'affiliations' => [],
                'position' => 1,
            ],
        ],
    ];

    postJson(route('curation.resources.store'), $payload)
        ->assertStatus(200)
        ->assertJson([
            'message' => 'Successfully updated resource.',
            'resource' => [
                'id' => $resource->id,
            ],
        ]);

    expect(Resource::query()->count())->toBe(1);

    $resource->refresh();

    expect($resource->doi)->toBe('10.1234/updated');
    expect($resource->year)->toBe(2025);
    expect($resource->version)->toBe('2.0');
    expect($resource->language_id)->toBe($language->id);

    $resource->load(['titles.titleType', 'licenses']);

    expect(ResourceAuthor::query()->whereKey($existingAuthor->id)->exists())->toBeFalse();
    $resource->load(['authors.roles', 'authors.affiliations', 'authors.authorable']);
    expect($resource->authors)->toHaveCount(2);
    $updatedPersonAuthor = $resource->authors->firstWhere('authorable_type', Person::class);
    expect($updatedPersonAuthor?->authorable)->toBeInstanceOf(Person::class);
    expect($updatedPersonAuthor?->authorable?->first_name)->toBe('Grace');
    expect($updatedPersonAuthor?->roles->pluck('name')->all())->toContain('Author');
    expect($updatedPersonAuthor?->roles->pluck('name')->all())->toContain('Contact Person');
    expect($updatedPersonAuthor?->affiliations->pluck('value')->all())->toBe(['US Navy']);
    $updatedInstitution = $resource->authors->firstWhere('authorable_type', Institution::class);
    expect($updatedInstitution?->authorable)->toBeInstanceOf(Institution::class);
    expect($updatedInstitution?->authorable?->name)->toBe('Computing Society');
    expect($updatedInstitution?->roles->pluck('name')->all())->toEqual(['Author']);

    expect($resource->titles)->toHaveCount(2);
    expect($resource->titles->pluck('title')->all())->toBe([
        'Updated main title',
        'Updated subtitle',
    ]);
    expect($resource->titles->pluck('titleType.slug')->all())->toBe([
        'main-title',
        'subtitle',
    ]);

    expect($resource->licenses)->toHaveCount(1);
    expect($resource->licenses->first()?->identifier)->toBe('cc-by-4');
});

it('stores authors with roles and affiliations when creating a resource', function (): void {
    $resourceType = ResourceType::query()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $language = Language::query()->create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);

    $mainTitleType = TitleType::query()->create([
        'name' => 'Main Title',
        'slug' => 'main-title',
    ]);

    $license = License::query()->create([
        'identifier' => 'cc-by-4',
        'name' => 'Creative Commons Attribution 4.0',
    ]);

    $payload = [
        'doi' => '10.1234/with-authors',
        'year' => 2024,
        'resourceType' => $resourceType->id,
        'version' => '1.0.0',
        'language' => 'en',
        'titles' => [
            ['title' => 'Resource with authors', 'titleType' => 'main-title'],
        ],
        'licenses' => [$license->identifier],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => '0000-0001-2345-6789',
                'firstName' => 'Ada',
                'lastName' => 'Lovelace',
                'email' => 'ada@example.org',
                'website' => 'https://ada.example',
                'isContact' => true,
                'affiliations' => [
                    ['value' => 'Analytical Engine Society', 'rorId' => null],
                ],
                'position' => 0,
            ],
            [
                'type' => 'institution',
                'institutionName' => 'Royal Society',
                'rorId' => 'https://ror.org/01bj3aw27',
                'affiliations' => [
                    ['value' => 'London', 'rorId' => null],
                ],
                'position' => 1,
            ],
        ],
    ];

    postJson(route('curation.resources.store'), $payload)
        ->assertStatus(201)
        ->assertJson([
            'message' => 'Successfully saved resource.',
        ]);

    $resource = Resource::query()
        ->with([
            'authors.roles',
            'authors.affiliations',
            'authors.authorable',
        ])
        ->first();

    expect($resource)->not->toBeNull();
    expect($resource?->authors)->toHaveCount(2);

    $personAuthor = $resource?->authors->firstWhere('authorable_type', Person::class);
    expect($personAuthor)->not->toBeNull();
    expect($personAuthor?->authorable)->toBeInstanceOf(Person::class);
    expect($personAuthor?->authorable?->orcid)->toBe('0000-0001-2345-6789');
    expect($personAuthor?->authorable?->first_name)->toBe('Ada');
    expect($personAuthor?->authorable?->last_name)->toBe('Lovelace');
    expect($personAuthor?->roles->pluck('name')->all())->toContain('Author');
    expect($personAuthor?->roles->pluck('name')->all())->toContain('Contact Person');
    expect($personAuthor?->affiliations)->toHaveCount(1);
    expect($personAuthor?->affiliations->first()?->value)->toBe('Analytical Engine Society');

    $institutionAuthor = $resource?->authors->firstWhere('authorable_type', Institution::class);
    expect($institutionAuthor)->not->toBeNull();
    expect($institutionAuthor?->authorable)->toBeInstanceOf(Institution::class);
    expect($institutionAuthor?->authorable?->name)->toBe('Royal Society');
    expect($institutionAuthor?->authorable?->ror_id)->toBe('https://ror.org/01bj3aw27');
    expect($institutionAuthor?->roles->pluck('name')->all())->toEqual(['Author']);
    expect($institutionAuthor?->affiliations)->toHaveCount(1);
    expect($institutionAuthor?->affiliations->first()?->value)->toBe('London');

    expect(Role::query()->where('name', 'Author')->exists())->toBeTrue();
    expect(Role::query()->where('name', 'Contact Person')->exists())->toBeTrue();
    expect(Affiliation::query()->count())->toBe(2);
});

it('normalizes blank affiliation ror ids to null when storing resource authors', function (): void {
    $resourceType = ResourceType::query()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $language = Language::query()->create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);

    $titleType = TitleType::query()->create([
        'name' => 'Main Title',
        'slug' => 'main-title',
    ]);

    $license = License::query()->create([
        'identifier' => 'cc-by-4',
        'name' => 'Creative Commons Attribution 4.0',
    ]);

    $payload = [
        'doi' => '10.1234/affiliations-normalized',
        'year' => 2024,
        'resourceType' => $resourceType->id,
        'version' => '1.0.0',
        'language' => $language->code,
        'titles' => [
            ['title' => 'Resource with blank ROR', 'titleType' => $titleType->slug],
        ],
        'licenses' => [$license->identifier],
        'authors' => [
            [
                'type' => 'person',
                'firstName' => 'Test',
                'lastName' => 'Author',
                'affiliations' => [
                    ['value' => 'Example Org', 'rorId' => '   '],
                ],
                'position' => 0,
            ],
        ],
    ];

    postJson(route('curation.resources.store'), $payload)
        ->assertStatus(201);

    $resource = Resource::query()
        ->with(['authors.affiliations'])
        ->firstWhere('doi', '10.1234/affiliations-normalized');

    expect($resource)->not->toBeNull();

    $author = $resource?->authors->first();
    expect($author)->not->toBeNull();

    $affiliation = $author?->affiliations->first();
    expect($affiliation)->not->toBeNull();
    expect($affiliation?->ror_id)->toBeNull();
});

it('deletes a resource along with related metadata records', function (): void {
    $resourceType = ResourceType::query()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $titleType = TitleType::query()->create([
        'name' => 'Main Title',
        'slug' => 'main-title',
    ]);

    $license = License::query()->create([
        'identifier' => 'cc-by-4',
        'name' => 'Creative Commons Attribution 4.0',
    ]);

    $resource = Resource::query()->create([
        'doi' => '10.1234/delete-me',
        'year' => 2024,
        'resource_type_id' => $resourceType->id,
        'version' => '1.0.0',
        'language_id' => null,
    ]);

    ResourceTitle::query()->create([
        'resource_id' => $resource->id,
        'title' => 'Resource scheduled for deletion',
        'title_type_id' => $titleType->id,
    ]);

    $resource->licenses()->attach($license->id);

    delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'))
        ->assertSessionHas('success', 'Resource deleted successfully.');

    expect(Resource::query()->find($resource->id))->toBeNull();
    expect(ResourceTitle::query()->where('resource_id', $resource->id)->exists())->toBeFalse();
    expect(DB::table('license_resource')->count())->toBe(0);
    expect(License::query()->count())->toBe(1);
});

it('reuses existing institutions when a ROR identifier is added later', function (): void {
    $resourceType = ResourceType::query()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $language = Language::query()->create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);

    $mainTitleType = TitleType::query()->create([
        'name' => 'Main Title',
        'slug' => 'main-title',
    ]);

    $license = License::query()->create([
        'identifier' => 'cc-by-4',
        'name' => 'Creative Commons Attribution 4.0',
    ]);

    $initialPayload = [
        'doi' => '10.1234/institution-without-ror',
        'year' => 2024,
        'resourceType' => $resourceType->id,
        'version' => '1.0.0',
        'language' => 'en',
        'titles' => [
            ['title' => 'Institution without ROR', 'titleType' => 'main-title'],
        ],
        'licenses' => [$license->identifier],
        'authors' => [
            [
                'type' => 'institution',
                'institutionName' => 'Example Institution',
                'rorId' => null,
                'affiliations' => [],
                'position' => 0,
            ],
        ],
    ];

    postJson(route('curation.resources.store'), $initialPayload)->assertStatus(201);

    $resource = Resource::query()->firstOrFail();
    $institution = Institution::query()->firstOrFail();

    expect($institution->ror_id)->toBeNull();

    $updatePayload = [
        'resourceId' => $resource->id,
        'doi' => '10.1234/institution-with-ror',
        'year' => 2024,
        'resourceType' => $resourceType->id,
        'version' => '1.0.1',
        'language' => 'en',
        'titles' => [
            ['title' => 'Institution with ROR', 'titleType' => 'main-title'],
        ],
        'licenses' => [$license->identifier],
        'authors' => [
            [
                'type' => 'institution',
                'institutionName' => 'Example Institution',
                'rorId' => 'https://ror.org/123456789',
                'affiliations' => [],
                'position' => 0,
            ],
        ],
    ];

    postJson(route('curation.resources.store'), $updatePayload)
        ->assertStatus(200)
        ->assertJsonPath('resource.id', $resource->id);

    expect(Institution::query()->count())->toBe(1);
    $institution->refresh();
    expect($institution->name)->toBe('Example Institution');
    expect($institution->ror_id)->toBe('https://ror.org/123456789');
});

it('does not require a contact email when isContact is explicitly false', function (): void {
    $resourceType = ResourceType::query()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $language = Language::query()->create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);

    $mainTitleType = TitleType::query()->create([
        'name' => 'Main Title',
        'slug' => 'main-title',
    ]);

    $license = License::query()->create([
        'identifier' => 'cc-by-4',
        'name' => 'Creative Commons Attribution 4.0',
    ]);

    $payload = [
        'doi' => '10.1234/non-contact-author',
        'year' => 2024,
        'resourceType' => $resourceType->id,
        'version' => '1.0.0',
        'language' => 'en',
        'titles' => [
            ['title' => 'Dataset without contact email', 'titleType' => 'main-title'],
        ],
        'licenses' => [$license->identifier],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => null,
                'firstName' => 'Jordan',
                'lastName' => 'Smith',
                'email' => '',
                'website' => null,
                'isContact' => 'false',
                'affiliations' => [],
                'position' => 0,
            ],
        ],
    ];

    postJson(route('curation.resources.store'), $payload)->assertStatus(201);

    $resource = Resource::query()
        ->with(['authors.roles', 'authors.authorable'])
        ->firstOrFail();

    $author = $resource->authors->first();
    expect($author)->not->toBeNull();
    expect($author?->email)->toBeNull();
    expect($author?->roles->pluck('name')->all())->toEqual(['Author']);
});
