<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Jobs\SendDataEmbargoNotificationJob;
use App\Mail\DataEmbargoExpired;
use App\Models\DateType;
use App\Models\Resource;
use App\Services\DataPublicationTeamRecipientService;
use App\Services\EmbargoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses()->group('mysql-sensitive');

it('claims a due notification once when two MySQL workers deliver concurrently', function (): void {
    expect(DB::selectOne('SELECT VERSION() AS version')->version)->toStartWith('9.7.');
    $resource = Resource::factory()->create(['access_level' => AccessLevel::EMBARGOED]);
    $type = DateType::firstOrCreate(['slug' => 'Available'], ['name' => 'Available', 'is_active' => true]);
    $resource->dates()->create(['date_type_id' => $type->id, 'date_value' => '2020-01-01']);
    $noticeId = DB::table('data_embargo_notifications')->insertGetId([
        'resource_id' => $resource->id, 'available_date' => '2020-01-01', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::commit();
    DB::disconnect();
    $children = [];
    try {
        for ($worker = 0; $worker < 2; $worker++) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($sockets === false) {
                throw new RuntimeException('Unable to create worker sockets.');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork notification worker.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                stream_set_timeout($sockets[1], 20);
                try {
                    DB::purge();
                    Mail::fake();
                    config(['mail.landing_page_contact_cc' => 'team@example.org']);
                    fwrite($sockets[1], "ready\n");
                    if (fgets($sockets[1]) !== "go\n") {
                        throw new RuntimeException('Worker barrier timed out.');
                    }
                    (new SendDataEmbargoNotificationJob($noticeId))->handle(app(DataPublicationTeamRecipientService::class), app(EmbargoService::class));
                    fwrite($sockets[1], (string) Mail::sent(DataEmbargoExpired::class)->count()."\n");
                    fclose($sockets[1]);
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($sockets[1], 'error: '.$exception->getMessage()."\n");
                    exit(1);
                }
            }
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 25);
            $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }
        foreach ($children as $child) {
            expect(fgets($child['socket']))->toBe("ready\n");
        }
        foreach ($children as $child) {
            fwrite($child['socket'], "go\n");
        }
        $delivered = 0;
        foreach ($children as $child) {
            $line = fgets($child['socket']);
            expect($line)->toBeIn(["0\n", "1\n"]);
            $delivered += (int) $line;
            pcntl_waitpid($child['pid'], $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        expect($delivered)->toBe(1)
            ->and(DB::table('data_embargo_notifications')->where('id', $noticeId)->value('status'))->toBe('sent');
    } finally {
        foreach ($children as $child) {
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
        }
        Resource::whereKey($resource->id)->delete();
        DB::beginTransaction();
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires the pinned MySQL 9.7 test server.');
