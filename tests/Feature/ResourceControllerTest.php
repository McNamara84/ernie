<?php

use App\Models\Language;
use App\Models\License;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function createCurationDependencies(): array
{
    $resourceType = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'dataset',
        'active' => true,
        'elmo_active' => true,
    ]);

    $mainTitleType = TitleType::create([
        'name' => 'Main Title',
        'slug' => 'main-title',
        'active' => true,
        'elmo_active' => true,
    ]);

    $subtitleType = TitleType::create([
        'name' => 'Subtitle',
        'slug' => 'subtitle',
        'active' => true,
        'elmo_active' => true,
    ]);

    $license = License::create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'active' => true,
        'elmo_active' => true,
    ]);

    $language = Language::create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);

    return compact('resourceType', 'mainTitleType', 'subtitleType', 'license', 'language');
}

test('authenticated users can store resources from the curation form', function () {
    actingAs(User::factory()->create());

    $dependencies = createCurationDependencies();

    $response = $this->postJson(route('curation.resources.store'), [
        'doi' => '10.1234/example',
        'year' => 2024,
        'resourceType' => $dependencies['resourceType']->id,
        'version' => '1.0',
        'language' => $dependencies['language']->code,
        'titles' => [
            ['title' => 'Primary Resource', 'titleType' => 'main-title'],
            ['title' => 'Secondary Title', 'titleType' => 'subtitle'],
        ],
        'licenses' => [$dependencies['license']->identifier],
    ]);

    $response->assertCreated()->assertJson([
        'message' => 'Successfully saved resource.',
    ]);

    $resource = Resource::with(['titles', 'licenses'])->first();

    expect($resource)->not->toBeNull();
    expect($resource->year)->toBe(2024);
    expect($resource->resource_type_id)->toBe($dependencies['resourceType']->id);
    expect($resource->language_id)->toBe($dependencies['language']->id);
    expect($resource->titles)->toHaveCount(2);
    expect($resource->titles->firstWhere('title_type_id', $dependencies['mainTitleType']->id)?->title)
        ->toBe('Primary Resource');
    expect($resource->licenses)->toHaveCount(1);
    expect($resource->licenses->first()->identifier)->toBe($dependencies['license']->identifier);
});

test('validation errors are returned when the resource payload is invalid', function () {
    actingAs(User::factory()->create());

    $dependencies = createCurationDependencies();

    $response = $this->postJson(route('curation.resources.store'), [
        'year' => 2024,
        'resourceType' => $dependencies['resourceType']->id,
        'language' => $dependencies['language']->code,
        'titles' => [
            ['title' => 'Only Subtitle', 'titleType' => 'subtitle'],
        ],
        'licenses' => [$dependencies['license']->identifier],
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['titles']);

    expect(Resource::count())->toBe(0);
});
