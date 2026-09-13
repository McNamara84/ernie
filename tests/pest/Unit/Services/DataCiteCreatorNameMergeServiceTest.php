<?php

declare(strict_types=1);

use App\Services\DataCiteCreatorNameMergeService;

covers(DataCiteCreatorNameMergeService::class);

beforeEach(function (): void {
    $this->service = new DataCiteCreatorNameMergeService;
    $this->orcid = static fn (string $id): array => [[
        'nameIdentifier' => $id,
        'nameIdentifierScheme' => 'ORCID',
    ]];
});

it('enriches a creator with a unique matching ORCID and a richer initial', function (): void {
    $current = [[
        'name' => 'Sommer, Philipp',
        'givenName' => 'Philipp',
        'familyName' => 'Sommer',
        'nameIdentifiers' => ($this->orcid)('0000-0001-6171-7716'),
        'affiliation' => [['name' => 'GFZ']],
    ]];
    $legacy = [[
        'name' => 'Sommer, Philipp S.',
        'givenName' => 'Philipp S.',
        'familyName' => 'Sommer',
        'nameIdentifiers' => ($this->orcid)('https://orcid.org/0000-0001-6171-7716'),
    ]];

    $result = $this->service->mergeWithReport($current, $legacy);

    expect($result['creators'][0])->toBe([
        ...$current[0],
        'name' => 'Sommer, Philipp S.',
        'givenName' => 'Philipp S.',
        'familyName' => 'Sommer',
    ])->and($result['matches'][0])->toMatchArray([
        'method' => 'orcid',
        'status' => 'merged',
        'legacy_index' => 0,
    ]);
});

it('uses compatible position and family name only when ORCIDs do not conflict', function (): void {
    $current = [[
        'name' => 'Sommer, Philipp',
        'givenName' => 'Philipp',
        'familyName' => 'Sommer',
    ]];
    $legacy = [[
        'name' => 'Sommer, Philipp S.',
        'givenName' => 'Philipp S.',
        'familyName' => 'Sommer',
    ]];

    expect($this->service->mergeWithReport($current, $legacy)['matches'][0])
        ->toMatchArray(['method' => 'position_and_name', 'status' => 'merged']);
});

it('does not overwrite opposite initials or conflicting ORCIDs', function (): void {
    $current = [[
        'name' => 'Sommer, Philipp A.',
        'givenName' => 'Philipp A.',
        'familyName' => 'Sommer',
        'nameIdentifiers' => ($this->orcid)('0000-0001-6171-7716'),
    ]];
    $legacy = [[
        'name' => 'Sommer, Philipp S.',
        'givenName' => 'Philipp S.',
        'familyName' => 'Sommer',
        'nameIdentifiers' => ($this->orcid)('0000-0002-1825-0097'),
    ]];

    expect($this->service->merge($current, $legacy))->toBe($current);
});

it('does not overwrite a non-empty unstructured name with a different ORCID-matched name', function (
    string $currentName,
    string $legacyName,
): void {
    $identifier = ($this->orcid)('0000-0001-6171-7716');
    $current = [[
        'name' => $currentName,
        'nameIdentifiers' => $identifier,
    ]];
    $legacy = [[
        'name' => $legacyName,
        'nameIdentifiers' => $identifier,
    ]];

    $result = $this->service->mergeWithReport($current, $legacy);

    expect($result['creators'])->toBe($current)
        ->and($result['matches'][0])->toMatchArray([
            'legacy_index' => 0,
            'method' => 'orcid',
            'status' => 'not_richer',
        ]);
})->with([
    'different normalized names' => ['The Artist', 'A Different Artist'],
    'non-normalizable current name is not empty' => ['!!!', 'A Different Artist'],
]);

it('accepts normalized-equivalent unstructured names for an ORCID match', function (): void {
    $identifier = ($this->orcid)('0000-0001-6171-7716');
    $current = [[
        'name' => 'The Artist',
        'nameIdentifiers' => $identifier,
    ]];
    $legacy = [[
        'name' => 'THE-ARTIST',
        'nameIdentifiers' => $identifier,
    ]];

    $result = $this->service->mergeWithReport($current, $legacy);

    expect($result['creators'][0]['name'])->toBe('THE-ARTIST')
        ->and($result['matches'][0])->toMatchArray([
            'legacy_index' => 0,
            'method' => 'orcid',
            'status' => 'merged',
        ]);
});

it('enriches an empty unstructured name from a unique ORCID match', function (): void {
    $identifier = ($this->orcid)('0000-0001-6171-7716');
    $current = [['nameIdentifiers' => $identifier]];
    $legacy = [[
        'name' => 'The Artist',
        'nameIdentifiers' => $identifier,
    ]];

    $result = $this->service->mergeWithReport($current, $legacy);

    expect($result['creators'][0]['name'])->toBe('The Artist')
        ->and($result['matches'][0])->toMatchArray([
            'legacy_index' => 0,
            'method' => 'orcid',
            'status' => 'merged',
        ]);
});

it('rejects ambiguous duplicate ORCID matches across one resource', function (): void {
    $identifier = ($this->orcid)('0000-0001-6171-7716');
    $current = [
        ['name' => 'Sommer, Philipp', 'familyName' => 'Sommer', 'givenName' => 'Philipp', 'nameIdentifiers' => $identifier],
        ['name' => 'Sommer, P.', 'familyName' => 'Sommer', 'givenName' => 'P.', 'nameIdentifiers' => $identifier],
    ];
    $legacy = [[
        'name' => 'Sommer, Philipp S.',
        'familyName' => 'Sommer',
        'givenName' => 'Philipp S.',
        'nameIdentifiers' => $identifier,
    ]];

    $result = $this->service->mergeWithReport($current, $legacy);

    expect($result['creators'])->toBe($current)
        ->and(array_column($result['matches'], 'status'))->toBe(['ambiguous', 'ambiguous']);
});

it('preserves family and given name positions during fallback matching', function (): void {
    $current = [[
        'name' => 'Lee',
        'familyName' => 'Lee',
    ]];
    $legacy = [[
        'name' => 'Lee',
        'givenName' => 'Lee',
    ]];

    $result = $this->service->mergeWithReport($current, $legacy);

    expect($result['creators'])->toBe($current)
        ->and($result['matches'][0])->toMatchArray([
            'legacy_index' => null,
            'method' => 'none',
            'status' => 'unmatched',
        ]);
});

it('merges wrapped and flat DOI records while preserving non-name metadata', function (): void {
    $legacy = [['name' => 'Sommer, Philipp S.', 'givenName' => 'Philipp S.', 'familyName' => 'Sommer']];

    expect($this->service->mergeIntoDoiRecord([
        'attributes' => ['creators' => [['name' => 'Sommer, Philipp', 'givenName' => 'Philipp', 'familyName' => 'Sommer']]],
    ], $legacy)['attributes']['creators'][0]['givenName'])->toBe('Philipp S.')
        ->and($this->service->mergeIntoDoiRecord([
            'creators' => [['name' => 'Sommer, Philipp', 'givenName' => 'Philipp', 'familyName' => 'Sommer']],
        ], $legacy)['creators'][0]['givenName'])->toBe('Philipp S.');
});
