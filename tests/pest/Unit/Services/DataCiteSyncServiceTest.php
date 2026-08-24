<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteServiceInterface;
use App\Services\DataCiteSyncService;

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

describe('syncLandingPageUrlIfRegistered', function (): void {
    $createSyncService = fn (?DataCiteServiceInterface $service = null): DataCiteSyncService => new DataCiteSyncService(
        $service ?? Mockery::mock(DataCiteServiceInterface::class),
    );

    test('updates only the URL of a published internal landing page', function () use ($createSyncService): void {
        $resource = Resource::factory()->create(['doi' => '10.5880/import.2026.001']);
        LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
        ]);
        $resource->load('landingPage');
        $targetUrl = $resource->landingPage->public_url;

        $registration = Mockery::mock(DataCiteServiceInterface::class);
        $registration->shouldReceive('isTestMode')->once()->andReturn(false);
        $registration->shouldReceive('updateLandingPageUrl')
            ->once()
            ->with('10.5880/import.2026.001', $targetUrl)
            ->andReturn([]);
        $registration->shouldNotReceive('updateMetadata');

        $result = $createSyncService($registration)->syncLandingPageUrlIfRegistered($resource);

        expect($result)->toMatchObject([
            'attempted' => true,
            'success' => true,
            'doi' => '10.5880/import.2026.001',
        ]);
    });

    test('does not update an unpublished landing page', function () use ($createSyncService): void {
        $resource = Resource::factory()->create(['doi' => '10.5880/import.review']);
        LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);

        $registration = Mockery::mock(DataCiteServiceInterface::class);
        $registration->shouldNotReceive('updateLandingPageUrl');

        $result = $createSyncService($registration)->syncLandingPageUrlIfRegistered($resource);

        expect($result->hasFailed())->toBeTrue()
            ->and($result->errorMessage)->toContain('published');
    });

    test('does not replace an external landing page URL', function () use ($createSyncService): void {
        $resource = Resource::factory()->create(['doi' => '10.14470/external']);
        LandingPage::factory()->external()->published()->create(['resource_id' => $resource->id]);

        $registration = Mockery::mock(DataCiteServiceInterface::class);
        $registration->shouldNotReceive('updateLandingPageUrl');

        $result = $createSyncService($registration)->syncLandingPageUrlIfRegistered($resource);

        expect($result->hasFailed())->toBeTrue()
            ->and($result->errorMessage)->toContain('External');
    });

    test('returns not required without a DOI', function () use ($createSyncService): void {
        $resource = Resource::factory()->create(['doi' => null]);

        $result = $createSyncService()->syncLandingPageUrlIfRegistered($resource);

        expect($result->attempted)->toBeFalse()
            ->and($result->success)->toBeTrue();
    });
});
