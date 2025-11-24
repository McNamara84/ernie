<?php

use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('dashboard'))->assertOk();
});

test('dashboard view receives the resource count', function () {
    $this->actingAs(User::factory()->create());

    $resourceType = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    Resource::create([
        'year' => 2024,
        'resource_type_id' => $resourceType->id,
    ]);

    Resource::create([
        'year' => 2025,
        'resource_type_id' => $resourceType->id,
    ]);

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->where('resourceCount', 2)
        );
});

test('dashboard provides PHP version from system', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->has('phpVersion')
            ->where('phpVersion', PHP_VERSION)
        );
});

test('dashboard provides Laravel version from application', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->has('laravelVersion')
            ->where('laravelVersion', app()->version())
        );
});

test('dashboard provides all version information together', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('dashboard')
            ->has('resourceCount')
            ->has('phpVersion')
            ->has('laravelVersion')
            ->where('phpVersion', PHP_VERSION)
            ->where('laravelVersion', app()->version())
        );
});
