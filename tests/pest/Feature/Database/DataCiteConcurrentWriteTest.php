<?php

declare(strict_types=1);

use App\Exceptions\DataCiteWriteSupersededException;
use App\Services\DataCiteDoiWriteLockService;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteRequestLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses()->group('mysql-sensitive');

it('rejects a stale retry across independent workers sharing Redis', function () {
    config([
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.username' => 'TEST', 'datacite.test.password' => 'test-only',
        'datacite.landing_page_url_update.minimum_interval_ms' => 1000,
        'cache.stores.review-concurrency' => [
            'driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'cache',
            'prefix' => 'editor-review:'.Str::uuid().':',
        ],
    ]);
    $cacheManager = Cache::getFacadeRoot();
    $cache = $cacheManager->store('review-concurrency');
    Cache::swap($cache);
    // Forked workers must own independent MySQL and Redis connections.
    app('redis')->purge('cache');
    DB::commit();
    DB::disconnect();
    $doi = '10.5880/review-concurrent';
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($sockets === false || ($pid = pcntl_fork()) === -1) {
        throw new RuntimeException('Cannot start the competing DataCite worker.');
    }
    if ($pid === 0) {
        fclose($sockets[0]);
        try {
            DB::purge();
            $limiter = new class($sockets[1]) extends DataCiteRequestLimiter
            {
                private bool $notified = false;

                public function __construct(private $socket) {}

                protected function pause(int $milliseconds): void
                {
                    if (! $this->notified) {
                        if (DataCiteDoiWriteLockService::hasHeldLock()) {
                            throw new RuntimeException('Worker retained the DOI lock while waiting.');
                        }
                        $this->notified = true;
                        fwrite($this->socket, "waiting-after-503\n");
                        stream_set_timeout($this->socket, 15);
                        if (fgets($this->socket) === false) {
                            throw new RuntimeException('The newer writer did not finish.');
                        }
                    }
                    parent::pause($milliseconds);
                }
            };
            Http::fakeSequence()->push([], 503)->push([], 200);
            try {
                (new DataCiteMemberApiClient($limiter, true))->updateDoi($doi, ['data' => ['attributes' => ['titles' => [['title' => 'Old metadata']]]]]);
                throw new RuntimeException('The old writer replayed its stale payload.');
            } catch (DataCiteWriteSupersededException) {
                Http::assertSentCount(1);
                fwrite($sockets[1], "superseded\n");
            }
            exit(0);
        } catch (Throwable $exception) {
            fwrite($sockets[1], 'error: '.$exception->getMessage()."\n");
            exit(1);
        }
    }
    fclose($sockets[1]);
    stream_set_timeout($sockets[0], 15);
    try {
        expect(trim((string) fgets($sockets[0])))->toBe('waiting-after-503');
        Http::fake(['*' => Http::response([], 200)]);
        $response = (new DataCiteMemberApiClient(new DataCiteRequestLimiter, true))->updateDoi($doi,
            ['data' => ['attributes' => ['titles' => [['title' => 'New metadata']]]]]);
        expect($response->successful())->toBeTrue();
        Http::assertSentCount(1);
        fwrite($sockets[0], "newer-write-completed\n");
        expect(trim((string) fgets($sockets[0])))->toBe('superseded');
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
    } finally {
        fclose($sockets[0]);
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
        // Delete only this test's namespaced keys, never flush a shared Redis DB.
        foreach (['request-history-ms', 'background-history-ms', 'interactive-waiting-until-ms', 'cooldown-until-ms'] as $key) {
            $cache->forget('datacite:member-api:'.$key);
        }
        $cache->forget('datacite:doi-write:'.hash('sha256', 'test:'.$doi).':revision');
        Cache::swap($cacheManager);
        app('redis')->purge('cache');
        DB::purge();
        DB::beginTransaction();
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires MySQL 9.7 and independent Redis-backed workers.');
