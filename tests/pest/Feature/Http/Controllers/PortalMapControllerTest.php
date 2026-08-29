<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Support\Facades\RateLimiter;

function createPublishedPortalMapResource(ResourceType $type, string $title = 'Mapped resource'): Resource
{
    $resource = Resource::factory()->create(['resource_type_id' => $type->id]);
    $titleType = TitleType::firstOrCreate(
        ['slug' => 'main-title'],
        ['name' => 'Main Title'],
    );

    Title::factory()->create([
        'resource_id' => $resource->id,
        'title_type_id' => $titleType->id,
        'value' => $title,
    ]);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

    return $resource;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function portalMapRequestQuery(array $overrides = []): array
{
    return [
        'viewport' => [
            'north' => 54,
            'south' => 50,
            'east' => 16,
            'west' => 10,
            'width' => 1000,
            'height' => 700,
        ],
        'zoom' => 8,
        ...$overrides,
    ];
}

beforeEach(function (): void {
    config([
        'bot_protection.enabled' => false,
        'portal_map.enabled' => true,
        'portal_map.max_features' => 1000,
    ]);

    $this->datasetType = ResourceType::firstOrCreate(
        ['slug' => 'dataset'],
        ['name' => 'Dataset', 'is_active' => true],
    );
    $this->physicalObjectType = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );
});

it('returns a lightweight resource feature for a published point in the viewport', function (): void {
    $resource = createPublishedPortalMapResource($this->datasetType, 'Berlin gravity data');
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $resource->id]);

    $this->getJson(route('portal.map', portalMapRequestQuery(['include_extent' => 1])))
        ->assertOk()
        ->assertJsonPath('schemaVersion', 1)
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.kind', 'resource')
        ->assertJsonPath('features.0.geometry.type', 'point')
        ->assertJsonPath('features.0.resource.title', 'Berlin gravity data')
        ->assertJsonPath('features.0.resource.resourceType.slug', 'dataset')
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('meta.totalLocations', 1)
        ->assertJsonPath('meta.returnedFeatures', 1);
});

it('infers legacy geometry details when geo type is missing', function (): void {
    config(['portal_map.shape_detail_zoom' => 8]);
    $pointResource = createPublishedPortalMapResource($this->datasetType, 'Legacy point');
    $boxResource = createPublishedPortalMapResource($this->datasetType, 'Legacy box');
    $polygonResource = createPublishedPortalMapResource($this->datasetType, 'Legacy polygon');

    GeoLocation::factory()->create([
        'resource_id' => $pointResource->id,
        'geo_type' => null,
        'point_longitude' => 10.5,
        'point_latitude' => 50.5,
    ]);
    GeoLocation::factory()->create([
        'resource_id' => $boxResource->id,
        'geo_type' => null,
        'west_bound_longitude' => 12,
        'east_bound_longitude' => 13,
        'south_bound_latitude' => 51,
        'north_bound_latitude' => 52,
    ]);
    GeoLocation::factory()->create([
        'resource_id' => $polygonResource->id,
        'geo_type' => null,
        'polygon_points' => [
            ['longitude' => 14.0, 'latitude' => 52.0],
            ['longitude' => 15.0, 'latitude' => 52.0],
            ['longitude' => 14.5, 'latitude' => 53.0],
        ],
    ]);

    $response = $this->getJson(route('portal.map', portalMapRequestQuery(['zoom' => 12])))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 3)
        ->json();

    expect(collect($response['features'])
        ->where('kind', 'resource')
        ->pluck('geometry.type')
        ->sort()
        ->values()
        ->all())->toBe(['box', 'point', 'polygon']);
});

it('excludes drafts, locations outside the viewport, and whole-world coverage boxes', function (): void {
    $published = createPublishedPortalMapResource($this->datasetType, 'Visible');
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $published->id]);
    GeoLocation::factory()->withPoint(-43.2, -22.9)->create(['resource_id' => $published->id]);
    GeoLocation::factory()->withBox(-180, 180, -90, 90)->create(['resource_id' => $published->id]);

    $draft = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    LandingPage::factory()->draft()->create(['resource_id' => $draft->id]);
    GeoLocation::factory()->withPoint(13.5, 52.6)->create(['resource_id' => $draft->id]);

    $this->getJson(route('portal.map', portalMapRequestQuery(['include_extent' => 1])))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('meta.totalLocations', 2)
        ->assertJsonCount(1, 'features');
});

it('clusters nearby locations and reports their type distribution', function (): void {
    $dataset = createPublishedPortalMapResource($this->datasetType, 'Dataset');
    $sample = createPublishedPortalMapResource($this->physicalObjectType, 'Sample');
    GeoLocation::factory()->withPoint(13.4000, 52.5000)->create(['resource_id' => $dataset->id]);
    GeoLocation::factory()->withPoint(13.4001, 52.5001)->create(['resource_id' => $sample->id]);

    $this->getJson(route('portal.map', portalMapRequestQuery()))
        ->assertOk()
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.kind', 'cluster')
        ->assertJsonPath('features.0.count', 2)
        ->assertJsonPath('features.0.resourceTypeCounts.dataset', 1)
        ->assertJsonPath('features.0.resourceTypeCounts.physical-object', 1);
});

it('uses the same text and resource-type filters as the result list', function (): void {
    $dataset = createPublishedPortalMapResource($this->datasetType, 'Wanted seismic dataset');
    $sample = createPublishedPortalMapResource($this->physicalObjectType, 'Wanted sample');
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $dataset->id]);
    GeoLocation::factory()->withPoint(13.5, 52.6)->create(['resource_id' => $sample->id]);

    $query = portalMapRequestQuery([
        'q' => 'Wanted',
        'type' => ['physical-object'],
    ]);

    $this->getJson(route('portal.map', $query))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('features.0.resource.resourceType.slug', 'physical-object');
});

it('preserves legacy doi and igsn type links on the map endpoint', function (): void {
    $dataset = createPublishedPortalMapResource($this->datasetType, 'Legacy DOI link');
    $sample = createPublishedPortalMapResource($this->physicalObjectType, 'Legacy IGSN link');
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $dataset->id]);
    GeoLocation::factory()->withPoint(13.6, 52.6)->create(['resource_id' => $sample->id]);

    $this->getJson(route('portal.map', portalMapRequestQuery(['type' => 'doi'])))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('features.0.resource.resourceType.slug', 'dataset');

    $this->getJson(route('portal.map', portalMapRequestQuery(['type' => 'igsn'])))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('features.0.resource.resourceType.slug', 'physical-object');
});

it('supports technical and filter viewports that cross the antimeridian', function (): void {
    $east = createPublishedPortalMapResource($this->datasetType, 'East');
    $west = createPublishedPortalMapResource($this->datasetType, 'West');
    GeoLocation::factory()->withPoint(179.5, 0)->create(['resource_id' => $east->id]);
    GeoLocation::factory()->withPoint(-179.5, 0)->create(['resource_id' => $west->id]);

    $query = portalMapRequestQuery([
        'viewport' => [
            'north' => 10,
            'south' => -10,
            'east' => -170,
            'west' => 170,
            'width' => 800,
            'height' => 600,
        ],
        'north' => 10,
        'south' => -10,
        'east' => -170,
        'west' => 170,
        'zoom' => 6,
    ]);

    $this->getJson(route('portal.map', $query))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 2);
});

it('returns full box and polygon geometry only at detail zoom', function (): void {
    config(['portal_map.shape_detail_zoom' => 8]);
    $boxResource = createPublishedPortalMapResource($this->datasetType, 'Box');
    $polygonResource = createPublishedPortalMapResource($this->datasetType, 'Polygon');
    GeoLocation::factory()->withBox(12, 13, 51, 52)->create(['resource_id' => $boxResource->id]);
    GeoLocation::factory()->withPolygon([
        ['longitude' => 14.0, 'latitude' => 51.0],
        ['longitude' => 15.0, 'latitude' => 51.0],
        ['longitude' => 14.5, 'latitude' => 52.0],
    ])->create(['resource_id' => $polygonResource->id]);

    $response = $this->getJson(route('portal.map', portalMapRequestQuery()))
        ->assertOk()
        ->json();

    $geometryTypes = collect($response['features'])
        ->where('kind', 'resource')
        ->pluck('geometry.type')
        ->sort()
        ->values()
        ->all();

    expect($geometryTypes)->toBe(['box', 'polygon']);
});

it('validates viewport dimensions, coordinate ordering, and complete filter bounds', function (): void {
    $this->getJson(route('portal.map', [
        'viewport' => [
            'north' => 40,
            'south' => 50,
            'east' => 14,
            'west' => 12,
            'width' => 0,
            'height' => 600,
        ],
        'north' => 53,
        'zoom' => 19,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['viewport.north', 'viewport.width', 'zoom', 'north']);
});

it('can be disabled independently during rollout', function (): void {
    config(['portal_map.enabled' => false]);

    $this->getJson(route('portal.map', portalMapRequestQuery()))
        ->assertStatus(503)
        ->assertJsonPath('message', 'The portal map is temporarily unavailable.');
});

it('rate limits map requests independently from page loads', function (): void {
    $ipAddress = '198.51.100.'.random_int(1, 254);
    config([
        'bot_protection.enabled' => true,
        'bot_protection.ai_user_agents' => ['GPTBot'],
        'bot_protection.limits.ai_bot_public_per_minute' => 1,
        'bot_protection.limits.public_portal_map_per_minute' => 10,
    ]);
    RateLimiter::clear("portal-map:ai-bot:{$ipAddress}");

    $server = [
        'REMOTE_ADDR' => $ipAddress,
        'HTTP_USER_AGENT' => 'GPTBot',
        'HTTP_ACCEPT' => 'application/json',
    ];

    $this->withServerVariables($server)
        ->getJson(route('portal.map', portalMapRequestQuery()))
        ->assertOk();

    $this->withServerVariables($server)
        ->getJson(route('portal.map', portalMapRequestQuery()))
        ->assertTooManyRequests();
});
