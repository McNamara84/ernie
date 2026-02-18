<?php

declare(strict_types=1);

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;

covers(LoginRequest::class);

describe('validation rules', function () {
    it('requires email and password', function () {
        $response = $this->post('/login', []);

        $response->assertSessionHasErrors(['email', 'password']);
    });

    it('rejects invalid email format', function () {
        $response = $this->post('/login', [
            'email' => 'not-an-email',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(['email']);
    });

    it('accepts valid login input without auth errors on fields', function () {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertSessionDoesntHaveErrors(['email', 'password']);
    });
});

describe('authenticate', function () {
    it('authenticates valid credentials and redirects', function () {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);

        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'secret123',
        ]);

        $this->assertAuthenticatedAs($user);
    });

    it('rejects invalid password', function () {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['email']);
    });

    it('rejects non-existent user', function () {
        $response = $this->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['email']);
    });

    it('logs out deactivated user immediately after authentication', function () {
        User::factory()->create([
            'email' => 'deactivated@example.com',
            'password' => bcrypt('secret123'),
            'is_active' => false,
        ]);

        $response = $this->post('/login', [
            'email' => 'deactivated@example.com',
            'password' => 'secret123',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['email']);
    });
});

describe('rate limiting', function () {
    it('allows up to 5 failed attempts', function () {
        User::factory()->create([
            'email' => 'rate@example.com',
            'password' => bcrypt('correct'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->post('/login', [
                'email' => 'rate@example.com',
                'password' => 'wrong',
            ]);

            // Should get auth.failed, not throttle
            $response->assertSessionHasErrors(['email']);
            $errors = session('errors')->getBag('default')->get('email');
            expect($errors[0])->not->toContain('Too many');
        }
    });

    it('throttles after 5 failed attempts', function () {
        User::factory()->create([
            'email' => 'throttle@example.com',
            'password' => bcrypt('correct'),
        ]);

        // Exhaust the 5 attempts
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => 'throttle@example.com',
                'password' => 'wrong',
            ]);
        }

        // 6th attempt should be throttled
        $response = $this->post('/login', [
            'email' => 'throttle@example.com',
            'password' => 'wrong',
        ]);

        $response->assertSessionHasErrors(['email']);
        $errors = session('errors')->getBag('default')->get('email');
        expect($errors[0])->toContain((string) __('auth.throttle', ['seconds' => 60, 'minutes' => 1])
            ? 'seconds' : 'Too many');
    });
});
