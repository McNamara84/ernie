<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use Modules\Assistants\DateTypeSuggestion\Assistant;

it('presents suggestions without metadata as regular review candidates', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $suggestion = AssistantSuggestion::create([
        'assistant_id' => $assistant->getId(),
        'resource_id' => $resource->id,
        'target_type' => 'date_type',
        'target_id' => $resource->id,
        'suggested_value' => '2026-07-26',
        'suggested_label' => 'Date type: 2026-07-26',
        'metadata' => null,
        'discovered_at' => now(),
    ]);

    $items = $assistant->loadSuggestionsForResources([$resource->id]);

    expect($items)->toHaveCount(1)
        ->and($items[0]['id'])->toBe($suggestion->id)
        ->and($items[0]['metadata'])->toBeNull()
        ->and($items[0]['review']['can_accept'])->toBeTrue()
        ->and($items[0]['review']['can_decline'])->toBeTrue();
});
