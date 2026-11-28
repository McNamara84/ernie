<?php

use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use App\Services\Usage\CounterAggregationService;
use App\Services\Usage\CounterReadService;
use App\Services\Usage\CounterTrackingStateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['usage.session_secret' => str_repeat('test-read-secret', 3)]);
    $this->travelTo(CarbonImmutable::parse('2025-01-15 12:00:00', 'UTC'));
});

it('uses lifetime totals but limits the public series to twelve months and distinguishes pending from measured zero', function () {
    $page = LandingPage::factory()->published()->create();
    app(CounterTrackingStateService::class)->activate();
    DB::table('usage_daily_metrics')->insert([
        'landing_page_id' => $page->id, 'day' => '2025-01-15', 'access_method' => 'Regular',
        'total_investigations' => 100, 'unique_investigations' => 70, 'total_requests' => 30, 'unique_requests' => 20,
    ]);
    app(CounterAggregationService::class)->rebuildMonth(CarbonImmutable::parse('2025-01-01'));
    $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'));
    DB::table('usage_tracking_state')->where('id', 1)->update(['data_through' => '2026-02-28', 'last_aggregated_at' => now()]);
    $result = app(CounterReadService::class)->landingPage($page);
    expect($result['views'])->toBe(70)->and($result['downloads'])->toBe(20)
        ->and($result['months'])->toHaveCount(12)
        ->and($result['months'][0]['month'])->toBe('2025-04')
        ->and($result['months'][10]['views'])->toBe(0)
        ->and($result['months'][10]['partial'])->toBeFalse()
        ->and($result['months'][11]['views'])->toBeNull()
        ->and($result['months'][11]['partial'])->toBeTrue();
});

it('exposes partial activation months and gaps without inventing pre-activation data', function () {
    $page = LandingPage::factory()->published()->create();
    app(CounterTrackingStateService::class)->activate();
    $this->travel(1)->days();
    app(CounterAggregationService::class)->aggregate();
    app(CounterTrackingStateService::class)->pause(true);
    $result = app(CounterReadService::class)->landingPage($page);
    expect($result['months'])->toHaveCount(1)
        ->and($result['months'][0]['month'])->toBe('2025-01')
        ->and($result['months'][0]['partial'])->toBeTrue()
        ->and($result['months'][0]['incomplete'])->toBeTrue()
        ->and($result['state'])->toBe('incomplete');
});

it('exports empty IGSN request fields and protects arbitrary text against spreadsheet formulas', function () {
    $type = ResourceType::firstOrCreate(['slug' => 'physical-object'], ['name' => 'Physical Object']);
    $page = LandingPage::factory()->published()->for(Resource::factory()->create(['resource_type_id' => $type->id]))->create();
    app(CounterTrackingStateService::class)->activate();
    DB::table('landing_pages')->where('id', $page->id)->update(['doi_prefix' => '=SUM(1,2)']);
    DB::table('usage_monthly_metrics')->insert([
        'landing_page_id' => $page->id, 'month' => '2025-01-01', 'access_method' => 'Machine',
        'total_investigations' => 10, 'unique_investigations' => 5, 'total_requests' => null, 'unique_requests' => null,
    ]);
    $this->actingAs(User::factory()->admin()->create());
    $response = $this->get('/statistics/usage.csv?access_method=machine')->assertOk();
    $lines = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));
    expect($lines[1][1])->toBe("'=SUM(1,2)")->and($lines[1][7])->toBe('')->and($lines[1][8])->toBe('');
    $empty = $this->get('/statistics/usage.csv?access_method=regular')->assertOk()->streamedContent();
    expect(substr_count(trim($empty), "\n"))->toBe(0);
});

it('migrates usage exactly once without reordering or unhiding existing modules', function () {
    $template = LandingPageTemplate::factory()->create([
        'left_column_order' => ['files', 'citation'], 'right_column_order' => ['location', 'creators'],
        'hidden_sections' => ['licenses'], 'logo_path' => 'templates/existing.png',
    ]);
    $migration = require database_path('migrations/2026_10_20_000002_add_usage_to_landing_page_templates.php');
    $migration->up();
    $migration->up();
    $template->refresh();
    expect($template->right_column_order)->toBe(['location', 'creators', 'usage'])
        ->and($template->left_column_order)->toBe(['files', 'citation'])
        ->and($template->hidden_sections)->toBe(['licenses'])
        ->and($template->logo_path)->toBe('templates/existing.png');
});

it('validates all three DOI zones while retaining immutable defaults and the IGSN version notice', function () {
    $this->actingAs(User::factory()->admin()->create());
    $template = LandingPageTemplate::factory()->create(['template_type' => 'resource']);
    $url = route('landing-page-templates.update', $template);
    $this->putJson($url, [
        'left_column_order' => [], 'right_column_order' => [], 'hidden_sections' => LandingPageTemplate::RESOURCE_SECTIONS,
    ])->assertOk();
    expect($template->fresh()->hidden_sections)->toBe(LandingPageTemplate::RESOURCE_SECTIONS);
    $this->putJson($url, ['name' => 'Hidden resource modules'])->assertOk();
    $this->putJson($url, [
        'left_column_order' => ['usage'], 'right_column_order' => [], 'hidden_sections' => LandingPageTemplate::RESOURCE_SECTIONS,
    ])->assertUnprocessable();
    expect(LandingPageTemplate::isValidIgsnSectionLayout([], [], LandingPageTemplate::IGSN_SECTIONS))->toBeFalse();
    expect(LandingPageTemplate::normalizeResourceSectionOrders([], [], LandingPageTemplate::RESOURCE_SECTIONS))
        ->toBe(['left' => [], 'right' => [], 'hidden' => LandingPageTemplate::RESOURCE_SECTIONS]);
});

it('supports explicit activation, status, pause, resume, catch-up and month rebuild commands', function () {
    $this->artisan('counter:activate')->assertSuccessful();
    $start = app(CounterTrackingStateService::class)->read()->started_at;
    $this->artisan('counter:activate')->assertSuccessful();
    expect(app(CounterTrackingStateService::class)->read()->started_at)->toBe($start);
    $this->artisan('counter:tracking', ['action' => 'status'])->assertSuccessful();
    $this->artisan('counter:tracking', ['action' => 'pause'])->assertSuccessful();
    $this->artisan('counter:tracking', ['action' => 'resume'])->assertSuccessful();
    $this->artisan('counter:tracking', ['action' => 'reset'])->assertExitCode(2);
    $this->travel(1)->days();
    $this->artisan('counter:aggregate')->assertSuccessful();
    $this->artisan('counter:aggregate', ['--rebuild-month' => '2025-01'])->assertSuccessful();
    $this->artisan('counter:aggregate', ['--rebuild-month' => 'invalid'])->assertExitCode(2);
    $this->artisan('counter:prune')->assertSuccessful();
    $this->artisan('counter:mark-gap', ['from' => '2025-01-15', 'through' => '2025-01-16'])->assertSuccessful();
    expect(DB::table('usage_aggregation_days')->where('incomplete', true)->count())->toBe(2);
});
