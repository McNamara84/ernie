<?php

declare(strict_types=1);

use App\Http\Controllers\ResourceController;
use App\Models\DescriptionType;
use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;

covers(ResourceController::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->resourceType = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);
    $this->language = Language::create([
        'code' => 'en',
        'name' => 'English',
        'is_active' => true,
        'is_elmo_active' => true,
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
});

describe('Draft save (Issue #548)', function () {
    it('saves a draft with only a Main Title', function () {
        $payload = [
            'titles' => [
                ['title' => 'My Draft Dataset', 'titleType' => 'main-title'],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Draft saved successfully.');

        $resource = Resource::latest('id')->first();
        expect($resource)->not->toBeNull()
            ->and($resource->publication_year)->toBeNull()
            ->and($resource->resource_type_id)->toBeNull()
            ->and($resource->created_by_user_id)->toBe($this->user->id);
    });

    it('saves a draft with partial data', function () {
        $payload = [
            'year' => 2025,
            'titles' => [
                ['title' => 'Partial Draft', 'titleType' => 'main-title'],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'position' => 0,
                    'affiliations' => [],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload);

        $response->assertStatus(201);

        $resource = Resource::latest()->first();
        expect($resource->publication_year)->toBe(2025)
            ->and($resource->creators)->toHaveCount(1);
    });

    it('rejects draft without a Main Title', function () {
        $payload = [
            'titles' => [
                ['title' => '', 'titleType' => 'main-title'],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload);

        $response->assertStatus(422);
    });

    it('rejects draft without titles', function () {
        $payload = [];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload);

        $response->assertStatus(422);
    });

    it('does not trigger DataCite sync for drafts', function () {
        $payload = [
            'doi' => '10.5880/test.draft.001',
            'titles' => [
                ['title' => 'Draft with DOI', 'titleType' => 'main-title'],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload);

        $response->assertStatus(201);

        // Draft response should not include dataCiteSync key
        $response->assertJsonMissing(['dataCiteSync']);
    });

    it('requires authentication for draft save', function () {
        $payload = [
            'titles' => [
                ['title' => 'Unauthenticated Draft', 'titleType' => 'main-title'],
            ],
        ];

        $response = $this->postJson('/editor/resources/draft', $payload);

        $response->assertStatus(401);
    });
});

describe('Draft status in resource list (Issue #548)', function () {
    it('marks incomplete resources as draft status', function () {
        // Create a resource with only a title (no year, no type, no creators, no rights, no abstract)
        $resource = Resource::create([
            'doi' => null,
            'publication_year' => null,
            'resource_type_id' => null,
            'version' => null,
            'language_id' => null,
            'created_by_user_id' => $this->user->id,
            'updated_by_user_id' => $this->user->id,
            'publisher_id' => null,
        ]);

        $resource->titles()->create([
            'value' => 'Draft Resource',
            'title_type_id' => $this->titleType->id,
            'position' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/resources');

        $response->assertStatus(200);

        // Find our resource in the response data
        $resources = $response->original->getData()['page']['props']['resources'];
        $draftResource = collect($resources)->firstWhere('id', $resource->id);

        expect($draftResource)->not->toBeNull()
            ->and($draftResource['publicstatus'])->toBe('draft');
    });

    it('marks complete resources as curation status', function () {
        // Create a complete resource
        $resource = Resource::create([
            'doi' => null,
            'publication_year' => 2025,
            'resource_type_id' => $this->resourceType->id,
            'version' => null,
            'language_id' => null,
            'created_by_user_id' => $this->user->id,
            'updated_by_user_id' => $this->user->id,
            'publisher_id' => null,
        ]);

        $resource->titles()->create([
            'value' => 'Complete Resource',
            'title_type_id' => $this->titleType->id,
            'position' => 0,
        ]);

        $person = \App\Models\Person::create([
            'family_name' => 'Test',
            'given_name' => 'Author',
        ]);

        $resource->creators()->create([
            'creatorable_type' => \App\Models\Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
        ]);

        $resource->rights()->attach($this->right->id);

        $resource->descriptions()->create([
            'value' => 'This is a valid abstract for the resource.',
            'description_type_id' => $this->descriptionType->id,
            'position' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/resources');

        $response->assertStatus(200);

        $resources = $response->original->getData()['page']['props']['resources'];
        $completeResource = collect($resources)->firstWhere('id', $resource->id);

        expect($completeResource)->not->toBeNull()
            ->and($completeResource['publicstatus'])->toBe('curation');
    });
});

describe('Draft filter in resource list (Issue #548)', function () {
    it('filters resources by draft status', function () {
        // Create a draft resource (incomplete)
        $draft = Resource::create([
            'doi' => null,
            'publication_year' => null,
            'resource_type_id' => null,
            'created_by_user_id' => $this->user->id,
            'updated_by_user_id' => $this->user->id,
        ]);
        $draft->titles()->create([
            'value' => 'Draft Only',
            'title_type_id' => $this->titleType->id,
            'position' => 0,
        ]);

        // Create a complete resource
        $complete = Resource::create([
            'doi' => null,
            'publication_year' => 2025,
            'resource_type_id' => $this->resourceType->id,
            'created_by_user_id' => $this->user->id,
            'updated_by_user_id' => $this->user->id,
        ]);
        $complete->titles()->create([
            'value' => 'Complete Resource',
            'title_type_id' => $this->titleType->id,
            'position' => 0,
        ]);
        $complete->creators()->create([
            'creatorable_type' => \App\Models\Person::class,
            'creatorable_id' => \App\Models\Person::create(['family_name' => 'Tester'])->id,
            'position' => 0,
        ]);
        $complete->rights()->attach($this->right->id);
        $complete->descriptions()->create([
            'value' => 'A valid abstract.',
            'description_type_id' => $this->descriptionType->id,
            'position' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->get('/resources?status=draft');

        $response->assertStatus(200);
        $resources = $response->original->getData()['page']['props']['resources'];

        // Only the draft should appear
        expect(collect($resources)->pluck('id')->toArray())->toContain($draft->id)
            ->and(collect($resources)->pluck('id')->toArray())->not->toContain($complete->id);
    });
});
