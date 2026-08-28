<?php

declare(strict_types=1);

use App\Console\Commands\RepairLegacyDescriptionBreaks;
use App\Jobs\SyncImportedResourcesWithDataCiteJob;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\Descriptions\LegacyDescriptionBreakCleanupService;
use App\Services\DoiSuggestionService;
use App\Support\LegacyDescriptionBreakNormalizer;
use Database\Seeders\DescriptionTypeSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

covers(
    RepairLegacyDescriptionBreaks::class,
    LegacyDescriptionBreakCleanupService::class,
    LegacyDescriptionBreakNormalizer::class,
);

beforeEach(function (): void {
    test()->seed(DescriptionTypeSeeder::class);

    Config::set('database.connections.metaworks', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    DB::purge('metaworks');
    Schema::connection('metaworks')->create('resource', function (Blueprint $table): void {
        $table->id();
        $table->string('identifier')->nullable();
    });
});

afterEach(function (): void {
    Schema::connection('metaworks')->dropIfExists('resource');
    DB::disconnect('metaworks');
});

function legacyBreakDescription(Resource $resource, string $value, ?string $html = null): Description
{
    return Description::query()->create([
        'resource_id' => $resource->id,
        'description_type_id' => DescriptionType::query()->where('slug', 'Abstract')->value('id'),
        'value' => $value,
        'landing_page_html' => $html,
    ]);
}

function legacyBreakResource(string $doi, int $legacyId): Resource
{
    return Resource::factory()->create([
        'doi' => $doi,
        'legacy_source' => 'sumario-pmd',
        'legacy_source_id' => $legacyId,
        'legacy_source_status' => 'released',
        'legacy_description_breaks_normalized_at' => null,
    ]);
}

it('audits linked legacy descriptions without mutating data or caches', function (): void {
    $resource = legacyBreakResource('10.5880/legacy.dry', 101);
    $description = legacyBreakDescription(
        $resource,
        "First\n\n\n\nSecond",
        '<p>First<br><br><br><br>Second</p>',
    );
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

    $cache = Mockery::mock(LandingPageRenderDataCacheService::class);
    $cache->shouldNotReceive('forgetById');
    $service = new LegacyDescriptionBreakCleanupService(
        new LegacyDescriptionBreakNormalizer,
        app(DoiSuggestionService::class),
        $cache,
    );

    $result = $service->run();

    expect($result)->toMatchArray([
        'resources_scanned' => 1,
        'legacy_resources' => 1,
        'descriptions_scanned' => 1,
        'changed' => 1,
        'unchanged' => 0,
        'breaks_removed' => 4,
        'sync_resource_ids' => [],
    ])->and($result['records'][0])->toMatchArray([
        'status' => 'would_update',
        'descriptions_changed' => 1,
        'datacite_sync_status' => 'would_queue',
    ])->and($description->fresh()->value)->toBe("First\n\n\n\nSecond")
        ->and($description->fresh()->landing_page_html)->toBe('<p>First<br><br><br><br>Second</p>')
        ->and($resource->fresh()->legacy_description_breaks_normalized_at)->toBeNull()
        ->and($landingPage->fresh())->not->toBeNull();
});

it('applies the pairwise repair once and invalidates a published landing page cache', function (): void {
    Config::set('datacite.test_mode', false);
    $resource = legacyBreakResource('10.5880/legacy.apply', 102);
    $description = legacyBreakDescription(
        $resource,
        "First\n\n\n\nSecond",
        '<p>First<br><br><br><br>Second</p>',
    );
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

    $cache = Mockery::mock(LandingPageRenderDataCacheService::class);
    $cache->shouldReceive('forgetById')->once()->with($landingPage->id)->andReturnTrue();
    $service = new LegacyDescriptionBreakCleanupService(
        new LegacyDescriptionBreakNormalizer,
        app(DoiSuggestionService::class),
        $cache,
    );

    $applied = $service->run(apply: true);

    expect($applied)->toMatchArray([
        'resources_scanned' => 1,
        'changed' => 1,
        'breaks_removed' => 4,
        'sync_resource_ids' => [$resource->id],
    ])->and($description->fresh()->value)->toBe("First\n\nSecond")
        ->and($description->fresh()->landing_page_html)->toBe('<p>First<br><br>Second</p>')
        ->and($resource->fresh()->legacy_description_breaks_normalized_at)->not->toBeNull();

    expect($service->run(apply: true))->toMatchArray([
        'resources_scanned' => 0,
        'changed' => 0,
        'breaks_removed' => 0,
        'sync_resource_ids' => [],
    ])->and($description->fresh()->value)->toBe("First\n\nSecond");
});

it('matches unlinked legacy resources by DOI and ignores regular resources', function (): void {
    $legacy = Resource::factory()->create([
        'doi' => '10.5880/LEGACY.MATCH',
        'legacy_description_breaks_normalized_at' => null,
    ]);
    legacyBreakDescription($legacy, "Legacy\n\ntext");
    $regular = Resource::factory()->create([
        'doi' => '10.5880/regular.resource',
        'legacy_description_breaks_normalized_at' => null,
    ]);
    legacyBreakDescription($regular, "Regular\n\ntext");
    $legacyId = DB::connection('metaworks')->table('resource')->insertGetId([
        'identifier' => '10.5880/legacy.match',
    ]);

    $result = app(LegacyDescriptionBreakCleanupService::class)->run();

    expect($result)->toMatchArray([
        'resources_scanned' => 2,
        'legacy_resources' => 1,
        'changed' => 1,
        'not_legacy' => 1,
        'last_scanned_resource_id' => $regular->id,
    ])->and($result['records'])->toHaveCount(1)
        ->and($result['records'][0])->toMatchArray([
            'resource_id' => $legacy->id,
            'legacy_resource_id' => $legacyId,
            'match_method' => 'doi',
            'status' => 'would_update',
        ]);
});

it('does not count blank unlinked DOIs against a bounded cleanup batch', function (): void {
    Resource::factory()->create([
        'doi' => '',
        'legacy_description_breaks_normalized_at' => null,
    ]);
    Resource::factory()->create([
        'doi' => '   ',
        'legacy_description_breaks_normalized_at' => null,
    ]);
    $legacy = Resource::factory()->create([
        'doi' => '10.5880/nonblank.match',
        'legacy_description_breaks_normalized_at' => null,
    ]);
    legacyBreakDescription($legacy, "Legacy\n\nvalue");
    DB::connection('metaworks')->table('resource')->insert([
        'identifier' => '10.5880/nonblank.match',
    ]);

    $result = app(LegacyDescriptionBreakCleanupService::class)->run(limit: 1);

    expect($result)->toMatchArray([
        'resources_scanned' => 1,
        'legacy_resources' => 1,
        'not_legacy' => 0,
        'last_scanned_resource_id' => $legacy->id,
    ])->and($result['records'][0]['resource_id'])->toBe($legacy->id);
});

it('still repairs source-linked legacy resources without a DOI', function (): void {
    $legacy = Resource::factory()->create([
        'doi' => '',
        'legacy_source' => 'sumario-pmd',
        'legacy_source_id' => 124,
        'legacy_source_status' => 'released',
        'legacy_description_breaks_normalized_at' => null,
    ]);
    $description = legacyBreakDescription($legacy, "Legacy\n\nvalue");

    $result = app(LegacyDescriptionBreakCleanupService::class)->run(apply: true, limit: 1);

    expect($result)->toMatchArray([
        'resources_scanned' => 1,
        'legacy_resources' => 1,
        'changed' => 1,
        'sync_resource_ids' => [],
    ])->and($description->fresh()->value)->toBe("Legacy\nvalue")
        ->and($legacy->fresh()->legacy_description_breaks_normalized_at)->not->toBeNull();
});

it('loads cleanup relations only for resources matched to SUMARIO', function (): void {
    $legacy = legacyBreakResource('10.5880/relations.legacy', 125);
    legacyBreakDescription($legacy, "Legacy\n\nvalue");
    $legacyLandingPage = LandingPage::factory()->published()->create(['resource_id' => $legacy->id]);
    $regular = Resource::factory()->create([
        'doi' => '10.5880/relations.regular',
        'legacy_description_breaks_normalized_at' => null,
    ]);
    legacyBreakDescription($regular, "Regular\n\nvalue");
    LandingPage::factory()->published()->create(['resource_id' => $regular->id]);

    $cache = Mockery::mock(LandingPageRenderDataCacheService::class);
    $cache->shouldReceive('forgetById')->once()->with($legacyLandingPage->id)->andReturnTrue();
    $service = new LegacyDescriptionBreakCleanupService(
        new LegacyDescriptionBreakNormalizer,
        app(DoiSuggestionService::class),
        $cache,
    );

    DB::flushQueryLog();
    DB::enableQueryLog();

    $result = $service->run(apply: true);
    $relationQueries = collect(DB::getQueryLog())->filter(
        static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
            && (str_contains(strtolower($query['query']), 'from "descriptions"')
                || str_contains(strtolower($query['query']), 'from "landing_pages"')),
    );
    $legacyIdOnlyPattern = '/\bin\s*\(\s*'.preg_quote((string) $legacy->id, '/').'\s*\)/i';

    expect($result)->toMatchArray([
        'resources_scanned' => 2,
        'legacy_resources' => 1,
        'not_legacy' => 1,
    ])->and($relationQueries)->toHaveCount(2)
        ->and($relationQueries->every(
            static fn (array $query): bool => preg_match($legacyIdOnlyPattern, $query['query']) === 1,
        ))->toBeTrue();
});

it('leaves ambiguous DOI matches for manual review', function (): void {
    $resource = Resource::factory()->create([
        'doi' => '10.5880/ambiguous',
        'legacy_description_breaks_normalized_at' => null,
    ]);
    legacyBreakDescription($resource, "Ambiguous\n\ntext");
    DB::connection('metaworks')->table('resource')->insert([
        ['identifier' => '10.5880/ambiguous'],
        ['identifier' => '10.5880/AMBIGUOUS'],
    ]);

    $result = app(LegacyDescriptionBreakCleanupService::class)->run(apply: true);

    expect($result)->toMatchArray([
        'legacy_resources' => 0,
        'changed' => 0,
        'manual_review' => 1,
    ])->and($result['records'][0]['status'])->toBe('manual_review')
        ->and($resource->fresh()->legacy_description_breaks_normalized_at)->toBeNull();
});

it('preserves a committed repair and sync candidate when cache invalidation fails', function (): void {
    Config::set('datacite.test_mode', false);
    $resource = legacyBreakResource('10.5880/cache.failure', 175);
    $description = legacyBreakDescription($resource, "Cache\n\nvalue");
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

    $cache = Mockery::mock(LandingPageRenderDataCacheService::class);
    $cache->shouldReceive('forgetById')
        ->once()
        ->with($landingPage->id)
        ->andThrow(new RuntimeException('Redis unavailable'));
    $service = new LegacyDescriptionBreakCleanupService(
        new LegacyDescriptionBreakNormalizer,
        app(DoiSuggestionService::class),
        $cache,
    );

    $result = $service->run(apply: true, dois: ['10.5880/cache.failure']);

    expect($result)->toMatchArray([
        'changed' => 1,
        'cache_invalidation_failures' => 1,
        'errors' => 0,
        'sync_resource_ids' => [$resource->id],
    ])->and($result['records'][0])->toMatchArray([
        'status' => 'updated',
        'datacite_sync_status' => 'queued',
    ])->and($result['records'][0]['message'])->toContain('cache invalidation failed')
        ->and($description->fresh()->value)->toBe("Cache\nvalue")
        ->and($resource->fresh()->legacy_description_breaks_normalized_at)->not->toBeNull();
});

it('rolls back a resource when a description changes concurrently', function (): void {
    $resource = legacyBreakResource('10.5880/concurrent', 150);
    $first = legacyBreakDescription($resource, "First\n\nvalue");
    $second = legacyBreakDescription($resource, "Second\n\nvalue");
    $armed = true;
    $originalDispatcher = Description::getEventDispatcher();

    if ($originalDispatcher === null) {
        throw new RuntimeException('Eloquent event dispatcher is not configured.');
    }

    Description::setEventDispatcher(clone $originalDispatcher);

    try {
        Description::retrieved(function (Description $loaded) use (&$armed, $second): void {
            if (! $armed || ! $loaded->is($second)) {
                return;
            }

            $armed = false;
            DB::table('descriptions')
                ->where('id', $loaded->id)
                ->update(['value' => "Curated\nvalue"]);
        });

        $result = app(LegacyDescriptionBreakCleanupService::class)->run(
            apply: true,
            dois: ['10.5880/concurrent'],
        );
    } finally {
        Description::setEventDispatcher($originalDispatcher);
    }

    expect($result)->toMatchArray([
        'changed' => 0,
        'concurrent_changes' => 1,
        'sync_resource_ids' => [],
    ])->and($result['records'][0]['status'])->toBe('concurrent_change')
        ->and($first->fresh()->value)->toBe("First\n\nvalue")
        ->and($second->fresh()->value)->toBe("Curated\nvalue")
        ->and($resource->fresh()->legacy_description_breaks_normalized_at)->toBeNull()
        ->and(Description::getEventDispatcher())->toBe($originalDispatcher);
});

it('honors DOI legacy ID resume and limit filters', function (): void {
    $first = legacyBreakResource('10.5880/filter.first', 201);
    legacyBreakDescription($first, "First\n\nvalue");
    $second = legacyBreakResource('10.5880/filter.second', 202);
    legacyBreakDescription($second, "Second\n\nvalue");
    $third = legacyBreakResource('10.5880/filter.third', 203);
    legacyBreakDescription($third, "Third\n\nvalue");

    $result = app(LegacyDescriptionBreakCleanupService::class)->run(
        afterId: $first->id,
        limit: 1,
        chunk: 1,
        dois: ['https://doi.org/10.5880/FILTER.SECOND'],
        legacyIds: [202],
    );

    expect($result)->toMatchArray([
        'resources_scanned' => 1,
        'legacy_resources' => 1,
        'changed' => 1,
    ])->and($result['records'][0]['resource_id'])->toBe($second->id)
        ->and($third->fresh()->legacy_description_breaks_normalized_at)->toBeNull();
});

it('writes a report and queues full metadata synchronization after apply', function (): void {
    Config::set('datacite.test_mode', false);
    Bus::fake();
    $resource = legacyBreakResource('10.5880/command.apply', 301);
    legacyBreakDescription($resource, "Command\n\nvalue");
    $reportPath = storage_path('framework/testing/legacy-breaks-'.Str::uuid().'.csv');

    try {
        $this->artisan('resources:repair-legacy-description-breaks', [
            '--apply' => true,
            '--doi' => ['10.5880/command.apply'],
            '--report' => $reportPath,
        ])->expectsOutputToContain('Legacy description break repair applied.')
            ->expectsOutputToContain('DataCite full-metadata sync run:')
            ->assertExitCode(Command::SUCCESS);

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof SyncImportedResourcesWithDataCiteJob);

        expect(File::get($reportPath))
            ->toContain('resource_id,doi,legacy_resource_id,match_method,status')
            ->toContain('10.5880/command.apply')
            ->toContain('updated')
            ->toContain('queued');
    } finally {
        File::delete($reportPath);
    }
});

it('prints the last scanned ID when a bounded batch contains only non-legacy resources', function (): void {
    $resource = Resource::factory()->create([
        'doi' => '10.5880/not-legacy-only',
        'legacy_description_breaks_normalized_at' => null,
    ]);
    legacyBreakDescription($resource, "Regular\n\nvalue");
    $reportPath = storage_path('framework/testing/legacy-breaks-empty-'.Str::uuid().'.csv');

    try {
        $this->artisan('resources:repair-legacy-description-breaks', [
            '--limit' => 1,
            '--report' => $reportPath,
        ])->expectsOutputToContain("Last scanned resource ID: {$resource->id}")
            ->assertExitCode(Command::SUCCESS);

        expect(File::get($reportPath))->toBe(
            "resource_id,doi,legacy_resource_id,match_method,status,descriptions_scanned,descriptions_changed,breaks_removed,datacite_sync_status,message\n",
        );
    } finally {
        File::delete($reportPath);
    }
});

it('dispatches durable synchronization before reporting a CSV failure', function (): void {
    Config::set('datacite.test_mode', false);
    Bus::fake();
    $resource = legacyBreakResource('10.5880/report.failure', 303);
    legacyBreakDescription($resource, "Report\n\nvalue");
    $blockingPath = storage_path('framework/testing/legacy-breaks-blocker-'.Str::uuid());
    File::put($blockingPath, 'not a directory');

    try {
        $this->artisan('resources:repair-legacy-description-breaks', [
            '--apply' => true,
            '--doi' => ['10.5880/report.failure'],
            '--report' => $blockingPath.'/report.csv',
        ])->expectsOutputToContain('DataCite full-metadata sync run:')
            ->expectsOutputToContain('Unable to write repair report:')
            ->assertExitCode(Command::FAILURE);

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof SyncImportedResourcesWithDataCiteJob);
        expect($resource->fresh()->legacy_description_breaks_normalized_at)->not->toBeNull();
    } finally {
        File::delete($blockingPath);
    }
});

it('keeps the command dry run free of database queue and report side effects beyond the requested CSV', function (): void {
    Bus::fake();
    $resource = legacyBreakResource('10.5880/command.dry', 302);
    $description = legacyBreakDescription($resource, "Dry\n\nvalue");

    $this->artisan('resources:repair-legacy-description-breaks', [
        '--doi' => ['10.5880/command.dry'],
    ])->expectsOutputToContain('Dry run only; no data was changed.')
        ->assertExitCode(Command::SUCCESS);

    Bus::assertNothingBatched();
    expect($description->fresh()->value)->toBe("Dry\n\nvalue")
        ->and($resource->fresh()->legacy_description_breaks_normalized_at)->toBeNull();
});
