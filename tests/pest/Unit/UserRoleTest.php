<?php

declare(strict_types=1);

use App\Enums\UserRole;

describe('UserRole Enum', function (): void {
    it('has correct role values', function (): void {
        expect(UserRole::ADMIN->value)->toBe('admin')
            ->and(UserRole::GROUP_LEADER->value)->toBe('group_leader')
            ->and(UserRole::CURATOR->value)->toBe('curator')
            ->and(UserRole::BEGINNER->value)->toBe('beginner');
    });

    it('returns correct label for each role', function (): void {
        expect(UserRole::ADMIN->label())->toBe('Admin')
            ->and(UserRole::GROUP_LEADER->label())->toBe('Group Leader')
            ->and(UserRole::CURATOR->label())->toBe('Curator')
            ->and(UserRole::BEGINNER->label())->toBe('Beginner');
    });

    describe('canManageUsers()', function (): void {
        it('returns true for admin', function (): void {
            expect(UserRole::ADMIN->canManageUsers())->toBeTrue();
        });

        it('returns true for group leader', function (): void {
            expect(UserRole::GROUP_LEADER->canManageUsers())->toBeTrue();
        });

        it('returns false for curator', function (): void {
            expect(UserRole::CURATOR->canManageUsers())->toBeFalse();
        });

        it('returns false for beginner', function (): void {
            expect(UserRole::BEGINNER->canManageUsers())->toBeFalse();
        });
    });

    describe('canPromoteToGroupLeader()', function (): void {
        it('returns true for admin', function (): void {
            expect(UserRole::ADMIN->canPromoteToGroupLeader())->toBeTrue();
        });

        it('returns false for group leader', function (): void {
            expect(UserRole::GROUP_LEADER->canPromoteToGroupLeader())->toBeFalse();
        });

        it('returns false for curator', function (): void {
            expect(UserRole::CURATOR->canPromoteToGroupLeader())->toBeFalse();
        });

        it('returns false for beginner', function (): void {
            expect(UserRole::BEGINNER->canPromoteToGroupLeader())->toBeFalse();
        });
    });

    describe('canRegisterProductionDoi()', function (): void {
        it('returns true for admin', function (): void {
            expect(UserRole::ADMIN->canRegisterProductionDoi())->toBeTrue();
        });

        it('returns true for group leader', function (): void {
            expect(UserRole::GROUP_LEADER->canRegisterProductionDoi())->toBeTrue();
        });

        it('returns true for curator', function (): void {
            expect(UserRole::CURATOR->canRegisterProductionDoi())->toBeTrue();
        });

        it('returns false for beginner', function (): void {
            expect(UserRole::BEGINNER->canRegisterProductionDoi())->toBeFalse();
        });
    });

    describe('Role hierarchy', function (): void {
        it('has correct permission hierarchy', function (): void {
            // Admin has all permissions
            expect(UserRole::ADMIN->canManageUsers())->toBeTrue()
                ->and(UserRole::ADMIN->canPromoteToGroupLeader())->toBeTrue()
                ->and(UserRole::ADMIN->canRegisterProductionDoi())->toBeTrue();

            // Group Leader can manage users and register DOIs, but cannot promote to Group Leader
            expect(UserRole::GROUP_LEADER->canManageUsers())->toBeTrue()
                ->and(UserRole::GROUP_LEADER->canPromoteToGroupLeader())->toBeFalse()
                ->and(UserRole::GROUP_LEADER->canRegisterProductionDoi())->toBeTrue();

            // Curator can only register DOIs
            expect(UserRole::CURATOR->canManageUsers())->toBeFalse()
                ->and(UserRole::CURATOR->canPromoteToGroupLeader())->toBeFalse()
                ->and(UserRole::CURATOR->canRegisterProductionDoi())->toBeTrue();

            // Beginner has no special permissions
            expect(UserRole::BEGINNER->canManageUsers())->toBeFalse()
                ->and(UserRole::BEGINNER->canPromoteToGroupLeader())->toBeFalse()
                ->and(UserRole::BEGINNER->canRegisterProductionDoi())->toBeFalse();
        });
    });
});
