<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Format;
use App\Models\Resource;
use App\Models\Size;
use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use App\Services\Assistance\BatchSuggestionActionService;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use App\Services\SizeFormat\DigitalContentSizeService;
use App\Services\SizeFormat\SizeFormatSizeParserService;
use App\Services\SizeFormat\SizeFormatSuggestionAcceptanceService;
use App\Services\SizeFormat\SizeFormatSuggestionDiscoveryService;
use Modules\Assistants\SizeFormatSuggestion\Assistant;

function createSizeFormatSuggestion(
    Assistant $assistant,
    Resource $resource,
    string $targetType,
    string $suggestedValue,
    ?array $metadata = null,
): AssistantSuggestion {
    return AssistantSuggestion::create([
        'assistant_id' => $assistant->getId(),
        'resource_id' => $resource->id,
        'target_type' => $targetType,
        'target_id' => $resource->id,
        'suggested_value' => $suggestedValue,
        'suggested_label' => strtoupper($targetType).': '.$suggestedValue,
        'metadata' => $metadata,
        'discovered_at' => now(),
    ]);
}

it('accepts a format suggestion by creating one resource format and deleting the suggestion', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $suggestion = createSizeFormatSuggestion(
        assistant: $assistant,
        resource: $resource,
        targetType: 'format',
        suggestedValue: 'text/csv',
    );

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toMatchArray([
        'success' => true,
        'message' => "Format 'text/csv' applied.",
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and(Format::where('resource_id', $resource->id)->where('value', 'text/csv')->count())->toBe(1);

    $secondSuggestion = createSizeFormatSuggestion(
        assistant: $assistant,
        resource: $resource,
        targetType: 'format',
        suggestedValue: 'text/csv',
    );

    $assistant->acceptSuggestion($secondSuggestion->id);

    expect(Format::where('resource_id', $resource->id)->where('value', 'text/csv')->count())->toBe(1);
});

it('normalizes extension-only format suggestions before storing them', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $suggestion = createSizeFormatSuggestion(
        assistant: $assistant,
        resource: $resource,
        targetType: 'format',
        suggestedValue: 'zip',
    );

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toMatchArray([
        'success' => true,
        'message' => "Format 'application/zip' applied.",
    ])
        ->and(Format::where('resource_id', $resource->id)->where('value', 'zip')->exists())->toBeFalse()
        ->and(Format::where('resource_id', $resource->id)->where('value', 'application/zip')->exists())->toBeTrue();
});

it('accepts a size suggestion using parsed metadata from discovery', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $suggestion = createSizeFormatSuggestion(
        assistant: $assistant,
        resource: $resource,
        targetType: 'size',
        suggestedValue: '2 GB',
        metadata: [
            'parsed_size' => [
                'numeric_value' => '2',
                'unit' => 'GB',
                'type' => null,
            ],
        ],
    );

    $result = $assistant->acceptSuggestion($suggestion->id);
    $size = Size::where('resource_id', $resource->id)->first();

    expect($result)->toMatchArray([
        'success' => true,
        'message' => "Size '2 GB' applied.",
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and($size)->not->toBeNull()
        ->and($size?->numeric_value)->toBe('2.0000')
        ->and($size?->unit)->toBe('GB')
        ->and($size?->type)->toBeNull()
        ->and($size?->export_string)->toBe('2 GB');
});

it('parses size suggestions on accept when discovery metadata is missing', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $suggestion = createSizeFormatSuggestion(
        assistant: $assistant,
        resource: $resource,
        targetType: 'size',
        suggestedValue: '512 MB',
    );

    $result = $assistant->acceptSuggestion($suggestion->id);
    $size = Size::where('resource_id', $resource->id)->first();

    expect($result['success'])->toBeTrue()
        ->and($size)->not->toBeNull()
        ->and($size?->numeric_value)->toBe('512.0000')
        ->and($size?->unit)->toBe('MB');
});

it('falls back to parsing suggested size values when stored parsed metadata is malformed', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $suggestion = createSizeFormatSuggestion(
        assistant: $assistant,
        resource: $resource,
        targetType: 'size',
        suggestedValue: '512 MB',
        metadata: [
            'parsed_size' => 'not an array',
        ],
    );

    $result = $assistant->acceptSuggestion($suggestion->id);
    $size = Size::where('resource_id', $resource->id)->first();

    expect($result['success'])->toBeTrue()
        ->and($size)->not->toBeNull()
        ->and($size?->numeric_value)->toBe('512.0000')
        ->and($size?->unit)->toBe('MB');
});

it('uses the persisted size export string in the success message', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $suggestion = createSizeFormatSuggestion(
        assistant: $assistant,
        resource: $resource,
        targetType: 'size',
        suggestedValue: '2MB',
    );

    $result = $assistant->acceptSuggestion($suggestion->id);
    $size = Size::where('resource_id', $resource->id)->sole();

    expect($result)->toMatchArray([
        'success' => true,
        'message' => "Size '2 MB' applied.",
    ])
        ->and($size->export_string)->toBe('2 MB');
});

it('keeps an unsupported suggestion pending and reports failure', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $suggestion = createSizeFormatSuggestion(
        assistant: $assistant,
        resource: $resource,
        targetType: 'checksum',
        suggestedValue: 'sha256:abc',
    );

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toMatchArray([
        'success' => false,
        'message' => 'Unknown suggestion type.',
    ])
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('requires explicit replacement before applying a conflicting digital size', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $current = $resource->sizes()->create([
        'numeric_value' => '1000',
        'unit' => 'bytes',
        'type' => 'Primary Data Size',
    ]);
    $pages = $resource->sizes()->create([
        'numeric_value' => '15',
        'unit' => 'pages',
    ]);
    $suggestion = createSizeFormatSuggestion(
        assistant: $assistant,
        resource: $resource,
        targetType: 'size',
        suggestedValue: '2048 Uncompressed Primary Data Size [bytes]',
        metadata: [
            'suggestion_kind' => 'size_conflict',
            'proposed_size' => [
                'numeric_value' => '2048',
                'unit' => 'bytes',
                'type' => 'Uncompressed Primary Data Size',
                'bytes' => 2048,
                'semantics' => 'uncompressed_primary_data',
            ],
            'current_sizes' => [[
                'id' => $current->id,
                'value' => $current->export_string,
                'bytes' => '1000',
            ]],
        ],
    );

    $rejected = $assistant->acceptSuggestion($suggestion->id);

    expect($rejected['success'])->toBeFalse()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and(Size::find($current->id))->not->toBeNull();

    $accepted = $assistant->acceptSuggestion($suggestion->id, ['size_conflict_resolution' => 'replace']);
    $acceptedSize = Size::query()
        ->where('resource_id', $resource->id)
        ->where('unit', 'bytes')
        ->sole();

    expect($accepted['success'])->toBeTrue()
        ->and(Size::find($current->id))->toBeNull()
        ->and(Size::find($pages->id))->not->toBeNull()
        ->and($acceptedSize->numeric_value)->toBe('2048.0000')
        ->and($acceptedSize->type)->toBe('Uncompressed Primary Data Size')
        ->and($acceptedSize->export_string)->toBe('2048 Uncompressed Primary Data Size [bytes]');
});

it('rejects a size conflict when a snapshotted byte value changed before acceptance', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $current = $resource->sizes()->create([
        'numeric_value' => '1000',
        'unit' => 'bytes',
        'type' => 'Primary Data Size',
    ]);
    $suggestion = createSizeFormatSuggestion(
        assistant: $assistant,
        resource: $resource,
        targetType: 'size',
        suggestedValue: '2048 Primary Data Size [bytes]',
        metadata: [
            'suggestion_kind' => 'size_conflict',
            'proposed_size' => [
                'numeric_value' => '2048',
                'unit' => 'bytes',
                'type' => 'Primary Data Size',
            ],
            'current_sizes' => [[
                'id' => $current->id,
                'value' => $current->export_string,
                'bytes' => '1000',
            ]],
        ],
    );

    $current->update(['numeric_value' => '1001']);
    $result = $assistant->acceptSuggestion($suggestion->id, ['size_conflict_resolution' => 'replace']);

    expect($result)->toMatchArray([
        'success' => false,
        'message' => 'The existing size metadata changed. Run discovery again.',
    ])
        ->and($current->fresh()?->numeric_value)->toBe('1001.0000')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and(Size::query()->where('resource_id', $resource->id)->count())->toBe(1);
});

it('rejects a size conflict when another eligible digital size was added before acceptance', function (): void {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $current = $resource->sizes()->create([
        'numeric_value' => '1000',
        'unit' => 'bytes',
        'type' => 'Primary Data Size',
    ]);
    $suggestion = createSizeFormatSuggestion(
        assistant: $assistant,
        resource: $resource,
        targetType: 'size',
        suggestedValue: '2048 Primary Data Size [bytes]',
        metadata: [
            'suggestion_kind' => 'size_conflict',
            'proposed_size' => [
                'numeric_value' => '2048',
                'unit' => 'bytes',
                'type' => 'Primary Data Size',
            ],
            'current_sizes' => [[
                'id' => $current->id,
                'value' => $current->export_string,
                'bytes' => '1000',
            ]],
        ],
    );
    $added = $resource->sizes()->create([
        'numeric_value' => '2',
        'unit' => 'KB',
        'type' => 'Primary Data Size',
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id, ['size_conflict_resolution' => 'replace']);

    expect($result['success'])->toBeFalse()
        ->and(Size::find($current->id))->not->toBeNull()
        ->and(Size::find($added->id))->not->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('synchronizes DataCite once after a single accepted suggestion', function (): void {
    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldReceive('syncIfRegistered')
        ->once()
        ->andReturnUsing(fn (Resource $resource): DataCiteSyncResult => DataCiteSyncResult::succeeded((string) $resource->doi));

    $acceptanceService = new SizeFormatSuggestionAcceptanceService(
        app(SizeFormatSizeParserService::class),
        app(DigitalContentSizeService::class),
        $syncService,
    );
    $assistant = new Assistant(
        app(SizeFormatSuggestionDiscoveryService::class),
        $acceptanceService,
    );
    $resource = Resource::factory()->create();
    $suggestion = createSizeFormatSuggestion($assistant, $resource, 'format', 'text/csv');

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeTrue()
        ->and($result['datacite_sync']['success'])->toBeTrue()
        ->and($result['synced_dois'])->toBe([$resource->doi]);
});

it('synchronizes DataCite only once for a batch of size and format suggestions', function (): void {
    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldReceive('syncIfRegistered')
        ->once()
        ->andReturnUsing(fn (Resource $resource): DataCiteSyncResult => DataCiteSyncResult::succeeded((string) $resource->doi));
    $assistant = new Assistant(
        app(SizeFormatSuggestionDiscoveryService::class),
        new SizeFormatSuggestionAcceptanceService(
            app(SizeFormatSizeParserService::class),
            app(DigitalContentSizeService::class),
            $syncService,
        ),
    );
    $registrar = new AssistantRegistrar;
    $registrar->register($assistant);
    $resource = Resource::factory()->create();
    $format = createSizeFormatSuggestion($assistant, $resource, 'format', 'text/csv');
    $size = createSizeFormatSuggestion(
        $assistant,
        $resource,
        'size',
        '2048 Primary Data Size [bytes]',
        ['proposed_size' => [
            'numeric_value' => '2048',
            'unit' => 'bytes',
            'type' => 'Primary Data Size',
            'bytes' => 2048,
            'semantics' => 'primary_data',
        ]],
    );

    $result = (new BatchSuggestionActionService($registrar, $syncService))->execute(
        'accept',
        $resource->id,
        [
            ['assistant_id' => $assistant->getId(), 'suggestion_id' => $format->id],
            ['assistant_id' => $assistant->getId(), 'suggestion_id' => $size->id],
        ],
        User::factory()->create(),
    );

    expect($result['success'])->toBeTrue()
        ->and($result['success_count'])->toBe(2)
        ->and($result['synced_dois'])->toBe([$resource->doi]);
});
