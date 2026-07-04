<?php

declare(strict_types=1);

use App\Services\SubjectEnrichment\SubjectEnrichmentMatcher;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatchInput;
use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $data
 */
function matcherPutVocabulary(string $fileName, array $data): void
{
    Storage::disk('local')->put($fileName, json_encode($data, JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, mixed>
 */
function matcherGcmdVocabulary(): array
{
    $schemeUri = 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords';

    return [
        'lastUpdated' => '2026-07-04T00:00:00Z',
        'data' => [
            [
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/root',
                'text' => 'Science Keywords',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => $schemeUri,
                'children' => [
                    [
                        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/earth',
                        'text' => 'EARTH SCIENCE',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'schemeURI' => $schemeUri,
                        'children' => [
                            [
                                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/atmosphere',
                                'text' => 'ATMOSPHERE',
                                'scheme' => 'NASA/GCMD Earth Science Keywords',
                                'schemeURI' => $schemeUri,
                                'children' => [
                                    [
                                        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/aerosols',
                                        'text' => 'AEROSOLS',
                                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                                        'schemeURI' => $schemeUri,
                                        'children' => [
                                            [
                                                'id' => 'https://cmr.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb',
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

/**
 * @param  array<string, mixed>  $overrides
 */
function matcherInput(array $overrides = []): SubjectEnrichmentMatchInput
{
    return new SubjectEnrichmentMatchInput(...array_replace([
        'resourceId' => 1,
        'targetType' => 'subject',
        'targetId' => 10,
        'value' => 'PARTICULATE MATTER',
        'subjectScheme' => null,
        'normalizedSubjectScheme' => null,
        'schemeUri' => null,
        'valueUri' => null,
        'classificationCode' => null,
        'breadcrumbPath' => null,
        'language' => 'en',
        'isControlled' => false,
    ], $overrides));
}

function matcherService(): SubjectEnrichmentMatcher
{
    return new SubjectEnrichmentMatcher(new SubjectVocabularyLookupService);
}

it('matches controlled subjects by exact value URI before path or leaf strategies', function (): void {
    matcherPutVocabulary('gcmd-science-keywords.json', matcherGcmdVocabulary());

    $result = matcherService()->match(matcherInput([
        'value' => 'not a usable label',
        'subjectScheme' => 'NASA/GCMD Earth Science Keywords',
        'normalizedSubjectScheme' => 'Science Keywords',
        'valueUri' => 'https://cmr.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb',
        'isControlled' => true,
    ]));

    expect($result->status)->toBe('matched')
        ->and($result->matchingStrategy)->toBe('exact_value_uri')
        ->and($result->matchedFields)->toBe(['value_uri'])
        ->and($result->concept?->valueUri())->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb');
});

it('matches controlled subjects by exact notation and exposes the classification code', function (): void {
    matcherPutVocabulary('analytical-methods.json', [
        'data' => [
            [
                'id' => 'https://w3id.org/geochem/1.0/analyticalmethod/method/icp-ms',
                'text' => 'ICP-MS',
                'notation' => 'ICP-MS',
                'scheme' => 'Analytical Methods for Geochemistry and Cosmochemistry',
                'schemeURI' => 'https://w3id.org/geochem/1.0/analyticalmethod/method',
                'children' => [],
            ],
        ],
    ]);

    $result = matcherService()->match(matcherInput([
        'value' => 'Inductively coupled plasma mass spectrometry',
        'subjectScheme' => 'Analytical Methods for Geochemistry and Cosmochemistry',
        'normalizedSubjectScheme' => 'Analytical Methods for Geochemistry and Cosmochemistry',
        'classificationCode' => 'ICP-MS',
        'isControlled' => true,
    ]));

    expect($result->status)->toBe('matched')
        ->and($result->matchingStrategy)->toBe('exact_notation')
        ->and($result->matchedFields)->toBe(['classification_code'])
        ->and($result->concept?->classificationCode)->toBe('ICP-MS');
});

it('matches controlled subjects by stored breadcrumb path when value contains only a leaf label', function (): void {
    matcherPutVocabulary('gcmd-science-keywords.json', matcherGcmdVocabulary());

    $result = matcherService()->match(matcherInput([
        'value' => 'PARTICULATE MATTER',
        'subjectScheme' => 'NASA/GCMD Earth Science Keywords',
        'normalizedSubjectScheme' => 'Science Keywords',
        'breadcrumbPath' => 'EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
        'isControlled' => true,
    ]));

    expect($result->status)->toBe('matched')
        ->and($result->matchingStrategy)->toBe('exact_breadcrumb_path')
        ->and($result->matchedFields)->toBe(['breadcrumb_path']);
});

it('uses unique leaf-label matching only inside the controlled subject scheme', function (): void {
    matcherPutVocabulary('msl-vocabulary.json', [
        'data' => [
            [
                'id' => 'https://epos-msl.uu.nl/voc/material',
                'text' => 'Material',
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
    ]);

    $result = matcherService()->match(matcherInput([
        'value' => 'Rock',
        'subjectScheme' => 'EPOS MSL vocabulary',
        'normalizedSubjectScheme' => 'EPOS MSL vocabulary',
        'isControlled' => true,
    ]));

    expect($result->status)->toBe('matched')
        ->and($result->matchingStrategy)->toBe('unique_leaf_label')
        ->and($result->matchedFields)->toBe(['value'])
        ->and($result->concept?->path)->toBe('Material > Rock');
});

it('suppresses controlled leaf matches when more than one candidate exists in the same scheme', function (): void {
    matcherPutVocabulary('msl-vocabulary.json', [
        'data' => [
            [
                'id' => 'https://epos-msl.uu.nl/voc/branch-a',
                'text' => 'Branch A',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'children' => [
                    [
                        'id' => 'https://epos-msl.uu.nl/voc/shared-a',
                        'text' => 'Shared Term',
                        'scheme' => 'EPOS MSL vocabulary',
                        'schemeURI' => 'https://epos-msl.uu.nl/voc',
                        'children' => [],
                    ],
                ],
            ],
            [
                'id' => 'https://epos-msl.uu.nl/voc/branch-b',
                'text' => 'Branch B',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'children' => [
                    [
                        'id' => 'https://epos-msl.uu.nl/voc/shared-b',
                        'text' => 'Shared Term',
                        'scheme' => 'EPOS MSL vocabulary',
                        'schemeURI' => 'https://epos-msl.uu.nl/voc',
                        'children' => [],
                    ],
                ],
            ],
        ],
    ]);

    $result = matcherService()->match(matcherInput([
        'value' => 'Shared Term',
        'subjectScheme' => 'EPOS MSL vocabulary',
        'normalizedSubjectScheme' => 'EPOS MSL vocabulary',
        'isControlled' => true,
    ]));

    expect($result->status)->toBe('suppressed')
        ->and($result->suppressionReasons)->toBe(['multiple_candidate_matches'])
        ->and($result->candidateCount)->toBe(2);
});

it('suppresses controlled subjects that already have complete matching metadata', function (): void {
    matcherPutVocabulary('gcmd-science-keywords.json', matcherGcmdVocabulary());

    $result = matcherService()->match(matcherInput([
        'value' => 'EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
        'subjectScheme' => 'Science Keywords',
        'normalizedSubjectScheme' => 'Science Keywords',
        'schemeUri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb',
        'breadcrumbPath' => 'EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
        'isControlled' => true,
    ]));

    expect($result->status)->toBe('suppressed')
        ->and($result->suppressionReasons)->toBe(['complete_controlled_subject_metadata'])
        ->and($result->candidateIds)->toBe(['https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb']);
});

it('still matches complete controlled records when only the language differs', function (): void {
    matcherPutVocabulary('gcmd-science-keywords.json', matcherGcmdVocabulary());

    $result = matcherService()->match(matcherInput([
        'value' => 'EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
        'subjectScheme' => 'Science Keywords',
        'normalizedSubjectScheme' => 'Science Keywords',
        'schemeUri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb',
        'breadcrumbPath' => 'EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
        'language' => 'de',
        'isControlled' => true,
    ]));

    expect($result->status)->toBe('matched')
        ->and($result->matchingStrategy)->toBe('exact_value_uri');
});

it('suppresses unsupported controlled schemes and supported schemes without candidates', function (): void {
    matcherPutVocabulary('msl-vocabulary.json', ['data' => []]);

    $unsupported = matcherService()->match(matcherInput([
        'value' => 'Rock',
        'subjectScheme' => 'Unsupported Scheme',
        'normalizedSubjectScheme' => 'Unsupported Scheme',
        'isControlled' => true,
    ]));
    $missingCandidate = matcherService()->match(matcherInput([
        'value' => 'Rock',
        'subjectScheme' => 'EPOS MSL vocabulary',
        'normalizedSubjectScheme' => 'EPOS MSL vocabulary',
        'isControlled' => true,
    ]));

    expect($unsupported->suppressionReasons)->toBe(['unsupported_scheme'])
        ->and($missingCandidate->suppressionReasons)->toBe(['no_candidate_match']);
});

it('matches free-text values that are stable concept URIs', function (): void {
    matcherPutVocabulary('gcmd-science-keywords.json', matcherGcmdVocabulary());

    $result = matcherService()->match(matcherInput([
        'value' => 'https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb',
    ]));

    expect($result->status)->toBe('matched')
        ->and($result->matchingStrategy)->toBe('stable_concept_uri')
        ->and($result->warnings)->toBe([])
        ->and($result->warningMessages)->toBe([]);
});

it('matches free-text recognized scheme-prefixed paths including legacy path normalization', function (): void {
    matcherPutVocabulary('gcmd-science-keywords.json', matcherGcmdVocabulary());

    $exact = matcherService()->match(matcherInput([
        'value' => 'Science Keywords > EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
    ]));
    $legacy = matcherService()->match(matcherInput([
        'value' => 'Science Keywords > EARTH SCIENCE > ATMOSPHERE > PARTICULATE MATTER',
    ]));

    expect($exact->matchingStrategy)->toBe('recognized_scheme_prefixed_path')
        ->and($legacy->matchingStrategy)->toBe('recognized_scheme_prefixed_legacy_path')
        ->and($exact->warnings)->toBe([])
        ->and($legacy->warnings)->toBe([])
        ->and($legacy->pathNormalizationApplied)->toBe('legacy_ordered_subsequence');
});

it('suppresses free-text recognized scheme-prefixed paths when the apparent cache is missing', function (): void {
    $result = matcherService()->match(matcherInput([
        'value' => 'Science Keywords > EARTH SCIENCE > ATMOSPHERE',
    ]));

    expect($result->status)->toBe('suppressed')
        ->and($result->suppressionReasons)->toBe(['missing_local_vocabulary_cache']);
});

it('matches free-text full paths globally when no scheme prefix is present', function (): void {
    matcherPutVocabulary('msl-vocabulary.json', [
        'data' => [
            [
                'id' => 'https://epos-msl.uu.nl/voc/material',
                'text' => 'Material',
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
    ]);

    $result = matcherService()->match(matcherInput([
        'value' => 'Material > Rock',
    ]));

    expect($result->status)->toBe('matched')
        ->and($result->matchingStrategy)->toBe('global_exact_path')
        ->and($result->warnings)->toBe(['free_keyword_can_be_transferred_to_thesaurus_keyword'])
        ->and($result->warningMessages['free_keyword_can_be_transferred_to_thesaurus_keyword'])->toBe('This Free Keyword could be transferred into a Thesaurus Keyword if you accept this suggestion.');
});

it('suppresses free-text values when URI, path, and label lookup all miss', function (): void {
    matcherPutVocabulary('gcmd-science-keywords.json', matcherGcmdVocabulary());

    $uriMiss = matcherService()->match(matcherInput([
        'value' => 'https://example.test/missing-concept',
    ]));
    $labelMiss = matcherService()->match(matcherInput([
        'value' => 'not a known keyword',
    ]));

    expect($uriMiss->status)->toBe('suppressed')
        ->and($uriMiss->suppressionReasons)->toBe(['no_candidate_match'])
        ->and($labelMiss->suppressionReasons)->toBe(['no_candidate_match']);
});
