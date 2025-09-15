<?php

use App\Models\User;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\License;
use App\Models\Language;
use App\Models\Resource;
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

test('authenticated users can save curation data', function () {
    $this->actingAs($user = User::factory()->create());

    $resourceType = ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);
    $titleTypes = [
        'main-title' => TitleType::create(['name' => 'Main Title', 'slug' => 'main-title']),
        'alternative-title' => TitleType::create(['name' => 'Alternative Title', 'slug' => 'alternative-title']),
    ];
    $license = License::create(['identifier' => 'MIT', 'name' => 'MIT License']);
    $language = Language::create(['code' => 'en', 'name' => 'English']);

    $data = [
        'doi' => '10.1234/abc',
        'year' => 2024,
        'resourceType' => $resourceType->id,
        'version' => '1.0',
        'language' => $language->code,
        'titles' => [
            ['title' => 'My Title', 'titleType' => 'main-title'],
            ['title' => 'Another Title', 'titleType' => 'alternative-title'],
        ],
        'licenses' => [$license->identifier],
    ];

    $this->post(route('curation.store'), $data)->assertRedirect(route('curation'));

    $this->assertDatabaseHas('resources', [
        'doi' => '10.1234/abc',
        'year' => 2024,
        'resource_type_id' => $resourceType->id,
        'version' => '1.0',
        'language_id' => $language->id,
        'last_editor_id' => $user->id,
        'curation' => false,
    ]);

    $resource = Resource::first();
    $this->assertDatabaseHas('titles', [
        'resource_id' => $resource->id,
        'title' => 'My Title',
        'title_type_id' => $titleTypes['main-title']->id,
    ]);
    $this->assertDatabaseHas('titles', [
        'resource_id' => $resource->id,
        'title' => 'Another Title',
        'title_type_id' => $titleTypes['alternative-title']->id,
    ]);
    $this->assertDatabaseHas('license_resource', [
        'resource_id' => $resource->id,
        'license_id' => $license->id,
    ]);
});
