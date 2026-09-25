<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\DateType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceListingProjection;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('the embargo migration rebuilds existing listing statuses and published ranks', function (): void {
    $available = DateType::firstOrCreate(['slug' => 'Available'], ['name' => 'Available', 'is_active' => true]);
    $embargoed = Resource::factory()->create(['doi' => null, 'access_level' => AccessLevel::EMBARGOED]);
    LandingPage::factory()->draft()->create(['resource_id' => $embargoed->id]);
    $embargoed->dates()->create(['date_type_id' => $available->id, 'date_value' => '2027-01-01']);

    $range = Resource::factory()->create(['doi' => null, 'access_level' => AccessLevel::EMBARGOED]);
    LandingPage::factory()->draft()->create(['resource_id' => $range->id]);
    $range->dates()->create([
        'date_type_id' => $available->id,
        'start_date' => '2027-01-01',
        'end_date' => '2027-01-31',
    ]);

    $published = Resource::factory()->create(['doi' => '10.83279/already-published']);
    LandingPage::factory()->published()->create(['resource_id' => $published->id]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    DB::table('resource_listing_projections')->where('resource_id', $embargoed->id)->update([
        'workflow_status' => 'curation',
        'workflow_status_rank' => 1,
    ]);
    DB::table('resource_listing_projections')->where('resource_id', $published->id)->update([
        'workflow_status_rank' => 3,
    ]);

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_24_000001_add_embargo_registration_attempt_to_resources.php');
    $migration->up();

    expect(Schema::hasColumn('resources', 'embargo_registration_started_at'))->toBeTrue()
        ->and(Schema::hasColumn('resources', 'embargo_registration_prefix'))->toBeTrue()
        ->and(ResourceListingProjection::query()->findOrFail($embargoed->id)->workflow_status)->toBe('embargo')
        ->and(ResourceListingProjection::query()->findOrFail($embargoed->id)->workflow_status_rank)->toBe(3)
        ->and(ResourceListingProjection::query()->findOrFail($range->id)->workflow_status)->not->toBe('embargo')
        ->and(ResourceListingProjection::query()->findOrFail($published->id)->workflow_status)->toBe('published')
        ->and(ResourceListingProjection::query()->findOrFail($published->id)->workflow_status_rank)->toBe(4);
});
