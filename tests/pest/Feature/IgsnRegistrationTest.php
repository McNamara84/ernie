<?php

declare(strict_types=1);

use App\Jobs\ProcessIgsnRegistrationRunJob;
use App\Models\IgsnMetadata;
use App\Models\IgsnRegistrationRun;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteRegistrationService;
use App\Services\IgsnRegistrationExclusionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

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
        'datacite.production.igsn_prefix' => '10.60510',
        'datacite.production.igsn_username' => 'GFZ.IGSN',
        'datacite.production.igsn_password' => 'igsn-password',
        'queue.default' => 'database',
        'datacite.queue' => 'datacite',
    ]);

    Queue::fake();
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

    test('uses the dedicated repository credentials when registering a production IGSN', function () {
        config(['datacite.test_mode' => false]);
        $resource = createIgsnWithMetadata(['doi' => '10.60510/GFTEST001']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            'api.datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.60510/GFTEST001',
                    'type' => 'dois',
                    'attributes' => ['doi' => '10.60510/GFTEST001', 'state' => 'findable'],
                ],
            ], 201),
        ]);

        $response = app(DataCiteRegistrationService::class)->registerIgsn($resource);

        expect($response['data']['id'])->toBe('10.60510/GFTEST001');
        Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0]
            === 'Basic '.base64_encode('GFZ.IGSN:igsn-password'));
    });

    test('rejects IGSN with invalid DOI format (missing suffix)', function () {
        $resource = createIgsnWithMetadata(['doi' => '10.83279']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $service = app(DataCiteRegistrationService::class);

        expect(fn () => $service->registerIgsn($resource))
            ->toThrow(InvalidArgumentException::class, 'invalid format');
    });

    test('rejects IGSN with invalid prefix', function () {
        $resource = createIgsnWithMetadata(['doi' => '10.99999/INVALID-PREFIX']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $service = app(DataCiteRegistrationService::class);

        expect(fn () => $service->registerIgsn($resource))
            ->toThrow(InvalidArgumentException::class, "IGSN prefix '10.99999' is not allowed");
    });

    test('requires a landing page before registering', function () {
        $resource = createIgsnWithMetadata();
        // No landing page created

        $service = app(DataCiteRegistrationService::class);

        expect(fn () => $service->registerIgsn($resource))
            ->toThrow(RuntimeException::class, 'must have a landing page');
    });

    test('requires an IGSN (doi) to register', function () {
        $resource = createIgsnWithMetadata(['doi' => null]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $service = app(DataCiteRegistrationService::class);

        expect(fn () => $service->registerIgsn($resource))
            ->toThrow(RuntimeException::class, 'must have an IGSN');
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

    test('rejects single registration while the IGSN is queued in an active batch run', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id]);
        Http::fake();

        $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
            ->assertAccepted();

        $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register")
            ->assertConflict()
            ->assertJsonPath('error', 'Registration already queued');

        expect($resource->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED);
        Http::assertNothingSent();
    });

    test('rejects single registration while the shared resource lock is held', function () {
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id]);
        Http::fake();
        $lock = app(IgsnRegistrationExclusionService::class)->resourceLock($resource->id);
        expect($lock->get())->toBeTrue();

        try {
            $this->actingAs($this->user)
                ->postJson("/igsns/{$resource->id}/register")
                ->assertConflict()
                ->assertJsonPath('error', 'Registration in progress');
        } finally {
            $lock->release();
        }

        expect($resource->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED);
        Http::assertNothingSent();
    });

    test('updates metadata for already-registered IGSN', function () {
        $resource = createIgsnWithMetadata(
            ['publication_year' => 2020],
            ['upload_status' => IgsnMetadata::STATUS_REGISTERED],
        );
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

        // publicationYear must NOT be overwritten for already-registered IGSNs
        $resource->refresh();
        expect($resource->publication_year)->toBe(2020);
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

    test('does not persist publicationYear on API failure', function () {
        $resource = createIgsnWithMetadata(['publication_year' => 2020]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response(['errors' => [['title' => 'Bad Request']]], 400),
        ]);

        $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        $resource->refresh();
        // publication_year should NOT have been updated because the DataCite call failed
        expect($resource->publication_year)->toBe(2020);
    });

    test('returns 404 for non-IGSN resource', function () {
        $resource = Resource::factory()->create();
        // No igsnMetadata

        $response = $this->actingAs($this->user)
            ->postJson("/igsns/{$resource->id}/register");

        $response->assertStatus(404);
    });

    test('allows beginners to register IGSNs in test mode', function () {
        $beginner = User::factory()->beginner()->create();
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        config(['datacite.test_mode' => false]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/IGSN-TEST-001',
                    'type' => 'dois',
                    'attributes' => ['doi' => '10.83279/IGSN-TEST-001', 'state' => 'findable'],
                ],
            ], 201),
        ]);

        $response = $this->actingAs($beginner)
            ->postJson("/igsns/{$resource->id}/register");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'doi' => '10.83279/IGSN-TEST-001',
                'mode' => 'test',
                'updated' => false,
            ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.test.datacite.org/dois')
            && $request->method() === 'POST');

        $resource->refresh();
        expect($resource->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_REGISTERED);
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
            '*datacite.org/*' => function (Request $request) {
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

        $response->assertAccepted()
            ->assertJsonPath('run.total', 2)
            ->assertJsonPath('run.status', 'queued');

        Queue::assertPushedOn('datacite', ProcessIgsnRegistrationRunJob::class);
        Http::assertNothingSent();
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

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('ids.1');

        expect(IgsnRegistrationRun::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    test('sets publicationYear for newly registered IGSNs', function () {
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
            ->assertAccepted()
            ->assertJsonPath('run.total', 1);

        $resource->refresh();
        expect($resource->publication_year)->toBe(2020);
    });

    test('preserves publicationYear for already-registered IGSNs in batch', function () {
        $resource = createIgsnWithMetadata(
            ['doi' => '10.83279/YEAR-002', 'publication_year' => 2020],
            ['upload_status' => IgsnMetadata::STATUS_REGISTERED],
        );
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/YEAR-002',
                    'type' => 'dois',
                    'attributes' => ['state' => 'findable'],
                ],
            ], 200),
        ]);

        $this->actingAs($this->user)
            ->postJson('/igsns/batch-register', ['ids' => [$resource->id]])
            ->assertAccepted()
            ->assertJsonPath('run.total', 1);

        $resource->refresh();
        expect($resource->publication_year)->toBe(2020);
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

        $response->assertAccepted()
            ->assertJsonPath('run.total', 2);

        expect($resource1->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED)
            ->and($resource2->fresh()->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED);
        Http::assertNothingSent();
    });

    test('allows beginners to batch register IGSNs in test mode', function () {
        $beginner = User::factory()->beginner()->create();
        $resource = createIgsnWithMetadata();
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        config(['datacite.test_mode' => false]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/IGSN-TEST-001',
                    'type' => 'dois',
                    'attributes' => ['doi' => '10.83279/IGSN-TEST-001', 'state' => 'findable'],
                ],
            ], 201),
        ]);

        $response = $this->actingAs($beginner)
            ->postJson('/igsns/batch-register', ['ids' => [$resource->id]]);

        $response->assertAccepted()
            ->assertJsonPath('run.test_mode', true)
            ->assertJsonPath('run.datacite_endpoint', 'https://api.test.datacite.org');

        Http::assertNothingSent();

        $resource->refresh();
        expect($resource->igsnMetadata->upload_status)->toBe(IgsnMetadata::STATUS_UPLOADED);
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
