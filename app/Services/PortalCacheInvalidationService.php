<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Enums\PortalCacheArea;
use App\Enums\PortalScope;
use App\Models\Resource;
use App\Services\BotProtection\PortalMapCacheService;
use App\Services\BotProtection\PortalPageCacheService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Coalesces public portal cache invalidations and applies them after commit.
 */
class PortalCacheInvalidationService
{
    /** @var array<string, array<string, true>> */
    private array $pending = [];

    private bool $scheduled = false;

    /** @var array<int, PortalScope|false> */
    private array $resourceScopes = [];

    public function __construct(
        private readonly PortalPageCacheService $pageCache,
        private readonly PortalMapCacheService $mapCache,
        private readonly PortalCacheVersionService $versionService,
        private readonly KeywordSuggestionService $keywordSuggestionService,
        private readonly ResourceCacheService $resourceCacheService,
    ) {}

    /**
     * @param  iterable<PortalScope>  $scopes
     * @param  iterable<PortalCacheArea>  $areas
     */
    public function schedule(iterable $scopes, iterable $areas): void
    {
        $normalizedScopes = [];
        foreach ($scopes as $scope) {
            $normalizedScopes[$scope->value] = $scope;
        }

        $normalizedAreas = [];
        foreach ($areas as $area) {
            $normalizedAreas[$area->value] = $area;
        }

        foreach ($normalizedScopes as $scope) {
            foreach ($normalizedAreas as $area) {
                $this->pending[$scope->value][$area->value] = true;
            }
        }

        if ($this->pending === [] || $this->scheduled) {
            return;
        }

        $databaseManager = DB::getFacadeRoot();
        if (! $databaseManager instanceof DatabaseManager) {
            $this->flushPending();

            return;
        }

        try {
            $connection = $databaseManager->connection();
            if ($connection->transactionLevel() === 0) {
                $this->flushPending();

                return;
            }

            $this->scheduled = true;
            $connection->afterCommit(fn () => $this->flushPending());
            $connection->afterRollBack(fn () => $this->reset());
        } catch (Throwable) {
            $this->scheduled = false;
            $this->flushPending();
        }
    }

    /** @param iterable<PortalCacheArea> $areas */
    public function scheduleForResourceId(int $resourceId, iterable $areas): void
    {
        if ($resourceId <= 0) {
            return;
        }

        if (! array_key_exists($resourceId, $this->resourceScopes)) {
            $resource = Resource::query()
                ->select(['id', 'resource_type_id'])
                ->whereHas(
                    'landingPage',
                    static fn ($query) => $query->where('is_published', true),
                )
                ->find($resourceId);

            $this->resourceScopes[$resourceId] = $resource instanceof Resource
                ? $this->scopeForResourceTypeId($resource->resource_type_id)
                : false;
        }

        $scope = $this->resourceScopes[$resourceId];
        if (! $scope instanceof PortalScope) {
            return;
        }

        $this->schedule([$scope], $areas);
    }

    public function isPublished(Resource $resource): bool
    {
        if ($resource->relationLoaded('landingPage')) {
            return $resource->landingPage?->is_published === true;
        }

        return $resource->landingPage()
            ->where('is_published', true)
            ->exists();
    }

    public function scopeForResource(Resource $resource): PortalScope
    {
        $resource->loadMissing('resourceType:id,slug');

        return $resource->resourceType?->slug === PortalScope::PHYSICAL_SAMPLE_RESOURCE_TYPE
            ? PortalScope::IGSN
            : PortalScope::DOI;
    }

    public function scopeForResourceTypeId(int|string|null $resourceTypeId): PortalScope
    {
        $physicalObjectTypeId = $this->resourceCacheService->getPhysicalObjectTypeId();

        return $physicalObjectTypeId !== null && (int) $resourceTypeId === $physicalObjectTypeId
            ? PortalScope::IGSN
            : PortalScope::DOI;
    }

    public function flushPending(): void
    {
        $pending = $this->pending;
        $this->reset();

        foreach ($pending as $scopeValue => $areas) {
            $scope = PortalScope::from($scopeValue);

            foreach (array_keys($areas) as $areaValue) {
                $this->invalidate($scope, PortalCacheArea::from($areaValue));
            }
        }
    }

    private function invalidate(PortalScope $scope, PortalCacheArea $area): void
    {
        match ($area) {
            PortalCacheArea::PAGE => $this->pageCache->flush($scope),
            PortalCacheArea::COUNT => $this->versionService->invalidate(CacheKey::PORTAL_LISTING_COUNT, $scope),
            PortalCacheArea::RESOURCE_TYPE_FACETS => $this->invalidateFacet(CacheKey::PORTAL_RESOURCE_TYPE_FACETS, $scope),
            PortalCacheArea::DATACENTER_FACETS => $this->invalidateFacet(CacheKey::PORTAL_DATACENTER_FACETS, $scope),
            PortalCacheArea::IGSN_FACETS => $this->versionService->invalidate(CacheKey::PORTAL_IGSN_FACETS, $scope),
            PortalCacheArea::TEMPORAL_RANGE => $this->invalidateFacet(CacheKey::PORTAL_TEMPORAL_RANGE, $scope),
            PortalCacheArea::KEYWORDS => $this->keywordSuggestionService->invalidateCache($scope),
            PortalCacheArea::MAP_PAYLOAD => $this->mapCache->flushPayload($scope),
            PortalCacheArea::MAP_EXTENT => $this->mapCache->flushExtent($scope),
        };
    }

    private function invalidateFacet(CacheKey $cacheKey, PortalScope $scope): void
    {
        $this->versionService->invalidate($cacheKey, $scope);
        $this->versionService->invalidate($cacheKey, null);

        // Remove pre-generation keys left by older deployments.
        $cacheKey->forget($scope->value);
        $cacheKey->forget();
    }

    private function reset(): void
    {
        $this->pending = [];
        $this->scheduled = false;
        $this->resourceScopes = [];
    }
}
