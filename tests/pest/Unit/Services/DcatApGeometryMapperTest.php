<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Services\DcatAp\DcatApGeometryMapper;
use App\Services\Iso19115\Iso19115JsonGeometryMapper;

it('preserves CRS84 longitude-latitude order and zero coordinates', function () {
    $mapper = new DcatApGeometryMapper(new Iso19115JsonGeometryMapper);
    expect($mapper->map(new GeoLocation(['point_longitude' => 0, 'point_latitude' => 0])))
        ->toBe(['wkt' => '<http://www.opengis.net/def/crs/OGC/1.3/CRS84> POINT (0 0)', 'property' => 'dcat:centroid']);
});

it('renders valid polygons, lines and antimeridian boxes as WKT', function () {
    $mapper = new DcatApGeometryMapper(new Iso19115JsonGeometryMapper);
    $points = [['longitude' => 13, 'latitude' => 52], ['longitude' => 14, 'latitude' => 52], ['longitude' => 14, 'latitude' => 53]];
    expect($mapper->map(new GeoLocation(['geo_type' => 'polygon', 'polygon_points' => $points]))['wkt'])->toEndWith('POLYGON ((13 52, 14 52, 14 53, 13 52))')
        ->and($mapper->map(new GeoLocation(['geo_type' => 'line', 'polygon_points' => $points]))['wkt'])->toEndWith('LINESTRING (13 52, 14 52, 14 53)');
    $box = new GeoLocation(['west_bound_longitude' => 170, 'east_bound_longitude' => -170, 'south_bound_latitude' => -10, 'north_bound_latitude' => 10]);
    expect($mapper->map($box)['wkt'])->toEndWith('MULTIPOLYGON (((170 -10, 180 -10, 180 10, 170 10, 170 -10)), ((-180 -10, -170 -10, -170 10, -180 10, -180 -10)))')
        ->and($mapper->map($box)['property'])->toBe('dcat:bbox');
});

it('omits invalid or incomplete geometry', function (array $attributes) {
    expect((new DcatApGeometryMapper(new Iso19115JsonGeometryMapper))->map(new GeoLocation($attributes)))->toBeNull();
})->with([
    [[]], [['point_longitude' => 190, 'point_latitude' => 0]], [['point_longitude' => 0]],
    [['geo_type' => 'line', 'polygon_points' => [['longitude' => 1, 'latitude' => 1]]]],
    [['west_bound_longitude' => 1, 'east_bound_longitude' => 2, 'south_bound_latitude' => 5, 'north_bound_latitude' => 4]],
]);
