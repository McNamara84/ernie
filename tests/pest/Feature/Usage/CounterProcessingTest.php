<?php

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use App\Services\Statistics\LandingPageAnalyticsService;
use App\Services\Statistics\PortalSearchAnalyticsService;
use App\Services\Usage\CounterAggregationService;
use App\Services\Usage\CounterEventRecorderService;
use App\Services\Usage\CounterListService;
use App\Services\Usage\CounterSessionHasherService;
use App\Services\Usage\CounterTrackingStateService;
use App\Services\Usage\CounterUserAgentClassifierService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['usage.session_secret' => str_repeat('counter-test-secret', 3)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
});

function usageRequest(string $ua = 'Mozilla/5.0 Firefox/130', string $url = '/'): Request
{
    return Request::create($url, 'GET', server: ['REMOTE_ADDR' => '192.0.2.9', 'HTTP_USER_AGENT' => $ua]);
}

function usagePage(bool $igsn = false): LandingPage
{
    $type = ResourceType::firstOrCreate(['slug' => $igsn ? 'physical-object' : 'dataset'], ['name' => $igsn ? 'Physical Object' : 'Dataset']);

    return LandingPage::factory()->published()->withDoi('10.5880/usage.'.fake()->uuid())
        ->for(Resource::factory()->create(['resource_type_id' => $type->id]))->create();
}

it('classifies research clients, unknown agents and specific crawlers using the bundled lists', function (?string $agent, string $expected) {
    $id = app(CounterListService::class)->installBundled();
    $rules = json_decode(DB::table('usage_list_versions')->find($id)->rules, true);
    expect(app(CounterUserAgentClassifierService::class)->classify($agent, $rules))->toBe($expected);
})->with([
    ['Googlebot', 'Robot'], ['GPTBot', 'Robot'], ['python-requests Googlebot', 'Robot'],
    ['curl/8.0', 'Machine'], ['python-requests/2.32', 'Machine'], ['Java/21', 'Machine'],
    ['Wget/1.21', 'Machine'], [null, 'Machine'], ['unlisted research browser', 'Regular'],
]);

it('preserves the active list after malformed or failed updates', function () {
    $id = app(CounterListService::class)->installBundled();
    Http::fake(['*' => Http::response('invalid', 200)]);
    expect(fn () => app(CounterListService::class)->update())->toThrow(JsonException::class);
    expect(app(CounterTrackingStateService::class)->read()->list_version_id)->toBe($id);
    Http::fake(['*' => Http::response('', 503)]);
    expect(fn () => app(CounterListService::class)->update())->toThrow(Exception::class);
    expect(DB::table('usage_list_versions')->count())->toBe(1);
});

it('switches a fully validated list atomically and preserves its version on a connection failure', function () {
    $service = app(CounterListService::class);
    $original = $service->installBundled();
    $sources = [
        'counter' => file_get_contents(resource_path('data/counter/counter-robots.json')),
        'robots' => file_get_contents(resource_path('data/counter/robots.txt'))."\nCounterTestCrawler\n",
        'machines' => file_get_contents(resource_path('data/counter/machines.txt')),
    ];
    $responses = [];
    foreach ($sources as $name => $body) {
        $responses[config('usage.sources.'.$name)] = Http::response($body);
    }
    Http::fake($responses);
    $updated = $service->update();
    expect($updated)->not->toBe($original);
    $rules = json_decode(DB::table('usage_list_versions')->find($updated)->rules, true);
    expect(app(CounterUserAgentClassifierService::class)->classify('CounterTestCrawler', $rules))->toBe('Robot');
    Http::fake(['*' => Http::failedConnection()]);
    expect(fn () => $service->update())->toThrow(ConnectionException::class);
    expect(app(CounterTrackingStateService::class)->read()->list_version_id)->toBe($updated)
        ->and(DB::table('usage_list_versions')->count())->toBe(2);
});

it('requires a dedicated secret and makes activation irreversible for every legacy writer', function () {
    $page = usagePage();
    $page->incrementViewCount();
    app(LandingPageAnalyticsService::class)->recordPageView($page);
    app(PortalSearchAnalyticsService::class)->recordSearch(usageRequest(), 'before');
    $before = $page->fresh()->only(['view_count', 'last_viewed_at']);
    config(['usage.session_secret' => '']);
    expect(fn () => app(CounterTrackingStateService::class)->activate())->toThrow(RuntimeException::class);
    config(['usage.session_secret' => str_repeat('x', 32)]);
    $start = app(CounterTrackingStateService::class)->activate();
    $this->travel(2)->hours();
    expect(app(CounterTrackingStateService::class)->activate()->equalTo($start))->toBeTrue();
    app(CounterTrackingStateService::class)->pause(true);
    $page->incrementViewCount();
    app(LandingPageAnalyticsService::class)->recordPageView($page);
    app(LandingPageAnalyticsService::class)->recordFileDownloadClick($page);
    app(PortalSearchAnalyticsService::class)->recordSearch(usageRequest(), 'after');
    expect($page->fresh()->only(['view_count', 'last_viewed_at']))->toEqual($before)
        ->and((int) DB::table('landing_page_daily_statistics')->sum('page_view_count'))->toBe(1)
        ->and((int) DB::table('landing_page_daily_statistics')->sum('file_download_click_count'))->toBe(0)
        ->and(DB::table('portal_search_daily_statistics')->count())->toBe(1);
});

it('changes pseudonymous sessions only at UTC hour boundaries or when the input changes', function () {
    $hasher = app(CounterSessionHasherService::class);
    $at = CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC');
    $first = $hasher->hash(usageRequest(), $at);
    expect($first)->toHaveLength(64)
        ->toBe($hasher->hash(usageRequest(), $at->addMinutes(59)))
        ->not->toBe($hasher->hash(usageRequest(), $at->addHour()))
        ->not->toBe($hasher->hash(usageRequest('curl/8'), $at));
    expect($hasher->target('https://example.org/a?b=2&a=1&utm_source=mail'))
        ->toBe($hasher->target('https://example.org/a?a=1&b=2'))
        ->not->toBe($hasher->target('https://example.org/b?a=1&b=2'));
});

it('filters chained clicks and unions investigations and requests without adding unique sessions', function (array $seconds, int $expected) {
    app(CounterTrackingStateService::class)->activate();
    $page = usagePage();
    $base = CarbonImmutable::now();
    foreach ($seconds as $second) {
        $this->travelTo($base->addSeconds($second));
        app(CounterEventRecorderService::class)->landingPage(usageRequest(), $page, 'investigation', 'landing-page');
    }
    app(CounterEventRecorderService::class)->landingPage(usageRequest(), $page, 'request', 'https://example.org/data.zip');
    $event = (array) DB::table('usage_events')->first();
    expect($event)->not->toHaveKeys(['ip', 'user_agent', 'url']);
    $this->travelTo($base->addDay());
    expect(app(CounterAggregationService::class)->aggregate())->toBe(1);
    $row = DB::table('usage_daily_metrics')->first();
    expect((int) $row->total_investigations)->toBe($expected + 1)
        ->and((int) $row->unique_investigations)->toBe(1)
        ->and((int) $row->total_requests)->toBe(1)
        ->and((int) $row->unique_requests)->toBe(1)
        ->and(DB::table('usage_events')->count())->toBe(0);
    expect(app(CounterAggregationService::class)->aggregate())->toBe(0);
    app(CounterAggregationService::class)->rebuildMonth($base);
    expect((int) DB::table('usage_monthly_metrics')->first()->total_investigations)->toBe($expected + 1);
})->with([[[0, 20, 40], 1], [[0, 29], 1], [[0, 30], 1], [[0, 31], 2]]);

it('separates access methods and resources and stores null request metrics for IGSNs', function () {
    app(CounterTrackingStateService::class)->activate();
    $doi = usagePage();
    $igsn = usagePage(true);
    foreach ([$doi, $igsn] as $page) {
        foreach (['Mozilla/5.0 Firefox/130', 'curl/8'] as $agent) {
            app(CounterEventRecorderService::class)->landingPage(usageRequest($agent), $page, 'investigation', 'landing-page');
            app(CounterEventRecorderService::class)->landingPage(usageRequest($agent), $page, 'request', 'https://example.org/data');
        }
    }
    $this->travel(1)->days();
    app(CounterAggregationService::class)->aggregate();
    expect(DB::table('usage_daily_metrics')->count())->toBe(4);
    foreach (DB::table('usage_daily_metrics')->where('landing_page_id', $igsn->id)->get() as $row) {
        expect($row->total_requests)->toBeNull()->and($row->unique_requests)->toBeNull();
    }
});

it('unions formats and downloads within an hour and starts new sessions across UTC hours and months', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 22:00:00', 'UTC'));
    app(CounterTrackingStateService::class)->activate();
    $page = usagePage();
    $recorder = app(CounterEventRecorderService::class);
    foreach (['2026-09-30 22:59:59', '2026-09-30 23:59:59', '2026-10-01 00:00:01'] as $time) {
        $this->travelTo(CarbonImmutable::parse($time, 'UTC'));
        $recorder->landingPage(usageRequest(), $page, 'investigation', 'landing-page');
        $recorder->landingPage(usageRequest(), $page, 'investigation', 'datacite-json');
        $recorder->landingPage(usageRequest(), $page, 'request', 'https://example.org/first.zip');
        $recorder->landingPage(usageRequest(), $page, 'request', 'https://example.org/second.zip');
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-02', 'UTC'));
    expect(app(CounterAggregationService::class)->aggregate())->toBe(2);
    $months = DB::table('usage_monthly_metrics')->orderBy('month')->get();
    expect((int) $months[0]->total_investigations)->toBe(8)
        ->and((int) $months[0]->unique_investigations)->toBe(2)
        ->and((int) $months[0]->unique_requests)->toBe(2)
        ->and((int) $months[1]->total_investigations)->toBe(4)
        ->and((int) $months[1]->unique_investigations)->toBe(1)
        ->and((int) $months[1]->unique_requests)->toBe(1);
});

it('excludes previews, logged-in users, robots, prefetches, HEAD and unpublished or external pages', function () {
    app(CounterTrackingStateService::class)->activate();
    $page = usagePage();
    $requests = [usageRequest('Googlebot'), usageRequest(url: '/?preview='.$page->preview_token)];
    $head = usageRequest();
    $head->setMethod('HEAD');
    $requests[] = $head;
    $prefetch = usageRequest();
    $prefetch->headers->set('Sec-Purpose', 'prefetch;prerender');
    $requests[] = $prefetch;
    $signedIn = usageRequest();
    $signedIn->setUserResolver(fn () => User::factory()->make());
    $requests[] = $signedIn;
    foreach ($requests as $request) {
        app(CounterEventRecorderService::class)->landingPage($request, $page, 'investigation', 'landing-page');
    }
    app(CounterEventRecorderService::class)->landingPage(usageRequest(), LandingPage::factory()->draft()->create(), 'investigation', 'landing-page');
    app(CounterEventRecorderService::class)->landingPage(usageRequest(), LandingPage::factory()->published()->external()->create(), 'investigation', 'landing-page');
    expect(DB::table('usage_events')->count())->toBe(0);
    app(CounterEventRecorderService::class)->landingPage(usageRequest(url: '/?preview=irrelevant'), $page, 'investigation', 'landing-page');
    expect(DB::table('usage_events')->count())->toBe(1);
});

it('counts intentional searches by portal and normalizes the full term without unique metrics', function () {
    app(CounterTrackingStateService::class)->activate();
    foreach (['doi', 'igsn'] as $scope) {
        app(CounterEventRecorderService::class)->search(usageRequest(url: '/?q=+Rock+++Sample*+'), $scope);
        $request = usageRequest(url: '/?q=rock+sample*');
        $request->headers->set('X-Inertia', 'true');
        app(CounterEventRecorderService::class)->search($request, $scope);
        $request->headers->set('X-Ernie-Search-Intent', 'submit');
        app(CounterEventRecorderService::class)->search($request, $scope);
    }
    expect(DB::table('usage_search_events')->count())->toBe(4);
    $this->travel(1)->days();
    app(CounterAggregationService::class)->aggregate();
    expect(DB::table('usage_search_daily_metrics')->count())->toBe(2);
    foreach (DB::table('usage_search_daily_metrics')->get() as $row) {
        expect($row->normalized_term)->toBe('rock sample*')->and((int) $row->search_count)->toBe(1);
    }
});

it('marks only the actual pause interval as incomplete and never resumes legacy recording', function () {
    app(CounterTrackingStateService::class)->activate();
    $this->travel(2)->days();
    app(CounterTrackingStateService::class)->pause(true);
    $this->travel(2)->days();
    app(CounterTrackingStateService::class)->pause(false);
    expect(DB::table('usage_aggregation_days')->where('incomplete', true)->pluck('day')->all())
        ->toBe(['2026-10-03', '2026-10-04', '2026-10-05']);
});

it('expires raw data within seven days even if aggregation failed and records the gap', function () {
    app(CounterTrackingStateService::class)->activate();
    app(CounterEventRecorderService::class)->landingPage(usageRequest(), usagePage(), 'investigation', 'landing-page');
    $this->travel(7)->days();
    expect(app(CounterAggregationService::class)->prune())->toBe(1)
        ->and(DB::table('usage_events')->count())->toBe(0)
        ->and((bool) DB::table('usage_aggregation_days')->where('day', '2026-10-01')->first()->incomplete)->toBeTrue();
    app(CounterAggregationService::class)->aggregate();
    expect((bool) DB::table('usage_aggregation_days')->where('day', '2026-10-01')->first()->incomplete)->toBeTrue();
});

it('rolls back daily and monthly writes together when a month cannot be committed', function () {
    app(CounterTrackingStateService::class)->activate();
    app(CounterEventRecorderService::class)->landingPage(usageRequest(), usagePage(), 'investigation', 'landing-page');
    $this->travel(1)->days();
    $fail = true;
    DB::listen(function (QueryExecuted $query) use (&$fail): void {
        if ($fail && str_contains($query->sql, 'usage_monthly_metrics') && str_starts_with(strtolower($query->sql), 'insert')) {
            $fail = false;
            throw new RuntimeException('Simulated aggregate failure');
        }
    });
    expect(fn () => app(CounterAggregationService::class)->aggregate())->toThrow(RuntimeException::class, 'Simulated');
    expect(DB::table('usage_events')->count())->toBe(1)
        ->and(DB::table('usage_daily_metrics')->count())->toBe(0)
        ->and(DB::table('usage_monthly_metrics')->count())->toBe(0)
        ->and(app(CounterTrackingStateService::class)->read()->data_through)->toBeNull();
    expect(app(CounterAggregationService::class)->aggregate())->toBe(1);
    expect(DB::table('usage_events')->count())->toBe(0);
});

it('rejects invalid regexes without replacing the last valid rules', function () {
    $service = app(CounterListService::class);
    $id = $service->installBundled();
    $sources = [
        'counter' => '[{"pattern":"["}]',
        'robots' => file_get_contents(resource_path('data/counter/robots.txt')),
        'machines' => file_get_contents(resource_path('data/counter/machines.txt')),
    ];
    expect(fn () => $service->install($sources, 'test'))->toThrow(RuntimeException::class, 'Invalid regular expression');
    expect(app(CounterTrackingStateService::class)->read()->list_version_id)->toBe($id);
});
