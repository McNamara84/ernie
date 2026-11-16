<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Resource;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataEmbargoReleaseService;
use App\Services\UserActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DataEmbargoReleaseController extends Controller
{
    public function __invoke(Request $request, Resource $resource, DataEmbargoReleaseService $service): JsonResponse
    {
        Gate::authorize('update', $resource);
        // A production data release must not be disguised as a Beginner's test-mode operation.
        abort_if(! config('datacite.test_mode', true) && ! $request->user()?->can('register-production-doi'), 403);
        $released = $service->release($resource);
        if ($released->wasChanged('access_level')) {
            app(UserActivityService::class)->record(
                $request->user() === null ? null : ['id' => $request->user()->id, 'name' => $request->user()->name],
                'resource.data-released', 'released the data of',
                ['kind' => 'Resource', 'id' => $resource->id, 'title' => $resource->titles->first()->value ?? 'Untitled Resource'],
            );
        }
        try {
            $service->synchronize($released, app(DataCiteMemberApiClient::class));
        } catch (Throwable $exception) {
            Log::warning('Data embargo release requires DataCite synchronization.', ['resource_id' => $resource->id, 'error' => $exception->getMessage()]);

            return response()->json(['released' => true, 'synchronized' => false, 'message' => 'Data is released in ERNIE. The DataCite access metadata update is pending. Retry Release data to synchronize it.']);
        }

        return response()->json(['released' => true, 'synchronized' => true, 'message' => 'Data released successfully.']);
    }
}
