<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PortalScope;
use App\Models\Resource;
use App\Services\Citations\LandingPageCitationService;
use App\Support\IgsnIdentifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use RuntimeException;

/** Builds the intentionally small, on-demand detail payload for a portal row. */
final class PortalResourcePreviewService
{
    private const string CITATION_STYLE = 'apa-7';

    public function __construct(
        private readonly PortalSearchService $searchService,
        private readonly LandingPageCitationService $citationService,
        private readonly PreferredAbstractResolverService $abstractResolver,
    ) {}

    /**
     * @return array{
     *     resourceId: int,
     *     citation: array{styleId: string, label: string, text: string},
     *     abstract: string|null
     * }|null
     */
    public function find(int $resourceId, PortalScope $scope): ?array
    {
        $resource = $this->searchService
            ->buildFilteredResourceQuery(['portal_scope' => $scope->value])
            ->with([
                'titles.titleType',
                'creators.creatorable',
                'resourceType',
                'publisher',
                'language:id,code',
                'descriptions' => function (Relation $descriptionRelation): void {
                    $descriptionRelation->getQuery()
                        ->select(['id', 'resource_id', 'value', 'description_type_id', 'language'])
                        ->whereHas('descriptionType', function (Builder $typeQuery): void {
                            $typeQuery->where('slug', 'Abstract');
                        })
                        ->with(['descriptionType:id,slug']);
                },
            ])
            ->find($resourceId);

        if (! $resource instanceof Resource) {
            return null;
        }

        $citation = $this->citationService->formatStyle($resource, self::CITATION_STYLE);
        if ($citation['available'] !== true || ! is_string($citation['text']) || trim($citation['text']) === '') {
            throw new RuntimeException('The portal citation preview could not be rendered.');
        }

        return [
            'resourceId' => $resource->id,
            'citation' => [
                'styleId' => $citation['id'],
                'label' => $citation['label'],
                'text' => $this->displayCitation($citation['text'], $resource, $scope),
            ],
            'abstract' => $this->abstractResolver->resolve($resource),
        ];
    }

    private function displayCitation(string $citation, Resource $resource, PortalScope $scope): string
    {
        if ($scope !== PortalScope::IGSN || $resource->doi === null) {
            return $citation;
        }

        $displayIdentifier = IgsnIdentifier::handleFromDoi($resource->doi);
        if ($displayIdentifier === null) {
            return $citation;
        }

        $quotedDoi = preg_quote($resource->doi, '~');
        $citation = preg_replace(
            "~https?://(?:dx\\.)?doi\\.org/{$quotedDoi}~i",
            $displayIdentifier,
            $citation,
        ) ?? $citation;

        return preg_replace("~{$quotedDoi}~i", $displayIdentifier, $citation) ?? $citation;
    }
}
