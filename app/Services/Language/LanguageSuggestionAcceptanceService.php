<?php

declare(strict_types=1);

namespace App\Services\Language;

use App\Models\AssistantSuggestion;
use App\Models\Language;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;

final class LanguageSuggestionAcceptanceService
{
    /**
     * @return array{success: bool, message: string}
     */
    public function accept(AssistantSuggestion $suggestion): array
    {
        if ($suggestion->target_type !== 'resource_language') {
            return [
                'success' => false,
                'message' => 'This language suggestion targets an unsupported entity type.',
            ];
        }

        $language = Language::query()->where('code', $suggestion->suggested_value)->first();
        if ($language === null) {
            return [
                'success' => false,
                'message' => 'Suggested language code is not recognised by the system.',
            ];
        }

        return DB::transaction(function () use ($suggestion, $language): array {
            $resource = Resource::query()
                ->whereKey($suggestion->resource_id)
                ->lockForUpdate()
                ->first();

            if (! $resource instanceof Resource) {
                return [
                    'success' => false,
                    'message' => 'The resource for this suggestion no longer exists.',
                ];
            }

            if ($resource->language_id === $language->id) {
                return [
                    'success' => true,
                    'message' => "Resource language is already set to {$language->name} ({$language->code}).",
                ];
            }

            $resource->language_id = $language->id;
            $resource->save();

            return [
                'success' => true,
                'message' => "Applied language suggestion: {$language->name} ({$language->code}).",
            ];
        });
    }
}
