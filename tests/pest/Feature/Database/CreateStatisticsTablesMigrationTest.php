<?php

declare(strict_types=1);

use App\Models\LandingPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

function statisticsMigrationTableHasIndex(string $table, array $columns, bool $unique = false): bool
{
    $expectedColumns = array_values($columns);

    return collect(Schema::getIndexes($table))
        ->contains(function (array $index) use ($expectedColumns, $unique): bool {
            $indexColumns = array_values($index['columns'] ?? []);

            if ($indexColumns !== $expectedColumns) {
                return false;
            }

            return ! $unique || (bool) ($index['unique'] ?? false);
        });
}

function statisticsMigrationTableHasForeignKey(string $table, string $column, string $foreignTable): bool
{
    return collect(Schema::getForeignKeys($table))
        ->contains(function (array $foreignKey) use ($column, $foreignTable): bool {
            return in_array($column, $foreignKey['columns'] ?? [], true)
                && ($foreignKey['foreign_table'] ?? null) === $foreignTable;
        });
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

it('repairs an existing landing_page_daily_statistics table without dropping rows', function (): void {
    $landingPage = LandingPage::factory()->create();

    Schema::dropIfExists('landing_page_daily_statistics');
    Schema::create('landing_page_daily_statistics', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('landing_page_id');
        $table->date('statistic_date');
    });

    DB::table('landing_page_daily_statistics')->insert([
        'landing_page_id' => $landingPage->id,
        'statistic_date' => '2026-06-09',
    ]);

    $migration = loadLandingPageDailyStatisticsMigration();

    /** @phpstan-ignore method.notFound */
    $migration->up();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    $row = DB::table('landing_page_daily_statistics')->first();

    expect(Schema::hasColumns('landing_page_daily_statistics', [
        'landing_page_id',
        'statistic_date',
        'page_view_count',
        'file_download_click_count',
        'created_at',
        'updated_at',
    ]))->toBeTrue()
        ->and(DB::table('landing_page_daily_statistics')->count())->toBe(1)
        ->and((int) $row->page_view_count)->toBe(0)
        ->and((int) $row->file_download_click_count)->toBe(0)
        ->and(statisticsMigrationTableHasIndex('landing_page_daily_statistics', ['landing_page_id', 'statistic_date'], unique: true))->toBeTrue()
        ->and(statisticsMigrationTableHasIndex('landing_page_daily_statistics', ['statistic_date']))->toBeTrue();

    if (Schema::getConnection()->getDriverName() !== 'sqlite') {
        expect(statisticsMigrationTableHasForeignKey('landing_page_daily_statistics', 'landing_page_id', 'landing_pages'))->toBeTrue();
    }
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

it('repairs an existing portal_search_daily_statistics table without dropping rows', function (): void {
    Schema::dropIfExists('portal_search_daily_statistics');
    Schema::create('portal_search_daily_statistics', function (Blueprint $table): void {
        $table->id();
        $table->date('statistic_date');
        $table->string('normalized_term', 255);
    });

    DB::table('portal_search_daily_statistics')->insert([
        'statistic_date' => '2026-06-09',
        'normalized_term' => 'metadata',
    ]);

    $migration = loadPortalSearchDailyStatisticsMigration();

    /** @phpstan-ignore method.notFound */
    $migration->up();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    $row = DB::table('portal_search_daily_statistics')->first();

    expect(Schema::hasColumns('portal_search_daily_statistics', [
        'statistic_date',
        'normalized_term',
        'search_count',
        'created_at',
        'updated_at',
    ]))->toBeTrue()
        ->and(DB::table('portal_search_daily_statistics')->count())->toBe(1)
        ->and((int) $row->search_count)->toBe(0)
        ->and(statisticsMigrationTableHasIndex('portal_search_daily_statistics', ['statistic_date', 'normalized_term'], unique: true))->toBeTrue()
        ->and(statisticsMigrationTableHasIndex('portal_search_daily_statistics', ['normalized_term']))->toBeTrue()
        ->and(statisticsMigrationTableHasIndex('portal_search_daily_statistics', ['statistic_date']))->toBeTrue();
});
