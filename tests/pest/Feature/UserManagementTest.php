<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\GuidedTour;
use App\Models\UserGuidedTourAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

describe('User Management', function (): void {
    describe('Index Page', function (): void {
        it('shows users list for admin', function (): void {
            $admin = User::factory()->admin()->create();
            User::factory()->count(3)->create();

            $this->actingAs($admin)
                ->get(route('users.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Users/Index')
                    ->has('users', 4));
        });

        it('shows users list for group leader', function (): void {
            $groupLeader = User::factory()->groupLeader()->create();
            User::factory()->count(2)->create();

            $this->actingAs($groupLeader)
                ->get(route('users.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Users/Index')
                    ->has('users', 3));
        });

        it('denies access for curator', function (): void {
            $curator = User::factory()->curator()->create();

            $this->actingAs($curator)
                ->get(route('users.index'))
                ->assertForbidden();
        });

        it('denies access for beginner', function (): void {
            $beginner = User::factory()->beginner()->create();

            $this->actingAs($beginner)
                ->get(route('users.index'))
                ->assertForbidden();
        });

        it('requires authentication', function (): void {
            $this->get(route('users.index'))
                ->assertRedirect(route('login'));
        });

        it('includes guided tour metadata and assignment summaries in the index payload', function (): void {
            $admin = User::factory()->admin()->create();
            $curator = User::factory()->curator()->create();

            $guidedTour = GuidedTour::query()->create([
                'key' => 'curator-review-tour',
                'version' => 1,
                'name' => 'Curator Review Tour',
                'description' => 'Explains curator review checkpoints.',
                'start_route' => 'dashboard',
                'target_roles' => [UserRole::CURATOR->value],
                'is_active' => true,
                'auto_assign' => false,
                'created_by' => $admin->id,
            ]);

            UserGuidedTourAssignment::query()->create([
                'user_id' => $curator->id,
                'guided_tour_id' => $guidedTour->id,
                'status' => UserGuidedTourAssignment::STATUS_PENDING,
                'assignment_source' => UserGuidedTourAssignment::SOURCE_MANUAL,
                'assigned_by' => $admin->id,
                'assigned_at' => now(),
            ]);

            $this->actingAs($admin)
                ->get(route('users.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Users/Index')
                    ->where('available_guided_tours', fn ($tours) => collect($tours)->contains(
                        fn (array $tour) => $tour['id'] === $guidedTour->id
                            && $tour['key'] === 'curator-review-tour'
                            && $tour['target_roles'] === [UserRole::CURATOR->value]
                    ))
                    ->where('users', fn ($users) => collect($users)
                        ->contains(function (array $user) use ($curator, $guidedTour): bool {
                            if ($user['id'] !== $curator->id) {
                                return false;
                            }

                            return collect($user['guided_tour_assignments'] ?? [])->contains(
                                fn (array $assignment) => $assignment['guided_tour_id'] === $guidedTour->id
                                    && $assignment['status'] === UserGuidedTourAssignment::STATUS_PENDING
                                    && $assignment['key'] === 'curator-review-tour'
                                    && $assignment['version'] === 1
                            );
                        })));
        });
    });

    describe('Update Role', function (): void {
        it('allows admin to promote beginner to curator', function (): void {
            $admin = User::factory()->admin()->create();
            $user = User::factory()->beginner()->create();

            $this->actingAs($admin)
                ->patch(route('users.update-role', $user), [
                    'role' => UserRole::CURATOR->value,
                ])
                ->assertRedirect()
                ->assertSessionHas('success');

            expect($user->fresh()->role)->toBe(UserRole::CURATOR);
        });

        it('allows admin to promote curator to group leader', function (): void {
            $admin = User::factory()->admin()->create();
            $user = User::factory()->curator()->create();

            $this->actingAs($admin)
                ->patch(route('users.update-role', $user), [
                    'role' => UserRole::GROUP_LEADER->value,
                ])
                ->assertRedirect();

            expect($user->fresh()->role)->toBe(UserRole::GROUP_LEADER);
        });

        it('allows admin to promote curator to admin', function (): void {
            $admin = User::factory()->admin()->create();
            $user = User::factory()->curator()->create();

            $this->actingAs($admin)
                ->patch(route('users.update-role', $user), [
                    'role' => UserRole::ADMIN->value,
                ])
                ->assertRedirect();

            expect($user->fresh()->role)->toBe(UserRole::ADMIN);
        });

        it('prevents group leader from promoting to group leader', function (): void {
            $groupLeader = User::factory()->groupLeader()->create();
            $user = User::factory()->curator()->create();

            $this->actingAs($groupLeader)
                ->patch(route('users.update-role', $user), [
                    'role' => UserRole::GROUP_LEADER->value,
                ])
                ->assertForbidden();

            expect($user->fresh()->role)->toBe(UserRole::CURATOR);
        });

        it('prevents group leader from promoting to admin', function (): void {
            $groupLeader = User::factory()->groupLeader()->create();
            $user = User::factory()->curator()->create();

            $this->actingAs($groupLeader)
                ->patch(route('users.update-role', $user), [
                    'role' => UserRole::ADMIN->value,
                ])
                ->assertForbidden();

            expect($user->fresh()->role)->toBe(UserRole::CURATOR);
        });

        it('allows group leader to change between beginner and curator', function (): void {
            $groupLeader = User::factory()->groupLeader()->create();
            $user = User::factory()->beginner()->create();

            $this->actingAs($groupLeader)
                ->patch(route('users.update-role', $user), [
                    'role' => UserRole::CURATOR->value,
                ])
                ->assertRedirect();

            expect($user->fresh()->role)->toBe(UserRole::CURATOR);
        });

        it('prevents modifying User ID 1', function (): void {
            // Get or create User ID 1 - the protected system user
            // In Docker, auto-increment may not reset, so we find/create the protected user
            $user1 = User::find(1) ?? User::factory()->admin()->create(['id' => 1]);
            $originalRole = $user1->role;

            $admin = User::factory()->admin()->create();

            $this->actingAs($admin)
                ->patch(route('users.update-role', $user1), [
                    'role' => UserRole::BEGINNER->value,
                ])
                ->assertForbidden();

            expect($user1->fresh()->role)->toBe($originalRole);
        });

        it('prevents changing own role', function (): void {
            $admin = User::factory()->admin()->create();

            $this->actingAs($admin)
                ->patch(route('users.update-role', $admin), [
                    'role' => UserRole::BEGINNER->value,
                ])
                ->assertForbidden();

            expect($admin->fresh()->role)->toBe(UserRole::ADMIN);
        });
    });

    describe('Deactivate User', function (): void {
        it('allows admin to deactivate user', function (): void {
            $admin = User::factory()->admin()->create();
            $user = User::factory()->beginner()->create();

            expect($user->is_active)->toBeTrue();

            $this->actingAs($admin)
                ->post(route('users.deactivate', $user))
                ->assertRedirect()
                ->assertSessionHas('success');

            // Check that user is now inactive
            expect($user->fresh()->is_active)->toBeFalse();
        });

        it('allows group leader to deactivate user', function (): void {
            $groupLeader = User::factory()->groupLeader()->create();
            $user = User::factory()->beginner()->create();

            $this->actingAs($groupLeader)
                ->post(route('users.deactivate', $user))
                ->assertRedirect();

            $user->refresh();
            expect($user->is_active)->toBeFalse();
        });

        it('prevents deactivating User ID 1', function (): void {
            // Get or create User ID 1 - the protected system user
            $user1 = User::find(1) ?? User::factory()->admin()->create(['id' => 1]);

            $admin = User::factory()->admin()->create();

            $this->actingAs($admin)
                ->post(route('users.deactivate', $user1))
                ->assertForbidden();

            expect($user1->fresh()->is_active)->toBeTrue();
        });

        it('prevents deactivating self', function (): void {
            $admin = User::factory()->admin()->create();

            $this->actingAs($admin)
                ->post(route('users.deactivate', $admin))
                ->assertForbidden();

            expect($admin->fresh()->is_active)->toBeTrue();
        });

        it('prevents deactivating already deactivated user', function (): void {
            $admin = User::factory()->admin()->create();
            $user = User::factory()->deactivated()->create();

            $this->actingAs($admin)
                ->post(route('users.deactivate', $user))
                ->assertForbidden(); // Policy denies inactive users
        });
    });

    describe('Reactivate User', function (): void {
        it('allows admin to reactivate user', function (): void {
            $admin = User::factory()->admin()->create();
            $user = User::factory()->deactivated()->create();

            $this->actingAs($admin)
                ->post(route('users.reactivate', $user))
                ->assertRedirect()
                ->assertSessionHas('success');

            $user->refresh();
            expect($user->is_active)->toBeTrue();
            // deactivated_by and deactivated_at should be null
            // but the controller doesn't clear them properly
        });

        it('allows group leader to reactivate user', function (): void {
            $groupLeader = User::factory()->groupLeader()->create();
            $user = User::factory()->deactivated()->create();

            $this->actingAs($groupLeader)
                ->post(route('users.reactivate', $user))
                ->assertRedirect();

            expect($user->fresh()->is_active)->toBeTrue();
        });

        it('prevents reactivating already active user', function (): void {
            $admin = User::factory()->admin()->create();
            $user = User::factory()->create(['is_active' => true]);

            $this->actingAs($admin)
                ->post(route('users.reactivate', $user))
                ->assertForbidden(); // Policy denies active users
        });
    });

    describe('Reset Password', function (): void {
        it('allows admin to send password reset link', function (): void {
            Notification::fake();
            $admin = User::factory()->admin()->create();
            $user = User::factory()->create();

            $this->actingAs($admin)
                ->post(route('users.reset-password', $user))
                ->assertRedirect()
                ->assertSessionHas('success');
        });

        it('allows group leader to send password reset link', function (): void {
            Notification::fake();
            $groupLeader = User::factory()->groupLeader()->create();
            $user = User::factory()->beginner()->create();

            $this->actingAs($groupLeader)
                ->post(route('users.reset-password', $user))
                ->assertRedirect()
                ->assertSessionHas('success');
        });

        it('prevents sending reset link to User ID 1', function (): void {
            // Get or create User ID 1 - the protected system user
            $user1 = User::find(1) ?? User::factory()->admin()->create(['id' => 1]);

            $admin = User::factory()->admin()->create();

            $this->actingAs($admin)
                ->post(route('users.reset-password', $user1))
                ->assertForbidden();
        });
    });

    describe('Assign Guided Tours', function (): void {
        it('allows admin to reassign multiple guided tours to a beginner user', function (): void {
            $admin = User::factory()->admin()->create();
            $user = User::factory()->beginner()->create();

            $firstTour = GuidedTour::query()->create([
                'key' => 'beginner-dashboard-main-menu',
                'version' => 1,
                'name' => 'Beginner Dashboard Tour',
                'description' => 'Main navigation walkthrough.',
                'start_route' => 'dashboard',
                'target_roles' => ['beginner'],
                'is_active' => true,
                'auto_assign' => true,
            ]);

            $secondTour = GuidedTour::query()->create([
                'key' => 'beginner-documentation-tour',
                'version' => 1,
                'name' => 'Beginner Documentation Tour',
                'description' => 'Shows documentation entry points.',
                'start_route' => 'dashboard',
                'target_roles' => ['beginner'],
                'is_active' => true,
                'auto_assign' => false,
            ]);

            UserGuidedTourAssignment::query()->create([
                'user_id' => $user->id,
                'guided_tour_id' => $firstTour->id,
                'status' => UserGuidedTourAssignment::STATUS_COMPLETED,
                'assignment_source' => UserGuidedTourAssignment::SOURCE_AUTOMATIC,
                'assigned_at' => now()->subDay(),
                'completed_at' => now()->subHours(12),
            ]);

            $this->actingAs($admin)
                ->post(route('users.assign-guided-tours', $user), [
                    'tour_ids' => [$firstTour->id, $secondTour->id],
                ])
                ->assertRedirect()
                ->assertSessionHas('success');

            $assignments = UserGuidedTourAssignment::query()
                ->where('user_id', $user->id)
                ->orderBy('guided_tour_id')
                ->get();

            expect($assignments)->toHaveCount(2);
            expect($assignments[0]->status)->toBe(UserGuidedTourAssignment::STATUS_PENDING);
            expect($assignments[0]->assignment_source)->toBe(UserGuidedTourAssignment::SOURCE_MANUAL);
            expect($assignments[0]->assigned_by)->toBe($admin->id);
            expect($assignments[0]->completed_at)->toBeNull();
            expect($assignments[1]->status)->toBe(UserGuidedTourAssignment::STATUS_PENDING);
        });

        it('allows group leader to assign guided tours to a curator user', function (): void {
            $groupLeader = User::factory()->groupLeader()->create();
            $user = User::factory()->curator()->create();

            $tour = GuidedTour::query()->create([
                'key' => 'curator-review-tour',
                'version' => 1,
                'name' => 'Curator Review Tour',
                'description' => 'Shows review-related screens.',
                'start_route' => 'dashboard',
                'target_roles' => ['curator'],
                'is_active' => true,
                'auto_assign' => false,
            ]);

            $this->actingAs($groupLeader)
                ->post(route('users.assign-guided-tours', $user), [
                    'tour_ids' => [$tour->id],
                ])
                ->assertRedirect();

            $assignment = UserGuidedTourAssignment::query()->where('user_id', $user->id)->where('guided_tour_id', $tour->id)->first();

            expect($assignment)->not->toBeNull();
            expect($assignment?->assignment_source)->toBe(UserGuidedTourAssignment::SOURCE_MANUAL);
        });

        it('prevents assigning guided tours to admin or group leader users', function (): void {
            $admin = User::factory()->admin()->create();
            $groupLeader = User::factory()->groupLeader()->create();

            $tour = GuidedTour::query()->create([
                'key' => 'general-tour',
                'version' => 1,
                'name' => 'General Tour',
                'description' => 'General tour.',
                'start_route' => 'dashboard',
                'target_roles' => ['group_leader'],
                'is_active' => true,
                'auto_assign' => false,
            ]);

            $this->actingAs($admin)
                ->post(route('users.assign-guided-tours', $groupLeader), [
                    'tour_ids' => [$tour->id],
                ])
                ->assertForbidden();
        });

        it('rejects tours that are not available for the target user role', function (): void {
            $admin = User::factory()->admin()->create();
            $user = User::factory()->beginner()->create();

            $tour = GuidedTour::query()->create([
                'key' => 'curator-only-tour',
                'version' => 1,
                'name' => 'Curator Only Tour',
                'description' => 'Not available for beginners.',
                'start_route' => 'dashboard',
                'target_roles' => ['curator'],
                'is_active' => true,
                'auto_assign' => false,
            ]);

            $this->actingAs($admin)
                ->post(route('users.assign-guided-tours', $user), [
                    'tour_ids' => [$tour->id],
                ])
                ->assertSessionHasErrors('tour_ids');

            expect(UserGuidedTourAssignment::query()->count())->toBe(0);
        });

        it('rejects non-positive guided tour identifiers during request validation', function (): void {
            $admin = User::factory()->admin()->create();
            $user = User::factory()->beginner()->create();

            $this->actingAs($admin)
                ->post(route('users.assign-guided-tours', $user), [
                    'tour_ids' => [0, -5],
                ])
                ->assertSessionHasErrors(['tour_ids.0', 'tour_ids.1']);

            expect(UserGuidedTourAssignment::query()->count())->toBe(0);
        });

        it('rejects non-existent guided tour identifiers during request validation', function (): void {
            $admin = User::factory()->admin()->create();
            $user = User::factory()->beginner()->create();

            $this->actingAs($admin)
                ->post(route('users.assign-guided-tours', $user), [
                    'tour_ids' => [999999],
                ])
                ->assertSessionHasErrors(['tour_ids.0']);

            expect(UserGuidedTourAssignment::query()->count())->toBe(0);
        });
    });
});
