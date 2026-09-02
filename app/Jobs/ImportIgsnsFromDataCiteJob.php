<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\IgsnParentRelationshipException;
use App\Exceptions\LegacyIgsnPortalException;
use App\Models\Datacenter;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\AutomaticIgsnLandingPageService;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\DataCiteToIgsnTransformer;
use App\Services\Igsn\IgsnSampleImageStorageService;
use App\Services\IgsnChildDiscoveryService;
use App\Services\IgsnEnrichmentService;
use App\Services\IgsnImportService;
use App\Services\ImportedResourceDataCiteSyncDispatcherService;
use App\Services\ImportProgressService;
use App\Services\LegacyIgsnPortalService;
use App\Support\IgsnIdentifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Background job for importing IGSNs from the DataCite API.
 *
 * Fetches all IGSNs (prefix 10.60510), creates Resource + IgsnMetadata records,
 * and enriches them with IGSN-specific metadata from Solr/legacy DB.
 * Progress is tracked via Redis cache for real-time frontend updates.
 */
class ImportIgsnsFromDataCiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum runtime: 4 hours (longer than DOI import due to enrichment).
     */
    public int $timeout = 14400;

    public int $tries = 1;

    /** @var list<int> */
    private array $resourceIdsForDataCiteSync = [];

    /** @var list<int> */
    private array $resourceIdsForImageSync = [];

    /**
     * @param  int  $userId  The user who initiated the import
     * @param  string  $importId  UUID for progress tracking
     */
    public function __construct(
        private int $userId,
        private string $importId,
        private ?string $singleDoi = null,
        private ?string $legacyDatacenterId = null,
    ) {
        $this->onQueue('imports');

        if ($this->singleDoi !== null && $this->legacyDatacenterId !== null) {
            throw new \InvalidArgumentException(
                'Single-IGSN and datacenter import modes are mutually exclusive.'
            );
        }

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $importId)) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $importId)) {
                $this->importId = strtolower($importId);
            } else {
                throw new \InvalidArgumentException(
                    "Invalid importId format. Expected UUID, got: {$importId}"
                );
            }
        }
    }

    public function handle(
        IgsnImportService $importService,
        DataCiteToIgsnTransformer $transformer,
        IgsnEnrichmentService $enrichmentService,
        ?IgsnChildDiscoveryService $childDiscoveryService = null,
        ?LegacyIgsnPortalService $legacyPortalService = null,
        ?IgsnSampleImageStorageService $imageStorageService = null,
    ): void {
        $legacyPortalService ??= app(LegacyIgsnPortalService::class);
        $imageStorageService ??= app(IgsnSampleImageStorageService::class);

        Log::info('Starting IGSN import job', [
            'import_id' => $this->importId,
            'user_id' => $this->userId,
            'single_doi' => $this->singleDoi,
            'legacy_datacenter_id' => $this->legacyDatacenterId,
            'enrichment_configuration' => [
                'solr' => filled(config('datacite.solr.host'))
                    && filled(config('datacite.solr.user'))
                    && filled(config('datacite.solr.password')),
                'legacy_db' => (bool) config('database.connections.igsn_legacy.configured', false),
            ],
        ]);

        $startTime = now();

        try {
            if ($this->legacyDatacenterId !== null) {
                $this->handleDatacenterImport(
                    importService: $importService,
                    transformer: $transformer,
                    enrichmentService: $enrichmentService,
                    legacyPortalService: $legacyPortalService,
                    imageStorageService: $imageStorageService,
                    startedAt: $startTime->toIso8601String(),
                );

                return;
            }

            if ($this->singleDoi !== null) {
                $this->handleSingleImport(
                    importService: $importService,
                    transformer: $transformer,
                    enrichmentService: $enrichmentService,
                    childDiscoveryService: $childDiscoveryService ?? app(IgsnChildDiscoveryService::class),
                    legacyPortalService: $legacyPortalService,
                    imageStorageService: $imageStorageService,
                    startedAt: $startTime->toIso8601String(),
                );

                return;
            }

            // Check if import was cancelled before the job even started (race condition)
            $existingStatus = Cache::get($this->getCacheKey());
            if (isset($existingStatus['status']) && $existingStatus['status'] === 'cancelled') {
                Log::info('IGSN import was cancelled before job started', [
                    'import_id' => $this->importId,
                ]);

                $this->updateProgress([
                    'status' => 'cancelled',
                    'total' => 0,
                    'processed' => 0,
                    'imported' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                    'enriched' => 0,
                    'skipped_dois' => [],
                    'failed_dois' => [],
                    'unassigned' => 0,
                    'unassigned_dois' => [],
                    'warnings' => [],
                    'started_at' => $startTime->toIso8601String(),
                    'completed_at' => now()->toIso8601String(),
                ]);

                return;
            }

            $assignments = $legacyPortalService->assignmentsForAllIgsns();
            $datacenterIds = $this->datacenterIdsForAssignments($assignments);
            $total = $importService->getTotalIgsnCount();

            $this->updateProgress([
                'status' => 'running',
                'total' => $total,
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
                'enriched' => 0,
                'skipped_dois' => [],
                'failed_dois' => [],
                'unassigned' => 0,
                'unassigned_dois' => [],
                'warnings' => [],
                'started_at' => $startTime->toIso8601String(),
                'completed_at' => null,
            ]);

            $processed = 0;
            $imported = 0;
            $skipped = 0;
            $failed = 0;
            $enriched = 0;
            /** @var array<int, string> */
            $skippedDois = [];
            /** @var array<int, array{doi: string, error: string}> */
            $failedDois = [];
            $unassigned = 0;
            /** @var list<string> $unassignedDois */
            $unassignedDois = [];
            $warnings = [];
            $maxStoredDois = 100;

            foreach ($importService->fetchAllIgsns() as $igsnRecord) {
                // Check for cancellation every 50 records (before incrementing processed)
                if ($processed === 0 || $processed % 50 === 0) {
                    $currentStatus = Cache::get($this->getCacheKey());
                    if (isset($currentStatus['status']) && $currentStatus['status'] === 'cancelled') {
                        Log::info('IGSN import cancelled by user', [
                            'import_id' => $this->importId,
                            'processed' => $processed,
                        ]);
                        break;
                    }
                }

                $processed++;

                $doi = $igsnRecord['attributes']['doi'] ?? $igsnRecord['id'] ?? null;

                if ($doi === null) {
                    $failed++;
                    if (count($failedDois) < $maxStoredDois) {
                        $failedDois[] = ['doi' => 'unknown', 'error' => 'No DOI found in record'];
                    }
                    $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $failedDois, $total);

                    continue;
                }

                ['doi' => $doi, 'igsnRecord' => $igsnRecord] = $this->normalizeIgsnRecord((string) $doi, $igsnRecord);

                try {
                    $result = $this->processIgsnRecord(
                        $doi,
                        $igsnRecord,
                        $transformer,
                        $enrichmentService,
                        $datacenterIds[$doi] ?? null,
                    );

                    if ($result['status'] === 'skipped') {
                        $skipped++;
                        if (count($skippedDois) < $maxStoredDois) {
                            $skippedDois[] = $doi;
                        }
                        $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $failedDois, $total);

                        continue;
                    }

                    $imported++;

                    if (! $result['assigned']) {
                        $unassigned++;
                        if (count($unassignedDois) < $maxStoredDois) {
                            $unassignedDois[] = $doi;
                        }
                        $warnings = [$this->unassignedWarning($unassigned)];
                    }

                    if ($result['enriched']) {
                        $enriched++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                    if (count($failedDois) < $maxStoredDois) {
                        $failedDois[] = ['doi' => $doi, 'error' => $e->getMessage()];
                    }

                    Log::warning('Failed to import IGSN', [
                        'doi' => $doi,
                        'error' => $e->getMessage(),
                    ]);
                }

                $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $failedDois, $total);
            }

            // Resolve parent-child relationships after all imports (skip if cancelled)
            $currentStatus = Cache::get($this->getCacheKey());
            $wasCancelled = isset($currentStatus['status']) && $currentStatus['status'] === 'cancelled';

            if (! $wasCancelled) {
                $this->resolveParentRelationships();
            }

            $finalStatus = $wasCancelled ? 'cancelled' : 'completed';

            $this->updateProgress([
                'status' => $finalStatus === 'cancelled' ? 'cancelled' : 'running',
                'phase' => $finalStatus === 'cancelled' ? 'completed' : 'syncing',
                'total' => $total,
                'processed' => $processed,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'enriched' => $enriched,
                'skipped_dois' => $skippedDois,
                'failed_dois' => $failedDois,
                'unassigned' => $unassigned,
                'unassigned_dois' => $unassignedDois,
                'warnings' => $warnings,
                'started_at' => $startTime->toIso8601String(),
                'completed_at' => $finalStatus === 'cancelled' ? now()->toIso8601String() : null,
            ]);

            if ($finalStatus !== 'cancelled') {
                $this->finishImageSyncPhase($imageStorageService);
                $this->updateProgressKeys(['phase' => 'syncing']);
                $this->finishDataCiteSyncPhase();
            }

            Log::info('IGSN import completed', [
                'import_id' => $this->importId,
                'total' => $total,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'enriched' => $enriched,
                'unassigned' => $unassigned,
                'duration_seconds' => now()->diffInSeconds($startTime),
            ]);

        } catch (\Exception $e) {
            Log::error('IGSN import job failed', [
                'import_id' => $this->importId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updateProgressKeys([
                'status' => 'failed',
                'phase' => 'completed',
                ...$this->failureMetadata($e),
                'error' => $e->getMessage(),
                'completed_at' => now()->toIso8601String(),
            ]);

            throw $e;
        }
    }

    private function handleDatacenterImport(
        IgsnImportService $importService,
        DataCiteToIgsnTransformer $transformer,
        IgsnEnrichmentService $enrichmentService,
        LegacyIgsnPortalService $legacyPortalService,
        IgsnSampleImageStorageService $imageStorageService,
        string $startedAt,
    ): void {
        if ($this->isCancelled()) {
            $this->updateProgressKeys([
                'status' => 'cancelled',
                'completed_at' => now()->toIso8601String(),
            ]);

            return;
        }

        $selection = $legacyPortalService->igsnsForDatacenter((string) $this->legacyDatacenterId);
        $targetDois = $selection['dois'];
        $total = count($targetDois);

        // The complete legacy target set is loaded before the first database write.
        $datacenter = Datacenter::query()->firstOrCreate([
            'name' => $selection['datacenter']['name'],
        ]);

        $this->updateProgress([
            'status' => 'running',
            'total' => $total,
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'enriched' => 0,
            'skipped_dois' => [],
            'failed_dois' => [],
            'datacenter' => $selection['datacenter'],
            'unassigned' => 0,
            'unassigned_dois' => [],
            'warnings' => [],
            'started_at' => $startedAt,
            'completed_at' => null,
        ]);

        $processed = 0;
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $enriched = 0;
        /** @var list<string> $skippedDois */
        $skippedDois = [];
        /** @var list<array{doi: string, error: string}> $failedDois */
        $failedDois = [];
        $maxStoredDois = 100;
        /** @var array<string, true> $remaining */
        $remaining = array_fill_keys($targetDois, true);

        $process = function (string $doi, ?array $record) use (
            &$processed,
            &$imported,
            &$skipped,
            &$failed,
            &$enriched,
            &$skippedDois,
            &$failedDois,
            $maxStoredDois,
            $total,
            $datacenter,
            $transformer,
            $enrichmentService,
        ): void {
            $processed++;

            if ($record === null) {
                $failed++;
                if (count($failedDois) < $maxStoredDois) {
                    $failedDois[] = [
                        'doi' => $doi,
                        'error' => 'The legacy IGSN was not found at DataCite.',
                    ];
                }
                $this->updateProgressCounts(
                    $processed,
                    $imported,
                    $skipped,
                    $failed,
                    $enriched,
                    $skippedDois,
                    $failedDois,
                    $total,
                );

                return;
            }

            ['doi' => $normalizedDoi, 'igsnRecord' => $record] = $this->normalizeIgsnRecord($doi, $record);

            try {
                $result = $this->processIgsnRecord(
                    $normalizedDoi,
                    $record,
                    $transformer,
                    $enrichmentService,
                    $datacenter->id,
                );

                if ($result['status'] === 'skipped') {
                    $skipped++;
                    if (count($skippedDois) < $maxStoredDois) {
                        $skippedDois[] = $normalizedDoi;
                    }
                } else {
                    $imported++;
                    if ($result['enriched']) {
                        $enriched++;
                    }
                }
            } catch (\Exception $exception) {
                $failed++;
                if (count($failedDois) < $maxStoredDois) {
                    $failedDois[] = ['doi' => $normalizedDoi, 'error' => $exception->getMessage()];
                }

                Log::warning('Failed to import IGSN for legacy datacenter', [
                    'doi' => $normalizedDoi,
                    'legacy_datacenter_id' => $this->legacyDatacenterId,
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->updateProgressCounts(
                $processed,
                $imported,
                $skipped,
                $failed,
                $enriched,
                $skippedDois,
                $failedDois,
                $total,
            );
        };

        foreach ($importService->fetchAllIgsns() as $record) {
            if ($this->isCancelled() || $remaining === []) {
                break;
            }

            $doi = $this->doiFromIgsnRecord($record);
            if ($doi === null || ! isset($remaining[$doi])) {
                continue;
            }

            unset($remaining[$doi]);
            $process($doi, $record);
        }

        foreach (array_keys($remaining) as $doi) {
            if ($this->isCancelled()) {
                break;
            }

            $process($doi, $importService->fetchSingleIgsn($doi));
        }

        if (! $this->isCancelled()) {
            $handles = array_values(array_filter(array_map(
                fn (string $doi): ?string => IgsnIdentifier::handleFromDoi($doi),
                $targetDois,
            )));
            $this->resolveParentRelationships($handles);
        }

        $finalStatus = $this->determineFinalStatus();

        $this->updateProgress([
            'status' => $finalStatus === 'cancelled' ? 'cancelled' : 'running',
            'phase' => $finalStatus === 'cancelled' ? 'completed' : 'syncing',
            'total' => $total,
            'processed' => $processed,
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'enriched' => $enriched,
            'skipped_dois' => $skippedDois,
            'failed_dois' => $failedDois,
            'datacenter' => $selection['datacenter'],
            'unassigned' => 0,
            'unassigned_dois' => [],
            'warnings' => [],
            'started_at' => $startedAt,
            'completed_at' => $finalStatus === 'cancelled' ? now()->toIso8601String() : null,
        ]);

        if ($finalStatus !== 'cancelled') {
            $this->finishImageSyncPhase($imageStorageService);
            $this->updateProgressKeys(['phase' => 'syncing']);
            $this->finishDataCiteSyncPhase();
        }
    }

    private function handleSingleImport(
        IgsnImportService $importService,
        DataCiteToIgsnTransformer $transformer,
        IgsnEnrichmentService $enrichmentService,
        IgsnChildDiscoveryService $childDiscoveryService,
        LegacyIgsnPortalService $legacyPortalService,
        IgsnSampleImageStorageService $imageStorageService,
        string $startedAt,
    ): void {
        $requestedDoi = IgsnIdentifier::normalizeDoi((string) $this->singleDoi);
        if ($requestedDoi === null) {
            throw new RuntimeException('Single IGSN import requested without a valid IGSN DOI.');
        }

        $requestedHandle = (string) IgsnIdentifier::handleFromDoi($requestedDoi);

        if ($this->isCancelled()) {
            $this->updateProgress([
                'status' => 'cancelled',
                'total' => 0,
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
                'enriched' => 0,
                'skipped_dois' => [],
                'failed_dois' => [],
                'requested_igsn' => $requestedHandle,
                'discovered_children' => [],
                'unassigned' => 0,
                'unassigned_dois' => [],
                'warnings' => [],
                'started_at' => $startedAt,
                'completed_at' => now()->toIso8601String(),
            ]);

            return;
        }

        $parentRecord = $importService->fetchSingleIgsn($requestedDoi);
        if ($parentRecord === null) {
            $this->updateProgress([
                'status' => 'failed',
                'total' => 1,
                'processed' => 1,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 1,
                'enriched' => 0,
                'skipped_dois' => [],
                'failed_dois' => [
                    ['doi' => $requestedDoi, 'error' => 'The requested IGSN was not found at DataCite.'],
                ],
                'error' => 'The requested IGSN was not found at DataCite.',
                'requested_igsn' => $requestedHandle,
                'discovered_children' => [],
                'unassigned' => 0,
                'unassigned_dois' => [],
                'warnings' => [],
                'started_at' => $startedAt,
                'completed_at' => now()->toIso8601String(),
            ]);

            return;
        }

        $targets = $this->buildSingleImportTargets(
            requestedDoi: $requestedDoi,
            requestedRecord: $parentRecord,
            importService: $importService,
            childDiscoveryService: $childDiscoveryService,
        );
        $childHandles = $targets['childHandles'];
        $targetDois = $targets['dois'];
        $targetRecords = $targets['records'];
        $total = count($targetDois);

        $this->updateProgress([
            'status' => 'running',
            'phase' => 'preflight',
            'total' => $total,
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'enriched' => 0,
            'skipped_dois' => [],
            'failed_dois' => [],
            'requested_igsn' => $requestedHandle,
            'discovered_children' => $childHandles,
            'unassigned' => 0,
            'unassigned_dois' => [],
            'warnings' => [],
            'started_at' => $startedAt,
            'completed_at' => null,
        ]);

        /** @var array<string, array<string, mixed>> $normalizedRecords */
        $normalizedRecords = [];
        foreach ($targetDois as $doi) {
            if ($this->isCancelled()) {
                $this->markSingleImportCancelled($total, $requestedHandle, $childHandles, $startedAt);

                return;
            }

            $record = $targetRecords[$doi] ?? $importService->fetchSingleIgsn($doi);
            if ($record === null) {
                $exception = new RuntimeException(
                    "IGSN {$doi} was discovered as a related import target but was not found at DataCite."
                );
                $this->markAtomicSingleImportFailed(
                    $exception,
                    $total,
                    $requestedHandle,
                    $childHandles,
                    $startedAt,
                    $doi,
                );

                throw $exception;
            }

            ['doi' => $normalizedDoi, 'igsnRecord' => $normalizedRecord] = $this->normalizeIgsnRecord($doi, $record);
            $normalizedRecords[$normalizedDoi] = $normalizedRecord;
        }

        $existingDois = Resource::query()
            ->whereIn('doi', array_keys($normalizedRecords))
            ->pluck('doi')
            ->filter(fn (mixed $doi): bool => is_string($doi))
            ->map(fn (string $doi): string => strtolower($doi))
            ->values()
            ->all();
        $newDois = array_values(array_diff(array_keys($normalizedRecords), $existingDois));
        $newHandles = array_values(array_filter(array_map(
            fn (string $doi): ?string => IgsnIdentifier::handleFromDoi($doi),
            $newDois,
        )));

        $syncIdCountBeforeTransaction = count($this->resourceIdsForDataCiteSync);
        $imageSyncIdCountBeforeTransaction = count($this->resourceIdsForImageSync);

        try {
            $assignments = $legacyPortalService->assignmentsForHandles($newHandles);
            $enrichmentService->prepareStrict($newHandles);
            $parentDoisByChild = $this->buildSingleParentDoiMap(
                $normalizedRecords,
                $newDois,
                $importService,
                $enrichmentService->preparedParentHandles(),
            );

            $this->updateProgressKeys(['phase' => 'importing']);

            $result = DB::transaction(function () use (
                $assignments,
                $enrichmentService,
                $existingDois,
                $newHandles,
                $normalizedRecords,
                $parentDoisByChild,
                $transformer,
            ): array {
                $datacenterIds = $this->datacenterIdsForAssignments($assignments);
                $processed = 0;
                $imported = 0;
                $skipped = 0;
                $enriched = 0;
                $unassigned = 0;
                $skippedDois = [];
                $unassignedDois = [];

                foreach ($normalizedRecords as $doi => $record) {
                    if ($this->isCancelled()) {
                        throw new RuntimeException('Single IGSN import was cancelled.');
                    }

                    $processed++;
                    if (in_array($doi, $existingDois, true)) {
                        $skipped++;
                        $skippedDois[] = $doi;

                        continue;
                    }

                    $recordResult = $this->processIgsnRecord(
                        $doi,
                        $record,
                        $transformer,
                        $enrichmentService,
                        $datacenterIds[$doi] ?? null,
                        strictEnrichment: true,
                    );

                    if ($recordResult['status'] === 'skipped') {
                        $skipped++;
                        $skippedDois[] = $doi;

                        continue;
                    }

                    $imported++;
                    if ($recordResult['enriched']) {
                        $enriched++;
                    }
                    if (! $recordResult['assigned']) {
                        $unassigned++;
                        $unassignedDois[] = $doi;
                    }
                }

                $this->resolveParentRelationships($newHandles, $parentDoisByChild);

                return compact(
                    'processed',
                    'imported',
                    'skipped',
                    'enriched',
                    'unassigned',
                    'skippedDois',
                    'unassignedDois',
                );
            });
        } catch (Throwable $exception) {
            $this->resourceIdsForDataCiteSync = array_slice(
                $this->resourceIdsForDataCiteSync,
                0,
                $syncIdCountBeforeTransaction,
            );
            $this->resourceIdsForImageSync = array_slice(
                $this->resourceIdsForImageSync,
                0,
                $imageSyncIdCountBeforeTransaction,
            );

            if ($this->isCancelled()) {
                $this->markSingleImportCancelled($total, $requestedHandle, $childHandles, $startedAt);

                return;
            }

            $this->markAtomicSingleImportFailed(
                $exception,
                $total,
                $requestedHandle,
                $childHandles,
                $startedAt,
            );

            throw $exception;
        } finally {
            $enrichmentService->clearStrictPreparation();
        }

        $warnings = $result['unassigned'] > 0
            ? [$this->unassignedWarning($result['unassigned'])]
            : [];

        $this->updateProgress([
            'status' => 'running',
            'phase' => 'syncing',
            'total' => $total,
            'processed' => $result['processed'],
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'failed' => 0,
            'enriched' => $result['enriched'],
            'skipped_dois' => $result['skippedDois'],
            'failed_dois' => [],
            'requested_igsn' => $requestedHandle,
            'discovered_children' => $childHandles,
            'unassigned' => $result['unassigned'],
            'unassigned_dois' => $result['unassignedDois'],
            'warnings' => $warnings,
            'started_at' => $startedAt,
            'completed_at' => null,
        ]);

        $this->finishImageSyncPhase($imageStorageService);
        $this->updateProgressKeys(['phase' => 'syncing']);
        $this->finishDataCiteSyncPhase();

        Log::info('Single IGSN import completed', [
            'import_id' => $this->importId,
            'requested_igsn' => $requestedHandle,
            'total' => $total,
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'failed' => 0,
            'enriched' => $result['enriched'],
            'unassigned' => $result['unassigned'],
        ]);
    }

    /**
     * Build the complete single-import target set from the requested IGSN.
     *
     * The set includes the requested IGSN, direct children when the request is a parent,
     * and, when the request is a child, the DataCite parent chain plus sibling groups.
     *
     * @param  array<string, mixed>  $requestedRecord
     * @return array{dois: list<string>, records: array<string, array<string, mixed>>, childHandles: list<string>}
     */
    private function buildSingleImportTargets(
        string $requestedDoi,
        array $requestedRecord,
        IgsnImportService $importService,
        IgsnChildDiscoveryService $childDiscoveryService,
    ): array {
        /** @var list<string> $targetDois */
        $targetDois = [$requestedDoi];
        /** @var array<string, array<string, mixed>> $targetRecords */
        $targetRecords = [$requestedDoi => $requestedRecord];
        /** @var list<string> $childHandles */
        $childHandles = [];

        /** @var list<string> $pendingParentDois */
        $pendingParentDois = $importService->extractParentDois($requestedRecord);
        /** @var array<string, true> $visitedParentDois */
        $visitedParentDois = [];
        $maxParentDepth = 10;

        foreach ($pendingParentDois as $parentDoi) {
            $this->addTargetDoi($targetDois, $parentDoi);
        }

        while ($pendingParentDois !== [] && count($visitedParentDois) < $maxParentDepth) {
            $parentDoi = array_shift($pendingParentDois);
            if (isset($visitedParentDois[$parentDoi])) {
                continue;
            }

            $visitedParentDois[$parentDoi] = true;
            $parentRecord = $targetRecords[$parentDoi] ?? $importService->fetchSingleIgsn($parentDoi);

            if ($parentRecord === null) {
                continue;
            }

            $targetRecords[$parentDoi] = $parentRecord;

            foreach ($importService->extractParentDois($parentRecord) as $ancestorDoi) {
                $this->addTargetDoi($targetDois, $ancestorDoi);

                if (! isset($visitedParentDois[$ancestorDoi])) {
                    $pendingParentDois[] = $ancestorDoi;
                }
            }
        }

        $parentDoisForChildren = array_values(array_unique([
            $requestedDoi,
            ...array_keys($visitedParentDois),
        ]));

        foreach ($parentDoisForChildren as $parentDoi) {
            $this->addChildrenForParentDoi(
                parentDoi: $parentDoi,
                targetDois: $targetDois,
                targetRecords: $targetRecords,
                childHandles: $childHandles,
                importService: $importService,
                childDiscoveryService: $childDiscoveryService,
            );
        }

        return [
            'dois' => $targetDois,
            'records' => $targetRecords,
            'childHandles' => $childHandles,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $recordsByDoi
     * @param  list<string>  $newDois
     * @param  array<string, string>  $legacyParentsByHandle
     * @return array<string, string> Child DOI to parent DOI.
     */
    private function buildSingleParentDoiMap(
        array $recordsByDoi,
        array $newDois,
        IgsnImportService $importService,
        array $legacyParentsByHandle,
    ): array {
        $parentsByChild = [];

        foreach ($newDois as $childDoi) {
            $record = $recordsByDoi[$childDoi];
            $dataCiteParents = array_values(array_unique(array_filter(array_map(
                fn (string $doi): ?string => IgsnIdentifier::normalizeDoi($doi),
                $importService->extractParentDois($record),
            ))));

            if (count($dataCiteParents) > 1) {
                throw new IgsnParentRelationshipException(
                    "IGSN {$childDoi} has multiple DataCite parent relationships."
                );
            }

            $childHandle = IgsnIdentifier::handleFromDoi($childDoi);
            $legacyParentHandle = $childHandle !== null
                ? ($legacyParentsByHandle[$childHandle] ?? null)
                : null;
            $legacyParentDoi = null;

            if ($legacyParentHandle !== null) {
                if (! IgsnIdentifier::isValidHandle($legacyParentHandle)) {
                    throw LegacyIgsnPortalException::invalidPayload(
                        "The legacy parent identifier for IGSN {$childDoi} is invalid."
                    );
                }

                $legacyParentDoi = IgsnIdentifier::doiFromHandle($legacyParentHandle);
            }

            $dataCiteParentDoi = $dataCiteParents[0] ?? null;
            if ($dataCiteParentDoi !== null
                && $legacyParentDoi !== null
                && $dataCiteParentDoi !== $legacyParentDoi) {
                throw new IgsnParentRelationshipException(
                    "DataCite and legacy metadata disagree about the parent of IGSN {$childDoi}."
                );
            }

            $parentDoi = $dataCiteParentDoi ?? $legacyParentDoi;
            if ($parentDoi === null) {
                continue;
            }

            if ($parentDoi === $childDoi) {
                throw new IgsnParentRelationshipException("IGSN {$childDoi} cannot be its own parent.");
            }

            $parentsByChild[$childDoi] = $parentDoi;
        }

        foreach (array_keys($parentsByChild) as $startDoi) {
            $visited = [];
            $currentDoi = $startDoi;

            while (isset($parentsByChild[$currentDoi])) {
                if (isset($visited[$currentDoi])) {
                    throw new IgsnParentRelationshipException(
                        "A cycle was detected in the IGSN family containing {$startDoi}."
                    );
                }

                $visited[$currentDoi] = true;
                $currentDoi = $parentsByChild[$currentDoi];
            }
        }

        return $parentsByChild;
    }

    /**
     * @param  list<string>  $targetDois
     * @param  array<string, array<string, mixed>>  $targetRecords
     * @param  list<string>  $childHandles
     */
    private function addChildrenForParentDoi(
        string $parentDoi,
        array &$targetDois,
        array &$targetRecords,
        array &$childHandles,
        IgsnImportService $importService,
        IgsnChildDiscoveryService $childDiscoveryService,
    ): void {
        foreach ($importService->fetchChildIgsnsForParent($parentDoi) as $childRecord) {
            $childDoi = $this->doiFromIgsnRecord($childRecord);
            if ($childDoi === null) {
                continue;
            }

            $targetRecords[$childDoi] = $childRecord;
            $this->addTargetDoi($targetDois, $childDoi);
            $this->addChildHandle($childHandles, $childDoi);
        }

        $parentHandle = IgsnIdentifier::handleFromDoi($parentDoi);
        if ($parentHandle === null) {
            return;
        }

        foreach ($childDiscoveryService->discoverDirectChildHandles($parentHandle) as $childHandle) {
            $this->addTargetDoi($targetDois, IgsnIdentifier::doiFromHandle($childHandle));
            $this->addChildHandle($childHandles, IgsnIdentifier::doiFromHandle($childHandle));
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function doiFromIgsnRecord(array $record): ?string
    {
        $doi = $record['attributes']['doi'] ?? $record['id'] ?? null;

        return is_string($doi) ? IgsnIdentifier::normalizeDoi($doi) : null;
    }

    /**
     * @param  list<string>  $targetDois
     */
    private function addTargetDoi(array &$targetDois, string $doi): void
    {
        if (! in_array($doi, $targetDois, true)) {
            $targetDois[] = $doi;
        }
    }

    /**
     * @param  list<string>  $childHandles
     */
    private function addChildHandle(array &$childHandles, string $childDoi): void
    {
        $childHandle = IgsnIdentifier::handleFromDoi($childDoi);
        if ($childHandle !== null && ! in_array($childHandle, $childHandles, true)) {
            $childHandles[] = $childHandle;
        }
    }

    /**
     * @param  array<string, mixed>  $igsnRecord
     * @return array{status: 'imported'|'skipped', enriched: bool, assigned: bool}
     */
    private function processIgsnRecord(
        string $doi,
        array $igsnRecord,
        DataCiteToIgsnTransformer $transformer,
        IgsnEnrichmentService $enrichmentService,
        ?int $datacenterId = null,
        bool $strictEnrichment = false,
    ): array {
        try {
            if (Resource::where('doi', $doi)->exists()) {
                return ['status' => 'skipped', 'enriched' => false, 'assigned' => false];
            }

            $result = DB::transaction(function () use ($transformer, $igsnRecord, $datacenterId) {
                $resource = $transformer->transform($igsnRecord, $this->userId);

                if ($datacenterId !== null) {
                    $resource->datacenter_id = $datacenterId;
                    $resource->save();
                }

                return ['status' => 'imported', 'resource' => $resource];
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateEntry($e)) {
                return ['status' => 'skipped', 'enriched' => false, 'assigned' => false];
            }

            throw $e;
        }

        /** @var Resource $importedResource */
        $importedResource = $result['resource'];
        $igsnMetadata = $importedResource->igsnMetadata;
        $wasEnriched = false;

        if ($igsnMetadata instanceof IgsnMetadata) {
            if ($strictEnrichment) {
                $wasEnriched = $enrichmentService->enrich($importedResource, $igsnMetadata);
            } else {
                try {
                    $wasEnriched = $enrichmentService->enrich($importedResource, $igsnMetadata);
                } catch (Throwable $e) {
                    Log::debug('IGSN enrichment failed (non-critical)', [
                        'doi' => $doi,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (is_string($igsnMetadata->sample_image_source_url) && $igsnMetadata->sample_image_source_url !== '') {
                $this->resourceIdsForImageSync[] = (int) $importedResource->id;
            }
        } elseif ($strictEnrichment) {
            throw new RuntimeException(
                "The transformer did not create IGSN metadata for {$doi}."
            );
        }

        $landingPageResult = app(AutomaticIgsnLandingPageService::class)
            ->createPublished($importedResource);

        if ($landingPageResult['created']) {
            $this->resourceIdsForDataCiteSync[] = (int) $importedResource->id;
        }

        return [
            'status' => 'imported',
            'enriched' => $wasEnriched,
            'assigned' => $datacenterId !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $igsnRecord
     * @return array{doi: string, igsnRecord: array<string, mixed>}
     */
    private function normalizeIgsnRecord(string $doi, array $igsnRecord): array
    {
        $normalizedDoi = IgsnIdentifier::normalizeDoi($doi) ?? strtolower($doi);
        $normalizedRecord = $igsnRecord;

        if (isset($normalizedRecord['attributes']) && is_array($normalizedRecord['attributes'])) {
            $normalizedRecord['attributes']['doi'] = $normalizedDoi;
        } else {
            $normalizedRecord['attributes'] = ['doi' => $normalizedDoi];
        }

        if (array_key_exists('id', $normalizedRecord)) {
            $normalizedRecord['id'] = $normalizedDoi;
        }

        return [
            'doi' => $normalizedDoi,
            'igsnRecord' => $normalizedRecord,
        ];
    }

    private function isDuplicateEntry(QueryException $e): bool
    {
        if (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1062) {
            return true;
        }

        return str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * Resolve parent-child IGSN relationships after all imports.
     *
     * During import, parent_igsn handles are stored in description_json.
     * This pass resolves them to actual parent_resource_id values.
     *
     * @param  list<string>|null  $onlyHandles
     * @param  array<string, string>  $parentDoisByChild
     */
    private function resolveParentRelationships(
        ?array $onlyHandles = null,
        array $parentDoisByChild = [],
    ): void {
        Log::info('Resolving IGSN parent-child relationships');

        $resolved = 0;
        /** @var list<int> $resolvedResourceIds */
        $resolvedResourceIds = [];

        foreach ($parentDoisByChild as $childDoi => $parentDoi) {
            $child = Resource::query()->where('doi', $childDoi)->first();
            $parent = Resource::query()->where('doi', $parentDoi)->first();

            if ($child === null || $parent === null) {
                throw new IgsnParentRelationshipException(
                    "The parent relationship {$childDoi} -> {$parentDoi} could not be resolved."
                );
            }

            $metadata = $child->igsnMetadata;
            if (! $metadata instanceof IgsnMetadata) {
                throw new RuntimeException("IGSN metadata is missing for {$childDoi}.");
            }

            $description = $metadata->description_json;
            $legacyParentHandle = is_array($description)
                ? ($description['parent_igsn_handle'] ?? null)
                : null;
            $expectedParentHandle = IgsnIdentifier::handleFromDoi($parentDoi);

            if (is_string($legacyParentHandle)
                && $expectedParentHandle !== null
                && strcasecmp($legacyParentHandle, $expectedParentHandle) !== 0) {
                throw new IgsnParentRelationshipException(
                    "DataCite and legacy metadata disagree about the parent of IGSN {$childDoi}."
                );
            }

            $metadata->parent_resource_id = $parent->id;
            if (is_array($description)) {
                unset($description['parent_igsn_handle']);
                $metadata->description_json = $description !== [] ? $description : null;
            }
            $metadata->save();
            $resolved++;
            $resolvedResourceIds[] = (int) $metadata->resource_id;
        }

        $query = IgsnMetadata::query()
            ->whereNull('parent_resource_id')
            ->whereNotNull('description_json');

        if ($onlyHandles !== null) {
            $fullDois = array_map(
                fn (string $handle): string => IgsnIdentifier::doiFromHandle($handle),
                array_values(array_unique($onlyHandles)),
            );

            $query->whereHas('resource', fn ($resourceQuery) => $resourceQuery->whereIn('doi', $fullDois));
        }

        $query
            ->chunkById(500, function ($records) use (&$resolved, &$resolvedResourceIds): void {
                // Collect all parent handles in this chunk
                /** @var array<string, list<IgsnMetadata>> */
                $handleMap = [];
                foreach ($records as $igsnMeta) {
                    $descJson = $igsnMeta->description_json;
                    if (! is_array($descJson) || ! isset($descJson['parent_igsn_handle'])) {
                        continue;
                    }
                    $handle = strtoupper($descJson['parent_igsn_handle']);
                    $handleMap[$handle][] = $igsnMeta;
                }

                if ($handleMap === []) {
                    return;
                }

                // Bulk-fetch all parent resources using reconstructed full DOIs (index-friendly)
                $handles = array_keys($handleMap);
                $fullDois = array_map(
                    fn (string $handle): string => IgsnIdentifier::doiFromHandle($handle),
                    $handles,
                );

                $parentResources = Resource::query()
                    ->whereNotNull('doi')
                    ->whereIn('doi', $fullDois)
                    ->get()
                    ->keyBy(fn (Resource $r): string => strtoupper((string) substr((string) $r->doi, (int) strrpos((string) $r->doi, '/') + 1)));

                // Assign parents from the bulk result
                foreach ($handleMap as $handle => $metaRecords) {
                    $parentResource = $parentResources->get($handle);
                    if ($parentResource === null) {
                        continue;
                    }

                    foreach ($metaRecords as $igsnMeta) {
                        $igsnMeta->parent_resource_id = $parentResource->id;

                        $descJson = $igsnMeta->description_json;
                        unset($descJson['parent_igsn_handle']);
                        $igsnMeta->description_json = $descJson !== [] ? $descJson : null;

                        $igsnMeta->save();
                        $resolved++;
                        $resolvedResourceIds[] = (int) $igsnMeta->resource_id;
                    }
                }
            });

        if ($resolvedResourceIds !== []) {
            app(LandingPageRenderDataCacheService::class)
                ->forgetForIgsnFamilies($resolvedResourceIds);
        }

        Log::info('Parent-child resolution completed', ['resolved' => $resolved]);
    }

    /**
     * @param  array<string, string>  $assignments
     * @return array<string, int>
     */
    private function datacenterIdsForAssignments(array $assignments): array
    {
        if ($assignments === []) {
            return [];
        }

        $idsByName = [];
        foreach (array_values(array_unique($assignments)) as $name) {
            $idsByName[$name] = Datacenter::query()->firstOrCreate(['name' => $name])->id;
        }

        $idsByDoi = [];
        foreach ($assignments as $doi => $name) {
            if (isset($idsByName[$name])) {
                $idsByDoi[$doi] = $idsByName[$name];
            }
        }

        return $idsByDoi;
    }

    private function unassignedWarning(int $count): string
    {
        return sprintf(
            '%d newly imported IGSN(s) could not be matched to a legacy datacenter.',
            $count,
        );
    }

    /**
     * @param  list<string>  $childHandles
     */
    private function markSingleImportCancelled(
        int $total,
        string $requestedHandle,
        array $childHandles,
        string $startedAt,
    ): void {
        $this->updateProgress([
            'status' => 'cancelled',
            'phase' => 'completed',
            'total' => $total,
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'enriched' => 0,
            'skipped_dois' => [],
            'failed_dois' => [],
            'requested_igsn' => $requestedHandle,
            'discovered_children' => $childHandles,
            'unassigned' => 0,
            'unassigned_dois' => [],
            'warnings' => [],
            'started_at' => $startedAt,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  list<string>  $childHandles
     */
    private function markAtomicSingleImportFailed(
        Throwable $exception,
        int $total,
        string $requestedHandle,
        array $childHandles,
        string $startedAt,
        ?string $failedDoi = null,
    ): void {
        $doi = $failedDoi ?? IgsnIdentifier::doiFromHandle($requestedHandle);

        $this->updateProgress([
            'status' => 'failed',
            'phase' => 'completed',
            ...$this->failureMetadata($exception, 'atomic_import_failed'),
            'error' => $exception->getMessage(),
            'total' => $total,
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 1,
            'enriched' => 0,
            'skipped_dois' => [],
            'failed_dois' => [[
                'doi' => $doi,
                'error' => $exception->getMessage(),
            ]],
            'requested_igsn' => $requestedHandle,
            'discovered_children' => $childHandles,
            'unassigned' => 0,
            'unassigned_dois' => [],
            'warnings' => [],
            'started_at' => $startedAt,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<int, string>  $skippedDois
     * @param  array<int, array{doi: string, error: string}>  $failedDois
     */
    private function updateProgressCounts(
        int $processed,
        int $imported,
        int $skipped,
        int $failed,
        int $enriched,
        array $skippedDois,
        array $failedDois,
        int $total
    ): void {
        if ($processed === 1 || $processed % 50 === 0 || $processed === $total) {
            $this->updateProgressKeys([
                'processed' => $processed,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'enriched' => $enriched,
                'skipped_dois' => $skippedDois,
                'failed_dois' => $failedDois,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateProgress(array $data): void
    {
        Cache::put($this->getCacheKey(), $data, now()->addHours(24));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateProgressKeys(array $data): void
    {
        $currentProgress = Cache::get($this->getCacheKey(), []);

        foreach ($data as $key => $value) {
            $currentProgress[$key] = $value;
        }

        Cache::put($this->getCacheKey(), $currentProgress, now()->addHours(24));
    }

    private function getCacheKey(): string
    {
        return "igsn_import:{$this->importId}";
    }

    private function isCancelled(): bool
    {
        $currentStatus = Cache::get($this->getCacheKey());

        return isset($currentStatus['status']) && $currentStatus['status'] === 'cancelled';
    }

    private function determineFinalStatus(): string
    {
        return $this->isCancelled() ? 'cancelled' : 'completed';
    }

    private function finishDataCiteSyncPhase(): void
    {
        if ($this->isCancelled()) {
            return;
        }

        app(ImportedResourceDataCiteSyncDispatcherService::class)->dispatch(
            ImportProgressService::TYPE_IGSN,
            $this->importId,
            $this->resourceIdsForDataCiteSync,
        );
    }

    private function finishImageSyncPhase(IgsnSampleImageStorageService $imageStorageService): void
    {
        if ($this->isCancelled()) {
            return;
        }

        $resourceIds = array_values(array_unique($this->resourceIdsForImageSync));
        $counts = [
            'images_total' => count($resourceIds),
            'images_processed' => 0,
            'images_stored' => 0,
            'images_external' => 0,
            'images_unavailable' => 0,
            'images_skipped' => 0,
            'images_failed' => 0,
        ];
        $warnings = [];

        $this->updateProgressKeys(['phase' => 'images', ...$counts, 'image_warnings' => []]);

        foreach ($resourceIds as $resourceId) {
            if ($this->isCancelled()) {
                return;
            }

            $metadata = IgsnMetadata::query()->with('resource')->where('resource_id', $resourceId)->first();
            if (! $metadata instanceof IgsnMetadata) {
                $counts['images_processed']++;
                $counts['images_skipped']++;
                $this->publishImageProgress($counts, $warnings);

                continue;
            }

            $result = $imageStorageService->sync($metadata);
            $counts['images_processed']++;

            match ($result['status']) {
                'stored' => $counts['images_stored']++,
                'external' => $counts['images_external']++,
                'unavailable' => $counts['images_unavailable']++,
                'failed' => $counts['images_failed']++,
                default => $counts['images_skipped']++,
            };

            if (in_array($result['status'], ['failed', 'unavailable'], true) && count($warnings) < 100) {
                $warnings[] = [
                    'doi' => (string) $metadata->resource->doi,
                    'error' => $result['message'],
                ];
            }

            $this->publishImageProgress($counts, $warnings);
        }
    }

    /**
     * @param  array{images_total: int, images_processed: int, images_stored: int, images_external: int, images_unavailable: int, images_skipped: int, images_failed: int}  $counts
     * @param  list<array{doi: string, error: string}>  $warnings
     */
    private function publishImageProgress(array $counts, array $warnings): void
    {
        if ($counts['images_processed'] === 1
            || $counts['images_processed'] % 25 === 0
            || $counts['images_processed'] === $counts['images_total']) {
            $this->updateProgressKeys([...$counts, 'image_warnings' => $warnings]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('IGSN import job failed completely', [
            'import_id' => $this->importId,
            'error' => $exception?->getMessage(),
        ]);

        $this->updateProgressKeys([
            'status' => 'failed',
            'phase' => 'completed',
            ...$this->failureMetadata($exception),
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    /** @return array{error_code: string, error_source: string|null} */
    private function failureMetadata(?Throwable $exception, string $fallbackCode = 'import_job_failed'): array
    {
        $currentProgress = Cache::get($this->getCacheKey(), []);
        $existingCode = is_array($currentProgress) ? ($currentProgress['error_code'] ?? null) : null;
        $existingSource = is_array($currentProgress) ? ($currentProgress['error_source'] ?? null) : null;

        if (is_string($existingCode) && $existingCode !== '') {
            return [
                'error_code' => $existingCode,
                'error_source' => is_string($existingSource) ? $existingSource : null,
            ];
        }

        if ($exception instanceof LegacyIgsnPortalException) {
            return ['error_code' => $exception->failureCode, 'error_source' => 'legacy_portal'];
        }

        if ($exception instanceof IgsnParentRelationshipException) {
            return [
                'error_code' => IgsnParentRelationshipException::FAILURE_CODE,
                'error_source' => 'datacite_legacy_metadata',
            ];
        }

        return ['error_code' => $fallbackCode, 'error_source' => null];
    }

    public function getImportId(): string
    {
        return $this->importId;
    }

    public function getSingleDoi(): ?string
    {
        return $this->singleDoi;
    }

    public function getLegacyDatacenterId(): ?string
    {
        return $this->legacyDatacenterId;
    }
}
