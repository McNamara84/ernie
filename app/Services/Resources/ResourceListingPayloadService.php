<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Http\Resources\ResourceListItemResource;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

final readonly class ResourceListingPayloadService
{
    public function __construct(
        private ResourcePartySearchMatchService $partySearchMatchService,
    ) {}

    /**
     * @param  array<int, Resource>  $items
     * @return array<int, array<string, mixed>>
     */
    public function resolve(array $items, Request $request, ?string $search): array
    {
        /** @var Collection<int, Resource> $resources */
        $resources = new Collection($items);
        $matchesByResource = $this->partySearchMatchService->resolve($resources, $search);

        /** @var array<int, array<string, mixed>> $payload */
        $payload = ResourceListItemResource::collection($resources)->resolve($request);

        return array_map(
            static fn (array $resource): array => [
                ...$resource,
                'search_matches' => $matchesByResource[(int) $resource['id']] ?? [],
            ],
            $payload,
        );
    }
}
