<?php

declare(strict_types=1);

use App\Enums\SystemMetricsPeriod;
use App\Models\SystemMetricSample;
use App\Models\User;
use App\Services\SystemMetricsHistoryService;
use Carbon\CarbonImmutable;

function createSystemMetricSample(string $recordedAt, ?float $cpu, float $memory = 50.0): SystemMetricSample
{
    return SystemMetricSample::query()->create([
        'recorded_at' => $recordedAt,
        'cpu_usage_percent' => $cpu,
        'memory_usage_percent' => $memory,
        'memory_used_bytes' => 512,
        'memory_total_bytes' => 1024,
        'cpu_total_ticks' => 1000,
        'cpu_idle_ticks' => 500,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-06 12:00:30 UTC');
    config()->set('system_metrics.enabled', true);
    config()->set('system_metrics.stale_after_seconds', 180);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns fixed five-minute buckets with averages and explicit gaps for one day', function (): void {
    createSystemMetricSample('2026-09-05 12:00:00', 99.0, 99.0);
    createSystemMetricSample('2026-09-05 12:01:00', 10.0, 40.0);
    createSystemMetricSample('2026-09-05 12:04:00', 30.0, 60.0);
    createSystemMetricSample('2026-09-06 12:00:00', 25.0, 75.0);

    $history = app(SystemMetricsHistoryService::class)->history(SystemMetricsPeriod::DAY);

    expect($history['period'])->toBe('day')
        ->and($history['bucket_minutes'])->toBe(5)
        ->and($history['from'])->toBe('2026-09-05T12:00:00+00:00')
        ->and($history['to'])->toBe('2026-09-06T12:00:00+00:00')
        ->and($history['status'])->toBe('available')
        ->and($history['samples'])->toHaveCount(288)
        ->and($history['samples'][0]['cpu_usage_percent'])->toBe(20.0)
        ->and($history['samples'][0]['memory_usage_percent'])->toBe(50.0)
        ->and($history['samples'][1]['cpu_usage_percent'])->toBeNull()
        ->and($history['samples'][287]['cpu_usage_percent'])->toBe(25.0)
        ->and($history['latest'])->not->toHaveKeys(['cpu_total_ticks', 'cpu_idle_ticks']);
});

it('returns 30-minute buckets for one week', function (): void {
    createSystemMetricSample('2026-09-06 11:59:00', 22.0);

    $history = app(SystemMetricsHistoryService::class)->history(SystemMetricsPeriod::WEEK);

    expect($history['period'])->toBe('week')
        ->and($history['bucket_minutes'])->toBe(30)
        ->and($history['samples'])->toHaveCount(336);
});

it('reports disabled collecting and stale states honestly', function (): void {
    $service = app(SystemMetricsHistoryService::class);

    config()->set('system_metrics.enabled', false);
    expect($service->history(SystemMetricsPeriod::DAY)['status'])->toBe('disabled');

    config()->set('system_metrics.enabled', true);
    expect($service->history(SystemMetricsPeriod::DAY)['status'])->toBe('collecting');

    createSystemMetricSample('2026-09-06 11:50:00', 20.0);
    expect($service->history(SystemMetricsPeriod::DAY)['status'])->toBe('stale');
});

it('protects the system metrics endpoint and defaults to the day period', function (): void {
    $admin = User::factory()->admin()->create();
    $beginner = User::factory()->beginner()->create();

    $this->get(route('logs.system-metrics'))->assertRedirect(route('login'));
    $this->actingAs($beginner)->getJson(route('logs.system-metrics'))->assertForbidden();
    $this->actingAs($admin)->getJson(route('logs.system-metrics'))
        ->assertOk()
        ->assertJsonPath('period', 'day')
        ->assertJsonStructure([
            'period',
            'from',
            'to',
            'bucket_minutes',
            'status',
            'latest',
            'samples' => ['*' => ['recorded_at', 'cpu_usage_percent', 'memory_usage_percent']],
        ]);
});

it('accepts the week period and rejects unsupported periods', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->getJson(route('logs.system-metrics', ['period' => 'week']))
        ->assertOk()
        ->assertJsonPath('period', 'week')
        ->assertJsonPath('bucket_minutes', 30);

    $this->actingAs($admin)->getJson(route('logs.system-metrics', ['period' => 'month']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('period');
});
