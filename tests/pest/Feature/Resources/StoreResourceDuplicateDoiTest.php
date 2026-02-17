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

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->resourceType = ResourceType::create([
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

    TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title', 'is_active' => true, 'is_elmo_active' => true]
    );

    DescriptionType::create([
        'name' => 'Abstract',
        'slug' => 'Abstract',
        'is_active' => true,
    ]);
});

describe('Store Resource - DOI Uniqueness Validation', function () {
    $makePayload = function (array $overrides = []): array {
        return array_merge([
            'resourceId' => null,
            'doi' => null,
            'year' => 2026,
            'resourceType' => ResourceType::first()->id,
            'version' => null,
            'language' => 'en',
            'titles' => [
                ['title' => 'Test Resource', 'titleType' => 'main-title'],
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
                ['descriptionType' => 'abstract', 'description' => 'Test abstract'],
            ],
        ], $overrides);
    };

    test('rejects duplicate DOI with 422 error', function () use ($makePayload) {
        // First, save a resource with a DOI
        $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $makePayload([
                'doi' => '10.5880/fidgeo.2026.053',
            ]))
            ->assertStatus(201);

        // Second, try to save another resource with the same DOI
        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $makePayload([
                'doi' => '10.5880/fidgeo.2026.053',
                'titles' => [
                    ['title' => 'Second Resource', 'titleType' => 'main-title'],
                ],
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['doi']);
    });

    test('allows same DOI when updating the same resource', function () use ($makePayload) {
        // First, save a resource with a DOI
        $createResponse = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $makePayload([
                'doi' => '10.5880/fidgeo.2026.100',
            ]));

        $createResponse->assertStatus(201);
        $resourceId = $createResponse->json('resource.id');

        // Update the same resource — should succeed
        $updateResponse = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $makePayload([
                'resourceId' => $resourceId,
                'doi' => '10.5880/fidgeo.2026.100',
                'titles' => [
                    ['title' => 'Updated Title', 'titleType' => 'main-title'],
                ],
            ]));

        $updateResponse->assertOk();
    });

    test('normalizes DOI to lowercase before saving', function () use ($makePayload) {
        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $makePayload([
                'doi' => '10.5880/FidGeo.2026.055',
            ]));

        $response->assertStatus(201);

        $resource = Resource::query()->latest('id')->firstOrFail();
        expect($resource->doi)->toBe('10.5880/fidgeo.2026.055');
    });

    test('detects duplicate DOI case-insensitively', function () use ($makePayload) {
        // Save with lowercase DOI
        $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $makePayload([
                'doi' => '10.5880/fidgeo.2026.060',
            ]))
            ->assertStatus(201);

        // Try to save with different case — should be rejected
        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $makePayload([
                'doi' => '10.5880/FIDGEO.2026.060',
                'titles' => [
                    ['title' => 'Another Resource', 'titleType' => 'main-title'],
                ],
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['doi']);
    });

    test('allows saving resource without DOI', function () use ($makePayload) {
        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $makePayload([
                'doi' => null,
            ]));

        $response->assertStatus(201);
    });

    test('allows multiple resources without DOI', function () use ($makePayload) {
        // First resource without DOI
        $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $makePayload([
                'doi' => null,
            ]))
            ->assertStatus(201);

        // Second resource without DOI
        $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $makePayload([
                'doi' => null,
                'titles' => [
                    ['title' => 'Second Resource', 'titleType' => 'main-title'],
                ],
            ]))
            ->assertStatus(201);
    });
});
