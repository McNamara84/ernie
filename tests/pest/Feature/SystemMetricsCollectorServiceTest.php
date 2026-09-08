<?php

declare(strict_types=1);

use App\Models\SystemMetricSample;
use App\Services\SystemMetricsCollectorService;
use App\Services\SystemMetricsReaderService;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;

function systemMetricsCollector(array $statContents, string $meminfo = "MemTotal: 1000 kB\nMemAvailable: 250 kB\n"): SystemMetricsCollectorService
{
    config()->set('system_metrics.proc_stat_path', '/fixtures/stat');
    config()->set('system_metrics.proc_meminfo_path', '/fixtures/meminfo');
    config()->set('system_metrics.maximum_cpu_interval_seconds', 90);

    $files = Mockery::mock(Filesystem::class);
    $files->shouldReceive('get')->with('/fixtures/stat')->andReturn(...$statContents);
    $files->shouldReceive('get')->with('/fixtures/meminfo')->andReturn($meminfo);

    return new SystemMetricsCollectorService(new SystemMetricsReaderService($files));
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('stores a baseline then calculates interval CPU and memory percentages', function (): void {
    $collector = systemMetricsCollector([
        "cpu 100 0 50 850 0 0 0 0\n",
        "cpu 120 0 55 925 0 0 0 0\n",
    ]);

    CarbonImmutable::setTestNow('2026-09-06 12:00:23 UTC');
    $baseline = $collector->collect();

    CarbonImmutable::setTestNow('2026-09-06 12:01:02 UTC');
    $sample = $collector->collect();

    expect($baseline->recorded_at->toIso8601String())->toBe('2026-09-06T12:00:00+00:00')
        ->and($baseline->cpu_usage_percent)->toBeNull()
        ->and($sample->cpu_usage_percent)->toBe(25.0)
        ->and($sample->memory_usage_percent)->toBe(75.0)
        ->and($sample->memory_used_bytes)->toBe(750 * 1024)
        ->and(SystemMetricSample::query()->count())->toBe(2);
});

it('updates an existing minute instead of creating a duplicate', function (): void {
    $collector = systemMetricsCollector([
        "cpu 100 0 0 900\n",
        "cpu 120 0 0 980\n",
    ]);

    CarbonImmutable::setTestNow('2026-09-06 12:00:01 UTC');
    $collector->collect();
    CarbonImmutable::setTestNow('2026-09-06 12:00:59 UTC');
    $updated = $collector->collect();

    expect(SystemMetricSample::query()->count())->toBe(1)
        ->and($updated->cpu_total_ticks)->toBe(1100)
        ->and($updated->cpu_usage_percent)->toBeNull();
});

it('starts a new CPU baseline after a collection gap', function (): void {
    $collector = systemMetricsCollector([
        "cpu 100 0 0 900\n",
        "cpu 300 0 0 1700\n",
    ]);

    CarbonImmutable::setTestNow('2026-09-06 12:00 UTC');
    $collector->collect();
    CarbonImmutable::setTestNow('2026-09-06 12:02 UTC');
    $sample = $collector->collect();

    expect($sample->cpu_usage_percent)->toBeNull()
        ->and($sample->memory_usage_percent)->toBe(75.0);
});

it('rejects reset or inconsistent CPU deltas without losing RAM readings', function (string $secondStat): void {
    $collector = systemMetricsCollector([
        "cpu 100 0 0 900\n",
        $secondStat,
    ]);

    CarbonImmutable::setTestNow('2026-09-06 12:00 UTC');
    $collector->collect();
    CarbonImmutable::setTestNow('2026-09-06 12:01 UTC');
    $sample = $collector->collect();

    expect($sample->cpu_usage_percent)->toBeNull()
        ->and($sample->memory_usage_percent)->toBe(75.0);
})->with([
    'total reset' => "cpu 10 0 0 90\n",
    'idle reset' => "cpu 110 0 0 800\n",
    'idle grows beyond total' => "cpu 50 0 0 1100\n",
]);
