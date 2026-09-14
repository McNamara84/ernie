<?php

declare(strict_types=1);

namespace App\Services\Editor;

use App\Enums\EditorDraftSaveIntent;
use App\Enums\ResourceWorkflowStatus;
use App\Models\Resource;
use App\Models\User;
use App\Policies\ResourcePolicy;
use App\Services\ResourceStorageService;
use Illuminate\Auth\Access\AuthorizationException;
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
            $this->lockResourceAndAuthorizeDoiChange($data, $user);

            [$resource, $isUpdate] = $this->storageService->store($data, $user?->id);

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
    public function saveRelaxed(array $data, ?User $user, EditorDraftSaveIntent $intent): array
    {
        return DB::transaction(function () use ($data, $user, $intent): array {
            $this->lockResourceAndAuthorizeDoiChange($data, $user);

            if ($intent === EditorDraftSaveIntent::SAVE_DRAFT) {
                $this->assertResourceIsNotPublished($data);
            }

            [$resource, $isUpdate] = $this->storageService->store($data, $user?->id);

            if ($intent === EditorDraftSaveIntent::SAVE_DRAFT) {
                $resource->workflow_status_override = ResourceWorkflowStatus::DRAFT;
                $resource->force_review_status = false;
                $resource->save();
            }

            return [$this->loadStatusRelations($resource), $isUpdate];
        });
    }

    /** @param array<string, mixed> $data */
    private function lockResourceAndAuthorizeDoiChange(array $data, ?User $user): void
    {
        $resourceId = $data['resourceId'] ?? null;

        if (! array_key_exists('doi', $data)
            || (! is_int($resourceId) && ! (is_string($resourceId) && ctype_digit($resourceId)))) {
            return;
        }

        /** @var Resource $resource */
        $resource = Resource::query()
            ->lockForUpdate()
            ->findOrFail((int) $resourceId);

        // Publication paths acquire the same resource lock. Loading the landing
        // page only after this point guarantees that the policy sees whichever
        // operation won the serialization race.
        $landingPage = $resource->landingPage()
            ->lockForUpdate()
            ->first();
        $resource->setRelation('landingPage', $landingPage);

        $doi = $data['doi'];

        if (($doi !== null && ! is_string($doi))
            || $user?->can('changeDoi', [$resource, $doi]) === true) {
            return;
        }

        throw new AuthorizationException(ResourcePolicy::DOI_CHANGE_UNAUTHORIZED_MESSAGE);
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
