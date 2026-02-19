<?php

declare(strict_types=1);

use App\Http\Controllers\DoiValidationController;
use App\Models\User;
use Illuminate\Support\Facades\Http;

covers(DoiValidationController::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

// =========================================================================
// Request validation
// =========================================================================

describe('request validation', function () {
    it('requires doi field', function () {
        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doi');
    });

    it('requires doi to be a string', function () {
        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => 123])
            ->assertUnprocessable();
    });
});

// =========================================================================
// DOI format validation
// =========================================================================

describe('DOI format validation', function () {
    it('rejects invalid DOI format', function () {
        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => 'not-a-doi'])
            ->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error' => 'Invalid DOI format',
            ]);
    });

    it('rejects DOI without 10. prefix', function () {
        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => '11.1234/test'])
            ->assertStatus(400);
    });

    it('extracts DOI from doi.org URL format', function () {
        Http::fake([
            'api.datacite.org/*' => Http::response([
                'data' => [
                    'attributes' => [
                        'titles' => [['title' => 'Test Dataset']],
                        'creators' => [['name' => 'Author']],
                        'publicationYear' => 2025,
                        'publisher' => 'GFZ',
                        'types' => ['resourceType' => 'Dataset'],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => 'https://doi.org/10.5880/test.2025.001'])
            ->assertOk()
            ->assertJson(['success' => true, 'source' => 'datacite']);
    });

    it('extracts DOI from dx.doi.org URL format', function () {
        Http::fake([
            'api.datacite.org/*' => Http::response([
                'data' => [
                    'attributes' => [
                        'titles' => [['title' => 'Test']],
                        'creators' => [],
                        'publicationYear' => 2025,
                        'publisher' => 'GFZ',
                        'types' => ['resourceType' => 'Dataset'],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => 'http://dx.doi.org/10.5880/test.2025.002'])
            ->assertOk()
            ->assertJson(['success' => true]);
    });
});

// =========================================================================
// DataCite API resolution
// =========================================================================

describe('DataCite API resolution', function () {
    it('returns metadata from DataCite when DOI is found', function () {
        Http::fake([
            'api.datacite.org/*' => Http::response([
                'data' => [
                    'attributes' => [
                        'titles' => [['title' => 'Seismic Activity Dataset']],
                        'creators' => [
                            ['name' => 'Smith, John'],
                            ['givenName' => 'Jane', 'familyName' => 'Doe'],
                        ],
                        'publicationYear' => 2024,
                        'publisher' => 'GFZ Data Services',
                        'types' => ['resourceType' => 'Dataset'],
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => '10.5880/GFZ.1.2.2024.001']);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'source' => 'datacite',
                'metadata' => [
                    'title' => 'Seismic Activity Dataset',
                    'creators' => ['Smith, John', 'Jane Doe'],
                    'publicationYear' => 2024,
                    'publisher' => 'GFZ Data Services',
                    'resourceType' => 'Dataset',
                ],
            ]);
    });

    it('extracts creator names from name field', function () {
        Http::fake([
            'api.datacite.org/*' => Http::response([
                'data' => [
                    'attributes' => [
                        'titles' => [['title' => 'Test']],
                        'creators' => [['name' => 'Organization Name']],
                        'publicationYear' => 2025,
                        'publisher' => 'Pub',
                        'types' => ['resourceType' => 'Dataset'],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => '10.5880/test.001'])
            ->assertOk()
            ->assertJsonPath('metadata.creators.0', 'Organization Name');
    });

    it('extracts creator names from familyName only', function () {
        Http::fake([
            'api.datacite.org/*' => Http::response([
                'data' => [
                    'attributes' => [
                        'titles' => [['title' => 'Test']],
                        'creators' => [['familyName' => 'Mueller']],
                        'publicationYear' => 2025,
                        'publisher' => 'Pub',
                        'types' => ['resourceType' => 'Dataset'],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => '10.5880/test.002'])
            ->assertOk()
            ->assertJsonPath('metadata.creators.0', 'Mueller');
    });

    it('falls back to Unknown when no name fields present', function () {
        Http::fake([
            'api.datacite.org/*' => Http::response([
                'data' => [
                    'attributes' => [
                        'titles' => [['title' => 'Test']],
                        'creators' => [[]],
                        'publicationYear' => 2025,
                        'publisher' => 'Pub',
                        'types' => ['resourceType' => 'Dataset'],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => '10.5880/test.003'])
            ->assertOk()
            ->assertJsonPath('metadata.creators.0', 'Unknown');
    });

    it('handles DataCite API error status', function () {
        Http::fake([
            'api.datacite.org/*' => Http::response([], 500),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => '10.5880/test.004'])
            ->assertOk()
            ->assertJson([
                'success' => false,
                'error' => 'DataCite API error: 500',
            ]);
    });
});

// =========================================================================
// doi.org fallback resolution
// =========================================================================

describe('doi.org fallback resolution', function () {
    it('falls back to doi.org when DataCite returns 404', function () {
        Http::fake([
            'api.datacite.org/*' => Http::response([], 404),
            'doi.org/*' => Http::response('', 302),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => '10.1000/crossref.2025.001'])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'source' => 'doi.org',
                'metadata' => ['title' => 'DOI registered'],
            ]);
    });

    it('handles 301 redirect from doi.org', function () {
        Http::fake([
            'api.datacite.org/*' => Http::response([], 404),
            'doi.org/*' => Http::response('', 301),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => '10.1000/redirect.test'])
            ->assertOk()
            ->assertJson(['success' => true, 'source' => 'doi.org']);
    });

    it('returns not found when doi.org does not resolve', function () {
        Http::fake([
            'api.datacite.org/*' => Http::response([], 404),
            'doi.org/*' => Http::response('', 404),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => '10.5880/does.not.exist'])
            ->assertOk()
            ->assertJson([
                'success' => false,
                'error' => 'DOI not found in DataCite registry',
            ]);
    });

    it('returns not found when doi.org throws exception', function () {
        Http::fake([
            'api.datacite.org/*' => Http::response([], 404),
            'doi.org/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Timeout'),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => '10.5880/timeout.test'])
            ->assertOk()
            ->assertJson([
                'success' => false,
                'error' => 'DOI not found in DataCite registry',
            ]);
    });
});

// =========================================================================
// Exception handling
// =========================================================================

describe('exception handling', function () {
    it('returns 500 when DataCite API throws exception', function () {
        Http::fake([
            'api.datacite.org/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/validate-doi', ['doi' => '10.5880/exception.test'])
            ->assertStatus(500)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonFragment(['error' => 'Failed to validate DOI: Connection refused']);
    });
});
