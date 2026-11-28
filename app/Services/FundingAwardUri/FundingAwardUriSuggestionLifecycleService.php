<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

use App\Enums\CacheKey;
use App\Models\AssistantSuggestion;
use Illuminate\Support\Facades\DB;

final class FundingAwardUriSuggestionLifecycleService
{
    /** Called under the editor's resource lock before funding rows are replaced. */
    public function invalidateForResource(int $resourceId): void
    {
        if (AssistantSuggestion::where('assistant_id', AwardMetadata::ASSISTANT_ID)->where('resource_id', $resourceId)->delete() > 0) {
            $this->changed();
        }
    }

    public function changed(): void
    {
        DB::afterCommit(static function (): void {
            CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->forget();
            CacheKey::ASSISTANCE_DATACENTER_OPTIONS->forget();
        });
    }
}
