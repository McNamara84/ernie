<?php

declare(strict_types=1);

namespace App\Services\DateType;

use App\Models\AssistantSuggestion;

final class DateTypeSuggestionAcceptanceService
{
    /** @return array{success: bool, message: string} */
    public function accept(AssistantSuggestion $suggestion): array
    {
        if ($suggestion->target_type !== DateTypeSuggestionDiscoveryService::TARGET_TYPE) {
            return [
                'success' => false,
                'message' => 'This dateType suggestion targets an unsupported entity type.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Date/geolocation count match acknowledged.',
        ];
    }
}
