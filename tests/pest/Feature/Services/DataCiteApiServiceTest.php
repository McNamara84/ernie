<?php

use App\Services\DataCiteApiService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = new DataCiteApiService;
});

describe('DataCiteApiService', function () {
    describe('getMetadata', function () {
        it('returns array for successful DOI lookup', function () {
            Http::fake([
                'doi.org/*' => Http::response([
                    'title' => 'Test Publication',
                    'DOI' => '10.1234/test',
                    'author' => [
                        ['family' => 'Doe', 'given' => 'John'],
                    ],
                    'publisher' => 'Test Publisher',
                    'issued' => ['date-parts' => [[2025]]],
                ], 200),
            ]);

            $result = $this->service->getMetadata('10.1234/test');

            expect($result)->toBeArray()
                ->and($result['title'])->toBe('Test Publication')
                ->and($result['DOI'])->toBe('10.1234/test');
        });

        it('cleans DOI with https://doi.org/ prefix', function () {
            Http::fake([
                'doi.org/10.1234/prefixed' => Http::response([
                    'title' => 'Prefixed DOI Test',
                    'DOI' => '10.1234/prefixed',
                ], 200),
            ]);

            $result = $this->service->getMetadata('https://doi.org/10.1234/prefixed');

            expect($result)->toBeArray()
                ->and($result['title'])->toBe('Prefixed DOI Test');

            Http::assertSent(fn ($request) => str_contains($request->url(), 'doi.org/10.1234/prefixed'));
        });

        it('cleans DOI with http://doi.org/ prefix', function () {
            Http::fake([
                'doi.org/10.1234/http-prefixed' => Http::response([
                    'title' => 'HTTP Prefixed Test',
                    'DOI' => '10.1234/http-prefixed',
                ], 200),
            ]);

            $result = $this->service->getMetadata('http://doi.org/10.1234/http-prefixed');

            expect($result)->toBeArray()
                ->and($result['DOI'])->toBe('10.1234/http-prefixed');
        });

        it('returns null for 404 not found', function () {
            Http::fake([
                'doi.org/*' => Http::response(null, 404),
            ]);

            $result = $this->service->getMetadata('10.9999/nonexistent');

            expect($result)->toBeNull();
        });

        it('returns null for server error', function () {
            Http::fake([
                'doi.org/*' => Http::response('Internal Server Error', 500),
            ]);

            $result = $this->service->getMetadata('10.1234/server-error');

            expect($result)->toBeNull();
        });

        it('returns null on network timeout', function () {
            Http::fake([
                'doi.org/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'),
            ]);

            $result = $this->service->getMetadata('10.1234/timeout');

            expect($result)->toBeNull();
        });

        it('sends correct Accept header for CSL JSON', function () {
            Http::fake([
                'doi.org/*' => Http::response(['title' => 'Test'], 200),
            ]);

            $this->service->getMetadata('10.1234/test');

            Http::assertSent(fn ($request) => $request->header('Accept')[0] === 'application/vnd.citationstyles.csl+json'
            );
        });
    });

    describe('buildCitationFromMetadata', function () {
        it('builds citation with multiple authors', function () {
            $metadata = [
                'author' => [
                    ['family' => 'Doe', 'given' => 'John'],
                    ['family' => 'Smith', 'given' => 'Jane'],
                ],
                'title' => 'Test Publication',
                'issued' => ['date-parts' => [[2025]]],
                'publisher' => 'Test Publisher',
                'DOI' => '10.1234/test',
            ];

            $citation = $this->service->buildCitationFromMetadata($metadata);

            expect($citation)->toContain('Doe, John')
                ->and($citation)->toContain('Smith, Jane')
                ->and($citation)->toContain('2025')
                ->and($citation)->toContain('Test Publication');
        });

        it('handles literal author names', function () {
            $metadata = [
                'author' => [
                    ['literal' => 'ACME Corporation'],
                ],
                'title' => 'Corporate Publication',
                'issued' => ['date-parts' => [[2024]]],
                'publisher' => 'Corporate Publisher',
                'DOI' => '10.1234/corp',
            ];

            $citation = $this->service->buildCitationFromMetadata($metadata);

            expect($citation)->toContain('ACME Corporation');
        });

        it('handles family-only author names', function () {
            $metadata = [
                'author' => [
                    ['family' => 'SingleName'],
                ],
                'title' => 'Single Author Publication',
                'issued' => ['date-parts' => [[2024]]],
                'publisher' => 'Publisher',
                'DOI' => '10.1234/single',
            ];

            $citation = $this->service->buildCitationFromMetadata($metadata);

            expect($citation)->toContain('SingleName');
        });

        it('uses fallback for missing authors', function () {
            $metadata = [
                'title' => 'No Author Publication',
                'issued' => ['date-parts' => [[2024]]],
                'publisher' => 'Publisher',
                'DOI' => '10.1234/noauthor',
            ];

            $citation = $this->service->buildCitationFromMetadata($metadata);

            expect($citation)->toContain('Unknown Author');
        });

        it('uses fallback year when issued is missing', function () {
            $metadata = [
                'author' => [['family' => 'Doe', 'given' => 'John']],
                'title' => 'No Year Publication',
                'publisher' => 'Publisher',
                'DOI' => '10.1234/noyear',
            ];

            $citation = $this->service->buildCitationFromMetadata($metadata);

            expect($citation)->toContain('n.d.');
        });

        it('extracts year from published field when issued is missing', function () {
            $metadata = [
                'author' => [['family' => 'Doe', 'given' => 'John']],
                'title' => 'Published Year Test',
                'published' => ['date-parts' => [[2023]]],
                'publisher' => 'Publisher',
                'DOI' => '10.1234/published',
            ];

            $citation = $this->service->buildCitationFromMetadata($metadata);

            expect($citation)->toContain('2023');
        });

        it('handles missing title gracefully', function () {
            $metadata = [
                'author' => [['family' => 'Doe', 'given' => 'John']],
                'issued' => ['date-parts' => [[2024]]],
                'publisher' => 'Publisher',
                'DOI' => '10.1234/notitle',
            ];

            $citation = $this->service->buildCitationFromMetadata($metadata);

            expect($citation)->toContain('Untitled');
        });

        it('handles missing publisher gracefully', function () {
            $metadata = [
                'author' => [['family' => 'Doe', 'given' => 'John']],
                'title' => 'No Publisher Test',
                'issued' => ['date-parts' => [[2024]]],
                'DOI' => '10.1234/nopublisher',
            ];

            $citation = $this->service->buildCitationFromMetadata($metadata);

            expect($citation)->toContain('Unknown Publisher');
        });
    });
});
