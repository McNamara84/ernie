<?php

declare(strict_types=1);

use App\Models\PidSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake();
});

function createAdmin(): User
{
    return User::factory()->admin()->create();
}

describe('checkStatus for ROR', function () {
    test('returns comparison data for ROR type', function () {
        Storage::put('ror/ror-affiliations.json', json_encode([
            'lastUpdated' => '2025-06-01T10:00:00Z',
            'data' => [],
            'total' => 100000,
        ]));

        Http::fake([
            'api.ror.org/v2/organizations*' => Http::response([
                'number_of_results' => 107542,
                'items' => [],
            ], 200),
        ]);

        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_ROR],
            [
                'display_name' => 'ROR (Research Organization Registry)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        actingAs(createAdmin())
            ->postJson('/pid-settings/ror/check')
            ->assertOk()
            ->assertJson([
                'type' => 'ror',
                'displayName' => 'ROR (Research Organization Registry)',
                'localCount' => 100000,
                'remoteCount' => 107542,
                'updateAvailable' => true,
                'lastUpdated' => '2025-06-01T10:00:00Z',
            ]);
    });

    test('returns 404 for unknown PID type', function () {
        actingAs(createAdmin())
            ->postJson('/pid-settings/unknown/check')
            ->assertStatus(404)
            ->assertJsonPath('error', "PID type 'unknown' not found");
    });

    test('returns 503 when remote API fails', function () {
        Storage::put('ror/ror-affiliations.json', json_encode([
            'lastUpdated' => '2025-06-01T10:00:00Z',
            'data' => [],
            'total' => 100000,
        ]));

        Http::fake([
            'api.ror.org/v2/organizations*' => Http::response('Service Unavailable', 503),
        ]);

        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_ROR],
            [
                'display_name' => 'ROR (Research Organization Registry)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        actingAs(createAdmin())
            ->postJson('/pid-settings/ror/check')
            ->assertStatus(503)
            ->assertJsonPath('error', 'Failed to check remote status: Failed to fetch from ROR API: HTTP 503');
    });
});

describe('triggerUpdate for ROR', function () {
    test('dispatches update job for ROR type', function () {
        Queue::fake();

        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_ROR],
            [
                'display_name' => 'ROR (Research Organization Registry)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        $response = actingAs(createAdmin())
            ->postJson('/pid-settings/ror/update')
            ->assertOk()
            ->assertJsonStructure(['jobId', 'type', 'displayName', 'message']);

        expect($response->json('type'))->toBe('ror');
        expect($response->json('displayName'))->toBe('ROR (Research Organization Registry)');

        Queue::assertPushed(\App\Jobs\UpdatePidJob::class, function ($job) {
            return true;
        });
    });

    test('denies non-admin users', function () {
        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_ROR],
            [
                'display_name' => 'ROR (Research Organization Registry)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        actingAs(User::factory()->beginner()->create())
            ->postJson('/pid-settings/ror/update')
            ->assertStatus(403);
    });

    test('returns 404 for non-existing PID type', function () {
        actingAs(createAdmin())
            ->postJson('/pid-settings/nonexistent/update')
            ->assertStatus(404)
            ->assertJsonPath('error', "PID type 'nonexistent' not found");
    });
});

describe('updateStatus', function () {
    test('returns job status from cache', function () {
        $jobId = \Illuminate\Support\Str::uuid()->toString();
        $cacheKey = \App\Jobs\UpdatePidJob::getCacheKey($jobId);

        \Illuminate\Support\Facades\Cache::put($cacheKey, [
            'status' => 'running',
            'pidType' => 'ror',
            'progress' => 'Downloading ROR data dump from Zenodo...',
            'startedAt' => now()->toIso8601String(),
        ], now()->addHour());

        actingAs(createAdmin())
            ->getJson("/pid-settings/update-status/{$jobId}")
            ->assertOk()
            ->assertJson([
                'status' => 'running',
                'pidType' => 'ror',
            ]);
    });

    test('returns 400 for invalid job ID format', function () {
        actingAs(createAdmin())
            ->getJson('/pid-settings/update-status/not-a-uuid')
            ->assertStatus(400)
            ->assertJsonPath('error', 'Invalid job ID format');
    });

    test('returns 404 for expired or missing job', function () {
        $jobId = \Illuminate\Support\Str::uuid()->toString();

        actingAs(createAdmin())
            ->getJson("/pid-settings/update-status/{$jobId}")
            ->assertStatus(404)
            ->assertJsonPath('error', 'Job not found or expired');
    });
});
