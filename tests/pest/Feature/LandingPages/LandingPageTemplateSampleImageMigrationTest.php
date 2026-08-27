<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;

uses()->group('landing-page-templates');

function issue1168TemplateMigration(): object
{
    return require database_path('migrations/2026_08_27_000002_enable_flexible_igsn_template_sections.php');
}

it('adds the sample image before location and completes only IGSN layouts', function (): void {
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

    expect(LandingPageTemplate::isValidIgsnSectionLayout(
        $igsn->left_column_order,
        $igsn->right_column_order,
    ))->toBeTrue()
        ->and(array_search('sample_image', $igsn->right_column_order, true))
        ->toBe(array_search('location', $igsn->right_column_order, true) - 1)
        ->and($igsn->left_column_order[0])->toBe('contact')
        ->and($igsn->left_column_order[1])->toBe('general')
        ->and($resource->right_column_order)->toBe(LandingPageTemplate::RIGHT_COLUMN_SECTIONS);
});

it('deduplicates known IGSN modules and rollback restores pre-migration column ownership', function (): void {
    $template = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => ['general', 'location', 'general'],
        'right_column_order' => ['location', 'sample_image', 'abstract', 'unknown'],
    ]);
    $migration = issue1168TemplateMigration();

    $migration->up();
    $template->refresh();
    expect(LandingPageTemplate::isValidIgsnSectionLayout(
        $template->left_column_order,
        $template->right_column_order,
    ))->toBeTrue()
        ->and(collect([...$template->left_column_order, ...$template->right_column_order])->duplicates()->all())->toBe([]);

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

    expect($template->left_column_order)->toHaveCount(count(LandingPageTemplate::IGSN_LEFT_COLUMN_SECTIONS))
        ->and(array_diff(LandingPageTemplate::IGSN_LEFT_COLUMN_SECTIONS, $template->left_column_order))->toBe([])
        ->and($template->left_column_order)->not->toContain('abstract', 'sample_image')
        ->and($template->right_column_order)->toHaveCount(count(LandingPageTemplate::RIGHT_COLUMN_SECTIONS))
        ->and(array_diff(LandingPageTemplate::RIGHT_COLUMN_SECTIONS, $template->right_column_order))->toBe([])
        ->and($template->right_column_order)->not->toContain('general', 'sample_image');
});
