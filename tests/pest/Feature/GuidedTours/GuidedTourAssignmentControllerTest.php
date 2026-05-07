<?php

declare(strict_types=1);

use App\Models\GuidedTour;
use App\Models\User;
use App\Models\UserGuidedTourAssignment;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('users can mark their guided tour assignment as started', function () {
    $user = User::factory()->beginner()->create();

    $tour = GuidedTour::query()->create([
        'key' => 'beginner-dashboard-main-menu',
        'version' => 1,
        'name' => 'Beginner Dashboard Tour',
        'description' => 'Main menu introduction.',
        'start_route' => 'dashboard',
        'target_roles' => ['beginner'],
        'is_active' => true,
        'auto_assign' => true,
    ]);

    $assignment = UserGuidedTourAssignment::query()->create([
        'user_id' => $user->id,
        'guided_tour_id' => $tour->id,
        'status' => UserGuidedTourAssignment::STATUS_PENDING,
        'assignment_source' => UserGuidedTourAssignment::SOURCE_AUTOMATIC,
        'assigned_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson(route('guided-tours.assignments.start', $assignment))
        ->assertOk()
        ->assertJson([
            'status' => UserGuidedTourAssignment::STATUS_IN_PROGRESS,
        ]);

    $assignment->refresh();

    expect($assignment->status)->toBe(UserGuidedTourAssignment::STATUS_IN_PROGRESS);
    expect($assignment->started_at)->not->toBeNull();
});

test('users can close an incomplete guided tour assignment without completing it', function () {
    $user = User::factory()->beginner()->create();

    $tour = GuidedTour::query()->create([
        'key' => 'beginner-dashboard-main-menu',
        'version' => 1,
        'name' => 'Beginner Dashboard Tour',
        'description' => 'Main menu introduction.',
        'start_route' => 'dashboard',
        'target_roles' => ['beginner'],
        'is_active' => true,
        'auto_assign' => true,
    ]);

    $assignment = UserGuidedTourAssignment::query()->create([
        'user_id' => $user->id,
        'guided_tour_id' => $tour->id,
        'status' => UserGuidedTourAssignment::STATUS_IN_PROGRESS,
        'assignment_source' => UserGuidedTourAssignment::SOURCE_AUTOMATIC,
        'assigned_at' => now()->subMinutes(10),
        'started_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($user)
        ->postJson(route('guided-tours.assignments.close', $assignment))
        ->assertOk()
        ->assertJson([
            'status' => UserGuidedTourAssignment::STATUS_IN_PROGRESS,
            'completed' => false,
        ]);

    expect($assignment->fresh()->completed_at)->toBeNull();
});

test('users can complete their guided tour assignment', function () {
    $user = User::factory()->beginner()->create();

    $tour = GuidedTour::query()->create([
        'key' => 'beginner-dashboard-main-menu',
        'version' => 1,
        'name' => 'Beginner Dashboard Tour',
        'description' => 'Main menu introduction.',
        'start_route' => 'dashboard',
        'target_roles' => ['beginner'],
        'is_active' => true,
        'auto_assign' => true,
    ]);

    $assignment = UserGuidedTourAssignment::query()->create([
        'user_id' => $user->id,
        'guided_tour_id' => $tour->id,
        'status' => UserGuidedTourAssignment::STATUS_IN_PROGRESS,
        'assignment_source' => UserGuidedTourAssignment::SOURCE_AUTOMATIC,
        'assigned_at' => now()->subMinutes(10),
        'started_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($user)
        ->postJson(route('guided-tours.assignments.complete', $assignment))
        ->assertOk()
        ->assertJson([
            'status' => UserGuidedTourAssignment::STATUS_COMPLETED,
            'completed' => true,
        ]);

    $assignment->refresh();

    expect($assignment->status)->toBe(UserGuidedTourAssignment::STATUS_COMPLETED);
    expect($assignment->completed_at)->not->toBeNull();
});

test('users cannot update another users guided tour assignment lifecycle', function () {
    $user = User::factory()->beginner()->create();
    $otherUser = User::factory()->beginner()->create();

    $tour = GuidedTour::query()->create([
        'key' => 'beginner-dashboard-main-menu',
        'version' => 1,
        'name' => 'Beginner Dashboard Tour',
        'description' => 'Main menu introduction.',
        'start_route' => 'dashboard',
        'target_roles' => ['beginner'],
        'is_active' => true,
        'auto_assign' => true,
    ]);

    $assignment = UserGuidedTourAssignment::query()->create([
        'user_id' => $otherUser->id,
        'guided_tour_id' => $tour->id,
        'status' => UserGuidedTourAssignment::STATUS_PENDING,
        'assignment_source' => UserGuidedTourAssignment::SOURCE_AUTOMATIC,
        'assigned_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson(route('guided-tours.assignments.start', $assignment))
        ->assertForbidden();
});