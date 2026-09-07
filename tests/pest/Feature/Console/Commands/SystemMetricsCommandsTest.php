<?php

declare(strict_types=1);

use App\Models\SystemMetricSample;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

function createCommandSystemMetricSample(string $recordedAt, float $cpu): SystemMetricSample
{
    return SystemMetricSample::query()->create([
        'recorded_at' => $recordedAt,
        'cpu_usage_percent' => $cpu,
        'memory_usage_percent' => 50,
        'memory_used_bytes' => 512,
        'memory_total_bytes' => 1024,
        'cpu_total_ticks' => 1000,
        'cpu_idle_ticks' => 500,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-06 12:00:00 UTC');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('skips collection successfully when system metrics are disabled', function (): void {
    config()->set('system_metrics.enabled', false);

    $this->artisan('system-metrics:collect')
        ->expectsOutputToContain('System metrics collection is disabled')
        ->assertSuccessful();

    expect(SystemMetricSample::query()->count())->toBe(0);
});

it('collects a sample from the configured host files', function (): void {
    $directory = storage_path('framework/testing/system-metrics-command');
    $statPath = $directory.'/stat';
    $meminfoPath = $directory.'/meminfo';
    File::ensureDirectoryExists($directory);
    File::put($statPath, "cpu 100 0 50 850\n");
    File::put($meminfoPath, "MemTotal: 1000 kB\nMemAvailable: 250 kB\n");

    config()->set('system_metrics.enabled', true);
    config()->set('system_metrics.proc_stat_path', $statPath);
    config()->set('system_metrics.proc_meminfo_path', $meminfoPath);

    try {
        $this->artisan('system-metrics:collect')
            ->expectsOutputToContain('Collected host VM system metrics')
            ->assertSuccessful();
    } finally {
        File::deleteDirectory($directory);
    }

    expect(SystemMetricSample::query()->count())->toBe(1);
});

it('fails cleanly and throttles repeated collection warnings', function (): void {
    config()->set('system_metrics.enabled', true);
    config()->set('system_metrics.proc_stat_path', storage_path('missing-proc-stat'));
    config()->set('system_metrics.proc_meminfo_path', storage_path('missing-proc-meminfo'));
    Cache::forget('system-metrics:collection-warning');
    Log::spy();

    $this->artisan('system-metrics:collect')->assertFailed();
    $this->artisan('system-metrics:collect')->assertFailed();

    Log::shouldHaveReceived('warning')->once()->with(
        'Failed to collect host VM system metrics.',
        Mockery::on(fn (array $context): bool => isset($context['exception'])),
    );
});

it('prunes only samples older than the configured retention window', function (): void {
    config()->set('system_metrics.retention_days', 30);
    createCommandSystemMetricSample('2026-08-07 11:59:59', 10.0);
    createCommandSystemMetricSample('2026-08-07 12:00:00', 20.0);

    $this->artisan('system-metrics:prune')
        ->expectsOutputToContain('Pruned 1 expired system metric sample')
        ->assertSuccessful();

    expect(SystemMetricSample::query()->count())->toBe(1)
        ->and(SystemMetricSample::query()->sole()->cpu_usage_percent)->toBe(20.0);
});
