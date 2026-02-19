<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\DataCiteServiceInterface;
use App\Services\FakeDataCiteRegistrationService;

covers(FakeDataCiteRegistrationService::class);

beforeEach(function () {
    $this->service = new FakeDataCiteRegistrationService;
});

// =========================================================================
// Interface compliance
// =========================================================================

describe('interface compliance', function () {
    it('implements DataCiteServiceInterface', function () {
        expect($this->service)->toBeInstanceOf(DataCiteServiceInterface::class);
    });
});

// =========================================================================
// registerDoi
// =========================================================================

describe('registerDoi', function () {
    it('returns successful response with DOI for valid request', function () {
        $resource = Resource::factory()->create();
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $result = $this->service->registerDoi($resource, '10.83279');

        expect($result)->toHaveKey('data')
            ->and($result['data']['type'])->toBe('dois')
            ->and($result['data']['attributes']['doi'])->toStartWith('10.83279/')
            ->and($result['data']['attributes']['state'])->toBe('findable')
            ->and($result['data']['attributes']['prefix'])->toBe('10.83279')
            ->and($result['data']['attributes']['url'])->toBeString();
    });

    it('generates predictable DOI suffix containing resource ID', function () {
        $resource = Resource::factory()->create();
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $result = $this->service->registerDoi($resource, '10.83186');

        expect($result['data']['attributes']['suffix'])->toContain("test-{$resource->id}-");
    });

    it('throws InvalidArgumentException for invalid prefix', function () {
        $resource = Resource::factory()->create();
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        expect(fn () => $this->service->registerDoi($resource, '10.99999'))
            ->toThrow(\InvalidArgumentException::class, 'Invalid prefix');
    });

    it('throws RuntimeException when resource has no landing page', function () {
        $resource = Resource::factory()->create();

        expect(fn () => $this->service->registerDoi($resource, '10.83279'))
            ->toThrow(\RuntimeException::class, 'must have a landing page');
    });

    it('uses public_url from landing page accessor', function () {
        $resource = Resource::factory()->create();
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/gfz.2025.12345',
            'slug' => 'test-dataset',
        ]);

        $result = $this->service->registerDoi($resource, '10.83279');

        expect($result['data']['attributes']['url'])->toContain('10.5880/gfz.2025.12345/test-dataset');
    });

    it('accepts all three valid prefixes', function (string $prefix) {
        $resource = Resource::factory()->create();
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $result = $this->service->registerDoi($resource, $prefix);

        expect($result['data']['attributes']['prefix'])->toBe($prefix);
    })->with(['10.83279', '10.83186', '10.83114']);
});

// =========================================================================
// updateMetadata
// =========================================================================

describe('updateMetadata', function () {
    it('returns successful response for resource with DOI', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/test.2025.001']);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        $result = $this->service->updateMetadata($resource);

        expect($result)->toHaveKey('data')
            ->and($result['data']['attributes']['doi'])->toBe('10.83279/test.2025.001')
            ->and($result['data']['attributes']['state'])->toBe('findable');
    });

    it('throws RuntimeException when resource has no DOI', function () {
        $resource = Resource::factory()->create(['doi' => null]);
        LandingPage::factory()->create(['resource_id' => $resource->id]);

        expect(fn () => $this->service->updateMetadata($resource))
            ->toThrow(\RuntimeException::class, 'must have a DOI');
    });

    it('throws RuntimeException when resource has no landing page', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/test.123']);

        expect(fn () => $this->service->updateMetadata($resource))
            ->toThrow(\RuntimeException::class, 'must have a landing page');
    });

    it('uses public_url from landing page accessor', function () {
        $resource = Resource::factory()->create(['doi' => '10.83279/test.2025.001']);
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/gfz.2025.67890',
            'slug' => 'another-dataset',
        ]);

        $result = $this->service->updateMetadata($resource);

        expect($result['data']['attributes']['url'])->toContain('10.5880/gfz.2025.67890/another-dataset');
    });
});

// =========================================================================
// Utility methods
// =========================================================================

describe('getAvailablePrefixes', function () {
    it('returns three prefixes', function () {
        expect($this->service->getAvailablePrefixes())
            ->toHaveCount(3)
            ->toContain('10.83279', '10.83186', '10.83114');
    });
});

describe('isTestMode', function () {
    it('always returns true', function () {
        expect($this->service->isTestMode())->toBeTrue();
    });
});

describe('getEndpoint', function () {
    it('returns fake endpoint URL', function () {
        expect($this->service->getEndpoint())->toBe('https://fake.datacite.org');
    });
});
