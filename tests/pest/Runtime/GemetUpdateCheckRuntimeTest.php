<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\User;
use App\Services\GemetApiService;
use GuzzleHttp\TransferStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GemetRuntimeServer;
use Tests\Support\GemetUpdateCheckFixture as Gemet;
use Tests\TestCase;

// Explicit opt-in: real 30/90-second waits do not belong in the normal suite.
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $disk = 'gemet-runtime-'.bin2hex(random_bytes(6));
    Storage::fake($disk);
    config(['filesystems.default' => $disk]);
    Cache::flush();
    $this->transfers = [];
    Http::globalOptions(['on_stats' => function (TransferStats $stats): void {
        $this->transfers[] = [
            'seconds' => $stats->getTransferTime(),
            'curl_error' => $stats->getHandlerErrorData(),
        ];
    }]);
});

afterEach(function (): void {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

it('really overlaps four TCP requests and completes successive pools out of order', function (): void {
    $this->server = new GemetRuntimeServer('parallel');
    $started = hrtime(true);
    expect((new GemetApiService)->fetchHierarchyNodeCountForUpdateCheck())->toBe(55);
    $elapsed = (hrtime(true) - $started) / 1e9;
    $metrics = $this->server->metrics();
    $finished = array_values(array_filter($metrics['events'], fn (array $event): bool => $event['type'] === 'finish' && str_starts_with($event['uri'], Gemet::GROUP) && $event['uri'] !== Gemet::GROUP));
    expect($metrics['maximumActive'])->toBe(4)
        ->and($metrics['active'])->toBe(0)
        ->and($metrics['attempts'])->toHaveCount(11)
        ->and($finished[0]['uri'])->toBe(Gemet::GROUP.'99');
    foreach ($metrics['attempts'] as $attempts) {
        expect($attempts)->toBe(1);
    }
    $firstBatchFinished = max(array_column(array_filter($finished, fn (array $event): bool => (int) basename($event['uri']) < 100), 'seconds'));
    $nextBatchStarted = min(array_column(array_filter($metrics['events'], fn (array $event): bool => $event['type'] === 'start' && (int) basename($event['uri']) >= 100), 'seconds'));
    expect($nextBatchStarted)->toBeGreaterThanOrEqual($firstBatchFinished);
    fwrite(STDOUT, json_encode(['scenario' => 'parallel', 'elapsed_seconds' => $elapsed, 'maximum_active' => $metrics['maximumActive'], 'count' => 55]).PHP_EOL);
});

it('recovers a real cURL 28 timeout and retries only the failed group', function (): void {
    $this->server = new GemetRuntimeServer('timeout');
    $started = hrtime(true);
    expect((new GemetApiService)->fetchHierarchyNodeCountForUpdateCheck())->toBe(55);
    $elapsed = (hrtime(true) - $started) / 1e9;
    $metrics = $this->server->metrics();
    expect($elapsed)->toBeGreaterThanOrEqual(30)->toBeLessThan(40)
        ->and($metrics['maximumActive'])->toBe(4)
        ->and($metrics['attempts'][Gemet::GROUP.'96'])->toBe(2)
        ->and(array_column($this->transfers, 'curl_error'))->toContain(28);
    foreach ($metrics['attempts'] as $uri => $attempts) {
        expect($attempts)->toBe($uri === Gemet::GROUP.'96' ? 2 : 1);
    }
    fwrite(STDOUT, json_encode(['scenario' => 'real-timeout-recovery', 'elapsed_seconds' => $elapsed, 'maximum_active' => $metrics['maximumActive'], 'attempts_group_96' => 2, 'count' => 55]).PHP_EOL);
});

it('honors Retry-After over real HTTP without repeating successful groups', function (): void {
    $this->server = new GemetRuntimeServer('retry-after');
    expect((new GemetApiService)->fetchHierarchyNodeCountForUpdateCheck())->toBe(55);
    $metrics = $this->server->metrics();
    $events = array_values(array_filter($metrics['events'], fn (array $event): bool => $event['uri'] === Gemet::GROUP.'96'));
    $delay = $events[2]['seconds'] - $events[1]['seconds'];
    expect($delay)->toBeGreaterThanOrEqual(1)
        ->and($metrics['attempts'][Gemet::GROUP.'96'])->toBe(2);
    foreach ($metrics['attempts'] as $uri => $attempts) {
        expect($attempts)->toBe($uri === Gemet::GROUP.'96' ? 2 : 1);
    }
    fwrite(STDOUT, json_encode(['scenario' => 'retry-after', 'observed_delay_seconds' => $delay, 'count' => 55]).PHP_EOL);
});

it('enforces the real shared 90 second budget across pools and recovers without a cache reset', function (): void {
    $this->server = new GemetRuntimeServer('deadline', 20);
    $this->actingAs(User::factory()->admin()->create());
    $started = hrtime(true);
    $this->post('/thesauri/gemet/check')->assertStatus(503)->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('error', 'Failed to check remote status: GEMET update check exceeded its time limit. Please try again later.');
    $elapsed = (hrtime(true) - $started) / 1e9;
    $metrics = $this->server->metrics();
    expect($elapsed)->toBeGreaterThanOrEqual(89.5)->toBeLessThan(95)
        ->and($metrics['maximumActive'])->toBe(4)
        ->and($metrics['attempts'])->toHaveCount(18)
        ->and($metrics['attempts'])->not->toHaveKey(Gemet::GROUP.'112')
        ->and(Cache::has(CacheKey::GEMET_THESAURUS->key('remote_count')))->toBeFalse();
    foreach ($metrics['attempts'] as $attempts) {
        expect($attempts)->toBe(1);
    }
    $timeouts = array_values(array_filter($this->transfers, fn (array $transfer): bool => $transfer['curl_error'] === 28));
    expect($timeouts)->toHaveCount(4);
    foreach ($timeouts as $timeout) {
        expect($timeout['seconds'])->toBeGreaterThan(15)->toBeLessThan(20);
    }
    $this->server->configure('success', 20);
    $this->postJson('/thesauri/gemet/check')->assertOk()->assertJsonPath('remoteCount', 231);
    $requests = count($this->server->metrics()['events']);
    $this->postJson('/thesauri/gemet/check')->assertOk()->assertJsonPath('remoteCount', 231);
    expect(count($this->server->metrics()['events']))->toBe($requests);
    fwrite(STDOUT, json_encode(['scenario' => 'shared-deadline', 'elapsed_seconds' => $elapsed, 'maximum_active' => $metrics['maximumActive'], 'requests_before_abort' => 18, 'last_pool_timeout_seconds' => max(array_column($timeouts, 'seconds')), 'recovery_count' => 231, 'warm_cache_requests' => 0]).PHP_EOL);
});
