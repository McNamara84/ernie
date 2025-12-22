<?php

use App\Services\DataCiteImportService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Set up valid config for all tests
    Config::set('datacite.production', [
        'endpoint' => 'https://api.datacite.org',
        'username' => 'test-user',
        'password' => 'test-password',
        'client_id' => 'test.client',
        'prefixes' => ['10.5880'],
    ]);
});

describe('DataCiteImportService', function () {
    it('throws exception when endpoint is not HTTPS', function () {
        Config::set('datacite.production.endpoint', 'http://api.datacite.org');

        new DataCiteImportService();
    })->throws(RuntimeException::class, 'must use HTTPS');

    it('throws exception when client_id is missing', function () {
        Config::set('datacite.production.client_id', '');

        new DataCiteImportService();
    })->throws(RuntimeException::class, 'client_id is not configured');

    it('throws exception when credentials are missing', function () {
        Config::set('datacite.production.username', '');

        new DataCiteImportService();
    })->throws(RuntimeException::class, 'credentials are not configured');

    it('fetches total DOI count from API', function () {
        Http::fake([
            'api.datacite.org/dois*' => Http::response([
                'data' => [],
                'meta' => ['total' => 42],
            ], 200),
        ]);

        $service = new DataCiteImportService();
        $count = $service->getTotalDoiCount();

        expect($count)->toBe(42);
    });

    it('fetches DOIs with cursor pagination', function () {
        Http::fake([
            'api.datacite.org/dois*' => Http::sequence()
                ->push([
                    'data' => [
                        ['id' => '10.5880/test.1', 'attributes' => ['doi' => '10.5880/test.1']],
                        ['id' => '10.5880/test.2', 'attributes' => ['doi' => '10.5880/test.2']],
                    ],
                    'links' => ['next' => 'https://api.datacite.org/dois?page[cursor]=abc123'],
                ], 200)
                ->push([
                    'data' => [
                        ['id' => '10.5880/test.3', 'attributes' => ['doi' => '10.5880/test.3']],
                    ],
                    'links' => [],
                ], 200),
        ]);

        $service = new DataCiteImportService();
        $dois = iterator_to_array($service->fetchAllDois());

        expect($dois)->toHaveCount(3);
        expect($dois[0]['id'])->toBe('10.5880/test.1');
        expect($dois[2]['id'])->toBe('10.5880/test.3');
    });

    it('includes client-id in API requests', function () {
        Http::fake([
            'api.datacite.org/dois*' => Http::response([
                'data' => [],
                'meta' => ['total' => 0],
            ], 200),
        ]);

        $service = new DataCiteImportService();
        $service->getTotalDoiCount();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'client-id=test.client');
        });
    });

    it('retries on server errors', function () {
        $callCount = 0;

        Http::fake(function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                return Http::response(['error' => 'Server error'], 503);
            }

            return Http::response([
                'data' => [],
                'meta' => ['total' => 10],
            ], 200);
        });

        $service = new DataCiteImportService();
        $count = $service->getTotalDoiCount();

        expect($count)->toBe(10);
        expect($callCount)->toBe(3);
    });

    it('fetches single DOI by identifier', function () {
        Http::fake([
            'api.datacite.org/dois/10.5880*' => Http::response([
                'data' => [
                    'id' => '10.5880/test.123',
                    'attributes' => [
                        'doi' => '10.5880/test.123',
                        'titles' => [['title' => 'Test Dataset']],
                    ],
                ],
            ], 200),
        ]);

        $service = new DataCiteImportService();
        $doi = $service->fetchSingleDoi('10.5880/test.123');

        expect($doi)->not->toBeNull();
        expect($doi['attributes']['doi'])->toBe('10.5880/test.123');
    });

    it('returns null for non-existent DOI', function () {
        Http::fake([
            'api.datacite.org/dois/*' => Http::response(['errors' => []], 404),
        ]);

        $service = new DataCiteImportService();
        $doi = $service->fetchSingleDoi('10.5880/nonexistent');

        expect($doi)->toBeNull();
    });
});
