<?php

declare(strict_types=1);

use App\Services\CrossrefFunderRor\CrossrefFunderRorFundrefIndexMappingSource;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('returns no candidates when the local FundRef index is missing or unreadable', function (?string $contents): void {
    if ($contents !== null) {
        Storage::disk('local')->put(CrossrefFunderRorFundrefIndexMappingSource::INDEX_PATH, $contents);
    }

    $source = new CrossrefFunderRorFundrefIndexMappingSource;

    expect($source->candidatesForCrossrefFunderId('501100001659'))->toBe([]);
})->with([
    'missing file' => [null],
    'empty file' => ['  '],
    'malformed json' => ['{"data":'],
    'non-array json' => ['"not an index"'],
]);

it('loads candidates from the generated FundRef index and applies fallback provenance', function (): void {
    $fallbackSource = [
        'source' => 'ror_fundref_index',
        'source_file' => 'ror/ror-fundref-index.json',
        'source_retrieved_at' => '2026-06-24T00:00:00Z',
        'matching_strategy' => 'exact_fundref_external_id',
    ];

    Storage::disk('local')->put(
        CrossrefFunderRorFundrefIndexMappingSource::INDEX_PATH,
        json_encode([
            'source' => $fallbackSource,
            'data' => [
                [
                    'fundref' => '501100001659',
                    'candidates' => [
                        [
                            'ror_id' => 'https://ror.org/018mejw64',
                            'external_ids' => [
                                'fundref' => ['all' => ['501100001659']],
                            ],
                        ],
                        [
                            'ror_id' => 'https://ror.org/03yrm5c26',
                            'source' => ['source' => 'candidate_specific'],
                        ],
                        'not-a-candidate',
                    ],
                ],
                [
                    'fundref_id' => '501100004238',
                    'candidates' => [
                        ['ror_id' => 'https://ror.org/02nr0ka47'],
                    ],
                ],
                [
                    'fundref' => 'not-numeric',
                    'candidates' => [
                        ['ror_id' => 'https://ror.org/ignored'],
                    ],
                ],
                [
                    'ror_id' => 'https://ror.org/04z8jg394',
                    'external_ids' => [
                        'fundref' => [
                            'all' => ['501100010956', 'abc', '', ['nested'], 501100020000],
                        ],
                    ],
                ],
                ['external_ids' => ['fundref' => ['preferred' => '501100000000']]],
                'not-an-entry',
            ],
        ], JSON_THROW_ON_ERROR),
    );

    $source = new CrossrefFunderRorFundrefIndexMappingSource;

    $dfg = $source->candidatesForCrossrefFunderId('501100001659');
    $potsdam = $source->candidatesForCrossrefFunderId('501100004238');
    $gfz = $source->candidatesForFundref('501100010956');
    $numericFundref = $source->candidatesForCrossrefFunderId('501100020000');

    expect($dfg)->toHaveCount(2)
        ->and($dfg[0]['source'])->toBe($fallbackSource)
        ->and($dfg[1]['source'])->toBe(['source' => 'candidate_specific'])
        ->and($potsdam)->toHaveCount(1)
        ->and($potsdam[0]['source'])->toBe($fallbackSource)
        ->and($source->candidatesForCrossrefFunderId('not-numeric'))->toBe([])
        ->and($gfz)->toHaveCount(1)
        ->and($gfz[0]['ror_id'])->toBe('https://ror.org/04z8jg394')
        ->and($gfz[0]['source'])->toBe($fallbackSource)
        ->and($numericFundref)->toHaveCount(1)
        ->and($numericFundref[0]['ror_id'])->toBe('https://ror.org/04z8jg394');
});

it('loads legacy root-level candidate arrays without fallback provenance', function (): void {
    Storage::disk('local')->put(
        CrossrefFunderRorFundrefIndexMappingSource::INDEX_PATH,
        json_encode([
            [
                'ror_id' => 'https://ror.org/018mejw64',
                'external_ids' => [
                    'fundref' => ['all' => ['501100001659']],
                ],
            ],
        ], JSON_THROW_ON_ERROR),
    );

    $source = new CrossrefFunderRorFundrefIndexMappingSource;
    $candidates = $source->candidatesForCrossrefFunderId('501100001659');

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['ror_id'])->toBe('https://ror.org/018mejw64')
        ->and($candidates[0])->not->toHaveKey('source');
});
