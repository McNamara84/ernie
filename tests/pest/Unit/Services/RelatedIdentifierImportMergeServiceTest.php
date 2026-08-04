<?php

declare(strict_types=1);

use App\Services\RelatedIdentifierImportMergeService;

covers(RelatedIdentifierImportMergeService::class);

beforeEach(function (): void {
    $this->service = app(RelatedIdentifierImportMergeService::class);
});

it('merges JSON, XML, and legacy values in priority order without duplicates', function (): void {
    $merged = $this->service->merge(
        [
            [
                'relatedIdentifier' => 'https://doi.org/10.5880/JSON.WINS',
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'Cites',
                'relationTypeInformation' => 'JSON information',
            ],
        ],
        [
            [
                'relatedIdentifier' => '10.5880/json.wins',
                'relatedIdentifierType' => 'doi',
                'relationType' => 'Cites',
                'relationTypeInformation' => 'XML must not overwrite JSON',
                'resourceTypeGeneral' => 'Dataset',
            ],
            [
                'relatedIdentifier' => '10.5880/json.wins',
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'IsSupplementedBy',
            ],
        ],
        [
            [
                'identifier' => 'doi:10.5880/JSON.WINS',
                'identifierType' => 'DOI',
                'relationType' => 'Cites',
                'citation_label' => 'Legacy citation',
            ],
            [
                'identifier' => 'https://example.org/legacy-only',
                'identifierType' => 'URL',
                'relationType' => 'References',
            ],
        ],
    );

    expect($merged)->toHaveCount(3)
        ->and($merged[0])->toMatchArray([
            'relatedIdentifier' => 'https://doi.org/10.5880/JSON.WINS',
            'relatedIdentifierType' => 'DOI',
            'relationType' => 'Cites',
            'relationTypeInformation' => 'JSON information',
            'resourceTypeGeneral' => 'Dataset',
            'citationLabel' => 'Legacy citation',
        ])
        ->and($merged[1]['relationType'])->toBe('IsSupplementedBy')
        ->and($merged[2]['relatedIdentifier'])->toBe('https://example.org/legacy-only');
});

it('repairs the three incomplete JSON entries from issue 1077 by XML position', function (): void {
    $json = array_fill(0, 3, [
        'relatedIdentifierType' => 'DOI',
        'relationType' => 'IsSupplementedBy',
        'resourceTypeGeneral' => 'Dataset',
    ]);

    $xml = array_map(
        fn (string $doi): array => [
            'relatedIdentifier' => $doi,
            'relatedIdentifierType' => 'DOI',
            'relationType' => 'IsSupplementedBy',
            'resourceTypeGeneral' => 'Dataset',
        ],
        ['10.5880/CRC1211DB.86', '10.5880/CRC1211DB.87', '10.5880/CRC1211DB.89'],
    );

    $merged = $this->service->merge($json, $xml);

    expect(array_column($merged, 'relatedIdentifier'))->toBe([
        '10.5880/CRC1211DB.86',
        '10.5880/CRC1211DB.87',
        '10.5880/CRC1211DB.89',
    ]);
});

it('uses a unique compatible XML fallback when the positions differ', function (): void {
    $merged = $this->service->merge(
        [[
            'relatedIdentifierType' => 'DOI',
            'relationType' => 'Cites',
        ]],
        [
            [
                'relatedIdentifier' => 'https://example.org/other',
                'relatedIdentifierType' => 'URL',
                'relationType' => 'References',
            ],
            [
                'relatedIdentifier' => '10.5880/unique',
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'Cites',
            ],
        ],
    );

    expect(array_column($merged, 'relatedIdentifier'))->toBe([
        '10.5880/unique',
        'https://example.org/other',
    ]);
});

it('drops an ambiguous incomplete JSON shell but retains all complete XML records', function (): void {
    $merged = $this->service->merge(
        [[
            'relatedIdentifierType' => 'DOI',
            'relationType' => 'Cites',
        ]],
        [
            [
                'relatedIdentifier' => 'https://example.org/other',
                'relatedIdentifierType' => 'URL',
                'relationType' => 'References',
            ],
            [
                'relatedIdentifier' => '10.5880/first',
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'Cites',
            ],
            [
                'relatedIdentifier' => '10.5880/second',
                'relatedIdentifierType' => 'DOI',
                'relationType' => 'Cites',
            ],
        ],
    );

    expect(array_column($merged, 'relatedIdentifier'))->toBe([
        'https://example.org/other',
        '10.5880/first',
        '10.5880/second',
    ]);
});

it('skips incomplete lower-priority records and unsupported type values', function (): void {
    $merged = $this->service->merge(
        [['relatedIdentifier' => null, 'relatedIdentifierType' => 'DOI', 'relationType' => 'Cites']],
        [['relatedIdentifier' => '', 'relatedIdentifierType' => 'DOI', 'relationType' => 'Cites']],
        [
            ['identifier' => '10.5880/invalid', 'identifierType' => 'unknown', 'relationType' => 'Cites'],
            ['identifier' => '10.5880/valid', 'identifierType' => 'DOI', 'relationType' => 'Is Cited By'],
        ],
    );

    expect($merged)->toBe([[
        'relatedIdentifier' => '10.5880/valid',
        'relatedIdentifierType' => 'DOI',
        'relationType' => 'IsCitedBy',
    ]]);
});
