<?php

declare(strict_types=1);

namespace App\Services\DateType;

use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;
use App\Services\DateType\DateTypeSchemaorgExtraction;
use Closure;

final class DateTypeDiscoveryService
{
    public const string ASSISTANT_ID = 'date-type-suggestion';

    public const string TARGET_TYPE = 'date_type';

    public const string GEOLOCATION_COUNT_TARGET_TYPE = 'resource_date_geolocation_count';

    private const int CHUNK_SIZE = 50;

    /** @var array<int, string> */
    private const array COLLECTED_DATE_TYPES = ['Collected', 'collected'];

    public function __construct(
        private readonly DateTypeSchemaorgExtraction $extractService,
        private readonly DateTypePlausibilityService $plausibilityService,
    ) {}

    public static function targetTypeForDateType(string $dateType): string
    {
        return self::TARGET_TYPE.':'.$dateType;
    }

    public static function isDateTypeTargetType(string $targetType): bool
    {
        return $targetType === self::TARGET_TYPE
            || str_starts_with($targetType, self::TARGET_TYPE.':');
    }

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     * @param  Closure(string): void  $onProgress
     */      
    public function discover(string $assistantId, Closure $storeSuggestion, Closure $onProgress): int
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
            ->chunkById(self::CHUNK_SIZE, function ($resources) use ( &$count, &$processed, $total, $assistantId, $storeSuggestion, $onProgress) : void {
                /** @var iterable<int, Resource> $resources */
                foreach ($resources as $resource) {
                    $processed++; 
                    $onProgress("Checking resource {$processed} of {$total}");
                    $count += $this->discoverForResource($assistantId, $resource, $storeSuggestion);
                    if ($this->storeMatchedCountSuggestion($resource, $storeSuggestion)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     */
    private function discoverForResource(string $assistantId, Resource $resource, Closure $storeSuggestion): int
    {
        $storedCount = 0;

        $existingDates = $resource->dates()
            ->with('dateType')
            ->get();

        $datesForHint = [];

        foreach ($existingDates as $date) {
            $dateType = $date->dateType?->slug;
            $value = $date->date_value ?? $date->start_date;

            if ($dateType !== null && $value !== null) {
                $datesForHint[$dateType] = $value;
            }
        }

        $hintSuggestions = $this->plausibilityService->hint($datesForHint, $resource->doi,);

        $suggestions = [
            ...$this->lookupSchemaorgDates($resource),
            ...$hintSuggestions,

        ];

        $existingDateTypes = $resource->dates()
            ->with('dateType')
            ->get()
            ->pluck('dateType.slug')
            ->filter()
            ->all();

        $hasCreated = in_array('Created', $existingDateTypes, true) || in_array('created', $existingDateTypes, true);
        $hasIssued = in_array('Issued', $existingDateTypes, true) || in_array('issued', $existingDateTypes, true);

        foreach ($suggestions as $suggestion) 
        {
            if (($suggestion['probe_method'] ?? null) === 'SKIP') {
                continue;
            }

            if (($suggestion['suggestion_kind'] ?? null) === 'hint') {
                $stored = $storeSuggestion(
                    $resource->id,
                    self::TARGET_TYPE,
                    $resource->id,
                    (string) $suggestion['message'],
                    (string) $suggestion['message'],
                    $this->confidenceToScore($suggestion['confidence'] ?? null),
                    $suggestion,
                );

                if ($stored) {
                    $storedCount++;
                }

                continue;
            }

            $type = (string) ($suggestion['target_date_type'] ?? '');

            if ($type === 'Created' && $hasCreated ) {
                continue;
            }

            if ($type === 'Issued' && $hasIssued) {
                continue;
            }

            if (! in_array($type, ['Created', 'Issued', 'Coverage'], true)) {
                continue;
            }

            $suggestedValue = (string) ($suggestion['normalized_value'] ?? '');
            if ($suggestedValue === '') {
                continue;
            }

            $metadata = $suggestion;

            $stored = $storeSuggestion(
                $resource->id,
                self::targetTypeForDateType($type),
                $resource->id,
                $suggestedValue,
                strtoupper($type).': '.$suggestedValue,
                $this->confidenceToScore($suggestion['confidence'] ?? null),
                $metadata,
            );

            if ($stored) {
                $storedCount++;
            }
        }

        return $storedCount;
    }

    /** @return Builder<Resource> */
    private function candidateQuery(): Builder
    {
        /** @var Builder<Resource> $query */
        $query = Resource::query()
            ->whereNotNull('doi')
            ->whereDoesntHave('igsnMetadata')
            ->whereDoesntHave('resourceType', fn (Builder $query): Builder => $query->where('slug', 'physical-object'))
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('dates', function (Builder $query): void 
                {
                    $query->whereHas('dateType', function (Builder $query): void  
                    {
                        $query->whereIn('slug', ['Created', 'created']);
                    });
                })
                ->orWhereDoesntHave('dates', function (Builder $query): void 
                {
                    $query->whereHas('dateType', function (Builder $query): void  
                    {
                        $query->whereIn('slug', ['Issued', 'issued']); 
                    });
                })
                ->orWhereHas('dates', function (Builder $query): void 
                {
                    $query->whereHas('dateType', function (Builder $query): void 
                    {
                        $query->whereIn('slug', self::COLLECTED_DATE_TYPES);
                    });
                });
            });

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

        if ($collectedDatesCount === 0 || $geoLocationsCount === 0) {
            return false;
        }

        $suggestedValue = "collected_dates:{$collectedDatesCount};geo_locations:{$geoLocationsCount}";
        $suggestedLabel = "Collected dates ({$collectedDatesCount}) match geolocations ({$geoLocationsCount})";

        return $storeSuggestion(
            $resource->id,
            self::GEOLOCATION_COUNT_TARGET_TYPE,
            $resource->id,
            $suggestedValue,
            $suggestedLabel,
            null,
            [
                'suggestion_kind' => 'correction',
                'from_date_type' => 'Collected',
                'target_date_type' => 'Coverage',
                'confidence' => 'medium',
                'source' => 'database',
                'check' => 'collected_dates_vs_geolocations',
                'resource_doi' => $resource->doi,
                'source_url' => 'https://doi.org/'.$resource->doi,
                'collected_dates_count' => $collectedDatesCount,
                'geo_locations_count' => $geoLocationsCount,
                'evidence' => 'The resource has a DOI and the same number of Collected date entries as geolocation entries.',
            ],
        );
    }


     /** 
      * @return array<int, array<string, mixed>>
     */
    private function lookupSchemaorgDates(Resource $resource): array
    {
        $doi = trim((string) $resource->doi);

        if ($doi === '') 
        {
            return [];
        }

        return $this->extractService->loadAllowedSchemaorg($doi);
    }

    private function confidenceToScore(mixed $confidence): ?float
    {
        return match ($confidence) {
            'high' => 0.95,
            'medium' => 0.65,
            'low' => 0.35,
            default => null,
        };
    }
}
