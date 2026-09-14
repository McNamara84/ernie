<?php

declare(strict_types=1);

use App\Models\OldDataset;
use App\Services\LegacyCreatorNameService;
use Illuminate\Support\Facades\Log;

covers(LegacyCreatorNameService::class);

it('maps SUMARIO creator spellings and valid ORCIDs to DataCite names', function (): void {
    $dataset = Mockery::mock(OldDataset::class)->makePartial();
    $dataset->shouldReceive('getAuthors')->once()->andReturn([
        [
            'givenName' => 'Philipp S.',
            'familyName' => 'Sommer',
            'name' => 'Sommer, Philipp S.',
            'orcid' => 'https://orcid.org/0000-0001-6171-7716',
        ],
        [
            'givenName' => null,
            'familyName' => 'Legacy Family',
            'name' => '',
            'orcid' => 'invalid',
        ],
    ]);

    expect((new LegacyCreatorNameService)->dataCiteCreators($dataset))->toBe([
        [
            'name' => 'Sommer, Philipp S.',
            'nameType' => 'Personal',
            'givenName' => 'Philipp S.',
            'familyName' => 'Sommer',
            'nameIdentifiers' => [[
                'nameIdentifier' => '0000-0001-6171-7716',
                'nameIdentifierScheme' => 'ORCID',
                'schemeUri' => 'https://orcid.org/',
            ]],
        ],
        [
            'name' => 'Legacy Family',
            'nameType' => 'Personal',
            'familyName' => 'Legacy Family',
        ],
    ]);
});

it('builds a creator name when a partial SUMARIO author omits the name key', function (): void {
    $dataset = Mockery::mock(OldDataset::class)->makePartial();
    $dataset->shouldReceive('getAuthors')->once()->andReturn([[
        'givenName' => 'Ada',
        'familyName' => 'Lovelace',
    ]]);

    expect((new LegacyCreatorNameService)->dataCiteCreators($dataset))->toBe([[
        'name' => 'Lovelace, Ada',
        'nameType' => 'Personal',
        'givenName' => 'Ada',
        'familyName' => 'Lovelace',
    ]]);
});

it('keeps import lookup best effort but exposes read failures to strict backfills', function (): void {
    Log::spy();
    $bestEffortDataset = Mockery::mock(OldDataset::class)->makePartial();
    $bestEffortDataset->shouldReceive('getAuthors')->once()->andThrow(new RuntimeException('SUMARIO unavailable'));

    expect((new LegacyCreatorNameService)->dataCiteCreators($bestEffortDataset))->toBe([]);
    Log::shouldHaveReceived('warning')->once();

    $strictDataset = Mockery::mock(OldDataset::class)->makePartial();
    $strictDataset->shouldReceive('getAuthors')->once()->andThrow(new RuntimeException('SUMARIO unavailable'));

    expect(fn () => (new LegacyCreatorNameService)->dataCiteCreators($strictDataset, bestEffort: false))
        ->toThrow(RuntimeException::class, 'SUMARIO unavailable');
});
