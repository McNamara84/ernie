<?php

use App\Exceptions\LegacyIgsnPortalException;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\Igsn\IgsnDifMetadataExtractor;
use App\Services\IgsnDifXmlParser;
use App\Services\IgsnEnrichmentService;
use App\Services\IgsnLegacyDbEnrichmentService;
use App\Services\IgsnSolrEnrichmentService;
use App\Services\LegacyIgsnPortalService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('datacite.solr.host', 'solr.internal');
    config()->set('datacite.solr.user', 'configured');
    config()->set('datacite.solr.password', 'secret');
    config()->set('database.connections.igsn_legacy.configured', true);
    config()->set('datacite.legacy_igsn_portal.proxy_url', null);

    $this->solrService = Mockery::mock(IgsnSolrEnrichmentService::class);
    $this->dbService = Mockery::mock(IgsnLegacyDbEnrichmentService::class);
    $this->portalService = Mockery::mock(LegacyIgsnPortalService::class);
    $this->difParser = Mockery::mock(IgsnDifXmlParser::class);
    $this->difExtractor = Mockery::mock(IgsnDifMetadataExtractor::class);

    $this->enrichmentService = new IgsnEnrichmentService(
        $this->solrService,
        $this->dbService,
        $this->portalService,
        $this->difParser,
        $this->difExtractor,
    );
});

afterEach(function () {
    Mockery::close();
});

describe('IgsnEnrichmentService', function () {
    it('tries Solr first and stops on success', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFTEST001']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $this->solrService->shouldReceive('isAvailable')->once()->andReturn(true);
        $this->solrService->shouldReceive('enrich')
            ->once()
            ->with($resource, $igsnMetadata, 'GFTEST001')
            ->andReturn(true);

        // DB should not be called if Solr succeeds
        $this->dbService->shouldReceive('isAvailable')->never();
        $this->dbService->shouldReceive('enrich')->never();

        $result = $this->enrichmentService->enrich($resource, $igsnMetadata);
        expect($result)->toBeTrue()
            ->and($this->enrichmentService->lastResult())->toBe(['status' => 'enriched', 'source' => 'solr']);
    });

    it('falls back to DB when Solr fails', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFTEST002']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $this->solrService->shouldReceive('isAvailable')->once()->andReturn(true);
        $this->solrService->shouldReceive('enrich')
            ->once()
            ->with($resource, $igsnMetadata, 'GFTEST002')
            ->andReturn(false);

        $this->dbService->shouldReceive('isAvailable')->once()->andReturn(true);
        $this->dbService->shouldReceive('enrich')
            ->once()
            ->with($resource, $igsnMetadata, 'GFTEST002')
            ->andReturn(true);

        $result = $this->enrichmentService->enrich($resource, $igsnMetadata);
        expect($result)->toBeTrue()
            ->and($this->enrichmentService->lastResult())->toBe(['status' => 'enriched', 'source' => 'legacy_db']);
    });

    it('skips Solr when unavailable and uses DB directly', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFTEST003']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $this->solrService->shouldReceive('isAvailable')->once()->andReturn(false);
        $this->solrService->shouldReceive('enrich')->never();

        $this->dbService->shouldReceive('isAvailable')->once()->andReturn(true);
        $this->dbService->shouldReceive('enrich')
            ->once()
            ->andReturn(true);

        $result = $this->enrichmentService->enrich($resource, $igsnMetadata);
        expect($result)->toBeTrue();
    });

    it('returns false when both sources unavailable', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFTEST004']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $this->solrService->shouldReceive('isAvailable')->once()->andReturn(false);
        $this->dbService->shouldReceive('isAvailable')->once()->andReturn(false);

        $result = $this->enrichmentService->enrich($resource, $igsnMetadata);
        expect($result)->toBeFalse()
            ->and($this->enrichmentService->lastResult())->toBe(['status' => 'sources_unavailable', 'source' => null]);
    });

    it('does not attempt runtime-available services when their sources are not configured', function () {
        config()->set('datacite.solr.host', null);
        config()->set('datacite.solr.user', null);
        config()->set('datacite.solr.password', null);
        config()->set('database.connections.igsn_legacy.configured', false);
        Log::spy();

        $resource = Resource::factory()->create(['doi' => '10.60510/GFUNCONFIGURED']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $this->solrService->shouldReceive('isAvailable')->never();
        $this->solrService->shouldReceive('enrich')->never();
        $this->dbService->shouldReceive('isAvailable')->never();
        $this->dbService->shouldReceive('enrich')->never();

        expect($this->enrichmentService->enrich($resource, $igsnMetadata))->toBeFalse()
            ->and($this->enrichmentService->lastResult())->toBe(['status' => 'sources_unavailable', 'source' => null]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'IGSN enrichment sources are unavailable; imports will contain DataCite metadata only'
                && $context['status'] === 'sources_unavailable'
                && $context['configuration'] === ['solr' => false, 'legacy_db' => false, 'portal' => false]);
    });

    it('returns false when resource has no DOI', function () {
        $resource = Resource::factory()->create(['doi' => null]);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $result = $this->enrichmentService->enrich($resource, $igsnMetadata);
        expect($result)->toBeFalse();
    });

    it('extracts handle from DOI suffix in uppercase', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/gfbno7002ec8h101']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $this->solrService->shouldReceive('isAvailable')->once()->andReturn(true);
        $this->solrService->shouldReceive('enrich')
            ->once()
            ->withArgs(function ($res, $meta, $handle) {
                return $handle === 'GFBNO7002EC8H101'; // Should be uppercased
            })
            ->andReturn(true);

        $result = $this->enrichmentService->enrich($resource, $igsnMetadata);
        expect($result)->toBeTrue();
    });

    it('returns false when both sources return no data', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFNODATA001']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $this->solrService->shouldReceive('isAvailable')->once()->andReturn(true);
        $this->solrService->shouldReceive('enrich')->once()->andReturn(false);

        $this->dbService->shouldReceive('isAvailable')->once()->andReturn(true);
        $this->dbService->shouldReceive('enrich')->once()->andReturn(false);

        $result = $this->enrichmentService->enrich($resource, $igsnMetadata);
        expect($result)->toBeFalse()
            ->and($this->enrichmentService->lastResult())->toBe(['status' => 'no_dif_found', 'source' => null]);
    });

    it('reports source configuration without exposing credentials', function () {
        config()->set('datacite.solr.host', 'solr.internal');
        config()->set('datacite.solr.user', 'configured');
        config()->set('datacite.solr.password', 'secret');
        config()->set('database.connections.igsn_legacy.configured', false);

        expect($this->enrichmentService->configurationStatus())->toBe([
            'solr' => true,
            'legacy_db' => false,
            'portal' => false,
        ]);
    });

    it('preloads validates and applies portal DIF metadata for a strict import', function () {
        $difXml = '<DIF><sample><parent_igsn>GFPARENT001</parent_igsn></sample></DIF>';
        $this->portalService->shouldReceive('difForHandles')
            ->once()
            ->with(['GFCHILD001'])
            ->andReturn(['GFCHILD001' => $difXml]);
        $this->difExtractor->shouldReceive('extract')
            ->once()
            ->with($difXml)
            ->andReturn(['parent_igsn' => 'gfparent001']);

        $this->enrichmentService->prepareStrict(['GFCHILD001']);

        expect($this->enrichmentService->preparedParentHandles())->toBe([
            'GFCHILD001' => 'GFPARENT001',
        ]);

        $resource = Resource::factory()->create(['doi' => '10.60510/gfchild001']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);
        $this->difParser->shouldReceive('enrichFromDifXml')
            ->once()
            ->with($difXml, $resource, $igsnMetadata)
            ->andReturn(true);
        $this->solrService->shouldReceive('enrich')->never();
        $this->dbService->shouldReceive('enrich')->never();

        expect($this->enrichmentService->enrich($resource, $igsnMetadata))->toBeTrue()
            ->and($this->enrichmentService->lastResult())->toBe([
                'status' => 'enriched',
                'source' => 'portal',
            ]);

        $this->enrichmentService->clearStrictPreparation();
        expect($this->enrichmentService->preparedParentHandles())->toBe([]);
    });

    it('treats an authoritative missing portal DIF as a valid strict result', function () {
        $this->portalService->shouldReceive('difForHandles')
            ->once()
            ->andReturn(['GFNODIF001' => null]);
        $this->difExtractor->shouldReceive('extract')->never();
        $this->difParser->shouldReceive('enrichFromDifXml')->never();

        $this->enrichmentService->prepareStrict(['GFNODIF001']);

        $resource = Resource::factory()->create(['doi' => '10.60510/gfnodif001']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        expect($this->enrichmentService->enrich($resource, $igsnMetadata))->toBeFalse()
            ->and($this->enrichmentService->lastResult())->toBe([
                'status' => 'no_dif_found',
                'source' => 'portal',
            ]);
    });

    it('rejects malformed DIF during strict preparation', function () {
        $this->portalService->shouldReceive('difForHandles')
            ->once()
            ->andReturn(['GFBADXML001' => '<not-dif>']);
        $this->difExtractor->shouldReceive('extract')->once()->andReturn(null);

        expect(fn () => $this->enrichmentService->prepareStrict(['GFBADXML001']))
            ->toThrow(LegacyIgsnPortalException::class, 'invalid DIF XML');
    });
});
