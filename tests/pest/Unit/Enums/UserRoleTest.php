<?php

declare(strict_types=1);

use App\Enums\UserRole;

covers(UserRole::class);

describe('UserRole', function (): void {
    test('has correct values', function (): void {
        expect(UserRole::ADMIN->value)->toBe('admin');
        expect(UserRole::GROUP_LEADER->value)->toBe('group_leader');
        expect(UserRole::CURATOR->value)->toBe('curator');
        expect(UserRole::BEGINNER->value)->toBe('beginner');
    });

    test('canManageUsers returns true for admin and group leader', function (): void {
        expect(UserRole::ADMIN->canManageUsers())->toBeTrue();
        expect(UserRole::GROUP_LEADER->canManageUsers())->toBeTrue();
        expect(UserRole::CURATOR->canManageUsers())->toBeFalse();
        expect(UserRole::BEGINNER->canManageUsers())->toBeFalse();
    });

    test('canPromoteToGroupLeader returns true only for admin', function (): void {
        expect(UserRole::ADMIN->canPromoteToGroupLeader())->toBeTrue();
        expect(UserRole::GROUP_LEADER->canPromoteToGroupLeader())->toBeFalse();
        expect(UserRole::CURATOR->canPromoteToGroupLeader())->toBeFalse();
        expect(UserRole::BEGINNER->canPromoteToGroupLeader())->toBeFalse();
    });

    test('canRegisterProductionDoi returns true for admin, group leader, curator', function (): void {
        expect(UserRole::ADMIN->canRegisterProductionDoi())->toBeTrue();
        expect(UserRole::GROUP_LEADER->canRegisterProductionDoi())->toBeTrue();
        expect(UserRole::CURATOR->canRegisterProductionDoi())->toBeTrue();
        expect(UserRole::BEGINNER->canRegisterProductionDoi())->toBeFalse();
    });

    test('getPromotableRoles returns correct roles', function (): void {
        expect(UserRole::ADMIN->getPromotableRoles())->toBe([
            UserRole::ADMIN, UserRole::GROUP_LEADER, UserRole::CURATOR, UserRole::BEGINNER,
        ]);
        expect(UserRole::GROUP_LEADER->getPromotableRoles())->toBe([
            UserRole::CURATOR, UserRole::BEGINNER,
        ]);
        expect(UserRole::CURATOR->getPromotableRoles())->toBe([]);
        expect(UserRole::BEGINNER->getPromotableRoles())->toBe([]);
    });

    test('label returns human-readable name', function (): void {
        expect(UserRole::ADMIN->label())->toBe('Admin');
        expect(UserRole::GROUP_LEADER->label())->toBe('Group Leader');
        expect(UserRole::CURATOR->label())->toBe('Curator');
        expect(UserRole::BEGINNER->label())->toBe('Beginner');
    });

    test('badgeVariant returns correct variant', function (): void {
        expect(UserRole::ADMIN->badgeVariant())->toBe('destructive');
        expect(UserRole::GROUP_LEADER->badgeVariant())->toBe('default');
        expect(UserRole::CURATOR->badgeVariant())->toBe('secondary');
        expect(UserRole::BEGINNER->badgeVariant())->toBe('outline');
    });
});
