<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\PortalMapService;
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
        'portal_map.igsn_material_visualization_enabled' => true,
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

    $this->getJson(route('portal.doi.map', portalMapRequestQuery(['include_extent' => 1])))
        ->assertOk()
        ->assertJsonPath('schemaVersion', 3)
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.kind', 'resource')
        ->assertJsonPath('features.0.geometry.type', 'point')
        ->assertJsonPath('features.0.resource.title', 'Berlin gravity data')
        ->assertJsonPath('features.0.resource.resourceType.slug', 'dataset')
        ->assertJsonPath('features.0.resource.presentation.dimension', 'resource-type')
        ->assertJsonPath('features.0.resource.presentation.key', 'dataset')
        ->assertJsonPath('meta.visualizationDimension', 'resource-type')
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('meta.totalLocations', 1)
        ->assertJsonPath('meta.returnedFeatures', 1);
});

it('drops resource candidates whose locations disappear before hydration', function (): void {
    $hydrate = new ReflectionMethod(PortalMapService::class, 'hydrateResourceCandidates');
    $features = [[
        'kind' => 'resource-candidate',
        'locationId' => PHP_INT_MAX,
        'position' => ['lat' => 52.5, 'lng' => 13.4],
        'bounds' => ['north' => 52.5, 'south' => 52.5, 'east' => 13.4, 'west' => 13.4],
    ]];

    expect($hydrate->invoke(app(PortalMapService::class), $features, 'resource-type'))->toBe([]);
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

    $response = $this->getJson(route('portal.doi.map', portalMapRequestQuery(['zoom' => 12])))
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

it('uses the authoritative polygon geometry when other geometry fields contain a global box', function (): void {
    config(['portal_map.shape_detail_zoom' => 8]);
    $resource = createPublishedPortalMapResource($this->datasetType, 'Mixed DataCite geometry');
    GeoLocation::factory()->create([
        'resource_id' => $resource->id,
        'geo_type' => 'polygon',
        'point_latitude' => 0,
        'point_longitude' => 0,
        'south_bound_latitude' => -90,
        'west_bound_longitude' => -180,
        'north_bound_latitude' => 90,
        'east_bound_longitude' => 180,
        'polygon_points' => [
            ['longitude' => 12.0, 'latitude' => 51.0],
            ['longitude' => 14.0, 'latitude' => 51.0],
            ['longitude' => 13.0, 'latitude' => 53.0],
        ],
    ]);

    $feature = $this->getJson(route('portal.doi.map', portalMapRequestQuery(['zoom' => 12, 'include_extent' => 1])))
        ->assertOk()
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.kind', 'resource')
        ->assertJsonPath('features.0.geometry.type', 'polygon')
        ->assertJsonPath('meta.totalLocations', 1)
        ->json('features.0');

    expect(abs((float) $feature['position']['lng'] - 13.0))->toBeLessThan(0.000001);
});

it('infers a polygon before rejecting an additional global box', function (): void {
    config(['portal_map.shape_detail_zoom' => 0]);
    $resource = createPublishedPortalMapResource($this->datasetType, 'Legacy mixed geometry');
    GeoLocation::factory()->create([
        'resource_id' => $resource->id,
        'geo_type' => null,
        'south_bound_latitude' => -90,
        'west_bound_longitude' => -180,
        'north_bound_latitude' => 90,
        'east_bound_longitude' => 180,
        'polygon_points' => [
            ['longitude' => 12.0, 'latitude' => 51.0],
            ['longitude' => 14.0, 'latitude' => 51.0],
            ['longitude' => 13.0, 'latitude' => 53.0],
        ],
    ]);

    $this->getJson(route('portal.doi.map', portalMapRequestQuery(['zoom' => 12])))
        ->assertOk()
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.kind', 'resource')
        ->assertJsonPath('features.0.geometry.type', 'polygon');
});

it('excludes drafts, locations outside the viewport, and whole-world coverage boxes', function (): void {
    $published = createPublishedPortalMapResource($this->datasetType, 'Visible');
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $published->id]);
    GeoLocation::factory()->withPoint(-43.2, -22.9)->create(['resource_id' => $published->id]);
    GeoLocation::factory()->withBox(-180, 180, -90, 90)->create(['resource_id' => $published->id]);

    $draft = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    LandingPage::factory()->draft()->create(['resource_id' => $draft->id]);
    GeoLocation::factory()->withPoint(13.5, 52.6)->create(['resource_id' => $draft->id]);

    $this->getJson(route('portal.doi.map', portalMapRequestQuery(['include_extent' => 1])))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('meta.totalLocations', 2)
        ->assertJsonCount(1, 'features');
});

it('keeps DOI and IGSN map data in their respective portal scopes', function (): void {
    $dataset = createPublishedPortalMapResource($this->datasetType, 'Dataset');
    $sample = createPublishedPortalMapResource($this->physicalObjectType, 'Sample');
    GeoLocation::factory()->withPoint(13.4000, 52.5000)->create(['resource_id' => $dataset->id]);
    GeoLocation::factory()->withPoint(13.4001, 52.5001)->create(['resource_id' => $sample->id]);

    $this->getJson(route('portal.doi.map', portalMapRequestQuery()))
        ->assertOk()
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('features.0.resource.resourceType.slug', 'dataset');

    $this->getJson(route('portal.igsn.map', portalMapRequestQuery()))
        ->assertOk()
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('features.0.resource.resourceType.slug', 'physical-object')
        ->assertJsonPath('features.0.resource.presentation.dimension', 'material')
        ->assertJsonPath('features.0.resource.presentation.key', 'missing')
        ->assertJsonPath('meta.visualizationDimension', 'material');
});

it('returns exact IGSN details and top-level material presentation for a point', function (): void {
    $sample = createPublishedPortalMapResource($this->physicalObjectType, 'Porewater sample');
    IgsnMetadata::query()->create([
        'resource_id' => $sample->id,
        'sample_type' => 'Individual Sample',
        'material' => 'Liquid>aqueous>porewater',
    ]);
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $sample->id]);

    $this->getJson(route('portal.igsn.map', portalMapRequestQuery(['zoom' => 12])))
        ->assertOk()
        ->assertJsonPath('schemaVersion', 3)
        ->assertJsonPath('meta.visualizationDimension', 'material')
        ->assertJsonPath('features.0.kind', 'resource')
        ->assertJsonPath('features.0.resource.presentation.dimension', 'material')
        ->assertJsonPath('features.0.resource.presentation.key', 'liquid')
        ->assertJsonPath('features.0.resource.presentation.label', 'Liquid')
        ->assertJsonPath('features.0.resource.presentation.status', 'value')
        ->assertJsonPath('features.0.resource.igsn.sampleType', 'Individual Sample')
        ->assertJsonPath('features.0.resource.igsn.material', 'Liquid>aqueous>porewater')
        ->assertJsonPath('features.0.resource.igsn.materialLabel', 'Liquid › aqueous › porewater');
});

it('aggregates controlled, missing, not-applicable, and unrecognized IGSN materials', function (): void {
    $materials = ['Rock', 'Liquid>aqueous', 'NotApplicable', null, 'Legacy material'];

    foreach ($materials as $index => $material) {
        $sample = createPublishedPortalMapResource($this->physicalObjectType, "Sample {$index}");
        if ($material !== null) {
            IgsnMetadata::query()->create([
                'resource_id' => $sample->id,
                'sample_type' => 'Individual Sample',
                'material' => $material,
            ]);
        }
        GeoLocation::factory()->withPoint(13.4 + ($index / 10000), 52.5 + ($index / 10000))->create([
            'resource_id' => $sample->id,
        ]);
    }

    $response = $this->getJson(route('portal.igsn.map', portalMapRequestQuery(['zoom' => 10])))
        ->assertOk()
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.kind', 'cluster')
        ->assertJsonPath('features.0.count', 5)
        ->assertJsonPath('features.0.resourceTypeCounts.physical-object', 5)
        ->assertJsonPath('features.0.composition.dimension', 'material')
        ->assertJsonPath('features.0.composition.counts.liquid', 1)
        ->assertJsonPath('features.0.composition.counts.missing', 1)
        ->assertJsonPath('features.0.composition.counts.not-applicable', 1)
        ->assertJsonPath('features.0.composition.counts.rock', 1)
        ->assertJsonPath('features.0.composition.counts.unrecognized', 1);

    expect(array_sum($response->json('features.0.composition.counts')))->toBe(5);
});

it('returns paginated and hydrated members for a terminal DOI cluster', function (): void {
    config(['portal_map.cluster_members_per_page' => 1]);
    $first = createPublishedPortalMapResource($this->datasetType, 'First colocated dataset');
    $second = createPublishedPortalMapResource($this->datasetType, 'Second colocated dataset');
    $draft = Resource::factory()->create(['resource_type_id' => $this->datasetType->id]);
    LandingPage::factory()->draft()->create(['resource_id' => $draft->id]);
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $first->id]);
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $second->id]);
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $draft->id]);
    $query = portalMapRequestQuery(['zoom' => 18, 'q' => 'colocated dataset']);

    $cluster = $this->getJson(route('portal.doi.map', $query))
        ->assertOk()
        ->assertJsonPath('schemaVersion', 3)
        ->assertJsonPath('features.0.kind', 'cluster')
        ->assertJsonPath('features.0.count', 2)
        ->assertJsonStructure(['features' => [['navigationBounds']]])
        ->json('features.0');

    $firstPage = $this->getJson(route('portal.doi.map-cluster-members', [
        'clusterId' => $cluster['id'],
        ...$query,
    ]))
        ->assertOk()
        ->assertJsonPath('schemaVersion', 1)
        ->assertJsonPath('clusterId', $cluster['id'])
        ->assertJsonPath('total', 2)
        ->assertJsonPath('pagination.currentPage', 1)
        ->assertJsonPath('pagination.lastPage', 2)
        ->assertJsonPath('pagination.perPage', 1)
        ->assertJsonCount(1, 'members');

    $secondPage = $this->getJson(route('portal.doi.map-cluster-members', [
        'clusterId' => $cluster['id'],
        ...$query,
        'page' => 2,
    ]))
        ->assertOk()
        ->assertJsonPath('pagination.currentPage', 2)
        ->assertJsonCount(1, 'members');

    expect($firstPage->json('members.0.resource.title'))->toBe('First colocated dataset')
        ->and($secondPage->json('members.0.resource.title'))->toBe('Second colocated dataset');
});

it('preserves IGSN material details when resolving terminal cluster members', function (): void {
    foreach (['First rock core', 'Second rock core'] as $index => $title) {
        $sample = createPublishedPortalMapResource($this->physicalObjectType, $title);
        IgsnMetadata::query()->create([
            'resource_id' => $sample->id,
            'sample_type' => $index === 0 ? 'Core' : 'Individual Sample',
            'material' => 'Rock',
        ]);
        GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $sample->id]);
    }
    $query = portalMapRequestQuery(['zoom' => 18, 'materials' => ['Rock']]);
    $clusterId = $this->getJson(route('portal.igsn.map', $query))
        ->assertOk()
        ->assertJsonPath('features.0.composition.dimension', 'material')
        ->assertJsonPath('features.0.composition.counts.rock', 2)
        ->json('features.0.id');

    $this->getJson(route('portal.igsn.map-cluster-members', ['clusterId' => $clusterId, ...$query]))
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('members.0.resource.presentation.dimension', 'material')
        ->assertJsonPath('members.0.resource.presentation.key', 'rock')
        ->assertJsonPath('members.0.resource.igsn.sampleType', 'Core')
        ->assertJsonPath('members.0.resource.igsn.material', 'Rock')
        ->assertJsonPath('members.1.resource.igsn.sampleType', 'Individual Sample');
});

it('keeps terminal cluster members in the portal scope and current viewport', function (): void {
    $dataset = createPublishedPortalMapResource($this->datasetType, 'Scoped dataset');
    $secondDataset = createPublishedPortalMapResource($this->datasetType, 'Second scoped dataset');
    $sample = createPublishedPortalMapResource($this->physicalObjectType, 'Scoped sample');
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $dataset->id]);
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $secondDataset->id]);
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $sample->id]);
    $query = portalMapRequestQuery(['zoom' => 18]);
    $clusterId = $this->getJson(route('portal.doi.map', $query))->json('features.0.id');

    $this->getJson(route('portal.doi.map-cluster-members', ['clusterId' => $clusterId, ...$query]))
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonMissing(['title' => 'Scoped sample']);

    $outsideViewport = portalMapRequestQuery([
        'zoom' => 18,
        'viewport' => ['north' => 10, 'south' => -10, 'east' => 10, 'west' => -10, 'width' => 1000, 'height' => 700],
    ]);
    $this->getJson(route('portal.doi.map-cluster-members', ['clusterId' => $clusterId, ...$outsideViewport]))
        ->assertNotFound()
        ->assertJsonPath('message', 'The requested map cluster was not found.');
});

it('validates cluster member requests and respects the map feature flag', function (): void {
    $this->getJson(route('portal.doi.map-cluster-members', [
        'clusterId' => 'invalid-cluster',
        ...portalMapRequestQuery(['zoom' => 19]),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cluster_id', 'zoom']);

    config(['portal_map.enabled' => false]);
    $this->getJson(route('portal.doi.map-cluster-members', [
        'clusterId' => 'z18:1:1',
        ...portalMapRequestQuery(['zoom' => 18]),
    ]))
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'The portal map is temporarily unavailable.');
});

it('can roll the IGSN map back to the resource-type presentation', function (): void {
    config(['portal_map.igsn_material_visualization_enabled' => false]);
    $sample = createPublishedPortalMapResource($this->physicalObjectType, 'Fallback sample');
    IgsnMetadata::query()->create([
        'resource_id' => $sample->id,
        'sample_type' => 'Specimen',
        'material' => 'Rock',
    ]);
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $sample->id]);

    $this->getJson(route('portal.igsn.map', portalMapRequestQuery(['zoom' => 12])))
        ->assertOk()
        ->assertJsonPath('meta.visualizationDimension', 'resource-type')
        ->assertJsonPath('features.0.resource.presentation.dimension', 'resource-type')
        ->assertJsonPath('features.0.resource.presentation.key', 'physical-object')
        ->assertJsonPath('features.0.resource.igsn', null);
});

it('applies IGSN metadata filters before building material presentation', function (): void {
    $rock = createPublishedPortalMapResource($this->physicalObjectType, 'Wanted core');
    $liquid = createPublishedPortalMapResource($this->physicalObjectType, 'Excluded specimen');
    IgsnMetadata::query()->create([
        'resource_id' => $rock->id,
        'sample_type' => 'Core',
        'material' => 'Rock',
    ]);
    IgsnMetadata::query()->create([
        'resource_id' => $liquid->id,
        'sample_type' => 'Specimen',
        'material' => 'Liquid>aqueous',
    ]);
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $rock->id]);
    GeoLocation::factory()->withPoint(13.4001, 52.5001)->create(['resource_id' => $liquid->id]);

    $this->getJson(route('portal.igsn.map', portalMapRequestQuery([
        'zoom' => 12,
        'sample_types' => ['Core'],
        'materials' => ['Rock'],
    ])))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.resource.title', 'Wanted core')
        ->assertJsonPath('features.0.resource.presentation.key', 'rock')
        ->assertJsonPath('features.0.resource.igsn.sampleType', 'Core');
});

it('uses the same text and resource-type filters as the result list', function (): void {
    $dataset = createPublishedPortalMapResource($this->datasetType, 'Wanted seismic dataset');
    $sample = createPublishedPortalMapResource($this->physicalObjectType, 'Wanted sample');
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $dataset->id]);
    GeoLocation::factory()->withPoint(13.5, 52.6)->create(['resource_id' => $sample->id]);

    $query = portalMapRequestQuery([
        'q' => 'Wanted',
        'type' => ['dataset'],
    ]);

    $this->getJson(route('portal.doi.map', $query))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('features.0.resource.resourceType.slug', 'dataset');
});

it('does not allow legacy type shortcuts to override a map portal scope', function (): void {
    $dataset = createPublishedPortalMapResource($this->datasetType, 'Legacy DOI link');
    $sample = createPublishedPortalMapResource($this->physicalObjectType, 'Legacy IGSN link');
    GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $dataset->id]);
    GeoLocation::factory()->withPoint(13.6, 52.6)->create(['resource_id' => $sample->id]);

    $this->getJson(route('portal.doi.map', portalMapRequestQuery(['type' => 'doi'])))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('features.0.resource.resourceType.slug', 'dataset');

    $this->getJson(route('portal.doi.map', portalMapRequestQuery(['type' => 'igsn'])))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 1)
        ->assertJsonPath('features.0.resource.resourceType.slug', 'dataset');

    $this->getJson(route('portal.igsn.map', portalMapRequestQuery(['type' => 'doi'])))
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

    $this->getJson(route('portal.doi.map', $query))
        ->assertOk()
        ->assertJsonPath('meta.visibleLocations', 2);
});

it('anchors an antimeridian polygon near its vertices instead of Greenwich', function (): void {
    config(['portal_map.shape_detail_zoom' => 0]);
    $resource = createPublishedPortalMapResource($this->datasetType, 'Dateline polygon');
    GeoLocation::factory()->create([
        'resource_id' => $resource->id,
        'geo_type' => 'polygon',
        'polygon_points' => [
            ['longitude' => 179.0, 'latitude' => -1.0],
            ['longitude' => -179.0, 'latitude' => -1.0],
            ['longitude' => 179.5, 'latitude' => 1.0],
        ],
        'in_polygon_point_latitude' => null,
        'in_polygon_point_longitude' => null,
    ]);
    $query = portalMapRequestQuery([
        'viewport' => [
            'north' => 10,
            'south' => -10,
            'east' => -170,
            'west' => 170,
            'width' => 800,
            'height' => 600,
        ],
        'zoom' => 12,
    ]);

    $feature = $this->getJson(route('portal.doi.map', $query))
        ->assertOk()
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.geometry.type', 'polygon')
        ->json('features.0');

    expect(abs((float) $feature['position']['lng']))->toBeGreaterThan(170.0)
        ->and($feature['bounds']['west'])->toBeGreaterThan($feature['bounds']['east']);
});

it('clusters an overlapping large box inside the visible viewport', function (): void {
    config(['portal_map.shape_detail_zoom' => 10]);
    $resource = createPublishedPortalMapResource($this->datasetType, 'Large overlapping box');
    GeoLocation::factory()->withBox(0, 100, -10, 10)->create(['resource_id' => $resource->id]);
    $query = portalMapRequestQuery([
        'viewport' => [
            'north' => 5,
            'south' => -5,
            'east' => 95,
            'west' => 90,
            'width' => 800,
            'height' => 600,
        ],
        'zoom' => 4,
    ]);

    $response = $this->getJson(route('portal.doi.map', $query))
        ->assertOk()
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.kind', 'cluster')
        ->assertJsonPath('features.0.position.lat', 0)
        ->assertJsonPath('features.0.bounds.west', 0)
        ->assertJsonPath('features.0.bounds.east', 100)
        ->assertJsonPath('features.0.navigationBounds.west', 92.5)
        ->assertJsonPath('features.0.navigationBounds.east', 92.5);

    $this->assertEqualsWithDelta(92.5, (float) $response->json('features.0.position.lng'), 1.0E-9);
});

it('clusters an overlapping polygon inside the visible viewport even when its centroid is outside', function (): void {
    config(['portal_map.shape_detail_zoom' => 10]);
    $resource = createPublishedPortalMapResource($this->datasetType, 'Large overlapping polygon');
    GeoLocation::factory()->create([
        'resource_id' => $resource->id,
        'geo_type' => 'polygon',
        'polygon_points' => [
            ['longitude' => 0.0, 'latitude' => -10.0],
            ['longitude' => 100.0, 'latitude' => -10.0],
            ['longitude' => 100.0, 'latitude' => 10.0],
            ['longitude' => 0.0, 'latitude' => 10.0],
            ['longitude' => 0.0, 'latitude' => -10.0],
        ],
        'in_polygon_point_latitude' => 0,
        'in_polygon_point_longitude' => 50,
    ]);
    $query = portalMapRequestQuery([
        'viewport' => [
            'north' => 5,
            'south' => -5,
            'east' => 95,
            'west' => 90,
            'width' => 800,
            'height' => 600,
        ],
        'zoom' => 4,
    ]);

    $feature = $this->getJson(route('portal.doi.map', $query))
        ->assertOk()
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.kind', 'cluster')
        ->json('features.0');

    expect((float) $feature['position']['lng'])->toBeGreaterThanOrEqual(90.0)
        ->toBeLessThanOrEqual(95.0)
        ->and((float) $feature['position']['lat'])->toBeGreaterThanOrEqual(-5.0)
        ->toBeLessThanOrEqual(5.0);
});

it('returns the minimum wrapped extent for locations on both sides of the dateline', function (): void {
    $east = createPublishedPortalMapResource($this->datasetType, 'Extent east');
    $west = createPublishedPortalMapResource($this->datasetType, 'Extent west');
    GeoLocation::factory()->withPoint(179, 0)->create(['resource_id' => $east->id]);
    GeoLocation::factory()->withPoint(-179, 1)->create(['resource_id' => $west->id]);
    $query = portalMapRequestQuery([
        'viewport' => [
            'north' => 10,
            'south' => -10,
            'east' => -170,
            'west' => 170,
            'width' => 800,
            'height' => 600,
        ],
        'zoom' => 6,
        'include_extent' => 1,
    ]);

    $this->getJson(route('portal.doi.map', $query))
        ->assertOk()
        ->assertJsonPath('meta.totalLocations', 2)
        ->assertJsonPath('meta.extent.west', 179)
        ->assertJsonPath('meta.extent.east', -179);
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

    $response = $this->getJson(route('portal.doi.map', portalMapRequestQuery()))
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
    $this->getJson(route('portal.doi.map', [
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

it('limits every IGSN metadata filter accepted by the map endpoint', function (): void {
    $tooManyValues = array_map(static fn (int $number): string => "Value {$number}", range(1, 21));

    $this->getJson(route('portal.igsn.map', portalMapRequestQuery([
        'sample_types' => $tooManyValues,
        'materials' => $tooManyValues,
        'classifications' => $tooManyValues,
        'geological_ages' => $tooManyValues,
        'geological_units' => $tooManyValues,
    ])))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'sample_types',
            'materials',
            'classifications',
            'geological_ages',
            'geological_units',
        ]);
});

it('rejects overlong values for every IGSN metadata filter accepted by the map endpoint', function (): void {
    $overlongValue = str_repeat('x', 256);

    $this->getJson(route('portal.igsn.map', portalMapRequestQuery([
        'sample_types' => [$overlongValue],
        'materials' => [$overlongValue],
        'classifications' => [$overlongValue],
        'geological_ages' => [$overlongValue],
        'geological_units' => [$overlongValue],
    ])))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'sample_types.0',
            'materials.0',
            'classifications.0',
            'geological_ages.0',
            'geological_units.0',
        ]);
});

it('ignores invalid IGSN-only filter values on the DOI map endpoint', function (): void {
    $this->getJson(route('portal.doi.map', portalMapRequestQuery([
        'sample_types' => 'not-an-array',
        'materials' => array_fill(0, 21, 'Rock'),
        'classifications' => [str_repeat('x', 256)],
        'geological_ages' => [42],
        'geological_units' => 'not-an-array',
    ])))
        ->assertOk();
});

it('accepts only documented legacy shortcuts for scalar type filters', function (): void {
    foreach (['doi', 'igsn'] as $type) {
        $this->getJson(route('portal.doi.map', portalMapRequestQuery(['type' => $type])))
            ->assertOk();
    }

    $this->getJson(route('portal.doi.map', portalMapRequestQuery(['type' => 'dataset'])))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    $this->getJson(route('portal.doi.map', portalMapRequestQuery(['type' => ['dataset']])))
        ->assertOk();
});

it('can be disabled independently during rollout', function (): void {
    config(['portal_map.enabled' => false]);

    $this->getJson(route('portal.doi.map', portalMapRequestQuery()))
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
        ->getJson(route('portal.doi.map', portalMapRequestQuery()))
        ->assertOk();

    $this->withServerVariables($server)
        ->getJson(route('portal.doi.map', portalMapRequestQuery()))
        ->assertTooManyRequests();
});
