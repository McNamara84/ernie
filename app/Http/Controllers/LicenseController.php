<?php

namespace App\Http\Controllers;

use App\Models\ResourceType;
use App\Models\Right;
use Illuminate\Http\JsonResponse;

/**
 * Controller for Rights/Licenses API endpoints.
 *
 * Note: This controller is named LicenseController for backward compatibility
 * with API routes. The underlying model is now Right (DataCite schema naming).
 */
class LicenseController extends Controller
{
    /**
     * Return all rights/licenses.
     */
    public function index(): JsonResponse
    {
        $rights = Right::query()
            ->orderByName()
            ->get(['id', 'identifier', 'name']);

        return response()->json($rights);
    }

    /**
     * Return all rights/licenses that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $rights = Right::query()
            ->active()
            ->elmoActive()
            ->orderByName()
            ->get(['id', 'identifier', 'name']);

        return response()->json($rights);
    }

    /**
     * Return all rights/licenses that are active for ELMO and available for a specific resource type.
     */
    public function elmoForResourceType(string $resourceTypeSlug): JsonResponse
    {
        $resourceType = ResourceType::where('slug', $resourceTypeSlug)->first();

        if (! $resourceType) {
            return response()->json([
                'message' => 'Resource type not found.',
            ], 404);
        }

        $rights = Right::query()
            ->active()
            ->elmoActive()
            ->availableForResourceType($resourceType->id)
            ->orderByName()
            ->get(['id', 'identifier', 'name']);

        return response()->json($rights);
    }

    /**
     * Return all rights/licenses that are active for Ernie.
     */
    public function ernie(): JsonResponse
    {
        $rights = Right::query()
            ->active()
            ->orderByUsageCount()
            ->get(['id', 'identifier', 'name']);

        return response()->json($rights);
    }
}
