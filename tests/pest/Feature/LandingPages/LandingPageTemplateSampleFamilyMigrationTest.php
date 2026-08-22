<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;

uses()->group('landing-page-templates');

function issue1127SampleFamilyMigration(): object
{
    return require database_path('migrations/2026_08_21_000001_add_sample_family_to_igsn_landing_page_templates.php');
}

it('adds sample family directly after general only for IGSN templates', function (): void {
    $igsnTemplate = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => ['contact', 'general', 'repositories', 'acquisition'],
    ]);
    $resourceTemplate = LandingPageTemplate::factory()->create([
        'left_column_order' => ['contact', 'general', 'files'],
    ]);

    issue1127SampleFamilyMigration()->up();

    expect($igsnTemplate->fresh()->left_column_order)->toBe([
        'contact',
        'general',
        'sample_family',
        'repositories',
        'acquisition',
    ])->and($resourceTemplate->fresh()->left_column_order)->toBe([
        'contact',
        'general',
        'files',
    ]);
});

it('normalizes duplicate entries and removes the section on rollback', function (): void {
    $template = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => ['sample_family', 'general', 'sample_family', 'contact'],
    ]);
    $migration = issue1127SampleFamilyMigration();

    $migration->up();

    expect($template->fresh()->left_column_order)->toBe([
        'general',
        'sample_family',
        'contact',
    ]);

    $migration->down();

    expect($template->fresh()->left_column_order)->toBe([
        'general',
        'contact',
    ]);
});
