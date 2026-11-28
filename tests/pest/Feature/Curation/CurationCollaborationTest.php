<?php

declare(strict_types=1);

use App\Mail\CurationNotification;
use App\Models\Resource;
use App\Models\ResourceCurationEvent;
use App\Models\ResourceDataLocation;
use App\Models\User;
use App\Services\Curation\CurationNotificationService;
use App\Services\Curation\CurationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    $this->actor = User::factory()->curator()->create();
    $this->resource = Resource::factory()->create(['doi' => null]);
    app(CurationService::class)->start($this->resource, $this->actor);
    $this->url = '/resources/'.$this->resource->id.'/curation';
    $this->actingAs($this->actor);
});

it('hands over the lead, retains the old lead, records context and notifies once', function (): void {
    $next = User::factory()->groupLeader()->create();
    $this->postJson($this->url.'/handover', ['lead' => $next->id, 'revision' => 0, 'note' => 'Please verify the coordinate system.'])
        ->assertOk()->assertJsonPath('state.lead_curator_id', $next->id)->assertJsonCount(2, 'curators');
    $this->postJson($this->url.'/handover', ['lead' => $this->actor->id, 'revision' => 0])->assertConflict();
    $this->postJson($this->url.'/handover', ['lead' => $next->id, 'revision' => 1])->assertOk();
    app(CurationNotificationService::class)->dispatch();
    app(CurationNotificationService::class)->dispatch();
    Mail::assertQueued(CurationNotification::class, 1);
    Mail::assertQueued(CurationNotification::class, fn ($mail): bool => $mail->hasTo($next->email));
    expect(ResourceCurationEvent::where('type', 'curation.handover')->count())->toBe(1);
    $this->getJson($this->url.'/events')->assertJsonPath('data.0.body', 'Please verify the coordinate system.');
});

it('rejects new beginner or inactive assignments without losing the current lead', function (string $role, bool $active): void {
    $invalid = User::factory()->create(['role' => $role, 'is_active' => $active]);
    $this->putJson($this->url.'/curators', ['lead' => $invalid->id, 'members' => [], 'revision' => 0])->assertUnprocessable();
    $this->putJson($this->url.'/curators', ['lead' => $this->actor->id, 'members' => [$invalid->id], 'revision' => 0])->assertUnprocessable();
    $this->putJson($this->url.'/curators', ['lead' => null, 'revision' => 0])->assertUnprocessable();
    expect($this->resource->fresh()->curationState->lead_curator_id)->toBe($this->actor->id);
})->with([['beginner', true], ['curator', false]]);

it('normalizes validated numeric member ids and does not notify self assignments', function (): void {
    $resource = Resource::factory()->create(['doi' => null]);
    app(CurationService::class)->start($resource, null, 'backfill');
    $this->putJson('/resources/'.$resource->id.'/curation/curators', ['lead' => (string) $this->actor->id, 'members' => [(string) $this->actor->id], 'revision' => 0])->assertOk();
    expect(DB::table('resource_curation_notifications')->count())->toBe(0);
});

it('retains ineligible former members and denies them write access', function (): void {
    $newLead = User::factory()->curator()->create();
    $this->postJson($this->url.'/handover', ['lead' => $newLead->id, 'revision' => 0])->assertOk();
    $this->actor->update(['role' => 'beginner']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('can_write', false)->assertJsonCount(2, 'curators');
    $this->postJson($this->url.'/notes', ['body' => 'No longer allowed'])->assertForbidden();
    $this->actingAs($newLead)->getJson($this->url)->assertJsonPath('can_write', true);
});

it('uses user ids for mentions, avoids repeated notifications and wipes deleted note text', function (): void {
    $one = User::factory()->curator()->create(['name' => 'Alex Example']);
    $two = User::factory()->beginner()->create(['name' => 'Alex Example']);
    $this->postJson($this->url.'/notes', ['body' => '@Alex Example initial', 'mentions' => [$one->id, $this->actor->id]])->assertOk();
    $note = ResourceCurationEvent::where('type', 'note')->firstOrFail();
    $this->patchJson($this->url.'/notes/'.$note->id, ['body' => '@Alex Example revised', 'mentions' => [$one->id, $two->id], 'revision' => 0])->assertOk();
    $this->patchJson($this->url.'/notes/'.$note->id, ['body' => 'Stale overwrite', 'revision' => 0])->assertConflict();
    app(CurationNotificationService::class)->dispatch();
    Mail::assertQueued(CurationNotification::class, 2);
    expect($note->fresh()->edited_at)->not->toBeNull();
    $this->deleteJson($this->url.'/notes/'.$note->id, ['revision' => 1])->assertOk();
    expect($note->fresh()->body)->toBeNull()->and($note->fresh()->deleted_at)->not->toBeNull();
    $this->getJson($this->url.'/events?notes_only=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_edit', false)->assertJsonPath('data.0.body', null);
    $this->getJson($this->url)->assertJsonPath('latest_note', null);
});

it('rolls back invalid mentions and all associated history', function (): void {
    $inactive = User::factory()->create(['is_active' => false]);
    $this->postJson($this->url.'/notes', ['body' => 'Will roll back', 'mentions' => [$inactive->id]])->assertUnprocessable();
    expect(ResourceCurationEvent::where('type', 'note')->count())->toBe(0);
    expect(DB::table('resource_curation_notifications')->count())->toBe(0);
});

it('paginates history without duplicates when new notes arrive', function (): void {
    $service = app(CurationService::class);
    for ($i = 0; $i < 25; $i++) {
        $service->event($this->resource, 'note', $this->actor, [], 'Note '.$i);
    }
    $first = $this->getJson($this->url.'/events?notes_only=1')->assertJsonCount(20, 'data')->json();
    $service->event($this->resource, 'note', $this->actor, [], 'Arrived later');
    $second = $this->getJson($this->url.'/events?notes_only=1&cursor='.$first['next_cursor'])->assertJsonCount(5, 'data')->json();
    expect(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')))->toBe([])
        ->and($second['next_cursor'])->toBeNull();
});

it('isolates locations and notes by resource and keeps location change history', function (): void {
    $other = Resource::factory()->create();
    $foreign = ResourceDataLocation::create(['resource_id' => $other->id, 'type' => 'other', 'location' => 'private']);
    $this->deleteJson($this->url.'/locations/'.$foreign->id, ['revision' => 0])->assertNotFound();
    $note = app(CurationService::class)->event($other, 'note', $this->actor, [], 'Foreign note');
    $this->patchJson($this->url.'/notes/'.$note->id, ['body' => 'Wrong resource', 'revision' => 0])->assertNotFound();
    $this->postJson($this->url.'/locations', ['type' => 'network_drive', 'location' => '\\server\dataset'])->assertOk();
    $entry = ResourceDataLocation::where('resource_id', $this->resource->id)->firstOrFail();
    $this->patchJson($this->url.'/locations/'.$entry->id, ['type' => 'nextcloud', 'location' => 'https://example.org/data', 'revision' => 1])->assertOk();
    $this->deleteJson($this->url.'/locations/'.$entry->id, ['revision' => 1])->assertConflict();
    $this->deleteJson($this->url.'/locations/'.$entry->id, ['revision' => 2])->assertOk();
    expect(ResourceCurationEvent::whereIn('type', ['location.saved', 'location.deleted'])->count())->toBe(3);
});

it('queues notification and its receipt in the same transaction and retries queue failures', function (): void {
    $target = User::factory()->curator()->create();
    $event = app(CurationService::class)->event($this->resource, 'note', $this->actor, [], 'Please help');
    DB::table('resource_curation_notifications')->insert(['event_id' => $event->id, 'user_id' => $target->id, 'type' => 'mention']);
    $fake = Mail::getFacadeRoot();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Queue unavailable'));
    app(CurationNotificationService::class)->dispatch();
    expect(DB::table('resource_curation_notifications')->value('queued_at'))->toBeNull();
    Mail::swap($fake);
    app(CurationNotificationService::class)->dispatch();
    Mail::assertQueued(CurationNotification::class, 1);
    expect(DB::table('resource_curation_notifications')->value('queued_at'))->not->toBeNull();
});
