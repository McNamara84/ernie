<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\ThesaurusSetting;
use App\Models\User;
use App\Services\ThesaurusReviewReminderService;
use App\Services\ThesaurusStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
    config(['app.timezone' => 'UTC']);
    Storage::fake();
    Http::preventStrayRequests();
    Queue::fake();
});

it('provides a due reminder to both responsible roles without performing a review', function (UserRole $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    foreach (['/dashboard', '/settings?section=thesauri'] as $url) {
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('thesaurusReviewReminder', ['period' => '2026-Q4', 'isDue' => true, 'completedAt' => null]));
    }
    expect(Setting::where('key', 'like', 'thesauri_review_completed:%')->count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([UserRole::ADMIN, UserRole::GROUP_LEADER]);

it('hides the reminder and rejects completion for other roles', function (UserRole $role) {
    DB::enableQueryLog();
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('thesaurusReviewReminder', null));
    $this->postJson('/thesauri/review-completion', ['period' => '2026-Q4'])->assertForbidden();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(array_filter($queries, fn (array $query) => in_array('thesauri_review_completed:2026-Q4', $query['bindings'], true)))->toBeEmpty();
})->with([UserRole::CURATOR, UserRole::BEGINNER]);

it('requires authentication to complete a review', function () {
    $this->post('/thesauri/review-completion', ['period' => '2026-Q4'])->assertRedirect('/login');
    $this->postJson('/thesauri/review-completion', ['period' => '2026-Q4'])->assertUnauthorized();
});

it('completes the quarter for every responsible user and preserves the first completion', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    $expected = ['period' => '2026-Q4', 'isDue' => false, 'completedAt' => '2026-10-19T10:00:00.000000Z'];
    $this->actingAs($user)->postJson('/thesauri/review-completion', [
        'period' => '2026-Q4', 'completed_by' => 9999, 'completed_at' => '2000-01-01',
    ])->assertOk()->assertExactJson(['thesaurusReviewReminder' => $expected]);
    $this->travel(1)->day();
    Cache::flush();
    $this->actingAs(User::factory()->groupLeader()->create());
    $this->postJson('/thesauri/review-completion', ['period' => '2026-Q4'])
        ->assertOk()->assertExactJson(['thesaurusReviewReminder' => $expected]);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('thesaurusReviewReminder', $expected));
    $this->get('/settings')->assertInertia(fn (Assert $page) => $page->where('thesaurusReviewReminder', $expected));
    $completions = Setting::where('key', 'like', 'thesauri_review_completed:%')->get();
    expect($completions)->toHaveCount(1);
    expect(json_decode($completions->first()->value, true)['completed_by'])->toBe($user->id);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([UserRole::ADMIN, UserRole::GROUP_LEADER]);

it('rejects malformed periods', function (mixed $period) {
    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/thesauri/review-completion', ['period' => $period])->assertUnprocessable()->assertJsonValidationErrors('period');
    expect(Setting::where('key', 'like', 'thesauri_review_completed:%')->exists())->toBeFalse();
})->with([null, '', '2026-Q0', '2026-Q5', '26-Q4', '2026-q4', "2026-Q\n4", 2026, [['2026-Q4']]]);

it('rejects a missing period', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/thesauri/review-completion', [])->assertUnprocessable()->assertJsonValidationErrors('period');
});

it('does not complete the current quarter from a stale or future request', function (string $period) {
    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/thesauri/review-completion', ['period' => $period])->assertConflict()
        ->assertJsonPath('thesaurusReviewReminder', ['period' => '2026-Q4', 'isDue' => true, 'completedAt' => null]);
    expect(Setting::where('key', 'like', 'thesauri_review_completed:%')->exists())->toBeFalse();
})->with(['2026-Q3', '2027-Q1']);

it('becomes due at each calendar quarter boundary', function (string $before, string $after, string $oldPeriod, string $newPeriod) {
    $service = app(ThesaurusReviewReminderService::class);
    $user = User::factory()->admin()->create();
    $this->travelTo(CarbonImmutable::parse($before));
    expect($service->complete($oldPeriod, $user)['isDue'])->toBeFalse();
    $this->travelTo(CarbonImmutable::parse($after));
    expect($service->status())->toBe(['period' => $newPeriod, 'isDue' => true, 'completedAt' => null]);
    expect($service->complete($oldPeriod, $user)['isDue'])->toBeTrue();
    expect($service->complete($newPeriod, $user)['isDue'])->toBeFalse();
    expect(Setting::where('key', 'like', 'thesauri_review_completed:%')->count())->toBe(2);
})->with([
    ['2026-03-31T23:59:59Z', '2026-04-01T00:00:00Z', '2026-Q1', '2026-Q2'],
    ['2026-06-30T23:59:59Z', '2026-07-01T00:00:00Z', '2026-Q2', '2026-Q3'],
    ['2026-09-30T23:59:59Z', '2026-10-01T00:00:00Z', '2026-Q3', '2026-Q4'],
    ['2026-12-31T23:59:59Z', '2027-01-01T00:00:00Z', '2026-Q4', '2027-Q1'],
]);

it('uses the configured application timezone for reading and completing the quarter', function () {
    config(['app.timezone' => 'Europe/Berlin']);
    $this->travelTo(CarbonImmutable::parse('2026-03-31T22:00:00Z'));
    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/thesauri/review-completion', ['period' => '2026-Q2'])->assertOk()
        ->assertJsonPath('thesaurusReviewReminder.completedAt', '2026-03-31T22:00:00.000000Z');
    expect(app(ThesaurusReviewReminderService::class)->status()['isDue'])->toBeFalse();
});

it('only selects the supported editor settings focus target', function (string $query, ?string $target) {
    $this->actingAs(User::factory()->admin()->create())->get('/settings'.$query)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('focusSection', $target));
})->with([['', null], ['?section=thesauri', 'thesauri'], ['?section=unknown', null], ['?section[]=thesauri', null]]);

it('keeps completion when its author is deleted or its optional metadata is unavailable', function () {
    $user = User::factory()->admin()->create();
    $service = app(ThesaurusReviewReminderService::class);
    $service->complete('2026-Q4', $user);
    $user->delete();
    expect($service->status()['isDue'])->toBeFalse();
    Setting::where('key', 'thesauri_review_completed:2026-Q4')->update(['value' => null]);
    expect($service->status())->toBe(['period' => '2026-Q4', 'isDue' => false, 'completedAt' => null]);
});

it('does not complete a review through a successful update check or queued download', function () {
    $status = Mockery::mock(ThesaurusStatusService::class);
    $status->shouldReceive('compareWithRemote')->once()->andReturn([
        'localCount' => 10, 'remoteCount' => 10, 'updateAvailable' => false, 'lastUpdated' => null,
    ]);
    $this->app->instance(ThesaurusStatusService::class, $status);
    $type = ThesaurusSetting::firstOrFail()->type;
    $this->actingAs(User::factory()->admin()->create());
    $this->postJson('/thesauri/'.$type.'/check')->assertOk();
    $this->postJson('/thesauri/'.$type.'/update')->assertOk();
    expect(app(ThesaurusReviewReminderService::class)->status()['isDue'])->toBeTrue();
    expect(Setting::where('key', 'like', 'thesauri_review_completed:%')->exists())->toBeFalse();
});
