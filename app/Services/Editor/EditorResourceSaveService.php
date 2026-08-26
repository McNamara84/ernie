<?php

declare(strict_types=1);

namespace App\Services\Editor;

use App\Enums\EditorDraftSaveIntent;
use App\Enums\ResourceWorkflowStatus;
use App\Models\Resource;
use App\Services\ResourceStorageService;
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
    public function saveValidated(array $data, ?int $userId): array
    {
        return DB::transaction(function () use ($data, $userId): array {
            [$resource, $isUpdate] = $this->storageService->store($data, $userId);

            if ($resource->workflow_status_override === ResourceWorkflowStatus::DRAFT) {
                $resource->workflow_status_override = null;
                $resource->save();
            }

            return [$this->loadStatusRelations($resource), $isUpdate];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Resource, 1: bool}
     */
    public function saveRelaxed(array $data, ?int $userId, EditorDraftSaveIntent $intent): array
    {
        return DB::transaction(function () use ($data, $userId, $intent): array {
            if ($intent === EditorDraftSaveIntent::SAVE_DRAFT) {
                $this->assertResourceIsNotPublished($data);
            }

            [$resource, $isUpdate] = $this->storageService->store($data, $userId);

            if ($intent === EditorDraftSaveIntent::SAVE_DRAFT) {
                $resource->workflow_status_override = ResourceWorkflowStatus::DRAFT;
                $resource->force_review_status = false;
                $resource->save();
            }

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
