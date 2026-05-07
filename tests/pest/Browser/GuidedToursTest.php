<?php

declare(strict_types=1);

use App\Models\GuidedTour;
use App\Models\User;
use App\Models\UserGuidedTourAssignment;
use Tests\TestCase;

uses()->group('guided-tours', 'browser');

describe('Guided tours', function (): void {
    it('autostarts the beginner dashboard tour on the first dashboard visit and persists completion', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->beginner()->create([
            'name' => 'Beginner Browser User',
        ]);

        $tour = GuidedTour::query()->create([
            'key' => 'beginner-dashboard-main-menu',
            'version' => 1,
            'name' => 'Beginner Dashboard Tour',
            'description' => 'Introduces the main dashboard and navigation entry points for beginner users.',
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

        $this->actingAs($user);
        $this->withSession([
            'guided_tours.autostart_after_login' => true,
        ]);

        $page = visit('/dashboard')
            ->assertNoSmoke()
            ->assertPathIs('/dashboard')
            ->assertSee('Hello Beginner Browser User!')
            ->assertPresent('.driver-popover')
            ->assertSeeIn('.driver-popover-title', 'Welcome to ERNIE')
            ->assertSeeIn('.driver-popover-progress-text', 'Step 1 of 8');

        $expectedStepTitles = [
            'Main Menu',
            'Upload Area',
            'Data Editor',
            'Resources',
            'IGSNs List',
            'IGSNs Map',
            'Documentation',
        ];

        foreach ($expectedStepTitles as $stepNumber => $stepTitle) {
            $page->click('.driver-popover-next-btn')
                ->assertSeeIn('.driver-popover-title', $stepTitle)
                ->assertSeeIn('.driver-popover-progress-text', 'Step '.($stepNumber + 2).' of 8');
        }

        $page->click('.driver-popover-next-btn')
            ->assertNotPresent('.driver-popover')
            ->wait(1);

        $assignment->refresh();

        expect($assignment->status)->toBe(UserGuidedTourAssignment::STATUS_COMPLETED);
        expect($assignment->started_at)->not->toBeNull();
        expect($assignment->completed_at)->not->toBeNull();
    });
});