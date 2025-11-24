<?php

use App\Models\Language;
use App\Models\License;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCoverage;
use App\Models\ResourceType;
use App\Models\Role;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();

    actingAs(User::factory()->create([
        'email_verified_at' => now(),
    ]));

    // Create common required models
    $this->resourceType = ResourceType::query()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $this->language = Language::query()->create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);

    $this->mainTitleType = TitleType::query()->create([
        'name' => 'Main Title',
        'slug' => 'main-title',
    ]);

    $this->license = License::query()->create([
        'identifier' => 'cc-by-4',
        'name' => 'Creative Commons Attribution 4.0',
    ]);

    $this->person = Person::query()->create([
        'orcid' => '0000-0001-2345-6789',
        'first_name' => 'Test',
        'last_name' => 'Author',
    ]);

    $this->authorRole = Role::query()->create([
        'name' => 'Author',
        'slug' => 'author',
        'applies_to' => Role::APPLIES_TO_AUTHOR,
    ]);
});

it('can store a resource with polygon coverage', function (): void {
    $payload = [
        'year' => 2024,
        'resourceType' => $this->resourceType->id,
        'version' => '1.0',
        'language' => $this->language->code,
        'titles' => [
            [
                'title' => 'Test Resource with Polygon',
                'titleType' => 'main-title',
            ],
        ],
        'licenses' => [$this->license->identifier],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => $this->person->orcid,
                'firstName' => $this->person->first_name,
                'lastName' => $this->person->last_name,
                'position' => 0,
                'isContact' => false,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract for polygon coverage',
            ],
        ],
        'spatialTemporalCoverages' => [
            [
                'type' => 'polygon',
                'polygonPoints' => [
                    ['lat' => 52.5, 'lon' => 13.4],
                    ['lat' => 52.6, 'lon' => 13.5],
                    ['lat' => 52.5, 'lon' => 13.6],
                    ['lat' => 52.4, 'lon' => 13.5],
                ],
                'description' => 'Test polygon area',
            ],
        ],
    ];

    $response = postJson('/editor/resources', $payload);

    $response->assertStatus(201)
        ->assertJsonStructure(['resource', 'message']);

    $resource = Resource::query()->latest()->first();
    expect($resource)->not->toBeNull();

    $coverage = $resource->coverages()->first();
    expect($coverage)->not->toBeNull()
        ->type->toBe('polygon')
        ->polygon_points->toBeArray()
        ->polygon_points->toHaveCount(4);

    expect($coverage->polygon_points[0])->toBe(['lat' => 52.5, 'lon' => 13.4]);
    expect($coverage->polygon_points[1])->toBe(['lat' => 52.6, 'lon' => 13.5]);
    expect($coverage->polygon_points[2])->toBe(['lat' => 52.5, 'lon' => 13.6]);
    expect($coverage->polygon_points[3])->toBe(['lat' => 52.4, 'lon' => 13.5]);

    // Verify lat/lon coordinates are null for polygon type
    expect($coverage->lat_min)->toBeNull()
        ->and($coverage->lat_max)->toBeNull()
        ->and($coverage->lon_min)->toBeNull()
        ->and($coverage->lon_max)->toBeNull();
});

it('validates polygon must have at least 3 points', function (): void {
    $payload = [
        'year' => 2024,
        'resourceType' => $this->resourceType->id,
        'version' => '1.0',
        'language' => $this->language->code,
        'titles' => [
            [
                'title' => 'Test Resource',
                'titleType' => 'main-title',
            ],
        ],
        'licenses' => [$this->license->identifier],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => $this->person->orcid,
                'firstName' => $this->person->first_name,
                'lastName' => $this->person->last_name,
                'position' => 0,
                'isContact' => false,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract',
            ],
        ],
        'spatialTemporalCoverages' => [
            [
                'type' => 'polygon',
                'polygonPoints' => [
                    ['lat' => 52.5, 'lon' => 13.4],
                    ['lat' => 52.6, 'lon' => 13.5],
                ],
                'description' => 'Invalid polygon with only 2 points',
            ],
        ],
    ];

    $response = postJson('/editor/resources', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('spatialTemporalCoverages.0.polygonPoints');
});

it('validates polygon coordinate ranges', function (): void {
    $payload = [
        'year' => 2024,
        'resourceType' => $this->resourceType->id,
        'version' => '1.0',
        'language' => $this->language->code,
        'titles' => [
            [
                'title' => 'Test Resource',
                'titleType' => 'main-title',
            ],
        ],
        'licenses' => [$this->license->identifier],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => $this->person->orcid,
                'firstName' => $this->person->first_name,
                'lastName' => $this->person->last_name,
                'position' => 0,
                'isContact' => false,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract',
            ],
        ],
        'spatialTemporalCoverages' => [
            [
                'type' => 'polygon',
                'polygonPoints' => [
                    ['lat' => 95.0, 'lon' => 13.4], // Invalid lat > 90
                    ['lat' => 52.6, 'lon' => 13.5],
                    ['lat' => 52.5, 'lon' => 13.6],
                ],
                'description' => 'Invalid polygon',
            ],
        ],
    ];

    $response = postJson('/editor/resources', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('spatialTemporalCoverages.0.polygonPoints.0.lat');
});

it('can update a resource changing coverage from point to polygon', function (): void {
    $resource = Resource::factory()->create([
        'resource_type_id' => $this->resourceType->id,
        'language_id' => $this->language->id,
    ]);

    $resource->coverages()->create([
        'type' => 'point',
        'lat_min' => 52.5,
        'lon_min' => 13.4,
    ]);

    $resource->titles()->create([
        'title' => 'Original Title',
        'title_type_id' => $this->mainTitleType->id,
    ]);

    $resource->licenses()->attach($this->license->id);

    $resource->authors()->create([
        'authorable_type' => Person::class,
        'authorable_id' => $this->person->id,
        'position' => 0,
    ]);

    $resource->descriptions()->create([
        'description_type' => 'abstract',
        'description' => 'Original abstract',
    ]);

    $payload = [
        'resourceId' => $resource->id,
        'year' => $resource->year,
        'resourceType' => $this->resourceType->id,
        'version' => $resource->version,
        'language' => $this->language->code,
        'titles' => [
            [
                'title' => 'Updated Title',
                'titleType' => 'main-title',
            ],
        ],
        'licenses' => [$this->license->identifier],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => $this->person->orcid,
                'firstName' => $this->person->first_name,
                'lastName' => $this->person->last_name,
                'position' => 0,
                'isContact' => false,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Updated abstract',
            ],
        ],
        'spatialTemporalCoverages' => [
            [
                'type' => 'polygon',
                'polygonPoints' => [
                    ['lat' => 52.5, 'lon' => 13.4],
                    ['lat' => 52.6, 'lon' => 13.5],
                    ['lat' => 52.5, 'lon' => 13.6],
                ],
                'description' => 'Now a polygon',
            ],
        ],
    ];

    $response = postJson('/editor/resources', $payload);

    $response->assertStatus(200);

    $resource->refresh();
    $coverage = $resource->coverages()->first();

    expect($coverage)->not->toBeNull()
        ->type->toBe('polygon')
        ->polygon_points->toBeArray()
        ->polygon_points->toHaveCount(3);

    // Old point coordinates should be cleared
    expect($coverage->lat_min)->toBeNull()
        ->and($coverage->lon_min)->toBeNull();
});

it('can store point coverage type alongside polygon coverage', function (): void {
    $payload = [
        'year' => 2024,
        'resourceType' => $this->resourceType->id,
        'version' => '1.0',
        'language' => $this->language->code,
        'titles' => [
            [
                'title' => 'Test Resource with Multiple Coverages',
                'titleType' => 'main-title',
            ],
        ],
        'licenses' => [$this->license->identifier],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => $this->person->orcid,
                'firstName' => $this->person->first_name,
                'lastName' => $this->person->last_name,
                'position' => 0,
                'isContact' => false,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract',
            ],
        ],
        'spatialTemporalCoverages' => [
            [
                'type' => 'point',
                'latMin' => 52.5,
                'lonMin' => 13.4,
                'description' => 'Point coverage',
            ],
            [
                'type' => 'polygon',
                'polygonPoints' => [
                    ['lat' => 52.5, 'lon' => 13.4],
                    ['lat' => 52.6, 'lon' => 13.5],
                    ['lat' => 52.5, 'lon' => 13.6],
                ],
                'description' => 'Polygon coverage',
            ],
        ],
    ];

    $response = postJson('/editor/resources', $payload);

    $response->assertStatus(201);

    $resource = Resource::query()->latest()->first();
    expect($resource->coverages)->toHaveCount(2);

    $pointCoverage = $resource->coverages->where('type', 'point')->first();
    expect($pointCoverage)->not->toBeNull()
        ->lat_min->toBe('52.500000')
        ->lon_min->toBe('13.400000')
        ->polygon_points->toBeNull();

    $polygonCoverage = $resource->coverages->where('type', 'polygon')->first();
    expect($polygonCoverage)->not->toBeNull()
        ->polygon_points->toBeArray()
        ->polygon_points->toHaveCount(3)
        ->lat_min->toBeNull()
        ->lon_min->toBeNull();
});

it('defaults missing type to point', function (): void {
    $payload = [
        'year' => 2024,
        'resourceType' => $this->resourceType->id,
        'version' => '1.0',
        'language' => $this->language->code,
        'titles' => [
            [
                'title' => 'Test Resource',
                'titleType' => 'main-title',
            ],
        ],
        'licenses' => [$this->license->identifier],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => $this->person->orcid,
                'firstName' => $this->person->first_name,
                'lastName' => $this->person->last_name,
                'position' => 0,
                'isContact' => false,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract',
            ],
        ],
        'spatialTemporalCoverages' => [
            [
                // Missing 'type' field
                'latMin' => 52.5,
                'lonMin' => 13.4,
                'description' => 'Coverage without type',
            ],
        ],
    ];

    $response = postJson('/editor/resources', $payload);

    // Type is normalized to 'point' by default in prepareForValidation
    $response->assertStatus(201);

    $resource = Resource::query()->latest()->first();
    $coverage = $resource->coverages()->first();

    expect($coverage->type)->toBe('point');
});

it('normalizes invalid type to point', function (): void {
    $payload = [
        'year' => 2024,
        'resourceType' => $this->resourceType->id,
        'version' => '1.0',
        'language' => $this->language->code,
        'titles' => [
            [
                'title' => 'Test Resource',
                'titleType' => 'main-title',
            ],
        ],
        'licenses' => [$this->license->identifier],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => $this->person->orcid,
                'firstName' => $this->person->first_name,
                'lastName' => $this->person->last_name,
                'position' => 0,
                'isContact' => false,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract',
            ],
        ],
        'spatialTemporalCoverages' => [
            [
                'type' => 'invalid-type',
                'latMin' => 52.5,
                'lonMin' => 13.4,
                'description' => 'Coverage with invalid type',
            ],
        ],
    ];

    $response = postJson('/editor/resources', $payload);

    // Invalid type is normalized to 'point' in prepareForValidation
    $response->assertStatus(201);

    $resource = Resource::query()->latest()->first();
    $coverage = $resource->coverages()->first();

    expect($coverage->type)->toBe('point');
});

it('can store box coverage type', function (): void {
    $payload = [
        'year' => 2024,
        'resourceType' => $this->resourceType->id,
        'version' => '1.0',
        'language' => $this->language->code,
        'titles' => [
            [
                'title' => 'Test Resource with Box',
                'titleType' => 'main-title',
            ],
        ],
        'licenses' => [$this->license->identifier],
        'authors' => [
            [
                'type' => 'person',
                'orcid' => $this->person->orcid,
                'firstName' => $this->person->first_name,
                'lastName' => $this->person->last_name,
                'position' => 0,
                'isContact' => false,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract',
            ],
        ],
        'spatialTemporalCoverages' => [
            [
                'type' => 'box',
                'latMin' => 52.0,
                'latMax' => 53.0,
                'lonMin' => 13.0,
                'lonMax' => 14.0,
                'description' => 'Bounding box coverage',
            ],
        ],
    ];

    $response = postJson('/editor/resources', $payload);

    $response->assertStatus(201);

    $resource = Resource::query()->latest()->first();
    $coverage = $resource->coverages()->first();

    expect($coverage)->not->toBeNull()
        ->type->toBe('box')
        ->lat_min->toBe('52.000000')
        ->lat_max->toBe('53.000000')
        ->lon_min->toBe('13.000000')
        ->lon_max->toBe('14.000000')
        ->polygon_points->toBeNull();
});
