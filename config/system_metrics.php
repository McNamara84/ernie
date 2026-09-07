<?php

declare(strict_types=1);

return [
    'enabled' => env('SYSTEM_METRICS_ENABLED', false),
    'proc_stat_path' => env('SYSTEM_METRICS_PROC_STAT_PATH', '/host/proc/stat'),
    'proc_meminfo_path' => env('SYSTEM_METRICS_PROC_MEMINFO_PATH', '/host/proc/meminfo'),
    'retention_days' => (int) env('SYSTEM_METRICS_RETENTION_DAYS', 30),
    'maximum_cpu_interval_seconds' => (int) env('SYSTEM_METRICS_MAXIMUM_CPU_INTERVAL_SECONDS', 90),
    'stale_after_seconds' => (int) env('SYSTEM_METRICS_STALE_AFTER_SECONDS', 180),
];
