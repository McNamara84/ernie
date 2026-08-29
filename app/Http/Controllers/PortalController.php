<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\BotProtection\PortalPageCacheService;
use App\Services\KeywordSuggestionService;
use App\Services\ListingCountService;
use App\Services\PortalFilterService;
use App\Services\PortalSearchService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for the public portal page.
 *
 * The initial response deliberately contains only the paginated result list.
 * Map features are loaded asynchronously from PortalMapController so the page
 * payload remains bounded as the number of published resources grows.
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly PortalSearchService $searchService,
        private readonly KeywordSuggestionService $keywordService,
        private readonly PortalPageCacheService $pageCache,
        private readonly PortalFilterService $filterService,
        private readonly ListingCountService $listingCountService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('portal', $this->pageCache->remember(
            $request,
            fn (): array => $this->buildPortalPayload($request),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPortalPayload(Request $request): array
    {
        $temporalRange = $this->searchService->getTemporalRange();
        $filters = $this->filterService->fromRequest($request, $temporalRange);
        $paginator = $this->searchService->simpleSearch($filters);
        $filterFingerprint = $this->listingCountService->fingerprint($filters);

        $resources = collect($paginator->items())
            ->map(fn ($resource) => $this->searchService->transformForPortal($resource))
            ->all();

        return [
            'resources' => $resources,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => null,
                'per_page' => $paginator->perPage(),
                'total' => null,
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
                'has_more' => $paginator->hasMorePages(),
                'count_status' => 'pending',
                'filter_fingerprint' => $filterFingerprint,
            ],
            'filters' => $this->filterService->forFrontend($filters),
            'keywordSuggestions' => $this->keywordService->getFreeKeywordSuggestions(),
            'thesaurusFacets' => $this->keywordService->getThesaurusFacets(),
            'temporalRange' => $temporalRange,
            'resourceTypeFacets' => $this->searchService->getResourceTypeFacets(),
            'datacenterFacets' => $this->searchService->getDatacenterFacets(),
        ];
    }
}
