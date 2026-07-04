<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Services\Assistance\AssistantManifest;
use App\Services\SubjectEnrichment\SubjectEnrichmentDiscoveryService;
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

it('keeps accepting subject enrichment suggestions guarded for issue 814', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81306')->create();
    $suggestion = AssistantSuggestion::create([
        'assistant_id' => SubjectEnrichmentDiscoveryService::ASSISTANT_ID,
        'resource_id' => $resource->id,
        'target_type' => SubjectEnrichmentDiscoveryService::TARGET_TYPE,
        'target_id' => 123,
        'suggested_value' => 'https://gcmd.earthdata.nasa.gov/kms/concept/example',
        'suggested_label' => 'Complete subject metadata for "Example" from Science Keywords',
        'similarity_score' => 1.0,
        'metadata' => [],
        'discovered_at' => now(),
    ]);

    $assistant = app(Assistant::class);
    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toBe([
        'success' => false,
        'message' => 'Accepting subject enrichment suggestions is implemented in Issue 814.',
    ])
        ->and(AssistantSuggestion::query()->whereKey($suggestion->id)->exists())->toBeTrue();
});
