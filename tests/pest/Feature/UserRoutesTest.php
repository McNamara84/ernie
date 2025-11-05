<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

describe('User Management Routes', function () {
    it('requires authentication to access user management pages', function () {
        $response = $this->get(route('users.index'));

        $response->assertRedirect(route('login'));
    });

    it('requires user management permission to access index page', function () {
        $beginner = User::factory()->beginner()->create();

        $response = $this->actingAs($beginner)->get(route('users.index'));

        $response->assertForbidden();
    });

    it('allows admin to access user management pages', function () {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('users.index'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Users/Index')
                ->has('users')
                ->has('available_roles')
                ->has('can_promote_to_group_leader')
            );
    });

    it('allows group leader to access user management pages', function () {
        $groupLeader = User::factory()->groupLeader()->create();

        $response = $this->actingAs($groupLeader)->get(route('users.index'));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Users/Index')
                ->has('users')
                ->has('available_roles')
                ->has('can_promote_to_group_leader')
            );
    });

    it('prevents curator from accessing user management', function () {
        $curator = User::factory()->curator()->create();

        $response = $this->actingAs($curator)->get(route('users.index'));

        $response->assertForbidden();
    });

    it('registers all user management routes with correct names', function () {
        $routes = collect(Route::getRoutes())->filter(function ($route) {
            return str_starts_with($route->getName() ?? '', 'users.');
        })->map(fn ($route) => $route->getName());

        expect($routes->toArray())->toContain(
            'users.index',
            'users.update-role',
            'users.deactivate',
            'users.reactivate',
            'users.reset-password'
        );
    });

    it('applies can.manage.users middleware to all user routes', function () {
        $routes = collect(Route::getRoutes())->filter(function ($route) {
            $name = $route->getName() ?? '';

            return str_starts_with($name, 'users.');
        });

        foreach ($routes as $route) {
            $middleware = $route->middleware();
            expect($middleware)
                ->toContain('can.manage.users')
                ->and($middleware)
                ->toContain('web')
                ->and($middleware)
                ->toContain('auth');
        }
    });
});
