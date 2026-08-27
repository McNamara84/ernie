<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\Resource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

function loadPreserveTemporalCoverageSemanticsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_08_27_000005_preserve_temporal_coverage_semantics.php'
    );

    return $migration;
}

it('stores reduced-precision dates and temporal mode in varchar columns', function (): void {
    $resource = Resource::factory()->create();
    $coverage = GeoLocation::create([
        'resource_id' => $resource->id,
        'start_date' => '2026',
        'end_date' => '2026-08',
        'temporal_mode' => 'interval',
    ]);

    expect($coverage->fresh()->start_date)->toBe('2026')
        ->and($coverage->fresh()->end_date)->toBe('2026-08')
        ->and($coverage->fresh()->temporal_mode)->toBe('interval')
        ->and(Schema::hasColumn('geo_locations', 'temporal_mode'))->toBeTrue();

    $driver = DB::connection()->getDriverName();
    if ($driver === 'sqlite') {
        /** @var array<int, object{name: string, type: string}> $columns */
        $columns = DB::select('PRAGMA table_info(geo_locations)');
        $types = collect($columns)->mapWithKeys(
            static fn (object $column): array => [$column->name => strtolower($column->type)]
        );

        expect($types['start_date'])->toBe('varchar')
            ->and($types['end_date'])->toBe('varchar')
            ->and($types['temporal_mode'])->toBe('varchar');

        return;
    }

    if ($driver === 'mysql' || $driver === 'mariadb') {
        /** @var array<int, object{COLUMN_NAME: string, DATA_TYPE: string, CHARACTER_MAXIMUM_LENGTH: int}> $columns */
        $columns = DB::select(
            'SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH '
            .'FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?, ?)',
            ['geo_locations', 'start_date', 'end_date', 'temporal_mode']
        );

        expect($columns)->toHaveCount(3);
        foreach ($columns as $column) {
            expect(strtolower($column->DATA_TYPE))->toBe('varchar')
                ->and((int) $column->CHARACTER_MAXIMUM_LENGTH)
                ->toBe($column->COLUMN_NAME === 'temporal_mode' ? 8 : 10);
        }
    }
});

it('marks dates stored before the semantics migration as intervals', function (): void {
    $migration = loadPreserveTemporalCoverageSemanticsMigration();
    /** @phpstan-ignore method.notFound */
    $migration->down();

    $resource = Resource::factory()->create();
    $coverageId = DB::table('geo_locations')->insertGetId([
        'resource_id' => $resource->id,
        'start_date' => '2026-08-27',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(GeoLocation::findOrFail($coverageId)->temporal_mode)->toBe('interval');
});

it('refuses rollback when reduced precision or instant semantics would be lost', function (array $coverage): void {
    $resource = Resource::factory()->create();
    GeoLocation::create(['resource_id' => $resource->id, ...$coverage]);
    $migration = loadPreserveTemporalCoverageSemanticsMigration();

    /** @phpstan-ignore method.notFound, argument.unresolvableType, function.unresolvableReturnType */
    expect(fn () => $migration->down())->toThrow(
        RuntimeException::class,
        'Cannot revert temporal coverage storage'
    );
})->with([
    'reduced precision' => [['start_date' => '2026', 'temporal_mode' => 'interval']],
    'temporal instant' => [['start_date' => '2026-08-27', 'temporal_mode' => 'instant']],
]);
