<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Affiliation;
use App\Models\DismissedRor;
use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Models\Institution;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\SuggestedRor;
use App\Models\User;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for discovering missing ROR identifiers for affiliations,
 * institutions, and funders via the ROR API v2.
 */
class RorDiscoveryService
{
    use ChecksCacheTagging;

    /**
     * Minimum similarity score (0.0–1.0) to include a ROR suggestion.
     */
    private const MIN_SIMILARITY = 0.5;

    /**
     * Maximum ROR API results to consider per entity.
     */
    private const ROR_SEARCH_LIMIT = 5;

    /**
     * Chunk size for processing entities in batches.
     */
    private const CHUNK_SIZE = 50;

    /**
     * Minimum delay between ROR API requests in milliseconds (~1 req/sec).
     */
    private const RATE_LIMIT_MS = 1000;

    /**
     * Cache key for rate-limiting ROR API calls.
     */
    private const RATE_LIMIT_KEY = 'ror_api_last_request';

    public function __construct(
        private readonly DataCiteSyncService $dataCiteSyncService,
    ) {}

    /**
     * Discover missing ROR-IDs for all entities across resources with registered DOIs.
     *
     * Processes entities in chunks to keep memory usage constant regardless of dataset size.
     * Dismissed/suggested sets are loaded per chunk, not for the entire dataset.
     *
     * @param  callable(int $processed, int $total): void|null  $progressCallback
     * @return int Number of newly discovered suggestions
     */
    public function discoverAll(?callable $progressCallback = null): int
    {
        $total = $this->countEntitiesWithoutRor();

        if ($total === 0) {
            Log::info('ROR discovery: No entities without ROR-ID found.');

            return 0;
        }

        $processed = 0;
        $newCount = 0;

        foreach ($this->yieldEntityChunks() as $chunk) {
            $dismissedSet = $this->loadDismissedSet($chunk);
            $suggestedSet = $this->loadSuggestedSet($chunk);

            foreach ($chunk as $entity) {
                $processed++;

                $key = "{$entity['entity_type']}:{$entity['entity_id']}";

                $dismissed = $dismissedSet[$key] ?? [];
                $suggested = $suggestedSet[$key] ?? [];

                $newCount += $this->processEntity($entity, $dismissed, $suggested);

                if ($progressCallback !== null) {
                    $progressCallback($processed, $total);
                }
            }
        }

        Log::info('ROR discovery completed', [
            'total_entities' => $total,
            'new_suggestions' => $newCount,
        ]);

        if ($newCount > 0) {
            $this->forgetCacheKey(CacheKey::SUGGESTED_RORS_COUNT);
        }

        return $newCount;
    }

    /**
     * Accept a ROR suggestion: updates the entity and syncs affected resources.
     *
     * Uses a database transaction with row-level locking to prevent race conditions
     * when multiple curators accept suggestions concurrently.
     *
     * @return array{success: bool, synced_dois: array<int, string>, message: string, replaced_identifier: string|null}
     */
    public function acceptRor(SuggestedRor $suggestion): array
    {
        $entityType = $suggestion->entity_type;
        $entityId = $suggestion->entity_id;

        if (! in_array($entityType, ['affiliation', 'institution', 'funder'], true)) {
            return [
                'success' => false,
                'synced_dois' => [],
                'message' => "Unknown entity type: {$entityType}",
                'replaced_identifier' => null,
            ];
        }

        $replacedIdentifier = null;

        try {
            /** @var array{success: bool, synced_dois: array<int, string>, message: string, replaced_identifier: string|null}|null $result */
            $result = DB::transaction(function () use ($suggestion, $entityType, $entityId, &$replacedIdentifier): ?array {
                // Lock the target entity row to prevent concurrent acceptance
                $entity = match ($entityType) {
                    'affiliation' => Affiliation::lockForUpdate()->find($entityId),
                    'institution' => Institution::lockForUpdate()->find($entityId),
                    default => FundingReference::lockForUpdate()->find($entityId),
                };

                if ($entity === null) {
                    $suggestion->delete();
                    $this->forgetCacheKey(CacheKey::SUGGESTED_RORS_COUNT);

                    return [
                        'success' => false,
                        'synced_dois' => [],
                        'message' => 'Entity not found. The suggestion has been removed.',
                        'replaced_identifier' => null,
                    ];
                }

                // Guard: entity already has a ROR-ID assigned (stale suggestion)
                $alreadyHasRor = match (true) {
                    $entity instanceof Affiliation => $entity->identifier_scheme === 'ROR'
                        && $entity->identifier !== null && $entity->identifier !== '',
                    $entity instanceof Institution => $entity->name_identifier_scheme === 'ROR'
                        && $entity->name_identifier !== null && $entity->name_identifier !== '',
                    default => $this->funderHasRor($entity),
                };

                if ($alreadyHasRor) {
                    SuggestedRor::where('entity_type', $entityType)
                        ->where('entity_id', $entityId)
                        ->delete();
                    $this->forgetCacheKey(CacheKey::SUGGESTED_RORS_COUNT);

                    return [
                        'success' => false,
                        'synced_dois' => [],
                        'message' => 'This entity already has a ROR-ID assigned. The suggestion has been removed.',
                        'replaced_identifier' => null,
                    ];
                }

                // Update the entity with the accepted ROR-ID
                $accepted = match (true) {
                    $entity instanceof Affiliation => $this->acceptAffiliation($entity, $suggestion, $replacedIdentifier),
                    $entity instanceof Institution => $this->acceptInstitution($entity, $suggestion, $replacedIdentifier),
                    default => $this->acceptFunder($entity, $suggestion, $replacedIdentifier),
                };

                if ($accepted === false) {
                    return [
                        'success' => false,
                        'synced_dois' => [],
                        'message' => 'Failed to update entity.',
                        'replaced_identifier' => null,
                    ];
                }

                // Delete ALL suggestions for this entity (it now has a ROR-ID)
                SuggestedRor::where('entity_type', $entityType)
                    ->where('entity_id', $entityId)
                    ->delete();

                // Return null to signal success — DataCite sync happens outside the transaction
                return null;
            });
        } catch (QueryException $e) {
            // Only handle unique constraint violations (concurrent acceptance race)
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }

            SuggestedRor::where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->delete();
            $this->forgetCacheKey(CacheKey::SUGGESTED_RORS_COUNT);

            return [
                'success' => false,
                'synced_dois' => [],
                'message' => 'This ROR-ID was just assigned by another curator. The suggestion has been removed.',
                'replaced_identifier' => null,
            ];
        }

        // Early-return for guard failures (result is an array)
        if ($result !== null) {
            return $result;
        }

        // Sync affected resources with DataCite (outside transaction)
        $syncedDois = $this->syncResourcesForEntity($suggestion);
        $this->forgetCacheKey(CacheKey::SUGGESTED_RORS_COUNT);

        $syncCount = count($syncedDois);
        $message = $syncCount > 0
            ? "ROR-ID accepted. {$syncCount} resource(s) synced with DataCite."
            : 'ROR-ID accepted. No resources required DataCite sync.';

        return [
            'success' => true,
            'synced_dois' => $syncedDois,
            'message' => $message,
            'replaced_identifier' => $replacedIdentifier,
        ];
    }

    /**
     * Decline a ROR suggestion: stores dismissal and removes matching suggestions.
     */
    public function declineRor(SuggestedRor $suggestion, User $user, ?string $reason = null): void
    {
        DismissedRor::firstOrCreate(
            [
                'entity_type' => $suggestion->entity_type,
                'entity_id' => $suggestion->entity_id,
                'ror_id' => $suggestion->suggested_ror_id,
            ],
            [
                'dismissed_by' => $user->id,
                'reason' => $reason,
            ],
        );

        // Delete all matching suggestions for this entity + ROR-ID
        SuggestedRor::where('entity_type', $suggestion->entity_type)
            ->where('entity_id', $suggestion->entity_id)
            ->where('suggested_ror_id', $suggestion->suggested_ror_id)
            ->delete();

        $this->forgetCacheKey(CacheKey::SUGGESTED_RORS_COUNT);
    }

    /**
     * Build the base affiliation query for entities without a ROR identifier.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Affiliation>
     */
    private function buildAffiliationWithoutRorQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Affiliation::where(function ($q): void {
            $q->whereNull('identifier_scheme')
                ->orWhere('identifier_scheme', '!=', 'ROR')
                ->orWhereNull('identifier')
                ->orWhere('identifier', '');
        })
            ->where('name', '!=', '')
            ->whereNotNull('name')
            ->whereHasMorph(
                'affiliatable',
                [ResourceCreator::class, ResourceContributor::class],
                fn ($q) => $q->whereHas('resource', fn ($r) => $r->whereNotNull('doi')->where('doi', '!=', '')),
            );
    }

    /**
     * Build the base institution query for entities without a ROR identifier.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Institution>
     */
    private function buildInstitutionWithoutRorQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Institution::where(function ($q): void {
            $q->whereNull('name_identifier_scheme')
                ->orWhere('name_identifier_scheme', '!=', 'ROR')
                ->orWhereNull('name_identifier')
                ->orWhere('name_identifier', '');
        })
            ->where('name', '!=', '')
            ->whereNotNull('name')
            ->where(function ($q): void {
                $q->whereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('resource_creators')
                        ->whereColumn('resource_creators.creatorable_id', 'institutions.id')
                        ->where('resource_creators.creatorable_type', Institution::class)
                        ->whereExists(function ($r): void {
                            $r->selectRaw('1')
                                ->from('resources')
                                ->whereColumn('resources.id', 'resource_creators.resource_id')
                                ->whereNotNull('resources.doi')
                                ->where('resources.doi', '!=', '');
                        });
                })->orWhereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('resource_contributors')
                        ->whereColumn('resource_contributors.contributorable_id', 'institutions.id')
                        ->where('resource_contributors.contributorable_type', Institution::class)
                        ->whereExists(function ($r): void {
                            $r->selectRaw('1')
                                ->from('resources')
                                ->whereColumn('resources.id', 'resource_contributors.resource_id')
                                ->whereNotNull('resources.doi')
                                ->where('resources.doi', '!=', '');
                        });
                });
            });
    }

    /**
     * Build the base funder query for entities without a ROR identifier.
     *
     * @return \Illuminate\Database\Eloquent\Builder<FundingReference>
     */
    private function buildFunderWithoutRorQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $hasDoi = fn ($q) => $q->whereNotNull('doi')->where('doi', '!=', '');
        $rorTypeId = FunderIdentifierType::where('slug', 'ROR')->value('id');

        $funderQuery = FundingReference::whereHas('resource', $hasDoi)
            ->where('funder_name', '!=', '')
            ->whereNotNull('funder_name');

        if ($rorTypeId !== null) {
            $funderQuery->where(function ($q) use ($rorTypeId): void {
                $q->whereNull('funder_identifier')
                    ->orWhere('funder_identifier', '')
                    ->orWhereNull('funder_identifier_type_id')
                    ->orWhere('funder_identifier_type_id', '!=', $rorTypeId);
            });
        } else {
            $funderQuery->where(function ($q): void {
                $q->whereNull('funder_identifier')
                    ->orWhere('funder_identifier', '');
            });
        }

        return $funderQuery;
    }

    /**
     * Count total entities without ROR-ID (lightweight SQL-only, no data loaded).
     */
    private function countEntitiesWithoutRor(): int
    {
        return $this->buildAffiliationWithoutRorQuery()->count()
            + $this->buildInstitutionWithoutRorQuery()->count()
            + $this->buildFunderWithoutRorQuery()->count();
    }

    /**
     * Yield entity chunks for streaming discovery processing.
     *
     * Uses lazyById() for true streaming – entities are emitted in CHUNK_SIZE
     * batches as they are read, so memory stays bounded to one chunk at a time.
     *
     * @return \Generator<int, array<int, array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}>>
     */
    private function yieldEntityChunks(): \Generator
    {
        $hasDoi = fn ($q) => $q->whereNotNull('doi')->where('doi', '!=', '');
        $pendingChunk = [];

        // 1. Affiliations – lazyById streams one row at a time
        // 1. Affiliations – collect into DB batches, resolve resource_id in bulk to avoid N+1
        $affiliationBatch = [];

        foreach ($this->buildAffiliationWithoutRorQuery()->lazyById(500) as $affiliation) {
            /** @var Affiliation $affiliation */
            $affiliationBatch[] = $affiliation;

            if (count($affiliationBatch) >= 500) {
                yield from $this->resolveAffiliationBatch($affiliationBatch, $pendingChunk);
                $affiliationBatch = [];
            }
        }

        if (! empty($affiliationBatch)) {
            yield from $this->resolveAffiliationBatch($affiliationBatch, $pendingChunk);
        }

        if (! empty($pendingChunk)) {
            yield $pendingChunk;
            $pendingChunk = [];
        }

        // 2. Institutions – lazyById with per-batch resource-ID resolution
        //    Collect institution rows into a DB batch, resolve resource maps, then emit processing chunks.
        $institutionBatch = [];

        foreach ($this->buildInstitutionWithoutRorQuery()->lazyById(500) as $institution) {
            /** @var Institution $institution */
            $institutionBatch[] = $institution;

            if (count($institutionBatch) >= 500) {
                yield from $this->resolveInstitutionBatch($institutionBatch, $hasDoi, $pendingChunk);
                $institutionBatch = [];
            }
        }

        if (! empty($institutionBatch)) {
            yield from $this->resolveInstitutionBatch($institutionBatch, $hasDoi, $pendingChunk);
        }

        if (! empty($pendingChunk)) {
            yield $pendingChunk;
            $pendingChunk = [];
        }

        // 3. Funders – lazyById with eager-load per row via relation access
        foreach ($this->buildFunderWithoutRorQuery()->with('funderIdentifierType')->lazyById(500) as $funder) {
            /** @var FundingReference $funder */
            $pendingChunk[] = [
                'entity_type' => 'funder',
                'entity_id' => $funder->id,
                'entity_name' => $funder->funder_name,
                'resource_id' => $funder->resource_id,
                'existing_identifier' => $funder->funder_identifier,
                'existing_identifier_type' => $funder->funderIdentifierType?->name,
            ];

            if (count($pendingChunk) >= self::CHUNK_SIZE) {
                yield $pendingChunk;
                $pendingChunk = [];
            }
        }

        if (! empty($pendingChunk)) {
            yield $pendingChunk;
        }
    }

    /**
     * Resolve a batch of affiliations into entity arrays, yielding full chunks immediately.
     *
     * Resolves resource_id in bulk via the polymorphic type/id to avoid N+1 queries.
     *
     * @param  array<int, Affiliation>  $batch
     * @param  array<int, array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}>  &$pendingChunk
     * @param-out array<int, array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}>  $pendingChunk
     * @return \Generator<int, array<int, array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}>>
     */
    private function resolveAffiliationBatch(array $batch, array &$pendingChunk): \Generator
    {
        // Group affiliatable IDs by morph type for bulk resolution
        $byType = [];
        foreach ($batch as $affiliation) {
            $byType[$affiliation->affiliatable_type][] = $affiliation->affiliatable_id;
        }

        // Resolve resource_id maps: affiliatable_id → resource_id
        $resourceMaps = [];
        if (isset($byType[ResourceCreator::class])) {
            $resourceMaps[ResourceCreator::class] = ResourceCreator::whereIn('id', $byType[ResourceCreator::class])
                ->pluck('resource_id', 'id');
        }
        if (isset($byType[ResourceContributor::class])) {
            $resourceMaps[ResourceContributor::class] = ResourceContributor::whereIn('id', $byType[ResourceContributor::class])
                ->pluck('resource_id', 'id');
        }

        foreach ($batch as $affiliation) {
            $resourceId = ($resourceMaps[$affiliation->affiliatable_type] ?? collect())->get($affiliation->affiliatable_id);

            if ($resourceId === null) {
                continue;
            }

            $pendingChunk[] = [
                'entity_type' => 'affiliation',
                'entity_id' => $affiliation->id,
                'entity_name' => $affiliation->name,
                'resource_id' => (int) $resourceId,
                'existing_identifier' => $affiliation->identifier,
                'existing_identifier_type' => $affiliation->identifier_scheme,
            ];

            if (count($pendingChunk) >= self::CHUNK_SIZE) {
                yield $pendingChunk;
                $pendingChunk = [];
            }
        }
    }

    /**
     * Resolve a batch of institutions into entity arrays, yielding full chunks immediately.
     *
     * @param  array<int, Institution>  $batch
     * @param  \Closure  $hasDoi
     * @param  array<int, array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}>  &$pendingChunk
     * @param-out array<int, array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}>  $pendingChunk
     * @return \Generator<int, array<int, array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}>>
     */
    private function resolveInstitutionBatch(array $batch, \Closure $hasDoi, array &$pendingChunk): \Generator
    {
        $batchIds = array_map(fn (Institution $i) => $i->id, $batch);

        $creatorResourceMap = ResourceCreator::where('creatorable_type', Institution::class)
            ->whereIn('creatorable_id', $batchIds)
            ->whereHas('resource', $hasDoi)
            ->pluck('resource_id', 'creatorable_id');

        $contributorResourceMap = ResourceContributor::where('contributorable_type', Institution::class)
            ->whereIn('contributorable_id', $batchIds)
            ->whereHas('resource', $hasDoi)
            ->pluck('resource_id', 'contributorable_id');

        foreach ($batch as $institution) {
            $resourceId = $creatorResourceMap->get($institution->id)
                ?? $contributorResourceMap->get($institution->id);

            if ($resourceId !== null) {
                $pendingChunk[] = [
                    'entity_type' => 'institution',
                    'entity_id' => $institution->id,
                    'entity_name' => $institution->name,
                    'resource_id' => (int) $resourceId,
                    'existing_identifier' => $institution->name_identifier,
                    'existing_identifier_type' => $institution->name_identifier_scheme,
                ];

                if (count($pendingChunk) >= self::CHUNK_SIZE) {
                    yield $pendingChunk;
                    $pendingChunk = [];
                }
            }
        }
    }

    /**
     * Process a single entity: search ROR API and store the best matching suggestion.
     *
     * @param  array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}  $entity
     * @param  array<string, true>  $dismissed
     * @param  array<string, true>  $suggested
     */
    private function processEntity(array $entity, array $dismissed, array $suggested): int
    {
        $candidates = $this->searchRorApi($entity['entity_name']);

        if (empty($candidates)) {
            return 0;
        }

        $newCount = 0;

        // Score and filter candidates
        $scored = [];
        foreach ($candidates as $candidate) {
            $rorId = $candidate['ror_id'];

            if (isset($dismissed[$rorId]) || isset($suggested[$rorId])) {
                continue;
            }

            $similarity = $this->computeNameSimilarity($entity['entity_name'], $candidate['names']);

            if ($similarity >= self::MIN_SIMILARITY) {
                $scored[] = ['candidate' => $candidate, 'similarity' => $similarity];
            }
        }

        // Sort by similarity descending, store only the best match
        usort($scored, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);

        if (! empty($scored)) {
            $best = $scored[0];
            $candidate = $best['candidate'];

            $suggestion = SuggestedRor::firstOrCreate(
                [
                    'entity_type' => $entity['entity_type'],
                    'entity_id' => $entity['entity_id'],
                    'suggested_ror_id' => $candidate['ror_id'],
                ],
                [
                    'resource_id' => $entity['resource_id'],
                    'entity_name' => $entity['entity_name'],
                    'suggested_name' => $candidate['name'],
                    'similarity_score' => $best['similarity'],
                    'ror_aliases' => $candidate['aliases'],
                    'existing_identifier' => $entity['existing_identifier'],
                    'existing_identifier_type' => $entity['existing_identifier_type'],
                    'discovered_at' => now(),
                ],
            );

            if ($suggestion->wasRecentlyCreated) {
                $newCount++;
            }
        }

        return $newCount;
    }

    /**
     * Search the ROR API v2 for organizations matching a name.
     *
     * @return array<int, array{ror_id: string, name: string, names: array<int, string>, aliases: array<int, string>}>
     */
    private function searchRorApi(string $query): array
    {
        $this->respectRateLimit();

        try {
            $response = Http::timeout(15)
                ->get('https://api.ror.org/v2/organizations', [
                    'query' => $query,
                ]);

            if (! $response->successful()) {
                Log::debug('ROR API request failed', [
                    'query' => $query,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();

            if (! is_array($data) || ! isset($data['items']) || ! is_array($data['items'])) {
                return [];
            }

            $results = [];

            foreach (array_slice($data['items'], 0, self::ROR_SEARCH_LIMIT) as $item) {
                if (! is_array($item) || ! isset($item['id'])) {
                    continue;
                }

                $names = $this->extractRorNames($item);
                $primaryName = $names[0] ?? '';

                if ($primaryName === '') {
                    continue;
                }

                $results[] = [
                    'ror_id' => $item['id'],
                    'name' => $primaryName,
                    'names' => $names,
                    'aliases' => array_slice($names, 1),
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            Log::debug('ROR API request exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Extract all name variants from a ROR API v2 organization item.
     *
     * @param  array<string, mixed>  $item
     * @return array<int, string>
     */
    private function extractRorNames(array $item): array
    {
        $names = [];

        if (isset($item['names']) && is_array($item['names'])) {
            // Prefer ror_display name first, then other types
            $displayNames = [];
            $otherNames = [];

            foreach ($item['names'] as $nameEntry) {
                if (! is_array($nameEntry) || ! isset($nameEntry['value']) || ! is_string($nameEntry['value'])) {
                    continue;
                }

                $types = $nameEntry['types'] ?? [];

                if (is_array($types) && in_array('ror_display', $types, true)) {
                    $displayNames[] = $nameEntry['value'];
                } else {
                    $otherNames[] = $nameEntry['value'];
                }
            }

            $names = [...$displayNames, ...$otherNames];
        }

        return array_values(array_unique($names));
    }

    /**
     * Compute name similarity between an entity name and ROR organization names.
     *
     * Compares against all name variants (primary + aliases) and returns the highest score.
     *
     * @param  array<int, string>  $rorNames
     */
    private function computeNameSimilarity(string $entityName, array $rorNames): float
    {
        $normalizedEntity = mb_strtolower(trim($entityName));

        if ($normalizedEntity === '') {
            return 0.0;
        }

        $bestScore = 0.0;

        foreach ($rorNames as $rorName) {
            $normalizedRor = mb_strtolower(trim($rorName));

            if ($normalizedRor === '') {
                continue;
            }

            // Exact match
            if ($normalizedEntity === $normalizedRor) {
                return 1.0;
            }

            similar_text($normalizedEntity, $normalizedRor, $percent);
            $bestScore = max($bestScore, $percent / 100.0);
        }

        return $bestScore;
    }

    /**
     * Enforce rate limiting for ROR API calls (~1 request per second).
     */
    private function respectRateLimit(): void
    {
        $lastRequest = (int) Cache::get(self::RATE_LIMIT_KEY, 0);
        $now = (int) (microtime(true) * 1000);
        $elapsed = $now - $lastRequest;

        if ($elapsed < self::RATE_LIMIT_MS) {
            usleep(($elapsed > 0 ? self::RATE_LIMIT_MS - $elapsed : self::RATE_LIMIT_MS) * 1000);
        }

        Cache::put(self::RATE_LIMIT_KEY, (int) (microtime(true) * 1000), 60);
    }

    /**
     * Accept an affiliation ROR suggestion.
     */
    private function acceptAffiliation(
        Affiliation|Institution|FundingReference $entity,
        SuggestedRor $suggestion,
        ?string &$replacedIdentifier,
    ): bool {
        if (! $entity instanceof Affiliation) {
            return false;
        }

        $replacedIdentifier = $entity->identifier;

        return $entity->update([
            'identifier' => $suggestion->suggested_ror_id,
            'identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org/',
        ]);
    }

    /**
     * Accept an institution ROR suggestion.
     */
    private function acceptInstitution(
        Affiliation|Institution|FundingReference $entity,
        SuggestedRor $suggestion,
        ?string &$replacedIdentifier,
    ): bool {
        if (! $entity instanceof Institution) {
            return false;
        }

        $replacedIdentifier = $entity->name_identifier;

        return $entity->update([
            'name_identifier' => $suggestion->suggested_ror_id,
            'name_identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org/',
        ]);
    }

    /**
     * Accept a funder ROR suggestion.
     */
    private function acceptFunder(
        Affiliation|Institution|FundingReference $entity,
        SuggestedRor $suggestion,
        ?string &$replacedIdentifier,
    ): bool {
        if (! $entity instanceof FundingReference) {
            return false;
        }

        $rorTypeId = FunderIdentifierType::where('slug', 'ROR')->value('id');

        if ($rorTypeId === null) {
            Log::error('ROR FunderIdentifierType not found – cannot accept funder ROR suggestion');

            return false;
        }

        $replacedIdentifier = $entity->funder_identifier;

        return $entity->update([
            'funder_identifier' => $suggestion->suggested_ror_id,
            'funder_identifier_type_id' => $rorTypeId,
            'scheme_uri' => 'https://ror.org/',
        ]);
    }

    /**
     * Sync resources affected by an entity update with DataCite.
     *
     * @return array<int, string>
     */
    private function syncResourcesForEntity(SuggestedRor $suggestion): array
    {
        $resourceIds = $this->getResourceIdsForEntity($suggestion);

        $syncedDois = [];

        $resources = Resource::whereIn('id', $resourceIds)
            ->whereNotNull('doi')
            ->where('doi', '!=', '')
            ->get();

        foreach ($resources as $resource) {
            $result = $this->dataCiteSyncService->syncIfRegistered($resource);
            if ($result->success && $resource->doi !== null) {
                $syncedDois[] = $resource->doi;
            }
        }

        return $syncedDois;
    }

    /**
     * Get all resource IDs affected by an entity.
     *
     * @return array<int, int>
     */
    private function getResourceIdsForEntity(SuggestedRor $suggestion): array
    {
        return match ($suggestion->entity_type) {
            'affiliation' => $this->getResourceIdsForAffiliationEntity($suggestion->entity_id),
            'institution' => $this->getResourceIdsForInstitutionEntity($suggestion->entity_id),
            'funder' => [$suggestion->resource_id],
            default => [],
        };
    }

    /**
     * Get resource IDs linked to an affiliation via creator/contributor.
     *
     * Queries the polymorphic target directly to handle orphaned rows safely.
     *
     * @return array<int, int>
     */
    private function getResourceIdsForAffiliationEntity(int $affiliationId): array
    {
        $affiliation = Affiliation::find($affiliationId);
        if ($affiliation === null) {
            return [];
        }

        $resourceId = match ($affiliation->affiliatable_type) {
            ResourceCreator::class => ResourceCreator::where('id', $affiliation->affiliatable_id)->value('resource_id'),
            ResourceContributor::class => ResourceContributor::where('id', $affiliation->affiliatable_id)->value('resource_id'),
            default => null,
        };

        return $resourceId !== null ? [$resourceId] : [];
    }

    /**
     * Get resource IDs linked to an institution as creator or contributor.
     *
     * @return array<int, int>
     */
    private function getResourceIdsForInstitutionEntity(int $institutionId): array
    {
        $creatorIds = ResourceCreator::where('creatorable_type', Institution::class)
            ->where('creatorable_id', $institutionId)
            ->pluck('resource_id');

        $contributorIds = ResourceContributor::where('contributorable_type', Institution::class)
            ->where('contributorable_id', $institutionId)
            ->pluck('resource_id');

        return $creatorIds->merge($contributorIds)->unique()->values()->all();
    }

    /**
     * Check if a funding reference already has a ROR identifier assigned.
     */
    private function funderHasRor(FundingReference $funder): bool
    {
        if ($funder->funder_identifier === null || $funder->funder_identifier === '') {
            return false;
        }

        $rorTypeId = FunderIdentifierType::where('slug', 'ROR')->value('id');

        return $rorTypeId !== null && $funder->funder_identifier_type_id === $rorTypeId;
    }

    /**
     * Load dismissed ROR-IDs for a set of entities.
     *
     * @param  array<int, array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}>  $entities
     * @return array<string, array<string, true>>
     */
    private function loadDismissedSet(array $entities): array
    {
        // Group entity IDs by type for constrained query
        $byType = [];
        foreach ($entities as $e) {
            $byType[$e['entity_type']][] = $e['entity_id'];
        }

        $query = DismissedRor::query();
        $query->where(function ($q) use ($byType): void {
            foreach ($byType as $type => $ids) {
                $q->orWhere(function ($sub) use ($type, $ids): void {
                    $sub->where('entity_type', $type)->whereIn('entity_id', $ids);
                });
            }
        });

        $result = [];
        foreach ($query->get(['entity_type', 'entity_id', 'ror_id']) as $d) {
            $key = "{$d->entity_type}:{$d->entity_id}";
            $result[$key][$d->ror_id] = true;
        }

        return $result;
    }

    /**
     * Load already-suggested ROR-IDs for a set of entities.
     *
     * @param  array<int, array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}>  $entities
     * @return array<string, array<string, true>>
     */
    private function loadSuggestedSet(array $entities): array
    {
        // Group entity IDs by type for constrained query
        $byType = [];
        foreach ($entities as $e) {
            $byType[$e['entity_type']][] = $e['entity_id'];
        }

        $query = SuggestedRor::query();
        $query->where(function ($q) use ($byType): void {
            foreach ($byType as $type => $ids) {
                $q->orWhere(function ($sub) use ($type, $ids): void {
                    $sub->where('entity_type', $type)->whereIn('entity_id', $ids);
                });
            }
        });

        $result = [];
        foreach ($query->get(['entity_type', 'entity_id', 'suggested_ror_id']) as $s) {
            $key = "{$s->entity_type}:{$s->entity_id}";
            $result[$key][$s->suggested_ror_id] = true;
        }

        return $result;
    }

    /**
     * Forget a cache key, using tags if supported.
     */
    private function forgetCacheKey(CacheKey $cacheKey): void
    {
        $this->getCacheInstance($cacheKey->tags())->forget($cacheKey->key());
    }
}
