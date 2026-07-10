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

function sizeFormatAssistantZipData(array $files): string
{
    $temporaryPath = tempnam(sys_get_temp_dir(), 'size-format-assistant-zip-test-');
    $zip = new ZipArchive;
    $zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($files as $filename => $contents) {
        $zip->addFromString((string) $filename, (string) $contents);
    }

    $zip->close();
    $zipData = file_get_contents($temporaryPath);
    unlink($temporaryPath);

    if ($zipData === false) {
        throw new RuntimeException('Could not read generated ZIP test data.');
    }

    return $zipData;
}

function fakeSizeFormatZipDiscovery(string $doi, array $zipFiles): void
{
    $zipData = sizeFormatAssistantZipData($zipFiles);
    $downloadUrl = 'https://dataservices.gfz-potsdam.de/download/archive.zip';

    Http::fake(function ($request) use ($doi, $zipData, $downloadUrl) {
        $url = $request->url();

        if ($url === 'https://doi.org/'.$doi) {
            return Http::response('', 302, [
                'Location' => 'https://dataservices.gfz-potsdam.de/landing-zip',
            ]);
        }

        if ($url === 'https://dataservices.gfz-potsdam.de/landing-zip') {
            return Http::response(<<<'HTML'
                <html>
                    <body>
                        <a class="piwik_download" href="/download/archive.zip">Download data</a>
                    </body>
                </html>
                HTML);
        }

        if ($url === $downloadUrl && $request->method() === 'HEAD') {
            return Http::response('', 200, [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) strlen($zipData),
            ]);
        }

        if ($url === $downloadUrl) {
            return Http::response($zipData, 200, [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) strlen($zipData),
            ]);
        }

        return Http::response('', 404);
    });
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

it('discovers ZIP-contained formats and uncompressed size suggestions', function (): void {
    $doi = '10.1234/ZIP.CONTENT';
    Resource::factory()->create(['doi' => $doi]);

    fakeSizeFormatZipDiscovery($doi, [
        'data/table.csv' => str_repeat('c', 1024),
        'docs/manual.pdf' => str_repeat('p', 2048),
    ]);

    $count = app(Assistant::class)->runDiscovery(fn (): null => null);

    $formatValues = AssistantSuggestion::where('assistant_id', 'size-format-suggestion')
        ->where('target_type', 'format')
        ->pluck('suggested_value')
        ->all();
    $sizeValues = AssistantSuggestion::where('assistant_id', 'size-format-suggestion')
        ->where('target_type', 'size')
        ->pluck('suggested_value')
        ->all();

    expect($count)->toBe(3)
        ->and($formatValues)->toEqualCanonicalizing(['text/csv', 'application/pdf'])
        ->and($formatValues)->not->toContain('application/zip')
        ->and($sizeValues)->toEqual(['3 KB']);
});

it('discovers content formats when the only existing format is application zip', function (): void {
    $doi = '10.1234/ZIP.ONLY.EXISTING';
    $resource = Resource::factory()->create(['doi' => $doi]);
    Format::create([
        'resource_id' => $resource->id,
        'value' => 'application/zip',
    ]);
    Size::create([
        'resource_id' => $resource->id,
        'numeric_value' => '1',
        'unit' => 'KB',
    ]);

    fakeSizeFormatZipDiscovery($doi, [
        'data/table.csv' => str_repeat('c', 1024),
    ]);

    $count = app(Assistant::class)->runDiscovery(fn (): null => null);

    $formatValues = AssistantSuggestion::where('assistant_id', 'size-format-suggestion')
        ->where('target_type', 'format')
        ->pluck('suggested_value')
        ->all();

    expect($count)->toBe(1)
        ->and($formatValues)->toEqual(['text/csv']);
});

it('keeps existing non-ZIP formats as the format discovery stop condition', function (): void {
    $doi = '10.1234/NONZIP.EXISTING';
    $resource = Resource::factory()->create(['doi' => $doi]);
    Format::create([
        'resource_id' => $resource->id,
        'value' => 'text/plain',
    ]);

    fakeSizeFormatZipDiscovery($doi, [
        'data/table.csv' => str_repeat('c', 1024),
    ]);

    $count = app(Assistant::class)->runDiscovery(fn (): null => null);

    expect($count)->toBe(1)
        ->and(AssistantSuggestion::where('assistant_id', 'size-format-suggestion')->where('target_type', 'format')->count())->toBe(0)
        ->and(AssistantSuggestion::where('assistant_id', 'size-format-suggestion')->where('target_type', 'size')->value('suggested_value'))->toBe('1 KB');
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

it('removes an existing ZIP container format when accepting a ZIP-content format suggestion', function (): void {
    $resource = Resource::factory()->create();
    Format::create([
        'resource_id' => $resource->id,
        'value' => 'application/zip',
    ]);

    $suggestion = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'text/csv',
        'suggested_label' => 'FORMAT: text/csv',
        'similarity_score' => null,
        'metadata' => [
            'type' => 'format',
            'inferred_value' => 'text/csv',
            'source_url' => 'https://datapub.gfz.de/download/archive.zip',
            'probe_method' => 'ZIP_CONTENT_LISTING',
            'confidence' => 'medium',
        ],
        'discovered_at' => now(),
    ]);

    $result = applySizeFormatSuggestion(app(Assistant::class), $suggestion);

    expect($result['success'])->toBeTrue()
        ->and(Format::where('resource_id', $resource->id)->where('value', 'text/csv')->exists())->toBeTrue()
        ->and(Format::where('resource_id', $resource->id)->where('value', 'application/zip')->exists())->toBeFalse();
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
