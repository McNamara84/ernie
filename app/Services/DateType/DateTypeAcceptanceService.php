<?php

declare(strict_types=1);

namespace App\Services\DateType;

use App\Models\AssistantSuggestion;
use App\Models\DateType;
use App\Models\ResourceDate;

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

        $dateValue = DateTypeNormalizerService::normalize( $suggestion->metadata['normalized_value'] ?? $suggestion->suggested_value);

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

        $dates = ResourceDate::query()
            ->where('resource_id', $suggestion->resource_id)
            ->whereHas('dateType', fn ($query) => $query->where('slug', 'Collected'))
            ->get();

        if ($dates->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No Collected date entries were found for this resource.',
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

        $currentCollectedCount = $dates->count();
        $currentGeoLocationCount = $suggestion->resource()->first()?->geoLocations()->count();

        if ($expectedCollectedCount === null
            || $expectedGeoLocationCount === null
            || $currentCollectedCount !== $expectedCollectedCount
            || $currentGeoLocationCount !== $expectedGeoLocationCount
            || $currentCollectedCount !== $currentGeoLocationCount) {
            return [
                'success' => false,
                'message' => 'This suggestion is stale because the Collected date or geolocation counts changed.',
            ];
        }

        $dates->each(fn (ResourceDate $date) => $date->update([
            'date_type_id' => $coverageDateTypeId,
        ]));

        $updatedCount = $dates->count();

        return [
            'success' => true,
            'message' => "Changed {$updatedCount} Collected date entr".($updatedCount === 1 ? 'y' : 'ies').' to Coverage.',
        ];
    }
}
