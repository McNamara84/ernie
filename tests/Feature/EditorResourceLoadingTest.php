<?php

use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Language;
use App\Models\Person;
use App\Models\ResourceAuthor;
use App\Models\Role;
use App\Models\ResourceTitle;
use App\Models\TitleType;
use App\Models\License;
use App\Models\ResourceDescription;
use App\Models\ResourceDate;
use App\Models\ResourceKeyword;
use App\Models\ResourceCoverage;
use App\Models\RelatedIdentifier;
use App\Models\ResourceFundingReference;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Create a test user
    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ]);

    // Create required related records
    $resourceType = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);
    
    $language = Language::create([
        'code' => 'en',
        'name' => 'English',
    ]);

    // Create a test resource
    $this->resource = Resource::create([
        'doi' => '10.5880/TEST.LOAD.001',
        'year' => 2025,
        'version' => '1.0',
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
    ]);
});

test('editor loads resource titles correctly', function () {
    $titleType = TitleType::create(['name' => 'Title', 'slug' => 'title']);
    $subtitleType = TitleType::create(['name' => 'Subtitle', 'slug' => 'subtitle']);
    
    ResourceTitle::create([
        'resource_id' => $this->resource->id,
        'title' => 'Main Title',
        'title_type_id' => $titleType->id,
    ]);
    
    ResourceTitle::create([
        'resource_id' => $this->resource->id,
        'title' => 'Subtitle Text',
        'title_type_id' => $subtitleType->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('editor') . '?resourceId=' . $this->resource->id)
        ->assertInertia(fn(Assert $page) => $page
            ->component('editor')
            ->has('initialData.titles', 2)
            ->where('initialData.titles.0.title', 'Main Title')
            ->where('initialData.titles.0.titleType', 'title')
        );
});

test('editor loads authors with deduplication', function () {
    $person = Person::create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'orcid' => '0000-0001-2345-6789',
        'orcid_verified_at' => now(),
    ]);

    $authorRole = Role::create(['name' => 'Author', 'slug' => 'author']);
    $contactRole = Role::create(['name' => 'Contact Person', 'slug' => 'contact-person']);

    // Create two ResourceAuthor entries for the same person (author + contact)
    $author1 = ResourceAuthor::create([
        'resource_id' => $this->resource->id,
        'authorable_type' => Person::class,
        'authorable_id' => $person->id,
        'position' => 1,
    ]);
    $author1->roles()->attach($authorRole);

    $author2 = ResourceAuthor::create([
        'resource_id' => $this->resource->id,
        'authorable_type' => Person::class,
        'authorable_id' => $person->id,
        'position' => 1,
    ]);
    $author2->roles()->attach($contactRole);

    $this->actingAs($this->user)
        ->get(route('editor') . '?resourceId=' . $this->resource->id)
        ->assertInertia(fn(Assert $page) => $page
            ->component('editor')
            ->has('initialData.authors', 1) // Should be deduplicated to 1
            ->where('initialData.authors.0.firstName', 'John')
            ->where('initialData.authors.0.lastName', 'Doe')
            ->where('initialData.authors.0.orcid', '0000-0001-2345-6789')
            ->where('initialData.authors.0.isContact', true)
            ->where('initialData.authors.0.orcidVerified', true)
        );
});

test('editor loads descriptions with type mapping', function () {
    ResourceDescription::create([
        'resource_id' => $this->resource->id,
        'description_type' => 'abstract',
        'description' => 'This is an abstract.',
    ]);

    ResourceDescription::create([
        'resource_id' => $this->resource->id,
        'description_type' => 'methods',
        'description' => 'These are the methods.',
    ]);

    $this->actingAs($this->user)
        ->get(route('editor') . '?resourceId=' . $this->resource->id)
        ->assertInertia(fn(Assert $page) => $page
            ->component('editor')
            ->has('initialData.descriptions', 2)
            ->where('initialData.descriptions.0.type', 'Abstract') // PascalCase
            ->where('initialData.descriptions.1.type', 'Methods') // PascalCase
        );
});

test('editor excludes coverage dates from dates array', function () {
    ResourceDate::create([
        'resource_id' => $this->resource->id,
        'date_type' => 'created',
        'start_date' => '2025-10-01',
    ]);

    ResourceDate::create([
        'resource_id' => $this->resource->id,
        'date_type' => 'coverage', // Should be excluded
        'start_date' => '2025-10-05',
        'end_date' => '2025-10-10',
    ]);

    $this->actingAs($this->user)
        ->get(route('editor') . '?resourceId=' . $this->resource->id)
        ->assertInertia(fn(Assert $page) => $page
            ->component('editor')
            ->has('initialData.dates', 1) // Only non-coverage dates
            ->where('initialData.dates.0.dateType', 'created')
            ->where('initialData.dates.0.startDate', '2025-10-01')
        );
});

test('editor loads coverages with date formatting', function () {
    ResourceCoverage::create([
        'resource_id' => $this->resource->id,
        'lat_min' => 48.173685,
        'lon_min' => 11.403433,
        'start_date' => '2025-10-13',
        'end_date' => '2025-10-19',
        'timezone' => 'Europe/Berlin',
        'description' => 'Test coverage',
    ]);

    $this->actingAs($this->user)
        ->get(route('editor') . '?resourceId=' . $this->resource->id)
        ->assertInertia(fn(Assert $page) => $page
            ->component('editor')
            ->has('initialData.coverages', 1)
            ->where('initialData.coverages.0.latMin', '48.173685')
            ->where('initialData.coverages.0.lonMin', '11.403433')
            ->where('initialData.coverages.0.startDate', '2025-10-13')
            ->where('initialData.coverages.0.endDate', '2025-10-19')
        );
});

test('editor handles zero coordinates correctly', function () {
    // Coordinates with 0 values (equator/prime meridian) should not become empty strings
    ResourceCoverage::create([
        'resource_id' => $this->resource->id,
        'lat_min' => 0.0, // Equator
        'lon_min' => 0.0, // Prime meridian
        'timezone' => 'UTC',
    ]);

    $this->actingAs($this->user)
        ->get(route('editor') . '?resourceId=' . $this->resource->id)
        ->assertInertia(fn(Assert $page) => $page
            ->component('editor')
            ->has('initialData.coverages', 1)
            ->where('initialData.coverages.0.latMin', '0')
            ->where('initialData.coverages.0.lonMin', '0')
        );
});

test('editor without resource id shows empty form', function () {
    $this->actingAs($this->user)
        ->get(route('editor'))
        ->assertInertia(fn(Assert $page) => $page
            ->component('editor')
            ->missing('initialData')
        );
});

test('editor with invalid resource id returns 404', function () {
    $this->actingAs($this->user)
        ->get(route('editor') . '?resourceId=99999')
        ->assertNotFound();
});
