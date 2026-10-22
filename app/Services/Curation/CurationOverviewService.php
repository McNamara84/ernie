<?php

declare(strict_types=1);

namespace App\Services\Curation;

use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

final class CurationOverviewService
{
    /** @param array<string, mixed> $filters
     * @return Builder<Resource>
     */
    public function query(array $filters, User $user): Builder
    {
        $query = Resource::query()
            ->join('resource_listing_projections as listing', 'listing.resource_id', '=', 'resources.id')
            ->join('resource_curation_states as curation', 'curation.resource_id', '=', 'resources.id')
            ->where('listing.curation_started', true)->where('listing.is_igsn', false)
            ->where('listing.workflow_status', '!=', 'dead');
        $tab = $filters['tab'] ?? 'progress';
        match ($tab) {
            'progress' => $query->whereIn('listing.workflow_status', ['draft', 'curation']),
            'review', 'embargo' => $query->where('listing.workflow_status', $tab),
            'published' => $query->where('listing.workflow_status', 'published')->where(fn ($q) => $q->where('listing.curation_open', true)->orWhere('listing.curation_published_at', '>=', now()->subDays(90))),
            default => $query,
        };
        if ($filters['mine'] ?? false) {
            $this->assigned($query, $user->id);
        }
        if (! empty($filters['curator'])) {
            $this->assigned($query, (int) $filters['curator']);
        }
        if (! empty($filters['datacenter'])) {
            $query->where('listing.datacenter_id', (int) $filters['datacenter']);
        }
        if ($filters['unassigned'] ?? false) {
            $query->whereNull('curation.lead_curator_id');
        }
        if ($filters['waiting'] ?? false) {
            $query->whereNotNull('listing.curation_waiting_since');
        }
        if ($filters['help'] ?? false) {
            $query->where('listing.curation_needs_help', true);
        }
        if ($filters['open'] ?? false) {
            $query->where('listing.curation_open', true);
        }
        if (($filters['overdue'] ?? false) || ($filters['waiting_overdue'] ?? false)) {
            $query->where('listing.curation_open', true)->where(function ($q) use ($filters): void {
                $q->where('listing.curation_waiting_since', '<', $this->waitingCutoff());
                if (! ($filters['waiting_overdue'] ?? false)) {
                    $q->orWhere(fn ($deadline) => $deadline->where('listing.workflow_status', '!=', 'published')->where('listing.curation_deadline', '<', now(config('app.timezone'))->toDateString()));
                }
            });
        }

        return $query;
    }

    public function waitingCutoff(): Carbon
    {
        return now(config('app.timezone'))->startOfDay()->subDays((int) config('curation.waiting_overdue_days', 14))->utc();
    }

    /** @param Builder<Resource> $query */
    private function assigned(Builder $query, int $userId): void
    {
        $query->whereExists(fn (QueryBuilder $assignment) => $assignment->selectRaw('1')->from('resource_curators')->whereColumn('resource_curators.resource_id', 'resources.id')->where('user_id', $userId));
    }

    /** @param array<string, mixed> $filters
     * @return Builder<Resource>
     */
    public function rows(array $filters, User $user): Builder
    {
        $column = match ($filters['sort'] ?? 'activity') {
            'created' => 'resources.created_at', 'deadline' => 'listing.curation_deadline',
            'progress' => 'listing.curation_progress', default => 'listing.curation_activity_at',
        };
        $query = $this->query($filters, $user)->with(['curators', 'datacenter'])
            ->withCasts(['curation_activity_at' => 'immutable_datetime']);
        if ($column === 'listing.curation_deadline') {
            $query->orderByRaw('listing.curation_deadline IS NULL');
        }

        return $query->orderBy($column, ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc')->orderBy('resources.id')
            ->select(['resources.id', 'resources.doi', 'resources.created_at', 'resources.datacenter_id',
                'listing.main_title as curation_title', 'listing.workflow_status', 'listing.curation_progress', 'listing.curation_open',
                'listing.curation_waiting_since', 'listing.curation_needs_help', 'listing.curation_deadline',
                'listing.curation_activity_at', 'listing.curation_contact', 'curation.lead_curator_id']);
    }

    /** @return array<string, mixed> */
    public function row(Resource $resource): array
    {
        $since = $resource->getAttribute('curation_waiting_since');
        $days = $since === null ? null : max(0, (int) Carbon::parse($since)->timezone(config('app.timezone'))->startOfDay()->diffInDays(now(config('app.timezone'))->startOfDay()));
        $deadline = $resource->getAttribute('curation_deadline');
        $overdue = (bool) $resource->getAttribute('curation_open') && (($days !== null && $days > (int) config('curation.waiting_overdue_days', 14))
            || ($deadline !== null && $deadline < now(config('app.timezone'))->toDateString() && $resource->getAttribute('workflow_status') !== 'published'));

        return [
            'id' => $resource->id, 'title' => $resource->getAttribute('curation_title') ?: 'Untitled Resource',
            'doi' => $resource->doi, 'datacenter' => $resource->datacenter?->name, 'contact' => $resource->getAttribute('curation_contact'),
            'created_at' => $resource->created_at?->toISOString(), 'status' => $resource->getAttribute('workflow_status'),
            'lead_id' => $resource->getAttribute('lead_curator_id'),
            'curators' => $resource->curators->map(fn (User $member): array => ['id' => $member->id, 'name' => $member->name])->all(),
            'progress' => (int) $resource->getAttribute('curation_progress'), 'waiting_days' => $days,
            'needs_help' => (bool) $resource->getAttribute('curation_needs_help'), 'overdue' => $overdue,
            'deadline' => $deadline, 'last_activity_at' => $resource->getAttribute('curation_activity_at')?->toISOString(),
        ];
    }

    /** @return array{open: int, waiting: int, help: int} */
    public function widget(User $user): array
    {
        $filters = ['tab' => 'all', 'mine' => true, 'open' => true];

        return ['open' => $this->query($filters, $user)->count(),
            'waiting' => $this->query([...$filters, 'waiting_overdue' => true], $user)->count(),
            'help' => $this->query([...$filters, 'help' => true], $user)->count()];
    }
}
