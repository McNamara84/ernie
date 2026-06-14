<?php

use App\Enums\UserRole;
use App\Jobs\ImportIgsnsFromDataCiteJob;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteToIgsnTransformer;
use App\Services\IgsnChildDiscoveryService;
use App\Services\IgsnEnrichmentService;
use App\Services\IgsnImportService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => UserRole::ADMIN]);

    $this->importService = Mockery::mock(IgsnImportService::class);
    $this->app->instance(IgsnImportService::class, $this->importService);

    $this->transformer = Mockery::mock(DataCiteToIgsnTransformer::class);
    $this->app->instance(DataCiteToIgsnTransformer::class, $this->transformer);

    $this->enrichmentService = Mockery::mock(IgsnEnrichmentService::class);
    $this->app->instance(IgsnEnrichmentService::class, $this->enrichmentService);
});

afterEach(function () {
    Mockery::close();
});

describe('ImportIgsnsFromDataCiteJob', function () {
    it('updates cache with progress during import', function () {
        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(2);

        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.60510/GFTEST001',
                    'attributes' => [
                        'doi' => '10.60510/GFTEST001',
                        'titles' => [['title' => 'Test IGSN 1']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                    ],
                ];
                yield [
                    'id' => '10.60510/GFTEST002',
                    'attributes' => [
                        'doi' => '10.60510/GFTEST002',
                        'titles' => [['title' => 'Test IGSN 2']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->twice()
            ->andReturnUsing(fn () => createMockResourceWithIgsn());

        $this->enrichmentService
            ->shouldReceive('enrich')
            ->twice()
            ->andReturn(true);

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['status'])->toBe('completed');
        expect($status['processed'])->toBe(2);
        expect($status['imported'])->toBe(2);
        expect($status['enriched'])->toBe(2);
        expect($status['failed'])->toBe(0);
    });

    it('skips existing DOIs', function () {
        Resource::factory()->create(['doi' => '10.60510/existing001']);

        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.60510/EXISTING001',
                    'attributes' => ['doi' => '10.60510/EXISTING001'],
                ];
            })());

        $this->transformer->shouldReceive('transform')->never();
        $this->enrichmentService->shouldReceive('enrich')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['skipped'])->toBe(1);
        expect($status['skipped_dois'])->toContain('10.60510/existing001');
    });

    it('tracks enrichment counter separately from imported', function () {
        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(2);

        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.60510/GFENRICH001',
                    'attributes' => [
                        'doi' => '10.60510/GFENRICH001',
                        'titles' => [['title' => 'Enriched IGSN']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                    ],
                ];
                yield [
                    'id' => '10.60510/GFNOENRICH002',
                    'attributes' => [
                        'doi' => '10.60510/GFNOENRICH002',
                        'titles' => [['title' => 'Not Enriched IGSN']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                    ],
                ];
            })());

        $callCount = 0;
        $this->transformer
            ->shouldReceive('transform')
            ->twice()
            ->andReturnUsing(fn () => createMockResourceWithIgsn());

        // First call enriches, second does not
        $this->enrichmentService
            ->shouldReceive('enrich')
            ->twice()
            ->andReturn(true, false);

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['imported'])->toBe(2);
        expect($status['enriched'])->toBe(1);
    });

    it('handles transform failures gracefully', function () {
        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(2);

        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.60510/FAIL001',
                    'attributes' => ['doi' => '10.60510/FAIL001'],
                ];
                yield [
                    'id' => '10.60510/OK001',
                    'attributes' => [
                        'doi' => '10.60510/OK001',
                        'titles' => [['title' => 'OK']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->andReturnUsing(function ($data, $userId) {
                $doi = $data['attributes']['doi'] ?? '';
                if ($doi === '10.60510/fail001') {
                    throw new Exception('Transform failed');
                }

                return createMockResourceWithIgsn();
            });

        $this->enrichmentService
            ->shouldReceive('enrich')
            ->once()
            ->andReturn(false);

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['status'])->toBe('completed');
        expect($status['imported'])->toBe(1);
        expect($status['failed'])->toBe(1);
        expect($status['failed_dois'][0]['doi'])->toBe('10.60510/fail001');
    });

    it('limits stored failed DOIs to prevent memory issues', function () {
        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(150);

        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () {
                for ($i = 1; $i <= 150; $i++) {
                    yield [
                        'id' => "10.60510/FAIL{$i}",
                        'attributes' => ['doi' => "10.60510/FAIL{$i}"],
                    ];
                }
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->times(150)
            ->andThrow(new Exception('Transform failed'));

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get("igsn_import:{$importId}");
        expect(count($status['failed_dois']))->toBeLessThanOrEqual(100);
        expect($status['failed'])->toBe(150);
    });

    it('validates importId is a valid UUID', function () {
        expect(fn () => new ImportIgsnsFromDataCiteJob($this->user->id, 'invalid-id'))
            ->toThrow(InvalidArgumentException::class, 'Invalid importId format');
    });

    it('accepts valid UUID format for importId', function () {
        $validUuid = '550e8400-e29b-41d4-a716-446655440000';
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $validUuid);

        expect($job->getImportId())->toBe($validUuid);
    });

    it('normalizes uppercase UUID to lowercase', function () {
        $uppercaseUuid = '550E8400-E29B-41D4-A716-446655440000';
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $uppercaseUuid);

        expect($job->getImportId())->toBe(strtolower($uppercaseUuid));
    });

    it('handles records with no DOI gracefully', function () {
        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () {
                yield ['attributes' => []]; // No DOI and no id
            })());

        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['failed'])->toBe(1);
        expect($status['failed_dois'][0]['error'])->toBe('No DOI found in record');
    });

    it('treats duplicate-entry race conditions as skipped imports', function () {
        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.60510/RACE001',
                    'attributes' => ['doi' => '10.60510/RACE001'],
                ];
            })());

        $pdoException = new PDOException('Duplicate entry');
        $pdoException->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $queryException = new QueryException('mysql', 'insert into `resources`', [], $pdoException);

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andThrow($queryException);
        $this->enrichmentService->shouldReceive('enrich')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['imported'])->toBe(0)
            ->and($status['skipped'])->toBe(1)
            ->and($status['failed'])->toBe(0)
            ->and($status['skipped_dois'])->toContain('10.60510/race001');
    });

    it('treats sqlite unique constraint race conditions as skipped imports', function () {
        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.60510/SQLITERACE001',
                    'attributes' => ['doi' => '10.60510/SQLITERACE001'],
                ];
            })());

        $queryException = new QueryException(
            'sqlite',
            'insert into "resources"',
            [],
            new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: resources.doi'),
        );

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andThrow($queryException);
        $this->enrichmentService->shouldReceive('enrich')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['imported'])->toBe(0)
            ->and($status['skipped'])->toBe(1)
            ->and($status['failed'])->toBe(0)
            ->and($status['skipped_dois'])->toContain('10.60510/sqliterace001');
    });

    it('records a failed IGSN when a non-duplicate query exception is thrown', function () {
        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.60510/QUERYFAIL001',
                    'attributes' => ['doi' => '10.60510/QUERYFAIL001'],
                ];
            })());

        $pdoException = new PDOException('Deadlock found');
        $pdoException->errorInfo = ['40001', 1213, 'Deadlock found'];
        $queryException = new QueryException('mysql', 'insert into `resources`', [], $pdoException);

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andThrow($queryException);
        $this->enrichmentService->shouldReceive('enrich')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['imported'])->toBe(0)
            ->and($status['skipped'])->toBe(0)
            ->and($status['failed'])->toBe(1)
            ->and($status['failed_dois'][0]['doi'])->toBe('10.60510/queryfail001');
    });

    it('enrichment failure does not stop import', function () {
        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.60510/ENRICHFAIL001',
                    'attributes' => [
                        'doi' => '10.60510/ENRICHFAIL001',
                        'titles' => [['title' => 'Test']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => createMockResourceWithIgsn());

        $this->enrichmentService
            ->shouldReceive('enrich')
            ->once()
            ->andThrow(new Exception('Solr connection failed'));

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['status'])->toBe('completed');
        expect($status['imported'])->toBe(1);
        expect($status['enriched'])->toBe(0);
    });

    it('skips parent resolution when import is cancelled', function () {
        // Create a parent resource for potential resolution
        $parentResource = Resource::factory()->create(['doi' => '10.60510/GFPARENT_CANCEL']);
        IgsnMetadata::create([
            'resource_id' => $parentResource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $importId = Str::uuid()->toString();
        $cacheKey = "igsn_import:{$importId}";

        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(1);

        // The generator sets cancel AFTER job sets 'running', but BEFORE cancel check
        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () use ($cacheKey) {
                // Set cancel before yielding — processed++ and cancel check happen after yield
                Cache::put($cacheKey, ['status' => 'cancelled'], 3600);

                yield [
                    'id' => '10.60510/GFCANCEL001',
                    'attributes' => [
                        'doi' => '10.60510/GFCANCEL001',
                        'titles' => [['title' => 'Cancelled IGSN']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                    ],
                ];
            })());

        // The record should not be transformed because cancel is checked at processed=1
        $this->transformer->shouldReceive('transform')->never();
        $this->enrichmentService->shouldReceive('enrich')->never();

        // Create a child resource with parent handle (simulating a previous import)
        $childResource = Resource::factory()->create(['doi' => '10.60510/GFCHILD_CANCEL']);
        $childIgsn = IgsnMetadata::create([
            'resource_id' => $childResource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
            'description_json' => ['parent_igsn_handle' => 'GFPARENT_CANCEL'],
        ]);

        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get($cacheKey);
        expect($status['status'])->toBe('cancelled');

        // Parent should NOT have been resolved
        $childIgsn->refresh();
        expect($childIgsn->parent_resource_id)->toBeNull();
    });

    it('returns early with cancelled status when cancelled before job starts', function () {
        $importId = Str::uuid()->toString();
        $cacheKey = "igsn_import:{$importId}";

        // Pre-set cache to cancelled (simulating cancel before job is dispatched)
        Cache::put($cacheKey, ['status' => 'cancelled'], 3600);

        // Import service should never be called
        $this->importService->shouldReceive('getTotalIgsnCount')->never();
        $this->importService->shouldReceive('fetchAllIgsns')->never();
        $this->transformer->shouldReceive('transform')->never();
        $this->enrichmentService->shouldReceive('enrich')->never();

        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        $status = Cache::get($cacheKey);
        expect($status['status'])->toBe('cancelled');
        expect($status['total'])->toBe(0);
        expect($status['processed'])->toBe(0);
        expect($status['imported'])->toBe(0);
    });

    it('resolves parent-child relationships after import', function () {
        // Create a parent resource first (lowercase DOI — normalized at import time)
        $parentResource = Resource::factory()->create(['doi' => '10.60510/gfparent002']);
        IgsnMetadata::create([
            'resource_id' => $parentResource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $this->importService
            ->shouldReceive('getTotalIgsnCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllIgsns')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.60510/GFCHILD002',
                    'attributes' => [
                        'doi' => '10.60510/GFCHILD002',
                        'titles' => [['title' => 'Child IGSN']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                    ],
                ];
            })());

        // Create the child resource with a parent handle in description_json (lowercase DOI)
        $childResource = Resource::factory()->create(['doi' => '10.60510/gfchild002']);
        $childIgsn = IgsnMetadata::create([
            'resource_id' => $childResource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
            'description_json' => ['parent_igsn_handle' => 'GFPARENT002'],
        ]);

        // The DOI already exists, so transform won't be called (skipped)
        $this->transformer->shouldReceive('transform')->never();
        $this->enrichmentService->shouldReceive('enrich')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->enrichmentService);

        // Parent should be resolved
        $childIgsn->refresh();
        expect($childIgsn->parent_resource_id)->toBe($parentResource->id);
        // Handle should be removed from description_json
        expect($childIgsn->description_json)->toBeNull();
    });

    it('imports a single leaf IGSN', function () {
        $childDiscoveryService = Mockery::mock(IgsnChildDiscoveryService::class);

        $this->importService
            ->shouldReceive('fetchSingleIgsn')
            ->once()
            ->with('10.60510/icdpleaf001')
            ->andReturn([
                'id' => '10.60510/ICDPLEAF001',
                'attributes' => [
                    'doi' => '10.60510/ICDPLEAF001',
                    'titles' => [['title' => 'Leaf IGSN']],
                    'publicationYear' => 2024,
                    'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                ],
            ]);

        $childDiscoveryService
            ->shouldReceive('discoverDirectChildHandles')
            ->once()
            ->with('ICDPLEAF001')
            ->andReturn([]);

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn (array $record) => createMockResourceWithIgsn($record['attributes']['doi']));

        $this->enrichmentService
            ->shouldReceive('enrich')
            ->once()
            ->andReturn(true);

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId, '10.60510/ICDPLEAF001');
        $job->handle($this->importService, $this->transformer, $this->enrichmentService, $childDiscoveryService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['status'])->toBe('completed');
        expect($status['requested_igsn'])->toBe('ICDPLEAF001');
        expect($status['total'])->toBe(1);
        expect($status['imported'])->toBe(1);
        expect($status['enriched'])->toBe(1);
    });

    it('returns cancelled status when a single IGSN import was cancelled before starting', function () {
        $childDiscoveryService = Mockery::mock(IgsnChildDiscoveryService::class);

        $importId = Str::uuid()->toString();
        $cacheKey = "igsn_import:{$importId}";
        Cache::put($cacheKey, ['status' => 'cancelled'], 3600);

        $this->importService->shouldReceive('fetchSingleIgsn')->never();
        $childDiscoveryService->shouldReceive('discoverDirectChildHandles')->never();
        $this->transformer->shouldReceive('transform')->never();
        $this->enrichmentService->shouldReceive('enrich')->never();

        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId, 'ICDPCANCELLED001');
        $job->handle($this->importService, $this->transformer, $this->enrichmentService, $childDiscoveryService);

        $status = Cache::get($cacheKey);
        expect($status['status'])->toBe('cancelled')
            ->and($status['requested_igsn'])->toBe('ICDPCANCELLED001')
            ->and($status['total'])->toBe(0);
    });

    it('marks a single IGSN import as failed when the requested IGSN no longer exists at DataCite', function () {
        $childDiscoveryService = Mockery::mock(IgsnChildDiscoveryService::class);

        $this->importService
            ->shouldReceive('fetchSingleIgsn')
            ->once()
            ->with('10.60510/icdpmissingparent')
            ->andReturn(null);

        $childDiscoveryService->shouldReceive('discoverDirectChildHandles')->never();
        $this->transformer->shouldReceive('transform')->never();
        $this->enrichmentService->shouldReceive('enrich')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId, 'ICDPMISSINGPARENT');
        $job->handle($this->importService, $this->transformer, $this->enrichmentService, $childDiscoveryService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['status'])->toBe('failed')
            ->and($status['processed'])->toBe(1)
            ->and($status['failed'])->toBe(1)
            ->and($status['failed_dois'][0]['doi'])->toBe('10.60510/icdpmissingparent');
    });

    it('fails the job when a single import is created with an invalid DOI', function () {
        $childDiscoveryService = Mockery::mock(IgsnChildDiscoveryService::class);

        $this->importService->shouldReceive('fetchSingleIgsn')->never();
        $childDiscoveryService->shouldReceive('discoverDirectChildHandles')->never();
        $this->transformer->shouldReceive('transform')->never();
        $this->enrichmentService->shouldReceive('enrich')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId, '10.99999/not-an-igsn');

        expect(fn () => $job->handle($this->importService, $this->transformer, $this->enrichmentService, $childDiscoveryService))
            ->toThrow(RuntimeException::class, 'Single IGSN import requested without a valid IGSN DOI.');

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['status'])->toBe('failed')
            ->and($status['error'])->toBe('Single IGSN import requested without a valid IGSN DOI.');
    });

    it('continues a single import when an imported resource has no IGSN metadata relation', function () {
        $childDiscoveryService = Mockery::mock(IgsnChildDiscoveryService::class);

        $this->importService
            ->shouldReceive('fetchSingleIgsn')
            ->once()
            ->with('10.60510/icdpnometadata001')
            ->andReturn([
                'id' => '10.60510/ICDPNOMETADATA001',
                'attributes' => [
                    'doi' => '10.60510/ICDPNOMETADATA001',
                    'titles' => [['title' => 'No metadata relation']],
                    'publicationYear' => 2024,
                    'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                ],
            ]);

        $childDiscoveryService
            ->shouldReceive('discoverDirectChildHandles')
            ->once()
            ->with('ICDPNOMETADATA001')
            ->andReturn([]);

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn (array $record) => Resource::factory()->create(['doi' => $record['attributes']['doi']]));

        $this->enrichmentService->shouldReceive('enrich')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId, 'ICDPNOMETADATA001');
        $job->handle($this->importService, $this->transformer, $this->enrichmentService, $childDiscoveryService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['imported'])->toBe(1)
            ->and($status['enriched'])->toBe(0);
    });

    it('imports a parent IGSN and discovered child IGSNs', function () {
        $childDiscoveryService = Mockery::mock(IgsnChildDiscoveryService::class);

        $this->importService
            ->shouldReceive('fetchSingleIgsn')
            ->once()
            ->with('10.60510/icdpparent001')
            ->andReturn([
                'id' => '10.60510/ICDPPARENT001',
                'attributes' => [
                    'doi' => '10.60510/ICDPPARENT001',
                    'titles' => [['title' => 'Parent IGSN']],
                    'publicationYear' => 2024,
                    'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                ],
            ]);

        $this->importService
            ->shouldReceive('fetchSingleIgsn')
            ->once()
            ->with('10.60510/icdpchild001')
            ->andReturn([
                'id' => '10.60510/ICDPCHILD001',
                'attributes' => [
                    'doi' => '10.60510/ICDPCHILD001',
                    'titles' => [['title' => 'Child IGSN 1']],
                    'publicationYear' => 2024,
                    'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                ],
            ]);

        $this->importService
            ->shouldReceive('fetchSingleIgsn')
            ->once()
            ->with('10.60510/icdpchild002')
            ->andReturn([
                'id' => '10.60510/ICDPCHILD002',
                'attributes' => [
                    'doi' => '10.60510/ICDPCHILD002',
                    'titles' => [['title' => 'Child IGSN 2']],
                    'publicationYear' => 2024,
                    'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                ],
            ]);

        $childDiscoveryService
            ->shouldReceive('discoverDirectChildHandles')
            ->once()
            ->with('ICDPPARENT001')
            ->andReturn(['ICDPCHILD001', 'ICDPCHILD002']);

        $this->transformer
            ->shouldReceive('transform')
            ->times(3)
            ->andReturnUsing(function (array $record): Resource {
                $doi = strtolower($record['attributes']['doi']);
                $parentHandle = str_ends_with($doi, 'child001') || str_ends_with($doi, 'child002')
                    ? 'ICDPPARENT001'
                    : null;

                return createMockResourceWithIgsn($doi, $parentHandle);
            });

        $this->enrichmentService
            ->shouldReceive('enrich')
            ->times(3)
            ->andReturn(true);

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId, 'ICDPPARENT001');
        $job->handle($this->importService, $this->transformer, $this->enrichmentService, $childDiscoveryService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['status'])->toBe('completed');
        expect($status['total'])->toBe(3);
        expect($status['imported'])->toBe(3);
        expect($status['discovered_children'])->toBe(['ICDPCHILD001', 'ICDPCHILD002']);

        $parent = Resource::where('doi', '10.60510/icdpparent001')->firstOrFail();
        $child = Resource::where('doi', '10.60510/icdpchild001')->firstOrFail();
        expect($child->igsnMetadata->parent_resource_id)->toBe($parent->id);
    });

    it('skips an existing single IGSN', function () {
        Resource::factory()->create(['doi' => '10.60510/icdpexisting001']);
        $childDiscoveryService = Mockery::mock(IgsnChildDiscoveryService::class);

        $this->importService
            ->shouldReceive('fetchSingleIgsn')
            ->once()
            ->with('10.60510/icdpexisting001')
            ->andReturn([
                'id' => '10.60510/ICDPEXISTING001',
                'attributes' => ['doi' => '10.60510/ICDPEXISTING001'],
            ]);

        $childDiscoveryService
            ->shouldReceive('discoverDirectChildHandles')
            ->once()
            ->with('ICDPEXISTING001')
            ->andReturn([]);

        $this->transformer->shouldReceive('transform')->never();
        $this->enrichmentService->shouldReceive('enrich')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId, 'ICDPEXISTING001');
        $job->handle($this->importService, $this->transformer, $this->enrichmentService, $childDiscoveryService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['imported'])->toBe(0);
        expect($status['skipped'])->toBe(1);
        expect($status['skipped_dois'])->toBe(['10.60510/icdpexisting001']);
    });

    it('continues when a discovered child IGSN is missing at DataCite', function () {
        $childDiscoveryService = Mockery::mock(IgsnChildDiscoveryService::class);

        $this->importService
            ->shouldReceive('fetchSingleIgsn')
            ->once()
            ->with('10.60510/icdpparentmissingchild')
            ->andReturn([
                'id' => '10.60510/ICDPPARENTMISSINGCHILD',
                'attributes' => [
                    'doi' => '10.60510/ICDPPARENTMISSINGCHILD',
                    'titles' => [['title' => 'Parent IGSN']],
                    'publicationYear' => 2024,
                    'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                ],
            ]);

        $this->importService
            ->shouldReceive('fetchSingleIgsn')
            ->once()
            ->with('10.60510/icdpmissingchild')
            ->andReturn(null);

        $childDiscoveryService
            ->shouldReceive('discoverDirectChildHandles')
            ->once()
            ->with('ICDPPARENTMISSINGCHILD')
            ->andReturn(['ICDPMISSINGCHILD']);

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn (array $record) => createMockResourceWithIgsn($record['attributes']['doi']));

        $this->enrichmentService
            ->shouldReceive('enrich')
            ->once()
            ->andReturn(false);

        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId, 'ICDPPARENTMISSINGCHILD');
        $job->handle($this->importService, $this->transformer, $this->enrichmentService, $childDiscoveryService);

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['status'])->toBe('completed');
        expect($status['imported'])->toBe(1);
        expect($status['failed'])->toBe(1);
        expect($status['failed_dois'][0]['doi'])->toBe('10.60510/icdpmissingchild');
    });

    it('marks a single import as cancelled when cancellation happens during processing', function () {
        $childDiscoveryService = Mockery::mock(IgsnChildDiscoveryService::class);

        $this->importService
            ->shouldReceive('fetchSingleIgsn')
            ->once()
            ->with('10.60510/icdpcancelmidrun')
            ->andReturn([
                'id' => '10.60510/ICDPCANCELMIDRUN',
                'attributes' => [
                    'doi' => '10.60510/ICDPCANCELMIDRUN',
                    'titles' => [['title' => 'Parent IGSN']],
                    'publicationYear' => 2024,
                    'types' => ['resourceTypeGeneral' => 'PhysicalObject'],
                ],
            ]);

        $this->importService
            ->shouldReceive('fetchSingleIgsn')
            ->with('10.60510/icdpchildcancelled')
            ->never();

        $childDiscoveryService
            ->shouldReceive('discoverDirectChildHandles')
            ->once()
            ->with('ICDPCANCELMIDRUN')
            ->andReturn(['ICDPCHILDCANCELLED']);

        $importId = Str::uuid()->toString();
        $cacheKey = "igsn_import:{$importId}";

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(function (array $record) use ($cacheKey): Resource {
                Cache::put($cacheKey, ['status' => 'cancelled'], 3600);

                return createMockResourceWithIgsn($record['attributes']['doi']);
            });

        $this->enrichmentService
            ->shouldReceive('enrich')
            ->once()
            ->andReturn(false);

        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId, 'ICDPCANCELMIDRUN');
        $job->handle($this->importService, $this->transformer, $this->enrichmentService, $childDiscoveryService);

        $status = Cache::get($cacheKey);
        expect($status['status'])->toBe('cancelled')
            ->and($status['processed'])->toBe(1)
            ->and($status['total'])->toBe(2)
            ->and($status['imported'])->toBe(1);
    });

    it('stores failure status from the queue failed callback', function () {
        $importId = Str::uuid()->toString();
        $job = new ImportIgsnsFromDataCiteJob($this->user->id, $importId, 'ICDPFAILED001');

        $job->failed(new RuntimeException('Queue worker failed'));

        $status = Cache::get("igsn_import:{$importId}");
        expect($status['status'])->toBe('failed')
            ->and($status['error'])->toBe('Queue worker failed')
            ->and($status['completed_at'])->not->toBeNull();
    });
});

/**
 * Create a mock Resource with IgsnMetadata for testing.
 */
function createMockResourceWithIgsn(?string $doi = null, ?string $parentHandle = null): Resource
{
    $resource = Resource::factory()->create($doi !== null ? ['doi' => $doi] : []);
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        'description_json' => $parentHandle !== null ? ['parent_igsn_handle' => $parentHandle] : null,
    ]);
    $resource->load('igsnMetadata');

    return $resource;
}
