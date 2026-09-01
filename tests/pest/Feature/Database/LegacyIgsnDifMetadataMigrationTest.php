<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use Illuminate\Support\Facades\Schema;

it('stores the versioned legacy DIF payload and provenance columns', function (): void {
    expect(Schema::hasColumns('igsn_metadata', [
        'legacy_dif_schema_namespace',
        'legacy_dif_json',
        'legacy_dif_imported_at',
    ]))->toBeTrue();

    $resource = Resource::factory()->create();
    $metadata = IgsnMetadata::create([
        'resource_id' => $resource->id,
        'legacy_dif_schema_namespace' => 'http://pmd.gfz-potsdam.de/igsn/schemas/description/1.3',
        'legacy_dif_json' => ['version' => 1, 'fields' => [['path' => 'resource/name', 'value' => 'Sample']]],
        'legacy_dif_imported_at' => '2026-08-31 12:00:00',
    ])->fresh();

    expect($metadata->legacy_dif_json['version'])->toBe(1)
        ->and($metadata->legacy_dif_json['fields'][0]['value'])->toBe('Sample')
        ->and($metadata->legacy_dif_imported_at)->not->toBeNull();
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
