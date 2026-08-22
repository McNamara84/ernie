<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Resource;
use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Assistants\DescriptionLanguageSuggestion\Assistant;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->abstractType = DescriptionType::query()->create([
        'name' => 'Abstract',
        'slug' => 'Abstract',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);
});

function descriptionForLanguageAssistant(Resource $resource, DescriptionType $type, string $value, ?string $language = null): Description
{
    return Description::query()->create([
        'resource_id' => $resource->id,
        'description_type_id' => $type->id,
        'value' => $value,
        'language' => $language,
    ]);
}

it('registers the description language assistant through module discovery', function (): void {
    expect(app(AssistantRegistrar::class)->has('description-language-suggestion'))->toBeTrue();
});

it('discovers only reliable German and English texts without a language', function (): void {
    $resource = Resource::factory()->create();
    $english = descriptionForLanguageAssistant(
        $resource,
        $this->abstractType,
        'This research dataset contains detailed measurements of groundwater recharge and regional hydrological processes over several years.',
    );
    $german = descriptionForLanguageAssistant(
        $resource,
        $this->abstractType,
        'Dieser Forschungsdatensatz enthält ausführliche Messungen zur Grundwasserneubildung und zu regionalen hydrologischen Prozessen über mehrere Jahre.',
    );
    descriptionForLanguageAssistant(
        $resource,
        $this->abstractType,
        'This description already has a language and must not produce a suggestion.',
        'en',
    );
    descriptionForLanguageAssistant($resource, $this->abstractType, '12345 / ABC');

    $discovered = app(Assistant::class)->runDiscovery(static function (): void {});
    $suggestions = AssistantSuggestion::query()
        ->where('assistant_id', 'description-language-suggestion')
        ->orderBy('target_id')
        ->get();

    expect($discovered)->toBe(2)
        ->and($suggestions)->toHaveCount(2)
        ->and($suggestions->pluck('target_id')->all())->toBe([$english->id, $german->id])
        ->and($suggestions->pluck('suggested_value')->all())->toBe(['en', 'de'])
        ->and($suggestions[0]->metadata['source_hash'] ?? null)->toBeString()
        ->and($suggestions[1]->metadata['source_snapshot']['description_id'] ?? null)->toBe($german->id);
});

it('eager-loads description types per discovery chunk', function (): void {
    $resource = Resource::factory()->create();

    foreach (range(1, 3) as $index) {
        descriptionForLanguageAssistant(
            $resource,
            $this->abstractType,
            "This research description number {$index} contains enough detailed English text for reliable language detection.",
        );
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(Assistant::class)->runDiscovery(static function (): void {});
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $descriptionTypeQueries = collect($queries)->filter(
        static fn (array $query): bool => str_contains(strtolower($query['query']), 'description_types'),
    );

    expect($descriptionTypeQueries)->toHaveCount(1);
});

it('accepts a current suggestion and changes only the addressed description', function (): void {
    $resource = Resource::factory()->create();
    $description = descriptionForLanguageAssistant(
        $resource,
        $this->abstractType,
        'This resource provides comprehensive observations and validated scientific measurements for hydrological research.',
    );
    $sibling = descriptionForLanguageAssistant($resource, $this->abstractType, '12345 / ABC');
    $assistant = app(Assistant::class);
    $assistant->runDiscovery(static function (): void {});
    $suggestion = AssistantSuggestion::query()->where('target_id', $description->id)->sole();

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeTrue()
        ->and($description->refresh()->language)->toBe('en')
        ->and($sibling->refresh()->language)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull();
});

it('rejects stale suggestions after description content changes', function (): void {
    $resource = Resource::factory()->create();
    $description = descriptionForLanguageAssistant(
        $resource,
        $this->abstractType,
        'This resource provides comprehensive observations and validated scientific measurements for hydrological research.',
    );
    $assistant = app(Assistant::class);
    $assistant->runDiscovery(static function (): void {});
    $suggestion = AssistantSuggestion::query()->where('target_id', $description->id)->sole();

    $description->update(['value' => 'The description was changed after discovery.']);
    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('stale')
        ->and($description->refresh()->language)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('refreshes an existing suggestion when changed text detects as the same language', function (): void {
    $resource = Resource::factory()->create();
    $description = descriptionForLanguageAssistant(
        $resource,
        $this->abstractType,
        'This resource provides comprehensive observations and validated scientific measurements for hydrological research.',
    );
    $assistant = app(Assistant::class);
    $assistant->runDiscovery(static function (): void {});
    $suggestion = AssistantSuggestion::query()->where('target_id', $description->id)->sole();
    $originalHash = $suggestion->metadata['source_hash'];

    $description->update([
        'value' => 'This updated resource description still contains detailed English observations and validated measurements for research.',
    ]);

    expect($assistant->runDiscovery(static function (): void {}))->toBe(0);

    $refreshedSuggestion = $suggestion->fresh();

    expect($refreshedSuggestion)->not->toBeNull()
        ->and($refreshedSuggestion->metadata['source_hash'])->not->toBe($originalHash)
        ->and($refreshedSuggestion->suggested_label)->toContain('This updated resource description');

    $result = $assistant->acceptSuggestion($refreshedSuggestion->id);

    expect($result['success'])->toBeTrue()
        ->and($description->refresh()->language)->toBe('en');
});

it('does not accept unsupported language codes', function (): void {
    $resource = Resource::factory()->create();
    $description = descriptionForLanguageAssistant(
        $resource,
        $this->abstractType,
        'This resource provides comprehensive observations and validated scientific measurements for hydrological research.',
    );
    $assistant = app(Assistant::class);
    $assistant->runDiscovery(static function (): void {});
    $suggestion = AssistantSuggestion::query()->where('target_id', $description->id)->sole();
    $suggestion->update(['suggested_value' => 'fr']);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($description->refresh()->language)->toBeNull();
});

it('honors dismissed suggestions during later discovery runs', function (): void {
    $resource = Resource::factory()->create();
    $description = descriptionForLanguageAssistant(
        $resource,
        $this->abstractType,
        'This resource provides comprehensive observations and validated scientific measurements for hydrological research.',
    );
    $user = User::factory()->create();
    $assistant = app(Assistant::class);
    $assistant->runDiscovery(static function (): void {});
    $suggestion = AssistantSuggestion::query()->where('target_id', $description->id)->sole();

    expect($assistant->declineSuggestion($suggestion->id, $user, 'Reviewed manually')['success'])->toBeTrue();
    expect($assistant->runDiscovery(static function (): void {}))->toBe(0);
    expect(AssistantSuggestion::query()->where('target_id', $description->id)->exists())->toBeFalse();
});
