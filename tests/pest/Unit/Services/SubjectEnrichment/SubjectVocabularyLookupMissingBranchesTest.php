<?php

declare(strict_types=1);

use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $data
 */
function lookupMissingPutVocabulary(string $fileName, array $data): void
{
    Storage::disk('local')->put($fileName, json_encode($data, JSON_THROW_ON_ERROR));
}

it('returns empty match sets for null or scheme-only lookup inputs', function (): void {
    $lookup = new SubjectVocabularyLookupService;

    expect($lookup->findGlobalById(null)->isEmpty())->toBeTrue()
        ->and($lookup->findUniqueLegacyPath('Science Keywords', null)->isEmpty())->toBeTrue()
        ->and($lookup->findUniqueLeafInScheme('Science Keywords', null)->isEmpty())->toBeTrue()
        ->and($lookup->findGlobalExactLabel(null)->isEmpty())->toBeTrue()
        ->and($lookup->findExactPath('Science Keywords', 'Science Keywords')->isEmpty())->toBeTrue();
});

it('ignores malformed vocabulary child and synonym containers while indexing valid nodes', function (): void {
    lookupMissingPutVocabulary('msl-vocabulary.json', [
        'data' => [
            [
                'id' => 'https://epos-msl.uu.nl/voc/material',
                'text' => 'Material',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'synonyms' => 'not-an-array',
                'aliases' => [true],
                'children' => [
                    'not-a-node',
                    [
                        'id' => 'https://epos-msl.uu.nl/voc/rock',
                        'text' => 'Rock',
                        'scheme' => 'EPOS MSL vocabulary',
                        'schemeURI' => 'https://epos-msl.uu.nl/voc',
                        'children' => [],
                    ],
                ],
            ],
        ],
    ]);

    $match = (new SubjectVocabularyLookupService)->findGlobalExactLabel('Rock');

    expect($match->isUnique())->toBeTrue()
        ->and($match->sole()?->path)->toBe('Material > Rock');
});

it('returns no legacy-path match when candidate leaves exist but ordered subsequence checks fail', function (): void {
    lookupMissingPutVocabulary('msl-vocabulary.json', [
        'data' => [
            [
                'id' => 'https://epos-msl.uu.nl/voc/material',
                'text' => 'Material',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'children' => [
                    [
                        'id' => 'https://epos-msl.uu.nl/voc/parent',
                        'text' => 'Parent',
                        'scheme' => 'EPOS MSL vocabulary',
                        'schemeURI' => 'https://epos-msl.uu.nl/voc',
                        'children' => [
                            [
                                'id' => 'https://epos-msl.uu.nl/voc/rock',
                                'text' => 'Rock',
                                'scheme' => 'EPOS MSL vocabulary',
                                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $lookup = new SubjectVocabularyLookupService;

    expect($lookup->findUniqueLegacyPath('EPOS MSL vocabulary', 'Material > Missing')->isEmpty())->toBeTrue()
        ->and($lookup->findUniqueLegacyPath('EPOS MSL vocabulary', 'Material > Extra > Parent > Rock')->isEmpty())->toBeTrue()
        ->and($lookup->findUniqueLegacyPath('EPOS MSL vocabulary', 'Material > Missing > Rock')->isEmpty())->toBeTrue();
});
