<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\Resource;
use App\Services\LegacyMetaworksDatacenterLookupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

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
            $table->string('doi')->nullable();
        });

        Schema::connection('legacy_metaworks')->create('sddb_dataset', function (Blueprint $table): void {
            $table->id();
            $table->string('doi')->nullable();
        });

        Datacenter::query()->create(['name' => LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER]);
        Datacenter::query()->create(['name' => LegacyMetaworksDatacenterLookupService::GIPP_DATACENTER]);
        Datacenter::query()->create(['name' => LegacyMetaworksDatacenterLookupService::SDDB_DATACENTER]);
    });

    it('resolves GIPP and SDDB datacenters from true Metaworks tables', function () {
        DB::connection('legacy_metaworks')->table('gipp_dataset')->insert([
            'doi' => '10.5880/gipp.dataset',
        ]);
        DB::connection('legacy_metaworks')->table('sddb_dataset')->insert([
            'doi' => '10.5880/sddb.dataset',
        ]);

        $service = new LegacyMetaworksDatacenterLookupService;

        expect($service->resolveDatacenterNames('10.5880/gipp.dataset'))
            ->toBe([LegacyMetaworksDatacenterLookupService::GIPP_DATACENTER])
            ->and($service->resolveDatacenterNames('10.5880/sddb.dataset'))
            ->toBe([LegacyMetaworksDatacenterLookupService::SDDB_DATACENTER]);
    });

    it('falls back to the GFZ datacenter when no specialised table contains the DOI', function () {
        $service = new LegacyMetaworksDatacenterLookupService;

        expect($service->resolveDatacenterNames('10.5880/default.dataset'))
            ->toBe([LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER]);
    });

    it('syncs resolved datacenters onto the imported resource', function () {
        DB::connection('legacy_metaworks')->table('gipp_dataset')->insert([
            'doi' => '10.5880/gipp.sync',
        ]);

        $resource = Resource::factory()->create(['doi' => '10.5880/gipp.sync']);

        (new LegacyMetaworksDatacenterLookupService)->syncDatacenters($resource, '10.5880/gipp.sync');

        expect($resource->fresh()->datacenters->pluck('name')->all())
            ->toBe([LegacyMetaworksDatacenterLookupService::GIPP_DATACENTER]);
    });
});
