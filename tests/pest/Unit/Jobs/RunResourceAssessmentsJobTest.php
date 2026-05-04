<?php

declare(strict_types=1);

use App\Jobs\RunResourceAssessmentsJob;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceType;
use App\Models\Title;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

covers(RunResourceAssessmentsJob::class);

beforeEach(function (): void {
    Config::set('fuji.enabled', true);
    Config::set('fuji.base_url', 'https://fuji.test');
    Config::set('fuji.username', 'admin');
    Config::set('fuji.password', 'secret');
    Config::set('cache.default', 'array');
    Cache::flush();
});

describe('handle', function (): void {
    it('stores a completed assessment for eligible non-IGSN resources', function (): void {
        $resource = Resource::factory()->withDoi('10.5880/test.001')->create();
        Title::factory()->for($resource)->create(['value' => 'Example dataset']);
        \App\Models\LandingPage::factory()->for($resource)->withDoi('10.5880/test.001')->published()->create();

        Http::fake([
            'https://fuji.test/fuji/api/v1/evaluate' => Http::response([
                'summary' => [
                    'score_percent' => [
                        'FAIR' => 41.25,
                    ],
                ],
            ]),
        ]);

        $jobId = (string) \Illuminate\Support\Str::uuid();
        $job = new RunResourceAssessmentsJob(RunResourceAssessmentsJob::RESOURCE_SCOPE, $jobId);

        $job->handle(app(\App\Services\Assessment\FujiAssessmentService::class), app(\App\Services\ResourceCacheService::class));

        $assessment = ResourceAssessment::query()->where('resource_id', $resource->id)->first();

        expect($assessment)->not->toBeNull()
            ->and($assessment?->status)->toBe(ResourceAssessment::STATUS_COMPLETED)
            ->and((float) $assessment?->total_score)->toBe(41.25);

        $status = Cache::get(RunResourceAssessmentsJob::getCacheKey(RunResourceAssessmentsJob::RESOURCE_SCOPE, $jobId));

        expect($status)->toBeArray()
            ->and($status['status'])->toBe('completed')
            ->and($status['assessedResources'])->toBe(1)
            ->and($status['failedResources'])->toBe(0)
            ->and($status['skippedResources'])->toBe(0);
    });

    it('marks resources without a published landing page as skipped', function (): void {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'Physical Object',
            'slug' => 'physical-object',
        ]);

        $resource = Resource::factory()->withDoi('10.5880/test.002')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);

        $jobId = (string) \Illuminate\Support\Str::uuid();
        $job = new RunResourceAssessmentsJob(RunResourceAssessmentsJob::IGSN_SCOPE, $jobId);

        $job->handle(app(\App\Services\Assessment\FujiAssessmentService::class), app(\App\Services\ResourceCacheService::class));

        $assessment = ResourceAssessment::query()->where('resource_id', $resource->id)->first();

        expect($assessment)->not->toBeNull()
            ->and($assessment?->status)->toBe(ResourceAssessment::STATUS_SKIPPED)
            ->and($assessment?->error_message)->toBe('Resource has no landing page.');
    });
});