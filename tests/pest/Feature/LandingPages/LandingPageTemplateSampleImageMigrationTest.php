<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;

uses()->group('landing-page-templates');

function issue1168TemplateMigration(): object
{
    return require database_path('migrations/2026_08_27_000002_enable_flexible_igsn_template_sections.php');
}

it('keeps the superseded flexible-column migration safe on the current schema', function (): void {
    $igsn = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => ['contact', 'general'],
        'right_column_order' => ['abstract', 'location'],
    ]);
    $resource = LandingPageTemplate::factory()->create([
        'left_column_order' => LandingPageTemplate::RESOURCE_LEFT_COLUMN_SECTIONS,
        'right_column_order' => LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
    ]);

    issue1168TemplateMigration()->up();
    $igsn->refresh();
    $resource->refresh();

    expect($igsn->left_column_order)->toBe(['contact', 'general'])
        ->and($igsn->right_column_order)->toBe(['version_notice', 'abstract', 'location'])
        ->and($resource->right_column_order)->toBe(LandingPageTemplate::RIGHT_COLUMN_SECTIONS);
});

it('deduplicates known modules and remains reversible after the layout supersession', function (): void {
    $template = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => ['general', 'location', 'general'],
        'right_column_order' => ['location', 'sample_image', 'abstract', 'unknown'],
    ]);
    $migration = issue1168TemplateMigration();

    $migration->up();
    $template->refresh();
    expect(collect([...$template->left_column_order, ...$template->right_column_order])->duplicates()->all())->toBe([])
        ->and($template->right_column_order[0])->toBe('version_notice');

    $template->update([
        'left_column_order' => [
            'abstract',
            ...array_values(array_filter(
                $template->left_column_order,
                static fn (string $key): bool => $key !== 'general',
            )),
        ],
        'right_column_order' => [
            'general',
            ...array_values(array_filter(
                $template->right_column_order,
                static fn (string $key): bool => $key !== 'abstract',
            )),
        ],
    ]);

    $migration->down();
    $template->refresh();

    expect($template->left_column_order)->not->toContain('abstract', 'sample_image')
        ->and($template->right_column_order)->not->toContain('general', 'sample_image', 'version_notice')
        ->and(collect([...$template->left_column_order, ...$template->right_column_order])->duplicates()->all())->toBe([]);
});
