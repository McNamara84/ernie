<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

function loadLandingPageDailyStatisticsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_05_27_000001_create_landing_page_daily_statistics_table.php');

    return $migration;
}

function loadPortalSearchDailyStatisticsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_05_27_000002_create_portal_search_daily_statistics_table.php');

    return $migration;
}

it('creates and drops the landing_page_daily_statistics table through up and down', function (): void {
    expect(Schema::hasTable('landing_page_daily_statistics'))->toBeTrue();

    $migration = loadLandingPageDailyStatisticsMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    expect(Schema::hasTable('landing_page_daily_statistics'))->toBeFalse();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasTable('landing_page_daily_statistics'))->toBeTrue()
        ->and(Schema::hasColumns('landing_page_daily_statistics', [
            'landing_page_id',
            'statistic_date',
            'page_view_count',
            'file_download_click_count',
        ]))->toBeTrue();
});

it('creates and drops the portal_search_daily_statistics table through up and down', function (): void {
    expect(Schema::hasTable('portal_search_daily_statistics'))->toBeTrue();

    $migration = loadPortalSearchDailyStatisticsMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    expect(Schema::hasTable('portal_search_daily_statistics'))->toBeFalse();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasTable('portal_search_daily_statistics'))->toBeTrue()
        ->and(Schema::hasColumns('portal_search_daily_statistics', [
            'statistic_date',
            'normalized_term',
            'search_count',
        ]))->toBeTrue();
});