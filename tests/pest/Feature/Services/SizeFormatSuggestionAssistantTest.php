<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Format;
use App\Models\Resource;
use App\Models\Size;
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
