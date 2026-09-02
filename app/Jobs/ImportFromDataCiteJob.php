<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AccessLevel;
use App\Enums\CitationLabelResolutionMode;
use App\Exceptions\AmbiguousLegacyResourceException;
use App\Models\Datacenter;
use App\Models\Resource;
use App\Services\Crc806LegacyRightsService;
use App\Services\DataCiteImportService;
use App\Services\DataCiteLandingPageImportService;
use App\Services\DataCiteSubjectMergeService;
use App\Services\DataCiteToResourceTransformer;
use App\Services\DoiSuggestionService;
use App\Services\GeofonSeismicEventsRightsService;
use App\Services\GfzDataServicesPortalService;
use App\Services\ImportedResourceDataCiteSyncDispatcherService;
use App\Services\ImportProgressService;
use App\Services\LegacyLandingPageDecisionService;
use App\Services\LegacyLandingPageImportService;
use App\Services\LegacyMetaworksDatacenterLookupService;
use App\Services\LegacyResourceLookupService;
use App\Services\MetaworksDownloadUrlService;
use App\Services\SumarioPendingResourceImportService;
use App\Services\SumarioPmdContactEnrichmentService;
use App\Services\SumarioPmdCoverageEnrichmentService;
use App\Services\Xml\OriginalDataCiteSubjectExtractionService;
use App\Support\LegacyDescriptionBreakNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Background job for importing DOIs from DataCite.
 *
 * Fetches all DOIs from the DataCite API and creates corresponding
 * Resource records in the database. Progress is tracked via Redis
 * cache for real-time frontend updates.
 */
class ImportFromDataCiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The maximum number of seconds the job can run.
     *
     * Import rate is approximately 100-200 DOIs per minute depending on network.
     * For 10,000 DOIs, expect ~60-90 minutes processing time.
     */
    public int $timeout = 7200; // 2 hours

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /** @var array<string, int> */
    private array $portalDatacenterIds = [];

    /** @var list<int> */
    private array $resourceIdsForDataCiteSync = [];

    /** @var list<int> */
    private array $resourceIdsForFullDataCiteSync = [];

    private ?Crc806LegacyRightsService $crc806LegacyRightsService = null;

    private ?GeofonSeismicEventsRightsService $geofonSeismicEventsRightsService = null;

    /**
     * Create a new job instance.
     *
     * @param  int  $userId  The user who initiated the import
     * @param  string  $importId  Unique identifier for progress tracking (UUID format, lowercase)
     *
     * @throws \InvalidArgumentException If importId is not a valid UUID
     */
    public function __construct(
        private int $userId,
        private string $importId,
        private ?string $singleDoi = null,
        private ?string $datacenterId = null,
    ) {
        // Validate UUID format to prevent cache key collisions or unexpected behavior.
        // The importId is used as part of the cache key and must be unique.
        // We enforce lowercase UUIDs for consistency (RFC 4122 recommends lowercase).
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $importId)) {
            // Check if it's a valid UUID with uppercase letters
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $importId)) {
                // Normalize to lowercase for consistency
                $this->importId = strtolower($importId);
            } else {
                throw new \InvalidArgumentException(
                    "Invalid importId format. Expected UUID, got: {$importId}"
                );
            }
        }

        if ($this->singleDoi !== null && $this->datacenterId !== null) {
            throw new \InvalidArgumentException('Single DOI and datacenter imports cannot be combined.');
        }
    }

    /**
     * Execute the job.
     */
    public function handle(
        DataCiteImportService $importService,
        DataCiteToResourceTransformer $transformer,
        MetaworksDownloadUrlService $metaworksService
    ): void {
        Log::info('Starting DataCite import job', [
            'import_id' => $this->importId,
            'user_id' => $this->userId,
            'single_doi' => $this->singleDoi,
            'datacenter_id' => $this->datacenterId,
        ]);

        $startTime = now();

        try {
            if ($this->singleDoi !== null) {
                $this->handleSingleImport($importService, $transformer, $metaworksService, $startTime->toIso8601String());

                return;
            }

            if ($this->datacenterId !== null) {
                $this->handleDatacenterImport(
                    $importService,
                    $transformer,
                    $metaworksService,
                    $startTime->toIso8601String(),
                );

                return;
            }

            $pendingImportService = app(SumarioPendingResourceImportService::class);
            // This is an intentional preflight. A legacy outage must fail before
            // DataCite resources are written; continuing would silently create a
            // partial import (for example 8 instead of 8+3 for DOME).
            $pendingTotal = $pendingImportService->countImportablePending();

            // Get total count for progress calculation
            $total = $importService->getTotalDoiCount() + $pendingTotal;

            $this->updateProgress([
                'status' => 'running',
                'total' => $total,
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
                'enriched' => 0,
                'skipped_dois' => [],
                'enriched_dois' => [],
                'failed_dois' => [],
                'started_at' => $startTime->toIso8601String(),
                'completed_at' => null,
                'current_prefix' => null,
            ]);

            $processed = 0;
            $imported = 0;
            $skipped = 0;
            $failed = 0;
            $enriched = 0;
            /** @var array<int, string> */
            $skippedDois = [];
            /** @var array<int, string> */
            $enrichedDois = [];
            /** @var array<int, array{doi: string, error: string}> */
            $failedDois = [];

            // Maximum entries to store in cache (to prevent memory issues)
            $maxStoredDois = 100;

            // Circuit-breaker: if metaworks DB fails, skip all subsequent lookups
            // to avoid flooding logs and adding latency for every DOI.
            $metaworksUnavailable = false;

            // Process DOIs one by one using the generator
            // Each DOI is processed in its own transaction for resilience
            foreach ($importService->fetchAllDois() as $doiRecord) {
                $processed++;

                // Check for cancellation and update progress at aligned intervals.
                // Both checks use the same condition (% 50 === 0 OR first record) to ensure:
                // 1. Early cancellation detection and progress visibility at the very first record
                // 2. Symmetric behavior - progress is always updated when cancellation is checked
                // For 10,000 DOIs this results in ~200 cache operations instead of 10,000.
                if ($processed === 1 || $processed % 50 === 0) {
                    $currentStatus = Cache::get($this->getCacheKey());
                    if (isset($currentStatus['status']) && $currentStatus['status'] === 'cancelled') {
                        Log::info('Import cancelled by user', ['import_id' => $this->importId, 'processed' => $processed - 1]);
                        break;
                    }
                }

                $doi = $doiRecord['attributes']['doi'] ?? $doiRecord['id'] ?? null;

                if ($doi === null) {
                    $failed++;
                    if (count($failedDois) < $maxStoredDois) {
                        $failedDois[] = [
                            'doi' => 'unknown',
                            'error' => 'No DOI found in record',
                        ];
                    }
                    $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $enrichedDois, $failedDois, $total);

                    continue;
                }

                ['doi' => $doi, 'doiRecord' => $doiRecord] = $this->normalizeDoiRecord($doi, $doiRecord);

                try {
                    $result = $this->processDoiRecord(
                        doi: $doi,
                        doiRecord: $doiRecord,
                        transformer: $transformer,
                        metaworksService: $metaworksService,
                        shouldLookupMetaworks: ! $metaworksUnavailable,
                        citationLabelResolutionMode: CitationLabelResolutionMode::BEST_EFFORT,
                    );

                    if ($result['enriched']) {
                        $enriched++;
                        if (count($enrichedDois) < $maxStoredDois) {
                            $enrichedDois[] = $doi;
                        }
                    }

                    if ($result['metaworks_unavailable']) {
                        $metaworksUnavailable = true;
                    }

                    if ($result['status'] === 'skipped') {
                        $skipped++;
                        if (count($skippedDois) < $maxStoredDois) {
                            $skippedDois[] = $doi;
                        }
                        $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $enrichedDois, $failedDois, $total);

                        continue;
                    }

                    $imported++;
                } catch (\Exception $e) {
                    $failed++;
                    if (count($failedDois) < $maxStoredDois) {
                        $failedDois[] = [
                            'doi' => $doi,
                            'error' => $e->getMessage(),
                        ];
                    }

                    Log::warning('Failed to import DOI', [
                        'doi' => $doi,
                        'error' => $e->getMessage(),
                    ]);
                }

                $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $enrichedDois, $failedDois, $total);
            }

            if ($this->determineFinalStatus() !== 'cancelled') {
                try {
                    $pendingSummary = $pendingImportService->importAllPending($this->userId, $maxStoredDois);

                    $processed += $pendingSummary['processed'];
                    $imported += $pendingSummary['imported'];
                    $skipped += $pendingSummary['skipped'];
                    $failed += $pendingSummary['failed'];
                    $skippedDois = array_slice(
                        array_merge($skippedDois, $pendingSummary['skipped_dois']),
                        0,
                        $maxStoredDois,
                    );
                    $failedDois = array_slice(
                        array_merge($failedDois, $pendingSummary['failed_dois']),
                        0,
                        $maxStoredDois,
                    );
                } catch (\Throwable $exception) {
                    Log::error('SUMARIO pending import failed', [
                        'import_id' => $this->importId,
                        'pending_total' => $pendingTotal,
                        'error' => $exception->getMessage(),
                    ]);

                    throw new \RuntimeException(
                        'SUMARIO pending resources could not be imported: '.$exception->getMessage(),
                        previous: $exception,
                    );
                }

                $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $enrichedDois, $failedDois, $total);
            }

            // Determine final status - preserve 'cancelled' if user cancelled during processing
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
                'enriched_dois' => $enrichedDois,
                'failed_dois' => $failedDois,
                'started_at' => $startTime->toIso8601String(),
                'completed_at' => $finalStatus === 'cancelled' ? now()->toIso8601String() : null,
                'current_prefix' => null,
            ]);

            if ($finalStatus !== 'cancelled') {
                $this->finishDataCiteSyncPhase();
            }

            Log::info('DataCite import completed', [
                'import_id' => $this->importId,
                'total' => $total,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'enriched' => $enriched,
                'duration_seconds' => now()->diffInSeconds($startTime),
            ]);

        } catch (\Exception $e) {
            Log::error('DataCite import job failed', [
                'import_id' => $this->importId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updateProgress([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now()->toIso8601String(),
            ]);

            throw $e;
        }
    }

    private function handleDatacenterImport(
        DataCiteImportService $importService,
        DataCiteToResourceTransformer $transformer,
        MetaworksDownloadUrlService $metaworksService,
        string $startedAt,
    ): void {
        $datacenterId = $this->datacenterId;

        if ($datacenterId === null) {
            throw new \RuntimeException('Datacenter import requested without a datacenter.');
        }

        $portalSelection = app(GfzDataServicesPortalService::class)
            ->resourcesForDatacenter($datacenterId);
        $datacenter = $portalSelection['datacenter'];
        /** @var array<string, list<string>> $targets */
        $targets = $portalSelection['resources'];
        $pendingImportService = app(SumarioPendingResourceImportService::class);
        try {
            $pendingDois = $pendingImportService
                ->importablePendingDoisForDatacenter($datacenter['name']);
        } catch (\Throwable $exception) {
            Log::error('SUMARIO pending datacenter preflight failed', [
                'import_id' => $this->importId,
                'datacenter_id' => $datacenter['id'],
                'error' => $exception->getMessage(),
            ]);

            throw new \RuntimeException(
                'Matching SUMARIO pending resources could not be loaded: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $warnings = [];

        foreach ($pendingDois as $pendingDoi) {
            $normalizedDoi = $this->normalizeDoi($pendingDoi);

            if ($normalizedDoi !== '' && ! array_key_exists($normalizedDoi, $targets)) {
                $targets[$normalizedDoi] = [];
            }
        }

        ksort($targets);

        $this->cacheExistingPortalDatacenterIds($targets);

        $total = count($targets);
        $processed = 0;
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $enriched = 0;
        /** @var list<string> $skippedDois */
        $skippedDois = [];
        /** @var list<string> $enrichedDois */
        $enrichedDois = [];
        /** @var list<array{doi: string, error: string}> $failedDois */
        $failedDois = [];
        $maxStoredDois = 100;
        $metaworksUnavailable = false;
        $remainingTargets = $targets;

        $this->updateProgress([
            'status' => 'running',
            'total' => $total,
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'enriched' => 0,
            'skipped_dois' => [],
            'enriched_dois' => [],
            'failed_dois' => [],
            'warnings' => $warnings,
            'datacenter' => $datacenter,
            'started_at' => $startedAt,
            'completed_at' => null,
            'current_prefix' => null,
        ]);

        /**
         * @param array{
         *     status: 'imported'|'skipped'|'failed',
         *     enriched: bool,
         *     metaworks_unavailable: bool,
         *     error: string|null
         * } $outcome
         */
        $recordOutcome = function (string $doi, array $outcome) use (
            &$imported,
            &$skipped,
            &$failed,
            &$enriched,
            &$skippedDois,
            &$enrichedDois,
            &$failedDois,
            $maxStoredDois,
        ): void {
            if ($outcome['enriched']) {
                $enriched++;

                if (count($enrichedDois) < $maxStoredDois) {
                    $enrichedDois[] = $doi;
                }
            }

            if ($outcome['status'] === 'imported') {
                $imported++;

                return;
            }

            if ($outcome['status'] === 'skipped') {
                $skipped++;

                if (count($skippedDois) < $maxStoredDois) {
                    $skippedDois[] = $doi;
                }

                return;
            }

            $failed++;

            if (count($failedDois) < $maxStoredDois) {
                $failedDois[] = [
                    'doi' => $doi,
                    'error' => $outcome['error'] ?? 'Import failed.',
                ];
            }
        };

        $scannedDataCiteRecords = 0;

        $dataCiteRecords = $remainingTargets === []
            ? []
            : $importService->fetchAllDois();

        foreach ($dataCiteRecords as $doiRecord) {
            $scannedDataCiteRecords++;

            if (($scannedDataCiteRecords === 1 || $scannedDataCiteRecords % 50 === 0) && $this->isCancelled()) {
                break;
            }

            $rawDoi = $doiRecord['attributes']['doi'] ?? $doiRecord['id'] ?? null;

            if (! is_string($rawDoi)) {
                continue;
            }

            ['doi' => $doi, 'doiRecord' => $normalizedRecord] = $this->normalizeDoiRecord(
                $rawDoi,
                $doiRecord,
            );

            if (! array_key_exists($doi, $remainingTargets)) {
                continue;
            }

            $processed++;
            $portalDatacenterNames = $remainingTargets[$doi];
            unset($remainingTargets[$doi]);

            $outcome = $this->processDatacenterDataCiteRecord(
                doi: $doi,
                doiRecord: $normalizedRecord,
                portalDatacenterNames: $portalDatacenterNames,
                transformer: $transformer,
                metaworksService: $metaworksService,
                shouldLookupMetaworks: ! $metaworksUnavailable,
            );
            $metaworksUnavailable = $metaworksUnavailable || $outcome['metaworks_unavailable'];
            $recordOutcome($doi, $outcome);

            $this->updateProgressCounts(
                $processed,
                $imported,
                $skipped,
                $failed,
                $enriched,
                $skippedDois,
                $enrichedDois,
                $failedDois,
                $total,
            );

            if ($remainingTargets === []) {
                break;
            }
        }

        foreach ($remainingTargets as $doi => $portalDatacenterNames) {
            if ($this->isCancelled()) {
                break;
            }

            $processed++;
            $doiRecord = $importService->fetchSingleDoi($doi);

            if ($doiRecord !== null) {
                ['doi' => $normalizedDoi, 'doiRecord' => $normalizedRecord] = $this->normalizeDoiRecord(
                    $doi,
                    $doiRecord,
                );
                $outcome = $this->processDatacenterDataCiteRecord(
                    doi: $normalizedDoi,
                    doiRecord: $normalizedRecord,
                    portalDatacenterNames: $portalDatacenterNames,
                    transformer: $transformer,
                    metaworksService: $metaworksService,
                    shouldLookupMetaworks: ! $metaworksUnavailable,
                );
                $metaworksUnavailable = $metaworksUnavailable || $outcome['metaworks_unavailable'];
                $recordOutcome($normalizedDoi, $outcome);
            } else {
                try {
                    $fallbackResult = $pendingImportService->importReviewFallbackByDoi(
                        $doi,
                        $this->userId,
                        CitationLabelResolutionMode::BEST_EFFORT,
                    );

                    if ($fallbackResult['status'] === 'imported') {
                        if ($portalDatacenterNames !== [] && $fallbackResult['resource'] !== null) {
                            $this->syncPortalDatacenters(
                                $fallbackResult['resource'],
                                $portalDatacenterNames,
                            );
                        }

                        $recordOutcome($doi, [
                            'status' => 'imported',
                            'enriched' => false,
                            'metaworks_unavailable' => false,
                            'error' => null,
                        ]);
                    } elseif ($fallbackResult['status'] === 'skipped') {
                        $recordOutcome($doi, [
                            'status' => 'skipped',
                            'enriched' => false,
                            'metaworks_unavailable' => false,
                            'error' => null,
                        ]);
                    } else {
                        $recordOutcome($doi, [
                            'status' => 'failed',
                            'enriched' => false,
                            'metaworks_unavailable' => false,
                            'error' => $fallbackResult['error']
                                ?? 'The DOI was not found in DataCite or eligible SUMARIO legacy resources.',
                        ]);
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Datacenter import fallback failed', [
                        'doi' => $doi,
                        'datacenter_id' => $datacenter['id'],
                        'error' => $exception->getMessage(),
                    ]);

                    $recordOutcome($doi, [
                        'status' => 'failed',
                        'enriched' => false,
                        'metaworks_unavailable' => false,
                        'error' => 'SUMARIO legacy lookup is unavailable.',
                    ]);
                }
            }

            $this->updateProgressCounts(
                $processed,
                $imported,
                $skipped,
                $failed,
                $enriched,
                $skippedDois,
                $enrichedDois,
                $failedDois,
                $total,
            );
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
            'enriched_dois' => $enrichedDois,
            'failed_dois' => $failedDois,
            'warnings' => $warnings,
            'datacenter' => $datacenter,
            'started_at' => $startedAt,
            'completed_at' => $finalStatus === 'cancelled' ? now()->toIso8601String() : null,
            'current_prefix' => null,
        ]);

        if ($finalStatus !== 'cancelled') {
            $this->finishDataCiteSyncPhase();
        }

        Log::info('Datacenter DataCite import completed', [
            'import_id' => $this->importId,
            'datacenter_id' => $datacenter['id'],
            'datacenter_name' => $datacenter['name'],
            'total' => $total,
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'enriched' => $enriched,
        ]);
    }

    /**
     * @param  array<string, mixed>  $doiRecord
     * @param  list<string>  $portalDatacenterNames
     * @return array{
     *     status: 'imported'|'skipped'|'failed',
     *     enriched: bool,
     *     metaworks_unavailable: bool,
     *     error: string|null
     * }
     */
    private function processDatacenterDataCiteRecord(
        string $doi,
        array $doiRecord,
        array $portalDatacenterNames,
        DataCiteToResourceTransformer $transformer,
        MetaworksDownloadUrlService $metaworksService,
        bool $shouldLookupMetaworks,
    ): array {
        try {
            $result = $this->processDoiRecord(
                doi: $doi,
                doiRecord: $doiRecord,
                transformer: $transformer,
                metaworksService: $metaworksService,
                shouldLookupMetaworks: $shouldLookupMetaworks,
                portalDatacenterNames: $portalDatacenterNames !== []
                    ? $portalDatacenterNames
                    : null,
                citationLabelResolutionMode: CitationLabelResolutionMode::BEST_EFFORT,
            );

            return [
                'status' => $result['status'],
                'enriched' => $result['enriched'],
                'metaworks_unavailable' => $result['metaworks_unavailable'],
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Failed to import datacenter DOI', [
                'doi' => $doi,
                'datacenter_id' => $this->datacenterId,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'enriched' => false,
                'metaworks_unavailable' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function handleSingleImport(
        DataCiteImportService $importService,
        DataCiteToResourceTransformer $transformer,
        MetaworksDownloadUrlService $metaworksService,
        string $startedAt
    ): void {
        $doi = $this->singleDoi;

        if ($doi === null) {
            throw new \RuntimeException('Single DOI import requested without a DOI.');
        }

        $doi = $this->normalizeDoi($doi);

        $this->updateProgress([
            'status' => 'running',
            'total' => 1,
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'enriched' => 0,
            'skipped_dois' => [],
            'enriched_dois' => [],
            'failed_dois' => [],
            'started_at' => $startedAt,
            'completed_at' => null,
            'current_prefix' => null,
        ]);

        $doiRecord = $importService->fetchSingleDoi($doi);

        if ($doiRecord === null) {
            $this->handleSingleLegacyFallback($doi, $startedAt);

            return;
        }

        ['doi' => $doi, 'doiRecord' => $doiRecord] = $this->normalizeDoiRecord($doi, $doiRecord);
        $portalDatacenterNames = $this->portalDatacenterNamesForSingleImport($doi);

        try {
            $result = $this->processDoiRecord(
                doi: $doi,
                doiRecord: $doiRecord,
                transformer: $transformer,
                metaworksService: $metaworksService,
                portalDatacenterNames: $portalDatacenterNames,
                citationLabelResolutionMode: CitationLabelResolutionMode::EXHAUSTIVE,
            );
        } catch (\Exception $exception) {
            Log::warning('Failed to import single DOI from DataCite', [
                'doi' => $doi,
                'error' => $exception->getMessage(),
            ]);

            $this->markSingleImportAsFailed($doi, $exception->getMessage(), $startedAt);

            return;
        }

        $wasSkipped = $result['status'] === 'skipped';
        $wasEnriched = $result['enriched'];

        $finalStatus = $this->determineFinalStatus();

        $this->updateProgress([
            'status' => $finalStatus === 'cancelled' ? 'cancelled' : 'running',
            'phase' => $finalStatus === 'cancelled' ? 'completed' : 'syncing',
            'total' => 1,
            'processed' => 1,
            'imported' => $wasSkipped ? 0 : 1,
            'skipped' => $wasSkipped ? 1 : 0,
            'failed' => 0,
            'enriched' => $wasEnriched ? 1 : 0,
            'skipped_dois' => $wasSkipped ? [$doi] : [],
            'enriched_dois' => $wasEnriched ? [$doi] : [],
            'failed_dois' => [],
            'started_at' => $startedAt,
            'completed_at' => $finalStatus === 'cancelled' ? now()->toIso8601String() : null,
            'current_prefix' => null,
        ]);

        if ($this->determineFinalStatus() !== 'cancelled') {
            $this->finishDataCiteSyncPhase();
        }
    }

    /**
     * @return list<string>|null
     */
    private function portalDatacenterNamesForSingleImport(string $doi): ?array
    {
        try {
            $names = app(GfzDataServicesPortalService::class)->datacenterNamesForDoi($doi);

            return $names !== [] ? $names : null;
        } catch (\Throwable $exception) {
            Log::warning('GFZ Data Services portal lookup failed during single DOI import; using legacy datacenter fallback.', [
                'import_id' => $this->importId,
                'doi' => $doi,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function markSingleImportAsFailed(string $doi, string $error, string $startedAt): void
    {
        $this->updateProgress([
            'status' => 'failed',
            'total' => 1,
            'processed' => 1,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 1,
            'enriched' => 0,
            'skipped_dois' => [],
            'enriched_dois' => [],
            'failed_dois' => [
                [
                    'doi' => $doi,
                    'error' => $error,
                ],
            ],
            'error' => $error,
            'started_at' => $startedAt,
            'completed_at' => now()->toIso8601String(),
            'current_prefix' => null,
        ]);
    }

    private function handleSingleLegacyFallback(string $doi, string $startedAt): void
    {
        try {
            $result = app(SumarioPendingResourceImportService::class)
                ->importReviewFallbackByDoi(
                    $doi,
                    $this->userId,
                    CitationLabelResolutionMode::EXHAUSTIVE,
                );
        } catch (\Throwable $exception) {
            Log::warning('SUMARIO legacy lookup failed during single DOI fallback', [
                'doi' => $doi,
                'error' => $exception->getMessage(),
            ]);

            $this->markSingleImportAsFailed($doi, 'SUMARIO legacy lookup is unavailable.', $startedAt);

            return;
        }

        $finalStatus = $this->determineFinalStatus();

        if ($result['status'] === 'imported') {
            $this->updateProgress([
                'status' => $finalStatus === 'cancelled' ? 'cancelled' : 'running',
                'phase' => $finalStatus === 'cancelled' ? 'completed' : 'syncing',
                'total' => 1,
                'processed' => 1,
                'imported' => 1,
                'skipped' => 0,
                'failed' => 0,
                'enriched' => 0,
                'skipped_dois' => [],
                'enriched_dois' => [],
                'failed_dois' => [],
                'started_at' => $startedAt,
                'completed_at' => $finalStatus === 'cancelled' ? now()->toIso8601String() : null,
                'current_prefix' => null,
            ]);

            if ($finalStatus !== 'cancelled') {
                $this->finishDataCiteSyncPhase();
            }

            return;
        }

        if ($result['status'] === 'skipped') {
            $this->updateProgress([
                'status' => $finalStatus === 'cancelled' ? 'cancelled' : 'running',
                'phase' => $finalStatus === 'cancelled' ? 'completed' : 'syncing',
                'total' => 1,
                'processed' => 1,
                'imported' => 0,
                'skipped' => 1,
                'failed' => 0,
                'enriched' => 0,
                'skipped_dois' => [$result['doi']],
                'enriched_dois' => [],
                'failed_dois' => [],
                'started_at' => $startedAt,
                'completed_at' => $finalStatus === 'cancelled' ? now()->toIso8601String() : null,
                'current_prefix' => null,
            ]);

            if ($finalStatus !== 'cancelled') {
                $this->finishDataCiteSyncPhase();
            }

            return;
        }

        $error = $result['error']
            ?? 'The DOI was not found in DataCite or eligible SUMARIO legacy resources.';

        $this->markSingleImportAsFailed($result['doi'], $error, $startedAt);
    }

    /**
     * @param  array<string, mixed>  $doiRecord
     * @param  list<string>|null  $portalDatacenterNames
     * @return array{status: 'imported'|'skipped', metaworks_unavailable: bool, enriched: bool}
     */
    private function processDoiRecord(
        string $doi,
        array $doiRecord,
        DataCiteToResourceTransformer $transformer,
        MetaworksDownloadUrlService $metaworksService,
        bool $shouldLookupMetaworks = true,
        ?array $portalDatacenterNames = null,
        CitationLabelResolutionMode $citationLabelResolutionMode = CitationLabelResolutionMode::BEST_EFFORT,
    ): array {
        $metaworksUnavailable = false;

        if ($this->shouldSkipLegacyDoi($doi)) {
            Log::info('Skipping legacy DOI marked as test/delete', ['doi' => $doi]);

            return [
                'status' => 'skipped',
                'metaworks_unavailable' => false,
                'enriched' => false,
            ];
        }

        try {
            $existingResource = Resource::where('doi', $doi)->first();

            if ($existingResource !== null) {
                return $this->repairExistingResource(
                    resource: $existingResource,
                    doi: $doi,
                    doiRecord: $doiRecord,
                    metaworksService: $metaworksService,
                    shouldLookupMetaworks: $shouldLookupMetaworks,
                    datacenterNames: $portalDatacenterNames ?? [],
                );
            }

            $doiRecord = app(OriginalDataCiteSubjectExtractionService::class)
                ->preferOriginalSubjects($doiRecord, $doi);

            $legacyMetadata = [
                'relatedIdentifiers' => [],
                'subjects' => [],
                'legacyResourceId' => null,
                'legacyResourceStatus' => null,
            ];

            if ($shouldLookupMetaworks) {
                try {
                    $legacyMetadata = array_replace(
                        $legacyMetadata,
                        app(LegacyResourceLookupService::class)->importMetadataByDoi($doi),
                    );
                } catch (AmbiguousLegacyResourceException $exception) {
                    Log::warning('Ambiguous SUMARIO DOI while loading legacy metadata; continuing without legacy enrichment for this record.', [
                        'import_id' => $this->importId,
                        'doi' => $doi,
                        'error' => $exception->getMessage(),
                    ]);
                } catch (\Throwable $exception) {
                    $metaworksUnavailable = true;

                    Log::warning('Metaworks DB unavailable while loading legacy metadata; continuing without legacy enrichment.', [
                        'import_id' => $this->importId,
                        'doi' => $doi,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $legacyResourceId = is_int($legacyMetadata['legacyResourceId'] ?? null)
                ? $legacyMetadata['legacyResourceId']
                : null;
            $legacyResourceStatus = is_string($legacyMetadata['legacyResourceStatus'] ?? null)
                ? $legacyMetadata['legacyResourceStatus']
                : null;

            $legacyBreakReplacements = 0;
            if ($legacyResourceId !== null) {
                $normalizedLegacyDescriptions = $this->normalizeLegacyDescriptionBreaks($doiRecord);
                $doiRecord = $normalizedLegacyDescriptions['record'];
                $legacyBreakReplacements = $normalizedLegacyDescriptions['replacements'];
            }

            $doiRecord = app(DataCiteSubjectMergeService::class)->mergeIntoDoiRecord(
                $doiRecord,
                $legacyMetadata['subjects'],
            );

            $legacyLandingPageUrl = $this->doiRecordLandingPageUrl($doiRecord);

            $preparedDoiRecord = $transformer->prepareDoiData(
                $doiRecord,
                $legacyMetadata['relatedIdentifiers'],
                $citationLabelResolutionMode,
            );
            $preparedDoiRecord = $this->withGeofonSeismicEventsRights(
                $preparedDoiRecord,
                $doi,
                $portalDatacenterNames ?? [],
            );
            $preparedDoiRecord = $this->withCrc806LegacyRightsFallback(
                $preparedDoiRecord,
                $doi,
                $legacyLandingPageUrl,
            );

            // Use database transaction to ensure atomicity of the check-then-insert operation.
            //
            // Design decision: We use SELECT + INSERT rather than INSERT IGNORE because:
            // 1. We need to know which DOIs were skipped for user feedback (skipped_dois list)
            // 2. INSERT IGNORE would silently succeed, making it impossible to track skips
            // 3. The unique constraint on DOI provides protection against race conditions
            // 4. Most imports won't have many duplicates, so the SELECT overhead is minimal
            $result = DB::transaction(function () use ($transformer, $preparedDoiRecord, $doi, $legacyResourceId, $legacyResourceStatus) {
                if (Resource::where('doi', $doi)->exists()) {
                    return ['status' => 'skipped', 'resource' => null];
                }

                $resource = $transformer->transform($preparedDoiRecord, $this->userId);

                if ($legacyResourceId !== null) {
                    $resource->forceFill([
                        'legacy_source' => 'sumario-pmd',
                        'legacy_source_id' => $legacyResourceId,
                        'legacy_source_status' => $legacyResourceStatus,
                        'legacy_description_breaks_normalized_at' => now(),
                    ])->save();
                }

                return ['status' => 'imported', 'resource' => $resource];
            });

            if ($result['status'] === 'skipped') {
                Log::debug('Skipping existing DOI', ['doi' => $doi]);

                $existingResource = Resource::where('doi', $doi)->first();

                if ($existingResource === null) {
                    return [
                        'status' => 'skipped',
                        'metaworks_unavailable' => $metaworksUnavailable,
                        'enriched' => false,
                    ];
                }

                $repairResult = $this->repairExistingResource(
                    resource: $existingResource,
                    doi: $doi,
                    doiRecord: $preparedDoiRecord,
                    metaworksService: $metaworksService,
                    shouldLookupMetaworks: $shouldLookupMetaworks && ! $metaworksUnavailable,
                    datacenterNames: $portalDatacenterNames ?? [],
                );
                $repairResult['metaworks_unavailable'] = $repairResult['metaworks_unavailable'] || $metaworksUnavailable;

                return $repairResult;
            }

            /** @var Resource $importedResource */
            $importedResource = $result['resource'];

            $this->enrichImportedResourceFromLegacyDatabases(
                $importedResource,
                $doi,
                $portalDatacenterNames,
            );

            $dataCiteLandingPageSync = $this->syncDataCiteLandingPageIfAllowed(
                $importedResource,
                $doi,
                $preparedDoiRecord,
                $portalDatacenterNames ?? [],
            );

            $legacyDownloadSync = $this->emptyLegacyDownloadSyncResult();

            $importedResource->loadMissing('landingPage');

            if ($shouldLookupMetaworks && ! $metaworksUnavailable && ! $importedResource->landingPage?->isExternal()) {
                $legacyDownloadSync = $this->syncLegacyDownloadLinks($importedResource, $doi, $doiRecord, $metaworksService);
            }

            if ($dataCiteLandingPageSync['sync_eligible'] || $legacyDownloadSync['sync_eligible']) {
                $this->resourceIdsForDataCiteSync[] = (int) $importedResource->id;
            }

            if ($legacyBreakReplacements > 0) {
                $this->resourceIdsForDataCiteSync[] = (int) $importedResource->id;
                $this->resourceIdsForFullDataCiteSync[] = (int) $importedResource->id;
            }

            Log::debug('Imported DOI', ['doi' => $doi]);

            return [
                'status' => 'imported',
                'metaworks_unavailable' => $metaworksUnavailable || $legacyDownloadSync['metaworks_unavailable'],
                'enriched' => false,
            ];
        } catch (QueryException $exception) {
            $isDuplicateEntry = false;
            if (isset($exception->errorInfo[1])) {
                $isDuplicateEntry = $exception->errorInfo[1] === 1062;
            }
            if (! $isDuplicateEntry && str_contains($exception->getMessage(), 'UNIQUE constraint failed')) {
                $isDuplicateEntry = true;
            }

            if ($isDuplicateEntry) {
                Log::debug('Skipping DOI due to concurrent insert (race condition)', ['doi' => $doi]);

                $existingResource = Resource::where('doi', $doi)->first();

                if ($existingResource === null) {
                    return [
                        'status' => 'skipped',
                        'metaworks_unavailable' => $metaworksUnavailable,
                        'enriched' => false,
                    ];
                }

                $repairResult = $this->repairExistingResource(
                    resource: $existingResource,
                    doi: $doi,
                    doiRecord: $doiRecord,
                    metaworksService: $metaworksService,
                    shouldLookupMetaworks: $shouldLookupMetaworks && ! $metaworksUnavailable,
                    datacenterNames: $portalDatacenterNames ?? [],
                );
                $repairResult['metaworks_unavailable'] = $repairResult['metaworks_unavailable'] || $metaworksUnavailable;

                return $repairResult;
            }

            throw $exception;
        }
    }

    /**
     * Repair enrichment that may have been missed by an earlier import while
     * still reporting the DOI as skipped for import-count compatibility.
     *
     * @param  array<string, mixed>  $doiRecord
     * @param  list<string>  $datacenterNames
     * @return array{status: 'skipped', metaworks_unavailable: bool, enriched: bool}
     */
    private function repairExistingResource(
        Resource $resource,
        string $doi,
        array $doiRecord,
        MetaworksDownloadUrlService $metaworksService,
        bool $shouldLookupMetaworks,
        array $datacenterNames = [],
    ): array {
        Log::debug('Repairing existing DOI import enrichment', ['doi' => $doi]);

        $dataCiteLandingPageSync = $this->syncDataCiteLandingPageIfAllowed(
            $resource,
            $doi,
            $doiRecord,
            $datacenterNames,
        );
        $legacyDownloadSync = $this->emptyLegacyDownloadSyncResult();
        $resource->unsetRelation('landingPage');
        $resource->load('landingPage');

        if ($shouldLookupMetaworks && ! $resource->landingPage?->isExternal()) {
            $legacyDownloadSync = $this->syncLegacyDownloadLinks($resource, $doi, $doiRecord, $metaworksService);
        }

        if ($dataCiteLandingPageSync['sync_eligible'] || $legacyDownloadSync['sync_eligible']) {
            $this->resourceIdsForDataCiteSync[] = (int) $resource->id;
        }

        return [
            'status' => 'skipped',
            'metaworks_unavailable' => $legacyDownloadSync['metaworks_unavailable'],
            'enriched' => $dataCiteLandingPageSync['changed'] || $legacyDownloadSync['changed'],
        ];
    }

    /**
     * @param  array<string, mixed>  $doiRecord
     */
    private function doiRecordLandingPageUrl(array $doiRecord): mixed
    {
        $attributes = is_array($doiRecord['attributes'] ?? null)
            ? $doiRecord['attributes']
            : $doiRecord;

        return $attributes['url'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $preparedDoiRecord
     * @return array<string, mixed>
     */
    private function withCrc806LegacyRightsFallback(
        array $preparedDoiRecord,
        string $doi,
        mixed $landingPageUrl,
    ): array {
        $hasAttributesWrapper = is_array($preparedDoiRecord['attributes'] ?? null);
        $attributes = $hasAttributesWrapper
            ? $preparedDoiRecord['attributes']
            : $preparedDoiRecord;

        if ($this->hasUsableRights($attributes['rightsList'] ?? null)) {
            return $preparedDoiRecord;
        }

        $rights = ($this->crc806LegacyRightsService ??= app(Crc806LegacyRightsService::class))
            ->findRights($doi, $landingPageUrl);

        if ($rights === null) {
            return $preparedDoiRecord;
        }

        $attributes['rightsList'] = [
            ...$this->coarAccessRightStatements($attributes['rightsList'] ?? null),
            $rights,
        ];

        if ($hasAttributesWrapper) {
            $preparedDoiRecord['attributes'] = $attributes;

            return $preparedDoiRecord;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $preparedDoiRecord
     * @param  list<string>  $datacenterNames
     * @return array<string, mixed>
     */
    private function withGeofonSeismicEventsRights(
        array $preparedDoiRecord,
        string $doi,
        array $datacenterNames,
    ): array {
        $rights = ($this->geofonSeismicEventsRightsService ??= app(GeofonSeismicEventsRightsService::class))
            ->rightsStatementForImport($doi, $datacenterNames);

        if ($rights === null) {
            return $preparedDoiRecord;
        }

        $hasAttributesWrapper = is_array($preparedDoiRecord['attributes'] ?? null);
        $attributes = $hasAttributesWrapper
            ? $preparedDoiRecord['attributes']
            : $preparedDoiRecord;
        $rightsList = is_array($attributes['rightsList'] ?? null)
            ? array_values($attributes['rightsList'])
            : [];

        $attributes['rightsList'] = [...$rightsList, $rights];

        if ($hasAttributesWrapper) {
            $preparedDoiRecord['attributes'] = $attributes;

            return $preparedDoiRecord;
        }

        return $attributes;
    }

    private function hasUsableRights(mixed $rightsList): bool
    {
        if (! is_array($rightsList)) {
            return false;
        }

        foreach ($rightsList as $statement) {
            if (is_string($statement) && trim($statement) !== '') {
                return true;
            }

            if (! is_array($statement)) {
                continue;
            }

            if ($this->isCoarAccessRightStatement($statement)) {
                continue;
            }

            foreach (['rights', 'rights_text', 'rightsUri', 'rightsURI', 'rights_uri', 'rightsIdentifier', 'rights_identifier'] as $key) {
                if (is_string($statement[$key] ?? null) && trim($statement[$key]) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function coarAccessRightStatements(mixed $rightsList): array
    {
        if (! is_array($rightsList)) {
            return [];
        }

        return array_values(array_filter(
            $rightsList,
            fn (mixed $statement): bool => is_array($statement)
                && $this->isCoarAccessRightStatement($statement),
        ));
    }

    /** @param array<string, mixed> $statement */
    private function isCoarAccessRightStatement(array $statement): bool
    {
        foreach (['rightsUri', 'rightsURI', 'rights_uri'] as $key) {
            $uri = $statement[$key] ?? null;

            if (is_string($uri) && AccessLevel::fromCoarUri($uri) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $doiRecord
     * @param  list<string>  $datacenterNames
     * @return array{changed: bool, sync_eligible: bool}
     */
    private function syncDataCiteLandingPageIfAllowed(
        Resource $resource,
        string $doi,
        array $doiRecord,
        array $datacenterNames = [],
    ): array {
        $attributes = is_array($doiRecord['attributes'] ?? null)
            ? $doiRecord['attributes']
            : $doiRecord;
        $decisionService = app(LegacyLandingPageDecisionService::class);

        if (! $decisionService->shouldImportDataCiteUrlAsExternal($doi, $attributes, $datacenterNames)) {
            $couldBeAssignedGeofonEvent = $decisionService->shouldImportDataCiteUrlAsExternal(
                $doi,
                $attributes,
                [LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER],
            );

            if (! $couldBeAssignedGeofonEvent) {
                return $this->emptyDataCiteLandingPageSyncResult();
            }

            $assignedDatacenterName = $resource->datacenter()->value('name');

            if (! is_string($assignedDatacenterName)
                || ! $decisionService->shouldImportDataCiteUrlAsExternal(
                    $doi,
                    $attributes,
                    [$assignedDatacenterName],
                )) {
                return $this->emptyDataCiteLandingPageSyncResult();
            }
        }

        try {
            $result = app(DataCiteLandingPageImportService::class)->createExternalForResource($resource, $attributes);

            return [
                'changed' => $result['changed'],
                'sync_eligible' => $result['created']
                    && $result['landing_page']?->is_published === true,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Failed to import external DataCite landing page URL', [
                'doi' => $resource->doi,
                'resource_id' => $resource->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->emptyDataCiteLandingPageSyncResult();
        }
    }

    /**
     * @return array{changed: bool, sync_eligible: bool}
     */
    private function emptyDataCiteLandingPageSyncResult(): array
    {
        return ['changed' => false, 'sync_eligible' => false];
    }

    /**
     * @param  array<string, mixed>  $doiRecord
     * @return array{changed: bool, metaworks_unavailable: bool, sync_eligible: bool}
     */
    private function syncLegacyDownloadLinks(
        Resource $resource,
        string $doi,
        array $doiRecord,
        MetaworksDownloadUrlService $metaworksService,
    ): array {
        /** @var array{files: list<array{url: string, label: string|null, source_name: string|null, visible: string|null}>, allPublic: bool, resourceFound?: bool, hasFileRows?: bool, resourcePublicStatus?: string|null} $fileResult */
        $fileResult = ['files' => [], 'allPublic' => false, 'resourceFound' => false, 'hasFileRows' => false, 'resourcePublicStatus' => null];

        try {
            $fileResult = $metaworksService->lookupFileEntries($doi);
        } catch (\Throwable $exception) {
            Log::warning('Metaworks DB unavailable, disabling lookups for remaining DOIs', [
                'doi' => $doi,
                'error' => $exception->getMessage(),
            ]);

            return [
                'changed' => false,
                'metaworks_unavailable' => true,
                'sync_eligible' => false,
            ];
        }

        $fileResult += [
            'resourceFound' => false,
            'hasFileRows' => $fileResult['files'] !== [],
            'resourcePublicStatus' => null,
        ];

        $attributes = is_array($doiRecord['attributes'] ?? null)
            ? $doiRecord['attributes']
            : $doiRecord;
        $decision = app(LegacyLandingPageDecisionService::class)->internalLandingPageDecision(
            $fileResult['resourcePublicStatus'],
            isset($attributes['state']) ? (string) $attributes['state'] : null,
        );

        if (! $decision['should_create']) {
            return $this->emptyLegacyDownloadSyncResult();
        }

        try {
            $syncResult = app(LegacyLandingPageImportService::class)->syncMissingFileEntries(
                resource: $resource,
                fileEntries: $fileResult['files'],
                isPublished: $decision['should_publish'],
                createWhenEmpty: true,
            );
        } catch (\Throwable $exception) {
            Log::warning('Failed to sync landing page with download links', [
                'doi' => $doi,
                'resource_id' => $resource->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->emptyLegacyDownloadSyncResult();
        }

        return [
            'changed' => $syncResult['changed'],
            'metaworks_unavailable' => false,
            'sync_eligible' => $decision['should_sync']
                && $syncResult['landing_page'] !== null
                && $syncResult['landing_page']->is_published
                && ! $syncResult['landing_page']->isExternal()
                && trim((string) $syncResult['landing_page']->public_url) !== trim((string) ($attributes['url'] ?? '')),
        ];
    }

    /** @return array{changed: bool, metaworks_unavailable: bool, sync_eligible: bool} */
    private function emptyLegacyDownloadSyncResult(): array
    {
        return [
            'changed' => false,
            'metaworks_unavailable' => false,
            'sync_eligible' => false,
        ];
    }

    /**
     * @param  list<string>|null  $portalDatacenterNames
     */
    private function enrichImportedResourceFromLegacyDatabases(
        Resource $resource,
        string $doi,
        ?array $portalDatacenterNames = null,
    ): void {
        if (! $resource->exists) {
            return;
        }

        app(SumarioPmdContactEnrichmentService::class)->enrich($resource, $doi);

        try {
            app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, $doi);
        } catch (\Throwable $exception) {
            Log::warning('Unexpected SUMARIO coverage enrichment failure; continuing DataCite import.', [
                'doi' => $doi,
                'resource_id' => $resource->id,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($portalDatacenterNames !== null && $portalDatacenterNames !== []) {
            $this->syncPortalDatacenters($resource, $portalDatacenterNames);

            return;
        }

        app(LegacyMetaworksDatacenterLookupService::class)->syncDatacenters($resource, $doi);
    }

    /**
     * @param  list<string>  $datacenterNames
     */
    private function syncPortalDatacenters(Resource $resource, array $datacenterNames): void
    {
        $names = $this->normalizePortalDatacenterNames($datacenterNames);

        if ($names === []) {
            return;
        }

        $selectedName = collect($names)->first(
            static fn (string $name): bool => $name !== LegacyMetaworksDatacenterLookupService::DEFAULT_DATACENTER,
        ) ?? $names[0];

        if (isset($this->portalDatacenterIds[$selectedName])) {
            $datacenterId = $this->portalDatacenterIds[$selectedName];
        } else {
            $datacenterId = (int) Datacenter::firstOrCreate(['name' => $selectedName])->id;
            $this->portalDatacenterIds[$selectedName] = $datacenterId;
        }

        if ($resource->datacenter_id !== $datacenterId) {
            $resource->forceFill(['datacenter_id' => $datacenterId])->save();
        }
    }

    /**
     * @param  array<string, list<string>>  $targets
     */
    private function cacheExistingPortalDatacenterIds(array $targets): void
    {
        $targetDatacenterNames = [];

        foreach ($targets as $datacenterNames) {
            foreach ($datacenterNames as $datacenterName) {
                $targetDatacenterNames[] = $datacenterName;
            }
        }

        $names = $this->normalizePortalDatacenterNames($targetDatacenterNames);

        if ($names === []) {
            return;
        }

        foreach (Datacenter::query()->whereIn('name', $names)->get(['id', 'name']) as $datacenter) {
            $this->portalDatacenterIds[$datacenter->name] = (int) $datacenter->id;
        }
    }

    /**
     * @param  list<string>  $datacenterNames
     * @return list<string>
     */
    private function normalizePortalDatacenterNames(array $datacenterNames): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (string $name): string => trim($name), $datacenterNames),
            static fn (string $name): bool => $name !== '',
        )));
    }

    private function finishDataCiteSyncPhase(): void
    {
        if ($this->determineFinalStatus() === 'cancelled') {
            return;
        }

        app(ImportedResourceDataCiteSyncDispatcherService::class)->dispatch(
            ImportProgressService::TYPE_RESOURCE,
            $this->importId,
            $this->resourceIdsForDataCiteSync,
            fullMetadataResourceIds: $this->resourceIdsForFullDataCiteSync,
        );
    }

    /**
     * @param  array<string, mixed>  $doiRecord
     * @return array{record: array<string, mixed>, replacements: int}
     */
    private function normalizeLegacyDescriptionBreaks(array $doiRecord): array
    {
        $hasAttributes = is_array($doiRecord['attributes'] ?? null);
        $attributes = $hasAttributes ? $doiRecord['attributes'] : $doiRecord;
        $descriptions = $attributes['descriptions'] ?? null;

        if (! is_array($descriptions)) {
            return ['record' => $doiRecord, 'replacements' => 0];
        }

        $replacements = 0;
        $normalizer = new LegacyDescriptionBreakNormalizer;

        foreach ($descriptions as $index => $description) {
            if (! is_array($description) || ! is_string($description['description'] ?? null)) {
                continue;
            }

            $normalized = $normalizer->normalizeStoredValue($description['description']);
            $attributes['descriptions'][$index]['description'] = $normalized['value'];
            $replacements += $normalized['replacements'];
        }

        if ($hasAttributes) {
            $doiRecord['attributes'] = $attributes;
        } else {
            $doiRecord = $attributes;
        }

        return ['record' => $doiRecord, 'replacements' => $replacements];
    }

    /**
     * Update progress counts in cache.
     *
     * @param  array<int, string>  $skippedDois
     * @param  array<int, string>  $enrichedDois
     * @param  array<int, array{doi: string, error: string}>  $failedDois
     */
    private function updateProgressCounts(
        int $processed,
        int $imported,
        int $skipped,
        int $failed,
        int $enriched,
        array $skippedDois,
        array $enrichedDois,
        array $failedDois,
        int $total
    ): void {
        // Only update cache every 50 records to reduce cache load.
        // For 10,000 DOIs this results in ~200 cache writes instead of 10,000.
        // The condition ($processed === 1 || % 50 === 0) matches the cancellation check
        // in the main loop, ensuring progress is always updated when cancellation is checked.
        // The final state is always written when $processed === $total.
        if ($processed === 1 || $processed % 50 === 0 || $processed === $total) {
            // Update only the changing keys to avoid unnecessary array copies
            $this->updateProgressKeys([
                'processed' => $processed,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'enriched' => $enriched,
                'skipped_dois' => $skippedDois,
                'enriched_dois' => $enrichedDois,
                'failed_dois' => $failedDois,
            ]);
        }
    }

    /**
     * Update the progress cache with a complete progress array.
     *
     * Use this for initial setup where all keys are provided.
     *
     * @param  array<string, mixed>  $data  Complete progress data
     */
    private function updateProgress(array $data): void
    {
        Cache::put(
            $this->getCacheKey(),
            $data,
            now()->addHours(24)
        );
    }

    /**
     * Update specific keys in the progress cache.
     *
     * Use this for incremental updates where only some keys change.
     * More efficient than updateProgress when modifying existing state.
     *
     * @param  array<string, mixed>  $data  Keys to update
     */
    private function updateProgressKeys(array $data): void
    {
        $currentProgress = Cache::get($this->getCacheKey(), []);

        // Directly assign new values to avoid array_merge overhead
        foreach ($data as $key => $value) {
            $currentProgress[$key] = $value;
        }

        Cache::put(
            $this->getCacheKey(),
            $currentProgress,
            now()->addHours(24)
        );
    }

    /**
     * @param  array<string, mixed>  $doiRecord
     * @return array{doi: string, doiRecord: array<string, mixed>}
     */
    private function normalizeDoiRecord(string $doi, array $doiRecord): array
    {
        $normalizedDoi = $this->normalizeDoi($doi);
        $normalizedRecord = $doiRecord;

        if (isset($normalizedRecord['attributes']) && is_array($normalizedRecord['attributes'])) {
            $normalizedRecord['attributes']['doi'] = $normalizedDoi;
        } else {
            $normalizedRecord['doi'] = $normalizedDoi;
        }

        if (array_key_exists('id', $normalizedRecord)) {
            $normalizedRecord['id'] = $normalizedDoi;
        }

        return [
            'doi' => $normalizedDoi,
            'doiRecord' => $normalizedRecord,
        ];
    }

    private function shouldSkipLegacyDoi(string $doi): bool
    {
        return app(LegacyLandingPageDecisionService::class)->shouldSkipLegacyDoi($doi);
    }

    private function normalizeDoi(string $doi): string
    {
        return app(DoiSuggestionService::class)->normalizeDoi($doi);
    }

    /**
     * Get the cache key for this import.
     */
    private function getCacheKey(): string
    {
        return "datacite_import:{$this->importId}";
    }

    private function determineFinalStatus(): string
    {
        $currentStatus = Cache::get($this->getCacheKey());

        return (isset($currentStatus['status']) && $currentStatus['status'] === 'cancelled')
            ? 'cancelled'
            : 'completed';
    }

    private function isCancelled(): bool
    {
        $currentStatus = Cache::get($this->getCacheKey());

        return isset($currentStatus['status']) && $currentStatus['status'] === 'cancelled';
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('DataCite import job failed completely', [
            'import_id' => $this->importId,
            'error' => $exception?->getMessage(),
        ]);

        $this->updateProgress([
            'status' => 'failed',
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get the import ID.
     */
    public function getImportId(): string
    {
        return $this->importId;
    }

    public function getSingleDoi(): ?string
    {
        return $this->singleDoi;
    }

    public function getDatacenterId(): ?string
    {
        return $this->datacenterId;
    }
}
