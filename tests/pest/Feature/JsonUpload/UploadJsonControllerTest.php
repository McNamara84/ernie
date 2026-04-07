<?php

declare(strict_types=1);

use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

describe('JSON Upload - DataCite JSON format', function () {
    test('returns session key for valid DataCite JSON', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes());
        $file = UploadedFile::fake()->createWithContent('test.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $response->assertOk()
            ->assertJsonStructure(['sessionKey']);

        $sessionKey = $response->json('sessionKey');
        expect($sessionKey)->toStartWith('json_upload_');
    });

    test('extracts titles from DataCite JSON', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'titles' => [
                ['title' => 'Main Title'],
                ['title' => 'Sub Title', 'titleType' => 'Subtitle'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('titles.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['titles'])->toHaveCount(2);
        expect($data['titles'][0]['title'])->toBe('Main Title');
        expect($data['titles'][0]['titleType'])->toBe('main-title');
        expect($data['titles'][1]['title'])->toBe('Sub Title');
        expect($data['titles'][1]['titleType'])->toBe('subtitle');
    });

    test('extracts authors with ORCID', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'creators' => [
                [
                    'name' => 'Smith, John',
                    'givenName' => 'John',
                    'familyName' => 'Smith',
                    'nameType' => 'Personal',
                    'nameIdentifiers' => [
                        [
                            'nameIdentifier' => 'https://orcid.org/0000-0001-2345-6789',
                            'nameIdentifierScheme' => 'ORCID',
                        ],
                    ],
                    'affiliation' => [
                        [
                            'name' => 'GFZ Potsdam',
                            'affiliationIdentifier' => 'https://ror.org/04z8jg394',
                            'affiliationIdentifierScheme' => 'ROR',
                        ],
                    ],
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('authors.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['authors'])->toHaveCount(1);
        expect($data['authors'][0]['type'])->toBe('person');
        expect($data['authors'][0]['orcid'])->toBe('0000-0001-2345-6789');
        expect($data['authors'][0]['firstName'])->toBe('John');
        expect($data['authors'][0]['lastName'])->toBe('Smith');
        expect($data['authors'][0]['affiliations'][0]['value'])->toBe('GFZ Potsdam');
        expect($data['authors'][0]['affiliations'][0]['rorId'])->toBe('https://ror.org/04z8jg394');
    });

    test('extracts institutional authors', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'creators' => [
                [
                    'name' => 'GFZ German Research Centre for Geosciences',
                    'nameType' => 'Organizational',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('institution.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['authors'][0]['type'])->toBe('institution');
        expect($data['authors'][0]['institutionName'])->toBe('GFZ German Research Centre for Geosciences');
    });

    test('extracts contributors with roles', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'contributors' => [
                [
                    'name' => 'Doe, Jane',
                    'givenName' => 'Jane',
                    'familyName' => 'Doe',
                    'nameType' => 'Personal',
                    'contributorType' => 'DataCollector',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('contributors.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['contributors'])->toHaveCount(1);
        expect($data['contributors'][0]['type'])->toBe('person');
        expect($data['contributors'][0]['roles'])->toBe(['Data Collector']);
        expect($data['contributors'][0]['firstName'])->toBe('Jane');
        expect($data['contributors'][0]['lastName'])->toBe('Doe');
    });

    test('extracts descriptions', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'descriptions' => [
                ['description' => 'This is the abstract.', 'descriptionType' => 'Abstract'],
                ['description' => 'Method description.', 'descriptionType' => 'Methods'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('descriptions.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['descriptions'])->toHaveCount(2);
        expect($data['descriptions'][0]['description'])->toBe('This is the abstract.');
        expect($data['descriptions'][0]['type'])->toBe('Abstract');
    });

    test('extracts dates', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'dates' => [
                ['date' => '2025-06-15', 'dateType' => 'Created'],
                ['date' => '2025-01-01/2025-12-31', 'dateType' => 'Collected'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('dates.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['dates'])->toHaveCount(2);
        expect($data['dates'][0]['dateType'])->toBe('created');
        expect($data['dates'][0]['startDate'])->toBe('2025-06-15');
        expect($data['dates'][1]['dateType'])->toBe('collected');
        expect($data['dates'][1]['startDate'])->toBe('2025-01-01');
        expect($data['dates'][1]['endDate'])->toBe('2025-12-31');
    });

    test('extracts licenses', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'rightsList' => [
                [
                    'rights' => 'Creative Commons Attribution 4.0',
                    'rightsIdentifier' => 'CC-BY-4.0',
                    'rightsUri' => 'https://creativecommons.org/licenses/by/4.0/',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('licenses.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['licenses'])->toBe(['CC-BY-4.0']);
    });

    test('extracts resource type', function () {
        $this->actingAs(User::factory()->create());

        $type = ResourceType::create([
            'name' => 'Dataset',
            'slug' => 'dataset',
            'active' => true,
            'elmo_active' => true,
        ]);

        $json = dataCiteJson(minimalAttributes([
            'types' => ['resourceTypeGeneral' => 'Dataset', 'resourceType' => 'Dataset'],
        ]));
        $file = UploadedFile::fake()->createWithContent('resource-type.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['resourceType'])->toBe((string) $type->id);
    });

    test('extracts scalar fields (DOI, year, version, language)', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'doi' => '10.5880/test.2025.001',
            'publicationYear' => '2025',
            'version' => '2.0',
            'language' => 'en',
        ]));
        $file = UploadedFile::fake()->createWithContent('scalars.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['doi'])->toBe('10.5880/test.2025.001');
        expect($data['year'])->toBe('2025');
        expect($data['version'])->toBe('2.0');
        expect($data['language'])->toBe('en');
    });

    test('extracts geo locations', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'geoLocations' => [
                [
                    'geoLocationPoint' => [
                        'pointLatitude' => 52.38,
                        'pointLongitude' => 13.06,
                    ],
                    'geoLocationPlace' => 'Potsdam',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('geo.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['coverages'])->toHaveCount(1);
        expect($data['coverages'][0]['description'])->toBe('Potsdam');
        expect($data['coverages'][0]['latMin'])->toBe('52.380000');
        expect($data['coverages'][0]['lonMin'])->toBe('13.060000');
    });

    test('extracts free keywords', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'subjects' => [
                ['subject' => 'seismology'],
                ['subject' => 'geophysics'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('keywords.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['freeKeywords'])->toBe(['seismology', 'geophysics']);
    });

    test('extracts related identifiers and instruments', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'relatedIdentifiers' => [
                [
                    'relatedIdentifier' => '10.1234/related',
                    'relatedIdentifierType' => 'DOI',
                    'relationType' => 'Cites',
                ],
                [
                    'relatedIdentifier' => 'http://hdl.handle.net/123/456',
                    'relatedIdentifierType' => 'Handle',
                    'relationType' => 'IsCollectedBy',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('related.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['relatedWorks'])->toHaveCount(1);
        expect($data['relatedWorks'][0]['identifier'])->toBe('10.1234/related');
        expect($data['relatedWorks'][0]['identifier_type'])->toBe('DOI');
        expect($data['relatedWorks'][0]['relation_type'])->toBe('Cites');

        expect($data['instruments'])->toHaveCount(1);
        expect($data['instruments'][0]['pid'])->toBe('http://hdl.handle.net/123/456');
    });

    test('extracts funding references', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'fundingReferences' => [
                [
                    'funderName' => 'DFG',
                    'funderIdentifier' => 'https://doi.org/10.13039/501100001659',
                    'funderIdentifierType' => 'Crossref Funder ID',
                    'awardNumber' => 'ABC-123',
                    'awardUri' => 'https://example.org/award/123',
                    'awardTitle' => 'Test Project',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('funding.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['fundingReferences'])->toHaveCount(1);
        expect($data['fundingReferences'][0]['funderName'])->toBe('DFG');
        expect($data['fundingReferences'][0]['funderIdentifier'])->toBe('https://doi.org/10.13039/501100001659');
        expect($data['fundingReferences'][0]['awardNumber'])->toBe('ABC-123');
    });

    test('merges contact persons into authors', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'creators' => [
                [
                    'name' => 'Smith, John',
                    'givenName' => 'John',
                    'familyName' => 'Smith',
                    'nameType' => 'Personal',
                ],
            ],
            'contributors' => [
                [
                    'name' => 'Smith, John',
                    'givenName' => 'John',
                    'familyName' => 'Smith',
                    'nameType' => 'Personal',
                    'contributorType' => 'ContactPerson',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('contact.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['authors'])->toHaveCount(1);
        expect($data['authors'][0]['isContact'])->toBeTrue();
        expect($data['contributors'])->toHaveCount(0);
    });
});

describe('JSON Upload - Flat DataCite JSON format', function () {
    test('accepts flat attributes without data wrapper', function () {
        $this->actingAs(User::factory()->create());

        $json = (string) json_encode(minimalAttributes([
            'titles' => [['title' => 'Flat Format Test']],
        ]));
        $file = UploadedFile::fake()->createWithContent('flat.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['titles'][0]['title'])->toBe('Flat Format Test');
        expect($data['authors'][0]['lastName'])->toBe('Smith');
        expect($data['authors'][0]['firstName'])->toBe('John');
    });
});

describe('JSON Upload - JSON-LD format', function () {
    test('accepts JSON-LD with @context and converts to DataCite JSON', function () {
        $this->actingAs(User::factory()->create());

        $jsonLd = (string) json_encode([
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            '@id' => 'https://doi.org/10.5880/test.2025.jsonld',
            'titles' => [
                'title' => ['value' => 'JSON-LD Test'],
            ],
            'creators' => [
                'creator' => [
                    'creatorName' => [
                        'attrs' => ['nameType' => 'Personal'],
                        'value' => 'Doe, Jane',
                    ],
                    'givenName' => ['value' => 'Jane'],
                    'familyName' => ['value' => 'Doe'],
                ],
            ],
            'publisher' => ['value' => 'GFZ Data Services'],
            'publicationYear' => ['value' => '2025'],
            'resourceType' => [
                'attrs' => ['resourceTypeGeneral' => 'Dataset'],
                'value' => 'Dataset',
            ],
        ]);

        $file = UploadedFile::fake()->createWithContent('test.jsonld', $jsonLd);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['doi'])->toBe('10.5880/test.2025.jsonld');
        expect($data['titles'][0]['title'])->toBe('JSON-LD Test');
        expect($data['authors'][0]['lastName'])->toBe('Doe');
        expect($data['year'])->toBe('2025');
    });

    test('accepts JSON-LD with attrs/value pattern for titles', function () {
        $this->actingAs(User::factory()->create());

        $jsonLd = (string) json_encode([
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'titles' => [
                'title' => [
                    [
                        'value' => 'Main Title',
                    ],
                    [
                        'attrs' => ['titleType' => 'Subtitle'],
                        'value' => 'A JSON-LD Subtitle',
                    ],
                ],
            ],
            'creators' => [
                'creator' => [
                    'creatorName' => ['value' => 'Smith, John'],
                    'givenName' => ['value' => 'John'],
                    'familyName' => ['value' => 'Smith'],
                ],
            ],
            'publisher' => ['value' => 'GFZ Data Services'],
            'publicationYear' => ['value' => '2025'],
            'resourceType' => [
                'attrs' => ['resourceTypeGeneral' => 'Dataset'],
                'value' => 'Dataset',
            ],
        ]);

        $file = UploadedFile::fake()->createWithContent('jsonld-attrs.jsonld', $jsonLd);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['titles'][1]['title'])->toBe('A JSON-LD Subtitle');
        expect($data['titles'][1]['titleType'])->toBe('subtitle');
    });
});
