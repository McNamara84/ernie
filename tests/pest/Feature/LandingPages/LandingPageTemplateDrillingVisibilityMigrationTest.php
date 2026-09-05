<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function loadLandingPageTemplateDrillingVisibilityMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_05_000001_add_igsn_drilling_visibility_to_landing_page_templates.php');

    return $migration;
}

it('adds a default-enabled boolean Drilling visibility setting and removes it on rollback', function (): void {
    $migration = loadLandingPageTemplateDrillingVisibilityMigration();
    $resourceTemplate = LandingPageTemplate::factory()->create(['show_igsn_drilling' => false]);
    $igsnTemplate = LandingPageTemplate::factory()->igsn()->create(['show_igsn_drilling' => false]);

    expect(Schema::hasColumn('landing_page_templates', 'show_igsn_drilling'))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('landing_page_templates', 'show_igsn_drilling'))->toBeFalse();

    $migration->up();

    $resourceTemplate->refresh();
    $igsnTemplate->refresh();

    expect(Schema::hasColumn('landing_page_templates', 'show_igsn_drilling'))->toBeTrue()
        ->and($resourceTemplate->show_igsn_drilling)->toBeTrue()
        ->and($igsnTemplate->show_igsn_drilling)->toBeTrue();
});

it('can be rerun safely when the Drilling visibility column already matches the desired state', function (): void {
    $migration = loadLandingPageTemplateDrillingVisibilityMigration();

    $migration->up();

    expect(Schema::hasColumn('landing_page_templates', 'show_igsn_drilling'))->toBeTrue();

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('landing_page_templates', 'show_igsn_drilling'))->toBeFalse();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('landing_page_templates', 'show_igsn_drilling'))->toBeTrue();
});
