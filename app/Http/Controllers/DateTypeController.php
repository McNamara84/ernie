<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DateType;
use App\Services\DateType\DateTypeUsageService;
use Illuminate\Http\JsonResponse;

class DateTypeController extends Controller
{
    /**
     * Return all date types.
     */
    public function index(): JsonResponse
    {
        $types = DateType::query()
            ->orderByName()
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return all date types that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $types = DateType::query()
            ->elmoActive()
            ->orderByName()
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /** Return entries independently enabled for ELMO-MSL. */
    public function elmoMsl(): JsonResponse
    {
        $types = DateType::query()
            ->elmoMslActive()
            ->orderByName()
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }

    /**
     * Return ERNIE-active date types ordered by repository usage, then name.
     */
    public function ernie(DateTypeUsageService $usageService): JsonResponse
    {
        $counts = $usageService->getUsageCounts();

        $types = DateType::query()
            ->active()
            ->orderByName()
            ->orderBy('id')
            ->get(['id', 'name', 'slug'])
            // Stable sorting preserves the database's alphabetical tie order.
            ->sortByDesc(fn (DateType $type): int => $counts[$type->id] ?? 0)
            ->values();

        return response()->json($types);
    }
}
