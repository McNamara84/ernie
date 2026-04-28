<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Resource\DestroyAllResourcesRequest;
use App\Http\Requests\Resource\DestroyResourceRequest;
use App\Http\Requests\Resource\IndexResourcesRequest;
use App\Http\Requests\StoreDraftResourceRequest;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Resources\ResourceListItemResource;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Services\DataCiteSyncService;
use App\Services\Resources\ResourceQueryBuilder;
use App\Services\ResourceStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ResourceController extends Controller
{
    public function __construct(
        private readonly ResourceQueryBuilder $queryBuilder,
        private readonly ResourceStorageService $storageService,
        private readonly DataCiteSyncService $syncService,
    ) {}

    /**
     * Render the paginated resource listing page.
     */
    public function index(IndexResourcesRequest $request): Response
    {
        $criteria = $request->toCriteria();
        $resources = $this->queryBuilder->paginate($criteria);

        /** @var array<int, Resource> $items */
        $items = $resources->items();
        $resourcesData = ResourceListItemResource::collection(collect($items))
            ->resolve($request);

        return Inertia::render('resources', [
            'resources' => $resourcesData,
            'pagination' => [
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
                'per_page' => $resources->perPage(),
                'total' => $resources->total(),
                'from' => $resources->firstItem(),
                'to' => $resources->lastItem(),
                'has_more' => $resources->hasMorePages(),
            ],
            'sort' => [
                'key' => $criteria['sortKey'],
                'direction' => $criteria['sortDirection'],
            ],
            'canImportFromDataCite' => $request->user()?->can('importFromDataCite', Resource::class) ?? false,
        ]);
    }

    /**
     * Persist a fully validated resource and sync it with DataCite if it has a DOI.
     */
    public function store(StoreResourceRequest $request): JsonResponse
    {
        try {
            [$resource, $isUpdate] = $this->storageService->store(
                $request->validated(),
                $request->user()?->id
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Unable to save resource. Please review the highlighted issues.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            Log::error('ResourceController::store failed', [
                'exception' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'user_id' => $request->user()?->id,
                'resource_id' => $request->input('resourceId'),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
            report($exception);

            return response()->json([
                'message' => 'Unable to save resource. Please try again later.',
            ], 500);
        }

        // Automatic DataCite synchronization (Issue #383).
        $syncResult = $this->syncService->syncIfRegistered($resource);

        $message = $isUpdate ? 'Successfully updated resource.' : 'Successfully saved resource.';
        $status = $isUpdate ? 200 : 201;

        $response = [
            'message' => $message,
            'resource' => [
                'id' => $resource->id,
            ],
            'dataCiteSync' => $syncResult->toArray(),
        ];

        if ($syncResult->hasFailed()) {
            $response['message'] = $message.' However, DataCite update failed.';
            $response['warning'] = $syncResult->errorMessage;
        }

        return response()->json($response, $status);
    }

    /**
     * Store a draft resource with relaxed validation (Issue #548).
     *
     * Only requires a Main Title; all other fields are optional. Does NOT trigger
     * DataCite synchronization.
     */
    public function storeDraft(StoreDraftResourceRequest $request): JsonResponse
    {
        try {
            [$resource, $isUpdate] = $this->storageService->store(
                $request->validated(),
                $request->user()?->id
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Unable to save draft. Please review the highlighted issues.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            Log::error('ResourceController::storeDraft failed', [
                'exception' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'user_id' => $request->user()?->id,
                'resource_id' => $request->input('resourceId'),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
            report($exception);

            return response()->json([
                'message' => 'Unable to save draft. Please try again later.',
            ], 500);
        }

        $message = $isUpdate ? 'Draft updated successfully.' : 'Draft saved successfully.';
        $status = $isUpdate ? 200 : 201;

        return response()->json([
            'message' => $message,
            'resource' => [
                'id' => $resource->id,
            ],
        ], $status);
    }

    /**
     * Delete a resource.
     *
     * Authorization is enforced by DestroyResourceRequest::authorize() (delegates
     * to ResourcePolicy::delete – Admin / Group Leader only).
     */
    public function destroy(DestroyResourceRequest $request, Resource $resource): RedirectResponse
    {
        $resource->delete();

        return redirect()
            ->route('resources')
            ->with('success', 'Resource deleted successfully.');
    }

    /**
     * Delete all resources (datasets and IGSNs) from the database.
     *
     * Destructive admin-only operation for cleaning up test data. Deletes all
     * resources with cascading relations, then cleans up orphaned persons,
     * institutions, and publishers. Settings tables, user accounts, and lookup
     * data are preserved.
     *
     * Authorization is enforced by both route middleware ('can:delete-all-resources')
     * and DestroyAllResourcesRequest::authorize() for defense in depth.
     */
    public function destroyAll(DestroyAllResourcesRequest $request): RedirectResponse
    {
        DB::transaction(function (): void {
            // Delete in chunks so ResourceObserver fires per resource for cache invalidation.
            // DB cascading FKs handle child tables.
            Resource::query()->chunkById(100, function ($resources): void {
                foreach ($resources as $resource) {
                    $resource->delete();
                }
            });

            // Clean up orphaned persons, institutions and publishers.
            Person::whereDoesntHave('resourceCreators')
                ->whereDoesntHave('resourceContributors')
                ->delete();

            Institution::whereDoesntHave('resourceCreators')
                ->whereDoesntHave('resourceContributors')
                ->delete();

            Publisher::whereDoesntHave('resources')->delete();
        });

        Log::info('All resources deleted by admin', [
            'user_id' => $request->user()?->id,
            'user_email' => $request->user()?->email,
        ]);

        return redirect()
            ->route('logs.index')
            ->with('success', 'All resources have been deleted successfully.');
    }
}
