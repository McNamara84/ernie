<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CacheKey;
use App\Enums\PortalScope;
use App\Services\BotProtection\PortalMapCacheService;
use App\Services\BotProtection\PortalPageCacheService;
use App\Services\IgsnPortalFacetService;
use App\Services\KeywordSuggestionService;
use App\Services\ListingCountService;
use App\Services\PortalCacheVersionService;
use App\Services\PortalFilterService;
use App\Services\PortalMapService;
use App\Services\PortalPayloadService;
use App\Services\PortalSearchService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

final class WarmPortalCache extends Command
{
    /** @var string */
    protected $signature = 'portal:cache-warm
        {--scope=all : all, doi or igsn}
        {--area=* : page, count, facets or map-extent; omit for all}';

    /** @var string */
    protected $description = 'Warm the standard public portal caches without making HTTP requests';

    private const AREAS = ['page', 'count', 'facets', 'map-extent'];

    public function __construct(
        private readonly PortalPageCacheService $pageCache,
        private readonly PortalMapCacheService $mapCache,
        private readonly PortalPayloadService $payloadService,
        private readonly PortalSearchService $searchService,
        private readonly PortalFilterService $filterService,
        private readonly ListingCountService $listingCountService,
        private readonly PortalCacheVersionService $cacheVersionService,
        private readonly KeywordSuggestionService $keywordService,
        private readonly IgsnPortalFacetService $igsnFacetService,
        private readonly PortalMapService $mapService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $scopes = $this->scopes();
        $areas = $this->areas();

        if ($scopes === null || $areas === null) {
            return self::FAILURE;
        }

        $startedAt = microtime(true);

        foreach ($scopes as $scope) {
            $this->warm($scope, $areas);
            $this->components->info("Warmed {$scope->value} portal cache.");
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $this->components->info("Portal cache warm-up completed in {$duration} ms.");

        return self::SUCCESS;
    }

    /** @return list<PortalScope>|null */
    private function scopes(): ?array
    {
        $scope = strtolower((string) $this->option('scope'));

        if ($scope === 'all') {
            return PortalScope::cases();
        }

        $resolved = PortalScope::tryFrom($scope);
        if ($resolved === null) {
            $this->components->error('The --scope option must be all, doi or igsn.');

            return null;
        }

        return [$resolved];
    }

    /** @return list<string>|null */
    private function areas(): ?array
    {
        $areas = $this->option('area');
        $areas = array_values(array_unique(array_filter(
            array_map(static fn (?string $area): string => (string) $area, $areas),
            static fn (string $area): bool => $area !== '',
        )));
        $areas = $areas === [] ? self::AREAS : $areas;

        $invalid = array_diff($areas, self::AREAS);
        if ($invalid !== []) {
            $this->components->error('Unknown --area value: '.implode(', ', $invalid));

            return null;
        }

        return $areas;
    }

    /** @param list<string> $areas */
    private function warm(PortalScope $scope, array $areas): void
    {
        $request = Request::create($scope->basePath(), 'GET');
        $temporalRange = $this->searchService->getTemporalRange($scope);
        $filters = $this->filterService->fromRequest($request, $temporalRange, $scope);

        if (in_array('page', $areas, true)) {
            $this->pageCache->remember(
                $request,
                fn (): array => $this->payloadService->build($request, $scope),
                $scope,
            );
        }

        if (in_array('count', $areas, true)) {
            $criteria = [
                ...$filters,
                '_portal_cache_version' => $this->cacheVersionService->current(
                    CacheKey::PORTAL_LISTING_COUNT,
                    $scope,
                ),
            ];
            $this->listingCountService->remember(
                CacheKey::PORTAL_LISTING_COUNT,
                $criteria,
                fn (): int => $this->searchService->count($filters),
            );
        }

        if (in_array('facets', $areas, true)) {
            $this->warmFacets($scope, $filters);
        }

        if (in_array('map-extent', $areas, true)) {
            $this->mapCache->rememberExtent(
                $filters,
                fn (): array => $this->mapService->calculateExtent($filters),
                $scope,
            );
        }
    }

    /** @param array<string, mixed> $filters */
    private function warmFacets(PortalScope $scope, array $filters): void
    {
        $this->searchService->getTemporalRange($scope);
        $this->searchService->getDatacenterFacets($scope);

        if ($scope === PortalScope::DOI) {
            $this->searchService->getResourceTypeFacets($scope);
            $this->keywordService->getThesaurusFacets($scope);

            return;
        }

        $this->igsnFacetService->getFacets($filters);
    }
}
