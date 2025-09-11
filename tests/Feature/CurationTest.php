<?php

use App\Models\User;
use App\Models\ResourceType;
use App\Models\TitleType;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TitleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

test('guests are redirected to login page', function () {
    $this->get(route('curation'))->assertRedirect(route('login'));
});

test('authenticated users can view curation page with resource and title types', function () {
    $this->seed([ResourceTypeSeeder::class, TitleTypeSeeder::class]);
    $this->actingAs(User::factory()->create());

    withoutVite();

    $response = $this->get(route('curation'))->assertOk();

    $response->assertInertia(fn (Assert $page) =>
        $page->component('curation')
            ->has('resourceTypes', ResourceType::count())
            ->has('titleTypes', TitleType::count())
            ->where('titles', [])
    );
});
