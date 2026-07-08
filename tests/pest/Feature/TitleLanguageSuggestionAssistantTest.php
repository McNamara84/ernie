<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\Title;
use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Assistants\TitleSuggestion\Assistant;

uses(RefreshDatabase::class);

function titleSuggestionSourceHash(Title $title): string
{
    return hash('sha256', implode('|', [
        (string) $title->id,
        trim((string) $title->value),
        (string) ($title->language ?? ''),
        (string) $title->resource_id,
    ]));
}

function createTitleLanguageSuggestion(Resource $resource, Title $title, array $overrides = [], array $metadataOverrides = []): AssistantSuggestion
{
    return AssistantSuggestion::create(array_replace_recursive([
        'assistant_id' => 'title-language-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'title',
        'target_id' => $title->id,
        'suggested_value' => 'en',
        'suggested_label' => 'English (en) · 95% confidence · current: not set · "Groundwater Recharge"',
        'similarity_score' => 0.95,
        'metadata' => array_replace_recursive([
            'title_text' => $title->value,
            'current_language' => null,
            'proposed_language' => 'en',
            'proposed_language_label' => 'English',
            'confidence' => 0.95,
            'confidence_percent' => 95,
            'reason' => 'Detected from title text using ELD language detection. Only German, English and French suggestions are supported.',
            'is_stale' => false,
            'source_hash' => titleSuggestionSourceHash($title),
            'source_snapshot' => [
                'title_id' => $title->id,
                'title_text' => $title->value,
                'current_language' => $title->language,
                'resource_id' => $resource->id,
            ],
        ], $metadataOverrides),
        'discovered_at' => now(),
    ], $overrides));
}

it('registers the title language assistant via auto-discovery', function (): void {
    $registrar = app(AssistantRegistrar::class);

    expect($registrar->has('title-language-suggestion'))->toBeTrue();
});

it('creates a title language suggestion through discovery with source verification metadata', function (): void {
    $assistant = app(Assistant::class);

    $resource = Resource::factory()->create();

    $title = Title::factory()
        ->for($resource)
        ->create([
            'language' => null,
            'value' => 'Groundwater recharge analysis and hydrological modeling for regional climate studies',
        ]);

    $discovered = $assistant->runDiscovery(static function (): void {
    });

    $suggestion = AssistantSuggestion::query()
        ->where('assistant_id', 'title-language-suggestion')
        ->where('target_type', 'title')
        ->where('target_id', $title->id)
        ->first();

    expect($discovered)->toBeGreaterThanOrEqual(1)
        ->and($suggestion)->not->toBeNull();

    if (! $suggestion instanceof AssistantSuggestion) {
        return;
    }

    $metadata = is_array($suggestion->metadata) ? $suggestion->metadata : [];

    expect($suggestion->resource_id)->toBe($resource->id)
        ->and($suggestion->suggested_value)->toBeIn(['de', 'en', 'fr'])
        ->and($metadata['proposed_language'] ?? null)->toBe($suggestion->suggested_value)
        ->and($metadata['source_hash'] ?? null)->toBe(titleSuggestionSourceHash($title))
        ->and($metadata['source_snapshot']['title_id'] ?? null)->toBe($title->id)
        ->and($metadata['source_snapshot']['title_text'] ?? null)->toBe($title->value)
        ->and($metadata['source_snapshot']['resource_id'] ?? null)->toBe($resource->id);
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

    createTitleLanguageSuggestion($resource, $title);

    $this->actingAs($user)
        ->get('/assistance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('assistance')
            ->has('manifests')
            ->has('sections')
        );
});

it('does not accept a title language suggestion for an unsupported target type', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $title = Title::factory()->for($resource)->create([
        'language' => null,
        'value' => 'Groundwater Recharge',
    ]);

    $suggestion = createTitleLanguageSuggestion($resource, $title, [
        'target_type' => 'resource',
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('This title language suggestion targets an unsupported entity type.')
        ->and($title->fresh()->language)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('does not accept a title language suggestion when title and resource do not match', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $otherResource = Resource::factory()->create();
    $title = Title::factory()->for($resource)->create([
        'language' => null,
        'value' => 'Groundwater Recharge',
    ]);

    $suggestion = createTitleLanguageSuggestion(
        $resource,
        $title,
        ['resource_id' => $otherResource->id],
        [
            'source_snapshot' => [
                'title_id' => $title->id,
                'resource_id' => $otherResource->id,
            ],
        ],
    );

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('The title for this suggestion no longer exists or no longer belongs to the expected resource.')
        ->and($title->fresh()->language)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('does not accept a title language suggestion with inconsistent snapshot metadata', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $title = Title::factory()->for($resource)->create([
        'language' => null,
        'value' => 'Groundwater Recharge',
    ]);
    $otherTitle = Title::factory()->for($resource)->create([
        'language' => null,
        'value' => 'Hydrology and Climate',
    ]);

    $suggestion = createTitleLanguageSuggestion($resource, $title, [], [
        'source_snapshot' => [
            'title_id' => $otherTitle->id,
            'resource_id' => $resource->id,
        ],
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('This title language suggestion contains inconsistent title metadata. Please refresh the assistant list.')
        ->and($title->fresh()->language)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('does not accept a title language suggestion without a valid title and resource reference', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $title = Title::factory()->for($resource)->create([
        'language' => null,
        'value' => 'Groundwater Recharge',
    ]);

    $suggestion = createTitleLanguageSuggestion($resource, $title, [
        'target_id' => 'not-a-valid-id',
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('This title language suggestion does not contain a valid title and resource reference.')
        ->and($title->fresh()->language)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('does not accept a title language suggestion with an unsupported language value', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $title = Title::factory()->for($resource)->create([
        'language' => null,
        'value' => 'Groundwater Recharge',
    ]);

    $suggestion = createTitleLanguageSuggestion($resource, $title, [
        'suggested_value' => 'es',
    ], [
        'proposed_language' => 'es',
        'proposed_language_label' => 'Spanish',
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe("Unsupported language 'es'. Only de, en and fr are supported.")
        ->and($title->fresh()->language)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('does not accept a title language suggestion without source verification metadata', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $title = Title::factory()->for($resource)->create([
        'language' => null,
        'value' => 'Groundwater Recharge',
    ]);

    $suggestion = createTitleLanguageSuggestion($resource, $title, [], [
        'source_hash' => null,
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('This title language suggestion is missing source verification metadata. Please refresh the assistant list.')
        ->and($title->fresh()->language)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('does not accept a stale title language suggestion after the title changed', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $title = Title::factory()->for($resource)->create([
        'language' => null,
        'value' => 'Groundwater Recharge',
    ]);

    $suggestion = createTitleLanguageSuggestion($resource, $title);

    $title->update([
        'value' => 'Groundwater Recharge Updated',
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Suggestion is stale because the title data changed after discovery. Please run discovery again.')
        ->and($title->fresh()->language)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});