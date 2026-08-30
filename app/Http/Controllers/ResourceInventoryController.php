<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ResourceCacheService;
use Illuminate\Http\JsonResponse;

final class ResourceInventoryController extends Controller
{
    public function __invoke(ResourceCacheService $cacheService): JsonResponse
    {
        $physicalObjectTypeId = $cacheService->getPhysicalObjectTypeId();

        return response()->json([
            'dataResourceCount' => $cacheService->getDataResourceCount($physicalObjectTypeId),
            'igsnCount' => $cacheService->getIgsnCount($physicalObjectTypeId),
        ]);
    }
}
