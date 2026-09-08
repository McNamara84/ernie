<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SystemMetricsPeriod;
use App\Models\SystemMetricSample;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

final class SystemMetricsHistoryService
{
    /**
     * @return array{
     *     period: string,
     *     from: string,
     *     to: string,
     *     bucket_minutes: int,
     *     status: 'disabled'|'collecting'|'available'|'stale',
     *     latest: array{recorded_at: string, cpu_usage_percent: float|null, memory_usage_percent: float, memory_used_bytes: int, memory_total_bytes: int}|null,
     *     samples: list<array{recorded_at: string, cpu_usage_percent: float|null, memory_usage_percent: float|null}>
     * }
     */
    public function history(SystemMetricsPeriod $period): array
    {
        $now = CarbonImmutable::now('UTC');
        $endsAt = $now->startOfMinute();
        $startsAt = $period->startsAt($endsAt);
        $latest = SystemMetricSample::query()->latest('recorded_at')->first();
        $samples = SystemMetricSample::query()
            ->where('recorded_at', '>', $startsAt)
            ->where('recorded_at', '<=', $endsAt)
            ->oldest('recorded_at')
            ->get();

        return [
            'period' => $period->value,
            'from' => $startsAt->toIso8601String(),
            'to' => $endsAt->toIso8601String(),
            'bucket_minutes' => $period->bucketMinutes(),
            'status' => $this->status($latest, $now),
            'latest' => $this->presentLatest($latest),
            'samples' => $this->bucketSamples($samples, $startsAt, $endsAt, $period->bucketMinutes()),
        ];
    }

    /** @return 'disabled'|'collecting'|'available'|'stale' */
    private function status(?SystemMetricSample $latest, CarbonImmutable $now): string
    {
        if (! config('system_metrics.enabled')) {
            return 'disabled';
        }

        if ($latest === null || $latest->cpu_usage_percent === null) {
            return 'collecting';
        }

        $staleAfterSeconds = max(1, (int) config('system_metrics.stale_after_seconds', 180));

        return $latest->recorded_at->getTimestamp() < $now->subSeconds($staleAfterSeconds)->getTimestamp()
            ? 'stale'
            : 'available';
    }

    /**
     * @return array{recorded_at: string, cpu_usage_percent: float|null, memory_usage_percent: float, memory_used_bytes: int, memory_total_bytes: int}|null
     */
    private function presentLatest(?SystemMetricSample $latest): ?array
    {
        if ($latest === null) {
            return null;
        }

        return [
            'recorded_at' => $latest->recorded_at->toIso8601String(),
            'cpu_usage_percent' => $latest->cpu_usage_percent,
            'memory_usage_percent' => $latest->memory_usage_percent,
            'memory_used_bytes' => $latest->memory_used_bytes,
            'memory_total_bytes' => $latest->memory_total_bytes,
        ];
    }

    /**
     * @param  Collection<int, SystemMetricSample>  $samples
     * @return list<array{recorded_at: string, cpu_usage_percent: float|null, memory_usage_percent: float|null}>
     */
    private function bucketSamples(Collection $samples, CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $bucketMinutes): array
    {
        $bucketSeconds = $bucketMinutes * 60;
        $durationSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();
        $bucketCount = (int) ceil($durationSeconds / $bucketSeconds);
        /** @var list<array{recorded_at: string, cpu_total: float, cpu_count: int, memory_total: float, memory_count: int}> $buckets */
        $buckets = [];

        for ($index = 0; $index < $bucketCount; $index++) {
            $buckets[$index] = [
                'recorded_at' => $startsAt->addSeconds(($index + 1) * $bucketSeconds)->toIso8601String(),
                'cpu_total' => 0.0,
                'cpu_count' => 0,
                'memory_total' => 0.0,
                'memory_count' => 0,
            ];
        }

        foreach ($samples as $sample) {
            $offset = $sample->recorded_at->getTimestamp() - $startsAt->getTimestamp();
            $index = min($bucketCount - 1, max(0, (int) ceil($offset / $bucketSeconds) - 1));
            $bucket = $buckets[$index];

            if ($sample->cpu_usage_percent !== null) {
                $bucket['cpu_total'] += $sample->cpu_usage_percent;
                $bucket['cpu_count']++;
            }

            $bucket['memory_total'] += $sample->memory_usage_percent;
            $bucket['memory_count']++;
            $buckets[$index] = $bucket;
        }

        /** @var list<array{recorded_at: string, cpu_usage_percent: float|null, memory_usage_percent: float|null}> $result */
        $result = [];

        foreach ($buckets as $bucket) {
            $result[] = [
                'recorded_at' => $bucket['recorded_at'],
                'cpu_usage_percent' => $bucket['cpu_count'] > 0
                    ? round($bucket['cpu_total'] / $bucket['cpu_count'], 2)
                    : null,
                'memory_usage_percent' => $bucket['memory_count'] > 0
                    ? round($bucket['memory_total'] / $bucket['memory_count'], 2)
                    : null,
            ];
        }

        return $result;
    }
}
