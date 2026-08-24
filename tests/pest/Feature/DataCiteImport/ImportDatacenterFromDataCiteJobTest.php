<?php

declare(strict_types=1);

use App\Enums\CitationLabelResolutionMode;
use App\Enums\UserRole;
use App\Jobs\ImportFromDataCiteJob;
use App\Models\Datacenter;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteImportService;
use App\Services\DataCiteToResourceTransformer;
use App\Services\GfzDataServicesPortalService;
use App\Services\LegacyMetaworksDatacenterLookupService;
use App\Services\LegacyResourceLookupService;
use App\Services\MetaworksDownloadUrlService;
use App\Services\SumarioPendingResourceImportService;
use App\Services\SumarioPmdContactEnrichmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => UserRole::ADMIN]);

    $this->importService = Mockery::mock(DataCiteImportService::class);
    $this->app->instance(DataCiteImportService::class, $this->importService);

    $this->transformer = Mockery::mock(DataCiteToResourceTransformer::class);
    $this->transformer
        ->shouldReceive('prepareDoiData')
        ->zeroOrMoreTimes()
        ->andReturnUsing(fn (
            array $doiRecord,
            array $_legacyRelatedIdentifiers = [],
            CitationLabelResolutionMode $_mode = CitationLabelResolutionMode::BEST_EFFORT,
        ): array => $doiRecord)
        ->byDefault();
    $this->app->instance(DataCiteToResourceTransformer::class, $this->transformer);

    $this->metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
    $this->metaworksService
        ->shouldReceive('lookupFileEntries')
        ->zeroOrMoreTimes()
        ->andReturn(['files' => [], 'allPublic' => false, 'resourceFound' => false])
        ->byDefault();
    $this->app->instance(MetaworksDownloadUrlService::class, $this->metaworksService);

    $this->pendingImportService = Mockery::mock(SumarioPendingResourceImportService::class);
    $this->app->instance(SumarioPendingResourceImportService::class, $this->pendingImportService);

    $this->contactEnrichmentService = Mockery::mock(SumarioPmdContactEnrichmentService::class);
    $this->contactEnrichmentService
        ->shouldReceive('enrich')
        ->zeroOrMoreTimes()
        ->andReturnFalse()
        ->byDefault();
    $this->app->instance(SumarioPmdContactEnrichmentService::class, $this->contactEnrichmentService);

    $this->datacenterLookupService = Mockery::mock(LegacyMetaworksDatacenterLookupService::class);
    $this->datacenterLookupService
        ->shouldReceive('syncDatacenters')
        ->zeroOrMoreTimes()
        ->andReturnNull()
        ->byDefault();
    $this->app->instance(LegacyMetaworksDatacenterLookupService::class, $this->datacenterLookupService);

    $this->portalService = Mockery::mock(GfzDataServicesPortalService::class);
    $this->app->instance(GfzDataServicesPortalService::class, $this->portalService);
});

afterEach(function () {
    Mockery::close();
});

function datacenterDoiRecord(string $doi): array
{
    return [
        'id' => $doi,
        'attributes' => [
            'doi' => $doi,
            'titles' => [['title' => "Resource {$doi}"]],
            'publicationYear' => 2024,
            'types' => ['resourceTypeGeneral' => 'Dataset'],
        ],
    ];
}

describe('datacenter-scoped DataCite import job', function () {
    it('imports the complete DOME manifest as eight published and three review resources', function () {
        $datacenterName = 'SPP 2238 - Dynamics of Ore Metals Enrichment - DOME';
        $publishedDois = [
            '10.5880/fidgeo.d.2022.014',
            '10.5880/fidgeo.d.2023.001',
            '10.5880/fidgeo.d.2024.001',
            '10.5880/fidgeo.d.2024.002',
            '10.5880/fidgeo.d.2025.001',
            '10.5880/fidgeo.d.2025.002',
            '10.5880/fidgeo.d.2025.003',
            '10.5880/fidgeo.d.2026.001',
        ];
        $pendingDois = [
            '10.5880/fidgeo.d.2025.004',
            '10.5880/fidgeo.d.2026.002',
            '10.5880/fidgeo.d.2026.003',
        ];
        $portalTargets = array_fill_keys($publishedDois, [$datacenterName]);

        $this->portalService
            ->shouldReceive('resourcesForDatacenter')
            ->once()
            ->with('DOIDB.DOME')
            ->andReturn([
                'datacenter' => [
                    'id' => 'DOIDB.DOME',
                    'name' => $datacenterName,
                    'resource_count' => 8,
                ],
                'resources' => $portalTargets,
            ]);
        $this->pendingImportService
            ->shouldReceive('importablePendingDoisForDatacenter')
            ->once()
            ->with($datacenterName)
            ->andReturn($pendingDois);

        $legacyLookup = Mockery::mock(LegacyResourceLookupService::class);
        $legacyLookup
            ->shouldReceive('importMetadataByDoi')
            ->times(8)
            ->andReturn(['relatedIdentifiers' => [], 'subjects' => []]);
        $this->app->instance(LegacyResourceLookupService::class, $legacyLookup);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () use ($publishedDois) {
                foreach ($publishedDois as $doi) {
                    $record = datacenterDoiRecord($doi);
                    $record['attributes']['state'] = 'findable';
                    $record['attributes']['url'] = 'https://dataservices.gfz.de/dome/showshort.php?id=legacy';
                    $record['attributes']['rightsList'] = [['rights' => 'CC BY 4.0']];
                    yield $record;
                }
            })());
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->times(3)
            ->withArgs(fn (string $doi): bool => in_array($doi, $pendingDois, true))
            ->andReturnNull();

        $this->transformer
            ->shouldReceive('transform')
            ->times(8)
            ->andReturnUsing(fn (array $record): Resource => Resource::factory()->create([
                'doi' => $record['attributes']['doi'],
                // Exercise the publication/status precedence for legacy records
                // that do not yet satisfy newer ERNIE completeness requirements.
                'access_level' => null,
            ]));

        $this->metaworksService
            ->shouldReceive('lookupFileEntries')
            ->times(8)
            ->andReturnUsing(function (string $doi): array {
                $downloadUrl = $doi === '10.5880/fidgeo.d.2026.001'
                    ? 'https://datapub.gfz.de/download/10.5880.FIDGEO.D.2026.001-lagvge'
                    : 'https://datapub.gfz.de/download/'.str_replace('/', '.', $doi);

                return [
                    'files' => [[
                        'url' => $downloadUrl,
                        'label' => 'Data and description',
                        'visible' => 'public',
                    ]],
                    'allPublic' => true,
                    'resourceFound' => true,
                    'hasFileRows' => true,
                    'resourcePublicStatus' => 'released',
                ];
            });

        $domeDatacenter = Datacenter::query()->create(['name' => $datacenterName]);
        $this->pendingImportService
            ->shouldReceive('importPendingByDoi')
            ->times(3)
            ->withArgs(fn (string $doi, int $userId): bool => in_array($doi, $pendingDois, true)
                && $userId === $this->user->id)
            ->andReturnUsing(function (string $doi) use ($domeDatacenter): array {
                $resource = Resource::factory()->create([
                    'doi' => $doi,
                    'datacenter_id' => $domeDatacenter->id,
                    'access_level' => null,
                    'legacy_source' => 'sumario-pmd',
                    'legacy_source_status' => 'pending',
                    'force_review_status' => true,
                ]);
                LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);

                return [
                    'status' => 'imported',
                    'resource' => $resource,
                    'doi' => $doi,
                    'error' => null,
                ];
            });

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, null, 'DOIDB.DOME'))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        $resources = Resource::query()
            ->whereIn('doi', [...$publishedDois, ...$pendingDois])
            ->with('landingPage')
            ->get();

        expect($status)->toMatchArray([
            'status' => 'completed',
            'total' => 11,
            'processed' => 11,
            'imported' => 11,
            'skipped' => 0,
            'failed' => 0,
            'sync_total' => 8,
            'sync_skipped_test_mode' => true,
        ])
            ->and($resources)->toHaveCount(11)
            ->and($resources->filter(fn (Resource $resource): bool => $resource->publicStatus() === 'published'))->toHaveCount(8)
            ->and($resources->filter(fn (Resource $resource): bool => $resource->publicStatus() === 'review'))->toHaveCount(3);

        $downloadLandingPage = $resources
            ->firstWhere('doi', '10.5880/fidgeo.d.2026.001')
            ?->landingPage;

        expect($downloadLandingPage)
            ->not->toBeNull()
            ->and($downloadLandingPage->is_published)->toBeTrue()
            ->and($downloadLandingPage->ftp_url)
            ->toBe('https://datapub.gfz.de/download/10.5880.FIDGEO.D.2026.001-lagvge');
    });

    it('imports only portal targets and assigns every portal facet to new resources', function () {
        $this->portalService
            ->shouldReceive('resourcesForDatacenter')
            ->once()
            ->with('ArboDat')
            ->andReturn([
                'datacenter' => [
                    'id' => 'ArboDat',
                    'name' => 'ArboDat 2016',
                    'resource_count' => 1,
                ],
                'resources' => [
                    '10.5880/selected' => ['ArboDat 2016', 'GFZ Data Services'],
                ],
            ]);
        $this->pendingImportService
            ->shouldReceive('importablePendingDoisForDatacenter')
            ->once()
            ->with('ArboDat 2016')
            ->andReturn([]);
        $streamAdvancedAfterFinalTarget = false;
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () use (&$streamAdvancedAfterFinalTarget) {
                yield datacenterDoiRecord('10.5880/not-selected');
                yield datacenterDoiRecord('10.5880/selected');
                $streamAdvancedAfterFinalTarget = true;
                yield datacenterDoiRecord('10.5880/after-final-target');
            })());
        $this->transformer
            ->shouldReceive('prepareDoiData')
            ->once()
            ->withArgs(fn (
                array $record,
                array $_legacyRelatedIdentifiers,
                CitationLabelResolutionMode $mode,
            ): bool => $record['attributes']['doi'] === '10.5880/selected'
                && $mode === CitationLabelResolutionMode::BEST_EFFORT)
            ->andReturnUsing(fn (array $record): array => $record);
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(
                fn (): Resource => Resource::factory()->create(['doi' => '10.5880/selected']),
            );

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, null, 'ArboDat'))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $resource = Resource::query()->where('doi', '10.5880/selected')->firstOrFail();
        $status = Cache::get("datacite_import:{$importId}");

        expect($status)
            ->toMatchArray([
                'status' => 'completed',
                'total' => 1,
                'processed' => 1,
                'imported' => 1,
                'skipped' => 0,
                'failed' => 0,
                'datacenter' => [
                    'id' => 'ArboDat',
                    'name' => 'ArboDat 2016',
                    'resource_count' => 1,
                ],
            ])
            ->and($resource->datacenter?->name)
            ->toBe('ArboDat 2016')
            ->and(Resource::query()->where('doi', '10.5880/not-selected')->exists())
            ->toBeFalse()
            ->and($streamAdvancedAfterFinalTarget)
            ->toBeFalse()
            ->and(Resource::query()->where('doi', '10.5880/after-final-target')->exists())
            ->toBeFalse();
    });

    it('does not start the DataCite bulk stream when there are no targets', function () {
        $this->portalService
            ->shouldReceive('resourcesForDatacenter')
            ->once()
            ->with('ArboDat')
            ->andReturn([
                'datacenter' => [
                    'id' => 'ArboDat',
                    'name' => 'ArboDat 2016',
                    'resource_count' => 0,
                ],
                'resources' => [],
            ]);
        $this->pendingImportService
            ->shouldReceive('importablePendingDoisForDatacenter')
            ->once()
            ->with('ArboDat 2016')
            ->andReturn([]);
        $this->importService->shouldReceive('fetchAllDois')->never();
        $this->importService->shouldReceive('fetchSingleDoi')->never();
        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, null, 'ArboDat'))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        expect(Cache::get("datacite_import:{$importId}"))
            ->toMatchArray([
                'status' => 'completed',
                'total' => 0,
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
            ]);
    });

    it('bulk-loads existing datacenter ids and caches newly resolved ids across resources', function () {
        Datacenter::query()->create(['name' => 'Existing portal datacenter']);

        $this->portalService
            ->shouldReceive('resourcesForDatacenter')
            ->once()
            ->with('Existing')
            ->andReturn([
                'datacenter' => [
                    'id' => 'Existing',
                    'name' => 'Existing portal datacenter',
                    'resource_count' => 2,
                ],
                'resources' => [
                    '10.5880/cache-one' => [
                        'Existing portal datacenter',
                        'New shared datacenter',
                    ],
                    '10.5880/cache-two' => [
                        'Existing portal datacenter',
                        'New shared datacenter',
                    ],
                ],
            ]);
        $this->pendingImportService
            ->shouldReceive('importablePendingDoisForDatacenter')
            ->once()
            ->with('Existing portal datacenter')
            ->andReturn([]);
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield datacenterDoiRecord('10.5880/cache-one');
                yield datacenterDoiRecord('10.5880/cache-two');
            })());
        $this->transformer
            ->shouldReceive('transform')
            ->twice()
            ->andReturnUsing(
                fn (array $doiRecord): Resource => Resource::factory()->create([
                    'doi' => $doiRecord['attributes']['doi'],
                ]),
            );

        $queries = [];
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $importId = Str::uuid()->toString();
            (new ImportFromDataCiteJob($this->user->id, $importId, null, 'Existing'))
                ->handle($this->importService, $this->transformer, $this->metaworksService);
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }

        $datacenterQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => preg_match(
                '/\b(?:from|into)\s+["`\[]?datacenters["`\]]?/i',
                $query['query'],
            ) === 1,
        ));

        $firstResourceDatacenter = Resource::query()
            ->where('doi', '10.5880/cache-one')
            ->firstOrFail()
            ->datacenter?->name;
        $secondResourceDatacenter = Resource::query()
            ->where('doi', '10.5880/cache-two')
            ->firstOrFail()
            ->datacenter?->name;

        expect($datacenterQueries)
            ->toHaveCount(1)
            ->and(Datacenter::query()->where('name', 'New shared datacenter')->count())
            ->toBe(0)
            ->and($firstResourceDatacenter)
            ->toBe('Existing portal datacenter')
            ->and($secondResourceDatacenter)
            ->toBe('Existing portal datacenter');
    });

    it('does not change datacenters on resources that already exist in ERNIE', function () {
        $legacyDatacenter = Datacenter::query()->create(['name' => 'Legacy assignment']);
        $existingResource = Resource::factory()->create(['doi' => '10.5880/existing']);
        $existingResource->update(['datacenter_id' => $legacyDatacenter->id]);

        $this->portalService
            ->shouldReceive('resourcesForDatacenter')
            ->once()
            ->andReturn([
                'datacenter' => [
                    'id' => 'ArboDat',
                    'name' => 'ArboDat 2016',
                    'resource_count' => 1,
                ],
                'resources' => [
                    '10.5880/existing' => ['ArboDat 2016'],
                ],
            ]);
        $this->pendingImportService
            ->shouldReceive('importablePendingDoisForDatacenter')
            ->once()
            ->with('ArboDat 2016')
            ->andReturn([]);
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield datacenterDoiRecord('10.5880/existing');
            })());
        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, null, 'ArboDat'))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");

        expect($status)
            ->toMatchArray([
                'status' => 'completed',
                'processed' => 1,
                'imported' => 0,
                'skipped' => 1,
            ])
            ->and($existingResource->fresh()->datacenter?->name)
            ->toBe('Legacy assignment')
            ->and(Datacenter::query()->where('name', 'ArboDat 2016')->exists())
            ->toBeFalse();
    });

    it('includes pending-only resources selected from the legacy database mapping', function () {
        $gfz = Datacenter::query()->create([
            'name' => LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER,
        ]);
        $pendingResource = Resource::factory()->create(['doi' => '10.5880/pending-gfz']);
        $pendingResource->update(['datacenter_id' => $gfz->id]);

        $this->portalService
            ->shouldReceive('resourcesForDatacenter')
            ->once()
            ->with('GFZ')
            ->andReturn([
                'datacenter' => [
                    'id' => 'GFZ',
                    'name' => LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER,
                    'resource_count' => 0,
                ],
                'resources' => [],
            ]);
        $this->pendingImportService
            ->shouldReceive('importablePendingDoisForDatacenter')
            ->once()
            ->with(LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER)
            ->andReturn(['10.5880/pending-gfz']);
        $this->pendingImportService
            ->shouldReceive('importPendingByDoi')
            ->once()
            ->with('10.5880/pending-gfz', $this->user->id)
            ->andReturn([
                'status' => 'imported',
                'resource' => $pendingResource,
                'doi' => '10.5880/pending-gfz',
                'error' => null,
            ]);
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                if (false) {
                    yield [];
                }
            })());
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/pending-gfz')
            ->andReturnNull();
        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, null, 'GFZ'))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");

        expect($status)
            ->toMatchArray([
                'status' => 'completed',
                'total' => 1,
                'processed' => 1,
                'imported' => 1,
                'failed' => 0,
            ])
            ->and($pendingResource->fresh()->datacenter?->name)
            ->toBe(LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER);
    });

    it('deduplicates portal and pending targets and gives the portal assignment precedence', function () {
        $gfz = Datacenter::query()->create([
            'name' => LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER,
        ]);
        $pendingResource = Resource::factory()->create(['doi' => '10.5880/shared']);
        $pendingResource->update(['datacenter_id' => $gfz->id]);

        $this->portalService
            ->shouldReceive('resourcesForDatacenter')
            ->once()
            ->andReturn([
                'datacenter' => [
                    'id' => 'ArboDat',
                    'name' => 'ArboDat 2016',
                    'resource_count' => 1,
                ],
                'resources' => [
                    '10.5880/shared' => ['ArboDat 2016'],
                ],
            ]);
        $this->pendingImportService
            ->shouldReceive('importablePendingDoisForDatacenter')
            ->once()
            ->with('ArboDat 2016')
            ->andReturn(['10.5880/shared']);
        $this->pendingImportService
            ->shouldReceive('importPendingByDoi')
            ->once()
            ->with('10.5880/shared', $this->user->id)
            ->andReturn([
                'status' => 'imported',
                'resource' => $pendingResource,
                'doi' => '10.5880/shared',
                'error' => null,
            ]);
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                if (false) {
                    yield [];
                }
            })());
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/shared')
            ->andReturnNull();
        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, null, 'ArboDat'))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");

        expect($status['total'])->toBe(1)
            ->and($status['processed'])->toBe(1)
            ->and($status['imported'])->toBe(1)
            ->and($pendingResource->fresh()->datacenter?->name)
            ->toBe('ArboDat 2016');
    });

    it('fails before local writes when pending selection is unavailable', function () {
        $this->portalService
            ->shouldReceive('resourcesForDatacenter')
            ->once()
            ->andReturn([
                'datacenter' => [
                    'id' => 'Riesgos',
                    'name' => 'Riesgos',
                    'resource_count' => 1,
                ],
                'resources' => [
                    '10.5880/riesgos' => ['Riesgos'],
                ],
            ]);
        $this->pendingImportService
            ->shouldReceive('importablePendingDoisForDatacenter')
            ->once()
            ->andThrow(new RuntimeException('legacy database unavailable'));
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->never();
        $this->transformer
            ->shouldReceive('transform')
            ->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId, null, 'Riesgos');

        expect(fn () => $job->handle($this->importService, $this->transformer, $this->metaworksService))
            ->toThrow(
                RuntimeException::class,
                'Matching SUMARIO pending resources could not be loaded: legacy database unavailable',
            );

        $status = Cache::get("datacite_import:{$importId}");

        expect($status)
            ->toMatchArray([
                'status' => 'failed',
                'error' => 'Matching SUMARIO pending resources could not be loaded: legacy database unavailable',
            ])
            ->and(Resource::query()->where('doi', '10.5880/riesgos')->exists())->toBeFalse();
    });

    it('uses the targeted DataCite lookup for portal resources missing from the bulk stream', function () {
        $this->portalService
            ->shouldReceive('resourcesForDatacenter')
            ->once()
            ->andReturn([
                'datacenter' => [
                    'id' => 'Riesgos',
                    'name' => 'Riesgos',
                    'resource_count' => 1,
                ],
                'resources' => [
                    '10.5880/riesgos-targeted' => ['Riesgos'],
                ],
            ]);
        $this->pendingImportService
            ->shouldReceive('importablePendingDoisForDatacenter')
            ->once()
            ->with('Riesgos')
            ->andReturn([]);
        $this->pendingImportService->shouldNotReceive('importPendingByDoi');
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                if (false) {
                    yield [];
                }
            })());
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/riesgos-targeted')
            ->andReturn(datacenterDoiRecord('10.5880/riesgos-targeted'));
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(
                fn (): Resource => Resource::factory()->create(['doi' => '10.5880/riesgos-targeted']),
            );

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, null, 'Riesgos'))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $resource = Resource::query()->where('doi', '10.5880/riesgos-targeted')->firstOrFail();
        $status = Cache::get("datacite_import:{$importId}");

        expect($status)
            ->toMatchArray([
                'status' => 'completed',
                'total' => 1,
                'processed' => 1,
                'imported' => 1,
                'failed' => 0,
            ])
            ->and($resource->datacenter?->name)
            ->toBe('Riesgos');
    });

    it('records a failure when a portal DOI exists in neither DataCite nor pending SUMARIO data', function () {
        $this->portalService
            ->shouldReceive('resourcesForDatacenter')
            ->once()
            ->andReturn([
                'datacenter' => [
                    'id' => 'Riesgos',
                    'name' => 'Riesgos',
                    'resource_count' => 1,
                ],
                'resources' => [
                    '10.5880/missing-everywhere' => ['Riesgos'],
                ],
            ]);
        $this->pendingImportService
            ->shouldReceive('importablePendingDoisForDatacenter')
            ->once()
            ->with('Riesgos')
            ->andReturn([]);
        $this->pendingImportService
            ->shouldReceive('importPendingByDoi')
            ->once()
            ->with('10.5880/missing-everywhere', $this->user->id)
            ->andReturn([
                'status' => 'missing',
                'resource' => null,
                'doi' => '10.5880/missing-everywhere',
                'error' => null,
            ]);
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                if (false) {
                    yield [];
                }
            })());
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/missing-everywhere')
            ->andReturnNull();
        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, null, 'Riesgos'))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");

        expect($status)
            ->toMatchArray([
                'status' => 'completed',
                'total' => 1,
                'processed' => 1,
                'imported' => 0,
                'failed' => 1,
                'failed_dois' => [[
                    'doi' => '10.5880/missing-everywhere',
                    'error' => 'The DOI was not found in DataCite or SUMARIO pending resources.',
                ]],
            ]);
    });

    it('stops scanning unrelated DataCite records after cancellation', function () {
        $this->portalService
            ->shouldReceive('resourcesForDatacenter')
            ->once()
            ->andReturn([
                'datacenter' => [
                    'id' => 'Riesgos',
                    'name' => 'Riesgos',
                    'resource_count' => 1,
                ],
                'resources' => [
                    '10.5880/riesgos-target' => ['Riesgos'],
                ],
            ]);
        $this->pendingImportService
            ->shouldReceive('importablePendingDoisForDatacenter')
            ->once()
            ->with('Riesgos')
            ->andReturn([]);
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturnUsing(function () {
                Cache::put("datacite_import:{$this->importId}", ['status' => 'cancelled']);

                yield datacenterDoiRecord('10.5880/unrelated');
                yield datacenterDoiRecord('10.5880/riesgos-target');
            });
        $this->importService->shouldNotReceive('fetchSingleDoi');
        $this->pendingImportService->shouldNotReceive('importPendingByDoi');
        $this->transformer->shouldReceive('transform')->never();

        $this->importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $this->importId, null, 'Riesgos'))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$this->importId}");

        expect($status)
            ->toMatchArray([
                'status' => 'cancelled',
                'total' => 1,
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
            ]);
    });

    it('rejects combining single-resource and datacenter modes', function () {
        expect(fn () => new ImportFromDataCiteJob(
            $this->user->id,
            Str::uuid()->toString(),
            '10.5880/single',
            'GFZ',
        ))->toThrow(
            InvalidArgumentException::class,
            'Single DOI and datacenter imports cannot be combined.',
        );
    });
});
