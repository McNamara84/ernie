<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('PUBLIC_TRAFFIC_ENABLED', false),

    'health_url' => env('PUBLIC_TRAFFIC_HEALTH_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/health'),

    'health_connect_timeout_seconds' => max(1, (int) env('PUBLIC_TRAFFIC_HEALTH_CONNECT_TIMEOUT_SECONDS', 2)),

    'health_timeout_seconds' => max(1, (int) env('PUBLIC_TRAFFIC_HEALTH_TIMEOUT_SECONDS', 4)),

    'retention_days' => max(365, (int) env('PUBLIC_TRAFFIC_RETENTION_DAYS', 400)),

    'display_timezone' => 'Europe/Berlin',

    'deduplication_grace_seconds' => max(60, (int) env('PUBLIC_TRAFFIC_DEDUPLICATION_GRACE_SECONDS', 300)),
];
