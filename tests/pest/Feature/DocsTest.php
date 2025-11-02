<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

test('guests are redirected to login when visiting docs', function () {
    $this->get(route('docs'))->assertRedirect(route('login'));
});

test('authenticated users can view the docs page', function () {
    $this->actingAs(User::factory()->create());
    withoutVite();
    $response = $this->get(route('docs'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('docs'));
});

test('guests are redirected to login when visiting users docs', function () {
    $this->get(route('docs.users'))->assertRedirect(route('login'));
});

test('authenticated users can view the users docs page', function () {
    $this->actingAs(User::factory()->create());
    withoutVite();
    $response = $this->get(route('docs.users'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('docs-users'));
});
