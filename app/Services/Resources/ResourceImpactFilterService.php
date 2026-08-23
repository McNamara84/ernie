<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Services\DoiSuggestionService;
use App\Support\ResourceImpactFilter;
use Illuminate\Database\Eloquent\Builder;

final readonly class ResourceImpactFilterService
{
    public function __construct(
        private DoiSuggestionService $doiService,
    ) {}

    /**
     * @param  Builder<*>  $query
     */
    public function apply(Builder $query, ResourceImpactFilter $filter): void
    {
        if ($filter->doi !== null) {
            $query->whereIn(
                $query->getQuery()->raw('LOWER(TRIM(resources.doi))'),
                $this->doiVariants($filter->doi),
            );
        }

        if ($filter->datacenterId !== null) {
            $query->where('resources.datacenter_id', $filter->datacenterId);
        }
    }

    /**
     * @return list<string>
     */
    private function doiVariants(string $doi): array
    {
        $normalized = $this->doiService->normalizeDoi($doi);

        return [
            $normalized,
            'https://doi.org/'.$normalized,
            'http://doi.org/'.$normalized,
            'https://dx.doi.org/'.$normalized,
            'http://dx.doi.org/'.$normalized,
        ];
    }
}
