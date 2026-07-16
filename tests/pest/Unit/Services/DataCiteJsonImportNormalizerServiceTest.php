<?php

declare(strict_types=1);

use App\Services\DataCiteJsonImportNormalizerService;

beforeEach(function () {
    $this->normalizer = new DataCiteJsonImportNormalizerService;
});

it('removes only known DataCite API read attributes and derived type fields', function () {
    $normalized = $this->normalizer->normalize([
        'doi' => '10.5880/test',
        'prefix' => '10.5880',
        'suffix' => 'test',
        'state' => 'findable',
        'xml' => '<resource/>',
        'viewCount' => 5,
        'types' => [
            'resourceType' => 'Dataset',
            'resourceTypeGeneral' => 'Dataset',
            'ris' => 'DATA',
            'bibtex' => 'misc',
        ],
    ]);

    expect($normalized)->toBe([
        'doi' => '10.5880/test',
        'types' => [
            'resourceType' => 'Dataset',
            'resourceTypeGeneral' => 'Dataset',
        ],
    ]);
});

it('retains unknown fields so strict validation can reject them', function () {
    $normalized = $this->normalizer->normalize([
        'unknownRoot' => true,
        'types' => [
            'resourceTypeGeneral' => 'Dataset',
            'unknownDerivedField' => true,
        ],
    ]);

    expect($normalized)->toHaveKey('unknownRoot')
        ->and($normalized['types'])->toHaveKey('unknownDerivedField');
});

it('normalizes URI aliases recursively', function () {
    $normalized = $this->normalizer->normalize([
        'creators' => [[
            'name' => 'Example',
            'affiliation' => [[
                'name' => 'GFZ',
                'schemeURI' => 'https://ror.org/',
            ]],
        ]],
        'rightsList' => [[
            'rightsURI' => 'https://creativecommons.org/licenses/by/4.0/',
            'schemeURI' => 'https://spdx.org/licenses/',
        ]],
        'fundingReferences' => [[
            'funderName' => 'DFG',
            'awardURI' => 'https://example.org/award/1',
        ]],
        'subjects' => [[
            'subject' => 'Geology',
            'valueURI' => 'https://example.org/concept/1',
        ]],
    ]);

    expect($normalized['creators'][0]['affiliation'][0]['schemeUri'])->toBe('https://ror.org/')
        ->and($normalized['rightsList'][0]['rightsUri'])->toBe('https://creativecommons.org/licenses/by/4.0/')
        ->and($normalized['rightsList'][0]['schemeUri'])->toBe('https://spdx.org/licenses/')
        ->and($normalized['fundingReferences'][0]['awardUri'])->toBe('https://example.org/award/1')
        ->and($normalized['subjects'][0]['valueUri'])->toBe('https://example.org/concept/1');
});

it('collapses identical aliases but rejects conflicting values', function () {
    expect($this->normalizer->normalize([
        'rightsList' => [[
            'rightsUri' => 'https://example.org/license',
            'rightsURI' => 'https://example.org/license',
        ]],
    ])['rightsList'][0])->toBe([
        'rightsUri' => 'https://example.org/license',
    ]);

    expect(fn () => $this->normalizer->normalize([
        'rightsList' => [[
            'rightsUri' => 'https://example.org/license-a',
            'rightsURI' => 'https://example.org/license-b',
        ]],
    ]))->toThrow(InvalidArgumentException::class);
});

it('removes nulls and normalizes years without inventing required values', function () {
    $normalized = $this->normalizer->normalize([
        'publisher' => null,
        'publicationYear' => 2026,
        'relatedItems' => [[
            'publicationYear' => 2025,
            'volume' => null,
        ]],
    ]);

    expect($normalized)->not->toHaveKey('publisher')
        ->and($normalized['publicationYear'])->toBe('2026')
        ->and($normalized['relatedItems'][0]['publicationYear'])->toBe('2025')
        ->and($normalized['relatedItems'][0])->not->toHaveKey('volume');
});

it('removes only empty legacy date entries before strict validation', function () {
    $normalized = $this->normalizer->normalize([
        'dates' => [
            ['date' => '', 'dateType' => 'Created'],
            ['date' => '   ', 'dateType' => 'Updated'],
            ['date' => '2026-07-16', 'dateType' => 'Issued'],
            ['dateType' => 'Other'],
            ['date' => 2026, 'dateType' => 'Other'],
        ],
    ]);

    expect($normalized['dates'])->toBe([
        ['date' => '2026-07-16', 'dateType' => 'Issued'],
        ['dateType' => 'Other'],
        ['date' => 2026, 'dateType' => 'Other'],
    ]);

    expect($this->normalizer->normalize([
        'dates' => [['date' => '', 'dateType' => 'Created']],
    ]))->not->toHaveKey('dates');
});

it('normalizes official compact affiliations and numeric coordinate strings', function () {
    $normalized = $this->normalizer->normalize([
        'creators' => [[
            'name' => 'Doe, Jane',
            'affiliation' => ['GFZ', ['name' => 'DataCite']],
        ]],
        'geoLocations' => [[
            'geoLocationPoint' => [
                'pointLongitude' => ' 13.064 ',
                'pointLatitude' => '52.379',
            ],
            'geoLocationBox' => [
                'westBoundLongitude' => '-10',
                'eastBoundLongitude' => '10.5',
                'southBoundLatitude' => 'not-a-number',
                'northBoundLatitude' => 80,
            ],
        ]],
    ]);

    expect($normalized['creators'][0]['affiliation'])->toBe([
        ['name' => 'GFZ'],
        ['name' => 'DataCite'],
    ])->and($normalized['geoLocations'][0]['geoLocationPoint'])->toBe([
        'pointLongitude' => 13.064,
        'pointLatitude' => 52.379,
    ])->and($normalized['geoLocations'][0]['geoLocationBox'])->toBe([
        'westBoundLongitude' => -10.0,
        'eastBoundLongitude' => 10.5,
        'southBoundLatitude' => 'not-a-number',
        'northBoundLatitude' => 80,
    ]);
});

it('converts the legacy ERNIE polygon object to canonical DataCite API entries', function () {
    $normalized = $this->normalizer->normalize([
        'geoLocations' => [[
            'geoLocationPolygon' => [
                'polygonPoints' => [
                    ['pointLongitude' => '0', 'pointLatitude' => '0'],
                    ['pointLongitude' => '1', 'pointLatitude' => '0'],
                    ['pointLongitude' => '1', 'pointLatitude' => '1'],
                    ['pointLongitude' => '0', 'pointLatitude' => '0'],
                ],
                'inPolygonPoint' => ['pointLongitude' => '0.5', 'pointLatitude' => '0.5'],
            ],
        ]],
    ]);

    expect($normalized['geoLocations'][0]['geoLocationPolygon'])->toBe([
        ['polygonPoint' => ['pointLongitude' => 0.0, 'pointLatitude' => 0.0]],
        ['polygonPoint' => ['pointLongitude' => 1.0, 'pointLatitude' => 0.0]],
        ['polygonPoint' => ['pointLongitude' => 1.0, 'pointLatitude' => 1.0]],
        ['polygonPoint' => ['pointLongitude' => 0.0, 'pointLatitude' => 0.0]],
        ['inPolygonPoint' => ['pointLongitude' => 0.5, 'pointLatitude' => 0.5]],
    ]);
});

it('moves only legacy ERNIE DOI identifiers into the canonical doi attribute', function () {
    $normalized = $this->normalizer->normalize([
        'identifiers' => [
            ['identifier' => '10.5880/test', 'identifierType' => 'DOI'],
            ['identifier' => 'local-1', 'identifierType' => 'Local'],
        ],
    ]);

    expect($normalized['doi'])->toBe('10.5880/test')
        ->and($normalized['identifiers'])->toBe([
            ['identifier' => 'local-1', 'identifierType' => 'Local'],
        ]);
});
