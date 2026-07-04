<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\Subject;
use App\Services\Assistance\AssistantManifest;
use App\Services\SubjectEnrichment\SubjectEnrichmentDiscoveryService;
use Illuminate\Support\Facades\Storage;
use Modules\Assistants\SubjectMetadataEnrichment\Assistant;

it('parses and autoloads the subject metadata enrichment assistant manifest', function (): void {
    $manifest = AssistantManifest::fromFile(
        base_path('modules/assistants/SubjectMetadataEnrichment/manifest.json'),
    );

    expect($manifest->id)->toBe(SubjectEnrichmentDiscoveryService::ASSISTANT_ID)
        ->and($manifest->assistantClass)->toBe(Assistant::class)
        ->and(class_exists($manifest->assistantClass))->toBeTrue()
        ->and($manifest->routePrefix)->toBe('subject-metadata-enrichment');
});

it('keeps invalid subject enrichment suggestions when acceptance metadata is incomplete', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81306')->create();
    $suggestion = AssistantSuggestion::create([
        'assistant_id' => SubjectEnrichmentDiscoveryService::ASSISTANT_ID,
        'resource_id' => $resource->id,
        'target_type' => SubjectEnrichmentDiscoveryService::TARGET_TYPE,
        'target_id' => 123,
        'suggested_value' => 'https://gcmd.earthdata.nasa.gov/kms/concept/example',
        'suggested_label' => 'Complete subject metadata for "Example" from GCMD Science Keywords',
        'similarity_score' => 1.0,
        'metadata' => [],
        'discovered_at' => now(),
    ]);

    $assistant = app(Assistant::class);
    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toBe([
        'success' => false,
        'message' => 'This subject metadata suggestion does not match its target subject.',
    ])
        ->and(AssistantSuggestion::query()->whereKey($suggestion->id)->exists())->toBeTrue();
});
it('refreshes existing subject metadata suggestions when the canonical subject scheme changes', function (): void {
    Storage::fake('local');

    $schemeUri = 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms';
    $valueUri = 'https://gcmd.earthdata.nasa.gov/kms/concept/11111111-1111-4111-8111-111111111111';

    Storage::disk('local')->put('gcmd-platforms.json', json_encode([
        'data' => [
            [
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/platforms-root',
                'text' => 'Platforms',
                'language' => 'en',
                'scheme' => 'GCMD Platforms',
                'schemeURI' => $schemeUri,
                'children' => [
                    [
                        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/space-based-platforms',
                        'text' => 'Space-based Platforms',
                        'language' => 'en',
                        'scheme' => 'GCMD Platforms',
                        'schemeURI' => $schemeUri,
                        'children' => [
                            [
                                'id' => $valueUri,
                                'text' => 'VOYAGER 1',
                                'language' => 'en',
                                'scheme' => 'GCMD Platforms',
                                'schemeURI' => $schemeUri,
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81311')->create();
    $subject = Subject::forceCreate([
        'resource_id' => $resource->id,
        'value' => 'Platforms > Space-based Platforms > VOYAGER 1',
        'language' => 'en',
        'subject_scheme' => 'GCMD Platforms',
        'scheme_uri' => null,
        'value_uri' => null,
        'classification_code' => null,
        'breadcrumb_path' => null,
    ]);

    $suggestion = AssistantSuggestion::create([
        'assistant_id' => SubjectEnrichmentDiscoveryService::ASSISTANT_ID,
        'resource_id' => $resource->id,
        'target_type' => SubjectEnrichmentDiscoveryService::TARGET_TYPE,
        'target_id' => $subject->id,
        'suggested_value' => $valueUri,
        'suggested_label' => 'Complete subject metadata for "VOYAGER 1" from Platforms',
        'similarity_score' => 1.0,
        'metadata' => [
            'proposed' => [
                'subject_scheme' => 'Platforms',
                'updates' => [
                    'subject_scheme' => 'Platforms',
                ],
            ],
        ],
        'discovered_at' => now()->subDay(),
    ]);

    $count = app(Assistant::class)->runDiscovery(function (string $message): void {});
    $suggestion->refresh();

    expect($count)->toBe(1)
        ->and(AssistantSuggestion::query()->count())->toBe(1)
        ->and($suggestion->suggested_label)->toBe('Complete subject metadata for "VOYAGER 1" from GCMD Platforms')
        ->and($suggestion->metadata['proposed']['subject_scheme'])->toBe('GCMD Platforms')
        ->and($suggestion->metadata['proposed']['updates'])->not->toHaveKey('subject_scheme')
        ->and($suggestion->metadata['proposed']['updates'])->toMatchArray([
            'scheme_uri' => $schemeUri,
            'value_uri' => $valueUri,
            'breadcrumb_path' => 'Space-based Platforms > VOYAGER 1',
        ]);
});
