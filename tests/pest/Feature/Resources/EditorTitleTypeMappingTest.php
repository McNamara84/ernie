<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

test('editor maps main titles to main-title and normalizes other title type slugs', function () {
    $user = User::factory()->create();

    $main = TitleType::create([
        'name' => 'Main Title',
        'slug' => 'MainTitle',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    $alternative = TitleType::create([
        'name' => 'Alternative Title',
        'slug' => 'AlternativeTitle',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    $resource = Resource::factory()->create();

    // Legacy-style: main title stored as a real type (MainTitle)
    $resource->titles()->create([
        'value' => 'Main title value',
        'title_type_id' => $main->id,
        'language' => 'en',
    ]);

    $resource->titles()->create([
        'value' => 'Alt title value',
        'title_type_id' => $alternative->id,
        'language' => 'en',
    ]);

    withoutVite();

    $this->actingAs($user)
        ->get(route('editor', ['resourceId' => $resource->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('editor')
            ->where('titles.0.title', 'Main title value')
            ->where('titles.0.titleType', 'main-title')
            ->where('titles.1.title', 'Alt title value')
            ->where('titles.1.titleType', 'alternative-title')
        );
});
