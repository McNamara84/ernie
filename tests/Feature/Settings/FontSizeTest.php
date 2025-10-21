<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can update font size preference to large', function () {
    $user = User::factory()->create(['font_size_preference' => 'regular']);

    $response = $this->actingAs($user)
        ->put(route('font-size.update'), [
            'font_size_preference' => 'large',
        ]);

    $response->assertRedirect();
    expect($user->fresh()->font_size_preference)->toBe('large');
});

test('user can update font size preference to regular', function () {
    $user = User::factory()->create(['font_size_preference' => 'large']);

    $response = $this->actingAs($user)
        ->put(route('font-size.update'), [
            'font_size_preference' => 'regular',
        ]);

    $response->assertRedirect();
    expect($user->fresh()->font_size_preference)->toBe('regular');
});

test('font size preference defaults to regular for new users', function () {
    $user = User::factory()->create();

    expect($user->font_size_preference)->toBe('regular');
});

test('invalid font size value is rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->put(route('font-size.update'), [
            'font_size_preference' => 'invalid',
        ]);

    $response->assertSessionHasErrors('font_size_preference');
});

test('font size preference is required', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->put(route('font-size.update'), []);

    $response->assertSessionHasErrors('font_size_preference');
});

test('unauthenticated users cannot update font size', function () {
    $response = $this->put(route('font-size.update'), [
        'font_size_preference' => 'large',
    ]);

    $response->assertRedirect(route('login'));
});

test('font size preference persists after user login', function () {
    $user = User::factory()->create(['font_size_preference' => 'large']);

    $this->actingAs($user)
        ->get(route('dashboard'));

    expect($user->fresh()->font_size_preference)->toBe('large');
});
