<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PortalScope;
use App\Http\Requests\PortalMapClusterMembersRequest;
use App\Services\BotProtection\PortalMapCacheService;
use App\Services\PortalFilterService;
use App\Services\PortalMapService;
use App\Services\PortalSearchService;
use Illuminate\Http\JsonResponse;

final class PortalMapClusterMembersController extends Controller
{
    public function __construct(
        private readonly PortalSearchService $portalSearchService,
        private readonly PortalFilterService $portalFilterService,
        private readonly PortalMapService $portalMapService,
        private readonly PortalMapCacheService $cacheService,
    ) {}

    public function __invoke(PortalMapClusterMembersRequest $request, string $clusterId, string $portalScope): JsonResponse
    {
        if (! (bool) config('portal_map.enabled', true)) {
            return response()->json(['message' => 'The portal map is temporarily unavailable.'], 503);
        }

        $scope = PortalScope::from($portalScope);
        $clusterId = $request->clusterId();
        $cached = $this->cacheService->remember($request, function () use ($request, $scope, $clusterId): array {
            $filters = $this->portalFilterService->fromRequest(
                $request,
                $this->portalSearchService->getTemporalRange($scope),
                $scope,
            );

            return [
                'payload' => $this->portalMapService->getClusterMembers(
                    $filters,
                    $request->viewport(),
                    $clusterId,
                    $request->page(),
                    $scope,
                ),
            ];
        }, $scope);
        $payload = is_array($cached['payload'] ?? null) ? $cached['payload'] : null;

        if ($payload === null) {
            return response()->json(['message' => 'The requested map cluster was not found.'], 404);
        }

        return response()->json($payload);
    }
}
