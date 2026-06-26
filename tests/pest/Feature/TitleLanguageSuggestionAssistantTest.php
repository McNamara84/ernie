<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\Title;
use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers the title language assistant via auto-discovery', function (): void {
    $registrar = app(AssistantRegistrar::class);

    expect($registrar->has('title-language-suggestion'))->toBeTrue();
});

it('returns title language suggestions for the assistance page', function (): void {
    $user = User::factory()
        ->groupLeader()
        ->create();

    $resource = Resource::factory()->create();

    $title = Title::factory()
        ->for($resource)
        ->create([
            'language' => null,
            'value' => 'Groundwater Recharge',
        ]);

    AssistantSuggestion::create([
        'assistant_id' => 'title-language-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'title',
        'target_id' => $title->id,
        'suggested_value' => 'en',
        'suggested_label' => 'English (en) · 95% confidence · current: not set · "Groundwater Recharge"',
        'similarity_score' => 0.95,
        'metadata' => [
            'title_text' => $title->value,
            'current_language' => null,
            'proposed_language' => 'en',
            'proposed_language_label' => 'English',
            'confidence' => 0.95,
            'confidence_percent' => 95,
            'reason' => 'Detected from title text using ELD language detection. Only German, English and French suggestions are supported.',
            'is_stale' => false,
        ],
        'discovered_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/assistance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('assistance')
            ->has('manifests')
            ->has('sections')
        );
});