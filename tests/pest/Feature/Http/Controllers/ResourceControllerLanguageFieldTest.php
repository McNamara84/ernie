<?php

declare(strict_types=1);

use App\Http\Controllers\ResourceController;
use App\Models\Datacenter;
use App\Models\DescriptionType;
use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;

covers(ResourceController::class);

/**
 * Helper to build a valid resource payload with language fields.
 */
function validPayloadWithLanguage(array $overrides = []): array
{
    return array_merge([
        'year' => 2024,
        'resourceType' => (string) test()->resourceType->id,
        'language' => 'en',
        'titles' => [
            ['title' => 'Test Resource', 'titleType' => 'main-title', 'language' => 'en'],
        ],
        'licenses' => ['cc-by-4'],
        'datacenters' => [test()->datacenter->id],
        'authors' => [
            [
                'type' => 'person',
                'firstName' => 'John',
                'lastName' => 'Doe',
                'position' => 0,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'This is a test abstract.',
                'language' => 'de',
            ],
        ],
    ], $overrides);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->resourceType = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);
    $this->language = Language::create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);
    $this->right = Right::create([
        'identifier' => 'cc-by-4',
        'name' => 'Creative Commons Attribution 4.0',
    ]);
    $this->titleType = TitleType::create([
        'name' => 'Main Title',
        'slug' => 'MainTitle',
    ]);
    $this->descriptionType = DescriptionType::create([
        'name' => 'Abstract',
        'slug' => 'Abstract',
    ]);
    $this->datacenter = Datacenter::create(['name' => 'Test Datacenter']);
});

describe('Title and description language preservation', function () {
    it('stores title language through prepareForValidation', function () {
        $payload = validPayloadWithLanguage([
            'titles' => [
                ['title' => 'English Title', 'titleType' => 'main-title', 'language' => 'en'],
                ['title' => 'Deutscher Titel', 'titleType' => 'main-title', 'language' => 'de'],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::latest()->first();
        $titles = $resource->titles()->orderBy('id')->get();

        expect($titles)->toHaveCount(2)
            ->and($titles[0]->language)->toBe('en')
            ->and($titles[1]->language)->toBe('de');
    });

    it('stores description language through prepareForValidation', function () {
        $payload = validPayloadWithLanguage([
            'descriptions' => [
                ['descriptionType' => 'abstract', 'description' => 'English abstract.', 'language' => 'en'],
                ['descriptionType' => 'abstract', 'description' => 'Deutsche Zusammenfassung.', 'language' => 'de'],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::latest()->first();
        $descriptions = $resource->descriptions()->orderBy('id')->get();

        expect($descriptions)->toHaveCount(2)
            ->and($descriptions[0]->language)->toBe('en')
            ->and($descriptions[1]->language)->toBe('de');
    });

    it('stores null language when not provided', function () {
        $payload = validPayloadWithLanguage([
            'titles' => [
                ['title' => 'No Language Title', 'titleType' => 'main-title'],
            ],
            'descriptions' => [
                ['descriptionType' => 'abstract', 'description' => 'No language abstract.'],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::latest()->first();
        expect($resource->titles->first()->language)->toBeNull()
            ->and($resource->descriptions->first()->language)->toBeNull();
    });

    it('normalizes empty language string to null', function () {
        $payload = validPayloadWithLanguage([
            'titles' => [
                ['title' => 'Empty Language Title', 'titleType' => 'main-title', 'language' => ''],
            ],
            'descriptions' => [
                ['descriptionType' => 'abstract', 'description' => 'Empty language.', 'language' => ''],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::latest()->first();
        expect($resource->titles->first()->language)->toBeNull()
            ->and($resource->descriptions->first()->language)->toBeNull();
    });
});
