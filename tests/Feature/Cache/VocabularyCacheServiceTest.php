<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Services\VocabularyCacheService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Clear all caches before each test
    Cache::flush();

    $this->cacheService = app(VocabularyCacheService::class);
});

it('caches GCMD science keywords', function () {
    $testData = collect([
        ['term' => 'Earth Science'],
        ['term' => 'Atmosphere'],
    ]);

    $result = $this->cacheService->cacheGcmdScienceKeywords(fn () => $testData);

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(2);

    // Verify cache was created
    $cacheKey = CacheKey::GCMD_SCIENCE_KEYWORDS->key();
    expect(Cache::tags(['vocabularies'])->has($cacheKey))->toBeTrue();
});

it('caches GCMD instruments', function () {
    $testData = collect([
        ['instrument' => 'Spectrometer'],
        ['instrument' => 'Radar'],
    ]);

    $result = $this->cacheService->cacheGcmdInstruments(fn () => $testData);

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(2);

    // Verify cache was created
    $cacheKey = CacheKey::GCMD_INSTRUMENTS->key();
    expect(Cache::tags(['vocabularies'])->has($cacheKey))->toBeTrue();
});

it('caches GCMD platforms', function () {
    $testData = collect([
        ['platform' => 'Satellite'],
        ['platform' => 'Aircraft'],
    ]);

    $result = $this->cacheService->cacheGcmdPlatforms(fn () => $testData);

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(2);

    // Verify cache was created
    $cacheKey = CacheKey::GCMD_PLATFORMS->key();
    expect(Cache::tags(['vocabularies'])->has($cacheKey))->toBeTrue();
});

it('caches MSL keywords', function () {
    $testData = collect([
        ['keyword' => 'Laboratory'],
        ['keyword' => 'Equipment'],
    ]);

    $result = $this->cacheService->cacheMslKeywords(fn () => $testData);

    expect($result)->toBeInstanceOf(Collection::class);
    expect($result)->toHaveCount(2);

    // Verify cache was created
    $cacheKey = CacheKey::MSL_KEYWORDS->key();
    expect(Cache::tags(['vocabularies'])->has($cacheKey))->toBeTrue();
});

it('invalidates all vocabulary caches', function () {
    // Create multiple vocabulary cache entries
    Cache::tags(['vocabularies'])->put('vocab1', 'value1', 3600);
    Cache::tags(['vocabularies'])->put('vocab2', 'value2', 3600);
    Cache::tags(['vocabularies'])->put('vocab3', 'value3', 3600);

    // Verify all exist
    expect(Cache::tags(['vocabularies'])->has('vocab1'))->toBeTrue();
    expect(Cache::tags(['vocabularies'])->has('vocab2'))->toBeTrue();
    expect(Cache::tags(['vocabularies'])->has('vocab3'))->toBeTrue();

    // Invalidate all
    $this->cacheService->invalidateAllVocabularyCaches();

    // Verify all cleared
    expect(Cache::tags(['vocabularies'])->has('vocab1'))->toBeFalse();
    expect(Cache::tags(['vocabularies'])->has('vocab2'))->toBeFalse();
    expect(Cache::tags(['vocabularies'])->has('vocab3'))->toBeFalse();
});

it('invalidates specific vocabulary cache', function () {
    // Create cache entries for different vocabularies
    Cache::tags(['vocabularies'])->put(CacheKey::GCMD_SCIENCE_KEYWORDS->key(), 'data1', 3600);
    Cache::tags(['vocabularies'])->put(CacheKey::GCMD_INSTRUMENTS->key(), 'data2', 3600);

    expect(Cache::tags(['vocabularies'])->has(CacheKey::GCMD_SCIENCE_KEYWORDS->key()))->toBeTrue();
    expect(Cache::tags(['vocabularies'])->has(CacheKey::GCMD_INSTRUMENTS->key()))->toBeTrue();

    // Invalidate only science keywords
    $this->cacheService->invalidateVocabularyCache(CacheKey::GCMD_SCIENCE_KEYWORDS);

    // Science keywords should be cleared, instruments should remain
    expect(Cache::tags(['vocabularies'])->has(CacheKey::GCMD_SCIENCE_KEYWORDS->key()))->toBeFalse();
    expect(Cache::tags(['vocabularies'])->has(CacheKey::GCMD_INSTRUMENTS->key()))->toBeTrue();
});

it('returns cached data on subsequent calls', function () {
    $callCount = 0;

    $callback = function () use (&$callCount) {
        $callCount++;

        return collect(['data' => 'test']);
    };

    // First call should execute callback
    $result1 = $this->cacheService->cacheGcmdScienceKeywords($callback);
    expect($callCount)->toBe(1);

    // Second call should use cache
    $result2 = $this->cacheService->cacheGcmdScienceKeywords($callback);
    expect($callCount)->toBe(1); // Callback not executed again

    expect($result1)->toEqual($result2);
});

it('respects cache TTL settings', function () {
    $cacheKey = CacheKey::GCMD_SCIENCE_KEYWORDS;

    // Check TTL is 24 hours (86400 seconds)
    expect($cacheKey->ttl())->toBe(86400);

    $cacheKey = CacheKey::MSL_KEYWORDS;
    expect($cacheKey->ttl())->toBe(86400);
});
