<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteRegistrationService;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
        'datacite.production.username' => 'PROD.USER',
        'datacite.production.password' => 'prod-password',
        'datacite.production.endpoint' => 'https://api.datacite.org',
        'datacite.production.prefixes' => ['10.5880', '10.26026', '10.14470'],
    ]);

    $this->user = User::factory()->curator()->create();
});

/**
 * Helper to create an IGSN resource with metadata.
 *
 * @param  array<string, mixed>  $resourceOverrides
 * @param  array<string, mixed>  $metadataOverrides
 */
function createIgsnWithMetadata(array $resourceOverrides = [], array $metadataOverrides = []): Resource
{
    $resource = Resource::factory()->create(array_merge([
        'doi' => '10.83279/IGSN-TEST-001',
        'publication_year' => 2024,
    ], $resourceOverrides));

    IgsnMetadata::create(array_merge([
        'resource_id' => $resource->id,
        'upload_status' => IgsnMetadata::STATUS_UPLOADED,
        'sample_type' => 'Rock',
        'material' => 'Granite',
    ], $metadataOverrides));

    return $resource->fresh(['igsnMetadata']);
}

// ============================================================================
// Service: registerIgsn()
// ============================================================================

describe('DataCiteRegistrationService::registerIgsn', function () {
    test('registers an IGSN keeping the existing DOI in the payload', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/IGSN-TEST-001',
                    'type' => 'dois',
                    'attributes' => [
                        'doi' => '10.83279/IGSN-TEST-001',
                        'state' => 'findable',
                    ],
                ],
            ], 201),
        ]);

        $service = app(DataCiteRegistrationService::class);
        $response = $service->registerIgsn($resource);

        expect($response['data']['id'])->toBe('10.83279/IGSN-TEST-001');

        // Verify the DOI was kept in the payload (not unset like registerDoi)
        // and publicationYear is always set to current year
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($request->url(), 'api.test.datacite.org/dois')
                && $request->method() === 'POST'
                && isset($body['data']['attributes']['doi'])
                && $body['data']['attributes']['doi'] === '10.83279/IGSN-TEST-001'
                && isset($body['data']['attributes']['publicationYear'])
                && $body['data']['attributes']['publicationYear'] === (string) date('Y');
        });
    });

    test('extracts prefix from IGSN automatically', function () {
        $resource = createIgsnWithMetadata(['doi' => '10.83186/SAMPLE-XYZ']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83186/SAMPLE-XYZ',
                    'type' => 'dois',
                    'attributes' => ['doi' => '10.83186/SAMPLE-XYZ', 'state' => 'findable'],
                ],
            ], 201),
        ]);

        $service = app(DataCiteRegistrationService::class);
        $response = $service->registerIgsn($resource);

        expect($response['data']['id'])->toBe('10.83186/SAMPLE-XYZ');

        // Verify the correct prefix was sent
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['data']['attributes']['prefix'] === '10.83186';
        });
    });

    test('rejects IGSN with invalid prefix', function () {
        $resource = createIgsnWithMetadata(['doi' => '10.99999/INVALID-PREFIX']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $service = app(DataCiteRegistrationService::class);

        expect(fn () => $service->registerIgsn($resource))
            ->toThrow(\InvalidArgumentException::class, "IGSN prefix '10.99999' is not allowed");
    });

    test('requires a landing page before registering', function () {
        $resource = createIgsnWithMetadata();
        // No landing page created

        $service = app(DataCiteRegistrationService::class);

        expect(fn () => $service->registerIgsn($resource))
            ->toThrow(\RuntimeException::class, 'must have a landing page');
    });

    test('requires an IGSN (doi) to register', function () {
        $resource = createIgsnWithMetadata(['doi' => null]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $service = app(DataCiteRegistrationService::class);

        expect(fn () => $service->registerIgsn($resource))
            ->toThrow(\RuntimeException::class, 'must have an IGSN');
    });
});

// ============================================================================
// Controller: IgsnController@registerAtDataCite (single registration)
// ============================================================================

describe('IgsnController@registerAtDataCite', function () {
    test('registers an IGSN at DataCite successfully', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/IGSN-TEST-001',
                    'type' => 'dois',
                    'attributes' => ['doi' => '10.83279/IGSN-TEST-001', 'state' => 'findable'],
                ],
            ], 201),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'doi' => '10.83279/IGSN-TEST-001',
                'updated' => false,
            ]);

        // Verify status was updated to registered
        $resource->refresh();
        expect($resource->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_REGISTERED);
    });

    test('sets publicationYear to current year on registration', function () {
        $resource = createIgsnWithMetadata(['publication_year' => 2020]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/IGSN-TEST-001',
                    'type' => 'dois',
                    'attributes' => ['doi' => '10.83279/IGSN-TEST-001', 'state' => 'findable'],
                ],
            ], 201),
        ]);

        $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register")
            ->assertOk();

        $resource->refresh();
        expect($resource->publication_year)->toBe((int) date('Y'));
    });

    test('rejects registration without landing page', function () {
        $resource = createIgsnWithMetadata();
        // No landing page

        $response = $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Landing page required',
            ]);
    });

    test('updates metadata for already-registered IGSN', function () {
        $resource = createIgsnWithMetadata([], ['upload_status' => IgsnMetadata::STATUS_REGISTERED]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/IGSN-TEST-001',
                    'type' => 'dois',
                    'attributes' => ['doi' => '10.83279/IGSN-TEST-001', 'state' => 'findable'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'updated' => true,
            ]);

        // Verify PUT was used instead of POST
        Http::assertSent(function ($request) {
            return $request->method() === 'PUT';
        });
    });

    test('marks IGSN as error on API failure', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response(['errors' => [['title' => 'Bad Request']]], 400),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        // DataCite 400 maps to 400 (client error preserved)
        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'DataCite API error',
            'message' => 'Bad Request',
        ]);

        $resource->refresh();
        expect($resource->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_ERROR);
        expect($resource->igsnMetadata->upload_error_message)->not->toBeNull();
    });

    test('returns 404 for non-IGSN resource', function () {
        $resource = Resource::factory()->create();
        // No igsnMetadata

        $response = $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        $response->assertStatus(404);
    });
});

// ============================================================================
// Controller: BatchIgsnRegistrationController@register
// ============================================================================

describe('BatchIgsnRegistrationController@register', function () {
    test('batch registers multiple IGSNs successfully', function () {
        $resource1 = createIgsnWithMetadata(['doi' => '10.83279/BATCH-001']);
        $resource2 = createIgsnWithMetadata(['doi' => '10.83279/BATCH-002']);
        LandingPage::factory()->create(['resource_id' => $resource1->id]);
        LandingPage::factory()->create(['resource_id' => $resource2->id]);

        Http::fake([
            '*datacite.org/*' => function (\Illuminate\Http\Client\Request $request) {
                $payload = $request->data();
                $doi = $payload['data']['attributes']['doi'] ?? 'unknown';

                return Http::response([
                    'data' => [
                        'id' => $doi,
                        'type' => 'dois',
                        'attributes' => ['state' => 'findable'],
                    ],
                ], 201);
            },
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', [
                'ids' => [$resource1->id, $resource2->id],
            ]);

        $response->assertOk();

        $data = $response->json();
        expect($data['success'])->toHaveCount(2);
        expect($data['failed'])->toHaveCount(0);

        // Both should be registered
        $resource1->refresh();
        $resource2->refresh();
        expect($resource1->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_REGISTERED);
        expect($resource2->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_REGISTERED);
    });

    test('reports failures for IGSNs without landing pages', function () {
        $resource1 = createIgsnWithMetadata(['doi' => '10.83279/LP-YES']);
        $resource2 = createIgsnWithMetadata(['doi' => '10.83279/LP-NO']);
        LandingPage::factory()->create(['resource_id' => $resource1->id]);
        // resource2 has no landing page

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/LP-YES',
                    'type' => 'dois',
                    'attributes' => ['state' => 'findable'],
                ],
            ], 201),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', [
                'ids' => [$resource1->id, $resource2->id],
            ]);

        // 207 Multi-Status because one failed
        $response->assertStatus(207);

        $data = $response->json();
        expect($data['success'])->toHaveCount(1);
        expect($data['failed'])->toHaveCount(1);
        expect($data['failed'][0]['reason'])->toBe('No landing page configured');
    });

    test('sets publicationYear for all registered IGSNs', function () {
        $resource = createIgsnWithMetadata(['doi' => '10.83279/YEAR-001', 'publication_year' => 2020]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/YEAR-001',
                    'type' => 'dois',
                    'attributes' => ['state' => 'findable'],
                ],
            ], 201),
        ]);

        $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
            ->assertOk();

        $resource->refresh();
        expect($resource->publication_year)->toBe((int) date('Y'));
    });

    test('validates request requires ids', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', []);

        $response->assertStatus(422);
    });

    test('isolates failures from successes in batch', function () {
        $resource1 = createIgsnWithMetadata(['doi' => '10.83279/OK-001']);
        $resource2 = createIgsnWithMetadata(['doi' => '10.99999/BAD-PREFIX']);
        LandingPage::factory()->create(['resource_id' => $resource1->id]);
        LandingPage::factory()->create(['resource_id' => $resource2->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/OK-001',
                    'type' => 'dois',
                    'attributes' => ['state' => 'findable'],
                ],
            ], 201),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', [
                'ids' => [$resource1->id, $resource2->id],
            ]);

        $response->assertStatus(207);

        $data = $response->json();
        // resource1 succeeds, resource2 fails due to invalid prefix
        expect($data['success'])->toHaveCount(1);
        expect($data['failed'])->toHaveCount(1);

        // resource1 → registered, resource2 → error
        $resource1->refresh();
        $resource2->refresh();
        expect($resource1->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_REGISTERED);
        expect($resource2->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_ERROR);
    });
});

// ============================================================================
// IgsnController: transformResource includes has_landing_page
// ============================================================================

describe('IgsnController@index includes has_landing_page', function () {
    test('returns has_landing_page true when landing page exists', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $response = $this->actingAs($this->user)
            ->get('/igsns');

        $response->assertOk();
        $igsns = $response->original->getData()['page']['props']['igsns'];
        $igsn = collect($igsns)->firstWhere('id', $resource->id);
        expect($igsn['has_landing_page'])->toBeTrue();
    });

    test('returns has_landing_page false when no landing page', function () {
        $resource = createIgsnWithMetadata();
        // No landing page

        $response = $this->actingAs($this->user)
            ->get('/igsns');

        $response->assertOk();
        $igsns = $response->original->getData()['page']['props']['igsns'];
        $igsn = collect($igsns)->firstWhere('id', $resource->id);
        expect($igsn['has_landing_page'])->toBeFalse();
    });
});
