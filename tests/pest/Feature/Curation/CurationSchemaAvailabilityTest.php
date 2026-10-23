<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\ResourceCurationState;
use App\Models\User;
use App\Services\Curation\CurationOverviewService;
use App\Services\Curation\CurationService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

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
