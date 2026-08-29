<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\EditorDraftSaveIntent;
use App\Enums\ResourceWorkflowStatus;
use App\Http\Controllers\ResourceController;
use App\Models\Datacenter;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Language;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Support\Facades\Http;

covers(ResourceController::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->datacenter = Datacenter::factory()->create();
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

    $this->validEditorPayload = fn (array $overrides = []): array => array_merge([
        'doi' => null,
        'year' => 2025,
        'resourceType' => $this->resourceType->id,
        'accessLevel' => AccessLevel::OPEN->value,
        'titles' => [
            ['title' => 'Validated Dataset', 'titleType' => 'main-title'],
        ],
        'licenses' => [$this->right->identifier],
        'authors' => [
            [
                'type' => 'person',
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'isContact' => false,
                'position' => 0,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            ['descriptionType' => 'abstract', 'description' => 'A complete abstract.'],
        ],
        'datacenter_id' => $this->datacenter->id,
    ], $overrides);
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
            ->and($resource->created_by_user_id)->toBe($this->user->id)
            ->and($resource->datacenter_id)->toBeNull()
            ->and($resource->workflow_status_override)->toBe(ResourceWorkflowStatus::DRAFT);
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

    it('distinguishes an omitted access level from an explicit null on draft updates', function () {
        $resource = Resource::factory()->create([
            'access_level' => AccessLevel::RESTRICTED,
            'created_by_user_id' => $this->user->id,
        ]);
        $payload = [
            'resourceId' => $resource->id,
            'titles' => [
                ['title' => 'Draft Access Update', 'titleType' => 'main-title'],
            ],
        ];

        $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload)
            ->assertOk();

        expect($resource->fresh()->access_level)->toBe(AccessLevel::RESTRICTED);

        $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', [
                ...$payload,
                'accessLevel' => null,
            ])
            ->assertOk();

        expect($resource->fresh()->access_level)->toBeNull();
    });

    it('saves a draft with one datacenter', function () {
        $payload = [
            'titles' => [
                ['title' => 'Draft with Datacenter', 'titleType' => 'main-title'],
            ],
            'datacenter_id' => $this->datacenter->id,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload);

        $response->assertCreated();

        $resource = Resource::latest('id')->first();
        expect($resource)->not->toBeNull()
            ->and($resource->datacenter_id)->toBe($this->datacenter->id);
    });

    it('accepts a canonical datacenter with an empty legacy array for a draft', function () {
        $payload = [
            'titles' => [
                ['title' => 'Draft with Empty Legacy Datacenters', 'titleType' => 'main-title'],
            ],
            'datacenter_id' => $this->datacenter->id,
            'datacenters' => [],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload);

        $response->assertCreated()
            ->assertJsonMissingValidationErrors(['datacenter_id', 'datacenters']);

        $resource = Resource::latest('id')->first();
        expect($resource)->not->toBeNull()
            ->and($resource->datacenter_id)->toBe($this->datacenter->id);
    });

    it('accepts one datacenter through the legacy array for a draft', function () {
        $payload = [
            'titles' => [
                ['title' => 'Legacy Draft Datacenter', 'titleType' => 'main-title'],
            ],
            'datacenters' => [$this->datacenter->id],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload);

        $response->assertCreated();

        $resource = Resource::latest('id')->first();
        expect($resource)->not->toBeNull()
            ->and($resource->datacenter_id)->toBe($this->datacenter->id);
    });

    it('rejects conflicting canonical and legacy datacenter values for a draft', function () {
        $secondDatacenter = Datacenter::factory()->create();
        $payload = [
            'titles' => [
                ['title' => 'Draft with Conflicting Datacenters', 'titleType' => 'main-title'],
            ],
            'datacenter_id' => $this->datacenter->id,
            'datacenters' => [$secondDatacenter->id],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['datacenter_id']);
    });

    it('rejects more than one datacenter through the legacy array for a draft', function () {
        $secondDatacenter = Datacenter::factory()->create();
        $payload = [
            'titles' => [
                ['title' => 'Draft with Multiple Datacenters', 'titleType' => 'main-title'],
            ],
            'datacenters' => [
                $this->datacenter->id,
                $secondDatacenter->id,
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['datacenters']);
    });

    it('prefixes duplicate legacy datacenter errors with the resource section for a draft', function () {
        $payload = [
            'titles' => [
                ['title' => 'Draft with Duplicate Datacenter', 'titleType' => 'main-title'],
            ],
            'datacenters' => [
                $this->datacenter->id,
                $this->datacenter->id,
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['datacenters.0']);

        $errors = $response->json('errors');
        expect($errors)->toBeArray()
            ->and($errors['datacenters.0'][0] ?? null)
            ->toBeString()
            ->toStartWith('[Resource Information]');
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

    it('preserves workflow state for background and landing-page saves', function (EditorDraftSaveIntent $intent) {
        $resource = Resource::factory()->create([
            'workflow_status_override' => ResourceWorkflowStatus::REVIEW,
            'force_review_status' => true,
            'created_by_user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', [
                'intent' => $intent->value,
                'resourceId' => $resource->id,
                'doi' => $resource->doi,
                'titles' => [
                    ['title' => 'Preserved workflow', 'titleType' => 'main-title'],
                ],
            ]);

        $response->assertOk();

        expect($resource->fresh()->workflow_status_override)->toBe(ResourceWorkflowStatus::REVIEW)
            ->and($resource->fresh()->force_review_status)->toBeTrue();
    })->with([
        EditorDraftSaveIntent::AUTOSAVE,
        EditorDraftSaveIntent::LANDING_PAGE_PREVIEW,
    ]);

    it('rejects an explicit draft transition for a published resource', function () {
        $resource = Resource::factory()->create(['created_by_user_id' => $this->user->id]);
        LandingPage::factory()->for($resource)->published()->withDoi((string) $resource->doi)->create();

        $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', [
                'intent' => EditorDraftSaveIntent::SAVE_DRAFT->value,
                'resourceId' => $resource->id,
                'doi' => $resource->doi,
                'titles' => [
                    ['title' => 'Published resource', 'titleType' => 'main-title'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['intent']);

        expect($resource->fresh()->workflow_status_override)->toBeNull();
    });

    it('validates locally, clears an explicit draft, and never writes to DataCite', function () {
        Http::fake();
        $payload = ($this->validEditorPayload)([
            'doi' => '10.5880/editor.validate-only',
        ]);

        $draftResponse = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', [
                ...$payload,
                'intent' => EditorDraftSaveIntent::SAVE_DRAFT->value,
            ])
            ->assertCreated();

        $resource = Resource::query()->findOrFail($draftResponse->json('resource.id'));
        expect($resource->workflow_status_override)->toBe(ResourceWorkflowStatus::DRAFT);

        LandingPage::factory()->for($resource)->published()->withDoi((string) $resource->doi)->create();

        $this->actingAs($this->user)
            ->postJson('/editor/resources', [
                ...$payload,
                'resourceId' => $resource->id,
            ])
            ->assertOk()
            ->assertJsonPath('resource.id', $resource->id)
            ->assertJsonPath('resource.publicStatus', 'published')
            ->assertJsonMissing(['dataCiteSync']);

        expect($resource->fresh()->workflow_status_override)->toBeNull();
        Http::assertNothingSent();
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
            'access_level' => AccessLevel::OPEN,
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

        $person = Person::create([
            'family_name' => 'Test',
            'given_name' => 'Author',
        ]);

        $resource->creators()->create([
            'creatorable_type' => Person::class,
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
            'access_level' => AccessLevel::OPEN,
            'created_by_user_id' => $this->user->id,
            'updated_by_user_id' => $this->user->id,
        ]);
        $complete->titles()->create([
            'value' => 'Complete Resource',
            'title_type_id' => $this->titleType->id,
            'position' => 0,
        ]);
        $complete->creators()->create([
            'creatorable_type' => Person::class,
            'creatorable_id' => Person::create(['family_name' => 'Tester'])->id,
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
