<?php

use App\Models\Dataset;
use App\Models\Language;
use App\Models\License;
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

test('authenticated users can store datasets from the curation form', function () {
    actingAs(User::factory()->create());

    $dependencies = createCurationDependencies();

    $response = $this->postJson(route('curation.datasets.store'), [
        'doi' => '10.1234/example',
        'year' => 2024,
        'resourceType' => $dependencies['resourceType']->id,
        'version' => '1.0',
        'language' => $dependencies['language']->code,
        'titles' => [
            ['title' => 'Primary Dataset', 'titleType' => 'main-title'],
            ['title' => 'Secondary Title', 'titleType' => 'subtitle'],
        ],
        'licenses' => [$dependencies['license']->identifier],
    ]);

    $response->assertCreated()->assertJson([
        'message' => 'Successfully saved dataset.',
    ]);

    $dataset = Dataset::with(['titles', 'licenses'])->first();

    expect($dataset)->not->toBeNull();
    expect($dataset->year)->toBe(2024);
    expect($dataset->resource_type_id)->toBe($dependencies['resourceType']->id);
    expect($dataset->language_id)->toBe($dependencies['language']->id);
    expect($dataset->titles)->toHaveCount(2);
    expect($dataset->titles->firstWhere('title_type_id', $dependencies['mainTitleType']->id)?->title)
        ->toBe('Primary Dataset');
    expect($dataset->licenses)->toHaveCount(1);
    expect($dataset->licenses->first()->identifier)->toBe($dependencies['license']->identifier);
});

test('validation errors are returned when the dataset payload is invalid', function () {
    actingAs(User::factory()->create());

    $dependencies = createCurationDependencies();

    $response = $this->postJson(route('curation.datasets.store'), [
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

    expect(Dataset::count())->toBe(0);
});
