<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;

describe('viewAny', function () {
    test('admin can view user management', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        expect($admin->can('viewAny', User::class))->toBeTrue();
    });

    test('group leader can view user management', function () {
        $leader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);

        expect($leader->can('viewAny', User::class))->toBeTrue();
    });

    test('curator cannot view user management', function () {
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);

        expect($curator->can('viewAny', User::class))->toBeFalse();
    });

    test('beginner cannot view user management', function () {
        $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);

        expect($beginner->can('viewAny', User::class))->toBeFalse();
    });
});

describe('view', function () {
    test('user can view own profile', function () {
        $user = User::factory()->create(['role' => UserRole::BEGINNER]);

        expect($user->can('view', $user))->toBeTrue();
    });

    test('admin can view other users', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $other = User::factory()->create(['role' => UserRole::BEGINNER]);

        expect($admin->can('view', $other))->toBeTrue();
    });

    test('beginner cannot view other users', function () {
        $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
        $other = User::factory()->create(['role' => UserRole::CURATOR]);

        expect($beginner->can('view', $other))->toBeFalse();
    });
});

describe('create', function () {
    test('admin can create users', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        expect($admin->can('create', User::class))->toBeTrue();
    });

    test('group leader can create users', function () {
        $leader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);

        expect($leader->can('create', User::class))->toBeTrue();
    });

    test('curator cannot create users', function () {
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);

        expect($curator->can('create', User::class))->toBeFalse();
    });
});

describe('update', function () {
    test('user can update own profile', function () {
        $user = User::factory()->create(['role' => UserRole::BEGINNER]);

        expect($user->can('update', $user))->toBeTrue();
    });

    test('admin can update other users', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $other = User::factory()->create(['role' => UserRole::CURATOR]);

        expect($admin->can('update', $other))->toBeTrue();
    });

    test('curator cannot update other users', function () {
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        $other = User::factory()->create(['role' => UserRole::BEGINNER]);

        expect($curator->can('update', $other))->toBeFalse();
    });
});

describe('changeRole', function () {
    test('admin can change role to any role', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $target = User::factory()->create(['role' => UserRole::BEGINNER]);

        foreach (UserRole::cases() as $role) {
            $response = app(\App\Policies\UserPolicy::class)->changeRole($admin, $target, $role);
            expect($response->allowed())->toBeTrue("Admin should be able to set role to {$role->value}");
        }
    });

    test('group leader can change role to curator or beginner only', function () {
        $leader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
        $target = User::factory()->create(['role' => UserRole::BEGINNER]);

        $curatorResponse = app(\App\Policies\UserPolicy::class)->changeRole($leader, $target, UserRole::CURATOR);
        expect($curatorResponse->allowed())->toBeTrue();

        $beginnerResponse = app(\App\Policies\UserPolicy::class)->changeRole($leader, $target, UserRole::BEGINNER);
        expect($beginnerResponse->allowed())->toBeTrue();

        $adminResponse = app(\App\Policies\UserPolicy::class)->changeRole($leader, $target, UserRole::ADMIN);
        expect($adminResponse->allowed())->toBeFalse();

        $glResponse = app(\App\Policies\UserPolicy::class)->changeRole($leader, $target, UserRole::GROUP_LEADER);
        expect($glResponse->allowed())->toBeFalse();
    });

    test('user cannot change own role', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $response = app(\App\Policies\UserPolicy::class)->changeRole($admin, $admin, UserRole::CURATOR);

        expect($response->allowed())->toBeFalse()
            ->and($response->message())->toContain('own role');
    });

    test('cannot change role of user id 1', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $systemAdmin = User::factory()->create(['id' => 1, 'role' => UserRole::ADMIN]);

        $response = app(\App\Policies\UserPolicy::class)->changeRole($admin, $systemAdmin, UserRole::CURATOR);

        expect($response->allowed())->toBeFalse()
            ->and($response->message())->toContain('system administrator');
    });

    test('curator cannot change any roles', function () {
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        $target = User::factory()->create(['role' => UserRole::BEGINNER]);

        $response = app(\App\Policies\UserPolicy::class)->changeRole($curator, $target, UserRole::CURATOR);

        expect($response->allowed())->toBeFalse();
    });
});

describe('deactivate', function () {
    test('admin can deactivate active user', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $target = User::factory()->create(['role' => UserRole::CURATOR, 'is_active' => true]);

        $response = app(\App\Policies\UserPolicy::class)->deactivate($admin, $target);

        expect($response->allowed())->toBeTrue();
    });

    test('cannot deactivate yourself', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'is_active' => true]);

        $response = app(\App\Policies\UserPolicy::class)->deactivate($admin, $admin);

        expect($response->allowed())->toBeFalse()
            ->and($response->message())->toContain('own account');
    });

    test('cannot deactivate user id 1', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $systemAdmin = User::factory()->create(['id' => 1, 'role' => UserRole::ADMIN, 'is_active' => true]);

        $response = app(\App\Policies\UserPolicy::class)->deactivate($admin, $systemAdmin);

        expect($response->allowed())->toBeFalse();
    });

    test('cannot deactivate already inactive user', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $target = User::factory()->create(['role' => UserRole::CURATOR, 'is_active' => false]);

        $response = app(\App\Policies\UserPolicy::class)->deactivate($admin, $target);

        expect($response->allowed())->toBeFalse()
            ->and($response->message())->toContain('already deactivated');
    });

    test('curator cannot deactivate users', function () {
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        $target = User::factory()->create(['role' => UserRole::BEGINNER, 'is_active' => true]);

        $response = app(\App\Policies\UserPolicy::class)->deactivate($curator, $target);

        expect($response->allowed())->toBeFalse();
    });
});

describe('reactivate', function () {
    test('admin can reactivate inactive user', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $target = User::factory()->create(['role' => UserRole::CURATOR, 'is_active' => false]);

        $response = app(\App\Policies\UserPolicy::class)->reactivate($admin, $target);

        expect($response->allowed())->toBeTrue();
    });

    test('cannot reactivate already active user', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $target = User::factory()->create(['role' => UserRole::CURATOR, 'is_active' => true]);

        $response = app(\App\Policies\UserPolicy::class)->reactivate($admin, $target);

        expect($response->allowed())->toBeFalse()
            ->and($response->message())->toContain('already active');
    });

    test('curator cannot reactivate users', function () {
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        $target = User::factory()->create(['role' => UserRole::BEGINNER, 'is_active' => false]);

        $response = app(\App\Policies\UserPolicy::class)->reactivate($curator, $target);

        expect($response->allowed())->toBeFalse();
    });
});

describe('resetPassword', function () {
    test('admin can reset password of other user', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $target = User::factory()->create(['role' => UserRole::CURATOR]);

        $response = app(\App\Policies\UserPolicy::class)->resetPassword($admin, $target);

        expect($response->allowed())->toBeTrue();
    });

    test('cannot reset password of user id 1 unless you are user id 1', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $systemAdmin = User::factory()->create(['id' => 1, 'role' => UserRole::ADMIN]);

        $response = app(\App\Policies\UserPolicy::class)->resetPassword($admin, $systemAdmin);

        expect($response->allowed())->toBeFalse();
    });

    test('user id 1 can reset own password', function () {
        $systemAdmin = User::factory()->create(['id' => 1, 'role' => UserRole::ADMIN]);

        $response = app(\App\Policies\UserPolicy::class)->resetPassword($systemAdmin, $systemAdmin);

        expect($response->allowed())->toBeTrue();
    });

    test('curator cannot reset passwords', function () {
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        $target = User::factory()->create(['role' => UserRole::BEGINNER]);

        $response = app(\App\Policies\UserPolicy::class)->resetPassword($curator, $target);

        expect($response->allowed())->toBeFalse();
    });
});

describe('delete and restore', function () {
    test('nobody can delete users', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $target = User::factory()->create();

        expect($admin->can('delete', $target))->toBeFalse();
    });

    test('nobody can restore users', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $target = User::factory()->create();

        expect($admin->can('restore', $target))->toBeFalse();
    });

    test('nobody can force delete users', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $target = User::factory()->create();

        expect($admin->can('forceDelete', $target))->toBeFalse();
    });
});
