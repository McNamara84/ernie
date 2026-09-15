<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

uses()->group('landing-page-templates');

const ISSUE_1168_LEGACY_LEFT_SECTIONS = [
    'general', 'sample_family', 'acquisition', 'igsn_methods', 'igsn_drilling',
    'repositories', 'licenses', 'citation', 'dates', 'contact',
    'model_description', 'related_work',
];

const ISSUE_1168_LEGACY_RIGHT_SECTIONS = [
    'abstract', 'methods', 'technical_info', 'series_information',
    'table_of_contents', 'other', 'creators', 'contributors', 'funders',
    'keywords', 'metadata_download', 'location',
];

const ISSUE_1168_FLEXIBLE_RIGHT_SECTIONS = [
    'abstract', 'methods', 'technical_info', 'series_information',
    'table_of_contents', 'other', 'creators', 'contributors', 'funders',
    'keywords', 'metadata_download', 'sample_image', 'location',
];

function issue1168TemplateMigration(): object
{
    return require database_path('migrations/2026_08_27_000002_enable_flexible_igsn_template_sections.php');
}

function issue1309TemplateMigrationForLegacyUpgrade(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_14_000001_restructure_igsn_landing_page_templates.php');

    return $migration;
}

it('preserves omitted modules while upgrading a sparse legacy layout through the three-zone migration', function (): void {
    $igsn = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => ['contact', 'general'],
        'right_column_order' => ['abstract', 'location'],
    ]);
    $resource = LandingPageTemplate::factory()->create([
        'left_column_order' => LandingPageTemplate::RESOURCE_LEFT_COLUMN_SECTIONS,
        'right_column_order' => LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
    ]);
    $layoutMigration = issue1309TemplateMigrationForLegacyUpgrade();
    $layoutMigration->down();

    DB::table('landing_page_templates')->where('id', $igsn->id)->update([
        'left_column_order' => json_encode(['contact', 'general'], JSON_THROW_ON_ERROR),
        'right_column_order' => json_encode(['abstract', 'location'], JSON_THROW_ON_ERROR),
    ]);

    issue1168TemplateMigration()->up();
    $igsn->refresh();
    $resource->refresh();
    $legacyLeft = $igsn->left_column_order;
    $legacyRight = $igsn->right_column_order;

    $layoutMigration->up();
    $igsn->refresh();
    $currentSections = [...$igsn->left_column_order, ...$igsn->right_column_order, ...$igsn->hidden_sections];

    expect($legacyLeft[0])->toBe('contact')
        ->and($legacyLeft[1])->toBe('general')
        ->and($legacyLeft)->toHaveCount(count(ISSUE_1168_LEGACY_LEFT_SECTIONS))
        ->and(array_diff(ISSUE_1168_LEGACY_LEFT_SECTIONS, $legacyLeft))->toBe([])
        ->and(array_diff($legacyLeft, ISSUE_1168_LEGACY_LEFT_SECTIONS))->toBe([])
        ->and($legacyRight)->toHaveCount(count(ISSUE_1168_FLEXIBLE_RIGHT_SECTIONS))
        ->and(array_diff(ISSUE_1168_FLEXIBLE_RIGHT_SECTIONS, $legacyRight))->toBe([])
        ->and(array_diff($legacyRight, ISSUE_1168_FLEXIBLE_RIGHT_SECTIONS))->toBe([])
        ->and(array_search('sample_image', $legacyRight, true))->toBe(array_search('location', $legacyRight, true) - 1)
        ->and(collect([...$legacyLeft, ...$legacyRight])->duplicates()->all())->toBe([])
        ->and($resource->right_column_order)->toBe(LandingPageTemplate::RIGHT_COLUMN_SECTIONS)
        ->and($currentSections)->toHaveCount(count(LandingPageTemplate::IGSN_SECTIONS))
        ->and(array_diff(LandingPageTemplate::IGSN_SECTIONS, $currentSections))->toBe([])
        ->and(collect($currentSections)->duplicates()->all())->toBe([])
        ->and($igsn->right_column_order)->toContain('sample_image')
        ->and($igsn->hidden_sections)->not->toContain('sample_image')
        ->and(array_search('sample_image', $igsn->right_column_order, true))->toBe(array_search('location', $igsn->right_column_order, true) - 1);

    $layoutMigration->down();
    $igsn->refresh();

    expect($igsn->right_column_order)->toContain('sample_image')
        ->and(array_search('sample_image', $igsn->right_column_order, true))->toBe(array_search('location', $igsn->right_column_order, true) - 1);

    $layoutMigration->up();
});

it('deduplicates known modules and remains reversible after the layout supersession', function (): void {
    $template = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => ['general', 'location', 'general', ...array_slice(ISSUE_1168_LEGACY_LEFT_SECTIONS, 1)],
        'right_column_order' => ['location', 'sample_image', 'abstract', 'unknown', ...array_slice(ISSUE_1168_LEGACY_RIGHT_SECTIONS, 1, -1)],
    ]);
    $migration = issue1168TemplateMigration();

    $migration->up();
    $template->refresh();
    expect(collect([...$template->left_column_order, ...$template->right_column_order])->duplicates()->all())->toBe([])
        ->and($template->right_column_order[0])->toBe('sample_image')
        ->and($template->right_column_order)->not->toContain('version_notice');

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

    expect($template->left_column_order)->toHaveCount(count(ISSUE_1168_LEGACY_LEFT_SECTIONS))
        ->and(array_diff(ISSUE_1168_LEGACY_LEFT_SECTIONS, $template->left_column_order))->toBe([])
        ->and(array_diff($template->left_column_order, ISSUE_1168_LEGACY_LEFT_SECTIONS))->toBe([])
        ->and($template->left_column_order)->not->toContain('abstract', 'sample_image')
        ->and($template->right_column_order)->toHaveCount(count(ISSUE_1168_LEGACY_RIGHT_SECTIONS))
        ->and(array_diff(ISSUE_1168_LEGACY_RIGHT_SECTIONS, $template->right_column_order))->toBe([])
        ->and(array_diff($template->right_column_order, ISSUE_1168_LEGACY_RIGHT_SECTIONS))->toBe([])
        ->and($template->right_column_order)->not->toContain('general', 'sample_image', 'version_notice')
        ->and(collect([...$template->left_column_order, ...$template->right_column_order])->duplicates()->all())->toBe([]);
});
