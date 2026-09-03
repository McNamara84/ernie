<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

function loadIgsnPortalFacetIndexesMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_03_000002_add_igsn_portal_facet_indexes.php');

    return $migration;
}

/** @return array<string, list<string>> */
function expectedIgsnPortalFacetIndexes(): array
{
    return [
        'igsn_metadata_material_idx' => ['material'],
        'igsn_class_type_value_resource_idx' => ['classification_type', 'value', 'resource_id'],
        'igsn_geo_ages_value_resource_idx' => ['value', 'resource_id'],
        'igsn_geo_units_value_resource_idx' => ['value', 'resource_id'],
    ];
}

it('defines all indexes used by the IGSN portal facets', function (): void {
    $indexesByTable = [
        'igsn_metadata' => collect(Schema::getIndexes('igsn_metadata'))->keyBy('name'),
        'igsn_classifications' => collect(Schema::getIndexes('igsn_classifications'))->keyBy('name'),
        'igsn_geological_ages' => collect(Schema::getIndexes('igsn_geological_ages'))->keyBy('name'),
        'igsn_geological_units' => collect(Schema::getIndexes('igsn_geological_units'))->keyBy('name'),
    ];
    $indexTables = [
        'igsn_metadata_material_idx' => 'igsn_metadata',
        'igsn_class_type_value_resource_idx' => 'igsn_classifications',
        'igsn_geo_ages_value_resource_idx' => 'igsn_geological_ages',
        'igsn_geo_units_value_resource_idx' => 'igsn_geological_units',
    ];

    foreach (expectedIgsnPortalFacetIndexes() as $name => $columns) {
        $index = $indexesByTable[$indexTables[$name]]->get($name);

        expect($index)->not->toBeNull("Missing IGSN portal facet index [{$name}]")
            ->and($index['columns'] ?? null)->toBe($columns);
    }
});

it('drops and recreates every IGSN portal facet index reversibly', function (): void {
    $migration = loadIgsnPortalFacetIndexesMigration();

    /** @phpstan-ignore method.notFound */
    $migration->down();

    try {
        foreach (expectedIgsnPortalFacetIndexes() as $name => $_columns) {
            expect(Schema::hasIndex(match ($name) {
                'igsn_metadata_material_idx' => 'igsn_metadata',
                'igsn_class_type_value_resource_idx' => 'igsn_classifications',
                'igsn_geo_ages_value_resource_idx' => 'igsn_geological_ages',
                default => 'igsn_geological_units',
            }, $name))->toBeFalse();
        }
    } finally {
        /** @phpstan-ignore method.notFound */
        $migration->up();
    }

    foreach (expectedIgsnPortalFacetIndexes() as $name => $_columns) {
        expect(Schema::hasIndex(match ($name) {
            'igsn_metadata_material_idx' => 'igsn_metadata',
            'igsn_class_type_value_resource_idx' => 'igsn_classifications',
            'igsn_geo_ages_value_resource_idx' => 'igsn_geological_ages',
            default => 'igsn_geological_units',
        }, $name))->toBeTrue();
    }
});
