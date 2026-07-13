<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Language;
use App\Models\Resource;
use App\Services\Language\LanguageSuggestionAcceptanceService;

it('applies a language suggestion to a resource', function () {
    $resource = Resource::factory()->create(['language_id' => null]);
    $language = Language::firstOrCreate(['code' => 'fr'], ['name' => 'French', 'active' => true, 'elmo_active' => true]);

    $suggestion = AssistantSuggestion::query()->create([
        'assistant_id' => 'language-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'resource_language',
        'target_id' => $resource->id,
        'suggested_value' => $language->code,
        'suggested_label' => 'French (fr)',
        'similarity_score' => 0.9,
        'metadata' => ['source' => 'text_heuristic'],
        'discovered_at' => now(),
    ]);

    $service = new LanguageSuggestionAcceptanceService;
    $result = $service->accept($suggestion);

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toBe('Applied language suggestion: French (fr).');
    expect($resource->refresh()->language_id)->toBe($language->id);
});
