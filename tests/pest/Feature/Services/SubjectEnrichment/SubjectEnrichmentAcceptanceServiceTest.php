<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\Subject;
use App\Services\SubjectEnrichment\SubjectEnrichmentAcceptanceService;
use App\Services\SubjectEnrichment\SubjectEnrichmentDiscoveryService;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatcher;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatchInputProvider;
use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use App\Support\PortalSubjectNormalizer;
use Illuminate\Support\Facades\Storage;
use Modules\Assistants\SubjectMetadataEnrichment\Assistant;

covers(SubjectEnrichmentAcceptanceService::class);

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $data
 */
function subjectAcceptancePutVocabulary(string $fileName, array $data): void
{
    Storage::disk('local')->put($fileName, json_encode($data, JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function subjectAcceptanceCreateSubject(Resource $resource, array $attributes): Subject
{
    return Subject::forceCreate(array_replace([
        'resource_id' => $resource->id,
        'value' => 'Science Keywords > EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
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
function subjectAcceptanceGcmdData(string $leafId = 'https://cmr.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb'): array
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
                                                'id' => $leafId,
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
 * @return array<string, mixed>
 */
function subjectAcceptanceMslData(string $label = 'multi-scale laboratories'): array
{
    return [
        'lastUpdated' => '2026-07-04T00:00:00Z',
        'data' => [
            [
                'id' => 'https://epos-msl.uu.nl/voc/'.str_replace(' ', '-', $label),
                'text' => $label,
                'language' => 'en',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'children' => [],
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function subjectAcceptanceAnalyticalMethodsData(): array
{
    return [
        'lastUpdated' => '2026-07-04T00:00:00Z',
        'data' => [
            [
                'id' => 'https://w3id.org/geochem/1.0/analyticalmethod/method/LA-ICP-MS',
                'text' => 'LA-ICP-MS',
                'notation' => 'LA-ICP-MS',
                'language' => 'en',
                'scheme' => PortalSubjectNormalizer::SCHEME_ANALYTICAL_METHODS,
                'schemeURI' => 'https://w3id.org/geochem/1.0/analyticalmethod/method',
                'children' => [],
            ],
        ],
    ];
}

function subjectAcceptanceDiscoveryService(): SubjectEnrichmentDiscoveryService
{
    $lookup = new SubjectVocabularyLookupService;

    return new SubjectEnrichmentDiscoveryService(
        inputProvider: new SubjectEnrichmentMatchInputProvider,
        matcher: new SubjectEnrichmentMatcher($lookup),
        lookup: $lookup,
    );
}

function subjectAcceptanceService(): SubjectEnrichmentAcceptanceService
{
    $lookup = new SubjectVocabularyLookupService;

    return new SubjectEnrichmentAcceptanceService(
        inputProvider: new SubjectEnrichmentMatchInputProvider,
        matcher: new SubjectEnrichmentMatcher($lookup),
        lookup: $lookup,
    );
}

/**
 * @param  array<string, mixed>  $metadataOverrides
 * @param  array<string, mixed>  $suggestionOverrides
 */
function subjectAcceptanceSuggestionFor(Subject $subject, array $metadataOverrides = [], array $suggestionOverrides = []): AssistantSuggestion
{
    $storedSuggestions = [];

    subjectAcceptanceDiscoveryService()->discover(
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
        onProgress: function (string $message): void {},
    );

    $stored = collect($storedSuggestions)
        ->first(fn (array $candidate): bool => $candidate['targetId'] === $subject->id);

    if (! is_array($stored)) {
        throw new RuntimeException('Expected a subject enrichment discovery suggestion for the test subject.');
    }

    $storedMetadata = $stored['metadata'];
    if (! is_array($storedMetadata)) {
        throw new RuntimeException('Expected subject enrichment metadata.');
    }

    $metadata = array_replace_recursive($storedMetadata, $metadataOverrides);

    return AssistantSuggestion::create(array_replace([
        'assistant_id' => SubjectEnrichmentDiscoveryService::ASSISTANT_ID,
        'resource_id' => $stored['resourceId'],
        'target_type' => $stored['targetType'],
        'target_id' => $stored['targetId'],
        'suggested_value' => $stored['suggestedValue'],
        'suggested_label' => $stored['suggestedLabel'],
        'similarity_score' => $stored['similarityScore'],
        'metadata' => $metadata,
        'discovered_at' => now(),
    ], $suggestionOverrides));
}

function subjectAcceptanceKeepOnlySchemeUriUpdate(AssistantSuggestion $suggestion): AssistantSuggestion
{
    $metadata = $suggestion->metadata;
    if (! is_array($metadata)) {
        throw new RuntimeException('Expected subject enrichment metadata.');
    }

    $schemeUri = $metadata['proposed']['scheme_uri'];
    $metadata['proposed']['updates'] = [
        'scheme_uri' => $schemeUri,
    ];
    $metadata['acceptance']['updates'] = [
        'scheme_uri',
    ];

    $suggestion->update(['metadata' => $metadata]);

    return $suggestion->refresh();
}

it('accepts controlled GCMD suggestions and preserves the imported subject text', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81401')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
        'language' => 'de',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Subject metadata enrichment applied.')
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and($subject->value)->toBe('Science Keywords > EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER')
        ->and($subject->resource_id)->toBe($resource->id)
        ->and($subject->subject_scheme)->toBe('GCMD Science Keywords')
        ->and($subject->scheme_uri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords')
        ->and($subject->value_uri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb')
        ->and($subject->classification_code)->toBeNull()
        ->and($subject->breadcrumb_path)->toBe('EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER')
        ->and($subject->getAttribute('language'))->toBe('en');
});

it('rejects suggestions that propose internal GCMD lookup keys as subject schemes', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81420')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    $metadata = $suggestion->metadata;
    if (! is_array($metadata)) {
        throw new RuntimeException('Expected subject enrichment metadata.');
    }

    $metadata['proposed']['subject_scheme'] = 'Science Keywords';
    $metadata['proposed']['updates']['subject_scheme'] = 'Science Keywords';
    $suggestion->update(['metadata' => $metadata]);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('canonical subject scheme')
        ->and($subject->subject_scheme)->toBe('NASA/GCMD Earth Science Keywords')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('accepts globally unique free keyword transfers while preserving the free keyword value', function (): void {
    subjectAcceptancePutVocabulary('msl-vocabulary.json', subjectAcceptanceMslData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81402')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'value' => 'multi-scale laboratories',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeTrue()
        ->and($subject->value)->toBe('multi-scale laboratories')
        ->and($subject->subject_scheme)->toBe('EPOS MSL vocabulary')
        ->and($subject->scheme_uri)->toBe('https://epos-msl.uu.nl/voc')
        ->and($subject->value_uri)->toBe('https://epos-msl.uu.nl/voc/multi-scale-laboratories')
        ->and($subject->breadcrumb_path)->toBe('multi-scale laboratories')
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull();
});

it('accepts notation based suggestions and writes classification_code when the vocabulary provides one', function (): void {
    subjectAcceptancePutVocabulary('analytical-methods.json', subjectAcceptanceAnalyticalMethodsData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81403')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'value' => 'LA-ICP-MS',
        'subject_scheme' => PortalSubjectNormalizer::SCHEME_ANALYTICAL_METHODS,
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeTrue()
        ->and($subject->subject_scheme)->toBe(PortalSubjectNormalizer::SCHEME_ANALYTICAL_METHODS)
        ->and($subject->classification_code)->toBe('LA-ICP-MS')
        ->and($subject->value_uri)->toBe('https://w3id.org/geochem/1.0/analyticalmethod/method/LA-ICP-MS')
        ->and($subject->value)->toBe('LA-ICP-MS');
});

it('applies only fields listed in proposed updates', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81404')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceKeepOnlySchemeUriUpdate(subjectAcceptanceSuggestionFor($subject));

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeTrue()
        ->and($subject->subject_scheme)->toBe('NASA/GCMD Earth Science Keywords')
        ->and($subject->scheme_uri)->toBe('https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords')
        ->and($subject->value_uri)->toBeNull()
        ->and($subject->breadcrumb_path)->toBeNull()
        ->and($subject->value)->toBe('Science Keywords > EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER');
});

it('keeps suggestions when the subject value changed after discovery', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81405')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    $subject->forceFill(['value' => 'Science Keywords > EARTH SCIENCE > OCEANS'])->save();

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('subject value changed')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($subject->value_uri)->toBeNull();
});

it('keeps suggestions when the subject scheme changed after discovery', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81406')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    $subject->forceFill(['subject_scheme' => 'Platforms'])->save();

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('subject scheme changed')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($subject->value_uri)->toBeNull();
});

it('rejects when current metadata changed after discovery', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81407')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    $subject->forceFill(['scheme_uri' => 'https://example.test/changed-scheme'])->save();

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('current subject metadata changed')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('rejects when the target subject no longer exists', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81408')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    $subject->delete();

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('no longer exists')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('rejects when the local vocabulary cache disappeared before acceptance', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81409')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    Storage::disk('local')->delete('gcmd-science-keywords.json');

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('local vocabulary cache is unavailable')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('rejects when revalidation no longer resolves a globally unique free keyword candidate', function (): void {
    subjectAcceptancePutVocabulary('msl-vocabulary.json', subjectAcceptanceMslData('environmental magnetism'));

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81410')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'value' => 'environmental magnetism',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    subjectAcceptancePutVocabulary('gemet-thesaurus.json', [
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

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('no longer resolves exactly one candidate')
        ->and($subject->subject_scheme)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('rejects when revalidation resolves the stored path to a different concept', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81411')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    subjectAcceptancePutVocabulary(
        'gcmd-science-keywords.json',
        subjectAcceptanceGcmdData('https://cmr.earthdata.nasa.gov/kms/concept/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'),
    );

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('proposed subject metadata no longer matches')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('rejects when the resource already has the proposed controlled concept on another row', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81412')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);

    subjectAcceptanceCreateSubject($resource, [
        'value' => 'PARTICULATE MATTER',
        'subject_scheme' => 'Science Keywords',
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb',
        'breadcrumb_path' => 'EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
    ]);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('already has this controlled subject concept')
        ->and($subject->value_uri)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('removes duplicate pending suggestions for the same subject after successful acceptance', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81413')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject);
    $duplicate = subjectAcceptanceSuggestionFor(
        $subject,
        [],
        [
            'suggested_value' => 'https://gcmd.earthdata.nasa.gov/kms/concept/duplicate',
            'suggested_label' => 'Duplicate subject enrichment suggestion',
        ],
    );

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeTrue()
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and(AssistantSuggestion::find($duplicate->id))->toBeNull();
});

it('rejects unsupported target types before writing', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81414')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject, [], ['target_type' => 'resource']);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('unsupported entity type')
        ->and($subject->value_uri)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('rejects suggestions whose suggested value no longer matches proposed metadata', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81415')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject, [], [
        'suggested_value' => 'https://gcmd.earthdata.nasa.gov/kms/concept/not-the-proposed-concept',
    ]);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('do not match')
        ->and($subject->value_uri)->toBeNull();
});

it('rejects non-high-confidence and non-unique suggestions', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81416')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $lowConfidence = subjectAcceptanceSuggestionFor($subject, [
        'confidence' => [
            'level' => 'medium',
        ],
    ]);
    $nonUnique = subjectAcceptanceSuggestionFor($subject, [
        'match' => [
            'candidate_count' => 2,
        ],
    ], [
        'suggested_value' => 'https://gcmd.earthdata.nasa.gov/kms/concept/non-unique',
    ]);

    $lowConfidenceResult = app(Assistant::class)->acceptSuggestion($lowConfidence->id);
    $nonUniqueResult = app(Assistant::class)->acceptSuggestion($nonUnique->id);
    $subject->refresh();

    expect($lowConfidenceResult['success'])->toBeFalse()
        ->and($lowConfidenceResult['message'])->toContain('high-confidence')
        ->and($nonUniqueResult['success'])->toBeFalse()
        ->and($nonUniqueResult['message'])->toContain('uniquely resolved')
        ->and($subject->value_uri)->toBeNull();
});

it('rejects suppressed ambiguity payloads and inconsistent acceptance update metadata', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81417')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suppressed = subjectAcceptanceSuggestionFor($subject, [
        'ambiguity' => [
            'status' => 'suppressed',
        ],
    ]);
    $inconsistentUpdates = subjectAcceptanceSuggestionFor($subject, [], [
        'suggested_value' => 'https://gcmd.earthdata.nasa.gov/kms/concept/inconsistent-updates',
    ]);
    $metadata = $inconsistentUpdates->metadata;
    if (! is_array($metadata)) {
        throw new RuntimeException('Expected subject enrichment metadata.');
    }
    $metadata['acceptance']['updates'] = ['scheme_uri'];
    $inconsistentUpdates->update(['metadata' => $metadata]);

    $suppressedResult = app(Assistant::class)->acceptSuggestion($suppressed->id);
    $inconsistentResult = app(Assistant::class)->acceptSuggestion($inconsistentUpdates->id);
    $subject->refresh();

    expect($suppressedResult['success'])->toBeFalse()
        ->and($suppressedResult['message'])->toContain('Suppressed')
        ->and($inconsistentResult['success'])->toBeFalse()
        ->and($inconsistentResult['message'])->toContain('inconsistent acceptance update metadata')
        ->and($subject->value_uri)->toBeNull();
});

it('rejects payloads that try to write disallowed subject fields', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81418')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject, [
        'proposed' => [
            'updates' => [
                'value' => 'Rewritten subject text',
            ],
        ],
        'acceptance' => [
            'updates' => ['value'],
        ],
    ]);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $subject->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('allowed subject field updates')
        ->and($subject->value)->toBe('Science Keywords > EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER');
});

it('rejects suggestions that belong to a different assistant when the service is called directly', function (): void {
    subjectAcceptancePutVocabulary('gcmd-science-keywords.json', subjectAcceptanceGcmdData());

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81419')->create();
    $subject = subjectAcceptanceCreateSubject($resource, [
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
    ]);
    $suggestion = subjectAcceptanceSuggestionFor($subject, [], [
        'assistant_id' => 'other-assistant',
    ]);

    $result = subjectAcceptanceService()->accept($suggestion);
    $subject->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('different assistant')
        ->and($subject->value_uri)->toBeNull();
});
