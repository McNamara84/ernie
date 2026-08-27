<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\Resource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

function loadWidenIgsnMetadataUserCodeMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_08_27_000003_widen_igsn_metadata_user_code.php'
    );

    return $migration;
}

function createIgsnMetadataForUserCodeTest(?string $userCode): IgsnMetadata
{
    /** @var Resource $resource */
    $resource = Resource::factory()->create();

    return IgsnMetadata::create([
        'resource_id' => $resource->id,
        'user_code' => $userCode,
    ]);
}

function skipUnlessUserCodeLengthIsEnforced(): void
{
    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        test()->markTestSkipped(
            "VARCHAR length is not enforced on driver [{$driver}]; "
            .'user_code boundary assertions require MySQL/MariaDB.'
        );
    }
}

it('persists the 77-character Medusa user code from issue 1192', function (): void {
    skipUnlessUserCodeLengthIsEnforced();

    $userCode = 'COLD project / Climate Sensitivity of Glacial Landscape Dynamics / ERC-funded';
    $metadata = createIgsnMetadataForUserCodeTest($userCode);

    expect($metadata->fresh()->user_code)->toBe($userCode)
        ->and(mb_strlen($userCode))->toBe(77);
});

it('persists user codes up to the new 255-character ceiling', function (): void {
    skipUnlessUserCodeLengthIsEnforced();

    $userCode = str_repeat('u', 255);
    $metadata = createIgsnMetadataForUserCodeTest($userCode);

    expect($metadata->fresh()->user_code)->toBe($userCode);
});

it('reverts igsn_metadata.user_code to VARCHAR(50) when no value would overflow', function (): void {
    $metadata = createIgsnMetadataForUserCodeTest('COLD project');
    $migration = loadWidenIgsnMetadataUserCodeMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect($metadata->fresh()->user_code)->toBe('COLD project');
});

it('refuses to narrow igsn_metadata.user_code when an existing value would overflow', function (): void {
    createIgsnMetadataForUserCodeTest(
        'COLD project / Climate Sensitivity of Glacial Landscape Dynamics / ERC-funded'
    );
    $migration = loadWidenIgsnMetadataUserCodeMigration();

    /** @phpstan-ignore method.notFound, argument.unresolvableType, function.unresolvableReturnType */
    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot revert igsn_metadata.user_code to VARCHAR(50)');
});

it('permits rollback at the legacy 50-character boundary', function (): void {
    $metadata = createIgsnMetadataForUserCodeTest(str_repeat('u', 50));
    $migration = loadWidenIgsnMetadataUserCodeMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect($metadata->fresh()->user_code)->toBe(str_repeat('u', 50));
});

it('keeps nullable user codes compatible with rollback', function (): void {
    $metadata = createIgsnMetadataForUserCodeTest(null);
    $migration = loadWidenIgsnMetadataUserCodeMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect($metadata->fresh()->user_code)->toBeNull();
});

it('exposes igsn_metadata.user_code as a widened varchar column', function (): void {
    expect(Schema::hasColumn('igsn_metadata', 'user_code'))->toBeTrue();

    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        /** @var array<int, object{name: string, type: string}> $columns */
        $columns = DB::select('PRAGMA table_info(igsn_metadata)');
        $userCode = collect($columns)->firstWhere('name', 'user_code');

        expect($userCode)->not->toBeNull()
            ->and(strtolower($userCode->type))->toBe('varchar');

        return;
    }

    if ($driver === 'mysql' || $driver === 'mariadb') {
        /** @var array<int, object{DATA_TYPE: string, CHARACTER_MAXIMUM_LENGTH: int}> $rows */
        $rows = DB::select(
            'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH '
            .'FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['igsn_metadata', 'user_code']
        );

        expect($rows)->toHaveCount(1)
            ->and(strtolower($rows[0]->DATA_TYPE))->toBe('varchar')
            ->and((int) $rows[0]->CHARACTER_MAXIMUM_LENGTH)->toBe(255);

        return;
    }

    test()->markTestSkipped("Schema introspection not implemented for driver [{$driver}].");
});
