<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\DateType;
use App\Models\GeoLocation;
use App\Models\Resource;
use App\Models\ResourceDate;
use App\Services\DateType\DateTypeDiscoveryService;
use App\Services\DateType\DateTypeSchemaorgExtraction;
use Modules\Assistants\DateTypeSuggestion\Assistant;

function createCollectedCoverageSuggestion(Assistant $assistant, Resource $resource): AssistantSuggestion
{
    return AssistantSuggestion::create([
        'assistant_id' => $assistant->getId(),
        'resource_id' => $resource->id,
        'target_type' => DateTypeDiscoveryService::GEOLOCATION_COUNT_TARGET_TYPE,
        'target_id' => $resource->id,
        'suggested_value' => 'collected_dates:2;geo_locations:2',
        'suggested_label' => 'Collected dates (2) match geolocations (2)',
        'metadata' => [
            'source' => 'database',
            'check' => 'collected_dates_vs_geolocations',
        ],
        'discovered_at' => now(),
    ]);
}

function createDateTypeSuggestion(
    Assistant $assistant,
    Resource $resource,
    string $suggestedValue,
    ?array $metadata = null,
    string $targetType = 'date_type',
): AssistantSuggestion {
    return AssistantSuggestion::create([
        'assistant_id' => $assistant->getId(),
        'resource_id' => $resource->id,
        'target_type' => $targetType,
        'target_id' => $resource->id,
        'suggested_value' => $suggestedValue,
        'suggested_label' => 'Date type: '.$suggestedValue,
        'metadata' => $metadata,
        'discovered_at' => now(),
    ]);
}

it('does not discover a collected to coverage correction when collected date and geolocation counts differ', function (): void {
    app()->instance(DateTypeSchemaorgExtraction::class, new class extends DateTypeSchemaorgExtraction
    {
        #[Override]
        public function loadAllowedSchemaorg(string $doi): array
        {
            return [];
        }
    });

    $collectedType = DateType::create(['name' => 'Collected', 'slug' => 'Collected', 'is_active' => true]);
    $resource = Resource::factory()->withDoi('10.5880/DATE-TYPE-MISMATCH')->create();

    ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $collectedType->id,
        'date_value' => '2020-01-01',
    ]);
    GeoLocation::factory()->withPoint(13.0, 52.0)->create(['resource_id' => $resource->id]);
    GeoLocation::factory()->withPoint(14.0, 53.0)->create(['resource_id' => $resource->id]);

    $count = app(Assistant::class)->runDiscovery(fn (string $message): null => null);

    expect($count)->toBe(0)
        ->and(AssistantSuggestion::where('assistant_id', 'date-type-suggestion')
            ->where('target_type', DateTypeDiscoveryService::GEOLOCATION_COUNT_TARGET_TYPE)
            ->exists())->toBeFalse();
});

it('discovers implausible date order as review hints', function (): void {
    app()->instance(DateTypeSchemaorgExtraction::class, new class extends DateTypeSchemaorgExtraction
    {
        #[Override]
        public function loadAllowedSchemaorg(string $doi): array
        {
            return [];
        }
    });

    $createdType = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    $submittedType = DateType::create(['name' => 'Submitted', 'slug' => 'Submitted', 'is_active' => true]);
    $resource = Resource::factory()->withDoi('10.5880/DATE-TYPE-HINT')->create();

    ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $createdType->id,
        'date_value' => '2024-07-01',
    ]);
    ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $submittedType->id,
        'date_value' => '2024-06-18',
    ]);
    GeoLocation::factory()->withPoint(13.0, 52.0)->create(['resource_id' => $resource->id]);

    $count = app(Assistant::class)->runDiscovery(fn (string $message): null => null);
    $suggestion = AssistantSuggestion::where('assistant_id', 'date-type-suggestion')->sole();
    $message = 'Created (2024-07-01) occurs after Submitted (2024-06-18). Please check whether the date values or date types are assigned correctly.';

    expect($count)->toBe(1)
        ->and($suggestion->resource_id)->toBe($resource->id)
        ->and($suggestion->target_type)->toBe(DateTypeDiscoveryService::TARGET_TYPE)
        ->and($suggestion->target_id)->toBe($resource->id)
        ->and($suggestion->suggested_value)->toBe($message)
        ->and($suggestion->suggested_label)->toBe($message)
        ->and($suggestion->similarity_score)->toBe(0.65)
        ->and($suggestion->metadata['suggestion_kind'])->toBe('review')
        ->and($suggestion->metadata['message'])->toBe($message)
        ->and($suggestion->metadata['confidence'])->toBe('medium')
        ->and($suggestion->metadata['is_ambiguous'])->toBeTrue();
});

it('accepts a collected to coverage correction by updating only the suggested resource dates', function (): void {
    $assistant = app(Assistant::class);
    $collectedType = DateType::create(['name' => 'Collected', 'slug' => 'Collected', 'is_active' => true]);
    $coverageType = DateType::create(['name' => 'Coverage', 'slug' => 'Coverage', 'is_active' => false]);
    $resource = Resource::factory()->create();
    $otherResource = Resource::factory()->create();

    $firstCollectedDate = ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $collectedType->id,
        'date_value' => '2020-01-01',
        'date_information' => 'Single collection date from import.',
    ]);
    $secondCollectedDate = ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $collectedType->id,
        'start_date' => '2020-01-01',
        'end_date' => '2020-12-31',
        'date_information' => 'Collection interval from import.',
    ]);
    $unrelatedCollectedDate = ResourceDate::create([
        'resource_id' => $otherResource->id,
        'date_type_id' => $collectedType->id,
        'date_value' => '2021-01-01',
    ]);

    $suggestion = createCollectedCoverageSuggestion($assistant, $resource);

    $result = $assistant->acceptSuggestion($suggestion->id);
    $firstCollectedDate->refresh();
    $secondCollectedDate->refresh();

    expect($result)->toMatchArray([
        'success' => true,
        'message' => 'Changed 2 Collected date entries to Coverage.',
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and($firstCollectedDate->date_type_id)->toBe($coverageType->id)
        ->and($firstCollectedDate->date_value)->toBe('2020-01-01')
        ->and($firstCollectedDate->start_date)->toBeNull()
        ->and($firstCollectedDate->end_date)->toBeNull()
        ->and($firstCollectedDate->date_information)->toBe('Single collection date from import.')
        ->and($secondCollectedDate->date_type_id)->toBe($coverageType->id)
        ->and($secondCollectedDate->date_value)->toBeNull()
        ->and($secondCollectedDate->start_date)->toBe('2020-01-01')
        ->and($secondCollectedDate->end_date)->toBe('2020-12-31')
        ->and($secondCollectedDate->date_information)->toBe('Collection interval from import.')
        ->and($unrelatedCollectedDate->fresh()->date_type_id)->toBe($collectedType->id);
});

it('keeps a collected to coverage correction pending when no collected dates exist', function (): void {
    $assistant = app(Assistant::class);
    $createdType = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    DateType::create(['name' => 'Coverage', 'slug' => 'Coverage', 'is_active' => false]);
    $resource = Resource::factory()->create();
    ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $createdType->id,
        'date_value' => '2020-01-01',
    ]);
    $suggestion = createCollectedCoverageSuggestion($assistant, $resource);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toMatchArray([
        'success' => false,
        'message' => 'No Collected date entries were found for this resource.',
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and(ResourceDate::where('resource_id', $resource->id)
            ->whereHas('dateType', fn ($query) => $query->where('slug', 'Coverage'))
            ->exists())->toBeFalse();
});

it('accepts a date type suggestion by creating a single date and deleting the suggestion', function (): void {
    $assistant = app(Assistant::class);
    $createdType = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    $resource = Resource::factory()->create();
    $suggestion = createDateTypeSuggestion($assistant, $resource, '2024-03-15', [
        'target_date_type' => 'Created',
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);
    $date = ResourceDate::where('resource_id', $resource->id)->sole();

    expect($result)->toMatchArray([
        'success' => true,
        'message' => "DateType 'Created' with value '2024-03-15' applied.",
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and($date->date_type_id)->toBe($createdType->id)
        ->and($date->date_value)->toBe('2024-03-15')
        ->and($date->start_date)->toBeNull()
        ->and($date->end_date)->toBeNull();
});

it('normalizes accepted date type suggestion values before storing them', function (): void {
    $assistant = app(Assistant::class);
    $createdType = DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    $resource = Resource::factory()->create();
    $suggestion = createDateTypeSuggestion($assistant, $resource, '15.03.2024', [
        'target_date_type' => 'Created',
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);
    $date = ResourceDate::where('resource_id', $resource->id)->sole();

    expect($result)->toMatchArray([
        'success' => true,
        'message' => "DateType 'Created' with value '2024-03-15' applied.",
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and($date->date_type_id)->toBe($createdType->id)
        ->and($date->date_value)->toBe('2024-03-15');
});

it('accepts a date type suggestion by creating a date range', function (): void {
    $assistant = app(Assistant::class);
    $validType = DateType::create(['name' => 'Valid', 'slug' => 'Valid', 'is_active' => true]);
    $resource = Resource::factory()->create();
    $suggestion = createDateTypeSuggestion($assistant, $resource, '2020-01-01 / 2020-12-31', [
        'target_date_type' => 'Valid',
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);
    $date = ResourceDate::where('resource_id', $resource->id)->sole();

    expect($result)->toMatchArray([
        'success' => true,
        'message' => "DateType 'Valid' range '2020-01-01/2020-12-31' applied.",
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and($date->date_type_id)->toBe($validType->id)
        ->and($date->date_value)->toBeNull()
        ->and($date->start_date)->toBe('2020-01-01')
        ->and($date->end_date)->toBe('2020-12-31');
});

it('keeps unsupported date type suggestions pending without writing dates', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $suggestion = createDateTypeSuggestion(
        assistant: $assistant,
        resource: $resource,
        suggestedValue: '2024-03-15',
        metadata: ['target_date_type' => 'Created'],
        targetType: 'checksum',
    );

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toMatchArray([
        'success' => false,
        'message' => 'Unknown suggestion type.',
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and(ResourceDate::where('resource_id', $resource->id)->exists())->toBeFalse();
});

it('keeps date type suggestions pending when target DateType metadata is missing', function (): void {
    $assistant = app(Assistant::class);
    DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    $resource = Resource::factory()->create();
    $suggestion = createDateTypeSuggestion($assistant, $resource, '2024-03-15', []);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toMatchArray([
        'success' => false,
        'message' => 'Missing target DateType.',
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and(ResourceDate::where('resource_id', $resource->id)->exists())->toBeFalse();
});

it('keeps date type suggestions pending when the target DateType no longer exists', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $suggestion = createDateTypeSuggestion($assistant, $resource, '2024-03-15', [
        'target_date_type' => 'Created',
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toMatchArray([
        'success' => false,
        'message' => 'Target DateType not found.',
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and(ResourceDate::where('resource_id', $resource->id)->exists())->toBeFalse();
});

it('keeps date type suggestions pending when the suggested date value is invalid', function (): void {
    $assistant = app(Assistant::class);
    DateType::create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    $resource = Resource::factory()->create();
    $suggestion = createDateTypeSuggestion($assistant, $resource, '2024-99-99', [
        'target_date_type' => 'Created',
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toMatchArray([
        'success' => false,
        'message' => 'Suggested date value is invalid.',
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and(ResourceDate::where('resource_id', $resource->id)->exists())->toBeFalse();
});

it('keeps collected to coverage corrections pending when the Coverage DateType is missing', function (): void {
    $assistant = app(Assistant::class);
    $collectedType = DateType::create(['name' => 'Collected', 'slug' => 'Collected', 'is_active' => true]);
    $resource = Resource::factory()->create();
    $collectedDate = ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $collectedType->id,
        'date_value' => '2020-01-01',
    ]);
    $suggestion = createCollectedCoverageSuggestion($assistant, $resource);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toMatchArray([
        'success' => false,
        'message' => 'Coverage DateType not found.',
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($collectedDate->fresh()->date_type_id)->toBe($collectedType->id);
});
