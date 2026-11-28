<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\DescriptionType;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceCurationEvent;
use App\Models\ResourceCurationState;
use App\Models\ResourceDoiRegistrationEvidence;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use App\Services\Curation\CurationRegistrationService;
use App\Services\Curation\CurationService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Services\Resources\ResourceListingProjectorService;
use App\Support\Curation\CurationChecklist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->actor = User::factory()->curator()->create();
    $this->resource = Resource::factory()->create(['doi' => null]);
    $this->service = app(CurationService::class);
    $this->service->start($this->resource, $this->actor);
    $this->actingAs($this->actor);
    $this->url = '/resources/'.$this->resource->id.'/curation';
});

it('starts curation from real editor draft saves and preserves activity on identical autosaves', function (bool $beginner): void {
    $actor = $beginner ? User::factory()->beginner()->create() : $this->actor;
    TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);
    $payload = ['resourceId' => null, 'doi' => null, 'resourceType' => null, 'titles' => [['title' => 'First saved draft', 'titleType' => 'main-title']], 'intent' => 'autosave'];
    $response = $this->actingAs($actor)->postJson(route('editor.resources.store-draft'), $payload)->assertCreated();
    $resource = Resource::findOrFail($response->json('resource.id'));
    expect($resource->curationState->lead_curator_id)->toBe($beginner ? null : $actor->id);
    $activity = $resource->curationState->last_activity_at;
    $this->travel(5)->minutes();
    $payload['resourceId'] = $resource->id;
    $this->postJson(route('editor.resources.store-draft'), $payload)->assertSuccessful();
    expect(ResourceCurationEvent::where('resource_id', $resource->id)->where('type', 'curation.started')->count())->toBe(1);
    expect($resource->fresh()->curationState->last_activity_at->equalTo($activity))->toBeTrue();
})->with([true, false]);

it('authorizes all curator roles while excluding inactive users and guests', function (string $role): void {
    $user = User::factory()->create(['role' => $role]);
    $this->actingAs($user)->postJson($this->url.'/notes', ['body' => 'Allowed'])->assertOk();
    $user->update(['is_active' => false]);
    $this->getJson($this->url)->assertForbidden();
    auth()->logout();
    $this->getJson($this->url)->assertUnauthorized();
})->with(['curator', 'group_leader', 'admin']);

it('treats deadlines by calendar day and does not expire them after publication', function (): void {
    config(['app.timezone' => 'Europe/Berlin']);
    $this->travelTo(Carbon::parse('2026-10-21 23:59:00', 'Europe/Berlin'));
    $this->patchJson($this->url.'/state', ['revision' => 0, 'deadline' => '2026-10-21'])->assertJsonPath('summary.deadline_overdue', false);
    $this->travel(2)->minutes();
    $this->getJson($this->url)->assertJsonPath('summary.deadline_overdue', true);
    $this->resource->update(['doi' => '10.1234/deadline']);
    LandingPage::factory()->published()->create(['resource_id' => $this->resource->id]);
    $this->getJson($this->url)->assertJsonPath('summary.deadline_overdue', false);
});

it('supports drafts without DOI or type and leaves beginner and imported drafts unassigned', function (string $source, bool $beginner): void {
    $resource = Resource::factory()->create(['doi' => null, 'resource_type_id' => null]);
    $actor = $beginner ? User::factory()->beginner()->create() : $this->actor;
    $this->service->start($resource, $actor, $source);
    expect($resource->fresh()->curationState->lead_curator_id)->toBeNull();
    expect($resource->fresh()->curators)->toHaveCount(0);
})->with([['editor', true], ['import', false], ['backfill', false]]);

it('excludes both ways of identifying an IGSN', function (bool $metadata): void {
    $resource = Resource::factory()->create();
    if ($metadata) {
        IgsnMetadata::create(['resource_id' => $resource->id]);
    } else {
        $resource->update(['resource_type_id' => ResourceType::firstOrCreate(['slug' => 'physical-object'], ['name' => 'Physical Object'])->id]);
    }
    $this->service->start($resource, $this->actor);
    expect($resource->fresh()->curationState)->toBeNull();
    $this->getJson('/resources/'.$resource->id.'/curation')->assertForbidden();
})->with([true, false]);

it('records system actors when starting and reconciling without a user', function (string $source): void {
    $resource = Resource::factory()->create(['doi' => null]);
    $this->service->start($resource, null, $source);
    $started = ResourceCurationEvent::where('resource_id', $resource->id)->sole();
    expect($started->type)->toBe('curation.started')
        ->and($started->user_id)->toBeNull()
        ->and($started->actor_name)->toBe('ERNIE');

    $this->travel(1)->minutes();
    DB::table('resources')->where('id', $resource->id)->update(['doi' => '10.1234/system-event']);
    $this->service->reconcile($resource->id);
    $changed = ResourceCurationEvent::where('resource_id', $resource->id)->where('type', 'resource.changed')->sole();
    expect($changed->user_id)->toBeNull()
        ->and($changed->actor_name)->toBe('ERNIE')
        ->and($changed->payload['snapshot']['automatic']['doi_assigned'])->toBeTrue()
        ->and($resource->fresh()->curationState->last_activity_at->equalTo($changed->created_at))->toBeTrue();
})->with(['import', 'backfill']);

it('resolves all live automatic items and retracts them when their evidence changes', function (): void {
    $resource = $this->resource;
    $resource->update(['doi' => '10.1234/curation', 'access_level' => AccessLevel::OPEN]);
    $resource->titles()->create(['title_type_id' => TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title'])->id, 'value' => 'Complete dataset']);
    ResourceCreator::factory()->create(['resource_id' => $resource->id]);
    $resource->rights()->attach(Right::factory()->create()->id);
    $resource->descriptions()->create(['description_type_id' => DescriptionType::firstOrCreate(['slug' => 'Abstract'], ['name' => 'Abstract'])->id, 'value' => 'Meaningful abstract']);
    $page = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $this->service->reconcile($resource->id);
    expect($this->service->automatic($resource->fresh()))->toMatchArray(['metadata_complete' => true, 'landing_page_configured' => true, 'doi_assigned' => true, 'doi_registered_and_published' => false, 'review_link_sent' => false]);
    app(CurationRegistrationService::class)->record($resource, ['data' => ['id' => $resource->doi, 'attributes' => ['state' => 'findable']]], 'production', 'registration');
    expect($this->service->automatic($resource->fresh())['doi_registered_and_published'])->toBeTrue();
    $resource->update(['doi' => '10.1234/another']);
    $this->service->reconcile($resource->id);
    expect($this->service->automatic($resource->fresh())['doi_registered_and_published'])->toBeFalse();
    $resource->titles()->delete();
    $resource->update(['doi' => null]);
    $page->delete();
    $this->service->reconcile($resource->id);
    expect($this->service->automatic($resource->fresh()))->toMatchArray(['metadata_complete' => false, 'landing_page_configured' => false, 'doi_assigned' => false]);
    $event = ResourceCurationEvent::where('type', 'resource.changed')->latest('id')->firstOrFail();
    expect($event->actor_name)->toBe('ERNIE')->and($event->payload['snapshot']['automatic']['metadata_complete'])->toBeFalse();
});

it('accepts only evidence from the matching DOI and environment', function (string $environment, bool $testMode, bool $expected): void {
    config(['datacite.test_mode' => $testMode]);
    $this->resource->update(['doi' => '10.1234/evidence']);
    LandingPage::factory()->published()->create(['resource_id' => $this->resource->id]);
    $proof = app(CurationRegistrationService::class);
    $proof->record($this->resource, ['data' => ['id' => '10.1234/different', 'attributes' => ['state' => 'findable']]], $environment, 'verification');
    $proof->record($this->resource, ['data' => ['id' => '10.1234/evidence']], $environment, 'verification');
    expect(ResourceDoiRegistrationEvidence::count())->toBe(0);
    $proof->record($this->resource, ['data' => ['id' => '10.1234/evidence', 'attributes' => ['state' => 'findable']]], $environment, 'verification');
    expect($this->service->automatic($this->resource->fresh())['doi_registered_and_published'])->toBe($expected);
    $proof->record($this->resource, ['data' => ['id' => '10.1234/evidence', 'attributes' => ['state' => 'draft']]], $environment, 'verification');
    expect($this->service->automatic($this->resource->fresh())['doi_registered_and_published'])->toBeFalse();
})->with([['production', false, true], ['production', true, true], ['test', false, false], ['test', true, true], ['fake', false, false], ['fake', true, true]]);

it('completes published work from applicable manual items without inventing historical reviews', function (): void {
    foreach (CurationChecklist::items() as $key => $item) {
        if (! $item['automatic']) {
            $this->service->checklist($this->resource, $this->actor, $key, $item['allows_na'] ? 'not_applicable' : 'done', 'Not needed for software', 0);
        }
    }
    expect($this->service->summary($this->resource->fresh())['open'])->toBeTrue();
    $this->resource->update(['doi' => '10.1234/finished']);
    LandingPage::factory()->published()->create(['resource_id' => $this->resource->id]);
    expect($this->service->summary($this->resource->fresh()))->toMatchArray(['open' => false, 'manual_open' => 0]);
    expect($this->service->automatic($this->resource->fresh())['review_link_sent'])->toBeFalse();
    $this->patchJson($this->url.'/state', ['revision' => 0, 'needs_help_text' => 'Correct a public link'])->assertJsonPath('summary.open', true);
    $this->patchJson($this->url.'/state', ['revision' => 1, 'needs_help_text' => null, 'waiting_for' => 'colleague'])->assertJsonPath('summary.open', true);
    $this->patchJson($this->url.'/state', ['revision' => 2, 'waiting_for' => null])->assertJsonPath('summary.open', false);
    $this->patchJson($this->url.'/checklist/authors_informed', ['revision' => 1, 'state' => 'open'])->assertJsonPath('summary.open', true);
});

it('does not invent history or change activity on reads, no-op saves and projection rebuilds', function (): void {
    $before = $this->resource->fresh()->curationState->last_activity_at;
    $events = ResourceCurationEvent::count();
    $this->travel(2)->days();
    $this->getJson($this->url)->assertOk();
    $this->patchJson($this->url.'/checklist/data_received', ['revision' => 0, 'state' => 'open'])->assertOk();
    $this->patchJson($this->url.'/state', ['revision' => 0, 'waiting_for' => null])->assertOk();
    app(ResourceListingProjectorService::class)->refreshMany([$this->resource->id]);
    expect(ResourceCurationEvent::count())->toBe($events)->and($this->resource->fresh()->curationState->last_activity_at->equalTo($before))->toBeTrue();
});

it('rolls back checklist, history and projected state together', function (): void {
    $events = ResourceCurationEvent::count();
    DB::beginTransaction();
    $this->service->checklist($this->resource, $this->actor, 'data_received', 'done', null, 0);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    DB::rollBack();
    expect(ResourceCurationEvent::count())->toBe($events)->and($this->resource->fresh()->checklistItems->where('item_key', 'data_received'))->toHaveCount(0);
});

it('keeps tombstone history readable and blocks all modifications until restoration', function (): void {
    $page = LandingPage::factory()->published()->create(['resource_id' => $this->resource->id, 'is_tombstone' => true]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('can_write', false)->assertJsonPath('summary.open', false);
    $this->postJson($this->url.'/notes', ['body' => 'Forbidden'])->assertForbidden();
    $page->forceFill(['is_tombstone' => false])->save();
    $this->getJson($this->url)->assertJsonPath('can_write', true)->assertJsonPath('summary.open', true);
});

it('initializes only unpublished eligible records and is safe to repeat', function (): void {
    $draft = Resource::factory()->create(['doi' => null]);
    $old = Resource::factory()->create();
    LandingPage::factory()->published()->create(['resource_id' => $old->id]);
    $this->artisan('curation:initialize')->assertSuccessful();
    expect($draft->fresh()->curationState)->toBeNull();
    $this->artisan('curation:initialize', ['--apply' => true])->assertSuccessful();
    $this->artisan('curation:initialize', ['--apply' => true])->assertSuccessful();
    expect($draft->fresh()->curationState->lead_curator_id)->toBeNull()->and($old->fresh()->curationState)->toBeNull()
        ->and(ResourceCurationState::count())->toBe(2);
});

it('hides curation relations even when resource models are serialized after eager loading', function (): void {
    $array = $this->resource->fresh()->load(CurationService::RELATIONS)->toArray();
    foreach (['curation_state', 'checklist_items', 'curators', 'registration_evidence', 'review_dispatches'] as $key) {
        expect($array)->not->toHaveKey($key);
    }
});
