<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceChecklistItem;
use App\Models\ResourceCurationState;
use App\Models\TitleType;
use App\Models\User;
use App\Services\Curation\CurationOverviewService;
use App\Services\Curation\CurationService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Services\Resources\ResourceListingProjectorService;
use App\Support\Curation\CurationChecklist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Mail::fake();
    $this->actor = User::factory()->curator()->create();
    $this->actingAs($this->actor);
    $this->service = app(CurationService::class);
});

function overviewDraft(User $actor, string $title = 'Dataset'): Resource
{
    $resource = Resource::factory()->create();
    $resource->titles()->create(['value' => $title, 'title_type_id' => TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title'])->id]);
    app(CurationService::class)->start($resource, $actor);

    return $resource;
}

function completeCurationManually(Resource $resource): void
{
    foreach (CurationChecklist::items() as $key => $definition) {
        if (! $definition['automatic']) {
            ResourceChecklistItem::create(['resource_id' => $resource->id, 'item_key' => $key, 'state' => 'done', 'changed_at' => now()]);
        }
    }
    app(ResourceListingProjectorService::class)->refreshMany([$resource->id]);
}

it('counts lead and member assignments once and keeps dashboard destinations consistent', function (): void {
    $mine = overviewDraft($this->actor, 'Lead resource');
    $colleague = User::factory()->curator()->create();
    $member = overviewDraft($colleague, 'Member resource');
    $member->curators()->attach($this->actor->id, ['assigned_at' => now()]);
    overviewDraft($colleague, 'Other resource');
    LandingPage::factory()->published()->create(['resource_id' => $member->id, 'published_at' => now()->subYear()]);
    ResourceCurationState::whereKey($member->id)->update(['needs_help_text' => 'Public correction', 'waiting_for' => 'submitters', 'waiting_since' => now()->subDays(16)]);
    app(ResourceListingProjectorService::class)->refreshMany([$member->id]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    $widget = app(CurationOverviewService::class)->widget($this->actor);
    expect($widget)->toBe(['open' => 2, 'waiting' => 1, 'help' => 1]);
    $this->get('/curation?tab=all&mine=1&open=1')->assertInertia(fn (Assert $page) => $page->component('curation')->where('resources.total', 2)->has('resources.data', 2));
    $this->get('/curation?tab=all&mine=1&open=1&waiting_overdue=1')->assertInertia(fn (Assert $page) => $page->where('resources.total', 1)->where('resources.data.0.id', $member->id));
    $this->get('/curation?tab=all&mine=1&open=1&help=1')->assertInertia(fn (Assert $page) => $page->where('resources.total', 1)->where('resources.data.0.title', 'Member resource'));
    $this->get('/curation?tab=progress&mine=1')->assertInertia(fn (Assert $page) => $page->where('resources.data.0.id', $mine->id));
});

it('serializes activity with its timezone for clients in different timezones', function (): void {
    $resource = overviewDraft($this->actor);
    ResourceCurationState::whereKey($resource->id)->update(['last_activity_at' => '2026-07-15 12:00:00']);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    app(ResourceListingProjectorService::class)->refreshMany([$resource->id]);

    $this->get('/curation?mine=1')->assertInertia(fn (Assert $page) => $page
        ->where('resources.data.0.last_activity_at', '2026-07-15T12:00:00.000000Z'));
    $overview = app(CurationOverviewService::class);
    $row = $overview->row($overview->rows(['mine' => true], $this->actor)->firstOrFail());
    expect(Carbon::parse($row['last_activity_at'])->setTimezone('Europe/Berlin')->format('H:i'))->toBe('14:00');

    DB::table('resource_listing_projections')->where('resource_id', $resource->id)->update(['curation_activity_at' => null]);
    expect($overview->row($overview->rows(['mine' => true], $this->actor)->firstOrFail())['last_activity_at'])->toBeNull();
});

it('uses the configured waiting threshold consistently in panels filters and dashboard counts', function (): void {
    config(['curation.waiting_overdue_days' => 3]);
    $this->travelTo(now()->startOfDay());
    $resources = collect([2, 3])->map(function (int $days): Resource {
        $resource = overviewDraft($this->actor, 'Waiting '.$days);
        ResourceCurationState::whereKey($resource->id)->update(['waiting_for' => 'submitters', 'waiting_since' => now()->subDays($days)]);

        return $resource;
    });
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    app(ResourceListingProjectorService::class)->refreshMany($resources->pluck('id')->all());
    $overview = app(CurationOverviewService::class);
    expect($overview->widget($this->actor)['waiting'])->toBe(1);
    $this->get('/curation?mine=1&waiting_overdue=1')->assertInertia(fn (Assert $page) => $page
        ->where('resources.total', 1)->where('resources.data.0.title', 'Waiting 3')->where('resources.data.0.overdue', true));
    foreach ($resources as $index => $resource) {
        $this->getJson('/resources/'.$resource->id.'/curation')->assertOk()->assertJsonPath('summary.waiting_overdue', $index === 1);
    }
});

it('includes the entire waiting start day at the calendar cutoff in every curation view', function (string $timezone, string $date, int $limit): void {
    config(['app.timezone' => $timezone, 'curation.waiting_overdue_days' => $limit]);
    $today = Carbon::parse($date, $timezone)->startOfDay();
    $startDay = $today->copy()->subDays($limit);
    $this->travelTo($today->copy()->subSecond());
    $cases = [
        'Older wait' => [$startDay->copy()->subDay()->endOfDay(), $limit + 1],
        'Start at midnight' => [$startDay->copy(), $limit],
        'Start at noon' => [$startDay->copy()->setTime(12, 0), $limit],
        'Start at last second' => [$startDay->copy()->setTime(23, 59, 59), $limit],
        'Next day midnight' => [$startDay->copy()->addDay(), $limit - 1],
        'Not waiting' => [null, null],
        'Future wait' => [$today->copy()->addDay(), 0],
    ];
    $resources = [];
    foreach ($cases as $title => [$since, $days]) {
        $resource = overviewDraft($this->actor, $title);
        ResourceCurationState::whereKey($resource->id)->update([
            'waiting_for' => $since === null ? null : 'submitters',
            'waiting_since' => $since?->copy()->utc(),
        ]);
        $resources[] = [$resource, $days];
    }
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    app(ResourceListingProjectorService::class)->refreshMany(array_map(fn (array $entry): int => $entry[0]->id, $resources));
    $overview = app(CurationOverviewService::class);

    // Crossing local midnight changes the classification without a projection refresh.
    foreach ([false, true] as $atBoundary) {
        if ($atBoundary) {
            $this->travelTo($today);
        }
        $expectedIds = [];
        $rows = $overview->rows(['tab' => 'all'], $this->actor)->get()->map($overview->row(...))->keyBy('id');
        foreach ($resources as [$resource, $days]) {
            $expectedDays = $days === null ? null : max(0, $days - ($atBoundary ? 0 : 1));
            $overdue = $expectedDays !== null && $expectedDays >= $limit;
            if ($overdue) {
                $expectedIds[] = $resource->id;
            }
            $this->getJson('/resources/'.$resource->id.'/curation')->assertOk()
                ->assertJsonPath('summary.waiting_days', $expectedDays)
                ->assertJsonPath('summary.waiting_overdue', $overdue)
                ->assertJsonPath('summary.overdue', $overdue);
            expect($rows[$resource->id]['waiting_days'])->toBe($expectedDays)
                ->and($rows[$resource->id]['overdue'])->toBe($overdue);
        }
        foreach (['waiting_overdue', 'overdue'] as $filter) {
            expect($overview->query(['tab' => 'all', $filter => true], $this->actor)->orderBy('resources.id')->pluck('resources.id')->all())
                ->toBe($expectedIds);
            $this->get('/curation?tab=all&mine=1&'.$filter.'=1')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('resources.total', count($expectedIds)));
        }
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('curationCounts.waiting', count($expectedIds)));
    }
    $csv = $this->get('/curation/export?tab=all&waiting_overdue=1')->assertOk()->streamedContent();
    expect($csv)->toContain('Start at midnight', 'Start at noon', 'Start at last second')
        ->not->toContain('Next day midnight', 'Not waiting', 'Future wait');
})->with([
    'UTC default' => ['UTC', '2026-07-15', 14],
    'Berlin summer' => ['Europe/Berlin', '2026-07-15', 14],
    'spans spring DST change' => ['Europe/Berlin', '2026-03-30', 14],
    'spans autumn DST change' => ['Europe/Berlin', '2026-10-26', 14],
    'start day has 23 hours' => ['Europe/Berlin', '2026-04-12', 14],
    'start day has 25 hours' => ['Europe/Berlin', '2026-11-08', 14],
    'custom three-day limit' => ['UTC', '2026-07-15', 3],
    'one-day limit across DST change' => ['Europe/Berlin', '2026-10-26', 1],
]);

it('limits completed published work to ninety days but retains older unfinished work', function (): void {
    $this->travelTo(now()->startOfDay());
    foreach ([89, 90, 91] as $age) {
        $resource = overviewDraft($this->actor, 'Published '.$age);
        LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'published_at' => now()->subDays($age)]);
        completeCurationManually($resource);
    }
    $open = overviewDraft($this->actor, 'Old unfinished');
    LandingPage::factory()->published()->create(['resource_id' => $open->id, 'published_at' => now()->subYear()]);
    $dead = overviewDraft($this->actor, 'Tombstone');
    LandingPage::factory()->published()->create(['resource_id' => $dead->id, 'is_tombstone' => true]);
    $this->get('/curation?tab=published')->assertInertia(fn (Assert $page) => $page->where('resources.total', 3));
    $this->get('/curation?tab=all&open=1')->assertInertia(fn (Assert $page) => $page->where('resources.total', 1)->where('resources.data.0.id', $open->id));
});

it('combines unassigned, datacenter and working-state filters and validates sort values', function (): void {
    $center = Datacenter::factory()->create();
    $resource = overviewDraft($this->actor);
    $resource->update(['datacenter_id' => $center->id]);
    ResourceCurationState::whereKey($resource->id)->update(['lead_curator_id' => null, 'waiting_for' => 'other', 'waiting_since' => now()->subDays(15), 'needs_help_text' => 'Help']);
    $resource->curators()->detach();
    app(ResourceListingProjectorService::class)->refreshMany([$resource->id]);
    overviewDraft($this->actor);
    $this->get('/curation?unassigned=1&waiting=1&help=1&overdue=1&datacenter='.$center->id)->assertInertia(fn (Assert $page) => $page->where('resources.total', 1)->where('resources.data.0.id', $resource->id));
    $this->getJson('/curation?sort=DROP%20TABLE')->assertUnprocessable();
    $this->getJson('/curation?tab=secret')->assertUnprocessable();
});

it('sorts deadlines stably with null dates last in both directions', function (string $direction): void {
    $none = overviewDraft($this->actor);
    $one = overviewDraft($this->actor);
    $two = overviewDraft($this->actor);
    ResourceCurationState::whereIn('resource_id', [$one->id, $two->id])->update(['deadline' => '2027-01-01']);
    app(ResourceListingProjectorService::class)->refreshMany([$none->id, $one->id, $two->id]);
    $this->get('/curation?sort=deadline&direction='.$direction)->assertInertia(fn (Assert $page) => $page->where('resources.data.0.id', $one->id)->where('resources.data.1.id', $two->id)->where('resources.data.2.id', $none->id));
})->with(['asc', 'desc']);

it('paginates fifty rows and exports every filtered row with formula protection', function (): void {
    for ($i = 0; $i < 52; $i++) {
        overviewDraft($this->actor, $i === 0 ? '=HYPERLINK("https://example.org")' : 'Dataset '.$i);
    }
    $other = overviewDraft(User::factory()->curator()->create(), 'Excluded resource');
    $this->get('/curation?mine=1&sort=created&direction=asc')->assertInertia(fn (Assert $page) => $page->where('resources.total', 52)->has('resources.data', 50)->where('resources.last_page', 2));
    $csv = $this->get('/curation/export?mine=1&page=2&sort=created&direction=asc')->assertOk()->streamedContent();
    expect($csv)->toStartWith("\xEF\xBB\xBF")->toContain("'=HYPERLINK")->not->toContain('Excluded resource');
    expect(count(explode("\n", trim($csv))))->toBe(53);
    expect($other->curationState)->not->toBeNull();
});

it('exports unassigned curations alongside assigned rows', function (bool $hasMember): void {
    $unassigned = Resource::factory()->create();
    $unassigned->titles()->create([
        'value' => 'Unassigned resource',
        'title_type_id' => TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title'])->id,
    ]);
    $this->service->start($unassigned, null, 'backfill');
    if ($hasMember) {
        $unassigned->curators()->attach($this->actor->id, ['assigned_at' => now()]);
    }
    overviewDraft($this->actor, 'Assigned resource');

    $csv = $this->get('/curation/export?tab=all&sort=created&direction=asc')
        ->assertOk()->assertDownload('curation.csv')->streamedContent();
    expect($csv)->toStartWith("\xEF\xBB\xBF");
    $rows = array_map(
        static fn (string $line): array => str_getcsv($line, escape: ''),
        explode("\n", trim(substr($csv, 3))),
    );

    expect($rows)->toHaveCount(3)
        ->and($rows[0][6])->toBe('Lead')
        ->and($rows[0][7])->toBe('Curators')
        ->and($rows[1][0])->toBe('Unassigned resource')
        ->and($rows[1][6])->toBe('Unassigned')
        ->and($rows[1][7])->toBe($hasMember ? $this->actor->name : '')
        ->and($rows[2][0])->toBe('Assigned resource')
        ->and($rows[2][6])->toBe($this->actor->name)
        ->and($rows[2][7])->toBe($this->actor->name);
})->with(['without curators' => false, 'with a member but no lead' => true]);

it('serves three hundred ongoing resources with a bounded number of queries', function (): void {
    // Bulk seed source tables; projection still resolves the real model graph.
    $prototype = overviewDraft($this->actor);
    $rows = [];
    for ($i = 0; $i < 299; $i++) {
        $rows[] = [...$prototype->getAttributes(), 'id' => $prototype->id + $i + 1, 'doi' => '10.1234/performance-'.$i];
    }
    DB::table('resources')->insert($rows);
    $ids = Resource::pluck('id')->all();
    $now = now();
    foreach (array_chunk(array_slice($ids, 1), 100) as $chunk) {
        DB::table('resource_curation_states')->insert(array_map(fn ($id): array => ['resource_id' => $id, 'start_source' => 'backfill', 'started_at' => $now, 'last_activity_at' => $now], $chunk));
    }
    app(ResourceListingProjectorService::class)->refreshMany($ids);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    $overview = app(CurationOverviewService::class);
    $measure = function (int $limit) use ($overview): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = microtime(true);
        $rows = $overview->rows(['tab' => 'all', 'sort' => 'progress'], $this->actor)->limit($limit)->get()->map($overview->row(...));
        $elapsed = microtime(true) - $start;
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$rows->count(), $elapsed, $count];
    };
    $small = $measure(5);
    $large = $measure(50);
    expect($large[0])->toBe(50)->and($large[2])->toBeLessThanOrEqual($small[2] + 1)->and($large[1])->toBeLessThan(1.0);
});
