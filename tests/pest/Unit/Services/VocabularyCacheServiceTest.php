<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Services\VocabularyCacheService;
use Illuminate\Support\Facades\Cache;

covers(VocabularyCacheService::class);

describe('VocabularyCacheService', function () {
    beforeEach(function () {
        $this->service = new VocabularyCacheService;
        Cache::flush();
    });

    describe('cacheGcmdScienceKeywords', function () {
        it('caches and returns science keywords', function () {
            $keywords = [['label' => 'Earth Science', 'uri' => 'http://example.com']];

            $result = $this->service->cacheGcmdScienceKeywords(fn () => $keywords);

            expect($result)->toBe($keywords);
        });

        it('returns cached value on subsequent calls', function () {
            $callCount = 0;
            $callback = function () use (&$callCount) {
                $callCount++;

                return ['keyword1'];
            };

            $this->service->cacheGcmdScienceKeywords($callback);
            $this->service->cacheGcmdScienceKeywords($callback);

            expect($callCount)->toBe(1);
        });
    });

    describe('cacheGcmdInstruments', function () {
        it('caches and returns instruments', function () {
            $instruments = [['name' => 'Seismometer']];

            $result = $this->service->cacheGcmdInstruments(fn () => $instruments);

            expect($result)->toBe($instruments);
        });
    });

    describe('cacheGcmdPlatforms', function () {
        it('caches and returns platforms', function () {
            $platforms = [['name' => 'ISS']];

            $result = $this->service->cacheGcmdPlatforms(fn () => $platforms);

            expect($result)->toBe($platforms);
        });
    });

    describe('cacheGcmdProviders', function () {
        it('caches and returns providers', function () {
            $providers = [['name' => 'NASA']];

            $result = $this->service->cacheGcmdProviders(fn () => $providers);

            expect($result)->toBe($providers);
        });
    });

    describe('cacheMslKeywords', function () {
        it('caches and returns MSL keywords', function () {
            $keywords = [['label' => 'Rock Physics']];

            $result = $this->service->cacheMslKeywords(fn () => $keywords);

            expect($result)->toBe($keywords);
        });
    });

    describe('cacheEuroSciVoc', function () {
        it('caches and returns EuroSciVoc vocabulary', function () {
            $vocabulary = [['text' => 'natural sciences', 'children' => []]];

            $result = $this->service->cacheEuroSciVoc(fn () => $vocabulary);

            expect($result)->toBe($vocabulary);
        });

        it('returns cached value on subsequent calls', function () {
            $callCount = 0;
            $callback = function () use (&$callCount) {
                $callCount++;

                return ['euroscivoc_data'];
            };

            $this->service->cacheEuroSciVoc($callback);
            $this->service->cacheEuroSciVoc($callback);

            expect($callCount)->toBe(1);
        });
    });

    describe('cacheVocabulary', function () {
        it('caches arbitrary vocabulary using CacheKey enum', function () {
            $data = ['test' => 'data'];

            $result = $this->service->cacheVocabulary(
                CacheKey::CHRONOSTRAT_TIMESCALE,
                fn () => $data
            );

            expect($result)->toBe($data);
        });
    });

    describe('invalidateAllVocabularyCaches', function () {
        it('flushes vocabulary caches', function () {
            $this->service->cacheGcmdScienceKeywords(fn () => ['cached']);

            $this->service->invalidateAllVocabularyCaches();

            // After invalidation, callback should be called again
            $callCount = 0;
            $this->service->cacheGcmdScienceKeywords(function () use (&$callCount) {
                $callCount++;

                return ['fresh'];
            });

            expect($callCount)->toBe(1);
        });
    });

    describe('touchVocabularyCache', function () {
        it('returns false when key does not exist in cache', function () {
            $result = $this->service->touchVocabularyCache(CacheKey::GCMD_SCIENCE_KEYWORDS);

            expect($result)->toBeFalse();
        });

        it('returns true when key exists in cache', function () {
            $this->service->cacheGcmdScienceKeywords(fn () => ['data']);

            $result = $this->service->touchVocabularyCache(CacheKey::GCMD_SCIENCE_KEYWORDS);

            expect($result)->toBeTrue();
        });
    });

    describe('touchAllVocabularyCaches', function () {
        it('returns results for all vocabulary keys', function () {
            $results = $this->service->touchAllVocabularyCaches();

            expect($results)->toBeArray()
                ->toHaveCount(10);

            foreach (CacheKey::vocabularyKeys() as $key) {
                expect($results)->toHaveKey($key->value);
            }
        });

        it('returns false for all keys when no caches exist', function () {
            $results = $this->service->touchAllVocabularyCaches();

            foreach ($results as $result) {
                expect($result)->toBeFalse();
            }
        });

        it('returns true for keys that exist in cache', function () {
            $this->service->cacheGcmdScienceKeywords(fn () => ['keywords']);
            $this->service->cacheMslKeywords(fn () => ['msl']);

            $results = $this->service->touchAllVocabularyCaches();

            expect($results[CacheKey::GCMD_SCIENCE_KEYWORDS->value])->toBeTrue();
            expect($results[CacheKey::MSL_KEYWORDS->value])->toBeTrue();
            expect($results[CacheKey::GCMD_INSTRUMENTS->value])->toBeFalse();
        });
    });
});
