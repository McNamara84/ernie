<?php

use App\Enums\UserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordCounterUsage;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\User;
use App\Services\Statistics\StatisticsDashboardService;
use App\Services\Usage\CounterAggregationService;
use App\Services\Usage\CounterReadService;
use App\Services\Usage\CounterTrackingStateService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config(['usage.session_secret' => str_repeat('test-counter-secret', 3)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
    app(CounterTrackingStateService::class)->activate();
    $type = ResourceType::firstOrCreate(['slug' => 'dataset'], ['name' => 'Dataset']);
    $resource = Resource::factory()->create(['doi' => '10.5880/counter.test', 'resource_type_id' => $type->id]);
    Title::factory()->for($resource)->create(['value' => 'COUNTER test dataset']);
    $this->page = LandingPage::factory()->published()->for($resource)->create([
        'doi_prefix' => $resource->doi, 'slug' => 'counter-test',
        'ftp_url' => 'https://example.org/data.zip',
    ]);
    $this->withServerVariables(['HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/130', 'REMOTE_ADDR' => '192.0.2.42']);
});

it('records each successful public response even when rendering is cached and refreshes the cache after aggregation', function () {
    $this->get($this->page->public_url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('usage.state', 'pending'));
    $this->get($this->page->public_url)->assertOk();
    expect(DB::table('usage_events')->count())->toBe(2);
    $this->travel(1)->days();
    app(CounterAggregationService::class)->aggregate();
    $this->get($this->page->public_url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('usage.views', 1)->where('usage.dataThrough', '2026-10-01')->where('usage.state', 'ready'));
});

it('records public metadata formats and maps the old JSON-LD alias to the canonical target', function (string $suffix) {
    $this->get($this->page->public_url.$suffix)->assertOk();
    expect(DB::table('usage_events')->count())->toBe(1);
    expect(DB::table('usage_events')->first()->event_type)->toBe('investigation');
})->with(['/metadata/datacite.xml', '/metadata/datacite.json', '/metadata/datacite.jsonld', '/metadata/iso-19115-3.xml', '/jsonld']);

it('counts a final not-modified response but excludes redirects and server errors', function () {
    $request = Request::create($this->page->public_url, 'GET', server: [
        'REMOTE_ADDR' => '192.0.2.42', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/130',
    ]);
    $route = new Route('GET', '{doiPrefix}/{slug}', fn () => null);
    $route->name('landing-page.show')->where('doiPrefix', '.*')->bind($request);
    $request->setRouteResolver(fn () => $route);
    foreach ([304, 301, 500] as $status) {
        $response = app(RecordCounterUsage::class)->handle($request, fn () => new Response('', $status));
        expect($response->getStatusCode())->toBe($status);
    }
    expect(DB::table('usage_events')->count())->toBe(1);
});

it('refreshes the displayed calendar window at the UTC month boundary before the next aggregation', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 23:59:59', 'UTC'));
    $this->get($this->page->public_url)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('usage.months.0.month', '2026-10')->has('usage.months', 1));
    $this->travel(2)->seconds();
    $this->get($this->page->public_url)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('usage.months.1.month', '2026-11')->has('usage.months', 2));
});

it('does not count unavailable ISO exports or downloads awaiting activation', function () {
    config(['iso19115.enabled' => false]);
    $this->get($this->page->public_url.'/metadata/iso-19115-3.xml')->assertNotFound();
    $this->page->update(['downloads_unavailable' => true]);
    $this->get(route('landing-page.download.primary', $this->page))->assertNotFound();
    expect(DB::table('usage_events')->count())->toBe(0);
});

it('does not count final Inertia version conflicts, HEAD, prefetch, failures or authenticated access', function () {
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'obsolete'])
        ->get($this->page->public_url)->assertStatus(409);
    $this->flushHeaders();
    $this->head($this->page->public_url)->assertOk();
    $this->withHeader('Purpose', 'prefetch')->get($this->page->public_url)->assertOk();
    $this->flushHeaders();
    $this->get('/10.5880/missing/missing')->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->get($this->page->public_url)->assertOk();
    expect(DB::table('usage_events')->count())->toBe(0);
});

it('propagates review exclusion to download and metadata links without contaminating cached public URLs', function () {
    $preview = '?preview='.$this->page->preview_token;
    $this->get($this->page->public_url.$preview)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('landingPage.tracked_ftp_url', fn (string $url) => str_ends_with($url, $preview))
            ->where('metadataLinks.0.url', fn (string $url) => str_ends_with($url, $preview)));
    $this->get(route('landing-page.download.primary', $this->page).$preview)->assertStatus(302);
    $this->get($this->page->public_url.'/metadata/datacite.json'.$preview)->assertOk();
    expect(DB::table('usage_events')->count())->toBe(0);
    $this->get($this->page->public_url)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('landingPage.tracked_ftp_url', fn (string $url) => ! str_contains($url, 'preview=')));
    expect(DB::table('usage_events')->count())->toBe(1);
});

it('counts only authorized download links and deduplicates the same external target across local links', function () {
    $file = $this->page->files()->create(['url' => $this->page->ftp_url, 'position' => 0]);
    $link = $this->page->links()->create(['url' => $this->page->ftp_url.'?utm_source=extra', 'kind' => 'download', 'label' => 'Download', 'position' => 0]);
    $repository = $this->page->links()->create(['url' => 'https://example.org', 'kind' => 'repository', 'label' => 'Repository', 'position' => 1]);
    $this->get(route('landing-page.download.primary', $this->page))->assertStatus(302);
    $this->get(route('landing-page.download.file', [$this->page, $file]))->assertStatus(302);
    $this->get(route('landing-page.download.link', [$this->page, $link]))->assertStatus(302);
    $this->get(route('landing-page.download.link', [$this->page, $repository]))->assertNotFound();
    $other = LandingPage::factory()->published()->create();
    $this->get(route('landing-page.download.link', [$other, $link]))->assertNotFound();
    expect(DB::table('usage_events')->count())->toBe(3);
    $this->travel(1)->days();
    app(CounterAggregationService::class)->aggregate();
    expect((int) DB::table('usage_daily_metrics')->first()->total_requests)->toBe(1);
});

it('keeps tombstone investigations and historical requests but blocks new requests', function () {
    $this->get(route('landing-page.download.primary', $this->page))->assertStatus(302);
    $this->page->forceFill(['is_tombstone' => true, 'tombstone_reason' => 'data_lost', 'tombstoned_at' => now()])->save();
    $this->get($this->page->public_url)->assertOk();
    $this->get($this->page->public_url.'/metadata/datacite.json')->assertOk();
    $this->get(route('landing-page.download.primary', $this->page))->assertStatus(410);
    expect(DB::table('usage_events')->count())->toBe(3);
    $this->travel(1)->days();
    app(CounterAggregationService::class)->aggregate();
    expect(app(CounterReadService::class)->landingPage($this->page)['downloads'])->toBe(1);
});

it('counts direct successful searches and submits but not filters, suggestions or legacy POSTs', function (string $portal) {
    $this->get('/'.$portal.'-search?q=Basalt')->assertOk();
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''])->get('/'.$portal.'-search?q=Basalt&type[]=dataset')->assertOk();
    $this->withHeader('X-Ernie-Search-Intent', 'submit')->get('/'.$portal.'-search?q=Granite')->assertOk();
    $this->flushHeaders();
    $this->get('/'.$portal.'-search?q=')->assertOk();
    $this->post('/'.$portal.'-search/search-analytics', ['search_term' => 'old browser'])->assertNoContent();
    expect(DB::table('usage_search_events')->count())->toBe(2)
        ->and(DB::table('portal_search_daily_statistics')->count())->toBe(0);
})->with(['doi', 'igsn']);

it('agrees between public usage, dashboard and CSV while keeping search counts independent of Total or Unique', function () {
    $this->get($this->page->public_url)->assertOk();
    $this->travel(31)->seconds();
    $this->get($this->page->public_url)->assertOk();
    $this->get(route('landing-page.download.primary', $this->page))->assertStatus(302);
    $this->get('/doi-search?q=Gestein')->assertOk();
    $this->travel(1)->days();
    app(CounterAggregationService::class)->aggregate();
    $card = app(CounterReadService::class)->landingPage($this->page);
    $unique = app(StatisticsDashboardService::class)->build();
    $total = app(StatisticsDashboardService::class)->build('total');
    expect($unique['overview']['totalPageViews'])->toBe($card['views'])->toBe(1)
        ->and($total['overview']['totalPageViews'])->toBe(3)
        ->and($total['overview']['totalPortalSearches'])->toBe($unique['overview']['totalPortalSearches'])->toBe(1)
        ->and($unique['overview']['totalDownloadClicks'])->toBe($card['downloads']);
    $response = $this->actingAs(User::factory()->admin()->create())->get('/statistics/usage.csv?from=2026-10&to=2026-10')->assertOk();
    $lines = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));
    expect($lines[1])->toBe([(string) $this->page->id, $this->page->doi_prefix, 'dataset', '2026-10', 'Regular', '3', '1', '1', '1']);
    $this->get('/statistics')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('lastUpdated', $card['lastAggregatedAt'])->where('filters.metric', 'unique'));
});

it('protects exports and validates filters including blank optional date controls', function () {
    $this->get('/statistics/usage.csv')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->curator()->create())->get('/statistics/usage.csv')->assertForbidden();
    $this->actingAs(User::factory()->groupLeader()->create())->get('/statistics/usage.csv?from=&to=')->assertOk();
    foreach (['metric=wrong', 'access_method=all', 'mode=unknown', 'from=2026-99', 'from=2026-10&to=2026-09'] as $query) {
        $this->getJson('/statistics/usage.csv?'.$query)->assertUnprocessable();
    }
});

it('exports COUNTER data only while retaining the historical dashboard', function (UserRole $role): void {
    $this->get($this->page->public_url)->assertOk();
    $this->travel(1)->days();
    app(CounterAggregationService::class)->aggregate();
    $this->actingAs(User::factory()->create(['role' => $role]));

    $default = $this->get('/statistics/usage.csv')->assertOk()->streamedContent();
    $explicit = $this->get('/statistics/usage.csv?mode=counter')->assertOk()->streamedContent();
    expect($explicit)->toBe($default)->toContain($this->page->doi_prefix);

    $this->getJson('/statistics/usage.csv?mode=legacy')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['mode'])
        ->assertJsonPath('errors.mode.0', 'CSV export is only available for COUNTER statistics.')
        ->assertHeaderMissing('Content-Disposition');

    $this->from('/statistics?mode=legacy')->get('/statistics/usage.csv?mode=legacy')
        ->assertRedirect('/statistics?mode=legacy')
        ->assertSessionHasErrors(['mode'])
        ->assertHeaderMissing('Content-Disposition');

    $this->get('/statistics?mode=legacy')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('statistics')->where('filters.mode', 'legacy'));
})->with([UserRole::ADMIN, UserRole::GROUP_LEADER]);

it('does not stop collection when usage is hidden and allows all DOI modules to remain hidden', function () {
    $template = LandingPageTemplate::factory()->create([
        'template_type' => 'resource', 'left_column_order' => [], 'right_column_order' => [],
        'hidden_sections' => LandingPageTemplate::RESOURCE_SECTIONS,
    ]);
    $this->page->update(['landing_page_template_id' => $template->id]);
    $this->get($this->page->public_url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('sectionOrder.leftColumn', [])->where('sectionOrder.rightColumn', [])
        ->where('sectionOrder.hiddenSections', LandingPageTemplate::RESOURCE_SECTIONS));
    expect(DB::table('usage_events')->count())->toBe(1);
});

it('exposes the actual activation date and links the existing TYPO3 privacy statement', function () {
    $this->get('/usage-statistics')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('usage-statistics')->where('usageStatus.startedAt', '2026-10-01T12:00:00+00:00')
        ->where('privacyUrl', 'https://dataservices.gfz-potsdam.de/web/about-us/data-protection'));
});
