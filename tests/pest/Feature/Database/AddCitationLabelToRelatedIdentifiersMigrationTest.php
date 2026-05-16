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

function loadAddCitationLabelMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_05_15_000001_add_citation_label_to_related_identifiers.php');

    return $migration;
}

it('drops and re-adds the citation_label column on related_identifiers', function (): void {
    $migration = loadAddCitationLabelMigration();

    expect(Schema::hasColumn('related_identifiers', 'citation_label'))->toBeTrue();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    expect(Schema::hasColumn('related_identifiers', 'citation_label'))->toBeFalse();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(Schema::hasColumn('related_identifiers', 'citation_label'))->toBeTrue();
});

it('leaves legacy related identifiers unresolved when reapplying the migration', function (): void {
    test()->seed(IdentifierTypeSeeder::class);
    test()->seed(RelationTypeSeeder::class);

    $resource = Resource::factory()->create();
    $identifierTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');
    $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');

    expect($identifierTypeId)->toBeInt()
        ->and($relationTypeId)->toBeInt();

    DB::table('related_identifiers')->insert([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/legacy.2026.001',
        'identifier_type_id' => $identifierTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = loadAddCitationLabelMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    /** @phpstan-ignore method.notFound */
    $migration->up();

    expect(DB::table('related_identifiers')->where('resource_id', $resource->id)->value('citation_label'))
        ->toBeNull();
});