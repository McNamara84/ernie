<?php

declare(strict_types=1);

use App\Services\GemetApiService;
use Illuminate\Support\Facades\Http;

covers(GemetApiService::class);

describe('fetchSuperGroups', function (): void {
    test('fetches and parses super groups', function (): void {
        Http::fake([
            'www.eionet.europa.eu/gemet/getTopmostConcepts*' => Http::response([
                [
                    'uri' => 'http://www.eionet.europa.eu/gemet/supergroup/1234',
                    'preferredLabel' => ['string' => 'Air', 'language' => 'en'],
                    'definition' => ['string' => 'Air related concepts', 'language' => 'en'],
                ],
            ]),
        ]);

        $service = new GemetApiService;
        $result = $service->fetchSuperGroups();

        expect($result)->toHaveCount(1);
        expect($result[0])->toHaveKeys(['uri', 'label', 'definition']);
    });

    test('throws on API failure', function (): void {
        Http::fake([
            'www.eionet.europa.eu/gemet/getTopmostConcepts*' => Http::response(null, 500),
        ]);

        $service = new GemetApiService;
        $service->fetchSuperGroups();
    })->throws(RuntimeException::class);
});

describe('fetchGroups', function (): void {
    test('fetches and parses groups', function (): void {
        Http::fake([
            'www.eionet.europa.eu/gemet/getTopmostConcepts*' => Http::response([
                [
                    'uri' => 'http://www.eionet.europa.eu/gemet/group/5678',
                    'preferredLabel' => ['string' => 'Soil', 'language' => 'en'],
                    'definition' => ['string' => 'Soil related concepts', 'language' => 'en'],
                ],
            ]),
        ]);

        $service = new GemetApiService;
        $result = $service->fetchGroups();

        expect($result)->toHaveCount(1);
        expect($result[0]['label'])->toBe('Soil');
    });

    test('throws on non-array response', function (): void {
        Http::fake([
            'www.eionet.europa.eu/gemet/getTopmostConcepts*' => Http::response('not json'),
        ]);

        $service = new GemetApiService;
        $service->fetchGroups();
    })->throws(RuntimeException::class);
});
