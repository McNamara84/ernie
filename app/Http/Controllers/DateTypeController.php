<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DateType;
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
     * Return all date types that are active for ERNIE.
     */
    public function ernie(): JsonResponse
    {
        $types = DateType::query()
            ->active()
            ->orderByName()
            ->get(['id', 'name', 'slug']);

        return response()->json($types);
    }
}
