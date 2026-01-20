<?php

use App\Models\Affiliation;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('dashboard'))->assertOk();
});

test('dashboard view receives separate resource counts for data resources and IGSNs', function () {
    $this->actingAs(User::factory()->create());

    // Create Dataset type
    $datasetType = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    // Create PhysicalObject type for IGSNs
    $physicalObjectType = ResourceType::create([
        'name' => 'PhysicalObject',
        'slug' => 'physical-object',
    ]);

    // Create 2 Data Resources
    Resource::create([
        'year' => 2024,
        'resource_type_id' => $datasetType->id,
    ]);

    Resource::create([
        'year' => 2025,
        'resource_type_id' => $datasetType->id,
    ]);

    // Create 3 IGSNs
    Resource::create([
        'year' => 2024,
        'resource_type_id' => $physicalObjectType->id,
    ]);

    Resource::create([
        'year' => 2025,
        'resource_type_id' => $physicalObjectType->id,
    ]);

    Resource::create([
        'year' => 2025,
        'resource_type_id' => $physicalObjectType->id,
    ]);

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->where('dataResourceCount', 2)
            ->where('igsnCount', 3)
        );
});

test('dashboard counts institutions with ROR identifiers for data resources', function () {
    $this->actingAs(User::factory()->create());

    $datasetType = ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);

    // Create resource with creator having ROR affiliation
    $resource = Resource::create(['year' => 2024, 'resource_type_id' => $datasetType->id]);
    $person = Person::create(['given_name' => 'John', 'family_name' => 'Doe']);
    $creator = ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);
    Affiliation::create([
        'affiliatable_type' => ResourceCreator::class,
        'affiliatable_id' => $creator->id,
        'name' => 'GFZ German Research Centre for Geosciences',
        'identifier' => 'https://ror.org/04z8jg394',
        'identifier_scheme' => 'ROR',
    ]);

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->where('dataResourceCount', 1)
            ->where('dataInstitutionCount', 1)
        );
});

test('dashboard counts institutions with ROR identifiers for IGSNs', function () {
    $this->actingAs(User::factory()->create());

    $physicalObjectType = ResourceType::create(['name' => 'PhysicalObject', 'slug' => 'physical-object']);

    // Create IGSN resource with creator having ROR affiliation
    $resource = Resource::create(['year' => 2024, 'resource_type_id' => $physicalObjectType->id]);
    $person = Person::create(['given_name' => 'Jane', 'family_name' => 'Smith']);
    $creator = ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);
    Affiliation::create([
        'affiliatable_type' => ResourceCreator::class,
        'affiliatable_id' => $creator->id,
        'name' => 'Helmholtz Centre Potsdam',
        'identifier' => 'https://ror.org/04z8jg394',
        'identifier_scheme' => 'ROR',
    ]);

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->where('igsnCount', 1)
            ->where('igsnInstitutionCount', 1)
        );
});

test('dashboard only counts unique ROR identifiers per category', function () {
    $this->actingAs(User::factory()->create());

    $datasetType = ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);

    // Create two resources with creators from the same institution (same ROR)
    $person1 = Person::create(['given_name' => 'John', 'family_name' => 'Doe']);
    $person2 = Person::create(['given_name' => 'Jane', 'family_name' => 'Smith']);

    $resource1 = Resource::create(['year' => 2024, 'resource_type_id' => $datasetType->id]);
    $creator1 = ResourceCreator::create([
        'resource_id' => $resource1->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person1->id,
        'position' => 1,
    ]);
    Affiliation::create([
        'affiliatable_type' => ResourceCreator::class,
        'affiliatable_id' => $creator1->id,
        'name' => 'GFZ',
        'identifier' => 'https://ror.org/04z8jg394',
        'identifier_scheme' => 'ROR',
    ]);

    $resource2 = Resource::create(['year' => 2025, 'resource_type_id' => $datasetType->id]);
    $creator2 = ResourceCreator::create([
        'resource_id' => $resource2->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person2->id,
        'position' => 1,
    ]);
    Affiliation::create([
        'affiliatable_type' => ResourceCreator::class,
        'affiliatable_id' => $creator2->id,
        'name' => 'GFZ German Research Centre for Geosciences',
        'identifier' => 'https://ror.org/04z8jg394', // Same ROR as above
        'identifier_scheme' => 'ROR',
    ]);

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->where('dataResourceCount', 2)
            ->where('dataInstitutionCount', 1) // Only 1 unique institution despite 2 affiliations
        );
});

test('dashboard does not count affiliations without ROR identifier', function () {
    $this->actingAs(User::factory()->create());

    $datasetType = ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);

    $resource = Resource::create(['year' => 2024, 'resource_type_id' => $datasetType->id]);
    $person = Person::create(['given_name' => 'John', 'family_name' => 'Doe']);
    $creator = ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);

    // Affiliation without ROR
    Affiliation::create([
        'affiliatable_type' => ResourceCreator::class,
        'affiliatable_id' => $creator->id,
        'name' => 'Some University',
        'identifier' => null,
        'identifier_scheme' => null,
    ]);

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->where('dataResourceCount', 1)
            ->where('dataInstitutionCount', 0) // No ROR = not counted
        );
});

test('dashboard provides PHP version from system', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->has('phpVersion')
            ->where('phpVersion', PHP_VERSION)
        );
});

test('dashboard provides Laravel version from application', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->has('laravelVersion')
            ->where('laravelVersion', app()->version())
        );
});

test('dashboard provides all statistics and version information together', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->has('dataResourceCount')
            ->has('igsnCount')
            ->has('dataInstitutionCount')
            ->has('igsnInstitutionCount')
            ->has('phpVersion')
            ->has('laravelVersion')
            ->where('phpVersion', PHP_VERSION)
            ->where('laravelVersion', app()->version())
        );
});
