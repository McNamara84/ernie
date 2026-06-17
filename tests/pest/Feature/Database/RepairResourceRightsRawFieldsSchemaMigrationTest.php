<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

function loadRepairResourceRightsRawFieldsSchemaMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_06_16_000001_repair_resource_rights_raw_fields_schema.php'
    );

    return $migration;
}

function dropRepairResourceRightsForeignKeyNamed(Migration $migration, string $constraint): void
{
    $method = new ReflectionMethod($migration, 'dropForeignKeyNamed');
    $method->setAccessible(true);
    $method->invoke($migration, $constraint);
}

function skipUnlessMysqlForResourceRightsRepair(): void
{
    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        test()->markTestSkipped(
            "resource_rights repair migration catalog assertions are implemented for MySQL/MariaDB, not [{$driver}]."
        );
    }
}

function recreateLegacyResourceRightsTable(?string $rightsForeignKeyName): void
{
    Schema::dropIfExists('resource_rights');

    Schema::create('resource_rights', function (Blueprint $table) use ($rightsForeignKeyName): void {
        $table->id();
        $table->foreignId('resource_id')
            ->constrained('resources')
            ->cascadeOnDelete();
        $table->unsignedBigInteger('rights_id');
        $table->timestamps();

        $table->unique(['resource_id', 'rights_id'], 'resource_rights_resource_id_rights_id_unique');

        if ($rightsForeignKeyName !== null) {
            $table->foreign('rights_id', $rightsForeignKeyName)
                ->references('id')
                ->on('rights')
                ->cascadeOnDelete();
        }
    });
}

function resourceRightsColumn(string $column): object
{
    $result = DB::selectOne(
        <<<'SQL'
        SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'resource_rights'
          AND COLUMN_NAME = ?
        SQL,
        [$column],
    );

    expect($result)->not->toBeNull();

    return $result;
}

function resourceRightsRightsForeignKey(): object
{
    $result = DB::selectOne(
        <<<'SQL'
        SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
         AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = DATABASE()
          AND k.TABLE_NAME = 'resource_rights'
          AND k.COLUMN_NAME = 'rights_id'
          AND k.REFERENCED_TABLE_NAME IS NOT NULL
        LIMIT 1
        SQL,
    );

    expect($result)->not->toBeNull();

    return $result;
}

it('repairs resource_rights when rights_id has a non-standard foreign key name', function (): void {
    skipUnlessMysqlForResourceRightsRepair();
    recreateLegacyResourceRightsTable('stage_resource_rights_rights_fk');

    Schema::table('resource_rights', function (Blueprint $table): void {
        // Simulate a migration that got far enough to add one raw column but
        // failed before nullability, FK recreation, or the rest of the fields.
        $table->text('rights_text')->nullable()->after('rights_id');
    });

    $migration = loadRepairResourceRightsRawFieldsSchemaMigration();

    /** @phpstan-ignore method.notFound */
    $migration->up();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(resourceRightsColumn('rights_id')->IS_NULLABLE)->toBe('YES')
        ->and(resourceRightsColumn('rights_text')->DATA_TYPE)->toBe('text')
        ->and((int) resourceRightsColumn('rights_uri')->CHARACTER_MAXIMUM_LENGTH)->toBe(512)
        ->and((int) resourceRightsColumn('rights_identifier_scheme')->CHARACTER_MAXIMUM_LENGTH)->toBe(100)
        ->and((int) resourceRightsColumn('scheme_uri')->CHARACTER_MAXIMUM_LENGTH)->toBe(512)
        ->and((int) resourceRightsColumn('language')->CHARACTER_MAXIMUM_LENGTH)->toBe(10)
        ->and((int) resourceRightsColumn('source')->CHARACTER_MAXIMUM_LENGTH)->toBe(100);

    $foreignKey = resourceRightsRightsForeignKey();

    expect($foreignKey->CONSTRAINT_NAME)->toBe('resource_rights_rights_id_foreign')
        ->and($foreignKey->DELETE_RULE)->toBe('SET NULL')
        ->and(Schema::hasIndex('resource_rights', 'resource_rights_resource_source_idx'))->toBeTrue();
});

it('ignores a stale rights_id foreign key name that is already absent during repair', function (): void {
    skipUnlessMysqlForResourceRightsRepair();
    recreateLegacyResourceRightsTable(null);

    $migration = loadRepairResourceRightsRawFieldsSchemaMigration();

    dropRepairResourceRightsForeignKeyNamed($migration, 'resource_rights_rights_id_foreign');

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(resourceRightsColumn('rights_id')->IS_NULLABLE)->toBe('YES')
        ->and(resourceRightsRightsForeignKey()->DELETE_RULE)->toBe('SET NULL');
});

it('repairs resource_rights when the rights_id foreign key is already missing', function (): void {
    skipUnlessMysqlForResourceRightsRepair();
    recreateLegacyResourceRightsTable(null);

    $migration = loadRepairResourceRightsRawFieldsSchemaMigration();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(resourceRightsColumn('rights_id')->IS_NULLABLE)->toBe('YES')
        ->and(Schema::hasColumns('resource_rights', [
            'rights_text',
            'rights_uri',
            'rights_identifier',
            'rights_identifier_scheme',
            'scheme_uri',
            'language',
            'source',
        ]))->toBeTrue();

    $foreignKey = resourceRightsRightsForeignKey();

    expect($foreignKey->CONSTRAINT_NAME)->toBe('resource_rights_rights_id_foreign')
        ->and($foreignKey->DELETE_RULE)->toBe('SET NULL');
});
