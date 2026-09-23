<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Format;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Size;
use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use App\Services\SizeFormat\SizeFormatSuggestionDiscoveryService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Assistants\SizeFormatSuggestion\Assistant;

use function Tests\Helpers\sizeFormatZipFixtureData;

function applySizeFormatSuggestion(Assistant $assistant, AssistantSuggestion $suggestion): array
{
    $method = new ReflectionMethod($assistant, 'applyAccepted');

    return $method->invoke($assistant, $suggestion);
}

function fakeSizeFormatZipDiscovery(Resource $resource, array $zipFiles): void
{
    $zipData = sizeFormatZipFixtureData($zipFiles);
    $downloadUrl = 'https://datapub.gfz.de/download/archive.zip';

    LandingPage::factory()->for($resource)->create([
        'ftp_url' => $downloadUrl,
        'downloads_unavailable' => false,
        'template' => 'default_gfz',
    ]);

    Http::fake(function ($request) use ($zipData, $downloadUrl) {
        $url = $request->url();

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

it('removes an ineligible suggestion backlog with set-based eligibility queries', function (): void {
    $resources = Resource::factory()->count(30)->create();

    foreach ($resources as $resource) {
        AssistantSuggestion::query()->create([
            'assistant_id' => SizeFormatSuggestionDiscoveryService::ASSISTANT_ID,
            'resource_id' => $resource->id,
            'target_type' => 'format',
            'target_id' => $resource->id,
            'suggested_value' => 'text/csv',
            'suggested_label' => 'FORMAT: text/csv',
            'metadata' => [],
            'discovered_at' => now(),
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $service = app(SizeFormatSuggestionDiscoveryService::class);
    $created = $service->discover(
        SizeFormatSuggestionDiscoveryService::ASSISTANT_ID,
        static fn (int $resourceId, string $targetType, int $targetId, string $value, string $label, ?float $score, ?array $metadata): bool => false,
        static function (string $message): void {},
    );

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $eligibilityQueries = array_filter(
        $queries,
        static fn (array $query): bool => str_contains(strtolower($query['query']), 'landing_pages'),
    );
    $suggestionDeletes = array_filter(
        $queries,
        static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'delete from "assistant_suggestions"'),
    );

    expect($created)->toBe(0)
        ->and(AssistantSuggestion::query()->where('assistant_id', SizeFormatSuggestionDiscoveryService::ASSISTANT_ID)->count())->toBe(0)
        ->and($service->lastReport()['stale_suggestions_removed'])->toBe(30)
        ->and($eligibilityQueries)->toHaveCount(3)
        ->and($suggestionDeletes)->toHaveCount(1);
});

it('withholds a total size when multiple top-level download sources may overlap', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.1234/MULTI.SIZE']);
    $landingPage = LandingPage::factory()->for($resource)->create([
        'ftp_url' => 'https://datapub.gfz.de/download/first/',
        'downloads_unavailable' => false,
        'template' => 'default_gfz',
    ]);
    LandingPageLink::query()->create([
        'landing_page_id' => $landingPage->id,
        'url' => 'https://datapub.gfz.de/download/second/',
        'label' => 'Second download',
        'kind' => LandingPageLink::KIND_DOWNLOAD,
        'position' => 0,
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if ($url === 'https://datapub.gfz.de/download/first/') {
            return Http::response(<<<'HTML'
                <a href="first.csv">first.csv</a> 2026-06-14 10:00 1M
                HTML, 200, [
                'Content-Type' => 'text/html',
            ]);
        }

        if ($url === 'https://datapub.gfz.de/download/second/') {
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

    expect($sizeSuggestions)->toBeEmpty();
});

it('discovers ZIP-contained formats and uncompressed size suggestions', function (): void {
    $doi = '10.1234/ZIP.CONTENT';
    $resource = Resource::factory()->create(['doi' => $doi]);

    fakeSizeFormatZipDiscovery($resource, [
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

    expect($count)->toBe(4)
        ->and($formatValues)->toEqualCanonicalizing(['application/zip', 'text/csv', 'application/pdf'])
        ->and($sizeValues)->toEqual(['3072 Uncompressed Primary Data Size [bytes]']);
});

it('regresses the production EXPQ download directory without counting its data description', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.5880/gfz.expq.2026.004']);
    $directoryUrl = 'https://datapub.gfz.de/download/10.5880.GFZ.EXPQ.2026.004-Ertzhg/';
    $zipUrl = $directoryUrl.'2026-004_Chen-et-al_data.zip';
    LandingPage::factory()->for($resource)->create([
        'ftp_url' => rtrim($directoryUrl, '/'),
        'primary_download_label' => 'Download data and description',
        'downloads_unavailable' => false,
        'template' => 'default_gfz',
    ]);

    $zipFiles = [];

    for ($index = 1; $index <= 8; $index++) {
        $zipFiles["data/table-{$index}.csv"] = str_repeat('c', 100000);
    }

    $zipFiles['data/workbook.xlsx'] = str_repeat('x', 1865858);
    $zipData = sizeFormatZipFixtureData($zipFiles);

    Http::fake(function ($request) use ($directoryUrl, $zipUrl, $zipData) {
        if (rtrim($request->url(), '/').'/' === $directoryUrl && $request->method() !== 'HEAD') {
            return Http::response(<<<'HTML'
                <a href="2026-004_Chen-et-al_data.zip">2026-004_Chen-et-al_data.zip</a> 2026-09-10 18:06 1.9M
                <a href="2026-004_Chen-et-al_data-description.pdf">2026-004_Chen-et-al_data-description.pdf</a> 2026-09-10 18:06 508K
                HTML);
        }

        if ($request->url() === $zipUrl) {
            return Http::response($zipData, 200, [
                'Content-Type' => 'application/zip',
                'Content-Length' => (string) strlen($zipData),
            ]);
        }

        return Http::response('', 405);
    });

    $count = app(Assistant::class)->runDiscovery(fn (): null => null);
    $suggestions = AssistantSuggestion::query()
        ->where('assistant_id', 'size-format-suggestion')
        ->where('resource_id', $resource->id)
        ->get();

    expect($count)->toBe(4)
        ->and($suggestions->where('target_type', 'format')->pluck('suggested_value')->all())
        ->toEqualCanonicalizing([
            'application/zip',
            'text/csv',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])
        ->and($suggestions->where('target_type', 'size')->sole()->suggested_value)
        ->toBe('2665858 Uncompressed Primary Data Size [bytes]')
        ->and($suggestions->where('target_type', 'size')->sole()->metadata['evidence']['excluded_files'][0]['filename'])
        ->toBe('2026-004_Chen-et-al_data-description.pdf');

    Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://doi.org/'));
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
        'numeric_value' => '1024',
        'unit' => 'bytes',
    ]);

    fakeSizeFormatZipDiscovery($resource, [
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

it('discovers content formats when the only existing format is application zip with MIME parameters', function (): void {
    $doi = '10.1234/ZIP.ONLY.PARAMETERIZED';
    $resource = Resource::factory()->create(['doi' => $doi]);
    Format::create([
        'resource_id' => $resource->id,
        'value' => 'application/zip; charset=utf-8',
    ]);
    Size::create([
        'resource_id' => $resource->id,
        'numeric_value' => '1024',
        'unit' => 'bytes',
    ]);

    fakeSizeFormatZipDiscovery($resource, [
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

it('adds missing formats even when another non-ZIP format exists', function (): void {
    $doi = '10.1234/NONZIP.EXISTING';
    $resource = Resource::factory()->create(['doi' => $doi]);
    Format::create([
        'resource_id' => $resource->id,
        'value' => 'text/plain',
    ]);

    fakeSizeFormatZipDiscovery($resource, [
        'data/table.csv' => str_repeat('c', 1024),
    ]);

    $count = app(Assistant::class)->runDiscovery(fn (): null => null);

    expect($count)->toBe(3)
        ->and(AssistantSuggestion::where('assistant_id', 'size-format-suggestion')->where('target_type', 'format')->pluck('suggested_value')->all())
        ->toEqualCanonicalizing(['application/zip', 'text/csv'])
        ->and(AssistantSuggestion::where('assistant_id', 'size-format-suggestion')->where('target_type', 'size')->value('suggested_value'))
        ->toBe('1024 Uncompressed Primary Data Size [bytes]');
});

it('creates an explicit conflict for a differing existing digital size', function (): void {
    $resource = Resource::factory()->create();
    LandingPage::factory()->for($resource)->create([
        'ftp_url' => 'https://datapub.gfz.de/download/data.csv',
        'template' => 'default_gfz',
        'downloads_unavailable' => false,
    ]);
    Format::query()->create(['resource_id' => $resource->id, 'value' => 'text/csv']);
    $current = Size::query()->create([
        'resource_id' => $resource->id,
        'numeric_value' => '1000',
        'unit' => 'bytes',
        'type' => 'Primary Data Size',
    ]);
    Http::fake([
        'https://datapub.gfz.de/download/data.csv' => Http::response('', 200, [
            'Content-Type' => 'text/csv',
            'Content-Length' => '2048',
        ]),
    ]);

    app(Assistant::class)->runDiscovery(fn (): null => null);
    $suggestion = AssistantSuggestion::query()
        ->where('assistant_id', 'size-format-suggestion')
        ->where('resource_id', $resource->id)
        ->where('target_type', 'size')
        ->sole();

    expect($suggestion->metadata['suggestion_kind'])->toBe('size_conflict')
        ->and($suggestion->metadata['current_sizes'][0]['id'])->toBe($current->id)
        ->and($suggestion->metadata['proposed_size']['bytes'])->toBe(2048);
});

it('removes stale suggestions after a complete probe and preserves them after a failed probe', function (): void {
    $resource = Resource::factory()->create();
    $landingPage = LandingPage::factory()->for($resource)->create([
        'ftp_url' => 'https://datapub.gfz.de/download/data.csv',
        'template' => 'default_gfz',
        'downloads_unavailable' => false,
    ]);
    $stale = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'application/pdf',
        'suggested_label' => 'FORMAT: application/pdf',
        'metadata' => [],
        'discovered_at' => now()->subDay(),
    ]);
    Http::fake([
        'https://datapub.gfz.de/download/data.csv' => Http::response('', 200, [
            'Content-Type' => 'text/csv',
            'Content-Length' => '2048',
        ]),
    ]);

    app(Assistant::class)->runDiscovery(fn (): null => null);

    expect(AssistantSuggestion::find($stale->id))->toBeNull()
        ->and(AssistantSuggestion::query()
            ->where('resource_id', $resource->id)
            ->where('suggested_value', 'text/csv')
            ->exists())->toBeTrue();

    $preserved = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'application/pdf',
        'suggested_label' => 'FORMAT: application/pdf',
        'metadata' => [],
        'discovered_at' => now(),
    ]);
    $landingPage->update(['ftp_url' => 'https://datapub.gfz.de/download/unreachable/']);
    Http::fake([
        'https://datapub.gfz.de/download/unreachable/*' => Http::response('', 500),
    ]);

    app(Assistant::class)->runDiscovery(fn (): null => null);

    expect(AssistantSuggestion::find($preserved->id))->not->toBeNull();
});

it('reconciles formats but preserves sizes when HEAD evidence has no size', function (): void {
    $resource = Resource::factory()->create();
    LandingPage::factory()->for($resource)->create([
        'ftp_url' => 'https://datapub.gfz.de/download/data.csv',
        'template' => 'default_gfz',
        'downloads_unavailable' => false,
    ]);
    $staleFormat = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'application/pdf',
        'suggested_label' => 'FORMAT: application/pdf',
        'metadata' => [],
        'discovered_at' => now()->subDay(),
    ]);
    $staleSize = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'size',
        'target_id' => $resource->id,
        'suggested_value' => '1024 Primary Data Size [bytes]',
        'suggested_label' => 'SIZE: 1024 Primary Data Size [bytes]',
        'metadata' => [],
        'discovered_at' => now()->subDay(),
    ]);

    Http::fake([
        'https://datapub.gfz.de/download/data.csv' => Http::response('', 200, [
            'Content-Type' => 'text/csv',
        ]),
    ]);

    app(Assistant::class)->runDiscovery(fn (): null => null);

    expect(AssistantSuggestion::find($staleFormat->id))->toBeNull()
        ->and(AssistantSuggestion::find($staleSize->id))->not->toBeNull()
        ->and(AssistantSuggestion::query()
            ->where('resource_id', $resource->id)
            ->where('target_type', 'format')
            ->where('suggested_value', 'text/csv')
            ->exists())->toBeTrue();
});

it('reconciles sizes but preserves formats when ranged evidence has no format', function (): void {
    $resource = Resource::factory()->create();
    $url = 'https://datapub.gfz.de/download/data.bin';
    LandingPage::factory()->for($resource)->create([
        'ftp_url' => $url,
        'template' => 'default_gfz',
        'downloads_unavailable' => false,
    ]);
    $staleFormat = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'application/pdf',
        'suggested_label' => 'FORMAT: application/pdf',
        'metadata' => [],
        'discovered_at' => now()->subDay(),
    ]);
    $staleSize = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'size',
        'target_id' => $resource->id,
        'suggested_value' => '1024 Primary Data Size [bytes]',
        'suggested_label' => 'SIZE: 1024 Primary Data Size [bytes]',
        'metadata' => [],
        'discovered_at' => now()->subDay(),
    ]);

    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }

        return Http::response('', 206, ['Content-Range' => 'bytes 0-1023/4096']);
    });

    app(Assistant::class)->runDiscovery(fn (): null => null);

    expect(AssistantSuggestion::find($staleFormat->id))->not->toBeNull()
        ->and(AssistantSuggestion::find($staleSize->id))->toBeNull()
        ->and(AssistantSuggestion::query()
            ->where('resource_id', $resource->id)
            ->where('target_type', 'size')
            ->where('suggested_value', '4096 Primary Data Size [bytes]')
            ->exists())->toBeTrue();
});

it('keeps stale suggestions when only filename fallback evidence is available', function (): void {
    $resource = Resource::factory()->create();
    LandingPage::factory()->for($resource)->create([
        'ftp_url' => 'https://datapub.gfz.de/download/unreachable.csv',
        'template' => 'default_gfz',
        'downloads_unavailable' => false,
    ]);
    $stale = AssistantSuggestion::query()->create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'application/pdf',
        'suggested_label' => 'FORMAT: application/pdf',
        'metadata' => [],
        'discovered_at' => now()->subDay(),
    ]);

    Http::fake([
        'https://datapub.gfz.de/download/unreachable.csv' => Http::response('', 500),
    ]);

    app(Assistant::class)->runDiscovery(fn (): null => null);

    expect(AssistantSuggestion::find($stale->id))->not->toBeNull()
        ->and(AssistantSuggestion::query()
            ->where('resource_id', $resource->id)
            ->where('suggested_value', 'text/csv')
            ->exists())->toBeTrue();
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
        ->getJson('/assistance/data/size-format-suggestion')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'data.0.suggestions')
        ->assertJsonPath('data.0.suggestions.0.suggested_value', 'application/zip')
        ->assertJsonPath('data.0.suggestions.0.suggested_label', 'FORMAT: application/zip')
        ->assertJsonPath('data.0.suggestions.0.metadata.inferred_value', 'application/zip')
        ->assertJsonPath('data.0.suggestions.0.metadata.source_url', 'https://datapub.gfz.de/download/10.5880/TEST.SIZEFORMAT')
        ->assertJsonPath('data.0.suggestions.0.metadata.probe_method', 'DIRECTORY_LISTING')
        ->assertJsonPath('data.0.suggestions.0.metadata.evidence', 'File extension detected from download listing.');
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

it('keeps an existing ZIP container format when accepting a ZIP-content format suggestion', function (): void {
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
        ->and(Format::where('resource_id', $resource->id)->where('value', 'application/zip')->exists())->toBeTrue();
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
