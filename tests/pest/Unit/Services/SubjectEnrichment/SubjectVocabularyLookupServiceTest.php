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
function subjectEnrichmentPutLocalVocabulary(string $fileName, array $data): void
{
    Storage::disk('local')->put($fileName, json_encode($data, JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, mixed>
 */
function subjectEnrichmentGcmdScienceKeywordsData(string $conceptId = 'https://cmr.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb'): array
{
    $schemeUri = 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords';

    return [
        'lastUpdated' => '2026-07-04T00:00:00Z',
        'data' => [
            [
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/root',
                'text' => 'Science Keywords',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => $schemeUri,
                'children' => [
                    [
                        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/earth',
                        'text' => 'EARTH SCIENCE',
                        'language' => 'en',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'schemeURI' => $schemeUri,
                        'children' => [
                            [
                                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/atmosphere',
                                'text' => 'ATMOSPHERE',
                                'language' => 'en',
                                'scheme' => 'NASA/GCMD Earth Science Keywords',
                                'schemeURI' => $schemeUri,
                                'children' => [
                                    [
                                        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/aerosols',
                                        'text' => 'AEROSOLS',
                                        'language' => 'en',
                                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                                        'schemeURI' => $schemeUri,
                                        'children' => [
                                            [
                                                'id' => $conceptId,
                                                'text' => 'PARTICULATE MATTER',
                                                'language' => 'en',
                                                'scheme' => 'NASA/GCMD Earth Science Keywords',
                                                'schemeURI' => $schemeUri,
                                                'children' => [],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

it('indexes GCMD paths and canonicalizes legacy CMR concept URIs without using UUIDs as classification codes', function (): void {
    subjectEnrichmentPutLocalVocabulary('gcmd-science-keywords.json', subjectEnrichmentGcmdScienceKeywordsData());

    $lookup = new SubjectVocabularyLookupService;
    $match = $lookup->findById('Science Keywords', 'https://cmr.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb');

    expect($match->isUnique())->toBeTrue();

    $concept = $match->sole();
    if ($concept === null) {
        throw new RuntimeException('Expected a unique GCMD concept match.');
    }

    expect($concept->id)->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb')
        ->and($concept->valueUri())->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb')
        ->and($concept->classificationCode)->toBeNull()
        ->and($concept->path)->toBe('EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER');
});

it('resolves legacy breadcrumb paths when the current vocabulary inserted intermediate nodes', function (): void {
    subjectEnrichmentPutLocalVocabulary('gcmd-science-keywords.json', subjectEnrichmentGcmdScienceKeywordsData());

    $lookup = new SubjectVocabularyLookupService;
    $match = $lookup->findUniqueLegacyPath(
        'Science Keywords',
        'Science Keywords > EARTH SCIENCE > ATMOSPHERE > PARTICULATE MATTER',
    );

    expect($match->isUnique())->toBeTrue()
        ->and($match->pathNormalizationApplied)->toBe('legacy_ordered_subsequence')
        ->and($match->sole()?->path)->toBe('EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER');
});

it('indexes source notation as DataCite classification codes when the vocabulary exposes one', function (): void {
    subjectEnrichmentPutLocalVocabulary('analytical-methods.json', [
        'lastUpdated' => '2026-07-04T00:00:00Z',
        'data' => [
            [
                'id' => 'https://w3id.org/geochem/1.0/analyticalmethod/method/icp-ms',
                'text' => 'ICP-MS',
                'notation' => 'ICP-MS',
                'language' => 'en',
                'scheme' => 'Analytical Methods for Geochemistry and Cosmochemistry',
                'schemeURI' => 'https://w3id.org/geochem/1.0/analyticalmethod/method',
                'children' => [],
            ],
        ],
    ]);

    $lookup = new SubjectVocabularyLookupService;
    $match = $lookup->findByNotation('Analytical Methods for Geochemistry and Cosmochemistry', 'ICP-MS');

    expect($match->isUnique())->toBeTrue()
        ->and($match->sole()?->classificationCode)->toBe('ICP-MS')
        ->and($match->sole()?->valueUri())->toBe('https://w3id.org/geochem/1.0/analyticalmethod/method/icp-ms');
});

it('detects globally ambiguous exact free keyword labels across supported vocabularies', function (): void {
    subjectEnrichmentPutLocalVocabulary('msl-vocabulary.json', [
        'data' => [
            [
                'id' => 'https://epos-msl.uu.nl/voc/environmental-magnetism',
                'text' => 'environmental magnetism',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'children' => [],
            ],
        ],
    ]);
    subjectEnrichmentPutLocalVocabulary('gemet-thesaurus.json', [
        'data' => [
            [
                'id' => 'http://www.eionet.europa.eu/gemet/concept/environmental-magnetism',
                'text' => 'environmental magnetism',
                'scheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
                'schemeURI' => 'http://www.eionet.europa.eu/gemet/concept/',
                'children' => [],
            ],
        ],
    ]);

    $lookup = new SubjectVocabularyLookupService;
    $match = $lookup->findGlobalExactLabel('environmental magnetism');

    expect($match->count())->toBe(2)
        ->and($match->candidateIds())->toEqualCanonicalizing([
            'https://epos-msl.uu.nl/voc/environmental-magnetism',
            'http://www.eionet.europa.eu/gemet/concept/environmental-magnetism',
        ]);
});
