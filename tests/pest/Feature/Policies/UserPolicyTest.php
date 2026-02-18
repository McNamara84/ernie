<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\UserPolicy;

covers(UserPolicy::class);

beforeEach(function () {
    $this->policy = new UserPolicy;
    $this->admin = User::factory()->admin()->create();
    $this->groupLeader = User::factory()->groupLeader()->create();
    $this->curator = User::factory()->curator()->create();
    $this->beginner = User::factory()->create(); // default is BEGINNER
});

/**
 * Call a policy method while acting as the given user.
 * This ensures Gate::allows('manage-users') checks the correct user.
 */
function asUser(User $user, Closure $fn): mixed
{
    return app()->make(\Illuminate\Contracts\Auth\Guard::class)->setUser($user)
        ? $fn()
        : null;
}

// =========================================================================
// viewAny
// =========================================================================

describe('viewAny', function () {
    it('allows admin to view user list', function () {
        $this->actingAs($this->admin);

        expect($this->policy->viewAny($this->admin))->toBeTrue();
    });

    it('allows group leader to view user list', function () {
        $this->actingAs($this->groupLeader);

        expect($this->policy->viewAny($this->groupLeader))->toBeTrue();
    });

    it('denies curator from viewing user list', function () {
        $this->actingAs($this->curator);

        expect($this->policy->viewAny($this->curator))->toBeFalse();
    });

    it('denies beginner from viewing user list', function () {
        $this->actingAs($this->beginner);

        expect($this->policy->viewAny($this->beginner))->toBeFalse();
    });
});

// =========================================================================
// view
// =========================================================================

describe('view', function () {
    it('allows users to view their own profile', function () {
        $this->actingAs($this->beginner);
        expect($this->policy->view($this->beginner, $this->beginner))->toBeTrue();

        $this->actingAs($this->curator);
        expect($this->policy->view($this->curator, $this->curator))->toBeTrue();
    });

    it('allows admin to view any user', function () {
        $this->actingAs($this->admin);

        expect($this->policy->view($this->admin, $this->beginner))->toBeTrue();
    });

    it('allows group leader to view any user', function () {
        $this->actingAs($this->groupLeader);

        expect($this->policy->view($this->groupLeader, $this->beginner))->toBeTrue();
    });

    it('denies curator from viewing other users', function () {
        $this->actingAs($this->curator);

        expect($this->policy->view($this->curator, $this->beginner))->toBeFalse();
    });
});

// =========================================================================
// create
// =========================================================================

describe('create', function () {
    it('allows admin to create users', function () {
        $this->actingAs($this->admin);

        expect($this->policy->create($this->admin))->toBeTrue();
    });

    it('allows group leader to create users', function () {
        $this->actingAs($this->groupLeader);

        expect($this->policy->create($this->groupLeader))->toBeTrue();
    });

    it('denies curator from creating users', function () {
        $this->actingAs($this->curator);

        expect($this->policy->create($this->curator))->toBeFalse();
    });

    it('denies beginner from creating users', function () {
        $this->actingAs($this->beginner);

        expect($this->policy->create($this->beginner))->toBeFalse();
    });
});

// =========================================================================
// update
// =========================================================================

describe('update', function () {
    it('allows users to update their own profile', function () {
        $this->actingAs($this->beginner);

        expect($this->policy->update($this->beginner, $this->beginner))->toBeTrue();
    });

    it('allows admin to update any user', function () {
        $this->actingAs($this->admin);

        expect($this->policy->update($this->admin, $this->beginner))->toBeTrue();
    });

    it('denies curator from updating other users', function () {
        $this->actingAs($this->curator);

        expect($this->policy->update($this->curator, $this->beginner))->toBeFalse();
    });
});

// =========================================================================
// changeRole
// =========================================================================

describe('changeRole', function () {
    it('denies changing own role', function () {
        $this->actingAs($this->admin);
        $response = $this->policy->changeRole($this->admin, $this->admin, UserRole::CURATOR);

        expect($response->denied())->toBeTrue()
            ->and($response->message())->toContain('your own role');
    });

    it('denies changing role of user ID 1 (system admin)', function () {
        $systemAdmin = User::factory()->admin()->create();
        $systemAdmin->id = 1;
        $systemAdmin->save();

        $this->actingAs($this->admin);
        $response = $this->policy->changeRole($this->admin, $systemAdmin, UserRole::BEGINNER);

        expect($response->denied())->toBeTrue()
            ->and($response->message())->toContain('system administrator');
    });

    it('denies non-managers from changing roles', function () {
        $this->actingAs($this->curator);
        $target = User::factory()->create();
        $response = $this->policy->changeRole($this->curator, $target, UserRole::BEGINNER);

        expect($response->denied())->toBeTrue();
    });

    it('allows admin to promote to any role', function () {
        $this->actingAs($this->admin);
        $target = User::factory()->create();

        foreach (UserRole::cases() as $role) {
            $response = $this->policy->changeRole($this->admin, $target, $role);
            expect($response->allowed())->toBeTrue();
        }
    });

    it('allows group leader to promote to curator', function () {
        $this->actingAs($this->groupLeader);
        $target = User::factory()->create();
        $response = $this->policy->changeRole($this->groupLeader, $target, UserRole::CURATOR);

        expect($response->allowed())->toBeTrue();
    });

    it('denies group leader from promoting to admin', function () {
        $this->actingAs($this->groupLeader);
        $target = User::factory()->create();
        $response = $this->policy->changeRole($this->groupLeader, $target, UserRole::ADMIN);

        expect($response->denied())->toBeTrue()
            ->and($response->message())->toContain('cannot promote');
    });

    it('denies group leader from promoting to group leader', function () {
        $this->actingAs($this->groupLeader);
        $target = User::factory()->create();
        $response = $this->policy->changeRole($this->groupLeader, $target, UserRole::GROUP_LEADER);

        expect($response->denied())->toBeTrue();
    });
});

// =========================================================================
// deactivate
// =========================================================================

describe('deactivate', function () {
    it('denies deactivating own account', function () {
        $this->actingAs($this->admin);
        $response = $this->policy->deactivate($this->admin, $this->admin);

        expect($response->denied())->toBeTrue()
            ->and($response->message())->toContain('your own account');
    });

    it('denies deactivating user ID 1', function () {
        $systemAdmin = User::factory()->admin()->create();
        $systemAdmin->id = 1;
        $systemAdmin->save();

        $this->actingAs($this->admin);
        $response = $this->policy->deactivate($this->admin, $systemAdmin);

        expect($response->denied())->toBeTrue()
            ->and($response->message())->toContain('system administrator');
    });

    it('denies non-managers deactivating users', function () {
        $this->actingAs($this->curator);
        $target = User::factory()->create();
        $response = $this->policy->deactivate($this->curator, $target);

        expect($response->denied())->toBeTrue();
    });

    it('allows admin to deactivate active user', function () {
        $this->actingAs($this->admin);
        $target = User::factory()->create(['is_active' => true]);
        $response = $this->policy->deactivate($this->admin, $target);

        expect($response->allowed())->toBeTrue();
    });

    it('denies deactivating already inactive user', function () {
        $this->actingAs($this->admin);
        $target = User::factory()->create(['is_active' => false]);
        $response = $this->policy->deactivate($this->admin, $target);

        expect($response->denied())->toBeTrue()
            ->and($response->message())->toContain('already deactivated');
    });
});

// =========================================================================
// reactivate
// =========================================================================

describe('reactivate', function () {
    it('denies non-managers from reactivating', function () {
        $this->actingAs($this->curator);
        $target = User::factory()->create(['is_active' => false]);
        $response = $this->policy->reactivate($this->curator, $target);

        expect($response->denied())->toBeTrue();
    });

    it('allows admin to reactivate inactive user', function () {
        $this->actingAs($this->admin);
        $target = User::factory()->create(['is_active' => false]);
        $response = $this->policy->reactivate($this->admin, $target);

        expect($response->allowed())->toBeTrue();
    });

    it('denies reactivating already active user', function () {
        $this->actingAs($this->admin);
        $target = User::factory()->create(['is_active' => true]);
        $response = $this->policy->reactivate($this->admin, $target);

        expect($response->denied())->toBeTrue()
            ->and($response->message())->toContain('already active');
    });
});

// =========================================================================
// resetPassword
// =========================================================================

describe('resetPassword', function () {
    it('denies non-managers from resetting passwords', function () {
        $this->actingAs($this->curator);
        $target = User::factory()->create();
        $response = $this->policy->resetPassword($this->curator, $target);

        expect($response->denied())->toBeTrue();
    });

    it('allows admin to reset any password', function () {
        $this->actingAs($this->admin);
        $target = User::factory()->create();
        $response = $this->policy->resetPassword($this->admin, $target);

        expect($response->allowed())->toBeTrue();
    });

    it('denies non-system-admin from resetting system admin password', function () {
        $systemAdmin = User::factory()->admin()->create();
        $systemAdmin->id = 1;
        $systemAdmin->save();

        $this->actingAs($this->groupLeader);
        $response = $this->policy->resetPassword($this->groupLeader, $systemAdmin);

        expect($response->denied())->toBeTrue()
            ->and($response->message())->toContain('system administrator');
    });
});

// =========================================================================
// delete / restore / forceDelete
// =========================================================================

describe('delete, restore, forceDelete', function () {
    it('always denies delete')
        ->expect(fn () => $this->policy->delete($this->admin, $this->beginner))
        ->toBeFalse();

    it('always denies restore')
        ->expect(fn () => $this->policy->restore($this->admin, $this->beginner))
        ->toBeFalse();

    it('always denies forceDelete')
        ->expect(fn () => $this->policy->forceDelete($this->admin, $this->beginner))
        ->toBeFalse();
});
