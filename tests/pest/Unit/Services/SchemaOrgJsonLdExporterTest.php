<?php

declare(strict_types=1);

use App\Models\DescriptionType;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\TitleType;
use App\Services\SchemaOrgJsonLdExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DateTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DescriptionTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ContributorTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'IdentifierTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'RelationTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'LanguageSeeder']);
    $this->artisan('db:seed', ['--class' => 'PublisherSeeder']);

    $this->exporter = new SchemaOrgJsonLdExporter;
});

covers(SchemaOrgJsonLdExporter::class);

describe('export basics', function () {
    it('includes @context as https://schema.org/', function () {
        $resource = createSchemaOrgResource();

        $result = $this->exporter->export($resource);

        expect($result['@context'])->toBe('https://schema.org/');
    });

    it('includes @type as Dataset', function () {
        $resource = createSchemaOrgResource();

        $result = $this->exporter->export($resource);

        expect($result['@type'])->toBe('Dataset');
    });

    it('includes isAccessibleForFree as true', function () {
        $resource = createSchemaOrgResource();

        $result = $this->exporter->export($resource);

        expect($result['isAccessibleForFree'])->toBeTrue();
    });

    it('includes @id and url from DOI', function () {
        $resource = createSchemaOrgResource('10.5880/test.2025.001');

        $result = $this->exporter->export($resource);

        expect($result['@id'])->toBe('https://doi.org/10.5880/test.2025.001');
        expect($result['url'])->toBe('https://doi.org/10.5880/test.2025.001');
    });

    it('omits @id and url when DOI is null', function () {
        $resource = createSchemaOrgResource(null);

        $result = $this->exporter->export($resource);

        expect($result)->not->toHaveKey('@id');
        expect($result)->not->toHaveKey('url');
        expect($result)->not->toHaveKey('subjectOf');
    });

    it('includes name from main title', function () {
        $resource = createSchemaOrgResource();

        $result = $this->exporter->export($resource);

        expect($result['name'])->toBe('Schema.org Test Title');
    });

    it('includes version when set', function () {
        $resource = createSchemaOrgResource();

        $result = $this->exporter->export($resource);

        expect($result['version'])->toBe('1.0');
    });

    it('includes subjectOf with DataDownload cross-links', function () {
        $resource = createSchemaOrgResource();

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('subjectOf');
        expect($result['subjectOf'])->toHaveCount(2);
        expect($result['subjectOf'][0]['@type'])->toBe('DataDownload');
        expect($result['subjectOf'][0]['encodingFormat'])->toBe('application/vnd.datacite.datacite+xml');
        expect($result['subjectOf'][0]['contentUrl'])->toContain('data.datacite.org');
        expect($result['subjectOf'][1]['encodingFormat'])->toBe('application/vnd.datacite.datacite+json');
        expect($result['subjectOf'][1]['contentUrl'])->toContain('data.datacite.org');
    });
});

describe('DOI identifier', function () {
    it('builds PropertyValue identifier with identifiers.org propertyID', function () {
        $resource = createSchemaOrgResource('10.5880/test.2025.001');

        $result = $this->exporter->export($resource);

        expect($result['identifier'])->toBeArray();
        expect($result['identifier']['@type'])->toBe('PropertyValue');
        expect($result['identifier']['propertyID'])->toBe('https://registry.identifiers.org/registry/doi');
        expect($result['identifier']['value'])->toBe('doi:10.5880/test.2025.001');
        expect($result['identifier']['url'])->toBe('https://doi.org/10.5880/test.2025.001');
    });
});

describe('creators', function () {
    it('transforms personal creator as Person with @list', function () {
        $resource = createSchemaOrgResource();

        $person = Person::factory()->create([
            'family_name' => 'Doe',
            'given_name' => 'John',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('creator');
        expect($result['creator'])->toHaveKey('@list');
        expect($result['creator']['@list'])->toHaveCount(1);

        $creator = $result['creator']['@list'][0];
        expect($creator['@type'])->toBe('Person');
        expect($creator['name'])->toBe('Doe, John');
        expect($creator['givenName'])->toBe('John');
        expect($creator['familyName'])->toBe('Doe');
    });

    it('transforms creator with ORCID as PropertyValue identifier', function () {
        $resource = createSchemaOrgResource();

        $person = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'Jane',
            'name_identifier' => 'https://orcid.org/0000-0001-2345-6789',
            'name_identifier_scheme' => 'ORCID',
            'scheme_uri' => 'https://orcid.org/',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource->fresh());

        $creator = $result['creator']['@list'][0];
        expect($creator)->toHaveKey('@id');
        expect($creator['@id'])->toBe('https://orcid.org/0000-0001-2345-6789');
        expect($creator)->toHaveKey('identifier');
        expect($creator['identifier']['@type'])->toBe('PropertyValue');
        expect($creator['identifier']['propertyID'])->toBe('https://registry.identifiers.org/registry/orcid');
    });
});

describe('publisher', function () {
    it('transforms publisher as Organization', function () {
        $resource = createSchemaOrgResource();

        $result = $this->exporter->export($resource);

        expect($result['publisher'])->toHaveKey('@type');
        expect($result['publisher']['@type'])->toBe('Organization');
        expect($result['publisher']['name'])->toBe('GFZ Data Services');
    });
});

describe('descriptions', function () {
    it('extracts abstract as description', function () {
        $resource = createSchemaOrgResource();

        $abstractType = DescriptionType::where('slug', 'Abstract')->first();
        $resource->descriptions()->create([
            'value' => 'A test abstract for Schema.org',
            'description_type_id' => $abstractType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('description');
        expect($result['description'])->toBe('A test abstract for Schema.org');
    });

    it('omits description when no abstract exists', function () {
        $resource = createSchemaOrgResource();

        $result = $this->exporter->export($resource);

        expect($result)->not->toHaveKey('description');
    });
});

describe('dates', function () {
    it('maps Issued date to datePublished', function () {
        $resource = createSchemaOrgResource();

        $issuedType = \App\Models\DateType::where('slug', 'Issued')->first();
        $resource->dates()->create([
            'date_value' => '2025-06-01',
            'date_type_id' => $issuedType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['datePublished'])->toBe('2025-06-01');
    });

    it('maps Created date to dateCreated', function () {
        $resource = createSchemaOrgResource();

        $createdType = \App\Models\DateType::where('slug', 'Created')->first();
        $resource->dates()->create([
            'date_value' => '2025-01-15',
            'date_type_id' => $createdType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['dateCreated'])->toBe('2025-01-15');
    });

    it('maps Updated date to dateModified', function () {
        $resource = createSchemaOrgResource();

        $updatedType = \App\Models\DateType::where('slug', 'Updated')->first();
        $resource->dates()->create([
            'date_value' => '2025-07-01',
            'date_type_id' => $updatedType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['dateModified'])->toBe('2025-07-01');
    });

    it('maps single Collected date to temporalCoverage with open end', function () {
        $resource = createSchemaOrgResource();

        $collectedType = \App\Models\DateType::where('slug', 'Collected')->first();
        $resource->dates()->create([
            'date_value' => '2024-01-01',
            'date_type_id' => $collectedType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['temporalCoverage'])->toBe('2024-01-01/..');
    });

    it('maps date range Collected to temporalCoverage as-is', function () {
        $resource = createSchemaOrgResource();

        $collectedType = \App\Models\DateType::where('slug', 'Collected')->first();
        $resource->dates()->create([
            'date_value' => '2024-01-01/2024-12-31',
            'date_type_id' => $collectedType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['temporalCoverage'])->toBe('2024-01-01/2024-12-31');
    });

    it('falls back to publicationYear for datePublished', function () {
        $resource = createSchemaOrgResource();

        $result = $this->exporter->export($resource);

        expect($result['datePublished'])->toBe('2025');
    });
});

describe('keywords', function () {
    it('transforms free-text keywords as plain strings', function () {
        $resource = createSchemaOrgResource();

        $resource->subjects()->create([
            'value' => 'Geophysics',
            'subject_scheme' => null,
            'scheme_uri' => null,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('keywords');
        expect($result['keywords'])->toContain('Geophysics');
    });

    it('transforms controlled vocabulary keywords as DefinedTerm', function () {
        $resource = createSchemaOrgResource();

        $resource->subjects()->create([
            'value' => 'EARTH SCIENCE > SOLID EARTH',
            'subject_scheme' => 'Science Keywords',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/1234',
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['keywords'])->toHaveCount(1);
        $keyword = $result['keywords'][0];
        expect($keyword['@type'])->toBe('DefinedTerm');
        expect($keyword['name'])->toBe('EARTH SCIENCE > SOLID EARTH');
        expect($keyword)->toHaveKey('inDefinedTermSet');
        expect($keyword)->toHaveKey('url');
    });
});

describe('license', function () {
    it('transforms rights with SPDX scheme to license URI', function () {
        $resource = createSchemaOrgResource();

        $right = \App\Models\Right::firstOrCreate(
            ['identifier' => 'CC-BY-4.0'],
            [
                'name' => 'Creative Commons Attribution 4.0 International',
                'uri' => 'https://creativecommons.org/licenses/by/4.0/',
                'scheme_uri' => 'https://spdx.org/licenses/',
            ]
        );
        $resource->rights()->attach($right->id);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('license');
        // With both schemeURI+identifier and rightsURI, license may be string or array
        $license = $result['license'];
        if (is_array($license)) {
            expect($license[0])->toContain('spdx.org');
        } else {
            expect($license)->toContain('spdx.org');
        }
    });
});

describe('spatial coverage', function () {
    it('transforms geo point to GeoCoordinates', function () {
        $resource = createSchemaOrgResource();

        $resource->geoLocations()->create([
            'place' => 'Potsdam',
            'point_longitude' => 13.0,
            'point_latitude' => 52.4,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('spatialCoverage');
        $spatial = $result['spatialCoverage'];
        expect($spatial['@type'])->toBe('Place');
        expect($spatial['name'])->toBe('Potsdam');
        expect($spatial['geo']['@type'])->toBe('GeoCoordinates');
        expect($spatial['geo']['latitude'])->toBe(52.4);
        expect($spatial['geo']['longitude'])->toBe(13.0);
    });

    it('transforms geo box to GeoShape', function () {
        $resource = createSchemaOrgResource();

        $resource->geoLocations()->create([
            'west_bound_longitude' => 12.0,
            'east_bound_longitude' => 14.0,
            'south_bound_latitude' => 51.0,
            'north_bound_latitude' => 53.0,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('spatialCoverage');
        $spatial = $result['spatialCoverage'];
        expect($spatial['geo']['@type'])->toBe('GeoShape');
        expect($spatial['geo'])->toHaveKey('box');
    });
});

describe('funding', function () {
    it('transforms funding references to MonetaryGrant', function () {
        $resource = createSchemaOrgResource();

        $resource->fundingReferences()->create([
            'funder_name' => 'DFG',
            'award_number' => 'ABC-123',
            'award_title' => 'Test Grant',
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('funding');
        $grant = $result['funding'][0];
        expect($grant['@type'])->toBe('MonetaryGrant');
        expect($grant['identifier'])->toBe('ABC-123');
        expect($grant['name'])->toBe('Test Grant');
        expect($grant['funder']['@type'])->toBe('Organization');
        expect($grant['funder']['name'])->toBe('DFG');
    });
});

describe('output is valid JSON-LD', function () {
    it('produces JSON-encodable output', function () {
        $resource = createSchemaOrgResource();

        $result = $this->exporter->export($resource);

        $json = json_encode($result, JSON_PRETTY_PRINT);
        expect($json)->not->toBeFalse();
        expect(json_decode($json, true))->toBe($result);
    });
});

// --- Helper ---

function createSchemaOrgResource(?string $doi = '10.5880/test.2025.001'): Resource
{
    $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

    $resource = Resource::factory()->create([
        'doi' => $doi,
        'publication_year' => 2025,
    ]);

    $resource->titles()->create([
        'value' => 'Schema.org Test Title',
        'title_type_id' => $mainTitleType?->id,
    ]);

    return $resource;
}
