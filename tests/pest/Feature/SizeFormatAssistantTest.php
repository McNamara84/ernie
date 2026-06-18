<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Format;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Size;
use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use Illuminate\Support\Facades\Http;
use Modules\Assistants\SizeFormatSuggestion\Assistant;

function applySizeFormatSuggestion(Assistant $assistant, AssistantSuggestion $suggestion): array
{
    $method = new ReflectionMethod($assistant, 'applyAccepted');

    return $method->invoke($assistant, $suggestion);
}

it('registers via auto-discovery', function (): void {
    $registrar = app(AssistantRegistrar::class);
    expect($registrar->has('size-format-suggestion'))->toBeTrue();
});

it('does not discover suggestions for physical object resources', function (): void {
    $physicalObjectType = ResourceType::factory()->create([
        'name' => 'Physical Object',
        'slug' => 'physical-object',
    ]);
    $resource = Resource::factory()->create([
        'doi' => '10.5880/IGSN.TEST.001',
        'resource_type_id' => $physicalObjectType->id,
    ]);
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'sample_type' => 'rock',
        'material' => 'granite',
    ]);

    Http::fake();

    $count = app(Assistant::class)->runDiscovery(fn (): null => null);

    expect($count)->toBe(0)
        ->and(AssistantSuggestion::where('assistant_id', 'size-format-suggestion')->count())->toBe(0);
    Http::assertNothingSent();
});

it('exposes size and format suggestion preview metadata', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $resource = Resource::factory()->create(['doi' => '10.5880/TEST.SIZEFORMAT']);

    AssistantSuggestion::create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'zip',
        'suggested_label' => 'FORMAT: zip',
        'similarity_score' => null,
        'discovered_at' => now(),
        'metadata' => [
            'type' => 'format',
            'inferred_value' => 'zip',
            'source_url' => 'https://datapub.gfz.de/download/10.5880/TEST.SIZEFORMAT',
            'probe_method' => 'DIRECTORY_LISTING',
            'evidence' => 'File extension detected from download listing.',
            'confidence' => 'medium',
        ],
    ]);

    $this->actingAs($user)
        ->get('/assistance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('assistance')
            ->has('sections.size-format-suggestion.data', 1)
            ->where('sections.size-format-suggestion.data.0.suggested_value', 'zip')
            ->where('sections.size-format-suggestion.data.0.suggested_label', 'FORMAT: zip')
            ->where('sections.size-format-suggestion.data.0.metadata.inferred_value', 'zip')
            ->where('sections.size-format-suggestion.data.0.metadata.source_url', 'https://datapub.gfz.de/download/10.5880/TEST.SIZEFORMAT')
            ->where('sections.size-format-suggestion.data.0.metadata.probe_method', 'DIRECTORY_LISTING')
            ->where('sections.size-format-suggestion.data.0.metadata.evidence', 'File extension detected from download listing.')
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
