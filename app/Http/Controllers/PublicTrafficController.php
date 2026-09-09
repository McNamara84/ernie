<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PublicTrafficHistoryRequest;
use App\Services\PublicTraffic\PublicTrafficHistoryService;
use Illuminate\Http\JsonResponse;

final class PublicTrafficController extends Controller
{
    public function __invoke(
        PublicTrafficHistoryRequest $request,
        PublicTrafficHistoryService $historyService,
    ): JsonResponse {
        return response()->json($historyService->history($request->period()));
    }
}
