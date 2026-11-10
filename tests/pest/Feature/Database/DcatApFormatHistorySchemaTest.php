<?php

declare(strict_types=1);

use App\Models\OaiPmhFormatRecord;
use App\Models\Resource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

it('preserves format history across resource deletion and indexes harvest queries', function () {
    $resource = Resource::factory()->create();
    $record = OaiPmhFormatRecord::create(['metadata_prefix' => 'dcat_ap', 'oai_identifier' => 'oai:test:'.$resource->doi,
        'doi' => $resource->doi, 'resource_id' => $resource->id, 'status' => 'deleted', 'first_available_at' => now(), 'datestamp' => now(),
        'sets' => ['resourcetype:dataset'], 'export_revision' => 1]);
    $resource->delete();
    expect($record->fresh()->sets)->toBe(['resourcetype:dataset']);
    $indexes = collect(Schema::getIndexes('oai_pmh_format_records'));
    expect($indexes->contains(fn (array $index): bool => $index['columns'] === ['metadata_prefix', 'status', 'datestamp']))->toBeTrue()
        ->and($indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['metadata_prefix', 'oai_identifier']))->toBeTrue();
});

it('rolls back the additive format table without removing existing OAI storage', function () {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_09_120000_create_oai_pmh_format_records_table.php');
    $migration->down();
    try {
        expect(Schema::hasTable('oai_pmh_format_records'))->toBeFalse()
            ->and(Schema::hasTable('oai_pmh_deleted_records'))->toBeTrue()
            ->and(Schema::hasTable('oai_pmh_harvests'))->toBeTrue();
    } finally {
        $migration->up();
    }
});
