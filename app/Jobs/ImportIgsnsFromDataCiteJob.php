<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\DataCiteToIgsnTransformer;
use App\Services\IgsnChildDiscoveryService;
use App\Services\IgsnEnrichmentService;
use App\Services\IgsnImportService;
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

    /**
     * @param  int  $userId  The user who initiated the import
     * @param  string  $importId  UUID for progress tracking
     */
    public function __construct(
        private int $userId,
        private string $importId,
        private ?string $singleDoi = null,
    ) {
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
    ): void {
        Log::info('Starting IGSN import job', [
            'import_id' => $this->importId,
            'user_id' => $this->userId,
            'single_doi' => $this->singleDoi,
        ]);

        $startTime = now();

        try {
            if ($this->singleDoi !== null) {
                $this->handleSingleImport(
                    importService: $importService,
                    transformer: $transformer,
                    enrichmentService: $enrichmentService,
                    childDiscoveryService: $childDiscoveryService ?? app(IgsnChildDiscoveryService::class),
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
                    'started_at' => $startTime->toIso8601String(),
                    'completed_at' => now()->toIso8601String(),
                ]);

                return;
            }

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
                    $result = $this->processIgsnRecord($doi, $igsnRecord, $transformer, $enrichmentService);

                    if ($result['status'] === 'skipped') {
                        $skipped++;
                        if (count($skippedDois) < $maxStoredDois) {
                            $skippedDois[] = $doi;
                        }
                        $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $failedDois, $total);

                        continue;
                    }

                    $imported++;

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
                'status' => $finalStatus,
                'total' => $total,
                'processed' => $processed,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'enriched' => $enriched,
                'skipped_dois' => $skippedDois,
                'failed_dois' => $failedDois,
                'started_at' => $startTime->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);

            Log::info('IGSN import completed', [
                'import_id' => $this->importId,
                'total' => $total,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'enriched' => $enriched,
                'duration_seconds' => now()->diffInSeconds($startTime),
            ]);

        } catch (\Exception $e) {
            Log::error('IGSN import job failed', [
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

    private function handleSingleImport(
        IgsnImportService $importService,
        DataCiteToIgsnTransformer $transformer,
        IgsnEnrichmentService $enrichmentService,
        IgsnChildDiscoveryService $childDiscoveryService,
        string $startedAt,
    ): void {
        $requestedDoi = IgsnIdentifier::normalizeDoi((string) $this->singleDoi);
        if ($requestedDoi === null) {
            throw new \RuntimeException('Single IGSN import requested without a valid IGSN DOI.');
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
        $targetHandles = array_values(array_filter(array_map(
            fn (string $doi): ?string => IgsnIdentifier::handleFromDoi($doi),
            $targetDois,
        )));
        $total = count($targetDois);

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
            'requested_igsn' => $requestedHandle,
            'discovered_children' => $childHandles,
            'started_at' => $startedAt,
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
        $maxStoredDois = 100;

        foreach ($targetDois as $doi) {
            if ($this->isCancelled()) {
                Log::info('Single IGSN import cancelled by user', [
                    'import_id' => $this->importId,
                    'processed' => $processed,
                ]);
                break;
            }

            $processed++;

            $igsnRecord = $targetRecords[$doi] ?? $importService->fetchSingleIgsn($doi);

            if ($igsnRecord === null) {
                $failed++;
                if (count($failedDois) < $maxStoredDois) {
                    $failedDois[] = ['doi' => $doi, 'error' => 'IGSN was discovered as a related import target but was not found at DataCite.'];
                }
                $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $failedDois, $total);

                continue;
            }

            ['doi' => $normalizedDoi, 'igsnRecord' => $igsnRecord] = $this->normalizeIgsnRecord($doi, $igsnRecord);

            try {
                $result = $this->processIgsnRecord($normalizedDoi, $igsnRecord, $transformer, $enrichmentService);

                if ($result['status'] === 'skipped') {
                    $skipped++;
                    if (count($skippedDois) < $maxStoredDois) {
                        $skippedDois[] = $normalizedDoi;
                    }
                    $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $failedDois, $total);

                    continue;
                }

                $imported++;
                if ($result['enriched']) {
                    $enriched++;
                }
            } catch (\Exception $e) {
                $failed++;
                if (count($failedDois) < $maxStoredDois) {
                    $failedDois[] = ['doi' => $normalizedDoi, 'error' => $e->getMessage()];
                }

                Log::warning('Failed to import single IGSN target', [
                    'doi' => $normalizedDoi,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $failedDois, $total);
        }

        if (! $this->isCancelled()) {
            $this->resolveParentRelationships($targetHandles);
        }

        $this->updateProgress([
            'status' => $this->determineFinalStatus(),
            'total' => $total,
            'processed' => $processed,
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'enriched' => $enriched,
            'skipped_dois' => $skippedDois,
            'failed_dois' => $failedDois,
            'requested_igsn' => $requestedHandle,
            'discovered_children' => $childHandles,
            'started_at' => $startedAt,
            'completed_at' => now()->toIso8601String(),
        ]);

        Log::info('Single IGSN import completed', [
            'import_id' => $this->importId,
            'requested_igsn' => $requestedHandle,
            'total' => $total,
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'enriched' => $enriched,
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
     * @return array{status: 'imported'|'skipped', enriched: bool}
     */
    private function processIgsnRecord(
        string $doi,
        array $igsnRecord,
        DataCiteToIgsnTransformer $transformer,
        IgsnEnrichmentService $enrichmentService,
    ): array {
        try {
            if (Resource::where('doi', $doi)->exists()) {
                return ['status' => 'skipped', 'enriched' => false];
            }

            $result = DB::transaction(function () use ($transformer, $igsnRecord) {
                $resource = $transformer->transform($igsnRecord, $this->userId);

                return ['status' => 'imported', 'resource' => $resource];
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateEntry($e)) {
                return ['status' => 'skipped', 'enriched' => false];
            }

            throw $e;
        }

        /** @var Resource $importedResource */
        $importedResource = $result['resource'];
        $igsnMetadata = $importedResource->igsnMetadata;
        $wasEnriched = false;

        if ($igsnMetadata instanceof IgsnMetadata) {
            try {
                $wasEnriched = $enrichmentService->enrich($importedResource, $igsnMetadata);
            } catch (\Throwable $e) {
                Log::debug('IGSN enrichment failed (non-critical)', [
                    'doi' => $doi,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['status' => 'imported', 'enriched' => $wasEnriched];
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
     */
    private function resolveParentRelationships(?array $onlyHandles = null): void
    {
        Log::info('Resolving IGSN parent-child relationships');

        $resolved = 0;

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
            ->chunkById(500, function ($records) use (&$resolved): void {
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
                    }
                }
            });

        Log::info('Parent-child resolution completed', ['resolved' => $resolved]);
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

    public function failed(?\Throwable $exception): void
    {
        Log::error('IGSN import job failed completely', [
            'import_id' => $this->importId,
            'error' => $exception?->getMessage(),
        ]);

        $this->updateProgress([
            'status' => 'failed',
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function getImportId(): string
    {
        return $this->importId;
    }

    public function getSingleDoi(): ?string
    {
        return $this->singleDoi;
    }
}
