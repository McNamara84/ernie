<?php

declare(strict_types=1);

use App\Http\Controllers\AssessmentController;
use App\Jobs\RunResourceAssessmentsJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

covers(AssessmentController::class);

beforeEach(function (): void {
    Config::set('fuji.enabled', true);
    Config::set('fuji.base_url', 'https://fuji.test');
    Config::set('fuji.username', 'admin');
    Config::set('fuji.password', 'secret');
    Config::set('cache.default', 'array');
    Cache::flush();
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
                ->has('resourcesNeedingAttention')
                ->has('igsnsNeedingAttention')
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
});