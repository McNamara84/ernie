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

function newDescriptionSectionsMigration(): object
{
    return require database_path('migrations/2026_05_01_000001_expand_landing_page_template_description_sections.php');
}

function invokeMigrationHelper(object $migration, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($migration, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($migration, $arguments);
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

it('decodes stored orders defensively', function (): void {
    $migration = newDescriptionSectionsMigration();

    expect(invokeMigrationHelper($migration, 'decodeOrder', ['location', 123, 'descriptions']))
        ->toBe(['location', 'descriptions'])
        ->and(invokeMigrationHelper($migration, 'decodeOrder', ''))
        ->toBe([])
        ->and(invokeMigrationHelper($migration, 'decodeOrder', 'not-json'))
        ->toBe([])
        ->and(invokeMigrationHelper($migration, 'decodeOrder', '["abstract",1,"location"]'))
        ->toBe(['abstract', 'location']);
});

it('normalizes malformed legacy orders by dropping unknown keys and appending missing modules', function (): void {
    $migration = newDescriptionSectionsMigration();

    $normalized = invokeMigrationHelper($migration, 'normalizeRightOrder', ['unknown', 'descriptions', 'keywords', 'keywords', 'location']);

    expect($normalized)->toBe([
        'abstract',
        'methods',
        'technical_info',
        'series_information',
        'table_of_contents',
        'other',
        'keywords',
        'creators',
        'contributors',
        'funders',
        'metadata_download',
        'location',
    ]);
});

it('falls back to the canonical trailing-location order for malformed payloads', function (): void {
    $migration = newDescriptionSectionsMigration();

    $decoded = invokeMigrationHelper($migration, 'decodeOrder', '{"unexpected":true}');
    $normalized = invokeMigrationHelper($migration, 'normalizeRightOrder', $decoded);

    expect($normalized)->toBe(expandedRightColumnOrder());
});