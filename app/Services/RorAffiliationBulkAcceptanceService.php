<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\Affiliation;
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
            'suggestion_ids' => array_column($matchingSuggestions, 'suggestion_id'),
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
        $payload = Cache::pull($this->cacheKey($token));

        if (! is_array($payload) || ! $this->isValidPayload($payload)) {
            return [
                'success' => false,
                'accepted_count' => 0,
                'skipped_count' => 0,
                'synced_dois' => [],
                'message' => 'Bulk ROR acceptance token is invalid or has expired.',
            ];
        }

        /** @var array<int, int> $suggestionIds */
        $suggestionIds = $payload['suggestion_ids'];
        /** @var string $creatorName */
        $creatorName = $payload['creator_name'];
        /** @var string $affiliation */
        $affiliation = $payload['affiliation'];
        /** @var string $suggestedRorId */
        $suggestedRorId = $payload['suggested_ror_id'];

        $acceptedResourceIds = [];
        $acceptedCount = 0;
        $skippedCount = 0;

        DB::transaction(function () use (
            $suggestionIds,
            $creatorName,
            $affiliation,
            $suggestedRorId,
            &$acceptedResourceIds,
            &$acceptedCount,
            &$skippedCount,
        ): void {
            $suggestions = SuggestedRor::whereIn('id', $suggestionIds)
                ->where('entity_type', 'affiliation')
                ->where('suggested_ror_id', $suggestedRorId)
                ->get()
                ->keyBy('id');

            foreach ($suggestionIds as $suggestionId) {
                $suggestion = $suggestions->get($suggestionId);

                if (! $suggestion instanceof SuggestedRor) {
                    $skippedCount++;

                    continue;
                }

                $context = $this->contextForSuggestion($suggestion, lockAffiliation: true);

                if ($context === null) {
                    $suggestion->delete();
                    $skippedCount++;

                    continue;
                }

                if ($context['already_has_ror']) {
                    $suggestion->delete();
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

                SuggestedRor::where('entity_type', 'affiliation')
                    ->where('entity_id', $context['affiliation_model']->id)
                    ->delete();

                $acceptedResourceIds[] = $context['resource_id'];
                $acceptedCount++;
            }
        });

        $syncedDois = $this->syncResources(array_values(array_unique($acceptedResourceIds)));
        CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->forget();

        $message = $acceptedCount > 0
            ? "ROR-ID accepted for {$acceptedCount} further creator affiliation(s)."
            : 'No further matching creator affiliations could be accepted.';

        return [
            'success' => $acceptedCount > 0,
            'accepted_count' => $acceptedCount,
            'skipped_count' => $skippedCount,
            'synced_dois' => $syncedDois,
            'message' => $message,
        ];
    }

    /**
     * @param  array{creator_name: string, affiliation: string, suggested_ror_id: string}  $sourceContext
     * @return array<int, array{suggestion_id: int}>
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
                $matches[] = ['suggestion_id' => $candidate->id];
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

        return [
            'creator_name' => $creatorName,
            'affiliation' => $affiliation->name,
            'suggested_ror_id' => $suggestion->suggested_ror_id,
            'already_has_ror' => $affiliation->identifier_scheme === 'ROR'
                && $affiliation->identifier !== null
                && $affiliation->identifier !== '',
            'resource_id' => $creator->resource_id,
            'affiliation_model' => $affiliation,
        ];
    }

    private function creatorName(ResourceCreator $creator): string
    {
        $creatorable = $creator->creatorable;

        if ($creatorable instanceof Person) {
            return $this->partyMapper->formatPersonName($creatorable);
        }

        return $this->partyMapper->formatInstitutionName($creatorable);
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

    /**
     * @phpstan-assert-if-true array{creator_name: string, affiliation: string, suggested_ror_id: string, suggestion_ids: array<int, int>} $payload
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
            || ! is_array($payload['suggestion_ids'] ?? null)
        ) {
            return false;
        }

        foreach ($payload['suggestion_ids'] as $suggestionId) {
            if (! is_int($suggestionId)) {
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
