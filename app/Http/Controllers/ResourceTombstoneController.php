<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LandingPage\ResourceTombstoneRequest;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use App\Services\ResourceTombstoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceTombstoneController extends Controller
{
    public function __construct(private readonly ResourceTombstoneService $service) {}

    public function show(Request $request, Resource $resource): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()->json(['tombstone' => $this->service->state($resource, $user)]);
    }

    public function activate(ResourceTombstoneRequest $request, Resource $resource): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $page = $this->service->activate($resource, $user, $request->validated());

        return $this->result($resource, $user, $page);
    }

    public function update(ResourceTombstoneRequest $request, Resource $resource): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return $this->result($resource, $user, $this->service->change($resource, $user, $request->validated()));
    }

    public function restore(ResourceTombstoneRequest $request, Resource $resource): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return $this->result($resource, $user, $this->service->change($resource, $user, $request->validated(), true));
    }

    public function retry(ResourceTombstoneRequest $request, Resource $resource): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $this->service->retry($resource, $request->integer('revision'));

        return response()->json(['tombstone' => $this->service->state($resource->fresh() ?? $resource, $user)]);
    }

    private function result(Resource $resource, User $user, LandingPage $page): JsonResponse
    {
        $resource->refresh();
        $page->refresh()->load(['externalDomain', 'files', 'links', 'landingPageTemplate']);

        return response()->json([
            'landing_page' => LandingPageController::serializeLandingPagePayload($resource, $page),
            'publicstatus' => $resource->publicStatus(),
            'tombstone' => $this->service->state($resource, $user),
        ]);
    }
}
