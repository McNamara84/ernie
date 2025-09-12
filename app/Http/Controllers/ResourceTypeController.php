<?php

namespace App\Http\Controllers;

use App\Models\ResourceType;
use Illuminate\Http\JsonResponse;

class ResourceTypeController extends Controller
{
    /**
     * Return all resource types that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $types = ResourceType::query()
            ->where('active', true)
            ->where('elmo_active', true)
            ->get();

        return response()->json($types);
    }
}
