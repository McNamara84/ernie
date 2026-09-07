<?php

declare(strict_types=1);

namespace App\Support;

final readonly class SystemMetricsSnapshot
{
    public function __construct(
        public int $cpuTotalTicks,
        public int $cpuIdleTicks,
        public int $memoryUsedBytes,
        public int $memoryTotalBytes,
    ) {}
}
