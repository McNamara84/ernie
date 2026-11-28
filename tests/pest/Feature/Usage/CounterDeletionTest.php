<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use App\Services\Usage\CounterAggregationService;
use App\Services\Usage\CounterEventRecorderService;
use App\Services\Usage\CounterTrackingStateService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

it('cascades usage through the existing resource deletion endpoints', function (string $operation, bool $aggregated): void {
    config(['usage.session_secret' => str_repeat('deletion-test-secret', 3), 'datacite.test_mode' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
    app(CounterTrackingStateService::class)->activate();
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $igsn = str_starts_with($operation, 'igsn');
    $type = ResourceType::firstOrCreate(
        ['slug' => $igsn ? 'physical-object' : 'dataset'],
        ['name' => $igsn ? 'Physical Object' : 'Dataset'],
    );
    $pages = collect(range(1, 3))->map(function () use ($igsn, $type): LandingPage {
        $resource = Resource::factory()->create(['resource_type_id' => $type->id]);
        if ($igsn) {
            IgsnMetadata::create(['resource_id' => $resource->id]);
        }

        return LandingPage::factory()->published()->withDoi('10.5880/deletion.'.fake()->uuid())->for($resource)->create();
    });
    $request = Request::create('/doi-search?q=deletion', 'GET', server: [
        'REMOTE_ADDR' => '192.0.2.12', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/130',
    ]);
    $recorder = app(CounterEventRecorderService::class);
    foreach ($pages as $page) {
        $recorder->landingPage($request, $page, 'investigation', 'landing-page');
    }
    $recorder->search($request, 'doi');
    expect(DB::table('usage_events')->count())->toBe(3);
    if ($aggregated) {
        $this->travel(1)->days();
        expect(app(CounterAggregationService::class)->aggregate())->toBe(1);
        expect(DB::table('usage_daily_metrics')->count())->toBe(3)
            ->and(DB::table('usage_monthly_metrics')->count())->toBe(3);
        // A page may have durable aggregates and new, unprocessed events at once.
        foreach ($pages as $page) {
            $recorder->landingPage($request, $page, 'investigation', 'landing-page');
        }
        $recorder->search($request, 'doi');
    }
    $tracking = (array) DB::table('usage_tracking_state')->find(1);
    $versions = DB::table('usage_list_versions')->count();
    $deleted = $pages->take($operation === 'all' ? 3 : (str_ends_with($operation, 'batch') ? 2 : 1));
    $ids = $deleted->pluck('resource_id')->all();
    $this->actingAs($admin);
    $response = match ($operation) {
        'single' => $this->delete(route('resources.destroy', $ids[0])),
        'batch' => $this->delete(route('resources.batch-destroy'), ['ids' => $ids]),
        'igsn-single' => $this->delete(route('igsns.destroy', $ids[0])),
        'igsn-batch' => $this->delete(route('igsns.batch.destroy'), ['ids' => $ids]),
        'all' => $this->delete(route('resources.destroy-all'), ['confirmation' => 'delete']),
    };
    $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
    foreach ($deleted as $page) {
        $this->assertDatabaseMissing('resources', ['id' => $page->resource_id]);
        $this->assertDatabaseMissing('landing_pages', ['id' => $page->id]);
        foreach (['usage_events', 'usage_daily_metrics', 'usage_monthly_metrics'] as $table) {
            $this->assertDatabaseMissing($table, ['landing_page_id' => $page->id]);
        }
    }
    $remaining = 3 - $deleted->count();
    expect(DB::table('usage_events')->count())->toBe($remaining)
        ->and(DB::table('usage_daily_metrics')->count())->toBe($aggregated ? $remaining : 0)
        ->and(DB::table('usage_monthly_metrics')->count())->toBe($aggregated ? $remaining : 0)
        ->and(DB::table('usage_search_events')->count())->toBe(1)
        ->and(DB::table('usage_search_daily_metrics')->count())->toBe($aggregated ? 1 : 0)
        ->and(DB::table('usage_list_versions')->count())->toBe($versions)
        ->and((array) DB::table('usage_tracking_state')->find(1))->toEqual($tracking);
})->with(['single', 'batch', 'igsn-single', 'igsn-batch', 'all'])->with([
    'before aggregation' => false,
    'after aggregation' => true,
]);
