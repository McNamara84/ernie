<?php

use App\Models\User;
use App\Models\TitleType;
use App\Models\License;
use Database\Seeders\TitleTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

test('guests are redirected to login page', function () {
    $this->get(route('curation'))->assertRedirect(route('login'));
});

test('authenticated users can view curation page with title types and licenses', function () {
    $this->seed([TitleTypeSeeder::class]);
    License::create(['identifier' => 'MIT', 'name' => 'MIT License']);
    $this->actingAs(User::factory()->create());

    withoutVite();

    $response = $this->get(route('curation'))->assertOk();

    $response->assertInertia(fn (Assert $page) =>
        $page->component('curation')
            ->has('titleTypes', TitleType::count())
            ->has('licenses', License::count())
            ->where('titles', [])
    );
});
