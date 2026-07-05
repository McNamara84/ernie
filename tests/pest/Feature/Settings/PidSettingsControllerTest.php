<?php

declare(strict_types=1);

use App\Jobs\UpdatePidJob;
use App\Models\PidSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        Queue::assertPushed(UpdatePidJob::class, function ($job) {
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
        $jobId = Str::uuid()->toString();
        $cacheKey = UpdatePidJob::getCacheKey($jobId);

        Cache::put($cacheKey, [
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
        $jobId = Str::uuid()->toString();

        actingAs(createAdmin())
            ->getJson("/pid-settings/update-status/{$jobId}")
            ->assertStatus(404)
            ->assertJsonPath('error', 'Job not found or expired');
    });
});

describe('checkStatus for RAiD', function () {
    test('returns comparison data for RAiD type', function () {
        config([
            'raid.datacite_endpoint' => 'https://api.datacite.example.test',
            'raid.search_query' => 'identifiers.identifier:*raid.org.au*',
        ]);

        Storage::put('raid/raid-projects.json', json_encode([
            'lastUpdated' => '2026-06-25T10:00:00Z',
            'data' => [],
            'total' => 500,
        ]));

        Http::fake([
            'api.datacite.example.test/dois*' => Http::response([
                'meta' => [
                    'total' => 570,
                    'totalPages' => 570,
                    'page' => 1,
                ],
                'data' => [],
            ], 200),
        ]);

        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_RAID],
            [
                'display_name' => 'RAiD (Research Activity Identifier)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        actingAs(createAdmin())
            ->postJson('/pid-settings/raid/check')
            ->assertOk()
            ->assertJson([
                'type' => 'raid',
                'displayName' => 'RAiD (Research Activity Identifier)',
                'localCount' => 500,
                'remoteCount' => 570,
                'updateAvailable' => true,
                'lastUpdated' => '2026-06-25T10:00:00Z',
            ]);
    });
});

describe('triggerUpdate for RAiD', function () {
    test('dispatches update job for RAiD type', function () {
        Queue::fake();

        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_RAID],
            [
                'display_name' => 'RAiD (Research Activity Identifier)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        $response = actingAs(createAdmin())
            ->postJson('/pid-settings/raid/update')
            ->assertOk()
            ->assertJsonStructure(['jobId', 'type', 'displayName', 'message']);

        expect($response->json('type'))->toBe('raid');
        expect($response->json('displayName'))->toBe('RAiD (Research Activity Identifier)');

        Queue::assertPushed(UpdatePidJob::class);
    });
});
