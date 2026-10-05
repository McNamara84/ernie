<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EditorContext;
use App\Http\Requests\ResourceTypeLicensesRequest;
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
            ->get(['id', 'identifier', 'name', 'uri', 'scheme_uri']);

        return response()->json($rights);
    }

    /**
     * Return all rights/licenses that are active for ELMO.
     */
    public function elmo(): JsonResponse
    {
        $rights = Right::query()
            ->elmoActive()
            ->orderByName()
            ->get(['id', 'identifier', 'name', 'uri', 'scheme_uri']);

        return response()->json($rights);
    }

    /**
     * Return all rights/licenses that are active for ELMO and available for a specific resource type.
     */
    public function elmoForResourceType(string $resourceTypeSlug): JsonResponse
    {
        return $this->forResourceType($resourceTypeSlug, EditorContext::ELMO);
    }

    public function elmoMsl(): JsonResponse
    {
        return response()->json(Right::query()->elmoMslActive()->orderByName()
            ->get(['id', 'identifier', 'name', 'uri', 'scheme_uri']));
    }

    public function elmoMslForResourceType(string $resourceTypeSlug): JsonResponse
    {
        return $this->forResourceType($resourceTypeSlug, EditorContext::ELMO_MSL);
    }

    public function ernieForResourceType(string $resourceTypeSlug): JsonResponse
    {
        return $this->forResourceType($resourceTypeSlug, EditorContext::ERNIE);
    }

    private function forResourceType(string $resourceTypeSlug, EditorContext $editor): JsonResponse
    {
        $resourceType = ResourceType::where('slug', $resourceTypeSlug)->first();

        if (! $resourceType) {
            return response()->json([
                'message' => 'Resource type not found.',
            ], 404);
        }

        $rights = Right::query()
            ->where($editor->activationColumn(), true)
            ->availableForResourceType($resourceType->id, $editor)
            ->when($editor === EditorContext::ERNIE,
                fn ($query) => $query->orderByUsageCount(),
                fn ($query) => $query->orderByName())
            ->get(['id', 'identifier', 'name', 'uri', 'scheme_uri']);

        return response()->json($rights);
    }

    /**
     * Return all rights/licenses that are active for Ernie.
     */
    public function ernie(ResourceTypeLicensesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        if (isset($validated['resource_type_id'])) {
            $resourceType = ResourceType::findOrFail((int) $validated['resource_type_id']);

            return $this->forResourceType($resourceType->slug, EditorContext::ERNIE);
        }

        $rights = Right::query()
            ->active()
            ->orderByUsageCount()
            ->get(['id', 'identifier', 'name', 'uri', 'scheme_uri']);

        return response()->json($rights);
    }
}
