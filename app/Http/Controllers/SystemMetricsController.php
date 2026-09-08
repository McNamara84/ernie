<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SystemMetricsHistoryRequest;
use App\Services\SystemMetricsHistoryService;
use Illuminate\Http\JsonResponse;

final class SystemMetricsController extends Controller
{
    public function __invoke(SystemMetricsHistoryRequest $request, SystemMetricsHistoryService $historyService): JsonResponse
    {
        return response()->json($historyService->history($request->period()));
    }
}
