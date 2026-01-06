<?php

declare(strict_types=1);

use App\Models\DescriptionType;
use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('storing a resource with main-title persists with MainTitle TitleType ID and sets publication_year', function () {
    $user = User::factory()->create();

    $resourceType = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'Dataset',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    Language::create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);

    Right::create([
        'identifier' => 'cc-by-4',
        'name' => 'Creative Commons Attribution 4.0',
        'uri' => null,
        'scheme_uri' => null,
        'is_active' => true,
        'is_elmo_active' => true,
        'usage_count' => 0,
    ]);

    // MainTitle TitleType - all main titles should reference this record
    $mainTitleType = TitleType::create([
        'name' => 'Main Title',
        'slug' => 'MainTitle',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    TitleType::create([
        'name' => 'Other',
        'slug' => 'Other',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    DescriptionType::create([
        'name' => 'Abstract',
        'slug' => 'Abstract',
        'is_active' => true,
    ]);

    $payload = [
        'resourceId' => null,
        'doi' => null,
        'year' => 2024,
        'resourceType' => $resourceType->id,
        'version' => null,
        'language' => 'en',
        'titles' => [
            ['title' => 'A main title', 'titleType' => 'main-title'],
        ],
        'licenses' => ['cc-by-4'],
        'authors' => [
            [
                'type' => 'person',
                'position' => 0,
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract',
            ],
        ],
    ];

    $this->actingAs($user)
        ->postJson(route('editor.resources.store'), $payload)
        ->assertStatus(201);

    /** @var Resource $resource */
    $resource = Resource::query()->latest('id')->firstOrFail();

    expect($resource->publication_year)->toBe(2024);

    $title = $resource->titles()->firstOrFail();
    // MainTitle should be stored with the MainTitle TitleType ID (not NULL)
    expect($title->title_type_id)->toBe($mainTitleType->id);
    expect($title->isMainTitle())->toBeTrue();
});
