<?php

declare(strict_types=1);

use App\Models\RelatedItem;
use App\Models\Resource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coverage for the anonymous migration in
 * `database/migrations/2026_05_01_000001_widen_related_items_edition_column.php`.
 *
 * RefreshDatabase already runs `up()` for us, so these tests verify both that
 * the widened column accepts long DataCite free-text values AND that the
 * `down()` data-loss guard refuses to narrow the column when existing rows
 * would no longer fit.
 */
function loadWidenRelatedItemsEditionMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_05_01_000001_widen_related_items_edition_column.php'
    );

    return $migration;
}

it('persists related_items edition values longer than the legacy 64-character limit', function (): void {
    // Direct-write regression test: the original DataCite payload that broke
    // the import job ("36th General Assembly of the European Seismological
    // Commission, Malta, ESC2018-S11-402") is well over 64 characters. After
    // the migration this must round-trip through the database without
    // truncation.
    $longEdition = '36th General Assembly of the European Seismological Commission, Malta, ESC2018-S11-402';

    /** @var RelatedItem $item */
    $item = RelatedItem::factory()->create([
        'edition' => $longEdition,
    ]);

    $item->refresh();

    expect($item->edition)->toBe($longEdition)
        ->and(mb_strlen((string) $item->edition))->toBeGreaterThan(64);
});

it('persists related_items edition values up to the new 255-character ceiling', function (): void {
    // Boundary test: the widened column must accept the full 255-character
    // payload that VARCHAR(255) advertises. Any future migration that
    // accidentally narrows the column will surface here as a truncation.
    $maxLengthEdition = str_repeat('a', 255);

    /** @var RelatedItem $item */
    $item = RelatedItem::factory()->create([
        'edition' => $maxLengthEdition,
    ]);

    $item->refresh();

    expect($item->edition)->toBe($maxLengthEdition)
        ->and(mb_strlen((string) $item->edition))->toBe(255);
});

it('reverts related_items.edition back to VARCHAR(64) when no row would overflow', function (): void {
    // Populate a row that comfortably fits the legacy limit so the guard is
    // satisfied and the actual Schema::table() rollback path runs end-to-end.
    RelatedItem::factory()->create(['edition' => '1st']);

    $migration = loadWidenRelatedItemsEditionMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    // Re-run up() so subsequent tests in the same process see the widened
    // column again. RefreshDatabase would recreate it, but being explicit
    // keeps this test self-contained.
    /** @phpstan-ignore method.notFound */
    $migration->up();

    /** @var RelatedItem $reloaded */
    $reloaded = RelatedItem::query()->sole();
    expect($reloaded->edition)->toBe('1st');
});

it('refuses to revert related_items.edition when a row exceeds the legacy 64-character limit', function (): void {
    // Insert a value that only fits into VARCHAR(255). The guard must abort
    // the rollback rather than silently truncating user-visible data.
    RelatedItem::factory()->create([
        'edition' => '36th General Assembly of the European Seismological Commission, Malta, ESC2018-S11-402',
    ]);

    $migration = loadWidenRelatedItemsEditionMigration();

    /** @phpstan-ignore method.notFound, argument.unresolvableType, function.unresolvableReturnType */
    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot revert related_items.edition to VARCHAR(64)');
});

it('permits revert when edition values sit exactly at the 64-character boundary', function (): void {
    // The guard must reject only STRICTLY out-of-range values; the legal
    // 64-character maximum must still be allowed. This protects against
    // off-by-one errors in the LENGTH/CHAR_LENGTH comparison.
    RelatedItem::factory()->create([
        'edition' => str_repeat('x', 64),
    ]);

    $migration = loadWidenRelatedItemsEditionMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(RelatedItem::query()->sole()->edition)->toBe(str_repeat('x', 64));
});

it('leaves null edition values untouched during the rollback guard check', function (): void {
    // Related items without an edition (the default for journal articles)
    // must not block the rollback. The guard's `whereNotNull('edition')`
    // clause encodes this contract; a future refactor that drops the
    // null-check would regress here.
    RelatedItem::factory()->create(['edition' => null]);

    $migration = loadWidenRelatedItemsEditionMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(RelatedItem::query()->sole()->edition)->toBeNull();
});

it('exposes related_items.edition as a varchar column with the widened length after migration', function (): void {
    // Driver-aware schema introspection: this project does not depend on
    // doctrine/dbal, so Schema::getColumnType() is unreliable. We query the
    // native catalog instead — PRAGMA on SQLite, information_schema on MySQL —
    // which also lets us assert the EXACT character limit (255) rather than
    // just the column type. Other backends are skipped to keep the test
    // honest about what it actually verifies.
    expect(Schema::hasColumn('related_items', 'edition'))->toBeTrue();

    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        /** @var array<int, object{name: string, type: string}> $columns */
        $columns = DB::select('PRAGMA table_info(related_items)');
        $edition = collect($columns)->firstWhere('name', 'edition');

        // SQLite normalises the column type produced by Laravel's
        // `->string('edition', 255)->change()` to plain "varchar" without a
        // length suffix, so we only assert the type affinity here. The
        // effective 255-char ceiling is covered by the data-level tests
        // above.
        expect($edition)->not->toBeNull()
            ->and(strtolower($edition->type))->toBe('varchar');

        return;
    }

    if ($driver === 'mysql' || $driver === 'mariadb') {
        /** @var array<int, object{DATA_TYPE: string, CHARACTER_MAXIMUM_LENGTH: int}> $rows */
        $rows = DB::select(
            'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH '
            .'FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['related_items', 'edition']
        );

        expect($rows)->toHaveCount(1)
            ->and(strtolower($rows[0]->DATA_TYPE))->toBe('varchar')
            ->and((int) $rows[0]->CHARACTER_MAXIMUM_LENGTH)->toBe(255);

        return;
    }

    test()->markTestSkipped("Schema introspection not implemented for driver [{$driver}].");
});
