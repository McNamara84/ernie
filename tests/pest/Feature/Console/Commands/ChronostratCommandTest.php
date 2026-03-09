<?php

declare(strict_types=1);

use App\Console\Commands\GetChronostratTimescale;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(GetChronostratTimescale::class);

// =========================================================================
// handle() – success & failure paths
// =========================================================================

describe('handle', function (): void {
    it('fetches, parses, and stores chronostrat data on success', function (): void {
        Storage::fake('local');

        $page0Items = [
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Phanerozoic',
                'prefLabel' => ['_value' => 'Phanerozoic', '_lang' => 'en'],
                'broader' => [],
            ],
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Mesozoic',
                'prefLabel' => ['_value' => 'Mesozoic', '_lang' => 'en'],
                'broader' => 'http://resource.geosciml.org/classifier/ics/ischart/Phanerozoic',
            ],
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Jurassic',
                'prefLabel' => ['_value' => 'Jurassic', '_lang' => 'en'],
                'broader' => 'http://resource.geosciml.org/classifier/ics/ischart/Mesozoic',
            ],
            // Boundary concept – should be filtered out
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/BaseJurassic',
                'prefLabel' => ['_value' => 'Base of Jurassic', '_lang' => 'en'],
                'broader' => 'http://resource.geosciml.org/classifier/ics/ischart/Jurassic',
            ],
            // GSSP concept – should be filtered out
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/GSSPJurassic',
                'prefLabel' => ['_value' => 'GSSP for Base of Jurassic', '_lang' => 'en'],
                'broader' => 'http://resource.geosciml.org/classifier/ics/ischart/Jurassic',
            ],
        ];

        Http::fake([
            'vocabs.ardc.edu.au/*_page=0*' => Http::response([
                'result' => [
                    'items' => $page0Items,
                    // No 'next' key → single page
                ],
            ], 200),
        ]);

        $this->artisan('get-chronostrat-timescale')
            ->assertExitCode(0);

        Storage::assertExists('chronostrat-timescale.json');

        $json = json_decode(Storage::get('chronostrat-timescale.json'), true);

        expect($json)->toHaveKeys(['lastUpdated', 'data'])
            // Root should be Phanerozoic only (3 interval concepts, boundaries filtered)
            ->and($json['data'])->toHaveCount(1)
            ->and($json['data'][0]['text'])->toBe('Phanerozoic')
            ->and($json['data'][0]['scheme'])->toBe('International Chronostratigraphic Chart')
            ->and($json['data'][0]['children'])->toHaveCount(1)
            ->and($json['data'][0]['children'][0]['text'])->toBe('Mesozoic')
            ->and($json['data'][0]['children'][0]['children'])->toHaveCount(1)
            ->and($json['data'][0]['children'][0]['children'][0]['text'])->toBe('Jurassic')
            // Boundary concepts should NOT appear as children of Jurassic
            ->and($json['data'][0]['children'][0]['children'][0]['children'])->toBeEmpty();
    });

    it('handles multi-page responses correctly', function (): void {
        Storage::fake('local');

        // Page 0: 200 items (triggers next page fetch)
        $page0Items = array_map(fn (int $i) => [
            '_about' => "http://resource.geosciml.org/classifier/ics/ischart/Concept{$i}",
            'prefLabel' => ['_value' => "Concept {$i}", '_lang' => 'en'],
            'broader' => [],
        ], range(1, 200));

        // Page 1: final page with 1 item
        $page1Items = [
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Concept201',
                'prefLabel' => ['_value' => 'Concept 201', '_lang' => 'en'],
                'broader' => [],
            ],
        ];

        Http::fake([
            'vocabs.ardc.edu.au/*_page=0*' => Http::response([
                'result' => [
                    'items' => $page0Items,
                    'next' => 'http://vocabs.ardc.edu.au/...?_page=1',
                ],
            ], 200),
            'vocabs.ardc.edu.au/*_page=1*' => Http::response([
                'result' => [
                    'items' => $page1Items,
                ],
            ], 200),
        ]);

        $this->artisan('get-chronostrat-timescale')
            ->assertExitCode(0);

        Storage::assertExists('chronostrat-timescale.json');

        $json = json_decode(Storage::get('chronostrat-timescale.json'), true);

        // All 201 concepts should be present as root nodes (no parent relationships)
        expect($json['data'])->toHaveCount(201);
    });

    it('returns failure exit code on API error', function (): void {
        Http::fake([
            'vocabs.ardc.edu.au/*' => Http::response('Server Error', 500),
        ]);

        $this->artisan('get-chronostrat-timescale')
            ->assertExitCode(1);
    });

    it('returns failure on invalid API response format', function (): void {
        Http::fake([
            'vocabs.ardc.edu.au/*' => Http::response('<html>Gateway Timeout</html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $this->artisan('get-chronostrat-timescale')
            ->assertExitCode(1);
    });

    it('filters out concepts without English labels', function (): void {
        Storage::fake('local');

        $items = [
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Cambrian',
                'prefLabel' => ['_value' => 'Cambrian', '_lang' => 'en'],
                'broader' => [],
            ],
            // French-only label – should be filtered out
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Ordovicien',
                'prefLabel' => ['_value' => 'Ordovicien', '_lang' => 'fr'],
                'broader' => [],
            ],
        ];

        Http::fake([
            'vocabs.ardc.edu.au/*' => Http::response([
                'result' => ['items' => $items],
            ], 200),
        ]);

        $this->artisan('get-chronostrat-timescale')
            ->assertExitCode(0);

        $json = json_decode(Storage::get('chronostrat-timescale.json'), true);

        expect($json['data'])->toHaveCount(1)
            ->and($json['data'][0]['text'])->toBe('Cambrian');
    });

    it('handles orphaned concepts as root nodes', function (): void {
        Storage::fake('local');

        $items = [
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Triassic',
                'prefLabel' => ['_value' => 'Triassic', '_lang' => 'en'],
                // Parent not in dataset → should become root
                'broader' => 'http://resource.geosciml.org/classifier/ics/ischart/NotInDataset',
            ],
            [
                '_about' => 'http://resource.geosciml.org/classifier/ics/ischart/Cretaceous',
                'prefLabel' => ['_value' => 'Cretaceous', '_lang' => 'en'],
                'broader' => [],
            ],
        ];

        Http::fake([
            'vocabs.ardc.edu.au/*' => Http::response([
                'result' => ['items' => $items],
            ], 200),
        ]);

        $this->artisan('get-chronostrat-timescale')
            ->assertExitCode(0);

        $json = json_decode(Storage::get('chronostrat-timescale.json'), true);

        // Both should be root nodes
        expect($json['data'])->toHaveCount(2);
        $texts = array_column($json['data'], 'text');
        expect($texts)->toContain('Triassic')
            ->and($texts)->toContain('Cretaceous');
    });
});
