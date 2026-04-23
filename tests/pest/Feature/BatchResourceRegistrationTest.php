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

describe('BatchResourceRegistrationController@register', function () {
    test('updates metadata for resources that already have a DOI', function () {
        $resource1 = Resource::factory()->create(['doi' => '10.83279/BATCH-REG-001']);
        $resource2 = Resource::factory()->create(['doi' => '10.83279/BATCH-REG-002']);
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
                ], 200);
            },
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource1->id, $resource2->id],
            ]);

        $response->assertOk();
        $data = $response->json();
        expect($data['success'])->toHaveCount(2);
        expect($data['failed'])->toHaveCount(0);
        expect($data['success'][0]['updated'])->toBeTrue();
    });

    test('registers a new DOI when prefix is provided and resource lacks a DOI', function () {
        $resource = Resource::factory()->create(['doi' => null]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => function (\Illuminate\Http\Client\Request $request) {
                $payload = $request->data();

                return Http::response([
                    'data' => [
                        'id' => $payload['data']['attributes']['doi'] ?? '10.83279/NEW-001',
                        'type' => 'dois',
                        'attributes' => ['state' => 'findable'],
                    ],
                ], 201);
            },
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
                'prefix' => '10.83279',
            ]);

        $response->assertOk();

        $resource->refresh();
        expect($resource->doi)->not->toBeNull()->toStartWith('10.83279/');
        expect($response->json('success.0.updated'))->toBeFalse();
    });

    test('fails items without DOI when no prefix is provided', function () {
        $resource = Resource::factory()->create(['doi' => null]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))
            ->toBe('Resource has no DOI. Provide a prefix to register a new DOI.');
    });

    test('rejects IGSN resources (must use IGSN batch endpoint)', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/IGSN-MIXED-001']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);
        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_UPLOADED,
            'sample_type' => 'Rock',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))
            ->toBe('IGSN resources must be registered via /igsns/batch-register');
    });

    test('reports failures for resources without landing pages', function () {
        $resource1 = Resource::factory()->create(['doi' => '10.83279/LP-YES']);
        $resource2 = Resource::factory()->create(['doi' => '10.83279/LP-NO']);
        LandingPage::factory()->create(['resource_id' => $resource1->id]);
        // resource2 has no landing page

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/LP-YES',
                    'type' => 'dois',
                    'attributes' => ['state' => 'findable'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource1->id, $resource2->id],
            ]);

        $response->assertStatus(207);
        $data = $response->json();
        expect($data['success'])->toHaveCount(1);
        expect($data['failed'])->toHaveCount(1);
        expect($data['failed'][0]['reason'])->toBe('No landing page configured');
    });

    test('isolates failures from successes', function () {
        $ok = Resource::factory()->create(['doi' => '10.83279/OK-001']);
        $bad = Resource::factory()->create(['doi' => null]);
        LandingPage::factory()->create(['resource_id' => $ok->id]);
        LandingPage::factory()->create(['resource_id' => $bad->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'data' => [
                    'id' => '10.83279/OK-001',
                    'type' => 'dois',
                    'attributes' => ['state' => 'findable'],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$ok->id, $bad->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('success'))->toHaveCount(1);
        expect($response->json('failed'))->toHaveCount(1);
    });

    test('validates request requires ids', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', []);

        $response->assertStatus(422);
    });

    test('rejects non-existent resource ids at validation', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [999999],
            ]);

        $response->assertStatus(422);
    });

    test('enforces maximum batch size of 25', function () {
        $ids = range(1, 26);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => $ids,
            ]);

        $response->assertStatus(422);
    });

    test('rejects batch registration for beginners', function () {
        $beginner = User::factory()->beginner()->create();
        $resource = Resource::factory()->create(['doi' => '10.83279/BEG-001']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $response = $this->actingAs($beginner)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(403);
    });

    test('requires authentication', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/AUTH-001']);

        $response = $this->postJson('/resources/batch-register', [
            'ids' => [$resource->id],
        ]);

        // Authenticated middleware redirects or 401/302 depending on config.
        expect($response->status())->toBeIn([302, 401, 403]);
    });

    test('handles DataCite API errors gracefully', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/FAIL-001']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'errors' => [
                    ['title' => 'Upstream error', 'detail' => 'Backend down'],
                ],
            ], 500),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))->toBe('Upstream error');
    });

    test('handles DataCite API error with detail-only error structure', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/DETAIL-ONLY']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([
                'errors' => [
                    ['detail' => 'Detail-level error message'],
                ],
            ], 422),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))->toBe('Detail-level error message');
    });

    test('falls back to generic message when DataCite response has no errors body', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/NO-BODY']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        Http::fake([
            '*datacite.org/*' => Http::response([], 503),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))
            ->toBe('Failed to communicate with DataCite API.');
    });

    test('tolerates non-JSON DataCite error responses without raising a 500', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/NON-JSON']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        // Upstream returns an HTML error page, not JSON — `response()->json()` returns
        // null. The handler must guard against that instead of dereferencing null.
        Http::fake([
            '*datacite.org/*' => Http::response(
                '<html><body>Bad Gateway</body></html>',
                502,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))
            ->toBe('Failed to communicate with DataCite API.');
    });

    test('treats empty prefix string as missing prefix for new DOI registration', function () {
        $resource = Resource::factory()->create(['doi' => null]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
                'prefix' => '',
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))
            ->toBe('Resource has no DOI. Provide a prefix to register a new DOI.');
    });

    test('reports InvalidArgumentException from the registration service as a failure', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/INVALID-001']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $mock = Mockery::mock(DataCiteRegistrationService::class);
        $mock->shouldReceive('updateMetadata')
            ->once()
            ->andThrow(new \InvalidArgumentException('Bad payload'));
        app()->instance(DataCiteRegistrationService::class, $mock);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))->toBe('Bad payload');
    });

    test('reports RuntimeException from the registration service as a failure', function () {
        $resource = Resource::factory()->create(['doi' => null]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $mock = Mockery::mock(DataCiteRegistrationService::class);
        $mock->shouldReceive('registerDoi')
            ->once()
            ->andThrow(new \RuntimeException('Service offline'));
        app()->instance(DataCiteRegistrationService::class, $mock);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
                'prefix' => '10.83279',
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))->toBe('Service offline');
    });

    test('hides unexpected errors from the user when app.debug is disabled', function () {
        config(['app.debug' => false]);

        $resource = Resource::factory()->create(['doi' => '10.83279/UNEXPECTED-001']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $mock = Mockery::mock(DataCiteRegistrationService::class);
        $mock->shouldReceive('updateMetadata')
            ->once()
            ->andThrow(new \LogicException('Internal stack trace details'));
        app()->instance(DataCiteRegistrationService::class, $mock);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))
            ->toBe('An unexpected error occurred during registration.');
    });

    test('exposes unexpected errors when app.debug is enabled', function () {
        config(['app.debug' => true]);

        $resource = Resource::factory()->create(['doi' => '10.83279/UNEXPECTED-002']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $mock = Mockery::mock(DataCiteRegistrationService::class);
        $mock->shouldReceive('updateMetadata')
            ->once()
            ->andThrow(new \LogicException('Detailed debug message'));
        app()->instance(DataCiteRegistrationService::class, $mock);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))->toBe('Detailed debug message');
    });

    test('persists a freshly-minted DOI when DataCite returns a different identifier', function () {
        $resource = Resource::factory()->create(['doi' => null]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $mock = Mockery::mock(DataCiteRegistrationService::class);
        $mock->shouldReceive('registerDoi')
            ->once()
            ->andReturn([
                'data' => [
                    'id' => '10.83279/SERVER-ASSIGNED-XYZ',
                    'type' => 'dois',
                    'attributes' => ['state' => 'findable'],
                ],
            ]);
        app()->instance(DataCiteRegistrationService::class, $mock);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
                'prefix' => '10.83279',
            ]);

        $response->assertOk();
        $resource->refresh();
        expect($resource->doi)->toBe('10.83279/SERVER-ASSIGNED-XYZ');
    });

    test('deduplicates repeated ids in the request payload', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/DEDUPE-001']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $mock = Mockery::mock(DataCiteRegistrationService::class);
        $mock->shouldReceive('updateMetadata')
            ->once() // important: only once even though we send the id twice
            ->andReturn([
                'data' => [
                    'id' => '10.83279/DEDUPE-001',
                    'type' => 'dois',
                    'attributes' => ['state' => 'findable'],
                ],
            ]);
        app()->instance(DataCiteRegistrationService::class, $mock);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id, $resource->id],
            ]);

        $response->assertOk();
        expect($response->json('success'))->toHaveCount(1);
    });

    // --- Issue #610: ORCID preflight ---
    test('ORCID preflight skips resources with confirmed-invalid ORCIDs', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/ORCID-BAD']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $person = \App\Models\Person::factory()->create([
            'given_name' => 'Bad',
            'family_name' => 'Author',
            'name_identifier' => '0000-0002-1825-0097',
            'name_identifier_scheme' => 'ORCID',
        ]);
        \App\Models\ResourceCreator::factory()
            ->forPerson($person)
            ->position(0)
            ->create(['resource_id' => $resource->id]);

        $this->mock(\App\Services\OrcidService::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('validateOrcid')
                ->andReturn([
                    'valid' => false,
                    'exists' => false,
                    'message' => 'Not found',
                    'errorType' => 'not_found',
                ]);
        });

        // DataCite must never be called when preflight blocks.
        $dataCite = Mockery::mock(DataCiteRegistrationService::class);
        $dataCite->shouldNotReceive('updateMetadata');
        $dataCite->shouldNotReceive('registerDoi');
        app()->instance(DataCiteRegistrationService::class, $dataCite);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('failed'))->toHaveCount(1);
        expect($response->json('failed.0.reason'))->toContain('ORCID preflight failed');
        expect($response->json('failed.0.reason'))->toContain('invalid ORCID');
    });

    test('ORCID preflight skips resources when ORCID service is unreachable (warnings)', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/ORCID-WARN']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $person = \App\Models\Person::factory()->create([
            'given_name' => 'Flaky',
            'family_name' => 'Network',
            'name_identifier' => '0000-0002-1825-0097',
            'name_identifier_scheme' => 'ORCID',
        ]);
        \App\Models\ResourceCreator::factory()
            ->forPerson($person)
            ->position(0)
            ->create(['resource_id' => $resource->id]);

        $this->mock(\App\Services\OrcidService::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('validateOrcid')
                ->andReturn([
                    'valid' => false,
                    'exists' => null,
                    'message' => 'Timeout',
                    'errorType' => 'timeout',
                ]);
        });

        $dataCite = Mockery::mock(DataCiteRegistrationService::class);
        $dataCite->shouldNotReceive('updateMetadata');
        app()->instance(DataCiteRegistrationService::class, $dataCite);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        expect($response->json('failed.0.reason'))->toContain('ORCID preflight skipped');
    });

    test('ORCID preflight reports combined invalid + warning counts', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/ORCID-MIX']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $badPerson = \App\Models\Person::factory()->create([
            'given_name' => 'Bad',
            'family_name' => 'One',
            'name_identifier' => '0000-0002-1825-0097',
            'name_identifier_scheme' => 'ORCID',
        ]);
        $flakyPerson = \App\Models\Person::factory()->create([
            'given_name' => 'Flaky',
            'family_name' => 'Two',
            'name_identifier' => '0000-0001-5109-3700',
            'name_identifier_scheme' => 'ORCID',
        ]);
        \App\Models\ResourceCreator::factory()->forPerson($badPerson)->position(0)
            ->create(['resource_id' => $resource->id]);
        \App\Models\ResourceCreator::factory()->forPerson($flakyPerson)->position(1)
            ->create(['resource_id' => $resource->id]);

        $this->mock(\App\Services\OrcidService::class, function (\Mockery\MockInterface $mock) {
            $mock->shouldReceive('validateOrcid')
                ->with('0000-0002-1825-0097')
                ->andReturn([
                    'valid' => false, 'exists' => false,
                    'message' => 'Not found', 'errorType' => 'not_found',
                ]);
            $mock->shouldReceive('validateOrcid')
                ->with('0000-0001-5109-3700')
                ->andReturn([
                    'valid' => false, 'exists' => null,
                    'message' => 'Timeout', 'errorType' => 'timeout',
                ]);
        });

        $dataCite = Mockery::mock(DataCiteRegistrationService::class);
        app()->instance(DataCiteRegistrationService::class, $dataCite);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-register', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(207);
        $reason = $response->json('failed.0.reason');
        expect($reason)->toContain('1 invalid');
        expect($reason)->toContain('1 unverifiable');
    });
});
