<?php

declare(strict_types=1);

use App\Http\Controllers\DataCiteImportController;
use App\Jobs\ImportFromDataCiteJob;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

covers(DataCiteImportController::class);

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
