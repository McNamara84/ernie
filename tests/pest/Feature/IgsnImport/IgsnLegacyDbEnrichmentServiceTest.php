<?php

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\IgsnDifXmlParser;
use App\Services\IgsnLegacyDbEnrichmentService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->parser = Mockery::mock(IgsnDifXmlParser::class);
    $this->service = new IgsnLegacyDbEnrichmentService($this->parser);
});

afterEach(function () {
    Mockery::close();
});

describe('IgsnLegacyDbEnrichmentService', function () {
    it('returns false when legacy DB query throws connection error', function () {
        DB::shouldReceive('connection')
            ->with('igsn_legacy')
            ->andThrow(new \Exception('Connection refused'));

        $resource = Resource::factory()->create(['doi' => '10.60510/GFLEGACY001']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $result = $this->service->enrich($resource, $igsnMetadata, 'GFLEGACY001');
        expect($result)->toBeFalse();
    });

    it('records failures and disables after MAX_CONSECUTIVE_FAILURES', function () {
        DB::shouldReceive('connection')
            ->with('igsn_legacy')
            ->andThrow(new \Exception('Connection refused'));

        $resource = Resource::factory()->create(['doi' => '10.60510/GFLEGACYFAIL']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        // MAX_CONSECUTIVE_FAILURES = 5
        for ($i = 0; $i < 5; $i++) {
            $this->service->enrich($resource, $igsnMetadata, 'GFLEGACYFAIL');
        }

        expect($this->service->isAvailable())->toBeFalse();
    });

    it('returns false when not available', function () {
        DB::shouldReceive('connection')
            ->with('igsn_legacy')
            ->andThrow(new \Exception('Connection refused'));

        $resource = Resource::factory()->create(['doi' => '10.60510/GFLEGACYNA']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        // Disable the service
        for ($i = 0; $i < 5; $i++) {
            $this->service->enrich($resource, $igsnMetadata, 'GFLEGACYNA');
        }

        // Further calls should return false immediately
        $result = $this->service->enrich($resource, $igsnMetadata, 'GFLEGACYNA');
        expect($result)->toBeFalse();
    });

    it('is initially available', function () {
        expect($this->service->isAvailable())->toBeTrue();
    });
});
