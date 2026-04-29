<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\Size;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coverage for the anonymous migration in
 * `database/migrations/2026_04_29_000002_widen_sizes_unit_column.php`.
 *
 * RefreshDatabase already runs `up()` for us, so these tests verify both that
 * the widened column accepts long DataCite free-text values AND that the
 * `down()` data-loss guard refuses to narrow the column when existing rows
 * would no longer fit.
 */
function loadWidenSizesUnitMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_04_29_000002_widen_sizes_unit_column.php'
    );

    return $migration;
}

it('persists size unit values longer than the legacy 50-character limit', function (): void {
    // Direct-write regression test: the original DataCite payload that broke
    // the import job ("Approximately 80 active stations; greater than
    // 440MB/day.") is 58 characters long. After the migration this must
    // round-trip through the database without truncation.
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    $longUnit = 'Approximately 80 active stations; greater than 440MB/day.';

    $size = Size::create([
        'resource_id' => $resource->id,
        'unit' => $longUnit,
    ]);

    $size->refresh();

    expect($size->unit)->toBe($longUnit)
        ->and(mb_strlen($size->unit))->toBeGreaterThan(50);
});

it('persists size unit values up to the new 255-character ceiling', function (): void {
    // Boundary test: the widened column must accept the full 255-character
    // payload that VARCHAR(255) advertises. Any future migration that
    // accidentally narrows the column will surface here as a truncation.
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    $maxLengthUnit = str_repeat('a', 255);

    $size = Size::create([
        'resource_id' => $resource->id,
        'unit' => $maxLengthUnit,
    ]);

    $size->refresh();

    expect($size->unit)->toBe($maxLengthUnit)
        ->and(mb_strlen($size->unit))->toBe(255);
});

it('reverts sizes.unit back to VARCHAR(50) when no row would overflow', function (): void {
    // Populate a row that comfortably fits the legacy limit so the guard is
    // satisfied and the actual Schema::table() rollback path runs end-to-end.
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    Size::create([
        'resource_id' => $resource->id,
        'numeric_value' => '1.5',
        'unit' => 'GB',
    ]);

    $migration = loadWidenSizesUnitMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    // Re-run up() so subsequent tests in the same process see the widened
    // column again. RefreshDatabase would recreate it, but being explicit
    // keeps this test self-contained.
    /** @phpstan-ignore method.notFound */
    $migration->up();

    /** @var Size $reloaded */
    $reloaded = Size::query()->sole();
    expect($reloaded->unit)->toBe('GB');
});

it('refuses to revert sizes.unit when a row exceeds the legacy 50-character limit', function (): void {
    // Insert a value that only fits into VARCHAR(255). The guard must abort
    // the rollback rather than silently truncating user-visible data.
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    DB::table('sizes')->insert([
        'resource_id' => $resource->id,
        'unit' => 'Approximately 80 active stations; greater than 440MB/day.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = loadWidenSizesUnitMigration();

    /** @phpstan-ignore method.notFound, argument.unresolvableType, function.unresolvableReturnType */
    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot revert sizes.unit to VARCHAR(50)');
});

it('permits revert when values sit exactly at the 50-character boundary', function (): void {
    // The guard must reject only STRICTLY out-of-range values; the legal
    // 50-character maximum must still be allowed. This protects against
    // off-by-one errors in the LENGTH/CHAR_LENGTH comparison.
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    Size::create([
        'resource_id' => $resource->id,
        'unit' => str_repeat('x', 50),
    ]);

    $migration = loadWidenSizesUnitMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Size::query()->sole()->unit)->toBe(str_repeat('x', 50));
});

it('leaves null unit values untouched during the rollback guard check', function (): void {
    // Sizes with only a numeric_value (no unit) must not block the rollback.
    // The guard's `whereNotNull('unit')` clause encodes this contract; a
    // future refactor that drops the null-check would regress here.
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    Size::create([
        'resource_id' => $resource->id,
        'numeric_value' => '42',
        'unit' => null,
    ]);

    $migration = loadWidenSizesUnitMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    /** @var Size $reloaded */
    $reloaded = Size::query()->sole();
    expect($reloaded->numeric_value)->toBe('42.0000')
        ->and($reloaded->unit)->toBeNull();
});

it('exposes sizes.unit as a string column after migration', function (): void {
    // Smoke test: confirm the column type the rest of the app relies on.
    // Doctrine/DBAL exposes both VARCHAR(50) and VARCHAR(255) as `string`,
    // so this guards primarily against the column being accidentally
    // dropped or renamed by a future migration.
    expect(Schema::hasColumn('sizes', 'unit'))->toBeTrue()
        ->and(Schema::getColumnType('sizes', 'unit'))->toBe('varchar');
});
