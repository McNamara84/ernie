<?php

declare(strict_types=1);

namespace App\Services\Editor;

use App\Enums\EditorDraftSaveIntent;
use App\Enums\ResourceWorkflowStatus;
use App\Models\Resource;
use App\Models\User;
use App\Services\ResourceStorageService;
use App\Services\UserActivityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * @return array{0: Resource, 1: bool}
     */
    public function saveValidated(array $data, ?User $user): array
    {
        return DB::transaction(function () use ($data, $user): array {
            $before = $user === null ? [] : $this->activitySnapshot($data);
            [$resource, $isUpdate] = $this->storageService->store(
                $data,
                $user?->id,
                doiChangeActor: $user,
            );

            if ($resource->workflow_status_override === ResourceWorkflowStatus::DRAFT) {
                $resource->workflow_status_override = null;
                $resource->save();
            }

            $this->logSave($resource, $user, $before, $isUpdate);

            return [$this->loadStatusRelations($resource), $isUpdate];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Resource, 1: bool}
     */
    public function saveRelaxed(array $data, ?User $user, EditorDraftSaveIntent $intent): array
    {
        return DB::transaction(function () use ($data, $user, $intent): array {
            if ($intent === EditorDraftSaveIntent::SAVE_DRAFT) {
                $this->assertResourceIsNotPublished($data);
            }

            $autosave = $intent === EditorDraftSaveIntent::AUTOSAVE;
            $before = $user === null ? [] : $this->activitySnapshot($data, $autosave);
            [$resource, $isUpdate] = $this->storageService->store(
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

            return [$this->loadStatusRelations($resource), $isUpdate];
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
            ->find((int) $resourceId);

        if ($resource?->publicStatus() === 'published') {
            throw ValidationException::withMessages([
                'intent' => ['Published resources cannot be changed to draft.'],
            ]);
        }
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
