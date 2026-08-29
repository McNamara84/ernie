<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnClassificationType;
use App\Models\Resource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('database');

function loadIgsnClassificationUniqueConstraintMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_08_29_000001_add_unique_constraint_to_igsn_classifications.php');

    return $migration;
}

it('merges existing duplicates before enforcing atomic classification uniqueness', function (): void {
    $migration = loadIgsnClassificationUniqueConstraintMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    $resource = Resource::factory()->create();
    $timestamp = now();
    DB::table('igsn_classifications')->insert([
        [
            'resource_id' => $resource->id,
            'value' => 'Igneous',
            'classification_type' => null,
            'position' => 2,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
        [
            'resource_id' => $resource->id,
            'value' => 'Igneous',
            'classification_type' => IgsnClassificationType::ROCK->value,
            'position' => 3,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
    ]);

    /** @phpstan-ignore method.notFound */
    $migration->up();

    $rows = DB::table('igsn_classifications')->where('resource_id', $resource->id)->get();
    $hasUniqueIndex = collect(Schema::getIndexes('igsn_classifications'))->contains(
        fn (array $index): bool => ($index['name'] ?? null) === 'igsn_classifications_resource_value_unique'
            && (bool) ($index['unique'] ?? false),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->classification_type)->toBe(IgsnClassificationType::ROCK->value)
        ->and($rows->first()?->position)->toBe(2)
        ->and($hasUniqueIndex)->toBeTrue()
        ->and(fn () => DB::table('igsn_classifications')->insert([
            'resource_id' => $resource->id,
            'value' => 'Igneous',
            'classification_type' => null,
            'position' => 4,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]))->toThrow(QueryException::class);
});
