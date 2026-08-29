<?php

declare(strict_types=1);

use App\Services\PortalMapClusterService;

covers(PortalMapClusterService::class);

function portalMapClusterLocation(int $id, float $latitude, float $longitude, string $type = 'point', string $slug = 'dataset'): array
{
    return [
        'location_id' => $id,
        'resource_type_slug' => $slug,
        'geometry_type' => $type,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'bounds' => [
            'north' => $latitude,
            'south' => $latitude,
            'east' => $longitude,
            'west' => $longitude,
        ],
    ];
}

function portalMapClusterViewport(array $overrides = []): array
{
    return [
        'north' => 53.0,
        'south' => 52.0,
        'east' => 14.0,
        'west' => 13.0,
        'width' => 1000,
        'height' => 2000,
        ...$overrides,
    ];
}

beforeEach(function (): void {
    config([
        'portal_map.max_features' => 1000,
        'portal_map.cluster_radius' => 60,
        'portal_map.shape_detail_zoom' => 10,
    ]);
});

it('returns an isolated point as a resource candidate', function (): void {
    $result = (new PortalMapClusterService)->cluster(
        [portalMapClusterLocation(7, 52.5, 13.4)],
        portalMapClusterViewport(),
        12,
    );

    expect($result['features'])->toHaveCount(1)
        ->and($result['features'][0]['kind'])->toBe('resource-candidate')
        ->and($result['features'][0]['locationId'])->toBe(7)
        ->and($result['meta']['visibleLocations'])->toBe(1)
        ->and($result['meta']['coarsened'])->toBeFalse();
});

it('aggregates colocated resources and preserves resource type counts', function (): void {
    $result = (new PortalMapClusterService)->cluster([
        portalMapClusterLocation(1, 52.5, 13.4, slug: 'dataset'),
        portalMapClusterLocation(2, 52.5001, 13.4001, slug: 'physical-object'),
        portalMapClusterLocation(3, 52.5002, 13.4002, slug: 'dataset'),
    ], portalMapClusterViewport(), 12);

    expect($result['features'])->toHaveCount(1)
        ->and($result['features'][0]['kind'])->toBe('cluster')
        ->and($result['features'][0]['count'])->toBe(3)
        ->and($result['features'][0]['resourceTypeCounts'])->toBe([
            'dataset' => 2,
            'physical-object' => 1,
        ]);
});

it('keeps shapes clustered until the configured detail zoom', function (): void {
    $service = new PortalMapClusterService;
    $shape = portalMapClusterLocation(1, 52.5, 13.4, 'polygon');

    $overview = $service->cluster([$shape], portalMapClusterViewport(), 9);
    $detail = $service->cluster([$shape], portalMapClusterViewport(), 10);

    expect($overview['features'][0]['kind'])->toBe('cluster')
        ->and($detail['features'][0]['kind'])->toBe('resource-candidate');
});

it('coarsens impossible client zooms and never exceeds the configured response bound', function (): void {
    config(['portal_map.max_features' => 5]);

    $locations = [];
    for ($index = 0; $index < 200; $index++) {
        $locations[] = portalMapClusterLocation(
            $index + 1,
            50.0 + (($index % 20) * 0.2),
            10.0 + ((int) floor($index / 20) * 0.5),
        );
    }

    $result = (new PortalMapClusterService)->cluster($locations, portalMapClusterViewport(), 18);
    $sum = array_sum(array_map(
        fn (array $feature): int => $feature['kind'] === 'cluster' ? $feature['count'] : 1,
        $result['features'],
    ));

    expect(count($result['features']))->toBeLessThanOrEqual(5)
        ->and($result['meta']['coarsened'])->toBeTrue()
        ->and($sum)->toBe(200);
});

it('handles a viewport that crosses the antimeridian', function (): void {
    $result = (new PortalMapClusterService)->cluster([
        portalMapClusterLocation(1, 0, 179.5),
        portalMapClusterLocation(2, 0, -179.5),
    ], portalMapClusterViewport([
        'north' => 10.0,
        'south' => -10.0,
        'west' => 170.0,
        'east' => -170.0,
    ]), 4);

    expect($result['meta']['visibleLocations'])->toBe(2)
        ->and($result['features'])->not->toBeEmpty();
});

it('uses a circular longitude mean for antimeridian clusters', function (): void {
    config([
        'portal_map.max_features' => 1,
        'portal_map.cluster_radius' => 20,
    ]);

    $result = (new PortalMapClusterService)->cluster([
        portalMapClusterLocation(1, 0, 179.0),
        portalMapClusterLocation(2, 0, -179.0),
    ], portalMapClusterViewport([
        'north' => 85.0,
        'south' => -85.0,
        'west' => -180.0,
        'east' => 180.0,
        'width' => 800,
        'height' => 600,
    ]), 0);

    expect($result['features'])->toHaveCount(1)
        ->and($result['features'][0]['kind'])->toBe('cluster')
        ->and(abs((float) $result['features'][0]['position']['lng']))->toBeGreaterThan(170.0);
});

it('continues terminal aggregation at zoom zero until the feature bound is met', function (): void {
    config([
        'portal_map.max_features' => 100,
        'portal_map.cluster_radius' => 20,
    ]);

    $locations = [];
    $id = 1;
    for ($cellY = 0; $cellY < 13; $cellY++) {
        $pixelY = ($cellY * 20) + 10;
        $latitude = rad2deg(atan(sinh(M_PI - ((2 * M_PI * $pixelY) / 256))));

        for ($cellX = 0; $cellX < 13; $cellX++) {
            $pixelX = ($cellX * 20) + 10;
            $longitude = (($pixelX / 256) * 360) - 180;
            $locations[] = portalMapClusterLocation($id++, $latitude, $longitude);
        }
    }

    $result = (new PortalMapClusterService)->cluster($locations, portalMapClusterViewport([
        'north' => 85.0,
        'south' => -85.0,
        'west' => -180.0,
        'east' => 180.0,
        'width' => 800,
        'height' => 600,
    ]), 0);
    $representedLocations = array_sum(array_map(
        fn (array $feature): int => $feature['kind'] === 'cluster' ? $feature['count'] : 1,
        $result['features'],
    ));

    expect($result['features'])->toHaveCount(49)
        ->and($result['meta']['effectiveZoom'])->toBe(0)
        ->and($result['meta']['coarsened'])->toBeTrue()
        ->and($representedLocations)->toBe(169);
});

it('keeps a forty-thousand-location world view bounded without retaining resource models', function (): void {
    $locations = (function (): Generator {
        for ($index = 0; $index < 40_000; $index++) {
            yield portalMapClusterLocation(
                $index + 1,
                -80.0 + (($index % 800) * 0.2),
                -179.0 + (($index % 1790) * 0.2),
                slug: $index % 5 === 0 ? 'physical-object' : 'dataset',
            );
        }
    })();

    $result = (new PortalMapClusterService)->cluster($locations, portalMapClusterViewport([
        'north' => 85.0,
        'south' => -85.0,
        'east' => 180.0,
        'west' => -180.0,
        'width' => 1200,
        'height' => 800,
    ]), 2);
    $sum = array_sum(array_map(
        fn (array $feature): int => $feature['kind'] === 'cluster' ? $feature['count'] : 1,
        $result['features'],
    ));

    expect($result['meta']['visibleLocations'])->toBe(40_000)
        ->and(count($result['features']))->toBeLessThanOrEqual(1000)
        ->and($sum)->toBe(40_000);
});
