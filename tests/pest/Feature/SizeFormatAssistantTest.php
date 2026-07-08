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

it('keeps multiple discovered size suggestions for the same resource', function (): void {
    Resource::factory()->create(['doi' => '10.1234/MULTI.SIZE']);

    Http::fake(function ($request) {
        $url = $request->url();

        if ($url === 'https://doi.org/10.1234/MULTI.SIZE') {
            return Http::response('', 302, [
                'Location' => 'https://dataservices.gfz-potsdam.de/landing-multi-size',
            ]);
        }

        if ($url === 'https://dataservices.gfz-potsdam.de/landing-multi-size') {
            return Http::response(<<<'HTML'
                <html>
                    <body>
                        <a class="piwik_download" href="/download/first/">Download data</a>
                        <a class="piwik_download" href="/download/second/">Download data</a>
                    </body>
                </html>
                HTML);
        }

        if ($url === 'https://dataservices.gfz-potsdam.de/download/first/') {
            return Http::response(<<<'HTML'
                <a href="first.csv">first.csv</a> 2026-06-14 10:00 1M
                HTML, 200, [
                'Content-Type' => 'text/html',
            ]);
        }

        if ($url === 'https://dataservices.gfz-potsdam.de/download/second/') {
            return Http::response(<<<'HTML'
                <a href="second.csv">second.csv</a> 2026-06-14 10:00 2M
                HTML, 200, [
                'Content-Type' => 'text/html',
            ]);
        }

        return Http::response('', 404);
    });

    app(Assistant::class)->runDiscovery(fn (): null => null);

    $sizeSuggestions = AssistantSuggestion::where('assistant_id', 'size-format-suggestion')
        ->where('target_type', 'size')
        ->pluck('suggested_value')
        ->all();

    expect($sizeSuggestions)->toEqualCanonicalizing(['1 MB', '2 MB']);
});

it('exposes size and format suggestion preview metadata', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $resource = Resource::factory()->create(['doi' => '10.5880/TEST.SIZEFORMAT']);

    AssistantSuggestion::create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'application/zip',
        'suggested_label' => 'FORMAT: application/zip',
        'similarity_score' => null,
        'discovered_at' => now(),
        'metadata' => [
            'type' => 'format',
            'inferred_value' => 'application/zip',
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
            ->where('sections.size-format-suggestion.data.0.suggested_value', 'application/zip')
            ->where('sections.size-format-suggestion.data.0.suggested_label', 'FORMAT: application/zip')
            ->where('sections.size-format-suggestion.data.0.metadata.inferred_value', 'application/zip')
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
        ->and(Format::where('resource_id', $resource->id)->where('value', 'application/pdf')->exists())->toBeTrue();
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
        ->toBe(0);
    expect(Format::where('resource_id', $resource->id)->where('value', 'application/pdf')->count())
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

it('creates specific format and size suggestions with proper metadata during discovery', function (): void {
    // Fake the external HTTP network calls for discovery probing
    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();

        if ($url === 'https://doi.org/10.1234/size-format-test') {
            return Http::response('', 302, [
                'Location' => 'https://dataservices.gfz-potsdam.de/landing-size-format-test',
            ]);
        }

        if ($url === 'https://dataservices.gfz-potsdam.de/landing-size-format-test') {
            return Http::response(<<<'HTML'
                <html>
                    <body>
                        <a class="piwik_download" href="/download/test/">Download data</a>
                    </body>
                </html>
                HTML, 200, ['Content-Type' => 'text/html']);
        }

        if ($url === 'https://dataservices.gfz-potsdam.de/download/test/') {
            return Http::response(<<<'HTML'
                <a href="test.zip">test.zip</a> 2026-06-14 10:00 12.5M
                HTML, 200, ['Content-Type' => 'text/html']);
        }

        return Http::response('', 404);
    });

    // Create a pristine resource that triggers the discovery engine
    $resource = Resource::factory()->create(['doi' => '10.1234/size-format-test']);

    // Run the assistant's discovery method
    $count = app(Assistant::class)->runDiscovery(fn (): null => null);

    // 1. Assert overall discovery count contains both size and format
    expect($count)->toBe(2);

    // 2. Strong assertion for the format suggestion database state
    $formatSuggestion = AssistantSuggestion::where('assistant_id', 'size-format-suggestion')
        ->where('resource_id', $resource->id)
        ->where('target_type', 'format')
        ->first();

    expect($formatSuggestion)->not->toBeNull()
        ->and($formatSuggestion->suggested_value)->toBe('zip')
        ->and($formatSuggestion->suggested_label)->toBe('FORMAT: zip');

    // 3. Strong assertion for the size suggestion database state and parsed metadata
    $sizeSuggestion = AssistantSuggestion::where('assistant_id', 'size-format-suggestion')
        ->where('resource_id', $resource->id)
        ->where('target_type', 'size')
        ->first();

    expect($sizeSuggestion)->not->toBeNull()
        ->and($sizeSuggestion->suggested_value)->toBe('12.5M')
        ->and($sizeSuggestion->suggested_label)->toBe('SIZE: 12.5M')
        ->and($sizeSuggestion->metadata)->toBeArray()
        ->and($sizeSuggestion->metadata['parsed_size']['numeric_value'])->toBe('12.5')
        ->and($sizeSuggestion->metadata['parsed_size']['unit'])->toBe('M');
});