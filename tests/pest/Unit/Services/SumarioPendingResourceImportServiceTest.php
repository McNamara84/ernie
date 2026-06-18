<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\Resource;
use App\Models\User;
use App\Services\DoiSuggestionService;
use App\Services\LegacyLandingPageImportService;
use App\Services\LegacyMetaworksDatacenterLookupService;
use App\Services\MetaworksDownloadUrlService;
use App\Services\OldDatasetEditorLoader;
use App\Services\ResourceStorageService;
use App\Services\SumarioPendingResourceImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('SumarioPendingResourceImportService', function () {
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
            $table->string('identifier')->nullable();
            $table->integer('publicationyear')->nullable();
            $table->string('title')->nullable();
        });
    });

    it('imports pending SUMARIO resources as review resources without publishing the landing page', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 55,
            'publicstatus' => 'pending',
            'identifier' => '10.5880/pending.one',
            'publicationyear' => 2024,
            'title' => 'Legacy Pending Dataset',
        ]);

        $user = User::factory()->create();
        $datacenter = Datacenter::query()->create([
            'name' => LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER,
        ]);

        $editorLoader = Mockery::mock(OldDatasetEditorLoader::class);
        $editorLoader
            ->shouldReceive('loadForEditor')
            ->once()
            ->with(55)
            ->andReturn([
                'doi' => '10.5880/pending.one',
                'year' => '2024',
                'version' => '1.0',
                'language' => 'en',
                'resourceType' => '1',
                'titles' => [
                    ['title' => 'Legacy Pending Dataset', 'titleType' => 'main-title'],
                ],
                'initialRights' => [],
                'authors' => [
                    [
                        'type' => 'person',
                        'firstName' => 'Jane',
                        'lastName' => 'Doe',
                        'isContact' => true,
                        'email' => 'jane@example.org',
                        'position' => 0,
                    ],
                ],
                'contributors' => [],
                'descriptions' => [],
                'dates' => [],
                'gcmdKeywords' => [],
                'freeKeywords' => [],
                'geoLocations' => [],
                'relatedWorks' => [],
                'fundingReferences' => [],
                'mslLaboratories' => [],
            ]);

        $resourceStorage = Mockery::mock(ResourceStorageService::class);
        $resourceStorage
            ->shouldReceive('store')
            ->once()
            ->andReturnUsing(function (array $payload, int $userId) use ($user, $datacenter): array {
                expect($userId)->toBe($user->id)
                    ->and($payload['doi'])->toBe('10.5880/pending.one')
                    ->and($payload['authors'][0]['isContact'])->toBeTrue()
                    ->and($payload['authors'][0]['email'])->toBe('jane@example.org')
                    ->and($payload['datacenters'])->toBe([$datacenter->id]);

                return [
                    Resource::factory()->create(['doi' => $payload['doi']]),
                    false,
                ];
            });

        $datacenterLookup = Mockery::mock(LegacyMetaworksDatacenterLookupService::class);
        $datacenterLookup
            ->shouldReceive('resolveDatacenterIds')
            ->once()
            ->with('10.5880/pending.one')
            ->andReturn([$datacenter->id]);

        $downloadUrlService = Mockery::mock(MetaworksDownloadUrlService::class);
        $downloadUrlService
            ->shouldReceive('lookupFileEntries')
            ->once()
            ->with('10.5880/pending.one')
            ->andReturn([
                'files' => [
                    [
                        'url' => 'https://datapub.gfz.de/pending-one.zip',
                        'label' => 'Pending package',
                        'visible' => 'public',
                    ],
                ],
                'allPublic' => true,
            ]);

        $service = new SumarioPendingResourceImportService(
            editorLoader: $editorLoader,
            resourceStorage: $resourceStorage,
            datacenterLookup: $datacenterLookup,
            downloadUrlService: $downloadUrlService,
            landingPageImport: new LegacyLandingPageImportService,
            doiSuggestionService: app(DoiSuggestionService::class),
        );

        $result = $service->importPendingByDoi('https://doi.org/10.5880/PENDING.ONE', $user->id);

        $resource = $result['resource']?->fresh(['landingPage']);

        expect($result['status'])->toBe('imported')
            ->and($resource)->not->toBeNull()
            ->and($resource->legacy_source)->toBe('sumario-pmd')
            ->and($resource->legacy_source_id)->toBe(55)
            ->and($resource->legacy_source_status)->toBe('pending')
            ->and($resource->force_review_status)->toBeTrue()
            ->and($resource->publicStatus())->toBe('review')
            ->and($resource->landingPage)->not->toBeNull()
            ->and($resource->landingPage->is_published)->toBeFalse()
            ->and($resource->landingPage->ftp_url)->toBe('https://datapub.gfz.de/pending-one.zip');
    });

    it('uses DOI pattern datacenters when importing mixed-case pending SUMARIO resources', function () {
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

        DB::connection('metaworks')->table('resource')->insert([
            'id' => 57,
            'publicstatus' => 'pending',
            'identifier' => '10.5880/hA-ArboDat_AK1',
            'publicationyear' => 2024,
            'title' => 'ArboDat Pending Dataset',
        ]);

        $user = User::factory()->create();
        Datacenter::query()->create([
            'name' => LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER,
        ]);
        $arbodat = Datacenter::query()->create([
            'name' => LegacyMetaworksDatacenterLookupService::ARBODAT_DATACENTER,
        ]);

        $editorLoader = Mockery::mock(OldDatasetEditorLoader::class);
        $editorLoader
            ->shouldReceive('loadForEditor')
            ->once()
            ->with(57)
            ->andReturn([
                'doi' => '10.5880/hA-ArboDat_AK1',
                'year' => '2024',
                'language' => 'en',
                'titles' => [
                    ['title' => 'ArboDat Pending Dataset', 'titleType' => 'main-title'],
                ],
                'initialRights' => [],
                'authors' => [],
                'contributors' => [],
                'descriptions' => [],
                'dates' => [],
                'gcmdKeywords' => [],
                'freeKeywords' => [],
                'geoLocations' => [],
                'relatedWorks' => [],
                'fundingReferences' => [],
                'mslLaboratories' => [],
            ]);

        $resourceStorage = Mockery::mock(ResourceStorageService::class);
        $resourceStorage
            ->shouldReceive('store')
            ->once()
            ->andReturnUsing(function (array $payload, int $userId) use ($user, $arbodat): array {
                expect($userId)->toBe($user->id)
                    ->and($payload['doi'])->toBe('10.5880/ha-arbodat_ak1')
                    ->and($payload['datacenters'])->toBe([$arbodat->id]);

                return [
                    Resource::factory()->create(['doi' => $payload['doi']]),
                    false,
                ];
            });

        $downloadUrlService = Mockery::mock(MetaworksDownloadUrlService::class);
        $downloadUrlService
            ->shouldReceive('lookupFileEntries')
            ->once()
            ->with('10.5880/ha-arbodat_ak1')
            ->andReturn(['files' => [], 'allPublic' => false]);

        $service = new SumarioPendingResourceImportService(
            editorLoader: $editorLoader,
            resourceStorage: $resourceStorage,
            datacenterLookup: new LegacyMetaworksDatacenterLookupService,
            downloadUrlService: $downloadUrlService,
            landingPageImport: new LegacyLandingPageImportService,
            doiSuggestionService: app(DoiSuggestionService::class),
        );

        $result = $service->importPendingByDoi('10.5880/hA-ArboDat_AK1', $user->id);

        expect($result['status'])->toBe('imported');
    });

    it('skips a pending SUMARIO resource when the DOI already exists in ERNIE', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 56,
            'publicstatus' => 'pending',
            'identifier' => '10.5880/pending.existing',
        ]);
        Resource::factory()->create(['doi' => '10.5880/pending.existing']);

        $service = new SumarioPendingResourceImportService(
            editorLoader: Mockery::mock(OldDatasetEditorLoader::class),
            resourceStorage: Mockery::mock(ResourceStorageService::class),
            datacenterLookup: Mockery::mock(LegacyMetaworksDatacenterLookupService::class),
            downloadUrlService: Mockery::mock(MetaworksDownloadUrlService::class),
            landingPageImport: new LegacyLandingPageImportService,
            doiSuggestionService: app(DoiSuggestionService::class),
        );

        $result = $service->importPendingByDoi('10.5880/pending.existing', 1);

        expect($result['status'])->toBe('skipped');
    });
});
