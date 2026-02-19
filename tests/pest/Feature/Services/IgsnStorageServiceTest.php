<?php

declare(strict_types=1);

use App\Models\AlternateIdentifier;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\IdentifierType;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\IgsnMetadata;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\Size;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\Entities\AffiliationService;
use App\Services\Entities\PersonService;
use App\Services\IgsnCsvParserService;
use App\Services\IgsnStorageService;

covers(IgsnStorageService::class);

/**
 * Seed required lookup data for IGSN storage.
 */
function seedIgsnLookupData(): void
{
    ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
    );
    TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
    );
    DateType::firstOrCreate(
        ['slug' => 'Collected'],
        ['name' => 'Collected', 'slug' => 'Collected', 'is_active' => true]
    );
}

/**
 * Build minimal valid IGSN row data.
 *
 * @return array<string, mixed>
 */
function buildMinimalIgsnRow(string $igsn = 'IEDE00001', string $title = 'Test Sample'): array
{
    return [
        'igsn' => $igsn,
        'title' => $title,
        'name' => '',
        'sample_type' => 'Rock',
        'material' => 'Granite',
        'is_private' => '0',
        'depth_min' => null,
        'depth_max' => null,
        'depth_scale' => null,
        'sample_purpose' => null,
        'collection_method' => null,
        'collection_method_descr' => null,
        'collection_date_precision' => null,
        'cruise_field_prgrm' => null,
        'platform_type' => null,
        'platform_name' => null,
        'platform_descr' => null,
        'current_archive' => null,
        'current_archive_contact' => null,
        'sampleAccess' => null,
        'operator' => null,
        'coordinate_system' => null,
        'user_code' => null,
        'description' => '',
        'collection_start_date' => '',
        'collection_end_date' => '',
        'collection_date_time_zone' => '',
        'sample_other_names' => [],
        '_creator' => [],
        '_contributors' => [],
        '_geo_location' => [],
        '_related_identifiers' => [],
        '_funding_references' => [],
        '_sizes' => [],
        'classification' => [],
        'geological_age' => [],
        'geological_unit' => [],
        '_row_number' => 1,
    ];
}

describe('IgsnStorageService::storeFromCsv()', function (): void {
    beforeEach(function (): void {
        seedIgsnLookupData();
    });

    it('creates a resource from minimal IGSN data', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00001', 'Granite Sample A');
        $result = $service->storeFromCsv([$row], 'test.csv', null);

        expect($result['created'])->toBe(1);
        expect($result['errors'])->toBeEmpty();

        $resource = Resource::where('doi', 'IEDE00001')->first();
        expect($resource)->not->toBeNull();
        expect($resource->resourceType->slug)->toBe('physical-object');

        // Check title was created
        $title = Title::where('resource_id', $resource->id)->first();
        expect($title)->not->toBeNull();
        expect($title->value)->toBe('Granite Sample A');
    });

    it('creates multiple resources in a single CSV batch', function (): void {
        $service = app(IgsnStorageService::class);

        $rows = [
            buildMinimalIgsnRow('IEDE00001', 'Sample A'),
            buildMinimalIgsnRow('IEDE00002', 'Sample B'),
            buildMinimalIgsnRow('IEDE00003', 'Sample C'),
        ];
        $rows[0]['_row_number'] = 1;
        $rows[1]['_row_number'] = 2;
        $rows[2]['_row_number'] = 3;

        $result = $service->storeFromCsv($rows, 'batch.csv', null);

        expect($result['created'])->toBe(3);
        expect($result['errors'])->toBeEmpty();
        expect(Resource::where('doi', 'LIKE', 'IEDE%')->count())->toBe(3);
    });

    it('captures errors for individual rows without failing the batch', function (): void {
        $service = app(IgsnStorageService::class);

        // First row valid
        $row1 = buildMinimalIgsnRow('IEDE00001', 'Sample A');
        $row1['_row_number'] = 1;

        // Second row has same IGSN → should cause unique constraint error
        $row2 = buildMinimalIgsnRow('IEDE00001', 'Duplicate');
        $row2['_row_number'] = 2;

        $result = $service->storeFromCsv([$row1, $row2], 'test.csv');

        expect($result['created'])->toBe(1);
        expect($result['errors'])->toHaveCount(1);
        expect($result['errors'][0]['row'])->toBe(2);
        expect($result['errors'][0]['igsn'])->toBe('IEDE00001');
    });

    it('associates user ID with created resource', function (): void {
        $user = \App\Models\User::factory()->create();
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00099');
        $result = $service->storeFromCsv([$row], 'test.csv', $user->id);

        $resource = Resource::where('doi', 'IEDE00099')->first();
        expect($resource->created_by_user_id)->toBe($user->id);
    });
});

describe('IGSN metadata creation', function (): void {
    beforeEach(function (): void {
        seedIgsnLookupData();
    });

    it('creates IGSN metadata with all fields', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00010');
        $row['sample_type'] = 'Rock';
        $row['material'] = 'Granite';
        $row['is_private'] = '1';
        $row['depth_min'] = '10.5';
        $row['depth_max'] = '25.3';
        $row['depth_scale'] = 'meter';
        $row['sample_purpose'] = 'Research';
        $row['collection_method'] = 'Drilling';
        $row['collection_method_descr'] = 'Diamond drill core';
        $row['cruise_field_prgrm'] = 'IODP Expedition 302';
        $row['platform_type'] = 'Ship';
        $row['platform_name'] = 'Vidar Viking';
        $row['platform_descr'] = 'Research vessel';
        $row['current_archive'] = 'GFZ Core Repository';
        $row['current_archive_contact'] = 'core@gfz.de';
        $row['sampleAccess'] = 'Public';
        $row['operator'] = 'GFZ Potsdam';
        $row['coordinate_system'] = 'WGS84';
        $row['user_code'] = 'IEDE';

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00010')->first();
        $metadata = IgsnMetadata::where('resource_id', $resource->id)->first();

        expect($metadata)->not->toBeNull();
        expect($metadata->sample_type)->toBe('Rock');
        expect($metadata->material)->toBe('Granite');
        expect($metadata->is_private)->toBeTrue();
        expect((float) $metadata->depth_min)->toBe(10.5);
        expect((float) $metadata->depth_max)->toBe(25.3);
        expect($metadata->depth_scale)->toBe('meter');
        expect($metadata->collection_method)->toBe('Drilling');
        expect($metadata->cruise_field_program)->toBe('IODP Expedition 302');
        expect($metadata->platform_type)->toBe('Ship');
        expect($metadata->current_archive)->toBe('GFZ Core Repository');
        expect($metadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED);
        expect($metadata->csv_filename)->toBe('test.csv');
    });
});

describe('IGSN alternate identifiers', function (): void {
    beforeEach(function (): void {
        seedIgsnLookupData();
    });

    it('creates alternate identifier from name field', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00020');
        $row['name'] = 'LAB-2024-001';

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00020')->first();
        $altIDs = AlternateIdentifier::where('resource_id', $resource->id)->get();

        expect($altIDs)->toHaveCount(1);
        expect($altIDs->first()->value)->toBe('LAB-2024-001');
        expect($altIDs->first()->type)->toBe('Local accession number');
    });

    it('creates alternate identifiers from sample_other_names', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00021');
        $row['name'] = 'LAB-001';
        $row['sample_other_names'] = ['ALT-A', 'ALT-B'];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00021')->first();
        $altIDs = AlternateIdentifier::where('resource_id', $resource->id)
            ->orderBy('position')
            ->get();

        expect($altIDs)->toHaveCount(3);
        expect($altIDs[0]->type)->toBe('Local accession number');
        expect($altIDs[1]->type)->toBe('Local sample name');
        expect($altIDs[1]->value)->toBe('ALT-A');
        expect($altIDs[2]->value)->toBe('ALT-B');
    });
});

describe('IGSN creator creation', function (): void {
    beforeEach(function (): void {
        seedIgsnLookupData();
    });

    it('creates a creator from collector data', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00030');
        $row['_creator'] = [
            'familyName' => 'Müller',
            'givenName' => 'Hans',
            'orcid' => null,
            'affiliation' => null,
            'ror' => null,
        ];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00030')->first();
        $creator = ResourceCreator::where('resource_id', $resource->id)->first();

        expect($creator)->not->toBeNull();
        expect($creator->creatorable_type)->toBe(Person::class);

        $person = Person::find($creator->creatorable_id);
        expect($person->family_name)->toBe('Müller');
        expect($person->given_name)->toBe('Hans');
    });

    it('skips creator when no name is provided', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00031');
        $row['_creator'] = [
            'familyName' => '',
            'givenName' => '',
        ];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00031')->first();
        $creators = ResourceCreator::where('resource_id', $resource->id)->get();

        expect($creators)->toBeEmpty();
    });
});

describe('IGSN geo location creation', function (): void {
    beforeEach(function (): void {
        seedIgsnLookupData();
    });

    it('creates geo location with coordinates and place', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00040');
        $row['_geo_location'] = [
            'latitude' => '52.3938',
            'longitude' => '13.0650',
            'elevation' => '105.2',
            'elevationUnit' => 'm',
            'place' => 'Potsdam, Germany',
        ];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00040')->first();
        $geo = GeoLocation::where('resource_id', $resource->id)->first();

        expect($geo)->not->toBeNull();
        expect((float) $geo->point_latitude)->toBe(52.3938);
        expect((float) $geo->point_longitude)->toBe(13.065);
        expect($geo->place)->toBe('Potsdam, Germany');
    });

    it('skips geo location when no data provided', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00041');
        $row['_geo_location'] = [];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00041')->first();
        $geoCount = GeoLocation::where('resource_id', $resource->id)->count();

        expect($geoCount)->toBe(0);
    });
});

describe('IGSN collection date creation', function (): void {
    beforeEach(function (): void {
        seedIgsnLookupData();
    });

    it('creates collection date from start date', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00050');
        $row['collection_start_date'] = '2024-06-15';
        $row['collection_end_date'] = '';

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00050')->first();
        $date = ResourceDate::where('resource_id', $resource->id)->first();

        expect($date)->not->toBeNull();
        expect($date->start_date)->toBe('2024-06-15');
    });

    it('extracts publication year from collection start date', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00051');
        $row['collection_start_date'] = '2023-03-20';

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00051')->first();
        expect($resource->publication_year)->toBe(2023);
    });

    it('sets null publication year when no date', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00052');
        $row['collection_start_date'] = '';

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00052')->first();
        expect($resource->publication_year)->toBeNull();
    });
});

describe('IGSN size creation', function (): void {
    beforeEach(function (): void {
        seedIgsnLookupData();
    });

    it('creates size entries from parsed data', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00060');
        $row['_sizes'] = [
            ['numeric_value' => '15.5', 'unit' => 'cm', 'type' => 'length'],
            ['numeric_value' => '3.2', 'unit' => 'kg', 'type' => 'weight'],
        ];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00060')->first();
        $sizes = Size::where('resource_id', $resource->id)->get();

        expect($sizes)->toHaveCount(2);
    });

    it('skips empty size entries', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00061');
        $row['_sizes'] = [[], null];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00061')->first();
        $sizes = Size::where('resource_id', $resource->id)->get();

        expect($sizes)->toBeEmpty();
    });
});

describe('IGSN relations (classifications, geological data)', function (): void {
    beforeEach(function (): void {
        seedIgsnLookupData();
    });

    it('creates classifications', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00070');
        $row['classification'] = ['Igneous', 'Volcanic'];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00070')->first();
        $classifications = IgsnClassification::where('resource_id', $resource->id)->get();

        expect($classifications)->toHaveCount(2);
        expect($classifications->pluck('value')->toArray())->toBe(['Igneous', 'Volcanic']);
    });

    it('creates geological ages', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00071');
        $row['geological_age'] = ['Cretaceous', 'Jurassic'];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00071')->first();
        $ages = IgsnGeologicalAge::where('resource_id', $resource->id)->get();

        expect($ages)->toHaveCount(2);
    });

    it('creates geological units', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00072');
        $row['geological_unit'] = ['Formation A', 'Member B'];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00072')->first();
        $units = IgsnGeologicalUnit::where('resource_id', $resource->id)->get();

        expect($units)->toHaveCount(2);
    });

    it('skips empty classification values', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00073');
        $row['classification'] = ['Igneous', '', 'Volcanic'];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00073')->first();
        $classifications = IgsnClassification::where('resource_id', $resource->id)->get();

        expect($classifications)->toHaveCount(2);
    });
});

describe('IGSN contributor creation', function (): void {
    beforeEach(function (): void {
        seedIgsnLookupData();
        ContributorType::firstOrCreate(
            ['slug' => 'Other'],
            ['name' => 'Other', 'slug' => 'Other', 'is_active' => true]
        );
    });

    it('creates contributors from parsed data', function (): void {
        $service = app(IgsnStorageService::class);
        ContributorType::firstOrCreate(
            ['slug' => 'ContactPerson'],
            ['name' => 'Contact Person', 'slug' => 'ContactPerson', 'is_active' => true]
        );

        $row = buildMinimalIgsnRow('IEDE00080');
        $row['_contributors'] = [
            [
                'name' => 'Schmidt, Maria',
                'type' => 'ContactPerson',
                'identifier' => null,
                'identifierType' => null,
            ],
        ];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00080')->first();
        $contributors = ResourceContributor::where('resource_id', $resource->id)->get();

        expect($contributors)->toHaveCount(1);

        $person = Person::find($contributors->first()->contributorable_id);
        expect($person->family_name)->toBe('Schmidt');
        expect($person->given_name)->toBe('Maria');
    });

    it('parses contributor name in "GivenName FamilyName" format', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00081');
        $row['_contributors'] = [
            [
                'name' => 'Maria Schmidt',
                'type' => 'Other',
                'identifier' => null,
                'identifierType' => null,
            ],
        ];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00081')->first();
        $contributor = ResourceContributor::where('resource_id', $resource->id)->first();
        $person = Person::find($contributor->contributorable_id);

        expect($person->family_name)->toBe('Schmidt');
        expect($person->given_name)->toBe('Maria');
    });

    it('handles single-word contributor name', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00082');
        $row['_contributors'] = [
            [
                'name' => 'Administrator',
                'type' => 'Other',
                'identifier' => null,
                'identifierType' => null,
            ],
        ];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00082')->first();
        $contributor = ResourceContributor::where('resource_id', $resource->id)->first();
        $person = Person::find($contributor->contributorable_id);

        expect($person->family_name)->toBe('Administrator');
    });
});

describe('IGSN related identifiers', function (): void {
    beforeEach(function (): void {
        seedIgsnLookupData();
        IdentifierType::firstOrCreate(
            ['slug' => 'DOI'],
            ['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true]
        );
        RelationType::firstOrCreate(
            ['slug' => 'IsPartOf'],
            ['name' => 'Is Part Of', 'slug' => 'IsPartOf', 'is_active' => true]
        );
    });

    it('creates related identifiers', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00090');
        $row['_related_identifiers'] = [
            [
                'identifier' => '10.5880/GFZ.2024.001',
                'type' => 'DOI',
                'relationType' => 'IsPartOf',
            ],
        ];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00090')->first();
        $relIds = RelatedIdentifier::where('resource_id', $resource->id)->get();

        expect($relIds)->toHaveCount(1);
        expect($relIds->first()->identifier)->toBe('10.5880/GFZ.2024.001');
    });

    it('skips related identifiers with unknown types', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00091');
        $row['_related_identifiers'] = [
            [
                'identifier' => 'some-id',
                'type' => 'UnknownType',
                'relationType' => 'UnknownRelation',
            ],
        ];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00091')->first();
        $relIds = RelatedIdentifier::where('resource_id', $resource->id)->get();

        expect($relIds)->toBeEmpty();
    });
});

describe('IGSN funding references', function (): void {
    beforeEach(function (): void {
        seedIgsnLookupData();
    });

    it('creates funding references', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00100');
        $row['_funding_references'] = [
            [
                'name' => 'Deutsche Forschungsgemeinschaft',
                'identifier' => 'https://doi.org/10.13039/501100001659',
                'identifierType' => null,
            ],
        ];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00100')->first();
        $fundings = FundingReference::where('resource_id', $resource->id)->get();

        expect($fundings)->toHaveCount(1);
        expect($fundings->first()->funder_name)->toBe('Deutsche Forschungsgemeinschaft');
    });

    it('skips funding references with empty name', function (): void {
        $service = app(IgsnStorageService::class);

        $row = buildMinimalIgsnRow('IEDE00101');
        $row['_funding_references'] = [
            ['name' => '', 'identifier' => null, 'identifierType' => null],
        ];

        $service->storeFromCsv([$row], 'test.csv');

        $resource = Resource::where('doi', 'IEDE00101')->first();
        $fundings = FundingReference::where('resource_id', $resource->id)->get();

        expect($fundings)->toBeEmpty();
    });
});
