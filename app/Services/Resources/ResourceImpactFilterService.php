<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Services\DoiSuggestionService;
use App\Support\ResourceImpactFilter;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final readonly class ResourceImpactFilterService
{
    public function __construct(
        private DoiSuggestionService $doiService,
    ) {}

    /**
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    public function apply(EloquentBuilder|QueryBuilder $query, ResourceImpactFilter $filter, string $resourceTable = 'resources'): void
    {
        $doiExpression = match ($resourceTable) {
            'resources' => 'LOWER(TRIM(resources.doi))',
            'impact_resources' => 'LOWER(TRIM(impact_resources.doi))',
            default => throw new \InvalidArgumentException('Unsupported resource table alias.'),
        };

        if ($filter->doi !== null) {
            $baseQuery = $query instanceof EloquentBuilder ? $query->getQuery() : $query;

            $query->whereIn(
                $baseQuery->getConnection()->raw($doiExpression),
                $this->doiVariants($filter->doi),
            );
        }

        if ($filter->datacenterId !== null) {
            $query->where($resourceTable.'.datacenter_id', $filter->datacenterId);
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
