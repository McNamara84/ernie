<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\Subject;
use App\Services\SubjectEnrichment\SubjectEnrichmentDiscoveryService;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatcher;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatchInputProvider;
use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $data
 */
function subjectEnrichmentDiscoveryPutVocabulary(string $fileName, array $data): void
{
    Storage::disk('local')->put($fileName, json_encode($data, JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function subjectEnrichmentDiscoveryCreateSubject(Resource $resource, array $attributes): Subject
{
    return Subject::forceCreate(array_replace([
        'resource_id' => $resource->id,
        'value' => 'EPOS',
        'language' => 'en',
        'subject_scheme' => null,
        'scheme_uri' => null,
        'value_uri' => null,
        'classification_code' => null,
        'breadcrumb_path' => null,
    ], $attributes));
}

/**
 * @return array<string, mixed>
 */
function subjectEnrichmentDiscoveryGcmdData(): array
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

function subjectEnrichmentDiscoveryService(): SubjectEnrichmentDiscoveryService
{
    $lookup = new SubjectVocabularyLookupService;

    return new SubjectEnrichmentDiscoveryService(
        inputProvider: new SubjectEnrichmentMatchInputProvider,
        matcher: new SubjectEnrichmentMatcher($lookup),
    );
}

/**
 * @return array{0: int, 1: list<array<string, mixed>>, 2: list<string>}
 */
function subjectEnrichmentRunDiscovery(SubjectEnrichmentDiscoveryService $service): array
{
    $storedSuggestions = [];
    $progressMessages = [];

    $count = $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$storedSuggestions): bool {
            $storedSuggestions[] = compact(
                'resourceId',
                'targetType',
                'targetId',
                'suggestedValue',
                'suggestedLabel',
                'similarityScore',
                'metadata',
            );

            return true;
        },
        onProgress: function (string $message) use (&$progressMessages): void {
            $progressMessages[] = $message;
        },
    );

    return [$count, $storedSuggestions, $progressMessages];
}

it('stores high-confidence suggestions for controlled GCMD path-only subjects', function (): void {
    subjectEnrichmentDiscoveryPutVocabulary('gcmd-science-keywords.json', subjectEnrichmentDiscoveryGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81301')->create();
    $subject = subjectEnrichmentDiscoveryCreateSubject($resource, [
        'value' => 'Science Keywords > EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);

    [$count, $storedSuggestions, $progressMessages] = subjectEnrichmentRunDiscovery(subjectEnrichmentDiscoveryService());

    expect($count)->toBe(1)
        ->and($storedSuggestions)->toHaveCount(1)
        ->and($storedSuggestions[0]['resourceId'])->toBe($resource->id)
        ->and($storedSuggestions[0]['targetType'])->toBe('subject')
        ->and($storedSuggestions[0]['targetId'])->toBe($subject->id)
        ->and($storedSuggestions[0]['suggestedValue'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb')
        ->and($storedSuggestions[0]['similarityScore'])->toBe(1.0)
        ->and($storedSuggestions[0]['metadata']['contract_version'])->toBe('1.0')
        ->and($storedSuggestions[0]['metadata']['issue'])->toBe(813)
        ->and($storedSuggestions[0]['metadata']['current']['subject_id'])->toBe($subject->id)
        ->and($storedSuggestions[0]['metadata']['current']['subject_scheme'])->toBe('NASA/GCMD Earth Science Keywords')
        ->and($storedSuggestions[0]['metadata']['current']['normalized_subject_scheme'])->toBe('Science Keywords')
        ->and($storedSuggestions[0]['metadata']['proposed']['subject_scheme'])->toBe('Science Keywords')
        ->and($storedSuggestions[0]['metadata']['proposed']['scheme_uri'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords')
        ->and($storedSuggestions[0]['metadata']['proposed']['value_uri'])->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb')
        ->and($storedSuggestions[0]['metadata']['proposed']['classification_code'])->toBeNull()
        ->and($storedSuggestions[0]['metadata']['proposed']['breadcrumb_path'])->toBe('EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER')
        ->and($storedSuggestions[0]['metadata']['proposed']['updates'])->toMatchArray([
            'subject_scheme' => 'Science Keywords',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb',
            'breadcrumb_path' => 'EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
        ])
        ->and($storedSuggestions[0]['metadata']['proposed']['updates'])->not->toHaveKey('classification_code')
        ->and($storedSuggestions[0]['metadata']['vocabulary']['source'])->toBe('nasa_gcmd_kms')
        ->and($storedSuggestions[0]['metadata']['match']['strategy'])->toBe('exact_breadcrumb_path')
        ->and($storedSuggestions[0]['metadata']['match']['matched_fields'])->toBe(['value'])
        ->and($storedSuggestions[0]['metadata']['provenance']['matching_strategy'])->toBe('exact_breadcrumb_path')
        ->and($storedSuggestions[0]['metadata']['confidence']['evidence'])->toContain('single_candidate')
        ->and($storedSuggestions[0]['metadata']['ambiguity']['status'])->toBe('none')
        ->and(implode("\n", $progressMessages))->toContain('Stored 1 subject enrichment suggestion(s)');
});

it('stores free keyword suggestions only when the exact concept label is globally unique and includes the transfer warning', function (): void {
    subjectEnrichmentDiscoveryPutVocabulary('msl-vocabulary.json', [
        'lastUpdated' => '2026-07-04T00:00:00Z',
        'data' => [
            [
                'id' => 'https://epos-msl.uu.nl/voc/multi-scale-laboratories',
                'text' => 'multi-scale laboratories',
                'language' => 'en',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'children' => [],
            ],
        ],
    ]);

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81302')->create();
    $subject = subjectEnrichmentDiscoveryCreateSubject($resource, [
        'value' => 'multi-scale laboratories',
    ]);

    [$count, $storedSuggestions] = subjectEnrichmentRunDiscovery(subjectEnrichmentDiscoveryService());

    expect($count)->toBe(1)
        ->and($storedSuggestions)->toHaveCount(1)
        ->and($storedSuggestions[0]['targetId'])->toBe($subject->id)
        ->and($storedSuggestions[0]['metadata']['proposed']['subject_scheme'])->toBe('EPOS MSL vocabulary')
        ->and($storedSuggestions[0]['metadata']['proposed']['value_uri'])->toBe('https://epos-msl.uu.nl/voc/multi-scale-laboratories')
        ->and($storedSuggestions[0]['metadata']['proposed']['updates'])->toMatchArray([
            'subject_scheme' => 'EPOS MSL vocabulary',
            'scheme_uri' => 'https://epos-msl.uu.nl/voc',
            'value_uri' => 'https://epos-msl.uu.nl/voc/multi-scale-laboratories',
            'breadcrumb_path' => 'multi-scale laboratories',
        ])
        ->and($storedSuggestions[0]['metadata']['match']['strategy'])->toBe('global_exact_label')
        ->and($storedSuggestions[0]['metadata']['ambiguity']['status'])->toBe('warning')
        ->and($storedSuggestions[0]['metadata']['ambiguity']['warnings'])->toBe([
            'free_keyword_can_be_transferred_to_thesaurus_keyword',
        ])
        ->and($storedSuggestions[0]['metadata']['ambiguity']['warning_messages']['free_keyword_can_be_transferred_to_thesaurus_keyword'])
        ->toBe('This Free Keyword could be transferred into a Thesaurus Keyword if you accept this suggestion.');
});

it('suppresses free keyword transfer suggestions when the controlled concept value URI already exists on the resource', function (): void {
    subjectEnrichmentDiscoveryPutVocabulary('msl-vocabulary.json', [
        'lastUpdated' => '2026-07-04T00:00:00Z',
        'data' => [
            [
                'id' => 'https://epos-msl.uu.nl/voc/multi-scale-laboratories',
                'text' => 'multi-scale laboratories',
                'language' => 'en',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'children' => [],
            ],
        ],
    ]);

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81306')->create();
    $freeSubject = subjectEnrichmentDiscoveryCreateSubject($resource, [
        'value' => 'multi-scale laboratories',
    ]);
    subjectEnrichmentDiscoveryCreateSubject($resource, [
        'value' => 'multi-scale laboratories',
        'subject_scheme' => 'Legacy MSL label',
        'scheme_uri' => 'https://epos-msl.uu.nl/voc',
        'value_uri' => 'https://epos-msl.uu.nl/voc/multi-scale-laboratories',
        'breadcrumb_path' => 'multi-scale laboratories',
    ]);

    [$count, $storedSuggestions, $progressMessages] = subjectEnrichmentRunDiscovery(subjectEnrichmentDiscoveryService());

    expect($count)->toBe(0)
        ->and($storedSuggestions)->toBeEmpty()
        ->and(implode("\n", $progressMessages))->toContain(sprintf(
            'Suppressed subject %d: resource already has this controlled subject concept.',
            $freeSubject->id,
        ));
});

it('suppresses free keyword transfer suggestions when the controlled classification code already exists on the resource', function (): void {
    subjectEnrichmentDiscoveryPutVocabulary('analytical-methods.json', [
        'lastUpdated' => '2026-07-04T00:00:00Z',
        'data' => [
            [
                'id' => 'icp-ms-local-id',
                'text' => 'ICP-MS',
                'notation' => 'ICP-MS',
                'language' => 'en',
                'scheme' => 'Analytical Methods for Geochemistry and Cosmochemistry',
                'schemeURI' => 'https://w3id.org/geochem/1.0/analyticalmethod/method',
                'children' => [],
            ],
        ],
    ]);

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81307')->create();
    $freeSubject = subjectEnrichmentDiscoveryCreateSubject($resource, [
        'value' => 'ICP-MS',
    ]);
    subjectEnrichmentDiscoveryCreateSubject($resource, [
        'value' => 'ICP-MS',
        'subject_scheme' => 'Analytical Methods for Geochemistry and Cosmochemistry',
        'scheme_uri' => 'https://w3id.org/geochem/1.0/analyticalmethod/method',
        'classification_code' => 'ICP-MS',
        'breadcrumb_path' => 'ICP-MS',
    ]);

    [$count, $storedSuggestions, $progressMessages] = subjectEnrichmentRunDiscovery(subjectEnrichmentDiscoveryService());

    expect($count)->toBe(0)
        ->and($storedSuggestions)->toBeEmpty()
        ->and(implode("\n", $progressMessages))->toContain(sprintf(
            'Suppressed subject %d: resource already has this controlled subject concept.',
            $freeSubject->id,
        ));
});

it('suppresses ambiguous free keyword labels across multiple vocabularies', function (): void {
    subjectEnrichmentDiscoveryPutVocabulary('msl-vocabulary.json', [
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
    subjectEnrichmentDiscoveryPutVocabulary('gemet-thesaurus.json', [
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

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81303')->create();
    subjectEnrichmentDiscoveryCreateSubject($resource, [
        'value' => 'environmental magnetism',
    ]);

    [$count, $storedSuggestions, $progressMessages] = subjectEnrichmentRunDiscovery(subjectEnrichmentDiscoveryService());

    expect($count)->toBe(0)
        ->and($storedSuggestions)->toBeEmpty()
        ->and(implode("\n", $progressMessages))->toContain('free_text_label_not_globally_unique');
});

it('stores legacy path normalization provenance when old controlled paths omit a current intermediate node', function (): void {
    subjectEnrichmentDiscoveryPutVocabulary('gcmd-science-keywords.json', subjectEnrichmentDiscoveryGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81304')->create();
    subjectEnrichmentDiscoveryCreateSubject($resource, [
        'value' => 'Science Keywords > EARTH SCIENCE > ATMOSPHERE > PARTICULATE MATTER',
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);

    [$count, $storedSuggestions] = subjectEnrichmentRunDiscovery(subjectEnrichmentDiscoveryService());

    expect($count)->toBe(1)
        ->and($storedSuggestions[0]['metadata']['match']['strategy'])->toBe('exact_legacy_breadcrumb_path')
        ->and($storedSuggestions[0]['metadata']['match']['path_normalization_applied'])->toBe('legacy_ordered_subsequence')
        ->and($storedSuggestions[0]['metadata']['provenance']['path_normalization_applied'])->toBe('legacy_ordered_subsequence')
        ->and($storedSuggestions[0]['metadata']['proposed']['breadcrumb_path'])->toBe('EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER');
});

it('suppresses supported controlled subjects when the local vocabulary cache is missing', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81305')->create();
    subjectEnrichmentDiscoveryCreateSubject($resource, [
        'value' => 'Science Keywords > EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);

    [$count, $storedSuggestions, $progressMessages] = subjectEnrichmentRunDiscovery(subjectEnrichmentDiscoveryService());

    expect($count)->toBe(0)
        ->and($storedSuggestions)->toBeEmpty()
        ->and(implode("\n", $progressMessages))->toContain('missing_local_vocabulary_cache');
});
