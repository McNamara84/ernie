<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalScope;
use App\Models\AlternateIdentifier;
use App\Services\Resources\ResourcePartySearchMatchService;
use App\Support\PortalIgsnSearchPattern;
use Illuminate\Database\Eloquent\Collection;
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
        private readonly ResourcePartySearchMatchService $partySearchMatchService,
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

        $resourceModels = new Collection($paginator->items());
        $query = is_string($filters['query']) ? $filters['query'] : null;
        $igsnPattern = $scope === PortalScope::IGSN && $query !== null
            ? new PortalIgsnSearchPattern(trim($query))
            : null;
        $matchesByResource = $igsnPattern?->isMatchAll() === true
            ? []
            : $this->partySearchMatchService->resolveNames(
                $resourceModels,
                $query,
                allowWildcard: $scope === PortalScope::IGSN,
            );
        $sampleNameMatches = $igsnPattern !== null && ! $igsnPattern->isMatchAll()
            ? $this->sampleNameMatches($resourceModels, $igsnPattern)
            : [];

        $resources = $resourceModels
            ->map(fn ($resource): array => [
                ...$this->searchService->transformForPortal($resource),
                'searchMatches' => $matchesByResource[$resource->id] ?? [],
                'sampleNameMatches' => $sampleNameMatches[$resource->id] ?? [],
            ])
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

    /**
     * @param  Collection<int, \App\Models\Resource>  $resources
     * @return array<int, list<array{label: string, display_value: string}>>
     */
    private function sampleNameMatches(Collection $resources, PortalIgsnSearchPattern $pattern): array
    {
        if ($resources->isEmpty()) {
            return [];
        }

        $matches = [];
        $seen = [];
        $identifiers = AlternateIdentifier::query()
            ->whereIn('resource_id', $resources->modelKeys())
            ->whereIn('type', ['Local accession number', 'Local sample name'])
            ->orderBy('resource_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($identifiers as $identifier) {
            if (! $pattern->matches($identifier->value)) {
                continue;
            }

            $key = $identifier->type."\0".mb_strtolower($identifier->value);
            if (isset($seen[$identifier->resource_id][$key])) {
                continue;
            }

            $seen[$identifier->resource_id][$key] = true;
            $matches[$identifier->resource_id][] = [
                'label' => $identifier->type,
                'display_value' => $identifier->value,
            ];
        }

        return $matches;
    }
}
