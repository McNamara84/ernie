<?php

declare(strict_types=1);

use App\Services\DataCiteJsonLdToJsonConverterService;

beforeEach(function () {
    $this->converter = new DataCiteJsonLdToJsonConverterService;
});

covers(DataCiteJsonLdToJsonConverterService::class);

describe('identifier conversion', function () {
    it('converts single identifier with attrs', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'identifier' => [
                'attrs' => ['identifierType' => 'DOI'],
                'value' => '10.5880/test.2025.001',
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['identifiers'])->toHaveCount(1);
        expect($result['identifiers'][0]['identifier'])->toBe('10.5880/test.2025.001');
        expect($result['identifiers'][0]['identifierType'])->toBe('DOI');
    });

    it('converts multiple identifiers wrapped in identifier key', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'identifier' => [
                'identifier' => [
                    [
                        'attrs' => ['identifierType' => 'DOI'],
                        'value' => '10.5880/first',
                    ],
                    [
                        'attrs' => ['identifierType' => 'Handle'],
                        'value' => 'hdl:20.500/second',
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['identifiers'])->toHaveCount(2);
        expect($result['identifiers'][0]['identifierType'])->toBe('DOI');
        expect($result['identifiers'][1]['identifierType'])->toBe('Handle');
    });

    it('returns empty array for unrecognized identifier format', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'identifier' => 'plain-string',
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['identifiers'])->toBe([]);
    });
});

describe('alternate identifiers conversion', function () {
    it('converts alternate identifiers with attrs', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'alternateIdentifiers' => [
                'alternateIdentifier' => [
                    'attrs' => ['alternateIdentifierType' => 'URL'],
                    'value' => 'https://example.org/data/123',
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['alternateIdentifiers'])->toHaveCount(1);
        expect($result['alternateIdentifiers'][0]['alternateIdentifier'])->toBe('https://example.org/data/123');
        expect($result['alternateIdentifiers'][0]['alternateIdentifierType'])->toBe('URL');
    });

    it('converts multiple alternate identifiers', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'alternateIdentifiers' => [
                'alternateIdentifier' => [
                    [
                        'attrs' => ['alternateIdentifierType' => 'URL'],
                        'value' => 'https://example.org/1',
                    ],
                    [
                        'attrs' => ['alternateIdentifierType' => 'ISBN'],
                        'value' => '978-3-16-148410-0',
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['alternateIdentifiers'])->toHaveCount(2);
        expect($result['alternateIdentifiers'][0]['alternateIdentifierType'])->toBe('URL');
        expect($result['alternateIdentifiers'][1]['alternateIdentifierType'])->toBe('ISBN');
    });
});

describe('publisher conversion with attrs', function () {
    it('converts publisher with all attrs', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'publisher' => [
                'attrs' => [
                    'publisherIdentifier' => 'https://ror.org/04z8jg394',
                    'publisherIdentifierScheme' => 'ROR',
                    'schemeUri' => 'https://ror.org/',
                    'lang' => 'en',
                ],
                'value' => 'GFZ Data Services',
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['publisher']['name'])->toBe('GFZ Data Services');
        expect($result['publisher']['publisherIdentifier'])->toBe('https://ror.org/04z8jg394');
        expect($result['publisher']['publisherIdentifierScheme'])->toBe('ROR');
        expect($result['publisher']['schemeUri'])->toBe('https://ror.org/');
        expect($result['publisher']['lang'])->toBe('en');
    });

    it('converts publisher without attrs', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'publisher' => ['value' => 'Simple Publisher'],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['publisher']['name'])->toBe('Simple Publisher');
        expect($result['publisher'])->not->toHaveKey('publisherIdentifier');
    });
});

describe('sizes conversion', function () {
    it('converts sizes with value wrapping', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'sizes' => [
                'size' => ['value' => '1.5 GB'],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['sizes'])->toBe(['1.5 GB']);
    });

    it('converts multiple sizes', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'sizes' => [
                'size' => [
                    ['value' => '100 MB'],
                    ['value' => '50 files'],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['sizes'])->toBe(['100 MB', '50 files']);
    });
});

describe('geo location polygon conversion', function () {
    it('converts polygon with points and inPolygonPoint', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'geoLocations' => [
                'geoLocation' => [
                    'geoLocationPolygon' => [
                        'polygonPoint' => [
                            [
                                'pointLongitude' => ['value' => '13.0'],
                                'pointLatitude' => ['value' => '52.0'],
                            ],
                            [
                                'pointLongitude' => ['value' => '14.0'],
                                'pointLatitude' => ['value' => '53.0'],
                            ],
                            [
                                'pointLongitude' => ['value' => '13.5'],
                                'pointLatitude' => ['value' => '52.5'],
                            ],
                            [
                                'pointLongitude' => ['value' => '13.0'],
                                'pointLatitude' => ['value' => '52.0'],
                            ],
                        ],
                        'inPolygonPoint' => [
                            'pointLongitude' => ['value' => '13.3'],
                            'pointLatitude' => ['value' => '52.3'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);
        $polygon = $result['geoLocations'][0]['geoLocationPolygon'];

        expect($polygon['polygonPoints'])->toHaveCount(4);
        expect($polygon['polygonPoints'][0]['pointLongitude'])->toBe('13.0');
        expect($polygon['polygonPoints'][0]['pointLatitude'])->toBe('52.0');
        expect($polygon['inPolygonPoint']['pointLongitude'])->toBe('13.3');
        expect($polygon['inPolygonPoint']['pointLatitude'])->toBe('52.3');
    });

    it('converts polygon without inPolygonPoint', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'geoLocations' => [
                'geoLocation' => [
                    'geoLocationPolygon' => [
                        'polygonPoint' => [
                            [
                                'pointLongitude' => ['value' => '10'],
                                'pointLatitude' => ['value' => '50'],
                            ],
                            [
                                'pointLongitude' => ['value' => '11'],
                                'pointLatitude' => ['value' => '51'],
                            ],
                            [
                                'pointLongitude' => ['value' => '10'],
                                'pointLatitude' => ['value' => '50'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);
        $polygon = $result['geoLocations'][0]['geoLocationPolygon'];

        expect($polygon['polygonPoints'])->toHaveCount(3);
        expect($polygon)->not->toHaveKey('inPolygonPoint');
    });
});

describe('contributor conversion edge cases', function () {
    it('converts contributor with name identifiers and affiliations', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'contributors' => [
                'contributor' => [
                    'attrs' => ['contributorType' => 'Researcher'],
                    'contributorName' => [
                        'attrs' => ['nameType' => 'Personal'],
                        'value' => 'Mueller, Max',
                    ],
                    'givenName' => ['value' => 'Max'],
                    'familyName' => ['value' => 'Mueller'],
                    'nameIdentifier' => [
                        'attrs' => [
                            'nameIdentifierScheme' => 'ORCID',
                            'schemeUri' => 'https://orcid.org',
                        ],
                        'value' => '0000-0002-1234-5678',
                    ],
                    'affiliation' => [
                        'attrs' => [
                            'affiliationIdentifier' => 'https://ror.org/04z8jg394',
                            'affiliationIdentifierScheme' => 'ROR',
                        ],
                        'value' => 'GFZ Potsdam',
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);
        $contributor = $result['contributors'][0];

        expect($contributor['contributorType'])->toBe('Researcher');
        expect($contributor['nameType'])->toBe('Personal');
        expect($contributor['name'])->toBe('Mueller, Max');
        expect($contributor['givenName'])->toBe('Max');
        expect($contributor['familyName'])->toBe('Mueller');
        expect($contributor['nameIdentifiers'])->toHaveCount(1);
        expect($contributor['nameIdentifiers'][0]['nameIdentifierScheme'])->toBe('ORCID');
        expect($contributor['affiliation'])->toHaveCount(1);
        expect($contributor['affiliation'][0]['name'])->toBe('GFZ Potsdam');
    });
});

describe('rights list conversion edge cases', function () {
    it('converts rights with all attrs', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'rightsList' => [
                'rights' => [
                    'attrs' => [
                        'rightsURI' => 'https://creativecommons.org/licenses/by/4.0/',
                        'rightsIdentifier' => 'CC-BY-4.0',
                        'rightsIdentifierScheme' => 'SPDX',
                        'schemeURI' => 'https://spdx.org/licenses/',
                        'lang' => 'en',
                    ],
                    'value' => 'Creative Commons Attribution 4.0',
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);
        $rights = $result['rightsList'][0];

        expect($rights['rights'])->toBe('Creative Commons Attribution 4.0');
        expect($rights['rightsURI'])->toBe('https://creativecommons.org/licenses/by/4.0/');
        expect($rights['rightsIdentifier'])->toBe('CC-BY-4.0');
        expect($rights['rightsIdentifierScheme'])->toBe('SPDX');
        expect($rights['schemeURI'])->toBe('https://spdx.org/licenses/');
        expect($rights['lang'])->toBe('en');
    });

    it('converts multiple rights entries', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'rightsList' => [
                'rights' => [
                    [
                        'attrs' => ['rightsIdentifier' => 'CC-BY-4.0'],
                        'value' => 'CC BY 4.0',
                    ],
                    [
                        'attrs' => ['rightsIdentifier' => 'CC0-1.0'],
                        'value' => 'CC0',
                    ],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['rightsList'])->toHaveCount(2);
        expect($result['rightsList'][0]['rightsIdentifier'])->toBe('CC-BY-4.0');
        expect($result['rightsList'][1]['rightsIdentifier'])->toBe('CC0-1.0');
    });
});

describe('funding reference edge cases', function () {
    it('converts funding reference with funderIdentifier attrs', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'fundingReferences' => [
                'fundingReference' => [
                    'funderName' => ['value' => 'NSF'],
                    'funderIdentifier' => [
                        'attrs' => [
                            'funderIdentifierType' => 'Crossref Funder ID',
                            'schemeUri' => 'https://doi.org/',
                        ],
                        'value' => 'https://doi.org/10.13039/100000001',
                    ],
                    'awardNumber' => [
                        'attrs' => ['awardUri' => 'https://nsf.gov/award/123'],
                        'value' => 'EAR-1234567',
                    ],
                    'awardTitle' => ['value' => 'Seismology Research'],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);
        $funding = $result['fundingReferences'][0];

        expect($funding['funderName'])->toBe('NSF');
        expect($funding['funderIdentifier'])->toBe('https://doi.org/10.13039/100000001');
        expect($funding['funderIdentifierType'])->toBe('Crossref Funder ID');
        expect($funding['schemeUri'])->toBe('https://doi.org/');
        expect($funding['awardNumber'])->toBe('EAR-1234567');
        expect($funding['awardUri'])->toBe('https://nsf.gov/award/123');
        expect($funding['awardTitle'])->toBe('Seismology Research');
    });

    it('converts funding reference without optional attrs', function () {
        $jsonLd = [
            '@context' => 'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
            'fundingReferences' => [
                'fundingReference' => [
                    'funderName' => ['value' => 'DFG'],
                ],
            ],
        ];

        $result = $this->converter->convert($jsonLd);

        expect($result['fundingReferences'][0]['funderName'])->toBe('DFG');
        expect($result['fundingReferences'][0])->not->toHaveKey('funderIdentifier');
        expect($result['fundingReferences'][0])->not->toHaveKey('awardNumber');
    });
});
