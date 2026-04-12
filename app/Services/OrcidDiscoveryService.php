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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
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
     * Kept small since we only retain the top 3 by similarity,
     * and each result triggers a follow-up record fetch.
     */
    private const ORCID_SEARCH_LIMIT = 5;

    /**
     * Chunk size for processing persons in batches.
     */
    private const CHUNK_SIZE = 100;

    public function __construct(
        private readonly OrcidService $orcidService,
        private readonly DataCiteSyncService $dataCiteSyncService,
    ) {}

    /**
     * Discover missing ORCIDs for all resources with registered DOIs.
     *
     * Queries unique person IDs without ORCID, then processes them in chunks
     * to keep memory bounded. Contexts (person→resource mappings) are loaded
     * per chunk rather than upfront.
     *
     * @param  callable(int $processed, int $total): void|null  $progressCallback
     * @return int Number of newly discovered suggestions
     */
    public function discoverAll(?callable $progressCallback = null): int
    {
        $allPersonIds = $this->collectPersonIdsWithoutOrcid();

        if (empty($allPersonIds)) {
            Log::info('ORCID discovery: No persons without ORCID found.');

            return 0;
        }

        $total = count($allPersonIds);
        $processed = 0;
        $newCount = 0;

        foreach (array_chunk($allPersonIds, self::CHUNK_SIZE) as $chunkPersonIds) {
            // Load contexts (person→resource mappings) for this chunk only
            $contextsByPerson = $this->loadContextsForPersons($chunkPersonIds);

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
     * Uses a two-pass approach:
     *  Pass 1 – Call the ORCID API for each person (rate-limited) and collect raw candidates.
     *  Pass 2 – Batch-check assigned ORCIDs, rank by similarity, store top N.
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

        // Pass 1: Collect all raw candidates per person via ORCID API
        /** @var array<int, array<int, array{orcid: string, firstName: string, lastName: string, creditName: string|null, institutions: array<int, string>}>> */
        $candidatesByPerson = [];
        $allCandidateOrcids = [];

        foreach ($chunkPersonIds as $personId) {
            $person = $persons->get($personId);
            if ($person === null) {
                $processed++;
                if ($progressCallback !== null) {
                    $progressCallback($processed, $total);
                }

                continue;
            }

            $candidates = $this->searchOrcidCandidates($person);
            $candidatesByPerson[$personId] = $candidates;

            foreach ($candidates as $c) {
                $allCandidateOrcids[] = $c['orcid'];
            }

            $processed++;
            if ($progressCallback !== null) {
                $progressCallback($processed, $total);
            }
        }

        // Batch-check which candidate ORCIDs are already assigned to any person
        $assignedOrcids = $this->batchCheckAssignedOrcids(array_unique($allCandidateOrcids));

        // Pass 2: Rank by similarity, filter, and store top N per person
        foreach ($chunkPersonIds as $personId) {
            if (! isset($candidatesByPerson[$personId])) {
                // Already processed (null person) in pass 1
                continue;
            }

            $dismissed = array_flip($dismissedSet[$personId] ?? []);
            $suggested = array_flip($suggestedSet[$personId] ?? []);
            $contexts = $contextsByPerson[$personId] ?? [];
            $affiliations = $personAffiliations[$personId] ?? [];

            // Compute similarity for ALL candidates, then rank and take top N
            $scored = [];
            foreach ($candidatesByPerson[$personId] as $candidate) {
                $orcid = $candidate['orcid'];

                if (isset($dismissed[$orcid]) || isset($suggested[$orcid]) || isset($assignedOrcids[$orcid])) {
                    continue;
                }

                $similarity = $this->computeAffiliationSimilarity(
                    $affiliations,
                    $candidate['institutions'],
                );

                $scored[] = ['candidate' => $candidate, 'similarity' => $similarity];
            }

            // Sort descending by similarity so the best matches are stored
            usort($scored, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);

            foreach (array_slice($scored, 0, self::MAX_CANDIDATES_PER_PERSON) as $entry) {
                $candidate = $entry['candidate'];
                $orcid = $candidate['orcid'];

                foreach ($contexts as $ctx) {
                    $suggestion = SuggestedOrcid::firstOrCreate(
                        [
                            'resource_id' => $ctx['resource_id'],
                            'person_id' => $personId,
                            'suggested_orcid' => $orcid,
                        ],
                        [
                            'similarity_score' => $entry['similarity'],
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

                $suggested[$orcid] = true;
            }
        }

        return $newCount;
    }

    /**
     * Batch-check which bare ORCIDs are already assigned to any person.
     *
     * @param  array<int, string>  $orcids  Bare ORCIDs (e.g. "0000-0001-2345-6789")
     * @return array<string, true> Flipped set of assigned ORCIDs for O(1) lookup
     */
    private function batchCheckAssignedOrcids(array $orcids): array
    {
        if (empty($orcids)) {
            return [];
        }

        // Build all possible URL variants for these ORCIDs
        $urlVariants = [];
        foreach ($orcids as $orcid) {
            $urlVariants = [...$urlVariants, ...$this->orcidUrlVariants($orcid)];
        }

        // Single query to find all matching persons
        $assignedIdentifiers = Person::whereNotNull('name_identifier')
            ->where('name_identifier', '!=', '')
            ->where('name_identifier_scheme', 'ORCID')
            ->whereIn('name_identifier', $urlVariants)
            ->pluck('name_identifier')
            ->all();

        // Extract bare ORCIDs and return as a flipped set
        $assigned = [];
        foreach ($assignedIdentifiers as $identifier) {
            if (preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[0-9X])/', $identifier, $matches)) {
                $assigned[$matches[1]] = true;
            }
        }

        return $assigned;
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
        $orcid = $suggestion->suggested_orcid;
        $personId = $suggestion->person_id;

        try {
            /** @var array{success: bool, synced_dois: array<int, string>, message: string}|null $result */
            $result = DB::transaction(function () use ($suggestion, $orcid, $personId): ?array {
                // Lock the target person row to prevent concurrent ORCID acceptance
                $person = Person::lockForUpdate()->find($personId);

                if ($person === null) {
                    $suggestion->delete();
                    $this->forgetCacheKey(CacheKey::SUGGESTED_ORCIDS_COUNT);

                    return [
                        'success' => false,
                        'synced_dois' => [],
                        'message' => 'Person not found. The suggestion has been removed.',
                    ];
                }

                // Guard: person already has an ORCID (stale suggestion)
                if ($person->name_identifier !== null
                    && $person->name_identifier !== ''
                    && $person->name_identifier_scheme === 'ORCID'
                ) {
                    SuggestedOrcid::where('person_id', $personId)->delete();
                    $this->forgetCacheKey(CacheKey::SUGGESTED_ORCIDS_COUNT);

                    return [
                        'success' => false,
                        'synced_dois' => [],
                        'message' => 'This person already has an ORCID assigned. The suggestion has been removed.',
                    ];
                }

                // Guard: ORCID already assigned to a different person
                $existingPerson = Person::whereNotNull('name_identifier')
                    ->where('name_identifier', '!=', '')
                    ->where('name_identifier_scheme', 'ORCID')
                    ->where('id', '!=', $personId)
                    ->whereIn('name_identifier', $this->orcidUrlVariants($orcid))
                    ->lockForUpdate()
                    ->first();

                if ($existingPerson !== null) {
                    SuggestedOrcid::where('suggested_orcid', $orcid)->delete();
                    $this->forgetCacheKey(CacheKey::SUGGESTED_ORCIDS_COUNT);

                    return [
                        'success' => false,
                        'synced_dois' => [],
                        'message' => "This ORCID is already assigned to another person ({$existingPerson->full_name}). The suggestion has been removed.",
                    ];
                }

                // Update the Person record with the accepted ORCID
                $person->update([
                    'name_identifier' => "https://orcid.org/{$orcid}",
                    'name_identifier_scheme' => 'ORCID',
                    'scheme_uri' => 'https://orcid.org/',
                ]);

                // Delete ALL suggestions for this person (accepted globally)
                SuggestedOrcid::where('person_id', $personId)->delete();

                // Return null to signal success – DataCite sync happens outside the transaction
                return null;
            });
        } catch (QueryException $e) {
            // Only handle unique constraint violations (concurrent acceptance race)
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }

            SuggestedOrcid::where('person_id', $personId)->delete();
            $this->forgetCacheKey(CacheKey::SUGGESTED_ORCIDS_COUNT);

            return [
                'success' => false,
                'synced_dois' => [],
                'message' => 'This ORCID was just assigned by another curator. The suggestion has been removed.',
            ];
        }

        // Early-return for guard failures (result is an array)
        if ($result !== null) {
            return $result;
        }

        // Sync all affected resources with DataCite (outside transaction)
        $person = Person::find($personId);
        $syncedDois = $person !== null ? $this->syncAffectedResources($person) : [];

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
     * Collect unique person IDs without ORCID across resources with registered DOIs.
     *
     * Returns only IDs (not full contexts) to keep memory minimal.
     * Contexts are loaded per chunk via loadContextsForPersons().
     *
     * @return array<int, int>
     */
    private function collectPersonIdsWithoutOrcid(): array
    {
        // A person "has a valid ORCID" when: name_identifier IS NOT NULL
        // AND name_identifier != '' AND name_identifier_scheme = 'ORCID'.
        // Everyone else (NULL, empty string, or non-ORCID scheme) should be discoverable.
        $personWithoutOrcid = fn ($q) => $q->where(function ($q2): void {
            $q2->whereNull('name_identifier')
                ->orWhere('name_identifier', '')
                ->orWhere('name_identifier_scheme', '!=', 'ORCID')
                ->orWhereNull('name_identifier_scheme');
        });

        $hasDoi = fn ($q) => $q->whereNotNull('doi')->where('doi', '!=', '');

        $creatorIds = ResourceCreator::whereHas('resource', $hasDoi)
            ->where('creatorable_type', Person::class)
            ->whereHas('creatorable', $personWithoutOrcid)
            ->distinct()
            ->pluck('creatorable_id');

        $contributorIds = ResourceContributor::whereHas('resource', $hasDoi)
            ->where('contributorable_type', Person::class)
            ->whereHas('contributorable', $personWithoutOrcid)
            ->distinct()
            ->pluck('contributorable_id');

        return $creatorIds->merge($contributorIds)->unique()->values()->all();
    }

    /**
     * Load person→resource contexts for a given set of person IDs.
     *
     * @param  array<int, int>  $personIds
     * @return array<int, array<int, array{person_id: int, resource_id: int, source_context: string}>>
     */
    private function loadContextsForPersons(array $personIds): array
    {
        $contextsByPerson = [];

        $hasDoi = fn ($q) => $q->whereNotNull('doi')->where('doi', '!=', '');

        $creators = ResourceCreator::whereHas('resource', $hasDoi)
            ->where('creatorable_type', Person::class)
            ->whereIn('creatorable_id', $personIds)
            ->select(['resource_id', 'creatorable_id'])
            ->get();

        foreach ($creators as $creator) {
            $contextsByPerson[$creator->creatorable_id][] = [
                'person_id' => $creator->creatorable_id,
                'resource_id' => $creator->resource_id,
                'source_context' => 'creator',
            ];
        }

        $contributors = ResourceContributor::whereHas('resource', $hasDoi)
            ->where('contributorable_type', Person::class)
            ->whereIn('contributorable_id', $personIds)
            ->select(['resource_id', 'contributorable_id'])
            ->get();

        foreach ($contributors as $contributor) {
            $contextsByPerson[$contributor->contributorable_id][] = [
                'person_id' => $contributor->contributorable_id,
                'resource_id' => $contributor->resource_id,
                'source_context' => 'contributor',
            ];
        }

        return $contextsByPerson;
    }

    /**
     * Load affiliations for persons via their creator/contributor links.
     *
     * Shared by both the discovery job and the AssistanceController to avoid
     * duplicated affiliation-loading logic.
     *
     * @param  array<int, int>  $personIds
     * @return array<int, array<int, string>> Map of person_id → affiliation names
     */
    public function loadPersonAffiliations(array $personIds): array
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
     * Generate all known URL variants for a bare ORCID.
     *
     * Covers https/http with and without www., plus the bare ID.
     *
     * @return array<int, string>
     */
    private function orcidUrlVariants(string $orcid): array
    {
        return [
            "https://orcid.org/{$orcid}",
            "http://orcid.org/{$orcid}",
            "https://www.orcid.org/{$orcid}",
            "http://www.orcid.org/{$orcid}",
            $orcid,
        ];
    }

    /**
     * Forget a cache key, using tags if supported.
     *
     * Also invalidates the total pending count so the sidebar badge updates.
     */
    private function forgetCacheKey(CacheKey $cacheKey): void
    {
        $this->getCacheInstance($cacheKey->tags())->forget($cacheKey->key());

        Cache::forget(CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->key());
    }
}
