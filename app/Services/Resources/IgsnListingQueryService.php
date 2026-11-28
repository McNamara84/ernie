<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Database\Eloquent\Builder;

final class IgsnListingQueryService
{
    /** @return Builder<Resource> */
    public function baseQuery(): Builder
    {
        $typeId = ResourceType::query()->where('slug', 'physical-object')->value('id');
        $query = Resource::query()->whereHas('igsnMetadata');
        if ($typeId !== null) {
            $query->where('resource_type_id', $typeId);
        }

        return $query;
    }
}
