<?php

declare(strict_types=1);

use App\Enums\ContributorCategory;
use App\Enums\UserRole;
use App\Mail\ResourceReviewLink;
use App\Mail\ResourceReviewLinkMigration;
use App\Models\ContributorType;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCurationState;
use App\Models\ResourceReviewLinkDispatch;
use App\Models\User;
use App\Services\Curation\CurationOverviewService;
use App\Services\Curation\CurationService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('serves the dashboard and preserves user-index access before the curation migration', function (UserRole $role, string $route): void {
    $actor = User::factory()->create(['role' => $role]);
    $this->actingAs($actor);
    $migration = require database_path('migrations/2026_10_21_000001_create_resource_curation_tables.php');
    $migration->down();

    try {
        $response = $this->get(route($route));
        if ($route === 'dashboard') {
            $response->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')->where('curationCounts', ['open' => 0, 'waiting' => 0, 'help' => 0])
                ->has('recentResources')->has('dueEmbargos'));
        } elseif (in_array($role, [UserRole::ADMIN, UserRole::GROUP_LEADER], true)) {
            $response->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Users/Index')->has('users', 1)
                ->where('users.0.id', $actor->id)->where('users.0.open_curation_leads', 0)
                ->has('available_guided_tours'));
        } else {
            $response->assertForbidden();
        }
    } finally {
        $migration->up();
        if (DB::connection()->getDriverName() === 'mysql') {
            $actor->delete();
        }
    }
})->with(UserRole::cases())->with(['dashboard', 'users.index']);

it('returns zero counts for incomplete schemas and resumes live counts after completion', function (string $table, ?string $column): void {
    $actor = User::factory()->admin()->create();
    $colleague = User::factory()->curator()->create();
    $resource = Resource::factory()->create(['doi' => null]);
    app(CurationService::class)->start($resource, $actor);
    ResourceCurationState::whereKey($resource->id)->update([
        'waiting_for' => 'submitters', 'waiting_since' => now()->subDays(20), 'needs_help_text' => 'Please assist',
    ]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    app(ResourceListingProjectorService::class)->refreshMany([$resource->id]);
    $this->actingAs($actor);
    $overview = app(CurationOverviewService::class);

    $assertCounts = function (bool $ready) use ($actor, $colleague, $overview, $table, $column): void {
        $counts = $ready ? ['open' => 1, 'waiting' => 1, 'help' => 1] : ['open' => 0, 'waiting' => 0, 'help' => 0];
        expect($overview->widget($actor))->toBe($counts);
        // Recent dashboard resources already require the pre-existing listing table.
        if ($ready || $table !== 'resource_listing_projections' || $column !== null) {
            $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('curationCounts', $counts));
        }
        $this->get(route('users.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('users.0.id', $actor->id)->where('users.0.open_curation_leads', $ready ? 1 : 0)
            ->where('users.1.id', $colleague->id)->where('users.1.open_curation_leads', 0));
    };

    try {
        $assertCounts(true);
        if ($column === null) {
            Schema::rename($table, $table.'_unavailable');
        } else {
            Schema::table($table, fn (Blueprint $schema) => $schema->renameColumn($column, $column.'_unavailable'));
        }
        try {
            $assertCounts(false);
        } finally {
            if ($column === null) {
                Schema::rename($table.'_unavailable', $table);
            } else {
                Schema::table($table, fn (Blueprint $schema) => $schema->renameColumn($column.'_unavailable', $column));
            }
        }
        $assertCounts(true);
    } finally {
        // MySQL DDL commits the surrounding test transaction; remove committed fixtures.
        if (DB::connection()->getDriverName() === 'mysql') {
            $resource->delete();
            User::whereIn('id', [$actor->id, $colleague->id])->delete();
        }
    }
})->with([
    'missing curation states' => ['resource_curation_states', null],
    'missing curator assignments' => ['resource_curators', null],
    'missing listing projections' => ['resource_listing_projections', null],
    'missing started flag' => ['resource_listing_projections', 'curation_started'],
    'missing open flag' => ['resource_listing_projections', 'curation_open'],
    'missing waiting timestamp' => ['resource_listing_projections', 'curation_waiting_since'],
    'missing help flag' => ['resource_listing_projections', 'curation_needs_help'],
]);

it('gates workflow endpoints while preserving legacy review mail until the schema is complete', function (?string $table, ?string $column): void {
    config([
        'mail.landing_page_contact_cc' => 'curation@example.test',
        'queue.default' => 'database',
        'queue.connections.database.connection' => config('database.default'),
        'queue.connections.database.queue' => 'rollout-legacy',
        'queue.connections.database.after_commit' => false,
    ]);
    $actor = User::factory()->curator()->create();
    $resource = Resource::factory()->create(['doi' => null, 'force_review_status' => true]);
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    $person = Person::factory()->create();
    $contact = ResourceContributor::factory()->create([
        'resource_id' => $resource->id, 'contributorable_type' => Person::class,
        'contributorable_id' => $person->id, 'email' => 'contact@example.test',
    ]);
    $contactType = ContributorType::firstOrCreate(['slug' => 'ContactPerson'], ['name' => 'Contact Person', 'category' => ContributorCategory::PERSON]);
    $contact->contributorTypes()->sync([$contactType->id]);
    $service = app(CurationService::class);
    $service->start($resource, $actor);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    $this->actingAs($actor);
    $base = '/resources/'.$resource->id.'/curation';
    $migration = require database_path('migrations/2026_10_21_000001_create_resource_curation_tables.php');

    try {
        if ($table === null) {
            $migration->down();
        } elseif ($column === null) {
            Schema::rename($table, $table.'_unavailable');
        } else {
            Schema::table($table, fn (Blueprint $schema) => $schema->renameColumn($column, $column.'_unavailable'));
        }
        try {
            expect($service->available())->toBeFalse();
            expect(app(CurationOverviewService::class)->widget($actor))->toBe(['open' => 0, 'waiting' => 0, 'help' => 0]);
            $this->get('/curation')->assertStatus(503);
            foreach (['/curation/export', $base, $base.'/events'] as $path) {
                $this->getJson($path)->assertStatus(503)
                    ->assertJsonPath('message', 'Curation is temporarily unavailable. Please try again later.');
            }
            $this->postJson($base.'/start')->assertStatus(503);
            $this->patchJson($base.'/state', ['revision' => 0, 'needs_help_text' => 'Please help'])->assertStatus(503);
            expect(fn () => $service->panel($resource->fresh(), $actor))->toThrow(HttpException::class);

            // Background projection refreshes must also avoid partial curation graphs.
            if ($table !== 'resource_listing_projections' || $column !== null) {
                app(ResourceListingProjectorService::class)->refresh($resource->id);
                app(ResourceListingProjectorService::class)->refreshMany([$resource->id]);
                app(ResourceListingProjectionRefreshService::class)->schedule($resource->id);
                app(ResourceListingProjectionRefreshService::class)->flushPending();
            }

            foreach (['resources.send-review-links', 'resources.send-review-link-migrations'] as $route) {
                $this->postJson(route($route), ['ids' => [$resource->id]])->assertOk()
                    ->assertJsonPath('queued_messages', 1)->assertJsonPath('failed_resources', [])
                    ->assertJsonPath('skipped_recipients_count', 0);
            }
            $jobs = DB::table('jobs')->where('queue', 'rollout-legacy')->get();
            expect($jobs)->toHaveCount(2);
            expect($jobs->map(fn ($job): string => json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR)['displayName'])->all())
                ->toBe([ResourceReviewLink::class, ResourceReviewLinkMigration::class]);
            expect(DB::table('jobs')->where('queue', 'curation')->count())->toBe(0);
        } finally {
            if ($table === null) {
                $migration->up();
            } elseif ($column === null) {
                Schema::rename($table.'_unavailable', $table);
            } else {
                Schema::table($table, fn (Blueprint $schema) => $schema->renameColumn($column.'_unavailable', $column));
            }
        }

        // The same service instance must recover without a restart or stale cache.
        expect($service->available())->toBeTrue();
        expect(ResourceReviewLinkDispatch::count())->toBe(0);
        $service->start($resource->fresh(), $actor);
        $this->get('/curation?tab=all')->assertOk()->assertInertia(fn (Assert $page) => $page->where('resources.total', 1));
        $this->getJson($base)->assertOk()->assertJsonPath('started', true)->assertJsonPath('can_write', true);
        $this->getJson($base.'/events')->assertOk();
        $this->get('/curation/export')->assertOk()->assertDownload('curation.csv');
        foreach (['resources.send-review-links', 'resources.send-review-link-migrations'] as $route) {
            $operation = (string) Str::uuid();
            $this->postJson(route($route), ['ids' => [$resource->id], 'operation_id' => $operation])->assertOk()->assertJsonPath('queued_messages', 1);
            $this->postJson(route($route), ['ids' => [$resource->id], 'operation_id' => $operation])->assertOk()->assertJsonPath('queued_messages', 0);
        }
        expect(ResourceReviewLinkDispatch::count())->toBe(2)
            ->and(DB::table('jobs')->where('queue', 'curation')->count())->toBe(2)
            ->and(DB::table('resource_curation_events')->where('type', 'review.queued')->count())->toBe(2);
    } finally {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::table('jobs')->whereIn('queue', ['rollout-legacy', 'curation'])->delete();
            $resource->delete();
            $person->delete();
            $actor->delete();
        }
    }
})->with([
    'before migration' => [null, null],
    'missing state' => ['resource_curation_states', null],
    'missing assignments' => ['resource_curators', null],
    'missing checklist' => ['resource_checklist_items', null],
    'missing events' => ['resource_curation_events', null],
    'missing dispatch receipts' => ['resource_review_link_dispatches', null],
    'missing registration evidence' => ['resource_doi_registration_evidence', null],
    'missing locations' => ['resource_data_locations', null],
    'missing mentions' => ['resource_curation_mentions', null],
    'missing notifications' => ['resource_curation_notifications', null],
    'missing projections' => ['resource_listing_projections', null],
    'missing started' => ['resource_listing_projections', 'curation_started'],
    'missing progress' => ['resource_listing_projections', 'curation_progress'],
    'missing manual open' => ['resource_listing_projections', 'curation_manual_open'],
    'missing open' => ['resource_listing_projections', 'curation_open'],
    'missing activity' => ['resource_listing_projections', 'curation_activity_at'],
    'missing publication' => ['resource_listing_projections', 'curation_published_at'],
    'missing waiting' => ['resource_listing_projections', 'curation_waiting_since'],
    'missing help' => ['resource_listing_projections', 'curation_needs_help'],
    'missing deadline' => ['resource_listing_projections', 'curation_deadline'],
    'missing contact' => ['resource_listing_projections', 'curation_contact'],
]);
