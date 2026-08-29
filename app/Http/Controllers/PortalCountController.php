<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CacheKey;
use App\Services\ListingCountService;
use App\Services\PortalFilterService;
use App\Services\PortalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PortalCountController extends Controller
{
    public function __construct(
        private readonly PortalSearchService $searchService,
        private readonly PortalFilterService $filterService,
        private readonly ListingCountService $listingCountService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $temporalRange = $this->searchService->getTemporalRange();
        $filters = $this->filterService->fromRequest($request, $temporalRange);
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
