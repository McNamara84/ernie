<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\GuidedTour;
use App\Models\User;
use App\Models\UserGuidedTourAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

covers(GuidedTour::class, UserGuidedTourAssignment::class);

describe('GuidedTour model', function (): void {
    it('casts attributes and evaluates eligible roles', function (): void {
        $tour = GuidedTour::query()->create([
            'key' => 'guided-tour-' . uniqid(),
            'version' => 1,
            'name' => 'Guided Tour',
            'description' => 'A guided tour for onboarding.',
            'start_route' => 'dashboard',
            'target_roles' => ['beginner', 'curator'],
            'is_active' => true,
            'auto_assign' => false,
        ]);

        expect($tour->target_roles)->toBe(['beginner', 'curator'])
            ->and($tour->is_active)->toBeTrue()
            ->and($tour->auto_assign)->toBeFalse()
            ->and($tour->targetsRole(UserRole::BEGINNER))->toBeTrue()
            ->and($tour->targetsRole('curator'))->toBeTrue()
            ->and($tour->targetsRole(UserRole::ADMIN))->toBeFalse();
    });

    it('resolves creator and assignment relationships', function (): void {
        $creator = User::factory()->admin()->create();
        $participant = User::factory()->beginner()->create();

        $tour = GuidedTour::query()->create([
            'key' => 'guided-tour-rel-' . uniqid(),
            'version' => 1,
            'name' => 'Relationship Tour',
            'description' => 'Covers model relationships.',
            'start_route' => 'dashboard',
            'target_roles' => ['beginner'],
            'is_active' => true,
            'auto_assign' => true,
            'created_by' => $creator->id,
        ]);

        $assignment = UserGuidedTourAssignment::query()->create([
            'user_id' => $participant->id,
            'guided_tour_id' => $tour->id,
            'status' => UserGuidedTourAssignment::STATUS_PENDING,
            'assignment_source' => UserGuidedTourAssignment::SOURCE_AUTOMATIC,
            'assigned_at' => now(),
        ]);

        expect($tour->creator)->toBeInstanceOf(User::class)
            ->and($tour->creator->is($creator))->toBeTrue()
            ->and($tour->assignments)->toHaveCount(1)
            ->and($tour->assignments->first()?->is($assignment))->toBeTrue();
    });
});

describe('UserGuidedTourAssignment model', function (): void {
    it('casts datetime fields and resolves related models', function (): void {
        $assignedBy = User::factory()->admin()->create();
        $user = User::factory()->beginner()->create();
        $tour = GuidedTour::query()->create([
            'key' => 'assignment-tour-' . uniqid(),
            'version' => 1,
            'name' => 'Assignment Tour',
            'description' => 'Covers assignment relations.',
            'start_route' => 'dashboard',
            'target_roles' => ['beginner'],
            'is_active' => true,
            'auto_assign' => true,
        ]);

        $assignment = UserGuidedTourAssignment::query()->create([
            'user_id' => $user->id,
            'guided_tour_id' => $tour->id,
            'status' => UserGuidedTourAssignment::STATUS_IN_PROGRESS,
            'assignment_source' => UserGuidedTourAssignment::SOURCE_MANUAL,
            'assigned_by' => $assignedBy->id,
            'assigned_at' => now()->subMinute(),
            'started_at' => now()->subSeconds(30),
            'completed_at' => null,
            'last_triggered_at' => now(),
        ])->fresh();

        expect($assignment?->assigned_at)->toBeInstanceOf(Carbon::class)
            ->and($assignment?->started_at)->toBeInstanceOf(Carbon::class)
            ->and($assignment?->last_triggered_at)->toBeInstanceOf(Carbon::class)
            ->and($assignment?->user)->toBeInstanceOf(User::class)
            ->and($assignment?->user->is($user))->toBeTrue()
            ->and($assignment?->guidedTour)->toBeInstanceOf(GuidedTour::class)
            ->and($assignment?->guidedTour->is($tour))->toBeTrue()
            ->and($assignment?->assignedBy)->toBeInstanceOf(User::class)
            ->and($assignment?->assignedBy->is($assignedBy))->toBeTrue();
    });

    it('reports whether the assignment is incomplete', function (): void {
        $pendingAssignment = new UserGuidedTourAssignment([
            'status' => UserGuidedTourAssignment::STATUS_PENDING,
        ]);

        $completedAssignment = new UserGuidedTourAssignment([
            'status' => UserGuidedTourAssignment::STATUS_COMPLETED,
        ]);

        expect($pendingAssignment->isIncomplete())->toBeTrue()
            ->and($completedAssignment->isIncomplete())->toBeFalse();
    });
});