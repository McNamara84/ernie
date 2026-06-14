<?php

use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;

it('registers via auto-discovery', function () {
    $registrar = app(AssistantRegistrar::class);

    expect($registrar->has('size-format-suggestion'))->toBeTrue();
});

it('returns suggestions for the assistance page', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get('/assistance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('assistance')
            ->has('manifests')
            ->has('sections')
        );
});