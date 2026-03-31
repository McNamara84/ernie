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
use Illuminate\Support\Facades\Cache;
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
     * @param  callable(int $processed, int $total): void|null  $progressCallback
     * @return int Number of newly discovered suggestions
     */
    public function discoverAll(?callable $progressCallback = null): int
    {
        $entities = $this->collectEntitiesWithoutRor();

        if (empty($entities)) {
            Log::info('ROR discovery: No entities without ROR-ID found.');

            return 0;
        }

        $total = count($entities);
        $processed = 0;
        $newCount = 0;

        // Pre-load dismissed ROR-IDs for all entities
        $dismissedSet = $this->loadDismissedSet($entities);
        $suggestedSet = $this->loadSuggestedSet($entities);

        foreach (array_chunk($entities, self::CHUNK_SIZE) as $chunk) {
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
     * @return array{success: bool, synced_dois: array<int, string>, message: string, replaced_identifier: string|null}
     */
    public function acceptRor(SuggestedRor $suggestion): array
    {
        $entity = $suggestion->entity();

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

        $replacedIdentifier = null;

        match ($suggestion->entity_type) {
            'affiliation' => $this->acceptAffiliation($entity, $suggestion, $replacedIdentifier),
            'institution' => $this->acceptInstitution($entity, $suggestion, $replacedIdentifier),
            'funder' => $this->acceptFunder($entity, $suggestion, $replacedIdentifier),
            default => null,
        };

        // Delete ALL suggestions for this entity (it now has a ROR-ID)
        SuggestedRor::where('entity_type', $suggestion->entity_type)
            ->where('entity_id', $suggestion->entity_id)
            ->delete();

        $this->forgetCacheKey(CacheKey::SUGGESTED_RORS_COUNT);

        // Sync affected resources with DataCite
        $syncedDois = $this->syncResourcesForEntity($suggestion);

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
     * Collect all entities without ROR-ID across resources with registered DOIs.
     *
     * @return array<int, array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}>
     */
    private function collectEntitiesWithoutRor(): array
    {
        $hasDoi = fn ($q) => $q->whereNotNull('doi')->where('doi', '!=', '');
        $entities = [];

        // 1. Affiliations without ROR
        $affiliations = Affiliation::where(function ($q): void {
            $q->whereNull('identifier_scheme')
                ->orWhere('identifier_scheme', '!=', 'ROR')
                ->orWhereNull('identifier');
        })
            ->where('name', '!=', '')
            ->whereNotNull('name')
            ->get();

        foreach ($affiliations as $affiliation) {
            $resourceId = $this->getResourceIdForAffiliation($affiliation, $hasDoi);
            if ($resourceId !== null) {
                $entities[] = [
                    'entity_type' => 'affiliation',
                    'entity_id' => $affiliation->id,
                    'entity_name' => $affiliation->name,
                    'resource_id' => $resourceId,
                    'existing_identifier' => null,
                    'existing_identifier_type' => null,
                ];
            }
        }

        // 2. Institutions without ROR (as creators/contributors)
        $institutions = Institution::where(function ($q): void {
            $q->whereNull('name_identifier_scheme')
                ->orWhere('name_identifier_scheme', '!=', 'ROR')
                ->orWhereNull('name_identifier');
        })
            ->where('name', '!=', '')
            ->whereNotNull('name')
            ->get();

        foreach ($institutions as $institution) {
            $resourceId = $this->getResourceIdForInstitution($institution, $hasDoi);
            if ($resourceId !== null) {
                $entities[] = [
                    'entity_type' => 'institution',
                    'entity_id' => $institution->id,
                    'entity_name' => $institution->name,
                    'resource_id' => $resourceId,
                    'existing_identifier' => $institution->name_identifier,
                    'existing_identifier_type' => $institution->name_identifier_scheme,
                ];
            }
        }

        // 3. Funders without ROR or with non-ROR identifier
        $rorTypeId = FunderIdentifierType::where('slug', 'ROR')->value('id');

        $funderQuery = FundingReference::whereHas('resource', $hasDoi)
            ->where('funder_name', '!=', '')
            ->whereNotNull('funder_name');

        if ($rorTypeId !== null) {
            $funderQuery->where(function ($q) use ($rorTypeId): void {
                $q->whereNull('funder_identifier')
                    ->orWhere('funder_identifier_type_id', '!=', $rorTypeId);
            });
        } else {
            $funderQuery->whereNull('funder_identifier');
        }

        $funders = $funderQuery->with('funderIdentifierType')->get();

        foreach ($funders as $funder) {
            $entities[] = [
                'entity_type' => 'funder',
                'entity_id' => $funder->id,
                'entity_name' => $funder->funder_name,
                'resource_id' => $funder->resource_id,
                'existing_identifier' => $funder->funder_identifier,
                'existing_identifier_type' => $funder->funderIdentifierType?->name,
            ];
        }

        return $entities;
    }

    /**
     * Get the resource ID for an affiliation, if it belongs to a resource with a DOI.
     *
     * @param  callable  $hasDoi
     */
    private function getResourceIdForAffiliation(Affiliation $affiliation, callable $hasDoi): ?int
    {
        $affiliatable = $affiliation->affiliatable;

        if ($affiliatable instanceof ResourceCreator) {
            $resource = Resource::where('id', $affiliatable->resource_id)
                ->where(fn ($q) => $hasDoi($q))
                ->first();

            return $resource?->id;
        }

        /** @var ResourceContributor $affiliatable */
        $resource = Resource::where('id', $affiliatable->resource_id)
            ->where(fn ($q) => $hasDoi($q))
            ->first();

        return $resource?->id;
    }

    /**
     * Get the first resource ID for an institution used as creator/contributor on a DOI resource.
     *
     * @param  \Closure(\Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>): mixed  $hasDoi
     */
    private function getResourceIdForInstitution(Institution $institution, \Closure $hasDoi): ?int
    {
        $creatorResource = ResourceCreator::where('creatorable_type', Institution::class)
            ->where('creatorable_id', $institution->id)
            ->whereHas('resource', $hasDoi)
            ->value('resource_id');

        if ($creatorResource !== null) {
            return $creatorResource;
        }

        return ResourceContributor::where('contributorable_type', Institution::class)
            ->where('contributorable_id', $institution->id)
            ->whereHas('resource', $hasDoi)
            ->value('resource_id');
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
    ): void {
        if (! $entity instanceof Affiliation) {
            return;
        }

        $replacedIdentifier = $entity->identifier;

        $entity->update([
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
    ): void {
        if (! $entity instanceof Institution) {
            return;
        }

        $replacedIdentifier = $entity->name_identifier;

        $entity->update([
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
    ): void {
        if (! $entity instanceof FundingReference) {
            return;
        }

        $replacedIdentifier = $entity->funder_identifier;

        $rorTypeId = FunderIdentifierType::where('slug', 'ROR')->value('id');

        $entity->update([
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
     * @return array<int, int>
     */
    private function getResourceIdsForAffiliationEntity(int $affiliationId): array
    {
        $affiliation = Affiliation::find($affiliationId);
        if ($affiliation === null) {
            return [];
        }

        $affiliatable = $affiliation->affiliatable;

        return [$affiliatable->resource_id];
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
     * Load dismissed ROR-IDs for a set of entities.
     *
     * @param  array<int, array{entity_type: string, entity_id: int, entity_name: string, resource_id: int, existing_identifier: string|null, existing_identifier_type: string|null}>  $entities
     * @return array<string, array<string, true>>
     */
    private function loadDismissedSet(array $entities): array
    {
        $dismissed = DismissedRor::get(['entity_type', 'entity_id', 'ror_id']);

        $result = [];
        foreach ($dismissed as $d) {
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
        $suggested = SuggestedRor::get(['entity_type', 'entity_id', 'suggested_ror_id']);

        $result = [];
        foreach ($suggested as $s) {
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
