<?php

declare(strict_types=1);

use App\Models\DateType;
use App\Models\GeoLocation;
use App\Models\ResourceDate;
use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\User;
use App\Services\Assistance\AssistantManifest;
use App\Services\DateType\DateTypeDiscoveryService;
use App\Services\DateType\DateTypePlausibilityService;
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




























































































































































































































































































































function dateType(string $slug): DateType
{
    return DateType::FirstOrCreate
    (
        ['slug' => $slug],
        ['name' => $slug, 'is_active' => true],
    );
}

function createDate(Resource $resource, string $type, string $value): ResourceDate
{
    return ResourceDate::create
    ([
        'resource_id' => $resource->id,
        'date_type_id' => dateType($type)->id,
        'date_value' => $value,
    ]);
}

it('parses and autoloads the date type assistant manifest', function (): void {
    $manifest = AssistantManifest::fromFile(
        base_path('modules/assistants/DateTypeSuggestion/manifest.json'),
    );

    expect($manifest->id)->toBe(DateTypeDiscoveryService::ASSISTANT_ID)
        ->and($manifest->assistantClass)->toBe(Assistant::class)
        ->and(class_exists($manifest->assistantClass))->toBeTrue()
        ->and($manifest->routePrefix)->toBe('date-type');
});

it('records declined date type suggestions and does not rediscover the same suggestion', function () 
{
    app()->instance(DateTypeSchemaorgExtraction::class, new class extends DateTypeSchemaorgExtraction 
    {
        #[Override]
        public function loadAllowedSchemaorg(string $doi): array
        {
            return 
            [
                [
                    'suggestion_kind' => 'addition',
                    'target_date_type' => 'Created',
                    'normalized_value' => '2016-07-03',
                    'confidence' => 'high',
                    'source_url' => 'https://dataservices.gfz.de/test-dataset',
                    'evidence_source' => 'schema.org',
                    'schema_org_field' => 'dateCreated',
                ],
            ];
        }

    });

    $resource = Resource::factory()->create(['doi' => '10.5880/test.2026.816']);
    $assistant = app(Assistant::class);

    $assistant->runDiscovery(function (string $message): void {});

    $suggestion = AssistantSuggestion::query()
        ->where('assistant_id', $assistant->getId())
        ->where('resource_id', $resource->id)
        ->where('target_type', DateTypeDiscoveryService::targetTypeForDateType('Created'))
        ->orderBy('suggested_label')
        ->sole();

    $user = User::factory()->create();

    $assistant->declineSuggestion($suggestion->id, $user, 'Not needed');

    expect(AssistantSuggestion::query()->whereKey($suggestion->id)->exists())->toBeFalse()
        ->and(AssistantDismissed::query()
            ->where('assistant_id', $assistant->getId())
            ->where('target_id', $resource->id)
            ->where('dismissed_value', $suggestion->suggested_value)
            ->where('dismissed_by', $user->id)
            ->exists())->toBeTrue();

    $count = $assistant->runDiscovery(function (string $message): void {});

    expect($count)->toBe(0)
        ->and(AssistantSuggestion::query()->where('assistant_id', $assistant->getId())->exists())->toBeFalse();
});

it('discovers and stores a created suggestion from schema.org metadata', function (): void {
    app()->instance(DateTypeSchemaorgExtraction::class, new class extends DateTypeSchemaorgExtraction {
        #[Override]
        public function loadAllowedSchemaorg(string $doi): array
        {
            return [
                [
                    'suggestion_kind' => 'addition',
                    'target_date_type' => 'Created',
                    'normalized_value' => '2016-07-03',
                    'confidence' => 'high',
                    'source_url' => 'https://dataservices.gfz.de/test-dataset',
                    'evidence_source' => 'schema.org',
                    'schema_org_field' => 'dateCreated',
                ],
            ];
        }
    });

    $resource = Resource::factory()->create([
        'doi' => '10.5880/test.2026.816',
    ]);

    $assistant = app(Assistant::class);
    $count = $assistant->runDiscovery(function (string $message): void {});

    $suggestion = AssistantSuggestion::query()
        ->where('assistant_id', $assistant->getId())
        ->where('resource_id', $resource->id)
        ->sole();
    
    expect($count)->toBe(1)
        ->and($suggestion->target_type)->toBe(DateTypeDiscoveryService::targetTypeForDateType('Created'))
        ->and($suggestion->target_id)->toBe($resource->id)
        ->and($suggestion->suggested_value)->toBe('2016-07-03')
        ->and($suggestion->suggested_label)->toBe('CREATED: 2016-07-03')
        ->and($suggestion->similarity_score)->toBe(0.95)
        ->and($suggestion->metadata['suggestion_kind'])->toBe('addition')
        ->and($suggestion->metadata['target_date_type'])->toBe('Created')
        ->and($suggestion->metadata['source_url'])->toBe('https://dataservices.gfz.de/test-dataset');

});

it('discovers and stores a issued suggestion from schema.org metadata', function (): void {
    app()->instance(DateTypeSchemaorgExtraction::class, new class extends DateTypeSchemaorgExtraction {
        #[Override]
        public function loadAllowedSchemaorg(string $doi): array
        {
            return [
                [
                    'suggestion_kind' => 'addition',
                    'target_date_type' => 'Issued',
                    'normalized_value' => '2016-07-03',
                    'confidence' => 'high',
                    'source_url' => 'https://dataservices.gfz.de/test-dataset',
                    'evidence_source' => 'schema.org',
                    'schema_org_field' => 'datePublished',
                ],
            ];
        }
    });

    $resource = Resource::factory()->create([
        'doi' => '10.5880/test.2026.816',
    ]);

    $assistant = app(Assistant::class);
    $count = $assistant->runDiscovery(function (string $message): void {});

    $suggestion = AssistantSuggestion::query()
        ->where('assistant_id', $assistant->getId())
        ->where('resource_id', $resource->id)
        ->sole();
    
    expect($count)->toBe(1)
        ->and($suggestion->target_type)->toBe(DateTypeDiscoveryService::targetTypeForDateType('Issued'))
        ->and($suggestion->target_id)->toBe($resource->id)
        ->and($suggestion->suggested_value)->toBe('2016-07-03')
        ->and($suggestion->suggested_label)->toBe('ISSUED: 2016-07-03')
        ->and($suggestion->similarity_score)->toBe(0.95)
        ->and($suggestion->metadata['suggestion_kind'])->toBe('addition')
        ->and($suggestion->metadata['target_date_type'])->toBe('Issued')
        ->and($suggestion->metadata['source_url'])->toBe('https://dataservices.gfz.de/test-dataset');

});

it('discovers and stores created and issued suggestion from schema.org metadata', function (): void {
    app()->instance(DateTypeSchemaorgExtraction::class, new class extends DateTypeSchemaorgExtraction {
        #[Override]
        public function loadAllowedSchemaorg(string $doi): array
        {
            return [
                [
                    'suggestion_kind' => 'addition',
                    'target_date_type' => 'Created',
                    'normalized_value' => '2016-07-03',
                    'confidence' => 'high',
                    'source_url' => 'https://dataservices.gfz.de/test-dataset',
                    'evidence_source' => 'schema.org',
                    'schema_org_field' => 'dateCreated',
                ],
                [
                    'suggestion_kind' => 'addition',
                    'target_date_type' => 'Issued',
                    'normalized_value' => '2016-07-03',
                    'confidence' => 'high',
                    'source_url' => 'https://dataservices.gfz.de/test-dataset',
                    'evidence_source' => 'schema.org',
                    'schema_org_field' => 'datePublished',
                ],

            ];
        }
    });

    $resource = Resource::factory()->create([
        'doi' => '10.5880/test.2026.816',
    ]);

    $assistant = app(Assistant::class);
    $assistant->runDiscovery(function (string $message): void {});

    $suggestion = AssistantSuggestion::query()
        ->where('assistant_id', $assistant->getId())
        ->where('resource_id', $resource->id)
        ->whereIn('target_type', [
            DateTypeDiscoveryService::targetTypeForDateType('Created'),
            DateTypeDiscoveryService::targetTypeForDateType('Issued'),
        ])
        ->orderBy('suggested_label')
        ->get();
    
    expect($suggestion)->toHaveCount(2)
        ->and($suggestion[0]->target_type)->toBe(DateTypeDiscoveryService::targetTypeForDateType('Created'))
        ->and($suggestion[0]->target_id)->toBe($resource->id)
        ->and($suggestion[0]->suggested_value)->toBe('2016-07-03')
        ->and($suggestion[0]->suggested_label)->toBe('CREATED: 2016-07-03')
        ->and($suggestion[0]->similarity_score)->toBe(0.95)
        ->and($suggestion[0]->metadata['suggestion_kind'])->toBe('addition')
        ->and($suggestion[0]->metadata['target_date_type'])->toBe('Created')
        ->and($suggestion[0]->metadata['source_url'])->toBe('https://dataservices.gfz.de/test-dataset')
        ->and($suggestion[1]->target_type)->toBe(DateTypeDiscoveryService::targetTypeForDateType('Issued'))
        ->and($suggestion[1]->target_id)->toBe($resource->id)
        ->and($suggestion[1]->suggested_value)->toBe('2016-07-03')
        ->and($suggestion[1]->suggested_label)->toBe('ISSUED: 2016-07-03')
        ->and($suggestion[1]->similarity_score)->toBe(0.95)
        ->and($suggestion[1]->metadata['suggestion_kind'])->toBe('addition')
        ->and($suggestion[1]->metadata['target_date_type'])->toBe('Issued')
        ->and($suggestion[1]->metadata['source_url'])->toBe('https://dataservices.gfz.de/test-dataset');
});

it('does not store schema.org skip results during discovery', function (): void 
{
    app()->instance(DateTypeSchemaorgExtraction::class, new class extends DateTypeSchemaorgExtraction 
    {
        #[Override]
        public function loadAllowedSchemaorg(string $doi): array
        {
            return [
                [
                    'probe_method' => 'SKIP',
                    'skip_reason' => 'schemaorg_unreachable',
                    'source_url' => 'https://data.crosscite.org/application/vnd.schemaorg.ld+json/'.$doi,
                    'suggestions' => [],
                ],
            ];
        }
    });

    $resource = Resource::factory()->create
    ([
        'doi' => '10.5880/test.2026.816',
    ]);

    $assistant = app(Assistant::class);

    $count = $assistant->runDiscovery(function (string $message): void {});

    expect($count)->toBe(0)
        ->and(AssistantSuggestion::query()
            ->where('assistant_id', $assistant->getId())
            ->where('resource_id', $resource->id)
            ->exists())->toBeFalse();
});

it('does not discover created suggestions when Created already exists', function (): void 
{
    app()->instance(DateTypeSchemaorgExtraction::class, new class extends DateTypeSchemaorgExtraction 
    {
        #[Override]
        public function loadAllowedSchemaorg(string $doi): array
        {
            return [
                [
                    'suggestion_kind' => 'addition',
                    'target_date_type' => 'Created',
                    'normalized_value' => '2016-07-03',
                    'confidence' => 'high',
                    'source_url' => 'https://dataservices.gfz.de/test-dataset',
                    'evidence_source' => 'schema.org',
                    'schema_org_field' => 'dateCreated',
                ],
            ];
        }
    });

    $resource = Resource::factory()->create([
        'doi' => '10.5880/test.2026.816',
    ]);

    createDate($resource, 'Created', '2015-01-01');

    $assistant = app(Assistant::class);

    $count = $assistant->runDiscovery(function (string $message): void {});

    expect($count)->toBe(0)
        ->and(AssistantSuggestion::query()
            ->where('assistant_id', $assistant->getId())
            ->where('resource_id', $resource->id)
            ->where('target_type', DateTypeDiscoveryService::targetTypeForDateType('Created'))
            ->exists())->toBeFalse();
});

it('does not discover issued suggestions when Issued already exists', function (): void 
{
    app()->instance(DateTypeSchemaorgExtraction::class, new class extends DateTypeSchemaorgExtraction 
    {
        #[Override]
        public function loadAllowedSchemaorg(string $doi): array
        {
            return [
                [
                    'suggestion_kind' => 'addition',
                    'target_date_type' => 'Issued',
                    'normalized_value' => '2016-07-03',
                    'confidence' => 'high',
                    'source_url' => 'https://dataservices.gfz.de/test-dataset',
                    'evidence_source' => 'schema.org',
                    'schema_org_field' => 'datePublished',
                ],
            ];
        }
    });

    $resource = Resource::factory()->create([
        'doi' => '10.5880/test.2026.816',
    ]);

    createDate($resource, 'Issued', '2015-01-01');

    $assistant = app(Assistant::class);

    $count = $assistant->runDiscovery(function (string $message): void {});

    expect($count)->toBe(0)
        ->and(AssistantSuggestion::query()
            ->where('assistant_id', $assistant->getId())
            ->where('resource_id', $resource->id)
            ->where('target_type', DateTypeDiscoveryService::targetTypeForDateType('Issued'))
            ->exists())->toBeFalse();
});

it('discovers collected to coverage correction when collected date and geolocation counts match', function (): void {
    app()->instance(DateTypeSchemaorgExtraction::class, new class extends DateTypeSchemaorgExtraction {
        #[Override]
        public function loadAllowedSchemaorg(string $doi): array
        {
            return [];
        }
    });

    $resource = Resource::factory()->create([
        'doi' => '10.5880/test.coverage-correction',
    ]);

    createDate($resource, 'Collected', '2020-01-01');

    GeoLocation::factory()
        ->withPoint(13.0, 52.0)
        ->create(['resource_id' => $resource->id]);

    $assistant = app(Assistant::class);

    $count = $assistant->runDiscovery(function (string $message): void {});

    $suggestion = AssistantSuggestion::query()
        ->where('assistant_id', $assistant->getId())
        ->where('resource_id', $resource->id)
        ->where('target_type', DateTypeDiscoveryService::GEOLOCATION_COUNT_TARGET_TYPE)
        ->sole();

    expect($count)->toBe(1)
        ->and($suggestion->target_id)->toBe($resource->id)
        ->and($suggestion->suggested_value)->toBe('collected_dates:1;geo_locations:1')
        ->and($suggestion->suggested_label)->toBe('Collected dates (1) match geolocations (1)')
        ->and($suggestion->metadata['suggestion_kind'])->toBe('correction')
        ->and($suggestion->metadata['from_date_type'])->toBe('Collected')
        ->and($suggestion->metadata['target_date_type'])->toBe('Coverage')
        ->and($suggestion->metadata['collected_dates_count'])->toBe(1)
        ->and($suggestion->metadata['geo_locations_count'])->toBe(1);
});

it('stores plausibility hint suggestions', function (): void {
    app()->instance(DateTypeSchemaorgExtraction::class, new class extends DateTypeSchemaorgExtraction {
        #[Override]
        public function loadAllowedSchemaorg(string $doi): array
        {
            return [];
        }
    });

    $resource = Resource::factory()->create([
        'doi' => '10.5880/test.2026.816',
    ]);

    createDate($resource, 'Created', '2016-02-22');
    createDate($resource, 'Collected', '2017-01-01');

    $assistant = app(Assistant::class);

    $count = $assistant->runDiscovery(function (string $message): void {});

    $suggestion = AssistantSuggestion::query()
        ->where('assistant_id', $assistant->getId())
        ->where('resource_id', $resource->id)
        ->where('target_type', DateTypeDiscoveryService::TARGET_TYPE)
        ->where('suggested_value', 'like', '%occurs after%')
        ->sole();

    expect($count)->toBe(1)
        ->and($suggestion->target_id)->toBe($resource->id)
        ->and($suggestion->suggested_value)->toBe('Collected (2017-01-01) occurs after Created (2016-02-22). Please check whether the date values or date types are assigned correctly.')
        ->and($suggestion->suggested_label)->toBe($suggestion->suggested_value)
        ->and($suggestion->similarity_score)->toBe(0.65)
        ->and($suggestion->metadata['suggestion_kind'])->toBe('hint')
        ->and($suggestion->metadata['confidence'])->toBe('medium')
        ->and($suggestion->metadata['is_ambiguous'])->toBeTrue()
        ->and($suggestion->metadata['source_url'])->toBe('https://doi.org/10.5880/test.2026.816');
});
