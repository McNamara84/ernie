<?php

use App\Models\Language;
use App\Models\License;
use App\Models\Resource;
use App\Models\ResourceTitle;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
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
            ->where('resources.0.created_at', $resource->created_at?->toIso8601String())
            ->where('resources.0.updated_at', $resource->updated_at?->toIso8601String())
            ->where('resources.1.id', $secondaryResource->id)
            ->where('resources.1.doi', null)
            ->where('resources.1.year', 2023)
            ->where('resources.1.resource_type.name', 'Text')
            ->where('resources.1.titles', fn ($titles) => count($titles) === 1)
            ->where('resources.1.licenses', fn ($licenses) => count($licenses) === 0)
            ->where('resources.1.language', null)
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
