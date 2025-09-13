<?php

namespace App\Http\Controllers;

use App\Models\ResourceType;
use Illuminate\Http\JsonResponse;

class ResourceTypeController extends Controller
{
    /**
     * Return all resource types.
     */
    public function index(): JsonResponse
    {
        $types = ResourceType::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($types);
    }

    /**
     * Return all resource types that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $types = ResourceType::query()
            ->where('active', true)
            ->where('elmo_active', true)
            ->get(['id', 'name']);

        return response()->json($types);
    }

    /**
     * Return all resource types that are active for Ernie.
     */
    public function ernie(): JsonResponse
    {
        $types = ResourceType::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($types);
    }
}
