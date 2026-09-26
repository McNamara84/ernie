<?php

declare(strict_types=1);

use App\Enums\AssessmentRunItemStatus;
use App\Enums\AssessmentRunStatus;
use App\Enums\AssessmentScope;
use App\Exceptions\FujiAssessmentException;
use App\Jobs\RefreshPublishedResourceAssessmentJob;
use App\Models\AssessmentRun;
use App\Models\AssessmentRunItem;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceAssessmentRefresh;
use App\Models\ResourceType;
use App\Services\Assessment\FujiAssessmentRequestLimiterService;
use App\Services\Assessment\FujiAssessmentService;
use App\Services\Assessment\ResourceAssessmentRefreshService;
use App\Services\ResourceCacheService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

beforeEach(function (): void {
    config([
        'fuji.enabled' => true,
        'fuji.assessment.queue_connection' => 'assessment',
        'fuji.assessment.queue' => 'assessments',
        'fuji.assessment.requests_per_minute' => 1000,
        'fuji.assessment.minimum_interval_ms' => 0,
        'queue.connections.assessment.driver' => 'database',
    ]);
    Cache::flush();
    Queue::fake();
});

/** @return array{Resource, LandingPage, ResourceAssessment} */
function assessedDraftResource(AssessmentScope $scope = AssessmentScope::RESOURCE): array
{
    $resource = Resource::factory()->withDoi('10.5880/refresh.test')->create();
    if ($scope === AssessmentScope::IGSN) {
        $physicalObjectType = ResourceType::query()->firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'is_active' => true],
        );
        $resource->update(['resource_type_id' => $physicalObjectType->id]);
    }
    $page = LandingPage::factory()->for($resource)->withDoi((string) $resource->doi)->draft()->create();
    $assessment = ResourceAssessment::query()->create([
        'resource_id' => $resource->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 40,
        'assessed_identifier' => $resource->doi,
        'payload' => ['software_version' => '4.0.0'],
        'assessed_at' => now()->subDay(),
    ]);

    return [$resource, $page, $assessment];
}

function publishAssessedPage(LandingPage $page): ResourceAssessmentRefresh
{
    $page->forceFill(['is_published' => true, 'published_at' => now()])->save();

    return ResourceAssessmentRefresh::query()->findOrFail($page->resource_id);
}

function runRefresh(int $resourceId, FujiAssessmentService $fuji): void
{
    app(ResourceAssessmentRefreshService::class)->dispatch($resourceId);
    $job = Queue::pushed(RefreshPublishedResourceAssessmentJob::class)->last();
    if (! $job instanceof RefreshPublishedResourceAssessmentJob) {
        throw new RuntimeException('No published assessment refresh job was queued.');
    }

    $job->handle(
        $fuji,
        app(FujiAssessmentRequestLimiterService::class),
        app(ResourceCacheService::class),
    );
}

it('queues a durable refresh only when an already assessed DOI page is published', function (): void {
    [$resource, $page] = assessedDraftResource();

    expect(ResourceAssessmentRefresh::query()->count())->toBe(0);

    $refresh = publishAssessedPage($page);
    expect($refresh->status)->toBe(ResourceAssessmentRefresh::QUEUED)
        ->and($refresh->generation)->toBe(1)
        ->and($refresh->requested_at)->not->toBeNull();

    $page->forceFill(['template' => 'default_gfz'])->save();
    expect($refresh->fresh()->generation)->toBe(1);

    $page->forceFill(['is_published' => false])->save();
    $page->forceFill(['is_published' => true, 'published_at' => now()])->save();
    expect($refresh->fresh()->generation)->toBe(2);

    $unassessed = Resource::factory()->withDoi('10.5880/unassessed.test')->create();
    LandingPage::factory()->for($unassessed)->withDoi((string) $unassessed->doi)->published()->create();
    expect(ResourceAssessmentRefresh::query()->whereKey($unassessed->id)->exists())->toBeFalse();

    $withoutDoi = Resource::factory()->create(['doi' => null]);
    ResourceAssessment::query()->create([
        'resource_id' => $withoutDoi->id,
        'status' => ResourceAssessment::STATUS_SKIPPED,
    ]);
    LandingPage::factory()->for($withoutDoi)->withoutDoi()->published()->create();
    expect(ResourceAssessmentRefresh::query()->whereKey($withoutDoi->id)->exists())->toBeFalse();
});

it('updates the request timestamp when publication requests share a second', function (): void {
    $this->travelTo(Carbon::parse('2026-09-25 12:00:00.100000'));
    [$resource, $page] = assessedDraftResource();
    $refresh = publishAssessedPage($page);

    $this->travelTo(Carbon::parse('2026-09-25 12:00:00.800000'));
    app(ResourceAssessmentRefreshService::class)->request($resource->id);

    expect($refresh->fresh()->generation)->toBe(2)
        ->and($refresh->fresh()->requested_at->format('u'))->toBe('800000');
});

it('does not let an older queued job claim a newer publication generation', function (): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    $oldJob = Queue::pushed(RefreshPublishedResourceAssessmentJob::class)->last();

    app(ResourceAssessmentRefreshService::class)->request($resource->id);
    $newJob = Queue::pushed(RefreshPublishedResourceAssessmentJob::class)->last();
    expect($refresh->fresh()->generation)->toBe(2)
        ->and($oldJob)->toBeInstanceOf(RefreshPublishedResourceAssessmentJob::class)
        ->and($newJob)->toBeInstanceOf(RefreshPublishedResourceAssessmentJob::class);

    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);
    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->once()->andReturn([
        'score' => 73.0,
        'payload' => ['software_version' => 'new-generation'],
        'resolvedUrl' => $page->public_url,
        'normalizedIdentifier' => $resource->doi,
    ]);

    $oldJob->handle($fuji, app(FujiAssessmentRequestLimiterService::class), app(ResourceCacheService::class));
    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::QUEUED)
        ->and($refresh->fresh()->attempts)->toBe(0)
        ->and((float) $assessment->fresh()->total_score)->toBe(40.0);

    $newJob->handle($fuji, app(FujiAssessmentRequestLimiterService::class), app(ResourceCacheService::class));
    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::COMPLETED)
        ->and((float) $assessment->fresh()->total_score)->toBe(73.0);
});

it('does not let a replaced queued job claim the same generation', function (): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    $oldJob = Queue::pushed(RefreshPublishedResourceAssessmentJob::class)->last();

    $refresh->forceFill(['lease_expires_at' => now()->subSecond()])->save();
    app(ResourceAssessmentRefreshService::class)->recover();
    $newJob = Queue::pushed(RefreshPublishedResourceAssessmentJob::class)->last();

    expect($refresh->fresh()->generation)->toBe(1)
        ->and($oldJob)->toBeInstanceOf(RefreshPublishedResourceAssessmentJob::class)
        ->and($newJob)->toBeInstanceOf(RefreshPublishedResourceAssessmentJob::class)
        ->and($newJob->dispatchToken)->not->toBe($oldJob->dispatchToken);

    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);
    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->once()->andReturn([
        'score' => 74.0,
        'payload' => ['software_version' => 'requeued'],
        'resolvedUrl' => $page->public_url,
        'normalizedIdentifier' => $resource->doi,
    ]);

    $oldJob->handle($fuji, app(FujiAssessmentRequestLimiterService::class), app(ResourceCacheService::class));
    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::QUEUED)
        ->and($refresh->fresh()->attempts)->toBe(0)
        ->and((float) $assessment->fresh()->total_score)->toBe(40.0);

    $newJob->handle($fuji, app(FujiAssessmentRequestLimiterService::class), app(ResourceCacheService::class));
    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::COMPLETED)
        ->and((float) $assessment->fresh()->total_score)->toBe(74.0);
});

it('waits for the DOI redirect and then replaces the previous score once', function (): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    expect($refresh->status)->toBe(ResourceAssessmentRefresh::QUEUED)
        ->and($refresh->lease_expires_at?->isFuture())->toBeTrue();

    Http::fakeSequence('doi.org/*')
        ->push('', 302, ['Location' => 'https://example.org/old-target'])
        ->push('', 302, ['Location' => $page->public_url]);

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->once()->with((string) $resource->doi)->andReturn([
        'score' => 68.5,
        'payload' => ['software_version' => '4.0.1', 'summary' => ['score_percent' => ['FAIR' => 68.5]]],
        'resolvedUrl' => $page->public_url,
        'normalizedIdentifier' => $resource->doi,
    ]);

    runRefresh($resource->id, $fuji);
    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::PENDING)
        ->and((float) $assessment->fresh()->total_score)->toBe(40.0);

    $this->travel(5)->minutes();
    runRefresh($resource->id, $fuji);
    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::COMPLETED)
        ->and((float) $assessment->fresh()->total_score)->toBe(68.5)
        ->and($assessment->fresh()->payload['software_version'])->toBe('4.0.1');
});

it('locks the resource before the refresh row when storing a successful result', function (): void {
    [$resource, $page] = assessedDraftResource();
    publishAssessedPage($page);
    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);

    $recordCompletionQueries = false;
    $lockOrder = [];
    DB::listen(function (QueryExecuted $query) use (&$recordCompletionQueries, &$lockOrder): void {
        if (! $recordCompletionQueries || ! str_starts_with(strtolower($query->sql), 'select')) {
            return;
        }

        if (preg_match('/from ["`](resources|resource_assessment_refreshes)["`]/i', $query->sql, $matches) === 1) {
            $lockOrder[] = $matches[1];
        }
    });

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->once()->andReturnUsing(function () use (&$recordCompletionQueries, $page, $resource): array {
        $recordCompletionQueries = true;

        return [
            'score' => 68.5,
            'payload' => ['software_version' => '4.0.1'],
            'resolvedUrl' => $page->public_url,
            'normalizedIdentifier' => $resource->doi,
        ];
    });

    runRefresh($resource->id, $fuji);
    $recordCompletionQueries = false;

    expect($lockOrder[0] ?? null)->toBe('resources')
        ->and($lockOrder[1] ?? null)->toBe('resource_assessment_refreshes')
        ->and(ResourceAssessmentRefresh::query()->findOrFail($resource->id)->status)->toBe(ResourceAssessmentRefresh::COMPLETED);
});

it('does not trust a newer full assessment before checking the published DOI target', function (): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    $this->travel(2)->seconds();
    $assessment->forceFill([
        'assessed_at' => now(),
        'total_score' => 55,
        'payload' => ['resolved_url' => 'https://example.org/old-target'],
    ])->save();
    Http::fakeSequence('doi.org/*')
        ->push('', 302, ['Location' => 'https://example.org/old-target'])
        ->push('', 302, ['Location' => $page->public_url]);

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->once()->andReturn([
        'score' => 73.0,
        'payload' => ['software_version' => '4.0.1', 'resolved_url' => $page->public_url],
        'resolvedUrl' => $page->public_url,
        'normalizedIdentifier' => $resource->doi,
    ]);

    runRefresh($resource->id, $fuji);

    Http::assertSentCount(1);
    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::PENDING)
        ->and($refresh->fresh()->service_attempts)->toBe(0)
        ->and((float) $assessment->fresh()->total_score)->toBe(55.0);

    $this->travel(5)->minutes();
    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::COMPLETED)
        ->and((float) $assessment->fresh()->total_score)->toBe(73.0);
});

it('retries if a newer terminal full assessment finishes during the targeted F-UJI call', function (string $status, ?float $score): void {
    $this->travelTo(Carbon::parse('2026-09-25 12:00:00.100000'));
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);

    $calls = 0;
    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->twice()->andReturnUsing(function () use (&$calls, $assessment, $page, $resource, $status, $score): array {
        if (++$calls === 1) {
            $this->travelTo(Carbon::parse('2026-09-25 12:00:00.800000'));
            $assessment->forceFill([
                'status' => $status,
                'assessed_at' => now()->startOfSecond(),
                'assessment_started_at' => now(),
                'total_score' => $score,
                'payload' => ['resolved_url' => 'https://example.org/old-target'],
            ])->save();
        }

        return [
            'score' => 74.0,
            'payload' => ['software_version' => '4.0.1', 'resolved_url' => $page->public_url],
            'resolvedUrl' => $page->public_url,
            'normalizedIdentifier' => $resource->doi,
        ];
    });

    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::PENDING)
        ->and($refresh->fresh()->attempts)->toBe(0)
        ->and($assessment->fresh()->assessment_started_at?->format('u'))->toBe('800000')
        ->and($assessment->fresh()->status)->toBe($status)
        ->and($assessment->fresh()->total_score)->toBe($score === null ? null : '55.00');

    $this->travel(1)->minute();
    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::COMPLETED)
        ->and($assessment->fresh()->status)->toBe(ResourceAssessment::STATUS_COMPLETED)
        ->and((float) $assessment->fresh()->total_score)->toBe(74.0);
})->with([
    'completed' => [ResourceAssessment::STATUS_COMPLETED, 55.0],
    'failed' => [ResourceAssessment::STATUS_FAILED, null],
    'skipped' => [ResourceAssessment::STATUS_SKIPPED, null],
]);

it('does not store a result started before a publication request in the same second', function (): void {
    $this->travelTo(Carbon::parse('2026-09-25 12:00:00.100000'));
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    $refresh->forceFill(['requested_at' => Carbon::parse('2026-09-25 12:00:00.800000')])->save();
    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->once()->andReturn([
        'score' => 74.0,
        'payload' => ['software_version' => 'stale-result'],
        'resolvedUrl' => $page->public_url,
        'normalizedIdentifier' => $resource->doi,
    ]);

    expect($refresh->fresh()->requested_at->format('u'))->toBe('800000')
        ->and(now()->format('u'))->toBe('100000');

    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::PENDING)
        ->and((float) $assessment->fresh()->total_score)->toBe(40.0)
        ->and($assessment->fresh()->payload['software_version'])->toBe('4.0.0');
});

it('keeps the previous score when a new publication request arrives during F-UJI assessment', function (): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->once()->andReturnUsing(function () use ($resource, $page): array {
        app(ResourceAssessmentRefreshService::class)->request($resource->id);

        return [
            'score' => 74.0,
            'payload' => ['software_version' => 'stale-result'],
            'resolvedUrl' => $page->public_url,
            'normalizedIdentifier' => $resource->doi,
        ];
    });

    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->generation)->toBe(2)
        ->and($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::QUEUED)
        ->and((float) $assessment->fresh()->total_score)->toBe(40.0)
        ->and($assessment->fresh()->payload['software_version'])->toBe('4.0.0');
});

it('keeps the previous assessment until F-UJI resolves the published landing page', function (?string $firstResolvedUrl): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->twice()->with((string) $resource->doi)->andReturn(
        [
            'score' => 10.0,
            'payload' => ['software_version' => 'wrong-target'],
            'resolvedUrl' => $firstResolvedUrl,
            'normalizedIdentifier' => $resource->doi,
        ],
        [
            'score' => 72.0,
            'payload' => ['software_version' => '4.0.1'],
            'resolvedUrl' => $page->public_url,
            'normalizedIdentifier' => $resource->doi,
        ],
    );

    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::PENDING)
        ->and($refresh->fresh()->last_error)->toBe('F-UJI did not resolve the DOI to the published landing page.')
        ->and($refresh->fresh()->available_at?->isFuture())->toBeTrue()
        ->and((float) $assessment->fresh()->total_score)->toBe(40.0)
        ->and($assessment->fresh()->payload['software_version'])->toBe('4.0.0');

    $this->travel(5)->minutes();
    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::COMPLETED)
        ->and($refresh->fresh()->last_error)->toBeNull()
        ->and((float) $assessment->fresh()->total_score)->toBe(72.0)
        ->and($assessment->fresh()->payload['software_version'])->toBe('4.0.1');
})->with([
    'different URL' => 'https://example.org/another-page',
    'missing URL' => null,
]);

it('stops retrying a persistently wrong F-UJI resolver target without replacing the old score', function (): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->times(12)->andReturn([
        'score' => 10.0,
        'payload' => ['software_version' => 'wrong-target'],
        'resolvedUrl' => 'https://example.org/another-page',
        'normalizedIdentifier' => $resource->doi,
    ]);

    for ($attempt = 0; $attempt < 12; $attempt++) {
        runRefresh($resource->id, $fuji);
        $this->travel(5)->minutes();
    }

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::FAILED)
        ->and($refresh->fresh()->attempts)->toBe(12)
        ->and($refresh->fresh()->service_attempts)->toBe(12)
        ->and($refresh->fresh()->last_error)->toBe('F-UJI did not resolve the DOI to the published landing page.')
        ->and((float) $assessment->fresh()->total_score)->toBe(40.0);
});

it('reassesses when a full run and publication share the same stored second', function (): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    $assessment->forceFill(['assessed_at' => $refresh->requested_at])->save();
    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->once()->andReturn([
        'score' => 71.0,
        'payload' => ['software_version' => '4.0.1'],
        'resolvedUrl' => $page->public_url,
        'normalizedIdentifier' => $resource->doi,
    ]);

    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::COMPLETED)
        ->and((float) $assessment->fresh()->total_score)->toBe(71.0);
});

it('keeps the last completed result when F-UJI rejects the automatic reassessment', function (): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->once()->andThrow(new FujiAssessmentException('F-UJI rejected the request.', retryable: false));

    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::FAILED)
        ->and((float) $assessment->fresh()->total_score)->toBe(40.0)
        ->and($assessment->fresh()->payload['software_version'])->toBe('4.0.0');
});

it('limits retryable service errors separately from DOI propagation retries', function (): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    Http::fakeSequence('doi.org/*')
        ->push('', 302, ['Location' => 'https://example.org/old-target'])
        ->push('', 302, ['Location' => $page->public_url])
        ->push('', 302, ['Location' => $page->public_url])
        ->push('', 302, ['Location' => $page->public_url]);

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->times(3)
        ->andThrow(new FujiAssessmentException('Temporary F-UJI failure.', retryable: true));

    for ($attempt = 0; $attempt < 4; $attempt++) {
        runRefresh($resource->id, $fuji);
        $this->travel(5)->minutes();
    }

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::FAILED)
        ->and($refresh->fresh()->service_attempts)->toBe(3)
        ->and((float) $assessment->fresh()->total_score)->toBe(40.0);
});

it('records a publication while the first full assessment is still processing', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/assessment.in.flight')->create();
    $page = LandingPage::factory()->for($resource)->withDoi((string) $resource->doi)->draft()->create();
    $run = AssessmentRun::factory()->create([
        'scope' => AssessmentScope::RESOURCE,
        'active_scope' => AssessmentScope::RESOURCE,
        'status' => AssessmentRunStatus::RUNNING,
    ]);
    AssessmentRunItem::factory()->for($run, 'run')->for($resource)->create([
        'status' => AssessmentRunItemStatus::PROCESSING,
    ]);

    $page->forceFill(['is_published' => true, 'published_at' => now()])->save();

    expect(ResourceAssessmentRefresh::query()->whereKey($resource->id)->exists())->toBeTrue();
});

it('defers the targeted request while a full run of its scope is active', function (AssessmentScope $scope): void {
    [$resource, $page] = assessedDraftResource($scope);
    $refresh = publishAssessedPage($page);
    AssessmentRun::factory()->create([
        'scope' => $scope,
        'active_scope' => $scope,
        'status' => AssessmentRunStatus::RUNNING,
    ]);
    Http::fake();

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldNotReceive('assessIdentifier');
    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::PENDING)
        ->and($refresh->fresh()->attempts)->toBe(0)
        ->and($refresh->fresh()->available_at?->isFuture())->toBeTrue();
    Http::assertNothingSent();
})->with([
    'resource scope' => AssessmentScope::RESOURCE,
    'IGSN scope' => AssessmentScope::IGSN,
]);

it('does not defer a targeted request for an unrelated active run', function (AssessmentScope $resourceScope, AssessmentScope $runScope): void {
    [$resource, $page, $assessment] = assessedDraftResource($resourceScope);
    $refresh = publishAssessedPage($page);
    AssessmentRun::factory()->create([
        'scope' => $runScope,
        'active_scope' => $runScope,
        'status' => AssessmentRunStatus::RUNNING,
    ]);
    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->once()->with((string) $resource->doi)->andReturn([
        'score' => 70.0,
        'payload' => ['software_version' => '4.0.1'],
        'resolvedUrl' => $page->public_url,
        'normalizedIdentifier' => $resource->doi,
    ]);

    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::COMPLETED)
        ->and((float) $assessment->fresh()->total_score)->toBe(70.0);
    Http::assertSentCount(1);
})->with([
    'resource with active IGSN run' => [AssessmentScope::RESOURCE, AssessmentScope::IGSN],
    'IGSN with active resource run' => [AssessmentScope::IGSN, AssessmentScope::RESOURCE],
]);

it('continues a targeted refresh while a full run is paused', function (): void {
    [$resource, $page] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    AssessmentRun::factory()->create([
        'scope' => AssessmentScope::RESOURCE,
        'active_scope' => AssessmentScope::RESOURCE,
        'status' => AssessmentRunStatus::PAUSED,
    ]);
    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);

    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->once()->andReturn([
        'score' => 70.0,
        'payload' => ['software_version' => '4.0.1'],
        'resolvedUrl' => $page->public_url,
        'normalizedIdentifier' => $resource->doi,
    ]);

    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::COMPLETED)
        ->and((float) $resource->resourceAssessment()->firstOrFail()->total_score)->toBe(70.0);
});

it('requeues expired processing leases from the durable refresh table', function (): void {
    [$resource, $page] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    $refresh->forceFill([
        'status' => ResourceAssessmentRefresh::PROCESSING,
        'lease_expires_at' => now()->subMinute(),
    ])->save();

    app(ResourceAssessmentRefreshService::class)->recover();

    Queue::assertPushed(RefreshPublishedResourceAssessmentJob::class);
    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::QUEUED)
        ->and($refresh->fresh()->resource_id)->toBe($resource->id);
});

it('keeps a queued database job during worker backlog and replaces it when missing', function (): void {
    app()->forgetInstance('queue');
    Queue::clearResolvedInstance('queue');
    [$resource, $page] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    $service = app(ResourceAssessmentRefreshService::class);
    $firstQueueJobId = $refresh->fresh()->queue_job_id;

    expect($refresh->status)->toBe(ResourceAssessmentRefresh::QUEUED)
        ->and($firstQueueJobId)->toBeInt()
        ->and(DB::table('jobs')->where('id', $firstQueueJobId)->exists())->toBeTrue();

    $this->travel(7)->minutes();
    $service->recover();
    $service->recover();
    expect($refresh->fresh()->queue_job_id)->toBe($firstQueueJobId)
        ->and($refresh->fresh()->lease_expires_at?->isFuture())->toBeTrue()
        ->and(DB::table('jobs')->where('queue', 'assessments')->count())->toBe(1);

    DB::table('jobs')->where('id', $firstQueueJobId)->delete();
    $this->travel(7)->minutes();
    $service->recover();
    $service->recover();

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::QUEUED)
        ->and($refresh->fresh()->queue_job_id)->toBeInt()->not->toBe($firstQueueJobId)
        ->and($refresh->fresh()->lease_expires_at?->isFuture())->toBeTrue()
        ->and(DB::table('jobs')->where('queue', 'assessments')->count())->toBe(1)
        ->and($refresh->fresh()->resource_id)->toBe($resource->id);
});

it('rejects an old worker result after another worker claims the recovered lease', function (bool $fails): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    Http::fake(['doi.org/*' => Http::response('', 302, ['Location' => $page->public_url])]);

    $calls = 0;
    /** @var FujiAssessmentService&MockInterface $fuji */
    $fuji = $this->mock(FujiAssessmentService::class);
    $fuji->shouldReceive('assessIdentifier')->twice()->andReturnUsing(function () use ($resource, $page, $refresh, $fuji, $fails, &$calls): array {
        if (++$calls === 2) {
            return [
                'score' => 76.0,
                'payload' => ['software_version' => 'replacement-worker'],
                'resolvedUrl' => $page->public_url,
                'normalizedIdentifier' => $resource->doi,
            ];
        }

        $oldToken = $refresh->fresh()->claim_token;
        expect($oldToken)->not->toBeNull();

        $refresh->forceFill(['lease_expires_at' => now()->subSecond()])->save();
        app(ResourceAssessmentRefreshService::class)->recover();
        expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::QUEUED)
            ->and($refresh->fresh()->claim_token)->not->toBe($oldToken);

        $replacement = Queue::pushed(RefreshPublishedResourceAssessmentJob::class)->last();
        expect($replacement)->toBeInstanceOf(RefreshPublishedResourceAssessmentJob::class);
        $replacement->handle($fuji, app(FujiAssessmentRequestLimiterService::class), app(ResourceCacheService::class));
        expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::COMPLETED);

        if ($fails) {
            throw new FujiAssessmentException('Old worker failed.', retryable: false);
        }

        return [
            'score' => 10.0,
            'payload' => ['software_version' => 'old-worker'],
            'resolvedUrl' => $page->public_url,
            'normalizedIdentifier' => $resource->doi,
        ];
    });

    runRefresh($resource->id, $fuji);

    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::COMPLETED)
        ->and($refresh->fresh()->last_error)->toBeNull()
        ->and((float) $assessment->fresh()->total_score)->toBe(76.0)
        ->and($assessment->fresh()->payload['software_version'])->toBe('replacement-worker');
})->with([
    'stale success' => false,
    'stale failure' => true,
]);
