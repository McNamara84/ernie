<?php

declare(strict_types=1);

namespace App\Services\DateType;

use App\Models\AssistantSuggestion;
use App\Models\DateType;
use App\Models\ResourceDate;


final class DateTypeSuggestionAcceptanceService
{
    /**
     *  @return array{success: bool, message: string} 
    */
    public function accept(AssistantSuggestion $suggestion): array
    {
        // Aus dem Objekt suggestion nehme Attribut target_type, wenn dieser exakt gleich mit string 'format ist
        // === : exakt gleich 
        if ($suggestion->target_type === 'date_type')
        {
            $dateValue = DateTypeNormalizerService::normalize($suggestion->suggested_value);

            if ($dateValue === '') 
            {
                return [
                    'success' => false,
                    'message' => 'Suggested date value is invalid.',
                ];
            }

            $metadata = $suggestion->metadata ?? [];
            $targetDateType = $metadata['target_date_type'] ?? null;

            if (! is_string($targetDateType) || $targetDateType === '') 
            {
                return [
                    'success' => false,
                    'message' => 'Missing target DateType.',
                ];
            }

            $dateType = DateType::where('slug', $targetDateType)->first();

            if ($dateType === null) 
            {
                return [
                    'success' => false,
                    'message' => 'Target DateType not found.',

                ];
            }

            if (str_contains($dateValue, '/')) 
            {
                [$startDate, $endDate] = explode('/', $dateValue);

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
        return [
            'success' => false,
            'message' => 'Unknown suggestion type.',
        ];
    }
}

