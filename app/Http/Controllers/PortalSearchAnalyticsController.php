<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Statistics\PortalSearchAnalyticsService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PortalSearchAnalyticsController extends Controller
{
    public function __construct(
        private readonly PortalSearchAnalyticsService $analyticsService,
    ) {}

    public function store(Request $request): \Illuminate\Http\Response
    {
        $validated = $request->validate([
            'search_term' => ['nullable', 'string', 'max:255'],
        ]);

        $this->analyticsService->recordSearch($request, $validated['search_term'] ?? null);

        return response()->noContent(HttpResponse::HTTP_NO_CONTENT);
    }
}