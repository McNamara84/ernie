<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Format;
use App\Models\Resource;
use App\Models\Size;
use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use Modules\Assistants\SizeFormatSuggestion\Assistant;


function applySizeFormatSuggestion(Assistant $assistant, AssistantSuggestion $suggestion): array
{
    $method = new ReflectionMethod($assistant, 'applyAccepted');
    $method->setAccessible(true);

    return $method->invoke($assistant, $suggestion);
}

it('registers via auto-discovery', function (): void {
    $registrar = app(AssistantRegistrar::class);

    expect($registrar->has('size-format-suggestion'))->toBeTrue();
});

it('returns suggestions for the assistance page', function (): void {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/assistance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('assistance')
            ->has('manifests')
            ->has('sections')
        );
});

it('accepts a format suggestion and creates a format record', function (): void {
    $resource = Resource::factory()->create();

    $suggestion = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'pdf',
        'suggested_label' => 'FORMAT: pdf',
        'similarity_score' => null,
        'metadata' => [
            'type' => 'format',
            'inferred_value' => 'pdf',
            'source_url' => 'https://files.example.org/data.pdf',
            'probe_method' => 'FILENAME_EXTENSION_FALLBACK',
            'confidence' => 'medium',
        ],
        'discovered_at' => now(),
    ]);

    $result = applySizeFormatSuggestion(app(Assistant::class), $suggestion);

    expect($result['success'])->toBeTrue()
        ->and(Format::where('resource_id', $resource->id)->where('value', 'pdf')->exists())->toBeTrue();
});

it('does not create duplicate format records when accepting the same suggestion twice', function (): void {
    $resource = Resource::factory()->create();

    $suggestion = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'pdf',
        'suggested_label' => 'FORMAT: pdf',
        'similarity_score' => null,
        'metadata' => [],
        'discovered_at' => now(),
    ]);

    $assistant = app(Assistant::class);

    applySizeFormatSuggestion($assistant, $suggestion);
    applySizeFormatSuggestion($assistant, $suggestion);

    expect(Format::where('resource_id', $resource->id)->where('value', 'pdf')->count())
        ->toBe(1);
});

it('accepts a size suggestion and creates a size record', function (): void {
    $resource = Resource::factory()->create();

    $suggestion = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'size',
        'target_id' => $resource->id,
        'suggested_value' => '8.1M',
        'suggested_label' => 'SIZE: 8.1M',
        'similarity_score' => null,
        'metadata' => [
            'parsed_size' => [
                'numeric_value' => '8.1',
                'unit' => 'M',
                'type' => null,
            ],
            'source_url' => 'https://files.example.org/data.zip',
            'probe_method' => 'DIRECTORY_LISTING',
            'confidence' => 'high',
        ],
        'discovered_at' => now(),
        
    ]);

    $result = applySizeFormatSuggestion(app(Assistant::class), $suggestion);

    expect($result['success'])->toBeTrue()
        ->and(Size::where('resource_id', $resource->id)
            ->where('numeric_value', '8.1')
            ->where('unit', 'M')
            ->exists())->toBeTrue();
});

it('does not create duplicate size records when accepting the same suggestion twice', function (): void {
    $resource = Resource::factory()->create();

    $suggestion = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'size',
        'target_id' => $resource->id,
        'suggested_value' => '8.1M',
        'suggested_label' => 'SIZE: 8.1M',
        'similarity_score' => null,
        'metadata' => [
            'parsed_size' => [
                'numeric_value' => '8.1',
                'unit' => 'M',
                'type' => null,
            ],
        ],
        'discovered_at' => now(),
    ]);

    $assistant = app(Assistant::class);

    applySizeFormatSuggestion($assistant, $suggestion);
    applySizeFormatSuggestion($assistant, $suggestion);

    expect(Size::where('resource_id', $resource->id)
        ->where('numeric_value', '8.1')
        ->where('unit', 'M')
        ->count())->toBe(1);
});

it('returns an error for unknown suggestion types', function (): void {
    $resource = Resource::factory()->create();

    $suggestion = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'unknown',
        'target_id' => $resource->id,
        'suggested_value' => 'something',
        'suggested_label' => 'UNKNOWN: something',
        'similarity_score' => null,
        'metadata' => [],
        'discovered_at' => now(),
    ]);

    $result = applySizeFormatSuggestion(app(Assistant::class), $suggestion);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Unknown suggestion type.');
});