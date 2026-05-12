<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Observers\ResourceAssessmentObserver;
use Illuminate\Support\Facades\Cache;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

covers(ResourceAssessmentObserver::class);

beforeEach(function (): void {
    $this->observer = new ResourceAssessmentObserver(); // @phpstan-ignore property.notFound
    Cache::forget(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version'));
});

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

    $this->observer->saved($assessment);

    expect((int) Cache::get(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version')))->toBe(2);
});

it('bumps the assessment summary cache version when an assessment is deleted', function (): void {
    Cache::forever(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version'), 4);
    $assessment = createAssessmentForObserverTest();

    $this->observer->deleted($assessment);

    expect((int) Cache::get(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version')))->toBe(5);
});

it('bumps the assessment summary cache version when an assessment is force deleted', function (): void {
    Cache::forever(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version'), 9);
    $assessment = createAssessmentForObserverTest();

    $this->observer->forceDeleted($assessment);

    expect((int) Cache::get(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version')))->toBe(10);
});