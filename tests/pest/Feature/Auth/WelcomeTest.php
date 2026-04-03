<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Welcome Page (GET)
|--------------------------------------------------------------------------
*/

test('welcome page can be rendered with valid signed URL', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
        'password_set_at' => null,
    ]);

    $signedUrl = URL::temporarySignedRoute('welcome.show', now()->addHours(72), ['user' => $user->id]);

    $this->get($signedUrl)->assertStatus(200);
});

test('welcome page shows expired view for invalid signature', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
        'password_set_at' => null,
    ]);

    $this->get("/welcome/{$user->id}")
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->component('auth/welcome-expired'));
});

test('welcome page redirects to login if password already set', function () {
    $user = User::factory()->create([
        'password_set_at' => now(),
    ]);

    $signedUrl = URL::temporarySignedRoute('welcome.show', now()->addHours(72), ['user' => $user->id]);

    $this->get($signedUrl)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your password has already been set. Please log in.');
});

test('welcome page passes signature params as Inertia props', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
        'password_set_at' => null,
    ]);

    $signedUrl = URL::temporarySignedRoute('welcome.show', now()->addHours(72), ['user' => $user->id]);

    $this->get($signedUrl)
        ->assertInertia(fn ($page) => $page
            ->component('auth/welcome')
            ->has('signatureParams')
            ->where('signatureParams.expires', fn ($value) => ! empty($value))
            ->where('signatureParams.signature', fn ($value) => ! empty($value))
        );
});

/*
|--------------------------------------------------------------------------
| Password Setup (POST)
|--------------------------------------------------------------------------
*/

test('new user can set password with valid signed URL', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
        'password_set_at' => null,
    ]);

    $signedUrl = URL::temporarySignedRoute('welcome.store', now()->addHours(72), ['user' => $user->id]);
    $parsed = parse_url($signedUrl);
    parse_str($parsed['query'], $queryParams);

    $this->post("/welcome/{$user->id}?expires={$queryParams['expires']}&signature={$queryParams['signature']}", [
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your password has been set successfully. Please log in.');

    $user->refresh();
    expect($user->password_set_at)->not()->toBeNull();
    expect(Hash::check('SecurePassword123!', $user->password))->toBeTrue();
});

test('new user can log in after setting password via welcome flow', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
        'password_set_at' => null,
    ]);

    $signedUrl = URL::temporarySignedRoute('welcome.store', now()->addHours(72), ['user' => $user->id]);
    $parsed = parse_url($signedUrl);
    parse_str($parsed['query'], $queryParams);

    $this->post("/welcome/{$user->id}?expires={$queryParams['expires']}&signature={$queryParams['signature']}", [
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
    ])->assertRedirect(route('login'));

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'SecurePassword123!',
    ]);

    $this->assertAuthenticated();
});

test('password submission fails without signature parameters', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
        'password_set_at' => null,
    ]);

    $this->post("/welcome/{$user->id}", [
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $user->refresh();
    expect($user->password_set_at)->toBeNull();
});

test('password submission fails with expired signature', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
        'password_set_at' => null,
    ]);

    $signedUrl = URL::temporarySignedRoute('welcome.store', now()->subHour(), ['user' => $user->id]);
    $parsed = parse_url($signedUrl);
    parse_str($parsed['query'], $queryParams);

    $this->post("/welcome/{$user->id}?expires={$queryParams['expires']}&signature={$queryParams['signature']}", [
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $user->refresh();
    expect($user->password_set_at)->toBeNull();
});

test('password cannot be set twice via welcome flow', function () {
    $user = User::factory()->create([
        'password_set_at' => now(),
    ]);

    $signedUrl = URL::temporarySignedRoute('welcome.store', now()->addHours(72), ['user' => $user->id]);
    $parsed = parse_url($signedUrl);
    parse_str($parsed['query'], $queryParams);

    $this->post("/welcome/{$user->id}?expires={$queryParams['expires']}&signature={$queryParams['signature']}", [
        'password' => 'AnotherPassword123!',
        'password_confirmation' => 'AnotherPassword123!',
    ])->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your password has already been set. Please log in.');
});

test('password validation requires confirmation', function () {
    $user = User::factory()->create([
        'password' => Hash::make(Str::random(32)),
        'password_set_at' => null,
    ]);

    $signedUrl = URL::temporarySignedRoute('welcome.store', now()->addHours(72), ['user' => $user->id]);
    $parsed = parse_url($signedUrl);
    parse_str($parsed['query'], $queryParams);

    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class)
        ->from($signedUrl)
        ->post("/welcome/{$user->id}?expires={$queryParams['expires']}&signature={$queryParams['signature']}", [
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'DifferentPassword456!',
        ])->assertRedirect($signedUrl)
        ->assertSessionHasErrors('password');

    $user->refresh();
    expect($user->password_set_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Welcome Email Resend
|--------------------------------------------------------------------------
*/

test('welcome email resend is rate limited', function () {
    $user = User::factory()->create([
        'password_set_at' => null,
    ]);

    // Rate limit is throttle:3,1 (3 requests per minute)
    for ($i = 0; $i < 3; $i++) {
        $this->post(route('welcome.resend'), [
            'email' => $user->email,
        ]);
    }

    $this->post(route('welcome.resend'), [
        'email' => $user->email,
    ])->assertStatus(429);
});

/*
|--------------------------------------------------------------------------
| Login Page Error Flash
|--------------------------------------------------------------------------
*/

test('login page displays error flash message', function () {
    $this->withSession(['error' => 'This link is invalid or has expired.'])
        ->get(route('login'))
        ->assertInertia(fn ($page) => $page
            ->component('auth/login')
            ->where('error', 'This link is invalid or has expired.')
        );
});

test('login page displays status flash message', function () {
    $this->withSession(['status' => 'Your password has been set successfully. Please log in.'])
        ->get(route('login'))
        ->assertInertia(fn ($page) => $page
            ->component('auth/login')
            ->where('status', 'Your password has been set successfully. Please log in.')
        );
});
