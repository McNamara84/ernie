<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('LandingPageObserver OAI-PMH tracking', function () {
    it('creates a deleted record when landing page is depublished', function () {
        $prefix = config('oaipmh.identifier_prefix');
        $resource = Resource::factory()->create(['doi' => '10.5880/obs.2024.001']);
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
        ]);

        $landingPage->update(['is_published' => false]);

        $deleted = OaiPmhDeletedRecord::where('doi', '10.5880/obs.2024.001')->first();

        expect($deleted)->not->toBeNull()
            ->and($deleted->oai_identifier)->toBe("{$prefix}:10.5880/obs.2024.001");
    });

    it('removes deleted record when landing page is republished', function () {
        $prefix = config('oaipmh.identifier_prefix');
        $resource = Resource::factory()->create(['doi' => '10.5880/obs.2024.002']);
        $landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $resource->id,
        ]);

        // Create a deleted record as if previously depublished
        OaiPmhDeletedRecord::create([
            'oai_identifier' => "{$prefix}:10.5880/obs.2024.002",
            'doi' => '10.5880/obs.2024.002',
            'datestamp' => now(),
            'sets' => ['resourcetype:dataset'],
        ]);

        $landingPage->update(['is_published' => true]);

        expect(OaiPmhDeletedRecord::where('doi', '10.5880/obs.2024.002')->exists())->toBeFalse();
    });

    it('does not create deleted record when resource has no DOI', function () {
        $resource = Resource::factory()->create(['doi' => null]);
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
        ]);

        $landingPage->update(['is_published' => false]);

        expect(OaiPmhDeletedRecord::count())->toBe(0);
    });

    it('does not create duplicate deleted records', function () {
        $prefix = config('oaipmh.identifier_prefix');
        $resource = Resource::factory()->create(['doi' => '10.5880/obs.2024.003']);
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
        ]);

        // Pre-create a deleted record
        OaiPmhDeletedRecord::create([
            'oai_identifier' => "{$prefix}:10.5880/obs.2024.003",
            'doi' => '10.5880/obs.2024.003',
            'datestamp' => now(),
            'sets' => [],
        ]);

        $landingPage->update(['is_published' => false]);

        expect(OaiPmhDeletedRecord::where('doi', '10.5880/obs.2024.003')->count())->toBe(1);
    });
});
