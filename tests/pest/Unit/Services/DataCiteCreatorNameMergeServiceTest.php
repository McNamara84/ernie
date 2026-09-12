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

it('merges wrapped and flat DOI records while preserving non-name metadata', function (): void {
    $legacy = [['name' => 'Sommer, Philipp S.', 'givenName' => 'Philipp S.', 'familyName' => 'Sommer']];

    expect($this->service->mergeIntoDoiRecord([
        'attributes' => ['creators' => [['name' => 'Sommer, Philipp', 'givenName' => 'Philipp', 'familyName' => 'Sommer']]],
    ], $legacy)['attributes']['creators'][0]['givenName'])->toBe('Philipp S.')
        ->and($this->service->mergeIntoDoiRecord([
            'creators' => [['name' => 'Sommer, Philipp', 'givenName' => 'Philipp', 'familyName' => 'Sommer']],
        ], $legacy)['creators'][0]['givenName'])->toBe('Philipp S.');
});
