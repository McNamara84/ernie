<?php

declare(strict_types=1);

namespace App\Services\DateType;

use App\Models\AssistantSuggestion;
use App\Models\DateType;
use App\Models\ResourceDate;
use Illuminate\Support\Facades\DB;

final class DateTypeAcceptanceService
{
    /** @return array{success: bool, message: string} */
    public function accept(AssistantSuggestion $suggestion): array
    {
        if ($suggestion->target_type === DateTypeDiscoveryService::GEOLOCATION_COUNT_TARGET_TYPE) {
            return $this->acceptCollectedCoverageCorrection($suggestion);
        }

        if (! DateTypeDiscoveryService::isDateTypeTargetType($suggestion->target_type)) {
            return [
                'success' => false,
                'message' => 'Unknown suggestion type.',
            ];
        }

        $dateValue = DateTypeNormalizerService::normalize($suggestion->metadata['normalized_value'] ?? $suggestion->suggested_value);

        if (! is_string($dateValue) || $dateValue === '') {
            return [
                'success' => false,
                'message' => 'Suggested date value is invalid.',
            ];
        }

        $metadata = $suggestion->metadata ?? [];
        $targetDateType = $metadata['target_date_type'] ?? null;

        if (! is_string($targetDateType) || $targetDateType === '') {
            return [
                'success' => false,
                'message' => 'Missing target DateType.',
            ];
        }

        $dateType = DateType::where('slug', $targetDateType)->first();

        if ($dateType === null) {
            return [
                'success' => false,
                'message' => 'Target DateType not found.',
            ];
        }

        $dateTypeAlreadyExists = ResourceDate::query()
            ->where('resource_id', $suggestion->resource_id)
            ->whereHas('dateType', fn ($query) => $query->whereIn('slug', array_unique([
                $targetDateType,
                strtolower($targetDateType),
            ])))
            ->exists();

        if ($dateTypeAlreadyExists) {
            return [
                'success' => false,
                'message' => "This suggestion is stale because the resource already has a '{$targetDateType}' date.",
            ];
        }

        if (str_contains($dateValue, '/')) {
            [$startDate, $endDate] = explode('/', $dateValue, 2);

            ResourceDate::firstOrCreate([
                'resource_id' => $suggestion->resource_id,
                'date_type_id' => $dateType->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'date_value' => null,
            ]);

            return [
                'success' => true,
                'message' => "DateType '{$targetDateType}' range '{$dateValue}' applied.",
            ];
        }

        ResourceDate::firstOrCreate([
            'resource_id' => $suggestion->resource_id,
            'date_type_id' => $dateType->id,
            'date_value' => $dateValue,
            'start_date' => null,
            'end_date' => null,
        ]);

        return [
            'success' => true,
            'message' => "DateType '{$targetDateType}' with value '{$dateValue}' applied.",
        ];
    }

    /** @return array{success: bool, message: string} */
    private function acceptCollectedCoverageCorrection(AssistantSuggestion $suggestion): array
    {
        $coverageDateTypeId = DateType::where('slug', 'Coverage')->value('id');

        if ($coverageDateTypeId === null) {
            return [
                'success' => false,
                'message' => 'Coverage DateType not found.',
            ];
        }

        $metadata = $suggestion->metadata ?? [];
        $expectedCollectedCount = filter_var($metadata['collected_dates_count'] ?? null, FILTER_VALIDATE_INT);
        $expectedGeoLocationCount = filter_var($metadata['geo_locations_count'] ?? null, FILTER_VALIDATE_INT);

        if ($expectedCollectedCount === false || $expectedGeoLocationCount === false) {
            preg_match('/^collected_dates:(\d+);geo_locations:(\d+)$/', $suggestion->suggested_value, $matches);
            $expectedCollectedCount = isset($matches[1]) ? (int) $matches[1] : null;
            $expectedGeoLocationCount = isset($matches[2]) ? (int) $matches[2] : null;
        }

        $expectedCollectedDateIds = $this->snapshotIds($metadata['collected_date_ids'] ?? null);
        $expectedCollectedDatesSnapshot = $metadata['collected_dates_snapshot'] ?? null;
        $expectedGeoLocationIds = $this->snapshotIds($metadata['geo_location_ids'] ?? null);

        return DB::transaction(function () use (
            $suggestion,
            $coverageDateTypeId,
            $expectedCollectedCount,
            $expectedGeoLocationCount,
            $expectedCollectedDateIds,
            $expectedCollectedDatesSnapshot,
            $expectedGeoLocationIds,
        ): array {
            $dates = ResourceDate::query()
                ->where('resource_id', $suggestion->resource_id)
                ->whereHas('dateType', fn ($query) => $query->where('slug', 'Collected'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($dates->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No Collected date entries were found for this resource.',
                ];
            }

            if ($expectedCollectedDateIds === null
                || ! is_array($expectedCollectedDatesSnapshot)
                || ! array_is_list($expectedCollectedDatesSnapshot)
                || $expectedGeoLocationIds === null) {
                return [
                    'success' => false,
                    'message' => 'This suggestion is stale because it does not identify the reviewed Collected dates and geolocations.',
                ];
            }

            $currentCollectedDateIds = $dates->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $currentCollectedDatesSnapshot = $dates
                ->map(fn (ResourceDate $date): array => [
                    'id' => (int) $date->id,
                    'date_value' => $date->date_value,
                    'start_date' => $date->start_date,
                    'end_date' => $date->end_date,
                    'date_information' => $date->date_information,
                ])
                ->all();
            $currentGeoLocationIds = $suggestion->resource()
                ->firstOrFail()
                ->geoLocations()
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if (count($currentCollectedDateIds) !== $expectedCollectedCount
                || count($currentGeoLocationIds) !== $expectedGeoLocationCount
                || count($currentCollectedDateIds) !== count($currentGeoLocationIds)) {
                return [
                    'success' => false,
                    'message' => 'This suggestion is stale because the Collected date or geolocation counts changed.',
                ];
            }

            if ($currentCollectedDateIds !== $expectedCollectedDateIds
                || $currentCollectedDatesSnapshot !== $expectedCollectedDatesSnapshot
                || $currentGeoLocationIds !== $expectedGeoLocationIds) {
                return [
                    'success' => false,
                    'message' => 'This suggestion is stale because the reviewed Collected dates or geolocations changed.',
                ];
            }

            // Update the locked, reviewed rows rather than a newly evaluated
            // open-ended set of all current Collected dates.
            $dates->each(fn (ResourceDate $date) => $date->update([
                'date_type_id' => $coverageDateTypeId,
            ]));

            $updatedCount = $dates->count();

            return [
                'success' => true,
                'message' => "Changed {$updatedCount} Collected date entr".($updatedCount === 1 ? 'y' : 'ies').' to Coverage.',
            ];
        });
    }

    /** @return list<int>|null */
    private function snapshotIds(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return null;
        }

        $ids = [];

        foreach ($value as $id) {
            $validatedId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($validatedId === false) {
                return null;
            }

            $ids[] = $validatedId;
        }

        sort($ids);

        return count($ids) === count(array_unique($ids)) ? $ids : null;
    }
}
