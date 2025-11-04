<?php

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

beforeEach(function () {
    withoutVite();

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

    $this->user = User::factory()->create();
    $this->resource = Resource::factory()->create([
        'doi' => null, // Explicitly set DOI to null for registration tests
        'created_by_user_id' => $this->user->id,
        'updated_by_user_id' => $this->user->id,
    ]);
});

test('doi registration requires authentication', function () {
    $response = $this->post(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279',
    ]);

    $response->assertRedirect(route('login'));
});

test('doi registration requires a landing page', function () {
    actingAs($this->user);

    $response = $this->post(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279',
    ]);

    $response->assertStatus(422);
    $response->assertJson([
        'error' => 'Landing page required',
    ]);
});

test('doi registration validates prefix against allowed list in test mode', function () {
    actingAs($this->user);

    // Create landing page
    LandingPage::factory()->create([
        'resource_id' => $this->resource->id,
        'status' => 'draft',
    ]);

    config(['datacite.test_mode' => true]);

    $response = $this->post(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.5880', // Production prefix, not allowed in test mode
    ]);

    $response->assertSessionHasErrors('prefix');
});

test('doi registration validates prefix against allowed list in production mode', function () {
    actingAs($this->user);

    LandingPage::factory()->create([
        'resource_id' => $this->resource->id,
        'status' => 'draft',
    ]);

    config(['datacite.test_mode' => false]);

    $response = $this->post(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279', // Test prefix, not allowed in production mode
    ]);

    $response->assertSessionHasErrors('prefix');
});

test('doi registration succeeds with valid data for new doi', function () {
    actingAs($this->user);

    LandingPage::factory()->create([
        'resource_id' => $this->resource->id,
        'status' => 'draft',
    ]);

    // Mock DataCite API response - use wildcard to catch any request
    Http::fake([
        '*datacite.org/*' => Http::response([
            'data' => [
                'id' => '10.83279/test-12345',
                'type' => 'dois',
                'attributes' => [
                    'doi' => '10.83279/test-12345',
                    'state' => 'findable',
                ],
            ],
        ], 201),
    ]);

    $response = $this->post(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279',
    ]);

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'doi' => '10.83279/test-12345',
    ]);

    // Verify DOI was saved to database
    $this->resource->refresh();
    expect($this->resource->doi)->toBe('10.83279/test-12345');
});

test('doi registration updates metadata for existing doi', function () {
    actingAs($this->user);

    // Set existing DOI
    $this->resource->update(['doi' => '10.83279/existing-doi']);

    LandingPage::factory()->create([
        'resource_id' => $this->resource->id,
        'status' => 'published',
    ]);

    // Mock DataCite API response for update - use wildcard
    Http::fake([
        '*datacite.org/*' => Http::response([
            'data' => [
                'id' => '10.83279/existing-doi',
                'type' => 'dois',
                'attributes' => [
                    'doi' => '10.83279/existing-doi',
                    'state' => 'findable',
                ],
            ],
        ], 200),
    ]);

    $response = $this->post(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279',
    ]);

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'updated' => true,
    ]);
    expect($response->json('message'))->toContain('metadata updated');
});

test('doi registration handles datacite api errors gracefully', function () {
    actingAs($this->user);

    LandingPage::factory()->create([
        'resource_id' => $this->resource->id,
        'status' => 'draft',
    ]);

    // Mock DataCite API error response - use wildcard
    Http::fake([
        '*datacite.org/*' => Http::response([
            'errors' => [
                [
                    'status' => '422',
                    'title' => 'Validation Error',
                    'detail' => 'Invalid metadata',
                ],
            ],
        ], 422),
    ]);

    $response = $this->post(route('resources.register-doi', ['resource' => $this->resource->id]), [
        'prefix' => '10.83279',
    ]);

    $response->assertStatus(422);
    $response->assertJsonStructure(['error', 'message']);
});

test('datacite prefixes endpoint returns correct prefixes in test mode', function () {
    actingAs($this->user);

    config([
        'datacite.test_mode' => true,
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
        'datacite.production.prefixes' => ['10.5880', '10.26026', '10.14470'],
    ]);

    $response = $this->get('/api/datacite/prefixes');

    $response->assertOk();
    $response->assertJson([
        'test' => ['10.83279', '10.83186', '10.83114'],
        'production' => ['10.5880', '10.26026', '10.14470'],
        'test_mode' => true,
    ]);
});

test('datacite prefixes endpoint returns correct prefixes in production mode', function () {
    actingAs($this->user);

    config([
        'datacite.test_mode' => false,
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
        'datacite.production.prefixes' => ['10.5880', '10.26026', '10.14470'],
    ]);

    $response = $this->get('/api/datacite/prefixes');

    $response->assertOk();
    $response->assertJson([
        'test' => ['10.83279', '10.83186', '10.83114'],
        'production' => ['10.5880', '10.26026', '10.14470'],
        'test_mode' => false,
    ]);
});

test('datacite prefixes endpoint requires authentication', function () {
    $response = $this->get('/api/datacite/prefixes');

    $response->assertRedirect(route('login'));
});
