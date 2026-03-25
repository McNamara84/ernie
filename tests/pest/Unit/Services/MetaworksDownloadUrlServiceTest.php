<?php

declare(strict_types=1);

use App\Services\MetaworksDownloadUrlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

describe('MetaworksDownloadUrlService', function () {
    it('returns empty array when DOI is not found in metaworks', function () {
        $resourceQuery = Mockery::mock();
        $resourceQuery->shouldReceive('where')
            ->with('identifier', '10.5880/unknown.doi')
            ->andReturnSelf();
        $resourceQuery->shouldReceive('select')
            ->with('id')
            ->andReturnSelf();
        $resourceQuery->shouldReceive('first')
            ->andReturnNull();

        $connection = Mockery::mock();
        $connection->shouldReceive('table')
            ->with('resource')
            ->andReturn($resourceQuery);

        DB::shouldReceive('connection')
            ->with('metaworks')
            ->andReturn($connection);

        $service = new MetaworksDownloadUrlService;
        $result = $service->lookupFileUrls('10.5880/unknown.doi');

        expect($result)->toBe(['urls' => [], 'allPublic' => false]);
    });

    it('returns file URLs when DOI is found with public files', function () {
        // Mock the resource lookup
        $resourceQuery = Mockery::mock();
        $resourceQuery->shouldReceive('where')
            ->with('identifier', '10.5880/GFZ.1.2.2024.001')
            ->andReturnSelf();
        $resourceQuery->shouldReceive('select')
            ->with('id')
            ->andReturnSelf();
        $resourceQuery->shouldReceive('first')
            ->andReturn((object) ['id' => 42]);

        // Mock the file lookup (returns all files with visibility info)
        $fileQuery = Mockery::mock();
        $fileQuery->shouldReceive('where')
            ->with('resource_id', 42)
            ->andReturnSelf();
        $fileQuery->shouldReceive('orderBy')
            ->with('id')
            ->andReturnSelf();
        $fileQuery->shouldReceive('get')
            ->with(['url', 'visible'])
            ->andReturn(collect([
                (object) ['url' => 'https://datapub.gfz.de/download/10.5880/GFZ.1.2.2024.001', 'visible' => 'public'],
            ]));

        $connection = Mockery::mock();
        $connection->shouldReceive('table')
            ->with('resource')
            ->andReturn($resourceQuery);
        $connection->shouldReceive('table')
            ->with('file')
            ->andReturn($fileQuery);

        DB::shouldReceive('connection')
            ->with('metaworks')
            ->andReturn($connection);

        $service = new MetaworksDownloadUrlService;
        $result = $service->lookupFileUrls('10.5880/GFZ.1.2.2024.001');

        expect($result['urls'])->toBe([
            'https://datapub.gfz.de/download/10.5880/GFZ.1.2.2024.001',
        ])->and($result['allPublic'])->toBeTrue();
    });

    it('deduplicates identical URLs', function () {
        $resourceQuery = Mockery::mock();
        $resourceQuery->shouldReceive('where')
            ->with('identifier', '10.5880/GFZ.dup.test')
            ->andReturnSelf();
        $resourceQuery->shouldReceive('select')
            ->with('id')
            ->andReturnSelf();
        $resourceQuery->shouldReceive('first')
            ->andReturn((object) ['id' => 99]);

        $fileQuery = Mockery::mock();
        $fileQuery->shouldReceive('where')
            ->with('resource_id', 99)
            ->andReturnSelf();
        $fileQuery->shouldReceive('orderBy')
            ->with('id')
            ->andReturnSelf();
        $fileQuery->shouldReceive('get')
            ->with(['url', 'visible'])
            ->andReturn(collect([
                (object) ['url' => 'https://datapub.gfz.de/download/10.5880/GFZ.dup.test', 'visible' => 'public'],
                (object) ['url' => 'https://datapub.gfz.de/download/10.5880/GFZ.dup.test', 'visible' => 'public'],
                (object) ['url' => 'https://datapub.gfz.de/download/10.5880/GFZ.dup.test', 'visible' => 'public'],
            ]));

        $connection = Mockery::mock();
        $connection->shouldReceive('table')
            ->with('resource')
            ->andReturn($resourceQuery);
        $connection->shouldReceive('table')
            ->with('file')
            ->andReturn($fileQuery);

        DB::shouldReceive('connection')
            ->with('metaworks')
            ->andReturn($connection);

        $service = new MetaworksDownloadUrlService;
        $result = $service->lookupFileUrls('10.5880/GFZ.dup.test');

        expect($result['urls'])->toHaveCount(1)
            ->and($result['urls'][0])->toBe('https://datapub.gfz.de/download/10.5880/GFZ.dup.test')
            ->and($result['allPublic'])->toBeTrue();
    });

    it('returns multiple distinct URLs', function () {
        $resourceQuery = Mockery::mock();
        $resourceQuery->shouldReceive('where')
            ->with('identifier', '10.5880/GFZ.multi.test')
            ->andReturnSelf();
        $resourceQuery->shouldReceive('select')
            ->with('id')
            ->andReturnSelf();
        $resourceQuery->shouldReceive('first')
            ->andReturn((object) ['id' => 55]);

        $fileQuery = Mockery::mock();
        $fileQuery->shouldReceive('where')
            ->with('resource_id', 55)
            ->andReturnSelf();
        $fileQuery->shouldReceive('orderBy')
            ->with('id')
            ->andReturnSelf();
        $fileQuery->shouldReceive('get')
            ->with(['url', 'visible'])
            ->andReturn(collect([
                (object) ['url' => 'https://datapub.gfz.de/download/10.5880/GFZ.multi.test/file1.zip', 'visible' => 'public'],
                (object) ['url' => 'https://datapub.gfz.de/download/10.5880/GFZ.multi.test/file2.zip', 'visible' => 'public'],
            ]));

        $connection = Mockery::mock();
        $connection->shouldReceive('table')
            ->with('resource')
            ->andReturn($resourceQuery);
        $connection->shouldReceive('table')
            ->with('file')
            ->andReturn($fileQuery);

        DB::shouldReceive('connection')
            ->with('metaworks')
            ->andReturn($connection);

        $service = new MetaworksDownloadUrlService;
        $result = $service->lookupFileUrls('10.5880/GFZ.multi.test');

        expect($result['urls'])->toHaveCount(2)
            ->and($result['urls'][0])->toBe('https://datapub.gfz.de/download/10.5880/GFZ.multi.test/file1.zip')
            ->and($result['urls'][1])->toBe('https://datapub.gfz.de/download/10.5880/GFZ.multi.test/file2.zip')
            ->and($result['allPublic'])->toBeTrue();
    });

    it('filters out non-HTTP URLs from legacy data', function () {
        $resourceQuery = Mockery::mock();
        $resourceQuery->shouldReceive('where')->with('identifier', '10.5880/GFZ.xss.test')->andReturnSelf();
        $resourceQuery->shouldReceive('select')->with('id')->andReturnSelf();
        $resourceQuery->shouldReceive('first')->andReturn((object) ['id' => 77]);

        $fileQuery = Mockery::mock();
        $fileQuery->shouldReceive('where')->with('resource_id', 77)->andReturnSelf();
        $fileQuery->shouldReceive('orderBy')->with('id')->andReturnSelf();
        $fileQuery->shouldReceive('get')->with(['url', 'visible'])->andReturn(collect([
            (object) ['url' => 'https://datapub.gfz.de/download/safe.zip', 'visible' => 'public'],
            (object) ['url' => 'javascript:alert(1)', 'visible' => 'public'],
            (object) ['url' => 'data:text/html,<script>alert(1)</script>', 'visible' => 'public'],
            (object) ['url' => 'ftp://old-server/file.dat', 'visible' => 'public'],
            (object) ['url' => 'http://valid-http.example.com/file.dat', 'visible' => 'public'],
        ]));

        $connection = Mockery::mock();
        $connection->shouldReceive('table')->with('resource')->andReturn($resourceQuery);
        $connection->shouldReceive('table')->with('file')->andReturn($fileQuery);

        DB::shouldReceive('connection')->with('metaworks')->andReturn($connection);
        Log::shouldReceive('warning')->times(3); // 3 non-http URLs skipped

        $service = new MetaworksDownloadUrlService;
        $result = $service->lookupFileUrls('10.5880/GFZ.xss.test');

        expect($result['urls'])->toHaveCount(2)
            ->and($result['urls'][0])->toBe('https://datapub.gfz.de/download/safe.zip')
            ->and($result['urls'][1])->toBe('http://valid-http.example.com/file.dat')
            ->and($result['allPublic'])->toBeTrue();
    });

    it('filters out URLs exceeding 2048 characters', function () {
        $longUrl = 'https://datapub.gfz.de/download/' . str_repeat('a', 2048);

        $resourceQuery = Mockery::mock();
        $resourceQuery->shouldReceive('where')->with('identifier', '10.5880/GFZ.long.test')->andReturnSelf();
        $resourceQuery->shouldReceive('select')->with('id')->andReturnSelf();
        $resourceQuery->shouldReceive('first')->andReturn((object) ['id' => 88]);

        $fileQuery = Mockery::mock();
        $fileQuery->shouldReceive('where')->with('resource_id', 88)->andReturnSelf();
        $fileQuery->shouldReceive('orderBy')->with('id')->andReturnSelf();
        $fileQuery->shouldReceive('get')->with(['url', 'visible'])->andReturn(collect([
            (object) ['url' => $longUrl, 'visible' => 'public'],
            (object) ['url' => 'https://datapub.gfz.de/download/short.zip', 'visible' => 'public'],
        ]));

        $connection = Mockery::mock();
        $connection->shouldReceive('table')->with('resource')->andReturn($resourceQuery);
        $connection->shouldReceive('table')->with('file')->andReturn($fileQuery);

        DB::shouldReceive('connection')->with('metaworks')->andReturn($connection);
        Log::shouldReceive('warning')->once(); // 1 long URL skipped

        $service = new MetaworksDownloadUrlService;
        $result = $service->lookupFileUrls('10.5880/GFZ.long.test');

        expect($result['urls'])->toHaveCount(1)
            ->and($result['urls'][0])->toBe('https://datapub.gfz.de/download/short.zip')
            ->and($result['allPublic'])->toBeTrue();
    });

    it('returns allPublic false when any file is non-public', function () {
        $resourceQuery = Mockery::mock();
        $resourceQuery->shouldReceive('where')->with('identifier', '10.5880/GFZ.mixed.vis')->andReturnSelf();
        $resourceQuery->shouldReceive('select')->with('id')->andReturnSelf();
        $resourceQuery->shouldReceive('first')->andReturn((object) ['id' => 33]);

        $fileQuery = Mockery::mock();
        $fileQuery->shouldReceive('where')->with('resource_id', 33)->andReturnSelf();
        $fileQuery->shouldReceive('orderBy')->with('id')->andReturnSelf();
        $fileQuery->shouldReceive('get')->with(['url', 'visible'])->andReturn(collect([
            (object) ['url' => 'https://datapub.gfz.de/download/public-file.zip', 'visible' => 'public'],
            (object) ['url' => 'https://datapub.gfz.de/download/private-file.zip', 'visible' => 'private'],
        ]));

        $connection = Mockery::mock();
        $connection->shouldReceive('table')->with('resource')->andReturn($resourceQuery);
        $connection->shouldReceive('table')->with('file')->andReturn($fileQuery);

        DB::shouldReceive('connection')->with('metaworks')->andReturn($connection);

        $service = new MetaworksDownloadUrlService;
        $result = $service->lookupFileUrls('10.5880/GFZ.mixed.vis');

        expect($result['urls'])->toHaveCount(2)
            ->and($result['allPublic'])->toBeFalse();
    });

    it('returns allPublic false when all files are non-public', function () {
        $resourceQuery = Mockery::mock();
        $resourceQuery->shouldReceive('where')->with('identifier', '10.5880/GFZ.all.priv')->andReturnSelf();
        $resourceQuery->shouldReceive('select')->with('id')->andReturnSelf();
        $resourceQuery->shouldReceive('first')->andReturn((object) ['id' => 44]);

        $fileQuery = Mockery::mock();
        $fileQuery->shouldReceive('where')->with('resource_id', 44)->andReturnSelf();
        $fileQuery->shouldReceive('orderBy')->with('id')->andReturnSelf();
        $fileQuery->shouldReceive('get')->with(['url', 'visible'])->andReturn(collect([
            (object) ['url' => 'https://datapub.gfz.de/download/internal.zip', 'visible' => 'internal'],
        ]));

        $connection = Mockery::mock();
        $connection->shouldReceive('table')->with('resource')->andReturn($resourceQuery);
        $connection->shouldReceive('table')->with('file')->andReturn($fileQuery);

        DB::shouldReceive('connection')->with('metaworks')->andReturn($connection);

        $service = new MetaworksDownloadUrlService;
        $result = $service->lookupFileUrls('10.5880/GFZ.all.priv');

        expect($result['urls'])->toHaveCount(1)
            ->and($result['urls'][0])->toBe('https://datapub.gfz.de/download/internal.zip')
            ->and($result['allPublic'])->toBeFalse();
    });
});
