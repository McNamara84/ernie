<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SystemMetricsSnapshot;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class SystemMetricsReaderService
{
    private const BYTES_PER_KIBIBYTE = 1024;

    public function __construct(private Filesystem $files) {}

    public function read(): SystemMetricsSnapshot
    {
        $statPath = (string) config('system_metrics.proc_stat_path');
        $meminfoPath = (string) config('system_metrics.proc_meminfo_path');

        if ($statPath === '' || $meminfoPath === '') {
            throw new RuntimeException('System metrics host paths must not be empty.');
        }

        [$totalTicks, $idleTicks] = $this->parseCpuStat($this->files->get($statPath));
        [$memoryUsedBytes, $memoryTotalBytes] = $this->parseMeminfo($this->files->get($meminfoPath));

        return new SystemMetricsSnapshot(
            cpuTotalTicks: $totalTicks,
            cpuIdleTicks: $idleTicks,
            memoryUsedBytes: $memoryUsedBytes,
            memoryTotalBytes: $memoryTotalBytes,
        );
    }

    /** @return array{int, int} */
    public function parseCpuStat(string $contents): array
    {
        $aggregateLine = collect(preg_split('/\R/', trim($contents)) ?: [])
            ->first(fn (string $line): bool => preg_match('/^cpu\s+/', $line) === 1);

        if (! is_string($aggregateLine)) {
            throw new RuntimeException('The aggregate CPU line is missing from proc stat.');
        }

        $parts = preg_split('/\s+/', trim($aggregateLine));
        if ($parts === false || array_shift($parts) !== 'cpu' || count($parts) < 4) {
            throw new RuntimeException('The aggregate CPU line is malformed.');
        }

        foreach ($parts as $part) {
            if ($part === '' || ! ctype_digit($part)) {
                throw new RuntimeException('The aggregate CPU line contains a non-integer counter.');
            }
        }

        $counters = array_map(static fn (string $value): int => (int) $value, $parts);
        $user = $counters[0];
        $nice = $counters[1];
        $system = $counters[2];
        $idle = $counters[3];
        $iowait = $counters[4] ?? 0;
        $irq = $counters[5] ?? 0;
        $softirq = $counters[6] ?? 0;
        $steal = $counters[7] ?? 0;

        $idleTicks = $idle + $iowait;
        $busyTicks = $user + $nice + $system + $irq + $softirq + $steal;
        $totalTicks = $idleTicks + $busyTicks;

        if ($totalTicks <= 0) {
            throw new RuntimeException('The aggregate CPU total must be positive.');
        }

        return [$totalTicks, $idleTicks];
    }

    /** @return array{int, int} */
    public function parseMeminfo(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
            if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)\s+kB$/', trim($line), $matches) === 1) {
                $values[$matches[1]] = (int) $matches[2];
            }
        }

        if (! isset($values['MemTotal'], $values['MemAvailable'])) {
            throw new RuntimeException('MemTotal or MemAvailable is missing from proc meminfo.');
        }

        $totalKibibytes = $values['MemTotal'];
        $availableKibibytes = $values['MemAvailable'];

        if ($totalKibibytes <= 0 || $availableKibibytes < 0 || $availableKibibytes > $totalKibibytes) {
            throw new RuntimeException('The proc meminfo values are outside their valid range.');
        }

        $memoryTotalBytes = $totalKibibytes * self::BYTES_PER_KIBIBYTE;
        $memoryUsedBytes = ($totalKibibytes - $availableKibibytes) * self::BYTES_PER_KIBIBYTE;

        return [$memoryUsedBytes, $memoryTotalBytes];
    }
}
