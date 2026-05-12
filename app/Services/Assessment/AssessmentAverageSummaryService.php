<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\CacheKey;
use App\Models\ResourceAssessment;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Database\Query\Builder;

class AssessmentAverageSummaryService
{
    use ChecksCacheTagging;

    private const NO_PHYSICAL_OBJECT_TYPE_CACHE_SUFFIX = 'none';

    /**
     * @return array{resources: float|null, igsns: float|null, formatted: string|null}
     */
    public function getSidebarSummary(?int $physicalObjectTypeId): array
    {
        /** @var array{resources: float|null, igsns: float|null, formatted: string|null} $summary */
        $summary = $this->getCacheInstance(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->tags())
            ->remember(
                CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key($this->resolveCacheSuffix($physicalObjectTypeId)),
                CacheKey::ASSESSMENT_AVERAGE_SUMMARY->ttl(),
                fn (): array => $this->buildSidebarSummary($physicalObjectTypeId),
            );

        return $summary;
    }

    /**
     * @return array{resources: float|null, igsns: float|null, formatted: string|null}
     */
    private function buildSidebarSummary(?int $physicalObjectTypeId): array
    {
        $resourceAverage = $this->resolveAverageForResources($physicalObjectTypeId);
        $igsnAverage = $this->resolveAverageForIgsns($physicalObjectTypeId);

        return [
            'resources' => $resourceAverage,
            'igsns' => $igsnAverage,
            'formatted' => $this->formatSummary($resourceAverage, $igsnAverage),
        ];
    }

    private function resolveAverageForResources(?int $physicalObjectTypeId): ?float
    {
        $query = $this->baseCompletedAssessmentsQuery();

        if ($physicalObjectTypeId !== null) {
            $query->where(function (Builder $builder) use ($physicalObjectTypeId): void {
                $builder->whereNull('resources.resource_type_id')
                    ->orWhere('resources.resource_type_id', '!=', $physicalObjectTypeId);
            });
        }

        return $this->normalizeAverage($query->avg('resource_assessments.total_score'));
    }

    private function resolveAverageForIgsns(?int $physicalObjectTypeId): ?float
    {
        if ($physicalObjectTypeId === null) {
            return null;
        }

        $query = $this->baseCompletedAssessmentsQuery()
            ->where('resources.resource_type_id', $physicalObjectTypeId);

        return $this->normalizeAverage($query->avg('resource_assessments.total_score'));
    }

    private function formatSummary(?float $resourceAverage, ?float $igsnAverage): ?string
    {
        if ($resourceAverage === null && $igsnAverage === null) {
            return null;
        }

        return sprintf('%s / %s', $this->formatAverage($resourceAverage), $this->formatAverage($igsnAverage));
    }

    private function formatAverage(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        return number_format($value, 1, '.', '');
    }

    private function normalizeAverage(float|int|string|null $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round((float) $value, 1);
    }

    private function resolveCacheSuffix(?int $physicalObjectTypeId): string
    {
        $scopeSuffix = $physicalObjectTypeId === null
            ? self::NO_PHYSICAL_OBJECT_TYPE_CACHE_SUFFIX
            : (string) $physicalObjectTypeId;

        return sprintf('%s:v%d', $scopeSuffix, $this->currentCacheVersion());
    }

    private function currentCacheVersion(): int
    {
        return app(AssessmentAverageSummaryVersionService::class)->current();
    }

    private function baseCompletedAssessmentsQuery(): Builder
    {
        return ResourceAssessment::query()
            ->join('resources', 'resources.id', '=', 'resource_assessments.resource_id')
            ->where('resource_assessments.status', ResourceAssessment::STATUS_COMPLETED)
            ->whereNotNull('resource_assessments.total_score')
            ->toBase();
    }
}