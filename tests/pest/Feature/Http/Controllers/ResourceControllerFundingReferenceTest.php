<?php

declare(strict_types=1);

use App\Http\Controllers\ResourceController;
use App\Models\FundingReference;
use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Datacenter;
use App\Models\DescriptionType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;

covers(ResourceController::class);

/**
 * Helper to build a valid resource payload.
 */
function getValidPayload(array $overrides = []): array
{
    return array_merge([
        'year' => 2024,
        'resourceType' => (string) test()->resourceType->id,
        'language' => 'en',
        'titles' => [
            ['title' => 'Test Resource', 'titleType' => 'main-title'],
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

describe('Creating funding references', function () {
    it('saves funding references when creating a resource', function () {
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

    it('saves a funding reference with only the funder name', function () {
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

    it('preserves funding reference positions', function () {
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

        expect($fundingRefs)->toHaveCount(3)
            ->and($fundingRefs[0]->funder_name)->toBe('First')
            ->and($fundingRefs[1]->funder_name)->toBe('Second')
            ->and($fundingRefs[2]->funder_name)->toBe('Third');
    });
});

describe('Updating funding references', function () {
    it('replaces funding references on an existing resource', function () {
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
});

describe('Validation', function () {
    it('silently skips funding references with empty funder names', function () {
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
                    'funderName' => '   ',  // Whitespace only
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

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        // Empty funder names are silently stripped in prepareForValidation
        $response->assertStatus(201);

        $resource = Resource::latest()->first();
        expect($resource->fundingReferences)->toHaveCount(1)
            ->and($resource->fundingReferences->first()->funder_name)->toBe('Valid Funder');
    });

    it('validates funder identifier type against allowed values', function () {
        $payload = getValidPayload([
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
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['fundingReferences.0.funderIdentifierType']);
    });

    it('validates award URI format', function () {
        $payload = getValidPayload([
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
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('editor.resources.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['fundingReferences.0.awardUri']);
    });
});

describe('Funder identifier type persistence', function () {
    it('persists funder_identifier_type_id for ROR and Crossref funders', function () {
        $this->artisan('db:seed', ['--class' => 'FunderIdentifierTypeSeeder']);

        $rorType = \App\Models\FunderIdentifierType::where('name', 'ROR')->first();
        $crossrefType = \App\Models\FunderIdentifierType::where('name', 'Crossref Funder ID')->first();

        $payload = getValidPayload([
            'fundingReferences' => [
                [
                    'funderName' => 'DFG',
                    'funderIdentifier' => 'https://ror.org/018mejw64',
                    'funderIdentifierType' => 'ROR',
                    'awardNumber' => '',
                    'awardUri' => '',
                    'awardTitle' => '',
                ],
                [
                    'funderName' => 'EU',
                    'funderIdentifier' => 'https://doi.org/10.13039/501100000780',
                    'funderIdentifierType' => 'Crossref Funder ID',
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
            'funder_name' => 'DFG',
            'funder_identifier_type_id' => $rorType->id,
            'scheme_uri' => 'https://ror.org/',
        ]);

        $this->assertDatabaseHas('funding_references', [
            'funder_name' => 'EU',
            'funder_identifier_type_id' => $crossrefType->id,
            'scheme_uri' => 'https://doi.org/10.13039/',
        ]);
    });

    it('persists null funder_identifier_type_id when no type is sent', function () {
        $payload = getValidPayload([
            'fundingReferences' => [
                [
                    'funderName' => 'Unknown Funder',
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
            'funder_name' => 'Unknown Funder',
            'funder_identifier_type_id' => null,
            'scheme_uri' => null,
        ]);
    });
});
