<?php

use App\Enums\UserRole;
use App\Mail\WelcomeNewUser;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

it('does not expose changelog JSON to guests', function () {
    $this->getJson('/api/changelog')
        ->assertUnauthorized()
        ->assertDontSee('Internal Changelog Navigation');
});

it('redirects browser requests for changelog JSON to login without a session', function () {
    $this->get('/api/changelog')->assertRedirect(route('login'));
});

it('returns private changelog JSON for every internal role regardless of email verification', function (UserRole $role, bool $verified) {
    $user = User::factory()->create([
        'role' => $role,
        'email_verified_at' => $verified ? now() : null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/changelog')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonFragment(['title' => 'Internal Changelog Navigation']);
})->with(UserRole::cases())->with(['verified' => true, 'unverified' => false]);

it('allows a command-created user to log in and read the changelog without email verification', function (bool $firstUser) {
    if (! $firstUser) {
        User::factory()->admin()->create();
    }

    $this->artisan('add-user', [
        'name' => 'Changelog Reader',
        'email' => 'changelog-reader@example.com',
        'password' => 'SecurePassword123!',
    ])->assertSuccessful();

    $user = User::where('email', 'changelog-reader@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull()
        ->and($user->role)->toBe($firstUser ? UserRole::ADMIN : UserRole::BEGINNER);

    // A direct link must resume at the changelog after authentication.
    $this->get('/changelog')->assertRedirect(route('login'));
    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'SecurePassword123!',
    ])->assertSessionHasNoErrors()->assertRedirect('/changelog');

    $this->assertAuthenticatedAs($user);
    $this->get('/changelog')->assertOk()->assertInertia(fn (Assert $page) => $page->component('changelog'));
    $this->getJson('/api/changelog')->assertOk()->assertJsonFragment(['title' => 'Internal Changelog Navigation']);
    expect($user->fresh()->email_verified_at)->toBeNull();

    $this->post(route('logout'))->assertRedirect('/');
    $this->assertGuest();
    $this->get('/changelog')->assertRedirect(route('login'));
    $this->getJson('/api/changelog')->assertUnauthorized()->assertDontSee('Internal Changelog Navigation');
})->with(['first user' => true, 'subsequent user' => false]);

it('allows an invited user to read the changelog after setting their password', function () {
    Mail::fake();

    $this->actingAs(User::factory()->admin()->create())->post('/users', [
        'name' => 'Invited Reader',
        'email' => 'invited-reader@example.com',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $user = User::where('email', 'invited-reader@example.com')->firstOrFail();
    Mail::assertSent(WelcomeNewUser::class, fn (WelcomeNewUser $mail) => $mail->hasTo($user->email));
    $this->post(route('logout'));

    $welcomeUrl = URL::temporarySignedRoute('welcome.store', now()->addHours(72), ['user' => $user->id]);
    $this->post($welcomeUrl, [
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect($user->refresh()->email_verified_at)->toBeNull()
        ->and($user->password_set_at)->not->toBeNull();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'SecurePassword123!',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    $this->get('/changelog')->assertOk()->assertInertia(fn (Assert $page) => $page->component('changelog'));
    $this->getJson('/api/changelog')->assertOk()->assertJsonFragment(['title' => 'Internal Changelog Navigation']);
    expect($user->fresh()->email_verified_at)->toBeNull();
});
