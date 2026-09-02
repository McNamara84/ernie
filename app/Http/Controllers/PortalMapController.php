<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PortalScope;
use App\Http\Requests\PortalMapRequest;
use App\Services\BotProtection\PortalMapCacheService;
use App\Services\PortalFilterService;
use App\Services\PortalMapService;
use App\Services\PortalSearchService;
use Illuminate\Http\JsonResponse;

final class PortalMapController extends Controller
{
    public function __construct(
        private readonly PortalSearchService $portalSearchService,
        private readonly PortalFilterService $portalFilterService,
        private readonly PortalMapService $portalMapService,
        private readonly PortalMapCacheService $cacheService,
    ) {}

    public function __invoke(PortalMapRequest $request, string $portalScope): JsonResponse
    {
        if (! (bool) config('portal_map.enabled', true)) {
            return response()->json(['message' => 'The portal map is temporarily unavailable.'], 503);
        }

        $scope = PortalScope::from($portalScope);
        $payload = $this->cacheService->remember($request, function () use ($request, $scope): array {
            $filters = $this->portalFilterService->fromRequest(
                $request,
                $this->portalSearchService->getTemporalRange($scope),
                $scope,
            );
            $extentSummary = $request->includeExtent()
                ? $this->cacheService->rememberExtent(
                    $filters,
                    fn (): array => $this->portalMapService->calculateExtent($filters),
                )
                : null;

            return $this->portalMapService->getMapData(
                $filters,
                $request->viewport(),
                $request->zoom(),
                $extentSummary,
            );
        });

        return response()->json($payload);
    }
}
