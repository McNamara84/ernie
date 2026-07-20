<?php

declare(strict_types=1);

use App\Services\DoiSuggestionService;
use App\Services\LegacyLandingPageImportService;
use App\Services\LegacyMetaworksDatacenterLookupService;
use App\Services\MetaworksDownloadUrlService;
use App\Services\OldDatasetEditorLoader;
use App\Services\ResourceStorageService;
use App\Services\SumarioPendingResourceImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Config::set('database.connections.metaworks', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    DB::purge('metaworks');

    Schema::connection('metaworks')->create('resource', function (Blueprint $table): void {
        $table->id();
        $table->string('publicstatus')->nullable();
        $table->string('identifier')->nullable()->collation('NOCASE');
        $table->integer('publicationyear')->nullable();
        $table->string('title')->nullable();
    });
});

afterEach(function () {
    DB::disconnect('metaworks');
    Mockery::close();
});

function pendingDatacenterSelectionService(
    LegacyMetaworksDatacenterLookupService $lookup,
): SumarioPendingResourceImportService {
    return new SumarioPendingResourceImportService(
        editorLoader: Mockery::mock(OldDatasetEditorLoader::class),
        resourceStorage: Mockery::mock(ResourceStorageService::class),
        datacenterLookup: $lookup,
        downloadUrlService: Mockery::mock(MetaworksDownloadUrlService::class),
        landingPageImport: new LegacyLandingPageImportService,
        doiSuggestionService: app(DoiSuggestionService::class),
    );
}

it('selects pending DOIs using legacy database and DOI-rule datacenter resolution', function () {
    DB::connection('metaworks')->table('resource')->insert([
        [
            'id' => 1,
            'publicstatus' => 'pending',
            'identifier' => 'https://doi.org/10.5880/HA-ARBODAT_AK1',
        ],
        [
            'id' => 2,
            'publicstatus' => 'pending',
            'identifier' => '10.5880/GFZ.FALLBACK',
        ],
        [
            'id' => 3,
            'publicstatus' => 'published',
            'identifier' => '10.5880/not.pending',
        ],
        [
            'id' => 4,
            'publicstatus' => 'pending',
            'identifier' => '10.5880/DELETE-ME',
        ],
        [
            'id' => 5,
            'publicstatus' => 'pending',
            'identifier' => '10.5880/ha-arbodat_ak1',
        ],
    ]);

    $lookup = Mockery::mock(LegacyMetaworksDatacenterLookupService::class);
    $lookup
        ->shouldReceive('resolveDatacenterNames')
        ->twice()
        ->with('10.5880/ha-arbodat_ak1')
        ->andReturn(['ArboDat 2016']);
    $lookup
        ->shouldReceive('resolveDatacenterNames')
        ->twice()
        ->with('10.5880/gfz.fallback')
        ->andReturn([LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER]);

    $service = pendingDatacenterSelectionService($lookup);

    expect($service->importablePendingDoisForDatacenter('ArboDat 2016'))
        ->toBe(['10.5880/ha-arbodat_ak1'])
        ->and($service->importablePendingDoisForDatacenter(
            LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER,
        ))
        ->toBe(['10.5880/gfz.fallback']);
});

it('returns no pending resources for an empty datacenter name', function () {
    $lookup = Mockery::mock(LegacyMetaworksDatacenterLookupService::class);
    $lookup->shouldNotReceive('resolveDatacenterNames');

    expect(pendingDatacenterSelectionService($lookup)
        ->importablePendingDoisForDatacenter('  '))
        ->toBe([]);
});
