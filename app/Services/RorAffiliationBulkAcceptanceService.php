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

        $matchingSuggestions = $this->matchingSuggestions($sourceContext, $acceptedSuggestion->id);

        if ($matchingSuggestions === []) {
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
     * @return array{success: bool, accepted_count: int, skipped_count: int, synced_dois: array<int, string>, message: string}
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

        $suggestionIds = [];
        foreach ($matches as $match) {
            $suggestionIds[] = $match['suggestion_id'];
        }

        DB::transaction(function () use (
            $matches,
            $suggestionIds,
            $creatorName,
            $affiliation,
            $suggestedRorId,
            &$acceptedResourceIds,
            &$alreadyAcceptedResourceIds,
            &$acceptedCount,
            &$skippedCount,
        ): void {
            $suggestions = SuggestedRor::whereIn('id', $suggestionIds)
                ->where('entity_type', 'affiliation')
                ->where('suggested_ror_id', $suggestedRorId)
                ->get()
                ->keyBy('id');

            foreach ($matches as $match) {
                $suggestionId = $match['suggestion_id'];
                $suggestion = $suggestions->get($suggestionId);

                if (! $suggestion instanceof SuggestedRor) {
                    if ($this->affiliationHasExpectedRor($match['affiliation_id'], $suggestedRorId)) {
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

                $context['affiliation_model']->update([
                    'identifier' => $suggestedRorId,
                    'identifier_scheme' => 'ROR',
                    'scheme_uri' => 'https://ror.org/',
                ]);

                $this->deleteAffiliationSuggestions($context['affiliation_model']->id);

                $acceptedResourceIds[] = $context['resource_id'];
                $acceptedCount++;
            }
        });

        $syncResourceIds = array_values(array_unique([
            ...$acceptedResourceIds,
            ...$alreadyAcceptedResourceIds,
        ]));

        $syncedDois = $this->syncResources($syncResourceIds);
        CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->forget();
        Cache::forget($cacheKey);

        $message = $acceptedCount > 0
            ? sprintf('ROR-ID accepted for %d further %s.', $acceptedCount, Str::plural('creator affiliation', $acceptedCount))
            : $this->messageForRetrySync($alreadyAcceptedResourceIds);

        return [
            'success' => $acceptedCount > 0 || $alreadyAcceptedResourceIds !== [],
            'accepted_count' => $acceptedCount,
            'skipped_count' => $skippedCount,
            'synced_dois' => $syncedDois,
            'message' => $message,
        ];
    }

    /**
     * @param  array{creator_name: string, affiliation: string, suggested_ror_id: string}  $sourceContext
     * @return array<int, array{suggestion_id: int, affiliation_id: int, resource_id: int}>
     */
    private function matchingSuggestions(array $sourceContext, int $acceptedSuggestionId): array
    {
        $matches = [];

        $candidates = SuggestedRor::where('entity_type', 'affiliation')
            ->where('suggested_ror_id', $sourceContext['suggested_ror_id'])
            ->where('id', '!=', $acceptedSuggestionId)
            ->get();

        foreach ($candidates as $candidate) {
            $context = $this->contextForSuggestion($candidate);

            if ($context === null || $context['already_has_ror']) {
                continue;
            }

            if (
                $context['creator_name'] === $sourceContext['creator_name']
                && $context['affiliation'] === $sourceContext['affiliation']
            ) {
                $matches[] = [
                    'suggestion_id' => (int) $candidate->id,
                    'affiliation_id' => (int) $context['affiliation_model']->id,
                    'resource_id' => (int) $context['resource_id'],
                ];
            }
        }

        return $matches;
    }

    /**
     * @return array{creator_name: string, affiliation: string, suggested_ror_id: string, already_has_ror: bool, resource_id: int, affiliation_model: Affiliation}|null
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
                && $affiliation->identifier !== null
                && $affiliation->identifier !== '',
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
     * @return array<int, string>
     */
    private function syncResources(array $resourceIds): array
    {
        if ($resourceIds === []) {
            return [];
        }

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

    /**
     * @param  array<int, int>  $alreadyAcceptedResourceIds
     */
    private function messageForRetrySync(array $alreadyAcceptedResourceIds): string
    {
        if ($alreadyAcceptedResourceIds !== []) {
            $alreadyAcceptedCount = count(array_unique($alreadyAcceptedResourceIds));

            return sprintf(
                'ROR-ID acceptance was already applied for %d further %s. DataCite sync has been retried.',
                $alreadyAcceptedCount,
                Str::plural('creator affiliation', $alreadyAcceptedCount),
            );
        }

        return 'No further matching creator affiliations could be accepted.';
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
