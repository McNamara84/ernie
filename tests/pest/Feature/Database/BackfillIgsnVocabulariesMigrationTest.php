<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function runIgsnVocabularyBackfillMigration(): void
{
    $migration = require database_path('migrations/2026_08_20_000004_backfill_igsn_vocabularies.php');

    if (! is_object($migration) || ! method_exists($migration, 'up')) {
        throw new RuntimeException('The IGSN vocabulary backfill migration is invalid.');
    }

    $migration->up();
}

it('keeps the classification type column indexed for the backfilled values', function (): void {
    $hasIndex = collect(Schema::getIndexes('igsn_classifications'))
        ->contains(fn (array $index): bool => array_values($index['columns'] ?? []) === ['classification_type']);

    expect(Schema::hasColumn('igsn_classifications', 'classification_type'))->toBeTrue()
        ->and($hasIndex)->toBeTrue();
});

it('canonically backfills existing IGSN materials and classifications idempotently', function (): void {
    $rock = Resource::factory()->create(['doi' => '10.5880/backfill.rock']);
    IgsnMetadata::create([
        'resource_id' => $rock->id,
        'material' => ' rock ',
    ]);
    insertLegacyClassifications($rock->id, [
        ['value' => ' igneous>volcanic ', 'classification_type' => null, 'position' => 4],
        ['value' => 'IGNEOUS>VOLCANIC', 'classification_type' => null, 'position' => 5],
        ['value' => 'Quartz', 'classification_type' => null, 'position' => 6],
        ['value' => 'N/A', 'classification_type' => null, 'position' => 7],
        ['value' => ' sedimentary ', 'classification_type' => null, 'position' => 8],
    ]);

    $mineral = Resource::factory()->create(['doi' => '10.5880/backfill.mineral']);
    IgsnMetadata::create([
        'resource_id' => $mineral->id,
        'material' => ' mineral ',
    ]);
    insertLegacyClassifications($mineral->id, [
        ['value' => 'More Info', 'classification_type' => null, 'position' => 2],
        ['value' => ' quartz ', 'classification_type' => null, 'position' => 3],
    ]);

    $notApplicable = Resource::factory()->create(['doi' => '10.5880/backfill.not-applicable']);
    IgsnMetadata::create([
        'resource_id' => $notApplicable->id,
        'material' => 'Not applicable',
    ]);
    insertLegacyClassifications($notApplicable->id, [
        ['value' => ' Custom   class ', 'classification_type' => 'rock', 'position' => 9],
    ]);

    $unknown = Resource::factory()->create(['doi' => '10.5880/backfill.unknown']);
    IgsnMetadata::create([
        'resource_id' => $unknown->id,
        'material' => ' Granite ',
    ]);
    insertLegacyClassifications($unknown->id, [
        ['value' => ' untouched ', 'classification_type' => null, 'position' => 11],
    ]);

    runIgsnVocabularyBackfillMigration();

    expect(IgsnMetadata::where('resource_id', $rock->id)->value('material'))->toBe('Rock')
        ->and(classificationRows($rock->id))->toBe([
            ['value' => 'Igneous>Volcanic', 'classification_type' => 'rock', 'position' => 0],
            ['value' => 'Sedimentary', 'classification_type' => 'rock', 'position' => 1],
        ])
        ->and(IgsnMetadata::where('resource_id', $mineral->id)->value('material'))->toBe('Mineral')
        ->and(classificationRows($mineral->id))->toBe([
            ['value' => 'Quartz', 'classification_type' => 'mineral', 'position' => 0],
        ])
        ->and(IgsnMetadata::where('resource_id', $notApplicable->id)->value('material'))->toBe('NotApplicable')
        ->and(classificationRows($notApplicable->id))->toBe([
            ['value' => 'Custom class', 'classification_type' => null, 'position' => 0],
        ])
        ->and(IgsnMetadata::where('resource_id', $unknown->id)->value('material'))->toBe(' Granite ')
        ->and(classificationRows($unknown->id))->toBe([
            ['value' => ' untouched ', 'classification_type' => null, 'position' => 11],
        ]);

    $metadataSnapshot = DB::table('igsn_metadata')
        ->orderBy('id')
        ->get(['resource_id', 'material'])
        ->map(static fn (stdClass $row): array => (array) $row)
        ->all();
    $classificationSnapshot = DB::table('igsn_classifications')
        ->orderBy('resource_id')
        ->orderBy('position')
        ->get(['resource_id', 'value', 'classification_type', 'position'])
        ->map(static fn (stdClass $row): array => (array) $row)
        ->all();

    runIgsnVocabularyBackfillMigration();

    expect(DB::table('igsn_metadata')
        ->orderBy('id')
        ->get(['resource_id', 'material'])
        ->map(static fn (stdClass $row): array => (array) $row)
        ->all())
        ->toBe($metadataSnapshot)
        ->and(DB::table('igsn_classifications')
            ->orderBy('resource_id')
            ->orderBy('position')
            ->get(['resource_id', 'value', 'classification_type', 'position'])
            ->map(static fn (stdClass $row): array => (array) $row)
            ->all())
        ->toBe($classificationSnapshot);
});

/**
 * @param  list<array{value: string, classification_type: string|null, position: int}>  $classifications
 */
function insertLegacyClassifications(int $resourceId, array $classifications): void
{
    $timestamp = now();

    DB::table('igsn_classifications')->insert(array_map(
        static fn (array $classification): array => [
            'resource_id' => $resourceId,
            ...$classification,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ],
        $classifications,
    ));
}

/**
 * @return list<array{value: string, classification_type: string|null, position: int}>
 */
function classificationRows(int $resourceId): array
{
    $rows = DB::table('igsn_classifications')
        ->where('resource_id', $resourceId)
        ->orderBy('position')
        ->get(['value', 'classification_type', 'position'])
        ->map(static fn (stdClass $row): array => [
            'value' => (string) $row->value,
            'classification_type' => is_string($row->classification_type) ? $row->classification_type : null,
            'position' => (int) $row->position,
        ])
        ->all();

    return array_values($rows);
}
