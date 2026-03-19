<?php

declare(strict_types=1);

use App\Services\ArdcApiService;
use Illuminate\Support\Facades\Http;

covers(ArdcApiService::class);

describe('fetchAllItems', function (): void {
    test('fetches items from single page', function (): void {
        Http::fake([
            'vocabs.ardc.edu.au/*' => Http::response([
                'result' => [
                    'items' => [
                        ['_about' => 'http://example.com/1', 'prefLabel' => ['_value' => 'Quaternary']],
                        ['_about' => 'http://example.com/2', 'prefLabel' => ['_value' => 'Jurassic']],
                    ],
                    // No 'next' key = single page
                ],
            ]),
        ]);

        $service = new ArdcApiService;
        $result = $service->fetchAllItems();

        expect($result)->toHaveCount(2);
    });

    test('fetches items from multiple pages', function (): void {
        Http::fakeSequence('vocabs.ardc.edu.au/*')
            ->push([
                'result' => [
                    'items' => [
                        ['_about' => 'http://example.com/1'],
                    ],
                    'next' => 'http://next-page',
                ],
            ])
            ->push([
                'result' => [
                    'items' => [
                        ['_about' => 'http://example.com/2'],
                    ],
                    // No 'next' = last page
                ],
            ]);

        $service = new ArdcApiService;
        $result = $service->fetchAllItems();

        expect($result)->toHaveCount(2);
    });

    test('throws on API failure', function (): void {
        Http::fake([
            'vocabs.ardc.edu.au/*' => Http::response(null, 500),
        ]);

        $service = new ArdcApiService;
        $service->fetchAllItems();
    })->throws(RuntimeException::class);

    test('throws on unexpected format', function (): void {
        Http::fake([
            'vocabs.ardc.edu.au/*' => Http::response(['result' => ['no_items_key' => true]]),
        ]);

        $service = new ArdcApiService;
        $service->fetchAllItems();
    })->throws(RuntimeException::class);
});
