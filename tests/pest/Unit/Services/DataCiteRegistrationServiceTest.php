<?php

use App\Models\Resource;
use App\Services\DataCiteRegistrationService;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->resource = Resource::factory()->create([
        'doi' => null,
    ]);
});

test('registerDoi generates new doi with correct format', function () {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
    ]);

    $service = app(DataCiteRegistrationService::class);

    Http::fake([
        'api.test.datacite.org/*' => Http::response([
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

    $doi = $service->registerDoi($this->resource, '10.83279');

    expect($doi)->toStartWith('10.83279/');
    expect($doi)->toMatch('/^10\.83279\/[a-z0-9]+$/');
});

test('registerDoi sends correct payload to datacite api', function () {
    Http::fake();

    try {
        $this->service->registerDoi($this->resource, '10.83279');
    } catch (\Exception $e) {
        // Expected to fail without proper resource metadata, but we can check the request
    }

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.test.datacite.org/dois'
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/vnd.api+json')
            && $request->hasHeader('Authorization');
    });
});

test('registerDoi includes basic auth credentials', function () {
    Http::fake();

    try {
        $this->service->registerDoi($this->resource, '10.83279');
    } catch (\Exception $e) {
        // Expected to fail
    }

    Http::assertSent(function ($request) {
        $auth = $request->header('Authorization')[0] ?? '';
        $expected = 'Basic '.base64_encode('TEST.USER:test-password');
        return $auth === $expected;
    });
});

test('registerDoi includes event publish parameter', function () {
    Http::fake();

    try {
        $this->service->registerDoi($this->resource, '10.83279');
    } catch (\Exception $e) {
        // Expected to fail
    }

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);
        return isset($body['data']['attributes']['event'])
            && $body['data']['attributes']['event'] === 'publish';
    });
});

test('updateMetadata sends put request to correct endpoint', function () {
    $this->resource->update(['doi' => '10.83279/existing']);

    Http::fake([
        'api.test.datacite.org/dois/10.83279/existing' => Http::response([
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

    $this->service->updateMetadata($this->resource);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.test.datacite.org/dois/10.83279/existing'
            && $request->method() === 'PUT';
    });
});

test('updateMetadata throws exception when resource has no doi', function () {
    expect(fn () => $this->service->updateMetadata($this->resource))
        ->toThrow(\Exception::class, 'Resource must have a DOI');
});

test('registerDoi retries on network failure', function () {
    Http::fake([
        'api.test.datacite.org/*' => Http::sequence()
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

    $doi = $this->service->registerDoi($this->resource, '10.83279');

    expect($doi)->toBe('10.83279/retry-success');
});

test('registerDoi uses production credentials when test mode is disabled', function () {
    config([
        'datacite.test_mode' => false,
    ]);

    // Recreate service with updated config
    $this->service = app(DataCiteRegistrationService::class);

    Http::fake();

    try {
        $this->service->registerDoi($this->resource, '10.5880');
    } catch (\Exception $e) {
        // Expected to fail
    }

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'https://api.datacite.org')
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('PROD.USER:prod-password');
    });
});

test('registerDoi handles datacite error responses', function () {
    Http::fake([
        'api.test.datacite.org/*' => Http::response([
            'errors' => [
                [
                    'status' => '422',
                    'title' => 'Validation failed',
                    'detail' => 'Title is required',
                ],
            ],
        ], 422),
    ]);

    expect(fn () => $this->service->registerDoi($this->resource, '10.83279'))
        ->toThrow(\Exception::class);
});

test('updateMetadata handles datacite error responses', function () {
    $this->resource->update(['doi' => '10.83279/error-test']);

    Http::fake([
        'api.test.datacite.org/dois/10.83279/error-test' => Http::response([
            'errors' => [
                [
                    'status' => '404',
                    'title' => 'Not found',
                    'detail' => 'DOI not found',
                ],
            ],
        ], 404),
    ]);

    expect(fn () => $this->service->updateMetadata($this->resource))
        ->toThrow(\Exception::class);
});
