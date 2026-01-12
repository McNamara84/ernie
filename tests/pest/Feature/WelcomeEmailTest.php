<?php

use App\Enums\UserRole;
use App\Mail\WelcomeNewUser;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
});

describe('Welcome Email', function () {
    it('sends welcome email when user is created', function () {
        Mail::fake();

        $this->actingAs($this->admin)->post('/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
        ]);

        Mail::assertQueued(WelcomeNewUser::class, function ($mail) {
            return $mail->hasTo('newuser@example.com');
        });
    });

    it('welcome email contains signed URL', function () {
        $user = User::factory()->create();

        $mail = new WelcomeNewUser($user);

        expect($mail->welcomeUrl)->toContain('/welcome/'.$user->id);
        expect($mail->welcomeUrl)->toContain('signature=');
    });

    it('welcome email has correct subject', function () {
        $user = User::factory()->create();

        $mail = new WelcomeNewUser($user);
        $envelope = $mail->envelope();

        expect($envelope->subject)->toBe('Welcome to ERNIE - Set Your Password');
    });

    it('welcome email contains user name in content', function () {
        $user = User::factory()->create(['name' => 'John Doe']);

        $mail = new WelcomeNewUser($user);
        $content = $mail->content();

        expect($content->with['userName'])->toBe('John Doe');
        expect($content->with['expiresIn'])->toBe('72 hours');
    });
});

describe('Welcome Page - Valid Signature', function () {
    it('shows password setup form with valid signed URL', function () {
        $user = User::factory()->create(['password_set_at' => null]);

        $url = URL::temporarySignedRoute(
            'welcome.show',
            now()->addHours(72),
            ['user' => $user->id]
        );

        $response = $this->get($url);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('auth/welcome')
            ->has('email')
            ->has('userId')
        );
    });

    it('allows user to set password with valid signed URL', function () {
        $user = User::factory()->create(['password_set_at' => null]);

        $url = URL::temporarySignedRoute(
            'welcome.store',
            now()->addHours(72),
            ['user' => $user->id]
        );

        $response = $this->post($url, [
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $user->refresh();
        expect($user->password_set_at)->not->toBeNull();
    });

    it('redirects to login if password already set', function () {
        $user = User::factory()->create(['password_set_at' => now()]);

        $url = URL::temporarySignedRoute(
            'welcome.show',
            now()->addHours(72),
            ['user' => $user->id]
        );

        $response = $this->get($url);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status', 'Your password has already been set. Please log in.');
    });

    it('prevents setting password twice', function () {
        $user = User::factory()->create(['password_set_at' => now()]);

        $url = URL::temporarySignedRoute(
            'welcome.store',
            now()->addHours(72),
            ['user' => $user->id]
        );

        $response = $this->post($url, [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status', 'Your password has already been set. Please log in.');
    });
});

describe('Welcome Page - Expired Signature', function () {
    it('shows expired page when signature is invalid without exposing email', function () {
        $user = User::factory()->create(['password_set_at' => null]);

        // Create URL with expired signature
        $url = URL::temporarySignedRoute(
            'welcome.show',
            now()->subHour(),
            ['user' => $user->id]
        );

        $response = $this->get($url);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('auth/welcome-expired')
            ->where('email', '') // Email should be empty to prevent enumeration
        );
    });

    it('rejects password submission with expired signature', function () {
        $user = User::factory()->create(['password_set_at' => null]);

        $url = URL::temporarySignedRoute(
            'welcome.store',
            now()->subHour(),
            ['user' => $user->id]
        );

        $response = $this->post($url, [
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');

        $user->refresh();
        expect($user->password_set_at)->toBeNull();
    });
});

describe('Welcome Resend', function () {
    it('resends welcome email for user without password', function () {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'pending@example.com',
            'password_set_at' => null,
        ]);

        $response = $this->post(route('welcome.resend'), [
            'email' => 'pending@example.com',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        Mail::assertQueued(WelcomeNewUser::class, function ($mail) {
            return $mail->hasTo('pending@example.com');
        });
    });

    it('does not resend email for user with password already set', function () {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'activated@example.com',
            'password_set_at' => now(),
        ]);

        $response = $this->post(route('welcome.resend'), [
            'email' => 'activated@example.com',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        Mail::assertNotQueued(WelcomeNewUser::class);
    });

    it('does not reveal if email exists (security)', function () {
        Mail::fake();

        $response = $this->post(route('welcome.resend'), [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        Mail::assertNotQueued(WelcomeNewUser::class);
    });
});

describe('Password Validation', function () {
    it('requires password confirmation', function () {
        $user = User::factory()->create(['password_set_at' => null]);

        $url = URL::temporarySignedRoute(
            'welcome.store',
            now()->addHours(72),
            ['user' => $user->id]
        );

        $response = $this->post($url, [
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'DifferentPassword!',
        ]);

        $response->assertSessionHasErrors('password');
    });

    it('requires password to meet minimum requirements', function () {
        $user = User::factory()->create(['password_set_at' => null]);

        $url = URL::temporarySignedRoute(
            'welcome.store',
            now()->addHours(72),
            ['user' => $user->id]
        );

        $response = $this->post($url, [
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
    });
});
