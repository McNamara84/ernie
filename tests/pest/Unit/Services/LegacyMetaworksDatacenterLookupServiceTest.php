<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\Resource;
use App\Services\DoiSuggestionService;
use App\Services\LegacyMetaworksDatacenterLookupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * @return list<string>
 */
function legacyImportDatacenterNames(): array
{
    return [
        LegacyMetaworksDatacenterLookupService::ARBODAT_DATACENTER,
        LegacyMetaworksDatacenterLookupService::CRC1211DB_DATACENTER,
        LegacyMetaworksDatacenterLookupService::DEKORP_DATACENTER,
        LegacyMetaworksDatacenterLookupService::DIGIS_DATACENTER,
        LegacyMetaworksDatacenterLookupService::ENMAP_DATACENTER,
        LegacyMetaworksDatacenterLookupService::FID_GEO_DATACENTER,
        LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER,
        LegacyMetaworksDatacenterLookupService::GIPP_DATACENTER,
        LegacyMetaworksDatacenterLookupService::ICGEM_DATACENTER,
        LegacyMetaworksDatacenterLookupService::IGETS_DATACENTER,
        LegacyMetaworksDatacenterLookupService::INTERMAGNET_DATACENTER,
        LegacyMetaworksDatacenterLookupService::ISDC_DATACENTER,
        LegacyMetaworksDatacenterLookupService::ISG_DATACENTER,
        LegacyMetaworksDatacenterLookupService::PIK_DATACENTER,
        LegacyMetaworksDatacenterLookupService::RIESGOS_DATACENTER,
        LegacyMetaworksDatacenterLookupService::SDDB_DATACENTER,
        LegacyMetaworksDatacenterLookupService::SFB806_DATACENTER,
        LegacyMetaworksDatacenterLookupService::SPP2238_DATACENTER,
        LegacyMetaworksDatacenterLookupService::TERENO_DATACENTER,
        LegacyMetaworksDatacenterLookupService::TR32DB_DATACENTER,
        LegacyMetaworksDatacenterLookupService::TRR228DB_DATACENTER,
        LegacyMetaworksDatacenterLookupService::WDS_DATACENTER,
    ];
}

describe('LegacyMetaworksDatacenterLookupService', function () {
    beforeEach(function () {
        Config::set('database.connections.legacy_metaworks', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('legacy_metaworks');

        Schema::connection('legacy_metaworks')->create('gipp_dataset', function (Blueprint $table): void {
            $table->id();
            $table->string('doi')->nullable()->collation('NOCASE');
        });

        Schema::connection('legacy_metaworks')->create('sddb_dataset', function (Blueprint $table): void {
            $table->id();
            $table->string('doi')->nullable()->collation('NOCASE');
        });

        foreach (legacyImportDatacenterNames() as $name) {
            Datacenter::query()->create(['name' => $name]);
        }
    });

    it('resolves GIPP and SDDB datacenters from true Metaworks tables', function () {
        DB::connection('legacy_metaworks')->table('gipp_dataset')->insert([
            'doi' => '10.5880/gipp.dataset',
        ]);
        DB::connection('legacy_metaworks')->table('sddb_dataset')->insert([
            'doi' => '10.5880/sddb.dataset',
        ]);

        $service = app(LegacyMetaworksDatacenterLookupService::class);

        expect($service->resolveDatacenterNames('10.5880/gipp.dataset'))
            ->toBe([LegacyMetaworksDatacenterLookupService::GIPP_DATACENTER])
            ->and($service->resolveDatacenterNames('10.5880/sddb.dataset'))
            ->toBe([LegacyMetaworksDatacenterLookupService::SDDB_DATACENTER]);
    });

    it('resolves datacenters from legacy DOI patterns', function (string $doi, array $expectedDatacenters): void {
        $service = app(LegacyMetaworksDatacenterLookupService::class);

        expect($service->resolveDatacenterNames($doi))->toBe($expectedDatacenters);
    })->with([
        'ArboDat underscore DOI' => [
            '10.5880/hA-ArboDat_AK1',
            [LegacyMetaworksDatacenterLookupService::ARBODAT_DATACENTER],
        ],
        'ArboDat hyphen DOI' => [
            '10.5880/hA-ArboDat-AK66',
            [LegacyMetaworksDatacenterLookupService::ARBODAT_DATACENTER],
        ],
        'CRC1211DB DOI' => [
            '10.5880/CRC1211DB.2024.001',
            [LegacyMetaworksDatacenterLookupService::CRC1211DB_DATACENTER],
        ],
        'DEKORP DOI with GFZ namespace' => [
            '10.5880/GFZ.DEKORP-1-8701.001',
            [LegacyMetaworksDatacenterLookupService::DEKORP_DATACENTER],
        ],
        'DIGIS DOI' => [
            '10.5880/digis.e.2024.001',
            [LegacyMetaworksDatacenterLookupService::DIGIS_DATACENTER],
        ],
        'DIGIS compact DOI' => [
            '10.5880/digis2024.002',
            [LegacyMetaworksDatacenterLookupService::DIGIS_DATACENTER],
        ],
        'EnMAP DOI' => [
            '10.5880/enmap.2024.001',
            [LegacyMetaworksDatacenterLookupService::ENMAP_DATACENTER],
        ],
        'FID GEO DOI' => [
            '10.5880/fidgeo.2026.059',
            [LegacyMetaworksDatacenterLookupService::FID_GEO_DATACENTER],
        ],
        'FID GEO short DOI' => [
            '10.5880/fid.2018.006',
            [LegacyMetaworksDatacenterLookupService::FID_GEO_DATACENTER],
        ],
        'FID GEO DOI with GFZ namespace' => [
            '10.5880/GFZ-fidgeo.blueprint',
            [LegacyMetaworksDatacenterLookupService::FID_GEO_DATACENTER],
        ],
        'GIPP-MT DOI' => [
            '10.5880/GIPP-MT.202403.1',
            [LegacyMetaworksDatacenterLookupService::GIPP_DATACENTER],
        ],
        'ICGEM DOI inside COST-G namespace' => [
            '10.5880/COST-G.ICGEM_02_L2',
            [LegacyMetaworksDatacenterLookupService::ICGEM_DATACENTER],
        ],
        'IGETS DOI' => [
            '10.5880/igets.2024.001',
            [LegacyMetaworksDatacenterLookupService::IGETS_DATACENTER],
        ],
        'INTERMAGNET DOI' => [
            '10.5880/intermagnet.2025.001',
            [LegacyMetaworksDatacenterLookupService::INTERMAGNET_DATACENTER],
        ],
        'ISDC DOI' => [
            '10.5880/isdc.2024.001',
            [LegacyMetaworksDatacenterLookupService::ISDC_DATACENTER],
        ],
        'ISG DOI' => [
            '10.5880/isg.2024.001',
            [LegacyMetaworksDatacenterLookupService::ISG_DATACENTER],
        ],
        'PIK DOI' => [
            '10.5880/pik.2024.001',
            [LegacyMetaworksDatacenterLookupService::PIK_DATACENTER],
        ],
        'Riesgos DOI' => [
            '10.5880/riesgos.2024.001',
            [LegacyMetaworksDatacenterLookupService::RIESGOS_DATACENTER],
        ],
        'SDDB DOI with GFZ namespace' => [
            '10.1594/GFZ.SDDB.1003',
            [LegacyMetaworksDatacenterLookupService::SDDB_DATACENTER],
        ],
        'SFB806 DOI' => [
            '10.5880/SFB806.2024.001',
            [LegacyMetaworksDatacenterLookupService::SFB806_DATACENTER],
        ],
        'CRC806 DOI' => [
            '10.5880/CRC806.2024.001',
            [LegacyMetaworksDatacenterLookupService::SFB806_DATACENTER],
        ],
        'SPP 2238 hyphen DOI' => [
            '10.5880/SPP-2238.2024.001',
            [LegacyMetaworksDatacenterLookupService::SPP2238_DATACENTER],
        ],
        'SPP 2238 compact DOI' => [
            '10.5880/spp2238.2024.001',
            [LegacyMetaworksDatacenterLookupService::SPP2238_DATACENTER],
        ],
        'TERENO DOI' => [
            '10.5880/tereno.2024.001',
            [LegacyMetaworksDatacenterLookupService::TERENO_DATACENTER],
        ],
        'TR32DB DOI' => [
            '10.5880/TR32DB.2024.001',
            [LegacyMetaworksDatacenterLookupService::TR32DB_DATACENTER],
        ],
        'TRR228DB DOI' => [
            '10.5880/TRR228DB.2024.001',
            [LegacyMetaworksDatacenterLookupService::TRR228DB_DATACENTER],
        ],
        'WDS DOI using WSM namespace' => [
            '10.5880/WSM.2025.001',
            [LegacyMetaworksDatacenterLookupService::WDS_DATACENTER],
        ],
        'WDS DOI' => [
            '10.5880/wds.2025.001',
            [LegacyMetaworksDatacenterLookupService::WDS_DATACENTER],
        ],
    ]);

    it('normalises DOI URLs and doi scheme before matching datacenter patterns', function (string $doi): void {
        $service = app(LegacyMetaworksDatacenterLookupService::class);

        expect($service->resolveDatacenterNames($doi))
            ->toBe([LegacyMetaworksDatacenterLookupService::ARBODAT_DATACENTER]);
    })->with([
        'https DOI URL' => ['https://doi.org/10.5880/hA-ArboDat_AK1'],
        'http dx.doi URL' => ['http://dx.doi.org/10.5880/hA-ArboDat_AK1'],
        'doi scheme' => ['doi:10.5880/hA-ArboDat_AK1'],
    ]);

    it('delegates DOI normalization after stripping the doi scheme prefix', function () {
        $doiSuggestionService = Mockery::mock(DoiSuggestionService::class);
        $doiSuggestionService
            ->shouldReceive('normalizeDoi')
            ->once()
            ->with('10.5880/hA-ArboDat_AK1')
            ->andReturn('10.5880/ha-arbodat_ak1');

        $service = new LegacyMetaworksDatacenterLookupService($doiSuggestionService);

        expect($service->resolveDatacenterNames('doi:10.5880/hA-ArboDat_AK1'))
            ->toBe([LegacyMetaworksDatacenterLookupService::ARBODAT_DATACENTER]);
    });

    it('matches legacy Metaworks table DOIs case-insensitively', function () {
        DB::connection('legacy_metaworks')->table('gipp_dataset')->insert([
            'doi' => '10.5880/Legacy.Table.Mixed',
        ]);

        $service = app(LegacyMetaworksDatacenterLookupService::class);

        expect($service->resolveDatacenterNames('https://doi.org/10.5880/legacy.table.mixed'))
            ->toBe([LegacyMetaworksDatacenterLookupService::GIPP_DATACENTER]);
    });

    it('does not infer the GFZ datacenter from GFZ-prefixed specialist DOI namespaces', function () {
        $service = app(LegacyMetaworksDatacenterLookupService::class);

        expect($service->resolveDatacenterNames('10.5880/GFZ.DEKORP-1-8701.001'))
            ->toBe([LegacyMetaworksDatacenterLookupService::DEKORP_DATACENTER])
            ->not->toContain(LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER);
    });

    it('uses DOI pattern datacenters when the legacy Metaworks tables are unavailable', function () {
        Schema::connection('legacy_metaworks')->drop('gipp_dataset');

        $service = app(LegacyMetaworksDatacenterLookupService::class);

        expect($service->resolveDatacenterNames('10.5880/GFZ.DEKORP-1-8701.001'))
            ->toBe([LegacyMetaworksDatacenterLookupService::DEKORP_DATACENTER]);
    });

    it('deduplicates datacenters when DOI pattern and Metaworks table point to the same datacenter', function () {
        DB::connection('legacy_metaworks')->table('gipp_dataset')->insert([
            'doi' => '10.5880/GIPP-MT.202403.1',
        ]);

        $service = app(LegacyMetaworksDatacenterLookupService::class);

        expect($service->resolveDatacenterNames('10.5880/GIPP-MT.202403.1'))
            ->toBe([LegacyMetaworksDatacenterLookupService::GIPP_DATACENTER]);
    });

    it('falls back to the GFZ datacenter when no specialised table contains the DOI', function () {
        $service = app(LegacyMetaworksDatacenterLookupService::class);

        expect($service->resolveDatacenterNames('10.5880/default.dataset'))
            ->toBe([LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER]);
    });

    it('syncs resolved datacenters onto the imported resource', function () {
        DB::connection('legacy_metaworks')->table('gipp_dataset')->insert([
            'doi' => '10.5880/gipp.sync',
        ]);

        $resource = Resource::factory()->create(['doi' => '10.5880/gipp.sync']);

        app(LegacyMetaworksDatacenterLookupService::class)->syncDatacenters($resource, '10.5880/gipp.sync');

        expect($resource->fresh()->datacenter?->name)
            ->toBe(LegacyMetaworksDatacenterLookupService::GIPP_DATACENTER);
    });

    it('syncs DOI pattern datacenters onto the imported resource', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/hA-ArboDat_AK1']);

        app(LegacyMetaworksDatacenterLookupService::class)->syncDatacenters($resource, '10.5880/hA-ArboDat_AK1');

        expect($resource->fresh()->datacenter?->name)
            ->toBe(LegacyMetaworksDatacenterLookupService::ARBODAT_DATACENTER);
    });
});
