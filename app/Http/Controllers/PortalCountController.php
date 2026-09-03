<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Enums\PortalScope;
use App\Http\Requests\PortalSearchRequest;
use App\Services\ListingCountService;
use App\Services\PortalFilterService;
use App\Services\PortalSearchService;
use Illuminate\Http\JsonResponse;

final class PortalCountController extends Controller
{
    public function __construct(
        private readonly PortalSearchService $searchService,
        private readonly PortalFilterService $filterService,
        private readonly ListingCountService $listingCountService,
    ) {}

    public function __invoke(PortalSearchRequest $request, string $portalScope): JsonResponse
    {
        $scope = PortalScope::from($portalScope);
        $temporalRange = $this->searchService->getTemporalRange($scope);
        $filters = $this->filterService->fromRequest($request, $temporalRange, $scope);
        $fingerprint = $this->listingCountService->fingerprint($filters);
        $total = $this->listingCountService->remember(
            CacheKey::PORTAL_LISTING_COUNT,
            $filters,
            fn (): int => $this->searchService->count($filters),
        );

        return response()->json([
            'filter_fingerprint' => $fingerprint,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / $filters['per_page'])),
            'count_status' => 'ready',
        ]);
    }
}
