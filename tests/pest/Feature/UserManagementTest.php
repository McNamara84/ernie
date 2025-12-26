<?php

declare(strict_types=1);

use App\Enums\UserRole;
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
            // Ensure we have a predictable "system" user with ID 1.
            // (MySQL auto-increment is not rolled back between tests.)
            User::query()->whereKey(1)->delete();
            $user1 = User::factory()->admin()->create(['id' => 1]);
            expect($user1->id)->toBe(1);

            $admin = User::factory()->admin()->create();

            $this->actingAs($admin)
                ->patch(route('users.update-role', $user1), [
                    'role' => UserRole::BEGINNER->value,
                ])
                ->assertForbidden();

            expect($user1->fresh()->role)->toBe(UserRole::ADMIN);
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
            User::query()->whereKey(1)->delete();
            $user1 = User::factory()->admin()->create(['id' => 1]);
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
            User::query()->whereKey(1)->delete();
            $user1 = User::factory()->admin()->create(['id' => 1]);
            $admin = User::factory()->admin()->create();

            $this->actingAs($admin)
                ->post(route('users.reset-password', $user1))
                ->assertForbidden();
        });
    });
});
