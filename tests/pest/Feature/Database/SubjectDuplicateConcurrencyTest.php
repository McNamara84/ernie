<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Services\Subjects\SubjectEquivalentCleanupService;
use Illuminate\Support\Facades\DB;
use Tests\Support\SubjectDuplicateFixtures as F;

uses()->group('mysql-sensitive');

it('serializes reviewed repairs and protects concurrent subject edits', function (bool $edit): void {
    F::fake();
    $resource = F::resource();
    $resourceId = $resource->id;
    $report = app(SubjectEquivalentCleanupService::class)->audit(dois: [$resource->doi]);
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
                throw new RuntimeException('Unable to fork a cleanup worker.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                fgets($sockets[1]);
                DB::purge();
                $result = app(SubjectEquivalentCleanupService::class)->apply($report, static function (array $entry): void {});
                fwrite($sockets[1], json_encode($result[0], JSON_THROW_ON_ERROR)."\n");
                fclose($sockets[1]);
                exit(0);
            }
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 30);
            $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }
        DB::beginTransaction();
        $locked = Resource::whereKey($resourceId)->lockForUpdate()->firstOrFail();
        foreach ($children as $child) {
            fwrite($child['socket'], "go\n");
        }
        if ($edit) {
            $locked->subjects()->orderBy('id')->firstOrFail()->update(['value' => 'AQUIFERS']);
        }
        usleep(250000);
        DB::commit();
        $statuses = [];
        foreach ($children as $child) {
            $line = fgets($child['socket']);
            expect($line)->not->toBeFalse();
            $statuses[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR)['status'];
            pcntl_waitpid($child['pid'], $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
            fclose($child['socket']);
        }
        sort($statuses);
        expect($statuses)->toBe($edit ? ['error', 'error'] : ['applied', 'unchanged'])
            ->and($resource->subjects()->count())->toBe($edit ? 2 : 1);
        if ($edit) {
            expect($resource->subjects()->orderBy('id')->firstOrFail()->value)->toBe('AQUIFERS');
        }
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Resource::whereKey($resourceId)->delete();
        DB::beginTransaction();
    }
})->with(['simultaneous repairs' => false, 'concurrent editor change' => true])
    ->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires the pinned MySQL 9.7 test server.');
