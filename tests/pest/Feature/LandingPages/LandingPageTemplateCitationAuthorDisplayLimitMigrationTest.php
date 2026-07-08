<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function loadLandingPageTemplateCitationAuthorDisplayLimitMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_06_26_000002_add_citation_author_display_limit_to_landing_page_templates.php');

    return $migration;
}

it('adds and removes the landing page template citation author display limit column', function (): void {
    $migration = loadLandingPageTemplateCitationAuthorDisplayLimitMigration();

    expect(Schema::hasColumn('landing_page_templates', 'citation_author_display_limit'))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('landing_page_templates', 'citation_author_display_limit'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('landing_page_templates', 'citation_author_display_limit'))->toBeTrue();

    $template = LandingPageTemplate::factory()->create();

    expect($template->citation_author_display_limit)->toBe(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT);
});

it('can be rerun safely when the citation author display limit column already matches the desired state', function (): void {
    $migration = loadLandingPageTemplateCitationAuthorDisplayLimitMigration();

    $migration->up();

    expect(Schema::hasColumn('landing_page_templates', 'citation_author_display_limit'))->toBeTrue();

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('landing_page_templates', 'citation_author_display_limit'))->toBeFalse();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('landing_page_templates', 'citation_author_display_limit'))->toBeTrue();
});
