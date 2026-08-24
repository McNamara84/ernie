<?php

declare(strict_types=1);

use App\Enums\ResourceWorkflowStatus;
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
            $table->string('identifier')->nullable()->collation('NOCASE');
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
                'language' => '',
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
                    ->and($payload['language'])->toBeNull()
                    ->and($payload['authors'][0]['isContact'])->toBeTrue()
                    ->and($payload['authors'][0]['email'])->toBe('jane@example.org')
                    ->and($payload['datacenter_id'])->toBe($datacenter->id);

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

    it('passes high-cardinality and repeated legacy metadata to storage without truncation', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 77,
            'publicstatus' => 'pending',
            'identifier' => '10.5880/legacy.high-cardinality',
            'publicationyear' => 2024,
            'title' => 'High-cardinality Legacy Dataset',
        ]);

        $user = User::factory()->create();
        $datacenter = Datacenter::query()->create([
            'name' => LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER,
        ]);
        $authors = array_map(
            fn (int $index): array => ['type' => 'person', 'lastName' => "Author {$index}"],
            range(1, 101),
        );
        $contributors = array_map(
            fn (int $index): array => ['type' => 'person', 'lastName' => "Contributor {$index}", 'roles' => ['Other']],
            range(1, 101),
        );
        $coverages = array_map(
            fn (int $index): array => ['type' => 'point', 'latMin' => (string) $index, 'lonMin' => (string) -$index],
            range(1, 201),
        );
        $relatedWorks = array_map(
            fn (int $index): array => [
                'identifier' => "10.5880/related.{$index}",
                'identifierType' => 'DOI',
                'relationType' => 'References',
            ],
            range(1, 101),
        );
        $dates = array_map(
            fn (int $index): array => [
                'dateType' => 'Collected',
                'dateMode' => 'single',
                'startDate' => '2024-01-01',
                'dateInformation' => "Collection event {$index}",
            ],
            range(1, 61),
        );

        $editorLoader = Mockery::mock(OldDatasetEditorLoader::class);
        $editorLoader->shouldReceive('loadForEditor')->once()->with(77)->andReturn([
            'doi' => '10.5880/legacy.high-cardinality',
            'year' => '2024',
            'titles' => [['title' => 'High-cardinality Legacy Dataset', 'titleType' => 'main-title']],
            'initialRights' => [],
            'authors' => $authors,
            'contributors' => $contributors,
            'descriptions' => [
                ['type' => 'Abstract', 'description' => 'First legacy abstract'],
                ['type' => 'Abstract', 'description' => 'Second legacy abstract'],
            ],
            'dates' => $dates,
            'gcmdKeywords' => [],
            'freeKeywords' => [],
            'geoLocations' => $coverages,
            'relatedWorks' => $relatedWorks,
            'fundingReferences' => [],
            'mslLaboratories' => [],
        ]);

        $resourceStorage = Mockery::mock(ResourceStorageService::class);
        $resourceStorage->shouldReceive('store')->once()->andReturnUsing(function (array $payload) use ($datacenter): array {
            expect($payload['authors'])->toHaveCount(101)
                ->and($payload['authors'][100]['lastName'])->toBe('Author 101')
                ->and($payload['contributors'])->toHaveCount(101)
                ->and($payload['contributors'][100]['lastName'])->toBe('Contributor 101')
                ->and($payload['spatialTemporalCoverages'])->toHaveCount(201)
                ->and($payload['spatialTemporalCoverages'][200]['latMin'])->toBe('201')
                ->and($payload['relatedIdentifiers'])->toHaveCount(101)
                ->and($payload['descriptions'])->toHaveCount(2)
                ->and(array_column($payload['descriptions'], 'descriptionType'))->toBe(['abstract', 'abstract'])
                ->and($payload['dates'])->toHaveCount(61)
                ->and($payload['dates'][60]['dateInformation'])->toBe('Collection event 61');

            return [
                Resource::factory()->create([
                    'doi' => $payload['doi'],
                    'datacenter_id' => $datacenter->id,
                ]),
                false,
            ];
        });

        $datacenterLookup = Mockery::mock(LegacyMetaworksDatacenterLookupService::class);
        $datacenterLookup->shouldReceive('resolveDatacenterIds')->once()->andReturn([$datacenter->id]);
        $downloadUrlService = Mockery::mock(MetaworksDownloadUrlService::class);
        $downloadUrlService->shouldReceive('lookupFileEntries')->once()->andReturn(['files' => [], 'allPublic' => false]);

        $service = new SumarioPendingResourceImportService(
            editorLoader: $editorLoader,
            resourceStorage: $resourceStorage,
            datacenterLookup: $datacenterLookup,
            downloadUrlService: $downloadUrlService,
            landingPageImport: new LegacyLandingPageImportService,
            doiSuggestionService: app(DoiSuggestionService::class),
        );

        $result = $service->importPendingByDoi('10.5880/legacy.high-cardinality', $user->id);

        expect($result['status'])->toBe('imported');
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
            $table->string('doi')->nullable()->collation('NOCASE');
        });

        Schema::connection('legacy_metaworks')->create('sddb_dataset', function (Blueprint $table): void {
            $table->id();
            $table->string('doi')->nullable()->collation('NOCASE');
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
                    ->and($payload['language'])->toBe('en')
                    ->and($payload['datacenter_id'])->toBe($arbodat->id);

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
            datacenterLookup: app(LegacyMetaworksDatacenterLookupService::class),
            downloadUrlService: $downloadUrlService,
            landingPageImport: new LegacyLandingPageImportService,
            doiSuggestionService: app(DoiSuggestionService::class),
        );

        $result = $service->importPendingByDoi('10.5880/hA-ArboDat_AK1', $user->id);

        expect($result['status'])->toBe('imported');
    });

    it('assigns canonical GEOFON datacenters during pending SUMARIO imports', function (
        int $legacyResourceId,
        string $storedDoi,
        string $requestedDoi,
        string $expectedDoi,
        string $expectedDatacenter,
    ) {
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

        DB::connection('metaworks')->table('resource')->insert([
            'id' => $legacyResourceId,
            'publicstatus' => 'pending',
            'identifier' => $storedDoi,
            'publicationyear' => 2025,
            'title' => 'Pending GEOFON Resource',
        ]);

        $user = User::factory()->create();
        $editorLoader = Mockery::mock(OldDatasetEditorLoader::class);
        $editorLoader
            ->shouldReceive('loadForEditor')
            ->once()
            ->with($legacyResourceId)
            ->andReturn([
                'doi' => $storedDoi,
                'year' => '2025',
                'language' => 'en',
                'titles' => [
                    ['title' => 'Pending GEOFON Resource', 'titleType' => 'main-title'],
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
            ->andReturnUsing(function (array $payload, int $userId) use ($user, $expectedDoi, $expectedDatacenter): array {
                $datacenter = Datacenter::query()
                    ->whereKey($payload['datacenter_id'])
                    ->firstOrFail();

                expect($userId)->toBe($user->id)
                    ->and($payload['doi'])->toBe($expectedDoi)
                    ->and($datacenter->name)->toBe($expectedDatacenter);

                return [
                    Resource::factory()->create([
                        'doi' => $payload['doi'],
                        'datacenter_id' => $datacenter->id,
                    ]),
                    false,
                ];
            });

        $downloadUrlService = Mockery::mock(MetaworksDownloadUrlService::class);
        $downloadUrlService
            ->shouldReceive('lookupFileEntries')
            ->once()
            ->with($expectedDoi)
            ->andReturn(['files' => [], 'allPublic' => false]);

        $service = new SumarioPendingResourceImportService(
            editorLoader: $editorLoader,
            resourceStorage: $resourceStorage,
            datacenterLookup: app(LegacyMetaworksDatacenterLookupService::class),
            downloadUrlService: $downloadUrlService,
            landingPageImport: new LegacyLandingPageImportService,
            doiSuggestionService: app(DoiSuggestionService::class),
        );

        $result = $service->importPendingByDoi($requestedDoi, $user->id);
        $resource = $result['resource'];

        if (! $resource instanceof Resource) {
            throw new RuntimeException('Expected the pending GEOFON resource to be imported.');
        }

        expect($result['status'])->toBe('imported')
            ->and($resource->fresh()->datacenter?->name)->toBe($expectedDatacenter)
            ->and(Datacenter::query()->where('name', $expectedDatacenter)->count())->toBe(1);
    })->with([
        'GEOFON seismic network' => [
            61,
            '10.14470/RV968923',
            'https://doi.org/10.14470/RV968923',
            '10.14470/rv968923',
            LegacyMetaworksDatacenterLookupService::GEOFON_NETWORKS_DATACENTER,
        ],
        'GEOFON seismic event' => [
            62,
            '10.1594/GFZ.GEOFON.GFZ2009GIBB',
            '10.1594/GFZ.GEOFON.GFZ2009GIBB',
            '10.1594/gfz.geofon.gfz2009gibb',
            LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER,
        ],
    ]);

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

    it('skips pending SUMARIO resources whose DOI contains test or delete', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 58,
            'publicstatus' => 'pending',
            'identifier' => '10.5880/fidgeo.test.to.be.deleted',
        ]);

        $editorLoader = Mockery::mock(OldDatasetEditorLoader::class);
        $editorLoader->shouldNotReceive('loadForEditor');

        $resourceStorage = Mockery::mock(ResourceStorageService::class);
        $resourceStorage->shouldNotReceive('store');

        $downloadUrlService = Mockery::mock(MetaworksDownloadUrlService::class);
        $downloadUrlService->shouldNotReceive('lookupFileEntries');

        $service = new SumarioPendingResourceImportService(
            editorLoader: $editorLoader,
            resourceStorage: $resourceStorage,
            datacenterLookup: Mockery::mock(LegacyMetaworksDatacenterLookupService::class),
            downloadUrlService: $downloadUrlService,
            landingPageImport: new LegacyLandingPageImportService,
            doiSuggestionService: app(DoiSuggestionService::class),
        );

        $result = $service->importPendingByDoi('10.5880/fidgeo.test.to.be.deleted', 1);

        expect($result['status'])->toBe('skipped')
            ->and($result['doi'])->toBe('10.5880/fidgeo.test.to.be.deleted')
            ->and(Resource::where('doi', '10.5880/fidgeo.test.to.be.deleted')->exists())->toBeFalse();
    });

    it('imports DOI-less pending records once as explicit drafts keyed by their legacy id', function () {
        DB::connection('metaworks')->table('resource')->insert([
            'id' => 91,
            'publicstatus' => 'pending',
            'identifier' => null,
            'publicationyear' => 2024,
            'title' => 'Local legacy draft',
        ]);

        $user = User::factory()->create();
        $editorLoader = Mockery::mock(OldDatasetEditorLoader::class);
        $editorLoader
            ->shouldReceive('loadForEditor')
            ->once()
            ->with(91)
            ->andReturn([
                'doi' => '',
                'year' => '2024',
                'titles' => [['title' => 'Local legacy draft', 'titleType' => 'main-title']],
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
            ->andReturnUsing(function (array $payload, int $userId) use ($user): array {
                expect($userId)->toBe($user->id)
                    ->and($payload['doi'])->toBeNull()
                    ->and($payload['datacenter_id'])->toBeNull();

                return [Resource::factory()->create(['doi' => null]), false];
            });

        $datacenterLookup = Mockery::mock(LegacyMetaworksDatacenterLookupService::class);
        $datacenterLookup->shouldNotReceive('resolveDatacenterIds');
        $downloadUrlService = Mockery::mock(MetaworksDownloadUrlService::class);
        $downloadUrlService->shouldNotReceive('lookupFileEntries');

        $service = new SumarioPendingResourceImportService(
            editorLoader: $editorLoader,
            resourceStorage: $resourceStorage,
            datacenterLookup: $datacenterLookup,
            downloadUrlService: $downloadUrlService,
            landingPageImport: new LegacyLandingPageImportService,
            doiSuggestionService: app(DoiSuggestionService::class),
        );

        expect($service->countImportablePending())->toBe(1);

        $firstRun = $service->importAllPending($user->id);
        $secondRun = $service->importAllPending($user->id);
        $resource = Resource::query()
            ->where('legacy_source', 'sumario-pmd')
            ->where('legacy_source_id', 91)
            ->with('landingPage')
            ->firstOrFail();

        expect($firstRun)->toMatchArray([
            'processed' => 1,
            'imported' => 1,
            'skipped' => 0,
            'failed' => 0,
        ])
            ->and($secondRun)->toMatchArray([
                'processed' => 1,
                'imported' => 0,
                'skipped' => 1,
                'failed' => 0,
                'skipped_dois' => ['legacy:sumario-pmd:91'],
            ])
            ->and($resource->doi)->toBeNull()
            ->and($resource->workflow_status_override)->toBe(ResourceWorkflowStatus::DRAFT)
            ->and($resource->force_review_status)->toBeFalse()
            ->and($resource->publicStatus())->toBe('draft')
            ->and($resource->landingPage)->not->toBeNull()
            ->and($resource->landingPage->is_published)->toBeFalse()
            ->and(Resource::query()->where('legacy_source', 'sumario-pmd')->where('legacy_source_id', 91)->count())->toBe(1);
    });
});
