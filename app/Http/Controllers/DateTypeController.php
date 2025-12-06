<?php

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
     * Note: DateType does not have is_elmo_active field, returns same as ernie().
     */
    public function elmo(): JsonResponse
    {
        $types = DateType::query()
            ->active()
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
