<?php

declare(strict_types=1);

use App\Console\Commands\BackfillLegacyTemporalCoverages;
use App\Enums\CacheKey;
use App\Models\GeoLocation;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\Legacy\LegacyTemporalCoverageBackfillService;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

covers(BackfillLegacyTemporalCoverages::class, LegacyTemporalCoverageBackfillService::class);

beforeEach(function (): void {
    Cache::flush();
    Config::set('database.connections.metaworks', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge('metaworks');

    Schema::connection('metaworks')->create('resource', function (Blueprint $table): void {
        $table->id();
        $table->string('identifier')->nullable();
    });

    Schema::connection('metaworks')->create('coverage', function (Blueprint $table): void {
        $table->id();
        $table->float('minlat')->nullable();
        $table->float('maxlat')->nullable();
        $table->float('minlon')->nullable();
        $table->float('maxlon')->nullable();
        $table->text('wkt')->nullable();
        $table->string('start')->nullable();
        $table->string('end')->nullable();
        $table->string('dateformat')->nullable();
        $table->text('description')->nullable();
        $table->unsignedBigInteger('resource_id');
    });
});

afterEach(function (): void {
    Schema::connection('metaworks')->dropIfExists('coverage');
    Schema::connection('metaworks')->dropIfExists('resource');
    DB::disconnect('metaworks');
});

function issue1141LegacyResource(int $legacyId, string $doi): void
{
    DB::connection('metaworks')->table('resource')->insert([
        'id' => $legacyId,
        'identifier' => $doi,
    ]);
}

/** @param array<string, mixed> $attributes */
function issue1141LegacyCoverage(int $legacyId, array $attributes = []): void
{
    DB::connection('metaworks')->table('coverage')->insert([
        'resource_id' => $legacyId,
        'minlat' => null,
        'maxlat' => null,
        'minlon' => null,
        'maxlon' => null,
        'wkt' => null,
        'start' => null,
        'end' => null,
        'dateformat' => null,
        'description' => null,
        ...$attributes,
    ]);
}

function issue1141ErnieResource(int $legacyId, string $doi): Resource
{
    return Resource::factory()->create([
        'doi' => $doi,
        'legacy_source' => 'sumario-pmd',
        'legacy_source_id' => $legacyId,
    ]);
}

it('dry-runs, applies, and repeats idempotently for matched point box and line coverages', function (): void {
    $doi = '10.5880/legacy.temporal.complete';
    issue1141LegacyResource(101, $doi);
    issue1141LegacyCoverage(101, [
        'id' => 30,
        'minlat' => 50.1,
        'maxlat' => 50.1,
        'minlon' => 8.6,
        'maxlon' => 8.6,
        'start' => '2026-08-25 14:37:00',
        'description' => 'Point station',
    ]);
    issue1141LegacyCoverage(101, [
        'id' => 10,
        'minlat' => 48.0,
        'maxlat' => 49.0,
        'minlon' => 7.0,
        'maxlon' => 8.0,
        'end' => '2026-09-30',
        'description' => 'Survey box',
    ]);
    issue1141LegacyCoverage(101, [
        'id' => 20,
        'minlat' => 49.2,
        'maxlat' => 49.4,
        'minlon' => 8.1,
        'maxlon' => 8.3,
        'wkt' => '8.1 49.2 8.3 49.4',
        'start' => '2026-08-25T14:37:00+09:00',
        'end' => '2026-08-27T17:37:42+09:00',
        'description' => 'Profile line',
    ]);

    $resource = issue1141ErnieResource(101, $doi);
    $box = GeoLocation::factory()->withBox(7.0, 8.0, 48.0, 49.0)->create([
        'resource_id' => $resource->id,
        'place' => 'Survey box',
        'position' => 0,
    ]);
    $line = GeoLocation::factory()->withLine([
        ['longitude' => 8.1, 'latitude' => 49.2],
        ['longitude' => 8.3, 'latitude' => 49.4],
    ])->create([
        'resource_id' => $resource->id,
        'place' => 'Profile line',
        'position' => 1,
    ]);
    $point = GeoLocation::factory()->withPoint(8.6, 50.1)->create([
        'resource_id' => $resource->id,
        'place' => 'Point station',
        'position' => 2,
    ]);
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $cache = Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags());
    $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);
    $cache->put($cacheKey, ['cached' => true], 600);

    $dryRun = app(LegacyTemporalCoverageBackfillService::class)->run();

    expect($dryRun)->toMatchArray([
        'scanned' => 1,
        'changed' => 1,
        'unchanged' => 0,
        'manual_review' => 0,
        'errors' => 0,
        'coverages_updated' => 3,
        'coverages_created' => 0,
    ])->and($dryRun['records'][0]['status'])->toBe('would_update')
        ->and($point->fresh()->start_date)->toBeNull()
        ->and($cache->has($cacheKey))->toBeTrue();

    $applied = app(LegacyTemporalCoverageBackfillService::class)->run(apply: true);

    expect($applied)->toMatchArray([
        'scanned' => 1,
        'changed' => 1,
        'coverages_updated' => 3,
        'coverages_created' => 0,
    ])->and($box->fresh()->end_date?->format('Y-m-d'))->toBe('2026-09-30')
        ->and($line->fresh()->start_date?->format('Y-m-d'))->toBe('2026-08-25')
        ->and($line->fresh()->start_time)->toBe('14:37')
        ->and($line->fresh()->end_time)->toBe('17:37:42')
        ->and($line->fresh()->timezone)->toBe('+09:00')
        ->and($point->fresh()->start_time)->toBe('14:37')
        ->and($point->fresh()->timezone)->toBeNull()
        ->and($cache->has($cacheKey))->toBeFalse();

    $cache->put($cacheKey, ['cached' => true], 600);
    $secondApply = app(LegacyTemporalCoverageBackfillService::class)->run(apply: true);

    expect($secondApply)->toMatchArray([
        'scanned' => 1,
        'changed' => 0,
        'unchanged' => 1,
        'coverages_updated' => 0,
        'coverages_created' => 0,
    ])->and($cache->has($cacheKey))->toBeTrue();
});

it('creates a temporal-only coverage that an older import could not persist', function (): void {
    $doi = '10.5880/legacy.temporal.only';
    issue1141LegacyResource(102, $doi);
    issue1141LegacyCoverage(102, [
        'start' => '2020-01-01',
        'end' => '2020-12-31',
        'description' => 'Measurement period',
    ]);
    $resource = issue1141ErnieResource(102, $doi);

    $result = app(LegacyTemporalCoverageBackfillService::class)->run(apply: true);

    $location = $resource->geoLocations()->sole();
    expect($result)->toMatchArray([
        'changed' => 1,
        'coverages_updated' => 0,
        'coverages_created' => 1,
    ])->and($location->place)->toBe('Measurement period')
        ->and($location->start_date?->format('Y-m-d'))->toBe('2020-01-01')
        ->and($location->end_date?->format('Y-m-d'))->toBe('2020-12-31')
        ->and($location->hasSpatialCoverage())->toBeTrue()
        ->and($location->point_latitude)->toBeNull();
});

it('fills missing fields but preserves a conflicting existing temporal coverage for review', function (): void {
    issue1141LegacyResource(103, '10.5880/legacy.temporal.merge');
    issue1141LegacyCoverage(103, [
        'minlat' => 50.0,
        'maxlat' => 50.0,
        'minlon' => 10.0,
        'maxlon' => 10.0,
        'start' => '2024-01-01T08:30:00Z',
        'end' => '2024-12-31T18:45:00Z',
    ]);
    $resource = issue1141ErnieResource(103, '10.5880/legacy.temporal.merge');
    $location = GeoLocation::factory()->withPoint(10.0, 50.0)->create([
        'resource_id' => $resource->id,
        'start_date' => '2024-01-01',
        'start_time' => '08:30:00',
        'end_date' => '2025-01-01',
    ]);

    $result = app(LegacyTemporalCoverageBackfillService::class)->run(apply: true);

    expect($result)->toMatchArray([
        'changed' => 0,
        'unchanged' => 1,
        'manual_review' => 1,
        'coverages_updated' => 0,
    ])->and($result['records'][0]['status'])->toBe('manual_review')
        ->and($result['records'][0]['message'])->toContain('end_date')
        ->and($location->fresh()->end_date?->format('Y-m-d'))->toBe('2025-01-01')
        ->and($location->fresh()->end_time)->toBeNull()
        ->and($location->fresh()->timezone)->toBeNull();
});

it('adds missing interval fields while treating equivalent time and UTC spellings as equal', function (): void {
    issue1141LegacyResource(109, '10.5880/legacy.temporal.partial');
    issue1141LegacyCoverage(109, [
        'minlat' => 50.0,
        'maxlat' => 50.0,
        'minlon' => 10.0,
        'maxlon' => 10.0,
        'start' => '2024-01-01T08:30:00Z',
        'end' => '2024-12-31T18:45:00Z',
    ]);
    $resource = issue1141ErnieResource(109, '10.5880/legacy.temporal.partial');
    $location = GeoLocation::factory()->withPoint(10.0, 50.0)->create([
        'resource_id' => $resource->id,
        'start_date' => '2024-01-01',
        'start_time' => '08:30:00',
        'timezone' => 'GMT',
    ]);

    $result = app(LegacyTemporalCoverageBackfillService::class)->run(apply: true);

    expect($result)->toMatchArray([
        'changed' => 1,
        'manual_review' => 0,
        'coverages_updated' => 1,
    ])->and($location->fresh()->start_time)->toBe('08:30:00')
        ->and($location->fresh()->timezone)->toBe('GMT')
        ->and($location->fresh()->end_date?->format('Y-m-d'))->toBe('2024-12-31')
        ->and($location->fresh()->end_time)->toBe('18:45');
});

it('disambiguates equal spatial coverages by their descriptions', function (): void {
    issue1141LegacyResource(110, '10.5880/legacy.temporal.duplicates');
    issue1141LegacyCoverage(110, [
        'id' => 2,
        'minlat' => 50.0,
        'maxlat' => 50.0,
        'minlon' => 10.0,
        'maxlon' => 10.0,
        'start' => '2022-01-01',
        'description' => 'Station Alpha',
    ]);
    issue1141LegacyCoverage(110, [
        'id' => 1,
        'minlat' => 50.0,
        'maxlat' => 50.0,
        'minlon' => 10.0,
        'maxlon' => 10.0,
        'start' => '2021-01-01',
        'description' => 'Station Beta',
    ]);
    $resource = issue1141ErnieResource(110, '10.5880/legacy.temporal.duplicates');
    $alpha = GeoLocation::factory()->withPoint(10.0, 50.0)->create([
        'resource_id' => $resource->id,
        'place' => ' Station   Alpha ',
        'position' => 0,
    ]);
    $beta = GeoLocation::factory()->withPoint(10.0, 50.0)->create([
        'resource_id' => $resource->id,
        'place' => 'STATION BETA',
        'position' => 1,
    ]);

    $result = app(LegacyTemporalCoverageBackfillService::class)->run(apply: true);

    expect($result)->toMatchArray([
        'changed' => 1,
        'manual_review' => 0,
        'coverages_updated' => 2,
    ])->and($alpha->fresh()->start_date?->format('Y-m-d'))->toBe('2022-01-01')
        ->and($beta->fresh()->start_date?->format('Y-m-d'))->toBe('2021-01-01');
});

it('matches a legacy line to an older bounding-box import only by one-to-one description and position', function (): void {
    issue1141LegacyResource(111, '10.5880/legacy.temporal.old-line');
    issue1141LegacyCoverage(111, [
        'minlat' => 49.2,
        'maxlat' => 49.4,
        'minlon' => 8.1,
        'maxlon' => 8.3,
        'wkt' => '8.1 49.2 8.3 49.4',
        'start' => '2017-04-05',
        'description' => 'Historic profile',
    ]);
    $resource = issue1141ErnieResource(111, '10.5880/legacy.temporal.old-line');
    $box = GeoLocation::factory()->withBox(8.1, 8.3, 49.2, 49.4)->create([
        'resource_id' => $resource->id,
        'place' => 'Historic profile',
        'position' => 0,
    ]);

    $result = app(LegacyTemporalCoverageBackfillService::class)->run(apply: true);

    expect($result)->toMatchArray([
        'changed' => 1,
        'manual_review' => 0,
        'coverages_updated' => 1,
    ])->and($box->fresh()->start_date?->format('Y-m-d'))->toBe('2017-04-05')
        ->and($resource->geoLocations()->count())->toBe(1);
});

it('does not attach temporal values when spatial matching is unsafe', function (): void {
    issue1141LegacyResource(104, '10.5880/legacy.temporal.unsafe');
    issue1141LegacyCoverage(104, [
        'minlat' => 50.0,
        'maxlat' => 50.0,
        'minlon' => 10.0,
        'maxlon' => 10.0,
        'start' => '2024-01-01',
        'description' => 'Legacy point',
    ]);
    $resource = issue1141ErnieResource(104, '10.5880/legacy.temporal.unsafe');
    $location = GeoLocation::factory()->withPoint(11.0, 51.0)->create([
        'resource_id' => $resource->id,
        'place' => 'Different point',
    ]);

    $result = app(LegacyTemporalCoverageBackfillService::class)->run(apply: true);

    expect($result)->toMatchArray([
        'changed' => 0,
        'manual_review' => 1,
        'coverages_updated' => 0,
        'coverages_created' => 0,
    ])->and($result['records'][0]['message'])->toContain('no existing GeoLocation matched')
        ->and($location->fresh()->hasTemporalCoverage())->toBeFalse();
});

it('supports explicit DOI matching for pre-link imports and command filters and reports', function (): void {
    issue1141LegacyResource(105, '10.5880/legacy.temporal.first');
    issue1141LegacyCoverage(105, ['start' => '2018-01-01']);
    $first = Resource::factory()->create(['doi' => '10.5880/legacy.temporal.first']);

    issue1141LegacyResource(106, '10.5880/legacy.temporal.selected');
    issue1141LegacyCoverage(106, ['start' => '2019-01-01']);
    $selected = Resource::factory()->create(['doi' => '10.5880/legacy.temporal.selected']);

    issue1141LegacyResource(107, '10.5880/legacy.temporal.third');
    issue1141LegacyCoverage(107, ['start' => '2020-01-01']);
    Resource::factory()->create(['doi' => '10.5880/legacy.temporal.third']);

    $reportPath = storage_path('framework/testing/legacy-temporal-'.Str::uuid().'.csv');

    try {
        $this->artisan('resources:backfill-legacy-temporal-coverages', [
            '--match-by-doi' => true,
            '--doi' => ['HTTPS://DOI.ORG/10.5880/LEGACY.TEMPORAL.SELECTED'],
            '--after-id' => $first->id,
            '--limit' => 1,
            '--chunk' => 5000,
            '--report' => $reportPath,
        ])->expectsOutputToContain('Dry run only; no data was changed.')
            ->expectsOutputToContain('Backfill report written')
            ->assertExitCode(Command::SUCCESS);

        expect($selected->geoLocations()->count())->toBe(0)
            ->and(File::get($reportPath))
            ->toContain('resource_id,doi,legacy_resource_id,match_method,status,temporal_coverages,coverages_updated,coverages_created,warnings,message')
            ->toContain('10.5880/legacy.temporal.selected,106,doi,would_update');

        $this->artisan('resources:backfill-legacy-temporal-coverages', [
            '--apply' => true,
            '--match-by-doi' => true,
            '--doi' => ['10.5880/legacy.temporal.selected'],
        ])->assertSuccessful();

        expect($selected->geoLocations()->sole()->start_date?->format('Y-m-d'))->toBe('2019-01-01');
    } finally {
        File::delete($reportPath);
    }
});

it('reports linked resources with missing legacy rows and legacy records without temporal values', function (): void {
    issue1141LegacyResource(108, '10.5880/legacy.temporal.none');
    issue1141LegacyCoverage(108, [
        'minlat' => 50.0,
        'maxlat' => 50.0,
        'minlon' => 10.0,
        'maxlon' => 10.0,
    ]);
    issue1141ErnieResource(108, '10.5880/legacy.temporal.none');
    issue1141ErnieResource(999, '10.5880/legacy.temporal.missing');

    $result = app(LegacyTemporalCoverageBackfillService::class)->run();

    expect($result)->toMatchArray([
        'scanned' => 2,
        'changed' => 0,
        'no_temporal' => 1,
        'missing_legacy' => 1,
        'errors' => 0,
    ])->and(collect($result['records'])->pluck('status')->sort()->values()->all())
        ->toBe(['missing_legacy', 'no_temporal']);
});

it('isolates duplicate DOI matches as errors without changing the resource', function (): void {
    issue1141LegacyResource(112, '10.5880/legacy.temporal.duplicate-doi');
    issue1141LegacyResource(113, '10.5880/legacy.temporal.duplicate-doi');
    issue1141LegacyCoverage(112, ['start' => '2024-01-01']);
    issue1141LegacyCoverage(113, ['start' => '2025-01-01']);
    $resource = Resource::factory()->create(['doi' => '10.5880/legacy.temporal.duplicate-doi']);

    $result = app(LegacyTemporalCoverageBackfillService::class)->run(apply: true, matchByDoi: true);

    expect($result)->toMatchArray([
        'scanned' => 1,
        'changed' => 0,
        'errors' => 1,
    ])->and($result['records'][0]['status'])->toBe('error')
        ->and($result['records'][0]['message'])->toContain('Multiple SUMARIO resources')
        ->and($resource->geoLocations()->count())->toBe(0);
});
