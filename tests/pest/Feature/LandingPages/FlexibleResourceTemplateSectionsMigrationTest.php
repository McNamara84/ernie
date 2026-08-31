<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;

uses()->group('landing-page-templates');

function flexibleResourceTemplateSectionsMigration(): object
{
    return require database_path('migrations/2026_08_31_000001_enable_flexible_resource_template_sections.php');
}

it('normalizes Resource templates while preserving clean cross-column ownership and IGSN rows', function (): void {
    $builtIn = LandingPageTemplate::query()
        ->where('slug', LandingPageTemplate::DEFAULT_TEMPLATE_SLUG)
        ->firstOrFail();
    $builtIn->update([
        'left_column_order' => ['location'],
        'right_column_order' => ['files'],
    ]);
    $crossColumnLeft = ['location', ...LandingPageTemplate::RESOURCE_LEFT_COLUMN_SECTIONS];
    $crossColumnRight = array_values(array_filter(
        LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
        static fn (string $key): bool => $key !== 'location',
    ));
    $custom = LandingPageTemplate::factory()->create([
        'left_column_order' => $crossColumnLeft,
        'right_column_order' => $crossColumnRight,
    ]);
    $igsnLeft = ['general', 'location'];
    $igsnRight = ['abstract', 'sample_image'];
    $igsn = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => $igsnLeft,
        'right_column_order' => $igsnRight,
    ]);

    flexibleResourceTemplateSectionsMigration()->up();

    expect($builtIn->fresh()->left_column_order)->toBe(LandingPageTemplate::RESOURCE_LEFT_COLUMN_SECTIONS)
        ->and($builtIn->fresh()->right_column_order)->toBe(LandingPageTemplate::RIGHT_COLUMN_SECTIONS)
        ->and($custom->fresh()->left_column_order)->toBe($crossColumnLeft)
        ->and($custom->fresh()->right_column_order)->toBe($crossColumnRight)
        ->and($igsn->fresh()->left_column_order)->toBe($igsnLeft)
        ->and($igsn->fresh()->right_column_order)->toBe($igsnRight);
});

it('expands legacy metadata and repairs malformed Resource layouts idempotently', function (): void {
    $template = LandingPageTemplate::factory()->create([
        'left_column_order' => ['location', 'descriptions', 'files', 'files', 'unknown'],
        'right_column_order' => ['contact', 'abstract', 'general'],
    ]);
    $migration = flexibleResourceTemplateSectionsMigration();

    $migration->up();
    $template->refresh();
    $firstLeft = $template->left_column_order;
    $firstRight = $template->right_column_order;

    expect(LandingPageTemplate::isValidResourceSectionLayout($firstLeft, $firstRight))->toBeTrue()
        ->and(collect([...$firstLeft, ...$firstRight])->duplicates()->all())->toBe([])
        ->and($firstLeft[0])->toBe('location')
        ->and($firstLeft)->toContain('abstract', 'methods', 'files', 'licenses')
        ->and($firstRight[0])->toBe('contact')
        ->and([...$firstLeft, ...$firstRight])->not->toContain('unknown', 'general', 'descriptions');

    $migration->up();

    expect($template->fresh()->left_column_order)->toBe($firstLeft)
        ->and($template->fresh()->right_column_order)->toBe($firstRight);
});

it('keeps intentional cross-column Resource ownership on rollback', function (): void {
    $left = ['abstract', ...LandingPageTemplate::RESOURCE_LEFT_COLUMN_SECTIONS];
    $right = array_values(array_filter(
        LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
        static fn (string $key): bool => $key !== 'abstract',
    ));
    $template = LandingPageTemplate::factory()->create([
        'left_column_order' => $left,
        'right_column_order' => $right,
    ]);

    flexibleResourceTemplateSectionsMigration()->down();

    expect($template->fresh()->left_column_order)->toBe($left)
        ->and($template->fresh()->right_column_order)->toBe($right);
});
