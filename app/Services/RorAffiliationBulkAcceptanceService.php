<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Affiliation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\SuggestedRor;
use App\Services\DataCite\Mapping\DataCitePartyMappingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handles the Issue #955 bulk accept flow for ROR suggestions on creator affiliations.
 */
class RorAffiliationBulkAcceptanceService
{
    private const CACHE_PREFIX = 'ror_affiliation_bulk_accept';

    private const CACHE_TTL_MINUTES = 15;

    private const MATCHING_SUGGESTION_BATCH_SIZE = 500;

    private const DEFAULT_MAX_BULK_MATCHES_PER_TOKEN = 1000;

    public function __construct(
        private readonly DataCitePartyMappingService $partyMapper,
        private readonly DataCiteSyncService $dataCiteSyncService,
    ) {}

    /**
     * Create a short-lived bulk accept preview for further exact creator/affiliation matches.
     *
     * @return array{available: bool, count: int, bulk_token: string, creator_name: string, affiliation: string, suggested_ror_id: string}|null
     */
    public function createPreviewForAcceptedSuggestion(SuggestedRor $acceptedSuggestion): ?array
    {
        $sourceContext = $this->contextForSuggestion($acceptedSuggestion);

        if ($sourceContext === null) {
            return null;
        }

        $maxBulkMatches = $this->maxBulkMatchesPerToken();
        $matchingSuggestions = $this->matchingSuggestions($sourceContext, $acceptedSuggestion->id, $maxBulkMatches + 1);

        if ($matchingSuggestions === [] || count($matchingSuggestions) > $maxBulkMatches) {
            return null;
        }

        $token = (string) Str::uuid();

        Cache::put($this->cacheKey($token), [
            'creator_name' => $sourceContext['creator_name'],
            'affiliation' => $sourceContext['affiliation'],
            'suggested_ror_id' => $sourceContext['suggested_ror_id'],
            'matches' => $matchingSuggestions,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        return [
            'available' => true,
            'count' => count($matchingSuggestions),
            'bulk_token' => $token,
            'creator_name' => $sourceContext['creator_name'],
            'affiliation' => $sourceContext['affiliation'],
            'suggested_ror_id' => $sourceContext['suggested_ror_id'],
        ];
    }

    /**
     * Accept all still-valid exact matches captured by a preview token.
     *
     * @return array{success: bool, accepted_count: int, skipped_count: int, synced_dois: array<int, string>, message: string, retryable?: bool}
     */
    public function acceptByToken(string $token): array
    {
        $cacheKey = $this->cacheKey($token);
        $payload = Cache::get($cacheKey);

        if (! is_array($payload) || ! $this->isValidPayload($payload)) {
            return [
                'success' => false,
                'accepted_count' => 0,
                'skipped_count' => 0,
                'synced_dois' => [],
                'message' => 'Bulk ROR acceptance token is invalid or has expired.',
            ];
        }

        /** @var array<int, array{suggestion_id: int, affiliation_id: int, resource_id: int}> $matches */
        $matches = $payload['matches'];
        usort($matches, fn (array $a, array $b): int => [$a['affiliation_id'], $a['suggestion_id']] <=> [$b['affiliation_id'], $b['suggestion_id']]);
        /** @var string $creatorName */
        $creatorName = $payload['creator_name'];
        /** @var string $affiliation */
        $affiliation = $payload['affiliation'];
        /** @var string $suggestedRorId */
        $suggestedRorId = $payload['suggested_ror_id'];

        $acceptedResourceIds = [];
        $alreadyAcceptedResourceIds = [];
        $acceptedCount = 0;
        $skippedCount = 0;

        $matchChunks = array_chunk($matches, self::MATCHING_SUGGESTION_BATCH_SIZE);

        DB::transaction(function () use (
            $matchChunks,
            $creatorName,
            $affiliation,
            $suggestedRorId,
            &$acceptedResourceIds,
            &$alreadyAcceptedResourceIds,
            &$acceptedCount,
            &$skippedCount,
        ): void {
            foreach ($matchChunks as $matchChunk) {
                $suggestionIds = array_column($matchChunk, 'suggestion_id');
                $suggestions = SuggestedRor::whereIn('id', $suggestionIds)
                    ->where('entity_type', 'affiliation')
                    ->where('suggested_ror_id', $suggestedRorId)
                    ->get()
                    ->keyBy('id');

                foreach ($matchChunk as $match) {
                    $suggestionId = $match['suggestion_id'];
                    $suggestion = $suggestions->get($suggestionId);

                    if (! $suggestion instanceof SuggestedRor) {
                        if ($this->affiliationHasExpectedRor($match['affiliation_id'], $suggestedRorId)) {
                            $this->deleteAffiliationSuggestions($match['affiliation_id']);
                            $alreadyAcceptedResourceIds[] = $match['resource_id'];
                        } else {
                            $skippedCount++;
                        }

                        continue;
                    }

                    $context = $this->contextForSuggestion($suggestion, lockAffiliation: true);

                    if ($context === null) {
                        $this->deleteAffiliationSuggestions($suggestion->entity_id);
                        $skippedCount++;

                        continue;
                    }

                    if ($context['already_has_ror']) {
                        $this->deleteAffiliationSuggestions($context['affiliation_model']->id);
                        $skippedCount++;

                        continue;
                    }

                    if (
                        $context['creator_name'] !== $creatorName
                        || $context['affiliation'] !== $affiliation
                        || $context['suggested_ror_id'] !== $suggestedRorId
                    ) {
                        $skippedCount++;

                        continue;
                    }

                    if ($context['has_identifier']) {
                        $skippedCount++;

                        continue;
                    }

                    $context['affiliation_model']->update([
                        'identifier' => $suggestedRorId,
                        'identifier_scheme' => 'ROR',
                        'scheme_uri' => 'https://ror.org/',
                    ]);

                    $this->deleteAffiliationSuggestions($context['affiliation_model']->id);

                    $acceptedResourceIds[] = $context['resource_id'];
                    $acceptedCount++;
                }
            }
        });

        $syncResourceIds = array_values(array_unique([
            ...$acceptedResourceIds,
            ...$alreadyAcceptedResourceIds,
        ]));

        $syncResult = $this->syncResources($syncResourceIds);
        CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->forget();

        if ($syncResult['failed']) {
            return [
                'success' => false,
                'accepted_count' => $acceptedCount,
                'skipped_count' => $skippedCount,
                'synced_dois' => $syncResult['synced_dois'],
                'message' => $syncResult['message'],
                'retryable' => true,
            ];
        }

        Cache::forget($cacheKey);

        $message = $acceptedCount > 0
            ? sprintf('ROR-ID accepted for %d further %s.', $acceptedCount, Str::plural('creator affiliation', $acceptedCount))
            : $this->messageForRetrySync($alreadyAcceptedResourceIds, $syncResult['synced_dois']);

        return [
            'success' => $acceptedCount > 0 || $alreadyAcceptedResourceIds !== [],
            'accepted_count' => $acceptedCount,
            'skipped_count' => $skippedCount,
            'synced_dois' => $syncResult['synced_dois'],
            'message' => $message,
        ];
    }

    /**
     * @param  array{creator_name: string, affiliation: string, suggested_ror_id: string}  $sourceContext
     * @return array<int, array{suggestion_id: int, affiliation_id: int, resource_id: int}>
     */
    private function matchingSuggestions(array $sourceContext, int $acceptedSuggestionId, int $limit): array
    {
        $matches = [];

        SuggestedRor::query()
            ->select(['id', 'entity_id'])
            ->where('entity_type', 'affiliation')
            ->where('suggested_ror_id', $sourceContext['suggested_ror_id'])
            ->where('id', '!=', $acceptedSuggestionId)
            ->chunkById(self::MATCHING_SUGGESTION_BATCH_SIZE, function ($candidates) use (&$matches, $sourceContext, $limit): bool {
                $affiliationIds = $candidates->pluck('entity_id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                if ($affiliationIds === []) {
                    return true;
                }

                $affiliations = Affiliation::query()
                    ->whereIn('id', $affiliationIds)
                    ->where('name', $sourceContext['affiliation'])
                    ->where('affiliatable_type', ResourceCreator::class)
                    ->where(function ($query): void {
                        $query->whereNull('identifier')
                            ->orWhere('identifier', '');
                    })
                    ->get()
                    ->keyBy('id');

                if ($affiliations->isEmpty()) {
                    return true;
                }

                $creatorIds = $affiliations->pluck('affiliatable_id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $creators = ResourceCreator::with('creatorable')
                    ->whereIn('id', $creatorIds)
                    ->whereIn('creatorable_type', [Person::class, Institution::class])
                    ->get()
                    ->keyBy('id');

                foreach ($candidates as $candidate) {
                    $affiliation = $affiliations->get($candidate->entity_id);

                    if (! $affiliation instanceof Affiliation) {
                        continue;
                    }

                    $creator = $creators->get($affiliation->affiliatable_id);

                    if (! $creator instanceof ResourceCreator) {
                        continue;
                    }

                    if ($this->creatorName($creator) !== $sourceContext['creator_name']) {
                        continue;
                    }

                    $matches[] = [
                        'suggestion_id' => (int) $candidate->id,
                        'affiliation_id' => (int) $affiliation->id,
                        'resource_id' => (int) $creator->resource_id,
                    ];

                    if (count($matches) >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        return $matches;
    }

    /**
     * @return array{creator_name: string, affiliation: string, suggested_ror_id: string, already_has_ror: bool, has_identifier: bool, resource_id: int, affiliation_model: Affiliation}|null
     */
    private function contextForSuggestion(SuggestedRor $suggestion, bool $lockAffiliation = false): ?array
    {
        if ($suggestion->entity_type !== 'affiliation') {
            return null;
        }

        $affiliationQuery = Affiliation::query();

        if ($lockAffiliation) {
            $affiliationQuery->lockForUpdate();
        }

        /** @var Affiliation|null $affiliation */
        $affiliation = $affiliationQuery->find($suggestion->entity_id);

        if (! $affiliation instanceof Affiliation || $affiliation->affiliatable_type !== ResourceCreator::class) {
            return null;
        }

        $creator = ResourceCreator::with('creatorable')->find($affiliation->affiliatable_id);

        if (! $creator instanceof ResourceCreator) {
            return null;
        }

        $creatorName = $this->creatorName($creator);
        if ($creatorName === null) {
            return null;
        }

        return [
            'creator_name' => $creatorName,
            'affiliation' => $affiliation->name,
            'suggested_ror_id' => $suggestion->suggested_ror_id,
            'already_has_ror' => $affiliation->identifier_scheme === 'ROR'
                && $this->affiliationHasIdentifier($affiliation),
            'has_identifier' => $this->affiliationHasIdentifier($affiliation),
            'resource_id' => (int) $creator->resource_id,
            'affiliation_model' => $affiliation,
        ];
    }

    private function creatorName(ResourceCreator $creator): ?string
    {
        $creatorable = $creator->getRelationValue('creatorable');

        if ($creatorable instanceof Person) {
            return $this->partyMapper->formatPersonName($creatorable);
        }

        if ($creatorable instanceof Institution) {
            return $this->partyMapper->formatInstitutionName($creatorable);
        }

        return null;
    }

    /**
     * @param  array<int, int>  $resourceIds
     * @return array{synced_dois: array<int, string>, failed: bool, message: string}
     */
    private function syncResources(array $resourceIds): array
    {
        if ($resourceIds === []) {
            return [
                'synced_dois' => [],
                'failed' => false,
                'message' => '',
            ];
        }

        $syncedDois = [];
        $failureMessage = null;

        $resources = Resource::whereIn('id', $resourceIds)
            ->whereNotNull('doi')
            ->where('doi', '!=', '')
            ->orderBy('id')
            ->get();

        foreach ($resources as $resource) {
            $result = $this->dataCiteSyncService->syncIfRegistered($resource);
            if ($result->success && $resource->doi !== null) {
                $syncedDois[] = $resource->doi;
            }

            if ($result->hasFailed()) {
                $failureMessage ??= $result->errorMessage;
            }
        }

        if ($failureMessage !== null) {
            return [
                'synced_dois' => $syncedDois,
                'failed' => true,
                'message' => 'DataCite sync failed: '.$failureMessage.' Please try again.',
            ];
        }

        return [
            'synced_dois' => $syncedDois,
            'failed' => false,
            'message' => '',
        ];
    }

    private function deleteAffiliationSuggestions(int $affiliationId): void
    {
        SuggestedRor::where('entity_type', 'affiliation')
            ->where('entity_id', $affiliationId)
            ->delete();
    }

    private function affiliationHasExpectedRor(int $affiliationId, string $suggestedRorId): bool
    {
        return Affiliation::whereKey($affiliationId)
            ->where('identifier_scheme', 'ROR')
            ->where('identifier', $suggestedRorId)
            ->exists();
    }

    private function affiliationHasIdentifier(Affiliation $affiliation): bool
    {
        return $affiliation->identifier !== null && $affiliation->identifier !== '';
    }

    /**
     * @param  array<int, int>  $alreadyAcceptedResourceIds
     * @param  array<int, string>  $syncedDois
     */
    private function messageForRetrySync(array $alreadyAcceptedResourceIds, array $syncedDois): string
    {
        if ($alreadyAcceptedResourceIds !== []) {
            $alreadyAcceptedCount = count(array_unique($alreadyAcceptedResourceIds));

            if ($syncedDois === []) {
                return sprintf(
                    'ROR-ID acceptance was already applied for %d further %s. No resources required DataCite sync.',
                    $alreadyAcceptedCount,
                    Str::plural('creator affiliation', $alreadyAcceptedCount),
                );
            }

            return sprintf(
                'ROR-ID acceptance was already applied for %d further %s. DataCite sync has been retried.',
                $alreadyAcceptedCount,
                Str::plural('creator affiliation', $alreadyAcceptedCount),
            );
        }

        return 'No further matching creator affiliations could be accepted.';
    }

    private function maxBulkMatchesPerToken(): int
    {
        return max(
            1,
            (int) config('services.ror_affiliation_bulk_accept.max_matches', self::DEFAULT_MAX_BULK_MATCHES_PER_TOKEN),
        );
    }

    /**
     * @phpstan-assert-if-true array{creator_name: string, affiliation: string, suggested_ror_id: string, matches: array<int, array{suggestion_id: int, affiliation_id: int, resource_id: int}>} $payload
     */
    private function isValidPayload(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        if (
            ! is_string($payload['creator_name'] ?? null)
            || ! is_string($payload['affiliation'] ?? null)
            || ! is_string($payload['suggested_ror_id'] ?? null)
            || ! is_array($payload['matches'] ?? null)
        ) {
            return false;
        }

        foreach ($payload['matches'] as $match) {
            if (
                ! is_array($match)
                || ! is_int($match['suggestion_id'] ?? null)
                || ! is_int($match['affiliation_id'] ?? null)
                || ! is_int($match['resource_id'] ?? null)
            ) {
                return false;
            }
        }

        return true;
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_PREFIX.':'.$token;
    }
}
