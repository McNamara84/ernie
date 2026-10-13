<?php

use App\Enums\ScienceTopic;
use App\Enums\UserRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

it('displays the public homepage with all locally available science topics', function (bool $authenticated) {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }
    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('home')->has('topics', 25)
        ->where('topics.0.label', 'Atmosphere')
        ->where('topics.0.href', '/doi-search?topic=atmosphere')
        ->where('topics.24.label', 'Volcanism'));

    foreach (ScienceTopic::cases() as $topic) {
        expect(file_exists(public_path($topic->image())))->toBeTrue();
        expect($topic->forHomepage()['href'])->toBe('/doi-search?topic='.$topic->value);
    }
})->with(['guest' => false, 'signed in' => true]);

it('displays the find overview publicly and for signed-in users', function (?UserRole $role) {
    withoutVite();
    if ($role !== null) {
        $this->actingAs(User::factory()->unverified()->create(['role' => $role]));
    }

    expect(route('find', absolute: false))->toBe('/find');
    $this->get('/find')->assertOk()->assertInertia(fn (Assert $page) => $page->component('find'));
})->with([null, ...UserRole::cases()]);

it('displays the about page', function () {
    withoutVite();
    $response = $this->get(route('about'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('about'));
});

it('displays the legal notice page', function () {
    withoutVite();
    $response = $this->get(route('legal-notice'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('legal-notice'));
});

it('redirects guests away from the changelog', function () {
    $this->get(route('changelog'))->assertRedirect(route('login'));
});

it('displays the changelog for every internal role regardless of email verification', function (UserRole $role, bool $verified) {
    withoutVite();
    $user = User::factory()->create([
        'role' => $role,
        'email_verified_at' => $verified ? now() : null,
    ]);

    $response = $this->actingAs($user)->get(route('changelog'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('changelog'));
})->with(UserRole::cases())->with(['verified' => true, 'unverified' => false]);
