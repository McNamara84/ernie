<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Services\Usage\CounterAggregationService;
use App\Services\Usage\CounterEventRecorderService;
use App\Services\Usage\CounterTrackingStateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

it('upgrades existing restrictive usage foreign keys without losing observations or indexes', function (): void {
    config(['usage.session_secret' => str_repeat('migration-test-secret', 3)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
    app(CounterTrackingStateService::class)->activate();
    $page = LandingPage::factory()->published()->withDoi('10.5880/deletion-migration')->create();
    $recorder = app(CounterEventRecorderService::class);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '192.0.2.21', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/130']);
    $recorder->landingPage($request, $page, 'investigation', 'landing-page');
    $this->travel(1)->days();
    expect(app(CounterAggregationService::class)->aggregate())->toBe(1);
    $recorder->landingPage($request, $page, 'investigation', 'landing-page');
    $tables = ['usage_events', 'usage_daily_metrics', 'usage_monthly_metrics'];
    $rows = [];
    $indexes = [];
    foreach ($tables as $table) {
        expect(DB::table($table)->count())->toBe(1);
        $rows[$table] = DB::table($table)->get()->toArray();
        $indexes[$table] = Schema::getIndexes($table);
    }
    $migration = require database_path('migrations/2026_10_20_000003_cascade_usage_on_landing_page_deletion.php');
    $migration->down();
    try {
        foreach ($tables as $table) {
            $foreignKey = collect(Schema::getForeignKeys($table))->firstWhere('columns', ['landing_page_id']);
            expect($foreignKey['on_delete'])->toBe('restrict');
        }
        expect(fn () => DB::table('resources')->where('id', $page->resource_id)->delete())->toThrow(QueryException::class);
    } finally {
        $migration->up();
    }
    // Fresh installs already have cascades; repeating the repair must also preserve data.
    $migration->up();
    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toArray())->toEqual($rows[$table])
            ->and(Schema::getIndexes($table))->toEqual($indexes[$table]);
        $foreignKey = collect(Schema::getForeignKeys($table))->firstWhere('columns', ['landing_page_id']);
        expect($foreignKey['on_delete'])->toBe('cascade');
    }
    expect(DB::table('resources')->where('id', $page->resource_id)->delete())->toBe(1);
    $this->assertDatabaseMissing('landing_pages', ['id' => $page->id]);
    foreach ($tables as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    expect(DB::table('usage_tracking_state')->find(1)->started_at)->not->toBeNull()
        ->and(DB::table('usage_list_versions')->count())->toBe(1);
});
