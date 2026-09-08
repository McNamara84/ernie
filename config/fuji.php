<?php

declare(strict_types=1);

return [
    'enabled' => env('FUJI_ENABLED', false),
    'base_url' => env('FUJI_BASE_URL'),
    'username' => env('FUJI_USERNAME'),
    'password' => env('FUJI_PASSWORD'),
    'timeout' => env('FUJI_TIMEOUT', 60),
    'connect_timeout' => env('FUJI_CONNECT_TIMEOUT', 10),
    'use_datacite' => env('FUJI_USE_DATACITE', true),
    'use_github' => env('FUJI_USE_GITHUB', false),
    'test_debug' => env('FUJI_TEST_DEBUG', false),
    'metric_version' => env('FUJI_METRIC_VERSION'),
    'assessment' => [
        'queue_connection' => env('FUJI_ASSESSMENT_QUEUE_CONNECTION', 'assessment'),
        'queue' => env('FUJI_ASSESSMENT_QUEUE', 'assessments'),
        'concurrency' => (int) env('FUJI_ASSESSMENT_CONCURRENCY', 2),
        'requests_per_minute' => (int) env('FUJI_ASSESSMENT_REQUESTS_PER_MINUTE', 80),
        'window_seconds' => (int) env('FUJI_ASSESSMENT_WINDOW_SECONDS', 60),
        'minimum_interval_ms' => (int) env('FUJI_ASSESSMENT_MINIMUM_INTERVAL_MS', 750),
        'snapshot_chunk_size' => 250,
        'item_timeout_seconds' => (int) env('FUJI_ASSESSMENT_ITEM_TIMEOUT', 150),
        'lease_seconds' => (int) env('FUJI_ASSESSMENT_LEASE_SECONDS', 210),
        'max_attempts' => (int) env('FUJI_ASSESSMENT_MAX_ATTEMPTS', 3),
        'retry_base_seconds' => (int) env('FUJI_ASSESSMENT_RETRY_BASE_SECONDS', 15),
        'retry_jitter_seconds' => (int) env('FUJI_ASSESSMENT_RETRY_JITTER_SECONDS', 5),
    ],
];
