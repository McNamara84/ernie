<?php

use App\Models\User;

it('does not expose changelog JSON to guests', function () {
    $this->getJson('/api/changelog')
        ->assertUnauthorized()
        ->assertDontSee('Internal Changelog Navigation');
});

it('does not expose changelog JSON to unverified users', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->getJson('/api/changelog')
        ->assertForbidden()
        ->assertDontSee('Internal Changelog Navigation');
});

it('keeps the existing URL and requires the web session', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/changelog')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonFragment(['title' => 'Internal Changelog Navigation']);
});
