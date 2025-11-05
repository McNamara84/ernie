<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;

describe('User Model', function (): void {
    describe('Relationships', function (): void {
        it('has deactivatedBy relationship', function (): void {
            $deactivator = User::factory()->admin()->create();
            $user = User::factory()->create([
                'is_active' => false,
                'deactivated_by' => $deactivator->id,
            ]);

            expect($user->deactivatedBy)->toBeInstanceOf(User::class)
                ->and($user->deactivatedBy->id)->toBe($deactivator->id);
        });

        it('has deactivatedUsers relationship', function (): void {
            $admin = User::factory()->admin()->create();
            $deactivatedUser = User::factory()->create([
                'is_active' => false,
                'deactivated_by' => $admin->id,
            ]);

            expect($admin->deactivatedUsers)->toHaveCount(1)
                ->and($admin->deactivatedUsers->first()->id)->toBe($deactivatedUser->id);
        });
    });

    describe('Scopes', function (): void {
        beforeEach(function (): void {
            User::factory()->count(3)->create(['is_active' => true]);
            User::factory()->count(2)->deactivated()->create();
        });

        it('filters active users', function (): void {
            $activeUsers = User::active()->get();

            expect($activeUsers)->toHaveCount(3)
                ->and($activeUsers->every(fn (User $user): bool => $user->is_active))->toBeTrue();
        });

        it('filters inactive users', function (): void {
            $inactiveUsers = User::inactive()->get();

            expect($inactiveUsers)->toHaveCount(2)
                ->and($inactiveUsers->every(fn (User $user): bool => ! $user->is_active))->toBeTrue();
        });

        it('filters by role', function (): void {
            User::factory()->admin()->create();
            User::factory()->groupLeader()->count(2)->create();

            $admins = User::role(UserRole::ADMIN)->get();
            $groupLeaders = User::role(UserRole::GROUP_LEADER)->get();

            expect($admins)->toHaveCount(1)
                ->and($admins->first()->role)->toBe(UserRole::ADMIN)
                ->and($groupLeaders)->toHaveCount(2)
                ->and($groupLeaders->every(fn (User $user): bool => $user->role === UserRole::GROUP_LEADER))->toBeTrue();
        });
    });

    describe('Helper Methods', function (): void {
        it('canManageUsers() returns true for admin', function (): void {
            $admin = User::factory()->admin()->create();

            expect($admin->canManageUsers())->toBeTrue();
        });

        it('canManageUsers() returns true for group leader', function (): void {
            $groupLeader = User::factory()->groupLeader()->create();

            expect($groupLeader->canManageUsers())->toBeTrue();
        });

        it('canManageUsers() returns false for curator', function (): void {
            $curator = User::factory()->curator()->create();

            expect($curator->canManageUsers())->toBeFalse();
        });

        it('canManageUsers() returns false for beginner', function (): void {
            $beginner = User::factory()->beginner()->create();

            expect($beginner->canManageUsers())->toBeFalse();
        });

        it('canPromoteToGroupLeader() returns true for admin', function (): void {
            $admin = User::factory()->admin()->create();

            expect($admin->canPromoteToGroupLeader())->toBeTrue();
        });

        it('canPromoteToGroupLeader() returns false for non-admin', function (): void {
            $groupLeader = User::factory()->groupLeader()->create();
            $curator = User::factory()->curator()->create();
            $beginner = User::factory()->beginner()->create();

            expect($groupLeader->canPromoteToGroupLeader())->toBeFalse()
                ->and($curator->canPromoteToGroupLeader())->toBeFalse()
                ->and($beginner->canPromoteToGroupLeader())->toBeFalse();
        });

        it('canRegisterProductionDoi() returns true for privileged roles', function (): void {
            $admin = User::factory()->admin()->create();
            $groupLeader = User::factory()->groupLeader()->create();
            $curator = User::factory()->curator()->create();

            expect($admin->canRegisterProductionDoi())->toBeTrue()
                ->and($groupLeader->canRegisterProductionDoi())->toBeTrue()
                ->and($curator->canRegisterProductionDoi())->toBeTrue();
        });

        it('canRegisterProductionDoi() returns false for beginner', function (): void {
            $beginner = User::factory()->beginner()->create();

            expect($beginner->canRegisterProductionDoi())->toBeFalse();
        });
    });

    describe('Role Enum Integration', function (): void {
        it('delegates canManageUsers to role enum', function (): void {
            $admin = User::factory()->admin()->create();
            $beginner = User::factory()->beginner()->create();

            expect($admin->canManageUsers())->toBe($admin->role->canManageUsers())
                ->and($beginner->canManageUsers())->toBe($beginner->role->canManageUsers());
        });

        it('delegates canPromoteToGroupLeader to role enum', function (): void {
            $admin = User::factory()->admin()->create();
            $groupLeader = User::factory()->groupLeader()->create();

            expect($admin->canPromoteToGroupLeader())->toBe($admin->role->canPromoteToGroupLeader())
                ->and($groupLeader->canPromoteToGroupLeader())->toBe($groupLeader->role->canPromoteToGroupLeader());
        });

        it('delegates canRegisterProductionDoi to role enum', function (): void {
            $curator = User::factory()->curator()->create();
            $beginner = User::factory()->beginner()->create();

            expect($curator->canRegisterProductionDoi())->toBe($curator->role->canRegisterProductionDoi())
                ->and($beginner->canRegisterProductionDoi())->toBe($beginner->role->canRegisterProductionDoi());
        });
    });
});
