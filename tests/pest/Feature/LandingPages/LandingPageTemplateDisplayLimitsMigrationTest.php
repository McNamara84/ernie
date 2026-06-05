<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function loadLandingPageTemplateDisplayLimitsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_06_04_000001_add_display_limits_to_landing_page_templates.php');

    return $migration;
}

it('adds and removes landing page template display limit columns', function (): void {
    $migration = loadLandingPageTemplateDisplayLimitsMigration();

    expect(Schema::hasColumns('landing_page_templates', [
        'creator_display_limit',
        'contributor_display_limit',
    ]))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('landing_page_templates', 'creator_display_limit'))->toBeFalse()
        ->and(Schema::hasColumn('landing_page_templates', 'contributor_display_limit'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns('landing_page_templates', [
        'creator_display_limit',
        'contributor_display_limit',
    ]))->toBeTrue();

    $template = LandingPageTemplate::factory()->create();

    expect($template->creator_display_limit)->toBe(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT)
        ->and($template->contributor_display_limit)->toBe(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT);
});

it('can be rerun safely when display limit columns already match the desired state', function (): void {
    $migration = loadLandingPageTemplateDisplayLimitsMigration();

    $migration->up();

    expect(Schema::hasColumns('landing_page_templates', [
        'creator_display_limit',
        'contributor_display_limit',
    ]))->toBeTrue();

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('landing_page_templates', 'creator_display_limit'))->toBeFalse()
        ->and(Schema::hasColumn('landing_page_templates', 'contributor_display_limit'))->toBeFalse();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumns('landing_page_templates', [
        'creator_display_limit',
        'contributor_display_limit',
    ]))->toBeTrue();
});
