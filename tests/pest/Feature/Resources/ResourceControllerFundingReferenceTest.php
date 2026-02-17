<?php

declare(strict_types=1);

use App\Models\FundingReference;
use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;

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
        'slug' => 'main-title',
    ]);
});

function getValidPayload(array $overrides = []): array
{
    return array_merge([
        'publicationYear' => 2024,
        'resourceType' => (string) test()->resourceType->id,
        'language' => 'en',
        'titles' => [
            ['value' => 'Test Resource', 'titleType' => 'main-title'],
        ],
        'rights' => ['cc-by-4'],
        'creators' => [
            [
                'type' => 'person',
                'givenName' => 'John',
                'familyName' => 'Doe',
                'position' => 0,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'Abstract',
                'description' => 'This is a test abstract.',
            ],
        ],
    ], $overrides);
}

describe('creating funding references', function () {
    test('can save funding references when creating resource', function () {
        $payload = getValidPayload([
            'fundingReferences' => [
                [
                    'funderName' => 'European Research Council',
                    'funderIdentifier' => 'https://doi.org/10.13039/501100000780',
                    'funderIdentifierType' => 'Crossref Funder ID',
                    'awardNumber' => 'ERC-2021-STG-123456',
                    'awardUri' => 'https://cordis.europa.eu/project/id/123456',
                    'awardTitle' => 'Innovative AI Research',
                ],
                [
                    'funderName' => 'Deutsche Forschungsgemeinschaft',
                    'funderIdentifier' => 'https://ror.org/018mejw64',
                    'funderIdentifierType' => 'ROR',
                    'awardNumber' => 'DFG-2024-789',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('funding_references', [
            'funder_name' => 'European Research Council',
            'funder_identifier' => 'https://doi.org/10.13039/501100000780',
            'award_number' => 'ERC-2021-STG-123456',
            'award_uri' => 'https://cordis.europa.eu/project/id/123456',
            'award_title' => 'Innovative AI Research',
        ]);

        $this->assertDatabaseHas('funding_references', [
            'funder_name' => 'Deutsche Forschungsgemeinschaft',
            'funder_identifier' => 'https://ror.org/018mejw64',
            'award_number' => 'DFG-2024-789',
        ]);

        $resource = Resource::latest()->first();
        expect($resource->fundingReferences)->toHaveCount(2);
    });

    test('can save funding reference with only funder name', function () {
        $payload = getValidPayload([
            'fundingReferences' => [
                [
                    'funderName' => 'Minimal Funder',
                    'funderIdentifier' => '',
                    'funderIdentifierType' => null,
                    'awardNumber' => '',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('funding_references', [
            'funder_name' => 'Minimal Funder',
            'funder_identifier' => null,
            'award_number' => null,
            'award_uri' => null,
            'award_title' => null,
        ]);
    });
});

describe('updating funding references', function () {
    test('can update funding references on existing resource', function () {
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->resourceType->id,
        ]);

        FundingReference::create([
            'resource_id' => $resource->id,
            'funder_name' => 'Old Funder',
            'funder_identifier' => 'https://ror.org/old',
        ]);

        $payload = getValidPayload([
            'resourceId' => $resource->id,
            'fundingReferences' => [
                [
                    'funderName' => 'New Funder 1',
                    'funderIdentifier' => 'https://doi.org/10.13039/new1',
                    'funderIdentifierType' => 'Crossref Funder ID',
                    'awardNumber' => 'NEW-001',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
                [
                    'funderName' => 'New Funder 2',
                    'funderIdentifier' => '',
                    'funderIdentifierType' => null,
                    'awardNumber' => '',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('funding_references', [
            'resource_id' => $resource->id,
            'funder_name' => 'Old Funder',
        ]);

        $this->assertDatabaseHas('funding_references', [
            'resource_id' => $resource->id,
            'funder_name' => 'New Funder 1',
            'funder_identifier' => 'https://doi.org/10.13039/new1',
        ]);

        $this->assertDatabaseHas('funding_references', [
            'resource_id' => $resource->id,
            'funder_name' => 'New Funder 2',
        ]);

        expect($resource->fresh()->fundingReferences)->toHaveCount(2);
    });

    test('preserves funding reference positions', function () {
        $payload = getValidPayload([
            'fundingReferences' => [
                ['funderName' => 'First', 'funderIdentifier' => '', 'funderIdentifierType' => null, 'awardNumber' => '', 'awardUri' => '', 'awardTitle' => ''],
                ['funderName' => 'Second', 'funderIdentifier' => '', 'funderIdentifierType' => null, 'awardNumber' => '', 'awardUri' => '', 'awardTitle' => ''],
                ['funderName' => 'Third', 'funderIdentifier' => '', 'funderIdentifierType' => null, 'awardNumber' => '', 'awardUri' => '', 'awardTitle' => ''],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(201);

        $resource = Resource::latest()->first();
        $fundingRefs = $resource->fundingReferences()->orderBy('position')->get();

        expect($fundingRefs[0]->funder_name)->toBe('First')
            ->and($fundingRefs[0]->position)->toBe(0)
            ->and($fundingRefs[1]->funder_name)->toBe('Second')
            ->and($fundingRefs[1]->position)->toBe(1)
            ->and($fundingRefs[2]->funder_name)->toBe('Third')
            ->and($fundingRefs[2]->position)->toBe(2);
    });
});

describe('validation', function () {
    test('does not save funding reference with empty funder name', function () {
        $payload = getValidPayload([
            'fundingReferences' => [
                [
                    'funderName' => '',
                    'funderIdentifier' => 'https://ror.org/example',
                    'funderIdentifierType' => 'ROR',
                    'awardNumber' => 'TEST-123',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
                [
                    'funderName' => '   ',
                    'funderIdentifier' => '',
                    'funderIdentifierType' => null,
                    'awardNumber' => '',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
                [
                    'funderName' => 'Valid Funder',
                    'funderIdentifier' => '',
                    'funderIdentifierType' => null,
                    'awardNumber' => '',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
            ],
        ]);

        $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload)
            ->assertStatus(422);
    });

    test('validates funder identifier type', function () {
        $payload = [
            'publicationYear' => 2024,
            'resourceType' => (string) $this->resourceType->id,
            'language' => 'en',
            'titles' => [
                ['value' => 'Test Resource', 'titleType' => 'main-title'],
            ],
            'creators' => [
                [
                    'type' => 'person',
                    'givenName' => 'John',
                    'familyName' => 'Doe',
                    'position' => 0,
                    'affiliations' => [],
                ],
            ],
            'fundingReferences' => [
                [
                    'funderName' => 'Test Funder',
                    'funderIdentifier' => 'https://example.org/invalid',
                    'funderIdentifierType' => 'InvalidType',
                    'awardNumber' => '',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
            ],
        ];

        $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fundingReferences.0.funderIdentifierType']);
    });

    test('validates award uri format', function () {
        $payload = [
            'publicationYear' => 2024,
            'resourceType' => (string) $this->resourceType->id,
            'language' => 'en',
            'titles' => [
                ['value' => 'Test Resource', 'titleType' => 'main-title'],
            ],
            'creators' => [
                [
                    'type' => 'person',
                    'givenName' => 'John',
                    'familyName' => 'Doe',
                    'position' => 0,
                    'affiliations' => [],
                ],
            ],
            'fundingReferences' => [
                [
                    'funderName' => 'Test Funder',
                    'funderIdentifier' => '',
                    'funderIdentifierType' => null,
                    'awardNumber' => 'TEST-123',
                    'awardUri' => 'not-a-valid-url',
                    'awardTitle' => '',
                ],
            ],
        ];

        $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fundingReferences.0.awardUri']);
    });
});
