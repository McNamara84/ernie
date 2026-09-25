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
use App\Services\Assessment\FujiAssessmentRequestLimiterService;
use App\Services\Assessment\FujiAssessmentService;
use App\Services\Assessment\ResourceAssessmentRefreshService;
use Illuminate\Support\Facades\Cache;
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
function assessedDraftResource(): array
{
    $resource = Resource::factory()->withDoi('10.5880/refresh.test')->create();
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
    (new RefreshPublishedResourceAssessmentJob($resourceId))->handle($fuji, app(FujiAssessmentRequestLimiterService::class));
}

it('queues a durable refresh only when an already assessed DOI page is published', function (): void {
    [$resource, $page] = assessedDraftResource();

    expect(ResourceAssessmentRefresh::query()->count())->toBe(0);

    $refresh = publishAssessedPage($page);
    expect($refresh->status)->toBe(ResourceAssessmentRefresh::PENDING)
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

it('waits for the DOI redirect and then replaces the previous score once', function (): void {
    [$resource, $page, $assessment] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
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

it('defers the targeted request while a full resource run is active', function (): void {
    [$resource, $page] = assessedDraftResource();
    $refresh = publishAssessedPage($page);
    AssessmentRun::factory()->create([
        'scope' => AssessmentScope::RESOURCE,
        'active_scope' => AssessmentScope::RESOURCE,
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
});

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
    expect($refresh->fresh()->status)->toBe(ResourceAssessmentRefresh::PROCESSING)
        ->and($refresh->fresh()->resource_id)->toBe($resource->id);
});
