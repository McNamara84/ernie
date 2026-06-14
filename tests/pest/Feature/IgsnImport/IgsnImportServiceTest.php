<?php

use App\Services\IgsnImportService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Config::set('datacite.production', [
        'endpoint' => 'https://api.datacite.org',
        'username' => 'test-user',
        'password' => 'test-password',
        'client_id' => 'tib.gfz',
        'prefixes' => ['10.5880'],
        'igsn_prefix' => '10.60510',
        'igsn_client_id' => 'gfz.igsn',
    ]);
});

describe('IgsnImportService', function () {
    it('throws exception when endpoint is not HTTPS', function () {
        Config::set('datacite.production.endpoint', 'http://api.datacite.org');

        new IgsnImportService;
    })->throws(RuntimeException::class, 'must use HTTPS');

    it('throws exception when IGSN prefix is missing', function () {
        Config::set('datacite.production.igsn_prefix', '');

        new IgsnImportService;
    })->throws(RuntimeException::class, 'IGSN prefix is not configured');

    it('throws exception when IGSN client ID is missing', function () {
        Config::set('datacite.production.igsn_client_id', '');

        new IgsnImportService;
    })->throws(RuntimeException::class, 'IGSN client ID is not configured');

    it('throws exception when credentials are missing', function () {
        Config::set('datacite.production.username', '');
        Config::set('datacite.production.password', '');

        new IgsnImportService;
    })->throws(RuntimeException::class, 'credentials are not configured');

    it('fetches total IGSN count from API', function () {
        Http::fake([
            'api.datacite.org/dois*' => Http::response([
                'data' => [],
                'meta' => ['total' => 38525],
            ], 200),
        ]);

        $service = new IgsnImportService;
        $count = $service->getTotalIgsnCount();

        expect($count)->toBe(38525);
    });

    it('returns 0 when count request fails', function () {
        Http::fake([
            'api.datacite.org/dois*' => Http::response([], 500),
        ]);

        $service = new IgsnImportService;
        $count = $service->getTotalIgsnCount();

        expect($count)->toBe(0);
    });

    it('sends correct query parameters for IGSN fetch', function () {
        Http::fake([
            'api.datacite.org/dois*' => Http::response([
                'data' => [],
                'meta' => ['total' => 0],
            ], 200),
        ]);

        $service = new IgsnImportService;
        $service->getTotalIgsnCount();

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'client-id=gfz.igsn')
                && str_contains($url, 'prefix=10.60510');
        });
    });

    it('fetches a single page of IGSNs', function () {
        Http::fake([
            'api.datacite.org/dois*' => Http::response([
                'data' => [
                    ['id' => '10.60510/GFTEST001', 'attributes' => ['doi' => '10.60510/GFTEST001']],
                    ['id' => '10.60510/GFTEST002', 'attributes' => ['doi' => '10.60510/GFTEST002']],
                ],
                'links' => [],
            ], 200),
        ]);

        $service = new IgsnImportService;
        $result = $service->fetchIgsnPage('1', 100);

        expect($result['data'])->toHaveCount(2);
        expect($result['next_cursor'])->toBeNull();
    });

    it('fetches a single IGSN by DOI', function () {
        Http::fake([
            'api.datacite.org/dois/*' => Http::response([
                'data' => [
                    'id' => '10.60510/icdp5052euyy001',
                    'attributes' => ['doi' => '10.60510/icdp5052euyy001'],
                ],
            ], 200),
        ]);

        $service = new IgsnImportService;
        $result = $service->fetchSingleIgsn('10.60510/ICDP5052EUYY001');

        expect($result)->not->toBeNull();
        expect($result['attributes']['doi'])->toBe('10.60510/icdp5052euyy001');
    });

    it('returns null when a single IGSN is not found', function () {
        Http::fake([
            'api.datacite.org/dois/*' => Http::response([], 404),
        ]);

        $service = new IgsnImportService;
        $result = $service->fetchSingleIgsn('10.60510/MISSING001');

        expect($result)->toBeNull();
    });

    it('returns null without an HTTP request for invalid single IGSN DOI input', function () {
        Http::fake();

        $service = new IgsnImportService;
        $result = $service->fetchSingleIgsn('10.99999/not-an-igsn');

        expect($result)->toBeNull();
        Http::assertNothingSent();
    });

    it('returns null for non-404 single IGSN API errors', function () {
        Http::fake([
            'api.datacite.org/dois/*' => Http::response(['errors' => [['title' => 'Server error']]], 500),
        ]);

        $service = new IgsnImportService;
        $result = $service->fetchSingleIgsn('10.60510/ICDP5052EUYY001');

        expect($result)->toBeNull();
    });

    it('returns null when a single IGSN request throws an exception', function () {
        Http::fake([
            'api.datacite.org/dois/*' => fn () => throw new RuntimeException('DataCite unavailable'),
        ]);

        $service = new IgsnImportService;
        $result = $service->fetchSingleIgsn('10.60510/ICDP5052EUYY001');

        expect($result)->toBeNull();
    });

    it('exposes the configured IGSN prefix', function () {
        $service = new IgsnImportService;

        expect($service->getIgsnPrefix())->toBe('10.60510');
    });

    it('extracts next cursor from pagination links', function () {
        Http::fake([
            'api.datacite.org/dois*' => Http::response([
                'data' => [
                    ['id' => '10.60510/GFTEST001', 'attributes' => ['doi' => '10.60510/GFTEST001']],
                ],
                'links' => [
                    'next' => 'https://api.datacite.org/dois?page[cursor]=abc123&page[size]=100',
                ],
            ], 200),
        ]);

        $service = new IgsnImportService;
        $result = $service->fetchIgsnPage('1', 100);

        expect($result['next_cursor'])->toBe('abc123');
    });

    it('uses generator to fetch all IGSNs with pagination', function () {
        Http::fake([
            'api.datacite.org/dois*' => Http::sequence()
                ->push([
                    'data' => [
                        ['id' => '10.60510/GFTEST001', 'attributes' => ['doi' => '10.60510/GFTEST001']],
                    ],
                    'links' => [
                        'next' => 'https://api.datacite.org/dois?page[cursor]=cursor2&page[size]=100',
                    ],
                ], 200)
                ->push([
                    'data' => [
                        ['id' => '10.60510/GFTEST002', 'attributes' => ['doi' => '10.60510/GFTEST002']],
                    ],
                    'links' => [],
                ], 200),
        ]);

        $service = new IgsnImportService;
        $results = iterator_to_array($service->fetchAllIgsns());

        expect($results)->toHaveCount(2);
        expect($results[0]['id'])->toBe('10.60510/GFTEST001');
        expect($results[1]['id'])->toBe('10.60510/GFTEST002');
    });

    it('caps page size at maximum 1000', function () {
        Http::fake([
            'api.datacite.org/dois*' => Http::response([
                'data' => [],
                'links' => [],
            ], 200),
        ]);

        $service = new IgsnImportService;
        $service->fetchIgsnPage('1', 5000);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'page%5Bsize%5D=1000') || str_contains($url, 'page[size]=1000');
        });
    });

    it('stops fetching when API returns error', function () {
        Http::fake([
            'api.datacite.org/dois*' => Http::response([], 500),
        ]);

        $service = new IgsnImportService;
        $results = iterator_to_array($service->fetchAllIgsns());

        expect($results)->toHaveCount(0);
    });
});
