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
 * Regression test for non-deterministic `created_at` selection on list rows.
 *
 * The `dates` relation is unordered and the schema permits multiple `Created`
 * rows per resource. ResourceListItemResource must therefore deterministically
 * pick the earliest `Created` date (and the latest `Updated` date) so that
 * identical requests always return the same timestamps.
 */
it('selects the earliest Created date and latest Updated date deterministically', function (): void {
    $resourceType = ResourceType::factory()->create();
    $language = Language::factory()->create();
    $titleType = TitleType::factory()->create(['slug' => 'MainTitle']);
    $createdType = DateType::firstOrCreate(['slug' => 'Created'], ['name' => 'Created']);
    $updatedType = DateType::firstOrCreate(['slug' => 'Updated'], ['name' => 'Updated']);

    $resource = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
        'publication_year' => 2024,
    ]);
    $resource->titles()->create([
        'value' => 'Deterministic Date Test',
        'title_type_id' => $titleType->id,
    ]);

    // Required relations so the resource passes assertRelationsLoaded() guards.
    $person = Person::create(['family_name' => 'Test', 'given_name' => 'A.']);
    ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 0,
    ]);
    $right = Right::firstOrCreate(['identifier' => 'cc-by-4'], ['name' => 'CC-BY 4.0']);
    $resource->rights()->attach($right->id);
    $abstractType = DescriptionType::firstOrCreate(['slug' => 'Abstract'], ['name' => 'Abstract']);
    Description::create([
        'resource_id' => $resource->id,
        'value' => 'Abstract',
        'description_type_id' => $abstractType->id,
    ]);

    // Insert multiple Created/Updated dates in non-chronological insertion order
    // to simulate XML imports that carry several historical revisions.
    foreach (['2024-06-15', '2023-01-01', '2024-12-31'] as $value) {
        ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $createdType->id,
            'date_value' => $value,
        ]);
    }
    foreach (['2024-03-10', '2025-02-20', '2024-08-05'] as $value) {
        ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $updatedType->id,
            'date_value' => $value,
        ]);
    }

    get(route('resources'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('resources')
            ->has('resources', 1)
            ->where('resources.0.id', $resource->id)
            ->where('resources.0.created_at', '2023-01-01')
            ->where('resources.0.updated_at', '2025-02-20')
        );
});
