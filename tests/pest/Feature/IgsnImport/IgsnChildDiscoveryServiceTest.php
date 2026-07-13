<?php

use App\Services\IgsnChildDiscoveryService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

afterEach(function () {
    Mockery::close();
});

describe('IgsnChildDiscoveryService', function () {
    it('returns no children for an invalid parent handle', function () {
        $service = new IgsnChildDiscoveryService;

        expect($service->discoverDirectChildHandles('not a valid handle'))->toBe([]);
    });

    it('discovers direct children from Solr and verifies parent from DIF XML', function () {
        Config::set('datacite.solr', [
            'host' => 'solr.example.com',
            'port' => '443',
            'user' => 'solr-user',
            'password' => 'solr-pass', // ggignore
        ]);

        $matchingDif = '<DIF><supplementalMetadata><record><sample><parent_igsn>ICDP-PARENT_001.2</parent_igsn></sample></record></supplementalMetadata></DIF>';
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

        expect($service->discoverDirectChildHandles('ICDP-PARENT_001.2'))->toBe(['ICDPCHILD001']);

        Http::assertSent(
            fn ($request): bool => $request['q'] === 'parent_igsn:"ICDP-PARENT_001.2" OR dif:"ICDP-PARENT_001.2"',
        );
    });

    it('handles Solr HTTP failures and malformed Solr payloads without discovering children', function () {
        Config::set('datacite.solr', [
            'host' => 'solr.example.com',
            'port' => '443',
            'user' => 'solr-user',
            'password' => 'solr-pass', // ggignore
        ]);

        Http::fake([
            'solr.example.com*' => Http::sequence()
                ->push([], 503)
                ->push(['response' => ['docs' => 'not-an-array']], 200),
        ]);

        DB::shouldReceive('connection')
            ->twice()
            ->with('igsn_legacy')
            ->andThrow(new RuntimeException('Legacy DB unavailable'));

        $service = new IgsnChildDiscoveryService;

        expect($service->discoverDirectChildHandles('ICDPPARENTHTTP'))->toBe([])
            ->and($service->discoverDirectChildHandles('ICDPPARENTPAYLOAD'))->toBe([]);
    });

    it('accepts uppercase Solr IGSN field names and skips malformed documents', function () {
        Config::set('datacite.solr', [
            'host' => 'solr.example.com',
            'port' => '443',
            'user' => 'solr-user',
            'password' => 'solr-pass', // ggignore
        ]);

        $matchingDif = '<DIF><sample><parent_igsn>ICDPPARENTGZIP</parent_igsn></sample></DIF>';

        Http::fake([
            'solr.example.com*' => Http::response([
                'response' => [
                    'docs' => [
                        'not-a-document',
                        ['IGSN' => 'ICDPCHILDGZIP', 'dif' => base64_encode($matchingDif)],
                        ['IGSN' => 'invalid handle', 'dif' => base64_encode($matchingDif)],
                        ['IGSN' => 'ICDPINVALIDXML', 'dif' => '<not-closed'],
                    ],
                ],
            ], 200),
        ]);

        DB::shouldReceive('connection')
            ->with('igsn_legacy')
            ->andThrow(new RuntimeException('Legacy DB unavailable'));

        $service = new IgsnChildDiscoveryService;

        expect($service->discoverDirectChildHandles('ICDPPARENTGZIP'))->toBe(['ICDPCHILDGZIP']);
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
        $builder->shouldReceive('whereRaw')->with('metadata.dif like ? escape ?', ['%ICDPPARENT002%', '\\'])->andReturnSelf();
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

    it('decodes gzipped DIF and ignores unusable legacy DOI rows', function () {
        Config::set('datacite.solr.host', null);
        Config::set('datacite.solr.user', null);
        Config::set('datacite.solr.password', null);

        $matchingDif = '<DIF><sample><parent_igsn>ICDPPARENT_LEGACYGZIP</parent_igsn></sample></DIF>';

        $builder = Mockery::mock();
        DB::shouldReceive('connection')
            ->with('igsn_legacy')
            ->andReturn($builder);

        $builder->shouldReceive('table')->with('metadata')->andReturnSelf();
        $builder->shouldReceive('join')->with('dataset', 'metadata.dataset', '=', 'dataset.id')->andReturnSelf();
        $builder->shouldReceive('whereNotNull')->with('metadata.dif')->andReturnSelf();
        $builder->shouldReceive('whereRaw')->with('metadata.dif like ? escape ?', ['%ICDPPARENT\\_LEGACYGZIP%', '\\'])->andReturnSelf();
        $builder->shouldReceive('orderByDesc')->with('metadata.id')->andReturnSelf();
        $builder->shouldReceive('limit')->with(1000)->andReturnSelf();
        $builder->shouldReceive('select')->with('dataset.doi', 'metadata.dif')->andReturnSelf();
        $builder->shouldReceive('get')->andReturn(collect([
            (object) ['doi' => '10273/ICDPCHILDLEGACYGZIP', 'dif' => gzencode($matchingDif)],
            (object) ['doi' => '', 'dif' => $matchingDif],
            (object) ['doi' => 'ICDPNOSLASH', 'dif' => $matchingDif],
        ]));

        $service = new IgsnChildDiscoveryService;

        expect($service->discoverDirectChildHandles('ICDPPARENT_LEGACYGZIP'))->toBe(['ICDPCHILDLEGACYGZIP']);
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
