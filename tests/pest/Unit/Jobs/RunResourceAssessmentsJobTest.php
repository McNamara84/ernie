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
        ResourceType::factory()->create([
            'name' => 'Physical Object',
            'slug' => 'physical-object',
        ]);

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

    it('marks resources without a DOI as skipped before contacting F-UJI', function (): void {
        $resource = Resource::factory()->create(['doi' => null]);
        Title::factory()->for($resource)->create(['value' => 'Missing DOI resource']);

        $jobId = (string) \Illuminate\Support\Str::uuid();
        $job = new RunResourceAssessmentsJob(RunResourceAssessmentsJob::RESOURCE_SCOPE, $jobId);

        $job->handle(app(\App\Services\Assessment\FujiAssessmentService::class), app(\App\Services\ResourceCacheService::class));

        $assessment = ResourceAssessment::query()->where('resource_id', $resource->id)->first();

        expect($assessment)->not->toBeNull()
            ->and($assessment?->status)->toBe(ResourceAssessment::STATUS_SKIPPED)
            ->and($assessment?->error_message)->toBe('Resource has no DOI.');
    });

    it('marks draft landing pages as skipped', function (): void {
        $resource = Resource::factory()->withDoi('10.5880/test.003')->create();
        Title::factory()->for($resource)->create(['value' => 'Draft landing page resource']);
        \App\Models\LandingPage::factory()->for($resource)->withDoi('10.5880/test.003')->draft()->create();

        $jobId = (string) \Illuminate\Support\Str::uuid();
        $job = new RunResourceAssessmentsJob(RunResourceAssessmentsJob::RESOURCE_SCOPE, $jobId);

        $job->handle(app(\App\Services\Assessment\FujiAssessmentService::class), app(\App\Services\ResourceCacheService::class));

        $assessment = ResourceAssessment::query()->where('resource_id', $resource->id)->first();

        expect($assessment)->not->toBeNull()
            ->and($assessment?->status)->toBe(ResourceAssessment::STATUS_SKIPPED)
            ->and($assessment?->error_message)->toBe('Landing page is not published.');
    });

    it('marks the assessment as failed when the F-UJI request throws', function (): void {
        $resource = Resource::factory()->withDoi('10.5880/test.004')->create();
        Title::factory()->for($resource)->create(['value' => 'Failing resource']);
        \App\Models\LandingPage::factory()->for($resource)->withDoi('10.5880/test.004')->published()->create();

        Http::fake([
            'https://fuji.test/fuji/api/v1/evaluate' => Http::response(['error' => 'Boom'], 500),
        ]);

        $jobId = (string) \Illuminate\Support\Str::uuid();
        $job = new RunResourceAssessmentsJob(RunResourceAssessmentsJob::RESOURCE_SCOPE, $jobId);

        $job->handle(app(\App\Services\Assessment\FujiAssessmentService::class), app(\App\Services\ResourceCacheService::class));

        $assessment = ResourceAssessment::query()->where('resource_id', $resource->id)->first();
        $status = Cache::get(RunResourceAssessmentsJob::getCacheKey(RunResourceAssessmentsJob::RESOURCE_SCOPE, $jobId));

        expect($assessment)->not->toBeNull()
            ->and($assessment?->status)->toBe(ResourceAssessment::STATUS_FAILED)
            ->and($assessment?->error_message)->toContain('status 500')
            ->and($status)->toBeArray()
            ->and($status['status'])->toBe('completed')
            ->and($status['assessedResources'])->toBe(0)
            ->and($status['failedResources'])->toBe(1);
    });

    it('completes with zero totals when the igsn scope has no physical-object type configured', function (): void {
        $jobId = (string) \Illuminate\Support\Str::uuid();
        $job = new RunResourceAssessmentsJob(RunResourceAssessmentsJob::IGSN_SCOPE, $jobId);

        $job->handle(app(\App\Services\Assessment\FujiAssessmentService::class), app(\App\Services\ResourceCacheService::class));

        $status = Cache::get(RunResourceAssessmentsJob::getCacheKey(RunResourceAssessmentsJob::IGSN_SCOPE, $jobId));

        expect($status)->toBeArray()
            ->and($status['status'])->toBe('completed')
            ->and($status['totalResources'])->toBe(0)
            ->and($status['processedResources'])->toBe(0)
            ->and($status['assessedResources'])->toBe(0)
            ->and($status['failedResources'])->toBe(0)
            ->and($status['skippedResources'])->toBe(0);
    });

    it('fails immediately without touching existing assessments when F-UJI is not configured', function (): void {
        $resourceWithAssessment = Resource::factory()->withDoi('10.5880/test.keep')->create();
        $untouchedResource = Resource::factory()->withDoi('10.5880/test.new')->create();

        ResourceAssessment::query()->create([
            'resource_id' => $resourceWithAssessment->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 88.75,
            'assessed_identifier' => $resourceWithAssessment->doi,
            'payload' => ['summary' => ['score_percent' => ['FAIR' => 88.75]]],
            'assessed_at' => now()->subDay(),
        ]);

        Config::set('fuji.enabled', false);

        $jobId = (string) \Illuminate\Support\Str::uuid();
        $job = new RunResourceAssessmentsJob(RunResourceAssessmentsJob::RESOURCE_SCOPE, $jobId);

        $job->handle(app(\App\Services\Assessment\FujiAssessmentService::class), app(\App\Services\ResourceCacheService::class));

        $status = Cache::get(RunResourceAssessmentsJob::getCacheKey(RunResourceAssessmentsJob::RESOURCE_SCOPE, $jobId));
        $assessment = ResourceAssessment::query()->where('resource_id', $resourceWithAssessment->id)->sole();

        expect(ResourceAssessment::query()->count())->toBe(1)
            ->and($assessment->resource_id)->toBe($resourceWithAssessment->id)
            ->and($assessment->status)->toBe(ResourceAssessment::STATUS_COMPLETED)
            ->and($assessment->total_score)->toBe('88.75')
            ->and($status)->toBeArray()
            ->and($status['status'])->toBe('failed')
            ->and($status['error'])->toBe('F-UJI is not configured.')
            ->and($status['processedResources'])->toBe(0)
            ->and($status['assessedResources'])->toBe(0)
            ->and($status['failedResources'])->toBe(0)
            ->and($status['skippedResources'])->toBe(0);

        expect(ResourceAssessment::query()->where('resource_id', $untouchedResource->id)->exists())->toBeFalse();
    });

    it('throttles in-flight status writes to every batch of resources', function (): void {
        $resources = Resource::factory()->count(26)->create();

        foreach ($resources as $resource) {
            \App\Models\LandingPage::factory()->for($resource)->withDoi((string) $resource->doi)->published()->create();
        }

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
        $job = new class(RunResourceAssessmentsJob::RESOURCE_SCOPE, $jobId) extends RunResourceAssessmentsJob
        {
            /** @var list<array{status: string, processedResources: int}> */
            public array $statusWrites = [];

            protected function writeStatus(
                string $cacheKey,
                string $status,
                string $progress,
                string $startedAt,
                int $totalResources,
                int $processedResources,
                int $assessedResources,
                int $failedResources,
                int $skippedResources,
                ?string $completedAt = null,
            ): void {
                $this->statusWrites[] = [
                    'status' => $status,
                    'processedResources' => $processedResources,
                ];

                parent::writeStatus(
                    cacheKey: $cacheKey,
                    status: $status,
                    progress: $progress,
                    startedAt: $startedAt,
                    totalResources: $totalResources,
                    processedResources: $processedResources,
                    assessedResources: $assessedResources,
                    failedResources: $failedResources,
                    skippedResources: $skippedResources,
                    completedAt: $completedAt,
                );
            }
        };

        $job->handle(app(\App\Services\Assessment\FujiAssessmentService::class), app(\App\Services\ResourceCacheService::class));

        expect($job->statusWrites)->toHaveCount(3)
            ->and($job->statusWrites[0])->toBe([
                'status' => 'running',
                'processedResources' => 0,
            ])
            ->and($job->statusWrites[1])->toBe([
                'status' => 'running',
                'processedResources' => 25,
            ])
            ->and($job->statusWrites[2])->toBe([
                'status' => 'completed',
                'processedResources' => 26,
            ]);
    });
});

describe('constructor validation', function (): void {
    it('rejects invalid assessment scopes', function (): void {
        expect(fn () => new RunResourceAssessmentsJob('invalid', (string) \Illuminate\Support\Str::uuid()))
            ->toThrow(InvalidArgumentException::class, 'Assessment scope is invalid.');
    });

    it('rejects invalid job ids', function (): void {
        expect(fn () => new RunResourceAssessmentsJob(RunResourceAssessmentsJob::RESOURCE_SCOPE, 'not-a-uuid'))
            ->toThrow(InvalidArgumentException::class, 'Job ID must be a valid UUID');
    });
});

describe('failed', function (): void {
    it('marks the cached job as failed and preserves existing metadata', function (): void {
        $jobId = (string) \Illuminate\Support\Str::uuid();
        $cacheKey = RunResourceAssessmentsJob::getCacheKey(RunResourceAssessmentsJob::RESOURCE_SCOPE, $jobId);

        Cache::put($cacheKey, [
            'startedAt' => now()->subMinute()->toIso8601String(),
            'status' => 'running',
        ], now()->addHour());

        $job = new RunResourceAssessmentsJob(RunResourceAssessmentsJob::RESOURCE_SCOPE, $jobId);
        $job->failed(new \RuntimeException('Queue blew up.'));

        $status = Cache::get($cacheKey);

        expect($status)->toBeArray()
            ->and($status['startedAt'])->toBeString()
            ->and($status['status'])->toBe('failed')
            ->and($status['error'])->toBe('Queue blew up.')
            ->and($status['completedAt'])->toBeString();
    });

    it('releases an existing cache lock when the job fails', function (): void {
        $lockKey = 'resource_assessment:test-lock';
        $lock = Cache::lock($lockKey, 7200);

        expect($lock->get())->toBeTrue();

        $job = new RunResourceAssessmentsJob(
            RunResourceAssessmentsJob::RESOURCE_SCOPE,
            (string) \Illuminate\Support\Str::uuid(),
            $lock->owner(),
            $lockKey,
        );

        $job->failed(new \RuntimeException('Queue blew up.'));

        expect(Cache::lock($lockKey, 7200)->get())->toBeTrue();
    });
});