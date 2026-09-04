<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Enums\Igsn\IgsnClassificationType;
use App\Enums\PortalScope;
use App\Services\Igsn\IgsnClassificationVocabularyService;
use App\Services\Igsn\IgsnMaterialHierarchyService;
use App\Support\PortalCacheNamespace;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class IgsnPortalFacetService
{
    use ChecksCacheTagging;

    public function __construct(
        private readonly PortalSearchService $searchService,
        private readonly IgsnMaterialHierarchyService $materialHierarchyService,
        private readonly IgsnClassificationVocabularyService $classificationVocabularyService,
        private readonly FlexibleCacheService $flexibleCache,
        private readonly PortalCacheVersionService $versionService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     sampleTypes: list<array{value: string, label: string, count: int}>,
     *     materials: list<array{value: string, label: string, count: int, children: list<mixed>}>,
     *     classifications: list<array{type: string, label: string, options: list<array{value: string, label: string, count: int}>}>,
     *     geologicalAges: list<array{value: string, label: string, count: int}>,
     *     geologicalUnits: list<array{value: string, label: string, count: int}>,
     *     datacenters: list<array{name: string, count: int}>
     * }
     */
    public function getFacets(array $filters): array
    {
        $cacheKey = CacheKey::PORTAL_IGSN_FACETS;
        $scope = PortalScope::IGSN;

        /** @var array{sampleTypes: list<array{value: string, label: string, count: int}>, materials: list<array{value: string, label: string, count: int, children: list<mixed>}>, classifications: list<array{type: string, label: string, options: list<array{value: string, label: string, count: int}>}>, geologicalAges: list<array{value: string, label: string, count: int}>, geologicalUnits: list<array{value: string, label: string, count: int}>, datacenters: list<array{name: string, count: int}>} */
        return $this->flexibleCache->remember(
            $this->getCacheInstance(PortalCacheNamespace::tags($cacheKey, $scope)),
            PortalCacheNamespace::versionedKey(
                $cacheKey,
                $scope,
                $this->versionService->current($cacheKey, $scope),
                $this->fingerprint($filters),
            ),
            intdiv($cacheKey->ttl(), 2),
            $cacheKey->ttl(),
            fn (): array => $this->buildFacets($filters),
            (int) config('bot_protection.portal_cache_lock_seconds', 15),
            (int) config('bot_protection.portal_cache_lock_wait_seconds', 10),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     sampleTypes: list<array{value: string, label: string, count: int}>,
     *     materials: list<array{value: string, label: string, count: int, children: list<mixed>}>,
     *     classifications: list<array{type: string, label: string, options: list<array{value: string, label: string, count: int}>}>,
     *     geologicalAges: list<array{value: string, label: string, count: int}>,
     *     geologicalUnits: list<array{value: string, label: string, count: int}>,
     *     datacenters: list<array{name: string, count: int}>
     * }
     */
    private function buildFacets(array $filters): array
    {
        $sampleTypes = $this->valueFacets(
            $this->without($filters, 'sample_types'),
            'igsn_metadata',
            'sample_type',
            $filters['sample_types'] ?? [],
        );
        $materialCounts = $this->valueCounts(
            $this->without($filters, 'materials'),
            'igsn_metadata',
            'material',
        );

        return [
            'sampleTypes' => $sampleTypes,
            'materials' => $this->materialHierarchyService->buildTree(
                $materialCounts,
                $this->stringList($filters['materials'] ?? []),
            ),
            'classifications' => $this->classificationFacets($filters),
            'geologicalAges' => $this->valueFacets(
                $filters,
                'igsn_geological_ages',
                'value',
                $filters['geological_ages'] ?? [],
            ),
            'geologicalUnits' => $this->valueFacets(
                $filters,
                'igsn_geological_units',
                'value',
                $filters['geological_units'] ?? [],
            ),
            'datacenters' => $this->datacenterFacets($this->without($filters, 'datacenter'), $filters['datacenter'] ?? []),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function cacheKeyForFilters(array $filters): string
    {
        $cacheKey = CacheKey::PORTAL_IGSN_FACETS;
        $scope = PortalScope::IGSN;

        return PortalCacheNamespace::versionedKey(
            $cacheKey,
            $scope,
            $this->versionService->current($cacheKey, $scope),
            $this->fingerprint($filters),
        );
    }

    /** @param array<string, mixed> $filters */
    private function fingerprint(array $filters): string
    {
        unset($filters['page'], $filters['per_page']);

        return hash('sha256', json_encode(
            $this->normalize($filters),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $normalized = array_map(fn (mixed $item): mixed => $this->normalize($item), $value);

            if (array_all($normalized, static fn (mixed $item): bool => is_scalar($item) || $item === null)) {
                usort($normalized, static fn (mixed $left, mixed $right): int => strcmp((string) $left, (string) $right));
            }

            return $normalized;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            if ($item === null || $item === '' || $item === []) {
                unset($value[$key]);

                continue;
            }

            $value[$key] = $this->normalize($item);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>|mixed  $selectedValues
     * @return list<array{value: string, label: string, count: int}>
     */
    private function valueFacets(array $filters, string $table, string $column, mixed $selectedValues): array
    {
        $counts = $this->valueCounts($filters, $table, $column);

        foreach ($this->stringList($selectedValues) as $selectedValue) {
            $counts[$selectedValue] ??= 0;
        }

        $facets = [];
        foreach ($counts as $value => $count) {
            $facets[] = [
                'value' => $value,
                'label' => $value,
                'count' => $count,
            ];
        }

        usort($facets, static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));

        return $facets;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function valueCounts(array $filters, string $table, string $column): array
    {
        $rows = $this->facetQuery($filters, $table)
            ->whereNotNull("{$table}.{$column}")
            ->where("{$table}.{$column}", '<>', '')
            ->select("{$table}.{$column} as value")
            ->selectRaw('COUNT(DISTINCT filtered_resources.id) as resources_count')
            ->groupBy("{$table}.{$column}")
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->value] = (int) $row->resources_count;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{type: string, label: string, options: list<array{value: string, label: string, count: int}>}>
     */
    private function classificationFacets(array $filters): array
    {
        $rows = $this->facetQuery($filters, 'igsn_classifications')
            ->whereNotNull('igsn_classifications.value')
            ->whereRaw("TRIM(igsn_classifications.value) <> ''")
            ->select([
                'igsn_classifications.classification_type as classification_type',
                'igsn_classifications.value as value',
            ])
            ->selectRaw('COUNT(DISTINCT filtered_resources.id) as resources_count')
            ->groupBy('igsn_classifications.classification_type', 'igsn_classifications.value')
            ->get();

        /** @var array<string, array<string, array{value: string, label: string, count: int}>> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $type = $this->classificationType((string) ($row->classification_type ?? ''));
            $value = (string) $row->value;
            $grouped[$type][$value] = [
                'value' => $value,
                'label' => str_replace('>', ' › ', $value),
                'count' => (int) $row->resources_count,
            ];
        }

        foreach ($this->stringList($filters['classifications'] ?? []) as $selectedValue) {
            $alreadyPresent = array_any(
                $grouped,
                static fn (array $options): bool => isset($options[$selectedValue]),
            );

            if (! $alreadyPresent) {
                $classificationType = $this->classificationVocabularyService->uniqueTypeFor($selectedValue);
                $type = $classificationType instanceof IgsnClassificationType
                    ? $classificationType->value
                    : 'unclassified';
                $grouped[$type][$selectedValue] = [
                    'value' => $selectedValue,
                    'label' => str_replace('>', ' › ', $selectedValue),
                    'count' => 0,
                ];
            }
        }

        $groups = [];
        foreach (['rock', 'mineral', 'biology', 'unclassified'] as $type) {
            $options = array_values($grouped[$type] ?? []);
            if ($options === []) {
                continue;
            }

            usort($options, static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));
            $groups[] = [
                'type' => $type,
                'label' => $this->classificationTypeLabel($type),
                'options' => $options,
            ];
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{name: string, count: int}>
     */
    private function datacenterFacets(array $filters, mixed $selectedNames): array
    {
        $rows = $this->filteredResources($filters)
            ->join('datacenters', 'datacenters.id', '=', 'filtered_resources.datacenter_id')
            ->whereNotNull('datacenters.name')
            ->whereRaw("TRIM(datacenters.name) <> ''")
            ->select('datacenters.name')
            ->selectRaw('COUNT(DISTINCT filtered_resources.id) as resources_count')
            ->groupBy('datacenters.id', 'datacenters.name')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->name] = (int) $row->resources_count;
        }
        foreach ($this->stringList($selectedNames) as $selectedName) {
            $counts[$selectedName] ??= 0;
        }

        $facets = [];
        foreach ($counts as $name => $count) {
            $facets[] = ['name' => $name, 'count' => $count];
        }

        usort($facets, static function (array $left, array $right): int {
            $countComparison = $right['count'] <=> $left['count'];

            return $countComparison !== 0 ? $countComparison : strnatcasecmp($left['name'], $right['name']);
        });

        return $facets;
    }

    /** @param  array<string, mixed>  $filters */
    private function facetQuery(array $filters, string $table): QueryBuilder
    {
        return $this->filteredResources($filters)
            ->join($table, "{$table}.resource_id", '=', 'filtered_resources.id');
    }

    /** @param  array<string, mixed>  $filters */
    private function filteredResources(array $filters): QueryBuilder
    {
        $resourceQuery = $this->searchService
            ->buildFilteredResourceQuery($filters)
            ->select(['resources.id', 'resources.datacenter_id']);

        return DB::query()->fromSub($resourceQuery, 'filtered_resources');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function without(array $filters, string $key): array
    {
        $filters[$key] = [];

        return $filters;
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        return array_values(array_filter((array) $values, 'is_string'));
    }

    private function classificationType(string $type): string
    {
        $classificationType = IgsnClassificationType::tryFrom($type);

        return $classificationType instanceof IgsnClassificationType
            ? $classificationType->value
            : 'unclassified';
    }

    private function classificationTypeLabel(string $type): string
    {
        return match ($type) {
            'rock' => 'Rock',
            'mineral' => 'Mineral',
            'biology' => 'Biology',
            default => 'Unclassified',
        };
    }
}
