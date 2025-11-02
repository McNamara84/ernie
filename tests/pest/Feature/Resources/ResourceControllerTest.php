<?php

use App\Models\Affiliation;
use App\Models\Institution;
use App\Models\Language;
use App\Models\License;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceAuthor;
use App\Models\ResourceTitle;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        'applies_to' => Role::APPLIES_TO_AUTHOR,
    ]);

    $contactRole = Role::query()->create([
        'name' => 'Contact Person',
        'slug' => 'contact-person',
        'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
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
            ->where('resources.0.resourcetypegeneral', 'Dataset')
            ->where('resources.0.title', 'Exploring metadata interoperability')
            ->where('resources.0.first_author.familyName', 'Taylor')
            ->where('resources.0.first_author.givenName', 'Avery')
            ->where('resources.0.created_at', $resource->created_at?->toIso8601String())
            ->where('resources.0.updated_at', $resource->updated_at?->toIso8601String())
            ->where('resources.1.id', $secondaryResource->id)
            ->where('resources.1.doi', null)
            ->where('resources.1.year', 2023)
            ->where('resources.1.resourcetypegeneral', 'Text')
            ->where('resources.1.title', 'Second resource title')
            ->where('resources.1.created_at', $secondaryResource->created_at?->toIso8601String())
            ->where('resources.1.updated_at', $secondaryResource->updated_at?->toIso8601String())
            ->where('pagination', [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 50,
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

    for ($index = 0; $index < 150; $index++) {
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

    // Test that requesting 500 per page gets capped to MAX_PER_PAGE (100)
    $response = get(route('resources', ['per_page' => 500, 'page' => 1]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->where('pagination.per_page', 100)
            ->where('pagination.current_page', 1)
            ->where('pagination.total', 150)
            ->where('pagination.has_more', true)
        );

    // Debug: Check actual count
    $resourcesCount = count($response->viewData('page')['props']['resources']);
    expect($resourcesCount)->toBe(100, "Expected 100 resources but got {$resourcesCount}");

    // Test that negative page numbers are corrected to 1
    get(route('resources', ['per_page' => 50, 'page' => -3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 50)
            ->where('pagination.current_page', 1)
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
        'descriptions' => [
            [
                'descriptionType' => 'Abstract',
                'description' => 'This is an abstract for the updated resource.',
            ],
        ],
    ];

    postJson(route('editor.resources.store'), $payload)
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
        'descriptions' => [
            [
                'descriptionType' => 'Abstract',
                'description' => 'This resource demonstrates author relationships.',
            ],
        ],
    ];

    postJson(route('editor.resources.store'), $payload)
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
        'descriptions' => [
            [
                'descriptionType' => 'Abstract',
                'description' => 'Testing normalization of blank ROR IDs.',
            ],
        ],
    ];

    postJson(route('editor.resources.store'), $payload)
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
        'descriptions' => [
            [
                'descriptionType' => 'Abstract',
                'description' => 'Testing institution ROR ID reuse.',
            ],
        ],
    ];

    postJson(route('editor.resources.store'), $initialPayload)->assertStatus(201);

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
        'descriptions' => [
            [
                'descriptionType' => 'Abstract',
                'description' => 'Testing institution ROR ID reuse with updated ROR.',
            ],
        ],
    ];

    postJson(route('editor.resources.store'), $updatePayload)
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
        'descriptions' => [
            [
                'descriptionType' => 'Abstract',
                'description' => 'Testing non-contact author without email.',
            ],
        ],
    ];

    postJson(route('editor.resources.store'), $payload)->assertStatus(201);

    $resource = Resource::query()
        ->with(['authors.roles', 'authors.authorable'])
        ->firstOrFail();

    $author = $resource->authors->first();
    expect($author)->not->toBeNull();
    expect($author?->email)->toBeNull();
    expect($author?->roles->pluck('name')->all())->toEqual(['Author']);
});

it('stores descriptions and dates with a resource', function (): void {
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

    $payload = [
        'doi' => '10.1234/descriptions-dates',
        'year' => 2024,
        'resourceType' => $resourceType->id,
        'version' => null,
        'language' => null,
        'titles' => [
            ['title' => 'Test Descriptions and Dates', 'titleType' => 'main-title'],
        ],
        'licenses' => ['cc-by-4'],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => null,
                'firstName' => 'Test',
                'lastName' => 'Author',
                'email' => null,
                'website' => null,
                'isContact' => false,
                'affiliations' => [],
                'position' => 0,
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'Abstract',
                'description' => 'This is an abstract description.',
            ],
            [
                'descriptionType' => 'Methods',
                'description' => 'This describes the methods used.',
            ],
        ],
        'dates' => [
            [
                'dateType' => 'created',
                'startDate' => '2024-01-15',
                'endDate' => null,
                'dateInformation' => null,
            ],
            [
                'dateType' => 'collected',
                'startDate' => '2024-02-01',
                'endDate' => '2024-03-31',
                'dateInformation' => null,
            ],
        ],
    ];

    postJson(route('editor.resources.store'), $payload)
        ->assertStatus(201)
        ->assertJson([
            'message' => 'Successfully saved resource.',
        ]);

    $resource = Resource::query()
        ->with(['descriptions', 'dates'])
        ->firstWhere('doi', '10.1234/descriptions-dates');

    expect($resource)->not->toBeNull();
    expect($resource->descriptions)->toHaveCount(2);
    expect($resource->dates)->toHaveCount(2);

    // Check descriptions (stored in kebab-case)
    $abstract = $resource->descriptions->firstWhere('description_type', 'abstract');
    expect($abstract)->not->toBeNull();
    expect($abstract?->description)->toBe('This is an abstract description.');

    $methods = $resource->descriptions->firstWhere('description_type', 'methods');
    expect($methods)->not->toBeNull();
    expect($methods?->description)->toBe('This describes the methods used.');

    // Check dates
    $created = $resource->dates->firstWhere('date_type', 'created');
    expect($created)->not->toBeNull();
    expect($created?->start_date?->toDateString())->toBe('2024-01-15');
    expect($created?->end_date)->toBeNull();

    $collected = $resource->dates->firstWhere('date_type', 'collected');
    expect($collected)->not->toBeNull();
    expect($collected?->start_date?->toDateString())->toBe('2024-02-01');
    expect($collected?->end_date?->toDateString())->toBe('2024-03-31');
});

it('updates descriptions and dates when updating a resource', function (): void {
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
        'doi' => '10.1234/update-desc-dates',
        'year' => 2024,
        'resource_type_id' => $resourceType->id,
    ]);

    $resource->titles()->create([
        'title' => 'Original Title',
        'title_type_id' => $titleType->id,
    ]);

    $resource->licenses()->attach($license->id);

    $person = Person::query()->create([
        'first_name' => 'Original',
        'last_name' => 'Author',
    ]);

    $resourceAuthor = $resource->authors()->create([
        'authorable_type' => Person::class,
        'authorable_id' => $person->id,
        'position' => 0,
    ]);

    $authorRole = Role::query()->create([
        'name' => 'Author',
        'slug' => 'author',
        'applies_to' => Role::APPLIES_TO_AUTHOR,
    ]);

    $resourceAuthor->roles()->attach($authorRole->id);

    // Original descriptions and dates
    $resource->descriptions()->create([
        'description_type' => 'abstract',
        'description' => 'Original abstract.',
    ]);

    $resource->dates()->create([
        'date_type' => 'created',
        'start_date' => '2024-01-01',
    ]);

    $payload = [
        'resourceId' => $resource->id,
        'doi' => '10.1234/update-desc-dates',
        'year' => 2024,
        'resourceType' => $resourceType->id,
        'titles' => [
            ['title' => 'Updated Title', 'titleType' => 'main-title'],
        ],
        'licenses' => ['cc-by-4'],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => null,
                'firstName' => 'Original',
                'lastName' => 'Author',
                'email' => null,
                'website' => null,
                'isContact' => false,
                'affiliations' => [],
                'position' => 0,
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'Abstract',
                'description' => 'Updated abstract description.',
            ],
            [
                'descriptionType' => 'Methods',
                'description' => 'New methods description.',
            ],
        ],
        'dates' => [
            [
                'dateType' => 'created',
                'startDate' => '2024-02-15',
                'endDate' => null,
                'dateInformation' => null,
            ],
        ],
    ];

    postJson(route('editor.resources.store'), $payload)
        ->assertStatus(200)
        ->assertJson([
            'message' => 'Successfully updated resource.',
        ]);

    $resource->refresh();
    $resource->load(['descriptions', 'dates']);

    // Verify old descriptions and dates were deleted
    expect($resource->descriptions)->toHaveCount(2);
    expect($resource->dates)->toHaveCount(1);

    // Verify new descriptions
    $abstract = $resource->descriptions->firstWhere('description_type', 'abstract');
    expect($abstract?->description)->toBe('Updated abstract description.');

    $methods = $resource->descriptions->firstWhere('description_type', 'methods');
    expect($methods?->description)->toBe('New methods description.');

    // Verify new dates
    $created = $resource->dates->firstWhere('date_type', 'created');
    expect($created?->start_date?->toDateString())->toBe('2024-02-15');
});

// ============================================================================
// Sorting and Filtering Tests
// ============================================================================

it('sorts resources by id in ascending order', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $resource1 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
    ]);
    $resource1->titles()->create([
        'title' => 'Resource 1',
        'title_type_id' => $titleType->id,
    ]);

    $resource2 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2023,
    ]);
    $resource2->titles()->create([
        'title' => 'Resource 2',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources', ['sort_key' => 'id', 'sort_direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 2)
            ->where('resources.0.id', $resource1->id)
            ->where('resources.1.id', $resource2->id)
        );
});

it('sorts resources by id in descending order', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $resource1 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
    ]);
    $resource1->titles()->create([
        'title' => 'Resource 1',
        'title_type_id' => $titleType->id,
    ]);

    $resource2 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2023,
    ]);
    $resource2->titles()->create([
        'title' => 'Resource 2',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources', ['sort_key' => 'id', 'sort_direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 2)
            ->where('resources.0.id', $resource2->id)
            ->where('resources.1.id', $resource1->id)
        );
});

it('sorts resources by year', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $resource1 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2020,
    ]);
    $resource1->titles()->create([
        'title' => 'Old Resource',
        'title_type_id' => $titleType->id,
    ]);

    $resource2 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
    ]);
    $resource2->titles()->create([
        'title' => 'New Resource',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources', ['sort_key' => 'year', 'sort_direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 2)
            ->where('resources.0.year', 2024)
            ->where('resources.1.year', 2020)
        );
});

it('sorts resources by title', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $resource1 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
    ]);
    $resource1->titles()->create([
        'title' => 'Zebra Research',
        'title_type_id' => $titleType->id,
    ]);

    $resource2 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
    ]);
    $resource2->titles()->create([
        'title' => 'Alpha Study',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources', ['sort_key' => 'title', 'sort_direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 2)
            ->where('resources.0.title', 'Alpha Study')
            ->where('resources.1.title', 'Zebra Research')
        );
});

it('sorts resources by curator name', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $userAlice = User::factory()->create(['name' => 'Alice Smith']);
    $userBob = User::factory()->create(['name' => 'Bob Jones']);

    $resource1 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
        'created_by_user_id' => $userBob->id,
    ]);
    $resource1->titles()->create([
        'title' => 'Resource by Bob',
        'title_type_id' => $titleType->id,
    ]);

    $resource2 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
        'created_by_user_id' => $userAlice->id,
    ]);
    $resource2->titles()->create([
        'title' => 'Resource by Alice',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources', ['sort_key' => 'curator', 'sort_direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 2)
            ->where('resources.0.curator', 'Alice Smith')
            ->where('resources.1.curator', 'Bob Jones')
        );
});

it('filters resources by resource type', function (): void {
    $datasetType = ResourceType::factory()->create(['name' => 'Dataset', 'slug' => 'dataset']);
    $textType = ResourceType::factory()->create(['name' => 'Text', 'slug' => 'text']);
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $dataset = Resource::factory()->create([
        'resource_type_id' => $datasetType->id,
        'language_id' => $language->id,
        'year' => 2024,
    ]);
    $dataset->titles()->create([
        'title' => 'Dataset Resource',
        'title_type_id' => $titleType->id,
    ]);

    $text = Resource::factory()->create([
        'resource_type_id' => $textType->id,
        'language_id' => $language->id,
        'year' => 2024,
    ]);
    $text->titles()->create([
        'title' => 'Text Resource',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources', ['resource_type' => ['dataset']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 1)
            ->where('resources.0.resourcetypegeneral', 'Dataset')
        );
});

it('filters resources by curator', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $alice = User::factory()->create(['name' => 'Alice Smith']);
    $bob = User::factory()->create(['name' => 'Bob Jones']);

    $aliceResource = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
        'created_by_user_id' => $alice->id,
    ]);
    $aliceResource->titles()->create([
        'title' => 'Alice Resource',
        'title_type_id' => $titleType->id,
    ]);

    $bobResource = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
        'created_by_user_id' => $bob->id,
    ]);
    $bobResource->titles()->create([
        'title' => 'Bob Resource',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources', ['curator' => ['Alice Smith']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 1)
            ->where('resources.0.curator', 'Alice Smith')
        );
});

it('filters resources by year range', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $old = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2020,
    ]);
    $old->titles()->create([
        'title' => 'Old Resource',
        'title_type_id' => $titleType->id,
    ]);

    $recent = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
    ]);
    $recent->titles()->create([
        'title' => 'Recent Resource',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources', ['year_from' => 2023, 'year_to' => 2025]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 1)
            ->where('resources.0.year', 2024)
        );
});

it('filters resources by text search in title', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $metadata = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
    ]);
    $metadata->titles()->create([
        'title' => 'Exploring Metadata Standards',
        'title_type_id' => $titleType->id,
    ]);

    $data = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
    ]);
    $data->titles()->create([
        'title' => 'Data Analysis Methods',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources', ['search' => 'Metadata']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 1)
            ->where('resources.0.title', 'Exploring Metadata Standards')
        );
});

it('filters resources by text search in DOI', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $resource1 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
        'doi' => '10.1234/example-abc',
    ]);
    $resource1->titles()->create([
        'title' => 'Resource 1',
        'title_type_id' => $titleType->id,
    ]);

    $resource2 = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
        'doi' => '10.5678/example-xyz',
    ]);
    $resource2->titles()->create([
        'title' => 'Resource 2',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources', ['search' => 'xyz']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 1)
            ->where('resources.0.doi', '10.5678/example-xyz')
        );
});

it('combines multiple filters correctly', function (): void {
    $datasetType = ResourceType::factory()->create(['name' => 'Dataset', 'slug' => 'dataset']);
    $textType = ResourceType::factory()->create(['name' => 'Text', 'slug' => 'text']);
    $english = Language::factory()->create(['code' => 'en', 'name' => 'English']);
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);
    $user = User::factory()->create(['name' => 'Test Curator']);

    // This matches all criteria
    $match = Resource::factory()->create([
        'resource_type_id' => $datasetType->id,
        'language_id' => $english->id,
        'year' => 2024,
        'created_by_user_id' => $user->id,
    ]);
    $match->titles()->create([
        'title' => 'Matching Dataset',
        'title_type_id' => $titleType->id,
    ]);

    // Wrong type
    $wrongType = Resource::factory()->create([
        'resource_type_id' => $textType->id,
        'language_id' => $english->id,
        'year' => 2024,
        'created_by_user_id' => $user->id,
    ]);
    $wrongType->titles()->create([
        'title' => 'Text Resource',
        'title_type_id' => $titleType->id,
    ]);

    // Wrong year
    $wrongYear = Resource::factory()->create([
        'resource_type_id' => $datasetType->id,
        'language_id' => $english->id,
        'year' => 2020,
        'created_by_user_id' => $user->id,
    ]);
    $wrongYear->titles()->create([
        'title' => 'Old Dataset',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources', [
        'resource_type' => ['dataset'],
        'language' => ['en'],
        'year_from' => 2023,
        'curator' => ['Test Curator'],
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 1)
            ->where('resources.0.title', 'Matching Dataset')
        );
});

// ============================================================================
// API Endpoint Tests
// ============================================================================

it('provides filter options endpoint', function (): void {
    $dataset = ResourceType::factory()->create(['name' => 'Dataset', 'slug' => 'dataset']);
    $english = Language::factory()->create(['code' => 'en', 'name' => 'English']);
    $user = User::factory()->create(['name' => 'Alice Curator']);
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $resource = Resource::factory()->create([
        'resource_type_id' => $dataset->id,
        'language_id' => $english->id,
        'year' => 2024,
        'created_by_user_id' => $user->id,
    ]);
    $resource->titles()->create([
        'title' => 'Test Resource',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources.filter-options'))
        ->assertOk()
        ->assertJson([
            'resource_types' => [
                ['name' => 'Dataset', 'slug' => 'dataset'],
            ],
            'curators' => ['Alice Curator'],
            'statuses' => ['curation'],
        ])
        ->assertJsonStructure([
            'year_range' => ['min', 'max'],
        ]);
});

it('loads more resources with pagination', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    // Create 60 resources to test pagination (default per_page is 50)
    for ($i = 1; $i <= 60; $i++) {
        $resource = Resource::factory()->create([
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'year' => 2024,
        ]);
        $resource->titles()->create([
            'title' => "Resource {$i}",
            'title_type_id' => $titleType->id,
        ]);
    }

    // First page should have 50 resources
    get(route('resources', ['per_page' => 50]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 50)
            ->where('pagination.current_page', 1)
            ->where('pagination.total', 60)
            ->where('pagination.has_more', true)
        );

    // Load more (page 2) should have remaining 10
    get(route('resources.load-more', ['page' => 2, 'per_page' => 50]))
        ->assertOk()
        ->assertJsonStructure([
            'resources',
            'pagination' => ['current_page', 'total', 'has_more'],
        ])
        ->assertJsonPath('pagination.current_page', 2)
        ->assertJsonPath('pagination.has_more', false)
        ->assertJsonCount(10, 'resources');
});

// ============================================================================
// User Tracking Tests
// ============================================================================

it('tracks the creating user when storing a new resource', function (): void {
    $user = User::factory()->create(['name' => 'Creator User']);
    actingAs($user);

    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);
    $license = License::factory()->create();
    $authorRole = Role::factory()->create([
        'slug' => 'author',
        'applies_to' => Role::APPLIES_TO_AUTHOR,
    ]);

    $payload = [
        'resourceTypeGeneral' => $resourceType->slug,
        'language' => $language->code,
        'publicationYear' => 2024,
        'year' => 2024,
        'resourceType' => $resourceType->id, // Use ID not slug
        'titles' => [
            [
                'title' => 'New Resource',
                'titleType' => $titleType->slug,
            ],
        ],
        'descriptions' => [
            [
                'description' => 'This is an abstract description for testing user tracking.',
                'descriptionType' => 'abstract',
            ],
        ],
        'licenses' => [$license->identifier],
        'authors' => [
            [
                'position' => 0,
                'type' => 'person',
                'firstName' => 'John',
                'lastName' => 'Doe',
                'roles' => [$authorRole->slug],
            ],
        ],
    ];

    postJson(route('editor.resources.store'), $payload)
        ->assertCreated(); // Expecting 201 for resource creation

    $resource = Resource::query()->latest('id')->first();

    expect($resource->created_by_user_id)->toBe($user->id);
    expect($resource->updated_by_user_id)->toBeNull();
});

it('tracks the updating user when updating an existing resource', function (): void {
    $creator = User::factory()->create(['name' => 'Creator']);
    $updater = User::factory()->create(['name' => 'Updater']);

    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);
    $license = License::factory()->create();
    $authorRole = Role::factory()->create([
        'slug' => 'author',
        'applies_to' => Role::APPLIES_TO_AUTHOR,
    ]);

    // Create resource as creator
    actingAs($creator);

    $resource = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
        'created_by_user_id' => $creator->id,
    ]);
    $resource->titles()->create([
        'title' => 'Original Title',
        'title_type_id' => $titleType->id,
    ]);
    $resource->licenses()->attach($license->id);

    // Update as different user
    actingAs($updater);

    $payload = [
        'resourceId' => $resource->id,
        'resourceTypeGeneral' => $resourceType->slug,
        'language' => $language->code,
        'publicationYear' => 2024,
        'year' => 2024,
        'resourceType' => $resourceType->id, // Use ID not slug
        'titles' => [
            [
                'title' => 'Updated Title',
                'titleType' => $titleType->slug,
            ],
        ],
        'descriptions' => [
            [
                'description' => 'Updated abstract description.',
                'descriptionType' => 'abstract',
            ],
        ],
        'licenses' => [$license->identifier],
        'authors' => [
            [
                'position' => 0,
                'type' => 'person',
                'firstName' => 'Jane',
                'lastName' => 'Smith',
                'roles' => [$authorRole->slug],
            ],
        ],
    ];

    postJson(route('editor.resources.store'), $payload)
        ->assertOk();

    $resource->refresh();

    expect($resource->created_by_user_id)->toBe($creator->id);
    expect($resource->updated_by_user_id)->toBe($updater->id);
});

it('displays curator name in resources index', function (): void {
    $user = User::factory()->create(['name' => 'John Curator']);
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $resource = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
        'created_by_user_id' => $user->id,
    ]);
    $resource->titles()->create([
        'title' => 'Test Resource',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 1)
            ->where('resources.0.curator', 'John Curator')
        );
});

it('handles resources without curator gracefully', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'main-title']);

    $resource = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'year' => 2024,
        'created_by_user_id' => null,
    ]);
    $resource->titles()->create([
        'title' => 'Legacy Resource',
        'title_type_id' => $titleType->id,
    ]);

    get(route('resources'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('resources')
            ->has('resources', 1)
            ->where('resources.0.curator', null)
        );
});
