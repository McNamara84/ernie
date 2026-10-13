<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Services\GeoLocations\GeoLocationWriterService;
use Illuminate\Support\Facades\DB;

uses()->group('mysql-sensitive');

it('serializes two incoming copies while the resource is locked', function () {
    $resource = Resource::factory()->create();
    $resourceId = $resource->id;
    // Publish this test fixture to independent connections, then fork without sharing a PDO socket.
    DB::commit();
    DB::disconnect();
    $children = [];
    try {
        for ($index = 0; $index < 2; $index++) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($sockets === false) {
                throw new RuntimeException('Unable to create worker sockets.');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork a writer.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                fgets($sockets[1]);
                try {
                    DB::purge();
                    $result = app(GeoLocationWriterService::class)->write(Resource::findOrFail($resourceId), [
                        'geo_type' => 'point', 'point_latitude' => 52.38, 'point_longitude' => 13.06,
                    ]);
                    fwrite($sockets[1], json_encode(['id' => $result->location->id, 'duplicate' => $result->duplicate], JSON_THROW_ON_ERROR)."\n");
                    fclose($sockets[1]);
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($sockets[1], json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n");
                    fclose($sockets[1]);
                    exit(1);
                }
            }
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 15);
            $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }
        DB::beginTransaction();
        Resource::whereKey($resourceId)->lockForUpdate()->firstOrFail();
        foreach ($children as $child) {
            fwrite($child['socket'], "go\n");
        }
        usleep(250000);
        DB::commit();
        $results = [];
        foreach ($children as $child) {
            $line = fgets($child['socket']);
            expect($line)->not->toBeFalse();
            $results[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($child['pid'], $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
            fclose($child['socket']);
        }
        expect($results[0]['id'])->toBe($results[1]['id'])
            ->and(array_column($results, 'duplicate'))->toContain(true, false)
            ->and(Resource::findOrFail($resourceId)->geoLocations()->count())->toBe(1);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Resource::whereKey($resourceId)->delete();
        DB::beginTransaction();
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires the pinned MySQL 9.7 test server.');
