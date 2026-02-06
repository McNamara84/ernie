<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Test: Delete All Resources (Admin Bulk Cleanup)
 *
 * Tests the admin-only endpoint that deletes all resources (datasets + IGSNs)
 * while preserving settings, lookup tables, and user accounts.
 */
it('allows admin to delete all resources', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Resource::factory()->count(3)->create();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete'])
        ->assertRedirect(route('logs.index'))
        ->assertSessionHas('success');

    expect(Resource::count())->toBe(0);
});

it('forbids non-admin users from deleting all resources', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    Resource::factory()->count(2)->create();

    actingAs($user)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete'])
        ->assertForbidden();

    expect(Resource::count())->toBe(2);
})->with([
    'group_leader' => UserRole::GROUP_LEADER,
    'curator' => UserRole::CURATOR,
    'beginner' => UserRole::BEGINNER,
]);

it('requires correct confirmation text', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Resource::factory()->count(2)->create();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'wrong'])
        ->assertSessionHasErrors('confirmation');

    expect(Resource::count())->toBe(2);
});

it('fails without confirmation parameter', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Resource::factory()->create();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), [])
        ->assertSessionHasErrors('confirmation');

    expect(Resource::count())->toBe(1);
});

it('preserves users after deleting all resources', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $otherUser = User::factory()->create(['role' => UserRole::CURATOR]);
    Resource::factory()->count(2)->create();

    $userCountBefore = User::count();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete']);

    expect(Resource::count())->toBe(0);
    expect(User::count())->toBe($userCountBefore);
});

it('preserves resource types after deleting all resources', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Resource::factory()->count(2)->create();

    $typeCountBefore = ResourceType::count();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete']);

    expect(Resource::count())->toBe(0);
    expect(ResourceType::count())->toBe($typeCountBefore);
});

it('cleans up orphaned persons after deleting all resources', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create();

    // Create a person linked to the resource via resource_creators
    $person = Person::factory()->create();
    $resource->creators()->create([
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 0,
    ]);

    expect(Person::count())->toBeGreaterThan(0);

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete']);

    expect(Resource::count())->toBe(0);
    expect(Person::count())->toBe(0);
});

it('cleans up orphaned publishers after deleting all resources', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);

    // The factory creates a default publisher and links it to the resource
    Resource::factory()->count(2)->create();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete']);

    expect(Resource::count())->toBe(0);
    // Publishers that were only linked to resources should be cleaned up
    expect(Publisher::whereDoesntHave('resources')->count())->toBe(0);
});

it('passes can_delete_all_resources flag to logs page for admin', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);

    actingAs($admin)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Logs/Index')
            ->where('can_delete_all_resources', true)
        );
});

it('does not pass can_delete_all_resources flag for non-admin', function () {
    // Non-admins cannot access logs at all (access-logs gate), so we test
    // that the gate itself restricts access
    $curator = User::factory()->create(['role' => UserRole::CURATOR]);

    actingAs($curator)
        ->get(route('logs.index'))
        ->assertForbidden();
});

it('succeeds even when no resources exist', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);

    expect(Resource::count())->toBe(0);

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete'])
        ->assertRedirect(route('logs.index'))
        ->assertSessionHas('success');
});
