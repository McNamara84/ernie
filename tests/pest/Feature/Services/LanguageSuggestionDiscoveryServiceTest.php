<?php

declare(strict_types=1);

use App\Models\Language;
use App\Models\Resource;
use App\Models\Title;
use App\Services\Language\LanguageSuggestionDiscoveryService;

it('suggests a resource language from explicit title language attributes', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Eine Studie über geologische Proben',
        'language' => 'de',
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService();

    $count = $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact(
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
        onProgress: fn (string $message) => null,
    );

    expect($count)->toBe(1);
    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['suggestedValue'])->toBe('de');
    expect($suggestions[0]['suggestedLabel'])->toBe('German (de)');
    expect($suggestions[0]['similarityScore'])->toBe(0.95);
});

it('suggests a resource language for English from title and description text', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'active' => true, 'elmo_active' => true]);
    Language::firstOrCreate(['code' => 'fr'], ['name' => 'French', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'A study of groundwater quality',
        'language' => null,
    ]);

    $resource->descriptions()->create([
        'value' => 'This dataset contains research data and analysis for the study.',
        'description_type_id' => 1,
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService();

    $count = $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact(
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
        onProgress: fn (string $message) => null,
    );

    expect($count)->toBe(1);
    expect($suggestions[0]['suggestedValue'])->toBe('en');
    expect($suggestions[0]['suggestedLabel'])->toBe('English (en)');
    expect($suggestions[0]['similarityScore'])->toBeGreaterThan(0.3);
});

it('skips low-confidence text that is unlikely to be useful', function () {
    $resource = Resource::factory()->create(['language_id' => null]);

    Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => true, 'elmo_active' => true]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'ABC123 XYZ',
        'language' => null,
    ]);

    $suggestions = [];
    $service = new LanguageSuggestionDiscoveryService();

    $count = $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$suggestions): bool {
            $suggestions[] = compact(
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
        onProgress: fn (string $message) => null,
    );

    expect($count)->toBe(0);
    expect($suggestions)->toHaveCount(0);
});
