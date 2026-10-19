<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Models\User;
use App\Services\ThesaurusReviewReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

uses()->group('mysql-sensitive');

it('preserves one completion when two users insert the same quarter concurrently', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19T10:00:00Z'));
    config(['app.timezone' => 'UTC']);
    $users = User::factory()->admin()->count(2)->create();
    $key = 'thesauri_review_completed:2026-Q4';
    DB::commit();
    DB::disconnect();
    $children = [];

    try {
        foreach ($users as $user) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($sockets === false) {
                throw new RuntimeException('Unable to create review worker sockets.');
            }
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork a review worker.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                stream_set_timeout($sockets[1], 15);
                try {
                    DB::purge();
                    // Both firstOrCreate calls must see the missing row before either inserts it.
                    Setting::creating(function () use ($sockets): void {
                        fwrite($sockets[1], "ready\n");
                        if (fgets($sockets[1]) !== "go\n") {
                            throw new RuntimeException('Timed out waiting for the competing review.');
                        }
                    });
                    $result = app(ThesaurusReviewReminderService::class)->complete('2026-Q4', $user);
                    fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                    fclose($sockets[1]);
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($sockets[1], json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR)."\n");
                    fclose($sockets[1]);
                    exit(1);
                }
            }
            fclose($sockets[1]);
            stream_set_timeout($sockets[0], 20);
            $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }

        foreach ($children as $child) {
            expect(fgets($child['socket']))->toBe("ready\n");
        }
        foreach ($children as $child) {
            fwrite($child['socket'], "go\n");
        }
        $results = [];
        foreach ($children as $child) {
            $line = fgets($child['socket']);
            expect($line)->not->toBeFalse();
            $results[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            pcntl_waitpid($child['pid'], $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        expect($results[0])->toBe($results[1])->and($results[0]['isDue'])->toBeFalse();
        expect(Setting::where('key', $key)->count())->toBe(1);
        $value = json_decode(Setting::getValue($key), true, flags: JSON_THROW_ON_ERROR);
        expect($value['completed_by'])->toBeIn($users->modelKeys());
    } finally {
        foreach ($children as $child) {
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
        }
        Setting::where('key', $key)->delete();
        User::whereKey($users->modelKeys())->delete();
        DB::beginTransaction();
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires the pinned MySQL 9.7 test server.');
