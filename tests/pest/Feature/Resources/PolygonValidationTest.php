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

/**
 * Helper to create minimal valid payload for resource creation.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function createBasePayload(int $resourceTypeId, array $overrides = []): array
{
    return array_merge([
        'resourceId' => null,
        'doi' => null,
        'year' => 2024,
        'resourceType' => $resourceTypeId,
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
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract',
            ],
        ],
        'spatialTemporalCoverages' => [],
    ], $overrides);
}

/**
 * Helper to seed required lookup tables for resource creation.
 */
function seedLookupTables(): int
{
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

    TitleType::create([
        'name' => 'Main Title',
        'slug' => 'MainTitle',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    DescriptionType::create([
        'name' => 'Abstract',
        'slug' => 'Abstract',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    return $resourceType->id;
}

describe('Polygon Validation', function () {
    test('accepts valid polygon with 3+ points', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 10.0, 'lat' => 50.0],
                        ['lon' => 11.0, 'lat' => 51.0],
                        ['lon' => 12.0, 'lat' => 50.5],
                    ],
                    'description' => 'Test polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::query()->latest('id')->firstOrFail();
        expect($resource->geoLocations)->toHaveCount(1);

        $geoLocation = $resource->geoLocations->first();
        expect($geoLocation->polygon_points)->toBeArray();
        expect($geoLocation->polygon_points)->toHaveCount(3);
    });

    test('accepts polygon with points at coordinate boundaries (0, 90, -90, 180, -180)', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 0.0, 'lat' => 0.0],       // Equator/Prime Meridian
                        ['lon' => 180.0, 'lat' => 90.0],   // North Pole, Date Line
                        ['lon' => -180.0, 'lat' => -90.0], // South Pole, Date Line
                    ],
                    'description' => 'Boundary test polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::query()->latest('id')->firstOrFail();
        $geoLocation = $resource->geoLocations->first();
        expect($geoLocation->polygon_points)->toHaveCount(3);
    });

    test('rejects polygon with fewer than 3 valid points', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 10.0, 'lat' => 50.0],
                        ['lon' => 11.0, 'lat' => 51.0],
                        // Only 2 points - not enough for a polygon
                    ],
                    'description' => 'Invalid polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spatialTemporalCoverages.0.polygonPoints']);
    });

    test('rejects polygon with out-of-range latitude (> 90)', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 10.0, 'lat' => 50.0],
                        ['lon' => 11.0, 'lat' => 91.0],  // Invalid: lat > 90
                        ['lon' => 12.0, 'lat' => 50.5],
                    ],
                    'description' => 'Invalid latitude polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        // Request validation catches out-of-range coordinates before controller
        $response->assertJsonValidationErrors(['spatialTemporalCoverages.0.polygonPoints.1.lat']);
    });

    test('rejects polygon with out-of-range latitude (< -90)', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 10.0, 'lat' => -91.0], // Invalid: lat < -90
                        ['lon' => 11.0, 'lat' => 51.0],
                        ['lon' => 12.0, 'lat' => 50.5],
                    ],
                    'description' => 'Invalid latitude polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spatialTemporalCoverages.0.polygonPoints.0.lat']);
    });

    test('rejects polygon with out-of-range longitude (> 180)', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 181.0, 'lat' => 50.0], // Invalid: lon > 180
                        ['lon' => 11.0, 'lat' => 51.0],
                        ['lon' => 12.0, 'lat' => 50.5],
                    ],
                    'description' => 'Invalid longitude polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spatialTemporalCoverages.0.polygonPoints.0.lon']);
    });

    test('rejects polygon with out-of-range longitude (< -180)', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => -181.0, 'lat' => 50.0], // Invalid: lon < -180
                        ['lon' => 11.0, 'lat' => 51.0],
                        ['lon' => 12.0, 'lat' => 50.5],
                    ],
                    'description' => 'Invalid longitude polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spatialTemporalCoverages.0.polygonPoints.0.lon']);
    });

    test('rejects polygon with missing lat coordinate', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 10.0],                  // Missing lat
                        ['lon' => 11.0, 'lat' => 51.0],
                        ['lon' => 12.0, 'lat' => 50.5],
                    ],
                    'description' => 'Missing lat polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        // Validation catches invalid point and polygon ends up with fewer than 3 valid points
        $response->assertJsonValidationErrors(['spatialTemporalCoverages.0.polygonPoints']);
    });

    test('rejects polygon with missing lon coordinate', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lat' => 50.0],                  // Missing lon
                        ['lon' => 11.0, 'lat' => 51.0],
                        ['lon' => 12.0, 'lat' => 50.5],
                    ],
                    'description' => 'Missing lon polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        // Validation catches invalid point and polygon ends up with fewer than 3 valid points
        $response->assertJsonValidationErrors(['spatialTemporalCoverages.0.polygonPoints']);
    });

    test('rejects polygon with non-numeric coordinates', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 'abc', 'lat' => 50.0],   // Invalid: non-numeric lon
                        ['lon' => 11.0, 'lat' => 51.0],
                        ['lon' => 12.0, 'lat' => 50.5],
                    ],
                    'description' => 'Non-numeric coordinates polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spatialTemporalCoverages.0.polygonPoints.0.lon']);
    });

    test('accepts polygon with alternate coordinate key names (lon/lat)', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        // The API expects lon/lat keys (not longitude/latitude)
        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 10.0, 'lat' => 50.0],
                        ['lon' => 11.0, 'lat' => 51.0],
                        ['lon' => 12.0, 'lat' => 50.5],
                    ],
                    'description' => 'Standard key names polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::query()->latest('id')->firstOrFail();
        $geoLocation = $resource->geoLocations->first();
        expect($geoLocation->polygon_points)->toHaveCount(3);
    });

    test('accepts string coordinates that are numeric', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => '10.0', 'lat' => '50.0'],
                        ['lon' => '11.0', 'lat' => '51.0'],
                        ['lon' => '12.0', 'lat' => '50.5'],
                    ],
                    'description' => 'String coordinates polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::query()->latest('id')->firstOrFail();
        $geoLocation = $resource->geoLocations->first();
        expect($geoLocation->polygon_points)->toHaveCount(3);
        // Verify coordinates are stored (may be stored as int or float depending on input)
        expect($geoLocation->polygon_points[0]['longitude'])->toEqual(10.0);
        expect($geoLocation->polygon_points[0]['latitude'])->toEqual(50.0);
    });

    test('stores polygon points with longitude/latitude keys in database', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 10.5, 'lat' => 50.5],
                        ['lon' => 11.5, 'lat' => 51.5],
                        ['lon' => 12.5, 'lat' => 50.0],
                    ],
                    'description' => 'Test polygon storage',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::query()->latest('id')->firstOrFail();
        $geoLocation = $resource->geoLocations->first();

        // Verify the stored format uses longitude/latitude keys
        expect($geoLocation->polygon_points[0])->toHaveKeys(['longitude', 'latitude']);
        expect($geoLocation->polygon_points[0]['longitude'])->toBe(10.5);
        expect($geoLocation->polygon_points[0]['latitude'])->toBe(50.5);
        expect($geoLocation->polygon_points[1]['longitude'])->toBe(11.5);
        expect($geoLocation->polygon_points[2]['longitude'])->toBe(12.5);
    });

    test('stores place description with polygon', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedLookupTables();

        $payload = createBasePayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 10.0, 'lat' => 50.0],
                        ['lon' => 11.0, 'lat' => 51.0],
                        ['lon' => 12.0, 'lat' => 50.5],
                    ],
                    'description' => 'Berlin, Germany',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::query()->latest('id')->firstOrFail();
        $geoLocation = $resource->geoLocations->first();

        expect($geoLocation->place)->toBe('Berlin, Germany');
    });
});


