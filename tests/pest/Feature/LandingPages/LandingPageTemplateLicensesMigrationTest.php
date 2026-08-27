<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;

uses()->group('landing-page-templates');

function issues1185And1186LicensesMigration(): object
{
    return require database_path('migrations/2026_08_26_000003_add_licenses_to_landing_page_templates.php');
}

it('adds licenses after the template-specific access section', function (): void {
    $resourceTemplate = LandingPageTemplate::factory()->create([
        'left_column_order' => ['contact', 'files', 'citation', 'related_work'],
    ]);
    $igsnTemplate = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => ['general', 'repositories', 'citation', 'contact'],
    ]);

    issues1185And1186LicensesMigration()->up();

    expect($resourceTemplate->fresh()->left_column_order)->toBe([
        'contact',
        'files',
        'licenses',
        'citation',
        'related_work',
    ])->and($igsnTemplate->fresh()->left_column_order)->toBe([
        'general',
        'repositories',
        'licenses',
        'citation',
        'contact',
    ]);
});

it('falls back before citation and is idempotent for existing license entries', function (): void {
    $withoutAnchor = LandingPageTemplate::factory()->create([
        'left_column_order' => ['contact', 'citation', 'related_work'],
    ]);
    $alreadyPresent = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => ['licenses', 'general', 'repositories', 'licenses', 'contact'],
    ]);
    $migration = issues1185And1186LicensesMigration();

    $migration->up();
    $migration->up();

    expect($withoutAnchor->fresh()->left_column_order)->toBe([
        'contact',
        'licenses',
        'citation',
        'related_work',
    ])->and($alreadyPresent->fresh()->left_column_order)->toBe([
        'general',
        'repositories',
        'licenses',
        'contact',
    ]);
});

it('removes only the licenses section on rollback', function (): void {
    $template = LandingPageTemplate::factory()->create([
        'left_column_order' => ['files', 'licenses', 'contact'],
    ]);
    $migration = issues1185And1186LicensesMigration();

    $migration->down();

    expect($template->fresh()->left_column_order)->toBe(['files', 'contact']);
});
