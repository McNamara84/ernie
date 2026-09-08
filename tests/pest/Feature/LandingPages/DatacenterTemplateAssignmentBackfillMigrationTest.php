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

it('refuses to backfill assignments when a built-in template is missing', function (): void {
    $migration = loadDatacenterTemplateAssignmentBackfillMigration();
    $defaults = LandingPageTemplate::ensureSystemTemplatesExist();

    $defaults[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->delete();

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Both built-in landing-page templates must exist');
});
