<?php

use App\Models\DateType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Language;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();

    actingAs(User::factory()->create([
        'email_verified_at' => now(),
    ]));
});

/**
 * Helper: create a fully complete resource with a single MainTitle.
 */
function makeListedResource(string $title, ?string $createdAt = null, ?string $updatedAt = null): Resource
{
    $resourceType = ResourceType::firstOrCreate(
        ['slug' => 'dataset'],
        ['name' => 'Dataset'],
    );
    $language = Language::firstOrCreate(
        ['code' => 'en'],
        ['name' => 'English'],
    );
    $titleType = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title'],
    );
    $right = Right::firstOrCreate(
        ['identifier' => 'cc-by-4'],
        ['name' => 'CC-BY 4.0'],
    );
    $abstractType = DescriptionType::firstOrCreate(
        ['slug' => 'Abstract'],
        ['name' => 'Abstract'],
    );

    $attributes = [
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'publication_year' => 2024,
    ];
    if ($createdAt !== null) {
        $attributes['created_at'] = $createdAt;
    }
    if ($updatedAt !== null) {
        $attributes['updated_at'] = $updatedAt;
    }

    $resource = Resource::factory()->create($attributes);
    $resource->titles()->create([
        'value' => $title,
        'title_type_id' => $titleType->id,
    ]);

    $person = Person::create(['family_name' => 'A', 'given_name' => 'B']);
    ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 0,
    ]);
    $resource->rights()->attach($right->id);
    Description::create([
        'resource_id' => $resource->id,
        'value' => 'Abstract',
        'description_type_id' => $abstractType->id,
    ]);

    return $resource;
}

/**
 * Regression test: sorting by created_at / updated_at must follow the same
 * date selection logic used by ResourceListItemResource (DataCite Created /
 * Updated dates first, falling back to Eloquent timestamp columns).
 *
 * Without this, list rows can appear visibly out of order when DataCite dates
 * differ from resources.created_at / updated_at.
 */
it('sorts by DataCite Created date asc, falling back to created_at column', function (): void {
    $createdType = DateType::firstOrCreate(['slug' => 'Created'], ['name' => 'Created']);

    // Resource A: DataCite Created 2020-01-01 but resources.created_at 2025-01-01.
    // Sorted ascending it must come first.
    $a = makeListedResource('A-old-datacite', createdAt: '2025-01-01 00:00:00');
    ResourceDate::create([
        'resource_id' => $a->id,
        'date_type_id' => $createdType->id,
        'date_value' => '2020-01-01',
    ]);

    // Resource B: no DataCite Created date, only resources.created_at = 2022-06-01.
    $b = makeListedResource('B-only-column', createdAt: '2022-06-01 00:00:00');

    // Resource C: DataCite Created 2024-12-31 but resources.created_at 2021-01-01.
    // Despite the older row timestamp, the DataCite date drives the order.
    $c = makeListedResource('C-new-datacite', createdAt: '2021-01-01 00:00:00');
    ResourceDate::create([
        'resource_id' => $c->id,
        'date_type_id' => $createdType->id,
        'date_value' => '2024-12-31',
    ]);

    get(route('resources', ['sort_key' => 'created_at', 'sort_direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('resources')
            ->has('resources', 3)
            ->where('resources.0.id', $a->id) // 2020-01-01 (DataCite)
            ->where('resources.1.id', $b->id) // 2022-06-01 (column fallback)
            ->where('resources.2.id', $c->id) // 2024-12-31 (DataCite)
        );
});

it('sorts by DataCite Updated date desc, falling back to updated_at column', function (): void {
    $updatedType = DateType::firstOrCreate(['slug' => 'Updated'], ['name' => 'Updated']);

    // Resource A: DataCite Updated 2026-01-01, column 2020-01-01. Newest → first.
    $a = makeListedResource('A-newest-datacite', updatedAt: '2020-01-01 00:00:00');
    ResourceDate::create([
        'resource_id' => $a->id,
        'date_type_id' => $updatedType->id,
        'date_value' => '2026-01-01',
    ]);

    // Resource B: no DataCite Updated, column 2024-06-01.
    $b = makeListedResource('B-only-column', updatedAt: '2024-06-01 00:00:00');

    // Resource C: DataCite Updated 2022-01-01, column 2025-12-31. DataCite drives.
    $c = makeListedResource('C-old-datacite', updatedAt: '2025-12-31 00:00:00');
    ResourceDate::create([
        'resource_id' => $c->id,
        'date_type_id' => $updatedType->id,
        'date_value' => '2022-01-01',
    ]);

    get(route('resources', ['sort_key' => 'updated_at', 'sort_direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('resources')
            ->has('resources', 3)
            ->where('resources.0.id', $a->id) // 2026-01-01
            ->where('resources.1.id', $b->id) // 2024-06-01
            ->where('resources.2.id', $c->id) // 2022-01-01
        );
});
