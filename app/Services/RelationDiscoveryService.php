<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\DismissedRelation;
use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\SuggestedRelation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator service for discovering new related works.
 *
 * Queries ScholExplorer (primary) and DataCite Event Data (supplementary)
 * to find scholarly links for registered DOIs. Deduplicates results,
 * filters out known/dismissed relations, and stores new suggestions.
 */
class RelationDiscoveryService
{
    public function __construct(
        private readonly ScholExplorerService $scholExplorerService,
        private readonly DataCiteEventDataService $dataCiteEventDataService,
        private readonly DataCiteSyncService $dataCiteSyncService,
    ) {}

    /**
     * Discover new relations for all resources with registered DOIs.
     *
     * @param  callable(int $processed, int $total): void|null  $progressCallback
     * @return int Number of newly discovered suggestions
     */
    public function discoverAll(?callable $progressCallback = null): int
    {
        $total = Resource::whereNotNull('doi')
            ->where('doi', '!=', '')
            ->count();

        $processed = 0;
        $newCount = 0;

        // Pre-fetch lookups
        $identifierTypeLookup = IdentifierType::pluck('id', 'slug')->all();
        $relationTypeLookup = RelationType::pluck('id', 'slug')->all();

        Resource::whereNotNull('doi')
            ->where('doi', '!=', '')
            ->select(['id', 'doi'])
            ->chunkById(100, function ($resources) use (
                $identifierTypeLookup,
                $relationTypeLookup,
                $total,
                &$processed,
                &$newCount,
                $progressCallback,
            ) {
                $resourceIds = $resources->pluck('id')->all();

                // Bulk-preload existing relations for this chunk
                $existingKeys = RelatedIdentifier::whereIn('resource_id', $resourceIds)
                    ->get(['resource_id', 'identifier', 'relation_type_id'])
                    ->groupBy('resource_id')
                    ->map(fn ($items) => $items->map(fn (RelatedIdentifier $ri) => mb_strtolower($ri->identifier) . '|' . $ri->relation_type_id)->all())
                    ->all();

                $dismissedKeys = DismissedRelation::whereIn('resource_id', $resourceIds)
                    ->get(['resource_id', 'identifier', 'relation_type_id'])
                    ->groupBy('resource_id')
                    ->map(fn ($items) => $items->map(fn (DismissedRelation $dr) => mb_strtolower($dr->identifier) . '|' . $dr->relation_type_id)->all())
                    ->all();

                $suggestedKeys = SuggestedRelation::whereIn('resource_id', $resourceIds)
                    ->get(['resource_id', 'identifier', 'relation_type_id'])
                    ->groupBy('resource_id')
                    ->map(fn ($items) => $items->map(fn (SuggestedRelation $sr) => mb_strtolower($sr->identifier) . '|' . $sr->relation_type_id)->all())
                    ->all();

                foreach ($resources as $resource) {
                    /** @var string $doi */
                    $doi = $resource->doi;
                    $resourceId = $resource->id;

                    $knownSet = array_flip(array_merge(
                        $existingKeys[$resourceId] ?? [],
                        $dismissedKeys[$resourceId] ?? [],
                        $suggestedKeys[$resourceId] ?? [],
                    ));

                    $relations = $this->discoverForDoi($doi);
                    $newCount += $this->storeNewSuggestions(
                        $resourceId,
                        $relations,
                        $identifierTypeLookup,
                        $relationTypeLookup,
                        $knownSet,
                    );

                    $processed++;
                    if ($progressCallback !== null) {
                        $progressCallback($processed, $total);
                    }
                }
            });

        Log::info('Relation discovery completed', [
            'total_dois' => $total,
            'new_suggestions' => $newCount,
        ]);

        if ($newCount > 0) {
            $this->invalidateAssistanceCache();
        }

        return $newCount;
    }

    /**
     * Discover relations for a single DOI from both sources.
     *
     * @return array<int, array{identifier: string, identifier_type: string, relation_type: string, source: string, source_title: string|null, source_type: string|null, source_publisher: string|null, source_publication_date: string|null}>
     */
    private function discoverForDoi(string $doi): array
    {
        $allRelations = [];
        $seen = [];

        // Primary source: ScholExplorer
        $scholexResults = $this->scholExplorerService->findRelationsForDoi($doi);
        foreach ($scholexResults as $relation) {
            $key = mb_strtolower($relation['identifier']) . '|' . $relation['relation_type'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $allRelations[] = [
                    ...$relation,
                    'source' => 'scholexplorer',
                ];
            }
        }

        // Supplementary source: DataCite Event Data
        $dataciteResults = $this->dataCiteEventDataService->findRelationsForDoi($doi);
        foreach ($dataciteResults as $relation) {
            $key = mb_strtolower($relation['identifier']) . '|' . $relation['relation_type'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $allRelations[] = [
                    ...$relation,
                    'source' => 'datacite_event_data',
                ];
            }
        }

        return $allRelations;
    }

    /**
     * Store new suggestions, filtering out known relations using pre-loaded set.
     *
     * @param  int  $resourceId
     * @param  array<int, array<string, mixed>>  $relations
     * @param  array<string, int>  $identifierTypeLookup
     * @param  array<string, int>  $relationTypeLookup
     * @param  array<string, true|int>  $knownSet  Pre-loaded set of known relation keys
     * @return int Number of newly stored suggestions
     */
    private function storeNewSuggestions(
        int $resourceId,
        array $relations,
        array $identifierTypeLookup,
        array $relationTypeLookup,
        array $knownSet,
    ): int {
        if (empty($relations)) {
            return 0;
        }

        $newCount = 0;

        foreach ($relations as $relation) {
            $relationTypeId = $relationTypeLookup[$relation['relation_type']] ?? null;
            $identifierTypeId = $identifierTypeLookup[$relation['identifier_type']] ?? null;

            if ($relationTypeId === null || $identifierTypeId === null) {
                Log::debug('Skipping relation with unknown type', [
                    'resource_id' => $resourceId,
                    'relation_type' => $relation['relation_type'],
                    'identifier_type' => $relation['identifier_type'],
                ]);

                continue;
            }

            $key = mb_strtolower((string) $relation['identifier']) . '|' . $relationTypeId;

            if (isset($knownSet[$key])) {
                continue;
            }

            $suggestion = SuggestedRelation::firstOrCreate(
                [
                    'resource_id' => $resourceId,
                    'identifier' => $relation['identifier'],
                    'relation_type_id' => $relationTypeId,
                ],
                [
                    'identifier_type_id' => $identifierTypeId,
                    'source' => $relation['source'],
                    'source_title' => $relation['source_title'] ?? null,
                    'source_type' => $relation['source_type'] ?? null,
                    'source_publisher' => $relation['source_publisher'] ?? null,
                    'source_publication_date' => $relation['source_publication_date'] ?? null,
                    'discovered_at' => now(),
                ],
            );

            // Add to known set to prevent duplicates within this batch
            $knownSet[$key] = true;

            // Only count if actually created (not pre-existing)
            if ($suggestion->wasRecentlyCreated) {
                $newCount++;
            }
        }

        return $newCount;
    }

    /**
     * Accept a suggested relation: creates a RelatedIdentifier and syncs to DataCite.
     *
     * Uses a DB transaction for atomicity and firstOrCreate for idempotency.
     *
     * @return array{success: bool, datacite_synced: bool, message: string}
     */
    public function acceptRelation(SuggestedRelation $suggestion): array
    {
        $resource = $suggestion->resource;

        DB::transaction(function () use ($suggestion, $resource) {
            // Lock existing rows to prevent concurrent inserts from reading the same max
            $maxPosition = RelatedIdentifier::where('resource_id', $resource->id)
                ->lockForUpdate()
                ->max('position') ?? -1;

            // Create the new RelatedIdentifier (idempotent via firstOrCreate)
            RelatedIdentifier::firstOrCreate(
                [
                    'resource_id' => $resource->id,
                    'identifier' => $suggestion->identifier,
                    'relation_type_id' => $suggestion->relation_type_id,
                ],
                [
                    'identifier_type_id' => $suggestion->identifier_type_id,
                    'position' => $maxPosition + 1,
                ],
            );

            // Delete the suggestion
            $suggestion->delete();
        });

        // Invalidate sidebar badge cache
        $this->invalidateAssistanceCache();

        // Sync to DataCite
        $syncResult = $this->dataCiteSyncService->syncIfRegistered($resource);

        if ($syncResult->success) {
            if ($syncResult->attempted) {
                return [
                    'success' => true,
                    'datacite_synced' => true,
                    'message' => 'Relation accepted and synced to DataCite.',
                ];
            }

            return [
                'success' => true,
                'datacite_synced' => false,
                'message' => 'Relation accepted. DataCite sync not required (no DOI registered).',
            ];
        }

        return [
            'success' => true,
            'datacite_synced' => false,
            'message' => 'Relation accepted but DataCite sync failed: ' . ($syncResult->errorMessage ?? 'Unknown error'),
        ];
    }

    /**
     * Decline a suggested relation: stores in dismissed_relations to prevent re-suggestion.
     *
     * Uses a DB transaction for atomicity and firstOrCreate for idempotency.
     */
    public function declineRelation(SuggestedRelation $suggestion, User $user, ?string $reason = null): void
    {
        DB::transaction(function () use ($suggestion, $user, $reason) {
            DismissedRelation::firstOrCreate(
                [
                    'resource_id' => $suggestion->resource_id,
                    'identifier' => $suggestion->identifier,
                    'relation_type_id' => $suggestion->relation_type_id,
                ],
                [
                    'dismissed_by' => $user->id,
                    'reason' => $reason,
                ],
            );

            $suggestion->delete();
        });

        $this->invalidateAssistanceCache();
    }

    /**
     * Invalidate the total pending count so the sidebar badge updates.
     */
    private function invalidateAssistanceCache(): void
    {
        Cache::forget(CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->key());
    }
}
