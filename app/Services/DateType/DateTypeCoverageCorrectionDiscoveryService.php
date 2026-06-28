<?php

declare(strict_types=1);

namespace App\Services\DateType;

use App\Models\Resource;
use App\Models\ResourceDate;

final class DateTypeCoverageCorrectionDiscoveryService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function discover(Resource $resource): array
    {
        $suggestions = [];

        $collectedDates = $resource->dates()
            ->with('dateType')
            ->get()
            ->filter(fn (ResourceDate $date): bool => $date->dateType?->slug === 'Collected');

        foreach ($collectedDates as $resourceDate) {
            $rawValue = $this->dateValue($resourceDate);

            if ($rawValue === null || trim($rawValue) === '') {
                continue;
            }

            $normalizedValue = DateTypeNormalizerService::normalize($rawValue);

            if ($normalizedValue === '') {
                $suggestions[] = $this->skip($resourceDate, 'invalid_collected_date_format');

                continue;
            }

            if (! str_contains($normalizedValue, '/')) {
                continue;
            }

            $suggestions[] = [
                'suggestion_kind' => 'correction',
                'resource_date_id' => $resourceDate->id,
                'current_date_type' => 'Collected',
                'target_date_type' => 'Coverage',
                'normalized_value' => $normalizedValue,
                'source_url' => null,
                'evidence_source' => 'current_metadata',
                'confidence' => $this->confidenceForRange($normalizedValue),
                'is_ambiguous' => $this->isAmbiguous($collectedDates->count(), $normalizedValue),
            ];
        }
        return $this->deduplicateSuggestions($suggestions);
    }

    private function dateValue(ResourceDate $resourceDate): ?string
    {
        if ($resourceDate->start_date !== null && $resourceDate->end_date !== null) {
            return $resourceDate->start_date.'/'.$resourceDate->end_date;
        }
        return $resourceDate->date_value;
    }

    private function confidenceForRange(string $value): string
    {
        if (str_contains($value, '2100')) {
            return 'medium';
        }
        return 'high';
    }

    private function isAmbiguous(int $collectedCount, string $value): bool
    {
        if ($collectedCount > 1) {
            return true;
        }

        if (str_contains($value, '2100')) {
            return true;
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function skip(ResourceDate $resourceDate, string $reason): array
    {
        return [
            'probe_method' => 'SKIP',
            'skip_reason' => $reason,
            'resource_date_id' => $resourceDate->id,
            'evidence_source' => 'current_metadata',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $suggestions
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateSuggestions(array $suggestions): array
    {
        $seen = [];
        $unique = [];

        foreach ($suggestions as $suggestion) {
            $key = ($suggestion['resource_date_id'] ?? '').'|'
                .($suggestion['target_date_type'] ?? '').'|'
                .($suggestion['normalized_value'] ?? '').'|'
                .($suggestion['skip_reason'] ?? '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $suggestion;
        }
        return $unique;
    }
}