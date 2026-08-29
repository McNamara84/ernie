<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PortalSearchAnalyticsRequest;
use App\Services\Statistics\PortalSearchAnalyticsService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PortalSearchAnalyticsController extends Controller
{
    public function __construct(
        private readonly PortalSearchAnalyticsService $analyticsService,
    ) {}

    public function store(PortalSearchAnalyticsRequest $request): Response
    {
        $this->analyticsService->recordSearch($request, $request->searchTerm());

        return response()->noContent(HttpResponse::HTTP_NO_CONTENT);
    }
}
