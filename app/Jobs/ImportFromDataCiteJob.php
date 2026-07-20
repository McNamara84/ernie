<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Datacenter;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteImportService;
use App\Services\DataCiteLandingPageImportService;
use App\Services\DataCiteSyncService;
use App\Services\DataCiteToResourceTransformer;
use App\Services\DoiSuggestionService;
use App\Services\GfzDataServicesPortalService;
use App\Services\LegacyLandingPageDecisionService;
use App\Services\LegacyLandingPageImportService;
use App\Services\LegacyMetaworksDatacenterLookupService;
use App\Services\MetaworksDownloadUrlService;
use App\Services\SumarioPendingResourceImportService;
use App\Services\SumarioPmdContactEnrichmentService;
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
            $pendingImportUnavailable = false;

            try {
                $pendingTotal = $pendingImportService->countImportablePending();
            } catch (\Throwable $exception) {
                $pendingTotal = 0;
                $pendingImportUnavailable = true;

                Log::warning('SUMARIO pending import count failed; skipping pending resources', [
                    'import_id' => $this->importId,
                    'error' => $exception->getMessage(),
                ]);
            }

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

            if (! $pendingImportUnavailable && $this->determineFinalStatus() !== 'cancelled') {
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
                    $processed += $pendingTotal;
                    $failed += $pendingTotal;

                    if ($pendingTotal > 0 && count($failedDois) < $maxStoredDois) {
                        $failedDois[] = [
                            'doi' => 'sumario-pending',
                            'error' => 'SUMARIO pending import is unavailable.',
                        ];
                    }

                    Log::warning('SUMARIO pending import failed; continuing DataCite import job', [
                        'import_id' => $this->importId,
                        'pending_total' => $pendingTotal,
                        'error' => $exception->getMessage(),
                    ]);
                }

                $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $enrichedDois, $failedDois, $total);
            }

            // Determine final status - preserve 'cancelled' if user cancelled during processing
            $finalStatus = $this->determineFinalStatus();

            $this->updateProgress([
                'status' => $finalStatus,
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
                'completed_at' => now()->toIso8601String(),
                'current_prefix' => null,
            ]);

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
        $warnings = [];

        try {
            $pendingDois = $pendingImportService
                ->importablePendingDoisForDatacenter($datacenter['name']);
        } catch (\Throwable $exception) {
            $pendingDois = [];
            $warnings[] = 'Matching SUMARIO pending resources could not be loaded.';

            Log::warning('SUMARIO pending datacenter lookup failed; importing portal resources only', [
                'import_id' => $this->importId,
                'datacenter_id' => $datacenter['id'],
                'error' => $exception->getMessage(),
            ]);
        }

        foreach ($pendingDois as $pendingDoi) {
            $normalizedDoi = $this->normalizeDoi($pendingDoi);

            if ($normalizedDoi !== '' && ! array_key_exists($normalizedDoi, $targets)) {
                $targets[$normalizedDoi] = [];
            }
        }

        ksort($targets);

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

        foreach ($importService->fetchAllDois() as $doiRecord) {
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
                    $pendingResult = $pendingImportService->importPendingByDoi($doi, $this->userId);

                    if ($pendingResult['status'] === 'imported') {
                        if ($portalDatacenterNames !== [] && $pendingResult['resource'] !== null) {
                            $this->syncPortalDatacenters(
                                $pendingResult['resource'],
                                $portalDatacenterNames,
                            );
                        }

                        $recordOutcome($doi, [
                            'status' => 'imported',
                            'enriched' => false,
                            'metaworks_unavailable' => false,
                            'error' => null,
                        ]);
                    } elseif ($pendingResult['status'] === 'skipped') {
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
                            'error' => $pendingResult['error']
                                ?? 'The DOI was not found in DataCite or SUMARIO pending resources.',
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
                        'error' => 'SUMARIO pending lookup is unavailable.',
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
            'status' => $finalStatus,
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
            'completed_at' => now()->toIso8601String(),
            'current_prefix' => null,
        ]);

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
            $this->handleSinglePendingFallback($doi, $startedAt);

            return;
        }

        ['doi' => $doi, 'doiRecord' => $doiRecord] = $this->normalizeDoiRecord($doi, $doiRecord);

        try {
            $result = $this->processDoiRecord(
                doi: $doi,
                doiRecord: $doiRecord,
                transformer: $transformer,
                metaworksService: $metaworksService,
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

        $this->updateProgress([
            'status' => $this->determineFinalStatus(),
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
            'completed_at' => now()->toIso8601String(),
            'current_prefix' => null,
        ]);
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

    private function handleSinglePendingFallback(string $doi, string $startedAt): void
    {
        try {
            $result = app(SumarioPendingResourceImportService::class)
                ->importPendingByDoi($doi, $this->userId);
        } catch (\Throwable $exception) {
            Log::warning('SUMARIO pending lookup failed during single DOI fallback', [
                'doi' => $doi,
                'error' => $exception->getMessage(),
            ]);

            $this->markSingleImportAsFailed($doi, 'SUMARIO pending lookup is unavailable.', $startedAt);

            return;
        }

        if ($result['status'] === 'imported') {
            $this->updateProgress([
                'status' => $this->determineFinalStatus(),
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
                'completed_at' => now()->toIso8601String(),
                'current_prefix' => null,
            ]);

            return;
        }

        if ($result['status'] === 'skipped') {
            $this->updateProgress([
                'status' => $this->determineFinalStatus(),
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
                'completed_at' => now()->toIso8601String(),
                'current_prefix' => null,
            ]);

            return;
        }

        $error = $result['error']
            ?? 'The DOI was not found in DataCite or SUMARIO pending resources.';

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
    ): array {
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
                Log::debug('Skipping existing DOI', ['doi' => $doi]);

                $dataCiteLandingPageSync = $this->syncDataCiteLandingPageIfAllowed($existingResource, $doi, $doiRecord);
                $legacyDownloadSync = $this->emptyLegacyDownloadSyncResult();

                if ($shouldLookupMetaworks && ! LandingPage::where('resource_id', $existingResource->id)->exists()) {
                    $legacyDownloadSync = $this->syncLegacyDownloadLinks($existingResource, $doi, $metaworksService);
                }

                return [
                    'status' => 'skipped',
                    'metaworks_unavailable' => $legacyDownloadSync['metaworks_unavailable'],
                    'enriched' => $dataCiteLandingPageSync['changed'] || $legacyDownloadSync['changed'],
                ];
            }

            $preparedDoiRecord = $transformer->prepareDoiData($doiRecord);

            // Use database transaction to ensure atomicity of the check-then-insert operation.
            //
            // Design decision: We use SELECT + INSERT rather than INSERT IGNORE because:
            // 1. We need to know which DOIs were skipped for user feedback (skipped_dois list)
            // 2. INSERT IGNORE would silently succeed, making it impossible to track skips
            // 3. The unique constraint on DOI provides protection against race conditions
            // 4. Most imports won't have many duplicates, so the SELECT overhead is minimal
            $result = DB::transaction(function () use ($transformer, $preparedDoiRecord, $doi) {
                if (Resource::where('doi', $doi)->exists()) {
                    return ['status' => 'skipped', 'resource' => null];
                }

                $resource = $transformer->transform($preparedDoiRecord, $this->userId);

                return ['status' => 'imported', 'resource' => $resource];
            });

            if ($result['status'] === 'skipped') {
                Log::debug('Skipping existing DOI', ['doi' => $doi]);

                $existingResource = Resource::where('doi', $doi)->first();
                $dataCiteLandingPageSync = $existingResource !== null
                    ? $this->syncDataCiteLandingPageIfAllowed($existingResource, $doi, $preparedDoiRecord)
                    : $this->emptyDataCiteLandingPageSyncResult();
                $legacyDownloadSync = $this->emptyLegacyDownloadSyncResult();

                if ($existingResource !== null && $shouldLookupMetaworks && ! LandingPage::where('resource_id', $existingResource->id)->exists()) {
                    $legacyDownloadSync = $this->syncLegacyDownloadLinks($existingResource, $doi, $metaworksService);
                }

                return [
                    'status' => 'skipped',
                    'metaworks_unavailable' => $legacyDownloadSync['metaworks_unavailable'],
                    'enriched' => $dataCiteLandingPageSync['changed'] || $legacyDownloadSync['changed'],
                ];
            }

            /** @var Resource $importedResource */
            $importedResource = $result['resource'];

            $this->enrichImportedResourceFromLegacyDatabases(
                $importedResource,
                $doi,
                $portalDatacenterNames,
            );

            $this->syncDataCiteLandingPageIfAllowed($importedResource, $doi, $preparedDoiRecord);

            $legacyDownloadSync = $this->emptyLegacyDownloadSyncResult();

            if ($shouldLookupMetaworks && ! LandingPage::where('resource_id', $importedResource->id)->exists()) {
                $legacyDownloadSync = $this->syncLegacyDownloadLinks($importedResource, $doi, $metaworksService);
            }

            $this->syncDataCiteMetadataIfAllowed($importedResource);

            Log::debug('Imported DOI', ['doi' => $doi]);

            return [
                'status' => 'imported',
                'metaworks_unavailable' => $legacyDownloadSync['metaworks_unavailable'],
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
                $dataCiteLandingPageSync = $existingResource !== null
                    ? $this->syncDataCiteLandingPageIfAllowed($existingResource, $doi, $doiRecord)
                    : $this->emptyDataCiteLandingPageSyncResult();
                $legacyDownloadSync = $this->emptyLegacyDownloadSyncResult();

                if ($existingResource !== null && $shouldLookupMetaworks && ! LandingPage::where('resource_id', $existingResource->id)->exists()) {
                    $legacyDownloadSync = $this->syncLegacyDownloadLinks($existingResource, $doi, $metaworksService);
                }

                return [
                    'status' => 'skipped',
                    'metaworks_unavailable' => $legacyDownloadSync['metaworks_unavailable'],
                    'enriched' => $dataCiteLandingPageSync['changed'] || $legacyDownloadSync['changed'],
                ];
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $doiRecord
     * @return array{changed: bool}
     */
    private function syncDataCiteLandingPageIfAllowed(Resource $resource, string $doi, array $doiRecord): array
    {
        $attributes = is_array($doiRecord['attributes'] ?? null)
            ? $doiRecord['attributes']
            : $doiRecord;

        if (! app(LegacyLandingPageDecisionService::class)->shouldImportDataCiteUrlAsExternal($doi, $attributes)) {
            return $this->emptyDataCiteLandingPageSyncResult();
        }

        try {
            $result = app(DataCiteLandingPageImportService::class)->createExternalForResource($resource, $attributes);

            return ['changed' => $result['changed']];
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
     * @return array{changed: bool}
     */
    private function emptyDataCiteLandingPageSyncResult(): array
    {
        return ['changed' => false];
    }

    /**
     * @return array{changed: bool, metaworks_unavailable: bool}
     */
    private function syncLegacyDownloadLinks(
        Resource $resource,
        string $doi,
        MetaworksDownloadUrlService $metaworksService,
    ): array {
        /** @var array{files: list<array{url: string, label: string|null, visible: string|null}>, allPublic: bool, resourceFound?: bool} $fileResult */
        $fileResult = ['files' => [], 'allPublic' => false, 'resourceFound' => false];

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
            ];
        }

        $fileResult += ['resourceFound' => false];

        try {
            $syncResult = app(LegacyLandingPageImportService::class)->syncMissingFileEntries(
                resource: $resource,
                fileEntries: $fileResult['files'],
                isPublished: $fileResult['files'] !== [] && $fileResult['allPublic'],
                createWhenEmpty: $fileResult['resourceFound'] === true,
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
        ];
    }

    /**
     * @return array{changed: bool, metaworks_unavailable: bool}
     */
    private function emptyLegacyDownloadSyncResult(): array
    {
        return [
            'changed' => false,
            'metaworks_unavailable' => false,
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
        $names = array_values(array_unique(array_filter(
            array_map(static fn (string $name): string => trim($name), $datacenterNames),
            static fn (string $name): bool => $name !== '',
        )));

        if ($names === []) {
            return;
        }

        $datacenterIds = array_map(
            static fn (string $name): int => (int) Datacenter::firstOrCreate(['name' => $name])->id,
            $names,
        );
        $changes = $resource->datacenters()->sync($datacenterIds);

        if (array_filter($changes)) {
            $resource->touch();
        }
    }

    private function syncDataCiteMetadataIfAllowed(Resource $resource): void
    {
        if (
            config('datacite.test_mode') !== false
            || ! (bool) config('datacite.sync_after_import', false)
            || ! $resource->exists
        ) {
            return;
        }

        app(DataCiteSyncService::class)->syncIfRegistered($resource->fresh() ?? $resource);
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
