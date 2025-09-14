<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

test('guests are redirected to login page', function () {
    $this->get(route('curation'))->assertRedirect(route('login'));
});

test('authenticated users can view curation page', function () {
    $this->actingAs(User::factory()->create());

    withoutVite();

    $response = $this->get(route('curation'))->assertOk();

    $response->assertInertia(fn (Assert $page) =>
        $page->component('curation')
            ->where('titles', [])
            ->where('initialLicenses', [])
    );
});
