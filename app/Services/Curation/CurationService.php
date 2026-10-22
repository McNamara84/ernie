<?php

declare(strict_types=1);

namespace App\Services\Curation;

use App\Enums\UserRole;
use App\Jobs\VerifyCurationRegistrationJob;
use App\Models\Resource;
use App\Models\ResourceChecklistItem;
use App\Models\ResourceCurationEvent;
use App\Models\ResourceCurationState;
use App\Models\ResourceDataLocation;
use App\Models\User;
use App\Policies\ResourceCurationPolicy;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Support\Curation\CurationChecklist;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class CurationService
{
    public const RELATIONS = ['resourceType', 'igsnMetadata', 'landingPage', 'titles.titleType', 'creators.creatorable', 'rights', 'descriptions.descriptionType', 'dates.dateType', 'curationState', 'checklistItems', 'curators', 'registrationEvidence', 'reviewDispatches'];

    public function available(): bool
    {
        return Schema::hasTable('resource_curation_states');
    }

    public function eligible(Resource $resource): bool
    {
        return ! $resource->isIgsn() && $resource->igsnMetadata === null;
    }

    public function start(Resource $resource, ?User $actor, string $source = 'editor', bool $explicit = false): void
    {
        if (! $this->available()) {
            return;
        }
        DB::transaction(function () use ($resource, $actor, $source, $explicit): void {
            $fresh = Resource::query()->lockForUpdate()->findOrFail($resource->id);
            $fresh->load(self::RELATIONS);
            if (! $this->eligible($fresh) || $fresh->curationState !== null || $fresh->publicStatus() === 'dead'
                || (! $explicit && $fresh->publicStatus() === 'published')) {
                return;
            }
            $lead = $actor !== null && $actor->is_active && $actor->role !== UserRole::BEGINNER && in_array($source, ['editor', 'explicit'], true) ? $actor : null;
            ResourceCurationState::create([
                'resource_id' => $fresh->id, 'started_by' => $actor?->id, 'start_source' => $source,
                'started_at' => now(), 'last_activity_at' => now(), 'lead_curator_id' => $lead?->id,
            ]);
            if ($lead !== null) {
                $fresh->curators()->attach($lead->id, ['assigned_by' => $lead->id, 'assigned_at' => now()]);
            }
            foreach ($this->automatic($fresh) as $key => $done) {
                ResourceChecklistItem::create(['resource_id' => $fresh->id, 'item_key' => $key, 'state' => $done ? 'done' : 'open',
                    'changed_by_name' => 'ERNIE', 'changed_at' => now(), 'reason' => 'Observed when curation started']);
            }
            $this->event($fresh, 'curation.started', $actor, ['source' => $source, 'snapshot' => $this->snapshot($fresh), 'observed' => true]);
            if ($explicit && $fresh->doi && $fresh->registrationEvidence->isEmpty()) {
                VerifyCurationRegistrationJob::dispatch($fresh->id, $fresh->doi, (bool) config('datacite.test_mode'))
                    ->onQueue((string) config('datacite.queue', 'datacite'))->afterCommit();
            }
            app(ResourceListingProjectionRefreshService::class)->schedule($fresh->id);
        });
    }

    /** @return array<string, bool> */
    public function automatic(Resource $resource): array
    {
        $resource->loadMissing(self::RELATIONS);
        $registered = $resource->registrationEvidence->contains(fn ($evidence): bool => strtolower($evidence->doi) === strtolower((string) $resource->doi)
            && ($evidence->environment === 'production' || ((bool) config('datacite.test_mode') && in_array($evidence->environment, ['test', 'fake'], true)))
            && in_array($evidence->state, ['registered', 'findable'], true));

        return [
            'metadata_complete' => $resource->isComplete(),
            'landing_page_configured' => $resource->landingPage !== null,
            'doi_assigned' => trim((string) $resource->doi) !== '',
            'review_link_sent' => $resource->reviewDispatches->isNotEmpty(),
            'doi_registered_and_published' => $registered && $resource->landingPage?->is_published === true && ! $resource->landingPage->is_tombstone,
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(Resource $resource): array
    {
        return ['automatic' => $this->automatic($resource), 'status' => $resource->publicStatus()];
    }

    /** Reconcile after the complete resource graph was saved, never during a projection rebuild. */
    public function reconcile(int $resourceId): void
    {
        if (! $this->available() || ! ResourceCurationState::whereKey($resourceId)->exists()) {
            return;
        }
        DB::transaction(function () use ($resourceId): void {
            $resource = Resource::query()->lockForUpdate()->find($resourceId);
            if ($resource === null || ! $this->eligible($resource)) {
                return;
            }
            $resource->load(self::RELATIONS);
            $previous = ResourceCurationEvent::where('resource_id', $resourceId)
                ->whereIn('type', ['curation.started', 'resource.changed'])->latest('id')->first();
            $before = $previous?->payload['snapshot'] ?? [];
            $after = $this->snapshot($resource);
            if ($before !== $after) {
                foreach ($after['automatic'] as $key => $done) {
                    if (($before['automatic'][$key] ?? null) !== $done) {
                        ResourceChecklistItem::updateOrCreate(['resource_id' => $resourceId, 'item_key' => $key], [
                            'state' => $done ? 'done' : 'open', 'changed_by_name' => 'ERNIE', 'changed_at' => now(), 'reason' => null,
                        ]);
                    }
                }
                $this->event($resource, 'resource.changed', null, ['before' => $before, 'snapshot' => $after]);
            }
        });
    }

    /** @param array<string, mixed> $payload */
    public function event(Resource $resource, string $type, ?User $actor, array $payload = [], ?string $body = null): ResourceCurationEvent
    {
        $event = ResourceCurationEvent::create([
            'resource_id' => $resource->id, 'type' => $type, 'user_id' => $actor?->id,
            'actor_name' => $actor->name ?? 'ERNIE', 'payload' => $payload,
            'body' => $body, 'created_at' => now(),
        ]);
        ResourceCurationState::whereKey($resource->id)->update(['last_activity_at' => now()]);

        return $event;
    }

    /** @return list<array<string, mixed>> */
    public function items(Resource $resource): array
    {
        $automatic = $this->automatic($resource);
        $manual = $resource->checklistItems->keyBy('item_key');
        $items = [];
        foreach (CurationChecklist::items() as $key => $definition) {
            $saved = $manual->get($key);
            $items[] = [
                ...$definition, 'key' => $key,
                'state' => $definition['automatic'] ? ($automatic[$key] ? 'done' : 'open') : ($saved->state ?? 'open'),
                'reason' => $saved?->reason, 'changed_by' => $saved?->changed_by,
                'changed_by_name' => $saved?->getAttribute('changed_by_name'),
                'observed' => $definition['automatic'] && $saved?->reason !== null,
                'changed_at' => $saved?->changed_at?->toISOString(), 'revision' => $saved->revision ?? 0,
            ];
        }

        return $items;
    }

    /** @return array{progress: int, manual_open: int, open: bool, waiting_days: int|null, waiting_overdue: bool, deadline_overdue: bool, overdue: bool} */
    public function summary(Resource $resource): array
    {
        $items = $this->items($resource);
        $progress = count(array_filter($items, fn ($item): bool => $item['state'] !== 'open'));
        $manualOpen = count(array_filter($items, fn ($item): bool => ! $item['automatic'] && $item['state'] === 'open'));
        $state = $resource->curationState;
        $status = $resource->publicStatus();
        $open = $state !== null && $this->eligible($resource) && $status !== 'dead'
            && ($status !== 'published' || $manualOpen > 0 || $state->waiting_for !== null || $state->needs_help_text !== null);
        $today = now(config('app.timezone'))->startOfDay();
        $days = $state?->waiting_since === null ? null : max(0, (int) $state->waiting_since->copy()->timezone(config('app.timezone'))->startOfDay()->diffInDays($today));
        $waitingOverdue = $open && $days !== null && $days > (int) config('curation.waiting_overdue_days', 14);
        $deadlineOverdue = $open && $status !== 'published' && $state->deadline !== null && $state->deadline < $today->toDateString();

        return ['progress' => $progress, 'manual_open' => $manualOpen, 'open' => $open, 'waiting_days' => $days, 'waiting_overdue' => $waitingOverdue, 'deadline_overdue' => $deadlineOverdue, 'overdue' => $waitingOverdue || $deadlineOverdue];
    }

    /** @return array<string, mixed> */
    public function panel(Resource $resource, User $user): array
    {
        $resource->load(self::RELATIONS);
        $state = $resource->curationState;

        return [
            'resource_id' => $resource->id, 'started' => $state !== null, 'state' => $state?->toArray(),
            'can_write' => (new ResourceCurationPolicy)->update($user, $resource),
            'items' => $this->items($resource), 'summary' => $this->summary($resource),
            'curators' => $resource->curators->map(fn (User $curator): array => ['id' => $curator->id, 'name' => $curator->name, 'active' => $curator->is_active, 'eligible' => $curator->is_active && $curator->role !== UserRole::BEGINNER])->all(),
            'locations' => ResourceDataLocation::where('resource_id', $resource->id)->orderBy('id')->get()->toArray(),
            'latest_note' => ResourceCurationEvent::where('resource_id', $resource->id)->where('type', 'note')->whereNull('deleted_at')->latest('id')->value('body'),
        ];
    }

    public function checkRevision(int $actual, int $expected): void
    {
        abort_if($actual !== $expected, 409, 'This entry changed. Reload it before saving your changes.');
    }

    /** @param Closure(Resource, ResourceCurationState): void $action */
    public function mutate(Resource $resource, Closure $action): void
    {
        DB::transaction(function () use ($resource, $action): void {
            $locked = Resource::query()->lockForUpdate()->findOrFail($resource->id);
            abort_unless($this->eligible($locked) && ! $locked->landingPage?->is_tombstone, 403);
            $state = ResourceCurationState::whereKey($resource->id)->firstOrFail();
            $action($locked, $state);
            $this->reconcile($resource->id);
            app(ResourceListingProjectionRefreshService::class)->schedule($resource->id);
        });
    }

    public function checklist(Resource $resource, User $user, string $key, string $value, ?string $reason, int $revision): void
    {
        $definition = CurationChecklist::items()[$key] ?? null;
        if ($definition === null || $definition['automatic'] || ($value === 'not_applicable' && (! $definition['allows_na'] || trim((string) $reason) === ''))) {
            throw ValidationException::withMessages(['state' => 'This checklist change is not allowed. Not applicable requires an explanation.']);
        }
        $this->mutate($resource, function (Resource $locked) use ($user, $key, $value, $reason, $revision): void {
            $item = ResourceChecklistItem::firstOrNew(['resource_id' => $locked->id, 'item_key' => $key]);
            $this->checkRevision($item->revision ?? 0, $revision);
            $nextReason = $value === 'not_applicable' ? $reason : null;
            if (($item->state ?? 'open') === $value && $item->reason === $nextReason) {
                return;
            }
            $before = ['state' => $item->state ?? 'open', 'reason' => $item->reason];
            $item->fill(['state' => $value, 'reason' => $nextReason, 'changed_by' => $user->id, 'changed_by_name' => $user->name, 'changed_at' => now(), 'revision' => $revision + 1])->save();
            $this->event($locked, 'checklist.changed', $user, ['key' => $key, 'before' => $before, 'state' => $value, 'reason' => $nextReason]);
        });
    }

    /** @param array<string, mixed> $data */
    public function state(Resource $resource, User $user, array $data): void
    {
        $this->mutate($resource, function (Resource $locked, ResourceCurationState $state) use ($user, $data): void {
            $this->checkRevision($state->revision, (int) $data['revision']);
            $before = $state->only(['waiting_for', 'waiting_text', 'waiting_since', 'needs_help_text', 'deadline', 'deadline_reason']);
            $state->fill(array_intersect_key($data, array_flip(['waiting_for', 'waiting_text', 'needs_help_text', 'deadline', 'deadline_reason'])));
            if ($state->isDirty('waiting_for')) {
                $state->waiting_since = $state->waiting_for === null ? null : now();
            }
            if ($state->waiting_for === null) {
                $state->waiting_text = null;
            }
            if ($state->deadline === null) {
                $state->deadline_reason = null;
            }
            if (! $state->isDirty()) {
                return;
            }
            $state->revision++;
            $state->save();
            $this->event($locked, 'state.changed', $user, ['before' => $before, 'after' => $state->only(array_keys($before))]);
        });
    }
}
