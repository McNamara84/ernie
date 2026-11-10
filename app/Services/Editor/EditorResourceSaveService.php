<?php

declare(strict_types=1);

namespace App\Services\Editor;

use App\Enums\EditorDraftSaveIntent;
use App\Enums\ResourceWorkflowStatus;
use App\Jobs\SyncDataEmbargoAccessJob;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteDoiWriteLockService;
use App\Services\DataCiteModeResolverService;
use App\Services\EmbargoService;
use App\Services\ResourceAccessLevelResolverService;
use App\Services\ResourceStorageService;
use App\Services\UserActivityService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Coordinates editor persistence with explicit workflow-state transitions.
 */
final readonly class EditorResourceSaveService
{
    public function __construct(
        private ResourceStorageService $storageService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Resource, 1: bool, 2: array{removedCount: int, idMap: array<int|string, string>, removedIds: list<string>}}
     */
    public function saveValidated(array $data, ?User $user): array
    {
        // Optional external citation lookups must finish before an editor
        // transaction takes the resource lock used by concurrent background work.
        $data = $this->storageService->prepareForStorage($data);

        return $this->persist($data, function () use ($data, $user): array {
            $before = $user === null ? [] : $this->activitySnapshot($data);
            [$resource, $isUpdate, $changes] = $this->storageService->storePrepared(
                $data,
                $user?->id,
                doiChangeActor: $user,
            );

            // Recheck the effective state under the resource lock: request
            // validation can predate a concurrent embargo change.
            $embargo = app(EmbargoService::class);
            if ($embargo->isEmbargoed($resource) && $embargo->availableDate($resource) === null) {
                throw ValidationException::withMessages([
                    'accessLevel' => ['Embargoed access requires exactly one valid day-precision Available date.'],
                ]);
            }

            if ($resource->workflow_status_override === ResourceWorkflowStatus::DRAFT) {
                $resource->workflow_status_override = null;
                $resource->save();
            }

            $this->logSave($resource, $user, $before, $isUpdate);

            return [$this->loadStatusRelations($resource), $isUpdate, $changes];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Resource, 1: bool, 2: array{removedCount: int, idMap: array<int|string, string>, removedIds: list<string>}}
     */
    public function saveRelaxed(array $data, ?User $user, EditorDraftSaveIntent $intent): array
    {
        $data = $this->storageService->prepareForStorage($data);

        return $this->persist($data, function () use ($data, $user, $intent): array {
            if ($intent === EditorDraftSaveIntent::SAVE_DRAFT) {
                $this->assertResourceIsNotPublished($data);
            }

            $autosave = $intent === EditorDraftSaveIntent::AUTOSAVE;
            $before = $user === null ? [] : $this->activitySnapshot($data, $autosave);
            [$resource, $isUpdate, $changes] = $this->storageService->storePrepared(
                $data,
                $user?->id,
                doiChangeActor: $user,
            );

            if ($intent === EditorDraftSaveIntent::SAVE_DRAFT) {
                $resource->workflow_status_override = ResourceWorkflowStatus::DRAFT;
                $resource->force_review_status = false;
                $resource->save();
            }

            $this->logSave($resource, $user, $before, $isUpdate, $autosave ? $data : null);

            return [$this->loadStatusRelations($resource), $isUpdate, $changes];
        });
    }

    /** @param array<string, mixed> $data */
    private function assertResourceIsNotPublished(array $data): void
    {
        $resourceId = $data['resourceId'] ?? null;

        if (! is_int($resourceId) && ! (is_string($resourceId) && ctype_digit($resourceId))) {
            return;
        }

        $resource = Resource::query()
            ->with('landingPage')
            ->lockForUpdate()
            ->find((int) $resourceId);

        if ($resource?->landingPage?->is_published) {
            throw ValidationException::withMessages([
                'intent' => ['Published resources cannot be changed to draft.'],
            ]);
        }
    }

    /**
     * Take the DOI lock before any row locks, consistently with remote writers.
     * Admission waits happen in the transport after this lock has been released.
     *
     * @param  array<string, mixed>  $data
     * @param  Closure(): array{0: Resource, 1: bool, 2: array{removedCount: int, idMap: array<int|string, string>, removedIds: list<string>}}  $save
     * @return array{0: Resource, 1: bool, 2: array{removedCount: int, idMap: array<int|string, string>, removedIds: list<string>}}
     */
    private function persist(array $data, Closure $save): array
    {
        if (empty($data['resourceId'])) {
            return DB::transaction($save);
        }
        $resourceId = (int) $data['resourceId'];
        // Production releases exclude Beginners. Their editor changes must
        // still supersede the production release, never a sandbox-only lock.
        $testMode = app(DataCiteModeResolverService::class)->shouldUseTestMode();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $doi = Resource::query()->findOrFail($resourceId)->doi;
            $persist = fn (): ?array => DB::transaction(function () use ($resourceId, $doi, $save): ?array {
                $previous = Resource::query()->lockForUpdate()->findOrFail($resourceId);
                if ($previous->doi !== $doi) {
                    return null; // Registration/DOI editing won the race; choose its lock again.
                }
                $access = app(ResourceAccessLevelResolverService::class);
                $previousAccess = $previous->landingPage?->is_published ? $access->resolve($previous) : null;
                $result = $save();
                $current = $result[0];
                if (! $current->data_embargo_sync_pending && $current->doi !== null && $current->doi !== ''
                    && $current->landingPage?->is_published && ! $current->landingPage->is_tombstone
                    && ($access->resolve($current) !== $previousAccess || $current->access_level !== $previous->access_level)) {
                    // A renewed embargo needs an access update even after the
                    // previous release was already confirmed by DataCite.
                    $current->data_embargo_sync_pending = true;
                    $current->save();
                }

                return $result;
            });
            $result = $doi === null || $doi === '' ? $persist()
                : DataCiteDoiWriteLockService::run($doi, $testMode, function () use ($persist, $doi, $testMode): ?array {
                    $result = $persist();
                    if ($result !== null && $result[0]->data_embargo_sync_pending) {
                        // Even an edit in the same second supersedes an older release.
                        DataCiteDoiWriteLockService::beginWrite($doi, $testMode);
                    }

                    return $result;
                });
            if ($result === null) {
                continue;
            }
            if ($result[0]->data_embargo_sync_pending) {
                try {
                    SyncDataEmbargoAccessJob::dispatch($result[0]->id, $testMode)->afterCommit();
                } catch (Throwable $exception) {
                    // A synchronous queue or unavailable broker must not turn an
                    // already committed editor save into a reported save failure.
                    Log::warning('Data embargo access correction remains pending.', ['resource_id' => $result[0]->id, 'error' => $exception->getMessage()]);
                }
            }

            return $result;
        }

        throw ValidationException::withMessages(['resourceId' => 'The resource DOI changed while saving. Reload and try again.']);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function activitySnapshot(array $data, bool $autosave = false): array
    {
        $resource = isset($data['resourceId']) ? Resource::query()->lockForUpdate()->find((int) $data['resourceId']) : null;

        if ($resource === null) {
            return [];
        }

        $activities = app(UserActivityService::class);

        return $autosave
            ? $activities->editorSnapshot($resource, array_key_exists('relatedItems', $data))
            : $activities->snapshot($resource);
    }

    /** @param array<string, mixed> $before
     * @param  array<string, mixed>|null  $autosaveData
     */
    private function logSave(Resource $resource, ?User $user, array $before, bool $isUpdate, ?array $autosaveData = null): void
    {
        if ($user === null) {
            return;
        }

        $activities = app(UserActivityService::class);
        $after = $autosaveData === null
            ? $activities->snapshot($resource)
            : $activities->editorSnapshot($resource, array_key_exists('relatedItems', $autosaveData));
        $fields = $activities->changedFields($before, $after);
        if (! $isUpdate || $fields !== []) {
            $activities->record($activities->actor($user), $isUpdate ? 'resource.metadata_updated' : 'resource.created',
                $isUpdate ? 'updated metadata in the Data Editor for' : 'created in the Data Editor',
                $activities->subject($resource->fresh() ?? $resource), $fields);
        }
    }

    private function loadStatusRelations(Resource $resource): Resource
    {
        return $resource->loadMissing([
            'landingPage',
            'titles.titleType',
            'rights',
            'creators',
            'descriptions.descriptionType',
            'dates.dateType',
        ]);
    }
}
