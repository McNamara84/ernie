<?php

declare(strict_types=1);

use App\Console\Commands\ObservePublicTrafficAvailability;
use App\Console\Commands\PrunePublicTraffic;
use App\Enums\CacheKey;
use App\Models\PublicTrafficHourlyStatistic;
use App\Services\PublicTraffic\PublicTrafficAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

covers(ObservePublicTrafficAvailability::class, PrunePublicTraffic::class, PublicTrafficAvailabilityService::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-09 12:15:00 UTC');
    Cache::flush();
    config()->set([
        'public_traffic.enabled' => true,
        'public_traffic.health_url' => 'https://ernie.example.test/health',
        'public_traffic.retention_days' => 400,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('observes each minute once after a successful public health and cache check', function (): void {
    Http::fake([
        'https://ernie.example.test/health' => Http::response(['status' => 'ok']),
    ]);

    $this->artisan('public-traffic:observe-availability')->assertSuccessful();
    $this->artisan('public-traffic:observe-availability')->assertSuccessful();

    $row = PublicTrafficHourlyStatistic::query()->sole();

    expect($row->observed_minute_count)->toBe(1)
        ->and($row->last_observed_minute_at->toIso8601String())->toBe('2026-09-09T12:15:00+00:00');
    Http::assertSentCount(2);
});

it('can mark all sixty distinct minutes of an hour', function (): void {
    Http::fake([
        'https://ernie.example.test/health' => Http::response(['status' => 'ok']),
    ]);

    $service = app(PublicTrafficAvailabilityService::class);

    for ($minute = 0; $minute < 60; $minute++) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-09 12:00:00 UTC')->addMinutes($minute));
        $service->observe();
    }

    expect(PublicTrafficHourlyStatistic::query()->sole()->observed_minute_count)->toBe(60);
});

it('fails without recording an observation when the public health response is unhealthy', function (): void {
    Http::fake([
        'https://ernie.example.test/health' => Http::response(['status' => 'down'], 503),
    ]);

    $this->artisan('public-traffic:observe-availability')->assertFailed();

    expect(PublicTrafficHourlyStatistic::query()->count())->toBe(0);
});

it('fails without recording when the public health request cannot connect', function (): void {
    Http::fake([
        'https://ernie.example.test/health' => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    $this->artisan('public-traffic:observe-availability')->assertFailed();

    expect(PublicTrafficHourlyStatistic::query()->count())->toBe(0);
});

it('requires the exact healthy JSON response even after an HTTP success', function (): void {
    Http::fake([
        'https://ernie.example.test/health' => Http::response(['healthy' => true]),
    ]);

    $this->artisan('public-traffic:observe-availability')->assertFailed();

    expect(PublicTrafficHourlyStatistic::query()->count())->toBe(0);
});

it('fails without recording when the shared cache is unavailable', function (): void {
    Http::fake([
        'https://ernie.example.test/health' => Http::response(['status' => 'ok']),
    ]);
    Cache::shouldReceive('put')
        ->once()
        ->withArgs(static fn (string $key, string $value, int $ttl): bool => str_starts_with(
            $key,
            CacheKey::PUBLIC_TRAFFIC_HEALTH_PROBE->key().':',
        ) && strlen($value) === 24 && $ttl === CacheKey::PUBLIC_TRAFFIC_HEALTH_PROBE->ttl())
        ->andThrow(new RuntimeException('cache unavailable'));
    Cache::shouldReceive('forget')
        ->once()
        ->with(Mockery::on(static fn (string $key): bool => str_starts_with(
            $key,
            CacheKey::PUBLIC_TRAFFIC_HEALTH_PROBE->key().':',
        )))
        ->andReturnTrue();
    Cache::shouldReceive('add')
        ->once()
        ->with(
            CacheKey::PUBLIC_TRAFFIC_WARNING->key('availability'),
            true,
            CacheKey::PUBLIC_TRAFFIC_WARNING->ttl(),
        )
        ->andReturnTrue();

    $this->artisan('public-traffic:observe-availability')->assertFailed();

    expect(PublicTrafficHourlyStatistic::query()->count())->toBe(0);
});

it('ignores an older minute after a newer minute has already been observed', function (): void {
    Http::fake([
        'https://ernie.example.test/health' => Http::response(['status' => 'ok']),
    ]);

    $service = app(PublicTrafficAvailabilityService::class);
    $service->observe();
    CarbonImmutable::setTestNow('2026-09-09 12:14:00 UTC');
    $service->observe();

    $row = PublicTrafficHourlyStatistic::query()->sole();
    expect($row->observed_minute_count)->toBe(1)
        ->and($row->last_observed_minute_at->toIso8601String())->toBe('2026-09-09T12:15:00+00:00');
});

it('skips availability observation successfully when analytics are disabled', function (): void {
    config()->set('public_traffic.enabled', false);

    $this->artisan('public-traffic:observe-availability')
        ->expectsOutputToContain('Public traffic analytics are disabled')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('prunes only aggregates older than the configured retention boundary', function (): void {
    PublicTrafficHourlyStatistic::query()->create([
        'bucket_started_at' => CarbonImmutable::now('UTC')->subDays(400)->subHour(),
    ]);
    PublicTrafficHourlyStatistic::query()->create([
        'bucket_started_at' => CarbonImmutable::now('UTC')->subDays(400)->startOfHour(),
    ]);

    $this->artisan('public-traffic:prune')->assertSuccessful();

    expect(PublicTrafficHourlyStatistic::query()->count())->toBe(1);
});
