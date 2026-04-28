<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows admins to delete resources', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create();

    $this->actingAs($admin)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'))
        ->assertSessionHas('success', 'Resource deleted successfully.');

    expect(Resource::find($resource->id))->toBeNull();
});

it('allows group leaders to delete resources', function (): void {
    $leader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    $resource = Resource::factory()->create();

    $this->actingAs($leader)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'));

    expect(Resource::find($resource->id))->toBeNull();
});

it('forbids curators from deleting resources', function (): void {
    $curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $resource = Resource::factory()->create();

    $this->actingAs($curator)
        ->delete(route('resources.destroy', $resource))
        ->assertStatus(403);

    expect(Resource::find($resource->id))->not->toBeNull();
});

it('rejects guests from deleting resources', function (): void {
    $resource = Resource::factory()->create();

    $this->delete(route('resources.destroy', $resource))
        ->assertRedirect('/login');

    expect(Resource::find($resource->id))->not->toBeNull();
});
