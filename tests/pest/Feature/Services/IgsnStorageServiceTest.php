<?php

declare(strict_types=1);

use App\Models\DateType;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Services\IgsnStorageService;

beforeEach(function () {
    $this->service = app(IgsnStorageService::class);

    // Seed required lookup data
    ResourceType::create(['name' => 'PhysicalObject', 'slug' => 'physical-object']);
    TitleType::create(['name' => 'Main Title', 'slug' => 'MainTitle']);
    DateType::firstOrCreate(['name' => 'Collected', 'slug' => 'Collected']);
});

function minimalParsedRow(array $overrides = []): array
{
    return array_merge([
        'igsn' => '10.60510/ICDP-5054-EY55-0001',
        'title' => 'Core Section 1A-1',
        'name' => 'SAMPLE-001',
        'sample_type' => 'Core',
        'material' => 'Rock',
        'sample_other_names' => [],
        'classification' => [],
        'geological_age' => [],
        'geological_unit' => [],
        '_contributors' => [],
        '_related_identifiers' => [],
        '_funding_references' => [],
        '_creator' => [
            'familyName' => 'Smith',
            'givenName' => 'John',
            'orcid' => null,
            'affiliation' => 'GFZ',
            'ror' => null,
        ],
        '_geo_location' => [
            'latitude' => null,
            'longitude' => null,
            'elevation' => null,
            'elevationUnit' => null,
            'place' => null,
        ],
        '_sizes' => [],
        '_row_number' => 2,
        'depth_min' => '',
        'depth_max' => '',
        'depth_scale' => '',
        'sample_purpose' => '',
        'collection_method' => '',
        'collection_method_description' => '',
        'collection_date_start' => '',
        'collection_date_end' => '',
        'collection_date_precision' => '',
        'cruise_field_program' => '',
        'platform_type' => '',
        'platform_name' => '',
        'platform_description' => '',
        'current_archive' => '',
        'current_archive_contact' => '',
        'sample_access' => '',
        'operator' => '',
        'coordinate_system' => '',
        'user_code' => '',
        'is_private' => '',
        'parent_igsn' => '',
        'description' => '',
    ], $overrides);
}

describe('storeFromCsv', function () {
    test('creates a resource from minimal parsed row', function () {
        $rows = [minimalParsedRow()];

        $result = $this->service->storeFromCsv($rows, 'test.csv');

        expect($result['created'])->toBe(1)
            ->and($result['errors'])->toBeEmpty();

        $resource = Resource::where('doi', '10.60510/ICDP-5054-EY55-0001')->first();
        expect($resource)->not->toBeNull()
            ->and($resource->resourceType->slug)->toBe('physical-object');
    });

    test('creates igsn metadata for resource', function () {
        $rows = [minimalParsedRow([
            'sample_type' => 'Core',
            'material' => 'Rock',
        ])];

        $this->service->storeFromCsv($rows, 'test.csv');

        $resource = Resource::where('doi', '10.60510/ICDP-5054-EY55-0001')->first();
        $metadata = $resource->igsnMetadata;

        expect($metadata)->not->toBeNull()
            ->and($metadata->sample_type)->toBe('Core')
            ->and($metadata->material)->toBe('Rock')
            ->and($metadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED);
    });

    test('creates title for resource', function () {
        $rows = [minimalParsedRow(['title' => 'My IGSN Sample'])];

        $this->service->storeFromCsv($rows, 'test.csv');

        $resource = Resource::where('doi', '10.60510/ICDP-5054-EY55-0001')->first();
        expect($resource->titles)->toHaveCount(1)
            ->and($resource->titles->first()->value)->toBe('My IGSN Sample');
    });

    test('creates creator from parsed row', function () {
        $rows = [minimalParsedRow([
            '_creator' => [
                'familyName' => 'Einstein',
                'givenName' => 'Albert',
                'orcid' => null,
                'affiliation' => 'Princeton',
                'ror' => null,
            ],
        ])];

        $this->service->storeFromCsv($rows, 'test.csv');

        $resource = Resource::where('doi', '10.60510/ICDP-5054-EY55-0001')->first();
        expect($resource->creators)->toHaveCount(1);
    });

    test('stores multiple rows', function () {
        $rows = [
            minimalParsedRow(['igsn' => '10.60510/ICDP-0001', 'title' => 'Sample 1']),
            minimalParsedRow(['igsn' => '10.60510/ICDP-0002', 'title' => 'Sample 2', '_row_number' => 3]),
        ];

        $result = $this->service->storeFromCsv($rows, 'test.csv');

        expect($result['created'])->toBe(2)
            ->and($result['errors'])->toBeEmpty();
        expect(Resource::count())->toBe(2);
    });

    test('records error for duplicate igsn', function () {
        $rows = [minimalParsedRow()];
        $this->service->storeFromCsv($rows, 'first.csv');

        // Try to store same IGSN again
        $result = $this->service->storeFromCsv($rows, 'second.csv');

        expect($result['created'])->toBe(0)
            ->and($result['errors'])->toHaveCount(1);
    });

    test('continues processing after individual row error', function () {
        $rows = [
            minimalParsedRow(['igsn' => '10.60510/ICDP-0001', 'title' => 'Good 1']),
            minimalParsedRow(['igsn' => '10.60510/ICDP-0001', 'title' => 'Duplicate', '_row_number' => 3]),
            minimalParsedRow(['igsn' => '10.60510/ICDP-0003', 'title' => 'Good 2', '_row_number' => 4]),
        ];

        // First store the first row
        $this->service->storeFromCsv([minimalParsedRow(['igsn' => '10.60510/ICDP-0001'])], 'first.csv');

        // Now try all three - row 1 and 2 have same IGSN as existing, row 3 should succeed
        $result = $this->service->storeFromCsv($rows, 'second.csv');

        expect($result['errors'])->not->toBeEmpty();
    });

    test('stores csv filename in igsn metadata', function () {
        $rows = [minimalParsedRow()];

        $this->service->storeFromCsv($rows, 'my-upload.csv');

        $resource = Resource::where('doi', '10.60510/ICDP-5054-EY55-0001')->first();
        expect($resource->igsnMetadata->csv_filename)->toBe('my-upload.csv');
    });

    test('stores user id in resource', function () {
        $user = \App\Models\User::factory()->create();
        $rows = [minimalParsedRow()];

        $this->service->storeFromCsv($rows, 'test.csv', $user->id);

        $resource = Resource::where('doi', '10.60510/ICDP-5054-EY55-0001')->first();
        expect($resource->created_by_user_id)->toBe($user->id);
    });

    test('creates geo location when coordinates provided', function () {
        $rows = [minimalParsedRow([
            '_geo_location' => [
                'latitude' => 52.38,
                'longitude' => 13.06,
                'elevation' => 100.0,
                'elevationUnit' => 'm',
                'place' => 'Potsdam',
            ],
        ])];

        $this->service->storeFromCsv($rows, 'test.csv');

        $resource = Resource::where('doi', '10.60510/ICDP-5054-EY55-0001')->first();
        $geo = $resource->geoLocations->first();

        expect($geo)->not->toBeNull()
            ->and((float) $geo->point_latitude)->toBe(52.38)
            ->and((float) $geo->point_longitude)->toBe(13.06);
    });

    test('creates alternate identifiers from name field', function () {
        $rows = [minimalParsedRow(['name' => 'ACC-12345'])];

        $this->service->storeFromCsv($rows, 'test.csv');

        $resource = Resource::where('doi', '10.60510/ICDP-5054-EY55-0001')->first();
        $altIds = $resource->alternateIdentifiers;

        expect($altIds)->not->toBeEmpty();
    });
});

describe('igsn metadata status', function () {
    test('new igsn has uploaded status', function () {
        $rows = [minimalParsedRow()];

        $this->service->storeFromCsv($rows, 'test.csv');

        $resource = Resource::where('doi', '10.60510/ICDP-5054-EY55-0001')->first();
        expect($resource->igsnMetadata->upload_status)->toBe('uploaded');
    });
});
