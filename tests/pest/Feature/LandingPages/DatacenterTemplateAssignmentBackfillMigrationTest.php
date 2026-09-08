<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function loadDatacenterTemplateAssignmentBackfillMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_09_08_000001_backfill_default_landing_page_template_assignments.php'
    );

    return $migration;
}

it('backfills only missing resource and IGSN datacenter template assignments', function (): void {
    $migration = loadDatacenterTemplateAssignmentBackfillMigration();
    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();
    $customResource = LandingPageTemplate::factory()->create();
    $customIgsn = LandingPageTemplate::factory()->igsn()->create();
    $bothMissing = Datacenter::factory()->create();
    $resourceMissing = Datacenter::factory()->create(['igsn_landing_page_template_id' => $customIgsn->id]);
    $igsnMissing = Datacenter::factory()->create(['landing_page_template_id' => $customResource->id]);
    $customAssignments = Datacenter::factory()->create([
        'landing_page_template_id' => $customResource->id,
        'igsn_landing_page_template_id' => $customIgsn->id,
    ]);

    DB::table('datacenters')->where('id', $bothMissing->id)->update([
        'landing_page_template_id' => null,
        'igsn_landing_page_template_id' => null,
    ]);
    DB::table('datacenters')->where('id', $resourceMissing->id)->update(['landing_page_template_id' => null]);
    DB::table('datacenters')->where('id', $igsnMissing->id)->update(['igsn_landing_page_template_id' => null]);

    $migration->up();

    expect($bothMissing->fresh()?->landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id)
        ->and($bothMissing->fresh()?->igsn_landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id)
        ->and($resourceMissing->fresh()?->landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id)
        ->and($resourceMissing->fresh()?->igsn_landing_page_template_id)->toBe($customIgsn->id)
        ->and($igsnMissing->fresh()?->landing_page_template_id)->toBe($customResource->id)
        ->and($igsnMissing->fresh()?->igsn_landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id)
        ->and($customAssignments->fresh()?->landing_page_template_id)->toBe($customResource->id)
        ->and($customAssignments->fresh()?->igsn_landing_page_template_id)->toBe($customIgsn->id);

    $migration->down();

    expect($bothMissing->fresh()?->landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id)
        ->and($bothMissing->fresh()?->igsn_landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id);
});

it('restores a missing built-in template before backfilling assignments', function (): void {
    $migration = loadDatacenterTemplateAssignmentBackfillMigration();
    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();
    $datacenter = Datacenter::factory()->create();

    DB::table('datacenters')->where('id', $datacenter->id)->update([
        'igsn_landing_page_template_id' => null,
    ]);

    $defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->delete();
    expect(LandingPageTemplate::query()->where('slug', LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG)->exists())->toBeFalse();

    $migration->up();

    $restoredIgsnTemplate = LandingPageTemplate::query()
        ->where('slug', LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG)
        ->firstOrFail();

    expect($restoredIgsnTemplate->is_default)->toBeTrue()
        ->and($restoredIgsnTemplate->template_type)->toBe(LandingPageTemplate::TEMPLATE_TYPE_IGSN)
        ->and($datacenter->fresh()?->landing_page_template_id)->toBe($defaults[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id)
        ->and($datacenter->fresh()?->igsn_landing_page_template_id)->toBe($restoredIgsnTemplate->id);
});
