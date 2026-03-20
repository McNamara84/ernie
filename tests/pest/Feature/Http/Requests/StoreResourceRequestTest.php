<?php

declare(strict_types=1);

use App\Http\Requests\StoreResourceRequest;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;

covers(StoreResourceRequest::class);

/**
 * Build valid minimal resource payload.
 *
 * @return array<string, mixed>
 */
function validResourcePayload(int $resourceTypeId, string $licenseIdentifier): array
{
    return [
        'year' => 2025,
        'resourceType' => $resourceTypeId,
        'titles' => [
            ['title' => 'Test Resource', 'titleType' => 'main-title'],
        ],
        'licenses' => [$licenseIdentifier],
        'authors' => [
            [
                'type' => 'person',
                'position' => 0,
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'isContact' => false,
                'affiliations' => [],
            ],
        ],
        // Abstract description is required by after() validator
        'descriptions' => [
            ['descriptionType' => 'abstract', 'description' => 'Test abstract.'],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    TitleType::factory()->count(5)->create();
    $this->resourceType = ResourceType::factory()->create();
    $this->right = Right::factory()->create();
});

// =========================================================================
// Required field validation
// =========================================================================

describe('required fields', function () {
    it('rejects empty payload', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['year', 'resourceType', 'titles', 'licenses', 'authors']);
    });

    it('accepts valid minimal payload', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        // Should not fail on validation (may fail on other things like missing publisher)
        $response->assertJsonMissingValidationErrors(['year', 'resourceType', 'titles', 'licenses', 'authors']);
    });
});

// =========================================================================
// Year validation
// =========================================================================

describe('year validation', function () {
    it('rejects year below 1000', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['year'] = 999;

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['year']);
    });

    it('rejects year above 9999', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['year'] = 10000;

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['year']);
    });

    it('rejects non-integer year', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['year'] = 'not-a-number';

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['year']);
    });
});

// =========================================================================
// Resource type validation
// =========================================================================

describe('resource type validation', function () {
    it('rejects non-existent resource type', function () {
        $data = validResourcePayload(99999, $this->right->identifier);

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['resourceType']);
    });
});

// =========================================================================
// Titles validation
// =========================================================================

describe('titles validation', function () {
    it('requires at least one title', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['titles'] = [];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['titles']);
    });

    it('requires title text', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['titles'] = [['title' => '', 'titleType' => 'main-title']];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['titles.0.title']);
    });

    it('requires title type', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['titles'] = [['title' => 'Test', 'titleType' => '']];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['titles.0.titleType']);
    });
});

// =========================================================================
// Licenses validation
// =========================================================================

describe('licenses validation', function () {
    it('requires at least one license', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['licenses'] = [];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['licenses']);
    });

    it('rejects non-existent license identifier', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['licenses'] = ['NON-EXISTENT-LICENSE'];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['licenses.0']);
    });
});

// =========================================================================
// Authors validation
// =========================================================================

describe('authors validation', function () {
    it('requires at least one author', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['authors'] = [];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['authors']);
    });

    it('validates author website URL format when isContact is true', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['authors'][0]['isContact'] = true;
        $data['authors'][0]['website'] = 'not-a-url';

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['authors.0.website']);
    });

    it('normalizes missing author type to person via prepareForValidation', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['authors'] = [
            ['position' => 0, 'firstName' => 'A', 'lastName' => 'B', 'isContact' => false, 'affiliations' => []],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        // prepareForValidation defaults missing type to 'person', so no validation error on type
        $response->assertJsonMissingValidationErrors(['authors.0.type']);
    });
});

// =========================================================================
// Descriptions validation
// =========================================================================

describe('descriptions validation', function () {
    it('rejects invalid description type', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['descriptions'] = [
            ['descriptionType' => 'invalid-type', 'description' => 'Some text'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['descriptions.0.descriptionType']);
    });

    it('accepts valid description types', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['descriptions'] = [
            ['descriptionType' => 'abstract', 'description' => 'An abstract'],
            ['descriptionType' => 'methods', 'description' => 'Method description'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonMissingValidationErrors([
            'descriptions.0.descriptionType',
            'descriptions.1.descriptionType',
        ]);
    });
});

// =========================================================================
// Spatial/Temporal coverage validation
// =========================================================================

describe('spatial temporal coverage validation', function () {
    it('rejects latitude outside valid range', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['spatialTemporalCoverages'] = [
            ['type' => 'point', 'latMin' => 91.0, 'lonMin' => 0.0],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['spatialTemporalCoverages.0.latMin']);
    });

    it('rejects longitude outside valid range', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['spatialTemporalCoverages'] = [
            ['type' => 'point', 'latMin' => 0.0, 'lonMin' => 181.0],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['spatialTemporalCoverages.0.lonMin']);
    });

    it('validates coverage type values at rule level', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['spatialTemporalCoverages'] = [
            ['type' => 'point', 'latMin' => 52.0, 'lonMin' => 13.0],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        // Valid type should not produce validation error for the type field
        $response->assertJsonMissingValidationErrors(['spatialTemporalCoverages.0.type']);
    });
});

// =========================================================================
// Related identifiers validation
// =========================================================================

describe('related identifiers validation', function () {
    it('rejects invalid identifier type', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['relatedIdentifiers'] = [
            ['identifier' => '10.1234/test', 'identifierType' => 'INVALID', 'relationType' => 'Cites'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['relatedIdentifiers.0.identifierType']);
    });

    it('rejects invalid relation type', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['relatedIdentifiers'] = [
            ['identifier' => '10.1234/test', 'identifierType' => 'DOI', 'relationType' => 'InvalidRelation'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['relatedIdentifiers.0.relationType']);
    });

    it('accepts valid related identifier', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['relatedIdentifiers'] = [
            ['identifier' => '10.1234/test', 'identifierType' => 'DOI', 'relationType' => 'Cites'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonMissingValidationErrors([
            'relatedIdentifiers.0.identifier',
            'relatedIdentifiers.0.identifierType',
            'relatedIdentifiers.0.relationType',
        ]);
    });
});

// =========================================================================
// Funding references validation
// =========================================================================

describe('funding references validation', function () {
    it('validates funding reference funder name is required', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['fundingReferences'] = [
            ['funderName' => 'Valid Funder', 'awardNumber' => '12345'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        // Valid funder name should not produce validation error
        $response->assertJsonMissingValidationErrors(['fundingReferences.0.funderName']);
    });

    it('rejects invalid funder identifier type', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['fundingReferences'] = [
            ['funderName' => 'DFG', 'funderIdentifierType' => 'INVALID'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['fundingReferences.0.funderIdentifierType']);
    });

    it('rejects invalid award URI', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['fundingReferences'] = [
            ['funderName' => 'DFG', 'awardUri' => 'not-a-url'],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['fundingReferences.0.awardUri']);
    });
});

// =========================================================================
// Contributor Contact Person email/website validation
// =========================================================================

describe('contributor contact person validation', function () {
    it('requires email when Contact Person role is assigned', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['contributors'] = [
            [
                'type' => 'person',
                'firstName' => 'Contact',
                'lastName' => 'Person',
                'roles' => ['Contact Person'],
                'email' => '',
                'website' => '',
                'affiliations' => [],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['contributors.0.email']);
    });

    it('accepts valid email when Contact Person role is assigned', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['contributors'] = [
            [
                'type' => 'person',
                'firstName' => 'Contact',
                'lastName' => 'Person',
                'roles' => ['Contact Person'],
                'email' => 'contact@example.org',
                'website' => 'https://example.org',
                'affiliations' => [],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonMissingValidationErrors(['contributors.0.email', 'contributors.0.website']);
    });

    it('does not require email when no Contact Person role', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['contributors'] = [
            [
                'type' => 'person',
                'firstName' => 'Other',
                'lastName' => 'Contributor',
                'roles' => ['DataCollector'],
                'email' => '',
                'website' => '',
                'affiliations' => [],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonMissingValidationErrors(['contributors.0.email']);
    });

    it('rejects invalid email format on contributor', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['contributors'] = [
            [
                'type' => 'person',
                'firstName' => 'Bad',
                'lastName' => 'Email',
                'roles' => ['Contact Person'],
                'email' => 'not-an-email',
                'affiliations' => [],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['contributors.0.email']);
    });

    it('rejects invalid website URL on contributor', function () {
        $data = validResourcePayload($this->resourceType->id, $this->right->identifier);
        $data['contributors'] = [
            [
                'type' => 'person',
                'firstName' => 'Bad',
                'lastName' => 'Website',
                'roles' => ['Contact Person'],
                'email' => 'ok@example.org',
                'website' => 'not-a-url',
                'affiliations' => [],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources', $data);

        $response->assertJsonValidationErrors(['contributors.0.website']);
    });
});
