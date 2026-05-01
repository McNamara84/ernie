<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;

uses()->group('landing-page-templates');

function expandedRightColumnOrder(): array
{
    return LandingPageTemplate::RIGHT_COLUMN_SECTIONS;
}

function expandedLocationFirstRightColumnOrder(): array
{
    return [
        'location',
        ...array_values(array_filter(
            LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
            static fn (string $key): bool => $key !== 'location',
        )),
    ];
}

function runDescriptionSectionsMigration(): void
{
    $migration = require database_path('migrations/2026_05_01_000001_expand_landing_page_template_description_sections.php');
    $migration->up();
}

it('expands the legacy descriptions slot while preserving trailing location placement', function (): void {
    $template = LandingPageTemplate::factory()->create([
        'right_column_order' => ['descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download', 'location'],
    ]);

    runDescriptionSectionsMigration();

    expect($template->fresh()->right_column_order)->toBe(expandedRightColumnOrder());
});

it('expands the legacy descriptions slot while preserving leading location placement', function (): void {
    $template = LandingPageTemplate::factory()->create([
        'right_column_order' => ['location', 'descriptions', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'],
    ]);

    runDescriptionSectionsMigration();

    expect($template->fresh()->right_column_order)->toBe(expandedLocationFirstRightColumnOrder());
});