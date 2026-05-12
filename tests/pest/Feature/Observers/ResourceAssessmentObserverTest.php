<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Observers\ResourceAssessmentObserver;
use App\Services\Assessment\AssessmentAverageSummaryVersionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

covers(ResourceAssessmentObserver::class, AssessmentAverageSummaryVersionService::class);

beforeEach(function (): void {
    Cache::forget(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version'));
});

function makeResourceAssessmentObserver(): ResourceAssessmentObserver
{
    return new ResourceAssessmentObserver();
}

function createAssessmentForObserverTest(): ResourceAssessment
{
    $resource = Resource::factory()->create();

    return ResourceAssessment::withoutEvents(fn (): ResourceAssessment => ResourceAssessment::query()->create([
        'resource_id' => $resource->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 6.0,
        'assessed_at' => now(),
    ]));
}

it('bumps the assessment summary cache version when an assessment is saved', function (): void {
    $assessment = createAssessmentForObserverTest();

    makeResourceAssessmentObserver()->saved($assessment);

    expect((int) Cache::get(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version')))->toBe(2);
});

it('bumps the assessment summary cache version when an assessment is deleted', function (): void {
    Cache::forever(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version'), 4);
    $assessment = createAssessmentForObserverTest();

    makeResourceAssessmentObserver()->deleted($assessment);

    expect((int) Cache::get(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version')))->toBe(5);
});

it('falls back to a best-effort version increment when the version lock is busy', function (): void {
    $assessment = createAssessmentForObserverTest();
    $versionKey = CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version');
    $lockKey = CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version-lock');
    $lock = Mockery::mock();

    Cache::shouldReceive('lock')
        ->once()
        ->with($lockKey, 5)
        ->andReturn($lock);

    $lock->shouldReceive('get')
        ->once()
        ->andReturnFalse();

    Cache::shouldReceive('add')
        ->once()
        ->withArgs(fn (string $key, int $value, mixed $ttl): bool => $key === $versionKey && $value === 1 && $ttl instanceof \DateTimeInterface)
        ->andReturn(true);

    Cache::shouldReceive('increment')
        ->once()
        ->with($versionKey)
        ->andReturn(2);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Assessment summary cache version lock could not be acquired immediately')
            && $context['cache_key'] === $versionKey
            && $context['lock_key'] === $lockKey);

    makeResourceAssessmentObserver()->saved($assessment);
});