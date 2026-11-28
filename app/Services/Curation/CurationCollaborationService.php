<?php

declare(strict_types=1);

namespace App\Services\Curation;

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\ResourceCurationEvent;
use App\Models\ResourceCurationState;
use App\Models\ResourceDataLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CurationCollaborationService
{
    public function __construct(private CurationService $curation) {}

    /** @param list<int> $members */
    public function assign(Resource $resource, User $actor, array $members, int $lead, int $revision, ?string $note, bool $handover): void
    {
        $members = array_map(intval(...), $members);
        $this->curation->mutate($resource, function (Resource $locked, ResourceCurationState $state) use ($actor, $members, $lead, $revision, $note, $handover): void {
            $this->curation->checkRevision($state->revision, $revision);
            $before = $locked->curators()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
            $ids = array_values(array_unique($handover ? [...$before, $lead] : [...$members, $lead]));
            $eligible = User::whereIn('id', $ids)->where('is_active', true)->where('role', '!=', UserRole::BEGINNER->value)->pluck('id')->all();
            // Existing inactive members may remain visible, but cannot be newly selected or made lead.
            if (! in_array($lead, $eligible, true) || array_diff($ids, $before, $eligible) !== []) {
                throw ValidationException::withMessages(['members' => 'Select active Curators, Group Leaders or Admins.']);
            }
            $oldLead = $state->lead_curator_id;
            if ($oldLead === $lead && array_diff($ids, $before) === [] && array_diff($before, $ids) === []) {
                return;
            }
            $locked->curators()->detach(array_diff($before, $ids));
            foreach (array_diff($ids, $before) as $id) {
                $locked->curators()->attach($id, ['assigned_by' => $actor->id, 'assigned_at' => now()]);
            }
            $state->fill(['lead_curator_id' => $lead, 'revision' => $revision + 1])->save();
            $event = $this->curation->event($locked, $handover ? 'curation.handover' : 'curators.changed', $actor,
                ['before' => $before, 'members' => $ids, 'previous_lead' => $oldLead, 'lead' => $lead,
                    'lead_name' => User::findOrFail($lead)->name, 'member_names' => User::whereIn('id', $ids)->orderBy('id')->pluck('name')->all()], $note);
            $recipients = array_unique([...array_diff($ids, $before), ...($lead !== $oldLead ? [$lead] : [])]);
            foreach ($recipients as $id) {
                if ($id !== $actor->id) {
                    $this->notify($event, $id, 'assignment');
                }
            }
        });
    }

    /** @param list<int> $mentions */
    public function note(Resource $resource, User $actor, ?int $eventId, ?string $body, array $mentions, int $revision, bool $delete = false): void
    {
        $this->curation->mutate($resource, function (Resource $locked) use ($actor, $eventId, $body, $mentions, $revision, $delete): void {
            if ($eventId === null) {
                $event = $this->curation->event($locked, 'note', $actor, [], $body);
            } else {
                $event = ResourceCurationEvent::where('resource_id', $locked->id)->where('type', 'note')->whereNull('deleted_at')->findOrFail($eventId);
                abort_unless($event->user_id === $actor->id && now()->lt($event->created_at->copy()->addHours(24)), 403, 'Only the author can change this note within 24 hours.');
                $this->curation->checkRevision($event->revision, $revision);
                $event->fill(['body' => $delete ? null : $body, 'edited_at' => now(), 'deleted_at' => $delete ? now() : null, 'revision' => $revision + 1])->save();
                ResourceCurationState::whereKey($locked->id)->update(['last_activity_at' => now()]);
            }
            if ($delete) {
                DB::table('resource_curation_notifications')->where('event_id', $event->id)->whereNull('queued_at')->delete();
                DB::table('resource_curation_mentions')->where('event_id', $event->id)->delete();

                return;
            }
            $users = User::whereIn('id', $mentions)->where('is_active', true)->get();
            if ($users->count() !== count(array_unique($mentions))) {
                throw ValidationException::withMessages(['mentions' => 'Mention only active ERNIE users.']);
            }
            foreach ($users as $user) {
                $inserted = DB::table('resource_curation_mentions')->insertOrIgnore(['event_id' => $event->id, 'user_id' => $user->id]);
                if ($inserted && $user->id !== $actor->id) {
                    $this->notify($event, $user->id, 'mention');
                }
            }
        });
    }

    public function location(Resource $resource, User $actor, ?int $id, ?string $type, ?string $location, int $revision, bool $delete): void
    {
        $this->curation->mutate($resource, function (Resource $locked) use ($actor, $id, $type, $location, $revision, $delete): void {
            $entry = $id === null ? new ResourceDataLocation(['resource_id' => $locked->id, 'created_by' => $actor->id])
                : ResourceDataLocation::where('resource_id', $locked->id)->findOrFail($id);
            $this->curation->checkRevision($entry->revision ?? 0, $revision);
            $before = $entry->only(['type', 'location']);
            if ($delete) {
                $entry->delete();
            } else {
                $entry->fill(['type' => $type, 'location' => $location]);
                if (! $entry->isDirty()) {
                    return;
                }
                $entry->revision = $revision + 1;
                $entry->save();
            }
            $this->curation->event($locked, $delete ? 'location.deleted' : 'location.saved', $actor,
                ['id' => $entry->id, 'before' => $before, 'after' => $delete ? null : $entry->only(['type', 'location'])]);
        });
    }

    private function notify(ResourceCurationEvent $event, int $recipient, string $type): void
    {
        DB::table('resource_curation_notifications')->insertOrIgnore(['event_id' => $event->id, 'user_id' => $recipient, 'type' => $type]);
        DB::afterCommit(fn () => app(CurationNotificationService::class)->dispatch());
    }
}
