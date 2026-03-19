<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteServiceInterface;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use Illuminate\Http\Client\RequestException;

covers(DataCiteSyncService::class);

describe('syncIfRegistered', function (): void {
    $createSyncService = fn (?DataCiteServiceInterface $service = null): DataCiteSyncService => new DataCiteSyncService(
        $service ?? Mockery::mock(DataCiteServiceInterface::class),
    );

    test('returns notRequired when resource has no DOI', function () use ($createSyncService): void {
        $resource = Resource::factory()->create(['doi' => null]);
        $service = $createSyncService();

        $result = $service->syncIfRegistered($resource);

        expect($result->attempted)->toBeFalse();
        expect($result->success)->toBeTrue();
    });

    test('returns notRequired when resource has empty DOI', function () use ($createSyncService): void {
        $resource = Resource::factory()->create(['doi' => '']);
        $service = $createSyncService();

        $result = $service->syncIfRegistered($resource);

        expect($result->attempted)->toBeFalse();
    });

    test('returns failed when resource has DOI but no landing page', function () use ($createSyncService): void {
        $resource = Resource::factory()->create(['doi' => '10.5880/test.2024.001']);
        $service = $createSyncService();

        $result = $service->syncIfRegistered($resource);

        expect($result->attempted)->toBeTrue();
        expect($result->success)->toBeFalse();
        expect($result->doi)->toBe('10.5880/test.2024.001');
    });

    test('returns succeeded when update succeeds', function () use ($createSyncService): void {
        $resource = Resource::factory()->create(['doi' => '10.5880/test.2024.001']);
        LandingPage::create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
        ]);

        $mockRegistration = Mockery::mock(DataCiteServiceInterface::class);
        $mockRegistration->shouldReceive('isTestMode')->andReturn(false);
        $mockRegistration->shouldReceive('updateMetadata')
            ->with(Mockery::on(fn ($r) => $r->id === $resource->id))
            ->once();

        $service = $createSyncService($mockRegistration);
        $result = $service->syncIfRegistered($resource);

        expect($result->success)->toBeTrue();
        expect($result->doi)->toBe('10.5880/test.2024.001');
    });

    test('returns failed on RuntimeException', function () use ($createSyncService): void {
        $resource = Resource::factory()->create(['doi' => '10.5880/test.2024.001']);
        LandingPage::create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
        ]);

        $mockRegistration = Mockery::mock(DataCiteServiceInterface::class);
        $mockRegistration->shouldReceive('isTestMode')->andReturn(false);
        $mockRegistration->shouldReceive('updateMetadata')
            ->andThrow(new RuntimeException('Connection timeout'));

        $service = $createSyncService($mockRegistration);
        $result = $service->syncIfRegistered($resource);

        expect($result->success)->toBeFalse();
        expect($result->attempted)->toBeTrue();
        expect($result->errorMessage)->toContain('Connection timeout');
    });
});
