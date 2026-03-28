<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DismissedRelation;
use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\SuggestedRelation;
use App\Models\User;
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
        $resources = Resource::whereNotNull('doi')
            ->where('doi', '!=', '')
            ->get(['id', 'doi']);

        $total = $resources->count();
        $processed = 0;
        $newCount = 0;

        // Pre-fetch lookups
        $identifierTypeLookup = IdentifierType::pluck('id', 'slug')->all();
        $relationTypeLookup = RelationType::pluck('id', 'slug')->all();

        foreach ($resources as $resource) {
            /** @var string $doi */
            $doi = $resource->doi;

            $relations = $this->discoverForDoi($doi);
            $newCount += $this->storeNewSuggestions(
                $resource->id,
                $relations,
                $identifierTypeLookup,
                $relationTypeLookup,
            );

            $processed++;
            if ($progressCallback !== null) {
                $progressCallback($processed, $total);
            }
        }

        Log::info('Relation discovery completed', [
            'total_dois' => $total,
            'new_suggestions' => $newCount,
        ]);

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
     * Store new suggestions, filtering out existing and dismissed relations.
     *
     * @param  int  $resourceId
     * @param  array<int, array<string, mixed>>  $relations
     * @param  array<string, int>  $identifierTypeLookup
     * @param  array<string, int>  $relationTypeLookup
     * @return int Number of newly stored suggestions
     */
    private function storeNewSuggestions(
        int $resourceId,
        array $relations,
        array $identifierTypeLookup,
        array $relationTypeLookup,
    ): int {
        if (empty($relations)) {
            return 0;
        }

        // Get existing related identifiers for this resource
        $existingRelations = RelatedIdentifier::where('resource_id', $resourceId)
            ->get(['identifier', 'relation_type_id'])
            ->map(fn (RelatedIdentifier $ri) => mb_strtolower($ri->identifier) . '|' . $ri->relation_type_id)
            ->toArray();

        // Get dismissed relations for this resource
        $dismissedRelations = DismissedRelation::where('resource_id', $resourceId)
            ->get(['identifier', 'relation_type_id'])
            ->map(fn (DismissedRelation $dr) => mb_strtolower($dr->identifier) . '|' . $dr->relation_type_id)
            ->toArray();

        // Get already suggested relations for this resource
        $suggestedRelations = SuggestedRelation::where('resource_id', $resourceId)
            ->get(['identifier', 'relation_type_id'])
            ->map(fn (SuggestedRelation $sr) => mb_strtolower($sr->identifier) . '|' . $sr->relation_type_id)
            ->toArray();

        $knownKeys = array_merge($existingRelations, $dismissedRelations, $suggestedRelations);
        $knownSet = array_flip($knownKeys);

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

            SuggestedRelation::create([
                'resource_id' => $resourceId,
                'identifier' => $relation['identifier'],
                'identifier_type_id' => $identifierTypeId,
                'relation_type_id' => $relationTypeId,
                'source' => $relation['source'],
                'source_title' => $relation['source_title'] ?? null,
                'source_type' => $relation['source_type'] ?? null,
                'source_publisher' => $relation['source_publisher'] ?? null,
                'source_publication_date' => $relation['source_publication_date'] ?? null,
                'discovered_at' => now(),
            ]);

            // Add to known set to prevent duplicates within this batch
            $knownSet[$key] = true;
            $newCount++;
        }

        return $newCount;
    }

    /**
     * Accept a suggested relation: creates a RelatedIdentifier and syncs to DataCite.
     *
     * @return array{success: bool, datacite_synced: bool, message: string}
     */
    public function acceptRelation(SuggestedRelation $suggestion): array
    {
        $resource = $suggestion->resource;

        // Determine next position for the resource's related identifiers
        $maxPosition = RelatedIdentifier::where('resource_id', $resource->id)
            ->max('position') ?? -1;

        // Create the new RelatedIdentifier
        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => $suggestion->identifier,
            'identifier_type_id' => $suggestion->identifier_type_id,
            'relation_type_id' => $suggestion->relation_type_id,
            'position' => $maxPosition + 1,
        ]);

        // Delete the suggestion
        $suggestion->delete();

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
     */
    public function declineRelation(SuggestedRelation $suggestion, User $user, ?string $reason = null): void
    {
        DismissedRelation::create([
            'resource_id' => $suggestion->resource_id,
            'identifier' => $suggestion->identifier,
            'relation_type_id' => $suggestion->relation_type_id,
            'dismissed_by' => $user->id,
            'reason' => $reason,
        ]);

        $suggestion->delete();
    }
}
