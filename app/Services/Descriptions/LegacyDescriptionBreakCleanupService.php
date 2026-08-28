<?php

declare(strict_types=1);

namespace App\Services\Descriptions;

use App\Exceptions\ConcurrentLegacyDescriptionChangeException;
use App\Models\Description;
use App\Models\OldDataset;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\DoiSuggestionService;
use App\Support\LegacyDescriptionBreakNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LegacyDescriptionBreakCleanupService
{
    private const LEGACY_SOURCE = 'sumario-pmd';

    public function __construct(
        private readonly LegacyDescriptionBreakNormalizer $normalizer,
        private readonly DoiSuggestionService $doiSuggestionService,
        private readonly LandingPageRenderDataCacheService $landingPageCache,
    ) {}

    /**
     * @param  list<string>  $dois
     * @param  list<int>  $legacyIds
     * @return array{
     *     resources_scanned: int,
     *     legacy_resources: int,
     *     descriptions_scanned: int,
     *     changed: int,
     *     unchanged: int,
     *     not_legacy: int,
     *     manual_review: int,
     *     concurrent_changes: int,
     *     cache_invalidation_failures: int,
     *     errors: int,
     *     breaks_removed: int,
     *     last_scanned_resource_id: int|null,
     *     sync_resource_ids: list<int>,
     *     records: list<array{
     *         resource_id: int,
     *         doi: string,
     *         legacy_resource_id: int|null,
     *         match_method: string,
     *         status: string,
     *         descriptions_scanned: int,
     *         descriptions_changed: int,
     *         breaks_removed: int,
     *         datacite_sync_status: string,
     *         message: string
     *     }>
     * }
     */
    public function run(
        bool $apply = false,
        int $afterId = 0,
        int $limit = 0,
        int $chunk = 100,
        array $dois = [],
        array $legacyIds = [],
    ): array {
        $cursor = max(0, $afterId);
        $limit = max(0, $limit);
        $chunk = max(1, min(1000, $chunk));
        $doiFilter = $this->normalizeDoiFilter($dois);
        $legacyIdFilter = array_values(array_unique(array_filter(
            array_map('intval', $legacyIds),
            static fn (int $id): bool => $id > 0,
        )));
        $stats = $this->emptyStats();

        if (($dois !== [] && $doiFilter === []) || ($legacyIds !== [] && $legacyIdFilter === [])) {
            return $stats;
        }

        $this->preflightLegacyConnection();

        while ($limit === 0 || $stats['resources_scanned'] < $limit) {
            $batchSize = $limit === 0 ? $chunk : min($chunk, $limit - $stats['resources_scanned']);
            $resources = Resource::query()
                ->select(['id', 'doi', 'legacy_source', 'legacy_source_id'])
                ->whereNull('legacy_description_breaks_normalized_at')
                ->where('id', '>', $cursor)
                ->where(function (Builder $query): void {
                    $query->where('legacy_source', self::LEGACY_SOURCE)
                        ->orWhere(function (Builder $doiQuery): void {
                            $doiQuery->whereNotNull('doi')
                                ->whereRaw("TRIM(doi) <> ''");
                        });
                })
                ->when(
                    $doiFilter !== [],
                    fn (Builder $query): Builder => $query->whereIn(DB::raw('LOWER(doi)'), $doiFilter),
                )
                ->when(
                    $legacyIdFilter !== [],
                    fn (Builder $query): Builder => $query
                        ->where('legacy_source', self::LEGACY_SOURCE)
                        ->whereIn('legacy_source_id', $legacyIdFilter),
                )
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($resources->isEmpty()) {
                break;
            }

            $cursor = (int) $resources->last()->id;
            $legacyMatches = $this->legacyMatchesForUnlinkedResources(array_values($resources->all()));
            /** @var array<int, array{status: 'matched'|'not_legacy'|'manual_review', legacy_resource_id: int|null, match_method: string}> $matchesByResourceId */
            $matchesByResourceId = [];

            foreach ($resources as $resource) {
                $matchesByResourceId[(int) $resource->id] = $this->resolveMatch($resource, $legacyMatches);
            }

            $matchedResources = $resources->filter(
                static fn (Resource $resource): bool => $matchesByResourceId[(int) $resource->id]['status'] === 'matched',
            );

            if ($matchedResources->isNotEmpty()) {
                $matchedResources->load($apply ? ['descriptions', 'landingPage'] : ['descriptions']);
            }

            foreach ($resources as $resource) {
                $stats['resources_scanned']++;
                $stats['last_scanned_resource_id'] = (int) $resource->id;
                $match = $matchesByResourceId[(int) $resource->id];

                if ($match['status'] === 'not_legacy') {
                    $stats['not_legacy']++;

                    continue;
                }

                if ($match['status'] === 'manual_review') {
                    $stats['manual_review']++;
                    $stats['records'][] = $this->record(
                        resource: $resource,
                        legacyResourceId: null,
                        matchMethod: 'doi',
                        status: 'manual_review',
                        message: 'Multiple SUMARIO resources have the same DOI.',
                    );

                    continue;
                }

                $stats['legacy_resources']++;

                try {
                    $result = $this->processResource($resource, $apply);
                    $stats['descriptions_scanned'] += $result['descriptions_scanned'];
                    $stats['breaks_removed'] += $result['breaks_removed'];
                    $stats['cache_invalidation_failures'] += (int) $result['cache_invalidation_failed'];

                    if ($result['descriptions_changed'] > 0) {
                        $stats['changed']++;
                    } else {
                        $stats['unchanged']++;
                    }

                    if ($apply && $result['descriptions_changed'] > 0 && trim((string) $resource->doi) !== '') {
                        $stats['sync_resource_ids'][] = (int) $resource->id;
                    }

                    $syncStatus = $this->syncStatus($resource, $result['descriptions_changed'], $apply);
                    $stats['records'][] = $this->record(
                        resource: $resource,
                        legacyResourceId: $match['legacy_resource_id'],
                        matchMethod: $match['match_method'],
                        status: $result['descriptions_changed'] > 0
                            ? ($apply ? 'updated' : 'would_update')
                            : ($apply ? 'marked_unchanged' : 'unchanged'),
                        descriptionsScanned: $result['descriptions_scanned'],
                        descriptionsChanged: $result['descriptions_changed'],
                        breaksRemoved: $result['breaks_removed'],
                        dataCiteSyncStatus: $syncStatus,
                        message: $result['cache_invalidation_failed']
                            ? 'Landing-page cache invalidation failed; the repair remains applied and any eligible DataCite sync remains queued.'
                            : '',
                    );
                } catch (ConcurrentLegacyDescriptionChangeException $exception) {
                    $stats['concurrent_changes']++;
                    $stats['records'][] = $this->record(
                        resource: $resource,
                        legacyResourceId: $match['legacy_resource_id'],
                        matchMethod: $match['match_method'],
                        status: 'concurrent_change',
                        message: $exception->getMessage(),
                    );
                } catch (Throwable $exception) {
                    report($exception);
                    $stats['errors']++;
                    $stats['records'][] = $this->record(
                        resource: $resource,
                        legacyResourceId: $match['legacy_resource_id'],
                        matchMethod: $match['match_method'],
                        status: 'error',
                        message: $exception->getMessage(),
                    );
                    Log::warning('Legacy description break cleanup failed', [
                        'resource_id' => $resource->id,
                        'legacy_resource_id' => $match['legacy_resource_id'],
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $stats['sync_resource_ids'] = array_values(array_unique($stats['sync_resource_ids']));

        return $stats;
    }

    /**
     * @param  list<Resource>  $resources
     * @return array<string, list<int>>
     */
    private function legacyMatchesForUnlinkedResources(array $resources): array
    {
        $dois = [];

        foreach ($resources as $resource) {
            if ($resource->legacy_source === self::LEGACY_SOURCE || ! is_string($resource->doi)) {
                continue;
            }

            $normalized = $this->doiSuggestionService->normalizeDoi($resource->doi);
            if ($normalized !== '') {
                $dois[$normalized] = true;
            }
        }

        if ($dois === []) {
            return [];
        }

        $matches = [];
        foreach (OldDataset::query()
            ->whereIn(DB::raw('LOWER(identifier)'), array_keys($dois))
            ->orderBy('id')
            ->get(['id', 'identifier']) as $oldDataset) {
            $doi = $this->doiSuggestionService->normalizeDoi((string) $oldDataset->identifier);
            $matches[$doi][] = (int) $oldDataset->id;
        }

        return $matches;
    }

    /**
     * @param  array<string, list<int>>  $legacyMatches
     * @return array{status: 'matched'|'not_legacy'|'manual_review', legacy_resource_id: int|null, match_method: string}
     */
    private function resolveMatch(Resource $resource, array $legacyMatches): array
    {
        if ($resource->legacy_source === self::LEGACY_SOURCE) {
            return [
                'status' => 'matched',
                'legacy_resource_id' => $resource->legacy_source_id,
                'match_method' => 'legacy_source_id',
            ];
        }

        $doi = is_string($resource->doi)
            ? $this->doiSuggestionService->normalizeDoi($resource->doi)
            : '';
        $matches = $doi !== '' ? ($legacyMatches[$doi] ?? []) : [];

        if (count($matches) > 1) {
            return ['status' => 'manual_review', 'legacy_resource_id' => null, 'match_method' => 'doi'];
        }

        if ($matches === []) {
            return ['status' => 'not_legacy', 'legacy_resource_id' => null, 'match_method' => 'none'];
        }

        return [
            'status' => 'matched',
            'legacy_resource_id' => $matches[0],
            'match_method' => 'doi',
        ];
    }

    /** @return array{descriptions_scanned: int, descriptions_changed: int, breaks_removed: int, cache_invalidation_failed: bool} */
    private function processResource(Resource $resource, bool $apply): array
    {
        $updates = [];
        $breaksRemoved = 0;

        foreach ($resource->descriptions as $description) {
            $value = $this->normalizer->normalizeStoredValue((string) $description->value);
            $landingPageHtml = is_string($description->landing_page_html)
                ? $this->normalizer->normalizeHtml($description->landing_page_html)
                : ['value' => null, 'replacements' => 0];
            $breaksRemoved += $value['replacements'] + $landingPageHtml['replacements'];

            if ($value['value'] === $description->value && $landingPageHtml['value'] === $description->landing_page_html) {
                continue;
            }

            $updates[] = [
                'description' => $description,
                'original_value' => (string) $description->value,
                'original_html' => $description->landing_page_html,
                'value' => $value['value'],
                'landing_page_html' => $landingPageHtml['value'],
            ];
        }

        if (! $apply) {
            return [
                'descriptions_scanned' => $resource->descriptions->count(),
                'descriptions_changed' => count($updates),
                'breaks_removed' => $breaksRemoved,
                'cache_invalidation_failed' => false,
            ];
        }

        DB::transaction(function () use ($resource, $updates): void {
            foreach ($updates as $update) {
                $query = Description::query()
                    ->whereKey($update['description']->id)
                    ->where('value', $update['original_value']);

                if ($update['original_html'] === null) {
                    $query->whereNull('landing_page_html');
                } else {
                    $query->where('landing_page_html', $update['original_html']);
                }

                $updated = $query->update([
                    'value' => $update['value'],
                    'landing_page_html' => $update['landing_page_html'],
                ]);

                if ($updated !== 1) {
                    throw new ConcurrentLegacyDescriptionChangeException(
                        'A description changed concurrently; the resource was not modified.',
                    );
                }
            }

            $marked = DB::table($resource->getTable())
                ->where('id', $resource->id)
                ->whereNull('legacy_description_breaks_normalized_at')
                ->update(['legacy_description_breaks_normalized_at' => now()]);

            if ($marked !== 1) {
                throw new ConcurrentLegacyDescriptionChangeException(
                    'The resource was normalized concurrently; no duplicate pass was applied.',
                );
            }
        });

        $cacheInvalidationFailed = false;
        if ($updates !== []) {
            $landingPage = $resource->landingPage;
            if ($landingPage !== null && $landingPage->isPublished()) {
                $cacheInvalidationError = null;

                try {
                    $cacheInvalidationFailed = ! $this->landingPageCache->forgetById((int) $landingPage->id);

                    if ($cacheInvalidationFailed) {
                        $cacheInvalidationError = 'The cache store returned false.';
                    }
                } catch (Throwable $exception) {
                    $cacheInvalidationFailed = true;
                    $cacheInvalidationError = $exception->getMessage();
                }

                if ($cacheInvalidationFailed) {
                    Log::warning('Legacy description repair remains applied despite landing-page cache invalidation failure', [
                        'resource_id' => $resource->id,
                        'landing_page_id' => $landingPage->id,
                        'error' => $cacheInvalidationError,
                    ]);
                }
            }
        }

        return [
            'descriptions_scanned' => $resource->descriptions->count(),
            'descriptions_changed' => count($updates),
            'breaks_removed' => $breaksRemoved,
            'cache_invalidation_failed' => $cacheInvalidationFailed,
        ];
    }

    private function syncStatus(Resource $resource, int $descriptionsChanged, bool $apply): string
    {
        if ($descriptionsChanged === 0 || trim((string) $resource->doi) === '') {
            return 'not_required';
        }

        if (! $apply) {
            return 'would_queue';
        }

        return config('datacite.test_mode') === false ? 'queued' : 'skipped_test_mode';
    }

    /**
     * @param  list<string>  $dois
     * @return list<string>
     */
    private function normalizeDoiFilter(array $dois): array
    {
        $normalized = [];
        foreach ($dois as $doi) {
            $value = $this->doiSuggestionService->normalizeDoi($doi);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }

    private function preflightLegacyConnection(): void
    {
        OldDataset::query()->limit(1)->get(['id']);
    }

    /**
     * @return array{resources_scanned: int, legacy_resources: int, descriptions_scanned: int, changed: int, unchanged: int, not_legacy: int, manual_review: int, concurrent_changes: int, cache_invalidation_failures: int, errors: int, breaks_removed: int, last_scanned_resource_id: int|null, sync_resource_ids: list<int>, records: list<array{resource_id: int, doi: string, legacy_resource_id: int|null, match_method: string, status: string, descriptions_scanned: int, descriptions_changed: int, breaks_removed: int, datacite_sync_status: string, message: string}>}
     */
    private function emptyStats(): array
    {
        return [
            'resources_scanned' => 0,
            'legacy_resources' => 0,
            'descriptions_scanned' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'not_legacy' => 0,
            'manual_review' => 0,
            'concurrent_changes' => 0,
            'cache_invalidation_failures' => 0,
            'errors' => 0,
            'breaks_removed' => 0,
            'last_scanned_resource_id' => null,
            'sync_resource_ids' => [],
            'records' => [],
        ];
    }

    /**
     * @return array{resource_id: int, doi: string, legacy_resource_id: int|null, match_method: string, status: string, descriptions_scanned: int, descriptions_changed: int, breaks_removed: int, datacite_sync_status: string, message: string}
     */
    private function record(
        Resource $resource,
        ?int $legacyResourceId,
        string $matchMethod,
        string $status,
        int $descriptionsScanned = 0,
        int $descriptionsChanged = 0,
        int $breaksRemoved = 0,
        string $dataCiteSyncStatus = 'not_required',
        string $message = '',
    ): array {
        return [
            'resource_id' => (int) $resource->id,
            'doi' => (string) $resource->doi,
            'legacy_resource_id' => $legacyResourceId,
            'match_method' => $matchMethod,
            'status' => $status,
            'descriptions_scanned' => $descriptionsScanned,
            'descriptions_changed' => $descriptionsChanged,
            'breaks_removed' => $breaksRemoved,
            'datacite_sync_status' => $dataCiteSyncStatus,
            'message' => $message,
        ];
    }
}
