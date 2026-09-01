<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnMeasurementType;
use App\Enums\Igsn\IgsnMetadataValueType;
use App\Models\IgsnMeasurement;
use App\Models\IgsnMetadataValue;
use App\Models\IgsnMethod;
use App\Models\IgsnOperator;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use Illuminate\Support\Facades\Schema;

it('stores projected legacy DIF values in typed relational tables', function (): void {
    expect(Schema::hasTable('igsn_operators'))->toBeTrue()
        ->and(Schema::hasTable('igsn_methods'))->toBeTrue()
        ->and(Schema::hasTable('igsn_measurements'))->toBeTrue()
        ->and(Schema::hasTable('igsn_metadata_values'))->toBeTrue();

    $resource = Resource::factory()->create();
    $operator = IgsnOperator::create([
        'resource_id' => $resource->id,
        'value' => 'Operator A',
        'normalized_value_hash' => hash('sha256', 'operator a'),
        'position' => 0,
    ]);
    $method = IgsnMethod::create([
        'resource_id' => $resource->id,
        'scheme' => 'MSCL',
        'value' => 'yes',
        'normalized_value_hash' => hash('sha256', "mscl\x1fyes"),
        'position' => 0,
    ]);
    $measurement = IgsnMeasurement::create([
        'resource_id' => $resource->id,
        'type' => IgsnMeasurementType::TotalLength,
        'start_value' => '42.5',
        'unit' => 'm',
        'normalized_value_hash' => hash('sha256', "42.5\x1f\x1fm\x1f"),
        'position' => 0,
    ]);
    $value = IgsnMetadataValue::create([
        'resource_id' => $resource->id,
        'type' => IgsnMetadataValueType::FieldName,
        'value' => 'Greywacke',
        'normalized_value_hash' => hash('sha256', 'greywacke'),
        'position' => 0,
    ]);

    expect($operator->resource->is($resource))->toBeTrue()
        ->and($method->resource->is($resource))->toBeTrue()
        ->and($measurement->type)->toBe(IgsnMeasurementType::TotalLength)
        ->and($value->type)->toBe(IgsnMetadataValueType::FieldName);
});

it('inserts IGSN Methods and Drilling after Acquisition without disturbing custom order', function (): void {
    $template = LandingPageTemplate::factory()->igsn()->create([
        'left_column_order' => ['dates', 'acquisition', 'general'],
        'right_column_order' => ['location', 'abstract'],
    ]);
    $migration = require database_path('migrations/2026_08_31_000003_add_legacy_dif_sections_to_igsn_templates.php');

    $migration->up();
    $template->refresh();
    expect($template->left_column_order)->toBe([
        'dates', 'acquisition', 'igsn_methods', 'igsn_drilling', 'general',
    ])->and($template->right_column_order)->toBe(['location', 'abstract']);

    $migration->up();
    expect($template->fresh()->left_column_order)->toBe([
        'dates', 'acquisition', 'igsn_methods', 'igsn_drilling', 'general',
    ]);

    $migration->down();
    expect($template->fresh()->left_column_order)->toBe(['dates', 'acquisition', 'general']);
});
