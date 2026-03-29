<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\DismissedOrcid;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\SuggestedOrcid;
use App\Models\User;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for discovering missing ORCID identifiers for creators and contributors.
 *
 * Queries the ORCID Public API for persons who lack an ORCID and computes
 * affiliation similarity scores to rank candidates.
 */
class OrcidDiscoveryService
{
    use ChecksCacheTagging;

    /**
     * Maximum ORCID candidates to suggest per person.
     */
    private const MAX_CANDIDATES_PER_PERSON = 3;

    /**
     * Maximum search results to request from ORCID API.
     */
    private const ORCID_SEARCH_LIMIT = 10;

    /**
     * Timestamp of the last ORCID API call (milliseconds).
     */
    private float $lastApiCallTime = 0;

    /**
     * Minimum delay between ORCID API calls in milliseconds.
     */
    private readonly int $rateLimitDelayMs;

    /**
     * Chunk size for processing persons in batches.
     */
    private const CHUNK_SIZE = 100;

    public function __construct(
        private readonly OrcidService $orcidService,
        private readonly DataCiteSyncService $dataCiteSyncService,
    ) {
        $this->rateLimitDelayMs = (int) config('services.orcid.rate_limit_delay_ms', 2100);
    }

    /**
     * Discover missing ORCIDs for all resources with registered DOIs.
     *
     * Processes persons in chunks to keep memory bounded, similar to
     * RelationDiscoveryService::discoverAll().
     *
     * @param  callable(int $processed, int $total): void|null  $progressCallback
     * @return int Number of newly discovered suggestions
     */
    public function discoverAll(?callable $progressCallback = null): int
    {
        // Gather all unique person IDs without ORCID across registered-DOI resources
        $personContexts = $this->collectPersonsWithoutOrcid();

        if (empty($personContexts)) {
            Log::info('ORCID discovery: No persons without ORCID found.');

            return 0;
        }

        // Group contexts by person_id for efficient processing
        $contextsByPerson = [];
        foreach ($personContexts as $ctx) {
            $contextsByPerson[$ctx['person_id']][] = $ctx;
        }

        $allPersonIds = array_keys($contextsByPerson);
        $total = count($allPersonIds);
        $processed = 0;
        $newCount = 0;

        // Process in chunks to keep memory bounded
        foreach (array_chunk($allPersonIds, self::CHUNK_SIZE) as $chunkPersonIds) {
            $newCount += $this->processPersonChunk(
                $chunkPersonIds,
                $contextsByPerson,
                $processed,
                $total,
                $progressCallback,
            );
        }

        Log::info('ORCID discovery completed', [
            'total_persons' => $total,
            'new_suggestions' => $newCount,
        ]);

        if ($newCount > 0) {
            $this->forgetCacheKey(CacheKey::SUGGESTED_ORCIDS_COUNT);
        }

        return $newCount;
    }

    /**
     * Process a chunk of person IDs for ORCID discovery.
     *
     * @param  array<int, int>  $chunkPersonIds
     * @param  array<int, array<int, array{person_id: int, resource_id: int, source_context: string}>>  $contextsByPerson
     * @param  callable(int $processed, int $total): void|null  $progressCallback
     */
    private function processPersonChunk(
        array $chunkPersonIds,
        array $contextsByPerson,
        int &$processed,
        int $total,
        ?callable $progressCallback,
    ): int {
        $newCount = 0;

        // Load data for this chunk only
        $dismissedSet = DismissedOrcid::whereIn('person_id', $chunkPersonIds)
            ->get(['person_id', 'orcid'])
            ->groupBy('person_id')
            ->map(fn ($items) => $items->pluck('orcid')->all())
            ->all();

        $suggestedSet = SuggestedOrcid::whereIn('person_id', $chunkPersonIds)
            ->get(['person_id', 'suggested_orcid'])
            ->groupBy('person_id')
            ->map(fn ($items) => $items->pluck('suggested_orcid')->all())
            ->all();

        $persons = Person::whereIn('id', $chunkPersonIds)
            ->get()
            ->keyBy('id');

        $personAffiliations = $this->loadPersonAffiliations($chunkPersonIds);

        foreach ($chunkPersonIds as $personId) {
            $person = $persons->get($personId);
            if ($person === null) {
                $processed++;

                continue;
            }

            $dismissed = array_flip($dismissedSet[$personId] ?? []);
            $suggested = array_flip($suggestedSet[$personId] ?? []);
            $contexts = $contextsByPerson[$personId] ?? [];
            $affiliations = $personAffiliations[$personId] ?? [];

            $candidates = $this->searchOrcidCandidates($person);

            foreach (array_slice($candidates, 0, self::MAX_CANDIDATES_PER_PERSON) as $candidate) {
                $orcid = $candidate['orcid'];

                // Skip if dismissed or already suggested
                if (isset($dismissed[$orcid]) || isset($suggested[$orcid])) {
                    continue;
                }

                // Check if ORCID is already assigned to any person (query instead of preload)
                if ($this->isOrcidAssigned($orcid)) {
                    continue;
                }

                $similarity = $this->computeAffiliationSimilarity(
                    $affiliations,
                    $candidate['institutions'],
                );

                // Store suggestion for each resource context where this person appears
                foreach ($contexts as $ctx) {
                    $suggestion = SuggestedOrcid::firstOrCreate(
                        [
                            'resource_id' => $ctx['resource_id'],
                            'person_id' => $personId,
                            'suggested_orcid' => $orcid,
                        ],
                        [
                            'similarity_score' => $similarity,
                            'candidate_first_name' => $candidate['firstName'],
                            'candidate_last_name' => $candidate['lastName'],
                            'candidate_affiliations' => $candidate['institutions'],
                            'source_context' => $ctx['source_context'],
                            'discovered_at' => now(),
                        ],
                    );

                    if ($suggestion->wasRecentlyCreated) {
                        $newCount++;
                    }
                }

                // Track as suggested to avoid duplicates within this run
                $suggested[$orcid] = true;
            }

            $processed++;
            if ($progressCallback !== null) {
                $progressCallback($processed, $total);
            }
        }

        return $newCount;
    }

    /**
     * Check if a bare ORCID is already assigned to any person.
     *
     * Uses a targeted query instead of preloading all ORCIDs into memory.
     */
    private function isOrcidAssigned(string $orcid): bool
    {
        return Person::whereNotNull('name_identifier')
            ->where('name_identifier', '!=', '')
            ->where('name_identifier_scheme', 'ORCID')
            ->where(function ($q) use ($orcid): void {
                $q->where('name_identifier', "https://orcid.org/{$orcid}")
                    ->orWhere('name_identifier', "http://orcid.org/{$orcid}")
                    ->orWhere('name_identifier', $orcid);
            })
            ->exists();
    }

    /**
     * Accept an ORCID suggestion: updates the Person record and syncs all affected resources.
     *
     * Guards against stale suggestions: if the person already has an ORCID assigned
     * (e.g., manually added after the suggestion was generated), the suggestion is
     * deleted and an error is returned instead of overwriting.
     *
     * @return array{success: bool, synced_dois: array<int, string>, message: string}
     */
    public function acceptOrcid(SuggestedOrcid $suggestion): array
    {
        $person = $suggestion->person->fresh();
        $orcid = $suggestion->suggested_orcid;

        // Guard: person already has an ORCID (stale suggestion)
        if ($person !== null
            && $person->name_identifier !== null
            && $person->name_identifier !== ''
            && $person->name_identifier_scheme === 'ORCID'
        ) {
            // Delete all stale suggestions for this person
            SuggestedOrcid::where('person_id', $suggestion->person_id)->delete();
            $this->forgetCacheKey(CacheKey::SUGGESTED_ORCIDS_COUNT);

            return [
                'success' => false,
                'synced_dois' => [],
                'message' => 'This person already has an ORCID assigned. The suggestion has been removed.',
            ];
        }

        // Guard: ORCID already assigned to a different person (targeted query)
        $existingPerson = Person::whereNotNull('name_identifier')
            ->where('name_identifier', '!=', '')
            ->where('name_identifier_scheme', 'ORCID')
            ->where('id', '!=', $suggestion->person_id)
            ->where(function ($q) use ($orcid): void {
                $q->where('name_identifier', "https://orcid.org/{$orcid}")
                    ->orWhere('name_identifier', "http://orcid.org/{$orcid}")
                    ->orWhere('name_identifier', $orcid);
            })
            ->first();

        if ($existingPerson !== null) {
            // Delete suggestions for this ORCID across all persons
            SuggestedOrcid::where('suggested_orcid', $orcid)->delete();
            $this->forgetCacheKey(CacheKey::SUGGESTED_ORCIDS_COUNT);

            return [
                'success' => false,
                'synced_dois' => [],
                'message' => "This ORCID is already assigned to another person ({$existingPerson->full_name}). The suggestion has been removed.",
            ];
        }

        if ($person === null) {
            $suggestion->delete();
            $this->forgetCacheKey(CacheKey::SUGGESTED_ORCIDS_COUNT);

            return [
                'success' => false,
                'synced_dois' => [],
                'message' => 'Person not found. The suggestion has been removed.',
            ];
        }

        DB::transaction(function () use ($person, $orcid, $suggestion): void {
            // Update the Person record with the accepted ORCID
            $person->update([
                'name_identifier' => "https://orcid.org/{$orcid}",
                'name_identifier_scheme' => 'ORCID',
                'name_identifier_scheme_uri' => 'https://orcid.org/',
            ]);

            // Delete ALL suggestions for this person (accepted globally)
            SuggestedOrcid::where('person_id', $suggestion->person_id)->delete();
        });

        // Sync all affected resources with DataCite
        $syncedDois = $this->syncAffectedResources($person);

        $this->forgetCacheKey(CacheKey::SUGGESTED_ORCIDS_COUNT);

        $syncCount = count($syncedDois);
        $message = $syncCount > 0
            ? "ORCID accepted. {$syncCount} resource(s) synced with DataCite."
            : 'ORCID accepted. No resources required DataCite sync.';

        return [
            'success' => true,
            'synced_dois' => $syncedDois,
            'message' => $message,
        ];
    }

    /**
     * Decline an ORCID suggestion: stores dismissal and removes all matching suggestions.
     */
    public function declineOrcid(SuggestedOrcid $suggestion, User $user, ?string $reason = null): void
    {
        DB::transaction(function () use ($suggestion, $user, $reason): void {
            DismissedOrcid::firstOrCreate(
                [
                    'person_id' => $suggestion->person_id,
                    'orcid' => $suggestion->suggested_orcid,
                ],
                [
                    'dismissed_by' => $user->id,
                    'reason' => $reason,
                ],
            );

            // Delete ALL suggestions for this person + orcid combination across all resources
            SuggestedOrcid::where('person_id', $suggestion->person_id)
                ->where('suggested_orcid', $suggestion->suggested_orcid)
                ->delete();
        });

        $this->forgetCacheKey(CacheKey::SUGGESTED_ORCIDS_COUNT);
    }

    /**
     * Collect all persons without ORCID across resources with registered DOIs.
     *
     * @return array<int, array{person_id: int, resource_id: int, source_context: string}>
     */
    private function collectPersonsWithoutOrcid(): array
    {
        $contexts = [];

        // Creators without ORCID
        $creators = ResourceCreator::whereHas('resource', fn ($q) => $q->whereNotNull('doi')->where('doi', '!=', ''))
            ->where('creatorable_type', Person::class)
            ->whereHas('creatorable', fn ($q) => $q->where(function ($q2): void {
                $q2->whereNull('name_identifier')
                    ->orWhere('name_identifier_scheme', '!=', 'ORCID');
            }))
            ->select(['resource_id', 'creatorable_id'])
            ->get();

        foreach ($creators as $creator) {
            $contexts[] = [
                'person_id' => $creator->creatorable_id,
                'resource_id' => $creator->resource_id,
                'source_context' => 'creator',
            ];
        }

        // Contributors without ORCID
        $contributors = ResourceContributor::whereHas('resource', fn ($q) => $q->whereNotNull('doi')->where('doi', '!=', ''))
            ->where('contributorable_type', Person::class)
            ->whereHas('contributorable', fn ($q) => $q->where(function ($q2): void {
                $q2->whereNull('name_identifier')
                    ->orWhere('name_identifier_scheme', '!=', 'ORCID');
            }))
            ->select(['resource_id', 'contributorable_id'])
            ->get();

        foreach ($contributors as $contributor) {
            $contexts[] = [
                'person_id' => $contributor->contributorable_id,
                'resource_id' => $contributor->resource_id,
                'source_context' => 'contributor',
            ];
        }

        return $contexts;
    }

    /**
     * Load affiliations for persons via their creator/contributor links.
     *
     * @param  array<int, int>  $personIds
     * @return array<int, array<int, string>> Map of person_id → affiliation names
     */
    private function loadPersonAffiliations(array $personIds): array
    {
        $result = [];

        // Affiliations from creators
        $creatorAffiliations = ResourceCreator::where('creatorable_type', Person::class)
            ->whereIn('creatorable_id', $personIds)
            ->with('affiliations')
            ->get();

        foreach ($creatorAffiliations as $creator) {
            $personId = $creator->creatorable_id;
            foreach ($creator->affiliations as $affil) {
                $result[$personId][] = $affil->name;
            }
        }

        // Affiliations from contributors
        $contributorAffiliations = ResourceContributor::where('contributorable_type', Person::class)
            ->whereIn('contributorable_id', $personIds)
            ->with('affiliations')
            ->get();

        foreach ($contributorAffiliations as $contributor) {
            $personId = $contributor->contributorable_id;
            foreach ($contributor->affiliations as $affil) {
                $result[$personId][] = $affil->name;
            }
        }

        // Deduplicate affiliation names per person
        foreach ($result as $personId => $names) {
            $result[$personId] = array_values(array_unique($names));
        }

        return $result;
    }

    /**
     * Search ORCID API for candidates matching a person's name.
     *
     * @return array<int, array{orcid: string, firstName: string, lastName: string, creditName: string|null, institutions: array<int, string>}>
     */
    private function searchOrcidCandidates(Person $person): array
    {
        $givenName = $person->given_name ?? '';
        $familyName = $person->family_name ?? '';

        if ($givenName === '' && $familyName === '') {
            return [];
        }

        $query = trim("{$givenName} {$familyName}");

        $this->respectRateLimit();

        $result = $this->orcidService->searchOrcid($query, self::ORCID_SEARCH_LIMIT);

        if (! $result['success'] || $result['data'] === null) {
            Log::debug('ORCID search failed for person', [
                'person_id' => $person->id,
                'query' => $query,
                'error' => $result['error'] ?? 'Unknown',
            ]);

            return [];
        }

        return $result['data']['results'] ?? [];
    }

    /**
     * Compute affiliation similarity between a person's affiliations and ORCID candidate affiliations.
     *
     * Uses PHP's similar_text() for fuzzy string matching.
     * Returns 0.5 (neutral) when both have no affiliations, 0.25 (weak) when only one side has affiliations.
     *
     * @param  array<int, string>  $personAffiliations  Affiliation names from ERNIE
     * @param  array<int, string>  $candidateAffiliations  Affiliation names from ORCID API
     * @return float Similarity score between 0.0 and 1.0
     */
    public function computeAffiliationSimilarity(array $personAffiliations, array $candidateAffiliations): float
    {
        $personAffiliations = array_filter($personAffiliations, fn (string $a) => $a !== '');
        $candidateAffiliations = array_filter($candidateAffiliations, fn (string $a) => $a !== '');

        // Both empty: neutral score (pure name match)
        if (empty($personAffiliations) && empty($candidateAffiliations)) {
            return 0.5;
        }

        // Only one side has affiliations: weak match
        if (empty($personAffiliations) || empty($candidateAffiliations)) {
            return 0.25;
        }

        $totalScore = 0.0;
        $comparisons = 0;

        foreach ($personAffiliations as $personAffil) {
            $bestMatch = 0.0;
            $normalizedPerson = mb_strtolower(trim($personAffil));

            foreach ($candidateAffiliations as $candidateAffil) {
                $normalizedCandidate = mb_strtolower(trim($candidateAffil));

                similar_text($normalizedPerson, $normalizedCandidate, $percent);
                $similarity = $percent / 100.0;

                $bestMatch = max($bestMatch, $similarity);
            }

            $totalScore += $bestMatch;
            $comparisons++;
        }

        return $totalScore / $comparisons;
    }

    /**
     * Sync all resources where a person is a creator or contributor.
     *
     * @return array<int, string> List of DOIs that were synced
     */
    private function syncAffectedResources(Person $person): array
    {
        $syncedDois = [];

        // Get all resource IDs where this person is a creator
        $creatorResourceIds = ResourceCreator::where('creatorable_type', Person::class)
            ->where('creatorable_id', $person->id)
            ->pluck('resource_id');

        // Get all resource IDs where this person is a contributor
        $contributorResourceIds = ResourceContributor::where('contributorable_type', Person::class)
            ->where('contributorable_id', $person->id)
            ->pluck('resource_id');

        $resourceIds = $creatorResourceIds->merge($contributorResourceIds)->unique();

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
     * Respect ORCID API rate limit (30 requests/minute).
     */
    private function respectRateLimit(): void
    {
        $now = microtime(true) * 1000;
        $elapsed = $now - $this->lastApiCallTime;

        if ($this->lastApiCallTime > 0 && $elapsed < $this->rateLimitDelayMs) {
            $sleepMs = (int) ceil($this->rateLimitDelayMs - $elapsed);
            usleep($sleepMs * 1000);
        }

        $this->lastApiCallTime = microtime(true) * 1000;
    }

    /**
     * Forget a cache key, using tags if supported.
     */
    private function forgetCacheKey(CacheKey $cacheKey): void
    {
        $this->getCacheInstance($cacheKey->tags())->forget($cacheKey->key());
    }
}
