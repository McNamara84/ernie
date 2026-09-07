<?php

declare(strict_types=1);

use App\Services\SystemMetricsReaderService;
use Illuminate\Filesystem\Filesystem;

function systemMetricsReaderWithFiles(string $stat, string $meminfo): SystemMetricsReaderService
{
    config()->set('system_metrics.proc_stat_path', '/fixtures/stat');
    config()->set('system_metrics.proc_meminfo_path', '/fixtures/meminfo');

    $files = Mockery::mock(Filesystem::class);
    $files->shouldReceive('get')->once()->with('/fixtures/stat')->andReturn($stat);
    $files->shouldReceive('get')->once()->with('/fixtures/meminfo')->andReturn($meminfo);

    return new SystemMetricsReaderService($files);
}

it('reads aggregate host CPU counters and available memory', function (): void {
    $reader = systemMetricsReaderWithFiles(
        "cpu  100 20 30 400 10 5 15 20 99 9\ncpu0 50 10 15 200 5 2 8 10 49 4\n",
        "MemTotal:       16384 kB\nMemFree:         2048 kB\nMemAvailable:    4096 kB\nCached:          1024 kB\n",
    );

    $snapshot = $reader->read();

    expect($snapshot->cpuTotalTicks)->toBe(600)
        ->and($snapshot->cpuIdleTicks)->toBe(410)
        ->and($snapshot->memoryUsedBytes)->toBe(12 * 1024 * 1024)
        ->and($snapshot->memoryTotalBytes)->toBe(16 * 1024 * 1024);
});

it('supports the four mandatory CPU counters', function (): void {
    $reader = new SystemMetricsReaderService(new Filesystem);

    expect($reader->parseCpuStat("cpu 1 2 3 4\n"))->toBe([10, 4]);
});

it('rejects missing and malformed aggregate CPU data', function (string $contents, string $message): void {
    $reader = new SystemMetricsReaderService(new Filesystem);

    expect(fn (): array => $reader->parseCpuStat($contents))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'missing aggregate' => ["cpu0 1 2 3 4\n", 'aggregate CPU line is missing'],
    'too few counters' => ["cpu 1 2 3\n", 'aggregate CPU line is malformed'],
    'non integer counter' => ["cpu 1 2 busy 4\n", 'non-integer counter'],
    'zero total' => ["cpu 0 0 0 0\n", 'CPU total must be positive'],
]);

it('rejects missing or invalid memory data', function (string $contents, string $message): void {
    $reader = new SystemMetricsReaderService(new Filesystem);

    expect(fn (): array => $reader->parseMeminfo($contents))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'missing available memory' => ["MemTotal: 1024 kB\n", 'MemTotal or MemAvailable is missing'],
    'zero total' => ["MemTotal: 0 kB\nMemAvailable: 0 kB\n", 'outside their valid range'],
    'available exceeds total' => ["MemTotal: 1024 kB\nMemAvailable: 2048 kB\n", 'outside their valid range'],
    'unexpected unit' => ["MemTotal: 1024 MB\nMemAvailable: 512 MB\n", 'MemTotal or MemAvailable is missing'],
]);

it('rejects empty configured host paths before reading files', function (): void {
    config()->set('system_metrics.proc_stat_path', '');
    config()->set('system_metrics.proc_meminfo_path', '/fixtures/meminfo');

    $reader = new SystemMetricsReaderService(Mockery::mock(Filesystem::class));

    expect(fn () => $reader->read())
        ->toThrow(RuntimeException::class, 'host paths must not be empty');
});
