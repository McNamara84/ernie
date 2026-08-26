<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DataCiteUrlUpdateScope;
use App\Enums\EditorDraftSaveIntent;
use App\Http\Requests\Resource\DestroyAllResourcesRequest;
use App\Http\Requests\Resource\DestroyResourceRequest;
use App\Http\Requests\Resource\DestroyResourcesRequest;
use App\Http\Requests\Resource\IndexResourcesRequest;
use App\Http\Requests\StoreDraftResourceRequest;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Resources\ResourceListItemResource;
use App\Models\DataCiteUrlUpdateRun;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteUrlUpdateRunPresenter;
use App\Services\Editor\EditorResourceSaveService;
use App\Services\Resources\DeleteAllResourcesService;
use App\Services\Resources\ResourceQueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Throwable;

class ResourceController extends Controller
{
    public function __construct(
        private readonly ResourceQueryBuilder $queryBuilder,
        private readonly EditorResourceSaveService $editorResourceSaveService,
        private readonly DeleteAllResourcesService $deleteAllResourcesService,
        private readonly DataCiteUrlUpdateRunPresenter $dataCiteUrlUpdateRunPresenter,
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

        $canUpdateDataCiteLandingPageUrls = $request->user()?->can('update-datacite-landing-page-urls') ?? false;
        $urlUpdateRun = $canUpdateDataCiteLandingPageUrls
            ? DataCiteUrlUpdateRun::query()->active()->latest()->first()
                ?? DataCiteUrlUpdateRun::query()->where('scope', DataCiteUrlUpdateScope::RESOURCES)->latest()->first()
            : null;

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
            'filters' => $criteria['filters'],
            'canImportFromDataCite' => $request->user()?->can('importFromDataCite', Resource::class) ?? false,
            'canUpdateDataCiteLandingPageUrls' => $canUpdateDataCiteLandingPageUrls,
            'dataCiteUrlUpdateRun' => $urlUpdateRun === null ? null : $this->dataCiteUrlUpdateRunPresenter->run($urlUpdateRun),
        ]);
    }

    /** Persist a fully validated resource without an implicit external write. */
    public function store(StoreResourceRequest $request): JsonResponse
    {
        try {
            [$resource, $isUpdate] = $this->editorResourceSaveService->saveValidated(
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

        $message = $isUpdate
            ? 'Resource validated and updated successfully.'
            : 'Resource validated and saved successfully.';
        $status = $isUpdate ? 200 : 201;

        return response()->json([
            'message' => $message,
            'resource' => [
                'id' => $resource->id,
                'publicStatus' => $resource->publicStatus(),
            ],
        ], $status);
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
            $validated = $request->validated();
            $intent = EditorDraftSaveIntent::from($validated['intent']);

            [$resource, $isUpdate] = $this->editorResourceSaveService->saveRelaxed(
                $validated,
                $request->user()?->id,
                $intent,
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

        $message = match ($intent) {
            EditorDraftSaveIntent::SAVE_DRAFT => $isUpdate ? 'Draft updated successfully.' : 'Draft saved successfully.',
            EditorDraftSaveIntent::AUTOSAVE => 'Draft autosaved successfully.',
            EditorDraftSaveIntent::LANDING_PAGE_PREVIEW => 'Resource saved for landing page preview.',
        };
        $status = $isUpdate ? 200 : 201;

        return response()->json([
            'message' => $message,
            'resource' => [
                'id' => $resource->id,
                'publicStatus' => $resource->publicStatus(),
            ],
        ], $status);
    }

    /**
     * Delete a resource authorized for the current user's role.
     *
     * Authorization is enforced by DestroyResourceRequest::authorize() and
     * ResourcePolicy::delete(). Admins and group leaders may also delete
     * published resources, while curators remain limited to non-published ones.
     */
    public function destroy(DestroyResourceRequest $request, Resource $resource): RedirectResponse
    {
        $publishedDeletion = $this->publishedDeletionContext($resource);

        $resource->delete();

        if ($publishedDeletion !== null) {
            $this->logPublishedResourceDeletion($request, 'single', [$publishedDeletion]);
        }

        return redirect()
            ->route('resources')
            ->with('success', 'Resource deleted successfully.');
    }

    /**
     * Delete selected resources authorized for the current user's role.
     *
     * Every submitted resource is checked through ResourcePolicy::delete so
     * batch deletion cannot bypass published-resource safeguards.
     *
     * @throws ValidationException
     */
    public function destroyBatch(DestroyResourcesRequest $request): RedirectResponse
    {
        /** @var array{ids: array<int, int>} $validated */
        $validated = $request->validated();

        /** @var array<int> $ids */
        $ids = array_values(array_unique($validated['ids']));
        sort($ids, SORT_NUMERIC);

        /** @var array<int, array{resource_id: int, doi: string}> $publishedDeletions */
        $publishedDeletions = DB::transaction(function () use ($ids, $request): array {
            $resources = Resource::query()
                ->with([
                    'titles.titleType',
                    'creators',
                    'rights',
                    'descriptions.descriptionType',
                    'landingPage',
                ])
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($resources->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'ids' => ['Some selected resources could not be found.'],
                ]);
            }

            $publishedDeletions = [];

            foreach ($ids as $id) {
                $resource = $resources->get($id);

                if (! $resource instanceof Resource) {
                    throw ValidationException::withMessages([
                        'ids' => ['Some selected resources could not be found.'],
                    ]);
                }

                if (! ($request->user()?->can('delete', $resource) ?? false)) {
                    $message = $resource->publicStatus() === 'published'
                        ? 'Published resources can only be deleted by Admins and Group Leaders.'
                        : 'You do not have permission to delete the selected resources.';

                    throw ValidationException::withMessages([
                        'ids' => [$message],
                    ]);
                }

                $publishedDeletion = $this->publishedDeletionContext($resource);
                if ($publishedDeletion !== null) {
                    $publishedDeletions[] = $publishedDeletion;
                }
            }

            foreach ($ids as $id) {
                $resource = $resources->get($id);

                if ($resource instanceof Resource) {
                    $resource->delete();
                }
            }

            return $publishedDeletions;
        });

        if ($publishedDeletions !== []) {
            $this->logPublishedResourceDeletion($request, 'batch', $publishedDeletions);
        }

        $count = count($ids);
        $message = $count === 1
            ? 'Resource deleted successfully.'
            : "{$count} resources deleted successfully.";

        return redirect()
            ->route('resources')
            ->with('success', $message);
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
        $deletedResources = $this->deleteAllResourcesService->deleteAll();

        Log::info('All resources deleted by admin', [
            'user_id' => $request->user()?->id,
            'user_email' => $request->user()?->email,
            'deleted_resources' => $deletedResources,
        ]);

        return redirect()
            ->route('logs.index')
            ->with('success', 'All resources have been deleted successfully.');
    }

    /**
     * Capture the identifiers needed to audit deletion of a published resource.
     *
     * @return array{resource_id: int, doi: string}|null
     */
    private function publishedDeletionContext(Resource $resource): ?array
    {
        $resource->loadMissing('landingPage');

        if ($resource->doi === null || $resource->doi === '' || ! $resource->landingPage?->is_published) {
            return null;
        }

        return [
            'resource_id' => $resource->id,
            'doi' => $resource->doi,
        ];
    }

    /**
     * @param  array<int, array{resource_id: int, doi: string}>  $resources
     */
    private function logPublishedResourceDeletion(Request $request, string $operation, array $resources): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('Published resource deletions require an authenticated user.');
        }

        Log::warning('Published resources deleted from ERNIE', [
            'operation' => $operation,
            'user_id' => $user->id,
            'user_role' => $user->role->value,
            'resources' => $resources,
        ]);
    }
}
