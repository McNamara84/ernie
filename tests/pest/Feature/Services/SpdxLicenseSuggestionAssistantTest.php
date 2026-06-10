<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use Modules\Assistants\SpdxLicenseSuggestion\Assistant;

it('explains that SPDX suggestions cannot be accepted yet and can be declined instead', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $suggestion = AssistantSuggestion::create([
        'assistant_id' => $assistant->getId(),
        'resource_id' => $resource->id,
        'target_type' => 'resource_right',
        'target_id' => 123,
        'suggested_value' => 'CC-BY-4.0',
        'suggested_label' => 'Creative Commons Attribution 4.0 International',
        'discovered_at' => now(),
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)
        ->toBe([
            'success' => false,
            'message' => 'Accepting SPDX rights suggestions is not supported yet. Please decline this suggestion to dismiss it for now.',
        ])
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});
