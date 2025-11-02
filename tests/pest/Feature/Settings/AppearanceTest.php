<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

test('guests are redirected to login when accessing settings root', function () {
    $this->get(route('settings'))->assertRedirect(route('login'));
});

test('guests are redirected to login when accessing appearance settings', function () {
    $this->get(route('appearance'))->assertRedirect(route('login'));
});

test('authenticated users can view the appearance settings page', function () {
    $this->actingAs(User::factory()->create());
    withoutVite();
    $response = $this->get(route('appearance'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('settings/appearance'));
});
