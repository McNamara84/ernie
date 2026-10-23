<?php

declare(strict_types=1);

use App\Enums\ContributorCategory;
use App\Mail\CurationNotification;
use App\Mail\ResourceReviewLink;
use App\Mail\ResourceReviewLinkMigration;
use App\Models\ContributorType;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCurationEvent;
use App\Models\ResourceReviewLinkDispatch;
use App\Models\User;
use App\Services\Curation\CurationNotificationService;
use App\Services\Curation\CurationService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Services\Resources\ResourceReviewLinkService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('rolls back and delivers curation mail independently of the general queue database', function (string $kind, bool $separateDatabase): void {
    $actor = User::factory()->curator()->create();
    $recipient = User::factory()->curator()->create();
    $resource = Resource::factory()->create(['doi' => null, 'force_review_status' => true]);
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    $contactType = ContributorType::firstOrCreate(['slug' => 'ContactPerson'], ['name' => 'Contact Person', 'category' => ContributorCategory::PERSON]);
    $contributor = ResourceContributor::factory()->create([
        'resource_id' => $resource->id, 'contributorable_type' => Person::class,
        'contributorable_id' => Person::factory()->create()->id, 'email' => $recipient->email,
    ]);
    $contributor->contributorTypes()->sync([$contactType->id]);
    $curation = app(CurationService::class);
    $curation->start($resource, $actor);
    $notification = in_array($kind, ['mention', 'assignment'], true);
    if ($notification) {
        $event = $curation->event($resource, 'note', $actor, [], 'Please review this resource');
        DB::table('resource_curation_notifications')->insert(['event_id' => $event->id, 'user_id' => $recipient->id, 'type' => $kind]);
    }
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    if ($separateDatabase) {
        // Represents DB_QUEUE_CONNECTION, DB_QUEUE_TABLE and DB_QUEUE overrides.
        config([
            'database.connections.separate_queue' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'queue.connections.database.connection' => 'separate_queue',
            'queue.connections.database.table' => 'queue_jobs',
            'queue.connections.database.queue' => 'general',
        ]);
        Schema::connection('separate_queue')->create('queue_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $this->beforeApplicationDestroyed(fn () => DB::purge('separate_queue'));
    }
    config(['queue.default' => 'database', 'mail.default' => 'array']);
    $operation = (string) Str::uuid();
    $dispatch = function () use ($kind, $notification, $resource, $actor, $operation): void {
        if ($notification) {
            app(CurationNotificationService::class)->dispatch();
        } elseif ($kind === 'migration') {
            app(ResourceReviewLinkService::class)->queueMigration([$resource->id], 'curation@example.test', $actor, $operation);
        } else {
            app(ResourceReviewLinkService::class)->queue([$resource->id], $actor, 'curation@example.test', $operation);
        }
    };
    $receipt = fn () => $notification
        ? DB::table('resource_curation_notifications')->value('queued_at')
        : ResourceReviewLinkDispatch::where('operation_id', $operation)->value('queued_at');

    DB::beginTransaction();
    try {
        $dispatch();
        expect(DB::table('jobs')->where('queue', 'curation')->count())->toBe(1)
            ->and($receipt())->not->toBeNull()
            ->and(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    } finally {
        DB::rollBack();
    }
    expect(DB::table('jobs')->where('queue', 'curation')->count())->toBe(0)
        ->and($receipt())->toBeNull()
        ->and(ResourceCurationEvent::where('type', 'review.queued')->count())->toBe(0);

    $dispatch();
    $dispatch();
    $stored = DB::table('jobs')->where('queue', 'curation')->sole();
    expect(json_decode($stored->payload, true, flags: JSON_THROW_ON_ERROR)['displayName'])
        ->toBe($notification ? CurationNotification::class : ($kind === 'migration' ? ResourceReviewLinkMigration::class : ResourceReviewLink::class))
        ->and($receipt())->not->toBeNull()
        ->and(Queue::connection('database')->size())->toBe(0);
    if ($separateDatabase) {
        expect(DB::connection('separate_queue')->table('queue_jobs')->count())->toBe(0);
    }

    $this->artisan('queue:work', ['connection' => 'curation', '--queue' => 'curation', '--once' => true, '--force' => true, '--tries' => 3, '--timeout' => 60])->assertSuccessful();
    $messages = Mail::mailer()->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1)
        ->and($messages->first()->getOriginalMessage()->getTo()[0]->getAddress())->toBe($recipient->email)
        ->and(DB::table('jobs')->where('queue', 'curation')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($receipt())->not->toBeNull();
})->with(['mention', 'assignment', 'review', 'migration'])->with([false, true]);

it('records review evidence only after curation starts within mixed resource batches', function (bool $migration, string $initialization): void {
    config([
        'mail.default' => 'array',
        'mail.landing_page_contact_cc' => 'curation@example.test',
        'queue.default' => 'database',
        'queue.connections.database.connection' => config('database.default'),
        'queue.connections.database.queue' => 'legacy-review',
        'queue.connections.database.after_commit' => false,
    ]);
    $actor = User::factory()->curator()->create();
    $tracked = Resource::factory()->create(['doi' => null, 'force_review_status' => true]);
    $untracked = Resource::factory()->create(['doi' => null, 'force_review_status' => true]);
    $contactType = ContributorType::firstOrCreate(['slug' => 'ContactPerson'], ['name' => 'Contact Person', 'category' => ContributorCategory::PERSON]);
    foreach ([$tracked, $untracked] as $resource) {
        LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
        $contact = ResourceContributor::factory()->create([
            'resource_id' => $resource->id, 'contributorable_type' => Person::class,
            'contributorable_id' => Person::factory()->create()->id, 'email' => 'contact@example.test',
        ]);
        $contact->contributorTypes()->sync([$contactType->id]);
    }
    $curation = app(CurationService::class);
    $curation->start($tracked, $actor);
    $route = $migration ? 'resources.send-review-link-migrations' : 'resources.send-review-links';
    $this->actingAs($actor)->postJson(route($route), [
        'ids' => [$tracked->id, $untracked->id], 'operation_id' => (string) Str::uuid(),
    ])->assertOk()->assertJsonPath('queued_messages', 2)->assertJsonPath('failed_resources', []);

    expect($untracked->fresh()->curationState)->toBeNull()
        ->and(ResourceCurationEvent::where('resource_id', $untracked->id)->count())->toBe(0)
        ->and(ResourceReviewLinkDispatch::pluck('resource_id')->all())->toBe([$tracked->id])
        ->and(DB::table('jobs')->where('queue', 'legacy-review')->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'curation')->count())->toBe(1);
    foreach (DB::table('jobs')->get() as $job) {
        expect(json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR)['displayName'])
            ->toBe($migration ? ResourceReviewLinkMigration::class : ResourceReviewLink::class);
    }

    if ($initialization === 'backfill') {
        $this->artisan('curation:initialize', ['--apply' => true])->assertSuccessful();
    } else {
        $this->postJson('/resources/'.$untracked->id.'/curation/start')->assertOk();
    }
    expect($untracked->fresh()->curationState)->not->toBeNull()
        ->and($curation->automatic($untracked->fresh())['review_link_sent'])->toBeFalse()
        ->and($untracked->fresh()->checklistItems->firstWhere('item_key', 'review_link_sent')->state)->toBe('open')
        ->and(ResourceCurationEvent::where('resource_id', $untracked->id)->where('type', 'curation.started')->sole()->payload['snapshot']['automatic']['review_link_sent'])->toBeFalse();

    $payload = ['ids' => [$untracked->id], 'operation_id' => (string) Str::uuid()];
    $this->postJson(route($route), $payload)->assertOk()->assertJsonPath('queued_messages', 1);
    $this->postJson(route($route), $payload)->assertOk()->assertJsonPath('queued_messages', 0);
    $reviewItem = collect($curation->items($untracked->fresh()))->firstWhere('key', 'review_link_sent');
    expect($reviewItem['state'])->toBe('done')->and($reviewItem['observed'])->toBeFalse()
        ->and(ResourceReviewLinkDispatch::where('resource_id', $untracked->id)->count())->toBe(1)
        ->and(ResourceCurationEvent::where('resource_id', $untracked->id)->where('type', 'review.queued')->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'legacy-review')->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'curation')->count())->toBe(2);
})->with([false, true])->with(['explicit', 'backfill']);
