<?php

declare(strict_types=1);

use App\Jobs\UpdateThesaurusJob;
use App\Models\ThesaurusSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

covers(UpdateThesaurusJob::class);

// =========================================================================
// Constructor validation
// =========================================================================

describe('constructor validation', function () {
    it('accepts valid thesaurus types', function () {
        $validTypes = [
            ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
            ThesaurusSetting::TYPE_PLATFORMS,
            ThesaurusSetting::TYPE_INSTRUMENTS,
        ];

        foreach ($validTypes as $type) {
            $job = new UpdateThesaurusJob($type, (string) Str::uuid());
            expect($job)->toBeInstanceOf(UpdateThesaurusJob::class);
        }
    });

    it('rejects invalid thesaurus type')
        ->throws(InvalidArgumentException::class, 'Invalid thesaurus type')
        ->expect(fn () => new UpdateThesaurusJob('invalid_type', (string) Str::uuid()));

    it('rejects invalid UUID format')
        ->throws(InvalidArgumentException::class, 'Invalid jobId format')
        ->expect(fn () => new UpdateThesaurusJob(ThesaurusSetting::TYPE_SCIENCE_KEYWORDS, 'not-a-uuid'));

    it('normalizes jobId to lowercase', function () {
        $uuid = strtoupper((string) Str::uuid());
        $job = new UpdateThesaurusJob(ThesaurusSetting::TYPE_SCIENCE_KEYWORDS, $uuid);

        $cacheKey = UpdateThesaurusJob::getCacheKey(strtolower($uuid));

        // Cache key should use lowercased UUID
        expect($cacheKey)->toBe('thesaurus_update:'.strtolower($uuid));
    });
});

// =========================================================================
// getCacheKey()
// =========================================================================

describe('getCacheKey', function () {
    it('returns formatted cache key', function () {
        $uuid = (string) Str::uuid();

        $key = UpdateThesaurusJob::getCacheKey($uuid);

        expect($key)->toBe("thesaurus_update:{$uuid}");
    });
});

// =========================================================================
// handle()
// =========================================================================

describe('handle', function () {
    it('sets cache status to running then completed on success', function () {
        $uuid = (string) Str::uuid();
        $cacheKey = UpdateThesaurusJob::getCacheKey($uuid);

        // Mock artisan call to succeed
        Artisan::shouldReceive('call')
            ->with('get-gcmd-science-keywords')
            ->once()
            ->andReturn(0);

        Artisan::shouldReceive('output')->never();

        $job = new UpdateThesaurusJob(ThesaurusSetting::TYPE_SCIENCE_KEYWORDS, $uuid);
        $job->handle();

        $cached = Cache::get($cacheKey);

        expect($cached)
            ->toBeArray()
            ->and($cached['status'])->toBe('completed')
            ->and($cached['thesaurusType'])->toBe(ThesaurusSetting::TYPE_SCIENCE_KEYWORDS);
    });

    it('sets cache status to failed on non-zero exit code', function () {
        $uuid = (string) Str::uuid();
        $cacheKey = UpdateThesaurusJob::getCacheKey($uuid);

        Artisan::shouldReceive('call')
            ->with('get-gcmd-platforms')
            ->once()
            ->andReturn(1);

        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('Some error output');

        $job = new UpdateThesaurusJob(ThesaurusSetting::TYPE_PLATFORMS, $uuid);
        $job->handle();

        $cached = Cache::get($cacheKey);

        expect($cached)
            ->toBeArray()
            ->and($cached['status'])->toBe('failed')
            ->and($cached['error'])->toContain('code 1');
    });

    it('sets cache status to failed on exception and re-throws', function () {
        $uuid = (string) Str::uuid();
        $cacheKey = UpdateThesaurusJob::getCacheKey($uuid);

        Artisan::shouldReceive('call')
            ->with('get-gcmd-instruments')
            ->once()
            ->andThrow(new RuntimeException('Connection timeout'));

        $job = new UpdateThesaurusJob(ThesaurusSetting::TYPE_INSTRUMENTS, $uuid);

        expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Connection timeout');

        $cached = Cache::get($cacheKey);

        expect($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Connection timeout');
    });

    it('maps thesaurus types to correct artisan commands', function () {
        $mapping = [
            ThesaurusSetting::TYPE_SCIENCE_KEYWORDS => 'get-gcmd-science-keywords',
            ThesaurusSetting::TYPE_PLATFORMS => 'get-gcmd-platforms',
            ThesaurusSetting::TYPE_INSTRUMENTS => 'get-gcmd-instruments',
        ];

        foreach ($mapping as $type => $expectedCommand) {
            $uuid = (string) Str::uuid();

            Artisan::shouldReceive('call')
                ->with($expectedCommand)
                ->once()
                ->andReturn(0);

            $job = new UpdateThesaurusJob($type, $uuid);
            $job->handle();
        }
    });
});

// =========================================================================
// failed()
// =========================================================================

describe('failed', function () {
    it('sets cache status to failed with error message', function () {
        $uuid = (string) Str::uuid();
        $cacheKey = UpdateThesaurusJob::getCacheKey($uuid);

        $job = new UpdateThesaurusJob(ThesaurusSetting::TYPE_SCIENCE_KEYWORDS, $uuid);
        $job->failed(new RuntimeException('Queue timeout'));

        $cached = Cache::get($cacheKey);

        expect($cached)
            ->toBeArray()
            ->and($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Queue timeout');
    });

    it('handles null exception', function () {
        $uuid = (string) Str::uuid();
        $cacheKey = UpdateThesaurusJob::getCacheKey($uuid);

        $job = new UpdateThesaurusJob(ThesaurusSetting::TYPE_PLATFORMS, $uuid);
        $job->failed(null);

        $cached = Cache::get($cacheKey);

        expect($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Unknown error');
    });
});
