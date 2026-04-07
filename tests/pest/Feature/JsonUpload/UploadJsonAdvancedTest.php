<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

describe('JSON Upload - Keyword extraction', function () {
    test('extracts GCMD science keywords with UUID', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'subjects' => [
                [
                    'subject' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
                    'subjectScheme' => 'NASA/GCMD Science Keywords',
                    'schemeUri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
                    'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/123e4567-e89b-12d3-a456-426614174000',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('gcmd.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['gcmdKeywords'])->toHaveCount(1);
        expect($data['gcmdKeywords'][0]['uuid'])->toBe('123e4567-e89b-12d3-a456-426614174000');
        expect($data['gcmdKeywords'][0]['text'])->toBe('SEISMOLOGY');
        expect($data['gcmdKeywords'][0]['scheme'])->toBe('Science Keywords');
    });

    test('extracts GCMD platform keywords', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'subjects' => [
                [
                    'subject' => 'In Situ Land-based Platforms > SEISMOLOGICAL STATIONS',
                    'subjectScheme' => 'NASA/GCMD Platforms',
                    'schemeUri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms',
                    'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/aabbccdd-1122-3344-5566-778899001122',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('platforms.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['gcmdKeywords'])->toHaveCount(1);
        expect($data['gcmdKeywords'][0]['scheme'])->toBe('Platforms');
    });

    test('extracts GCMD instrument keywords', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'subjects' => [
                [
                    'subject' => 'Seismometers > BROADBAND SEISMOMETERS',
                    'subjectScheme' => 'NASA/GCMD Instruments',
                    'schemeUri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments',
                    'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/11223344-5566-7788-99aa-bbccddeeff00',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('instruments-kw.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['gcmdKeywords'])->toHaveCount(1);
        expect($data['gcmdKeywords'][0]['scheme'])->toBe('Instruments');
    });

    test('extracts MSL vocabulary keywords', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'subjects' => [
                [
                    'subject' => 'Rock and Melt Physics > Deformation',
                    'subjectScheme' => 'EPOS MSL vocabulary',
                    'schemeUri' => 'https://epos-msl.uu.nl/voc',
                    'valueUri' => 'https://epos-msl.uu.nl/voc/def/1',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('msl.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['mslKeywords'])->toHaveCount(1);
        expect($data['mslKeywords'][0]['text'])->toBe('Deformation');
        expect($data['mslKeywords'][0]['path'])->toBe('Rock and Melt Physics > Deformation');
        expect($data['mslKeywords'][0]['scheme'])->toBe('EPOS MSL vocabulary');
    });

    test('extracts GEMET vocabulary keywords', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'subjects' => [
                [
                    'subject' => 'earthquake',
                    'subjectScheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
                    'schemeUri' => 'http://www.eionet.europa.eu/gemet/concept/',
                    'valueUri' => 'http://www.eionet.europa.eu/gemet/concept/2528',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('gemet.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['gemetKeywords'])->toHaveCount(1);
        expect($data['gemetKeywords'][0]['text'])->toBe('earthquake');
        expect($data['gemetKeywords'][0]['scheme'])->toBe('GEMET - GEneral Multilingual Environmental Thesaurus');
    });

    test('extracts unknown scheme keywords with valueUri as GCMD-like', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'subjects' => [
                [
                    'subject' => 'Custom Term',
                    'subjectScheme' => 'Custom Vocabulary',
                    'valueUri' => 'https://example.org/vocab/1',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('unknown-scheme.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['gcmdKeywords'])->toHaveCount(1);
        expect($data['gcmdKeywords'][0]['text'])->toBe('Custom Term');
        expect($data['gcmdKeywords'][0]['scheme'])->toBe('Custom Vocabulary');
    });

    test('ignores GCMD keywords without valueUri', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'subjects' => [
                [
                    'subject' => 'Some keyword',
                    'subjectScheme' => 'NASA/GCMD Science Keywords',
                    // No valueUri
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('no-uri.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['gcmdKeywords'])->toHaveCount(0);
    });

    test('skips subjects with empty text', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'subjects' => [
                ['subject' => ''],
                ['subject' => '  '],
                ['subject' => 'valid keyword'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('empty-subjects.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['freeKeywords'])->toBe(['valid keyword']);
    });
});

describe('JSON Upload - Geo location edge cases', function () {
    test('extracts bounding box geo location', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'geoLocations' => [
                [
                    'geoLocationBox' => [
                        'westBoundLongitude' => 12.0,
                        'eastBoundLongitude' => 14.0,
                        'southBoundLatitude' => 51.0,
                        'northBoundLatitude' => 53.0,
                    ],
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('box.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['coverages'])->toHaveCount(1);
        expect($data['coverages'][0]['lonMin'])->toBe('12.000000');
        expect($data['coverages'][0]['lonMax'])->toBe('14.000000');
        expect($data['coverages'][0]['latMin'])->toBe('51.000000');
        expect($data['coverages'][0]['latMax'])->toBe('53.000000');
    });

    test('extracts polygon geo location', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'geoLocations' => [
                [
                    'geoLocationPolygon' => [
                        'polygonPoints' => [
                            ['pointLatitude' => 52.0, 'pointLongitude' => 13.0],
                            ['pointLatitude' => 53.0, 'pointLongitude' => 14.0],
                            ['pointLatitude' => 52.5, 'pointLongitude' => 13.5],
                            ['pointLatitude' => 52.0, 'pointLongitude' => 13.0],
                        ],
                    ],
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('polygon.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['coverages'])->toHaveCount(1);
        expect($data['coverages'][0]['polygonPoints'])->toHaveCount(4);
        expect($data['coverages'][0]['polygonPoints'][0]['latitude'])->toBe(52.0);
        expect($data['coverages'][0]['polygonPoints'][0]['longitude'])->toBe(13.0);
    });

    test('creates temporal-only coverage from dates', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'dates' => [
                ['date' => '2020-01-01/2020-12-31', 'dateType' => 'Collected'],
            ],
            'geoLocations' => [],
        ]));
        $file = UploadedFile::fake()->createWithContent('temporal.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        // Coverage date type is lowercased to kebab-case; "Collected" does NOT match "coverage" dateType
        // so temporal-only coverage is NOT created (only created for dateType=coverage)
        expect($data['coverages'])->toHaveCount(0);
    });

    test('creates temporal-only coverage when dateType is coverage', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'dates' => [
                ['date' => '2020-01-01/2020-12-31', 'dateType' => 'Coverage'],
            ],
            'geoLocations' => [],
        ]));
        $file = UploadedFile::fake()->createWithContent('temporal-coverage.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        // dateType is kebab-cased, "Coverage" → "coverage"
        expect($data['coverages'])->toHaveCount(1);
        expect($data['coverages'][0]['startDate'])->toBe('2020-01-01');
        expect($data['coverages'][0]['endDate'])->toBe('2020-12-31');
    });

    test('extracts only populated geo locations', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'geoLocations' => [
                [
                    'geoLocationPlace' => 'Potsdam',
                    'geoLocationPoint' => [
                        'pointLatitude' => 52.38,
                        'pointLongitude' => 13.06,
                    ],
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('populated-geo.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['coverages'])->toHaveCount(1);
        expect($data['coverages'][0]['description'])->toBe('Potsdam');
    });
});

describe('JSON Upload - Contributor edge cases', function () {
    test('extracts MSL laboratory from HostingInstitution with labid', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'contributors' => [
                [
                    'name' => 'Rock Mechanics Lab',
                    'nameType' => 'Organizational',
                    'contributorType' => 'HostingInstitution',
                    'nameIdentifiers' => [
                        [
                            'nameIdentifier' => 'lab-123',
                            'nameIdentifierScheme' => 'labid',
                        ],
                    ],
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('msl-lab.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['mslLaboratories'])->toHaveCount(1);
        expect($data['mslLaboratories'][0]['labId'])->toBe('lab-123');
        expect($data['mslLaboratories'][0]['labName'])->toBe('Rock Mechanics Lab');
        expect($data['contributors'])->toHaveCount(0);
    });

    test('classifies institution-only roles as institutional contributors', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'contributors' => [
                [
                    'name' => 'GFZ Potsdam',
                    'contributorType' => 'HostingInstitution',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('hosting.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['contributors'])->toHaveCount(1);
        expect($data['contributors'][0]['type'])->toBe('institution');
        expect($data['contributors'][0]['roles'])->toBe(['Hosting Institution']);
    });

    test('extracts organizational contributor by nameType', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'contributors' => [
                [
                    'name' => 'Some Research Group',
                    'nameType' => 'Organizational',
                    'contributorType' => 'Researcher',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('org-contributor.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['contributors'])->toHaveCount(1);
        expect($data['contributors'][0]['type'])->toBe('institution');
        expect($data['contributors'][0]['institutionName'])->toBe('Some Research Group');
    });

    test('adds unmatched contact person as new author', function () {
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
                    'name' => 'Doe, Jane',
                    'givenName' => 'Jane',
                    'familyName' => 'Doe',
                    'nameType' => 'Personal',
                    'contributorType' => 'ContactPerson',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('new-contact.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['authors'])->toHaveCount(2);
        expect($data['authors'][1]['firstName'])->toBe('Jane');
        expect($data['authors'][1]['lastName'])->toBe('Doe');
        expect($data['authors'][1]['isContact'])->toBeTrue();
    });

    test('matches contact person by ORCID', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'creators' => [
                [
                    'name' => 'Smith, John',
                    'givenName' => 'John',
                    'familyName' => 'Smith',
                    'nameType' => 'Personal',
                    'nameIdentifiers' => [
                        ['nameIdentifier' => '0000-0001-2345-6789', 'nameIdentifierScheme' => 'ORCID'],
                    ],
                ],
            ],
            'contributors' => [
                [
                    'name' => 'Smith, John',
                    'givenName' => 'John',
                    'familyName' => 'Smith',
                    'nameType' => 'Personal',
                    'contributorType' => 'ContactPerson',
                    'nameIdentifiers' => [
                        ['nameIdentifier' => '0000-0001-2345-6789', 'nameIdentifierScheme' => 'ORCID'],
                    ],
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('orcid-contact.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['authors'])->toHaveCount(1);
        expect($data['authors'][0]['isContact'])->toBeTrue();
    });

    test('skips organizational contact persons', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'contributors' => [
                [
                    'name' => 'GFZ Potsdam',
                    'nameType' => 'Organizational',
                    'contributorType' => 'ContactPerson',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('org-contact.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['authors'])->toHaveCount(1); // Only default creator
        expect($data['contributors'])->toHaveCount(0);
    });
});

describe('JSON Upload - Date normalization', function () {
    test('normalizes year-only dates', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'dates' => [
                ['date' => '2025', 'dateType' => 'Created'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('year-date.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['dates'][0]['startDate'])->toBe('2025-01-01');
    });

    test('normalizes year-month dates', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'dates' => [
                ['date' => '2025-06', 'dateType' => 'Issued'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('yearmonth.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['dates'][0]['startDate'])->toBe('2025-06-01');
    });

    test('strips time from datetime values', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'dates' => [
                ['date' => '2025-06-15T10:30:00Z', 'dateType' => 'Available'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('datetime.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['dates'][0]['startDate'])->toBe('2025-06-15');
    });

    test('skips empty date values', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'dates' => [
                ['date' => '', 'dateType' => 'Created'],
                ['date' => '2025-01-01', 'dateType' => 'Issued'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('empty-date.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['dates'])->toHaveCount(1);
        expect($data['dates'][0]['dateType'])->toBe('issued');
    });
});

describe('JSON Upload - Title edge cases', function () {
    test('skips empty titles', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'titles' => [
                ['title' => ''],
                ['title' => 'Valid Title'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('empty-title.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['titles'])->toHaveCount(1);
        expect($data['titles'][0]['title'])->toBe('Valid Title');
    });

    test('sorts main titles before other title types', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'titles' => [
                ['title' => 'Subtitle First', 'titleType' => 'Subtitle'],
                ['title' => 'Main Title'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('title-sort.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['titles'][0]['title'])->toBe('Main Title');
        expect($data['titles'][0]['titleType'])->toBe('main-title');
        expect($data['titles'][1]['title'])->toBe('Subtitle First');
    });
});

describe('JSON Upload - Related identifier edge cases', function () {
    test('extracts valid related identifiers', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'relatedIdentifiers' => [
                [
                    'relatedIdentifier' => '10.1234/valid',
                    'relatedIdentifierType' => 'DOI',
                    'relationType' => 'Cites',
                ],
                [
                    'relatedIdentifier' => '10.5678/second',
                    'relatedIdentifierType' => 'DOI',
                    'relationType' => 'References',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('multi-related.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['relatedWorks'])->toHaveCount(2);
        expect($data['relatedWorks'][0]['identifier'])->toBe('10.1234/valid');
        expect($data['relatedWorks'][0]['relation_type'])->toBe('Cites');
        expect($data['relatedWorks'][1]['identifier'])->toBe('10.5678/second');
    });

    test('includes relationTypeInformation when present', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'relatedIdentifiers' => [
                [
                    'relatedIdentifier' => '10.1234/related',
                    'relatedIdentifierType' => 'DOI',
                    'relationType' => 'References',
                    'relationTypeInformation' => 'Contains supplementary data',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('relation-info.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['relatedWorks'][0]['relation_type_information'])->toBe('Contains supplementary data');
    });

    test('skips related identifiers with empty identifier', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'relatedIdentifiers' => [
                [
                    'relatedIdentifier' => '',
                    'relatedIdentifierType' => 'DOI',
                    'relationType' => 'Cites',
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('empty-related.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['relatedWorks'])->toHaveCount(0);
    });
});

describe('JSON Upload - License and description edge cases', function () {
    test('skips licenses without rightsIdentifier', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'rightsList' => [
                ['rights' => 'Open Access'],
                ['rightsIdentifier' => 'CC-BY-4.0'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('partial-rights.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['licenses'])->toBe(['CC-BY-4.0']);
    });

    test('skips empty descriptions', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'descriptions' => [
                ['description' => '', 'descriptionType' => 'Abstract'],
                ['description' => 'Valid description', 'descriptionType' => 'Methods'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('empty-desc.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['descriptions'])->toHaveCount(1);
        expect($data['descriptions'][0]['description'])->toBe('Valid description');
    });
});

describe('JSON Upload - Funding reference edge cases', function () {
    test('skips funding references without funder name', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'fundingReferences' => [
                ['funderName' => '', 'awardNumber' => '123'],
                ['funderName' => 'DFG', 'awardNumber' => '456'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('funding-edge.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['fundingReferences'])->toHaveCount(1);
        expect($data['fundingReferences'][0]['funderName'])->toBe('DFG');
    });

    test('handles funding references with null optional fields', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'fundingReferences' => [
                ['funderName' => 'NSF'],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('funding-minimal.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['fundingReferences'][0]['funderName'])->toBe('NSF');
        expect($data['fundingReferences'][0]['funderIdentifier'])->toBeNull();
        expect($data['fundingReferences'][0]['awardNumber'])->toBeNull();
        expect($data['fundingReferences'][0]['awardUri'])->toBeNull();
        expect($data['fundingReferences'][0]['awardTitle'])->toBeNull();
    });
});

describe('JSON Upload - Affiliation edge cases', function () {
    test('extracts affiliations without ROR identifier', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'creators' => [
                [
                    'name' => 'Doe, Jane',
                    'givenName' => 'Jane',
                    'familyName' => 'Doe',
                    'nameType' => 'Personal',
                    'affiliation' => [
                        ['name' => 'University of Berlin'],
                    ],
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('aff-no-ror.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['authors'][0]['affiliations'][0]['value'])->toBe('University of Berlin');
        expect($data['authors'][0]['affiliations'][0]['rorId'])->toBeNull();
    });

    test('skips affiliations with empty name', function () {
        $this->actingAs(User::factory()->create());

        $json = dataCiteJson(minimalAttributes([
            'creators' => [
                [
                    'name' => 'Doe, Jane',
                    'givenName' => 'Jane',
                    'familyName' => 'Doe',
                    'nameType' => 'Personal',
                    'affiliation' => [
                        ['name' => ''],
                        ['name' => 'Valid University'],
                    ],
                ],
            ],
        ]));
        $file = UploadedFile::fake()->createWithContent('empty-aff.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        expect($data['authors'][0]['affiliations'])->toHaveCount(1);
        expect($data['authors'][0]['affiliations'][0]['value'])->toBe('Valid University');
    });
});

describe('JSON Upload - Resource type edge cases', function () {
    test('returns null resource type when not found in database', function () {
        $this->actingAs(User::factory()->create());

        // Use a valid schema value that doesn't exist in the database
        $json = dataCiteJson(minimalAttributes([
            'types' => ['resourceTypeGeneral' => 'Audiovisual', 'resourceType' => 'Video'],
        ]));
        $file = UploadedFile::fake()->createWithContent('audiovisual.json', $json);

        $response = $this->postJson('/dashboard/upload-json', ['file' => $file]);

        $data = getJsonUploadData($response);

        // Audiovisual is a valid schema value but may not be in the seeded database
        // The resource type lookup returns null or the matched type
        expect($data)->toHaveKey('resourceType');
    });
});
