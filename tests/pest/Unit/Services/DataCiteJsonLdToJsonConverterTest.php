<?php

declare(strict_types=1);

use App\Services\DataCiteJsonLdToJsonConverterService;

beforeEach(function () {
    $this->converter = new DataCiteJsonLdToJsonConverterService;
});

covers(DataCiteJsonLdToJsonConverterService::class);

describe('format detection and basic conversion', function () {
    it('strips @context and @id from root', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            '@id' => 'https://doi.org/10.5880/test.2025.001',
            'titles' => ['title' => ['value' => 'Test']],
            'creators' => ['creator' => ['creatorName' => ['value' => 'Smith, John']]],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result)->not->toHaveKey('@context');
        expect($result)->not->toHaveKey('@id');
    });

    it('extracts DOI from @id', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            '@id' => 'https://doi.org/10.5880/test.2025.001',
            'titles' => ['title' => ['value' => 'Test']],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['doi'])->toBe('10.5880/test.2025.001');
    });

    it('handles missing @id gracefully', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'titles' => ['title' => ['value' => 'Test']],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result)->not->toHaveKey('doi');
    });

    it('handles full DOI URL in @id', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            '@id' => 'https://doi.org/10.14470/FX828672',
            'titles' => ['title' => ['value' => 'Test']],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['doi'])->toBe('10.14470/FX828672');
    });
});

describe('titles conversion', function () {
    it('unwraps attrs/value pattern', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'titles' => [
                'title' => [
                    'attrs' => ['titleType' => 'Subtitle'],
                    'value' => 'A Subtitle',
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['titles'][0])->toBe([
            'title' => 'A Subtitle',
            'titleType' => 'Subtitle',
        ]);
    });

    it('handles plain string title', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'titles' => [
                'title' => ['value' => 'Main Title'],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['titles'][0]['title'])->toBe('Main Title');
    });

    it('handles multiple titles', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'titles' => [
                'title' => [
                    ['value' => 'Main Title'],
                    [
                        'attrs' => ['titleType' => 'Subtitle'],
                        'value' => 'A Subtitle',
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['titles'])->toHaveCount(2);
        expect($result['titles'][0]['title'])->toBe('Main Title');
        expect($result['titles'][1]['title'])->toBe('A Subtitle');
        expect($result['titles'][1]['titleType'])->toBe('Subtitle');
    });
});

describe('creators conversion', function () {
    it('converts personal creators with ORCID', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'creators' => [
                'creator' => [
                    'creatorName' => [
                        'attrs' => ['nameType' => 'Personal'],
                        'value' => 'Smith, John',
                    ],
                    'givenName' => ['value' => 'John'],
                    'familyName' => ['value' => 'Smith'],
                    'nameIdentifier' => [
                        'attrs' => [
                            'nameIdentifierScheme' => 'ORCID',
                            'schemeUri' => 'https://orcid.org',
                        ],
                        'value' => 'https://orcid.org/0000-0001-2345-6789',
                    ],
                    'affiliation' => [
                        'attrs' => [
                            'affiliationIdentifier' => 'https://ror.org/04z8jg394',
                            'affiliationIdentifierScheme' => 'ROR',
                        ],
                        'value' => 'GFZ German Research Centre for Geosciences',
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);
        $creator = $result['creators'][0];

        expect($creator['name'])->toBe('Smith, John');
        expect($creator['nameType'])->toBe('Personal');
        expect($creator['givenName'])->toBe('John');
        expect($creator['familyName'])->toBe('Smith');
        expect($creator['nameIdentifiers'][0]['nameIdentifier'])->toBe('https://orcid.org/0000-0001-2345-6789');
        expect($creator['nameIdentifiers'][0]['nameIdentifierScheme'])->toBe('ORCID');
        expect($creator['affiliation'][0]['name'])->toBe('GFZ German Research Centre for Geosciences');
        expect($creator['affiliation'][0]['affiliationIdentifier'])->toBe('https://ror.org/04z8jg394');
    });

    it('handles multiple creators', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'creators' => [
                'creator' => [
                    [
                        'creatorName' => ['value' => 'Smith, John'],
                        'givenName' => ['value' => 'John'],
                        'familyName' => ['value' => 'Smith'],
                    ],
                    [
                        'creatorName' => ['value' => 'Doe, Jane'],
                        'givenName' => ['value' => 'Jane'],
                        'familyName' => ['value' => 'Doe'],
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['creators'])->toHaveCount(2);
        expect($result['creators'][0]['name'])->toBe('Smith, John');
        expect($result['creators'][1]['name'])->toBe('Doe, Jane');
    });
});

describe('contributors conversion', function () {
    it('converts contributors with type', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'contributors' => [
                'contributor' => [
                    'attrs' => ['contributorType' => 'DataCollector'],
                    'contributorName' => [
                        'attrs' => ['nameType' => 'Personal'],
                        'value' => 'Doe, Jane',
                    ],
                    'givenName' => ['value' => 'Jane'],
                    'familyName' => ['value' => 'Doe'],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['contributors'][0]['contributorType'])->toBe('DataCollector');
        expect($result['contributors'][0]['name'])->toBe('Doe, Jane');
        expect($result['contributors'][0]['givenName'])->toBe('Jane');
        expect($result['contributors'][0]['familyName'])->toBe('Doe');
    });
});

describe('subjects conversion', function () {
    it('unwraps attrs/value pattern in subjects', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'subjects' => [
                'subject' => [
                    'attrs' => [
                        'subjectScheme' => 'NASA/GCMD Science Keywords',
                        'schemeUri' => 'https://gcmd.earthdata.nasa.gov/kms',
                        'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/abc123',
                    ],
                    'value' => 'Earth Science > Solid Earth',
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['subjects'][0]['subject'])->toBe('Earth Science > Solid Earth');
        expect($result['subjects'][0]['subjectScheme'])->toBe('NASA/GCMD Science Keywords');
        expect($result['subjects'][0]['valueUri'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/abc123');
    });

    it('handles plain subjects without attrs', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'subjects' => [
                'subject' => ['value' => 'Free keyword'],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['subjects'][0]['subject'])->toBe('Free keyword');
    });

    it('handles multiple subjects', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'subjects' => [
                'subject' => [
                    [
                        'attrs' => ['subjectScheme' => 'GCMD'],
                        'value' => 'Earth Science',
                    ],
                    ['value' => 'Free keyword'],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['subjects'])->toHaveCount(2);
        expect($result['subjects'][0]['subject'])->toBe('Earth Science');
        expect($result['subjects'][1]['subject'])->toBe('Free keyword');
    });
});

describe('geoLocations conversion', function () {
    it('converts geoLocation point with JSON-LD value wrapping', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'geoLocations' => [
                'geoLocation' => [
                    'geoLocationPlace' => ['value' => 'Potsdam, Germany'],
                    'geoLocationPoint' => [
                        'pointLatitude' => ['value' => '52.38'],
                        'pointLongitude' => ['value' => '13.06'],
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['geoLocations'][0]['geoLocationPoint']['pointLatitude'])->toBe('52.38');
        expect($result['geoLocations'][0]['geoLocationPoint']['pointLongitude'])->toBe('13.06');
        expect($result['geoLocations'][0]['geoLocationPlace'])->toBe('Potsdam, Germany');
    });

    it('converts geoLocation box with JSON-LD value wrapping', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'geoLocations' => [
                'geoLocation' => [
                    'geoLocationBox' => [
                        'westBoundLongitude' => ['value' => '-180'],
                        'eastBoundLongitude' => ['value' => '180'],
                        'southBoundLatitude' => ['value' => '-90'],
                        'northBoundLatitude' => ['value' => '90'],
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);
        $box = $result['geoLocations'][0]['geoLocationBox'];

        expect($box['westBoundLongitude'])->toBe('-180');
        expect($box['eastBoundLongitude'])->toBe('180');
        expect($box['southBoundLatitude'])->toBe('-90');
        expect($box['northBoundLatitude'])->toBe('90');
    });
});

describe('dates conversion', function () {
    it('converts dates with type', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'dates' => [
                'date' => [
                    'attrs' => ['dateType' => 'Created'],
                    'value' => '2025-01-15',
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['dates'][0]['date'])->toBe('2025-01-15');
        expect($result['dates'][0]['dateType'])->toBe('Created');
    });

    it('handles multiple dates', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'dates' => [
                'date' => [
                    [
                        'attrs' => ['dateType' => 'Created'],
                        'value' => '2025-01-15',
                    ],
                    [
                        'attrs' => ['dateType' => 'Issued'],
                        'value' => '2025-06-01',
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['dates'])->toHaveCount(2);
        expect($result['dates'][0]['dateType'])->toBe('Created');
        expect($result['dates'][1]['dateType'])->toBe('Issued');
    });
});

describe('fundingReferences conversion', function () {
    it('converts funding references with attrs/value pattern', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'fundingReferences' => [
                'fundingReference' => [
                    'funderName' => ['value' => 'Deutsche Forschungsgemeinschaft'],
                    'funderIdentifier' => [
                        'attrs' => ['funderIdentifierType' => 'Crossref Funder ID'],
                        'value' => 'https://doi.org/10.13039/501100001659',
                    ],
                    'awardNumber' => [
                        'attrs' => ['awardUri' => 'https://example.org/award/123'],
                        'value' => 'ABC-123',
                    ],
                    'awardTitle' => ['value' => 'Research Project Title'],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);
        $funding = $result['fundingReferences'][0];

        expect($funding['funderName'])->toBe('Deutsche Forschungsgemeinschaft');
        expect($funding['funderIdentifier'])->toBe('https://doi.org/10.13039/501100001659');
        expect($funding['funderIdentifierType'])->toBe('Crossref Funder ID');
        expect($funding['awardNumber'])->toBe('ABC-123');
        expect($funding['awardUri'])->toBe('https://example.org/award/123');
        expect($funding['awardTitle'])->toBe('Research Project Title');
    });
});

describe('relatedIdentifiers conversion', function () {
    it('converts related identifiers', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'relatedIdentifiers' => [
                'relatedIdentifier' => [
                    'attrs' => [
                        'relatedIdentifierType' => 'DOI',
                        'relationType' => 'Cites',
                    ],
                    'value' => '10.1234/related',
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);
        $ri = $result['relatedIdentifiers'][0];

        expect($ri['relatedIdentifier'])->toBe('10.1234/related');
        expect($ri['relatedIdentifierType'])->toBe('DOI');
        expect($ri['relationType'])->toBe('Cites');
    });
});

describe('descriptions conversion', function () {
    it('converts descriptions with type', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'descriptions' => [
                'description' => [
                    'attrs' => ['descriptionType' => 'Abstract'],
                    'value' => 'This is the abstract.',
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['descriptions'][0]['description'])->toBe('This is the abstract.');
        expect($result['descriptions'][0]['descriptionType'])->toBe('Abstract');
    });
});

describe('rightsList conversion', function () {
    it('converts rights with identifier', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'rightsList' => [
                'rights' => [
                    'attrs' => [
                        'rightsIdentifier' => 'CC-BY-4.0',
                        'rightsURI' => 'https://creativecommons.org/licenses/by/4.0/',
                    ],
                    'value' => 'Creative Commons Attribution 4.0 International',
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['rightsList'][0]['rightsIdentifier'])->toBe('CC-BY-4.0');
        expect($result['rightsList'][0]['rightsURI'])->toBe('https://creativecommons.org/licenses/by/4.0/');
        expect($result['rightsList'][0]['rights'])->toBe('Creative Commons Attribution 4.0 International');
    });
});

describe('scalar fields passthrough', function () {
    it('unwraps value-wrapped scalars', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'publicationYear' => ['value' => '2025'],
            'version' => ['value' => '1.0'],
            'language' => ['value' => 'en'],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['publicationYear'])->toBe('2025');
        expect($result['version'])->toBe('1.0');
        expect($result['language'])->toBe('en');
    });

    it('passes through plain scalar values', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'publicationYear' => '2025',
            'version' => '1.0',
            'language' => 'en',
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['publicationYear'])->toBe('2025');
        expect($result['version'])->toBe('1.0');
        expect($result['language'])->toBe('en');
    });

    it('converts resourceType with attrs', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'resourceType' => [
                'attrs' => ['resourceTypeGeneral' => 'Dataset'],
                'value' => 'DataCite Dataset',
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['types']['resourceTypeGeneral'])->toBe('Dataset');
        expect($result['types']['resourceType'])->toBe('DataCite Dataset');
    });
});
