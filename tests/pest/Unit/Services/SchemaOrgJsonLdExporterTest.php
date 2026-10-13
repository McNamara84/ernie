<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Services\SchemaOrgJsonLdExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SchemaOrgExampleMetadata;
use Tests\Fixtures\SchemaOrgResourceTypes;
use Tests\Fixtures\SchemaOrgVocabulary;

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

    $this->exporter = app(SchemaOrgJsonLdExporter::class);
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

    it('omits access assertions when the access level is unresolved', function () {
        $resource = createSchemaOrgResource();

        $result = $this->exporter->export($resource);

        expect($result)->not->toHaveKey('isAccessibleForFree')
            ->and($result)->not->toHaveKey('conditionsOfAccess');
    });

    it('maps access levels to conditionsOfAccess and isAccessibleForFree', function (
        AccessLevel $level,
        string $label,
        ?bool $free,
    ) {
        $resource = createSchemaOrgResource();
        $resource->update(['access_level' => $level]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['conditionsOfAccess'])->toBe($label);
        if ($free === null) {
            expect($result)->not->toHaveKey('isAccessibleForFree');
        } else {
            expect($result['isAccessibleForFree'])->toBe($free);
        }
    })->with([
        [AccessLevel::OPEN, 'Open access', true],
        [AccessLevel::RESTRICTED, 'Restricted access', false],
        [AccessLevel::EMBARGOED, 'Embargoed access', false],
        [AccessLevel::METADATA_ONLY, 'Metadata only access', null],
    ]);

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

    it('preserves the exact canonical schema org description text', function () {
        $resource = createSchemaOrgResource();

        $abstractType = DescriptionType::where('slug', 'Abstract')->first();
        $resource->descriptions()->create([
            'value' => 'Schema.org first line'.chr(10).chr(10).'<strong>literal</strong> &lt;encoded>',
            'landing_page_html' => '<p>Schema.org <strong>first</strong> line</p><p>second line</p>',
            'description_type_id' => $abstractType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['description'])
            ->toBe('Schema.org first line'.chr(10).chr(10).'<strong>literal</strong> &lt;encoded>')
            ->and($result['description'])->not->toContain('<br>');
    });
});

describe('dates', function () {
    it('maps Issued date to datePublished', function () {
        $resource = createSchemaOrgResource();

        $issuedType = DateType::where('slug', 'Issued')->first();
        $resource->dates()->create([
            'date_value' => '2025-06-01',
            'date_type_id' => $issuedType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['datePublished'])->toBe('2025-06-01');
    });

    it('maps Created date to dateCreated', function () {
        $resource = createSchemaOrgResource();

        $createdType = DateType::where('slug', 'Created')->first();
        $resource->dates()->create([
            'date_value' => '2025-01-15',
            'date_type_id' => $createdType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['dateCreated'])->toBe('2025-01-15');
    });

    it('maps Updated date to dateModified', function () {
        $resource = createSchemaOrgResource();

        $updatedType = DateType::where('slug', 'Updated')->first();
        $resource->dates()->create([
            'date_value' => '2025-07-01',
            'date_type_id' => $updatedType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['dateModified'])->toBe('2025-07-01');
    });

    it('maps single Collected date to temporalCoverage with open end', function () {
        $resource = createSchemaOrgResource();

        $collectedType = DateType::where('slug', 'Collected')->first();
        $resource->dates()->create([
            'date_value' => '2024-01-01',
            'date_type_id' => $collectedType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['temporalCoverage'])->toBe('2024-01-01/..');
    });

    it('maps date range Collected to temporalCoverage as-is', function () {
        $resource = createSchemaOrgResource();

        $collectedType = DateType::where('slug', 'Collected')->first();
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
    it('filters malformed and COAR access-right entries before transforming licenses', function () {
        $filterLicenseRights = new ReflectionMethod(SchemaOrgJsonLdExporter::class, 'filterLicenseRights');
        $filterLicenseRights->setAccessible(true);

        $license = [
            'rights' => 'Creative Commons Attribution 4.0 International',
            'rightsUri' => 'https://creativecommons.org/licenses/by/4.0/',
        ];

        $result = $filterLicenseRights->invoke($this->exporter, [
            'unexpected string',
            null,
            $license,
            [
                'rights' => AccessLevel::OPEN->label(),
                'rightsUri' => AccessLevel::OPEN->coarUri(),
            ],
        ]);

        expect($result)->toBe([$license]);
    });

    it('transforms rights with SPDX scheme to license URI', function () {
        $resource = createSchemaOrgResource();

        $right = Right::firstOrCreate(
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

    it('transforms a canonical DataCite polygon to GeoShape', function () {
        $resource = createSchemaOrgResource();

        $resource->geoLocations()->create([
            'geo_type' => 'polygon',
            'polygon_points' => [
                ['longitude' => 13.0, 'latitude' => 52.0],
                ['longitude' => 14.0, 'latitude' => 52.0],
                ['longitude' => 14.0, 'latitude' => 53.0],
            ],
        ]);

        $result = $this->exporter->export($resource->fresh());
        $geoShape = $result['spatialCoverage']['geo'];

        expect($geoShape['@type'])->toBe('GeoShape')
            ->and($geoShape['polygon'])->toBe('52 13 52 14 53 14 52 13');
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

describe('landing page content', function () {
    it('adds canonical landing page URL and Dataset distributions', function () {
        $resource = createSchemaOrgResource();
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => $resource->doi,
            'slug' => 'schema-org-content',
        ]);

        $result = $this->exporter->export($resource, $landingPage, [
            'mimeType' => 'application/zip',
            'contentLinks' => [
                ['url' => 'https://downloads.example.org/data.zip', 'mimeType' => 'application/zip', 'contentSize' => '1500000'],
            ],
            'repositories' => [],
        ]);

        expect($result['url'])->toBe(url($landingPage->getPublicPath()))
            ->and($result['distribution'])->toBe([
                [
                    '@type' => 'DataDownload',
                    'contentUrl' => 'https://downloads.example.org/data.zip',
                    'encodingFormat' => 'application/zip',
                    'contentSize' => '1500000',
                ],
            ]);
    });

    it('uses software types repository and downloadUrl for Software resources', function () {
        $softwareType = ResourceType::firstOrCreate(
            ['slug' => 'software'],
            ['name' => 'Software', 'is_active' => true],
        );
        $resource = createSchemaOrgResource();
        $resource->update(['resource_type_id' => $softwareType->id]);
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => $resource->doi,
            'slug' => 'schema-org-software',
        ]);

        $result = $this->exporter->export($resource->fresh(), $landingPage, [
            'mimeType' => 'application/zip',
            'contentLinks' => [
                ['url' => 'https://downloads.example.org/source.zip', 'mimeType' => 'application/zip', 'contentSize' => '2048'],
            ],
            'repositories' => ['https://git.example.org/source'],
        ]);

        expect($result['@type'])->toBe(['SoftwareSourceCode', 'SoftwareApplication'])
            ->and($result['codeRepository'])->toBe('https://git.example.org/source')
            ->and($result['downloadUrl'])->toBe('https://downloads.example.org/source.zip')
            ->and($result['associatedMedia'])->toBe([[
                '@type' => 'DataDownload',
                'contentUrl' => 'https://downloads.example.org/source.zip',
                'encodingFormat' => 'application/zip',
                'contentSize' => '2048',
            ]])
            ->and($result)->not->toHaveKey('distribution');
    });
});

it('exports every reviewed type with the correct content property and no guessed software role', function (string $slug, string $type, string $profile, bool $fallback): void {
    $resource = createSchemaOrgResource();
    $resource->update(['resource_type_id' => ResourceType::where('slug', $slug)->sole()->id]);
    SchemaOrgExampleMetadata::addTo($resource);
    $content = [
        'mimeType' => 'application/zip',
        'contentLinks' => [
            ['url' => 'https://example.org/archive.zip', 'mimeType' => 'application/zip', 'contentSize' => '2048'],
            ['url' => 'https://example.org/document.pdf', 'mimeType' => 'application/pdf', 'contentSize' => null],
        ],
        'repositories' => ['https://example.org/source'],
    ];
    $result = $this->exporter->export($resource->fresh(), content: $content);
    $target = $profile === 'described-object' ? collect($result['subjectOf'])->firstWhere('@type', 'CreativeWork') : $result;
    $property = match ($profile) {
        'dataset' => 'distribution',
        'media' => 'encoding',
        default => 'associatedMedia',
    };

    expect($result['@type'])->toBe($slug === 'software' ? ['SoftwareSourceCode', 'SoftwareApplication'] : $type)
        ->and(SchemaOrgVocabulary::violations($result))->toBe([])
        ->and($target[$property][0]['contentUrl'])->toBe('https://example.org/archive.zip')
        ->and($target[$property][0]['encodingFormat'])->toBe('application/zip')
        ->and($target[$property][0]['contentSize'])->toBe('2048')
        ->and($target[$property][1]['encodingFormat'])->toBe('application/pdf')
        ->and($target[$property][1])->not->toHaveKey('contentSize')
        ->and(array_column($target['creator']['@list'], 'name'))->toBe(['Lovelace, Ada', 'Example Observatory'])
        ->and($target['publisher']['@type'])->toBe('Organization')
        ->and($target['datePublished'])->toBe('2025-06-01')
        ->and($target['dateCreated'])->toBe('2024-01-01')
        ->and($target['dateModified'])->toBe('2025-05-01')
        ->and($target['temporalCoverage'])->toBe('2024-01-01/..')
        ->and($target['keywords'][1]['@type'])->toBe('DefinedTerm')
        ->and($target['license'])->toContain('https://spdx.org/licenses/CC-BY-4.0')
        ->and($target['spatialCoverage']['geo']['latitude'])->toBe(52.4)
        ->and($target['funding'][0]['identifier'])->toBe('EXAMPLE-1')
        ->and($target['citation'][0]['name'])->toBe('Related research');
    if ($profile !== 'dataset') {
        expect($result)->not->toHaveKey('distribution');
    }
    if ($profile !== 'software') {
        expect($result)->not->toHaveKeys(['codeRepository', 'downloadUrl']);
    }
    if ($profile === 'described-object') {
        expect($target['@id'])->toBe('https://doi.org/'.$resource->doi.'#resource-description')
            ->and($target['about'])->toBe(['@id' => $result['@id']])
            ->and($result)->not->toHaveKey('associatedMedia');
    }
})->with(SchemaOrgResourceTypes::cases());

it('exports safe descriptions when the type or DOI is missing', function (bool $withDoi): void {
    $resource = createSchemaOrgResource($withDoi ? '10.60510/fallback' : null);
    $resource->update(['resource_type_id' => null]);
    $result = $this->exporter->export($resource->fresh());
    $description = collect($result['subjectOf'])->firstWhere('@type', 'CreativeWork');
    expect($result['@type'])->toBe('Thing')
        ->and($result)->not->toHaveKey('additionalType')
        ->and($description['name'])->toBe('Metadata description of Schema.org Test Title')
        ->and($description['datePublished'])->toBe('2025');
    if (! $withDoi) {
        expect($result)->not->toHaveKey('@id')
            ->and($description)->not->toHaveKeys(['@id', 'about']);
    }
})->with([true, false]);

it('never exports caller supplied downloads or repositories for a tombstone', function (string $slug): void {
    $resource = createSchemaOrgResource();
    $resource->update(['resource_type_id' => ResourceType::where('slug', $slug)->sole()->id]);
    $page = LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => $resource->doi,
        'is_tombstone' => true,
        'tombstone_statement' => 'The content was lost.',
    ]);
    $result = $this->exporter->export($resource->fresh(), $page, [
        'mimeType' => 'application/zip',
        'contentLinks' => [['url' => 'https://example.org/private-content.zip', 'mimeType' => 'application/zip', 'contentSize' => null]],
        'repositories' => ['https://example.org/private-repository'],
    ]);
    expect($result['description'])->toContain('The content was lost.')
        ->and(SchemaOrgVocabulary::violations($result))->toBe([])
        ->and(json_encode($result))->not->toContain('private-content')->not->toContain('private-repository');
    if ($slug === 'physical-object') {
        $description = collect($result['subjectOf'])->firstWhere('@type', 'CreativeWork');
        expect($result['@type'])->toBe('Thing')
            ->and($description['conditionsOfAccess'])->toContain('This resource is no longer available.')
            ->and($description)->not->toHaveKey('isAccessibleForFree');
    } else {
        expect($result['isAccessibleForFree'])->toBeFalse();
    }
})->with(['dataset', 'software', 'physical-object', 'image', 'report']);

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
