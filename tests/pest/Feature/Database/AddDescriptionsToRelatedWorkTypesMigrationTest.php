<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\RelationType;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('database');

function loadAddDescriptionsToRelatedWorkTypesMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_15_000001_add_descriptions_to_related_work_types.php');

    return $migration;
}

it('drops and re-adds both description columns', function (): void {
    $migration = loadAddDescriptionsToRelatedWorkTypesMigration();

    expect(Schema::hasColumn('relation_types', 'description'))->toBeTrue()
        ->and(Schema::hasColumn('identifier_types', 'description'))->toBeTrue();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    expect(Schema::hasColumn('relation_types', 'description'))->toBeFalse()
        ->and(Schema::hasColumn('identifier_types', 'description'))->toBeFalse();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasColumn('relation_types', 'description'))->toBeTrue()
        ->and(Schema::hasColumn('identifier_types', 'description'))->toBeTrue();
});

it('backfills known slugs while preserving rows and configuration', function (): void {
    test()->seed([
        RelationTypeSeeder::class,
        IdentifierTypeSeeder::class,
    ]);

    RelationType::query()->where('slug', 'Cites')->update([
        'name' => 'Custom citation label',
        'description' => 'Legacy relation description',
        'is_active' => false,
        'is_elmo_active' => false,
    ]);
    IdentifierType::query()->where('slug', 'DOI')->update([
        'name' => 'Custom DOI label',
        'description' => 'Legacy identifier description',
        'is_active' => false,
        'is_elmo_active' => false,
    ]);

    $customRelationId = DB::table('relation_types')->insertGetId([
        'name' => 'Custom Relation',
        'slug' => 'CustomRelation',
        'description' => 'Custom relation description',
        'is_active' => true,
        'is_elmo_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $customIdentifierId = DB::table('identifier_types')->insertGetId([
        'name' => 'Custom Identifier',
        'slug' => 'CustomIdentifier',
        'description' => 'Custom identifier description',
        'is_active' => true,
        'is_elmo_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $relationCount = RelationType::query()->count();
    $identifierCount = IdentifierType::query()->count();
    $migration = loadAddDescriptionsToRelatedWorkTypesMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    $relationType = RelationType::query()->where('slug', 'Cites')->firstOrFail();
    $identifierType = IdentifierType::query()->where('slug', 'DOI')->firstOrFail();

    expect(RelationType::query()->count())->toBe($relationCount)
        ->and($relationType->name)->toBe('Custom citation label')
        ->and($relationType->description)->toBe('Indicates that A includes B in a citation')
        ->and($relationType->is_active)->toBeFalse()
        ->and($relationType->is_elmo_active)->toBeFalse()
        ->and(DB::table('relation_types')->where('id', $customRelationId)->value('description'))->toBeNull()
        ->and(IdentifierType::query()->count())->toBe($identifierCount)
        ->and($identifierType->name)->toBe('Custom DOI label')
        ->and($identifierType->description)
        ->toBe('A character string used to uniquely identify an object. A DOI name is divided into two parts, a prefix and a suffix, separated by a slash.')
        ->and($identifierType->is_active)->toBeFalse()
        ->and($identifierType->is_elmo_active)->toBeFalse()
        ->and(DB::table('identifier_types')->where('id', $customIdentifierId)->value('description'))->toBeNull();
});
