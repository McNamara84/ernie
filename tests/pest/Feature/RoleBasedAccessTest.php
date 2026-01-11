<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;

/**
 * Role-based Access Control Tests (Issue #379)
 *
 * Tests the granular permission system that controls access to different
 * application areas based on user roles.
 *
 * Permission Matrix:
 * | Area            | Admin | Group Leader | Curator | Beginner |
 * |-----------------|-------|--------------|---------|----------|
 * | Logs            | ✅    | ❌           | ❌      | ❌       |
 * | Old Datasets    | ✅    | ❌           | ❌      | ❌       |
 * | Statistics      | ✅    | ✅           | ❌      | ❌       |
 * | Users           | ✅    | ✅           | ❌      | ❌       |
 * | Editor Settings | ✅    | ✅           | ❌      | ❌       |
 */

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Admin Access', function () {
    beforeEach(function () {
        $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    });

    it('can access logs page', function () {
        $this->actingAs($this->admin)
            ->get('/logs')
            ->assertOk();
    });

    it('can access old datasets page', function () {
        $this->actingAs($this->admin)
            ->get('/old-datasets')
            ->assertOk();
    });

    it('can access statistics page', function () {
        // Note: The statistics page uses an external database connection (metaworks)
        // which may not be available in the test environment. We only test that
        // the user is NOT forbidden (403), which proves the gate allows access.
        $response = $this->actingAs($this->admin)->get('/old-statistics');
        expect($response->status())->not->toBe(403);
    });

    it('can access users page', function () {
        $this->actingAs($this->admin)
            ->get('/users')
            ->assertOk();
    });

    it('can access editor settings page', function () {
        $this->actingAs($this->admin)
            ->get('/settings')
            ->assertOk();
    });

    it('can access profile settings page', function () {
        $this->actingAs($this->admin)
            ->get('/settings/profile')
            ->assertOk();
    });

    it('can access dashboard', function () {
        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk();
    });

    it('can access editor', function () {
        $this->actingAs($this->admin)
            ->get('/editor')
            ->assertOk();
    });

    it('can access resources', function () {
        $this->actingAs($this->admin)
            ->get('/resources')
            ->assertOk();
    });
});

describe('Group Leader Access', function () {
    beforeEach(function () {
        $this->groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    });

    it('cannot access logs page', function () {
        $this->actingAs($this->groupLeader)
            ->get('/logs')
            ->assertForbidden();
    });

    it('cannot access old datasets page', function () {
        $this->actingAs($this->groupLeader)
            ->get('/old-datasets')
            ->assertForbidden();
    });

    it('can access statistics page', function () {
        // Note: The statistics page uses an external database connection (metaworks)
        // which may not be available in the test environment. We only test that
        // the user is NOT forbidden (403), which proves the gate allows access.
        $response = $this->actingAs($this->groupLeader)->get('/old-statistics');
        expect($response->status())->not->toBe(403);
    });

    it('can access users page', function () {
        $this->actingAs($this->groupLeader)
            ->get('/users')
            ->assertOk();
    });

    it('can access editor settings page', function () {
        $this->actingAs($this->groupLeader)
            ->get('/settings')
            ->assertOk();
    });

    it('can access profile settings page', function () {
        $this->actingAs($this->groupLeader)
            ->get('/settings/profile')
            ->assertOk();
    });

    it('can access dashboard', function () {
        $this->actingAs($this->groupLeader)
            ->get('/dashboard')
            ->assertOk();
    });

    it('can access editor', function () {
        $this->actingAs($this->groupLeader)
            ->get('/editor')
            ->assertOk();
    });

    it('can access resources', function () {
        $this->actingAs($this->groupLeader)
            ->get('/resources')
            ->assertOk();
    });
});

describe('Curator Access', function () {
    beforeEach(function () {
        $this->curator = User::factory()->create(['role' => UserRole::CURATOR]);
    });

    it('cannot access logs page', function () {
        $this->actingAs($this->curator)
            ->get('/logs')
            ->assertForbidden();
    });

    it('cannot access old datasets page', function () {
        $this->actingAs($this->curator)
            ->get('/old-datasets')
            ->assertForbidden();
    });

    it('cannot access statistics page', function () {
        $this->actingAs($this->curator)
            ->get('/old-statistics')
            ->assertForbidden();
    });

    it('cannot access users page', function () {
        $this->actingAs($this->curator)
            ->get('/users')
            ->assertForbidden();
    });

    it('cannot access editor settings page', function () {
        $this->actingAs($this->curator)
            ->get('/settings')
            ->assertForbidden();
    });

    it('can access profile settings page', function () {
        $this->actingAs($this->curator)
            ->get('/settings/profile')
            ->assertOk();
    });

    it('can access password settings page', function () {
        $this->actingAs($this->curator)
            ->get('/settings/password')
            ->assertOk();
    });

    it('can access appearance settings page', function () {
        $this->actingAs($this->curator)
            ->get('/settings/appearance')
            ->assertOk();
    });

    it('can access dashboard', function () {
        $this->actingAs($this->curator)
            ->get('/dashboard')
            ->assertOk();
    });

    it('can access editor', function () {
        $this->actingAs($this->curator)
            ->get('/editor')
            ->assertOk();
    });

    it('can access resources', function () {
        $this->actingAs($this->curator)
            ->get('/resources')
            ->assertOk();
    });
});

describe('Beginner Access', function () {
    beforeEach(function () {
        $this->beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
    });

    it('cannot access logs page', function () {
        $this->actingAs($this->beginner)
            ->get('/logs')
            ->assertForbidden();
    });

    it('cannot access old datasets page', function () {
        $this->actingAs($this->beginner)
            ->get('/old-datasets')
            ->assertForbidden();
    });

    it('cannot access statistics page', function () {
        $this->actingAs($this->beginner)
            ->get('/old-statistics')
            ->assertForbidden();
    });

    it('cannot access users page', function () {
        $this->actingAs($this->beginner)
            ->get('/users')
            ->assertForbidden();
    });

    it('cannot access editor settings page', function () {
        $this->actingAs($this->beginner)
            ->get('/settings')
            ->assertForbidden();
    });

    it('can access profile settings page', function () {
        $this->actingAs($this->beginner)
            ->get('/settings/profile')
            ->assertOk();
    });

    it('can access password settings page', function () {
        $this->actingAs($this->beginner)
            ->get('/settings/password')
            ->assertOk();
    });

    it('can access appearance settings page', function () {
        $this->actingAs($this->beginner)
            ->get('/settings/appearance')
            ->assertOk();
    });

    it('can access dashboard', function () {
        $this->actingAs($this->beginner)
            ->get('/dashboard')
            ->assertOk();
    });

    it('can access editor', function () {
        $this->actingAs($this->beginner)
            ->get('/editor')
            ->assertOk();
    });

    it('can access resources', function () {
        $this->actingAs($this->beginner)
            ->get('/resources')
            ->assertOk();
    });
});

describe('Gate Definitions', function () {
    it('access-logs gate allows only admin', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);

        expect($admin->can('access-logs'))->toBeTrue();
        expect($groupLeader->can('access-logs'))->toBeFalse();
        expect($curator->can('access-logs'))->toBeFalse();
        expect($beginner->can('access-logs'))->toBeFalse();
    });

    it('access-old-datasets gate allows only admin', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);

        expect($admin->can('access-old-datasets'))->toBeTrue();
        expect($groupLeader->can('access-old-datasets'))->toBeFalse();
        expect($curator->can('access-old-datasets'))->toBeFalse();
        expect($beginner->can('access-old-datasets'))->toBeFalse();
    });

    it('access-statistics gate allows admin and group leader', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);

        expect($admin->can('access-statistics'))->toBeTrue();
        expect($groupLeader->can('access-statistics'))->toBeTrue();
        expect($curator->can('access-statistics'))->toBeFalse();
        expect($beginner->can('access-statistics'))->toBeFalse();
    });

    it('access-users gate allows admin and group leader', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);

        expect($admin->can('access-users'))->toBeTrue();
        expect($groupLeader->can('access-users'))->toBeTrue();
        expect($curator->can('access-users'))->toBeFalse();
        expect($beginner->can('access-users'))->toBeFalse();
    });

    it('access-editor-settings gate allows admin and group leader', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);

        expect($admin->can('access-editor-settings'))->toBeTrue();
        expect($groupLeader->can('access-editor-settings'))->toBeTrue();
        expect($curator->can('access-editor-settings'))->toBeFalse();
        expect($beginner->can('access-editor-settings'))->toBeFalse();
    });
});

describe('Unauthenticated Access', function () {
    it('redirects to login for logs page', function () {
        $this->get('/logs')
            ->assertRedirect('/login');
    });

    it('redirects to login for old datasets page', function () {
        $this->get('/old-datasets')
            ->assertRedirect('/login');
    });

    it('redirects to login for statistics page', function () {
        $this->get('/old-statistics')
            ->assertRedirect('/login');
    });

    it('redirects to login for users page', function () {
        $this->get('/users')
            ->assertRedirect('/login');
    });

    it('redirects to login for editor settings page', function () {
        $this->get('/settings')
            ->assertRedirect('/login');
    });

    it('redirects to login for dashboard', function () {
        $this->get('/dashboard')
            ->assertRedirect('/login');
    });
});
