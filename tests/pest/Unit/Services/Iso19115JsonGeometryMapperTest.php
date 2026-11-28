<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Services\Iso19115\Iso19115JsonGeometryMapper;

covers(Iso19115JsonGeometryMapper::class);

test('preserves coordinate order and closes polygon rings', function () {
    $mapper = new Iso19115JsonGeometryMapper;
    expect($mapper->geometry(new GeoLocation(['point_longitude' => 13, 'point_latitude' => 52])))
        ->toBe(['type' => 'Point', 'coordinates' => [13.0, 52.0]]);
    $points = [['longitude' => '13', 'latitude' => '52'], ['longitude' => '14', 'latitude' => '52'], ['longitude' => '14', 'latitude' => '53']];
    expect($mapper->geometry(new GeoLocation(['geo_type' => 'polygon', 'polygon_points' => $points])))
        ->toBe(['type' => 'Polygon', 'coordinates' => [[[13.0, 52.0], [14.0, 52.0], [14.0, 53.0], [13.0, 52.0]]]])
        ->and($mapper->geometry(new GeoLocation(['geo_type' => 'line', 'polygon_points' => $points])))
        ->toBe(['type' => 'LineString', 'coordinates' => [[13.0, 52.0], [14.0, 52.0], [14.0, 53.0]]]);
});

test('splits bounding boxes crossing the antimeridian', function () {
    $mapper = new Iso19115JsonGeometryMapper;
    $location = new GeoLocation(['west_bound_longitude' => 170, 'east_bound_longitude' => -170, 'south_bound_latitude' => -10, 'north_bound_latitude' => 10]);
    $geometry = $mapper->geometry($location);
    expect($geometry['type'])->toBe('MultiPolygon')
        ->and($geometry['coordinates'][0][0])->toBe([[170.0, -10.0], [180.0, -10.0], [180.0, 10.0], [170.0, 10.0], [170.0, -10.0]])
        ->and($geometry['coordinates'][1][0][0])->toBe([-180.0, -10.0]);
    $location->east_bound_longitude = 175;
    expect($mapper->geometry($location)['type'])->toBe('Polygon');
});

test('omits incomplete and invalid legacy geometry without inventing points', function (array $attributes) {
    expect((new Iso19115JsonGeometryMapper)->geometry(new GeoLocation($attributes)))->toBeNull();
})->with([
    'no coordinates' => [[]],
    'partial point' => [['point_longitude' => 13]],
    'invalid longitude' => [['point_longitude' => 190, 'point_latitude' => 52]],
    'invalid latitude' => [['point_longitude' => 13, 'point_latitude' => -95]],
    'inverted latitude' => [['west_bound_longitude' => 10, 'east_bound_longitude' => 20, 'south_bound_latitude' => 60, 'north_bound_latitude' => 50]],
    'invalid box' => [['west_bound_longitude' => -190, 'east_bound_longitude' => 20, 'south_bound_latitude' => 40, 'north_bound_latitude' => 50]],
    'invalid line vertex' => [['geo_type' => 'line', 'polygon_points' => [['longitude' => 1, 'latitude' => 2], ['longitude' => '', 'latitude' => 2]]]],
    'degenerate line' => [['geo_type' => 'line', 'polygon_points' => [['longitude' => 1, 'latitude' => 2], ['longitude' => 1, 'latitude' => 2]]]],
    'degenerate polygon' => [['geo_type' => 'polygon', 'polygon_points' => [['longitude' => 1, 'latitude' => 2], ['longitude' => 1, 'latitude' => 2], ['longitude' => 1, 'latitude' => 2]]]],
]);
