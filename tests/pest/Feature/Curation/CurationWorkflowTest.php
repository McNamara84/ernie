<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceCurationEvent;
use App\Models\ResourceCurationState;
use App\Models\User;
use App\Services\Curation\CurationService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    $this->user = User::factory()->curator()->create();
    $this->resource = Resource::factory()->create(['doi' => null]);
    app(CurationService::class)->start($this->resource, $this->user);
    $this->url = '/resources/'.$this->resource->id.'/curation';
    $this->actingAs($this->user);
});

test('starts an unassigned DOI draft once and gives its curator responsibility', function (): void {
    app(CurationService::class)->start($this->resource, $this->user);
    expect(ResourceCurationState::count())->toBe(1)
        ->and($this->resource->fresh()->curationState->lead_curator_id)->toBe($this->user->id)
        ->and(ResourceCurationEvent::where('type', 'curation.started')->count())->toBe(1);
    $this->getJson($this->url)->assertOk()->assertJsonCount(13, 'items')->assertJsonPath('summary.manual_open', 8);
});

test('beginner may read but never change curation', function (string $method, string $path, array $data): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::BEGINNER]));
    $this->getJson($this->url)->assertOk()->assertJsonPath('can_write', false);
    $this->json($method, $this->url.$path, $data)->assertForbidden();
})->with([
    ['PATCH', '/checklist/data_received', ['state' => 'done', 'revision' => 0]],
    ['POST', '/start', []], ['PUT', '/curators', []], ['POST', '/handover', []],
    ['PATCH', '/state', []], ['POST', '/notes', []], ['PATCH', '/notes/1', []],
    ['DELETE', '/notes/1', []], ['POST', '/locations', []], ['PATCH', '/locations/1', []], ['DELETE', '/locations/1', []],
]);

test('manual changes retain their history and reject stale revisions', function (): void {
    $this->patchJson($this->url.'/checklist/data_received', ['state' => 'done', 'revision' => 0])->assertOk();
    $this->patchJson($this->url.'/checklist/data_received', ['state' => 'open', 'revision' => 0])->assertConflict();
    $this->patchJson($this->url.'/checklist/data_received', ['state' => 'open', 'revision' => 1])->assertOk();
    expect(ResourceCurationEvent::where('type', 'checklist.changed')->count())->toBe(2);
});

test('not applicable requires a reason and respects the catalog', function (): void {
    $this->patchJson($this->url.'/checklist/data_received', ['state' => 'not_applicable', 'revision' => 0])->assertUnprocessable();
    $this->patchJson($this->url.'/checklist/authors_approved', ['state' => 'not_applicable', 'reason' => 'No', 'revision' => 0])->assertUnprocessable();
    $this->patchJson($this->url.'/checklist/doi_assigned', ['state' => 'done', 'revision' => 0])->assertUnprocessable();
    $this->patchJson($this->url.'/checklist/data_received', ['state' => 'not_applicable', 'reason' => 'Software on GitHub', 'revision' => 0])
        ->assertOk()->assertJsonPath('items.0.state', 'not_applicable')->assertJsonPath('items.0.reason', 'Software on GitHub');
});

test('old published resources do not enter curation implicitly', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.1234/old']);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    app(CurationService::class)->start($resource, $this->user, 'import');
    expect($resource->fresh()->curationState)->toBeNull();
    $this->postJson('/resources/'.$resource->id.'/curation/start')->assertOk()->assertJsonPath('started', true);
});

test('notes enforce author and the exact 24 hour boundary', function (): void {
    $this->postJson($this->url.'/notes', ['body' => 'Please check https://example.org'])->assertOk();
    $note = ResourceCurationEvent::where('type', 'note')->firstOrFail();
    $this->actingAs(User::factory()->curator()->create())->patchJson($this->url.'/notes/'.$note->id, ['body' => 'Changed', 'revision' => 0])->assertForbidden();
    $this->actingAs($this->user);
    $this->travelTo($note->created_at->copy()->addDay());
    $this->deleteJson($this->url.'/notes/'.$note->id, ['revision' => 0])->assertForbidden();
});

test('waiting becomes overdue only on day fifteen and text edits preserve its start', function (): void {
    $this->travelTo(now()->startOfDay());
    $this->patchJson($this->url.'/state', ['waiting_for' => 'submitters', 'revision' => 0])->assertOk();
    $since = $this->resource->fresh()->curationState->waiting_since;
    $this->travelTo($since->copy()->addDays(14)->subSecond());
    $this->getJson($this->url)->assertJsonPath('summary.waiting_days', 13)->assertJsonPath('summary.waiting_overdue', false);
    $this->patchJson($this->url.'/state', ['waiting_text' => 'Still waiting', 'revision' => 1])->assertOk();
    expect($this->resource->fresh()->curationState->waiting_since->equalTo($since))->toBeTrue();
    $this->travel(1)->seconds();
    $this->getJson($this->url)->assertJsonPath('summary.waiting_days', 14)->assertJsonPath('summary.waiting_overdue', true);
});
