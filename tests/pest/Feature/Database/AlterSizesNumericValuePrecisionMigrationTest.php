<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\Size;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Coverage for the anonymous migration in
 * `database/migrations/2026_04_22_000001_alter_sizes_numeric_value_precision.php`.
 *
 * RefreshDatabase already runs `up()` for us, so these tests focus on the
 * `down()` path — specifically its data-loss guard, which is the part that
 * would otherwise go untested.
 */
function loadAlterSizesPrecisionMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_04_22_000001_alter_sizes_numeric_value_precision.php'
    );

    return $migration;
}

it('reverts sizes.numeric_value back to decimal(12, 4) when no row would overflow', function (): void {
    // Populate a row that comfortably fits both the new and old precision so
    // the guard is satisfied and the actual Schema::table() path runs.
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    Size::create([
        'resource_id' => $resource->id,
        'numeric_value' => '1.5',
        'unit' => 'GB',
    ]);

    $migration = loadAlterSizesPrecisionMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    // Re-run up() so subsequent tests in the same process see the widened
    // column again (RefreshDatabase would recreate it, but being explicit
    // keeps this test self-contained).
    /** @phpstan-ignore method.notFound */
    $migration->up();

    // The row must still be present and readable after the round-trip.
    // Assert the exact string produced by the `decimal:4` cast so both the
    // value and the 4-decimal scale are verified — a float cast would hide
    // an accidental scale change.
    /** @var Size $reloaded */
    $reloaded = Size::query()->sole();
    expect($reloaded->numeric_value)->toBe('1.5000')
        ->and($reloaded->unit)->toBe('GB');
});

it('refuses to revert sizes.numeric_value when a row would overflow decimal(12, 4)', function (): void {
    // Insert a value that only fits into decimal(20, 4). The guard must abort
    // the rollback rather than silently truncating or corrupting data.
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    DB::table('sizes')->insert([
        'resource_id' => $resource->id,
        'numeric_value' => '2675059373.0000',
        'unit' => 'Bytes',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = loadAlterSizesPrecisionMigration();

    /** @phpstan-ignore method.notFound, argument.unresolvableType, function.unresolvableReturnType */
    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot revert sizes.numeric_value to decimal(12, 4)');
});

it('refuses to revert sizes.numeric_value when a row underflows decimal(12, 4)', function (): void {
    // decimal(12, 4) is signed, so the guard must also reject values below
    // -99,999,999.9999. decimal(20, 4) can hold much larger negative values.
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    DB::table('sizes')->insert([
        'resource_id' => $resource->id,
        'numeric_value' => '-2675059373.0000',
        'unit' => 'Bytes',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = loadAlterSizesPrecisionMigration();

    /** @phpstan-ignore method.notFound, argument.unresolvableType, function.unresolvableReturnType */
    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot revert sizes.numeric_value to decimal(12, 4)');
});

it('permits revert when values sit exactly on the decimal(12, 4) boundary', function (): void {
    // Boundary values 99,999,999.9999 and -99,999,999.9999 must be accepted:
    // they are the last legal values for decimal(12, 4), so the guard must
    // reject only STRICTLY out-of-range values. This also catches float-based
    // guards that round the bound and incorrectly reject exact boundary rows.
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    DB::table('sizes')->insert([
        [
            'resource_id' => $resource->id,
            'numeric_value' => '99999999.9999',
            'unit' => 'max',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'resource_id' => $resource->id,
            'numeric_value' => '-99999999.9999',
            'unit' => 'min',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $migration = loadAlterSizesPrecisionMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    // Restore widened precision for subsequent tests.
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Size::query()->count())->toBe(2);
});

it('can re-apply up() after it has already run without losing data', function (): void {
    // Exercises the up() path explicitly (RefreshDatabase triggers it once,
    // but Codecov attributes that run to the migration framework, not to the
    // anonymous class). Calling it a second time on the already-widened
    // column must remain a no-op from the caller's perspective.
    $user = User::factory()->create();
    /** @var Resource $resource */
    $resource = Resource::factory()->create(['created_by_user_id' => $user->id]);
    Size::create([
        'resource_id' => $resource->id,
        'numeric_value' => '2675059373',
        'unit' => 'Bytes',
    ]);

    $migration = loadAlterSizesPrecisionMigration();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    /** @var Size $reloaded */
    $reloaded = Size::query()->sole();
    expect($reloaded->numeric_value)->toBe('2675059373.0000')
        ->and($reloaded->unit)->toBe('Bytes');
});
