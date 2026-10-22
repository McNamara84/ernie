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
