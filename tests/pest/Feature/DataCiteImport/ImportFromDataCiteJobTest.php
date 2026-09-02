<?php

use App\Enums\CacheKey;
use App\Enums\CitationLabelResolutionMode;
use App\Enums\ResourceWorkflowStatus;
use App\Enums\UserRole;
use App\Exceptions\AmbiguousLegacyResourceException;
use App\Jobs\ImportFromDataCiteJob;
use App\Models\Datacenter;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\LandingPageFile;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Models\ResourceRight;
use App\Models\Right;
use App\Models\User;
use App\Services\DataCiteImportService;
use App\Services\DataCiteSyncService;
use App\Services\DataCiteToResourceTransformer;
use App\Services\DoiSuggestionService;
use App\Services\GfzDataServicesPortalService;
use App\Services\LandingPageResourceTransformer;
use App\Services\LegacyMetaworksDatacenterLookupService;
use App\Services\LegacyResourceLookupService;
use App\Services\MetaworksDownloadUrlService;
use App\Services\SumarioPendingResourceImportService;
use App\Services\SumarioPmdContactEnrichmentService;
use App\Services\SumarioPmdCoverageEnrichmentService;
use Database\Seeders\ContributorTypeSeeder;
use Database\Seeders\DescriptionTypeSeeder;
use Database\Seeders\FunderIdentifierTypeSeeder;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PublisherSeeder;
use Database\Seeders\RelationTypeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TitleTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    // Create a user for the import
    $this->user = User::factory()->create(['role' => UserRole::ADMIN]);

    // Mock the import service
    $this->importService = Mockery::mock(DataCiteImportService::class);
    $this->app->instance(DataCiteImportService::class, $this->importService);

    // Mock the transformer for isolated job testing
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

    // Mock the metaworks service (returns empty result by default)
    $this->metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
    $this->metaworksService
        ->shouldReceive('lookupFileUrls')
        ->zeroOrMoreTimes()
        ->andReturn(['urls' => [], 'allPublic' => false])
        ->byDefault();
    $this->metaworksService
        ->shouldReceive('lookupFileEntries')
        ->zeroOrMoreTimes()
        ->andReturn(['files' => [], 'allPublic' => false])
        ->byDefault();
    $this->app->instance(MetaworksDownloadUrlService::class, $this->metaworksService);

    $this->legacyResourceLookupService = Mockery::mock(LegacyResourceLookupService::class);
    $this->legacyResourceLookupService
        ->shouldReceive('importMetadataByDoi')
        ->zeroOrMoreTimes()
        ->andReturn([
            'relatedIdentifiers' => [],
            'subjects' => [],
            'legacyResourceId' => null,
            'legacyResourceStatus' => null,
        ])
        ->byDefault();
    $this->app->instance(LegacyResourceLookupService::class, $this->legacyResourceLookupService);

    $this->pendingImportService = Mockery::mock(SumarioPendingResourceImportService::class);
    $this->pendingImportService
        ->shouldReceive('countImportablePending')
        ->zeroOrMoreTimes()
        ->andReturn(0)
        ->byDefault();
    $this->pendingImportService
        ->shouldReceive('importAllPending')
        ->zeroOrMoreTimes()
        ->andReturn([
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'skipped_dois' => [],
            'failed_dois' => [],
        ])
        ->byDefault();
    $this->pendingImportService
        ->shouldReceive('importReviewFallbackByDoi')
        ->zeroOrMoreTimes()
        ->andReturnUsing(fn (string $doi, mixed ...$_): array => [
            'status' => 'missing',
            'resource' => null,
            'doi' => $doi,
            'error' => null,
        ])
        ->byDefault();
    $this->app->instance(SumarioPendingResourceImportService::class, $this->pendingImportService);

    $this->contactEnrichmentService = Mockery::mock(SumarioPmdContactEnrichmentService::class);
    $this->contactEnrichmentService
        ->shouldReceive('enrich')
        ->zeroOrMoreTimes()
        ->andReturn(false)
        ->byDefault();
    $this->app->instance(SumarioPmdContactEnrichmentService::class, $this->contactEnrichmentService);

    $this->coverageEnrichmentService = Mockery::mock(SumarioPmdCoverageEnrichmentService::class);
    $this->coverageEnrichmentService
        ->shouldReceive('enrich')
        ->zeroOrMoreTimes()
        ->andReturn(false)
        ->byDefault();
    $this->app->instance(SumarioPmdCoverageEnrichmentService::class, $this->coverageEnrichmentService);

    $this->datacenterLookupService = Mockery::mock(LegacyMetaworksDatacenterLookupService::class);
    $this->datacenterLookupService
        ->shouldReceive('syncDatacenters')
        ->zeroOrMoreTimes()
        ->andReturnNull()
        ->byDefault();
    $this->app->instance(LegacyMetaworksDatacenterLookupService::class, $this->datacenterLookupService);

    $this->portalService = Mockery::mock(GfzDataServicesPortalService::class);
    $this->portalService
        ->shouldReceive('datacenterNamesForDoi')
        ->zeroOrMoreTimes()
        ->andReturn([])
        ->byDefault();
    $this->app->instance(GfzDataServicesPortalService::class, $this->portalService);
});

afterEach(function () {
    Mockery::close();
});

function crc806ImportJobPage(string $doi = '10.5880/sfb806.80'): string
{
    return '<script>window.__routeInfo = '.json_encode([
        'allProps' => [
            'dataset' => [
                'extras' => ['bibtex:doi' => $doi],
                'license' => [
                    'name' => 'CC BY-NC-ND',
                    'url' => 'https://creativecommons.org/licenses/by-nc-nd/4.0',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).';</script>';
}

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
                    'id' => '10.5880/sample.1',
                    'attributes' => [
                        'doi' => '10.5880/sample.1',
                        'titles' => [['title' => 'Test 1']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
                yield [
                    'id' => '10.5880/sample.2',
                    'attributes' => [
                        'doi' => '10.5880/sample.2',
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

    it('adds SUMARIO pending import results to the bulk progress summary', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(0);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                if (false) {
                    yield [];
                }
            })());

        $this->pendingImportService
            ->shouldReceive('countImportablePending')
            ->once()
            ->andReturn(1);

        $this->pendingImportService
            ->shouldReceive('importAllPending')
            ->once()
            ->with($this->user->id, 100)
            ->andReturn([
                'processed' => 1,
                'imported' => 1,
                'skipped' => 0,
                'failed' => 0,
                'skipped_dois' => [],
                'failed_dois' => [],
            ]);

        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['total'])->toBe(1)
            ->and($status['processed'])->toBe(1)
            ->and($status['imported'])->toBe(1)
            ->and($status['failed'])->toBe(0);
    });

    it('fails the bulk import before local writes when the SUMARIO preflight is unavailable', function () {
        $this->pendingImportService
            ->shouldReceive('countImportablePending')
            ->once()
            ->andThrow(new RuntimeException('SUMARIO connection refused'));

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->never();

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->never();

        $this->transformer
            ->shouldReceive('transform')
            ->never();

        $this->pendingImportService
            ->shouldReceive('importAllPending')
            ->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);

        expect(fn () => $job->handle($this->importService, $this->transformer, $this->metaworksService))
            ->toThrow(RuntimeException::class, 'SUMARIO connection refused');

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('failed')
            ->and($status['error'])->toBe('SUMARIO connection refused')
            ->and(Resource::query()->where('doi', '10.5880/datacite.before.pending.failure')->exists())->toBeFalse();
    });

    it('keeps the underlying diagnostic in the cached error when pending import fails', function () {
        $this->pendingImportService
            ->shouldReceive('countImportablePending')
            ->once()
            ->andReturn(1);
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(0);
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                if (false) {
                    yield [];
                }
            })());
        $this->transformer
            ->shouldReceive('transform')
            ->never();
        $this->pendingImportService
            ->shouldReceive('importAllPending')
            ->once()
            ->with($this->user->id, 100)
            ->andThrow(new RuntimeException('legacy cursor read timed out'));

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $expectedError = 'SUMARIO pending resources could not be imported: legacy cursor read timed out';

        expect(fn () => $job->handle($this->importService, $this->transformer, $this->metaworksService))
            ->toThrow(RuntimeException::class, $expectedError);

        expect(Cache::get("datacite_import:{$importId}"))
            ->toMatchArray([
                'status' => 'failed',
                'error' => $expectedError,
            ]);
    });

    it('skips existing DOIs', function () {
        // Create existing resource
        $existingResource = Resource::factory()->create(['doi' => '10.5880/existing']);
        $existingResource->subjects()->create([
            'value' => 'Existing keyword',
            'subject_scheme' => null,
            'scheme_uri' => null,
            'value_uri' => null,
            'classification_code' => null,
            'language' => null,
        ]);

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
        $this->legacyResourceLookupService->shouldReceive('importMetadataByDoi')->never();
        $this->coverageEnrichmentService->shouldReceive('enrich')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['skipped'])->toBe(1);
        expect($status['skipped_dois'])->toContain('10.5880/existing');
        expect($existingResource->fresh()->subjects()->pluck('value')->all())->toBe(['Existing keyword']);
    });

    it('merges legacy subjects and passes related identifiers to preparation for new resources', function () {
        $doiRecord = [
            'id' => '10.5880/legacy-relations',
            'attributes' => [
                'doi' => '10.5880/legacy-relations',
                'titles' => [['title' => 'Legacy relations']],
                'subjects' => [[
                    'subject' => 'DataCite keyword',
                ]],
            ],
        ];
        $legacyRelatedIdentifiers = [[
            'identifier' => '10.5880/legacy-related',
            'identifierType' => 'DOI',
            'relationType' => 'Cites',
            'position' => 0,
        ]];
        $legacySubjects = [
            ['subject' => 'DataCite keyword'],
            [
                'subject' => 'EARTH SCIENCE > SOLID EARTH > TECTONICS',
                'subjectScheme' => 'Science Keywords',
                'schemeUri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
                'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/legacy-tectonics',
                'lang' => 'en',
            ],
            ['subject' => 'Legacy free keyword'],
        ];
        $mergedDoiRecord = $doiRecord;
        $mergedDoiRecord['attributes']['subjects'] = [
            ['subject' => 'DataCite keyword'],
            $legacySubjects[1],
            $legacySubjects[2],
        ];

        $this->importService->shouldReceive('getTotalDoiCount')->once()->andReturn(1);
        $this->importService->shouldReceive('fetchAllDois')->once()->andReturn((function () use ($doiRecord) {
            yield $doiRecord;
        })());
        $this->legacyResourceLookupService
            ->shouldReceive('importMetadataByDoi')
            ->once()
            ->with('10.5880/legacy-relations')
            ->andReturn([
                'relatedIdentifiers' => $legacyRelatedIdentifiers,
                'subjects' => $legacySubjects,
            ]);
        $this->transformer
            ->shouldReceive('prepareDoiData')
            ->once()
            ->with($mergedDoiRecord, $legacyRelatedIdentifiers, CitationLabelResolutionMode::BEST_EFFORT)
            ->andReturn($mergedDoiRecord);
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->with($mergedDoiRecord, $this->user->id)
            ->andReturn(Resource::factory()->make(['doi' => '10.5880/legacy-relations']));

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        expect(Cache::get("datacite_import:{$importId}")['imported'])->toBe(1);
    });

    it('adds CRC806 rights after preparation when DataCite and XML rights are empty', function (): void {
        Right::query()->create([
            'identifier' => 'CC-BY-NC-ND-4.0',
            'name' => 'Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International',
            'uri' => 'https://creativecommons.org/licenses/by-nc-nd/4.0',
            'scheme_uri' => 'https://spdx.org/licenses/',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $doiRecord = [
            'id' => '10.5880/sfb806.80',
            'attributes' => [
                'doi' => '10.5880/sfb806.80',
                'url' => 'http://crc806db.uni-koeln.de/dataset/show/example/',
                'titles' => [['title' => 'CRC806 rights import']],
            ],
        ];
        $preparedRecord = $doiRecord;
        unset($preparedRecord['attributes']['url']);

        $this->importService->shouldReceive('getTotalDoiCount')->once()->andReturn(1);
        $this->importService->shouldReceive('fetchAllDois')->once()->andReturn((function () use ($doiRecord) {
            yield $doiRecord;
        })());
        $this->transformer
            ->shouldReceive('prepareDoiData')
            ->once()
            ->with($doiRecord, [], CitationLabelResolutionMode::BEST_EFFORT)
            ->andReturn($preparedRecord);
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->withArgs(function (array $record, int $userId): bool {
                return $userId === $this->user->id
                    && ($record['attributes']['rightsList'] ?? null) === [[
                        'rights' => 'CC BY-NC-ND',
                        'rightsUri' => 'https://creativecommons.org/licenses/by-nc-nd/4.0',
                        'rightsIdentifier' => 'CC-BY-NC-ND-4.0',
                        'rightsIdentifierScheme' => 'SPDX',
                        'schemeUri' => 'https://spdx.org/licenses/',
                        'source' => 'legacy-crc806',
                    ]];
            })
            ->andReturn(Resource::factory()->make(['doi' => '10.5880/sfb806.80']));

        Http::fake([
            'https://crc806db.uni-koeln.de/*' => Http::response(crc806ImportJobPage()),
        ]);

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        expect(Cache::get("datacite_import:{$importId}")['imported'])->toBe(1);
        Http::assertSentCount(1);
    });

    it('does not request CRC806 when prepared DataCite or XML rights are present', function (): void {
        $doiRecord = [
            'id' => '10.5880/sfb806.existing-rights',
            'attributes' => [
                'doi' => '10.5880/sfb806.existing-rights',
                'url' => 'https://crc806db.uni-koeln.de/dataset/show/example/',
            ],
        ];
        $preparedRecord = [
            'id' => $doiRecord['id'],
            'attributes' => [
                'doi' => $doiRecord['attributes']['doi'],
                'rightsList' => [[
                    'rights' => 'Original XML rights',
                    'rightsUri' => 'https://example.test/original-rights',
                    'source' => 'datacite-import',
                ]],
            ],
        ];

        $this->importService->shouldReceive('getTotalDoiCount')->once()->andReturn(1);
        $this->importService->shouldReceive('fetchAllDois')->once()->andReturn((function () use ($doiRecord) {
            yield $doiRecord;
        })());
        $this->transformer
            ->shouldReceive('prepareDoiData')
            ->once()
            ->andReturn($preparedRecord);
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->with($preparedRecord, $this->user->id)
            ->andReturn(Resource::factory()->make(['doi' => $doiRecord['id']]));

        Http::fake();
        Http::preventStrayRequests();

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        expect(Cache::get("datacite_import:{$importId}")['imported'])->toBe(1);
        Http::assertNothingSent();
    });

    it('adds CRC806 rights beside a COAR access-right statement', function (): void {
        Right::query()->create([
            'identifier' => 'CC-BY-NC-ND-4.0',
            'name' => 'Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International',
            'uri' => 'https://creativecommons.org/licenses/by-nc-nd/4.0',
            'scheme_uri' => 'https://spdx.org/licenses/',
            'is_active' => true,
            'is_elmo_active' => true,
        ]);

        $doiRecord = [
            'id' => '10.5880/sfb806.coar-only',
            'attributes' => [
                'doi' => '10.5880/sfb806.coar-only',
                'url' => 'https://crc806db.uni-koeln.de/dataset/show/coar-only/',
            ],
        ];
        $coarRights = [
            'rights' => 'Open access',
            'rightsUri' => 'https://purl.org/coar/access_right/c_abf2',
            'rightsIdentifier' => 'c_abf2',
            'rightsIdentifierScheme' => 'COAR Access Rights',
            'schemeUri' => 'http://purl.org/coar/access_right/',
        ];
        $preparedRecord = $doiRecord;
        unset($preparedRecord['attributes']['url']);
        $preparedRecord['attributes']['rightsList'] = [$coarRights];

        $this->importService->shouldReceive('getTotalDoiCount')->once()->andReturn(1);
        $this->importService->shouldReceive('fetchAllDois')->once()->andReturn((function () use ($doiRecord) {
            yield $doiRecord;
        })());
        $this->transformer
            ->shouldReceive('prepareDoiData')
            ->once()
            ->andReturn($preparedRecord);
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->withArgs(function (array $record, int $userId) use ($coarRights): bool {
                return $userId === $this->user->id
                    && ($record['attributes']['rightsList'] ?? null) === [
                        $coarRights,
                        [
                            'rights' => 'CC BY-NC-ND',
                            'rightsUri' => 'https://creativecommons.org/licenses/by-nc-nd/4.0',
                            'rightsIdentifier' => 'CC-BY-NC-ND-4.0',
                            'rightsIdentifierScheme' => 'SPDX',
                            'schemeUri' => 'https://spdx.org/licenses/',
                            'source' => 'legacy-crc806',
                        ],
                    ];
            })
            ->andReturn(Resource::factory()->make(['doi' => $doiRecord['id']]));

        Http::fake([
            'https://crc806db.uni-koeln.de/*' => Http::response(crc806ImportJobPage(
                doi: '10.5880/sfb806.coar-only',
            )),
        ]);

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        expect(Cache::get("datacite_import:{$importId}")['imported'])->toBe(1);
        Http::assertSentCount(1);
    });

    it('continues a CRC806 import without rights when the legacy page is malformed', function (): void {
        $doiRecord = [
            'id' => '10.5880/sfb806.malformed',
            'attributes' => [
                'doi' => '10.5880/sfb806.malformed',
                'url' => 'https://crc806db.uni-koeln.de/dataset/show/malformed/',
            ],
        ];
        $preparedRecord = $doiRecord;
        unset($preparedRecord['attributes']['url']);

        $this->importService->shouldReceive('getTotalDoiCount')->once()->andReturn(1);
        $this->importService->shouldReceive('fetchAllDois')->once()->andReturn((function () use ($doiRecord) {
            yield $doiRecord;
        })());
        $this->transformer->shouldReceive('prepareDoiData')->once()->andReturn($preparedRecord);
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->with($preparedRecord, $this->user->id)
            ->andReturn(Resource::factory()->make(['doi' => $doiRecord['id']]));

        Http::fake([
            'https://crc806db.uni-koeln.de/*' => Http::response('<html>legacy page without route data</html>'),
        ]);

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['imported'])->toBe(1)
            ->and($status['failed'])->toBe(0);
    });

    it('shares the CRC806 circuit breaker across records in one bulk job', function (): void {
        $records = [
            [
                'id' => '10.5880/sfb806.unavailable.1',
                'attributes' => [
                    'doi' => '10.5880/sfb806.unavailable.1',
                    'url' => 'https://crc806db.uni-koeln.de/dataset/show/one/',
                ],
            ],
            [
                'id' => '10.5880/sfb806.unavailable.2',
                'attributes' => [
                    'doi' => '10.5880/sfb806.unavailable.2',
                    'url' => 'https://crc806db.uni-koeln.de/dataset/show/two/',
                ],
            ],
        ];

        $this->importService->shouldReceive('getTotalDoiCount')->once()->andReturn(2);
        $this->importService->shouldReceive('fetchAllDois')->once()->andReturn((function () use ($records) {
            yield from $records;
        })());
        $this->transformer
            ->shouldReceive('prepareDoiData')
            ->twice()
            ->andReturnUsing(function (array $record): array {
                unset($record['attributes']['url']);

                return $record;
            });
        $this->transformer
            ->shouldReceive('transform')
            ->twice()
            ->andReturnUsing(fn (array $record): Resource => Resource::factory()->make([
                'doi' => $record['attributes']['doi'],
            ]));

        Http::fake([
            'https://crc806db.uni-koeln.de/*' => Http::response('Unavailable', 503),
        ]);

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        expect(Cache::get("datacite_import:{$importId}")['imported'])->toBe(2);
        Http::assertSentCount(2);
    });

    it('continues without legacy metadata and opens the metaworks circuit breaker after lookup failure', function () {
        $records = [
            [
                'id' => '10.5880/metaworks-failure.1',
                'attributes' => ['doi' => '10.5880/metaworks-failure.1'],
            ],
            [
                'id' => '10.5880/metaworks-failure.2',
                'attributes' => ['doi' => '10.5880/metaworks-failure.2'],
            ],
        ];

        $this->importService->shouldReceive('getTotalDoiCount')->once()->andReturn(2);
        $this->importService->shouldReceive('fetchAllDois')->once()->andReturn((function () use ($records) {
            yield from $records;
        })());
        $this->legacyResourceLookupService
            ->shouldReceive('importMetadataByDoi')
            ->once()
            ->with('10.5880/metaworks-failure.1')
            ->andThrow(new RuntimeException('connection refused'));
        $this->transformer
            ->shouldReceive('prepareDoiData')
            ->twice()
            ->with(Mockery::type('array'), [], CitationLabelResolutionMode::BEST_EFFORT)
            ->andReturnUsing(fn (array $record): array => $record);
        $this->transformer
            ->shouldReceive('transform')
            ->twice()
            ->andReturnUsing(fn (array $record): Resource => Resource::factory()->make([
                'doi' => $record['attributes']['doi'],
            ]));
        $this->metaworksService->shouldReceive('lookupFileEntries')->never();

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['imported'])->toBe(2)
            ->and($status['failed'])->toBe(0);
    });

    it('continues legacy lookup after an ambiguous DOI without opening the metaworks circuit breaker', function (): void {
        $records = [
            [
                'id' => '10.5880/ambiguous.1',
                'attributes' => ['doi' => '10.5880/ambiguous.1'],
            ],
            [
                'id' => '10.5880/legacy.2',
                'attributes' => ['doi' => '10.5880/legacy.2'],
            ],
        ];

        $this->importService->shouldReceive('getTotalDoiCount')->once()->andReturn(2);
        $this->importService->shouldReceive('fetchAllDois')->once()->andReturn((function () use ($records) {
            yield from $records;
        })());
        $this->legacyResourceLookupService
            ->shouldReceive('importMetadataByDoi')
            ->once()
            ->ordered()
            ->with('10.5880/ambiguous.1')
            ->andThrow(new AmbiguousLegacyResourceException('duplicate DOI'));
        $this->legacyResourceLookupService
            ->shouldReceive('importMetadataByDoi')
            ->once()
            ->ordered()
            ->with('10.5880/legacy.2')
            ->andReturn([
                'relatedIdentifiers' => [],
                'subjects' => [],
                'legacyResourceId' => 902,
                'legacyResourceStatus' => 'released',
            ]);
        $this->transformer
            ->shouldReceive('transform')
            ->twice()
            ->andReturnUsing(fn (array $record): Resource => Resource::factory()->create([
                'doi' => $record['attributes']['doi'],
            ]));

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        $legacyResource = Resource::query()->where('doi', '10.5880/legacy.2')->firstOrFail();

        expect($status['imported'])->toBe(2)
            ->and($status['failed'])->toBe(0)
            ->and($legacyResource->legacy_source_id)->toBe(902);
    });

    it('normalizes incoming DOIs before checking for duplicates', function () {
        Resource::factory()->create(['doi' => '10.5880/gfz.ojsj.2026.001']);

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/GFZ.OJSJ.2026.001',
                    'attributes' => [
                        'doi' => 'https://doi.org/10.5880/GFZ.OJSJ.2026.001',
                    ],
                ];
            })());

        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['skipped'])->toBe(1)
            ->and($status['skipped_dois'])->toContain('10.5880/gfz.ojsj.2026.001');
    });

    it('passes a normalized DOI record to the transformer', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/GFZ.OJSJ.2026.002',
                    'attributes' => [
                        'doi' => 'https://doi.org/10.5880/GFZ.OJSJ.2026.002',
                        'titles' => [['title' => 'Normalized DOI Test']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->withArgs(function (array $doiRecord, int $userId): bool {
                return $userId === $this->user->id
                    && $doiRecord['id'] === '10.5880/gfz.ojsj.2026.002'
                    && $doiRecord['attributes']['doi'] === '10.5880/gfz.ojsj.2026.002';
            })
            ->andReturn(Resource::factory()->make(['doi' => '10.5880/gfz.ojsj.2026.002']));

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['imported'])->toBe(1)
            ->and($status['failed'])->toBe(0);
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
                    'id' => '10.5880/sample.1',
                    'attributes' => [
                        'doi' => '10.5880/sample.1',
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

    it('stops the bulk import when cancellation is detected before the first record is processed', function () {
        $importId = Str::uuid()->toString();

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(2);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () use ($importId) {
                Cache::put("datacite_import:{$importId}", [
                    'status' => 'cancelled',
                ], now()->addHour());

                yield [
                    'id' => '10.5880/cancelled.bulk.1',
                    'attributes' => ['doi' => '10.5880/cancelled.bulk.1'],
                ];

                yield [
                    'id' => '10.5880/cancelled.bulk.2',
                    'attributes' => ['doi' => '10.5880/cancelled.bulk.2'],
                ];
            })());

        $this->transformer->shouldReceive('transform')->never();

        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('cancelled')
            ->and($status['processed'])->toBe(1)
            ->and($status['imported'])->toBe(0)
            ->and($status['skipped'])->toBe(0)
            ->and($status['failed'])->toBe(0);
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
            ->andThrow(new Exception('Transform failed'));

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
            ->toThrow(InvalidArgumentException::class, 'Invalid importId format');
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

    it('returns the configured single DOI', function () {
        $job = new ImportFromDataCiteJob($this->user->id, Str::uuid()->toString(), '10.5880/configured.single');

        expect($job->getSingleDoi())->toBe('10.5880/configured.single');
    });

    it('marks records without any DOI as failed without calling the transformer', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'attributes' => [],
                ];
            })());

        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['failed'])->toBe(1)
            ->and($status['failed_dois'])->toBe([
                ['doi' => 'unknown', 'error' => 'No DOI found in record'],
            ]);
    });

    it('treats duplicate-entry race conditions as skipped imports', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/race-condition',
                    'attributes' => ['doi' => '10.5880/race-condition'],
                ];
            })());

        $pdoException = new PDOException('Duplicate entry');
        $pdoException->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $queryException = new QueryException('mysql', 'insert into `resources`', [], $pdoException);

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andThrow($queryException);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['imported'])->toBe(0)
            ->and($status['skipped'])->toBe(1)
            ->and($status['failed'])->toBe(0)
            ->and($status['skipped_dois'])->toContain('10.5880/race-condition');
    });

    it('treats sqlite unique constraint race conditions as skipped imports', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/sqlite-race-condition',
                    'attributes' => ['doi' => '10.5880/sqlite-race-condition'],
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

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['imported'])->toBe(0)
            ->and($status['skipped'])->toBe(1)
            ->and($status['failed'])->toBe(0)
            ->and($status['skipped_dois'])->toContain('10.5880/sqlite-race-condition');
    });

    it('records a failed DOI when a non-duplicate query exception bubbles out of the transformer', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/query.fail',
                    'attributes' => ['doi' => '10.5880/query.fail'],
                ];
            })());

        $pdoException = new PDOException('Deadlock found');
        $pdoException->errorInfo = ['40001', 1213, 'Deadlock found'];
        $queryException = new QueryException('mysql', 'insert into `resources`', [], $pdoException);

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andThrow($queryException);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['imported'])->toBe(0)
            ->and($status['skipped'])->toBe(0)
            ->and($status['failed'])->toBe(1)
            ->and($status['failed_dois'][0]['doi'])->toBe('10.5880/query.fail');
    });

    it('imports a single DOI when requested', function () {
        $doiRecord = [
            'id' => '10.5880/sample.single',
            'attributes' => [
                'doi' => '10.5880/sample.single',
                'titles' => [['title' => 'Single DOI Test']],
                'publicationYear' => 2024,
                'types' => ['resourceTypeGeneral' => 'Dataset'],
            ],
        ];

        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/sample.single')
            ->andReturn($doiRecord);

        $this->transformer
            ->shouldReceive('prepareDoiData')
            ->once()
            ->with($doiRecord, [], CitationLabelResolutionMode::EXHAUSTIVE)
            ->andReturn($doiRecord);

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->with($doiRecord, $this->user->id)
            ->andReturn(Resource::factory()->make());

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId, '10.5880/sample.single');
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['total'])->toBe(1)
            ->and($status['processed'])->toBe(1)
            ->and($status['imported'])->toBe(1)
            ->and($status['skipped'])->toBe(0)
            ->and($status['failed'])->toBe(0);
    });

    it('uses the nine original XML subjects instead of the extra Issue 1123 REST subject', function () {
        $doi = '10.5880/trr228db.398';
        $xmlSubjects = array_map(
            static fn (int $number): array => ['subject' => "Original keyword {$number}"],
            range(1, 9),
        );
        $xml = '<resource xmlns="http://datacite.org/schema/kernel-4"><subjects>'
            .implode('', array_map(
                static fn (array $subject): string => '<subject>'.htmlspecialchars($subject['subject'], ENT_XML1).'</subject>',
                $xmlSubjects,
            ))
            .'</subjects></resource>';
        $doiRecord = [
            'id' => $doi,
            'attributes' => [
                'doi' => $doi,
                'xml' => base64_encode($xml),
                'subjects' => [
                    ...$xmlSubjects,
                    ['subject' => 'FOS: Biological sciences'],
                ],
            ],
        ];
        $expectedRecord = $doiRecord;
        $expectedRecord['attributes']['subjects'] = $xmlSubjects;

        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with($doi)
            ->andReturn($doiRecord);
        $this->transformer
            ->shouldReceive('prepareDoiData')
            ->once()
            ->with($expectedRecord, [], CitationLabelResolutionMode::EXHAUSTIVE)
            ->andReturn($expectedRecord);
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->with($expectedRecord, $this->user->id)
            ->andReturn(Resource::factory()->make(['doi' => $doi]));

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, $doi))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['failed_dois'])->toBe([])
            ->and($status['imported'])->toBe(1);
    });

    it('prefers a specialized portal datacenter during a single DOI import', function () {
        $doi = '10.5880/icdp.5069.001';
        $doiRecord = [
            'id' => $doi,
            'attributes' => [
                'doi' => $doi,
                'titles' => [['title' => 'SDDB portal assignment']],
                'publicationYear' => 2026,
                'types' => ['resourceTypeGeneral' => 'Dataset'],
            ],
        ];

        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with($doi)
            ->andReturn($doiRecord);
        $this->portalService
            ->shouldReceive('datacenterNamesForDoi')
            ->once()
            ->with($doi)
            ->andReturn([
                LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER,
                LegacyMetaworksDatacenterLookupService::SDDB_DATACENTER,
            ]);
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->with($doiRecord, $this->user->id)
            ->andReturnUsing(fn (): Resource => Resource::factory()->create(['doi' => $doi]));
        $this->coverageEnrichmentService
            ->shouldReceive('enrich')
            ->once()
            ->withArgs(fn (Resource $resource, string $resolvedDoi): bool => $resource->doi === $doi
                && $resolvedDoi === $doi)
            ->andReturnTrue();

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, $doi))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $resource = Resource::query()->where('doi', $doi)->firstOrFail();

        expect(Cache::get("datacite_import:{$importId}"))
            ->toMatchArray([
                'status' => 'completed',
                'imported' => 1,
                'failed' => 0,
            ])
            ->and($resource->fresh()->datacenter?->name)
            ->toBe(LegacyMetaworksDatacenterLookupService::SDDB_DATACENTER);
    });

    it('keeps a new DataCite import successful when coverage enrichment unexpectedly fails', function () {
        $doi = '10.5880/coverage.enrichment.failure';
        $doiRecord = [
            'id' => $doi,
            'attributes' => [
                'doi' => $doi,
                'titles' => [['title' => 'Coverage enrichment failure']],
                'publicationYear' => 2026,
                'types' => ['resourceTypeGeneral' => 'Dataset'],
            ],
        ];

        $this->importService->shouldReceive('fetchSingleDoi')->once()->with($doi)->andReturn($doiRecord);
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->with($doiRecord, $this->user->id)
            ->andReturnUsing(fn (): Resource => Resource::factory()->create(['doi' => $doi]));
        $this->coverageEnrichmentService
            ->shouldReceive('enrich')
            ->once()
            ->andThrow(new RuntimeException('legacy coverage database unavailable'));

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, $doi))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        expect(Cache::get("datacite_import:{$importId}"))
            ->toMatchArray([
                'status' => 'completed',
                'imported' => 1,
                'failed' => 0,
            ])
            ->and(Resource::query()->where('doi', $doi)->exists())
            ->toBeTrue();
    });

    it('uses the legacy datacenter fallback when the portal has no DOI assignment', function () {
        $doi = '10.5880/icdp.5068.002';
        $doiRecord = [
            'id' => $doi,
            'attributes' => [
                'doi' => $doi,
                'titles' => [['title' => 'SDDB fallback assignment']],
                'publicationYear' => 2026,
                'types' => ['resourceTypeGeneral' => 'Dataset'],
            ],
        ];

        $this->importService->shouldReceive('fetchSingleDoi')->once()->with($doi)->andReturn($doiRecord);
        $this->portalService->shouldReceive('datacenterNamesForDoi')->once()->with($doi)->andReturn([]);
        $this->datacenterLookupService
            ->shouldReceive('syncDatacenters')
            ->once()
            ->withArgs(fn (Resource $resource, string $resolvedDoi): bool => $resource->doi === $doi && $resolvedDoi === $doi)
            ->andReturnUsing(function (Resource $resource): void {
                $datacenter = Datacenter::query()->firstOrCreate([
                    'name' => LegacyMetaworksDatacenterLookupService::SDDB_DATACENTER,
                ]);
                $resource->update(['datacenter_id' => $datacenter->id]);
            });
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn (): Resource => Resource::factory()->create(['doi' => $doi]));

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, $doi))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        expect(Resource::query()->where('doi', $doi)->firstOrFail()->datacenter?->name)
            ->toBe(LegacyMetaworksDatacenterLookupService::SDDB_DATACENTER)
            ->and(Cache::get("datacite_import:{$importId}")['status'])
            ->toBe('completed');
    });

    it('logs a portal failure and completes a single import through the legacy fallback', function () {
        Log::spy();

        $doi = '10.5880/icdp.5065.001';
        $doiRecord = [
            'id' => $doi,
            'attributes' => [
                'doi' => $doi,
                'titles' => [['title' => 'SDDB unavailable portal fallback']],
                'publicationYear' => 2026,
                'types' => ['resourceTypeGeneral' => 'Dataset'],
            ],
        ];

        $this->importService->shouldReceive('fetchSingleDoi')->once()->with($doi)->andReturn($doiRecord);
        $this->portalService
            ->shouldReceive('datacenterNamesForDoi')
            ->once()
            ->with($doi)
            ->andThrow(new RuntimeException('Portal unavailable'));
        $this->datacenterLookupService
            ->shouldReceive('syncDatacenters')
            ->once()
            ->andReturnNull();
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn (): Resource => Resource::factory()->create(['doi' => $doi]));

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, $doi))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        expect(Cache::get("datacite_import:{$importId}"))
            ->toMatchArray([
                'status' => 'completed',
                'imported' => 1,
                'failed' => 0,
            ]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'GFZ Data Services portal lookup failed during single DOI import; using legacy datacenter fallback.',
                [
                    'import_id' => $importId,
                    'doi' => $doi,
                    'error' => 'Portal unavailable',
                ],
            );
    });

    it('keeps an existing resource datacenter unchanged during a repeated single DOI import', function () {
        $doi = '10.5880/icdp.5069.001';
        $existingDatacenter = Datacenter::query()->create(['name' => 'Existing manual assignment']);
        $existingResource = Resource::factory()->create([
            'doi' => $doi,
            'datacenter_id' => $existingDatacenter->id,
        ]);
        $doiRecord = [
            'id' => $doi,
            'attributes' => [
                'doi' => $doi,
                'titles' => [['title' => 'Existing SDDB resource']],
            ],
        ];

        $this->importService->shouldReceive('fetchSingleDoi')->once()->with($doi)->andReturn($doiRecord);
        $this->portalService
            ->shouldReceive('datacenterNamesForDoi')
            ->once()
            ->with($doi)
            ->andReturn([LegacyMetaworksDatacenterLookupService::SDDB_DATACENTER]);
        $this->transformer->shouldReceive('transform')->never();
        $this->datacenterLookupService->shouldReceive('syncDatacenters')->never();

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, $doi))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        expect(Cache::get("datacite_import:{$importId}"))
            ->toMatchArray([
                'status' => 'completed',
                'imported' => 0,
                'skipped' => 1,
            ])
            ->and($existingResource->fresh()->datacenter_id)
            ->toBe($existingDatacenter->id);
    });

    it('assigns canonical GEOFON datacenters during single DataCite imports', function (
        string $doi,
        string $expectedDatacenter,
        bool $expectsCc0,
    ): void {
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

        $this->app->instance(
            LegacyMetaworksDatacenterLookupService::class,
            new LegacyMetaworksDatacenterLookupService(app(DoiSuggestionService::class)),
        );

        $doiRecord = [
            'id' => $doi,
            'attributes' => [
                'doi' => $doi,
                'titles' => [['title' => 'GEOFON import regression']],
                'publicationYear' => 2025,
                'types' => ['resourceTypeGeneral' => 'Dataset'],
            ],
        ];
        $expectedDoiRecord = $doiRecord;

        if ($expectsCc0) {
            Right::factory()->cc0()->create();
            $expectedDoiRecord['attributes']['rightsList'] = [[
                'rights' => 'Creative Commons Zero v1.0 Universal',
                'rightsUri' => 'https://creativecommons.org/publicdomain/zero/1.0/',
                'rightsIdentifier' => 'CC0-1.0',
                'rightsIdentifierScheme' => 'SPDX',
                'schemeUri' => 'https://spdx.org/licenses/',
                'source' => 'geofon-seismic-events-default',
            ]];
        }

        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with($doi)
            ->andReturn($doiRecord);

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->with($expectedDoiRecord, $this->user->id)
            ->andReturnUsing(fn (): Resource => Resource::factory()->create(['doi' => $doi]));

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId, $doi);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $resource = Resource::query()->where('doi', $doi)->firstOrFail();
        $status = Cache::get("datacite_import:{$importId}");

        expect($status['status'])->toBe('completed')
            ->and($status['imported'])->toBe(1)
            ->and($status['failed'])->toBe(0)
            ->and($resource->fresh()->datacenter?->name)->toBe($expectedDatacenter)
            ->and(Datacenter::query()->where('name', $expectedDatacenter)->count())->toBe(1);
    })->with([
        'GEOFON seismic network' => [
            '10.14470/rv968923',
            LegacyMetaworksDatacenterLookupService::GEOFON_NETWORKS_DATACENTER,
            false,
        ],
        'GEOFON seismic event' => [
            '10.1594/gfz.geofon.gfz2009gibb',
            LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER,
            true,
        ],
        'current GEOFON seismic event' => [
            '10.5880/geofon.gfz2015icra',
            LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER,
            true,
        ],
    ]);

    it('persists the GEOFON seismic-event default as a resolved SPDX CC0 license', function (): void {
        $this->seed([
            ResourceTypeSeeder::class,
            TitleTypeSeeder::class,
            DescriptionTypeSeeder::class,
            ContributorTypeSeeder::class,
            IdentifierTypeSeeder::class,
            LanguageSeeder::class,
            PublisherSeeder::class,
            RelationTypeSeeder::class,
            FunderIdentifierTypeSeeder::class,
        ]);
        $cc0 = Right::factory()->cc0()->create();
        $doi = '10.1594/gfz.geofon.gfz2026abcd';
        $doiRecord = [
            'id' => $doi,
            'attributes' => [
                'doi' => $doi,
                'titles' => [['title' => 'GEOFON seismic event']],
                'publicationYear' => 2026,
                'types' => ['resourceTypeGeneral' => 'Dataset'],
            ],
        ];

        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with($doi)
            ->andReturn($doiRecord);
        $this->portalService
            ->shouldReceive('datacenterNamesForDoi')
            ->once()
            ->with($doi)
            ->andReturn([LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER]);

        $transformer = new DataCiteToResourceTransformer;
        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, $doi))
            ->handle($this->importService, $transformer, $this->metaworksService);

        $resource = Resource::query()->where('doi', $doi)->firstOrFail();
        $resourceRight = ResourceRight::query()
            ->where('resource_id', $resource->id)
            ->sole();

        expect($resource->fresh()->datacenter?->name)
            ->toBe(LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER)
            ->and($resourceRight->rights_id)->toBe($cc0->id)
            ->and($resourceRight->rights_text)->toBe($cc0->name)
            ->and($resourceRight->rights_uri)->toBe($cc0->uri)
            ->and($resourceRight->rights_identifier)->toBe('CC0-1.0')
            ->and($resourceRight->rights_identifier_scheme)->toBe('SPDX')
            ->and($resourceRight->scheme_uri)->toBe('https://spdx.org/licenses/')
            ->and($resourceRight->source)->toBe('geofon-seismic-events-default')
            ->and(ResourceRight::query()
                ->where('resource_id', $resource->id)
                ->whereNull('rights_id')
                ->count())->toBe(0);
    });

    it('completes a single import when an exhaustive citation label lookup remains unresolved', function () {
        $doiRecord = [
            'id' => '10.5880/incomplete-citations',
            'attributes' => [
                'doi' => '10.5880/incomplete-citations',
                'relatedIdentifiers' => [[
                    'relatedIdentifier' => '10.1234/unavailable',
                    'relatedIdentifierType' => 'DOI',
                    'relationType' => 'Cites',
                ]],
            ],
        ];
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/incomplete-citations')
            ->andReturn($doiRecord);
        $this->transformer
            ->shouldReceive('prepareDoiData')
            ->once()
            ->with($doiRecord, [], CitationLabelResolutionMode::EXHAUSTIVE)
            ->andReturn($doiRecord);
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->with($doiRecord, $this->user->id)
            ->andReturn(Resource::factory()->make(['doi' => '10.5880/incomplete-citations']));

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, '10.5880/incomplete-citations'))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");

        expect($status)->toMatchArray([
            'status' => 'completed',
            'total' => 1,
            'processed' => 1,
            'imported' => 1,
            'skipped' => 0,
            'failed' => 0,
            'failed_dois' => [],
        ]);
    });

    it('marks single import as failed when DOI is missing from DataCite', function () {
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/missing.single')
            ->andReturnNull();

        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId, '10.5880/missing.single');
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('failed')
            ->and($status['total'])->toBe(1)
            ->and($status['processed'])->toBe(1)
            ->and($status['imported'])->toBe(0)
            ->and($status['failed'])->toBe(1)
            ->and($status['failed_dois'])->toBe([
                ['doi' => '10.5880/missing.single', 'error' => 'The DOI was not found in DataCite or eligible SUMARIO legacy resources.'],
            ]);
    });

    it('marks single SUMARIO pending fallback as failed when the lookup is unavailable', function () {
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/pending.unavailable')
            ->andReturnNull();

        $this->pendingImportService
            ->shouldReceive('importReviewFallbackByDoi')
            ->once()
            ->with(
                '10.5880/pending.unavailable',
                $this->user->id,
                CitationLabelResolutionMode::EXHAUSTIVE,
            )
            ->andThrow(new RuntimeException('Connection refused'));

        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId, '10.5880/pending.unavailable');
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('failed')
            ->and($status['total'])->toBe(1)
            ->and($status['processed'])->toBe(1)
            ->and($status['imported'])->toBe(0)
            ->and($status['failed'])->toBe(1)
            ->and($status['error'])->toBe('SUMARIO legacy lookup is unavailable.')
            ->and($status['failed_dois'])->toBe([
                ['doi' => '10.5880/pending.unavailable', 'error' => 'SUMARIO legacy lookup is unavailable.'],
            ]);
    });

    it('imports a single SUMARIO pending resource when DataCite has no DOI record', function () {
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/pending.single')
            ->andReturnNull();

        $this->pendingImportService
            ->shouldReceive('importReviewFallbackByDoi')
            ->once()
            ->with(
                '10.5880/pending.single',
                $this->user->id,
                CitationLabelResolutionMode::EXHAUSTIVE,
            )
            ->andReturn([
                'status' => 'imported',
                'resource' => Resource::factory()->create([
                    'doi' => '10.5880/pending.single',
                    'force_review_status' => true,
                    'legacy_source_status' => 'pending',
                ]),
                'doi' => '10.5880/pending.single',
                'error' => null,
            ]);

        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId, '10.5880/pending.single');
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['processed'])->toBe(1)
            ->and($status['imported'])->toBe(1)
            ->and($status['failed'])->toBe(0);
    });

    it('imports a single released SUMARIO resource as review when DataCite has no DOI record', function () {
        $doi = '10.5880/icgem.2026.001';

        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with($doi)
            ->andReturnNull();

        $this->pendingImportService
            ->shouldReceive('importReviewFallbackByDoi')
            ->once()
            ->with(
                $doi,
                $this->user->id,
                CitationLabelResolutionMode::EXHAUSTIVE,
            )
            ->andReturnUsing(function () use ($doi): array {
                $resource = Resource::factory()->create([
                    'doi' => $doi,
                    'access_level' => null,
                    'legacy_source' => 'sumario-pmd',
                    'legacy_source_status' => 'released',
                    'force_review_status' => true,
                    'workflow_status_override' => ResourceWorkflowStatus::REVIEW,
                ]);
                LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);

                return [
                    'status' => 'imported',
                    'resource' => $resource,
                    'doi' => $doi,
                    'error' => null,
                ];
            });

        $this->transformer->shouldReceive('transform')->never();

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId, $doi))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        $resource = Resource::query()->where('doi', $doi)->with('landingPage')->firstOrFail();

        expect($status)->toMatchArray([
            'status' => 'completed',
            'processed' => 1,
            'imported' => 1,
            'failed' => 0,
            'sync_total' => 0,
        ])
            ->and($resource->legacy_source_status)->toBe('released')
            ->and($resource->publicStatus())->toBe('review')
            ->and($resource->landingPage)->not->toBeNull()
            ->and($resource->landingPage->is_published)->toBeFalse();
    });

    it('marks a single SUMARIO pending fallback as skipped when the resource already exists', function () {
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/pending.skip')
            ->andReturnNull();

        $this->pendingImportService
            ->shouldReceive('importReviewFallbackByDoi')
            ->once()
            ->with(
                '10.5880/pending.skip',
                $this->user->id,
                CitationLabelResolutionMode::EXHAUSTIVE,
            )
            ->andReturn([
                'status' => 'skipped',
                'resource' => null,
                'doi' => '10.5880/pending.skip',
                'error' => null,
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId, '10.5880/pending.skip');
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['imported'])->toBe(0)
            ->and($status['skipped'])->toBe(1)
            ->and($status['skipped_dois'])->toBe(['10.5880/pending.skip']);
    });

    it('syncs newly imported published DataCite resources after enrichment in production', function () {
        Config::set('datacite.test_mode', false);
        Bus::fake();

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/sync.production',
                    'attributes' => [
                        'doi' => '10.5880/sync.production',
                        'state' => 'findable',
                        'titles' => [['title' => 'Production Sync Dataset']],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => Resource::factory()->create(['doi' => '10.5880/sync.production']));

        $this->metaworksService
            ->shouldReceive('lookupFileEntries')
            ->once()
            ->with('10.5880/sync.production')
            ->andReturn([
                'files' => [
                    [
                        'url' => 'https://datapub.gfz.de/download/10.5880/sync.production/data.zip',
                        'label' => 'Data package',
                        'visible' => 'public',
                    ],
                ],
                'allPublic' => true,
                'resourceFound' => true,
                'resourcePublicStatus' => 'released',
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('running')
            ->and($status['phase'])->toBe('syncing')
            ->and($status['imported'])->toBe(1)
            ->and($status['failed'])->toBe(0)
            ->and($status['sync_total'])->toBe(1);
        Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === 1);
    });

    it('normalizes and marks published SUMARIO resources imported through DataCite', function () {
        Config::set('datacite.test_mode', false);
        Bus::fake();

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);
        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/legacy.description.breaks',
                    'attributes' => [
                        'doi' => '10.5880/legacy.description.breaks',
                        'state' => 'findable',
                        'titles' => [['title' => 'Legacy Description Breaks']],
                        'descriptions' => [[
                            'descriptionType' => 'Abstract',
                            'description' => 'First<br> <br><br><br>Second',
                        ]],
                    ],
                ];
            })());
        $this->legacyResourceLookupService
            ->shouldReceive('importMetadataByDoi')
            ->once()
            ->with('10.5880/legacy.description.breaks')
            ->andReturn([
                'relatedIdentifiers' => [],
                'subjects' => [],
                'legacyResourceId' => 991,
                'legacyResourceStatus' => 'released',
            ]);
        $this->transformer
            ->shouldReceive('prepareDoiData')
            ->once()
            ->withArgs(function (array $record): bool {
                expect($record['attributes']['descriptions'][0]['description'])
                    ->toBe('First<br><br>Second');

                return true;
            })
            ->andReturnUsing(fn (array $record): array => $record);
        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => Resource::factory()->create([
                'doi' => '10.5880/legacy.description.breaks',
            ]));

        $importId = Str::uuid()->toString();
        (new ImportFromDataCiteJob($this->user->id, $importId))
            ->handle($this->importService, $this->transformer, $this->metaworksService);

        $resource = Resource::query()->where('doi', '10.5880/legacy.description.breaks')->sole();
        $status = Cache::get("datacite_import:{$importId}");

        expect($resource->legacy_source)->toBe('sumario-pmd')
            ->and($resource->legacy_source_id)->toBe(991)
            ->and($resource->legacy_source_status)->toBe('released')
            ->and($resource->legacy_description_breaks_normalized_at)->not->toBeNull()
            ->and($status)->toMatchArray([
                'phase' => 'syncing',
                'sync_total' => 1,
                'sync_full_metadata_total' => 1,
            ])->not->toHaveKey('sync_full_metadata_resource_ids');

        Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === 1);
    });

    it('does not call DataCite when test mode is enabled', function () {
        Config::set('datacite.test_mode', true);

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/sync.disabled',
                    'attributes' => [
                        'doi' => '10.5880/sync.disabled',
                        'state' => 'findable',
                        'titles' => [['title' => 'Sync Disabled Dataset']],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => Resource::factory()->create(['doi' => '10.5880/sync.disabled']));

        $this->metaworksService
            ->shouldReceive('lookupFileEntries')
            ->once()
            ->with('10.5880/sync.disabled')
            ->andReturn([
                'files' => [],
                'allPublic' => false,
                'resourceFound' => true,
                'hasFileRows' => false,
                'resourcePublicStatus' => 'published',
            ]);

        $syncService = Mockery::mock(DataCiteSyncService::class);
        $syncService
            ->shouldReceive('syncIfRegistered')
            ->never();
        $this->app->instance(DataCiteSyncService::class, $syncService);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['imported'])->toBe(1)
            ->and($status['failed'])->toBe(0)
            ->and($status['sync_skipped_test_mode'])->toBeTrue()
            ->and($status['sync_total'])->toBe(1);
    });

    it('marks single import as failed when the DOI transform throws an exception', function () {
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/failing.single')
            ->andReturn([
                'id' => '10.5880/failing.single',
                'attributes' => [
                    'doi' => '10.5880/failing.single',
                ],
            ]);

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andThrow(new RuntimeException('Transform failed hard'));

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId, '10.5880/failing.single');
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('failed')
            ->and($status['failed'])->toBe(1)
            ->and($status['failed_dois'])->toBe([
                ['doi' => '10.5880/failing.single', 'error' => 'Transform failed hard'],
            ])
            ->and($status['error'])->toBe('Transform failed hard');
    });

    it('preserves a cancelled status when a single import is cancelled during processing', function () {
        $this->importService
            ->shouldReceive('fetchSingleDoi')
            ->once()
            ->with('10.5880/cancelled.single')
            ->andReturn([
                'id' => '10.5880/cancelled.single',
                'attributes' => [
                    'doi' => '10.5880/cancelled.single',
                    'titles' => [['title' => 'Cancelled Single DOI']],
                ],
            ]);

        $importId = Str::uuid()->toString();

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(function () use ($importId) {
                Cache::put("datacite_import:{$importId}", [
                    'status' => 'cancelled',
                ], now()->addHour());

                return Resource::factory()->make();
            });

        $job = new ImportFromDataCiteJob($this->user->id, $importId, '10.5880/cancelled.single');
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('cancelled')
            ->and($status['imported'])->toBe(1);
    });

    it('defensively rejects invoking the private single import handler without a DOI', function () {
        $job = new ImportFromDataCiteJob($this->user->id, Str::uuid()->toString());
        $method = new ReflectionMethod($job, 'handleSingleImport');
        $method->setAccessible(true);

        expect(fn () => $method->invoke(
            $job,
            $this->importService,
            $this->transformer,
            $this->metaworksService,
            now()->toIso8601String(),
        ))->toThrow(RuntimeException::class, 'Single DOI import requested without a DOI.');
    });

    it('marks the import as failed and rethrows when the bulk import bootstrap crashes', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andThrow(new RuntimeException('Count unavailable'));

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);

        expect(fn () => $job->handle($this->importService, $this->transformer, $this->metaworksService))
            ->toThrow(RuntimeException::class, 'Count unavailable');

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('failed')
            ->and($status['error'])->toBe('Count unavailable');
    });
});

describe('ImportFromDataCiteJob download URL enrichment', function () {
    it('imports the released DOME 2026.001 directory as primary download and preserves additional links', function () {
        Cache::put(CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->key(), [
            'domains' => [['value' => 'https://stale.example.org/', 'usage_count' => 99]],
            'urls' => [['value' => 'https://stale.example.org/download/file.zip', 'usage_count' => 99]],
        ]);

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/fidgeo.d.2026.001',
                    'attributes' => [
                        'doi' => '10.5880/fidgeo.d.2026.001',
                        'url' => 'https://dataservices.gfz.de/dome/showshort.php?id=legacy',
                        'state' => 'findable',
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
            ->andReturnUsing(fn () => Resource::factory()->create(['doi' => '10.5880/fidgeo.d.2026.001']));

        // Override the default mock: return download URLs (all public)
        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileEntries')
            ->with('10.5880/fidgeo.d.2026.001')
            ->once()
            ->andReturn([
                'files' => [
                    [
                        'url' => 'https://datapub.gfz.de/download/10.5880.FIDGEO.D.2026.001-lagvge',
                        'label' => 'Data and description',
                        'visible' => 'public',
                    ],
                    [
                        'url' => 'https://datapub.gfz.de/download/10.5880.FIDGEO.D.2026.001-lagvge/supplement.xlsx',
                        'label' => 'Supplement table',
                        'visible' => 'public',
                    ],
                ],
                'allPublic' => true,
                'resourceFound' => true,
                'resourcePublicStatus' => 'released',
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        // Verify landing page was created
        $resource = Resource::where('doi', '10.5880/fidgeo.d.2026.001')->first();
        $landingPage = LandingPage::where('resource_id', $resource->id)->first();
        expect($landingPage)->not->toBeNull()
            ->and($landingPage->template)->toBe('default_gfz')
            ->and($landingPage->is_published)->toBeTrue()
            ->and($landingPage->published_at)->not->toBeNull()
            ->and($landingPage->ftp_url)->toBe('https://datapub.gfz.de/download/10.5880.FIDGEO.D.2026.001-lagvge');

        $links = LandingPageLink::where('landing_page_id', $landingPage->id)->orderBy('position')->get();
        expect($links)->toHaveCount(1)
            ->and($links[0]->url)->toBe('https://datapub.gfz.de/download/10.5880.FIDGEO.D.2026.001-lagvge/supplement.xlsx')
            ->and($links[0]->label)->toBe('Supplement table')
            ->and($links[0]->position)->toBe(0);

        expect(LandingPageFile::where('landing_page_id', $landingPage->id)->count())->toBe(0);

        expect(Cache::get(CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->key()))->toBeNull();

        // The setup modal loads this endpoint when opened. Verify the import's
        // persisted primary directory is returned through that exact path.
        $this->actingAs($this->user)
            ->getJson("/resources/{$resource->id}/landing-page")
            ->assertOk()
            ->assertJsonPath(
                'landing_page.ftp_url',
                'https://datapub.gfz.de/download/10.5880.FIDGEO.D.2026.001-lagvge',
            );
    });

    it('keeps a landing page in review when public files belong to a non-findable DOI', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/lp.registered.001',
                    'attributes' => [
                        'doi' => '10.5880/lp.registered.001',
                        'state' => 'registered',
                        'titles' => [['title' => 'Registered Dataset']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => Resource::factory()->create(['doi' => '10.5880/lp.registered.001']));

        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileEntries')
            ->with('10.5880/lp.registered.001')
            ->once()
            ->andReturn([
                'files' => [
                    [
                        'url' => 'https://datapub.gfz.de/download/public-file.zip',
                        'label' => 'Public package',
                        'visible' => 'public',
                    ],
                ],
                'allPublic' => true,
                'resourceFound' => true,
                'resourcePublicStatus' => 'published',
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        $landingPage = Resource::where('doi', '10.5880/lp.registered.001')
            ->firstOrFail()
            ->fresh(['landingPage'])
            ->landingPage;

        expect($landingPage)->not->toBeNull()
            ->and($landingPage->is_published)->toBeFalse()
            ->and($landingPage->published_at)->toBeNull();
    });

    it('publishes a landing page regardless of legacy file visibility', function () {
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
                        'state' => 'findable',
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
        $metaworksService->shouldReceive('lookupFileEntries')
            ->with('10.5880/lp.nonpub.001')
            ->once()
            ->andReturn([
                'files' => [
                    [
                        'url' => 'https://datapub.gfz.de/download/internal-file.zip',
                        'label' => 'Internal package',
                        'visible' => 'internal',
                    ],
                ],
                'allPublic' => false,
                'resourceFound' => true,
                'resourcePublicStatus' => 'published',
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        // File visibility is not a publication criterion.
        $resource = Resource::where('doi', '10.5880/lp.nonpub.001')->first();
        $landingPage = LandingPage::where('resource_id', $resource->id)->first();
        expect($landingPage)->not->toBeNull()
            ->and($landingPage->is_published)->toBeTrue()
            ->and($landingPage->published_at)->not->toBeNull();

        expect($landingPage->ftp_url)->toBe('https://datapub.gfz.de/download/internal-file.zip')
            ->and(LandingPageLink::where('landing_page_id', $landingPage->id)->count())->toBe(0)
            ->and(LandingPageFile::where('landing_page_id', $landingPage->id)->count())->toBe(0);
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

    it('creates a default landing page with downloads unavailable when a legacy resource has no files', function () {
        $this->seed([
            ResourceTypeSeeder::class,
            TitleTypeSeeder::class,
            DescriptionTypeSeeder::class,
            ContributorTypeSeeder::class,
            IdentifierTypeSeeder::class,
            LanguageSeeder::class,
            PublisherSeeder::class,
            RelationTypeSeeder::class,
            FunderIdentifierTypeSeeder::class,
        ]);
        $catalogRight = Right::factory()->create([
            'identifier' => 'CC-BY-NC-4.0',
            'name' => 'Creative Commons Attribution Non Commercial 4.0 International',
            'uri' => 'https://spdx.org/licenses/CC-BY-NC-4.0.html',
            'scheme_uri' => 'https://spdx.org/licenses/',
        ]);

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/gfz.dekorp.ktb8401.001',
                    'attributes' => [
                        'doi' => '10.5880/gfz.dekorp.ktb8401.001',
                        'url' => 'https://dataservices.gfz.de/dekorp/showshort.php?id=493dcc02-011c-11ed-9531-ca1f3ed77ce8',
                        'state' => 'findable',
                        'titles' => [['title' => 'DEKORP No Files Dataset']],
                        'creators' => [['name' => 'DEKORP Research Team']],
                        'publicationYear' => 2022,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                        'fundingReferences' => [
                            [
                                'funderName' => 'Bundesministerium für Forschung und Technologie',
                                'funderIdentifier' => 'https://doi.org/10.13039/501100004937',
                                'funderIdentifierType' => 'Crossref Funder ID',
                                'awardTitle' => 'DEKORP',
                            ],
                            [
                                'funderName' => 'Bundesministerium für Forschung und Technologie',
                                'funderIdentifier' => 'https://doi.org/10.13039/501100004937',
                                'funderIdentifierType' => 'Crossref Funder ID',
                                'awardTitle' => 'KTB',
                            ],
                        ],
                        'rightsList' => [[
                            'rights' => 'Creative Commons Attribution Non Commercial 4.0 International',
                            'rightsUri' => 'https://creativecommons.org/licenses/by-nc/4.0/legalcode',
                            'rightsIdentifier' => 'CC-BY-NC-4.0',
                            'rightsIdentifierScheme' => 'SPDX',
                            'schemeUri' => 'https://spdx.org/licenses/',
                        ]],
                        'xml' => <<<'XML'
                            <resource xmlns="http://datacite.org/schema/kernel-4">
                              <rightsList>
                                <rights rightsURI="https://creativecommons.org/licenses/by-nc/4.0/legalcode" rightsIdentifier="CC-BY-NC-4.0" rightsIdentifierScheme="SPDX" schemeURI="https://spdx.org/licenses/">Creative Commons Attribution Non Commercial 4.0 International</rights>
                                <rights rightsURI="https://creativecommons.org/licenses/by-nc/4.0/">Creative Commons Attribution-NonCommercial 4.0 International (CC BY-NC 4.0)</rights>
                              </rightsList>
                            </resource>
                            XML,
                    ],
                ];
            })());

        // Exercise the actual DataCite transformation and rights persistence;
        // only the external DataCite and legacy service boundaries stay mocked.
        $transformer = new DataCiteToResourceTransformer;
        $this->app->instance(DataCiteToResourceTransformer::class, $transformer);

        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileEntries')
            ->once()
            ->with('10.5880/gfz.dekorp.ktb8401.001')
            ->andReturn([
                'files' => [],
                'allPublic' => false,
                'resourceFound' => true,
                'hasFileRows' => false,
                'resourcePublicStatus' => 'published',
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $transformer, $metaworksService);

        $resource = Resource::where('doi', '10.5880/gfz.dekorp.ktb8401.001')->firstOrFail();
        $landingPage = $resource->fresh(['landingPage'])->landingPage;
        $resolvedResourceRight = ResourceRight::query()
            ->where('resource_id', $resource->id)
            ->whereNotNull('rights_id')
            ->sole();
        $rawResourceRight = ResourceRight::query()
            ->where('resource_id', $resource->id)
            ->whereNull('rights_id')
            ->sole();
        $landingPageTransformer = new LandingPageResourceTransformer;
        $resource->load($landingPageTransformer->requiredRelations());
        $landingPagePayload = $landingPageTransformer->transform($resource);
        $catalogLicense = collect($landingPagePayload['licenses'])->firstWhere('source', 'catalog');
        $rawLicense = collect($landingPagePayload['licenses'])->firstWhere('source', 'raw');

        expect($landingPage)->not->toBeNull()
            ->and($landingPage->template)->toBe('default_gfz')
            ->and($landingPage->ftp_url)->toBeNull()
            ->and($landingPage->downloads_unavailable)->toBeTrue()
            ->and($landingPage->is_published)->toBeTrue()
            ->and($landingPage->published_at)->not->toBeNull()
            ->and(LandingPageDomain::count())->toBe(0)
            ->and($resource->fundingReferences)->toHaveCount(2)
            ->and($resource->fundingReferences->pluck('award_title')->all())->toBe(['DEKORP', 'KTB'])
            ->and($resolvedResourceRight->rights_id)->toBe($catalogRight->id)
            ->and($rawResourceRight->rights_id)->toBeNull()
            ->and($rawResourceRight->rights_text)->toBe('Creative Commons Attribution-NonCommercial 4.0 International (CC BY-NC 4.0)')
            ->and($landingPagePayload['licenses'])->toHaveCount(2)
            ->and($catalogLicense)->toMatchArray([
                'id' => $catalogRight->id,
                'resource_right_id' => $resolvedResourceRight->id,
                'spdx_id' => 'CC-BY-NC-4.0',
                'source' => 'catalog',
            ])
            ->and($rawLicense)->toMatchArray([
                'resource_right_id' => $rawResourceRight->id,
                'name' => 'Creative Commons Attribution-NonCommercial 4.0 International (CC BY-NC 4.0)',
                'reference' => 'https://creativecommons.org/licenses/by-nc/4.0/',
                'source' => 'raw',
            ]);
    });

    it('publishes a findable landing page even when non-public legacy rows have no valid URLs', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/invalid.private.001',
                    'attributes' => [
                        'doi' => '10.5880/invalid.private.001',
                        'state' => 'findable',
                        'titles' => [['title' => 'Invalid Private File Dataset']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => Resource::factory()->create(['doi' => '10.5880/invalid.private.001']));

        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileEntries')
            ->once()
            ->with('10.5880/invalid.private.001')
            ->andReturn([
                'files' => [],
                'allPublic' => false,
                'resourceFound' => true,
                'hasFileRows' => true,
                'resourcePublicStatus' => 'published',
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        $resource = Resource::where('doi', '10.5880/invalid.private.001')->firstOrFail();
        $landingPage = $resource->fresh(['landingPage'])->landingPage;

        expect($landingPage)->not->toBeNull()
            ->and($landingPage->ftp_url)->toBeNull()
            ->and($landingPage->downloads_unavailable)->toBeTrue()
            ->and($landingPage->is_published)->toBeTrue()
            ->and($landingPage->published_at)->not->toBeNull();
    });

    it('ignores old DataCite data services URLs and imports SUMARIO file URLs instead', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/gfz.2.6.2023.010',
                    'attributes' => [
                        'doi' => '10.5880/gfz.2.6.2023.010',
                        'url' => 'https://dataservices.gfz.de/panmetaworks/showshort.php?id=d9d1cfb5-7a4f-11ee-967a-4ffbfe06208e',
                        'state' => 'findable',
                        'titles' => [['title' => 'Global energy magnitude catalog']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => Resource::factory()->create(['doi' => '10.5880/gfz.2.6.2023.010']));

        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileEntries')
            ->once()
            ->with('10.5880/gfz.2.6.2023.010')
            ->andReturn([
                'files' => [
                    [
                        'url' => 'https://datapub.gfz.de/download/10.5880.GFZ.2.6.2023.010-NcaeZB',
                        'label' => 'download data and description',
                        'visible' => 'public',
                    ],
                ],
                'allPublic' => true,
                'resourceFound' => true,
                'resourcePublicStatus' => 'published',
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        $resource = Resource::where('doi', '10.5880/gfz.2.6.2023.010')->firstOrFail();
        $landingPage = $resource->fresh(['landingPage.externalDomain'])->landingPage;

        expect($landingPage)->not->toBeNull()
            ->and($landingPage->template)->toBe('default_gfz')
            ->and($landingPage->ftp_url)->toBe('https://datapub.gfz.de/download/10.5880.GFZ.2.6.2023.010-NcaeZB')
            ->and($landingPage->downloads_unavailable)->toBeFalse()
            ->and($landingPage->externalDomain)->toBeNull()
            ->and(LandingPageDomain::count())->toBe(0);
    });

    it('skips imported legacy resources when the DOI contains test or delete', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/fidgeo.test.to.be.deleted',
                    'attributes' => [
                        'doi' => '10.5880/fidgeo.test.to.be.deleted',
                        'url' => 'https://ernie.rz-vm499.gfz.de/10.5880/fidgeo.test.to.be.deleted/test-title-to-be-deleted',
                        'titles' => [['title' => 'Test title to be deleted']],
                    ],
                ];
            })());

        $this->transformer->shouldReceive('prepareDoiData')->never();
        $this->transformer->shouldReceive('transform')->never();
        $this->metaworksService->shouldReceive('lookupFileEntries')->never();

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $this->metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['imported'])->toBe(0)
            ->and($status['skipped'])->toBe(1)
            ->and($status['skipped_dois'])->toBe(['10.5880/fidgeo.test.to.be.deleted'])
            ->and(Resource::where('doi', '10.5880/fidgeo.test.to.be.deleted')->exists())->toBeFalse();
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

    it('repairs landing page downloads and schedules URL sync for skipped released resources', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/skip.backfill']);

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/skip.backfill',
                    'attributes' => [
                        'doi' => '10.5880/skip.backfill',
                        'url' => 'https://dataservices.gfz.de/dome/showshort.php?id=legacy',
                        'state' => 'findable',
                    ],
                ];
            })());

        $this->transformer->shouldReceive('transform')->never();

        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileEntries')
            ->once()
            ->with('10.5880/skip.backfill')
            ->andReturn([
                'files' => [[
                    'url' => 'https://datapub.gfz.de/download/10.5880.skip.backfill',
                    'label' => 'Data and description',
                    'visible' => 'public',
                ]],
                'allPublic' => true,
                'resourceFound' => true,
                'hasFileRows' => true,
                'resourcePublicStatus' => 'released',
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['imported'])->toBe(0)
            ->and($status['skipped'])->toBe(1)
            ->and($status['enriched'])->toBe(1)
            ->and($status['skipped_dois'])->toBe(['10.5880/skip.backfill'])
            ->and($status['enriched_dois'])->toBe(['10.5880/skip.backfill'])
            ->and($status['sync_total'])->toBe(1)
            ->and($status['sync_skipped_test_mode'])->toBeTrue();

        $landingPage = $resource->fresh(['landingPage'])->landingPage;
        expect($landingPage)->not->toBeNull()
            ->and($landingPage->is_published)->toBeTrue()
            ->and($landingPage->ftp_url)->toBe('https://datapub.gfz.de/download/10.5880.skip.backfill');
    });

    it('creates an external landing page from DataCite url before metaworks lookup', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.14470/rv968923',
                    'attributes' => [
                        'doi' => '10.14470/rv968923',
                        'url' => 'https://geofon.gfz.de/waveform/archive/network.php?ncode=_EIFELLNX',
                        'state' => 'findable',
                        'titles' => [['title' => 'GEOFON Network']],
                        'publicationYear' => 2024,
                        'types' => ['resourceTypeGeneral' => 'Dataset'],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturnUsing(fn () => Resource::factory()->create(['doi' => '10.14470/rv968923']));

        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldNotReceive('lookupFileEntries');

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        $resource = Resource::where('doi', '10.14470/rv968923')->firstOrFail();
        $landingPage = $resource->fresh(['landingPage.externalDomain'])->landingPage;

        expect($landingPage)->not->toBeNull()
            ->and($landingPage->template)->toBe('external')
            ->and($landingPage->is_published)->toBeTrue()
            ->and($landingPage->ftp_url)->toBeNull()
            ->and($landingPage->externalDomain->domain)->toBe('https://geofon.gfz.de/')
            ->and($landingPage->external_path)->toBe('waveform/archive/network.php?ncode=_EIFELLNX')
            ->and(LandingPageDomain::where('domain', 'https://geofon.gfz.de/')->exists())->toBeTrue();
    });

    it('backfills an external DataCite landing page for skipped existing resources', function () {
        $resource = Resource::factory()->create(['doi' => '10.14470/skip.external']);

        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.14470/skip.external',
                    'attributes' => [
                        'doi' => '10.14470/skip.external',
                        'url' => 'https://geofon.gfz.de/waveform/archive/network.php?ncode=SKIP',
                        'state' => 'findable',
                    ],
                ];
            })());

        $this->transformer->shouldReceive('transform')->never();

        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldNotReceive('lookupFileEntries');

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['imported'])->toBe(0)
            ->and($status['skipped'])->toBe(1)
            ->and($status['enriched'])->toBe(1)
            ->and($status['enriched_dois'])->toBe(['10.14470/skip.external']);

        $landingPage = $resource->fresh(['landingPage.externalDomain'])->landingPage;
        expect($landingPage)->not->toBeNull()
            ->and($landingPage->template)->toBe('external')
            ->and($landingPage->external_url)->toBe('https://geofon.gfz.de/waveform/archive/network.php?ncode=SKIP');
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
        $metaworksService->shouldReceive('lookupFileEntries')
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));

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

    it('disables metaworks lookups for remaining bulk items after the first failure', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(2);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/metaworks.first',
                    'attributes' => [
                        'doi' => '10.5880/metaworks.first',
                        'titles' => [['title' => 'First']],
                    ],
                ];
                yield [
                    'id' => '10.5880/metaworks.second',
                    'attributes' => [
                        'doi' => '10.5880/metaworks.second',
                        'titles' => [['title' => 'Second']],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->twice()
            ->andReturnUsing(fn (array $record) => Resource::factory()->create([
                'doi' => $record['attributes']['doi'],
            ]));

        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileEntries')
            ->once()
            ->with('10.5880/metaworks.first')
            ->andThrow(new RuntimeException('Legacy DB unavailable'));

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['imported'])->toBe(2)
            ->and($status['failed'])->toBe(0);
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

        // Existing internal landing pages are inspected for missing legacy files,
        // but the import must not create a duplicate page.
        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileEntries')
            ->once()
            ->with('10.5880/dup.lp.001')
            ->andReturn([
                'files' => [],
                'allPublic' => false,
                'resourceFound' => false,
                'hasFileRows' => false,
                'resourcePublicStatus' => null,
            ]);

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

    it('continues the import when landing page creation fails after metaworks files were found', function () {
        $this->importService
            ->shouldReceive('getTotalDoiCount')
            ->once()
            ->andReturn(1);

        $this->importService
            ->shouldReceive('fetchAllDois')
            ->once()
            ->andReturn((function () {
                yield [
                    'id' => '10.5880/lp.create.fail',
                    'attributes' => [
                        'doi' => '10.5880/lp.create.fail',
                        'titles' => [['title' => 'Landing Page Failure']],
                    ],
                ];
            })());

        $this->transformer
            ->shouldReceive('transform')
            ->once()
            ->andReturn(Resource::factory()->make(['doi' => '10.5880/lp.create.fail']));

        $metaworksService = Mockery::mock(MetaworksDownloadUrlService::class);
        $metaworksService->shouldReceive('lookupFileEntries')
            ->once()
            ->with('10.5880/lp.create.fail')
            ->andReturn([
                'files' => [
                    [
                        'url' => 'https://datapub.gfz.de/download/10.5880/lp.create.fail/file.zip',
                        'label' => 'File',
                        'visible' => 'public',
                    ],
                ],
                'allPublic' => true,
                'resourceFound' => true,
                'resourcePublicStatus' => 'published',
            ]);

        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);
        $job->handle($this->importService, $this->transformer, $metaworksService);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('completed')
            ->and($status['imported'])->toBe(1)
            ->and($status['failed'])->toBe(0)
            ->and(LandingPage::count())->toBe(0);
    });

    it('writes failed progress when the queue failure hook receives a null exception', function () {
        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);

        $job->failed(null);

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('failed')
            ->and($status['error'])->toBe('Unknown error');
    });

    it('writes failed progress when the queue failure hook receives an exception', function () {
        $importId = Str::uuid()->toString();
        $job = new ImportFromDataCiteJob($this->user->id, $importId);

        $job->failed(new RuntimeException('Queue crashed'));

        $status = Cache::get("datacite_import:{$importId}");
        expect($status['status'])->toBe('failed')
            ->and($status['error'])->toBe('Queue crashed');
    });
});
