<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;

beforeEach(function () {
    $resourceType = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $language = Language::create([
        'code' => 'en',
        'name' => 'English',
    ]);

    $this->resource = Resource::create([
        'doi' => '10.5880/TEST.LINE.001',
        'publication_year' => 2026,
        'version' => '1.0',
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
    ]);
});

describe('Line GeoLocation Model', function () {
    it('can create a line geo location', function () {
        $linePoints = [
            ['longitude' => 13.0, 'latitude' => 52.0],
            ['longitude' => 13.5, 'latitude' => 52.5],
            ['longitude' => 14.0, 'latitude' => 53.0],
        ];

        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'geo_type' => 'line',
            'polygon_points' => $linePoints,
        ]);

        $fresh = GeoLocation::find($geoLocation->id);

        expect($fresh->hasLine())->toBeTrue()
            ->and($fresh->hasPolygon())->toBeFalse()
            ->and($fresh->hasPoint())->toBeFalse()
            ->and($fresh->hasBox())->toBeFalse()
            ->and($fresh->polygon_points)->toHaveCount(3);
    });

    it('requires at least 2 points for hasLine', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'geo_type' => 'line',
            'polygon_points' => [
                ['longitude' => 13.0, 'latitude' => 52.0],
            ],
        ]);

        expect($geoLocation->hasLine())->toBeFalse();
    });

    it('returns false for hasLine when geo_type is polygon', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'geo_type' => 'polygon',
            'polygon_points' => [
                ['longitude' => 13.0, 'latitude' => 52.0],
                ['longitude' => 14.0, 'latitude' => 52.0],
                ['longitude' => 14.0, 'latitude' => 53.0],
                ['longitude' => 13.0, 'latitude' => 52.0],
            ],
        ]);

        expect($geoLocation->hasLine())->toBeFalse()
            ->and($geoLocation->hasPolygon())->toBeTrue();
    });

    it('creates a valid line via factory', function () {
        $geoLocation = GeoLocation::factory()->withLine()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($geoLocation->hasLine())->toBeTrue()
            ->and($geoLocation->geo_type)->toBe('line')
            ->and($geoLocation->polygon_points)->not->toBeNull();
    });
});

describe('Line to Polygon Conversion', function () {
    it('converts a 2-point line to a valid closed polygon', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'geo_type' => 'line',
            'polygon_points' => [
                ['longitude' => 13.0, 'latitude' => 52.0],
                ['longitude' => 14.0, 'latitude' => 53.0],
            ],
        ]);

        // Use the JSON exporter to test the conversion (export returns array)
        $jsonExporter = app(DataCiteJsonExporter::class);
        $data = $jsonExporter->export($this->resource->fresh());

        $polygon = $data['data']['attributes']['geoLocations'][0]['geoLocationPolygon'] ?? null;

        // A→B→A'→A = 4 points
        expect($polygon)->not->toBeNull()
            ->and($polygon['polygonPoints'])->toHaveCount(4)
            // First point = start
            ->and($polygon['polygonPoints'][0]['pointLongitude'])->toBe(13.0)
            ->and($polygon['polygonPoints'][0]['pointLatitude'])->toBe(52.0)
            // Second point = end of line
            ->and($polygon['polygonPoints'][1]['pointLongitude'])->toBe(14.0)
            ->and($polygon['polygonPoints'][1]['pointLatitude'])->toBe(53.0)
            // Third point = return with offset
            ->and($polygon['polygonPoints'][2]['pointLongitude'])->toBe(13.0)
            ->and(abs($polygon['polygonPoints'][2]['pointLatitude'] - 52.00000001))->toBeLessThan(0.0000001)
            // Last point = close = first point
            ->and($polygon['polygonPoints'][3]['pointLongitude'])->toBe(13.0)
            ->and($polygon['polygonPoints'][3]['pointLatitude'])->toBe(52.0);
    });

    it('converts a 3-point line to a valid closed polygon', function () {
        GeoLocation::create([
            'resource_id' => $this->resource->id,
            'geo_type' => 'line',
            'polygon_points' => [
                ['longitude' => 13.0, 'latitude' => 52.0],
                ['longitude' => 13.5, 'latitude' => 52.5],
                ['longitude' => 14.0, 'latitude' => 53.0],
            ],
        ]);

        $jsonExporter = app(DataCiteJsonExporter::class);
        $data = $jsonExporter->export($this->resource->fresh());

        $polygon = $data['data']['attributes']['geoLocations'][0]['geoLocationPolygon'] ?? null;

        // A→B→C→B'→A'→A = 6 points
        expect($polygon)->not->toBeNull()
            ->and($polygon['polygonPoints'])->toHaveCount(6)
            // Forward: A
            ->and($polygon['polygonPoints'][0]['pointLongitude'])->toBe(13.0)
            // Forward: B
            ->and($polygon['polygonPoints'][1]['pointLongitude'])->toBe(13.5)
            // Forward: C
            ->and($polygon['polygonPoints'][2]['pointLongitude'])->toBe(14.0)
            // Return: B' (offset)
            ->and($polygon['polygonPoints'][3]['pointLongitude'])->toBe(13.5)
            ->and(abs($polygon['polygonPoints'][3]['pointLatitude'] - 52.50000001))->toBeLessThan(0.0000001)
            // Return: A' (offset)
            ->and($polygon['polygonPoints'][4]['pointLongitude'])->toBe(13.0)
            ->and(abs($polygon['polygonPoints'][4]['pointLatitude'] - 52.00000001))->toBeLessThan(0.0000001)
            // Close: A
            ->and($polygon['polygonPoints'][5]['pointLongitude'])->toBe(13.0)
            ->and($polygon['polygonPoints'][5]['pointLatitude'])->toBe(52.0);
    });

    it('exports line as polygon in XML', function () {
        GeoLocation::create([
            'resource_id' => $this->resource->id,
            'geo_type' => 'line',
            'polygon_points' => [
                ['longitude' => 13.0, 'latitude' => 52.0],
                ['longitude' => 14.0, 'latitude' => 53.0],
            ],
        ]);

        $xmlExporter = app(DataCiteXmlExporter::class);
        $xml = $xmlExporter->export($this->resource->fresh());

        // Should contain geoLocationPolygon (not a custom line element)
        expect($xml)->toContain('<geoLocationPolygon>')
            ->and($xml)->toContain('<polygonPoint>')
            ->and($xml)->toContain('13')
            ->and($xml)->toContain('52');
    });

    it('does not export line as polygon when hasPolygon check runs', function () {
        // A line with geo_type='line' should NOT be treated as polygon
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'geo_type' => 'line',
            'polygon_points' => [
                ['longitude' => 13.0, 'latitude' => 52.0],
                ['longitude' => 14.0, 'latitude' => 53.0],
            ],
        ]);

        expect($geoLocation->hasPolygon())->toBeFalse()
            ->and($geoLocation->hasLine())->toBeTrue();
    });
});

describe('Geo Type Column', function () {
    it('stores geo_type for points', function () {
        $geoLocation = GeoLocation::factory()->withPoint()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($geoLocation->geo_type)->toBe('point');
    });

    it('stores geo_type for boxes', function () {
        $geoLocation = GeoLocation::factory()->withBox()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($geoLocation->geo_type)->toBe('box');
    });

    it('stores geo_type for polygons', function () {
        $geoLocation = GeoLocation::factory()->withPolygon()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($geoLocation->geo_type)->toBe('polygon');
    });

    it('stores geo_type for lines', function () {
        $geoLocation = GeoLocation::factory()->withLine()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($geoLocation->geo_type)->toBe('line');
    });
});
