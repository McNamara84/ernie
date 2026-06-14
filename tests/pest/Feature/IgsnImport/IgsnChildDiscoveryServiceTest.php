<?php

use App\Services\IgsnChildDiscoveryService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

afterEach(function () {
    Mockery::close();
});

describe('IgsnChildDiscoveryService', function () {
    it('discovers direct children from Solr and verifies parent from DIF XML', function () {
        Config::set('datacite.solr', [
            'host' => 'solr.example.com',
            'port' => '443',
            'user' => 'solr-user',
            'password' => 'solr-pass', // ggignore
        ]);

        $matchingDif = '<DIF><supplementalMetadata><record><sample><parent_igsn>ICDPPARENT001</parent_igsn></sample></record></supplementalMetadata></DIF>';
        $otherDif = '<DIF><supplementalMetadata><record><sample><parent_igsn>OTHERPARENT</parent_igsn></sample></record></supplementalMetadata></DIF>';

        Http::fake([
            'solr.example.com*' => Http::response([
                'response' => [
                    'docs' => [
                        ['igsn' => 'ICDPCHILD001', 'has_dif' => true, 'dif' => base64_encode($matchingDif)],
                        ['igsn' => 'ICDPCHILD002', 'has_dif' => true, 'dif' => base64_encode($otherDif)],
                    ],
                ],
            ], 200),
        ]);

        DB::shouldReceive('connection')
            ->with('igsn_legacy')
            ->andThrow(new RuntimeException('Legacy DB unavailable'));

        $service = new IgsnChildDiscoveryService;

        expect($service->discoverDirectChildHandles('ICDPPARENT001'))->toBe(['ICDPCHILD001']);
    });

    it('discovers direct children from the legacy database fallback', function () {
        Config::set('datacite.solr.host', null);
        Config::set('datacite.solr.user', null);
        Config::set('datacite.solr.password', null);

        $matchingDif = '<DIF><supplementalMetadata><record><sample><parent_igsn>ICDPPARENT002</parent_igsn></sample></record></supplementalMetadata></DIF>';
        $otherDif = '<DIF><supplementalMetadata><record><sample><parent_igsn>OTHERPARENT</parent_igsn></sample></record></supplementalMetadata></DIF>';

        $builder = Mockery::mock();
        DB::shouldReceive('connection')
            ->with('igsn_legacy')
            ->andReturn($builder);

        $builder->shouldReceive('table')->with('metadata')->andReturnSelf();
        $builder->shouldReceive('join')->with('dataset', 'metadata.dataset', '=', 'dataset.id')->andReturnSelf();
        $builder->shouldReceive('whereNotNull')->with('metadata.dif')->andReturnSelf();
        $builder->shouldReceive('where')->with('metadata.dif', 'like', '%ICDPPARENT002%')->andReturnSelf();
        $builder->shouldReceive('orderByDesc')->with('metadata.id')->andReturnSelf();
        $builder->shouldReceive('limit')->with(1000)->andReturnSelf();
        $builder->shouldReceive('select')->with('dataset.doi', 'metadata.dif')->andReturnSelf();
        $builder->shouldReceive('get')->andReturn(collect([
            (object) ['doi' => '10273/ICDPCHILD003', 'dif' => $matchingDif],
            (object) ['doi' => '10273/ICDPCHILD004', 'dif' => $otherDif],
        ]));

        $service = new IgsnChildDiscoveryService;

        expect($service->discoverDirectChildHandles('ICDPPARENT002'))->toBe(['ICDPCHILD003']);
    });

    it('deduplicates children and excludes the parent handle', function () {
        Config::set('datacite.solr', [
            'host' => 'solr.example.com',
            'port' => '443',
            'user' => 'solr-user',
            'password' => 'solr-pass', // ggignore
        ]);

        Http::fake([
            'solr.example.com*' => Http::response([
                'response' => [
                    'docs' => [
                        ['igsn' => 'ICDPCHILD005'],
                        ['igsn' => 'ICDPCHILD005'],
                        ['igsn' => 'ICDPPARENT003'],
                    ],
                ],
            ], 200),
        ]);

        DB::shouldReceive('connection')
            ->with('igsn_legacy')
            ->andThrow(new RuntimeException('Legacy DB unavailable'));

        $service = new IgsnChildDiscoveryService;

        expect($service->discoverDirectChildHandles('ICDPPARENT003'))->toBe(['ICDPCHILD005']);
    });
});
