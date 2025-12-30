<?php

declare(strict_types=1);

use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('updating a resource syncs licenses (removes old, adds new)', function () {
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

    Right::create([
        'identifier' => 'cc0-1.0',
        'name' => 'Creative Commons CC0 1.0',
        'uri' => null,
        'scheme_uri' => null,
        'is_active' => true,
        'is_elmo_active' => true,
        'usage_count' => 0,
    ]);

    $createPayload = [
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
        ->postJson(route('editor.resources.store'), $createPayload)
        ->assertStatus(201);

    /** @var Resource $resource */
    $resource = Resource::query()->latest('id')->firstOrFail();

    expect($resource->rights()->pluck('identifier')->all())
        ->toEqualCanonicalizing(['cc-by-4']);

    $updatePayload = $createPayload;
    $updatePayload['resourceId'] = $resource->id;
    $updatePayload['year'] = 2025;
    $updatePayload['licenses'] = ['cc0-1.0'];

    $this->actingAs($user)
        ->postJson(route('editor.resources.store'), $updatePayload)
        ->assertStatus(200);

    $resource->refresh();

    expect($resource->rights()->pluck('identifier')->all())
        ->toEqualCanonicalizing(['cc0-1.0']);
});
