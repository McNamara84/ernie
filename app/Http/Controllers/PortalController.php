<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PortalScope;
use App\Http\Requests\PortalSearchRequest;
use App\Services\BotProtection\PortalPageCacheService;
use App\Services\IgsnPortalFacetService;
use App\Services\KeywordSuggestionService;
use App\Services\ListingCountService;
use App\Services\PortalFilterService;
use App\Services\PortalSearchService;
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
        private readonly IgsnPortalFacetService $igsnFacetService,
    ) {}

    public function index(PortalSearchRequest $request, string $portalScope): Response
    {
        $scope = PortalScope::from($portalScope);

        return Inertia::render('portal', $this->pageCache->remember(
            $request,
            fn (): array => $this->buildPortalPayload($request, $scope),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPortalPayload(PortalSearchRequest $request, PortalScope $scope): array
    {
        $temporalRange = $this->searchService->getTemporalRange($scope);
        $filters = $this->filterService->fromRequest($request, $temporalRange, $scope);
        $paginator = $this->searchService->simpleSearch($filters);
        $filterFingerprint = $this->listingCountService->fingerprint($filters);
        $igsnFacets = $scope === PortalScope::IGSN
            ? $this->igsnFacetService->getFacets($filters)
            : null;

        $resources = collect($paginator->items())
            ->map(fn ($resource) => $this->searchService->transformForPortal($resource))
            ->all();

        return [
            'portal' => $scope->frontendDescriptor(),
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
            'thesaurusFacets' => $scope === PortalScope::DOI
                ? $this->keywordService->getThesaurusFacets($scope)
                : [],
            'igsnFacets' => $igsnFacets === null ? null : [
                'sampleTypes' => $igsnFacets['sampleTypes'],
                'materials' => $igsnFacets['materials'],
                'classifications' => $igsnFacets['classifications'],
                'geologicalAges' => $igsnFacets['geologicalAges'],
                'geologicalUnits' => $igsnFacets['geologicalUnits'],
            ],
            'temporalRange' => $temporalRange,
            'resourceTypeFacets' => $scope->showsResourceTypeFilter()
                ? $this->searchService->getResourceTypeFacets($scope)
                : [],
            'datacenterFacets' => $igsnFacets['datacenters']
                ?? $this->searchService->getDatacenterFacets($scope),
        ];
    }
}
