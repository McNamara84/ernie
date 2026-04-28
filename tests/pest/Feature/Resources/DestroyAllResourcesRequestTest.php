<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows admins to delete all resources with the correct confirmation', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Resource::factory()->count(2)->create();

    $this->actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete'])
        ->assertRedirect(route('logs.index'))
        ->assertSessionHas('success');

    expect(Resource::count())->toBe(0);
});

it('rejects deletion without the confirmation token', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Resource::factory()->create();

    $this->actingAs($admin)
        ->from(route('resources'))
        ->delete(route('resources.destroy-all'))
        ->assertSessionHasErrors('confirmation');

    expect(Resource::count())->toBe(1);
});

it('rejects deletion with the wrong confirmation token', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Resource::factory()->create();

    $this->actingAs($admin)
        ->from(route('resources'))
        ->delete(route('resources.destroy-all'), ['confirmation' => 'yes'])
        ->assertSessionHasErrors('confirmation');

    expect(Resource::count())->toBe(1);
});

it('forbids non-admins from deleting all resources', function (): void {
    $leader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    Resource::factory()->create();

    $this->actingAs($leader)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete'])
        ->assertStatus(403);

    expect(Resource::count())->toBe(1);
});
