<?php

declare(strict_types=1);

use App\Models\Description;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

function loadWidenDescriptionLanguageMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_08_22_000001_widen_description_language.php'
    );

    return $migration;
}

it('persists normalized BCP 47 description tags beyond the former limit', function (): void {
    $description = Description::factory()->create([
        'language' => 'SL_ROZAJ_BISKE_1994',
    ]);

    expect($description->fresh()->language)->toBe('sl-rozaj-biske-1994')
        ->and(mb_strlen((string) $description->fresh()->language))->toBeGreaterThan(10);
});

it('refuses to narrow the description language column when data would be lost', function (): void {
    Description::factory()->create([
        'language' => 'sl-rozaj-biske-1994',
    ]);

    $migration = loadWidenDescriptionLanguageMigration();

    /** @phpstan-ignore method.notFound, argument.unresolvableType, function.unresolvableReturnType */
    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot revert descriptions.language to VARCHAR(10)');
});

it('allows a reversible rollback at the former ten-character boundary', function (): void {
    Description::factory()->create([
        'language' => 'de-ch-1996',
    ]);

    $migration = loadWidenDescriptionLanguageMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Description::query()->sole()->language)->toBe('de-ch-1996');
});

it('exposes the widened description language column', function (): void {
    expect(Schema::hasColumn('descriptions', 'language'))->toBeTrue();

    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        /** @var array<int, object{name: string, type: string}> $columns */
        $columns = DB::select('PRAGMA table_info(descriptions)');
        $language = collect($columns)->firstWhere('name', 'language');

        expect($language)->not->toBeNull()
            ->and(strtolower($language->type))->toBe('varchar');

        return;
    }

    if ($driver === 'mysql' || $driver === 'mariadb') {
        /** @var array<int, object{DATA_TYPE: string, CHARACTER_MAXIMUM_LENGTH: int}> $rows */
        $rows = DB::select(
            'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH '
            .'FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['descriptions', 'language']
        );

        expect($rows)->toHaveCount(1)
            ->and(strtolower($rows[0]->DATA_TYPE))->toBe('varchar')
            ->and((int) $rows[0]->CHARACTER_MAXIMUM_LENGTH)->toBe(35);

        return;
    }

    test()->markTestSkipped("Schema introspection not implemented for driver [{$driver}].");
});
