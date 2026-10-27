<?php

declare(strict_types=1);

use App\Models\FunderIdentifierType;
use App\Services\FundingAwardUri\FunderIdentityResolver;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FundingAwardUriFixtures as F;

beforeEach(function (): void {
    Storage::fake('local');
});

it('uses only typed identifiers and never guesses from names', function (string $type, string $id, ?string $expected): void {
    $funding = F::target();
    $funding->funder_identifier = $id;
    $funding->setRelation('funderIdentifierType', new FunderIdentifierType(['name' => $type]));
    expect(app(FunderIdentityResolver::class)->resolve($funding)?->crossrefId)->toBe($expected);
})->with([
    ['Crossref Funder ID', 'https://doi.org/10.13039/100010269', '10.13039/100010269'],
    ['Crossref Funder ID', '100010269', '10.13039/100010269'],
    ['Other', '100010269', null], ['Crossref Funder ID', 'Wellcome', null], ['ROR', 'https://ror.org/029chgv08', null],
]);

it('requires a recent unique active funder crosswalk', function (string $scenario, bool $valid): void {
    $funding = F::target(['funder_identifier' => 'https://ror.org/029chgv08']);
    $funding->setRelation('funderIdentifierType', new FunderIdentifierType(['name' => 'ROR']));
    $entry = ['ror_id' => 'https://ror.org/029chgv08', 'ror_status' => 'active', 'ror_types' => ['funder'],
        'external_ids' => ['fundref' => ['all' => ['100010269']]], 'source' => ['source_retrieved_at' => now()->toIso8601String()]];
    if ($scenario === 'stale') {
        $entry['source']['source_retrieved_at'] = now()->subDays(181)->toIso8601String();
    }
    if ($scenario === 'inactive') {
        $entry['ror_status'] = 'inactive';
    }
    if ($scenario === 'not funder') {
        $entry['ror_types'] = ['education'];
    }
    if ($scenario === 'multiple ids') {
        $entry['external_ids']['fundref']['all'][] = '1234';
    }
    if ($scenario === 'malformed ids') {
        $entry['external_ids']['fundref']['all'] = ['unexpected' => '100010269'];
    }
    $data = [$entry];
    if ($scenario === 'duplicate ror') {
        $data[] = $entry;
    }
    if ($scenario === 'duplicate funder') {
        $data[] = [...$entry, 'ror_id' => 'https://ror.org/04z8jg394'];
    }
    Storage::disk('local')->put('ror/ror-fundref-index.json', json_encode(['data' => $data], JSON_THROW_ON_ERROR));
    $identity = app(FunderIdentityResolver::class)->resolve($funding);
    expect($identity !== null)->toBe($valid);
    if ($valid) {
        expect($identity->crossrefId)->toBe('10.13039/100010269')->and($identity->provenance['source'])->toBe('ror_fundref_index');
    }
})->with([['valid', true], ['stale', false], ['inactive', false], ['not funder', false], ['multiple ids', false], ['malformed ids', false], ['duplicate ror', false], ['duplicate funder', false]]);

it('skips corrupt crosswalk files, invalid dates and invalid FundRef identifiers', function (string $scenario): void {
    $funding = F::target(['funder_identifier' => 'https://ror.org/029chgv08']);
    $funding->setRelation('funderIdentifierType', new FunderIdentifierType(['name' => 'ROR']));
    $entry = ['ror_id' => $funding->funder_identifier, 'ror_status' => 'active', 'ror_types' => ['funder'],
        'external_ids' => ['fundref' => ['all' => [$scenario === 'id' ? 'invalid' : '100010269']]],
        'source' => ['source_retrieved_at' => $scenario === 'date' ? 'invalid date' : now()->toIso8601String()]];
    Storage::disk('local')->put('ror/ror-fundref-index.json', $scenario === 'json' ? '{invalid' : json_encode(['data' => [$entry]], JSON_THROW_ON_ERROR));
    expect(app(FunderIdentityResolver::class)->resolve($funding))->toBeNull();
})->with(['json', 'date', 'id']);

it('requires valid sibling identifier lists before trusting a valid requested crosswalk row', function (mixed $siblingIds, string $status, bool $valid): void {
    $funding = F::target(['funder_identifier' => 'https://ror.org/029chgv08']);
    $funding->setRelation('funderIdentifierType', new FunderIdentifierType(['name' => 'ROR']));
    $entry = ['ror_id' => $funding->funder_identifier, 'ror_status' => 'active', 'ror_types' => ['funder'],
        'external_ids' => ['fundref' => ['all' => ['100010269']]],
        'source' => ['source_retrieved_at' => now()->toIso8601String()]];
    $sibling = [...$entry, 'ror_id' => 'https://ror.org/04z8jg394', 'ror_status' => $status,
        'external_ids' => ['fundref' => ['all' => $siblingIds]]];
    Storage::disk('local')->put('ror/ror-fundref-index.json', json_encode(['data' => [$entry, $sibling]], JSON_THROW_ON_ERROR));

    expect(app(FunderIdentityResolver::class)->resolve($funding)?->crossrefId)->toBe($valid ? '10.13039/100010269' : null);
})->with([
    'string instead of list' => ['501100001659', 'active', false],
    'associative list' => [['unexpected' => '501100001659'], 'active', false],
    'non-string identifier' => [[501100001659], 'active', false],
    'nested list' => [[['501100001659']], 'active', false],
    'invalid identifier' => [['invalid'], 'active', false],
    'valid unrelated sibling' => [['501100001659'], 'active', true],
    'empty sibling list' => [[], 'active', true],
    'inactive malformed sibling' => ['501100001659', 'inactive', true],
    'duplicate canonical identifier' => [['https://doi.org/10.13039/100010269'], 'active', false],
]);
