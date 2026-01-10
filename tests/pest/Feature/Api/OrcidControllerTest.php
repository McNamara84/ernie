<?php

use App\Services\OrcidService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Clear HTTP fakes between tests
    Http::preventStrayRequests();
});

describe('OrcidController - Validation Endpoint', function () {
    test('validates orcid format successfully', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response([
                'name' => [
                    'given-names' => ['value' => 'John'],
                    'family-name' => ['value' => 'Doe'],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/orcid/validate/0000-0001-2345-6789');

        $response->assertOk()
            ->assertJson([
                'valid' => true,
                'exists' => true,
            ]);
    });

    test('returns invalid for malformed orcid', function () {
        $response = $this->getJson('/api/v1/orcid/validate/invalid-orcid');

        $response->assertOk()
            ->assertJson([
                'valid' => false,
                'exists' => null,
            ]);
    });

    test('returns valid but not existing for unknown orcid', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0001-2345-6789/person' => Http::response(null, 404),
        ]);

        $response = $this->getJson('/api/v1/orcid/validate/0000-0001-2345-6789');

        $response->assertOk()
            ->assertJson([
                'valid' => true,
                'exists' => false,
            ]);
    });
});

describe('OrcidController - Show Endpoint', function () {
    test('fetches orcid record successfully', function () {
        Http::fake([
            'pub.orcid.org/v3.0/0000-0002-1825-0097' => Http::response([
                'person' => [
                    'name' => [
                        'given-names' => ['value' => 'Josiah'],
                        'family-name' => ['value' => 'Carberry'],
                    ],
                ],
            ], 200),
            'pub.orcid.org/v3.0/0000-0002-1825-0097/person' => Http::response([
                'name' => [
                    'given-names' => ['value' => 'Josiah'],
                    'family-name' => ['value' => 'Carberry'],
                ],
            ], 200),
            'pub.orcid.org/v3.0/0000-0002-1825-0097/employments' => Http::response([
                'affiliation-group' => [],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/orcid/0000-0002-1825-0097');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    });

    test('returns 404 for unknown orcid', function () {
        Http::fake([
            'pub.orcid.org/*' => Http::response(null, 404),
        ]);

        $response = $this->getJson('/api/v1/orcid/0000-0001-0000-0000');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    });

    test('returns 400 for invalid orcid format', function () {
        $response = $this->getJson('/api/v1/orcid/invalid');

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    });
});

describe('OrcidController - Search Endpoint', function () {
    test('searches for orcid records', function () {
        Http::fake([
            'pub.orcid.org/v3.0/search*' => Http::response([
                'num-found' => 1,
                'result' => [
                    [
                        'orcid-identifier' => [
                            'path' => '0000-0002-1825-0097',
                            'uri' => 'https://orcid.org/0000-0002-1825-0097',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/orcid/search?q=Carberry');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    });

    test('validates search query is required', function () {
        $response = $this->getJson('/api/v1/orcid/search');

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ])
            ->assertJsonStructure([
                'errors' => ['q'],
            ]);
    });

    test('validates search query minimum length', function () {
        $response = $this->getJson('/api/v1/orcid/search?q=a');

        $response->assertStatus(422)
            ->assertJsonStructure([
                'errors' => ['q'],
            ]);
    });

    test('validates search query maximum length', function () {
        $longQuery = str_repeat('a', 201);
        $response = $this->getJson('/api/v1/orcid/search?q='.$longQuery);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'errors' => ['q'],
            ]);
    });

    test('validates limit parameter range', function () {
        $response = $this->getJson('/api/v1/orcid/search?q=Test&limit=100');

        $response->assertStatus(422)
            ->assertJsonStructure([
                'errors' => ['limit'],
            ]);
    });

    test('accepts valid limit parameter', function () {
        Http::fake([
            'pub.orcid.org/v3.0/search*' => Http::response([
                'num-found' => 0,
                'result' => [],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/orcid/search?q=Test&limit=25');

        $response->assertOk();
    });

    test('handles api error gracefully', function () {
        Http::fake([
            'pub.orcid.org/v3.0/search*' => Http::response(null, 500),
        ]);

        $response = $this->getJson('/api/v1/orcid/search?q=Test');

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    });
});
