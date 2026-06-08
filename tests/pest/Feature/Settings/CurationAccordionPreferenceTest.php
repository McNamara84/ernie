<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected when updating curation accordion preference', function () {
    $this->put(route('curation-accordion.update'), [
        'open_items' => ['resource-info'],
    ])->assertRedirect(route('login'));
});

test('authenticated users can persist curation accordion open items', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('curation-accordion.update'), [
            'open_items' => ['resource-info', 'authors', 'funding-references'],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->refresh()->curation_accordion_open_items)->toBe([
        'resource-info',
        'authors',
        'funding-references',
    ]);
});

test('authenticated users can persist all curation accordions as collapsed', function () {
    $user = User::factory()->create([
        'curation_accordion_open_items' => ['resource-info'],
    ]);

    $this->actingAs($user)
        ->put(route('curation-accordion.update'), [
            'open_items' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($user->refresh()->curation_accordion_open_items)->toBe([]);
});

test('unknown curation accordion item values are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/editor')
        ->put(route('curation-accordion.update'), [
            'open_items' => ['resource-info', 'unknown-section'],
        ])
        ->assertRedirect('/editor')
        ->assertSessionHasErrors('open_items.1');

    expect($user->refresh()->curation_accordion_open_items)->toBeNull();
});

test('duplicate curation accordion item values are rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/editor')
        ->put(route('curation-accordion.update'), [
            'open_items' => ['authors', 'authors'],
        ])
        ->assertRedirect('/editor')
        ->assertSessionHasErrors('open_items.1');
});
