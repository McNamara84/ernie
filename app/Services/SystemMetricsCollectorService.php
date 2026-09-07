<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemMetricSample;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class SystemMetricsCollectorService
{
    public function __construct(private SystemMetricsReaderService $reader) {}

    public function collect(): SystemMetricSample
    {
        $snapshot = $this->reader->read();
        $recordedAt = CarbonImmutable::now('UTC')->startOfMinute();

        return DB::transaction(function () use ($recordedAt, $snapshot): SystemMetricSample {
            $previous = SystemMetricSample::query()
                ->where('recorded_at', '<', $recordedAt)
                ->latest('recorded_at')
                ->lockForUpdate()
                ->first();

            $cpuUsagePercent = $this->cpuUsagePercent($previous, $snapshot->cpuTotalTicks, $snapshot->cpuIdleTicks, $recordedAt);
            $memoryUsagePercent = round(($snapshot->memoryUsedBytes / $snapshot->memoryTotalBytes) * 100, 2);

            return SystemMetricSample::query()->updateOrCreate(
                ['recorded_at' => $recordedAt],
                [
                    'cpu_usage_percent' => $cpuUsagePercent,
                    'memory_usage_percent' => $memoryUsagePercent,
                    'memory_used_bytes' => $snapshot->memoryUsedBytes,
                    'memory_total_bytes' => $snapshot->memoryTotalBytes,
                    'cpu_total_ticks' => $snapshot->cpuTotalTicks,
                    'cpu_idle_ticks' => $snapshot->cpuIdleTicks,
                ],
            );
        });
    }

    private function cpuUsagePercent(
        ?SystemMetricSample $previous,
        int $totalTicks,
        int $idleTicks,
        CarbonImmutable $recordedAt,
    ): ?float {
        if ($previous === null) {
            return null;
        }

        $elapsedSeconds = $recordedAt->getTimestamp() - $previous->recorded_at->getTimestamp();
        $maximumInterval = max(1, (int) config('system_metrics.maximum_cpu_interval_seconds', 90));
        $totalDelta = $totalTicks - $previous->cpu_total_ticks;
        $idleDelta = $idleTicks - $previous->cpu_idle_ticks;

        if ($elapsedSeconds <= 0 || $elapsedSeconds > $maximumInterval || $totalDelta <= 0 || $idleDelta < 0 || $idleDelta > $totalDelta) {
            return null;
        }

        return round(max(0.0, min(100.0, (($totalDelta - $idleDelta) / $totalDelta) * 100)), 2);
    }
}
