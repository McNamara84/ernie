<?php

declare(strict_types=1);

use App\Console\Commands\ReconcileLegacyRelatedIdentifiers;
use App\Models\IdentifierType;
use App\Models\RelationType;
use App\Models\Resource;
use App\Services\Legacy\LegacyRelatedIdentifierReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

covers(ReconcileLegacyRelatedIdentifiers::class, LegacyRelatedIdentifierReconciliationService::class);

beforeEach(function (): void {
    Config::set('database.connections.metaworks', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('metaworks');

    Schema::connection('metaworks')->create('resource', function (Blueprint $table): void {
        $table->id();
        $table->string('identifier')->nullable();
    });
    Schema::connection('metaworks')->create('relatedidentifier', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('resource_id');
        $table->string('identifier')->nullable();
        $table->string('identifiertype')->nullable();
        $table->string('relationtype')->nullable();
    });

    IdentifierType::query()->create([
        'name' => 'DOI',
        'slug' => 'DOI',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);
    IdentifierType::query()->create([
        'name' => 'URL',
        'slug' => 'URL',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);
    foreach ([
        'IsPreviousVersionOf' => 'Is Previous Version Of',
        'IsObsoletedBy' => 'Is Obsoleted By',
        'References' => 'References',
    ] as $slug => $name) {
        RelationType::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'is_elmo_active' => true,
        ]);
    }
});

afterEach(function (): void {
    Schema::connection('metaworks')->dropIfExists('relatedidentifier');
    Schema::connection('metaworks')->dropIfExists('resource');
    DB::disconnect('metaworks');
});

function versionNoticeLegacyResource(int $id, string $doi): void
{
    DB::connection('metaworks')->table('resource')->insert(['id' => $id, 'identifier' => $doi]);
}

function versionNoticeLegacyRelation(
    int $resourceId,
    string $identifier,
    string $identifierType = 'DOI',
    string $relationType = 'IsPreviousVersionOf',
): void {
    DB::connection('metaworks')->table('relatedidentifier')->insert([
        'resource_id' => $resourceId,
        'identifier' => $identifier,
        'identifiertype' => $identifierType,
        'relationtype' => $relationType,
    ]);
}

it('dry-runs, applies and remains idempotent without overwriting existing relations', function (): void {
    $doi = '10.5880/legacy.versioned';
    versionNoticeLegacyResource(501, $doi);
    versionNoticeLegacyRelation(501, 'https://doi.org/10.5880/GFZ.NEW.001');
    versionNoticeLegacyRelation(501, 'https://example.test/reference', 'URL', 'References');
    $resource = Resource::factory()->create([
        'doi' => $doi,
        'legacy_source' => 'sumario-pmd',
        'legacy_source_id' => 501,
    ]);
    $resource->relatedIdentifiers()->create([
        'identifier' => 'https://example.test/reference',
        'identifier_type_id' => IdentifierType::query()->where('slug', 'URL')->value('id'),
        'relation_type_id' => RelationType::query()->where('slug', 'References')->value('id'),
        'citation_label' => 'Curated label must survive',
        'position' => 7,
    ]);

    $service = app(LegacyRelatedIdentifierReconciliationService::class);
    $dryRun = $service->run(dois: [$doi]);

    expect($dryRun)
        ->resources_scanned->toBe(1)
        ->changed->toBe(1)
        ->relations_added->toBe(1)
        ->errors->toBe(0);
    expect($resource->relatedIdentifiers()->count())->toBe(1);

    $applied = $service->run(apply: true, dois: [$doi]);

    expect($applied)
        ->changed->toBe(1)
        ->relations_added->toBe(1)
        ->sync_resource_ids->toBe([(int) $resource->id]);
    expect($resource->relatedIdentifiers()->count())->toBe(2);
    expect($resource->relatedIdentifiers()->where('citation_label', 'Curated label must survive')->exists())->toBeTrue();
    expect($resource->relatedIdentifiers()->where('identifier', '10.5880/gfz.new.001')->value('position'))->toBe(8);

    $repeat = $service->run(apply: true, dois: [$doi]);

    expect($repeat)
        ->changed->toBe(0)
        ->unchanged->toBe(1)
        ->relations_added->toBe(0)
        ->sync_resource_ids->toBe([]);
    expect($resource->relatedIdentifiers()->count())->toBe(2);
});

it('reports invalid and duplicate legacy rows while adding only the valid unique relation', function (): void {
    $doi = '10.5880/legacy.invalid';
    versionNoticeLegacyResource(502, $doi);
    versionNoticeLegacyRelation(502, 'not-a-doi');
    versionNoticeLegacyRelation(502, '10.5880/valid-target');
    versionNoticeLegacyRelation(502, 'https://doi.org/10.5880/VALID-TARGET');
    versionNoticeLegacyRelation(502, '10.5880/unknown-relation', 'DOI', 'NotADataCiteRelation');
    $resource = Resource::factory()->create(['doi' => $doi]);

    $result = app(LegacyRelatedIdentifierReconciliationService::class)->run(apply: true, dois: [$doi]);

    expect($result)
        ->relations_found->toBe(1)
        ->relations_added->toBe(1)
        ->invalid_relations->toBe(2)
        ->duplicate_legacy_relations->toBe(1)
        ->errors->toBe(0);
    expect($result['records'][0]['message'])
        ->toContain('Skipped 2 invalid')
        ->toContain('Collapsed 1 duplicate');
    expect($resource->relatedIdentifiers()->pluck('identifier')->all())->toBe(['10.5880/valid-target']);
});

it('reports missing and ambiguous legacy resources without changing ERNIE', function (): void {
    $missing = Resource::factory()->create(['doi' => '10.5880/no-legacy-match']);
    $ambiguous = Resource::factory()->create(['doi' => '10.5880/ambiguous-match']);
    versionNoticeLegacyResource(601, $ambiguous->doi);
    versionNoticeLegacyResource(602, $ambiguous->doi);

    $result = app(LegacyRelatedIdentifierReconciliationService::class)->run(
        apply: true,
        dois: [$missing->doi, $ambiguous->doi],
    );

    expect($result)
        ->resources_scanned->toBe(2)
        ->missing_legacy->toBe(1)
        ->errors->toBe(1)
        ->relations_added->toBe(0);
    expect($result['records'])->sequence(
        fn ($record) => $record->status->toBe('missing_legacy'),
        fn ($record) => $record->status->toBe('error')->message->toContain('Multiple SUMARIO resources'),
    );
});

it('exposes safe command defaults and rejects DataCite sync during a dry run', function (): void {
    $doi = '10.5880/legacy.command';
    versionNoticeLegacyResource(701, $doi);
    versionNoticeLegacyRelation(701, '10.5880/command-target');
    $resource = Resource::factory()->create(['doi' => $doi]);

    $this->artisan('resources:reconcile-legacy-related-identifiers', ['--doi' => [$doi]])
        ->expectsOutputToContain('Dry run only; no data was changed.')
        ->assertSuccessful();
    expect($resource->relatedIdentifiers()->count())->toBe(0);

    $this->artisan('resources:reconcile-legacy-related-identifiers', [
        '--doi' => [$doi],
        '--sync-datacite' => true,
    ])
        ->expectsOutputToContain('--sync-datacite requires --apply')
        ->assertExitCode(Command::INVALID);
    expect($resource->relatedIdentifiers()->count())->toBe(0);

    $this->artisan('resources:reconcile-legacy-related-identifiers', [
        '--doi' => [$doi],
        '--apply' => true,
        '--sync-datacite' => true,
    ])
        ->expectsOutputToContain('Legacy related identifier reconciliation applied.')
        ->expectsOutputToContain('DataCite full-metadata sync run:')
        ->assertSuccessful();
    expect($resource->relatedIdentifiers()->count())->toBe(1);
});

it('rejects invalid DOI filters instead of silently scanning nothing', function (): void {
    Resource::factory()->create(['doi' => '10.5880/valid-resource']);

    $this->artisan('resources:reconcile-legacy-related-identifiers', ['--doi' => ['not-a-doi']])
        ->expectsOutputToContain('Invalid DOI filter(s): not-a-doi')
        ->assertExitCode(Command::INVALID);
});
