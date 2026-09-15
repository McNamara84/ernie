<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function loadLandingPageTemplateIgsnLayoutMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_14_000001_restructure_igsn_landing_page_templates.php');

    return $migration;
}

function loadSupersededFlexibleIgsnTemplateMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_08_27_000002_enable_flexible_igsn_template_sections.php');

    return $migration;
}

/** @return list<string> */
function decodeLandingPageTemplateOrder(mixed $value): array
{
    if (is_array($value)) {
        return array_values(array_filter($value, 'is_string'));
    }

    $decoded = is_string($value) ? json_decode($value, true) : null;

    return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
}

it('migrates existing IGSN layouts into visible and hidden zones without losing modules', function (): void {
    $migration = loadLandingPageTemplateIgsnLayoutMigration();
    $resource = LandingPageTemplate::factory()->create();
    $igsn = LandingPageTemplate::factory()->igsn()->create();
    $visibleDrillingIgsn = LandingPageTemplate::factory()->igsn()->create();
    $default = LandingPageTemplate::ensureIgsnDefaultTemplateExists();
    $datacenter = Datacenter::factory()->create(['igsn_landing_page_template_id' => $visibleDrillingIgsn->id]);

    $migration->down();

    DB::table('landing_page_templates')->where('id', $igsn->id)->update([
        'left_column_order' => json_encode(['general', 'location', 'igsn_drilling'], JSON_THROW_ON_ERROR),
        'right_column_order' => json_encode(['contributors', 'creators'], JSON_THROW_ON_ERROR),
        'show_igsn_drilling' => false,
    ]);
    DB::table('landing_page_templates')->where('id', $visibleDrillingIgsn->id)->update([
        'left_column_order' => json_encode(['general', 'contributors'], JSON_THROW_ON_ERROR),
        'right_column_order' => json_encode(['location', 'igsn_drilling', 'creators'], JSON_THROW_ON_ERROR),
        'show_igsn_drilling' => true,
    ]);

    $migration->up();

    expect(Schema::hasColumn('landing_page_templates', 'hidden_sections'))->toBeTrue()
        ->and(Schema::hasColumn('landing_page_templates', 'show_igsn_drilling'))->toBeFalse();

    $resourceRow = DB::table('landing_page_templates')->find($resource->id);
    $igsnRow = DB::table('landing_page_templates')->find($igsn->id);
    $defaultRow = DB::table('landing_page_templates')->find($default->id);
    $visibleDrillingRow = DB::table('landing_page_templates')->find($visibleDrillingIgsn->id);
    $left = decodeLandingPageTemplateOrder($igsnRow->left_column_order);
    $right = decodeLandingPageTemplateOrder($igsnRow->right_column_order);
    $hidden = decodeLandingPageTemplateOrder($igsnRow->hidden_sections);

    expect(decodeLandingPageTemplateOrder($resourceRow->hidden_sections))->toBe([])
        ->and($left)->toBe(['general', 'location', 'map'])
        ->and($right)->toBe(['version_notice', 'contributors', 'creators'])
        ->and($hidden)->toContain('igsn_drilling')
        ->and([...$left, ...$right, ...$hidden])->toHaveCount(count(LandingPageTemplate::IGSN_SECTIONS))
        ->and(array_unique([...$left, ...$right, ...$hidden]))->toHaveCount(count(LandingPageTemplate::IGSN_SECTIONS))
        ->and(decodeLandingPageTemplateOrder($defaultRow->left_column_order))->toBe(LandingPageTemplate::IGSN_LEFT_COLUMN_SECTIONS)
        ->and(decodeLandingPageTemplateOrder($defaultRow->right_column_order))->toBe(LandingPageTemplate::IGSN_RIGHT_COLUMN_SECTIONS)
        ->and(decodeLandingPageTemplateOrder($defaultRow->hidden_sections))->toBe(LandingPageTemplate::IGSN_HIDDEN_SECTIONS)
        ->and(decodeLandingPageTemplateOrder($visibleDrillingRow->left_column_order))->toBe(['general', 'contributors'])
        ->and(decodeLandingPageTemplateOrder($visibleDrillingRow->right_column_order))->toBe([
            'version_notice', 'location', 'map', 'igsn_drilling', 'creators',
        ])
        ->and(decodeLandingPageTemplateOrder($visibleDrillingRow->hidden_sections))->not->toContain('igsn_drilling')
        ->and($datacenter->fresh()?->igsn_landing_page_template_id)->toBe($visibleDrillingIgsn->id);

    $migration->down();
    $rolledBack = DB::table('landing_page_templates')->find($igsn->id);
    $rolledBackDefault = DB::table('landing_page_templates')->find($default->id);
    $rolledBackVisibleDrilling = DB::table('landing_page_templates')->find($visibleDrillingIgsn->id);
    $rolledBackLeft = decodeLandingPageTemplateOrder($rolledBack->left_column_order);
    $rolledBackRight = decodeLandingPageTemplateOrder($rolledBack->right_column_order);
    $rolledBackVisibleLeft = decodeLandingPageTemplateOrder($rolledBackVisibleDrilling->left_column_order);
    $rolledBackVisibleRight = decodeLandingPageTemplateOrder($rolledBackVisibleDrilling->right_column_order);

    expect(Schema::hasColumn('landing_page_templates', 'hidden_sections'))->toBeFalse()
        ->and(Schema::hasColumn('landing_page_templates', 'show_igsn_drilling'))->toBeTrue()
        ->and((bool) $rolledBack->show_igsn_drilling)->toBeFalse()
        ->and((bool) $rolledBackDefault->show_igsn_drilling)->toBeTrue()
        ->and((bool) $rolledBackVisibleDrilling->show_igsn_drilling)->toBeTrue()
        ->and(array_slice($rolledBackLeft, 0, 2))->toBe(['general', 'location'])
        ->and(array_slice($rolledBackRight, 0, 2))->toBe(['contributors', 'creators'])
        ->and(array_slice($rolledBackVisibleLeft, 0, 2))->toBe(['general', 'contributors'])
        ->and(array_slice($rolledBackVisibleRight, 0, 3))->toBe(['location', 'igsn_drilling', 'creators'])
        ->and($rolledBackLeft)->not->toContain('map', 'version_notice')
        ->and($rolledBackRight)->not->toContain('map', 'version_notice');

    $legacyLeft = [
        'general', 'sample_family', 'acquisition', 'igsn_methods', 'igsn_drilling',
        'repositories', 'licenses', 'citation', 'dates', 'contact',
        'model_description', 'related_work',
    ];
    $legacyRight = [
        'abstract', 'methods', 'technical_info', 'series_information',
        'table_of_contents', 'other', 'creators', 'contributors', 'funders',
        'keywords', 'metadata_download', 'location',
    ];
    $legacyRightWithSampleImage = [
        ...array_slice($legacyRight, 0, -1),
        'sample_image',
        'location',
    ];

    expect(decodeLandingPageTemplateOrder($rolledBackDefault->left_column_order))->toBe($legacyLeft)
        ->and(decodeLandingPageTemplateOrder($rolledBackDefault->right_column_order))->toBe($legacyRightWithSampleImage);

    loadSupersededFlexibleIgsnTemplateMigration()->down();
    $rolledBackPastFlexibleLayout = DB::table('landing_page_templates')->find($igsn->id);
    $rolledBackLeft = decodeLandingPageTemplateOrder($rolledBackPastFlexibleLayout->left_column_order);
    $rolledBackRight = decodeLandingPageTemplateOrder($rolledBackPastFlexibleLayout->right_column_order);

    expect($rolledBackLeft)->toHaveCount(count($legacyLeft))
        ->and(array_diff($legacyLeft, $rolledBackLeft))->toBe([])
        ->and(array_diff($rolledBackLeft, $legacyLeft))->toBe([])
        ->and($rolledBackRight)->toHaveCount(count($legacyRight))
        ->and(array_diff($legacyRight, $rolledBackRight))->toBe([])
        ->and(array_diff($rolledBackRight, $legacyRight))->toBe([])
        ->and(collect([...$rolledBackLeft, ...$rolledBackRight])->duplicates()->all())->toBe([]);

    $migration->up();
});

it('is safe to rerun in either migration direction', function (): void {
    $migration = loadLandingPageTemplateIgsnLayoutMigration();

    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('landing_page_templates', 'hidden_sections'))->toBeTrue();

    $migration->down();
    $migration->down();
    expect(Schema::hasColumn('landing_page_templates', 'hidden_sections'))->toBeFalse();

    $migration->up();
});
