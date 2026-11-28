<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CurationRequest;
use App\Models\Resource;
use App\Models\ResourceCurationEvent;
use App\Models\User;
use App\Services\Curation\CurationCollaborationService;
use App\Services\Curation\CurationEventDescriptionService;
use App\Services\Curation\CurationService;
use Illuminate\Http\JsonResponse;

final class ResourceCurationController extends Controller
{
    public function show(CurationRequest $request, Resource $resource, CurationService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($service->panel($resource, $user));
    }

    public function startPost(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        return $this->write($request, $resource, $service, $collaboration);
    }

    public function checklistPatch(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        return $this->write($request, $resource, $service, $collaboration);
    }

    public function assignPut(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        return $this->write($request, $resource, $service, $collaboration);
    }

    public function handoverPost(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        return $this->write($request, $resource, $service, $collaboration);
    }

    public function statePatch(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        return $this->write($request, $resource, $service, $collaboration);
    }

    public function notePost(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        return $this->write($request, $resource, $service, $collaboration);
    }

    public function notePatch(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        return $this->write($request, $resource, $service, $collaboration);
    }

    public function noteDelete(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        return $this->write($request, $resource, $service, $collaboration);
    }

    public function locationPost(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        return $this->write($request, $resource, $service, $collaboration);
    }

    public function locationPatch(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        return $this->write($request, $resource, $service, $collaboration);
    }

    public function locationDelete(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        return $this->write($request, $resource, $service, $collaboration);
    }

    private function write(CurationRequest $request, Resource $resource, CurationService $service, CurationCollaborationService $collaboration): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $operation = $request->route('operation');
        $revision = (int) ($data['revision'] ?? 0);
        $delete = $request->isMethod('DELETE');
        if ($operation === 'start') {
            $service->start($resource, $user, 'explicit', true);
        } elseif ($operation === 'checklist') {
            $service->checklist($resource, $user, (string) $request->route('key'), $data['state'], $data['reason'] ?? null, $revision);
        } elseif ($operation === 'state') {
            $service->state($resource, $user, $data);
        } elseif ($operation === 'assign' || $operation === 'handover') {
            $collaboration->assign($resource, $user, $data['members'] ?? [], (int) $data['lead'], $revision, $data['note'] ?? null, $operation === 'handover');
        } elseif ($operation === 'note') {
            $collaboration->note($resource, $user, $request->route('event') === null ? null : (int) $request->route('event'), $data['body'] ?? null, $data['mentions'] ?? [], $revision, $delete);
        } elseif ($operation === 'location') {
            $collaboration->location($resource, $user, $request->route('location') === null ? null : (int) $request->route('location'), $data['type'] ?? null, $data['location'] ?? null, $revision, $delete);
        }

        return response()->json($service->panel($resource, $user));
    }

    public function events(CurationRequest $request, Resource $resource): JsonResponse
    {
        $events = ResourceCurationEvent::where('resource_id', $resource->id)
            ->when($request->boolean('notes_only'), fn ($query) => $query->where('type', 'note'))
            ->when($request->integer('cursor') > 0, fn ($query) => $query->where('id', '<', $request->integer('cursor')))
            ->orderByDesc('id')->limit(21)->get();

        return response()->json([
            'data' => $events->take(20)->map(fn (ResourceCurationEvent $event): array => [
                ...$event->toArray(), 'description' => app(CurationEventDescriptionService::class)->describe($event),
                'can_edit' => $event->type === 'note' && $event->deleted_at === null
                    && $event->user_id === $request->user()?->id && $request->user()?->role->value !== 'beginner'
                    && now()->lt($event->created_at->copy()->addHours(24)) && ! $resource->landingPage?->is_tombstone,
            ])->values(),
            'next_cursor' => $events->count() > 20 ? $events->get(19)?->id : null,
        ]);
    }
}
