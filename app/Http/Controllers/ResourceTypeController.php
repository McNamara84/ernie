<?php

declare(strict_types=1);

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
            ->orderByName()
            ->get(['id', 'name', 'description']);

        return response()->json($types);
    }

    /**
     * Return all resource types that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $types = ResourceType::query()
            ->elmoActive()
            ->orderByName()
            ->get(['id', 'name', 'description']);

        return response()->json($types);
    }

    /** Return entries independently enabled for ELMO-MSL. */
    public function elmoMsl(): JsonResponse
    {
        $types = ResourceType::query()
            ->elmoMslActive()
            ->orderByName()
            ->get(['id', 'name', 'description']);

        return response()->json($types);
    }

    /**
     * Return all resource types that are active for Ernie.
     */
    public function ernie(): JsonResponse
    {
        $types = ResourceType::query()
            ->active()
            ->orderByName()
            ->get(['id', 'name', 'description']);

        return response()->json($types);
    }
}
