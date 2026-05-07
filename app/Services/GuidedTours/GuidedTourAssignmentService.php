<?php

declare(strict_types=1);

namespace App\Services\GuidedTours;

use App\Models\GuidedTour;
use App\Models\User;
use App\Models\UserGuidedTourAssignment;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class GuidedTourAssignmentService
{
    public function __construct(
        private readonly GuidedTourCatalog $catalog,
    ) {}

    public function syncCatalogTours(): void
    {
        foreach ($this->catalog->all() as $tourDefinition) {
            GuidedTour::query()->updateOrCreate(
                [
                    'key' => $tourDefinition['key'],
                    'version' => $tourDefinition['version'],
                ],
                [
                    'name' => $tourDefinition['name'],
                    'description' => $tourDefinition['description'],
                    'start_route' => $tourDefinition['start_route'],
                    'target_roles' => $tourDefinition['target_roles'],
                    'is_active' => $tourDefinition['is_active'],
                    'auto_assign' => $tourDefinition['auto_assign'],
                ],
            );
        }
    }

    public function syncAutomaticAssignmentsForUser(User $user): void
    {
        $this->syncCatalogTours();

        $eligibleTours = GuidedTour::query()
            ->where('is_active', true)
            ->where('auto_assign', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (GuidedTour $tour): bool => $tour->targetsRole($user->role));

        foreach ($eligibleTours as $tour) {
            UserGuidedTourAssignment::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'guided_tour_id' => $tour->id,
                ],
                [
                    'status' => UserGuidedTourAssignment::STATUS_PENDING,
                    'assignment_source' => UserGuidedTourAssignment::SOURCE_AUTOMATIC,
                    'assigned_at' => now(),
                ],
            );
        }
    }

    /**
     * @param array<int, int|string> $tourIds
     */
    public function assignToursToUser(User $targetUser, array $tourIds, User $actor): int
    {
        $this->syncCatalogTours();

        $requestedTourIds = array_values(array_unique(array_map('intval', $tourIds)));

        $eligibleTours = GuidedTour::query()
            ->where('is_active', true)
            ->whereIn('id', $requestedTourIds)
            ->orderBy('id')
            ->get()
            ->filter(fn (GuidedTour $tour): bool => $tour->targetsRole($targetUser->role))
            ->values();

        if ($eligibleTours->count() !== count($requestedTourIds)) {
            throw ValidationException::withMessages([
                'tour_ids' => 'One or more selected tours are not available for this user role.',
            ]);
        }

        foreach ($eligibleTours as $tour) {
            UserGuidedTourAssignment::query()->updateOrCreate(
                [
                    'user_id' => $targetUser->id,
                    'guided_tour_id' => $tour->id,
                ],
                [
                    'status' => UserGuidedTourAssignment::STATUS_PENDING,
                    'assignment_source' => UserGuidedTourAssignment::SOURCE_MANUAL,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                    'started_at' => null,
                    'completed_at' => null,
                    'last_triggered_at' => null,
                ],
            );
        }

        return $eligibleTours->count();
    }

    /**
     * @return array{
     *     assignmentId: int,
     *     key: string,
     *     version: int,
     *     startRoute: string,
     *     status: string,
     *     autostart: bool
     * }|null
     */
    public function buildAutostartPayloadForRoute(User $user, string $routeName, bool $shouldAutostart): ?array
    {
        $assignment = $this->resolveAutostartAssignmentForRoute($user, $routeName, $shouldAutostart);

        if ($assignment === null || $assignment->guidedTour === null) {
            return null;
        }

        return [
            'assignmentId' => $assignment->id,
            'key' => $assignment->guidedTour->key,
            'version' => $assignment->guidedTour->version,
            'startRoute' => $assignment->guidedTour->start_route,
            'status' => $assignment->status,
            'autostart' => true,
        ];
    }

    public function markStarted(UserGuidedTourAssignment $assignment): UserGuidedTourAssignment
    {
        if ($assignment->status === UserGuidedTourAssignment::STATUS_COMPLETED) {
            return $assignment;
        }

        $assignment->forceFill([
            'status' => UserGuidedTourAssignment::STATUS_IN_PROGRESS,
            'started_at' => $assignment->started_at ?? now(),
            'last_triggered_at' => now(),
        ])->save();

        return $assignment->refresh();
    }

    public function markClosed(UserGuidedTourAssignment $assignment): UserGuidedTourAssignment
    {
        if ($assignment->status === UserGuidedTourAssignment::STATUS_COMPLETED) {
            return $assignment;
        }

        $assignment->forceFill([
            'status' => $assignment->started_at === null
                ? UserGuidedTourAssignment::STATUS_PENDING
                : UserGuidedTourAssignment::STATUS_IN_PROGRESS,
            'last_triggered_at' => now(),
        ])->save();

        return $assignment->refresh();
    }

    public function markCompleted(UserGuidedTourAssignment $assignment): UserGuidedTourAssignment
    {
        $assignment->forceFill([
            'status' => UserGuidedTourAssignment::STATUS_COMPLETED,
            'started_at' => $assignment->started_at ?? now(),
            'completed_at' => now(),
            'last_triggered_at' => now(),
        ])->save();

        return $assignment->refresh();
    }

    private function resolveAutostartAssignmentForRoute(User $user, string $routeName, bool $shouldAutostart): ?UserGuidedTourAssignment
    {
        $this->syncAutomaticAssignmentsForUser($user);

        if (! $shouldAutostart) {
            return null;
        }

        /** @var Collection<int, UserGuidedTourAssignment> $assignments */
        $assignments = UserGuidedTourAssignment::query()
            ->with('guidedTour')
            ->where('user_id', $user->id)
            ->whereIn('status', [
                UserGuidedTourAssignment::STATUS_PENDING,
                UserGuidedTourAssignment::STATUS_IN_PROGRESS,
            ])
            ->whereHas('guidedTour', function ($query) use ($routeName): void {
                $query->where('is_active', true)
                    ->where('start_route', $routeName);
            })
            ->get();

        return $assignments
            ->sortBy([
                fn (UserGuidedTourAssignment $assignment): int => $assignment->status === UserGuidedTourAssignment::STATUS_PENDING ? 0 : 1,
                fn (UserGuidedTourAssignment $assignment): int => $assignment->assigned_at?->getTimestamp() ?? 0,
                fn (UserGuidedTourAssignment $assignment): int => $assignment->id,
            ])
            ->first();
    }
}
