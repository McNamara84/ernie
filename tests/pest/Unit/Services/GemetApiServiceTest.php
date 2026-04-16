<?php

declare(strict_types=1);

use App\Services\GemetApiService;
use Illuminate\Http\Client\Request;
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

describe('fetchGroupToSuperGroupMapping', function (): void {
    test('normalizes group URI prefix to supergroup URI prefix', function (): void {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getRelatedConcepts')) {
                return Http::response([
                    'uri' => 'http://www.eionet.europa.eu/gemet/group/1234',
                    'preferredLabel' => ['string' => 'Parent', 'language' => 'en'],
                ]);
            }

            return Http::response([], 404);
        });

        $service = new GemetApiService;
        $groups = [
            ['uri' => 'http://www.eionet.europa.eu/gemet/group/5678', 'label' => 'Test', 'definition' => ''],
        ];

        $mapping = $service->fetchGroupToSuperGroupMapping($groups);

        expect($mapping)->toHaveKey('http://www.eionet.europa.eu/gemet/group/5678')
            ->and($mapping['http://www.eionet.europa.eu/gemet/group/5678'])
            ->toBe('http://www.eionet.europa.eu/gemet/supergroup/1234');
    });

    test('preserves URIs already using supergroup prefix', function (): void {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getRelatedConcepts')) {
                return Http::response([
                    'uri' => 'http://www.eionet.europa.eu/gemet/supergroup/1234',
                    'preferredLabel' => ['string' => 'Parent', 'language' => 'en'],
                ]);
            }

            return Http::response([], 404);
        });

        $service = new GemetApiService;
        $groups = [
            ['uri' => 'http://www.eionet.europa.eu/gemet/group/5678', 'label' => 'Test', 'definition' => ''],
        ];

        $mapping = $service->fetchGroupToSuperGroupMapping($groups);

        expect($mapping['http://www.eionet.europa.eu/gemet/group/5678'])
            ->toBe('http://www.eionet.europa.eu/gemet/supergroup/1234');
    });

    test('maps multiple groups to their parent supergroups', function (): void {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getRelatedConcepts')) {
                $conceptUri = $request->data()['concept_uri'] ?? '';

                return match ($conceptUri) {
                    'http://www.eionet.europa.eu/gemet/group/100' => Http::response([
                        'uri' => 'http://www.eionet.europa.eu/gemet/group/2894',
                        'preferredLabel' => ['string' => 'Parent A', 'language' => 'en'],
                    ]),
                    'http://www.eionet.europa.eu/gemet/group/200' => Http::response([
                        'uri' => 'http://www.eionet.europa.eu/gemet/group/4044',
                        'preferredLabel' => ['string' => 'Parent B', 'language' => 'en'],
                    ]),
                    default => Http::response([], 404),
                };
            }

            return Http::response([], 404);
        });

        $service = new GemetApiService;
        $groups = [
            ['uri' => 'http://www.eionet.europa.eu/gemet/group/100', 'label' => 'G1', 'definition' => ''],
            ['uri' => 'http://www.eionet.europa.eu/gemet/group/200', 'label' => 'G2', 'definition' => ''],
        ];

        $mapping = $service->fetchGroupToSuperGroupMapping($groups);

        expect($mapping)->toHaveCount(2)
            ->and($mapping['http://www.eionet.europa.eu/gemet/group/100'])
            ->toBe('http://www.eionet.europa.eu/gemet/supergroup/2894')
            ->and($mapping['http://www.eionet.europa.eu/gemet/group/200'])
            ->toBe('http://www.eionet.europa.eu/gemet/supergroup/4044');
    });

    test('handles empty broader response gracefully', function (): void {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getRelatedConcepts')) {
                return Http::response([], 200);
            }

            return Http::response([], 404);
        });

        $service = new GemetApiService;
        $groups = [
            ['uri' => 'http://www.eionet.europa.eu/gemet/group/5678', 'label' => 'Test', 'definition' => ''],
        ];

        $mapping = $service->fetchGroupToSuperGroupMapping($groups);

        expect($mapping)->toBeEmpty();
    });

    test('throws on API failure', function (): void {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getRelatedConcepts')) {
                return Http::response(null, 500);
            }

            return Http::response([], 404);
        });

        $service = new GemetApiService;
        $groups = [
            ['uri' => 'http://www.eionet.europa.eu/gemet/group/5678', 'label' => 'Test', 'definition' => ''],
        ];

        $service->fetchGroupToSuperGroupMapping($groups);
    })->throws(RuntimeException::class);
});
