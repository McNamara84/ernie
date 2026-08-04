<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\RelationType;
use App\Models\Resource;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('database');

function loadAddResourceTypeGeneralMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_08_03_000001_add_resource_type_general_to_related_identifiers.php');

    return $migration;
}

it('drops and re-adds resource_type_general on related identifiers', function (): void {
    $migration = loadAddResourceTypeGeneralMigration();

    expect(Schema::hasColumn('related_identifiers', 'resource_type_general'))->toBeTrue();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    expect(Schema::hasColumn('related_identifiers', 'resource_type_general'))->toBeFalse();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasColumn('related_identifiers', 'resource_type_general'))->toBeTrue();
});

it('preserves existing related identifiers when the migration is reapplied', function (): void {
    test()->seed(IdentifierTypeSeeder::class);
    test()->seed(RelationTypeSeeder::class);

    $resource = Resource::factory()->create();
    $identifierTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');
    $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');

    expect($identifierTypeId)->toBeInt()
        ->and($relationTypeId)->toBeInt();

    DB::table('related_identifiers')->insert([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/existing.related',
        'identifier_type_id' => $identifierTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = loadAddResourceTypeGeneralMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(DB::table('related_identifiers')->where('resource_id', $resource->id)->value('resource_type_general'))
        ->toBeNull();
});
