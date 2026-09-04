<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalScope;
use Illuminate\Http\Request;

/** Builds the initial public portal payload for HTTP and cache warm-up callers. */
final class PortalPayloadService
{
    public function __construct(
        private readonly PortalSearchService $searchService,
        private readonly KeywordSuggestionService $keywordService,
        private readonly PortalFilterService $filterService,
        private readonly ListingCountService $listingCountService,
        private readonly IgsnPortalFacetService $igsnFacetService,
    ) {}

    /** @return array<string, mixed> */
    public function build(Request $request, PortalScope $scope): array
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
