<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('landing-page-templates');

function loadResourceRelationCardsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_15_000002_remove_resource_model_description_section.php');

    return $migration;
}

/** @return list<string> */
function decodeResourceRelationCardsOrder(mixed $value): array
{
    if (is_array($value)) {
        return array_values(array_filter($value, 'is_string'));
    }

    $decoded = is_string($value) ? json_decode($value, true) : null;

    return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
}

it('removes the obsolete Resource module while preserving all IGSN layout zones', function (): void {
    $migration = loadResourceRelationCardsMigration();
    $resourceWithLeftLicenses = LandingPageTemplate::factory()->create([
        'left_column_order' => ['contact', 'licenses', 'model_description', 'dates', 'model_description'],
        'right_column_order' => ['files', 'model_description', 'abstract'],
    ]);
    $resourceWithRightLicenses = LandingPageTemplate::factory()->create([
        'left_column_order' => ['files', 'related_work'],
        'right_column_order' => ['location', 'licenses', 'model_description', 'creators'],
    ]);
    $igsn = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => ['general', 'related_work'],
        'right_column_order' => ['version_notice', 'creators'],
        'hidden_sections' => ['model_description', 'licenses', 'contact'],
    ]);
    $igsnBefore = DB::table('landing_page_templates')->find($igsn->id);

    $migration->up();

    $leftLicensesRow = DB::table('landing_page_templates')->find($resourceWithLeftLicenses->id);
    $rightLicensesRow = DB::table('landing_page_templates')->find($resourceWithRightLicenses->id);
    $igsnAfter = DB::table('landing_page_templates')->find($igsn->id);

    expect(decodeResourceRelationCardsOrder($leftLicensesRow->left_column_order))->toBe(['contact', 'licenses', 'dates'])
        ->and(decodeResourceRelationCardsOrder($leftLicensesRow->right_column_order))->toBe(['files', 'abstract'])
        ->and(decodeResourceRelationCardsOrder($rightLicensesRow->left_column_order))->toBe(['files', 'related_work'])
        ->and(decodeResourceRelationCardsOrder($rightLicensesRow->right_column_order))->toBe(['location', 'licenses', 'creators'])
        ->and($igsnAfter->left_column_order)->toBe($igsnBefore->left_column_order)
        ->and($igsnAfter->right_column_order)->toBe($igsnBefore->right_column_order)
        ->and($igsnAfter->hidden_sections)->toBe($igsnBefore->hidden_sections);

    $migration->up();

    expect(decodeResourceRelationCardsOrder(DB::table('landing_page_templates')->find($resourceWithLeftLicenses->id)->left_column_order))
        ->toBe(['contact', 'licenses', 'dates']);
});

it('restores one legacy module directly after License & Rights when rolled back', function (): void {
    $migration = loadResourceRelationCardsMigration();
    $leftLicenses = LandingPageTemplate::factory()->create([
        'left_column_order' => ['files', 'licenses', 'citation'],
        'right_column_order' => ['abstract'],
    ]);
    $rightLicenses = LandingPageTemplate::factory()->create([
        'left_column_order' => ['files', 'related_work'],
        'right_column_order' => ['location', 'licenses', 'creators'],
    ]);
    $missingLicenses = LandingPageTemplate::factory()->create([
        'left_column_order' => ['contact', 'files', 'dates'],
        'right_column_order' => ['abstract'],
    ]);

    $migration->down();
    $migration->down();

    expect(decodeResourceRelationCardsOrder(DB::table('landing_page_templates')->find($leftLicenses->id)->left_column_order))
        ->toBe(['files', 'licenses', 'model_description', 'citation'])
        ->and(decodeResourceRelationCardsOrder(DB::table('landing_page_templates')->find($rightLicenses->id)->right_column_order))
        ->toBe(['location', 'licenses', 'model_description', 'creators'])
        ->and(decodeResourceRelationCardsOrder(DB::table('landing_page_templates')->find($missingLicenses->id)->left_column_order))
        ->toBe(['contact', 'files', 'model_description', 'dates']);

    $migration->up();

    expect(decodeResourceRelationCardsOrder(DB::table('landing_page_templates')->find($leftLicenses->id)->left_column_order))
        ->not->toContain('model_description');
});
