<?php

declare(strict_types=1);

use App\Http\Controllers\AssessmentController;
use App\Jobs\RunResourceAssessmentsJob;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\User;
use App\Services\Assessment\FujiAssessmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

covers(AssessmentController::class);

beforeEach(function (): void {
    Config::set('fuji.enabled', true);
    Config::set('fuji.base_url', 'https://fuji.test');
    Config::set('fuji.username', 'admin');
    Config::set('fuji.password', 'secret');
    Config::set('cache.default', 'array');
    Cache::flush();
    Http::fake([
        'https://fuji.test/fuji/api/v1/ui*' => Http::response('OK', 200),
    ]);
});

describe('index', function () {
    it('returns assessment page for admins', function () {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('assessment')
                ->where('auth.user.can_access_assessment', true)
                ->where('fujiConfigured', true)
                ->where('fujiHealthy', true)
                ->where('fujiStatusMessage', null)
                ->has('resourcesNeedingAttention')
                ->has('igsnsNeedingAttention')
            );
    });

    it('renders the page with an unhealthy F-UJI warning when the service is temporarily unavailable', function () {
        $this->mock(FujiAssessmentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('healthStatus')
                ->once()
                ->andReturn([
                    'healthy' => false,
                    'message' => 'F-UJI is currently unavailable. Please try again shortly.',
                ]);
            $mock->shouldReceive('isConfigured')
                ->once()
                ->andReturn(true);
        });

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('assessment')
                ->where('fujiConfigured', true)
                ->where('fujiHealthy', false)
                ->where('fujiStatusMessage', 'F-UJI is currently unavailable. Please try again shortly.')
            );
    });

    it('rejects unauthenticated users', function () {
        $this->get('/assessment')
            ->assertRedirect('/login');
    });

    it('forbids non-admin users', function () {
        $user = User::factory()->create(['role' => 'curator']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertForbidden();
    });

    it('returns populated summaries and ordered attention lists for both scopes', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'Physical Object',
            'slug' => 'physical-object',
        ]);

        $lowestResource = Resource::factory()->withDoi('10.5880/test.resource.001')->create();
        Title::factory()->for($lowestResource)->create(['value' => 'Lowest resource']);
        ResourceAssessment::query()->create([
            'resource_id' => $lowestResource->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 12.5,
            'assessed_identifier' => $lowestResource->doi,
            'assessed_at' => now(),
        ]);

        $higherResource = Resource::factory()->withDoi('10.5880/test.resource.002')->create();
        Title::factory()->for($higherResource)->create(['value' => 'Higher resource']);
        ResourceAssessment::query()->create([
            'resource_id' => $higherResource->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 24.75,
            'assessed_identifier' => $higherResource->doi,
            'assessed_at' => now(),
        ]);

        $failedResource = Resource::factory()->withDoi('10.5880/test.resource.003')->create();
        Title::factory()->for($failedResource)->create(['value' => 'Failed resource']);
        ResourceAssessment::query()->create([
            'resource_id' => $failedResource->id,
            'status' => ResourceAssessment::STATUS_FAILED,
            'error_message' => 'Request failed.',
            'assessed_identifier' => $failedResource->doi,
            'assessed_at' => now(),
        ]);

        Resource::factory()->withDoi('10.5880/test.resource.004')->create();

        $lowestIgsn = Resource::factory()->withDoi('10.5880/test.igsn.001')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);
        Title::factory()->for($lowestIgsn)->create(['value' => 'Lowest IGSN']);
        ResourceAssessment::query()->create([
            'resource_id' => $lowestIgsn->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 8.25,
            'assessed_identifier' => $lowestIgsn->doi,
            'assessed_at' => now(),
        ]);

        $higherIgsn = Resource::factory()->withDoi('10.5880/test.igsn.002')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);
        Title::factory()->for($higherIgsn)->create(['value' => 'Higher IGSN']);
        ResourceAssessment::query()->create([
            'resource_id' => $higherIgsn->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 19.5,
            'assessed_identifier' => $higherIgsn->doi,
            'assessed_at' => now(),
        ]);

        $skippedIgsn = Resource::factory()->withDoi('10.5880/test.igsn.003')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);
        Title::factory()->for($skippedIgsn)->create(['value' => 'Skipped IGSN']);
        ResourceAssessment::query()->create([
            'resource_id' => $skippedIgsn->id,
            'status' => ResourceAssessment::STATUS_SKIPPED,
            'error_message' => 'Landing page is not published.',
            'assessed_identifier' => $skippedIgsn->doi,
            'assessed_at' => now(),
        ]);

        Resource::factory()->withDoi('10.5880/test.igsn.004')->create([
            'resource_type_id' => $physicalObjectType->id,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assessment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('assessment')
                ->where('resourceAssessmentSummary.total', 4)
                ->where('resourceAssessmentSummary.assessed', 2)
                ->where('resourceAssessmentSummary.failed', 1)
                ->where('resourceAssessmentSummary.skipped', 0)
                ->where('resourceAssessmentSummary.unassessed', 1)
                ->where('igsnAssessmentSummary.total', 4)
                ->where('igsnAssessmentSummary.assessed', 2)
                ->where('igsnAssessmentSummary.failed', 0)
                ->where('igsnAssessmentSummary.skipped', 1)
                ->where('igsnAssessmentSummary.unassessed', 1)
                ->where('resourcesNeedingAttention.0.mainTitle', 'Lowest resource')
                ->where('resourcesNeedingAttention.0.score', 12.5)
                ->where('resourcesNeedingAttention.1.mainTitle', 'Higher resource')
                ->where('igsnsNeedingAttention.0.mainTitle', 'Lowest IGSN')
                ->where('igsnsNeedingAttention.0.score', 8.25)
                ->where('igsnsNeedingAttention.1.mainTitle', 'Higher IGSN')
            );
    });
});

describe('checkResources', function () {
    it('starts the resource assessment job and returns a job id', function () {
        Queue::fake();
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->post('/assessment/check-resources')
            ->assertOk();

        expect($response->json('jobId'))->toBeString()->toMatch('/^[a-f0-9-]{36}$/');
        Queue::assertPushed(RunResourceAssessmentsJob::class, 1);
    });

    it('returns 409 when the resource assessment lock is already held', function () {
        $user = User::factory()->create(['role' => 'admin']);
        Cache::lock('resource_assessment:resource:running', 7200)->get();

        $this->actingAs($user)
            ->post('/assessment/check-resources')
            ->assertStatus(409);
    });

    it('returns 503 when F-UJI is not configured', function () {
        Config::set('fuji.enabled', false);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post('/assessment/check-resources')
            ->assertStatus(503)
            ->assertJson(['error' => 'F-UJI is not configured.']);
    });

    it('returns 503 when F-UJI is configured but unhealthy', function () {
        $this->mock(FujiAssessmentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('healthStatus')
                ->once()
                ->andReturn([
                    'healthy' => false,
                    'message' => 'F-UJI is currently unavailable. Please try again shortly.',
                ]);
        });
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post('/assessment/check-resources')
            ->assertStatus(503)
            ->assertJson(['error' => 'F-UJI is currently unavailable. Please try again shortly.']);
    });
});

describe('checkIgsns', function () {
    it('starts the igsn assessment job and returns a job id', function () {
        Queue::fake();
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->post('/assessment/check-igsns')
            ->assertOk();

        expect($response->json('jobId'))->toBeString()->toMatch('/^[a-f0-9-]{36}$/');
        Queue::assertPushed(RunResourceAssessmentsJob::class, 1);
    });

    it('returns 503 when F-UJI is configured but unhealthy', function () {
        $this->mock(FujiAssessmentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('healthStatus')
                ->once()
                ->andReturn([
                    'healthy' => false,
                    'message' => 'F-UJI is currently unavailable. Please try again shortly.',
                ]);
        });
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post('/assessment/check-igsns')
            ->assertStatus(503)
            ->assertJson(['error' => 'F-UJI is currently unavailable. Please try again shortly.']);
    });
});

describe('status', function () {
    it('returns cached job status for a running assessment', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $jobId = \Illuminate\Support\Str::uuid()->toString();

        Cache::put(RunResourceAssessmentsJob::getCacheKey('resource', $jobId), [
            'status' => 'completed',
            'progress' => 'Done.',
            'assessedResources' => 4,
        ], now()->addHour());

        $this->actingAs($user)
            ->get("/assessment/check/resource/{$jobId}/status")
            ->assertOk()
            ->assertJson([
                'status' => 'completed',
                'assessedResources' => 4,
            ]);
    });

    it('returns 404 for unknown scopes', function () {
        $response = app(AssessmentController::class)->status('unknown', '11111111-1111-4111-8111-111111111111');

        expect($response->getStatusCode())->toBe(404)
            ->and($response->getData(true))->toBe(['error' => 'Unknown assessment scope.']);
    });

    it('returns 404 when the job status is missing', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $jobId = \Illuminate\Support\Str::uuid()->toString();

        $this->actingAs($user)
            ->get("/assessment/check/resource/{$jobId}/status")
            ->assertStatus(404)
            ->assertJson([
                'status' => 'unknown',
                'progress' => 'Job not found.',
            ]);
    });
});

describe('checkAll', function () {
    it('starts both assessment scopes', function () {
        Queue::fake();
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->post('/assessment/check-all')
            ->assertOk();

        expect($response->json())->toHaveKeys(['resourceJobId', 'igsnJobId']);
        Queue::assertPushed(RunResourceAssessmentsJob::class, 2);
    });

    it('returns 503 when F-UJI is not configured', function () {
        Config::set('fuji.enabled', false);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post('/assessment/check-all')
            ->assertStatus(503)
            ->assertJson(['error' => 'F-UJI is not configured.']);
    });

    it('returns 503 when F-UJI is configured but unhealthy', function () {
        $this->mock(FujiAssessmentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('healthStatus')
                ->once()
                ->andReturn([
                    'healthy' => false,
                    'message' => 'F-UJI is currently unavailable. Please try again shortly.',
                ]);
        });
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post('/assessment/check-all')
            ->assertStatus(503)
            ->assertJson(['error' => 'F-UJI is currently unavailable. Please try again shortly.']);
    });

    it('returns 409 when all assessment jobs are already running', function () {
        $user = User::factory()->create(['role' => 'admin']);
        Cache::lock('resource_assessment:resource:running', 7200)->get();
        Cache::lock('resource_assessment:igsn:running', 7200)->get();

        $this->actingAs($user)
            ->post('/assessment/check-all')
            ->assertStatus(409)
            ->assertJson([
                'error' => 'All assessment jobs are already running. Please wait for them to finish.',
            ]);
    });

    it('returns a partial result when one scope is locked and the other can start', function () {
        Queue::fake();
        $user = User::factory()->create(['role' => 'admin']);
        Cache::lock('resource_assessment:resource:running', 7200)->get();

        $response = $this->actingAs($user)
            ->post('/assessment/check-all')
            ->assertOk();

        expect($response->json())->toHaveKeys(['resourceError', 'igsnJobId']);
        expect($response->json('resourceError'))->toBe('Resource assessment is already running.');
        Queue::assertPushed(RunResourceAssessmentsJob::class, 1);
    });
});