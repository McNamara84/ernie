<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\DescriptionType;
use App\Models\Language;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Seed minimal lookup tables required by StoreResourceRequest validation.
 */
function seedValidationLookupTables(): int
{
    $resourceType = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'Dataset',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    Language::create([
        'code' => 'en',
        'name' => 'English',
        'active' => true,
        'elmo_active' => true,
    ]);

    Right::create([
        'identifier' => 'cc-by-4',
        'name' => 'Creative Commons Attribution 4.0',
        'uri' => null,
        'scheme_uri' => null,
        'is_active' => true,
        'is_elmo_active' => true,
        'usage_count' => 0,
    ]);

    TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        [
            'name' => 'Main Title',
            'is_active' => true,
            'is_elmo_active' => true,
        ]
    );

    DescriptionType::create([
        'name' => 'Abstract',
        'slug' => 'Abstract',
        'is_active' => true,
        'is_elmo_active' => true,
    ]);

    Datacenter::create(['name' => 'Test Datacenter']);

    return $resourceType->id;
}

/**
 * Build a minimal valid payload for resource creation.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validationMsgPayload(int $resourceTypeId, array $overrides = []): array
{
    return array_merge([
        'resourceId' => null,
        'doi' => null,
        'year' => 2024,
        'resourceType' => $resourceTypeId,
        'version' => null,
        'language' => 'en',
        'titles' => [
            ['title' => 'Test Resource', 'titleType' => 'main-title'],
        ],
        'licenses' => ['cc-by-4'],
        'authors' => [
            [
                'type' => 'person',
                'position' => 0,
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Test abstract',
            ],
        ],
        'datacenters' => [Datacenter::first()->id],
    ], $overrides);
}

describe('Store Resource – Section-Prefixed Validation Messages (Issue #605)', function () {

    test('missing required year returns [Resource Information] prefix', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, ['year' => null]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['year']);

        $yearErrors = $response->json('errors.year');
        expect($yearErrors)->toBeArray();
        expect($yearErrors[0])->toStartWith('[Resource Information]');
        expect($yearErrors[0])->toContain('Publication Year is required');
    });

    test('invalid year format returns [Resource Information] prefix', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, ['year' => 'not-a-number']);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $yearErrors = $response->json('errors.year');
        expect($yearErrors)->toBeArray();
        expect($yearErrors[0])->toStartWith('[Resource Information]');
    });

    test('missing resource type returns [Resource Information] prefix', function () {
        $user = User::factory()->create();
        seedValidationLookupTables();

        $payload = validationMsgPayload(0, ['resourceType' => null]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['resourceType']);

        $errors = $response->json('errors.resourceType');
        expect($errors[0])->toStartWith('[Resource Information]');
    });

    test('missing titles returns [Resource Information] prefix', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, ['titles' => []]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $titleErrors = $response->json('errors.titles');
        expect($titleErrors)->toBeArray();
        expect($titleErrors[0])->toStartWith('[Resource Information]');
    });

    test('missing datacenters returns [Resource Information] prefix', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, ['datacenters' => []]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['datacenters']);

        $errors = $response->json('errors.datacenters');
        expect($errors[0])->toStartWith('[Resource Information]');
    });

    test('missing licenses returns [Licenses & Rights] prefix', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, ['licenses' => []]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['licenses']);

        $errors = $response->json('errors.licenses');
        expect($errors[0])->toStartWith('[Licenses & Rights]');
    });

    test('missing authors returns [Authors] prefix', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, ['authors' => []]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $authorErrors = $response->json('errors.authors');
        expect($authorErrors)->toBeArray();
        expect($authorErrors[0])->toStartWith('[Authors]');
    });

    test('author without last name returns [Authors] prefix with position', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, [
            'authors' => [
                [
                    'type' => 'person',
                    'position' => 0,
                    'firstName' => 'Jane',
                    'lastName' => '',
                    'affiliations' => [],
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors.authors.0.lastName');
        expect($errors)->toBeArray();
        expect($errors[0])->toStartWith('[Authors]');
        expect($errors[0])->toContain('Author #1');
    });

    test('contact author without email returns [Authors] prefix with position', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, [
            'authors' => [
                [
                    'type' => 'person',
                    'position' => 0,
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'isContact' => true,
                    'email' => '',
                    'affiliations' => [],
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors.authors.0.email');
        expect($errors)->toBeArray();
        expect($errors[0])->toStartWith('[Authors]');
        expect($errors[0])->toContain('Author #1');
        expect($errors[0])->toContain('contact email');
    });

    test('institution author without name returns [Authors] prefix', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, [
            'authors' => [
                [
                    'type' => 'institution',
                    'position' => 0,
                    'institutionName' => '',
                    'affiliations' => [],
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors.authors.0.institutionName');
        expect($errors)->toBeArray();
        expect($errors[0])->toStartWith('[Authors]');
        expect($errors[0])->toContain('Author #1');
    });

    test('contributor without roles returns [Contributors] prefix', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, [
            'contributors' => [
                [
                    'type' => 'person',
                    'position' => 0,
                    'firstName' => 'John',
                    'lastName' => 'Smith',
                    'roles' => [],
                    'affiliations' => [],
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors.contributors.0.roles');
        expect($errors)->toBeArray();
        expect($errors[0])->toStartWith('[Contributors]');
        expect($errors[0])->toContain('Contributor #1');
    });

    test('missing abstract description returns [Descriptions] prefix', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, [
            'descriptions' => [],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors.descriptions');
        expect($errors)->toBeArray();
        expect($errors[0])->toStartWith('[Descriptions]');
        expect($errors[0])->toContain('Abstract');
    });

    test('polygon with fewer than 3 points returns [Spatial & Temporal Coverage] prefix', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, [
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 10.0, 'lat' => 50.0],
                        ['lon' => 11.0, 'lat' => 51.0],
                    ],
                    'description' => 'Invalid polygon',
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors.spatialTemporalCoverages.0.polygonPoints');
        expect($errors)->toBeArray();
        expect($errors[0])->toStartWith('[Spatial & Temporal Coverage]');
        expect($errors[0])->toContain('Coverage #1');
    });

    test('missing main title returns [Resource Information] prefix from after() hook', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, [
            'titles' => [
                ['title' => 'Alternate Title', 'titleType' => 'AlternativeTitle'],
            ],
        ]);

        // Seed AlternativeTitle type so the title type validation passes
        TitleType::firstOrCreate(
            ['slug' => 'AlternativeTitle'],
            [
                'name' => 'Alternative Title',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors.titles');
        expect($errors)->toBeArray();
        expect($errors[0])->toStartWith('[Resource Information]');
        expect($errors[0])->toContain('Main Title');
    });

    test('multiple errors have correct section prefixes per field', function () {
        $user = User::factory()->create();
        seedValidationLookupTables();

        // Submit with many missing fields to verify all prefixes
        $payload = [
            'resourceId' => null,
            'doi' => null,
            'year' => null,
            'resourceType' => null,
            'titles' => [],
            'licenses' => [],
            'authors' => [],
            'descriptions' => [],
            'datacenters' => [],
        ];

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors');

        // Verify section prefixes for all required fields
        expect($errors['year'][0])->toStartWith('[Resource Information]');
        expect($errors['resourceType'][0])->toStartWith('[Resource Information]');
        expect($errors['titles'][0])->toStartWith('[Resource Information]');
        expect($errors['licenses'][0])->toStartWith('[Licenses & Rights]');
        expect($errors['datacenters'][0])->toStartWith('[Resource Information]');
    });

    test('second author position is numbered correctly as Author #2', function () {
        $user = User::factory()->create();
        $resourceTypeId = seedValidationLookupTables();

        $payload = validationMsgPayload($resourceTypeId, [
            'authors' => [
                [
                    'type' => 'person',
                    'position' => 0,
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'affiliations' => [],
                ],
                [
                    'type' => 'person',
                    'position' => 1,
                    'firstName' => 'John',
                    'lastName' => '',
                    'affiliations' => [],
                ],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors.authors.1.lastName');
        expect($errors)->toBeArray();
        expect($errors[0])->toContain('Author #2');
    });
});

describe('Store Draft Resource – Section-Prefixed Validation Messages (Issue #605)', function () {

    test('draft with missing main title returns [Resource Information] prefix', function () {
        $user = User::factory()->create();
        seedValidationLookupTables();

        $payload = [
            'resourceId' => null,
            'doi' => null,
            'year' => null,
            'resourceType' => null,
            'titles' => [],
            'authors' => [],
            'descriptions' => [],
            'datacenters' => [],
        ];

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store-draft'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors.titles');
        expect($errors)->toBeArray();
        expect($errors[0])->toStartWith('[Resource Information]');
    });

    test('draft with invalid author email returns [Authors] prefix', function () {
        $user = User::factory()->create();
        seedValidationLookupTables();

        $payload = [
            'resourceId' => null,
            'doi' => null,
            'titles' => [
                ['title' => 'Draft Title', 'titleType' => 'main-title'],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'position' => 0,
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'email' => 'not-an-email',
                    'affiliations' => [],
                ],
            ],
        ];

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store-draft'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors.authors.0.email');
        expect($errors)->toBeArray();
        expect($errors[0])->toStartWith('[Authors]');
    });

    test('draft with polygon with fewer than 3 points returns [Spatial & Temporal Coverage] prefix', function () {
        $user = User::factory()->create();
        seedValidationLookupTables();

        $payload = [
            'resourceId' => null,
            'doi' => null,
            'titles' => [
                ['title' => 'Draft Title', 'titleType' => 'main-title'],
            ],
            'spatialTemporalCoverages' => [
                [
                    'type' => 'polygon',
                    'polygonPoints' => [
                        ['lon' => 10.0, 'lat' => 50.0],
                    ],
                    'description' => 'Bad polygon',
                ],
            ],
        ];

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store-draft'), $payload);

        $response->assertStatus(422);

        $errors = $response->json('errors.spatialTemporalCoverages.0.polygonPoints');
        expect($errors)->toBeArray();
        expect($errors[0])->toStartWith('[Spatial & Temporal Coverage]');
    });

    test('valid draft with only main title succeeds', function () {
        $user = User::factory()->create();
        seedValidationLookupTables();

        $payload = [
            'resourceId' => null,
            'doi' => null,
            'titles' => [
                ['title' => 'Minimal Draft Title', 'titleType' => 'main-title'],
            ],
        ];

        $response = $this->actingAs($user)
            ->postJson(route('editor.resources.store-draft'), $payload);

        $response->assertStatus(201);
    });
});
