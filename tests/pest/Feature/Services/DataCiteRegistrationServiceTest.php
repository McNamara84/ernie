<?php

use App\Models\LandingPage;
use App\Models\Resource;
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

    $this->resource = Resource::factory()->create([
        'doi' => null,
    ]);

    // Create landing page for the resource
    LandingPage::factory()->create([
        'resource_id' => $this->resource->id,
        'status' => 'draft',
    ]);
});

test('registerDoi generates new doi with correct format', function () {
    Http::fake([
        '*datacite.org/*' => Http::response([
            'data' => [
                'id' => '10.83279/abc123',
                'type' => 'dois',
                'attributes' => [
                    'doi' => '10.83279/abc123',
                    'state' => 'findable',
                ],
            ],
        ], 201),
    ]);

    $service = app(DataCiteRegistrationService::class);
    $response = $service->registerDoi($this->resource, '10.83279');

    expect($response)->toBeArray();
    expect($response['data']['id'])->toBe('10.83279/abc123');
    expect($response['data']['id'])->toStartWith('10.83279/');
    expect($response['data']['id'])->toMatch('/^10\.83279\/[a-z0-9]+$/');
});

test('registerDoi sends correct payload to datacite api', function () {
    Http::fake([
        '*datacite.org/*' => Http::response([
            'data' => [
                'id' => '10.83279/test',
                'type' => 'dois',
                'attributes' => [
                    'doi' => '10.83279/test',
                    'state' => 'findable',
                ],
            ],
        ], 201),
    ]);

    $service = app(DataCiteRegistrationService::class);
    $service->registerDoi($this->resource, '10.83279');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.test.datacite.org/dois')
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/vnd.api+json')
            && $request->hasHeader('Authorization');
    });
});

test('registerDoi includes basic auth credentials', function () {
    Http::fake([
        '*datacite.org/*' => Http::response([
            'data' => [
                'id' => '10.83279/test',
                'type' => 'dois',
                'attributes' => [
                    'doi' => '10.83279/test',
                    'state' => 'findable',
                ],
            ],
        ], 201),
    ]);

    $service = app(DataCiteRegistrationService::class);
    $service->registerDoi($this->resource, '10.83279');

    Http::assertSent(function ($request) {
        $auth = $request->header('Authorization')[0] ?? '';
        $expected = 'Basic '.base64_encode('TEST.USER:test-password');
        return $auth === $expected;
    });
});

test('registerDoi includes event publish parameter', function () {
    Http::fake([
        '*datacite.org/*' => Http::response([
            'data' => [
                'id' => '10.83279/test',
                'type' => 'dois',
                'attributes' => [
                    'doi' => '10.83279/test',
                    'state' => 'findable',
                ],
            ],
        ], 201),
    ]);

    $service = app(DataCiteRegistrationService::class);
    $service->registerDoi($this->resource, '10.83279');

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);
        return isset($body['data']['attributes']['event'])
            && $body['data']['attributes']['event'] === 'publish';
    });
});

test('updateMetadata sends put request to correct endpoint', function () {
    $this->resource->update(['doi' => '10.83279/existing']);

    Http::fake([
        '*datacite.org/*' => Http::response([
            'data' => [
                'id' => '10.83279/existing',
                'type' => 'dois',
                'attributes' => [
                    'doi' => '10.83279/existing',
                    'state' => 'findable',
                ],
            ],
        ], 200),
    ]);

    $service = app(DataCiteRegistrationService::class);
    $service->updateMetadata($this->resource);

    Http::assertSent(function ($request) {
        // DOI is now URL-encoded in the path
        $expectedEncodedDoi = urlencode('10.83279/existing');
        return str_contains($request->url(), $expectedEncodedDoi)
            && $request->method() === 'PUT';
    });
});

test('updateMetadata throws exception when resource has no doi', function () {
    $service = app(DataCiteRegistrationService::class);
    expect(fn () => $service->updateMetadata($this->resource))
        ->toThrow(\Exception::class, 'must have a DOI');
});

test('registerDoi retries on network failure', function () {
    Http::fake([
        '*datacite.org/*' => Http::sequence()
            ->push('Network error', 500)
            ->push('Network error', 500)
            ->push([
                'data' => [
                    'id' => '10.83279/retry-success',
                    'type' => 'dois',
                    'attributes' => [
                        'doi' => '10.83279/retry-success',
                        'state' => 'findable',
                    ],
                ],
            ], 201),
    ]);

    $service = app(DataCiteRegistrationService::class);
    $response = $service->registerDoi($this->resource, '10.83279');

    expect($response['data']['id'])->toBe('10.83279/retry-success');
});

test('registerDoi uses production credentials when test mode is disabled', function () {
    Http::fake([
        '*datacite.org/*' => Http::response([
            'data' => [
                'id' => '10.5880/test',
                'type' => 'dois',
                'attributes' => [
                    'doi' => '10.5880/test',
                    'state' => 'findable',
                ],
            ],
        ], 201),
    ]);

    config([
        'datacite.test_mode' => false,
    ]);

    // Recreate service with updated config
    $service = app(DataCiteRegistrationService::class);
    $service->registerDoi($this->resource, '10.5880');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.datacite.org')
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('PROD.USER:prod-password');
    });
});

test('registerDoi handles datacite error responses', function () {
    Http::fake([
        '*datacite.org/*' => Http::response([
            'errors' => [
                [
                    'status' => '422',
                    'title' => 'Validation failed',
                    'detail' => 'Title is required',
                ],
            ],
        ], 422),
    ]);

    $service = app(DataCiteRegistrationService::class);
    expect(fn () => $service->registerDoi($this->resource, '10.83279'))
        ->toThrow(\Exception::class);
});

test('updateMetadata handles datacite error responses', function () {
    $this->resource->update(['doi' => '10.83279/error-test']);

    Http::fake([
        '*datacite.org/*' => Http::response([
            'errors' => [
                [
                    'status' => '404',
                    'title' => 'Not found',
                    'detail' => 'DOI not found',
                ],
            ],
        ], 404),
    ]);

    $service = app(DataCiteRegistrationService::class);
    expect(fn () => $service->updateMetadata($this->resource))
        ->toThrow(\Exception::class);
});
