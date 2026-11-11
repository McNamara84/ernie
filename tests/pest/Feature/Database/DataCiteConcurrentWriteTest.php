<?php

declare(strict_types=1);

use App\Exceptions\DataCiteWriteSupersededException;
use App\Models\LandingPage;
use App\Models\ResourceDoiPublication;
use App\Services\DataCiteDoiWriteLockService;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteRequestLimiter;
use App\Services\ResourceDoiPublicationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses()->group('mysql-sensitive');
require_once __DIR__.'/../../Support/DataCitePublicationFixtures.php';

it('rejects a stale retry across independent workers sharing Redis', function () {
    config([
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.client_id' => 'test.repo',
        'datacite.test.username' => 'TEST', 'datacite.test.password' => 'test-only',
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
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
    $doi = '10.5880/review-concurrent';
    $resource = App\Models\Resource::factory()->create(['doi' => $doi]);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
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
            Http::fake(function ($request) use ($doi) {
                if ($request->method() === 'PUT') {
                    config(['datacite.landing_page_url_update.minimum_interval_ms' => 1000]);

                    return Http::response([], 503);
                }

                return Http::response(dataCiteRecord($doi));
            });
            try {
                (new DataCiteMemberApiClient($limiter, true))->updateDoi($doi, ['data' => ['attributes' => ['titles' => [['title' => 'Old metadata']]]]]);
                throw new RuntimeException('The old writer replayed its stale payload.');
            } catch (DataCiteWriteSupersededException) {
                expect(Http::recorded(fn ($request) => $request->method() === 'PUT'))->toHaveCount(1);
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
        Http::fake(['*' => Http::response(dataCiteRecord($doi))]);
        $response = (new DataCiteMemberApiClient(new DataCiteRequestLimiter, true))->updateDoi($doi,
            ['data' => ['attributes' => ['titles' => [['title' => 'New metadata']]]]]);
        expect($response->successful())->toBeTrue();
        Http::assertSentCount(2);
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

it('serializes simultaneous first publication and reconciles instead of posting twice', function (): void {
    config([
        'app.url' => 'https://ernie.example',
        'datacite.test_mode' => true, 'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.username' => 'TEST', 'datacite.test.password' => 'test-only',
        'datacite.test.client_id' => 'test.repo', 'datacite.test.prefixes' => ['10.83279'],
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'cache.stores.publication-concurrency' => [
            'driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'cache',
            'prefix' => 'doi-publication:'.Str::uuid().':',
        ],
    ]);
    URL::forceRootUrl('https://ernie.example');
    URL::forceScheme('https');
    $manager = Cache::getFacadeRoot();
    $cache = $manager->store('publication-concurrency');
    Cache::swap($cache);
    $resource = App\Models\Resource::factory()->create(['doi' => null]);
    completeDataCiteFixture($resource);
    LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    DB::commit();
    DB::disconnect();
    app('redis')->purge('cache');
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($sockets === false || ($pid = pcntl_fork()) === -1) {
        throw new RuntimeException('Cannot fork publication worker.');
    }
    if ($pid === 0) {
        fclose($sockets[0]);
        try {
            DB::purge();
            Http::fake(function ($request) use ($sockets) {
                if ($request->method() === 'HEAD') {
                    fwrite($sockets[1], "page-released\n");
                    usleep(300_000);

                    return Http::response('', 200);
                }
                if ($request->method() === 'POST') {
                    Cache::increment('post-count');

                    return Http::failedConnection('Lost response');
                }
                throw new RuntimeException('Unexpected HTTP request.');
            });
            $result = app(ResourceDoiPublicationService::class)->publish($resource, '10.83279');
            fwrite($sockets[1], $result['complete'] ? "unexpected-success\n" : "pending\n");
            exit(0);
        } catch (Throwable $exception) {
            fwrite($sockets[1], $exception->getMessage()."\n");
            exit(1);
        }
    }
    fclose($sockets[1]);
    stream_set_timeout($sockets[0], 15);
    try {
        expect(trim((string) fgets($sockets[0])))->toBe('page-released');
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                Cache::increment('post-count');

                return Http::failedConnection();
            }

            return Http::response(['data' => []]);
        });
        $result = app(ResourceDoiPublicationService::class)->publish($resource, '10.83279');
        expect($result['complete'])->toBeFalse()->and((int) $cache->get('post-count'))->toBe(1);
        expect(trim((string) fgets($sockets[0])))->toBe('pending');
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0)
            ->and(ResourceDoiPublication::where('resource_id', $resource->id)->count())->toBe(1)
            ->and($resource->fresh()->landingPage->is_published)->toBeTrue();
        Http::assertNotSent(fn ($request) => in_array($request->method(), ['POST', 'PUT']));
    } finally {
        fclose($sockets[0]);
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
        foreach (['post-count', 'datacite:member-api:request-history-ms', 'datacite:member-api:background-history-ms'] as $key) {
            $cache->forget($key);
        }
        Cache::swap($manager);
        app('redis')->purge('cache');
        DB::purge();
        App\Models\Resource::whereKey($resource->id)->delete();
        DB::beginTransaction();
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires MySQL 9.7 and independent Redis-backed workers.');
