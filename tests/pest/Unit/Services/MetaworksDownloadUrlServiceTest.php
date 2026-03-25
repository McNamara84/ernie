<?php

declare(strict_types=1);

use App\Services\MetaworksDownloadUrlService;
use Illuminate\Support\Facades\DB;

describe('MetaworksDownloadUrlService', function () {
    it('returns empty array when DOI is not found in metaworks', function () {
        DB::shouldReceive('connection')
            ->with('metaworks')
            ->andReturnSelf();

        DB::shouldReceive('table')
            ->with('resource')
            ->andReturnSelf();

        DB::shouldReceive('where')
            ->with('identifier', '10.5880/unknown.doi')
            ->andReturnSelf();

        DB::shouldReceive('select')
            ->with('id')
            ->andReturnSelf();

        DB::shouldReceive('first')
            ->andReturnNull();

        $service = new MetaworksDownloadUrlService;
        $result = $service->lookupFileUrls('10.5880/unknown.doi');

        expect($result)->toBe([]);
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

        // Mock the file lookup
        $fileQuery = Mockery::mock();
        $fileQuery->shouldReceive('where')
            ->with('resource_id', 42)
            ->andReturnSelf();
        $fileQuery->shouldReceive('where')
            ->with('visible', 'public')
            ->andReturnSelf();
        $fileQuery->shouldReceive('orderBy')
            ->with('id')
            ->andReturnSelf();
        $fileQuery->shouldReceive('pluck')
            ->with('url')
            ->andReturn(collect([
                'https://datapub.gfz.de/download/10.5880/GFZ.1.2.2024.001',
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

        expect($result)->toBe([
            'https://datapub.gfz.de/download/10.5880/GFZ.1.2.2024.001',
        ]);
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
        $fileQuery->shouldReceive('where')
            ->with('visible', 'public')
            ->andReturnSelf();
        $fileQuery->shouldReceive('orderBy')
            ->with('id')
            ->andReturnSelf();
        $fileQuery->shouldReceive('pluck')
            ->with('url')
            ->andReturn(collect([
                'https://datapub.gfz.de/download/10.5880/GFZ.dup.test',
                'https://datapub.gfz.de/download/10.5880/GFZ.dup.test',
                'https://datapub.gfz.de/download/10.5880/GFZ.dup.test',
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

        expect($result)->toHaveCount(1)
            ->and($result[0])->toBe('https://datapub.gfz.de/download/10.5880/GFZ.dup.test');
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
        $fileQuery->shouldReceive('where')
            ->with('visible', 'public')
            ->andReturnSelf();
        $fileQuery->shouldReceive('orderBy')
            ->with('id')
            ->andReturnSelf();
        $fileQuery->shouldReceive('pluck')
            ->with('url')
            ->andReturn(collect([
                'https://datapub.gfz.de/download/10.5880/GFZ.multi.test/file1.zip',
                'https://datapub.gfz.de/download/10.5880/GFZ.multi.test/file2.zip',
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

        expect($result)->toHaveCount(2)
            ->and($result[0])->toBe('https://datapub.gfz.de/download/10.5880/GFZ.multi.test/file1.zip')
            ->and($result[1])->toBe('https://datapub.gfz.de/download/10.5880/GFZ.multi.test/file2.zip');
    });
});
