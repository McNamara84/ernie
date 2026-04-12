<?php

declare(strict_types=1);

use App\Http\Controllers\AssistanceController;
use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use Illuminate\Support\Facades\Cache;

covers(AssistanceController::class);

beforeEach(function (): void {
    Cache::flush();
});

// =========================================================================
// index
// =========================================================================

describe('index', function () {
    it('returns assistance page for authenticated user', function () {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->get('/assistance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('assistance')
                ->has('sections')
                ->has('manifests')
            );
    });

    it('rejects unauthenticated users', function () {
        $this->get('/assistance')
            ->assertRedirect('/login');
    });
});

// =========================================================================
// check (start discovery for single assistant)
// =========================================================================

describe('check', function () {
    it('starts discovery and returns job ID', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Use a real registered assistant (relation-suggestion)
        $response = $this->actingAs($user)
            ->post('/assistance/check/relation-suggestion')
            ->assertOk();

        $jobId = $response->json('jobId');
        expect($jobId)->toBeString()->toMatch('/^[a-f0-9-]{36}$/');
    });

    it('returns 404 for unknown assistant', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // unknown-assistant has no registered route, expect 404
        $this->actingAs($user)
            ->post('/assistance/check/unknown')
            ->assertNotFound();
    });

    it('returns 409 when lock already acquired', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Acquire the real lock for relation-suggestion
        $registrar = app(AssistantRegistrar::class);
        $assistant = $registrar->get('relation-suggestion');
        Cache::lock($assistant->getLockKey(), 7200)->get();

        $this->actingAs($user)
            ->post('/assistance/check/relation-suggestion')
            ->assertStatus(409);
    });
});

// =========================================================================
// status (poll job progress)
// =========================================================================

describe('status', function () {
    it('returns cached job status', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $jobId = \Illuminate\Support\Str::uuid()->toString();

        $registrar = app(AssistantRegistrar::class);
        $assistant = $registrar->get('relation-suggestion');
        $cacheKey = $assistant->getJobStatusCacheKey($jobId);

        Cache::put($cacheKey, [
            'status' => 'completed',
            'progress' => 'Done.',
            'newSuggestionsFound' => 3,
            'lockOwner' => 'secret-token',
        ], now()->addHour());

        $response = $this->actingAs($user)
            ->get("/assistance/check/relation-suggestion/{$jobId}/status")
            ->assertOk();

        $data = $response->json();
        expect($data['status'])->toBe('completed')
            ->and($data['newSuggestionsFound'])->toBe(3)
            // lockOwner should be stripped from response
            ->and($data)->not->toHaveKey('lockOwner');
    });

    it('returns 404 when job not found in cache', function () {
        $user = User::factory()->create(['role' => 'admin']);
        $jobId = \Illuminate\Support\Str::uuid()->toString();

        $this->actingAs($user)
            ->get("/assistance/check/relation-suggestion/{$jobId}/status")
            ->assertNotFound();
    });
});

// =========================================================================
// accept
// =========================================================================

describe('accept', function () {
    it('returns not found for non-existent suggestion', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Suggestion 99999 doesn't exist, so acceptSuggestion returns failure
        $this->actingAs($user)
            ->post('/assistance/relations/99999/accept')
            ->assertOk()
            ->assertJson(['success' => false]);
    });
});

// =========================================================================
// decline
// =========================================================================

describe('decline', function () {
    it('returns success for non-existent suggestion', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Non-existent suggestion declines silently
        $this->actingAs($user)
            ->post('/assistance/relations/99999/decline', ['reason' => 'Not relevant'])
            ->assertOk()
            ->assertJson(['success' => true]);
    });
});

// =========================================================================
// checkAll
// =========================================================================

describe('checkAll', function () {
    it('starts discovery for all assistants', function () {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)
            ->post('/assistance/check-all')
            ->assertOk();

        $data = $response->json();
        // Should have jobId entries for registered assistants
        $hasJobIds = collect($data)->keys()->filter(fn ($k) => str_ends_with($k, 'JobId'))->count();
        expect($hasJobIds)->toBeGreaterThanOrEqual(1);
    });

    it('returns 409 when all assistants are already locked', function () {
        $user = User::factory()->create(['role' => 'admin']);

        // Lock all real assistants
        $registrar = app(AssistantRegistrar::class);
        foreach ($registrar->getAll() as $assistant) {
            Cache::lock($assistant->getLockKey(), 7200)->get();
        }

        $this->actingAs($user)
            ->post('/assistance/check-all')
            ->assertStatus(409);
    });
});
