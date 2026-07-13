<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\SuggestedRelation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

function loadWidenSuggestedRelationsSourceTitleMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_06_16_000002_widen_suggested_relations_source_title.php'
    );

    return $migration;
}

function skipUnlessMysqlForSuggestedRelationsSourceTitle(): void
{
    $driver = DB::connection()->getDriverName();

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        test()->markTestSkipped(
            "suggested_relations.source_title length assertions are implemented for MySQL/MariaDB, not [{$driver}]."
        );
    }
}

function createSuggestedRelationLookupRows(): array
{
    $identifierTypeId = DB::table('identifier_types')->insertGetId([
        'name' => 'DOI',
        'slug' => 'DOI',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $relationTypeId = DB::table('relation_types')->insertGetId([
        'name' => 'Cites',
        'slug' => 'Cites',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$identifierTypeId, $relationTypeId];
}

it('persists suggested relation source titles longer than the legacy 255-character limit', function (): void {
    skipUnlessMysqlForSuggestedRelationsSourceTitle();

    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    [$identifierTypeId, $relationTypeId] = createSuggestedRelationLookupRows();
    $longTitle = str_repeat('A very long related-work title segment. ', 8);

    $suggestion = SuggestedRelation::create([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/long-source-title.2026.001',
        'identifier_type_id' => $identifierTypeId,
        'relation_type_id' => $relationTypeId,
        'source' => 'scholexplorer',
        'source_title' => $longTitle,
        'discovered_at' => now(),
    ]);

    $suggestion->refresh();

    expect($suggestion->source_title)->toBe($longTitle)
        ->and(mb_strlen($suggestion->source_title))->toBeGreaterThan(255);
});

it('exposes suggested_relations.source_title as a text column after migration', function (): void {
    skipUnlessMysqlForSuggestedRelationsSourceTitle();

    $row = DB::selectOne(
        <<<'SQL'
        SELECT DATA_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'suggested_relations'
          AND COLUMN_NAME = 'source_title'
        SQL,
    );

    expect($row)->not->toBeNull()
        ->and(strtolower($row->DATA_TYPE))->toBe('text');
});

it('refuses to revert suggested_relations.source_title when a row exceeds 255 characters', function (): void {
    skipUnlessMysqlForSuggestedRelationsSourceTitle();

    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    [$identifierTypeId, $relationTypeId] = createSuggestedRelationLookupRows();

    SuggestedRelation::create([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/source-title-rollback.2026.001',
        'identifier_type_id' => $identifierTypeId,
        'relation_type_id' => $relationTypeId,
        'source' => 'scholexplorer',
        'source_title' => str_repeat('x', 256),
        'discovered_at' => now(),
    ]);

    $migration = loadWidenSuggestedRelationsSourceTitleMigration();

    /** @phpstan-ignore method.notFound, argument.unresolvableType, function.unresolvableReturnType */
    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot revert suggested_relations.source_title to VARCHAR(255)');
});

it('can revert suggested_relations.source_title when all values fit the legacy limit', function (): void {
    skipUnlessMysqlForSuggestedRelationsSourceTitle();

    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    [$identifierTypeId, $relationTypeId] = createSuggestedRelationLookupRows();

    SuggestedRelation::create([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/source-title-short.2026.001',
        'identifier_type_id' => $identifierTypeId,
        'relation_type_id' => $relationTypeId,
        'source' => 'scholexplorer',
        'source_title' => str_repeat('x', 255),
        'discovered_at' => now(),
    ]);

    $migration = loadWidenSuggestedRelationsSourceTitleMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();
    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasColumn('suggested_relations', 'source_title'))->toBeTrue()
        ->and(SuggestedRelation::query()->sole()->source_title)->toBe(str_repeat('x', 255));
});
