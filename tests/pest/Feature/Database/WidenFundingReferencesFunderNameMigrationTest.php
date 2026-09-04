<?php

declare(strict_types=1);

use App\Models\FundingReference;
use App\Models\Resource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

function loadWidenFundingReferencesFunderNameMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_09_04_000001_widen_funding_references_funder_name.php'
    );

    return $migration;
}

function createFundingReferenceForFunderNameMigration(string $funderName): FundingReference
{
    /** @var Resource $resource */
    $resource = Resource::factory()->create();

    return FundingReference::create([
        'resource_id' => $resource->id,
        'funder_name' => $funderName,
    ]);
}

function skipUnlessFunderNameLengthIsEnforced(): void
{
    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        test()->markTestSkipped(
            "VARCHAR length is not enforced on driver [{$driver}]; "
            .'funder_name boundary assertions require MySQL/MariaDB.'
        );
    }
}

function productionIcdpFundingAgency(): string
{
    return 'International Continental Scientific Drilling Program (ICDP), the NZ Marsden Fund, '
        .'GNS Science, Victoria University of Wellington, University of Otago, the NZ Ministry '
        .'for Business Innovation and Employment, NERC grants NE/J022128/1 and NE/J024449/1, '
        .'the Netherlands Organization for Scientific Research VIDI grant 854.12.011 and the '
        .'ERC starting grant SEISMIC 335915';
}

it('persists the 367-character ICDP funding agency that blocked the DIF backfill', function (): void {
    skipUnlessFunderNameLengthIsEnforced();

    $funderName = productionIcdpFundingAgency();
    $fundingReference = createFundingReferenceForFunderNameMigration($funderName);

    expect(mb_strlen($funderName))->toBe(367)
        ->and($fundingReference->fresh()->funder_name)->toBe($funderName);
});

it('persists funder names at the new 500-character boundary', function (): void {
    skipUnlessFunderNameLengthIsEnforced();

    $funderName = str_repeat('f', 500);
    $fundingReference = createFundingReferenceForFunderNameMigration($funderName);

    expect($fundingReference->fresh()->funder_name)->toBe($funderName)
        ->and(mb_strlen((string) $fundingReference->fresh()->funder_name))->toBe(500);
});

it('reverts funder_name to VARCHAR(255) when all existing values fit', function (): void {
    $fundingReference = createFundingReferenceForFunderNameMigration('International Continental Scientific Drilling Program');
    $migration = loadWidenFundingReferencesFunderNameMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect($fundingReference->fresh()->funder_name)
        ->toBe('International Continental Scientific Drilling Program');
});

it('permits rollback at exactly the legacy 255-character boundary', function (): void {
    $funderName = str_repeat('f', 255);
    $fundingReference = createFundingReferenceForFunderNameMigration($funderName);
    $migration = loadWidenFundingReferencesFunderNameMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect($fundingReference->fresh()->funder_name)->toBe($funderName);
});

it('refuses to narrow funder_name when an existing value would overflow', function (): void {
    createFundingReferenceForFunderNameMigration(str_repeat('f', 256));
    $migration = loadWidenFundingReferencesFunderNameMigration();

    /** @phpstan-ignore method.notFound, argument.unresolvableType, function.unresolvableReturnType */
    expect(fn () => $migration->down())
        ->toThrow(
            RuntimeException::class,
            'Cannot revert funding_references.funder_name to VARCHAR(255)'
        );
});

it('exposes funder_name as an indexed non-null VARCHAR(500) column', function (): void {
    expect(Schema::hasColumn('funding_references', 'funder_name'))->toBeTrue();

    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        /** @var array<int, object{name: string, type: string, notnull: int}> $columns */
        $columns = DB::select('PRAGMA table_info(funding_references)');
        $funderName = collect($columns)->firstWhere('name', 'funder_name');

        expect($funderName)->not->toBeNull()
            ->and(strtolower($funderName->type))->toBe('varchar')
            ->and((int) $funderName->notnull)->toBe(1);

        return;
    }

    if ($driver === 'mysql' || $driver === 'mariadb') {
        /** @var array<int, object{DATA_TYPE: string, CHARACTER_MAXIMUM_LENGTH: int, IS_NULLABLE: string}> $columns */
        $columns = DB::select(
            'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE '
            .'FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['funding_references', 'funder_name']
        );
        /** @var array<int, object{INDEX_NAME: string}> $indexes */
        $indexes = DB::select(
            'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['funding_references', 'funder_name']
        );

        expect($columns)->toHaveCount(1)
            ->and(strtolower($columns[0]->DATA_TYPE))->toBe('varchar')
            ->and((int) $columns[0]->CHARACTER_MAXIMUM_LENGTH)->toBe(500)
            ->and($columns[0]->IS_NULLABLE)->toBe('NO')
            ->and(collect($indexes)->pluck('INDEX_NAME')->all())
            ->toContain('funding_references_funder_name_index');

        return;
    }

    test()->markTestSkipped("Schema introspection not implemented for driver [{$driver}].");
});
