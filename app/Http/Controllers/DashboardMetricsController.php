<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DashboardMetricsService;
use Illuminate\Http\JsonResponse;

final class DashboardMetricsController extends Controller
{
    public function __invoke(DashboardMetricsService $metricsService): JsonResponse
    {
        return response()->json($metricsService->metrics());
    }
}
