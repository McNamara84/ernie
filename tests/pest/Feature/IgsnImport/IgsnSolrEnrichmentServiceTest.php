<?php

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\IgsnDifXmlParser;
use App\Services\IgsnSolrEnrichmentService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Config::set('datacite.solr', [
        'host' => 'solr.example.com',
        'port' => '443',
        'user' => 'solr-user',
        'password' => 'solr-pass', // ggignore
    ]);

    $this->parser = Mockery::mock(IgsnDifXmlParser::class);
    $this->service = new IgsnSolrEnrichmentService($this->parser);
});

afterEach(function () {
    Mockery::close();
});

describe('IgsnSolrEnrichmentService', function () {
    it('returns true when Solr has DIF data', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFTEST001']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $difXml = '<DIF><supplementalMetadata><record><sample><sample_type>Rock</sample_type></sample></record></supplementalMetadata></DIF>';

        Http::fake([
            'solr.example.com*' => Http::response([
                'response' => [
                    'docs' => [
                        ['has_dif' => true, 'dif' => base64_encode($difXml)],
                    ],
                ],
            ], 200),
        ]);

        $this->parser->shouldReceive('enrichFromDifXml')
            ->once()
            ->with($difXml, $resource, $igsnMetadata)
            ->andReturn(true);

        $result = $this->service->enrich($resource, $igsnMetadata, 'GFTEST001');
        expect($result)->toBeTrue();
    });

    it('returns false when Solr has no matching document', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFNOMATCH']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        Http::fake([
            'solr.example.com*' => Http::response([
                'response' => ['docs' => []],
            ], 200),
        ]);

        $result = $this->service->enrich($resource, $igsnMetadata, 'GFNOMATCH');
        expect($result)->toBeFalse();
    });

    it('returns false when document has no DIF', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFNODIF']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        Http::fake([
            'solr.example.com*' => Http::response([
                'response' => [
                    'docs' => [['has_dif' => false]],
                ],
            ], 200),
        ]);

        $result = $this->service->enrich($resource, $igsnMetadata, 'GFNODIF');
        expect($result)->toBeFalse();
    });

    it('returns false when HTTP request fails', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFFAIL']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        Http::fake([
            'solr.example.com*' => Http::response([], 500),
        ]);

        $result = $this->service->enrich($resource, $igsnMetadata, 'GFFAIL');
        expect($result)->toBeFalse();
    });

    it('becomes unavailable when credentials are missing', function () {
        Config::set('datacite.solr.host', '');

        $resource = Resource::factory()->create(['doi' => '10.60510/GFNOCRED']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $result = $this->service->enrich($resource, $igsnMetadata, 'GFNOCRED');
        expect($result)->toBeFalse();
        expect($this->service->isAvailable())->toBeFalse();
    });

    it('disables itself after consecutive failures', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFFAILMANY']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        Http::fake([
            'solr.example.com*' => Http::response([], 500),
        ]);

        // Trigger enough failures to disable the service (MAX_CONSECUTIVE_FAILURES = 10)
        for ($i = 0; $i < 10; $i++) {
            $this->service->enrich($resource, $igsnMetadata, 'GFFAILMANY');
        }

        expect($this->service->isAvailable())->toBeFalse();
    });

    it('returns false when base64 DIF decode fails', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFBADDIF']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        Http::fake([
            'solr.example.com*' => Http::response([
                'response' => [
                    'docs' => [
                        ['has_dif' => true, 'dif' => '!!!invalid-base64!!!'],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->enrich($resource, $igsnMetadata, 'GFBADDIF');
        expect($result)->toBeFalse();
    });

    it('returns false when not available', function () {
        Config::set('datacite.solr.host', '');

        $resource = Resource::factory()->create(['doi' => '10.60510/GFSKIP']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        // First call makes it unavailable
        $this->service->enrich($resource, $igsnMetadata, 'GFSKIP');
        expect($this->service->isAvailable())->toBeFalse();

        // Second call should return early
        Http::fake(); // No HTTP calls should happen
        $result = $this->service->enrich($resource, $igsnMetadata, 'GFSKIP');
        expect($result)->toBeFalse();
    });

    it('resets failure counter on success', function () {
        $resource = Resource::factory()->create(['doi' => '10.60510/GFRESET']);
        $igsnMetadata = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $difXml = '<DIF><supplementalMetadata><record><sample><sample_type>Rock</sample_type></sample></record></supplementalMetadata></DIF>';

        Http::fake([
            'solr.example.com*' => Http::response([
                'response' => [
                    'docs' => [
                        ['has_dif' => true, 'dif' => base64_encode($difXml)],
                    ],
                ],
            ], 200),
        ]);

        $this->parser->shouldReceive('enrichFromDifXml')->andReturn(true);

        $result = $this->service->enrich($resource, $igsnMetadata, 'GFRESET');
        expect($result)->toBeTrue();
        expect($this->service->isAvailable())->toBeTrue();
    });
});
