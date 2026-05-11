<?php

declare(strict_types=1);

use App\Models\GuidedTour;
use App\Models\User;
use App\Models\UserGuidedTourAssignment;
use App\Services\GuidedTours\GuidedTourAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

covers(GuidedTourAssignmentService::class);

describe('GuidedTourAssignmentService', function (): void {
    beforeEach(function (): void {
        $this->service = app(GuidedTourAssignmentService::class);
    });

    it('returns null when autostart is disabled for the current request', function (): void {
        $user = User::factory()->beginner()->create();
        $tour = GuidedTour::query()->create([
            'key' => 'manual-tour-' . uniqid(),
            'version' => 1,
            'name' => 'Manual Tour',
            'description' => 'Should not autostart when disabled.',
            'start_route' => 'dashboard',
            'target_roles' => ['beginner'],
            'is_active' => true,
            'auto_assign' => false,
        ]);

        UserGuidedTourAssignment::query()->create([
            'user_id' => $user->id,
            'guided_tour_id' => $tour->id,
            'status' => UserGuidedTourAssignment::STATUS_PENDING,
            'assignment_source' => UserGuidedTourAssignment::SOURCE_MANUAL,
            'assigned_at' => now(),
        ]);

        expect($this->service->buildAutostartPayloadForRoute($user, 'dashboard', false))->toBeNull();
    });

    it('does not sync automatic assignments when autostart is disabled for the current request', function (): void {
        $user = User::factory()->beginner()->create();

        expect(GuidedTour::query()->count())->toBe(0)
            ->and(UserGuidedTourAssignment::query()->count())->toBe(0);

        expect($this->service->buildAutostartPayloadForRoute($user, 'dashboard', false))->toBeNull();

        expect(GuidedTour::query()->count())->toBe(0)
            ->and(UserGuidedTourAssignment::query()->count())->toBe(0);
    });

    it('keeps completed assignments unchanged when start or close is reported again', function (): void {
        $user = User::factory()->beginner()->create();
        $tour = GuidedTour::query()->create([
            'key' => 'completed-tour-' . uniqid(),
            'version' => 1,
            'name' => 'Completed Tour',
            'description' => 'Already completed.',
            'start_route' => 'dashboard',
            'target_roles' => ['beginner'],
            'is_active' => true,
            'auto_assign' => true,
        ]);

        $completedAt = now()->subMinute();
        $startedAt = $completedAt->copy()->subMinute();
        $lastTriggeredAt = now();

        $assignment = UserGuidedTourAssignment::query()->create([
            'user_id' => $user->id,
            'guided_tour_id' => $tour->id,
            'status' => UserGuidedTourAssignment::STATUS_COMPLETED,
            'assignment_source' => UserGuidedTourAssignment::SOURCE_AUTOMATIC,
            'assigned_at' => $startedAt,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'last_triggered_at' => $lastTriggeredAt,
        ]);

        $this->service->markStarted($assignment);
        $this->service->markClosed($assignment);

        $assignment->refresh();

        expect($assignment->status)->toBe(UserGuidedTourAssignment::STATUS_COMPLETED)
            ->and($assignment->started_at?->toDateTimeString())->toBe($startedAt->toDateTimeString())
            ->and($assignment->completed_at?->toDateTimeString())->toBe($completedAt->toDateTimeString())
            ->and($assignment->last_triggered_at?->toDateTimeString())->toBe($lastTriggeredAt->toDateTimeString());
    });
});