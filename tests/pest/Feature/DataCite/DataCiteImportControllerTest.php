<?php

declare(strict_types=1);

use App\Http\Controllers\DataCiteImportController;
use App\Jobs\ImportFromDataCiteJob;
use App\Models\User;
use App\Services\LegacyResourceLookupService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

covers(DataCiteImportController::class);

beforeEach(function (): void {
    Config::set('datacite.production.prefixes', ['10.5880', '10.14470']);
});

describe('POST /datacite/import/start', function (): void {
    test('admin can start import', function (): void {
        Bus::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/datacite/import/start')
            ->assertOk()
            ->assertJsonStructure(['import_id', 'message']);

        Bus::assertDispatched(ImportFromDataCiteJob::class);
    });

    test('beginner cannot start import', function (): void {
        $beginner = User::factory()->beginner()->create();

        $this->actingAs($beginner)
            ->postJson('/datacite/import/start')
            ->assertForbidden();
    });
});

describe('POST /datacite/import/start-single', function (): void {
    test('admin can start single import for a configured DataCite DOI URL', function (): void {
        Bus::fake();
        $admin = User::factory()->admin()->create();

        $this->app->instance(LegacyResourceLookupService::class, new class extends LegacyResourceLookupService
        {
            #[\Override]
            public function existsByDoi(string $doi): bool
            {
                throw new RuntimeException('Legacy lookup should not be used for configured DataCite prefixes.');
            }
        });

        $response = $this->actingAs($admin)
            ->postJson('/datacite/import/start-single', [
                'doi' => 'https://doi.org/10.5880/GFZ.OJSJ.2026.001',
            ])
            ->assertOk()
            ->assertJsonStructure(['import_id', 'message']);

        $importId = $response->json('import_id');
        $status = Cache::get("datacite_import:{$importId}");

        expect($status['status'])->toBe('pending')
            ->and($status['total'])->toBe(1);

        Bus::assertDispatched(ImportFromDataCiteJob::class, function (ImportFromDataCiteJob $job): bool {
            return $job->getSingleDoi() === '10.5880/gfz.ojsj.2026.001';
        });
    });

    test('admin can start single import for the GEOFON 10.14470 prefix without a legacy row', function (): void {
        Bus::fake();
        $admin = User::factory()->admin()->create();

        $this->app->instance(LegacyResourceLookupService::class, new class extends LegacyResourceLookupService
        {
            #[\Override]
            public function existsByDoi(string $doi): bool
            {
                throw new RuntimeException('Legacy lookup should not be used for configured DataCite prefixes.');
            }
        });

        $this->actingAs($admin)
            ->postJson('/datacite/import/start-single', [
                'doi' => '10.14470/RV968923',
            ])
            ->assertOk();

        Bus::assertDispatched(ImportFromDataCiteJob::class, function (ImportFromDataCiteJob $job): bool {
            return $job->getSingleDoi() === '10.14470/rv968923';
        });
    });

    test('admin can start single import for an unconfigured DOI with a legacy fallback row', function (): void {
        Bus::fake();
        $admin = User::factory()->admin()->create();

        $this->app->instance(LegacyResourceLookupService::class, new class extends LegacyResourceLookupService
        {
            public array $receivedDois = [];

            #[\Override]
            public function existsByDoi(string $doi): bool
            {
                $this->receivedDois[] = $doi;

                return true;
            }
        });

        $this->actingAs($admin)
            ->postJson('/datacite/import/start-single', [
                'doi' => '10.9999/legacy.only',
            ])
            ->assertOk();

        Bus::assertDispatched(ImportFromDataCiteJob::class, function (ImportFromDataCiteJob $job): bool {
            return $job->getSingleDoi() === '10.9999/legacy.only';
        });
    });

    test('returns validation error for invalid DOI format', function (): void {
        Bus::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson('/datacite/import/start-single', [
                'doi' => 'not-a-doi',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['doi']);

        Bus::assertNothingDispatched();
    });

    test('returns validation error when DOI has no configured prefix and no legacy fallback row', function (): void {
        Bus::fake();
        $admin = User::factory()->admin()->create();

        $this->app->instance(LegacyResourceLookupService::class, new class extends LegacyResourceLookupService
        {
            #[\Override]
            public function existsByDoi(string $doi): bool
            {
                return false;
            }
        });

        $this->actingAs($admin)
            ->postJson('/datacite/import/start-single', [
                'doi' => '10.9999/missing',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['doi'])
            ->assertJsonPath('errors.doi.0', 'Only DOIs with a configured GFZ DataCite prefix or GFZ legacy resources can be imported with this action.');

        Bus::assertNothingDispatched();
    });

    test('returns service unavailable when legacy fallback lookup fails', function (): void {
        Bus::fake();
        $admin = User::factory()->admin()->create();

        $this->app->instance(LegacyResourceLookupService::class, new class extends LegacyResourceLookupService
        {
            #[\Override]
            public function existsByDoi(string $doi): bool
            {
                throw new RuntimeException('Legacy DB unavailable');
            }
        });

        $this->actingAs($admin)
            ->postJson('/datacite/import/start-single', [
                'doi' => '10.9999/legacy.lookup.unavailable',
            ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'The legacy resource database is currently unavailable. Please try again later.');

        Bus::assertNothingDispatched();
    });
});

describe('GET /datacite/import/{importId}/status', function (): void {
    test('returns status for existing import', function (): void {
        $admin = User::factory()->admin()->create();
        $importId = \Illuminate\Support\Str::uuid()->toString();

        Cache::put("datacite_import:{$importId}", [
            'status' => 'running',
            'total' => 10,
            'processed' => 5,
            'imported' => 3,
            'skipped' => 2,
            'failed' => 0,
            'skipped_dois' => [],
            'failed_dois' => [],
            'started_at' => now()->toIso8601String(),
            'completed_at' => null,
        ], now()->addHour());

        $this->actingAs($admin)
            ->getJson("/datacite/import/{$importId}/status")
            ->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('processed', 5);
    });

    test('returns 404 for unknown import', function (): void {
        $admin = User::factory()->admin()->create();
        $importId = \Illuminate\Support\Str::uuid()->toString();

        $this->actingAs($admin)
            ->getJson("/datacite/import/{$importId}/status")
            ->assertNotFound();
    });

    test('returns 400 for invalid UUID format', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson('/datacite/import/not-a-uuid/status')
            ->assertBadRequest();
    });
});
