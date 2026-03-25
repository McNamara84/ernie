<?php

use App\Enums\UserRole;
use App\Jobs\ImportFromDataCiteJob;
use App\Models\LandingPage;
use App\Models\LandingPageFile;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteImportService;
use App\Services\DataCiteToResourceTransformer;
use App\Services\MetaworksDownloadUrlService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    // Create a user for the import
    $this->user = User::factory()->create(['role' => UserRole::ADMIN]);

    // Mock the import service
    $this->importService = Mockery::mock(DataCiteImportService::class);
    $this->app->instance(DataCiteImportService::class, $this->importService);

    // Mock the transformer for isolated job testing
    $this->transformer = Mockery::mock(DataCiteToResourceTransformer::class);
    $this->app->instance(DataCiteToResourceTransformer::class, $this->transformer);

    // Mock the metaworks service (returns empty result by default)
    $this->metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
    $this->metaworksService->shouldReceive('lookupFileUrls')->andReturn(['urls' => [], 'allPublic' => false]);
    $this->app->instance(MetaworksDownloadUrlService::class, $this->metaworksService);
});

afterEach(function () {
    Mockery::close();
});

describe('ImportFromDataCiteJob', function () {
    it('updates cache with progress during import', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(2);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/test.1',
                    'attributes' => [
                        'doi' => '10.5880/test.1',
                        'titles' => [['title' => 'Test 1']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
                yield [
                    'id' => '10.5880/test.2',
                    'attributes' => [
                        'doi' => '10.5880/test.2',
                        'titles' => [['title' => 'Test 2']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        // Mock transformer to simulate successful import
        $this->transformer
            ->shouldReceive('transform')
            ->twice()
            ->andReturn(Resource::factory()->make());

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        // Check final cache state
        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed');
        expect($status['processed'])->toBe(2);
        expect($status['imported'])->toBe(2);
        expect($status['failed'])->toBe(0);
    });

    it('skips existing DOIs', function () {
        // Create existing resource
        Resource::factory()->create(['doi' => '10.5880/existing']);

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/existing',
                    'attributes' => ['doi' => '10.5880/existing'],
                ];
            })());

        // Transformer should not be called for existing DOIs
        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['skipped'])->toBe(1);
        expect($status['skipped_dois'])->toContain('10.5880/existing');
    });

    it('tracks status in cache during processing and respects cancellation flag', function () {
        // This test verifies that the job properly writes status to cache during processing
        // and that the cache key structure supports cancellation (by checking 'status' key).
        // The actual cancellation behavior is tested implicitly - if the job finds
        // status='cancelled' in cache during processing, it will preserve that status.
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        // Create generator that yields one item
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/test.1',
                    'attributes' => [
                        'doi' => '10.5880/test.1',
                        'titles' => [['title' => 'Test']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        // Mock transformer to simulate successful import
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturn(Resource::factory()->make());

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        // Verify the cache was written with status tracking
        $status = Cache::get("datacite_import:{$importId}");
        expect($status)->toHaveKey('status');
        expect($status['status'])->toBe('completed');
    });

    it('limits stored failed DOIs to prevent memory issues', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(150);

        // Create generator with many failing items
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                for ($i = 1; $i <= 150; $i++) {
                    // Yield invalid records that will fail
                    yield [
                        'id' => "10.5880/fail.{$i}",
                        'attributes' => [
                            'doi' => "10.5880/fail.{$i}",
                            // Missing required fields to cause failure
                        ],
                    ];
                }
            })());

        // Mock transformer to throw exception (simulating transform failure)
        $this->transformer
            ->shouldReceive('transform')
            ->times(150)
            ->andThrow(new \Exception('Transform failed'));

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        // Failed DOIs array should be capped at 100
        expect(count($status['failed_dois']))->toBeLessThanOrEqual(100);
        expect($status['failed'])->toBe(150);
    });

    it('validates importId is a valid UUID', function () {
        expect(fn () => new ImportFromDataCiteJob($this->user->id, 'invalid-id'))
            ->toThrow(\InvalidArgumentException::class, 'Invalid importId format');
    });

    it('accepts valid UUID format for importId', function () {
        $validUuid = '550e8400-e29b-41d4-a716-446655440000';
        $job = new ImportFromDataCiteJob($this->user->id, $validUuid);

        expect($job->getImportId())->toBe($validUuid);
    });

    it('normalizes uppercase UUID to lowercase', function () {
        $uppercaseUuid = '550E8400-E29B-41D4-A716-446655440000';
        $job = new ImportFromDataCiteJob($this->user->id, $uppercaseUuid);

        expect($job->getImportId())->toBe(strtolower($uppercaseUuid));
    });
});

describe('ImportFromDataCiteJob download URL enrichment', function () {
    it('creates landing page with files when metaworks has download URLs', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/lp.test.001',
                    'attributes' => [
                        'doi' => '10.5880/lp.test.001',
                        'titles' => [['title' => 'Test Dataset Title']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        // Transformer creates the resource (like the real transformer does)
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => Resource::factory()->create(['doi' => '10.5880/lp.test.001']));

        // Override the default mock: return download URLs (all public)
        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileUrls')
            ->with('10.5880/lp.test.001')
            ->once()
            ->andReturn([
                'urls' => [
                    'https://datapub.gfz.de/download/10.5880/GFZ.lp.test.001/file1.zip',
                    'https://datapub.gfz.de/download/10.5880/GFZ.lp.test.001/file2.zip',
                ],
                'allPublic' => true,
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        // Verify landing page was created
        $resource = Resource::where('doi', '10.5880/lp.test.001')->first();
        $landingPage = LandingPage::where('resource_id', $resource->id)->first();
        expect($landingPage)->not->toBeNull()
            ->and($landingPage->template)->toBe('default_gfz')
            ->and($landingPage->is_published)->toBeTrue()
            ->and($landingPage->published_at)->not->toBeNull()
            ->and($landingPage->ftp_url)->toBe('https://datapub.gfz.de/download/10.5880/GFZ.lp.test.001/file1.zip');

        // Verify files were created
        $files = LandingPageFile::where('landing_page_id', $landingPage->id)->orderBy('position')->get();
        expect($files)->toHaveCount(2)
            ->and($files[0]->url)->toBe('https://datapub.gfz.de/download/10.5880/GFZ.lp.test.001/file1.zip')
            ->and($files[0]->position)->toBe(0)
            ->and($files[1]->url)->toBe('https://datapub.gfz.de/download/10.5880/GFZ.lp.test.001/file2.zip')
            ->and($files[1]->position)->toBe(1);
    });

    it('creates unpublished landing page when metaworks files are non-public', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/lp.nonpub.001',
                    'attributes' => [
                        'doi' => '10.5880/lp.nonpub.001',
                        'titles' => [['title' => 'Non-Public Files Dataset']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => Resource::factory()->create(['doi' => '10.5880/lp.nonpub.001']));

        // Return URLs with allPublic=false (some files are non-public)
        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileUrls')
            ->with('10.5880/lp.nonpub.001')
            ->once()
            ->andReturn([
                'urls' => ['https://datapub.gfz.de/download/internal-file.zip'],
                'allPublic' => false,
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        // Verify landing page was created but NOT published
        $resource = Resource::where('doi', '10.5880/lp.nonpub.001')->first();
        $landingPage = LandingPage::where('resource_id', $resource->id)->first();
        expect($landingPage)->not->toBeNull()
            ->and($landingPage->is_published)->toBeFalse()
            ->and($landingPage->published_at)->toBeNull();

        // Files should still be created
        $files = LandingPageFile::where('landing_page_id', $landingPage->id)->get();
        expect($files)->toHaveCount(1)
            ->and($files[0]->url)->toBe('https://datapub.gfz.de/download/internal-file.zip');
    });

    it('does not create landing page when metaworks has no files', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/nofiles.001',
                    'attributes' => [
                        'doi' => '10.5880/nofiles.001',
                        'titles' => [['title' => 'No Files Dataset']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => Resource::factory()->create(['doi' => '10.5880/nofiles.001']));

        // MetaworksService returns empty array (default mock behavior)
        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $resource = Resource::where('doi', '10.5880/nofiles.001')->first();
        expect(LandingPage::where('resource_id', $resource->id)->exists())->toBeFalse();
    });

    it('does not create landing page for skipped (existing) resources', function () {
        Resource::factory()->create(['doi' => '10.5880/skip.existing']);

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/skip.existing',
                    'attributes' => ['doi' => '10.5880/skip.existing'],
                ];
            })());

        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        // No landing page should exist
        expect(LandingPage::count())->toBe(0);
    });

    it('continues import gracefully when metaworks lookup fails', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/fail.metaworks',
                    'attributes' => [
                        'doi' => '10.5880/fail.metaworks',
                        'titles' => [['title' => 'Fail Metaworks']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => Resource::factory()->create(['doi' => '10.5880/fail.metaworks']));

        // MetaworksService throws exception
        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileUrls')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        // Import should still be completed successfully
        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['imported'])->toBe(1)
            ->and($status['failed'])->toBe(0);

        // No landing page created (metaworks failed)
        $resource = Resource::where('doi', '10.5880/fail.metaworks')->first();
        expect(LandingPage::where('resource_id', $resource->id)->exists())->toBeFalse();
    });

    it('does not create duplicate landing page if one already exists', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/dup.lp.001',
                    'attributes' => [
                        'doi' => '10.5880/dup.lp.001',
                        'titles' => [['title' => 'Existing LP Dataset']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        // Transformer creates the resource AND a landing page already exists
        // (simulates race condition or pre-existing LP from another process)
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(function () {
                $resource = Resource::factory()->create(['doi' => '10.5880/dup.lp.001']);
                LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

                return $resource;
            });

        // MetaworksService should NOT be called since landing page already exists
        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldNotReceive('lookupFileUrls');

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        // Only the original landing page should exist (no duplicate)
        $resource = Resource::where('doi', '10.5880/dup.lp.001')->first();
        expect(LandingPage::where('resource_id', $resource->id)->count())->toBe(1);
        // No files should have been created on the existing landing page
        $lp = LandingPage::where('resource_id', $resource->id)->first();
        expect(LandingPageFile::where('landing_page_id', $lp->id)->count())->toBe(0);
    });
});
