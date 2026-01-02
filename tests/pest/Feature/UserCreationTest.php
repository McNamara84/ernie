<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    $this->curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $this->beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
});

describe('User Creation', function () {
    it('allows admin to create a new user', function () {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andReturn(Password::RESET_LINK_SENT);

        $response = $this->actingAs($this->admin)->post('/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'role' => UserRole::BEGINNER->value,
            'is_active' => true,
        ]);
    });

    it('allows group leader to create a new user', function () {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andReturn(Password::RESET_LINK_SENT);

        $response = $this->actingAs($this->groupLeader)->post('/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'role' => UserRole::BEGINNER->value,
        ]);
    });

    it('prevents curator from creating users', function () {
        $response = $this->actingAs($this->curator)->post('/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', [
            'email' => 'newuser@example.com',
        ]);
    });

    it('prevents beginner from creating users', function () {
        $response = $this->actingAs($this->beginner)->post('/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', [
            'email' => 'newuser@example.com',
        ]);
    });

    it('prevents unauthenticated users from creating users', function () {
        $response = $this->post('/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
        ]);

        $response->assertRedirect('/login');
    });

    it('validates required name field', function () {
        $response = $this->actingAs($this->admin)->post('/users', [
            'name' => '',
            'email' => 'newuser@example.com',
        ]);

        $response->assertSessionHasErrors(['name']);
    });

    it('validates required email field', function () {
        $response = $this->actingAs($this->admin)->post('/users', [
            'name' => 'New User',
            'email' => '',
        ]);

        $response->assertSessionHasErrors(['email']);
    });

    it('validates email format', function () {
        $response = $this->actingAs($this->admin)->post('/users', [
            'name' => 'New User',
            'email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors(['email']);
    });

    it('prevents duplicate emails', function () {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->actingAs($this->admin)->post('/users', [
            'name' => 'New User',
            'email' => 'existing@example.com',
        ]);

        $response->assertSessionHasErrors(['email']);
    });

    it('creates user with beginner role regardless of who creates it', function () {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andReturn(Password::RESET_LINK_SENT);

        $this->actingAs($this->admin)->post('/users', [
            'name' => 'New Beginner',
            'email' => 'beginner@example.com',
        ]);

        $user = User::where('email', 'beginner@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->role)->toBe(UserRole::BEGINNER);
        expect($user->is_active)->toBeTrue();
    });

    it('shows warning when password reset email fails', function () {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andReturn(Password::RESET_THROTTLED);

        $response = $this->actingAs($this->admin)->post('/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('warning');
        // User should still be created
        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
        ]);
    });

    it('validates name max length', function () {
        $response = $this->actingAs($this->admin)->post('/users', [
            'name' => str_repeat('a', 256),
            'email' => 'newuser@example.com',
        ]);

        $response->assertSessionHasErrors(['name']);
    });

    it('validates email max length', function () {
        $response = $this->actingAs($this->admin)->post('/users', [
            'name' => 'New User',
            'email' => str_repeat('a', 250).'@example.com',
        ]);

        $response->assertSessionHasErrors(['email']);
    });
});

describe('User Creation Policy', function () {
    it('policy allows admin to create users', function () {
        expect($this->admin->canManageUsers())->toBeTrue();
    });

    it('policy allows group leader to create users', function () {
        expect($this->groupLeader->canManageUsers())->toBeTrue();
    });

    it('policy denies curator from creating users', function () {
        expect($this->curator->canManageUsers())->toBeFalse();
    });

    it('policy denies beginner from creating users', function () {
        expect($this->beginner->canManageUsers())->toBeFalse();
    });
});
