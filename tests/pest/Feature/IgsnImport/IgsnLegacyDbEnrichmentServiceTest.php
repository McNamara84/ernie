<?php

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\IgsnDifXmlParser;
use App\Services\IgsnLegacyDbEnrichmentService;
use App\Support\GeoLocations\GeoLocationWriteReport;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->parser = Mockery::mock(IgsnDifXmlParser::class);
    $this->service = new IgsnLegacyDbEnrichmentService($this->parser);
});

afterEach(function () {
    Mockery::close();
});

describe('IgsnLegacyDbEnrichmentService', function () {
    it('passes the operation report from legacy DIF metadata to the parser', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFREPORT001']);
        $metadata = IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => IgsnMetadata::STATUS_REGISTERED]);
        $report = new GeoLocationWriteReport;
        $xml = '<resource><sample><primary_location_name>Germany</primary_location_name></sample></resource>';
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('join', 'where', 'whereNotNull', 'orderByDesc', 'select')->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) ['dif' => $xml]);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('table')->once()->with('metadata')->andReturn($query);
        $ernieConnection = DB::connection();
        DB::partialMock()->shouldReceive('connection')->byDefault()->andReturn($ernieConnection);
        DB::partialMock()->shouldReceive('connection')->once()->with('igsn_legacy')->andReturn($connection);
        $this->parser->shouldReceive('enrichFromDifXml')->once()->with($xml, $resource, $metadata, false, $report)->andReturn(true);

        expect($this->service->enrich($resource, $metadata, 'GFREPORT001', $report))->toBeTrue();
    });

    it('returns false when legacy DB query throws connection error', function () {
        DB::shouldReceive('connection')
            ->with('igsn_legacy')
            ->andThrow(new Exception('Connection refused'));

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
            ->andThrow(new Exception('Connection refused'));

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
            ->andThrow(new Exception('Connection refused'));

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
