<?php

declare(strict_types=1);

namespace App\Services\DateType;

use App\Models\Resource;
use Closure;
use Illuminate\Database\Eloquent\Builder;

final class DateTypeSuggestionDiscoveryService
{
    public const string TARGET_TYPE = 'resource_date_geolocation_count';

    private const int CHUNK_SIZE = 100;
    /** @var array<int, string> */
    private const array COLLECTED_DATE_TYPES = ['Collected', 'collected'];

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     * @param  Closure(string): void  $onProgress
     */
    public function discover(Closure $storeSuggestion, Closure $onProgress): int
    {
        $count = 0;
        $processed = 0;
        $query = $this->candidateQuery();
        $total = (clone $query)->count();

        $query
            ->withCount([
                'dates as collected_dates_count' => fn (Builder $query): Builder => $query
                    ->whereHas('dateType', fn (Builder $dateTypeQuery): Builder => $dateTypeQuery
                        ->whereIn('slug', self::COLLECTED_DATE_TYPES)),
                'geoLocations as geo_locations_count',
            ])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($resources) use (&$count, &$processed, $total, $storeSuggestion, $onProgress): void {
                /** @var iterable<int, Resource> $resources */
                foreach ($resources as $resource) {
                    $processed++;
                    $onProgress("Checking resource {$processed} of {$total}");

                    if ($this->storeMatchedCountSuggestion($resource, $storeSuggestion)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /** @return Builder<Resource> */
    private function candidateQuery(): Builder
    {
        /** @var Builder<Resource> $query */
        $query = Resource::query()
            ->whereNotNull('doi')
            ->whereHas('dates.dateType', fn (Builder $query): Builder => $query
                ->whereIn('slug', self::COLLECTED_DATE_TYPES));

        return $query;
    }

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     */
    private function storeMatchedCountSuggestion(Resource $resource, Closure $storeSuggestion): bool
    {
        $collectedDatesCount = (int) $resource->getAttribute('collected_dates_count');
        $geoLocationsCount = (int) $resource->getAttribute('geo_locations_count');

        if ($collectedDatesCount !== $geoLocationsCount) {
            return false;
        }

        $suggestedValue = "collected_dates:{$collectedDatesCount};geo_locations:{$geoLocationsCount}";
        $suggestedLabel = "Collected dates ({$collectedDatesCount}) match geolocations ({$geoLocationsCount})";

        return $storeSuggestion(
            $resource->id,
            self::TARGET_TYPE,
            $resource->id,
            $suggestedValue,
            $suggestedLabel,
            null,
            [
                'source' => 'database',
                'check' => 'collected_dates_vs_geolocations',
                'resource_doi' => $resource->doi,
                'collected_dates_count' => $collectedDatesCount,
                'geo_locations_count' => $geoLocationsCount,
                'evidence' => 'The resource has a DOI and the same number of Collected date entries as geolocation entries.',
            ],
        );
    }
}
