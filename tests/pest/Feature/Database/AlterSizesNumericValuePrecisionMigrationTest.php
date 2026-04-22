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
    /** @var Size $reloaded */
    $reloaded = Size::query()->sole();
    expect((float) $reloaded->numeric_value)->toBe(1.5)
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
    expect((float) $reloaded->numeric_value)->toBe(2_675_059_373.0)
        ->and($reloaded->unit)->toBe('Bytes');
});
