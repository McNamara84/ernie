<?php

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Configure DataCite test mode
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-password',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279', '10.83186', '10.83114'],
    ]);
});

describe('DataCiteSyncService', function () {
    describe('syncIfRegistered', function () {
        test('returns notRequired when resource has no DOI', function () {
            $resource = Resource::factory()->create(['doi' => null]);

            $service = app(DataCiteSyncService::class);
            $result = $service->syncIfRegistered($resource);

            expect($result)->toBeInstanceOf(DataCiteSyncResult::class);
            expect($result->attempted)->toBeFalse();
            expect($result->success)->toBeTrue();
            expect($result->doi)->toBeNull();
        });

        test('returns notRequired when resource has empty DOI string', function () {
            $resource = Resource::factory()->create(['doi' => '']);

            $service = app(DataCiteSyncService::class);
            $result = $service->syncIfRegistered($resource);

            expect($result->attempted)->toBeFalse();
            expect($result->success)->toBeTrue();
        });

        test('returns failed when resource has DOI but no landing page', function () {
            $resource = Resource::factory()->create([
                'doi' => '10.5880/GFZ.1.2024.001',
            ]);
            // No landing page created

            $service = app(DataCiteSyncService::class);
            $result = $service->syncIfRegistered($resource);

            expect($result->attempted)->toBeTrue();
            expect($result->success)->toBeFalse();
            expect($result->errorMessage)->toContain('Landing page');
            expect($result->doi)->toBe('10.5880/GFZ.1.2024.001');
        });

        test('returns succeeded when DOI update succeeds', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $resource = Resource::factory()->create(['doi' => $doi]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'is_published' => true,
            ]);

            Http::fake([
                '*datacite.org/*' => Http::response([
                    'data' => [
                        'id' => $doi,
                        'type' => 'dois',
                        'attributes' => [
                            'doi' => $doi,
                            'state' => 'findable',
                        ],
                    ],
                ], 200),
            ]);

            $service = app(DataCiteSyncService::class);
            $result = $service->syncIfRegistered($resource);

            expect($result->attempted)->toBeTrue();
            expect($result->success)->toBeTrue();
            expect($result->errorMessage)->toBeNull();
            expect($result->doi)->toBe($doi);
        });

        test('returns failed when DataCite API returns error', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $resource = Resource::factory()->create(['doi' => $doi]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'is_published' => true,
            ]);

            Http::fake([
                '*datacite.org/*' => Http::response([
                    'errors' => [
                        ['title' => 'Validation failed', 'detail' => 'Invalid metadata'],
                    ],
                ], 422),
            ]);

            $service = app(DataCiteSyncService::class);
            $result = $service->syncIfRegistered($resource);

            expect($result->attempted)->toBeTrue();
            expect($result->success)->toBeFalse();
            expect($result->errorMessage)->toBe('Validation failed');
            expect($result->doi)->toBe($doi);
        });

        test('returns failed with timeout message when API is unreachable', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $resource = Resource::factory()->create(['doi' => $doi]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'is_published' => true,
            ]);

            Http::fake([
                '*datacite.org/*' => Http::response([], 500),
            ]);

            $service = app(DataCiteSyncService::class);
            $result = $service->syncIfRegistered($resource);

            expect($result->attempted)->toBeTrue();
            expect($result->success)->toBeFalse();
            expect($result->errorMessage)->toContain('temporarily unavailable');
            expect($result->doi)->toBe($doi);
        });

        test('returns user-friendly message for 401 authentication error', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $resource = Resource::factory()->create(['doi' => $doi]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'is_published' => true,
            ]);

            Http::fake([
                '*datacite.org/*' => Http::response([], 401),
            ]);

            $service = app(DataCiteSyncService::class);
            $result = $service->syncIfRegistered($resource);

            expect($result->success)->toBeFalse();
            expect($result->errorMessage)->toContain('authentication failed');
        });

        test('returns user-friendly message for 404 DOI not found', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $resource = Resource::factory()->create(['doi' => $doi]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'is_published' => true,
            ]);

            Http::fake([
                '*datacite.org/*' => Http::response([], 404),
            ]);

            $service = app(DataCiteSyncService::class);
            $result = $service->syncIfRegistered($resource);

            expect($result->success)->toBeFalse();
            expect($result->errorMessage)->toContain('not found');
        });

        test('returns user-friendly message for 429 rate limiting', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $resource = Resource::factory()->create(['doi' => $doi]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'is_published' => true,
            ]);

            Http::fake([
                '*datacite.org/*' => Http::response([], 429),
            ]);

            $service = app(DataCiteSyncService::class);
            $result = $service->syncIfRegistered($resource);

            expect($result->success)->toBeFalse();
            expect($result->errorMessage)->toContain('Too many requests');
        });
    });

    describe('API interaction', function () {
        test('sends PUT request to correct DataCite endpoint', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $resource = Resource::factory()->create(['doi' => $doi]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'is_published' => true,
            ]);

            Http::fake([
                '*datacite.org/*' => Http::response([
                    'data' => ['id' => $doi],
                ], 200),
            ]);

            $service = app(DataCiteSyncService::class);
            $service->syncIfRegistered($resource);

            Http::assertSent(function ($request) use ($doi) {
                $encodedDoi = urlencode($doi);

                return str_contains($request->url(), "dois/{$encodedDoi}")
                    && $request->method() === 'PUT';
            });
        });

        test('does not make API call when resource has no DOI', function () {
            $resource = Resource::factory()->create(['doi' => null]);

            Http::fake();

            $service = app(DataCiteSyncService::class);
            $service->syncIfRegistered($resource);

            Http::assertNothingSent();
        });

        test('does not make API call when resource has no landing page', function () {
            $resource = Resource::factory()->create(['doi' => '10.5880/test']);
            // No landing page

            Http::fake();

            $service = app(DataCiteSyncService::class);
            $service->syncIfRegistered($resource);

            Http::assertNothingSent();
        });
    });

    describe('never throws exceptions', function () {
        test('catches API errors and returns failed result instead of throwing', function () {
            $doi = '10.5880/GFZ.1.2024.001';
            $resource = Resource::factory()->create(['doi' => $doi]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'is_published' => true,
            ]);

            Http::fake([
                '*datacite.org/*' => Http::response([], 500),
            ]);

            $service = app(DataCiteSyncService::class);

            // Should not throw, should return failed result
            $result = $service->syncIfRegistered($resource);

            expect($result)->toBeInstanceOf(DataCiteSyncResult::class);
            expect($result->hasFailed())->toBeTrue();
        });
    });
});
