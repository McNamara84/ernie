<?php

declare(strict_types=1);

namespace App\Services\FundingAwardUri;

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use Illuminate\Support\LazyCollection;

final class FundingAwardUriInputProvider
{
    /** @return LazyCollection<int, Resource> */
    public function resources(int $afterId): LazyCollection
    {
        return Resource::query()->where('id', '>', $afterId)
            ->where(fn ($query) => $query->whereHas('fundingReferences')->orWhereIn('id',
                AssistantSuggestion::select('resource_id')->where('assistant_id', AwardMetadata::ASSISTANT_ID)))
            ->with(['landingPage', 'fundingReferences.funderIdentifierType'])
            ->lazyById(max(1, (int) config('funding_award_uri.chunk_size', 100)));
    }
}
