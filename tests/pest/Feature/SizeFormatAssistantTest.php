<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\User;

it('exposes size and format suggestion preview metadata', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $resource = Resource::factory()->create(['doi' => '10.5880/TEST.SIZEFORMAT']);

    AssistantSuggestion::create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'zip',
        'suggested_label' => 'FORMAT: zip',
        'similarity_score' => null,
        'discovered_at' => now(),
        'metadata' => [
            'type' => 'format',
            'inferred_value' => 'zip',
            'source_url' => 'https://datapub.gfz.de/download/10.5880/TEST.SIZEFORMAT',
            'probe_method' => 'DIRECTORY_LISTING',
            'evidence' => 'File extension detected from download listing.',
            'confidence' => 'medium',
        ],
    ]);

    $this->actingAs($user)
        ->get('/assistance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('assistance')
            ->has('sections.size-format-suggestion.data', 1)
            ->where('sections.size-format-suggestion.data.0.suggested_value', 'zip')
            ->where('sections.size-format-suggestion.data.0.suggested_label', 'FORMAT: zip')
            ->where('sections.size-format-suggestion.data.0.metadata.inferred_value', 'zip')
            ->where('sections.size-format-suggestion.data.0.metadata.source_url', 'https://datapub.gfz.de/download/10.5880/TEST.SIZEFORMAT')
            ->where('sections.size-format-suggestion.data.0.metadata.probe_method', 'DIRECTORY_LISTING')
            ->where('sections.size-format-suggestion.data.0.metadata.evidence', 'File extension detected from download listing.')
        );
});
