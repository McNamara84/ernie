<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Services\DataCiteApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

covers(DataCiteApiService::class);

beforeEach(function (): void {
    // Use array driver so each test starts with a clean, isolated cache
    // and avoids interfering with other parallel test workers.
    $this->previousCacheDriver = Config::get('cache.default');
    Config::set('cache.default', 'array');
    $this->service = new DataCiteApiService;
});

afterEach(function (): void {
    Config::set('cache.default', $this->previousCacheDriver);
});

// =========================================================================
// getMetadata
// =========================================================================

describe('getMetadata', function (): void {
    it('returns metadata for a valid DOI', function (): void {
        Http::fake([
            'doi.org/*' => Http::response([
                'DOI' => '10.5880/test.2024.001',
                'title' => 'Test Dataset',
                'publisher' => 'GFZ',
            ]),
        ]);

        $result = $this->service->getMetadata('10.5880/test.2024.001');

        expect($result)->toBeArray()
            ->and($result['DOI'])->toBe('10.5880/test.2024.001')
            ->and($result['title'])->toBe('Test Dataset');
    });

    it('strips https://doi.org/ prefix before resolving', function (): void {
        Http::fake([
            'doi.org/10.5880/test*' => Http::response(['DOI' => '10.5880/test.2024.001']),
        ]);

        $result = $this->service->getMetadata('https://doi.org/10.5880/test.2024.001');

        expect($result)->toBeArray();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'doi.org/10.5880/test'));
    });

    it('strips http://doi.org/ prefix before resolving', function (): void {
        Http::fake([
            'doi.org/10.5880/test*' => Http::response(['DOI' => '10.5880/test.2024.001']),
        ]);

        $result = $this->service->getMetadata('http://doi.org/10.5880/test.2024.001');

        expect($result)->toBeArray();
    });

    it('strips https://dx.doi.org/ prefix before resolving', function (): void {
        Http::fake([
            'doi.org/10.5880/test*' => Http::response(['DOI' => '10.5880/test.2024.001']),
        ]);

        $result = $this->service->getMetadata('https://dx.doi.org/10.5880/test.2024.001');

        expect($result)->toBeArray();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'doi.org/10.5880/test'));
    });

    it('strips http://dx.doi.org/ prefix before resolving', function (): void {
        Http::fake([
            'doi.org/10.5880/test*' => Http::response(['DOI' => '10.5880/test.2024.001']),
        ]);

        $result = $this->service->getMetadata('http://dx.doi.org/10.5880/test.2024.001');

        expect($result)->toBeArray();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'doi.org/10.5880/test'));
    });

    it('trims whitespace from DOI before resolving', function (): void {
        Http::fake([
            'doi.org/10.5880/test*' => Http::response(['DOI' => '10.5880/test.2024.001']),
        ]);

        $result = $this->service->getMetadata('  10.5880/test.2024.001  ');

        expect($result)->toBeArray();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'doi.org/10.5880/test'));
    });

    it('returns null for empty DOI without calling API', function (): void {
        Http::fake();

        expect($this->service->getMetadata(''))->toBeNull();
        expect($this->service->getMetadata('   '))->toBeNull();
        expect($this->service->getMetadata('https://doi.org/'))->toBeNull();
        expect($this->service->getMetadata('https://doi.org'))->toBeNull();

        Http::assertNothingSent();
    });

    it('strips resolver prefix case-insensitively', function (): void {
        Http::fake([
            'doi.org/10.5880/test*' => Http::response(['DOI' => '10.5880/test.2024.001']),
        ]);

        $result = $this->service->getMetadata('HTTPS://DOI.ORG/10.5880/test.2024.001');

        expect($result)->toBeArray();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'doi.org/10.5880/test'));
    });

    it('normalizes DOI casing for consistent cache keys', function (): void {
        Http::fake([
            'doi.org/10.5880/test*' => Http::response(['DOI' => '10.5880/TEST.2024.CASE']),
        ]);

        $result = $this->service->getMetadata('10.5880/TEST.2024.CASE');

        expect($result)->toBeArray();
        // Requesting the same DOI in different casing should use cache, not trigger a second request
        $this->service->getMetadata('10.5880/test.2024.case');
        Http::assertSentCount(1);
    });

    it('returns null for 404 response', function (): void {
        Http::fake([
            'doi.org/*' => Http::response('Not Found', 404),
        ]);

        $result = $this->service->getMetadata('10.5880/nonexistent');

        expect($result)->toBeNull();
    });

    it('returns null for server error responses', function (): void {
        Http::fake([
            'doi.org/*' => Http::response('Server Error', 500),
        ]);

        $result = $this->service->getMetadata('10.5880/test.2024.001');

        expect($result)->toBeNull();
    });

    it('returns null on HTTP exception', function (): void {
        Http::fake([
            'doi.org/*' => fn () => throw new \Exception('Connection timeout'),
        ]);

        $result = $this->service->getMetadata('10.5880/test.2024.001');

        expect($result)->toBeNull();
    });
});

// =========================================================================
// buildCitationFromMetadata
// =========================================================================

describe('buildCitationFromMetadata', function (): void {
    it('builds citation with family and given name authors', function (): void {
        $metadata = [
            'author' => [
                ['family' => 'Doe', 'given' => 'John'],
                ['family' => 'Smith', 'given' => 'Jane'],
            ],
            'issued' => ['date-parts' => [[2024]]],
            'title' => 'Test Dataset',
            'publisher' => 'GFZ',
            'DOI' => '10.5880/test.2024.001',
        ];

        $result = $this->service->buildCitationFromMetadata($metadata);

        expect($result)->toBe('Doe, John; Smith, Jane (2024): Test Dataset. GFZ. https://doi.org/10.5880/test.2024.001');
    });

    it('handles literal author names', function (): void {
        $metadata = [
            'author' => [['literal' => 'GFZ German Research Centre for Geosciences']],
            'issued' => ['date-parts' => [[2024]]],
            'title' => 'Test',
            'publisher' => 'GFZ',
            'DOI' => '10.5880/test.2024.001',
        ];

        $result = $this->service->buildCitationFromMetadata($metadata);

        expect($result)->toContain('GFZ German Research Centre for Geosciences');
    });

    it('handles family-only author names', function (): void {
        $metadata = [
            'author' => [['family' => 'Organization']],
            'issued' => ['date-parts' => [[2024]]],
            'title' => 'Test',
            'publisher' => 'GFZ',
            'DOI' => '10.5880/test.2024.001',
        ];

        $result = $this->service->buildCitationFromMetadata($metadata);

        expect($result)->toContain('Organization (2024)');
    });

    it('uses Unknown Author when no authors present', function (): void {
        $metadata = [
            'issued' => ['date-parts' => [[2024]]],
            'title' => 'Test',
            'publisher' => 'GFZ',
            'DOI' => '10.5880/test.2024.001',
        ];

        $result = $this->service->buildCitationFromMetadata($metadata);

        expect($result)->toStartWith('Unknown Author');
    });

    it('falls back to published date-parts when issued is missing', function (): void {
        $metadata = [
            'author' => [['family' => 'Doe', 'given' => 'John']],
            'published' => ['date-parts' => [[2023]]],
            'title' => 'Test',
            'publisher' => 'GFZ',
            'DOI' => '10.5880/test.2024.001',
        ];

        $result = $this->service->buildCitationFromMetadata($metadata);

        expect($result)->toContain('(2023)');
    });

    it('falls back to created date-parts when issued and published are missing', function (): void {
        $metadata = [
            'author' => [['family' => 'Doe', 'given' => 'John']],
            'created' => ['date-parts' => [[2022]]],
            'title' => 'Test',
            'publisher' => 'GFZ',
            'DOI' => '10.5880/test.2024.001',
        ];

        $result = $this->service->buildCitationFromMetadata($metadata);

        expect($result)->toContain('(2022)');
    });

    it('shows n.d. when no date is available', function (): void {
        $metadata = [
            'author' => [['family' => 'Doe', 'given' => 'John']],
            'title' => 'Test',
            'publisher' => 'GFZ',
            'DOI' => '10.5880/test.2024.001',
        ];

        $result = $this->service->buildCitationFromMetadata($metadata);

        expect($result)->toContain('(n.d.)');
    });

    it('shows Untitled when title is missing', function (): void {
        $metadata = [
            'author' => [['family' => 'Doe', 'given' => 'John']],
            'issued' => ['date-parts' => [[2024]]],
            'publisher' => 'GFZ',
            'DOI' => '10.5880/test.2024.001',
        ];

        $result = $this->service->buildCitationFromMetadata($metadata);

        expect($result)->toContain('Untitled');
    });

    it('shows Unknown Publisher when publisher is missing', function (): void {
        $metadata = [
            'author' => [['family' => 'Doe', 'given' => 'John']],
            'issued' => ['date-parts' => [[2024]]],
            'title' => 'Test',
            'DOI' => '10.5880/test.2024.001',
        ];

        $result = $this->service->buildCitationFromMetadata($metadata);

        expect($result)->toContain('Unknown Publisher');
    });

    it('omits DOI URL when DOI is missing', function (): void {
        $metadata = [
            'author' => [['family' => 'Doe', 'given' => 'John']],
            'issued' => ['date-parts' => [[2024]]],
            'title' => 'Test',
            'publisher' => 'GFZ',
        ];

        $result = $this->service->buildCitationFromMetadata($metadata);

        expect($result)->not->toContain('https://doi.org/');
    });
});

// =========================================================================
// Caching
// =========================================================================

describe('caching', function (): void {
    it('caches metadata response for subsequent calls', function (): void {
        Http::fake([
            'doi.org/*' => Http::response([
                'DOI' => '10.5880/cached.2024.001',
                'title' => 'Cached Dataset',
            ]),
        ]);

        // First call - should trigger HTTP request
        $first = $this->service->getMetadata('10.5880/cached.2024.001');

        // Second call - should use cache
        $second = $this->service->getMetadata('10.5880/cached.2024.001');

        expect($first)->toBe($second);
        Http::assertSentCount(1);
    });

    it('uses 24-hour TTL for DOI citation cache', function (): void {
        expect(CacheKey::DOI_CITATION->ttl())->toBe(86400);
    });

    it('stores cache under correct key pattern', function (): void {
        Http::fake([
            'doi.org/*' => Http::response([
                'DOI' => '10.5880/key-test',
                'title' => 'Key Test',
            ]),
        ]);

        $this->service->getMetadata('10.5880/key-test');

        // Second call should not trigger another HTTP request (proves caching works)
        $this->service->getMetadata('10.5880/key-test');

        Http::assertSentCount(1);
        expect(CacheKey::DOI_CITATION->key('10.5880/key-test'))->toBe('doi:citation:10.5880/key-test');
    });

    it('caches 404 responses using sentinel value for full TTL', function (): void {
        Http::fake([
            'doi.org/*' => Http::response('Not Found', 404),
        ]);

        // First call - should trigger HTTP request
        $first = $this->service->getMetadata('10.5880/not-found');
        expect($first)->toBeNull();

        // Second call - should use cached sentinel, not trigger another HTTP request
        $second = $this->service->getMetadata('10.5880/not-found');
        expect($second)->toBeNull();

        Http::assertSentCount(1);
    });

    it('caches transient failures with short TTL so they can recover', function (): void {
        Http::fake([
            'doi.org/*' => Http::response('Server Error', 500),
        ]);

        // First call - should trigger HTTP request
        $first = $this->service->getMetadata('10.5880/server-error');
        expect($first)->toBeNull();

        // Second call within 5-minute window - should use cached transient sentinel
        $second = $this->service->getMetadata('10.5880/server-error');
        expect($second)->toBeNull();

        Http::assertSentCount(1);
    });
});
