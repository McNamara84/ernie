<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Pagination-aware collection of ResourceListItemResource items.
 *
 * Wraps a LengthAwarePaginator and exposes the standardized pagination
 * shape consumed by the Resources index page and IGSN list.
 */
final class ResourceListCollection extends ResourceCollection
{
    /**
     * @var class-string
     */
    public $collects = ResourceListItemResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator<int, \App\Models\Resource> $paginator */
        $paginator = $this->resource;

        return [
            'resources' => $this->collection?->all() ?? [],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }
}
